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
# SmartClim - UC04 du domaine post-mvp/05-temps-reel-et-demon (spike puis transport
# local alternatif AUXLink, spec technique 04-spike-auxlink-local-tech.md).
#
# === SONDE AUXLINK (UC04 post-mvp/05) - debut ===
#
# Sonde de DECOUVERTE en lecture seule pour le protocole LAN "AUXLink" des modules
# recents (decouverte UDP 12414/2415, magic a5a5 ; corrobore par le LAN Gizwits
# GAgent, ports identiques, magic different). AUCUN code de session, d'appairage ou
# de pilotage n'est ecrit dans ce fichier -- c'est l'AC1 de l'UC, verifiable par
# relecture : ce module ne contient qu'une emission de trames de DECOUVERTE, une
# ecoute PASSIVE, et un test d'OUVERTURE de port TCP suivi d'une fermeture
# immediate, sans jamais rien ecrire sur ce canal.
#
# Fonction de CRC-16 CCITT portee depuis latentharbor/ha-aux-a-plus
# (custom_components/aux_a_plus/lan.py), MIT :
#   Copyright (c) latentharbor/ha-aux-a-plus contributors
#   Permission is hereby granted, free of charge, to any person obtaining a copy
#   of this software and associated documentation files, to deal in the Software
#   without restriction, subject to the inclusion of the above copyright notice
#   in all copies or substantial portions of the Software.
#
# Les ports UDP (12414/2415) et le cadrage GAgent (commandes 0x03/0x68) sont des
# FAITS de protocole corrobores independamment par Apollon77/node-ph803w et
# gizwits/Gizwits-GAgent -- aucune ligne n'en est recopiee, un fait de protocole
# n'etant pas protegeable.
#
# Classement d'une trame (S 6.2 de la spec technique) : le critere PRIMAIRE est
# TOUJOURS l'octet de commande / le type declare par l'emetteur, JAMAIS l'adresse
# source. Le filtre d'adresse locale (_adresses_locales) n'est qu'une defense en
# profondeur, incomplete par construction (multi-interfaces, conteneur) : un
# classement qui reposerait sur elle seule produirait un verdict FAUX, pas
# indecis. Seules les REPONSES (auxlink type 0x0003, gagent commande 0x68) peuvent
# armer un verdict positif ; une requete (la notre ou celle d'un autre client du
# LAN) ne le peut jamais.
#
# La purge du rapport de campagne est CONDITIONNELLE, cote PHP
# (smartclim::armerSondeAuxlink()) : ce module se contente de faire un NO-OP sur
# une configuration d'empreinte identique et de threads deja vivants, pour ne pas
# perdre la preuve deja accumulee sur une relance de la meme campagne.
#
# === SONDE AUXLINK (UC04 post-mvp/05) - fin ===
# ---------------------------------------------------------------------------

import binascii
import logging
import re
import socket
import threading
import time


# Trame de decouverte AUXLink (C1 de la spec technique) : magic a5a5, longueur
# totale (10, LE), type 0x0002 (LE), sequence 0, CRC-16 CCITT big-endian.
SONDE_AUXLINK = binascii.unhexlify('a5a50a000200000028ab')
# Trame de decouverte du LAN Gizwits GAgent (C2) : commande 0x03, aucun rapport
# avec AUX -- corroboration independante des ports 12414/2415.
SONDE_GAGENT = binascii.unhexlify('0000000303000003')

PORT_DECOUVERTE = 12414
PORT_REPONSE = 2415
PORT_SESSION = 12416

CMD_GAGENT_REQUETE = 0x03
CMD_GAGENT_REPONSE = 0x68
TYPE_AUXLINK_REQUETE = 0x0002
TYPE_AUXLINK_REPONSE = 0x0003

INTERVALLE_AMORCE = 10
AMORCES = 3
INTERVALLE_VEILLE = 300
INTERVALLE_PORT = 900
INTERVALLE_RAPPORT = 60
TAILLE_MAX_DATAGRAMME = 2048
TRAMES_MAX = 20
HEX_MAX = 128

DUREE_OBSERVATION_DEFAUT = 86400
DUREE_OBSERVATION_MIN = 60
DUREE_OBSERVATION_MAX = 604800

REGEX_DID = re.compile(r'[A-Za-z0-9_-]{1,64}')
REGEX_IP = re.compile(r'(?:\d{1,3}\.){3}\d{1,3}')


def _crc16_ccitt(octets):
    """CRC-16 CCITT (polynome 0x1021, init 0xFFFF), portee de lan.py (MIT)."""
    crc = 0xFFFF
    for octet in octets:
        crc ^= octet << 8
        for _ in range(8):
            if crc & 0x8000:
                crc = ((crc << 1) ^ 0x1021) & 0xFFFF
            else:
                crc = (crc << 1) & 0xFFFF
    return crc


class SondeAuxlink(object):
    """
    Sonde de decouverte AUXLink en lecture seule. AUCUNE ecriture de session,
    AUCUN pilotage -- cf. l'en-tete de ce fichier (AC1).
    """

    def __init__(self, jeedom_com):
        self._jeedom_com = jeedom_com
        self._verrou = threading.Lock()
        # Compteur de GENERATION, meme patron que RelaisAuxCloud : invalide une
        # campagne anterieure sans avoir a interrompre violemment un socket
        # bloquant, chaque thread se terminant de lui-meme des qu'il constate que
        # sa generation n'est plus la generation courante.
        self._generation = 0
        self._threads = []
        self._sock_reponse = None
        self._sock_decouverte = None
        self._empreinte = None
        self._expire_le = 0
        self._hotes = []
        self._trames = []
        self._ports = {}
        self._evenement_trame = threading.Event()
        # Calculees UNE FOIS par generation (configurer()), jamais par datagramme :
        # _adresses_locales() ouvre un socket et peut declencher une resolution, et
        # _analyser_trame() est appelee sur CHAQUE paquet recu, donc sur une entree
        # NON AUTHENTIFIEE (n'importe qui sur le LAN). Un cout par paquet serait
        # gratuitement offert a l'exterieur.
        self._adresses_locales_cache = set()

    def configurer(self, paquet):
        """
        Valide la FORME du paquet recu du pont PHP puis (re)demarre la campagne si
        necessaire. Empreinte IDENTIQUE et threads deja vivants => NO-OP (ne pas
        perdre la preuve deja accumulee sur une relance de la meme campagne, cf.
        l'en-tete de ce fichier). `actif == False` => arret complet. NE LEVE
        JAMAIS.
        """
        try:
            if not isinstance(paquet, dict):
                logging.warning("Sonde AUXLink : configuration recue de forme invalide (pas un objet), ignoree")
                return

            if paquet.get('actif') is False:
                logging.info("Sonde AUXLink : desarmement demande")
                self.arreter()
                return

            duree = paquet.get('duree')
            if not isinstance(duree, (int, float)) or isinstance(duree, bool):
                duree = DUREE_OBSERVATION_DEFAUT
            duree = int(max(DUREE_OBSERVATION_MIN, min(DUREE_OBSERVATION_MAX, duree)))

            hotes_bruts = paquet.get('hotes')
            hotes = []
            if isinstance(hotes_bruts, list):
                for hote in hotes_bruts:
                    if len(hotes) >= 4:
                        break
                    if isinstance(hote, str) and REGEX_IP.fullmatch(hote):
                        hotes.append(hote)

            empreinte = paquet.get('empreinte')
            empreinte = empreinte if isinstance(empreinte, str) else ''

            with self._verrou:
                threads_vivants = any(t.is_alive() for t in self._threads)
                if self._empreinte == empreinte and threads_vivants:
                    return
                self._generation += 1
                generation = self._generation
                self._empreinte = empreinte
                self._expire_le = time.time() + duree
                self._hotes = hotes
                self._trames = []
                self._ports = {}
                # Une nouvelle GENERATION reelle (pas un no-op) implique des sockets
                # NEUFS : les anciens threads _boucle_ecoute, encore vivants, les
                # fermeront eux-memes des qu'ils constateront leur generation perimee
                # (sous 1 s, granularite du timeout de recvfrom). Reutiliser les
                # memes objets socket pour la nouvelle campagne les exposerait a
                # cette fermeture tardive - la review a montre qu'un simple
                # "if self._sock_reponse is None" dans _ouvrir_sockets() ne suffit
                # pas : il faut REMETTRE A None ici, sous la MEME generation, pour
                # que _ouvrir_sockets() en recree des neufs juste apres.
                self._fermer_sockets()

            # Calculees UNE FOIS par generation (cf. le commentaire du
            # constructeur) - jamais recalculees a chaque datagramme recu.
            # HORS du verrou : socket.gethostbyname(gethostname()) peut bloquer
            # plusieurs secondes sans timeout explicite (DNS degrade) - le faire
            # sous self._verrou geler tout autre thread testant
            # _generation_active() (_attendre/_boucle_ecoute/_boucle_rapport),
            # et un arreter() concurrent (donc le shutdown() du demon).
            adresses_locales = self._adresses_locales()
            with self._verrou:
                self._adresses_locales_cache = adresses_locales

            try:
                self._ouvrir_sockets()
            except OSError as erreur:
                logging.error(
                    "Sonde AUXLink : impossible d'ouvrir les sockets (%s), sonde desactivee",
                    type(erreur).__name__
                )
                return

            sock_reponse = self._sock_reponse
            sock_decouverte = self._sock_decouverte
            expire_le = self._expire_le

            threads = [
                threading.Thread(target=self._boucle_emission, args=(generation, hotes, expire_le), daemon=True),
                threading.Thread(target=self._boucle_ecoute, args=(generation, sock_reponse, expire_le), daemon=True),
                threading.Thread(target=self._boucle_ecoute, args=(generation, sock_decouverte, expire_le), daemon=True),
                threading.Thread(target=self._boucle_rapport, args=(generation,), daemon=True),
            ]
            with self._verrou:
                self._threads = threads
            for thread in threads:
                thread.start()
            logging.info("Sonde AUXLink : campagne armee pour %d s (%d hote(s) connu(s))", duree, len(hotes))
        except Exception as erreur:
            logging.error("Sonde AUXLink : erreur de configuration (%s)", type(erreur).__name__)

    def arreter(self):
        """Invalide la generation courante et ferme les sockets. Appelee par shutdown()."""
        with self._verrou:
            self._generation += 1
            self._empreinte = None
            self._expire_le = 0
            self._hotes = []
            self._trames = []
            self._ports = {}
            self._fermer_sockets()

    def _fermer_sockets(self):
        """
        Ferme S1/S2 s'ils existent et les remet a None. SOUS GARDE (un ancien
        thread _boucle_ecoute peut deja les avoir fermes de son cote au moment ou
        cette methode s'execute) - ne doit JAMAIS lever, ni empecher une nouvelle
        campagne de demarrer. Appelante DOIT deja tenir self._verrou.
        """
        for sock in (self._sock_reponse, self._sock_decouverte):
            if sock is not None:
                try:
                    sock.close()
                except Exception:
                    pass
        self._sock_reponse = None
        self._sock_decouverte = None

    def _generation_active(self, generation):
        with self._verrou:
            return generation == self._generation

    def _ouvrir_sockets(self):
        if self._sock_reponse is None:
            sock_reponse = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            sock_reponse.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            sock_reponse.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
            sock_reponse.bind(('', PORT_REPONSE))
            self._sock_reponse = sock_reponse
        if self._sock_decouverte is None:
            sock_decouverte = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            sock_decouverte.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            sock_decouverte.bind(('', PORT_DECOUVERTE))
            self._sock_decouverte = sock_decouverte

    def _attendre(self, secondes, generation, expire_le):
        fin = time.time() + secondes
        while self._generation_active(generation) and time.time() < fin and time.time() < expire_le:
            time.sleep(min(1, max(0, fin - time.time())))

    def _emettre(self, trame, hotes):
        sock = self._sock_reponse
        if sock is None:
            return
        try:
            sock.sendto(trame, ('255.255.255.255', PORT_DECOUVERTE))
        except OSError as erreur:
            logging.warning("Sonde AUXLink : emission en diffusion impossible (%s)", type(erreur).__name__)
        for hote in hotes:
            try:
                sock.sendto(trame, (hote, PORT_DECOUVERTE))
            except OSError as erreur:
                logging.warning("Sonde AUXLink : emission vers un hote connu impossible (%s)", type(erreur).__name__)

    def _tester_port(self, ip):
        # === SONDE AUXLINK (UC04 post-mvp/05) -- AC1 : SEULE fonction de ce fichier
        # qui ouvre une connexion TCP. Elle se contente d'ETABLIR la connexion puis
        # de la FERMER IMMEDIATEMENT, sans jamais rien ecrire sur ce canal.
        try:
            connexion = socket.create_connection((ip, PORT_SESSION), timeout=2)
            connexion.close()
            return 'ouvert'
        except socket.timeout:
            return 'timeout'
        except OSError:
            return 'refuse'

    def _adresses_locales(self):
        """
        Determine (best effort) les adresses IP locales, pour le filtre de defense
        en profondeur SECONDAIRE de _analyser_trame() (S 6.2 de la spec
        technique). ⚠️ Limites assumees : socket.gethostbyname(gethostname()) est
        peu fiable sur Debian ; le repli par socket UDP non connecte
        (connect() + getsockname(), AUCUN paquet emis) ne voit qu'UNE interface.
        En multi-interfaces ou en conteneur, ce filtre est incomplet par
        construction -- c'est exactement pourquoi il n'est jamais le critere
        primaire de classement.
        """
        adresses = set()
        try:
            adresses.add(socket.gethostbyname(socket.gethostname()))
        except OSError:
            pass
        try:
            sonde = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            try:
                sonde.connect(('8.8.8.8', 80))
                adresses.add(sonde.getsockname()[0])
            finally:
                sonde.close()
        except OSError:
            pass
        return adresses

    def _analyser_trame(self, octets, ip_source):
        """
        Fonction PURE. Classe une trame recue : critere PRIMAIRE = l'octet de
        commande/le type declare par l'emetteur (S 6.2 de la spec technique),
        JAMAIS l'adresse source -- le filtre d'adresse locale n'est qu'une
        information de defense en profondeur, jointe au resultat mais jamais
        utilisee pour re-classer 'famille'/'sens'.
        """
        if not isinstance(octets, (bytes, bytearray)) or len(octets) < 8:
            return None
        # Lit le cache calcule UNE FOIS par generation (configurer()), jamais
        # recalcule ici : cette fonction est appelee sur CHAQUE datagramme recu,
        # donc sur une entree NON AUTHENTIFIEE (n'importe qui sur le LAN).
        locale = ip_source in self._adresses_locales_cache

        if octets[0:2] == b'\xa5\xa5':
            if len(octets) < 10:
                return None
            longueur = octets[2] | (octets[3] << 8)
            if longueur != len(octets):
                return None
            type_trame = octets[4] | (octets[5] << 8)
            crc_recu = (octets[-2] << 8) | octets[-1]
            crc_calcule = _crc16_ccitt(octets[:-2])
            if crc_calcule != crc_recu:
                return None

            mac = ''
            device_id = ''
            if type_trame == TYPE_AUXLINK_REQUETE:
                sens = 'requete'
            elif type_trame == TYPE_AUXLINK_REPONSE:
                sens = 'reponse'
                charge = octets[8:-2]
                if len(charge) >= 14 and charge[7] == 6:
                    mac = binascii.hexlify(charge[8:14]).decode('ascii')
                    if len(charge) >= 15:
                        longueur_did = charge[14]
                        brut = charge[15:15 + longueur_did]
                        try:
                            device_id_candidat = brut.decode('ascii')
                        except UnicodeDecodeError:
                            device_id_candidat = ''
                        if device_id_candidat and REGEX_DID.fullmatch(device_id_candidat):
                            device_id = device_id_candidat
            else:
                sens = 'autre'

            return {
                'famille': 'auxlink',
                'sens': sens,
                'hex': binascii.hexlify(octets).decode('ascii')[:HEX_MAX],
                'mac': mac,
                'device_id': device_id,
                'ip_source': ip_source,
                'locale': locale,
            }

        if octets[0:4] == b'\x00\x00\x00\x03' and len(octets) >= 5:
            commande = octets[4]
            if commande == CMD_GAGENT_REQUETE:
                sens = 'requete'
            elif commande == CMD_GAGENT_REPONSE:
                sens = 'reponse'
            else:
                sens = 'autre'
            return {
                'famille': 'gagent',
                'sens': sens,
                'hex': binascii.hexlify(octets).decode('ascii')[:HEX_MAX],
                'mac': '',
                'device_id': '',
                'ip_source': ip_source,
                'locale': locale,
            }

        return {
            'famille': 'autre',
            'sens': 'autre',
            'hex': binascii.hexlify(octets).decode('ascii')[:HEX_MAX],
            'mac': '',
            'device_id': '',
            'ip_source': ip_source,
            'locale': locale,
        }

    def _noter_trame(self, generation, trame):
        with self._verrou:
            if generation != self._generation:
                return
            self._trames.append(trame)
            if len(self._trames) > TRAMES_MAX:
                self._trames = self._trames[-TRAMES_MAX:]
        self._evenement_trame.set()

    def _boucle_emission(self, generation, hotes, expire_le):
        # Palier 1 (amorce) : 3 emissions alternees a 10 s d'intervalle, plus un
        # test d'ouverture TCP par hote connu (S 4 de la spec technique).
        for compteur in range(AMORCES):
            if not self._generation_active(generation):
                return
            trame = SONDE_AUXLINK if compteur % 2 == 0 else SONDE_GAGENT
            self._emettre(trame, hotes)
            for hote in hotes:
                statut = self._tester_port(hote)
                with self._verrou:
                    if generation == self._generation:
                        self._ports[hote] = statut
            if compteur < AMORCES - 1:
                self._attendre(INTERVALLE_AMORCE, generation, expire_le)

        # Palier 2 (veille) : emission alternee toutes les 300 s, test de port
        # toutes les 900 s si un hote est connu, jusqu'a expiration de la fenetre.
        dernier_port = time.time()
        compteur = 0
        while self._generation_active(generation) and time.time() < expire_le:
            trame = SONDE_AUXLINK if compteur % 2 == 0 else SONDE_GAGENT
            self._emettre(trame, hotes)
            compteur += 1
            if hotes and (time.time() - dernier_port) >= INTERVALLE_PORT:
                for hote in hotes:
                    statut = self._tester_port(hote)
                    with self._verrou:
                        if generation == self._generation:
                            self._ports[hote] = statut
                dernier_port = time.time()
            self._attendre(INTERVALLE_VEILLE, generation, expire_le)

    def _boucle_ecoute(self, generation, sock, expire_le):
        # ⚠️ S'arrete AUSSI a expire_le (pas seulement au desarmement explicite),
        # et ferme son socket dans tous les cas (S 6.1 de la spec technique).
        try:
            sock.settimeout(1)
            while self._generation_active(generation) and time.time() < expire_le:
                try:
                    donnees, adresse = sock.recvfrom(TAILLE_MAX_DATAGRAMME)
                except socket.timeout:
                    continue
                except OSError:
                    break
                if not self._generation_active(generation):
                    break
                trame = self._analyser_trame(donnees, adresse[0])
                if trame is not None:
                    self._noter_trame(generation, trame)
        except Exception as erreur:
            logging.warning("Sonde AUXLink : ecoute interrompue (%s)", type(erreur).__name__)
        finally:
            try:
                sock.close()
            except Exception:
                pass

    def _boucle_rapport(self, generation):
        while self._generation_active(generation):
            self._envoyer_rapport(generation)
            self._evenement_trame.wait(timeout=INTERVALLE_RAPPORT)
            self._evenement_trame.clear()

    def _envoyer_rapport(self, generation):
        with self._verrou:
            if generation != self._generation:
                return
            rapport = {
                'empreinte': self._empreinte if isinstance(self._empreinte, str) else '',
                'expire_le': int(self._expire_le),
                'trames': list(self._trames),
                'ports': dict(self._ports),
            }
        self._jeedom_com.add_changes('auxlink::sonde', rapport)
