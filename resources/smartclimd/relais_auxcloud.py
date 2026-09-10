# This file is part of Jeedom.
#
# Jeedom is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# Jeedom is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
#
# ---------------------------------------------------------------------------
# SmartClim - UC03 du domaine post-mvp/05-temps-reel-et-demon (spec technique
# 03-websocket-legacy-temps-reel-tech.md). Session WebSocket temps reel vers le relais
# du cloud historique AUX Cloud legacy / AC Freedom (apprelay/relayconnect) : init,
# maintien (ping/pingk), abonnement (sub), reception des evenements pousses (push),
# reconnexion temporisee, remontee d'un battement periodique.
#
# Contrat protocolaire mesure par sonde directe le 2026-09-09 et recoupe avec
# maeek/ha-aux-cloud (MIT), demo_ws.py - cf. S 1 de la spec technique.
#
# CE MODULE NE PARLE PAS HVAC : il tient une socket, valide une FORME, et transmet des
# octets au format hex/base64 tels quels a smartclim::valeursDepuisPush() cote PHP, qui
# delegue au decodeur DEJA RECETTE (smartclimAuxCloudApi::decoderParametres()). Un
# decodeur Python ici aurait ete une divergence garantie avec ce decodeur PHP.
#
# ⚠️ Import de "websocket" (bibliotheque websocket-client) SOUS GARDE try/except : si la
# dependance n'est pas installee, le relais est simplement DESACTIVE (un seul
# logging.error) et le reste du demon (ping/pong du pont) continue de fonctionner
# normalement - meme lecon que l'import pyudev manquant du gabarit officiel (cf. l'en-tete
# de smartclimd.py).
#
# ⚠️ "loginsession"/"userid" ne sont JAMAIS journalises ici (ni en clair, ni dans un
# message d'exception), et enableTrace() de websocket-client n'est JAMAIS active : le
# trace ecrirait le contenu des messages (donc le loginsession) dans le journal du
# demon (S 5.1/6.2 de la spec technique).
# ---------------------------------------------------------------------------

import json
import logging
import re
import threading
import time

try:
    import websocket
    _WEBSOCKET_DISPONIBLE = True
except ImportError as _erreur_import_websocket:
    _WEBSOCKET_DISPONIBLE = False
    logging.error(
        "Bibliotheque websocket-client absente : relais AUX Cloud legacy desactive "
        "(installez les dependances du plugin) : %s", _erreur_import_websocket
    )

# Meme forme que les endpointId valides cote PHP (S 4.1 de la spec technique) : defense
# en profondeur, ne pas relacher cote Python en se fiant a la barriere PHP.
REGEX_ENDPOINT = re.compile(r'[A-Za-z0-9_-]{1,64}')

INTERVALLE_PING = 10          # s, maintien de session applicatif
TIMEOUT_PONG = 30             # s, absence de pingk => reconnexion
BACKOFF_MIN = 10              # s
BACKOFF_MAX = 300             # s
DUREE_STABLE = 60             # s, au-dela : backoff remis a BACKOFF_MIN
INTERVALLE_BATTEMENT = 60     # s
TAILLE_MAX_MESSAGE = 65536    # octets, taille max applicative AVANT parsing


class RelaisAuxCloud(object):
    """
    Detient l'UNIQUE session WebSocket vers le relais temps reel AUX Cloud legacy.
    Aucun decodage HVAC, aucune connaissance des concepts generiques du plugin - cf.
    l'en-tete de ce fichier.
    """

    def __init__(self, jeedom_com):
        self._jeedom_com = jeedom_com
        self._verrou = threading.Lock()
        # Compteur de GENERATION : invalide toute session anterieure sans avoir besoin
        # d'interrompre violemment un socket bloquant - le thread en cours se termine de
        # lui-meme des qu'il constate que sa generation n'est plus la generation courante.
        self._generation = 0
        self._thread = None
        self._empreinte = None
        self._etat = 'reconnexion'
        self._confirmes = set()
        self._abonnes = 0
        self._battement_demarre = False

    def configurer(self, paquet):
        """
        Valide la FORME du paquet recu du pont PHP (S 4.1/5.1 de la spec technique) puis
        (re)demarre la session si necessaire. Empreinte IDENTIQUE et thread deja vivant
        => NO-OP (ne pas se deconnecter toutes les 10 min sur un paquet inchange).
        `appareils` vide => ferme la session en cours, s'il y en a une.
        """
        if not _WEBSOCKET_DISPONIBLE:
            return
        if not isinstance(paquet, dict):
            logging.warning("Configuration du relais AUX Cloud legacy recue de forme invalide (pas un objet), ignoree")
            return

        url = paquet.get('url')
        entetes = paquet.get('entetes')
        session = paquet.get('session')
        appareils = paquet.get('appareils')
        empreinte = paquet.get('empreinte')

        appareils_vide_demande = isinstance(appareils, list) and len(appareils) == 0

        if not appareils_vide_demande:
            if not isinstance(url, str) or url == '' or not isinstance(entetes, list) or not isinstance(session, dict) or not isinstance(appareils, list):
                logging.warning("Configuration du relais AUX Cloud legacy recue de forme invalide, ignoree")
                return

        if appareils_vide_demande:
            logging.info("Relais AUX Cloud legacy : aucun equipement cible, fermeture de la session en cours")
            self.arreter()
            return

        appareils_valides = []
        for appareil in appareils:
            if not isinstance(appareil, dict):
                continue
            endpoint_id = appareil.get('endpointId')
            if not isinstance(endpoint_id, str) or not REGEX_ENDPOINT.fullmatch(endpoint_id):
                continue
            dev_session = appareil.get('devSession')
            pid = appareil.get('pid')
            appareils_valides.append({
                'endpointId': endpoint_id,
                # S 1.3 de la spec technique : sub.pid = productId. Une erreur ici rend
                # l'abonnement MUET, sans aucun signal - d'ou la journalisation explicite
                # du nombre d'appareils abonnes ci-dessous.
                'devSession': dev_session if isinstance(dev_session, str) else '',
                'pid': pid if isinstance(pid, str) else '',
                'gatewayId': '',
            })

        if not appareils_valides:
            logging.warning("Configuration du relais AUX Cloud legacy recue sans aucun appareil exploitable, ignoree")
            return

        with self._verrou:
            thread_vivant = self._thread is not None and self._thread.is_alive()
            if self._empreinte == empreinte and thread_vivant:
                # No-op : eviter de casser une session stable toutes les 10 min sur un
                # paquet de synchro identique.
                return
            self._generation += 1
            generation = self._generation
            self._empreinte = empreinte
            self._confirmes = set()
            self._abonnes = len(appareils_valides)
            self._etat = 'reconnexion'
            self._thread = threading.Thread(
                target=self._boucle_session,
                args=(generation, url, entetes, session, appareils_valides),
                daemon=True,
            )
            self._thread.start()
        # Battement demarre au PREMIER appareil reellement synchronise (S 5.1 de la spec
        # technique : "toutes les 60 s tant que le thread [de session] vit") - jamais
        # avant, pour ne pas ecrire un battement "reconnexion" en boucle sur un demon dont
        # le relais n'a jamais ete configure (aucun compte legacy, ou aucun equipement
        # cible). Reste actif ensuite meme pendant une reconnexion temporisee, pour que le
        # battement demeure un instrument de diagnostic utile.
        self._demarrer_battement()

    def arreter(self):
        """Fermeture propre (invalide la generation courante). Appelee par shutdown()."""
        with self._verrou:
            self._generation += 1
            self._empreinte = None
            self._etat = 'reconnexion'
            self._confirmes = set()
            self._abonnes = 0

    def _generation_active(self, generation):
        with self._verrou:
            return generation == self._generation

    def _maj_etat(self, generation, etat):
        with self._verrou:
            if generation == self._generation:
                self._etat = etat

    def _nouveau_messageid(self):
        return str(int(time.time() * 1000))

    def _boucle_session(self, generation, url, entetes, session, appareils):
        backoff = BACKOFF_MIN
        while self._generation_active(generation):
            ws = None
            debut_session = time.time()
            try:
                ws = websocket.create_connection(url, header=entetes, timeout=10)
                self._maj_etat(generation, 'reconnexion')

                ws.send(json.dumps({
                    'msgtype': 'init',
                    'data': {'relayrule': 'share'},
                    'scope': session,
                    'messageid': self._nouveau_messageid(),
                }))
                reponse = json.loads(ws.recv())
                if not isinstance(reponse, dict) or reponse.get('msgtype') != 'initk' or reponse.get('status') != 0:
                    logging.warning(
                        "Relais AUX Cloud legacy : initk refuse (status=%s)",
                        reponse.get('status') if isinstance(reponse, dict) else '?'
                    )
                    self._maj_etat(generation, 'refus')
                    raise RuntimeError('initk refuse')

                # Reconfiguration a chaud survenue pendant le handshake (initk recu, "sub"
                # pas encore envoye) : la generation courante n'est deja plus la bonne. On
                # sort proprement (comme les autres chemins de sortie de cette boucle, via
                # le "finally" qui ferme la socket) plutot que d'envoyer un "sub" pour une
                # session deja obsolete - cf. le point 2 des corrections mecaniques du 05-03.
                if not self._generation_active(generation):
                    raise RuntimeError('generation obsolete avant sub')

                # S 1.3/R1 de la spec technique : le "sub" est le PIVOT de toute l'UC -
                # sans lui, la session s'etablit, le relais la confirme, et AUCUN
                # evenement n'arrive jamais. Son refus est SILENCIEUX (aucun "subk"
                # documente) : cette ligne est la SEULE trace qui permette de le
                # diagnostiquer en recette communautaire.
                logging.info("Relais AUX Cloud legacy : session initialisee, abonnement envoye pour %d appareil(s)", len(appareils))
                ws.send(json.dumps({
                    'msgtype': 'sub',
                    'topic': 'devpush',
                    'messageid': self._nouveau_messageid(),
                    'data': {'devList': appareils},
                }))
                self._maj_etat(generation, 'connecte')

                dernier_ping = time.time()
                dernier_pong = time.time()
                ws.settimeout(1)
                while self._generation_active(generation):
                    maintenant = time.time()
                    if maintenant - dernier_ping >= INTERVALLE_PING:
                        ws.send(json.dumps({'msgtype': 'ping', 'messageid': self._nouveau_messageid()}))
                        dernier_ping = maintenant
                    if maintenant - dernier_pong > TIMEOUT_PONG:
                        logging.warning("Relais AUX Cloud legacy : aucun pingk recu depuis %d s, reconnexion", TIMEOUT_PONG)
                        raise RuntimeError('pingk absent')
                    try:
                        brut = ws.recv()
                    except websocket.WebSocketTimeoutException:
                        continue
                    if brut is None or brut == '':
                        raise RuntimeError('connexion fermee par le relais')
                    if isinstance(brut, (bytes, str)) and len(brut) > TAILLE_MAX_MESSAGE:
                        logging.warning("Relais AUX Cloud legacy : message ignore (taille excessive)")
                        continue
                    try:
                        message = json.loads(brut)
                    except ValueError:
                        logging.warning("Relais AUX Cloud legacy : message non JSON ignore")
                        continue
                    if not isinstance(message, dict):
                        continue

                    msgtype = message.get('msgtype')
                    if msgtype == 'pingk':
                        dernier_pong = time.time()
                        continue
                    if msgtype == 'push':
                        self._traiter_push(generation, message)
                        continue
                    # Tout autre msgtype : SEUL le couple msgtype/topic est journalise,
                    # JAMAIS la charge (S 5.1 de la spec technique).
                    logging.info("Relais AUX Cloud legacy : message recu (msgtype=%s topic=%s)", msgtype, message.get('topic'))

            except Exception as erreur:
                logging.warning("Relais AUX Cloud legacy : session interrompue (%s)", type(erreur).__name__)
            finally:
                if ws is not None:
                    try:
                        ws.close()
                    except Exception:
                        pass

            if (time.time() - debut_session) > DUREE_STABLE:
                backoff = BACKOFF_MIN

            if not self._generation_active(generation):
                break

            self._maj_etat(generation, 'reconnexion')
            time.sleep(backoff)
            backoff = min(BACKOFF_MAX, backoff * 2)

    def _traiter_push(self, generation, message):
        if message.get('topic') != 'devpush':
            logging.info("Relais AUX Cloud legacy : push ignore (topic=%s)", message.get('topic'))
            return
        data = message.get('data')
        if not isinstance(data, dict):
            return
        endpoint_id = data.get('endpointId')
        # Le rejet reel du cas "::" vient deja de REGEX_ENDPOINT ([A-Za-z0-9_-]{1,64}) :
        # ":" n'appartient pas a son alphabet, donc fullmatch() refuse toute chaine qui en
        # contient avant meme d'atteindre ce test. Le test explicite ci-dessous est un
        # filet de defense en profondeur (S 4.1 de la spec technique) - la cle passee a
        # add_changes() est arborescente ("auxcloud::push::<id>"), un "::" dans
        # l'identifiant casserait l'arborescence cote merge_dict() - qui survivrait a un
        # elargissement futur de l'alphabet de la regex. Garde, meme s'il est aujourd'hui
        # inatteignable.
        if not isinstance(endpoint_id, str) or '::' in endpoint_id or not REGEX_ENDPOINT.fullmatch(endpoint_id):
            logging.warning("Relais AUX Cloud legacy : endpointId de push de forme invalide, ignore")
            return

        enveloppe = {}
        if isinstance(data.get('data'), str) and data.get('data') != '':
            enveloppe['data'] = data['data']
        payload = data.get('payload')
        if isinstance(payload, dict) and isinstance(payload.get('data'), str) and payload.get('data') != '':
            enveloppe['payload'] = {'data': payload['data']}
        if not enveloppe:
            return

        with self._verrou:
            if generation != self._generation:
                return
            self._confirmes.add(endpoint_id)

        self._jeedom_com.add_changes('auxcloud::push::' + endpoint_id, enveloppe)

    def _demarrer_battement(self):
        if self._battement_demarre:
            return
        self._battement_demarre = True
        threading.Thread(target=self._boucle_battement, daemon=True).start()

    def _boucle_battement(self):
        # Remonte un battement toutes les INTERVALLE_BATTEMENT secondes, tant que le
        # demon vit (approximation assumee de "tant que le thread [de session] vit" -
        # S 5.1 de la spec technique : le battement decrit l'etat COURANT, qu'une session
        # soit en cours d'etablissement, connectee, ou en reconnexion temporisee). AUCUN
        # secret : un code d'etat, deux listes d'identifiants d'endpoint, une empreinte.
        while True:
            time.sleep(INTERVALLE_BATTEMENT)
            with self._verrou:
                etat = self._etat
                confirmes = sorted(self._confirmes)
                abonnes = self._abonnes
                empreinte = self._empreinte
            self._jeedom_com.add_changes('auxcloud::relais', {
                'etat': etat,
                'abonnes': abonnes,
                'confirmes': confirmes,
                'empreinte': empreinte if isinstance(empreinte, str) else '',
            })
