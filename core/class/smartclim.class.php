<?php
/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

/* * ***************************Includes********************************* */
require_once __DIR__  . '/../../../../core/php/core.inc.php';
// Charge les classes ANNEXES du plugin (smartclimException, smartclimAuxHomeApi, ...).
// OBLIGATOIRE : l'autoloader du core n'inclut QUE ce fichier-ci pour tout le plugin, et
// ignore SILENCIEUSEMENT un nom de classe qui n'est pas l'id du plugin (demonstration
// complete, avec le code du core, dans core/php/smartclim.inc.php). Sans cette ligne,
// toute reference a une classe annexe casse en « Class not found » au runtime — invisible
// a `php -l` comme en CI.
require_once __DIR__ . '/../php/smartclim.inc.php';

class smartclim extends eqLogic {
  /*     * *************************Attributs****************************** */

  /*
  * Permet de définir les possibilités de personnalisation du widget (en cas d'utilisation de la fonction 'toHtml' par exemple)
  * Tableau multidimensionnel - exemple: array('custom' => true, 'custom::layout' => false)
  public static $_widgetPossibility = array();
  */

  // Bornes de l'intervalle de rafraîchissement (minutes), utilisées à l'écriture
  // (normaliserIntervalle(), appelée par preConfig_refresh_interval) et à la lecture
  // (intervalleRafraichissement()). Dupliquées en littéral (contexte non-PHP, donc pas
  // de référence possible à ces constantes) dans core/config/smartclim.config.ini
  // (défaut) et plugin_info/configuration.php (édité via son miroir configuration.txt ;
  // attributs min/max du champ HTML).
  const INTERVALLE_MIN = 1;
  const INTERVALLE_MAX = 1440;
  const INTERVALLE_DEFAUT = 5;

  // Pays du compte AUX Home retenu tant que l'utilisateur n'en a pas choisi un autre dans
  // la liste déroulante de la page de configuration. Valeur EN DUR, sans déduction depuis
  // le fuseau horaire de Jeedom : ce fuseau ne dit rien du pays d'un compte cloud (une
  // installation française réglée sur « Europe/Brussels » se voyait proposer BEL, cas
  // remonté en recette), et un pays faux échoue au login sur un message trompeur. Un
  // défaut simple et prévisible, que la liste déroulante rend trivial à corriger, vaut
  // mieux qu'une devinette.
  // ⚠️ Doit rester identique à la valeur de core/config/smartclim.config.ini, seul défaut
  // vu par config::byKeys() — donc par le chargement AJAX du formulaire.
  const PAYS_DEFAUT = 'FRA';

  // Région du compte AUX Cloud legacy (UC01 du domaine post-mvp/03-cloud-aux-legacy)
  // retenue tant que l'utilisateur n'en a pas choisi une autre dans la liste déroulante
  // dédiée. Contrairement à PAYS_DEFAUT, ce défaut n'a jamais besoin d'un repli — la
  // liste est FERMÉE (4 régions connues côté backend, § 6.4 de la spec technique).
  // ⚠️ Doit rester identique à une clé de smartclimAuxCloudApi::regions() et à la valeur
  // de core/config/smartclim.config.ini, seul défaut vu par config::byKeys() — donc par
  // le chargement AJAX du formulaire.
  const REGION_DEFAUT = 'EU';

  // Les mots de passe des comptes AUX Home et AUX Cloud legacy sont chiffrés au repos
  // par le core Jeedom.
  public static $_encryptConfigKey = array('auxhome_password', 'auxcloud_password');

  // Verrou anti double-scan (UC03, § 6.3 de la spec technique) : cache::byKey() puis
  // cache::set() ne sont PAS atomiques — une atténuation (double-clic, deux onglets),
  // jamais un mutex. TTL court pour qu'un fatal ne laisse pas le plugin bloqué ;
  // libéré dans un finally quoi qu'il arrive.
  const CLE_CACHE_VERROU_SCAN = 'smartclim::scan_en_cours';
  const DUREE_VERROU_SCAN = 60;

  // Clés de configuration PAR ÉQUIPEMENT du profil de capacités (UC04). Espaces de
  // nommage DISJOINTS entre le profil détecté (CLE_CONF_CAPACITES) et les bornes
  // personnalisées (CLE_CONF_TEMP_*) : c'est cette séparation STRUCTURELLE, pas une
  // convention, qui garantit AC3 (une redétection n'écrit JAMAIS temp_min/temp_max/temp_pas).
  const CLE_CONF_CAPACITES = 'capacites';
  const CLE_CONF_TEMP_MIN = 'temp_min';
  const CLE_CONF_TEMP_MAX = 'temp_max';
  const CLE_CONF_TEMP_PAS = 'temp_pas';
  const VERSION_PROFIL = 1;

  // logicalId des 2 commandes info MÉTA (UC05) : produites par le PLUGIN, créées dans
  // TOUS les cas (y compris sur un équipement sans profil de capacités), contrairement
  // aux commandes de CONCEPT (les 6 historiques et les fonctions de confort livrées),
  // conditionnées à configuration.capacites['concepts'].
  const CMD_TRANSPORT = 'transport';
  const CMD_DERNIERE_MAJ = 'last_update';

  // logicalId des commandes ACTION (UC06, § 5.3 de la spec technique). Les entrées
  // mode_ / fan_ sont dérivées mécaniquement du profil de capacités : préfixe +
  // strtolower(<valeur générique>) (cf. § 6 — 'mode_cool', 'fan_turbo'...).
  const CMD_ON = 'on';
  const CMD_OFF = 'off';
  const CMD_CONSIGNE = 'set_target_temp';
  const PREFIXE_CMD_MODE = 'mode_';
  const PREFIXE_CMD_VITESSE = 'fan_';

  // UC01 du domaine post-mvp/06-ergonomie-jeedom (§ 6 de la spec technique) : widget de
  // tuile posé sur la commande info 'power'. WIDGET_TUILE est la valeur passée à
  // setTemplate() ; JETON_TUILE est le nom (SANS le '#') du jeton de substitution
  // consommé par smartclimCmd::toHtml() -> cmd::toHtml(), donc lu côté template comme
  // '#tuile_smartclim#'.
  const WIDGET_TUILE = 'smartclim::climatiseur';
  const JETON_TUILE = 'tuile_smartclim';

  // Marqueur d'unicité du masquage des commandes reprises par la tuile. Porté par la
  // CONFIGURATION DE LA COMMANDE 'power', jamais par celle de l'équipement : un
  // setConfiguration() sur l'eqLogic imposerait un save() -> postSave() ->
  // creerCommandesAction() -> poserWidgetTuile(), donc une récursion. cmd::save()
  // n'appelle pas eqLogic::postSave() : le marqueur y est inerte.
  const CLE_MASQUAGE_TUILE = 'tuile_masquage_joue';

  // UC01 du domaine post-mvp/04-fonctions-avancees (§ 5.4 de sa spec technique) :
  // suffixes des deux commandes action de chaque fonction de confort — <concept>_on /
  // <concept>_off, dérivés mécaniquement de smartclimCapabilities::conceptsConfortLivres().
  const SUFFIXE_CMD_ON = '_on';
  const SUFFIXE_CMD_OFF = '_off';

  // Déduplication d'ordre (AC7) : clé de cache = CLE_CACHE_DEDUP + id d'équipement +
  // empreinte du CONTENU de l'ordre (jamais l'équipement seul, § 7 — sinon AC10
  // échouerait). DUREE_DEDUP_ORDRE = fenêtre anti-double-bip.
  const CLE_CACHE_DEDUP = 'smartclim::ordre_recent::';
  const DUREE_DEDUP_ORDRE = 10;

  // Mémoire des valeurs COMMANDÉES (dette D-MVP05-07, § 9 de la spec technique) : une
  // entrée de cache par équipement, purgée dès expiration de la période de grâce.
  const CLE_CACHE_ORDRES = 'smartclim::ordres::';
  const DUREE_GRACE = 60;

  // Cadencement du cycle de rafraîchissement (UC07, § 5 de la spec technique).
  const CLE_CACHE_DERNIER_CYCLE = 'smartclim::dernier_cycle';
  const DUREE_MEMOIRE_CYCLE = self::INTERVALLE_MAX * 60 * 2;  // 48 h, > intervalle max
  const MARGE_ECHEANCE_CYCLE = 30;                            // secondes
  const CMD_RAFRAICHIR = 'refresh';                           // logicalId générique

  // Mémoire du dernier incident de CONNEXION du cycle automatique (UC08, § 5 de la
  // spec technique) : une entrée UNIQUE et GLOBALE (portée sur le compte cloud, pas sur
  // un appareil — un appareil individuellement injoignable est déjà décrit par son
  // `online = false`). TTL = DUREE_MEMOIRE_CYCLE, réutilisée sans constante nouvelle :
  // au-delà, l'information n'a plus de valeur de diagnostic, et un cron arrêté ne doit
  // pas laisser une erreur affichée indéfiniment.
  const CLE_CACHE_DERNIER_INCIDENT = 'smartclim::dernier_incident';

  // UC01 du domaine post-mvp/01-transport-broadlink-lan (§ 5.2/D-POSTMVP0101-08/09) :
  // clés PERSONNALISÉES par équipement (config, chaîne vide = "non personnalisé") pour
  // l'adresse LAN saisie à la main — espace de nommage DISJOINT de la mémoire de sonde
  // ci-dessous, même invariant que CLE_CONF_TEMP_* / CLE_CONF_CAPACITES : une redétection
  // n'écrase jamais une saisie manuelle.
  const CLE_CONF_LAN_IP = 'lan_ip';
  const CLE_CONF_LAN_MAC = 'lan_mac';

  // Mémoire de sonde LAN DÉTECTÉE (cache, jetable), indexée par MAC normalisée. Support
  // DISTINCT de CLE_CONF_LAN_* ci-dessus — c'est cette séparation par SUPPORT, pas une
  // convention de nommage, qui garantit qu'un scan n'écrase jamais une saisie manuelle.
  const CLE_CACHE_LAN = 'smartclim::lan_appareil::';
  const DUREE_MEMOIRE_LAN = 86400; // 24 h

  // Budget de temps GLOBAL de la phase LAN d'un scan (D-POSTMVP0101-04) : arrêt DUR
  // évalué avant chaque appareil, dans les deux phases de scannerReseauLocal() — jamais un
  // budget seulement indicatif (cf. smartclimAuxHomeApi § 8.3 pour le précédent qui a
  // motivé cette exigence).
  const BUDGET_LAN = 18;

  // Budget de temps PAR APPAREIL de la lecture d'état LAN (UC02 de ce domaine, § 6 de sa
  // spec technique) : session + CHARGE_ETAT + CHARGE_INFO + rejeu éventuel. Borné par le
  // budget GLOBAL restant (min(BUDGET_LECTURE_LAN, restant), plancher 1 s) — un appareil
  // lent ne peut donc pas consommer le budget des suivants, le pire cas total reste
  // BUDGET_LAN.
  const BUDGET_LECTURE_LAN = 8;

  // Budget de temps GLOBAL d'un ordre d'écriture LAN (UC03 de ce domaine, § 7 de sa
  // spec technique) : hello + session + lecture de base + écriture, chronométré depuis
  // l'entrée d'envoyerOrdreLan(). RESERVE_ECRITURE_LAN documente, à ce niveau, la même
  // réserve que smartclimBroadlinkLan::RESERVE_ECRITURE (3 s, vérifiée là-bas juste
  // avant l'émission) — dupliquée à dessein, un transport ne doit pas dépendre d'une
  // constante définie ailleurs (même motif que les autres duplications du plugin).
  const BUDGET_ORDRE_LAN = 12;
  const RESERVE_ECRITURE_LAN = 3;

  // UC03 du domaine post-mvp/02-strategies-de-transport (§ 2.3 de sa spec technique) :
  // budget d'une redécouverte par diffusion insérée derrière un échec de sonde
  // unicast. BUDGET_REDECOUVERTE_LAN = 3 s EXACTEMENT (vérifié dans
  // smartclimBroadlinkLan::diffuserParExtensionSockets()/diffuserParFluxNatif()) : la
  // retransmission du hello exige un écoulement >= INTERVALLE_RENVOI (2 s), donc un
  // budget de 2 s ne la laisserait JAMAIS partir — 3 s délivrent les deux émissions
  // (t=0, t≈2 s) et ~1 s d'écoute après la seconde.
  //
  // ⚠️ M2 (revue de plan) — TROIS constantes de « réserve » coexistent désormais, deux
  // seulement sont arithmétiques : smartclimBroadlinkLan::RESERVE_ECRITURE (3 s, refus
  // d'émettre un ordre sous ce reliquat) et RESERVE_ECRITURE_LAN ci-dessus (3 s,
  // PUREMENT DOCUMENTAIRE, jamais lue) sont deux constantes DISTINCTES ; celle-ci,
  // RESERVE_APRES_REDECOUVERTE_LAN (6 s), est la troisième et est ARITHMÉTIQUE : elle
  // borne la fenêtre de diffusion contre le budget restant d'un ordre interactif, pour
  // qu'il reste de quoi rejouer l'échange après une redécouverte réussie.
  const BUDGET_REDECOUVERTE_LAN = 3;
  const RESERVE_APRES_REDECOUVERTE_LAN = 6;

  // Contextes techniques de smartclimException RÉSERVÉS à la sonde LAN interactive
  // (UC03 de ce domaine, § 4.2/4.3 de sa spec technique) : distinguent, au sein d'un
  // même TYPE_RESEAU, deux messages curatés différents (adresse jamais connue vs MAC
  // divergente à cette adresse). messageErreurLan() est l'UNIQUE endroit qui les
  // traduit — même règle que CONTEXTE_REQUETE_INITIALE pour messageErreurAuxHome().
  const CONTEXTE_LAN_ADRESSE_INCONNUE = 'lan_adresse_inconnue';
  const CONTEXTE_LAN_MAC_DIVERGENTE = 'lan_mac_divergente';

  // Correctif post-review UC03 du domaine post-mvp/03-cloud-aux-legacy (point 2) :
  // marqueur LOCAL à envoyerOrdreAuxCloud() — « ce message est DÉJÀ curaté à la
  // source, ne le retraduis pas via messageErreurAuxCloud() » — DISTINCT de
  // smartclimAuxCloudApi::CONTEXTE_ETAT_INCONNU (qui, lui, garde son sens RÉEL :
  // « aucune des 4 sources d'etatMarcheCourant() n'a permis de conclure »). Les deux
  // sont testés côte à côte au point de retraduction : un contexte dont le SENS ment
  // (ex. réutiliser CONTEXTE_ETAT_INCONNU pour « budget insuffisant ») est un défaut à
  // part entière sur ce plugin — un futur lecteur testant getContexte() croirait lire
  // le cas qu'il ne lit pas.
  const CONTEXTE_MESSAGE_DEJA_CURATE = 'message_deja_curate';

  // UC01 du domaine post-mvp/02-strategies-de-transport (§ 5.2 de sa spec technique) :
  // clé de configuration PAR ÉQUIPEMENT du mode de transport choisi (AUTO/LOCAL/CLOUD,
  // cf. smartclimTransport). CLE_CACHE_DERNIER_CYCLE_LAN / INTERVALLE_CYCLE_LAN
  // cadencent le cycle de SONDE LAN périodique, DÉCOUPLÉ de refresh_interval (§ 6 de la
  // spec technique) : cadence FIXE de 15 min, arbitrée en gate utilisateur le
  // 2026-09-07 (§ 0.2).
  const CLE_CONF_TRANSPORT_MODE = 'transport_mode';
  const CLE_CACHE_DERNIER_CYCLE_LAN = 'smartclim::dernier_cycle_lan';
  const INTERVALLE_CYCLE_LAN = 900;

  // UC02 du domaine post-mvp/02-strategies-de-transport (§ 2.2/5.1 de sa spec
  // technique) : 6ᵉ mémoire de cache — compteurs d'échecs LAN/cloud PAR ÉQUIPEMENT (clé
  // par getId(), JAMAIS par MAC — cf. § 2.1 de la spec technique, branche (c2) écartée :
  // les 5 écrivains de la mémoire de sonde LAN ne s'accordent pas sur la MAC utilisée).
  // Non chiffrée : aucun secret (3 entiers, 2 horodatages). TTL alignée sur
  // DUREE_MEMOIRE_LAN (24 h) : les deux décrivent ce qu'on sait du lien LAN de cet
  // appareil.
  const CLE_CACHE_ECHECS_TRANSPORT = 'smartclim::echecs_transport::';
  const DUREE_MEMOIRE_ECHECS_TRANSPORT = 86400;

  // UC02 du domaine post-mvp/03-cloud-aux-legacy (§ 4/6.2 de sa spec technique) : clés de
  // configuration PAR ÉQUIPEMENT de la découverte legacy — identité stable, non sensible,
  // séparation par NATURE de donnée (pas par commodité) de la mémoire chiffrée
  // ci-dessous, qui porte le SECRET d'appairage.
  const CLE_CONF_AUXCLOUD_ENDPOINT_ID = 'auxcloud_endpoint_id';
  const CLE_CONF_AUXCLOUD_PRODUCT_ID = 'auxcloud_product_id';
  const CLE_CONF_AUXCLOUD_DEVICETYPE_FLAG = 'auxcloud_devicetype_flag';
  const CLE_CONF_AUXCLOUD_FAMILY_ID = 'auxcloud_family_id';
  const CLE_CONF_AUXCLOUD_PARTAGE = 'auxcloud_partage';

  // 7ᵉ mémoire de cache du plugin (§ 4 de la spec technique) : `cookie` (secret
  // d'appairage) + `devSession` (jeton périssable) d'un équipement legacy, CHIFFRÉE,
  // clé par getId() (jamais par MAC ni par endpointId — même leçon que
  // CLE_CACHE_ECHECS_TRANSPORT). ⚠️ Écrite SANS lecteur dans cette UC (§ 4.1 — contrat
  // imposé à l'UC03 : cette mémoire sera le plus souvent EXPIRÉE, le pilotage devra
  // savoir la re-obtenir par un dev/query ciblé sur CLE_CONF_AUXCLOUD_FAMILY_ID). Les
  // postConfig_auxcloud_* ne la purgent PAS (§ 4 : itérer sur tout le parc ne se
  // justifie pas, la TTL de 30 min suffit, aucun secret DE COMPTE dedans).
  const CLE_CACHE_APPAREIL_AUXCLOUD = 'smartclim::appareil_auxcloud::';
  const DUREE_MEMOIRE_APPAREIL_AUXCLOUD = 1800;

  // UC03 du domaine post-mvp/03-cloud-aux-legacy (§ 3.5/5.3 de sa spec technique) : clé
  // d'équipement du mécanisme d'ajustement SANS MODIFICATION DE CODE d'AC6 — inverse la
  // convention 'actif'/'fixe' des deux axes d'oscillation, au décodage COMME à
  // l'encodage (smartclimAuxCloudApi::decoderParametres()/appliquerOrdre()).
  const CLE_CONF_AUXCLOUD_SWING_INVERSE = 'auxcloud_swing_inverse';

  // Cadencement du 3ᵉ cycle cron (UC03 du domaine post-mvp/03-cloud-aux-legacy, § 3.4/D4
  // de sa spec technique) — marqueur SÉPARÉ de CLE_CACHE_DERNIER_CYCLE_LAN : les deux
  // cycles dérivent et ne coïncident donc pas systématiquement, DÉCOUPLÉS de
  // refresh_interval pour la même raison que le cycle LAN (plugin::cron est PARTAGÉ par
  // tous les plugins). Budgets § 4 : BUDGET_CYCLE_AUXCLOUD (cycle O(N) global, arrêt
  // dur), BUDGET_ETAT_AUXCLOUD (une lecture interactive), BUDGET_COMMANDE_AUXCLOUD +
  // RESERVE_ECRITURE_AUXCLOUD (écriture interactive, § 4.1). AGE_MAX_POWER_AUXCLOUD
  // s'écrit DÉRIVÉE, jamais en littéral (§ 3.3/D3) : un `power` plus vieux que le cycle
  // plus un tick n'est plus une observation, c'est une supposition.
  const CLE_CACHE_DERNIER_CYCLE_AUXCLOUD = 'smartclim::dernier_cycle_auxcloud';
  const INTERVALLE_CYCLE_AUXCLOUD = 900;
  const BUDGET_CYCLE_AUXCLOUD = 20;
  const BUDGET_ETAT_AUXCLOUD = 12;
  const BUDGET_COMMANDE_AUXCLOUD = 20;
  const RESERVE_ECRITURE_AUXCLOUD = 6;
  const AGE_MAX_POWER_AUXCLOUD = self::INTERVALLE_CYCLE_AUXCLOUD + 60;

  // UC03 du domaine post-mvp/05-temps-reel-et-demon (§ 5.4 de sa spec technique) :
  // cadencement de la SYNCHRONISATION de l'abonnement au relais WebSocket AUX Cloud
  // legacy (marqueur SÉPARÉ des trois cycles ci-dessus, DÉCOUPLÉ de refresh_interval,
  // même doctrine que CLE_CACHE_DERNIER_CYCLE_LAN). BUDGET_SYNC_RELAIS borne le temps
  // GLOBAL de cette synchro (session + un appareilAuxCloud() par équipement ciblé) —
  // en régime établi (session/jetons déjà en cache) elle coûte ZÉRO requête réseau.
  const CLE_CACHE_DERNIER_SYNC_RELAIS = 'smartclim::dernier_sync_relais';
  const INTERVALLE_SYNC_RELAIS = 600;
  const BUDGET_SYNC_RELAIS = 10;

  // Cadence du FILET de sécurité quand le push est actif (§ 3 de la spec technique) :
  // ×4 par rapport à INTERVALLE_CYCLE_AUXCLOUD (900 s) — un équipement dont le push est
  // confirmé actif n'est relu par la scrutation legacy que toutes les 3600 s, le push
  // devenant la source principale de fraîcheur (AC6). Marqueur SÉPARÉ des marqueurs de
  // cycle ci-dessus.
  const CLE_CACHE_DERNIER_FILET_AUXCLOUD = 'smartclim::dernier_filet_auxcloud';
  const INTERVALLE_FILET_AUXCLOUD = 3600;

  /*     * ***********************Methode static*************************** */

  /**
   * Code pays ISO-3166 alpha-3 du compte AUX Home (en-tête "country" du cloud).
   * Utilise la valeur configurée si conforme, sinon le défaut PAYS_DEFAUT. Repasse par la
   * même normalisation qu'à l'écriture (normaliserPays()) : une valeur non conforme
   * présente en base (restauration, script, écriture SQL directe) n'est jamais renvoyée
   * telle quelle — elle alimente un en-tête HTTP.
   *
   * @return string Code pays en majuscules (ex. "FRA") ; jamais vide.
   */
  public static function paysAuxHome() {
    $pays = self::normaliserPays(config::byKey('auxhome_country', 'smartclim'));
    if ($pays != '') {
      return $pays;
    }
    return self::PAYS_DEFAUT;
  }

  /**
   * Pays proposables pour le compte AUX Home : code ISO-3 => libellé traduit, trié par
   * libellé. Sert à peupler la liste déroulante de la page de configuration. Simple
   * délégation : ni la page de configuration ni le reste du plugin ne parlent
   * directement à une brique de transport (CLAUDE.md § Conventions).
   *
   * @return array<string,string>
   */
  public static function paysDisponiblesAuxHome() {
    return smartclimAuxHomeApi::paysDisponibles();
  }

  /**
   * Enveloppe des bornes de température PERSONNALISABLES par équipement — simple
   * délégation (CLAUDE.md § Conventions : aucun code propriétaire hors des adaptateurs
   * de transport / tables de smartclimCapabilities).
   *
   * NON consommée par UC04 : la barrière AUTORITAIRE reste preSave() (normalisation
   * serveur silencieuse), et le client (desktop/js/smartclim.js) duplique volontairement
   * l'enveloppe 5/35 en dur. Cette méthode existe pour UC05/UC06, qui en auront besoin
   * afin d'éviter une seconde source de vérité sur ces bornes.
   *
   * @return array{min:int,max:int,pasAutorises:array<int,string>}
   */
  public static function enveloppeTemperature() {
    return smartclimCapabilities::enveloppeBornes();
  }

  /**
   * Profil de capacités AFFICHABLE (chaînes déjà traduites) de plusieurs équipements,
   * indexé par ID d'équipement — sert directement de charge à sendVarToJS() (§ Server
   * vs Client de la spec technique UC04 : tout le rendu de texte est SERVEUR).
   *
   * @param smartclim[] $_eqLogics
   * @return array<int,array>
   */
  public static function profilsAffichables(array $_eqLogics) {
    $profils = array();
    foreach ($_eqLogics as $eqLogic) {
      if ($eqLogic instanceof smartclim) {
        $profils[$eqLogic->getId()] = $eqLogic->profilAffichable();
      }
    }
    return $profils;
  }

  /**
   * État de connexion AFFICHABLE (chaînes déjà traduites) de plusieurs équipements,
   * indexé par ID d'équipement (UC08, AC8, § 4.3 de la spec technique) — miroir exact
   * de profilsAffichables() : try/catch PAR équipement, ne lève jamais. Une lecture de
   * cache/commande en échec sur UN équipement ne doit pas casser toute la page admin.
   *
   * @param smartclim[] $_eqLogics
   * @return array<int,array>
   */
  public static function etatsConnexionAffichables(array $_eqLogics) {
    $etats = array();
    foreach ($_eqLogics as $eqLogic) {
      if (!($eqLogic instanceof smartclim)) {
        continue;
      }
      try {
        $etats[$eqLogic->getId()] = $eqLogic->etatConnexionAffichable();
      } catch (Throwable $t) {
        log::add('smartclim', 'error', 'État de connexion indisponible (équipement "' . self::neutraliserPourLog($eqLogic->getHumanName()) . '") : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
        $etats[$eqLogic->getId()] = array(
          'niveau' => 'neutre',
          'etat' => __('État indisponible — consultez les logs du plugin', __FILE__),
          'detail' => '',
          'transport' => '',
          'fraicheur' => '',
          'derniereDonnee' => '',
          'incidentLe' => '',
          // ⚠️ UC01 du domaine post-mvp/01-transport-broadlink-lan, § 5.2 de la spec
          // technique : ces 2 clés DOIVENT figurer aussi dans ce repli — piège jQuery
          // .text(undefined) (accesseur, pas mutateur) : un champ omis conserverait le
          // texte de l'équipement précédemment consulté.
          'lan' => '',
          'lanAdresse' => '',
          // UC01 du domaine post-mvp/02-strategies-de-transport, § 5.4 de sa spec
          // technique : MÊME piège .text(undefined), 8ᵉ point d'écriture (les 7 branches
          // de etatConnexionAffichable() + ce repli).
          'modeTransport' => '',
          // UC02 du domaine post-mvp/02-strategies-de-transport, § 8 de sa spec
          // technique : MÊME piège .text(undefined) — 8ᵉ point d'écriture de CETTE clé
          // (les 7 branches de etatConnexionAffichable() + ce repli).
          'repli' => '',
        );
      }
    }
    return $etats;
  }

  /**
   * Profil de capacités « vide » : repli UNIQUE utilisé À LA FOIS par appliquerCapacites()
   * (fusion) et profilAffichable() (rendu), pour que ces deux méthodes ne puissent
   * jamais diverger sur le cas « aucun profil encore détecté » — le chemin le PLUS
   * emprunté (aucun équipement créé par UC03 ne possède configuration.capacites), cf.
   * spec technique UC04 § « Profil de repli et ordre canonique ».
   *
   * @return array
   */
  private static function profilVide() {
    return array(
      'version' => self::VERSION_PROFIL,
      'concepts' => array(),
      'modes' => array(),
      'vitesses' => array(),
      'temperature' => smartclimCapabilities::bornesParDefaut(),
      'source' => '',
      'detecte_le' => 0,
    );
  }

  /**
   * Intervalle de rafraîchissement des équipements, en minutes.
   * Repasse par la même normalisation qu'à l'écriture (normaliserIntervalle()).
   *
   * @return int
   */
  public static function intervalleRafraichissement() {
    return self::normaliserIntervalle(config::byKey('refresh_interval', 'smartclim'));
  }

  /**
   * E-mail du compte AUX Home. Repasse par la même normalisation qu'à l'écriture
   * (normaliserEmail()) : une valeur non conforme en base (restauration, script,
   * écriture SQL directe) n'est jamais renvoyée telle quelle. C'était jusqu'ici la
   * seule des quatre clés lue en direct par compteConfigure(), sans barrière de
   * lecture ; UC02 s'appuiera sur cet accesseur pour alimenter le champ "account" du
   * protocole plutôt que de relire config::byKey() directement.
   *
   * @return string
   */
  public static function emailAuxHome() {
    return self::normaliserEmail(config::byKey('auxhome_email', 'smartclim'));
  }

  /**
   * Indique si le compte AUX Home est entièrement configuré (e-mail, mot de passe et
   * pays non vides). Garde-fou à appeler avant tout appel réseau vers le cloud AUX Home
   * (UC02/UC03) : le plugin ne doit jamais tenter une connexion avec des identifiants vides.
   *
   * @return bool
   */
  public static function compteConfigure() {
    return (self::emailAuxHome() != ''
      && config::byKey('auxhome_password', 'smartclim') != ''
      && self::paysAuxHome() != '');
  }

  /**
   * Teste une connexion complète au cloud AUX Home avec les identifiants actuellement
   * enregistrés. Appelle TOUJOURS smartclimAuxHomeApi::login() (jamais ::session()) :
   * aucune session ou clé mise en cache d'une tentative précédente n'est réutilisée
   * (AC6). Les deux gardes ci-dessous sont testées séparément — et non via
   * compteConfigure(), qui renvoie un simple booléen — car elles produisent chacune un
   * message distinct : si le pays manque, l'utilisateur doit savoir que c'est LUI qui
   * bloque, sans le confondre avec un compte non configuré (§ 4 de la spec technique).
   * ⚠️ Depuis l'adoption d'un défaut constant (PAYS_DEFAUT), paysAuxHome() ne renvoie
   * plus jamais de chaîne vide : la seconde garde est devenue théorique. Elle est
   * conservée à dessein — c'est le seul filet si ce défaut redevient un jour vide, et
   * elle ne coûte qu'une comparaison.
   *
   * @return string Message de succès en français, déjà traduit.
   * @throws smartclimException Message d'échec curaté en français (jamais de code brut).
   */
  public static function testerConnexionAuxHome() {
    if (self::emailAuxHome() == '' || config::byKey('auxhome_password', 'smartclim') == '') {
      throw new smartclimException(__('Compte AUX Home non configuré : renseignez l\'e-mail et le mot de passe', __FILE__), smartclimException::TYPE_AUTH);
    }
    if (self::paysAuxHome() == '') {
      throw new smartclimException(__('Pays du compte AUX Home introuvable : sélectionnez-le dans la liste du champ Pays', __FILE__), smartclimException::TYPE_AUTH);
    }
    try {
      smartclimAuxHomeApi::login();
    } catch (smartclimException $e) {
      // Le message technique ($e->getMessage()) est contractuellement exempt de secret
      // (docblock de smartclimException) : le journaliser en 'error' est indispensable
      // au diagnostic, faute de quoi 5 des 9 messages utilisateur affichés ci-dessous
      // disent « consultez les logs du plugin » alors que le log serait vide (finding
      // MAJOR de la revue croisée). AC4 reste respecté : ni secret ni trace de pile.
      log::add('smartclim', 'error', 'Test de connexion AUX Home échoué (type ' . $e->getType() . ') : ' . self::neutraliserPourLog($e->getMessage()));
      throw new smartclimException(self::messageErreurAuxHome($e->getType(), $e->getContexte()), $e->getType());
    }
    // UC08, AC9 : un test de connexion réussi vaut connexion réussie (§ 5.2 de la
    // spec technique).
    self::oublierIncident();
    return __('Connexion réussie au compte AUX Home', __FILE__);
  }

  /**
   * E-mail du compte AUX Cloud legacy (UC01 du domaine post-mvp/03-cloud-aux-legacy).
   * Repasse par normaliserEmail() — RÉUTILISÉE, mêmes pièges déjà traités (caractères de
   * contrôle, U+00A0, traitement en octets) que pour le compte AUX Home.
   *
   * @return string
   */
  public static function emailAuxCloud() {
    return self::normaliserEmail(config::byKey('auxcloud_email', 'smartclim'));
  }

  /**
   * Code de région du compte AUX Cloud legacy. Repasse par la même normalisation qu'à
   * l'écriture (normaliserRegion()) : une valeur non conforme en base n'est jamais
   * renvoyée telle quelle — elle sélectionne l'hôte cURL. JAMAIS vide (repli sur
   * REGION_DEFAUT).
   *
   * @return string
   */
  public static function regionAuxCloud() {
    $region = self::normaliserRegion(config::byKey('auxcloud_region', 'smartclim'));
    if ($region != '') {
      return $region;
    }
    return self::REGION_DEFAUT;
  }

  /**
   * Régions proposables pour le compte AUX Cloud legacy : code => libellé traduit. Sert
   * à peupler la liste déroulante (fermée) de la page de configuration. Simple
   * délégation : ni la page de configuration ni le reste du plugin ne parlent
   * directement à une brique de transport (CLAUDE.md § Conventions).
   *
   * @return array<string,string>
   */
  public static function regionsDisponiblesAuxCloud() {
    return smartclimAuxCloudApi::regionsDisponibles();
  }

  /**
   * Indique si le compte AUX Cloud legacy est entièrement configuré (e-mail, mot de
   * passe et région non vides). Garde-fou à appeler avant tout appel réseau vers ce
   * cloud (UC02/UC03). ⚠️ Méthode NOUVELLE, INDÉPENDANTE de compteConfigure() (compte
   * AUX Home) : les deux comptes sont configurables l'un sans l'autre (AC1/AC8).
   *
   * @return bool
   */
  public static function compteAuxCloudConfigure() {
    return (self::emailAuxCloud() != ''
      && config::byKey('auxcloud_password', 'smartclim') != ''
      && self::regionAuxCloud() != '');
  }

  /**
   * Teste une connexion complète au cloud AUX Cloud legacy avec les identifiants
   * actuellement enregistrés. Appelle TOUJOURS smartclimAuxCloudApi::login() (jamais
   * ::session()) : aucune session mise en cache d'une tentative précédente n'est
   * réutilisée (AC3/AC6 — un test doit prouver les identifiants, pas relire un cache).
   * Deux gardes séparées (identifiants / région) pour deux messages distincts, même
   * doctrine que testerConnexionAuxHome().
   *
   * @return string Message de succès en français, déjà traduit.
   * @throws smartclimException Message d'échec curaté en français.
   */
  public static function testerConnexionAuxCloud() {
    if (self::emailAuxCloud() == '' || config::byKey('auxcloud_password', 'smartclim') == '') {
      throw new smartclimException(__('Compte cloud historique non configuré : renseignez l\'e-mail et le mot de passe', __FILE__), smartclimException::TYPE_AUTH);
    }
    if (self::regionAuxCloud() == '') {
      throw new smartclimException(__('Région du compte cloud historique introuvable : sélectionnez-la dans la liste du champ Région', __FILE__), smartclimException::TYPE_AUTH);
    }
    try {
      smartclimAuxCloudApi::login();
    } catch (smartclimException $e) {
      log::add('smartclim', 'error', 'Test de connexion AUX Cloud legacy échoué (type ' . $e->getType() . ') : ' . self::neutraliserPourLog($e->getMessage()));
      throw new smartclimException(self::messageErreurAuxCloud($e->getType(), $e->getContexte()), $e->getType());
    }
    // ⚠️ Volontairement PAS d'appel à self::oublierIncident() ici : cette mémoire décrit
    // le cycle automatique AUX HOME, pas ce compte (§ 6.2 de la spec technique — piège
    // le plus coûteux de cette UC, un jet dessus régresserait silencieusement AC8).
    return __('Connexion réussie au compte cloud historique', __FILE__);
  }

  /**
   * Lance la SONDE DE DIAGNOSTIC du transport AUX Home et renvoie son rapport, prêt à
   * être affiché puis partagé. Appelée par la page admin (core/ajax/smartclim.ajax.php,
   * action 'sonderDiagnostic') ; la ligne de commande passe, elle, directement par
   * smartclimDiagnostic (elle a le droit d'ajouter des chemins et de retirer le
   * masquage, ce qu'un navigateur n'a pas).
   *
   * Cette méthode n'existe que pour une raison, la même que testerConnexionAuxHome() :
   * traduire un message TECHNIQUE de transport en message CURATÉ français
   * (messageErreurAuxHome()), en journalisant le technique au passage. Le rapport de
   * sonde lui-même n'est PAS curaté — c'est un rapport de développeur, il est fait pour
   * montrer les champs bruts du backend.
   *
   * @return array{texte:string, rapport:array, nomFichier:string}
   * @throws smartclimException Message d'échec curaté en français.
   */
  public static function sonderDiagnostic() {
    if (!self::compteConfigure()) {
      throw new smartclimException(__('Compte AUX Home non configuré : renseignez l\'e-mail, le mot de passe et le pays', __FILE__), smartclimException::TYPE_AUTH);
    }
    try {
      $rapport = smartclimDiagnostic::rapport();
    } catch (smartclimException $e) {
      log::add('smartclim', 'error', 'Sonde de diagnostic AUX Home échouée (type ' . $e->getType() . ') : ' . self::neutraliserPourLog($e->getMessage()));
      throw new smartclimException(self::messageErreurAuxHome($e->getType(), $e->getContexte()), $e->getType());
    }
    return array(
      'texte' => smartclimDiagnostic::texte($rapport),
      'rapport' => $rapport,
      'nomFichier' => 'smartclim-diagnostic-' . date('Ymd-His') . '.json',
    );
  }

  /**
   * Efface l'e-mail et le mot de passe du compte AUX Home enregistrés, puis purge la
   * session en cache. Action VOLONTAIRE de l'utilisateur (bouton dédié de la page de
   * configuration) : ce nettoyage ne vit jamais dans smartclim_remove(), appelée à
   * chaque désactivation du plugin, pas seulement à la désinstallation (cf.
   * .memory/specs/MVP/01-configuration-plugin-tech.md § 1.6 — une purge y détruirait
   * silencieusement les identifiants lors d'un simple cycle désactiver/réactiver). Le
   * pays et l'intervalle de rafraîchissement, qui ne sont pas des identifiants,
   * restent inchangés.
   *
   * @return string Message de confirmation en français, déjà traduit.
   */
  public static function effacerIdentifiantsAuxHome() {
    config::remove('auxhome_email', 'smartclim');
    config::remove('auxhome_password', 'smartclim');
    // config::remove() ne déclenche PAS postConfig_* (cf. spec technique § 0.4) : la
    // purge de session doit donc être explicite ici, pas déléguée au hook. Même motif
    // pour l'incident mémorisé (UC08, AC9, § 5.2 de la spec technique).
    smartclimAuxHomeApi::purgerSession();
    self::oublierIncident();
    return __('Identifiants effacés', __FILE__);
  }

  /**
   * Point d'entrée du bouton "Scanner les climatiseurs" DEPUIS UC01 du domaine
   * post-mvp/01-transport-broadlink-lan (D-POSTMVP0101-10) : COMPOSE la découverte/lecture
   * LAN (jamais levée) et le scan AUX Home existant (peut lever). Un utilisateur SANS
   * compte cloud configuré voit quand même ses résultats LAN — l'exception cloud est
   * CAPTURÉE et placée dans 'cloudErreur' (message DÉJÀ curaté), jamais propagée : sans
   * cela, AC1 de cette UC serait cassé pour tout utilisateur purement LAN. ⚠️
   * scannerAuxHome() reste PUBLIQUE et INCHANGÉE (contrat) : ce changement est LOCAL à
   * cette méthode.
   *
   * Depuis UC02 de ce domaine (§ 5.5 de sa spec technique) : 'profils'/'etatsConnexion'
   * FUSIONNENT ceux du LAN et ceux du cloud (array_replace, le CLOUD gagne en cas de
   * collision — reflet de l'ordre réel d'exécution, le cloud passant EN DERNIER).
   *
   * Depuis l'UC02 du domaine post-mvp/03-cloud-aux-legacy (§ 3.5/6.2 de sa spec
   * technique) : le scan legacy est INTERCALÉ entre le LAN et AUX Home — ordre de
   * composition LAN -> legacy -> AUX Home (D6). `array_replace` fait gagner le
   * DERNIER : intercaler (plutôt qu'ajouter en fin) laisse l'affichage existant d'un
   * équipement piloté par AUX Home strictement INCHANGÉ. Son exception éventuelle est
   * capturée SÉPARÉMENT ('legacyErreur'), même doctrine que 'cloudErreur' ci-dessus.
   *
   * @return array{resume:string, compteurs:array<string,int>, appareils:array, disparus:array, profils:array, etatsConnexion:array, lan:array, legacy:array, legacyErreur:string, cloudErreur:string, climatiseurs:array}
   */
  public static function scannerClimatiseurs() {
    $lan = self::scannerReseauLocal();

    $resultatLegacy = array(
      'resume' => '',
      'compteurs' => array(),
      'appareils' => array(),
      'disparus' => array(),
      'profils' => array(),
      'etatsConnexion' => array(),
    );
    $legacyErreur = '';
    try {
      $resultatLegacy = self::scannerAuxCloud();
    } catch (smartclimException $e) {
      // Message déjà curaté en français (scannerAuxCloud() journalise déjà le
      // technique avant de lever) : niveau warning côté JS, pas une panne — même
      // doctrine que 'cloudErreur' (D-POSTMVP0101-10).
      $legacyErreur = $e->getMessage();
    }

    $resultatCloud = array(
      'resume' => '',
      'compteurs' => array(),
      'appareils' => array(),
      'disparus' => array(),
      'profils' => array(),
      'etatsConnexion' => array(),
    );
    $cloudErreur = '';
    try {
      $resultatCloud = self::scannerAuxHome();
    } catch (smartclimException $e) {
      // Message déjà curaté en français (scannerAuxHome() journalise déjà le technique
      // avant de lever) : niveau warning côté JS, pas une panne (D-POSTMVP0101-10).
      $cloudErreur = $e->getMessage();
    }

    $lanProfils = isset($lan['profils']) && is_array($lan['profils']) ? $lan['profils'] : array();
    $lanEtatsConnexion = isset($lan['etatsConnexion']) && is_array($lan['etatsConnexion']) ? $lan['etatsConnexion'] : array();
    // UC04 du domaine post-mvp/01-transport-broadlink-lan (§ 5.10 de sa spec
    // technique) : le cloud passe EN DERNIER et gagne — reflète l'ordre RÉEL d'exécution
    // (LAN puis legacy puis AUX Home), donc le transport RÉELLEMENT en usage.
    $etatsConnexionFusionnes = array_replace($lanEtatsConnexion, $resultatLegacy['etatsConnexion'], $resultatCloud['etatsConnexion']);
    $profilsFusionnes = array_replace($lanProfils, $resultatLegacy['profils'], $resultatCloud['profils']);

    return array_merge($resultatCloud, array(
      'profils' => $profilsFusionnes,
      'etatsConnexion' => $etatsConnexionFusionnes,
      'lan' => $lan,
      'legacy' => $resultatLegacy,
      'legacyErreur' => $legacyErreur,
      'cloudErreur' => $cloudErreur,
      // UC04 (§ 5.10), enrichie à l'UC02 du domaine post-mvp/03-cloud-aux-legacy
      // (§ 6.2) : une ligne de synthèse par climatiseur (LAN oui/non, cloud historique
      // oui/non, cloud AUX Home oui/non, transport actif), calculée APRÈS les trois
      // phases.
      'climatiseurs' => self::lignesFusionScan($lan['appareils'], $resultatCloud['appareils'], $etatsConnexionFusionnes, $resultatLegacy['appareils']),
    ));
  }

  /**
   * Scanne le compte AUX Home configuré et crée un équipement Jeedom par appareil
   * découvert, ou rafraîchit les seuls champs cloud (auxhome_device_id, modele) d'un
   * équipement déjà connu (UC03). Écriture toujours CONDITIONNÉE : sur un équipement
   * existant, `save()` n'est appelé que si l'un de ces deux champs a réellement changé
   * (§ 7.1 de la spec technique — jamais sur getChanged() seul). Hors périmètre,
   * strictement : aucune smartclimCmd, aucune capacité, aucune trame HVAC, aucune
   * suppression/désactivation d'équipement (§ 0).
   *
   * @return array{resume:string, compteurs:array<string,int>, appareils:array, disparus:array, profils:array, etatsConnexion:array}
   * @throws smartclimException Message DÉJÀ curaté en français (via messageErreurAuxHome()).
   */
  public static function scannerAuxHome() {
    // Garde "zéro requête si compte non configuré" (§ 5.2/§ 6.2 de la spec technique) :
    // délègue à compteConfigure() (garde-fou déjà en place, appelé avant tout appel
    // réseau) et réutilise LE LITTÉRAL EXISTANT de testerConnexionAuxHome() (même clé
    // i18n, pas de nouvelle entrée).
    if (!self::compteConfigure()) {
      throw new smartclimException(__('Compte AUX Home non configuré : renseignez l\'e-mail et le mot de passe', __FILE__), smartclimException::TYPE_AUTH);
    }

    if (cache::byKey(self::CLE_CACHE_VERROU_SCAN)->getValue(null) !== null) {
      throw new smartclimException(__('Un scan est déjà en cours, réessayez dans quelques instants', __FILE__), smartclimException::TYPE_INTERNE);
    }
    cache::set(self::CLE_CACHE_VERROU_SCAN, '1', self::DUREE_VERROU_SCAN);

    try {
      try {
        $appareilsBruts = smartclimAuxHomeApi::listerAppareils();
      } catch (smartclimException $e) {
        // Point de bascule message TECHNIQUE -> message CURATÉ (même motif que
        // testerConnexionAuxHome()) : une smartclimException qui remonterait sans
        // curation mettrait un code métier brut dans le DOM.
        log::add('smartclim', 'error', 'Scan AUX Home échoué (type ' . $e->getType() . ') : ' . self::neutraliserPourLog($e->getMessage()));
        throw new smartclimException(self::messageErreurAuxHome($e->getType(), $e->getContexte()), $e->getType());
      }
      // UC08, AC9 : un scan réussi vaut connexion réussie (§ 5.2 de la spec technique).
      self::oublierIncident();

      $index = self::indexerEquipements();
      $compteurs = array(
        'trouves' => count($appareilsBruts),
        'crees' => 0,
        'existants' => 0,
        'ignores' => 0,
        'erreurs' => 0,
        'disparus' => 0,
      );
      $appareilsResultat = array();
      // logicalId des équipements rapprochés PENDANT ce scan : sert à la fois à
      // détecter un doublon dans la réponse cloud (jamais écrasé) et à calculer les
      // disparus (§ 5.2/§ 0 de la spec technique).
      $consommes = array();
      // Équipements touchés par CE scan (créés ou rapprochés) : $index['tous'] est
      // figé AVANT la boucle (indexerEquipements() plus haut) et ne contient donc pas
      // les équipements fraîchement créés — UC04 a besoin de leur profil affichable.
      $eqLogicsTouches = array();

      foreach ($appareilsBruts as $appareil) {
        $macNorm = self::normaliserMac($appareil['mac']);
        $identifiant = is_string($appareil['identifiant']) ? $appareil['identifiant'] : '';
        $nomAffiche = $appareil['nom'];

        // Un équipement en erreur ne doit jamais interrompre la boucle (CLAUDE.md,
        // robustesse cron) : try/catch PAR appareil, Exception puis Throwable.
        try {
          if ($macNorm === '' && $identifiant === '') {
            $compteurs['ignores']++;
            $appareilsResultat[] = self::ligneResultatScan($nomAffiche, $appareil['modele'], $macNorm, $identifiant, $appareil['enLigne'], 'ignore_identifiant');
            continue;
          }

          // Profil de capacités GÉNÉRIQUE de cet appareil (UC04). Appel DIRECT à la
          // brique de transport autorisé ici : smartclim:: EST le routeur (CLAUDE.md §
          // Conventions — « aucun appel direct à une classe annexe depuis un point
          // d'entrée externe » ne s'applique pas à ce fichier).
          $capacites = smartclimAuxHomeApi::capacitesAppareil($appareil);

          $logicalId = $macNorm !== '' ? ('mac:' . $macNorm) : ('auxhome:' . $identifiant);
          $eqLogic = self::chercherEquipementExistant($macNorm, $identifiant, $index);
          // Clé de "consommation" = l'identité RÉELLE de l'équipement rapproché (son
          // propre logicalId, potentiellement différent de $logicalId sur un
          // rapprochement par MAC inversée ou par auxhome_device_id), sinon le
          // logicalId qui SERA créé. Comparer sur $logicalId seul manquerait un
          // doublon de la réponse cloud pointant vers un équipement déjà rapproché
          // via un autre chemin (§ 5.2 de la spec technique).
          $cleConsommee = is_object($eqLogic) ? $eqLogic->getLogicalId() : $logicalId;

          if (in_array($cleConsommee, $consommes, true)) {
            $compteurs['ignores']++;
            // UC04 (post-mvp/01), § 5.7 : equipementId renseigné quand l'objet est
            // effectivement en main (rapproché via l'index), 0 sinon.
            $appareilsResultat[] = self::ligneResultatScan($nomAffiche, $appareil['modele'], $macNorm, $identifiant, $appareil['enLigne'], 'ignore_doublon', is_object($eqLogic) ? $eqLogic->getId() : 0);
            continue;
          }

          if (is_object($eqLogic)) {
            $consommes[] = $cleConsommee;
            // Écriture CONDITIONNÉE (§ 7.1, finding advisor) : comparer AVANT
            // d'écrire, ne JAMAIS se reposer sur getChanged() comme unique condition.
            $modifie = false;
            if ($eqLogic->getConfiguration('auxhome_device_id') !== $identifiant) {
              $eqLogic->setConfiguration('auxhome_device_id', $identifiant);
              $modifie = true;
            }
            if ($eqLogic->getConfiguration('modele') !== $appareil['modele']) {
              $eqLogic->setConfiguration('modele', $appareil['modele']);
              $modifie = true;
            }
            // Écriture conditionnée au même titre que les 2 champs ci-dessus (§
            // Invariant UC03 à préserver, spec technique UC04) : appliquerCapacites()
            // ne modifie/ne renvoie true QUE si le profil fusionné diverge du profil
            // stocké — un scan strictement identique n'émet donc AUCUN save().
            if ($eqLogic->appliquerCapacites($capacites)) {
              $modifie = true;
            }
            // UC04 du domaine post-mvp/01-transport-broadlink-lan (§ 5.4 de sa spec
            // technique) : intègre memoriserMacEquipement() à la MÊME chaîne $modifie,
            // AUCUN save() supplémentaire — c'est la migration du parc sans script.
            if (self::memoriserMacEquipement($eqLogic, $macNorm)) {
              $modifie = true;
            }
            if ($modifie) {
              $eqLogic->save();
            }
            $compteurs['existants']++;
            $appareilsResultat[] = self::ligneResultatScan($eqLogic->getName(), $appareil['modele'], $macNorm, $identifiant, $appareil['enLigne'], 'existant', $eqLogic->getId());
            $eqLogicsTouches[] = $eqLogic;
          } else {
            $eqLogic = self::creerEquipement($logicalId, $appareil['nom'], $macNorm, $index['noms'], $capacites, array('auxhome_device_id' => $identifiant, 'modele' => $appareil['modele']));
            $consommes[] = $logicalId;
            $compteurs['crees']++;
            $appareilsResultat[] = self::ligneResultatScan($eqLogic->getName(), $appareil['modele'], $macNorm, $identifiant, $appareil['enLigne'], 'cree', $eqLogic->getId());
            $eqLogicsTouches[] = $eqLogic;
          }

          // UC05 : crée les commandes info manquantes puis pousse l'état décodé des
          // trames déjà rapportées (aucun appel réseau nouveau). try/catch LOCAL
          // obligatoire (spec technique UC05, § Branchement) : sans lui, une exception
          // remonterait au catch par appareil ci-dessous, qui ajouterait une SECONDE
          // ligne 'erreur' pour un appareil déjà compté 'cree'/'existant'.
          try {
            $eqLogic->appliquerEtat(smartclimAuxHomeApi::etatAppareil($appareil));
          } catch (Throwable $t) {
            log::add('smartclim', 'error', 'AUX Home : application de l\'état impossible (identifiant=' . $identifiant . ') : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
          }
        } catch (Exception $e) {
          // Même neutralisation que le catch(Throwable) ci-dessous (finding sécurité LOW
          // de la revue croisée UC03, tour 2) : ces deux branches traitent le même motif,
          // un message d'exception venu du core ne doit pas pouvoir forger des lignes de log.
          log::add('smartclim', 'error', 'Scan AUX Home : erreur lors du traitement de l\'appareil (identifiant=' . $identifiant . ') : ' . self::neutraliserPourLog($e->getMessage()));
          $compteurs['erreurs']++;
          $appareilsResultat[] = self::ligneResultatScan($nomAffiche, $appareil['modele'], $macNorm, $identifiant, $appareil['enLigne'], 'erreur');
        } catch (Throwable $t) {
          // $t->getMessage() neutralisé AVANT journalisation (finding sécurité LOW de
          // la revue croisée UC03, tour 1) : cohérent avec le catch(Throwable) de
          // smartclim.ajax.php, qui filtre déjà toute valeur non garantie inoffensive.
          log::add('smartclim', 'error', 'Scan AUX Home : erreur inattendue lors du traitement de l\'appareil (identifiant=' . $identifiant . ') : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
          $compteurs['erreurs']++;
          $appareilsResultat[] = self::ligneResultatScan($nomAffiche, $appareil['modele'], $macNorm, $identifiant, $appareil['enLigne'], 'erreur');
        }
      }

      $disparus = self::appareilsDisparus($index, $consommes);
      $compteurs['disparus'] = count($disparus);

      return array(
        'resume' => self::resumeScan($compteurs),
        'compteurs' => $compteurs,
        'appareils' => $appareilsResultat,
        'disparus' => $disparus,
        // UC04 : profil de capacités (affichable) des équipements créés/existants
        // touchés par ce scan, pour rafraîchir l'affichage sans recharger la page.
        'profils' => self::profilsAffichables($eqLogicsTouches),
        // UC08 (AC8) : état de connexion des mêmes équipements, même motif — additif
        // pur, ne change rien pour un appelant qui l'ignore.
        'etatsConnexion' => self::etatsConnexionAffichables($eqLogicsTouches),
      );
    } finally {
      cache::delete(self::CLE_CACHE_VERROU_SCAN);
    }
  }

  /**
   * Scanne le compte AUX Cloud legacy configuré (UC02 du domaine
   * post-mvp/03-cloud-aux-legacy, § 6.2 de sa spec technique) : familles -> appareils
   * propres ET partagés -> jeu de paramètres réellement annoncé -> profil de capacités
   * générique. JUMEAU de scannerAuxHome() (même verrou CLE_CACHE_VERROU_SCAN, même
   * indexerEquipements(), même try/catch PAR appareil), avec DEUX divergences
   * assumées :
   *
   * - garde SILENCIEUSE (`!compteAuxCloudConfigure()` -> résultat vide + `log debug`,
   *   JAMAIS d'exception) : un compte legacy non configuré est le cas NOMINAL d'un
   *   utilisateur purement AUX Home (contrairement à scannerAuxHome(), appelée seule
   *   par le cron et devant donc signaler une absence de configuration) ;
   * - AUCUN calcul de « disparus » : appareilsDisparus() reste indexée sur
   *   `auxhome_device_id` et n'est PAS touchée ici.
   *
   * ⚠️ Correctif reviews croisées (findings major #1/#2, § 3.1/D1 de la spec
   * technique) : c'est ICI, et nulle part ailleurs, que se décide si la preuve
   * `estClimatiseur()` (portée par `preuve_climatiseur` sur chaque appareil renvoyé par
   * `listerAppareils()`) est EXIGÉE — elle ne l'est que pour un appareil NON rapproché
   * (`chercherEquipementExistant()` renvoie `null`). Un appareil DÉJÀ rapproché pose ses
   * clés `auxcloud_*`/`online` même sans cette preuve. Les motifs d'exclusion
   * indépendants du rapprochement (`pompe_a_chaleur`, `budget_epuise`) restent, eux,
   * établis par le transport et simplement RELAYÉS ici en ligne de résultat.
   *
   * @return array{resume:string, compteurs:array<string,int>, appareils:array, disparus:array, profils:array, etatsConnexion:array}
   * @throws smartclimException Message DÉJÀ curaté en français (via messageErreurAuxCloud()).
   */
  public static function scannerAuxCloud() {
    if (!self::compteAuxCloudConfigure()) {
      log::add('smartclim', 'debug', 'Scan AUX Cloud legacy ignoré : compte non configuré');
      return array(
        'resume' => '',
        'compteurs' => array(),
        'appareils' => array(),
        'disparus' => array(),
        'profils' => array(),
        'etatsConnexion' => array(),
      );
    }

    if (cache::byKey(self::CLE_CACHE_VERROU_SCAN)->getValue(null) !== null) {
      throw new smartclimException(__('Un scan est déjà en cours, réessayez dans quelques instants', __FILE__), smartclimException::TYPE_INTERNE);
    }
    cache::set(self::CLE_CACHE_VERROU_SCAN, '1', self::DUREE_VERROU_SCAN);

    try {
      try {
        $appareilsBruts = smartclimAuxCloudApi::listerAppareils();
      } catch (smartclimException $e) {
        log::add('smartclim', 'error', 'Scan AUX Cloud legacy échoué (type ' . $e->getType() . ') : ' . self::neutraliserPourLog($e->getMessage()));
        throw new smartclimException(self::messageErreurAuxCloud($e->getType(), $e->getContexte()), $e->getType());
      }

      $index = self::indexerEquipements();
      $compteurs = array(
        'trouves' => count($appareilsBruts),
        'crees' => 0,
        'existants' => 0,
        'ignores' => 0,
        'erreurs' => 0,
        'disparus' => 0,
      );
      $appareilsResultat = array();
      $consommes = array();
      $eqLogicsTouches = array();

      foreach ($appareilsBruts as $appareil) {
        $macNorm = self::normaliserMac($appareil['mac']);
        $identifiant = is_string($appareil['identifiant']) ? $appareil['identifiant'] : '';
        $nomAffiche = $appareil['nom'];

        try {
          if ($macNorm === '' && $identifiant === '') {
            $compteurs['ignores']++;
            $appareilsResultat[] = self::ligneResultatScan($nomAffiche, $appareil['type_produit'], $macNorm, $identifiant, $appareil['enLigne'], 'ignore_identifiant');
            continue;
          }

          // Correctif reviews croisées (findings major #1/#2) : les motifs d'exclusion
          // ÉTABLIS PAR LE TRANSPORT — indépendants de tout rapprochement Jeedom — sont
          // désormais RENVOYÉS par listerAppareils() au lieu d'être filtrés en silence.
          // Ils s'affichent, comme les deux autres cas d'ignoré, via ligneResultatScan().
          $motifTransport = isset($appareil['motif_exclusion']) ? $appareil['motif_exclusion'] : '';
          if ($motifTransport === 'pompe_a_chaleur') {
            $compteurs['ignores']++;
            $appareilsResultat[] = self::ligneResultatScan($nomAffiche, $appareil['type_produit'], $macNorm, $identifiant, $appareil['enLigne'], 'ignore_pompe_chaleur');
            continue;
          }
          if ($motifTransport === 'budget_epuise') {
            $compteurs['ignores']++;
            $appareilsResultat[] = self::ligneResultatScan($nomAffiche, $appareil['type_produit'], $macNorm, $identifiant, $appareil['enLigne'], 'ignore_budget');
            continue;
          }

          $logicalId = $macNorm !== '' ? ('mac:' . $macNorm) : ('auxcloud:' . $identifiant);
          // +1 étape de rapprochement (parEndpointAuxCloud), gardée par $_transport ===
          // TRANSPORT_AUX_CLOUD_LEGACY (§ 6.2 de la spec technique) — $_deviceId passé
          // '' : l'étape 7 (auxhome_device_id) reste réservée à AUX Home.
          $eqLogic = self::chercherEquipementExistant($macNorm, '', $index, smartclimCapabilities::TRANSPORT_AUX_CLOUD_LEGACY, $identifiant);
          $cleConsommee = is_object($eqLogic) ? $eqLogic->getLogicalId() : $logicalId;

          if (in_array($cleConsommee, $consommes, true)) {
            $compteurs['ignores']++;
            $appareilsResultat[] = self::ligneResultatScan($nomAffiche, $appareil['type_produit'], $macNorm, $identifiant, $appareil['enLigne'], 'ignore_doublon', is_object($eqLogic) ? $eqLogic->getId() : 0);
            continue;
          }

          // Correctif reviews croisées (finding major #1) : la preuve estClimatiseur()
          // (portée par 'preuve_climatiseur') n'est EXIGÉE que pour un appareil NON
          // rapproché — § 3.1/D1 de la spec technique : « sur un appareil déjà
          // rapproché, on pose seulement les clés auxcloud_* et online ». Un appareil
          // jamais vu par Jeedom, sans cette preuve, n'est PAS créé — quel que soit son
          // productId, catalogue ou non (produitsClimatiseurConnus() ne gouverne plus
          // cette décision, cf. son docblock côté transport).
          if (!is_object($eqLogic) && empty($appareil['preuve_climatiseur'])) {
            $compteurs['ignores']++;
            $appareilsResultat[] = self::ligneResultatScan($nomAffiche, $appareil['type_produit'], $macNorm, $identifiant, $appareil['enLigne'], 'ignore_non_climatiseur');
            continue;
          }

          $capacites = smartclimAuxCloudApi::capacitesAppareil($appareil);

          if (is_object($eqLogic)) {
            $consommes[] = $cleConsommee;
            $modifie = self::appliquerDecouverteAuxCloud($eqLogic, $appareil);
            if ($eqLogic->appliquerCapacites($capacites)) {
              $modifie = true;
            }
            if (self::memoriserMacEquipement($eqLogic, $macNorm)) {
              $modifie = true;
            }
            if ($modifie) {
              $eqLogic->save();
            }
            self::memoriserAppareilAuxCloud($eqLogic, $appareil);
            $compteurs['existants']++;
            $appareilsResultat[] = self::ligneResultatScan($eqLogic->getName(), $appareil['type_produit'], $macNorm, $identifiant, $appareil['enLigne'], 'existant', $eqLogic->getId());
            $eqLogicsTouches[] = $eqLogic;
          } else {
            $eqLogic = self::creerEquipement($logicalId, $appareil['nom'], $macNorm, $index['noms'], $capacites, array(
              self::CLE_CONF_AUXCLOUD_ENDPOINT_ID => $identifiant,
              self::CLE_CONF_AUXCLOUD_PRODUCT_ID => $appareil['type_produit'],
              self::CLE_CONF_AUXCLOUD_DEVICETYPE_FLAG => $appareil['devicetype_flag'],
              self::CLE_CONF_AUXCLOUD_FAMILY_ID => $appareil['famille'],
              self::CLE_CONF_AUXCLOUD_PARTAGE => $appareil['partage'] ? 1 : 0,
            ));
            $consommes[] = $logicalId;
            self::memoriserAppareilAuxCloud($eqLogic, $appareil);
            $compteurs['crees']++;
            $appareilsResultat[] = self::ligneResultatScan($eqLogic->getName(), $appareil['type_produit'], $macNorm, $identifiant, $appareil['enLigne'], 'cree', $eqLogic->getId());
            $eqLogicsTouches[] = $eqLogic;
          }

          try {
            // Correctif post-review UC03 (point 3) : $appareil vient de
            // normaliserAppareilLegacy()/listerAppareils(), qui ne porte JAMAIS
            // 'swing_inverse' (contrairement à appareilAuxCloud()) — sans cette
            // injection, un re-scan d'un équipement dont l'utilisateur a coché la case
            // décoderait l'oscillation avec la convention PAR DÉFAUT. $eqLogic est
            // disponible ICI dans les DEUX branches ci-dessus (rapproché ou fraîchement
            // créé) : injection possible sans forcer.
            $appareil['swing_inverse'] = (bool) $eqLogic->getConfiguration(self::CLE_CONF_AUXCLOUD_SWING_INVERSE);
            $eqLogic->appliquerEtat(smartclimAuxCloudApi::etatAppareil($appareil));
          } catch (Throwable $t) {
            log::add('smartclim', 'error', 'AUX Cloud legacy : application de l\'état impossible (identifiant=' . $identifiant . ') : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
          }
        } catch (Exception $e) {
          log::add('smartclim', 'error', 'Scan AUX Cloud legacy : erreur lors du traitement de l\'appareil (identifiant=' . $identifiant . ') : ' . self::neutraliserPourLog($e->getMessage()));
          $compteurs['erreurs']++;
          $appareilsResultat[] = self::ligneResultatScan($nomAffiche, $appareil['type_produit'], $macNorm, $identifiant, $appareil['enLigne'], 'erreur');
        } catch (Throwable $t) {
          log::add('smartclim', 'error', 'Scan AUX Cloud legacy : erreur inattendue lors du traitement de l\'appareil (identifiant=' . $identifiant . ') : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
          $compteurs['erreurs']++;
          $appareilsResultat[] = self::ligneResultatScan($nomAffiche, $appareil['type_produit'], $macNorm, $identifiant, $appareil['enLigne'], 'erreur');
        }
      }

      return array(
        'resume' => self::resumeScanAuxCloud($compteurs),
        'compteurs' => $compteurs,
        'appareils' => $appareilsResultat,
        'disparus' => array(),
        'profils' => self::profilsAffichables($eqLogicsTouches),
        'etatsConnexion' => self::etatsConnexionAffichables($eqLogicsTouches),
      );
    } finally {
      cache::delete(self::CLE_CACHE_VERROU_SCAN);
    }
  }

  /**
   * Phrase française résumant le résultat du scan AUX Cloud legacy (§ 6.2 de la spec
   * technique) — réutilise les fragments EXISTANTS de resumeScan() (`%d créé(s)`, `%d
   * déjà connu(s)`, `%d ignoré(s)`, `%d en erreur`) : aucune clé i18n nouvelle. Pas de
   * fragment « disparus » (scannerAuxCloud() n'en calcule jamais).
   *
   * @param array $_compteurs
   * @return string
   */
  private static function resumeScanAuxCloud(array $_compteurs) {
    if ($_compteurs['trouves'] === 0) {
      return __('Aucun climatiseur trouvé sur ce compte', __FILE__);
    }
    $fragments = array();
    $fragments[] = sprintf(__('%d climatiseur(s) trouvé(s) sur le compte', __FILE__), $_compteurs['trouves']);
    if ($_compteurs['crees'] > 0) {
      $fragments[] = sprintf(__('%d créé(s)', __FILE__), $_compteurs['crees']);
    }
    if ($_compteurs['existants'] > 0) {
      $fragments[] = sprintf(__('%d déjà connu(s)', __FILE__), $_compteurs['existants']);
    }
    if ($_compteurs['ignores'] > 0) {
      $fragments[] = sprintf(__('%d ignoré(s)', __FILE__), $_compteurs['ignores']);
    }
    if ($_compteurs['erreurs'] > 0) {
      $fragments[] = sprintf(__('%d en erreur', __FILE__), $_compteurs['erreurs']);
    }
    return implode(', ', $fragments);
  }

  /**
   * Pose les 5 clés `auxcloud_*` d'un équipement EXISTANT, EN COMPARANT AVANT D'ÉCRIRE
   * (§ 6.2 de la spec technique, même doctrine que le bloc équivalent AUX Home) — jamais
   * de save() ici, c'est à l'appelant de décider.
   *
   * @param smartclim $_eq
   * @param array $_appareil Ligne normalisée par smartclimAuxCloudApi::listerAppareils().
   * @return bool true si l'objet a été modifié.
   */
  private static function appliquerDecouverteAuxCloud(smartclim $_eq, array $_appareil) {
    $modifie = false;
    $paires = array(
      self::CLE_CONF_AUXCLOUD_ENDPOINT_ID => is_string($_appareil['identifiant']) ? $_appareil['identifiant'] : '',
      self::CLE_CONF_AUXCLOUD_PRODUCT_ID => is_string($_appareil['type_produit']) ? $_appareil['type_produit'] : '',
      self::CLE_CONF_AUXCLOUD_DEVICETYPE_FLAG => is_string($_appareil['devicetype_flag']) ? $_appareil['devicetype_flag'] : '',
      self::CLE_CONF_AUXCLOUD_FAMILY_ID => is_string($_appareil['famille']) ? $_appareil['famille'] : '',
      self::CLE_CONF_AUXCLOUD_PARTAGE => !empty($_appareil['partage']) ? 1 : 0,
    );
    foreach ($paires as $cle => $valeur) {
      if ($_eq->getConfiguration($cle) !== $valeur) {
        $_eq->setConfiguration($cle, $valeur);
        $modifie = true;
      }
    }
    return $modifie;
  }

  /**
   * Mémorise `cookie`/`devSession` d'un appareil legacy en cache CHIFFRÉ, clé par
   * `getId()` (§ 4/6.2 de la spec technique — JAMAIS par MAC ni par `endpointId`, même
   * leçon que CLE_CACHE_ECHECS_TRANSPORT). Sans effet si `cookie` est vide (appareil pas
   * encore enregistré, ou jeton illisible) : ne mémorise JAMAIS un secret vide sous une
   * clé valide.
   *
   * ⚠️ UC03 du domaine post-mvp/03-cloud-aux-legacy : DÉLÈGUE désormais à
   * memoriserJetonsAuxCloud() (méthode d'instance, § 5.3 de sa spec technique) — pour
   * qu'un SEUL endroit écrive le format de cette mémoire, jamais deux formats
   * potentiellement divergents (celui du scan, celui du pilotage continu).
   *
   * @param smartclim $_eq
   * @param array $_appareil
   */
  private static function memoriserAppareilAuxCloud(smartclim $_eq, array $_appareil) {
    if (empty($_appareil['cookie'])) {
      return;
    }
    $_eq->memoriserJetonsAuxCloud(array(
      'cookie' => $_appareil['cookie'],
      'dev_session' => isset($_appareil['dev_session']) ? $_appareil['dev_session'] : '',
    ));
  }

  /**
   * Phase LAN du scan (UC01 du domaine post-mvp/01-transport-broadlink-lan, ENRICHIE en
   * UC02 de LECTURE D'ÉTAT — § 5.5 de sa spec technique) : compose la DÉCOUVERTE par
   * diffusion (phase 1) et la sonde des équipements déjà connus porteurs d'une adresse
   * LAN, jamais rencontrés en phase 1 (phase 2, AC3). Budget GLOBAL BUDGET_LAN, arrêt DUR
   * évalué AVANT chaque appareil dans les DEUX phases (D-POSTMVP0101-04). NE LÈVE JAMAIS
   * (AC4) : toute anomalie devient un statut/compteur.
   *
   * ⚠️ Le déclencheur reste EXCLUSIVEMENT le scan MANUEL (§ 8.1 de la spec technique
   * UC02) : `cron()` et `rafraichirMaintenant()` restent cloud pur, inchangés — brancher
   * le LAN sur le cycle automatique arbitrerait LAN vs cloud, hors périmètre de ce
   * domaine (post-mvp/02).
   *
   * @return array{resume:string, compteurs:array<string,int>, appareils:array, profils:array, etatsConnexion:array}
   */
  private static function scannerReseauLocal() {
    $debut = microtime(true);
    $compteurs = array(
      'trouves' => 0,
      'etablies' => 0,
      'reutilisees' => 0,
      'refusees' => 0,
      'injoignables' => 0,
      'occupees' => 0,
      'verrouillees' => 0,
      'mac_divergentes' => 0,
      'non_sondes' => 0,
      // UC02 : succès/échec du DÉCODAGE, distinct du succès/échec de la SESSION
      // ci-dessus (un appareil Broadlink non-climatiseur authentifie très bien, § 3.4).
      'etats_lus' => 0,
      'etats_illisibles' => 0,
      // UC04 du domaine post-mvp/01-transport-broadlink-lan (§ 5.5 de sa spec
      // technique) : créations LAN (preuve STATUT_ETAT_LU) et doublons ignorés au sein
      // de ce MÊME scan.
      'crees' => 0,
      'ignores' => 0,
    );
    $appareils = array();
    $rencontres = array(); // MAC (imprimable) déjà traitées, phases 1 et 2 confondues.
    $diffusionIndisponible = false;
    // Équipements TOUCHÉS par ce scan LAN (lecture appliquée), pour rafraîchir l'affichage
    // sans recharger la page — même motif que scannerAuxHome() (UC04/UC08).
    $eqLogicsTouches = array();
    // UC04 (post-mvp/01), § 5.5 de sa spec technique : id des équipements déjà
    // rapprochés PENDANT ce scan LAN — empêche deux appareils découverts de revendiquer
    // le même équipement (et donc d'écrire l'un sur l'autre via une collision de MAC
    // inversée). ⚠️ Reste DISJOINT de $consommes (scan cloud) : ne sert qu'ici.
    $rapprochesLan = array();

    // Une SEULE requête SQL pour tout le scan LAN (D-POSTMVP0101-04, review UC02) :
    // réutilisée en phase 1 (rapprochement par MAC, § 8.2 de la spec technique UC02) ET
    // en phase 2 (qui refaisait un eqLogic::byType() dédié avant UC02).
    $index = self::indexerEquipements();

    try {
      $decouverts = smartclimBroadlinkLan::decouvrir();
    } catch (smartclimException $e) {
      // TYPE_INTERNE = aucun chemin de diffusion disponible sur cet hôte
      // (D-POSTMVP0101-03) : dégradation documentée, jamais un niveau 'error' (AC4).
      log::add('smartclim', 'warning', 'Broadlink LAN : découverte indisponible sur cet hôte : ' . self::neutraliserPourLog($e->getMessage()));
      $decouverts = array();
      $diffusionIndisponible = true;
    } catch (Throwable $t) {
      log::add('smartclim', 'warning', 'Broadlink LAN : découverte impossible : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
      $decouverts = array();
    }
    $compteurs['trouves'] = count($decouverts);

    foreach ($decouverts as $appareil) {
      if ((microtime(true) - $debut) >= self::BUDGET_LAN) {
        $compteurs['non_sondes'] += (count($decouverts) - count($rencontres));
        break;
      }
      try {
        $mac = $appareil['mac'];
        $rencontres[$mac] = true;

        // UC01 du domaine post-mvp/02-strategies-de-transport (§ 5.5 de sa spec
        // technique, AC5) : chercherEquipementExistant() REMONTÉ avant lireEtat() — un
        // lookup PUREMENT EN MÉMOIRE sur $index, sans dépendance au résultat de la
        // lecture. Un équipement rapproché ET en mode CLOUD n'ouvre AUCUNE session et
        // n'écrit AUCUNE mémoire de sonde.
        $eqLogicRapprocheAvantLecture = self::chercherEquipementExistant($mac, '', $index, smartclimCapabilities::TRANSPORT_BROADLINK_LAN);
        if (is_object($eqLogicRapprocheAvantLecture) && smartclimTransport::mode($eqLogicRapprocheAvantLecture) === smartclimTransport::MODE_CLOUD) {
          $appareils[] = self::ligneResultatLan($appareil['nom'], $mac, $appareil['ip'], $appareil['type_appareil'], 'ignore_mode_cloud', self::libelleStatutLan('ignore_mode_cloud'), $eqLogicRapprocheAvantLecture->getId());
          continue;
        }

        $budgetRestant = self::BUDGET_LAN - (microtime(true) - $debut);
        $lecture = smartclimBroadlinkLan::lireEtat($appareil, max(1, min(self::BUDGET_LECTURE_LAN, $budgetRestant)));
        self::compterStatutLan($compteurs, $lecture['session']);
        self::compterStatutLecture($compteurs, $lecture['statut']);
        self::memoriserSondeLan($mac, array(
          'ip' => $appareil['ip'],
          'port' => $appareil['port'],
          'type_appareil' => $appareil['type_appareil'],
          'nom' => $appareil['nom'],
          'verrouille' => $appareil['verrouille'],
          'statut' => $lecture['statut'],
          'vu_le' => $appareil['vu_le'],
          'echec_le' => self::statutEnEchec($lecture['statut']) ? time() : 0,
        ));

        // UC04 du domaine post-mvp/01-transport-broadlink-lan (§ 5.5 de sa spec
        // technique) : rapprochement (déjà fait ci-dessus, § 5.5 de la spec technique de
        // ce domaine) PUIS, à défaut, création conditionnée à la PREUVE STATUT_ETAT_LU —
        // critère de PREUVE, pas de joignabilité (§ 5.5, un appareil Broadlink
        // non-climatiseur authentifie très bien mais ne rend pas de charge exploitable).
        // C'est le SEUL acte irréversible de cette UC.
        $eqLogicRapproche = $eqLogicRapprocheAvantLecture;
        if (!is_object($eqLogicRapproche) && $lecture['statut'] === smartclimBroadlinkLan::STATUT_ETAT_LU) {
          $eqLogicRapproche = self::creerEquipement('mac:' . $mac, $appareil['nom'], $mac, $index['noms'], smartclimBroadlinkLan::capacitesAppareil($lecture), array());
          $compteurs['crees']++;
          // Insertion IMMÉDIATE dans l'index EN MÉMOIRE (§ 5.5) : empêche un second
          // appareil de MAC inversée de créer un doublon DANS CE MÊME SCAN. ⚠️ JAMAIS
          // dans $index['tous'] (réservé au balayage de la phase 2) : l'appareil est
          // déjà dans $rencontres.
          $index['parLogicalId'][$eqLogicRapproche->getLogicalId()] = $eqLogicRapproche;
          $index['parMac'][$mac] = $eqLogicRapproche;
          $index['noms'][$eqLogicRapproche->getName()] = true;
        }

        $statutLigne = $lecture['statut'];
        $equipementId = 0;
        if (is_object($eqLogicRapproche)) {
          $equipementId = $eqLogicRapproche->getId();
          if (isset($rapprochesLan[$eqLogicRapproche->getId()])) {
            // Deux appareils découverts pointent vers le MÊME équipement DANS CE SCAN
            // (§ 5.5) : aucun état appliqué — c'est ce qui empêche deux appareils
            // d'écrire l'un sur l'autre via une collision de MAC inversée.
            log::add('smartclim', 'warning', 'Broadlink LAN : deux appareils détectés se rapprochent du même équipement "' . self::neutraliserPourLog($eqLogicRapproche->getHumanName()) . '" — ignoré');
            $compteurs['ignores']++;
            $statutLigne = 'ignore_doublon';
          } else {
            $rapprochesLan[$eqLogicRapproche->getId()] = true;
            if (self::memoriserMacEquipement($eqLogicRapproche, $mac)) {
              $eqLogicRapproche->save();
            }
            // UC02 du domaine post-mvp/02-strategies-de-transport (§ 5.3/5.4 de sa
            // spec technique) : compteur de repli, JUSTE AVANT appliquerLectureLan().
            $eqLogicRapproche->noterOperationLan($lecture['statut']);
            self::appliquerLectureLan($eqLogicRapproche, $lecture);
            $eqLogicsTouches[] = $eqLogicRapproche;
          }
        }
        // UC04 (§ 5.5) : ligne poussée APRÈS le rapprochement, pour porter equipementId.
        $appareils[] = self::ligneResultatLan($appareil['nom'], $mac, $appareil['ip'], $appareil['type_appareil'], $statutLigne, self::libelleStatutLan($statutLigne), $equipementId);
      } catch (Throwable $t) {
        log::add('smartclim', 'warning', 'Broadlink LAN : traitement d\'un appareil découvert en échec : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
      }
    }

    foreach ($index['tous'] as $eqLogic) {
      if (!($eqLogic instanceof smartclim)) {
        continue;
      }
      if ((microtime(true) - $debut) >= self::BUDGET_LAN) {
        $compteurs['non_sondes']++;
        continue;
      }

      // UC01 du domaine post-mvp/02-strategies-de-transport (§ 5.4 de sa spec
      // technique, AC5) : aucun paquet LAN n'est jamais émis vers un équipement dont
      // l'utilisateur a explicitement choisi CLOUD.
      if (!smartclimTransport::sondeLanAutorisee($eqLogic)) {
        continue;
      }

      $adresse = $eqLogic->adresseLan();
      if ($adresse['ip'] === '') {
        continue;
      }
      $macAttendue = $adresse['mac'];
      $macAttendueInversee = self::macInversee($macAttendue);
      if (($macAttendue !== '' && isset($rencontres[$macAttendue])) || ($macAttendueInversee !== '' && isset($rencontres[$macAttendueInversee]))) {
        continue;
      }

      try {
        $budgetRestant = self::BUDGET_LAN - (microtime(true) - $debut);
        // UC01 du domaine post-mvp/02-strategies-de-transport (§ 5.5 de sa spec
        // technique) : sonderAdresseLan() encapsule interroger() + le contrôle MAC
        // (directe ET inversée) et écrit déjà memoriserSondeLan() pour les DEUX issues
        // TERMINALES (injoignable, MAC divergente) — cette méthode conserve SES PROPRES
        // compteurs et lignes de résultat, inchangés.
        $resultatSonde = self::sonderAdresseLan($eqLogic, max(1, min(smartclimBroadlinkLan::TIMEOUT_ECHANGE, $budgetRestant)));

        if ($resultatSonde['appareil'] === null) {
          if ($resultatSonde['statut'] !== '') {
            self::compterStatutLan($compteurs, $resultatSonde['statut']);
          }
          continue;
        }

        $trouve = $resultatSonde['appareil'];
        $macTrouvee = $resultatSonde['mac'];
        $rencontres[$macTrouvee] = true;
        $budgetRestant = self::BUDGET_LAN - (microtime(true) - $debut);
        $lecture = smartclimBroadlinkLan::lireEtat($trouve, max(1, min(self::BUDGET_LECTURE_LAN, $budgetRestant)));
        self::compterStatutLan($compteurs, $lecture['session']);
        self::compterStatutLecture($compteurs, $lecture['statut']);
        self::memoriserSondeLan($macTrouvee, array(
          'ip' => $trouve['ip'],
          'port' => $trouve['port'],
          'type_appareil' => $trouve['type_appareil'],
          'nom' => $trouve['nom'],
          'verrouille' => $trouve['verrouille'],
          'statut' => $lecture['statut'],
          'vu_le' => $trouve['vu_le'],
          'echec_le' => self::statutEnEchec($lecture['statut']) ? time() : 0,
        ));
        // Phase 2 : l'équipement est DÉJÀ en main (§ 8.2 de la spec technique UC02),
        // aucun rapprochement à refaire. UC04 (§ 5.5 de sa spec technique) :
        // memoriserMacEquipement() + save() conditionné, AVANT appliquerLectureLan().
        if (self::memoriserMacEquipement($eqLogic, $macTrouvee)) {
          $eqLogic->save();
        }
        // UC02 du domaine post-mvp/02-strategies-de-transport (§ 5.3/5.4 de sa spec
        // technique) : compteur de repli, JUSTE AVANT appliquerLectureLan().
        $eqLogic->noterOperationLan($lecture['statut']);
        self::appliquerLectureLan($eqLogic, $lecture);
        $eqLogicsTouches[] = $eqLogic;
        // UC04 (§ 5.5) : ligne de scan poussée pour cette phase — SANS elle, un
        // équipement joint UNIQUEMENT via une adresse saisie (cas VLAN) afficherait à
        // tort "Disponible en LAN : Non" dans la synthèse d'AC3.
        $appareils[] = self::ligneResultatLan($trouve['nom'], $macTrouvee, $trouve['ip'], $trouve['type_appareil'], $lecture['statut'], self::libelleStatutLan($lecture['statut']), $eqLogic->getId());
      } catch (Throwable $t) {
        log::add('smartclim', 'warning', 'Broadlink LAN : sonde d\'adresse manuelle en échec (équipement "' . self::neutraliserPourLog($eqLogic->getHumanName()) . '") : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
      }
    }

    return array(
      'resume' => self::resumeScanLan($compteurs, $diffusionIndisponible),
      'compteurs' => $compteurs,
      'appareils' => $appareils,
      // UC02 : profil de capacités / état de connexion (affichables) des équipements
      // touchés par CE scan LAN — même motif que scannerAuxHome() (UC04/UC08).
      'profils' => self::profilsAffichables($eqLogicsTouches),
      'etatsConnexion' => self::etatsConnexionAffichables($eqLogicsTouches),
    );
  }

  /**
   * Applique une lecture LAN (smartclimBroadlinkLan::lireEtat()) à UN équipement DÉJÀ
   * rapproché (UC02, § 5.5 de la spec technique) : capacités PUIS état, dans cet ordre
   * (même ordre que scannerAuxHome()) — try/catch INTERNE, ne lève JAMAIS (un équipement
   * en échec ne doit pas interrompre la boucle de scannerReseauLocal()).
   *
   * ⚠️ RÉSERVÉE AU SCAN (scannerReseauLocal()) : c'est elle qui doit mettre à jour le
   * profil de capacités détecté. Un RAFRAÎCHISSEMENT (rafraichirLan(),
   * rafraichirLanEquipement()) applique l'état SEUL, en appelant appliquerEtat()
   * directement — jamais cette méthode (correctif blocker : un appel ici depuis un
   * cycle de rafraîchissement ferait diverger 'source' du profil stocké à chaque
   * passage, sur tout équipement AUTO découvert par le cloud, avec un save() par
   * équipement à chaque cycle).
   *
   * @param smartclim $_eqLogic
   * @param array $_lecture Renvoyé par smartclimBroadlinkLan::lireEtat().
   */
  private static function appliquerLectureLan(smartclim $_eqLogic, array $_lecture) {
    try {
      if ($_eqLogic->appliquerCapacites(smartclimBroadlinkLan::capacitesAppareil($_lecture))) {
        $_eqLogic->save();
      }
      // SANS second argument (§ 5.5 de la spec technique) : $_optimiste reste false, donc
      // filtrerEtatSelonOrdres() s'applique — la période de grâce de 60 s protège le LAN
      // exactement comme elle protège le cron cloud, gratuitement, sans une ligne de plus.
      $_eqLogic->appliquerEtat(smartclimBroadlinkLan::etatAppareil($_lecture));
    } catch (Throwable $t) {
      log::add('smartclim', 'error', 'Broadlink LAN : application de l\'état impossible (équipement "' . self::neutraliserPourLog($_eqLogic->getHumanName()) . '") : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
    }
  }

  /**
   * Redécouverte par diffusion (UC03 du domaine post-mvp/02-strategies-de-transport,
   * § 4.1 de sa spec technique) : diffuse pendant $_budget secondes et indexe le
   * résultat par MAC (déjà dédoublonné par decouvrir()). NE LÈVE JAMAIS — deux
   * rattrapages, JOURNALISANT TOUS LES DEUX (M1 de la revue de plan) : un bug interne
   * muet se répéterait sinon toutes les 15 minutes sans laisser de trace.
   *
   * N'écrit RIEN : ni cache, ni base, ni disque — c'est une simple diffusion, la
   * mémorisation reste à la charge de l'appelant.
   *
   * @param int $_budget
   * @return array<string,array> MAC imprimable => ligne normalisée (decouvrir()).
   */
  private static function decouverteParMac($_budget) {
    try {
      $decouverts = smartclimBroadlinkLan::decouvrir($_budget);
    } catch (smartclimException $e) {
      // TYPE_INTERNE = aucun chemin de diffusion disponible sur cet hôte
      // (D-POSTMVP0101-03) : dégradation documentée, jamais 'error' (AC5).
      log::add('smartclim', 'warning', 'Redécouverte LAN : diffusion impossible sur cet hôte : ' . self::neutraliserPourLog($e->getMessage()));
      return array();
    } catch (Throwable $t) {
      log::add('smartclim', 'warning', 'Redécouverte LAN : erreur interne pendant la diffusion : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
      return array();
    }
    $parMac = array();
    foreach ($decouverts as $appareil) {
      $parMac[$appareil['mac']] = $appareil;
    }
    return $parMac;
  }

  /**
   * Rapproche un équipement AVEC un appareil fraîchement redécouvert par diffusion
   * (UC03 de ce domaine, § 4.2 de sa spec technique) — fonction de rapprochement PURE :
   * aucune E/S, aucun cache écrit, NE TOUCHE JAMAIS la 6ᵉ mémoire (§ 6.1). Sens
   * équipement -> appareil : NE PAS réutiliser chercherEquipementExistant(), qui va
   * dans l'autre sens (appareil -> équipement, avec création possible).
   *
   * @param smartclim $_eqLogic
   * @param array<string,array> $_parMac Résultat de decouverteParMac().
   * @param array<string,bool> $_adoptes MAC déjà adoptées DANS CETTE PASSE, par
   *   référence — l'appelant interactif passe un tableau local vide (no-op assumé).
   * @return array|null Ligne normalisée adoptée, ou null si aucune correspondance ou
   *   refus (ambiguïté, doublon).
   */
  private static function appareilRedecouvert(smartclim $_eqLogic, array $_parMac, array &$_adoptes) {
    $candidats = $_eqLogic->macsCandidatesLan();
    $macTrouvee = '';
    foreach ($candidats as $mac) {
      if (isset($_parMac[$mac])) {
        $macTrouvee = $mac;
        break;
      }
    }
    if ($macTrouvee === '') {
      return null;
    }

    // Garde d'ambiguïté (A3) : la MAC retrouvée ET sa MAC inversée, portées par DEUX
    // appareils DISTINCTS de $_parMac -> refus explicite, jamais d'adoption « au
    // mieux ». Envoyer un ordre HVAC à la machine d'un tiers est physiquement
    // observable et irréversible (bip + changement d'état) — même doctrine que
    // STATUT_MAC_DIVERGENTE.
    $macTrouveeInversee = self::macInversee($macTrouvee);
    if ($macTrouveeInversee !== '' && $macTrouveeInversee !== $macTrouvee && isset($_parMac[$macTrouveeInversee])) {
      log::add('smartclim', 'warning', 'Redécouverte LAN : ambiguïté pour l\'équipement "' . self::neutraliserPourLog($_eqLogic->getHumanName()) . '" — deux appareils distincts répondent, un pour la MAC ' . $macTrouvee . ' (' . $_parMac[$macTrouvee]['ip'] . '), un pour son inverse ' . $macTrouveeInversee . ' (' . $_parMac[$macTrouveeInversee]['ip'] . ') — aucune adoption');
      return null;
    }

    if (isset($_adoptes[$macTrouvee])) {
      log::add('smartclim', 'warning', 'Redécouverte LAN : appareil de MAC ' . $macTrouvee . ' déjà adopté par un autre équipement dans ce cycle — ignoré pour "' . self::neutraliserPourLog($_eqLogic->getHumanName()) . '"');
      return null;
    }

    // Adoption via une MAC INVERSÉE (comme chercherEquipementExistant()) : $macTrouvee
    // est le membre "inverse" de sa paire si le membre "direct" apparaît AVANT lui dans
    // macsCandidatesLan() — dont l'ordre est INTERFOLÉ (direct, inverse, direct,
    // inverse…), jamais groupé (cf. son docblock) : c'est précisément pour cela que la
    // détection se fait par POSITION RELATIVE des deux membres dans la liste, seul
    // critère robuste quel que soit l'ordre effectif et quels que soient les doublons
    // sautés par macsCandidatesLan().
    $indexTrouve = array_search($macTrouvee, $candidats, true);
    $indexDirect = ($macTrouveeInversee !== '') ? array_search($macTrouveeInversee, $candidats, true) : false;
    if ($indexDirect !== false && $indexDirect < $indexTrouve) {
      log::add('smartclim', 'warning', 'Redécouverte LAN : appareil rapproché via la MAC inversée (' . $macTrouveeInversee . ' / ' . $macTrouvee . ') pour l\'équipement "' . self::neutraliserPourLog($_eqLogic->getHumanName()) . '"');
    }

    $_adoptes[$macTrouvee] = true;
    return $_parMac[$macTrouvee];
  }

  /**
   * Sonde d'une adresse LAN connue (UC01 du domaine post-mvp/02-strategies-de-transport,
   * § 5.5 de sa spec technique) — extraction de la phase 2 de scannerReseauLocal() :
   * adresseLan() → interroger() → contrôle MAC (directe ET inversée). Ne lève JAMAIS.
   * Deux appelants : scannerReseauLocal() phase 2 (qui conserve SES PROPRES compteurs
   * et lignes de résultat) et rafraichirLan().
   *
   * ⚠️ N'écrit memoriserSondeLan() que pour les DEUX issues TERMINALES (INJOIGNABLE,
   * MAC_DIVERGENTE) — l'issue « trouvé » ne l'écrit PAS : elle rend la main à
   * l'appelant, qui écrira la mémoire APRÈS son propre lireEtat(), avec le statut
   * RÉELLEMENT lu (STATUT_ETAT_LU / STATUT_ETAT_ILLISIBLE / …). Non négociable : écrire
   * la mémoire au moment du simple interroger() ferait qu'elle ne recevrait JAMAIS
   * STATUT_ETAT_LU, donc que smartclimTransport::lanJoignable() serait TOUJOURS faux.
   *
   * @param smartclim $_eqLogic Doit déjà avoir une adresse LAN connue (adresseLan()['ip'] !== '').
   * @param float|int $_budget
   * @return array{appareil:array|null, statut:string, mac:string} 'statut' vide si non
   *   terminal (issue « trouvé »).
   */
  private static function sonderAdresseLan(smartclim $_eqLogic, $_budget) {
    $adresse = $_eqLogic->adresseLan();
    $macAttendue = $adresse['mac'];

    $trouve = smartclimBroadlinkLan::interroger($adresse['ip'], max(1, min(smartclimBroadlinkLan::TIMEOUT_ECHANGE, $_budget)));

    if ($trouve === null) {
      // UC02 du domaine post-mvp/02-strategies-de-transport (§ 7 de sa spec technique) :
      // CORRECTIF du défaut d'UC01 — sans lui, 'ip' => '' fait retomber adresseLan() sur
      // 'aucun' au prochain appel, ce qui désarme DÉFINITIVEMENT le cycle de 15 min (un
      // seul scan manuel le réarmait). On reporte l'entrée PRÉCÉDENTE (via
      // sondeLanEquipement(), qui connaît la règle « essayer aussi lan_mac et les MAC
      // inversées » — pas sondeLanMemorisee($macAttendue)) : SEULS 'statut' et
      // 'echec_le' sont neufs. ⚠️ Portée à source === 'detecte' uniquement : pour une
      // lan_ip PERSONNALISÉE (source === 'manuel'), adresseLan() l'utilise déjà de façon
      // inconditionnelle, ce correctif y est un no-op bénin.
      $precedente = $_eqLogic->sondeLanEquipement();
      self::memoriserSondeLan($macAttendue !== '' ? $macAttendue : $_eqLogic->macEquipement(), array(
        'ip' => is_array($precedente) && isset($precedente['ip']) && is_string($precedente['ip']) ? $precedente['ip'] : '',
        'port' => is_array($precedente) && isset($precedente['port']) ? (int) $precedente['port'] : 0,
        'type_appareil' => is_array($precedente) && isset($precedente['type_appareil']) && is_string($precedente['type_appareil']) ? $precedente['type_appareil'] : '',
        'nom' => is_array($precedente) && isset($precedente['nom']) && is_string($precedente['nom']) ? $precedente['nom'] : '',
        'verrouille' => is_array($precedente) && !empty($precedente['verrouille']),
        'statut' => smartclimBroadlinkLan::STATUT_INJOIGNABLE,
        'vu_le' => is_array($precedente) && isset($precedente['vu_le']) ? (int) $precedente['vu_le'] : 0,
        'echec_le' => time(),
      ));
      return array('appareil' => null, 'statut' => smartclimBroadlinkLan::STATUT_INJOIGNABLE, 'mac' => $macAttendue);
    }

    $macTrouvee = $trouve['mac'];
    $macTrouveeInversee = self::macInversee($macTrouvee);
    $correspond = ($macAttendue === '') || ($macAttendue === $macTrouvee) || ($macAttendue === $macTrouveeInversee);

    if (!$correspond) {
      // D-POSTMVP0101-05 : l'appareil répondant n'est PAS celui visé -> jamais adopté,
      // aucune session ouverte avec lui.
      log::add('smartclim', 'warning', 'Broadlink LAN : adresse locale déclarée pour l\'équipement "' . self::neutraliserPourLog($_eqLogic->getHumanName()) . '" répond avec une MAC différente de celle attendue (' . $macTrouvee . ')');
      self::memoriserSondeLan($macAttendue !== '' ? $macAttendue : $_eqLogic->macEquipement(), array(
        'ip' => '', 'port' => 0, 'type_appareil' => '', 'nom' => '', 'verrouille' => false,
        'statut' => smartclimBroadlinkLan::STATUT_MAC_DIVERGENTE, 'vu_le' => 0, 'echec_le' => time(),
      ));
      return array('appareil' => null, 'statut' => smartclimBroadlinkLan::STATUT_MAC_DIVERGENTE, 'mac' => $macTrouvee);
    }

    return array('appareil' => $trouve, 'statut' => '', 'mac' => $macTrouvee);
  }

  /**
   * Cycle de SONDE LAN périodique (UC01 du domaine post-mvp/02-strategies-de-transport,
   * § 5.5/6 de sa spec technique), déclenché par cron() toutes les INTERVALLE_CYCLE_LAN
   * secondes (15 min FIXES, DÉCOUPLÉES de refresh_interval). NE LÈVE JAMAIS
   * (try/catch(Throwable) GLOBAL + PAR équipement). Portée : équipements en mode
   * AUTO/LOCAL (sondeLanAutorisee()) porteurs d'une adresse LAN CONNUE — un équipement
   * AUTO sans adresse connue reste cloud, le scan restant l'unique vecteur de
   * DÉCOUVERTE, ce cycle n'étant qu'un vecteur de MAINTENANCE.
   *
   * ⚠️ AUCUN appliquerCapacites(), AUCUN eqLogic->save(), SANS AUCUNE EXCEPTION (§ 5.5) :
   * lecture d'état SEULE, strictement comme rafraichirAuxHome() — la migration de
   * configuration.mac reste au SCAN, seul vecteur prévu pour ça. Un save() ici
   * déclencherait postSave() (donc creerCommandesInfo()/creerCommandesAction()) à chaque
   * cycle, dans le processus plugin::cron PARTAGÉ par tous les plugins. AUCUN
   * basculerHorsLigne() : un LAN muet ne prouve
   * pas qu'un appareil est hors ligne (VLAN, pare-feu, diffusion filtrée) — seul le
   * cloud sait le dire.
   *
   * ⚠️ N'appelle DÉLIBÉRÉMENT PAS appliquerLectureLan() : celle-ci pose TOUJOURS
   * 'source' => TRANSPORT_BROADLINK_LAN dans le profil de capacités, et
   * appliquerCapacites() compare ce profil (source incluse) pour décider d'un save() —
   * sur un équipement AUTO découvert par le cloud, ce cycle de 15 min ferait alors
   * osciller 'source' en base à chaque passage. appliquerEtat() est donc appelé ICI en
   * DIRECT, sur l'état seul (correctif blocker, ne pas "factoriser" avec le scan).
   *
   * MODIFIÉE par l'UC03 du domaine post-mvp/02-strategies-de-transport (§ 4.4 de sa
   * spec technique) : une redécouverte par diffusion, AU PLUS UNE FOIS PAR CYCLE
   * (mutualisée pour tout le parc), est tentée avant de conclure à l'indisponibilité
   * d'un équipement — tout le code nouveau reste DANS le try/catch(Throwable) PAR
   * équipement existant, cette méthode continue de NE JAMAIS lever.
   *
   * @return array{lance:bool, sondes:int, lus:int, injoignables:int, erreurs:int, redecouverts:int}
   */
  private static function rafraichirLan() {
    $resultat = array(
      'lance' => false,
      'sondes' => 0,
      'lus' => 0,
      'injoignables' => 0,
      'erreurs' => 0,
      // UC03 de ce domaine (§ 4.4 de sa spec technique) : compteur additif pur, ignoré
      // par l'unique appelant cron() — seul le log::add('debug') interne le consomme.
      'redecouverts' => 0,
    );
    try {
      // Marqueur posé AVANT tout paquet (même règle que marquerCycle()) : un réseau
      // hostile ne doit pas être re-sondé chaque minute.
      self::marquerCycleLan();
      $resultat['lance'] = true;

      $debut = microtime(true);
      // UC03 (§ 4.4) : $decouverts === null est le SEUL mécanisme qui garantit « au
      // plus une diffusion PAR CYCLE » — un tableau VIDE signifie « diffusion
      // tentée, rien trouvé » et ne doit JAMAIS relancer une diffusion.
      $decouverts = null;
      $adoptes = array();
      $equipements = eqLogic::byType('smartclim', true);
      foreach ($equipements as $eqLogic) {
        if (!($eqLogic instanceof smartclim)) {
          continue;
        }
        try {
          if (!smartclimTransport::sondeLanAutorisee($eqLogic)) {
            continue;
          }
          $adresse = $eqLogic->adresseLan();
          if ($adresse['ip'] === '') {
            // UC03 (§ 5.2, règle (b)) : EXCEPTION UNIQUE — un équipement éjecté par
            // une divergence de MAC (mémoire de sonde STATUT_MAC_DIVERGENTE, adresse
            // effacée) ne sort PAS : il va directement à la redécouverte, ce qui
            // referme la dette D-1 de l'UC02 (§ 6.5). Aucune autre situation ne lève
            // ce `continue`.
            $sondePrecedente = $eqLogic->sondeLanEquipement();
            $statutPrecedent = is_array($sondePrecedente) && isset($sondePrecedente['statut']) && is_string($sondePrecedente['statut']) ? $sondePrecedente['statut'] : '';
            if ($statutPrecedent !== smartclimBroadlinkLan::STATUT_MAC_DIVERGENTE) {
              continue;
            }
            $resultatSonde = array('appareil' => null, 'statut' => smartclimBroadlinkLan::STATUT_MAC_DIVERGENTE, 'mac' => '');
          } else {
            // Arrêt DUR évalué AVANT chaque appareil (même règle que scannerReseauLocal()).
            $budgetRestant = self::BUDGET_LAN - (microtime(true) - $debut);
            if ($budgetRestant <= 0) {
              break;
            }
            $resultatSonde = self::sonderAdresseLan($eqLogic, min(smartclimBroadlinkLan::TIMEOUT_ECHANGE, $budgetRestant));
            $resultat['sondes']++;
          }

          if ($resultatSonde['appareil'] === null) {
            // UC03 (§ 4.4) : AVANT toute conclusion, une tentative de redécouverte.
            if ($decouverts === null) {
              $budgetRestant = self::BUDGET_LAN - (microtime(true) - $debut);
              $fenetre = min(self::BUDGET_REDECOUVERTE_LAN, $budgetRestant);
              $decouverts = ($fenetre >= 1) ? self::decouverteParMac((int) $fenetre) : array();
            }
            $trouve = self::appareilRedecouvert($eqLogic, $decouverts, $adoptes);
            if ($trouve !== null) {
              $resultat['redecouverts']++;
              self::memoriserAdresseLan($eqLogic, $trouve);
              if ($trouve['ip'] !== $adresse['ip']) {
                // Journalisation § 7 : condition UNIQUE, adresse RETROUVÉE différente
                // de l'adresse enregistrée dans la mémoire de sonde.
                if ($adresse['source'] === 'manuel') {
                  log::add('smartclim', 'warning', 'Équipement "' . self::neutraliserPourLog($eqLogic->getHumanName()) . '" : l\'adresse IP locale SAISIE (' . $adresse['ip'] . ') ne répond plus, appareil retrouvé en ' . $trouve['ip'] . ' — mettez à jour la configuration de l\'équipement (l\'adresse saisie reste prioritaire)');
                } elseif ($adresse['ip'] === '') {
                  // Message DÉDIÉ à la règle (b) (§ 5.2) : ici $adresse['ip'] vaut
                  // TOUJOURS '' (équipement éjecté pour STATUT_MAC_DIVERGENTE, adresse
                  // effacée) — le message générique ci-dessous produirait une adresse
                  // d'origine vide, déroutant précisément sur le cas le plus délicat à
                  // diagnostiquer.
                  log::add('smartclim', 'info', 'Équipement "' . self::neutraliserPourLog($eqLogic->getHumanName()) . '" : appareil retrouvé après une divergence de MAC, adresse IP locale enregistrée (' . $trouve['ip'] . ')');
                } else {
                  log::add('smartclim', 'info', 'Équipement "' . self::neutraliserPourLog($eqLogic->getHumanName()) . '" : adresse IP locale mise à jour automatiquement (' . $adresse['ip'] . ' -> ' . $trouve['ip'] . ')');
                }
              }
              // On RECONSTRUIT $resultatSonde et on POURSUIT le corps existant
              // (lireEtat(), memoriserSondeLanEquipement(), noterOperationLan()) — la
              // 6ᵉ mémoire est donc atteinte par le chemin NORMAL (§ 6.1).
              $resultatSonde = array('appareil' => $trouve, 'statut' => '', 'mac' => $trouve['mac']);
            } else {
              $resultat['injoignables']++;
              log::add('smartclim', 'debug', 'Redécouverte LAN : aucune correspondance pour l\'équipement "' . self::neutraliserPourLog($eqLogic->getHumanName()) . '"');
              // UC02 du domaine post-mvp/02-strategies-de-transport (§ 5.3 de sa spec
              // technique) : SEUL STATUT_INJOIGNABLE compte comme un échec de repli ici —
              // STATUT_MAC_DIVERGENTE reste HORS périmètre (§ 12.1, M2 : frontière
              // identité/joignabilité).
              if ($resultatSonde['statut'] === smartclimBroadlinkLan::STATUT_INJOIGNABLE) {
                $eqLogic->memoriserEchecLan(smartclimBroadlinkLan::STATUT_INJOIGNABLE);
              }
              continue;
            }
          }

          $budgetRestant = self::BUDGET_LAN - (microtime(true) - $debut);
          $lecture = smartclimBroadlinkLan::lireEtat($resultatSonde['appareil'], max(1, min(self::BUDGET_LECTURE_LAN, $budgetRestant)));
          // UC03 (§ 4.4/4.6) : memoriserSondeLan() devient memoriserSondeLanEquipement()
          // — contenu du tableau INCHANGÉ, seule la résolution de la clé d'écriture change.
          self::memoriserSondeLanEquipement($eqLogic, $resultatSonde['mac'], array(
            'ip' => $resultatSonde['appareil']['ip'],
            'port' => $resultatSonde['appareil']['port'],
            'type_appareil' => $resultatSonde['appareil']['type_appareil'],
            'nom' => $resultatSonde['appareil']['nom'],
            'verrouille' => $resultatSonde['appareil']['verrouille'],
            'statut' => $lecture['statut'],
            'vu_le' => $resultatSonde['appareil']['vu_le'],
            'echec_le' => self::statutEnEchec($lecture['statut']) ? time() : 0,
          ));
          if ($lecture['statut'] === smartclimBroadlinkLan::STATUT_ETAT_LU) {
            $resultat['lus']++;
          }
          // UC02 du domaine post-mvp/02-strategies-de-transport (§ 5.3/12.1 M2 de sa
          // spec technique) : couvre la branche qui N'EXISTAIT PAS avant cette UC (le
          // `if` ci-dessus n'a aucun `else`) — tout statut AUTRE que STATUT_ETAT_LU
          // compte désormais comme un échec de repli, y compris STATUT_ETAT_ILLISIBLE
          // (pas un échec de COMMUNICATION pour statutEnEchec(), mais bien un échec
          // d'UTILISABILITÉ ici).
          $eqLogic->noterOperationLan($lecture['statut']);
          // Lecture d'état SEULE (rafraîchissement, pas scan) : appliquerLectureLan()
          // n'est PAS appelée ici, cf. son docblock. SANS second argument : $_optimiste
          // reste false, donc filtrerEtatSelonOrdres() s'applique — la période de grâce
          // de 60 s protège le LAN exactement comme elle protège le cron cloud.
          $eqLogic->appliquerEtat(smartclimBroadlinkLan::etatAppareil($lecture));
        } catch (Throwable $t) {
          $resultat['erreurs']++;
          log::add('smartclim', 'warning', 'Cycle de sonde LAN : équipement "' . self::neutraliserPourLog($eqLogic->getHumanName()) . '" en échec : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
        }
      }

      log::add('smartclim', 'debug', 'Cycle de sonde LAN : ' . $resultat['sondes'] . ' sonde(s), ' . $resultat['lus'] . ' état(s) lu(s), ' . $resultat['injoignables'] . ' injoignable(s), ' . $resultat['redecouverts'] . ' redécouverte(s), ' . $resultat['erreurs'] . ' erreur(s)');
      return $resultat;
    } catch (Throwable $t) {
      log::add('smartclim', 'error', 'Cycle de sonde LAN : erreur interne inattendue : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
      return $resultat;
    }
  }

  /**
   * Cycle de rafraîchissement AUX Cloud legacy (UC03 du domaine post-mvp/03-cloud-aux-
   * legacy, § 3.4/D4 de sa spec technique), déclenché par cron() toutes les
   * INTERVALLE_CYCLE_AUXCLOUD secondes (900 s FIXES, DÉCOUPLÉES de refresh_interval).
   * NE LÈVE JAMAIS (try/catch(Throwable) GLOBAL + PAR équipement) — même doctrine que
   * rafraichirAuxHome()/rafraichirLan().
   *
   * Portée : équipements dont lectureLegacyAutorisee() est vraie — PLUS STRICTE que
   * lectureCloudAutorisee() (§ 3.6/D6 de la spec technique) : un équipement vu par les
   * DEUX clouds n'entre JAMAIS dans ce cycle O(N), contrairement au cycle AUX Home,
   * UNE requête pour tout le parc.
   *
   * ⚠️ Optimisation NON négociable (§ 3.4) : les équipements ciblés sont GROUPÉS par
   * `auxcloud_family_id` avant tout appel réseau — sans conséquence directe sur le
   * nombre de requêtes de CE cycle (chaque équipement continue d'appeler
   * lireParametres() individuellement, la 7ᵉ mémoire étant PAR équipement), mais c'est
   * ce groupement qui borne le budget par famille plutôt que par appareil isolé et
   * documente l'intention pour une future optimisation (dev/query par famille).
   *
   * ⚠️ AUCUN appliquerCapacites(), AUCUN eqLogic->save() : lecture d'état SEULE, comme
   * les deux autres cycles. AUCUN basculerHorsLigne() ni memoriserIncident() : cette
   * mémoire décrit le cycle AUTOMATIQUE AUX HOME, y écrire depuis ici falsifierait
   * l'état de connexion affiché d'un AUTRE transport (piège déjà payé sur
   * postConfig_auxcloud_*).
   *
   * @return array{lance:bool, appareils:int, rafraichis:int, erreurs:int}
   */
  private static function rafraichirAuxCloud() {
    $resultat = array(
      'lance' => false,
      'appareils' => 0,
      'rafraichis' => 0,
      'erreurs' => 0,
    );
    try {
      // Zéro requête réseau, et AUCUN marqueur posé si le compte n'est pas configuré
      // (même règle que rafraichirAuxHome()) : dès que l'utilisateur configure son
      // compte legacy, le tick suivant lance un cycle sans attendre un intervalle
      // complet.
      if (!self::compteAuxCloudConfigure()) {
        log::add('smartclim', 'debug', 'Cycle de rafraîchissement AUX Cloud legacy ignoré : compte non configuré');
        return $resultat;
      }

      // Marqueur posé AVANT tout appel réseau (même règle que marquerCycle()/
      // marquerCycleLan()) : un backend en panne ne doit pas être re-sollicité chaque
      // minute dans le processus plugin::cron, PARTAGÉ par tous les plugins.
      self::marquerCycleAuxCloud();
      $resultat['lance'] = true;

      $debut = microtime(true);
      $cibles = array();
      foreach (eqLogic::byType('smartclim', true) as $eqLogic) {
        if ($eqLogic instanceof smartclim && smartclimTransport::lectureLegacyAutorisee($eqLogic)) {
          $cibles[] = $eqLogic;
        }
      }
      if (empty($cibles)) {
        return $resultat;
      }
      $resultat['appareils'] = count($cibles);

      // UC03 du domaine post-mvp/05-temps-reel-et-demon (§ 5.4 de sa spec technique) :
      // le battement est lu UNE SEULE FOIS ici (mémoire GLOBALE au compte — une seule
      // session WS pour tout le parc legacy), jamais par équipement dans la boucle.
      // $filetEchu marque au passage le filet (900 s * 4 = 3600 s), INDÉPENDAMMENT de
      // l'échéance du cycle lui-même (déjà posée par marquerCycleAuxCloud() ci-dessus).
      $filetEchu = self::filetAuxCloudEchu();
      if ($filetEchu) {
        self::marquerFiletAuxCloud();
      }
      $battement = smartclimDemon::battementRelais();

      // Groupement PAR FAMILLE (§ 3.4 de la spec technique) : deux boucles imbriquées,
      // le budget GLOBAL restant est réévalué avant chaque famille ET avant chaque
      // équipement — un appareil lent d'une famille ne peut donc pas consommer le
      // budget des familles suivantes au-delà du plafond global.
      $parFamille = array();
      foreach ($cibles as $eqLogic) {
        $familyId = $eqLogic->getConfiguration(self::CLE_CONF_AUXCLOUD_FAMILY_ID);
        $familyId = is_string($familyId) ? $familyId : '';
        $parFamille[$familyId][] = $eqLogic;
      }

      foreach ($parFamille as $groupe) {
        if ((microtime(true) - $debut) >= self::BUDGET_CYCLE_AUXCLOUD) {
          log::add('smartclim', 'warning', 'Cycle de rafraîchissement AUX Cloud legacy : budget de temps épuisé, familles restantes ignorées');
          break;
        }
        foreach ($groupe as $eqLogic) {
          $restant = self::BUDGET_CYCLE_AUXCLOUD - (microtime(true) - $debut);
          if ($restant <= 0) {
            break;
          }
          // UC03 du domaine post-mvp/05-temps-reel-et-demon (§ 5.4/3 de sa spec
          // technique, AC6) : le filet n'est pas échu ET le push est confirmé actif
          // POUR CET équipement -> la scrutation legacy espace son intervalle effectif
          // à INTERVALLE_FILET_AUXCLOUD, le push devenant la source principale de
          // fraîcheur. Zéro requête réseau pour cet équipement sur ce tick.
          if (!$filetEchu && $eqLogic->pushAuxCloudActif($battement)) {
            log::add('smartclim', 'debug', 'Cycle de rafraîchissement AUX Cloud legacy : équipement "' . self::neutraliserPourLog($eqLogic->getHumanName()) . '" ignoré (push temps réel actif)');
            continue;
          }
          try {
            $appareil = $eqLogic->appareilAuxCloud(max(3, $restant));
            $restant = self::BUDGET_CYCLE_AUXCLOUD - (microtime(true) - $debut);
            // Correctif post-review UC03 (point 1) : REJEU D'APPAIRAGE, PAR ÉQUIPEMENT,
            // à l'intérieur de ce même try/catch — respecte l'arrêt dur de budget du
            // cycle (le rejeu interne est lui-même conditionné à $restant, cf. son
            // docblock) : un équipement ne peut donc pas consommer plus que le budget
            // GLOBAL restant de cette famille/ce cycle.
            $valeurs = $eqLogic->lireParametresAvecRejeuAppairage($appareil, max(3, $restant));
            $appareil['valeurs'] = $valeurs;
            $eqLogic->appliquerEtat(smartclimAuxCloudApi::etatAppareil($appareil));
            $resultat['rafraichis']++;
          } catch (Throwable $t) {
            // Une Error PHP 8 ne doit pas traverser : la boucle continue.
            $resultat['erreurs']++;
            log::add('smartclim', 'warning', 'Cycle de rafraîchissement AUX Cloud legacy : équipement "' . self::neutraliserPourLog($eqLogic->getHumanName()) . '" en échec : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
          }
        }
      }

      log::add('smartclim', 'debug', 'Cycle de rafraîchissement AUX Cloud legacy : ' . $resultat['appareils'] . ' équipement(s) ciblé(s), ' . $resultat['rafraichis'] . ' rafraîchi(s), ' . $resultat['erreurs'] . ' erreur(s)');
      return $resultat;
    } catch (Throwable $t) {
      log::add('smartclim', 'error', 'Cycle de rafraîchissement AUX Cloud legacy : erreur interne inattendue : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
      return $resultat;
    }
  }

  /**
   * true si un statut LAN correspond à un échec (utilisé pour dater 'echec_le' de la
   * mémoire de sonde) — succès = STATUT_ETABLIE / STATUT_REUTILISEE (session), ou
   * STATUT_ETAT_LU / STATUT_ETAT_ILLISIBLE (UC02 : une session ÉTABLIE dont la trame
   * n'est pas décodable — ex. appareil Broadlink non-climatiseur, § 3.4 de la spec
   * technique UC02 — n'est PAS un échec de communication).
   *
   * @param string $_statut
   * @return bool
   */
  private static function statutEnEchec($_statut) {
    return !in_array($_statut, array(
      smartclimBroadlinkLan::STATUT_ETABLIE,
      smartclimBroadlinkLan::STATUT_REUTILISEE,
      smartclimBroadlinkLan::STATUT_ETAT_LU,
      smartclimBroadlinkLan::STATUT_ETAT_ILLISIBLE,
    ), true);
  }

  /**
   * Incrémente etats_lus/etats_illisibles selon le statut de LECTURE (UC02, distinct du
   * statut de SESSION compté par compterStatutLan() ci-dessous) — n'incrémente rien pour
   * les autres statuts (lecture non tentée, session en échec : déjà comptés ailleurs).
   *
   * @param array $_compteurs Par référence.
   * @param string $_statut
   */
  private static function compterStatutLecture(array &$_compteurs, $_statut) {
    if ($_statut === smartclimBroadlinkLan::STATUT_ETAT_LU) {
      $_compteurs['etats_lus']++;
    } elseif ($_statut === smartclimBroadlinkLan::STATUT_ETAT_ILLISIBLE) {
      $_compteurs['etats_illisibles']++;
    }
  }

  /**
   * Incrémente le compteur du scan LAN correspondant à un statut (une seule table,
   * jamais un switch dupliqué).
   *
   * @param array $_compteurs Par référence.
   * @param string $_statut
   */
  private static function compterStatutLan(array &$_compteurs, $_statut) {
    $correspondance = array(
      smartclimBroadlinkLan::STATUT_ETABLIE => 'etablies',
      smartclimBroadlinkLan::STATUT_REUTILISEE => 'reutilisees',
      smartclimBroadlinkLan::STATUT_REFUSEE => 'refusees',
      smartclimBroadlinkLan::STATUT_INJOIGNABLE => 'injoignables',
      smartclimBroadlinkLan::STATUT_OCCUPE => 'occupees',
      smartclimBroadlinkLan::STATUT_VERROUILLE => 'verrouillees',
      smartclimBroadlinkLan::STATUT_MAC_DIVERGENTE => 'mac_divergentes',
    );
    if (isset($correspondance[$_statut]) && isset($_compteurs[$correspondance[$_statut]])) {
      $_compteurs[$correspondance[$_statut]]++;
    }
  }

  /**
   * Construit une ligne du tableau "appareils" LAN (D-POSTMVP0101-09) : liste BLANCHE de
   * champs, jamais une clé de session. `equipementId` (UC04 du domaine
   * post-mvp/01-transport-broadlink-lan, § 5.7 de sa spec technique) : id de l'eqLogic
   * rapproché, 0 sinon.
   *
   * @return array{nom:string, mac:string, ip:string, typeAppareil:string, statut:string, statutLibelle:string, equipementId:int}
   */
  private static function ligneResultatLan($_nom, $_mac, $_ip, $_typeAppareil, $_statut, $_statutLibelle, $_equipementId = 0) {
    return array(
      'nom' => $_nom,
      'mac' => $_mac,
      'ip' => $_ip,
      'typeAppareil' => $_typeAppareil,
      'statut' => $_statut,
      'statutLibelle' => $_statutLibelle,
      'equipementId' => (int) $_equipementId,
    );
  }

  /**
   * Libellé français d'un statut LAN (AC1/AC4, D-POSTMVP0101-05/09). SEUL endroit du
   * plugin où vivent ces __() (même règle que libelleStatut()/messageErreurAuxHome()).
   *
   * @param string $_statut
   * @return string
   */
  private static function libelleStatutLan($_statut) {
    if ($_statut === smartclimBroadlinkLan::STATUT_ETABLIE) {
      return __('LAN disponible', __FILE__);
    }
    if ($_statut === smartclimBroadlinkLan::STATUT_REUTILISEE) {
      return __('LAN disponible (session existante)', __FILE__);
    }
    if ($_statut === smartclimBroadlinkLan::STATUT_REFUSEE) {
      return __('LAN indisponible — authentification refusée', __FILE__);
    }
    if ($_statut === smartclimBroadlinkLan::STATUT_INJOIGNABLE) {
      return __('LAN indisponible', __FILE__);
    }
    if ($_statut === smartclimBroadlinkLan::STATUT_OCCUPE) {
      return __('LAN indisponible — appareil déjà sollicité', __FILE__);
    }
    if ($_statut === smartclimBroadlinkLan::STATUT_VERROUILLE) {
      return __('LAN indisponible — appareil verrouillé', __FILE__);
    }
    if ($_statut === smartclimBroadlinkLan::STATUT_MAC_DIVERGENTE) {
      return __('Adresse locale incohérente : l\'appareil joint n\'est pas celui attendu', __FILE__);
    }
    if ($_statut === smartclimBroadlinkLan::STATUT_ETAT_LU) {
      return __('État lu sur le réseau local', __FILE__);
    }
    if ($_statut === smartclimBroadlinkLan::STATUT_ETAT_ILLISIBLE) {
      return __('LAN disponible — état non décodable par cet appareil', __FILE__);
    }
    // UC04 du domaine post-mvp/01-transport-broadlink-lan (§ 5.5 de sa spec technique) :
    // un statut de LIGNE, pas un statut de session/lecture — deux appareils découverts
    // dans ce même scan pointent vers le même équipement.
    if ($_statut === 'ignore_doublon') {
      return __('Ignoré — un autre appareil détecté correspond déjà à cet équipement', __FILE__);
    }
    // UC01 du domaine post-mvp/02-strategies-de-transport (§ 5.4/8 de sa spec
    // technique) : même famille que 'ignore_doublon' ci-dessus — un statut de LIGNE,
    // pas de session/lecture. Aucune session ouverte, aucune écriture de mémoire de
    // sonde pour cette ligne (AC5).
    if ($_statut === 'ignore_mode_cloud') {
      return __('Ignoré — équipement en mode Cloud', __FILE__);
    }
    return __('Jamais détecté sur le réseau local', __FILE__);
  }

  /**
   * Résumé français du scan LAN (AC1/AC4/AC7), mentionnant les appareils NON SONDÉS faute
   * de budget (D-POSTMVP0101-04) et le message de dégradation si la diffusion est
   * indisponible sur cet hôte (D-POSTMVP0101-03, § 8 de la spec technique).
   *
   * @param array $_compteurs
   * @param bool $_diffusionIndisponible
   * @return string
   */
  private static function resumeScanLan(array $_compteurs, $_diffusionIndisponible = false) {
    $fragments = array();
    if ($_compteurs['trouves'] === 0) {
      $fragments[] = __('Aucun climatiseur détecté sur le réseau local', __FILE__);
    } else {
      $fragments[] = sprintf(__('%d appareil(s) Broadlink détecté(s) sur le réseau local', __FILE__), $_compteurs['trouves']);
    }
    if ($_compteurs['etats_lus'] > 0) {
      $fragments[] = sprintf(__('%d état(s) lu(s) sur le réseau local', __FILE__), $_compteurs['etats_lus']);
    }
    // UC04 du domaine post-mvp/01-transport-broadlink-lan (§ 5.5 de sa spec technique) :
    // mentionne les créations issues du LAN, symétrique du résumé du scan cloud.
    if ($_compteurs['crees'] > 0) {
      $fragments[] = sprintf(__('%d climatiseur(s) créé(s) depuis le réseau local', __FILE__), $_compteurs['crees']);
    }
    if ($_compteurs['non_sondes'] > 0) {
      $fragments[] = sprintf(__('%d appareil(s) non sondé(s) faute de budget', __FILE__), $_compteurs['non_sondes']);
    }
    $resume = implode('. ', $fragments);
    if ($_diffusionIndisponible) {
      $resume .= ' ' . __('Découverte automatique indisponible sur cet hôte (extension PHP « sockets » absente) — renseignez l\'adresse IP locale de chaque climatiseur.', __FILE__);
    }
    return $resume;
  }

  /**
   * Mémorise le résultat d'une sonde LAN (détecté ou saisi à la main), indexé par MAC
   * normalisée (D-POSTMVP0101-08/09). Cache JETABLE, NON chiffré : aucun secret (IP et MAC
   * n'en sont pas) — la clé de session vit dans une entrée DISTINCTE et chiffrée
   * (smartclimBroadlinkLan::CLE_CACHE_SESSION).
   *
   * @param string $_macNorm
   * @param array $_resultat
   */
  private static function memoriserSondeLan($_macNorm, array $_resultat) {
    if ($_macNorm === '') {
      return;
    }
    $donnees = array(
      'ip' => isset($_resultat['ip']) && is_string($_resultat['ip']) ? $_resultat['ip'] : '',
      'port' => isset($_resultat['port']) ? (int) $_resultat['port'] : 0,
      'type_appareil' => isset($_resultat['type_appareil']) && is_string($_resultat['type_appareil']) ? $_resultat['type_appareil'] : '',
      'nom' => isset($_resultat['nom']) && is_string($_resultat['nom']) ? $_resultat['nom'] : '',
      'verrouille' => !empty($_resultat['verrouille']),
      'statut' => isset($_resultat['statut']) && is_string($_resultat['statut']) ? $_resultat['statut'] : '',
      'vu_le' => isset($_resultat['vu_le']) && is_numeric($_resultat['vu_le']) ? (int) $_resultat['vu_le'] : 0,
      'echec_le' => isset($_resultat['echec_le']) && is_numeric($_resultat['echec_le']) ? (int) $_resultat['echec_le'] : 0,
    );
    cache::set(self::CLE_CACHE_LAN . $_macNorm, json_encode($donnees), self::DUREE_MEMOIRE_LAN);
  }

  /**
   * Relit la mémoire de sonde LAN d'une MAC normalisée, ou null si absente/corrompue.
   * ⚠️ N'essaie PAS la MAC inversée elle-même : c'est aux appelants (adresseLan(),
   * lanAffichable()) de le faire, comme chercherEquipementExistant() le fait déjà pour le
   * rapprochement cloud — cohérence d'un seul endroit qui connaît la règle "essayer aussi
   * l'inverse".
   *
   * @param string $_macNorm
   * @return array|null
   */
  private static function sondeLanMemorisee($_macNorm) {
    if ($_macNorm === '') {
      return null;
    }
    $brut = cache::byKey(self::CLE_CACHE_LAN . $_macNorm)->getValue(null);
    if ($brut === null) {
      return null;
    }
    $donnees = json_decode($brut, true);
    return is_array($donnees) ? $donnees : null;
  }

  /**
   * Résout la clé d'ÉCRITURE de la mémoire de sonde LAN pour CET équipement (UC03 du
   * domaine post-mvp/02-strategies-de-transport, § 4.6 de sa spec technique) : première
   * MAC de macsCandidatesLan() possédant DÉJÀ une entrée de cache, à défaut
   * $_macTrouvee (comportement actuel). Puis délègue à memoriserSondeLan().
   *
   * ⚠️ Pas cosmétique — corrige un défaut PRÉ-EXISTANT (R2) : les écritures d'ÉCHECS
   * (sonderAdresseLan()) utilisent TOUJOURS la MAC candidate DIRECTE, celles de SUCCÈS
   * la MAC RÉELLEMENT rapportée (potentiellement l'INVERSÉE). sondeLanEquipement()
   * essayant les directs AVANT les inversés et retournant à la PREMIÈRE entrée
   * trouvée, un appareil inversé voyait toujours son entrée d'ÉCHEC relue, et sa
   * nouvelle adresse écrite là où PERSONNE ne la lit. La résolution converge les DEUX
   * entrées vers une SEULE et ne peut JAMAIS en créer une nouvelle quand une existe.
   *
   * ⚠️ Portée VOLONTAIREMENT limitée à rafraichirLan() et au chemin interactif — les
   * écritures de scannerReseauLocal() ne sont PAS touchées (§ 12.1 M4 de l'UC02, D-2).
   *
   * @param smartclim $_eqLogic
   * @param string $_macTrouvee
   * @param array $_resultat
   */
  private static function memoriserSondeLanEquipement(smartclim $_eqLogic, $_macTrouvee, array $_resultat) {
    $cle = $_macTrouvee;
    foreach ($_eqLogic->macsCandidatesLan() as $candidat) {
      if (self::sondeLanMemorisee($candidat) !== null) {
        $cle = $candidat;
        break;
      }
    }
    self::memoriserSondeLan($cle, $_resultat);
  }

  /**
   * Mémorise une adresse LAN REDÉCOUVERTE, pas encore lue (UC03 de ce domaine, § 4.7 de
   * sa spec technique) — chemin INTERACTIF, où aucun lireEtat() ne suivra. Écrit via
   * memoriserSondeLanEquipement() (résolution de clé, § 4.6).
   *
   * ⚠️ Règle en une phrase, SYMÉTRIQUE EXACTE du correctif d'UC02 sur sonderAdresseLan()
   * (« seuls statut et echec_le sont NEUFS ») : ICI, TOUT est NEUF SAUF statut et
   * echec_le, REPORTÉS de l'entrée PRÉCÉDENTE. Une redécouverte prouve une ADRESSE,
   * jamais une CAPACITÉ — écrire un statut inventé casserait lanJoignable() (qui exige
   * STATUT_ETAT_LU) : un statut de complaisance ferait router des ordres vers un
   * appareil NON VÉRIFIÉ, un statut d'échec dégraderait un équipement joignable.
   *
   * ⚠️ UNIQUE EXCEPTION : si le statut précédent est STATUT_MAC_DIVERGENTE, écrire ''.
   * Un verdict de divergence porte sur l'ANCIENNE adresse ; le reporter sur une adresse
   * dont on vient de vérifier la MAC serait factuellement faux.
   *
   * @param smartclim $_eqLogic
   * @param array $_appareil Ligne normalisée (decouvrir()/interroger()).
   */
  private static function memoriserAdresseLan(smartclim $_eqLogic, array $_appareil) {
    $precedente = $_eqLogic->sondeLanEquipement();
    $statutPrecedent = is_array($precedente) && isset($precedente['statut']) && is_string($precedente['statut']) ? $precedente['statut'] : '';
    $echecLePrecedent = is_array($precedente) && isset($precedente['echec_le']) ? (int) $precedente['echec_le'] : 0;
    $statutReporte = ($statutPrecedent === smartclimBroadlinkLan::STATUT_MAC_DIVERGENTE) ? '' : $statutPrecedent;

    self::memoriserSondeLanEquipement($_eqLogic, $_appareil['mac'], array(
      'ip' => $_appareil['ip'],
      'port' => $_appareil['port'],
      'type_appareil' => $_appareil['type_appareil'],
      'nom' => $_appareil['nom'],
      'verrouille' => $_appareil['verrouille'],
      'statut' => $statutReporte,
      'vu_le' => $_appareil['vu_le'],
      'echec_le' => $echecLePrecedent,
    ));
  }

  /**
   * true si un statut d'opération LAN doit compter comme un ÉCHEC du compteur de repli
   * (UC02 du domaine post-mvp/02-strategies-de-transport, § 5.1 de sa spec technique).
   *
   * ⚠️⚠️ À NE JAMAIS CONFONDRE avec statutEnEchec() ci-dessus, qui répond à une
   * question DIFFÉRENTE : « la communication a-t-elle abouti ? » (sert à dater
   * 'echec_le' de la mémoire de sonde), et qui WHITELISTE STATUT_ETAT_ILLISIBLE comme un
   * succès. Ici la question est « le LAN est-il UTILISABLE pour piloter cet appareil ? » —
   * une trame indécodable rend le LAN inutilisable tout autant qu'un timeout, et DOIT
   * donc compter comme un échec de repli. Substituer l'une à l'autre remettrait le
   * compteur à zéro sur une lecture illisible (piège identifié à DEUX sites :
   * rafraichirLanEquipement() et rafraichirLan()).
   *
   * @param string $_statut
   * @return bool
   */
  private static function echecOperationLan($_statut) {
    return $_statut !== smartclimBroadlinkLan::STATUT_ETAT_LU;
  }

  /**
   * Compteur d'échecs LAN CONSÉCUTIFS de cet équipement (UC02 de ce domaine, § 4.2/5.1
   * de sa spec technique — AC1/AC2). SEULE méthode PUBLIQUE de ce groupe : consommée par
   * smartclimTransport::lanJoignable()/repliCloudActif().
   *
   * @return int
   */
  public function echecsLanConsecutifs() {
    $memoire = $this->memoireEchecsTransport();
    return $memoire['lan'];
  }

  /**
   * 6ᵉ mémoire de cache, LUE et VALIDÉE champ par champ (UC02 de ce domaine, § 2.2/5.2
   * de sa spec technique) — MÊME discipline qu'incidentMemorise() : jamais d'état
   * forgé, JAMAIS null (les appelants n'ont donc aucune branche à écrire). Toute
   * anomalie (absente, corrompue, forme non conforme) renvoie une structure À ZÉRO,
   * strictement équivalente à « appareil jamais prouvé, aucun échec connu ».
   *
   * @return array{preuve:int, lan:int, cloud:int, cloud_le:int}
   */
  private function memoireEchecsTransport() {
    $vide = array('preuve' => 0, 'lan' => 0, 'cloud' => 0, 'cloud_le' => 0);
    $brut = cache::byKey(self::CLE_CACHE_ECHECS_TRANSPORT . $this->getId())->getValue(null);
    if (!is_string($brut) || $brut === '') {
      return $vide;
    }
    $memoire = json_decode($brut, true);
    if (
      !is_array($memoire)
      || !isset($memoire['preuve'], $memoire['lan'], $memoire['cloud'], $memoire['cloud_le'])
      || !is_numeric($memoire['preuve'])
      || !is_numeric($memoire['lan'])
      || !is_numeric($memoire['cloud'])
      || !is_numeric($memoire['cloud_le'])
    ) {
      return $vide;
    }
    return array(
      'preuve' => (int) $memoire['preuve'],
      'lan' => (int) $memoire['lan'],
      'cloud' => (int) $memoire['cloud'],
      'cloud_le' => (int) $memoire['cloud_le'],
    );
  }

  /**
   * Écrit la 6ᵉ mémoire de cache — remplacement INTÉGRAL, jamais un merge partiel
   * (UC02 de ce domaine, § 5.1 de sa spec technique).
   *
   * @param array $_memoire
   */
  private function ecrireMemoireEchecsTransport(array $_memoire) {
    cache::set(self::CLE_CACHE_ECHECS_TRANSPORT . $this->getId(), json_encode($_memoire), self::DUREE_MEMOIRE_ECHECS_TRANSPORT);
  }

  /**
   * AIGUILLAGE UNIQUE d'une opération LAN terminée (UC02 de ce domaine, § 5.2 de sa
   * spec technique) — c'est la pièce qui rend le scénario dangereux de la revue croisée
   * (§ 12.1, M4) INATTEIGNABLE : aucun site de LECTURE n'appelle memoriserSuccesLan()
   * directement, tous passent par ici (seul appel DIRECT restant : le succès
   * d'envoyerOrdreLan(), où une écriture appliquée EST la preuve, par construction).
   *
   * @param string $_statut Un des statuts smartclimBroadlinkLan::STATUT_*.
   */
  private function noterOperationLan($_statut) {
    if (self::echecOperationLan($_statut)) {
      $this->memoriserEchecLan($_statut);
    } else {
      $this->memoriserSuccesLan();
    }
  }

  /**
   * Succès LAN (UC02 de ce domaine, § 5.2 de sa spec technique — AC2/AC6) : pose la
   * PREUVE et remet le compteur d'échecs à zéro. Log 'info' SEULEMENT à la TRANSITION
   * (retour au pilotage local depuis un repli), jamais à chaque opération réussie.
   */
  private function memoriserSuccesLan() {
    $memoire = $this->memoireEchecsTransport();
    $transition = $memoire['lan'] >= smartclimTransport::SEUIL_ECHECS_LAN;
    $memoire['preuve'] = time();
    $memoire['lan'] = 0;
    $this->ecrireMemoireEchecsTransport($memoire);
    if ($transition) {
      log::add('smartclim', 'info', 'Équipement "' . self::neutraliserPourLog($this->getHumanName()) . '" : retour au pilotage local (réseau local de nouveau joignable)');
    }
  }

  /**
   * Échec LAN (UC02 de ce domaine, § 5.2 de sa spec technique — AC1) : DEUX no-op,
   * tous deux essentiels.
   * 1. Si $_motif désigne une MAC divergente (STATUT_MAC_DIVERGENTE ou
   *    CONTEXTE_LAN_MAC_DIVERGENTE) : frontière identité/joignabilité (§ 12.1, M2 de la
   *    spec technique) — le lien a répondu, c'est l'IDENTITÉ qui diverge, hors périmètre
   *    de cette UC (renvoyé à l'UC03).
   * 2. Si aucune preuve STATUT_ETAT_LU n'a JAMAIS été constatée (preuve === 0) :
   *    garde-fou de l'étape 4 de smartclimTransport::lanJoignable() (§ 4.2 de sa spec
   *    technique) — SANS lui, un appareil qui n'a jamais parlé Broadlink serait déclaré
   *    joignable après son 1er échec.
   * Log 'info' SEULEMENT au FRANCHISSEMENT du seuil, jamais à chaque échec.
   *
   * @param string $_motif '' ou un statut/contexte technique (jamais affiché tel quel).
   */
  private function memoriserEchecLan($_motif = '') {
    if ($_motif === smartclimBroadlinkLan::STATUT_MAC_DIVERGENTE || $_motif === self::CONTEXTE_LAN_MAC_DIVERGENTE) {
      return;
    }
    $memoire = $this->memoireEchecsTransport();
    if ($memoire['preuve'] === 0) {
      return;
    }
    $transition = $memoire['lan'] === (smartclimTransport::SEUIL_ECHECS_LAN - 1);
    $memoire['lan'] = min($memoire['lan'] + 1, 999);
    $this->ecrireMemoireEchecsTransport($memoire);
    if ($transition) {
      log::add('smartclim', 'info', 'Équipement "' . self::neutraliserPourLog($this->getHumanName()) . '" : bascule en repli cloud (' . $memoire['lan'] . ' échec(s) LAN consécutif(s))');
    }
  }

  /**
   * Échec CLOUD, EN repli uniquement (UC02 de ce domaine, § 5.2/6.2 de sa spec
   * technique — AC5, disjoncteur à demi-ouverture).
   */
  private function memoriserEchecCloud() {
    $memoire = $this->memoireEchecsTransport();
    $memoire['cloud'] = min($memoire['cloud'] + 1, 999);
    $memoire['cloud_le'] = time();
    $this->ecrireMemoireEchecsTransport($memoire);
  }

  /**
   * Désarme la temporisation cloud (UC02 de ce domaine, § 5.2/6.3 de sa spec technique) :
   * NO-OP si cloud === 0 — évite une écriture par commande réussie et par équipement à
   * chaque cycle cloud (§ 0.1, seule inflexion à l'inertie hors repli).
   */
  private function oublierEchecsCloud() {
    $memoire = $this->memoireEchecsTransport();
    if ($memoire['cloud'] <= 0) {
      return;
    }
    $memoire['cloud'] = 0;
    $memoire['cloud_le'] = 0;
    $this->ecrireMemoireEchecsTransport($memoire);
  }

  /**
   * Secondes restantes avant qu'une nouvelle tentative cloud soit autorisée (UC02 de ce
   * domaine, § 5.2/6.2 de sa spec technique — disjoncteur à demi-ouverture). ⚠️ SANS
   * AUCUNE consultation de incidentMemorise() (§ 12.1, M1 : un fait non borné en
   * fraîcheur ne peut ni armer ni désarmer une temporisation).
   *
   * @return int
   */
  private function attenteCloudRestante() {
    $memoire = $this->memoireEchecsTransport();
    if ($memoire['cloud'] <= 0) {
      return 0;
    }
    return max(0, smartclimTransport::delaiTemporisationCloud($memoire['cloud']) - (time() - $memoire['cloud_le']));
  }

  /**
   * Valide/normalise une IPv4 saisie par l'utilisateur (lan_ip, § 4.1 de la spec
   * technique) : rejette les adresses PUBLIQUEMENT ROUTABLES (dont le CGNAT
   * 100.64.0.0/10, exclu explicitement) — le plugin envoie de l'UDP vers cette adresse, la
   * restreindre aux plages privées/réservées empêche qu'une faute de frappe (ou une
   * saisie malveillante sur une surface admin) ne transforme le plugin en émetteur vers
   * Internet.
   *
   * @param mixed $_valeur
   * @return string '' si non exploitable ou publiquement routable (= "non personnalisé").
   */
  private static function normaliserIpV4($_valeur) {
    if (!is_scalar($_valeur)) {
      return '';
    }
    $ip = filter_var((string) $_valeur, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    if ($ip === false) {
      return '';
    }
    $publique = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    if ($publique !== false) {
      return '';
    }
    $octets = array_map('intval', explode('.', $ip));
    if ($octets[0] === 100 && $octets[1] >= 64 && $octets[1] <= 127) {
      return '';
    }
    // FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE ne couvre ni 0.0.0.0/8 ni la
    // plage multicast/réservée haute (224.0.0.0 et au-delà) : rejet explicite par
    // comparaison d'OCTETS, PAS ip2long() — ip2long() renvoie un entier SIGNÉ, et sur un
    // build PHP 32 bits (ex. Raspberry Pi OS armhf) le seuil 224.0.0.0 devient négatif,
    // ce qui fait passer à tort toute adresse en 10.x.x.x pour "supérieure" à ce seuil et
    // la rejette (bug vécu, cf. revue). Raisonner sur les octets est exact quelle que
    // soit la largeur des entiers.
    if ($octets[0] === 0 || $octets[0] >= 224) {
      return '';
    }
    return $ip;
  }

  /**
   * Construit une ligne du tableau "appareils" renvoyé par scannerAuxHome() : liste
   * BLANCHE de champs (AC2 — jamais de jeton, uid ou e-mail). `equipementId` (UC04 du
   * domaine post-mvp/01-transport-broadlink-lan, § 5.7 de sa spec technique) : id de
   * l'eqLogic si l'objet est en main (cree/existant/ignore_doublon), 0 sinon.
   *
   * @return array{nom:string, modele:string, mac:string, identifiant:string, enLigne:bool, enLigneLibelle:string, statut:string, statutLibelle:string, equipementId:int}
   */
  private static function ligneResultatScan($_nom, $_modele, $_mac, $_identifiant, $_enLigne, $_statut, $_equipementId = 0) {
    return array(
      'nom' => $_nom,
      'modele' => $_modele,
      'mac' => $_mac,
      'identifiant' => $_identifiant,
      'enLigne' => (bool) $_enLigne,
      'enLigneLibelle' => self::libelleEnLigne($_enLigne),
      'statut' => $_statut,
      'statutLibelle' => self::libelleStatut($_statut),
      'equipementId' => (int) $_equipementId,
    );
  }

  /**
   * Charge tous les équipements smartclim en UNE seule requête
   * (eqLogic::byType('smartclim')) et construit les index utilisés par le
   * rapprochement (§ 5.1/5.2 de la spec technique UC04, post-mvp/01) : par logicalId,
   * par MAC (configuration.mac), par MAC LAN saisie (configuration.lan_mac, non vide
   * seulement), par auxhome_device_id, la liste complète (pour appareilsDisparus()) et
   * l'ensemble des noms déjà utilisés (pour nomUnique()).
   *
   * ⚠️ Premier arrivé gagne sur parMac/parLanMac, avec un log 'debug' en cas de
   * collision (doublon RÉEL du parc, pas une anomalie de code).
   *
   * ⚠️ +1 index (UC02 du domaine post-mvp/03-cloud-aux-legacy, § 6.2 de sa spec
   * technique) : `parEndpointAuxCloud`, FILTRÉ « non vide » — même piège que
   * `parLanMac` (une clé vide collisionnerait tous les équipements sans endpoint legacy).
   *
   * @return array{parLogicalId:array<string,smartclim>, parMac:array<string,smartclim>, parLanMac:array<string,smartclim>, parDeviceId:array<string,smartclim>, parEndpointAuxCloud:array<string,smartclim>, tous:smartclim[], noms:array<string,bool>}
   */
  private static function indexerEquipements() {
    $tous = eqLogic::byType('smartclim');
    $parLogicalId = array();
    $parMac = array();
    $parLanMac = array();
    $parDeviceId = array();
    $parEndpointAuxCloud = array();
    $noms = array();
    foreach ($tous as $eqLogic) {
      $parLogicalId[$eqLogic->getLogicalId()] = $eqLogic;
      $mac = self::normaliserMac($eqLogic->getConfiguration('mac'));
      if ($mac !== '') {
        if (isset($parMac[$mac])) {
          log::add('smartclim', 'debug', 'Collision d\'index MAC entre équipements lors de l\'indexation (' . $mac . ')');
        } else {
          $parMac[$mac] = $eqLogic;
        }
      }
      // ⚠️ preSave() écrit TOUJOURS lan_mac, fût-ce une chaîne vide : filtrer sur
      // "non vide" avant d'indexer, sinon tous les équipements sans adresse locale
      // collisionnent sur la clé '' (§ 5.1 de la spec technique).
      $lanMac = self::normaliserMac($eqLogic->getConfiguration(self::CLE_CONF_LAN_MAC));
      if ($lanMac !== '') {
        if (isset($parLanMac[$lanMac])) {
          log::add('smartclim', 'debug', 'Collision d\'index MAC LAN entre équipements lors de l\'indexation (' . $lanMac . ')');
        } else {
          $parLanMac[$lanMac] = $eqLogic;
        }
      }
      $deviceId = $eqLogic->getConfiguration('auxhome_device_id');
      if (is_string($deviceId) && $deviceId !== '') {
        $parDeviceId[$deviceId] = $eqLogic;
      }
      $endpointAuxCloud = $eqLogic->getConfiguration(self::CLE_CONF_AUXCLOUD_ENDPOINT_ID);
      if (is_string($endpointAuxCloud) && $endpointAuxCloud !== '') {
        if (isset($parEndpointAuxCloud[$endpointAuxCloud])) {
          log::add('smartclim', 'debug', 'Collision d\'index endpoint AUX Cloud legacy entre équipements lors de l\'indexation');
        } else {
          $parEndpointAuxCloud[$endpointAuxCloud] = $eqLogic;
        }
      }
      $noms[$eqLogic->getName()] = true;
    }
    return array(
      'parLogicalId' => $parLogicalId,
      'parMac' => $parMac,
      'parLanMac' => $parLanMac,
      'parDeviceId' => $parDeviceId,
      'parEndpointAuxCloud' => $parEndpointAuxCloud,
      'tous' => $tous,
      'noms' => $noms,
    );
  }

  /**
   * Rapproche un appareil (cloud ou LAN) avec un équipement Jeedom déjà connu, dans
   * l'ordre CORRIGÉ imposé par § 5.2 de la spec technique UC04 du domaine
   * post-mvp/01-transport-broadlink-lan (conforme à
   * .memory/analyse/smartclim-architecture-jeedom.md § 4) : tous les ordres DIRECTS
   * (logicalId, mac, lan_mac) avant tous les ordres INVERSÉS (mêmes trois clés), puis
   * auxhome_device_id. `logicalId` n'est JAMAIS réécrit après création — c'est
   * l'identité de l'équipement, rien n'en garantit l'unicité au niveau SQL.
   *
   * ⚠️ `parLanMac` ne rapproche que POUR SON TRANSPORT (étapes 3 et 6, gardées par
   * `$_transport === TRANSPORT_BROADLINK_LAN`) : `lan_mac` est une déclaration
   * utilisateur pour le LAN, s'en servir pour un appareil cloud attacherait un
   * appareil neuf à l'équipement d'un autre sur une simple faute de frappe.
   * ⚠️ Garde palindrome : les étapes inversées (4-6) sont SAUTÉES si
   * `macInversee($_macNorm) === $_macNorm`, sinon le warning "MAC inversée" se
   * déclencherait à tort sur une MAC symétrique alors que c'est le MÊME équipement
   * que l'étape 1.
   *
   * ⚠️ +1 étape (8, UC02 du domaine post-mvp/03-cloud-aux-legacy, § 6.2 de sa spec
   * technique) : `auxcloud_endpoint_id`, gardée par `$_transport ===
   * TRANSPORT_AUX_CLOUD_LEGACY` — même doctrine que la garde `lan_mac` (« ne rapproche
   * que pour son transport »). Le scan legacy passe `$_deviceId = ''` : l'étape 7
   * (`auxhome_device_id`) reste réservée à AUX Home, SANS modification.
   *
   * @param string $_macNorm
   * @param string $_deviceId
   * @param array $_index Index construit par indexerEquipements().
   * @param string $_transport '' (cloud AUX Home, comportement historique inchangé),
   *   smartclimCapabilities::TRANSPORT_BROADLINK_LAN ou
   *   smartclimCapabilities::TRANSPORT_AUX_CLOUD_LEGACY.
   * @param string $_endpointAuxCloud `auxcloud_endpoint_id` de l'appareil recherché
   *   (UC02 du domaine post-mvp/03-cloud-aux-legacy), '' pour les deux autres transports.
   * @return smartclim|null
   */
  private static function chercherEquipementExistant($_macNorm, $_deviceId, array $_index, $_transport = '', $_endpointAuxCloud = '') {
    $lan = ($_transport === smartclimCapabilities::TRANSPORT_BROADLINK_LAN);

    if ($_macNorm !== '') {
      // 1. logicalId direct.
      if (isset($_index['parLogicalId']['mac:' . $_macNorm])) {
        return $_index['parLogicalId']['mac:' . $_macNorm];
      }
      // 2. configuration.mac direct.
      if (isset($_index['parMac'][$_macNorm])) {
        return $_index['parMac'][$_macNorm];
      }
      // 3. lan_mac direct (LAN seulement).
      if ($lan && isset($_index['parLanMac'][$_macNorm])) {
        return $_index['parLanMac'][$_macNorm];
      }

      $macInversee = self::macInversee($_macNorm);
      if ($macInversee !== '' && $macInversee !== $_macNorm) {
        // 4. logicalId inversé.
        if (isset($_index['parLogicalId']['mac:' . $macInversee])) {
          // Littéral NEUTRE de transport (UC02 du domaine post-mvp/01-transport-broadlink-lan,
          // § 5.5 de sa spec technique) : cette méthode sert au rapprochement cloud ET LAN.
          log::add('smartclim', 'warning', 'Appareil rapproché via la MAC inversée (' . $_macNorm . ' / ' . $macInversee . ')');
          return $_index['parLogicalId']['mac:' . $macInversee];
        }
        // 5. configuration.mac inversé.
        if (isset($_index['parMac'][$macInversee])) {
          log::add('smartclim', 'warning', 'Appareil rapproché via la MAC inversée (' . $_macNorm . ' / ' . $macInversee . ')');
          return $_index['parMac'][$macInversee];
        }
        // 6. lan_mac inversé (LAN seulement).
        if ($lan && isset($_index['parLanMac'][$macInversee])) {
          log::add('smartclim', 'warning', 'Appareil rapproché via la MAC inversée (' . $_macNorm . ' / ' . $macInversee . ')');
          return $_index['parLanMac'][$macInversee];
        }
      }
    }
    // 7. auxhome_device_id (cloud seulement, le LAN passe '').
    if ($_deviceId !== '' && isset($_index['parDeviceId'][$_deviceId])) {
      return $_index['parDeviceId'][$_deviceId];
    }
    // 8. auxcloud_endpoint_id (AUX Cloud legacy seulement).
    if ($_transport === smartclimCapabilities::TRANSPORT_AUX_CLOUD_LEGACY && $_endpointAuxCloud !== '' && isset($_index['parEndpointAuxCloud'][$_endpointAuxCloud])) {
      return $_index['parEndpointAuxCloud'][$_endpointAuxCloud];
    }
    return null;
  }

  /**
   * Crée un équipement Jeedom pour un appareil nouvellement découvert, CLOUD ou LAN
   * (AC1 du MVP UC03, § 5.3 de la spec technique UC04 du domaine
   * post-mvp/01-transport-broadlink-lan) : setName() UNIQUEMENT ici (AC3 — jamais
   * réappelé sur un équipement existant), setObject_id() JAMAIS appelé (AC4). Aucune
   * smartclimCmd, aucune trame HVAC ici : les capacités sont pré-calculées par
   * l'appelant (§ 0 de la spec technique UC03).
   *
   * *Arbitrage* (§ 5.3 de la spec technique UC04) : méthode NEUTRE de transport
   * plutôt qu'un jumeau creerEquipementLan() — $_nomBrut est l'alias déjà assaini par
   * le transport appelant, $_configuration porte les clés SUPPLÉMENTAIRES propres à
   * ce transport (ex. auxhome_device_id/modele côté cloud, aucune côté LAN).
   *
   * @param string $_logicalId
   * @param string $_nomBrut Alias déjà assaini par le transport appelant.
   * @param string $_macNorm
   * @param array $_noms Noms déjà utilisés, par référence (nomUnique()).
   * @param array $_capacites Profil de capacités détecté (UC04 du MVP).
   * @param array $_configuration Clés de configuration SUPPLÉMENTAIRES à poser.
   * @return smartclim
   */
  private static function creerEquipement($_logicalId, $_nomBrut, $_macNorm, array &$_noms, array $_capacites, array $_configuration = array()) {
    $eqLogic = new smartclim();
    $eqLogic->setEqType_name('smartclim');
    $eqLogic->setLogicalId($_logicalId);

    // Même fonction que celle appliquée par setName() (§ 6.4 de la spec technique) :
    // un alias non vide après nettoyage côté transport peut le redevenir une fois
    // passé au filtre du core -> repli sur le nom par défaut dans ce cas.
    $souhaite = (trim(cleanComponanteName($_nomBrut)) !== '') ? $_nomBrut : self::nomAppareilParDefaut($_macNorm);
    $eqLogic->setName(self::nomUnique($souhaite, $_noms));

    $eqLogic->setIsEnable(1);
    $eqLogic->setIsVisible(1);
    $eqLogic->setCategory('heating', 1);
    if ($_macNorm !== '') {
      $eqLogic->setConfiguration('mac', $_macNorm);
    }
    foreach ($_configuration as $cle => $valeur) {
      $eqLogic->setConfiguration($cle, $valeur);
    }
    // Pose le profil de capacités AVANT le save() unique (UC04) : jamais un 2e save()
    // dédié, qui romprait l'invariant « une création = un seul save() ».
    $eqLogic->appliquerCapacites($_capacites);
    $eqLogic->save();

    return $eqLogic;
  }

  /**
   * Pose `configuration.mac` d'un équipement EXISTANT SI ET SEULEMENT SI elle est
   * actuellement VIDE et que la MAC fournie ne l'est pas (UC04 du domaine
   * post-mvp/01-transport-broadlink-lan, § 5.4 de sa spec technique) — N'ÉCRASE
   * JAMAIS une MAC déjà connue. N'émet AUCUN save() : c'est à l'appelant de décider.
   *
   * Trois motifs, par ordre d'importance : (1) idempotence de l'écriture (AC4) ; (2)
   * empêche un cloud annonçant la MAC dans l'autre ordre de désynchroniser
   * configuration.mac du suffixe du logicalId et de provoquer un save() à CHAQUE scan ;
   * (3) c'est LA migration du parc — un équipement `auxhome:<deviceId>` acquiert sa
   * MAC au premier scan qui la connaît, sans script de migration.
   *
   * @param smartclim $_eqLogic
   * @param string $_macNorm
   * @return bool true si l'objet a été modifié (l'appelant doit alors appeler save()).
   */
  private static function memoriserMacEquipement(smartclim $_eqLogic, $_macNorm) {
    if ($_macNorm === '') {
      return false;
    }
    $macActuelle = self::normaliserMac($_eqLogic->getConfiguration('mac'));
    if ($macActuelle !== '') {
      return false;
    }
    $_eqLogic->setConfiguration('mac', $_macNorm);
    return true;
  }

  /**
   * Nom de repli quand l'alias renvoyé par le cloud est vide (ou inexploitable) après
   * nettoyage (§ 6.4 de la spec technique UC03) : "Climatiseur <4 derniers hexa de la
   * MAC>", ou "Climatiseur" seul si aucune MAC n'est disponible.
   *
   * @param string $_macNorm
   * @return string
   */
  private static function nomAppareilParDefaut($_macNorm) {
    if ($_macNorm !== '') {
      return __('Climatiseur', __FILE__) . ' ' . strtoupper(substr($_macNorm, -4));
    }
    return __('Climatiseur', __FILE__);
  }

  /**
   * Garantit l'unicité du nom d'un équipement CRÉÉ (AC3 : jamais appelé sur un
   * équipement existant). eqLogic::save() lève une exception si `name` est vide, et la
   * table porte un index UNIQUE(name, object_id) : suffixe parenthésé numéroté,
   * borné à 50 essais.
   *
   * @param string $_souhaite
   * @param array $_noms Noms déjà utilisés ; mis à jour avec le nom retenu.
   * @return string
   */
  private static function nomUnique($_souhaite, array &$_noms) {
    $nom = $_souhaite;
    $essai = 1;
    while (isset($_noms[$nom]) && $essai <= 50) {
      $essai++;
      $nom = $_souhaite . ' (' . $essai . ')';
    }
    $_noms[$nom] = true;
    return $nom;
  }

  /**
   * Normalise une MAC brute en 12 caractères hexadécimaux minuscules, ou '' si non
   * conforme (déjà fait côté transport par smartclimAuxHomeApi::normaliserAppareil() ;
   * revalidation défensive, idempotente, avant tout usage en `logicalId`).
   *
   * @param mixed $_valeur
   * @return string
   */
  private static function normaliserMac($_valeur) {
    if (!is_scalar($_valeur)) {
      return '';
    }
    $mac = preg_replace('/[^0-9a-f]/', '', strtolower((string) $_valeur));
    return strlen($mac) === 12 ? $mac : '';
  }

  /**
   * MAC dans l'ordre d'octets INVERSÉ (certaines implémentations Broadlink de
   * référence lisent des ordres d'octets opposés, cf.
   * .memory/analyse/smartclim-architecture-jeedom.md § 4). Inverse les OCTETS
   * (str_split par paire + array_reverse), JAMAIS strrev() qui inverserait aussi les
   * quartets.
   *
   * @param string $_macNorm 12 caractères hexadécimaux minuscules, ou ''.
   * @return string
   */
  private static function macInversee($_macNorm) {
    if (strlen($_macNorm) !== 12) {
      return '';
    }
    return implode('', array_reverse(str_split($_macNorm, 2)));
  }

  /**
   * Équipements smartclim déjà connus, plausiblement issus d'AUX Home, mais non
   * retrouvés dans la réponse de ce scan (AC6) : jamais supprimés ni désactivés ici —
   * écran de résultat + log 'warning' uniquement (§ 0 de la spec technique, décision
   * actée avec l'utilisateur).
   *
   * ⚠️ CRITÈRE CORRIGÉ (UC04 du domaine post-mvp/01-transport-broadlink-lan, § 5.12 de
   * sa spec technique) : `auxhome_device_id` non vide SEUL, et non plus `OU mac non
   * vide`. Un équipement créé par le LAN porte une `mac` et AUCUN `auxhome_device_id` —
   * avec l'ancien critère, il serait signalé "introuvable" à CHAQUE scan cloud,
   * indéfiniment. C'est aussi le critère sémantiquement juste : « disparu du compte AUX
   * Home » ne veut rien dire pour un appareil qui n'y a JAMAIS été.
   *
   * @param array $_index Index construit par indexerEquipements().
   * @param array $_consommes logicalId des équipements rapprochés PENDANT ce scan.
   * @return array
   */
  private static function appareilsDisparus(array $_index, array $_consommes) {
    $disparus = array();
    foreach ($_index['tous'] as $eqLogic) {
      if (in_array($eqLogic->getLogicalId(), $_consommes, true)) {
        continue;
      }
      $deviceId = $eqLogic->getConfiguration('auxhome_device_id');
      $mac = $eqLogic->getConfiguration('mac');
      $issuAuxHome = is_string($deviceId) && $deviceId !== '';
      if (!$issuAuxHome) {
        continue;
      }
      // Nom neutralisé AVANT journalisation : getName() est une entrée CLIENT
      // (renommable, y compris via un appel direct à l'API Jeedom qui ne filtre pas
      // les sauts de ligne) — même motif que
      // smartclimAuxHomeApi::nettoyerTexteExterne()/journaliserErreurBackend() (finding
      // MEDIUM de la revue croisée UC03, tour 1).
      log::add('smartclim', 'warning', 'AUX Home : équipement "' . self::neutraliserPourLog($eqLogic->getName()) . '" (' . $eqLogic->getLogicalId() . ') introuvable au dernier scan');
      $disparus[] = array(
        'nom' => $eqLogic->getName(),
        'mac' => $mac,
        'identifiant' => $deviceId,
        'statutLibelle' => __('Introuvable au dernier scan', __FILE__),
      );
    }
    return $disparus;
  }

  /**
   * Libellé traduit d'une disponibilité (Oui/Non), UC04 du domaine
   * post-mvp/01-transport-broadlink-lan (§ 5.9 de sa spec technique). SEUL endroit du
   * plugin où vivent ces deux __() (même règle que libelleStatutLan()/
   * messageErreurAuxHome()).
   *
   * @param bool $_disponible
   * @return string
   */
  private static function libelleDisponibilite($_disponible) {
    return $_disponible ? __('Oui', __FILE__) : __('Non', __FILE__);
  }

  /**
   * Table de synthèse d'AC3 (UC04 du domaine post-mvp/01-transport-broadlink-lan, §
   * 5.8 de sa spec technique) : UNE ligne par eqLogic référencé par au moins une ligne
   * de scan (`equipementId` > 0, LAN ou cloud), triée par nom.
   *
   * *Arbitrage* (§ 5.8) : UNE requête SQL de plus (eqLogic::byType()) sur une opération
   * de ~40 s, plutôt que de faire remonter trois nouvelles clés par les deux phases —
   * la requête est le moindre coût.
   *
   * ⚠️ Ne LÈVE JAMAIS (try/catch(Throwable) global, tableau vide en dernier recours) :
   * cette méthode tourne APRÈS deux phases coûteuses, elle ne doit jamais faire perdre
   * leur résultat.
   *
   * ⚠️ +1 paramètre (UC02 du domaine post-mvp/03-cloud-aux-legacy, § 6.2 de sa spec
   * technique) : `$_auxCloud`, lignes normalisées par ligneResultatScan()
   * (scannerAuxCloud()) — alimente `equipementId > 0` comme les deux autres phases et
   * ajoute la colonne `cloudHistorique` (via libelleDisponibilite(), ZÉRO chaîne
   * nouvelle).
   *
   * @param array $_lignesLan Lignes normalisées par ligneResultatLan() (scannerReseauLocal()).
   * @param array $_lignesCloud Lignes normalisées par ligneResultatScan() (scannerAuxHome()).
   * @param array $_etatsConnexion Carte id eqLogic => etatConnexionAffichable(), déjà FUSIONNÉE (LAN puis cloud).
   * @param array $_auxCloud Lignes normalisées par ligneResultatScan() (scannerAuxCloud()).
   * @return array<int, array{nom:string, mac:string, lan:string, cloud:string, cloudHistorique:string, transport:string}>
   */
  private static function lignesFusionScan(array $_lignesLan, array $_lignesCloud, array $_etatsConnexion, array $_auxCloud = array()) {
    try {
      $ids = array();
      foreach ($_lignesLan as $ligneLan) {
        if (isset($ligneLan['equipementId']) && (int) $ligneLan['equipementId'] > 0) {
          $ids[(int) $ligneLan['equipementId']] = true;
        }
      }
      foreach ($_lignesCloud as $ligneCloud) {
        if (isset($ligneCloud['equipementId']) && (int) $ligneCloud['equipementId'] > 0) {
          $ids[(int) $ligneCloud['equipementId']] = true;
        }
      }
      foreach ($_auxCloud as $ligneAuxCloud) {
        if (isset($ligneAuxCloud['equipementId']) && (int) $ligneAuxCloud['equipementId'] > 0) {
          $ids[(int) $ligneAuxCloud['equipementId']] = true;
        }
      }
      if (empty($ids)) {
        return array();
      }

      $eqLogics = array();
      foreach (eqLogic::byType('smartclim') as $eqLogic) {
        $eqLogics[$eqLogic->getId()] = $eqLogic;
      }

      $lignes = array();
      foreach (array_keys($ids) as $id) {
        if (!isset($eqLogics[$id])) {
          continue;
        }
        $eqLogic = $eqLogics[$id];

        $lan = false;
        foreach ($_lignesLan as $ligneLan) {
          if (isset($ligneLan['equipementId']) && (int) $ligneLan['equipementId'] === $id && isset($ligneLan['statut']) && !self::statutEnEchec($ligneLan['statut'])) {
            $lan = true;
            break;
          }
        }
        $cloud = false;
        foreach ($_lignesCloud as $ligneCloud) {
          if (isset($ligneCloud['equipementId']) && (int) $ligneCloud['equipementId'] === $id && isset($ligneCloud['statut']) && in_array($ligneCloud['statut'], array('cree', 'existant'), true)) {
            $cloud = true;
            break;
          }
        }
        $cloudHistorique = false;
        foreach ($_auxCloud as $ligneAuxCloud) {
          if (isset($ligneAuxCloud['equipementId']) && (int) $ligneAuxCloud['equipementId'] === $id && isset($ligneAuxCloud['statut']) && in_array($ligneAuxCloud['statut'], array('cree', 'existant'), true)) {
            $cloudHistorique = true;
            break;
          }
        }

        $transport = (isset($_etatsConnexion[$id]['transport']) && is_string($_etatsConnexion[$id]['transport']) && $_etatsConnexion[$id]['transport'] !== '')
          ? $_etatsConnexion[$id]['transport']
          : __('Inconnu', __FILE__);

        $lignes[] = array(
          'nom' => $eqLogic->getName(),
          'mac' => $eqLogic->macEquipement(),
          'lan' => self::libelleDisponibilite($lan),
          'cloud' => self::libelleDisponibilite($cloud),
          'cloudHistorique' => self::libelleDisponibilite($cloudHistorique),
          'transport' => $transport,
        );
      }

      usort($lignes, function ($_a, $_b) {
        return strnatcasecmp($_a['nom'], $_b['nom']);
      });

      return $lignes;
    } catch (Throwable $t) {
      log::add('smartclim', 'error', 'Synthèse LAN/cloud du scan impossible : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
      return array();
    }
  }

  /**
   * Neutralise les caractères de CONTRÔLE (dont \n) d'une valeur qui n'est PAS
   * garantie exempte d'injection avant de la passer à log::add() — un nom
   * d'équipement (entrée CLIENT, renommable hors UI) ou le message d'un Throwable
   * (peut embarquer un fragment de donnée applicative). Même motif que
   * smartclimAuxHomeApi::nettoyerTexteExterne()/journaliserErreurBackend() (finding
   * sécurité de la revue croisée UC03, tour 1) : sans ce filtre, un "\n" forgé
   * fabrique des lignes de log arbitraires.
   *
   * @param mixed $_valeur
   * @return string
   */
  public static function neutraliserPourLog($_valeur) {
    if (!is_scalar($_valeur)) {
      return '';
    }
    return preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) $_valeur);
  }

  /**
   * Libellé traduit d'un statut de traitement d'appareil (AC8 — traduit côté
   * serveur, renvoyé prêt à l'affichage).
   *
   * @param string $_statut
   * @return string
   */
  private static function libelleStatut($_statut) {
    if ($_statut === 'cree') {
      return __('Créé', __FILE__);
    }
    if ($_statut === 'existant') {
      return __('Déjà présent', __FILE__);
    }
    if ($_statut === 'ignore_identifiant') {
      return __('Ignoré — aucun identifiant exploitable', __FILE__);
    }
    if ($_statut === 'ignore_doublon') {
      return __('Ignoré — doublon dans la réponse du cloud', __FILE__);
    }
    // UC02 du domaine post-mvp/03-cloud-aux-legacy (§ 7 de sa spec technique, correctif
    // reviews croisées findings major #1/#2) : les 3 motifs d'exclusion propres au
    // cloud legacy — jusqu'ici invisibles hors des logs — alimentent désormais aussi le
    // compteur 'ignores' de resumeScanAuxCloud(), même doctrine que les deux 'ignore_*'
    // existants ci-dessus.
    if ($_statut === 'ignore_pompe_chaleur') {
      return __('Ignoré — pompe à chaleur (hors périmètre du plugin)', __FILE__);
    }
    if ($_statut === 'ignore_non_climatiseur') {
      return __('Ignoré — appareil non reconnu comme climatiseur', __FILE__);
    }
    if ($_statut === 'ignore_budget') {
      return __('Ignoré — budget de temps épuisé', __FILE__);
    }
    if ($_statut === 'erreur') {
      return __('Erreur lors de la création — consultez les logs du plugin', __FILE__);
    }
    return '';
  }

  /**
   * Libellé traduit de l'état en ligne/hors ligne d'un appareil (AC8). Visibilité élargie
   * à public depuis l'UC01 du domaine post-mvp/06-ergonomie-jeedom (§ 6 de sa spec
   * technique) : réutilisée par smartclimWidget::chargeTuile() pour le libellé
   * 'hors_ligne' de la tuile — pas de redéclaration de la même chaîne dans une autre
   * classe.
   *
   * @param bool $_enLigne
   * @return string
   */
  public static function libelleEnLigne($_enLigne) {
    return $_enLigne ? __('En ligne', __FILE__) : __('Hors ligne', __FILE__);
  }

  /**
   * Phrase française résumant le résultat d'un scan (AC7/AC8). ⚠️ Message pluralisé :
   * chaque fragment enveloppe __() D'ABORD, puis sprintf() ENSUITE (§ 9 de la spec
   * technique, finding advisor) — jamais __($chaineDejaConstruite), l'extraction i18n
   * est un scan STATIQUE.
   *
   * @param array $_compteurs
   * @return string
   */
  private static function resumeScan(array $_compteurs) {
    if ($_compteurs['trouves'] === 0 && $_compteurs['disparus'] === 0) {
      return __('Aucun climatiseur trouvé sur ce compte', __FILE__);
    }

    if ($_compteurs['trouves'] === 0) {
      // Compte vide MAIS des équipements connus manquent à l'appel (AC6) : ne pas
      // court-circuiter le fragment "disparus" (minor de la revue croisée UC03, tour
      // 1) — le tableau des disparus, juste en dessous dans l'écran de résultat,
      // serait sinon en contradiction avec le résumé affiché au-dessus.
      return __('Aucun climatiseur trouvé sur ce compte', __FILE__) . '. ' . sprintf(__('%d climatiseur(s) déjà connu(s) sont introuvables sur le compte', __FILE__), $_compteurs['disparus']);
    }

    $fragments = array();
    $fragments[] = sprintf(__('%d climatiseur(s) trouvé(s) sur le compte', __FILE__), $_compteurs['trouves']);
    if ($_compteurs['crees'] > 0) {
      $fragments[] = sprintf(__('%d créé(s)', __FILE__), $_compteurs['crees']);
    }
    if ($_compteurs['existants'] > 0) {
      $fragments[] = sprintf(__('%d déjà connu(s)', __FILE__), $_compteurs['existants']);
    }
    if ($_compteurs['ignores'] > 0) {
      $fragments[] = sprintf(__('%d ignoré(s)', __FILE__), $_compteurs['ignores']);
    }
    if ($_compteurs['erreurs'] > 0) {
      $fragments[] = sprintf(__('%d en erreur', __FILE__), $_compteurs['erreurs']);
    }
    $resume = implode(', ', $fragments);
    if ($_compteurs['disparus'] > 0) {
      $resume .= '. ' . sprintf(__('%d climatiseur(s) déjà connu(s) sont introuvables sur le compte', __FILE__), $_compteurs['disparus']);
    }
    return $resume;
  }

  /**
   * Traduit le (type, contexte) d'une smartclimException levée par la brique de
   * transport en un message français curaté, sans jamais exposer de code métier AUX ni
   * de statut HTTP brut (AC4). Seul endroit du plugin où vivent ces __() : la brique de
   * transport (smartclimAuxHomeApi) ne porte qu'un type et un contexte technique (§ 5
   * de la spec technique — une clé i18n est indexée sous le fichier où vit l'appel
   * __(), les éparpiller produirait plusieurs entrées pour une même intention).
   *
   * @param int $_type Une des constantes smartclimException::TYPE_*.
   * @param string $_contexte '' ou smartclimException::CONTEXTE_REQUETE_INITIALE, ou
   *   (depuis l'UC01 du domaine post-mvp/04-fonctions-avancees)
   *   smartclimAuxHomeApi::CONTEXTE_ORDRE_REFUSE — les seuls cas où le message dépend
   *   du contexte.
   * @return string
   */
  private static function messageErreurAuxHome($_type, $_contexte) {
    if ($_type == smartclimException::TYPE_RESEAU) {
      return __('Service AUX Home injoignable, réessayez plus tard', __FILE__);
    }
    if ($_type == smartclimException::TYPE_PROTOCOLE) {
      // Constante neutre côté transport (jamais le littéral 'getPubkey', un nom
      // d'endpoint du protocole qui n'a rien à faire hors de smartclimAuxHomeApi,
      // cf. CLAUDE.md § Conventions — finding minor de la revue croisée).
      if ($_contexte == smartclimException::CONTEXTE_REQUETE_INITIALE) {
        return __('Le service AUX Home a refusé la requête initiale — vérifiez le code pays (FRA, BEL…)', __FILE__);
      }
      return __('Réponse inattendue du service AUX Home — consultez les logs du plugin', __FILE__);
    }
    if ($_type == smartclimException::TYPE_INTERNE) {
      return __('Erreur interne lors de la préparation de la connexion — consultez les logs du plugin', __FILE__);
    }
    // UC01 du domaine post-mvp/04-fonctions-avancees (§ 6/6.1 de sa spec technique) :
    // un ordre de confort refusé par le backend (ex. "Nettoyage automatique" sur une
    // clim allumée, § 2.1) traverse requeteControle() classé TYPE_AUTH, comme TOUT code
    // métier non classé — ce nouveau message s'affiche donc aussi pour un échec de
    // commande de base (marche/mode/consigne/vitesse), et c'est assumé (§ 6.1) : le
    // message reste vrai dans les deux cas plutôt que d'inverser le classement/la purge
    // de session, qui casserait le re-login réactif d'UC08.
    if ($_contexte === smartclimAuxHomeApi::CONTEXTE_ORDRE_REFUSE) {
      return __('Le service AUX Home a refusé la commande — cette fonction est peut-être indisponible dans l\'état actuel de l\'appareil', __FILE__);
    }
    // smartclimException::TYPE_AUTH, et repli par défaut pour tout type inattendu.
    return __('Échec de la connexion — vérifiez vos identifiants et le pays sélectionné', __FILE__);
  }

  /**
   * Traduit le (type, contexte) d'une smartclimException levée par le transport AUX
   * Cloud legacy (UC01 du domaine post-mvp/03-cloud-aux-legacy, § 6.2/7 de sa spec
   * technique) en un message français curaté — SEUL endroit du plugin où vivent ces
   * __(), fonction SÉPARÉE de messageErreurAuxHome()/messageErreurLan() (jamais
   * fusionnées : deux transports, deux vocabulaires d'erreur distincts). Discrimine
   * 4 causes (AC4) : identifiants invalides, service/région injoignable, magasin de
   * certificats local illisible, certificat serveur (ou magasin local) invalide.
   *
   * @param int $_type Une des constantes smartclimException::TYPE_*.
   * @param string $_contexte '' ou smartclimAuxCloudApi::CONTEXTE_CERTIFICAT /
   *   CONTEXTE_MAGASIN_LOCAL.
   * @return string
   */
  private static function messageErreurAuxCloud($_type, $_contexte) {
    if ($_type == smartclimException::TYPE_RESEAU) {
      if ($_contexte === smartclimAuxCloudApi::CONTEXTE_CERTIFICAT) {
        return __('Certificat de sécurité invalide (serveur ou magasin de certificats local)', __FILE__);
      }
      if ($_contexte === smartclimAuxCloudApi::CONTEXTE_MAGASIN_LOCAL) {
        return __('Magasin de certificats local de ce Jeedom illisible', __FILE__);
      }
      return __('Serveur du cloud historique injoignable, réessayez plus tard', __FILE__);
    }
    if ($_type == smartclimException::TYPE_PROTOCOLE) {
      return __('Réponse inattendue du cloud historique — consultez les logs du plugin', __FILE__);
    }
    if ($_type == smartclimException::TYPE_INTERNE) {
      return __('Erreur interne lors de la préparation de la connexion — consultez les logs du plugin', __FILE__);
    }
    // smartclimException::TYPE_AUTH, et repli par défaut pour tout type inattendu.
    return __('Identifiants invalides — vérifiez l\'e-mail, le mot de passe et la région', __FILE__);
  }

  /**
   * Traduit le (type, contexte) d'une smartclimException levée par le pilotage LOCAL
   * (UC03 du domaine post-mvp/01-transport-broadlink-lan, § 4.3 de sa spec technique)
   * en un message français curaté — SEUL endroit du plugin où vivent ces __() (même
   * règle que messageErreurAuxHome() ci-dessus, jamais fusionnées : deux transports,
   * deux vocabulaires d'erreur distincts).
   *
   * @param int $_type Une des constantes smartclimException::TYPE_*.
   * @param string $_contexte '' ou une des constantes CONTEXTE_LAN_ADRESSE_INCONNUE /
   *   CONTEXTE_LAN_MAC_DIVERGENTE / smartclimBroadlinkLan::CONTEXTE_ECRITURE_NON_CONFIRMEE.
   * @return string
   */
  private static function messageErreurLan($_type, $_contexte) {
    if ($_contexte === self::CONTEXTE_LAN_ADRESSE_INCONNUE) {
      return __('Adresse réseau locale inconnue pour cet équipement — lancez un scan ou renseignez l\'adresse IP', __FILE__);
    }
    if ($_contexte === self::CONTEXTE_LAN_MAC_DIVERGENTE) {
      return __('L\'appareil trouvé à cette adresse ne correspond pas à l\'équipement attendu', __FILE__);
    }
    if ($_contexte === smartclimBroadlinkLan::CONTEXTE_ECRITURE_NON_CONFIRMEE) {
      // Littéral DÉDIÉ de la spec fonctionnelle UC03 (§ Impact i18n) : l'ordre a été
      // EFFECTIVEMENT émis, seule sa confirmation manque.
      return __('Commande LAN non confirmée', __FILE__);
    }
    if ($_contexte === smartclimFrame::CONTEXTE_BASE_ILLISIBLE) {
      // Littéral DÉDIÉ (§ 12 de la spec technique UC03, revue croisée) : l'ordre n'a PAS
      // été envoyé — distinct du contexte ci-dessus, où il l'a été.
      return __('État de l\'appareil illisible : commande non envoyée pour ne pas dérégler le climatiseur', __FILE__);
    }
    if ($_contexte === smartclimFrame::CONTEXTE_CONSIGNE_HORS_PLAGE) {
      // Littéral DÉDIÉ (§ 12 de la spec technique UC03, revue croisée).
      return __('Consigne non transmissible en local (plage encodable : 8 à 39 °C)', __FILE__);
    }
    if ($_type == smartclimException::TYPE_AUTH) {
      return __('Authentification locale refusée par l\'appareil', __FILE__);
    }
    if ($_type == smartclimException::TYPE_PROTOCOLE) {
      return __('Réponse inattendue de l\'appareil sur le réseau local — consultez les logs du plugin', __FILE__);
    }
    if ($_type == smartclimException::TYPE_INTERNE) {
      return __('Erreur interne lors de l\'envoi de la commande locale — consultez les logs du plugin', __FILE__);
    }
    // smartclimException::TYPE_RESEAU générique (session/appareil injoignable, budget
    // insuffisant AVANT émission…), et repli par défaut pour tout type inattendu.
    return __('Appareil injoignable sur le réseau local, réessayez plus tard', __FILE__);
  }

  /**
   * Définition Jeedom des commandes info (UC05, § Signatures de la spec technique) :
   * logicalId => nom/subType/unité/generic_type/historisation/ordre/meta. La CLÉ EST le
   * logicalId, et pour les 6 concepts elle est identique au code de concept d'UC04
   * (rien à renommer). 'meta' => true : commande produite par le PLUGIN (transport,
   * horodatage), créée indépendamment du profil de capacités.
   *
   * ⚠️ 'generic_type' laissé VIDE sur ambient_temp (AC11 — cf. § « AC11 en détail » de
   * la spec technique, D-MVP05-04) : poser un generic_type de température y enrôlerait
   * automatiquement la valeur dans les intégrations tierces (thermostat, Alexa, Google)
   * comme une sonde de pièce fiable, ce que l'avertissement d'AC11 met précisément en
   * garde. Seul 'online' porte un generic_type ('ONLINE'). 'unite' reste posée (°C),
   * seul l'ENRÔLEMENT AUTOMATIQUE via generic_type est visé par AC11.
   *
   * @return array<string, array{name:string, subType:string, unite:string, generic_type:string, isHistorized:int, ordre:int, meta:bool}>
   */
  private static function definitionsCommandesInfo() {
    return array(
      smartclimCapabilities::CONCEPT_ONLINE => array(
        'name' => smartclimCapabilities::libelleCommande(smartclimCapabilities::CONCEPT_ONLINE),
        'subType' => 'binary',
        'unite' => '',
        'generic_type' => 'ONLINE',
        'isHistorized' => 0,
        'ordre' => 0,
        'meta' => false,
      ),
      smartclimCapabilities::CONCEPT_POWER => array(
        'name' => smartclimCapabilities::libelleCommande(smartclimCapabilities::CONCEPT_POWER),
        'subType' => 'binary',
        'unite' => '',
        'generic_type' => '',
        'isHistorized' => 0,
        'ordre' => 1,
        'meta' => false,
      ),
      smartclimCapabilities::CONCEPT_MODE => array(
        'name' => smartclimCapabilities::libelleCommande(smartclimCapabilities::CONCEPT_MODE),
        'subType' => 'string',
        'unite' => '',
        'generic_type' => '',
        'isHistorized' => 0,
        'ordre' => 2,
        'meta' => false,
      ),
      smartclimCapabilities::CONCEPT_TARGET_TEMP => array(
        'name' => smartclimCapabilities::libelleCommande(smartclimCapabilities::CONCEPT_TARGET_TEMP),
        'subType' => 'numeric',
        'unite' => '°C',
        'generic_type' => '',
        'isHistorized' => 1,
        'ordre' => 3,
        'meta' => false,
      ),
      smartclimCapabilities::CONCEPT_AMBIENT_TEMP => array(
        'name' => smartclimCapabilities::libelleCommande(smartclimCapabilities::CONCEPT_AMBIENT_TEMP),
        'subType' => 'numeric',
        'unite' => '°C',
        'generic_type' => '',
        'isHistorized' => 1,
        'ordre' => 4,
        'meta' => false,
      ),
      smartclimCapabilities::CONCEPT_FAN_SPEED => array(
        'name' => smartclimCapabilities::libelleCommande(smartclimCapabilities::CONCEPT_FAN_SPEED),
        'subType' => 'string',
        'unite' => '',
        'generic_type' => '',
        'isHistorized' => 0,
        'ordre' => 5,
        'meta' => false,
      ),
      // UC01 du domaine post-mvp/04-fonctions-avancees (§ 5.4 de sa spec technique) :
      // les 5 fonctions de confort sont TOUJOURS déclarées ici (comme les 6 concepts
      // historiques ci-dessus), mais ne sont créées par creerCommandesInfo() QUE si
      // leur concept figure dans capacites['concepts'] — lui-même n'y figure que si
      // smartclimCapabilities::conceptsConfortLivres() les inclut (§ 5.1/5.2). Aucune
      // commande n'apparaît donc tant que 'confirme' vaut false (livraison désactivée,
      // AC7). subType 'binary' comme 'power'/'online' : le core garde son widget natif,
      // aucun __('Actif')/__('Inactif') n'est introduit (§ 7 de la spec technique).
      smartclimCapabilities::CONCEPT_DISPLAY => array(
        'name' => smartclimCapabilities::libelleCommande(smartclimCapabilities::CONCEPT_DISPLAY),
        'subType' => 'binary',
        'unite' => '',
        'generic_type' => '',
        'isHistorized' => 0,
        'ordre' => 20,
        'meta' => false,
      ),
      smartclimCapabilities::CONCEPT_SLEEP => array(
        'name' => smartclimCapabilities::libelleCommande(smartclimCapabilities::CONCEPT_SLEEP),
        'subType' => 'binary',
        'unite' => '',
        'generic_type' => '',
        'isHistorized' => 0,
        'ordre' => 21,
        'meta' => false,
      ),
      smartclimCapabilities::CONCEPT_HEALTH => array(
        'name' => smartclimCapabilities::libelleCommande(smartclimCapabilities::CONCEPT_HEALTH),
        'subType' => 'binary',
        'unite' => '',
        'generic_type' => '',
        'isHistorized' => 0,
        'ordre' => 22,
        'meta' => false,
      ),
      smartclimCapabilities::CONCEPT_CLEAN => array(
        'name' => smartclimCapabilities::libelleCommande(smartclimCapabilities::CONCEPT_CLEAN),
        'subType' => 'binary',
        'unite' => '',
        'generic_type' => '',
        'isHistorized' => 0,
        'ordre' => 23,
        'meta' => false,
      ),
      smartclimCapabilities::CONCEPT_MILDEW => array(
        'name' => smartclimCapabilities::libelleCommande(smartclimCapabilities::CONCEPT_MILDEW),
        'subType' => 'binary',
        'unite' => '',
        'generic_type' => '',
        'isHistorized' => 0,
        'ordre' => 24,
        'meta' => false,
      ),
      // UC02 du domaine post-mvp/04-fonctions-avancees (§ 5.5.1 de sa spec technique) :
      // même patron que les fonctions de confort ci-dessus (créées SEULEMENT si le
      // concept figure dans capacites['concepts'], donc conceptsOscillationLivres()
      // 'confirme' => true). Le NOM porte, ou non, le suffixe « (état commandé) » selon
      // 'lecture' — cf. smartclimCapabilities::libelleCommande() (§ 5.1.3, porteur d'AC5).
      smartclimCapabilities::CONCEPT_SWING_V => array(
        'name' => smartclimCapabilities::libelleCommande(smartclimCapabilities::CONCEPT_SWING_V),
        'subType' => 'binary',
        'unite' => '',
        'generic_type' => '',
        'isHistorized' => 0,
        'ordre' => 25,
        'meta' => false,
      ),
      smartclimCapabilities::CONCEPT_SWING_H => array(
        'name' => smartclimCapabilities::libelleCommande(smartclimCapabilities::CONCEPT_SWING_H),
        'subType' => 'binary',
        'unite' => '',
        'generic_type' => '',
        'isHistorized' => 0,
        'ordre' => 26,
        'meta' => false,
      ),
      // UC03 du domaine post-mvp/04-fonctions-avancees (§ 5.3 de sa spec technique) :
      // même patron que ci-dessus (créée SEULEMENT si le concept figure dans
      // capacites['concepts'], donc conceptsProtectionLivres() 'confirme' => true). Le
      // NOM porte TOUJOURS le suffixe « (état commandé) » — cf. smartclimCapabilities::
      // libelleCommande(), repli inconditionnel, aucune lecture n'étant possible sur ce
      // transport (§ 5.1.3 de la spec technique).
      smartclimCapabilities::CONCEPT_CHILD_LOCK => array(
        'name' => smartclimCapabilities::libelleCommande(smartclimCapabilities::CONCEPT_CHILD_LOCK),
        'subType' => 'binary',
        'unite' => '',
        'generic_type' => '',
        'isHistorized' => 0,
        'ordre' => 27,
        'meta' => false,
      ),
      self::CMD_TRANSPORT => array(
        'name' => __('Transport actif', __FILE__),
        'subType' => 'string',
        'unite' => '',
        'generic_type' => '',
        'isHistorized' => 0,
        'ordre' => 6,
        'meta' => true,
      ),
      self::CMD_DERNIERE_MAJ => array(
        'name' => __('Dernière mise à jour', __FILE__),
        'subType' => 'string',
        'unite' => '',
        'generic_type' => '',
        'isHistorized' => 0,
        'ordre' => 7,
        'meta' => true,
      ),
    );
  }

  /**
   * Fonction exécutée automatiquement toutes les minutes par Jeedom (UC07, § 5 de la
   * spec technique). SEUL hook cron utilisé par le plugin : cron5()...cronDaily()
   * restent vides.
   *
   * ⚠️ Ce try/catch(Throwable) n'est PAS redondant avec celui de plugin::cron() : le
   * core journalise via log::exception(), qui imprime la TRACE DE PILE — or une trace
   * née dans la brique de transport peut porter le jeton de session en argument de
   * frame. C'est une garde de SÉCURITÉ, pas de confort. Ne jamais y appeler
   * getTraceAsString().
   */
  public static function cron() {
    try {
      if (self::cycleEchu()) {
        self::rafraichirAuxHome();
      }
    } catch (Throwable $t) {
      log::add('smartclim', 'error', 'Cycle de rafraîchissement AUX Home : erreur inattendue : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
    }

    // UC01 du domaine post-mvp/02-strategies-de-transport (§ 5.4/6 de sa spec
    // technique) : garde d'échéance INDÉPENDANTE, dans son PROPRE try/catch(Throwable) —
    // ⚠️ jamais de `return` court-circuitant ce second bloc : un cycle LAN en échec (ou
    // simplement pas encore échu) ne doit pas empêcher le cycle cloud du tick suivant,
    // et réciproquement (règle « un équipement en erreur n'interrompt pas la boucle »,
    // transposée aux deux cycles indépendants).
    try {
      if (self::cycleLanEchu()) {
        self::rafraichirLan();
      }
    } catch (Throwable $t) {
      log::add('smartclim', 'error', 'Cycle de sonde LAN : erreur inattendue : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
    }

    // UC03 du domaine post-mvp/03-cloud-aux-legacy (§ 3.0/3.4/D4 de sa spec technique,
    // arbitrage A) : TROISIÈME cycle, sa PROPRE garde d'échéance et son PROPRE
    // try/catch(Throwable) — ⚠️ jamais de `return` dans les DEUX blocs ci-dessus, qui
    // court-circuiterait celui-ci (piège déjà payé sur le cycle LAN).
    try {
      if (self::cycleAuxCloudEchu()) {
        self::rafraichirAuxCloud();
      }
    } catch (Throwable $t) {
      log::add('smartclim', 'error', 'Cycle de rafraîchissement AUX Cloud legacy : erreur inattendue : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
    }

    // UC03 du domaine post-mvp/05-temps-reel-et-demon (§ 4.2/5.4 de sa spec technique) :
    // 4e bloc, sa PROPRE garde d'échéance (synchroniserPushAuxCloud() ne fait rien
    // avant syncRelaisEchu()) et son PROPRE try/catch(Throwable) — ⚠️ jamais de `return`
    // dans les TROIS blocs ci-dessus, qui court-circuiterait celui-ci (même piège déjà
    // payé sur le cycle LAN puis le cycle AUX Cloud legacy). synchroniserPushAuxCloud()
    // ne lève elle-même jamais : ce try/catch est une défense en profondeur, cohérente
    // avec les trois blocs précédents.
    try {
      self::synchroniserPushAuxCloud();
    } catch (Throwable $t) {
      log::add('smartclim', 'error', 'Synchro relais AUX Cloud legacy : erreur inattendue : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
    }
  }

  /*
   * Cycle de vie du démon (UC02 du domaine post-mvp/05-temps-reel-et-demon) — trois
   * DÉLÉGATIONS statiques, rien d'autre : le core appelle deamon_info()/deamon_start()/
   * deamon_stop() EN STATIQUE SUR LA CLASSE PRINCIPALE (plugin::deamon_*, cf. spec
   * technique § 1.1) — ces trois méthodes n'ont pas le choix de leur domicile. Toute la
   * mécanique (port, chemins, état, lancement/arrêt, unique point socket) vit dans
   * smartclimDemon, seule classe autorisée à ouvrir un socket vers le démon — même règle
   * que « tout le LAN par smartclimBroadlinkLan, jamais de socket épars ».
   *
   * ⚠️ deamon_start() est déclarée SANS PARAMÈTRE : le core résout sa signature par
   * ReflectionMethod et ne transmet $_auto que si la méthode déclare au moins un
   * paramètre obligatoire (spec technique § 1.1) — un paramètre ici romprait ce contrat.
   * ⚠️ deamon_info() n'est PAS entourée d'un try/catch par le core (contrairement à
   * deamon_start()/deamon_stop()) : smartclimDemon::etat() ne lève JAMAIS.
   */
  public static function deamon_info() {
    return smartclimDemon::etat();
  }

  public static function deamon_start() {
    return smartclimDemon::lancer();
  }

  public static function deamon_stop() {
    return smartclimDemon::arreter();
  }

  /*
  * Fonction exécutée automatiquement toutes les 5 minutes par Jeedom
  public static function cron5() {}
  */

  /*
  * Fonction exécutée automatiquement toutes les 10 minutes par Jeedom
  public static function cron10() {}
  */

  /*
  * Fonction exécutée automatiquement toutes les 15 minutes par Jeedom
  public static function cron15() {}
  */

  /*
  * Fonction exécutée automatiquement toutes les 30 minutes par Jeedom
  public static function cron30() {}
  */

  /*
  * Fonction exécutée automatiquement toutes les heures par Jeedom
  public static function cronHourly() {}
  */

  /*
  * Fonction exécutée automatiquement tous les jours par Jeedom
  public static function cronDaily() {}
  */

  /**
   * Garde d'échéance du cycle automatique (UC07, § 5 de la spec technique) : lit le
   * marqueur de dernier cycle en cache. Marge de MARGE_ECHEANCE_CYCLE secondes : les
   * ticks de cron ne sont pas espacés d'exactement 60 s ; sans cette marge, un
   * intervalle de N minutes dégénère en N+1 dès qu'un tick arrive en retard.
   *
   * @return bool
   */
  private static function cycleEchu() {
    $dernier = cache::byKey(self::CLE_CACHE_DERNIER_CYCLE)->getValue(null);
    if (!is_numeric($dernier)) {
      // Jamais tourné, ou cache purgé.
      return true;
    }
    $ecoule = time() - (int) $dernier;
    if ($ecoule < 0) {
      // Horloge reculée (Jeedom sans RTC, resynchro NTP au démarrage) : neutralisé.
      return true;
    }
    return $ecoule >= (self::intervalleRafraichissement() * 60 - self::MARGE_ECHEANCE_CYCLE);
  }

  /**
   * Pose le marqueur de dernier cycle (UC07). Valeur stockée en CHAÎNE, relue via
   * is_numeric() (même prudence que memoireOrdres() vis-à-vis du moteur de cache).
   */
  private static function marquerCycle() {
    cache::set(self::CLE_CACHE_DERNIER_CYCLE, (string) time(), self::DUREE_MEMOIRE_CYCLE);
  }

  /**
   * Garde d'échéance du cycle de SONDE LAN périodique (UC01 du domaine
   * post-mvp/02-strategies-de-transport, § 5.5/6 de sa spec technique) — MÊME patron
   * que cycleEchu() ci-dessus (marqueur DÉDIÉ, horloge reculée neutralisée), mais
   * cadence FIXE INTERVALLE_CYCLE_LAN (15 min), DÉCOUPLÉE de refresh_interval : un
   * utilisateur réglé à 1 min transformerait sinon le processus PARTAGÉ plugin::cron en
   * générateur de trafic UDP.
   *
   * @return bool
   */
  private static function cycleLanEchu() {
    $dernier = cache::byKey(self::CLE_CACHE_DERNIER_CYCLE_LAN)->getValue(null);
    if (!is_numeric($dernier)) {
      return true;
    }
    $ecoule = time() - (int) $dernier;
    if ($ecoule < 0) {
      return true;
    }
    return $ecoule >= (self::INTERVALLE_CYCLE_LAN - self::MARGE_ECHEANCE_CYCLE);
  }

  /**
   * Pose le marqueur de dernier cycle LAN (même patron que marquerCycle()).
   */
  private static function marquerCycleLan() {
    cache::set(self::CLE_CACHE_DERNIER_CYCLE_LAN, (string) time(), self::DUREE_MEMOIRE_CYCLE);
  }

  /**
   * Garde d'échéance du 3ᵉ cycle (UC03 du domaine post-mvp/03-cloud-aux-legacy, § 3.4/D4
   * de sa spec technique) — jumelle EXACTE de cycleLanEchu() : marqueur DÉDIÉ, cadence
   * FIXE INTERVALLE_CYCLE_AUXCLOUD (900 s), horloge reculée neutralisée.
   *
   * @return bool
   */
  private static function cycleAuxCloudEchu() {
    $dernier = cache::byKey(self::CLE_CACHE_DERNIER_CYCLE_AUXCLOUD)->getValue(null);
    if (!is_numeric($dernier)) {
      return true;
    }
    $ecoule = time() - (int) $dernier;
    if ($ecoule < 0) {
      return true;
    }
    return $ecoule >= (self::INTERVALLE_CYCLE_AUXCLOUD - self::MARGE_ECHEANCE_CYCLE);
  }

  /**
   * Pose le marqueur de dernier cycle AUX Cloud legacy (même patron que marquerCycleLan()).
   */
  private static function marquerCycleAuxCloud() {
    cache::set(self::CLE_CACHE_DERNIER_CYCLE_AUXCLOUD, (string) time(), self::DUREE_MEMOIRE_CYCLE);
  }

  /**
   * Garde d'échéance de la SYNCHRONISATION de l'abonnement au relais WebSocket AUX Cloud
   * legacy (UC03 du domaine post-mvp/05-temps-reel-et-demon, § 5.4 de sa spec
   * technique) — jumelle EXACTE de cycleAuxCloudEchu()/cycleLanEchu().
   *
   * @return bool
   */
  private static function syncRelaisEchu() {
    $dernier = cache::byKey(self::CLE_CACHE_DERNIER_SYNC_RELAIS)->getValue(null);
    if (!is_numeric($dernier)) {
      return true;
    }
    $ecoule = time() - (int) $dernier;
    if ($ecoule < 0) {
      return true;
    }
    return $ecoule >= (self::INTERVALLE_SYNC_RELAIS - self::MARGE_ECHEANCE_CYCLE);
  }

  /**
   * Pose le marqueur de dernière synchro relais (même patron que marquerCycleAuxCloud()).
   */
  private static function marquerSyncRelais() {
    cache::set(self::CLE_CACHE_DERNIER_SYNC_RELAIS, (string) time(), self::DUREE_MEMOIRE_CYCLE);
  }

  /**
   * Garde d'échéance du FILET de sécurité (UC03 du domaine post-mvp/05-temps-reel-et-
   * demon, § 3/5.4 de sa spec technique) — même patron que les jumelles ci-dessus, MAIS
   * cadence INTERVALLE_FILET_AUXCLOUD (3600 s, ×4 par rapport au cycle de scrutation
   * legacy lui-même).
   *
   * @return bool
   */
  private static function filetAuxCloudEchu() {
    $dernier = cache::byKey(self::CLE_CACHE_DERNIER_FILET_AUXCLOUD)->getValue(null);
    if (!is_numeric($dernier)) {
      return true;
    }
    $ecoule = time() - (int) $dernier;
    if ($ecoule < 0) {
      return true;
    }
    return $ecoule >= (self::INTERVALLE_FILET_AUXCLOUD - self::MARGE_ECHEANCE_CYCLE);
  }

  /**
   * Pose le marqueur de dernier filet AUX Cloud legacy (même patron que marquerSyncRelais()).
   */
  private static function marquerFiletAuxCloud() {
    cache::set(self::CLE_CACHE_DERNIER_FILET_AUXCLOUD, (string) time(), self::DUREE_MEMOIRE_CYCLE);
  }

  /**
   * Synchronise l'abonnement au relais WebSocket AUX Cloud legacy (UC03 du domaine
   * post-mvp/05-temps-reel-et-demon, § 5.4 de sa spec technique) — 4e bloc de cron().
   * Gardes DANS CET ORDRE : compteAuxCloudConfigure() -> urlRelais() non vide -> démon
   * 'ok' -> marqueur d'échéance (posé APRÈS les gardes, AVANT tout appel réseau, même
   * doctrine que marquerCycle()/marquerCycleLan()). Cible = MÊME filtre que
   * rafraichirAuxCloud() (lectureLegacyAutorisee()). Cible vide => paquet VIDE envoyé
   * (le démon ferme la session côté relais), SANS login. NE LÈVE JAMAIS.
   *
   * @param bool $_force Ignore l'échéance — réservé à l'usage CLI --relais-sync.
   * @return array{lance:bool, appareils:int}
   */
  public static function synchroniserPushAuxCloud($_force = false) {
    $resultat = array('lance' => false, 'appareils' => 0);
    try {
      // Zéro requête réseau, et AUCUN marqueur posé si le compte n'est pas configuré ou
      // si la région n'a aucun relais connu (RUS) — même règle que rafraichirAuxCloud().
      if (!self::compteAuxCloudConfigure()) {
        return $resultat;
      }
      if (smartclimAuxCloudApi::urlRelais() === '') {
        return $resultat;
      }
      $etatDemon = smartclimDemon::etat();
      if (!isset($etatDemon['state']) || $etatDemon['state'] !== 'ok') {
        return $resultat;
      }
      if (!$_force && !self::syncRelaisEchu()) {
        return $resultat;
      }

      // Marqueur posé AVANT tout appel réseau (même règle que marquerCycle()/
      // marquerCycleLan()/marquerCycleAuxCloud()).
      self::marquerSyncRelais();

      $cibles = array();
      foreach (eqLogic::byType('smartclim', true) as $eqLogic) {
        if ($eqLogic instanceof smartclim && smartclimTransport::lectureLegacyAutorisee($eqLogic)) {
          $cibles[] = $eqLogic;
        }
      }
      $resultat['appareils'] = count($cibles);

      if (empty($cibles)) {
        // Cible vide = paquet VIDE : le démon ferme la session relais s'il en tenait
        // une (§ 5.1 de la spec technique). Aucun login déclenché pour autant.
        smartclimDemon::envoyerRelais(array('appareils' => array()));
        return $resultat;
      }

      $debut = microtime(true);
      $session = smartclimAuxCloudApi::session();
      $appareilsRelais = array();
      foreach ($cibles as $eqLogic) {
        $restant = self::BUDGET_SYNC_RELAIS - (microtime(true) - $debut);
        if ($restant <= 0) {
          log::add('smartclim', 'warning', 'Synchro relais AUX Cloud legacy : budget de temps épuisé, équipements restants ignorés');
          break;
        }
        try {
          $appareil = $eqLogic->appareilAuxCloud(max(3, $restant));
          $appareilsRelais[] = array(
            // ⚠️ § 1.3 de la spec technique : sub.pid = productId = 'type_produit' —
            // une erreur ici rend l'abonnement MUET, sans aucun signal.
            'endpointId' => $appareil['identifiant'],
            'devSession' => $appareil['dev_session'],
            'pid' => $appareil['type_produit'],
          );
        } catch (Throwable $t) {
          log::add('smartclim', 'warning', 'Synchro relais AUX Cloud legacy : équipement "' . self::neutraliserPourLog($eqLogic->getHumanName()) . '" ignoré : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
        }
      }

      if (empty($appareilsRelais)) {
        return $resultat;
      }

      $paquet = array(
        'url' => smartclimAuxCloudApi::urlRelais(),
        'entetes' => smartclimAuxCloudApi::enTetesRelais($session),
        'session' => array(
          'loginsession' => isset($session['loginsession']) ? $session['loginsession'] : '',
          'userid' => isset($session['userid']) ? $session['userid'] : '',
        ),
        'appareils' => $appareilsRelais,
      );
      // Empreinte du CONTENU du paquet (§ 5.1 de la spec technique) : le démon s'en
      // sert pour éviter de casser une session stable toutes les 10 min sur un paquet
      // identique.
      $paquet['empreinte'] = sha1((string) json_encode($paquet));

      smartclimDemon::envoyerRelais($paquet);
      $resultat['lance'] = true;
      return $resultat;
    } catch (Throwable $t) {
      log::add('smartclim', 'error', 'Synchro relais AUX Cloud legacy : erreur interne inattendue : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
      return $resultat;
    }
  }

  /**
   * Route un ou plusieurs événements poussés par le relais vers l'équipement concerné
   * (UC03 du domaine post-mvp/05-temps-reel-et-demon, § 5.4/4.2 de sa spec technique) —
   * appelée UNIQUEMENT par core/php/jeeSmartclim.php (branche 'auxcloud.push').
   * try/catch(Throwable) PAR PUSH : un push en échec n'empêche pas les suivants.
   *
   * ⚠️ AC5 est tenu ici, et nulle part ailleurs : appliquerEtat($etat) appelée SANS
   * $_optimiste => filtrerEtatSelonOrdres() s'applique, exactement comme pour le cron
   * legacy — un push relayant encore l'état antérieur à une commande récente n'écrase
   * pas la valeur commandée pendant la période de grâce.
   *
   * @param array $_pushs endpointId => enveloppe (cf. smartclimAuxCloudApi::valeursDepuisPush()).
   * @return int Nombre de pushs effectivement appliqués (au moins un concept modifié).
   */
  public static function appliquerPushAuxCloud(array $_pushs) {
    $index = self::indexerEquipements();
    $applique = 0;
    foreach ($_pushs as $endpointId => $enveloppe) {
      if (!is_string($endpointId) || !is_array($enveloppe)) {
        continue;
      }
      // Barrière PHP du § 4.1 de la spec technique : valide la forme de l'endpointId AVANT tout
      // usage (y compris la recherche dans l'index). Volontairement redondante avec REGEX_ENDPOINT
      // côté démon (relais_auxcloud.py) — défense en profondeur, à ne supprimer ni l'une ni l'autre.
      if (!is_string($endpointId) || strpos($endpointId, '::') !== false || preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $endpointId) !== 1) {
        continue;
      }
      $eqLogic = isset($index['parEndpointAuxCloud'][$endpointId]) ? $index['parEndpointAuxCloud'][$endpointId] : null;
      if (!($eqLogic instanceof smartclim)) {
        // Log SANS l'identifiant complet (§ 4.1 de la spec technique) : un endpointId
        // inconnu du parc n'est pas une donnée sensible, mais autant rester prudent.
        log::add('smartclim', 'debug', 'Push relais AUX Cloud legacy ignoré : endpointId inconnu du parc');
        continue;
      }
      if ($eqLogic->getIsEnable() == 0 || !smartclimTransport::lectureLegacyAutorisee($eqLogic)) {
        continue;
      }
      try {
        $valeurs = smartclimAuxCloudApi::valeursDepuisPush($enveloppe);
        if (empty($valeurs)) {
          continue;
        }
        $appareil = array(
          'valeurs' => $valeurs,
          'swing_inverse' => (bool) $eqLogic->getConfiguration(self::CLE_CONF_AUXCLOUD_SWING_INVERSE),
        );
        // ⚠️ Le push ne pose JAMAIS 'online' (§ 5.3 de la spec technique) : etatAppareil()
        // n'est appelée ici QU'AVEC 'valeurs', jamais 'enLigne_connue'.
        $etat = smartclimAuxCloudApi::etatAppareil($appareil);
        if ($eqLogic->appliquerEtat($etat)) {
          $applique++;
        }
      } catch (Throwable $t) {
        log::add('smartclim', 'warning', 'Push relais AUX Cloud legacy : équipement "' . self::neutraliserPourLog($eqLogic->getHumanName()) . '" en échec : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
      }
    }
    return $applique;
  }

  /**
   * Invalide le marqueur de synchro relais (UC03 de ce domaine, § 5.4 de sa spec
   * technique) — appelée UNIQUEMENT par core/php/jeeSmartclim.php (branche
   * 'pont.demarre') et par preRemove(). AUCUN réseau : c'est tout l'effet du signal de
   * démarrage du démon (une resynchro au prochain tick de cron plutôt que d'attendre
   * jusqu'à INTERVALLE_SYNC_RELAIS après un redémarrage).
   */
  public static function invaliderSyncRelais() {
    cache::delete(self::CLE_CACHE_DERNIER_SYNC_RELAIS);
  }

  /**
   * Surface UNIQUE de la CLI --relais (patron diagnosticTransport()) : état et âge du
   * battement, abonnés, confirmés, et PAR équipement legacy : endpoint, push actif,
   * cadence effective (UC03 de ce domaine, § 5.4 de sa spec technique). AUCUNE émission
   * réseau — c'est un rapport de lecture d'état INTERNE (cache), pas une sonde.
   *
   * @return array{battement:?array, equipements:array}
   */
  public static function diagnosticRelaisAuxCloud() {
    $battement = smartclimDemon::battementRelais();
    $ageBattement = ($battement !== null) ? max(0, time() - $battement['ts']) : null;

    $equipements = array();
    foreach (eqLogic::byType('smartclim', true) as $eqLogic) {
      if (!($eqLogic instanceof smartclim) || !smartclimTransport::lectureLegacyAutorisee($eqLogic)) {
        continue;
      }
      $endpointId = $eqLogic->getConfiguration(self::CLE_CONF_AUXCLOUD_ENDPOINT_ID);
      $pushActif = $eqLogic->pushAuxCloudActif($battement);
      $equipements[] = array(
        'nom' => self::neutraliserPourLog($eqLogic->getHumanName()),
        'endpointId' => is_string($endpointId) ? $endpointId : '',
        'pushActif' => $pushActif,
        'cadence' => $pushActif ? self::INTERVALLE_FILET_AUXCLOUD : self::INTERVALLE_CYCLE_AUXCLOUD,
      );
    }

    return array(
      'battement' => ($battement !== null) ? array(
        'etat' => $battement['etat'],
        'age' => $ageBattement,
        'abonnes' => count($battement['abonnes']),
        'confirmes' => count($battement['confirmes']),
      ) : null,
      'equipements' => $equipements,
    );
  }

  /**
   * Mémorise le dernier échec de CONNEXION du cycle automatique (UC08, AC9, § 5 de la
   * spec technique) : invariant en une phrase — seul le cycle automatique écrit
   * l'incident, toute connexion réussie l'efface (oublierIncident()). Cache NON
   * chiffré : aucun secret dedans (un type 1..4, une constante de contexte neutre, un
   * timestamp). Appelée depuis les catch INTERNES de rafraichirAuxHome(), déjà couverte
   * par son catch(Throwable) global : pas de try/catch propre nécessaire ici.
   *
   * @param int $_type Une des constantes smartclimException::TYPE_*.
   * @param string $_contexte '' ou smartclimException::CONTEXTE_REQUETE_INITIALE.
   */
  private static function memoriserIncident($_type, $_contexte) {
    cache::set(self::CLE_CACHE_DERNIER_INCIDENT, json_encode(array(
      'type' => (int) $_type,
      'contexte' => is_string($_contexte) ? $_contexte : '',
      'ts' => time(),
    )), self::DUREE_MEMOIRE_CYCLE);
  }

  /**
   * Efface la mémoire d'incident : dès qu'une connexion RÉUSSIT (cycle automatique,
   * scan manuel, test de connexion), et à tout changement d'identifiants (UC08, § 5.2
   * de la spec technique).
   */
  private static function oublierIncident() {
    cache::delete(self::CLE_CACHE_DERNIER_INCIDENT);
  }

  /**
   * Dernier incident de connexion mémorisé, ou null si absent/illisible/de forme non
   * conforme (UC08, § 5.1 de la spec technique) : aucun état forgé n'est affiché plutôt
   * qu'un état inventé sur une entrée de cache corrompue.
   *
   * @return array{type:int, contexte:string, ts:int}|null
   */
  private static function incidentMemorise() {
    $brut = cache::byKey(self::CLE_CACHE_DERNIER_INCIDENT)->getValue(null);
    if (!is_string($brut) || $brut === '') {
      return null;
    }
    $incident = json_decode($brut, true);
    if (
      !is_array($incident)
      || !isset($incident['type'], $incident['contexte'], $incident['ts'])
      || !is_numeric($incident['type'])
      || !is_string($incident['contexte'])
      || !is_numeric($incident['ts'])
    ) {
      return null;
    }
    $type = (int) $incident['type'];
    $typesConnus = array(
      smartclimException::TYPE_RESEAU,
      smartclimException::TYPE_AUTH,
      smartclimException::TYPE_PROTOCOLE,
      smartclimException::TYPE_INTERNE,
    );
    if (!in_array($type, $typesConnus, true)) {
      return null;
    }
    return array('type' => $type, 'contexte' => $incident['contexte'], 'ts' => (int) $incident['ts']);
  }

  /**
   * Index des équipements smartclim par identifiant d'appareil AUX Home (UC07, § 6 de
   * la spec technique) — clé UNIQUEMENT `auxhome_device_id`, jamais MAC/MAC inversée
   * (chercherEquipementExistant() n'est pas réutilisée ici : elle journalise un
   * warning à chaque rapprochement par MAC inversée, acceptable une fois par SCAN,
   * insupportable des centaines de fois par jour en cron). Valeur = LISTE (un
   * device_id peut être partagé par un équipement dupliqué dans Jeedom).
   *
   * @param array $_equipements
   * @return array<string, smartclim[]>
   */
  private static function equipementsParIdentifiant(array $_equipements) {
    $index = array();
    foreach ($_equipements as $eqLogic) {
      if (!($eqLogic instanceof smartclim)) {
        continue;
      }
      $identifiant = $eqLogic->getConfiguration('auxhome_device_id');
      if (!is_string($identifiant) || $identifiant === '') {
        continue;
      }
      if (!isset($index[$identifiant])) {
        $index[$identifiant] = array();
      }
      $index[$identifiant][] = $eqLogic;
    }
    return $index;
  }

  /**
   * Pousse `online = false` sur chaque équipement des groupes passés (UC07, § 6 de la
   * spec technique — AC7). AC7 tenu par le mécanisme d'UC05 : seule la clé `online`
   * étant présente dans le tableau, aucune autre commande n'est écrite. Le warning
   * n'est journalisé QU'À LA TRANSITION (retour de appliquerEtat()), pas à chaque
   * cycle d'une panne longue.
   *
   * ⚠️ setStatus() n'est volontairement PAS utilisé ici : cmd::event() force
   * timeout => 0 à chaque poussée de valeur (le badge "timeout" du core ne peut donc
   * pas signaler notre hors-ligne), checkAlive() est propriétaire de `timeout` côté
   * core, et warning/danger appartiennent au calcul de niveau d'alerte des commandes.
   * La commande info `online` reste le seul porteur de l'état de joignabilité.
   *
   * @param array<string, smartclim[]> $_groupes
   * @param string $_motif Fragment de log français NON traduit (convention du dépôt).
   * @return int Nombre d'équipements réellement BASCULÉS (transition).
   */
  private static function basculerHorsLigne(array $_groupes, $_motif) {
    $bascules = 0;
    foreach ($_groupes as $groupe) {
      foreach ($groupe as $eqLogic) {
        if (!($eqLogic instanceof smartclim)) {
          continue;
        }
        try {
          if ($eqLogic->appliquerEtat(array(smartclimCapabilities::CONCEPT_ONLINE => false))) {
            log::add('smartclim', 'warning', 'Équipement "' . self::neutraliserPourLog($eqLogic->getHumanName()) . '" : ' . $_motif);
            $bascules++;
          }
        } catch (Throwable $t) {
          log::add('smartclim', 'error', 'Bascule hors ligne impossible (équipement "' . self::neutraliserPourLog($eqLogic->getHumanName()) . '") : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
        }
      }
    }
    return $bascules;
  }

  /**
   * Cycle de rafraîchissement AUX Home (UC07, § 6 de la spec technique) : UN SEUL
   * appel réseau (listerAppareils()), puis distribution vers les équipements ciblés.
   * NE LÈVE JAMAIS — tout échec (global ou par équipement) est absorbé et journalisé ;
   * un échec INTERNE imprévu (hors appel réseau/distribution, ex. incident ORM sur
   * eqLogic::byType()) est signalé via $resultat['echecType'] = TYPE_INTERNE, seul
   * canal consommé par rafraichirMaintenant() pour distinguer un cycle qui a
   * réellement tourné d'un succès silencieux.
   * Appelée par cron() (automatique) et smartclim::rafraichirMaintenant() (manuel, §
   * 7 de la spec technique).
   *
   * ⚠️ Ne touche JAMAIS les capacités ni le profil (aucun appliquerCapacites(), aucun
   * eqLogic->save()) : c'est un cycle de LECTURE D'ÉTAT SEULE — la migration du parc
   * reste le rôle exclusif du scan (UC03).
   *
   * @return array{lance:bool, appareils:int, rafraichis:int, horsLigne:int, erreurs:int, echecType:int|null, echecContexte:string}
   */
  private static function rafraichirAuxHome() {
    $resultat = array(
      'lance' => false,
      'appareils' => 0,
      'rafraichis' => 0,
      'horsLigne' => 0,
      'erreurs' => 0,
      'echecType' => null,
      'echecContexte' => '',
    );

    try {
      // Zéro requête réseau, et AUCUN marqueur posé (§ 6, étape 1 de la spec
      // technique) : dès que l'utilisateur configure son compte, le tick suivant
      // lance un cycle sans attendre un intervalle complet.
      if (!self::compteConfigure()) {
        log::add('smartclim', 'debug', 'Cycle de rafraîchissement AUX Home ignoré : compte non configuré');
        return $resultat;
      }

      // Marqueur posé AVANT l'appel réseau (D-MVP07-02) : sinon un cloud en panne
      // serait re-sollicité CHAQUE minute, jusqu'à consommer une part notable du
      // budget du processus plugin::cron, qui exécute séquentiellement les crons de
      // TOUS les plugins.
      self::marquerCycle();
      $resultat['lance'] = true;

      // UC01 du domaine post-mvp/02-strategies-de-transport (§ 5.4 de sa spec
      // technique) : un équipement en mode LOCAL est retiré du cycle cloud AVANT
      // equipementsParIdentifiant() — foreach EXPLICITE (⚠️ os.min: 10 => Debian 10 =>
      // PHP 7.3 : pas de fonction fléchée). Effets voulus : un parc 100 % LOCAL sort
      // AVANT listerAppareils() (zéro requête cloud, AC4) ; un équipement LOCAL n'est
      // jamais atteint par basculerHorsLigne() (une panne WAN ne le passe pas
      // online = false alors qu'il répond en local).
      $equipements = eqLogic::byType('smartclim', true);
      $equipementsCloud = array();
      foreach ($equipements as $eqLogic) {
        if ($eqLogic instanceof smartclim && smartclimTransport::lectureCloudAutorisee($eqLogic)) {
          $equipementsCloud[] = $eqLogic;
        }
      }
      $cibles = self::equipementsParIdentifiant($equipementsCloud);
      if (empty($cibles)) {
        return $resultat;
      }

      try {
        $appareils = smartclimAuxHomeApi::listerAppareils();
      } catch (smartclimException $e) {
        // 'warning' si transitoire (attendu, évite d'inonder le journal pendant une
        // coupure), 'error' sinon (actionnable). Message TECHNIQUE, jamais affiché.
        $niveau = ($e->getType() == smartclimException::TYPE_RESEAU) ? 'warning' : 'error';
        log::add('smartclim', $niveau, 'Cycle de rafraîchissement AUX Home échoué (type ' . $e->getType() . ') : ' . self::neutraliserPourLog($e->getMessage()));
        $resultat['echecType'] = $e->getType();
        $resultat['echecContexte'] = $e->getContexte();
        // UC08, AC9 : mémorise l'incident de CONNEXION du cycle automatique (§ 5.2 de
        // la spec technique) — seul le cycle automatique écrit cette mémoire.
        self::memoriserIncident($e->getType(), $e->getContexte());
        $resultat['horsLigne'] = self::basculerHorsLigne($cibles, 'appareil AUX Home injoignable au dernier cycle');
        return $resultat;
      } catch (Throwable $t) {
        log::add('smartclim', 'error', 'Cycle de rafraîchissement AUX Home : erreur inattendue lors de la lecture du compte : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
        $resultat['echecType'] = smartclimException::TYPE_INTERNE;
        $resultat['echecContexte'] = '';
        // UC08, AC9 : même motif que ci-dessus, branche « erreur inattendue » de
        // listerAppareils() (§ 5.2 de la spec technique).
        self::memoriserIncident(smartclimException::TYPE_INTERNE, '');
        $resultat['horsLigne'] = self::basculerHorsLigne($cibles, 'appareil AUX Home injoignable au dernier cycle');
        return $resultat;
      }

      // UC08, AC9 : un listerAppareils() réussi efface l'incident mémorisé (§ 5.2 de
      // la spec technique) — les deux ne peuvent pas coexister.
      self::oublierIncident();

      $resultat['appareils'] = count($appareils);

      foreach ($appareils as $appareil) {
        $identifiant = is_string($appareil['identifiant']) ? $appareil['identifiant'] : '';
        if ($identifiant === '' || !isset($cibles[$identifiant])) {
          // Appareil du cloud inconnu de Jeedom (découverte hors périmètre) : ignoré
          // silencieusement.
          continue;
        }
        foreach ($cibles[$identifiant] as $eqLogic) {
          try {
            $eqLogic->appliquerEtat(smartclimAuxHomeApi::etatAppareil($appareil));
            // UC02 du domaine post-mvp/02-strategies-de-transport (§ 0.1/5.3 de sa
            // spec technique) : SEULE inflexion à l'inertie hors repli — une lecture de
            // cache PAR équipement et par cycle cloud, no-op si cloud === 0. Un
            // appliquerEtat() réussi prouve que le cloud répond POUR CET appareil.
            $eqLogic->oublierEchecsCloud();
            $resultat['rafraichis']++;
          } catch (Throwable $t) {
            // Une Error PHP 8 ne doit pas traverser : la boucle continue (AC4).
            $resultat['erreurs']++;
            log::add('smartclim', 'error', 'Cycle de rafraîchissement AUX Home : équipement "' . self::neutraliserPourLog($eqLogic->getHumanName()) . '" en erreur : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
          }
        }
        unset($cibles[$identifiant]);
      }

      // Ce qui reste dans $cibles : équipements Jeedom dont l'appareil n'est plus
      // renvoyé par le compte (AC4). Jamais de suppression, jamais de désactivation.
      $resultat['horsLigne'] = self::basculerHorsLigne($cibles, 'appareil absent de la réponse du compte AUX Home');

      log::add('smartclim', 'debug', 'Cycle de rafraîchissement AUX Home : ' . $resultat['appareils'] . ' appareil(s) reçu(s), ' . $resultat['rafraichis'] . ' rafraîchi(s), ' . $resultat['horsLigne'] . ' basculé(s) hors ligne, ' . $resultat['erreurs'] . ' erreur(s)');

      return $resultat;
    } catch (Throwable $t) {
      // Filet de sécurité INTERNE (hors appel réseau/distribution, déjà gardés
      // ci-dessus) : ex. incident ORM sur eqLogic::byType()/equipementsParIdentifiant().
      // Ne bascule PAS les équipements hors ligne ici : une erreur interne ne dit
      // rien de leur joignabilité, et l'index des cibles peut ne même pas exister.
      log::add('smartclim', 'error', 'Cycle de rafraîchissement AUX Home : erreur interne inattendue : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
      $resultat['echecType'] = smartclimException::TYPE_INTERNE;
      return $resultat;
    }
  }

  /**
   * Normalise l'e-mail du compte AUX Home avant enregistrement — délègue à
   * normaliserEmail() (même règle qu'à la lecture, cf. emailAuxHome()).
   * ⚠️ Jamais de throw ici (cf. preConfig_auxhome_country ci-dessous) : $value peut
   * provenir d'un appel programmatique (scénario, JSON-RPC) et n'est pas garanti scalaire.
   */
  public static function preConfig_auxhome_email($value) {
    return self::normaliserEmail($value);
  }

  /**
   * Normalise le code pays avant enregistrement — délègue à normaliserPays() (même
   * règle qu'à la lecture, cf. paysAuxHome()). Si le résultat n'est pas conforme, repli
   * sur PAYS_DEFAUT : le formulaire n'enregistre jamais un pays vide, qui bloquerait
   * toute connexion au cloud sans que rien ne l'indique dans l'interface.
   * ⚠️ Aucun throw ici : config.ajax.php::addKey boucle sans transaction sur les clés
   * de configuration, une exception ferait perdre les clés suivantes (dont
   * refresh_interval).
   * ⚠️ Enregistrer une valeur ÉGALE au défaut de l'INI supprime la ligne en base et
   * court-circuite ce hook (piège documenté dans CLAUDE.md) : c'est sans conséquence
   * ici, la lecture appliquant la même normalisation et le même défaut.
   */
  public static function preConfig_auxhome_country($value) {
    $pays = self::normaliserPays($value);
    if ($pays != '') {
      return $pays;
    }
    return self::PAYS_DEFAUT;
  }

  /**
   * Borne l'intervalle de rafraîchissement à l'écriture — délègue à
   * normaliserIntervalle() (même règle qu'à la lecture, cf. intervalleRafraichissement()).
   */
  public static function preConfig_refresh_interval($value) {
    return self::normaliserIntervalle($value);
  }

  /**
   * Normalise la région du compte AUX Cloud legacy avant enregistrement — délègue à
   * normaliserRegion() (même règle qu'à la lecture, cf. regionAuxCloud()). Repli sur
   * REGION_DEFAUT si le résultat n'est pas conforme (même doctrine que
   * preConfig_auxhome_country() : le formulaire n'enregistre jamais une région vide).
   * ⚠️ Aucun throw ici (même motif que preConfig_auxhome_country()) : config.ajax.php
   * boucle sans transaction sur les clés de configuration.
   * ⚠️ Enregistrer une valeur ÉGALE au défaut de l'INI supprime la ligne en base et
   * court-circuite ce hook (piège documenté dans CLAUDE.md) : sans conséquence ici, la
   * lecture appliquant la même normalisation et le même défaut (§ 7.7 de la spec
   * technique).
   */
  public static function preConfig_auxcloud_region($value) {
    $region = self::normaliserRegion($value);
    if ($region != '') {
      return $region;
    }
    return self::REGION_DEFAUT;
  }

  /**
   * Normalise l'e-mail du compte AUX Cloud legacy avant enregistrement — délègue à
   * normaliserEmail(), par symétrie avec le compte AUX Home : la valeur en base doit
   * être propre, car l'empreinte de session est calculée sur l'e-mail normalisé des deux
   * côtés (§ 6.2 de la spec technique). ⚠️ Jamais de throw ici (même motif que
   * preConfig_auxhome_email()).
   */
  public static function preConfig_auxcloud_email($value) {
    return self::normaliserEmail($value);
  }

  /**
   * Purge la session AUX Home en cache dès que le mot de passe change. Ce hook ne
   * reçoit que le CHIFFRÉ ($value inutilisé, cf. spec technique § 0.4 : postConfig_*
   * d'une clé chiffrée ne voit jamais le clair) — mais la seule NOTIFICATION du
   * changement suffit à invalider une session obtenue avec l'ancien mot de passe
   * (AC6) : cache::delete() n'a besoin que de la clé de cache, jamais du secret.
   * Explicitement autorisé par UC01 § 4 : « le hook ne lit rien, il purge ».
   */
  public static function postConfig_auxhome_password($value) {
    smartclimAuxHomeApi::purgerSession();
    // UC08, AC9 : un changement d'identifiants efface aussi l'incident mémorisé (§ 5.2
    // de la spec technique) — sans quoi une erreur affichée avant correction resterait
    // visible jusqu'au cycle suivant.
    self::oublierIncident();
  }

  /**
   * Purge la session AUX Home en cache dès que l'e-mail change (même motif que
   * postConfig_auxhome_password() ci-dessus).
   */
  public static function postConfig_auxhome_email($value) {
    smartclimAuxHomeApi::purgerSession();
    // UC08, AC9 : même motif que postConfig_auxhome_password() ci-dessus.
    self::oublierIncident();
  }

  /**
   * Purge la session AUX Home en cache dès que le pays change (même motif que
   * postConfig_auxhome_password() ci-dessus) : le cloud AUX Home route potentiellement
   * vers un jeu de clés différent par pays.
   */
  public static function postConfig_auxhome_country($value) {
    smartclimAuxHomeApi::purgerSession();
    // UC08, AC9 : même motif que postConfig_auxhome_password() ci-dessus.
    self::oublierIncident();
  }

  /**
   * Purge la session AUX Cloud legacy en cache dès que le mot de passe de ce compte
   * change (même motif que postConfig_auxhome_password() ci-dessus).
   * ⚠️⚠️ NE PAS appeler self::oublierIncident() ici, contrairement aux 3 hooks
   * postConfig_auxhome_* ci-dessus : smartclim::dernier_incident décrit le CYCLE
   * AUTOMATIQUE AUX HOME, pas ce compte legacy — l'effacer ici rendrait faussement
   * "résolu" un incident AUX Home réel, jusqu'au cycle suivant (§ 6.2 de la spec
   * technique, piège le plus coûteux de cette UC, invisible à php -l/CI/relecture
   * rapide).
   */
  public static function postConfig_auxcloud_password($value) {
    smartclimAuxCloudApi::purgerSession();
  }

  /**
   * Purge la session AUX Cloud legacy en cache dès que l'e-mail de ce compte change
   * (même motif que postConfig_auxcloud_password() ci-dessus, y compris le
   * ⚠️ sur oublierIncident()).
   */
  public static function postConfig_auxcloud_email($value) {
    smartclimAuxCloudApi::purgerSession();
  }

  /**
   * Purge la session AUX Cloud legacy en cache dès que la région de ce compte change :
   * une autre région signifie un autre hôte, donc une autre session (même motif que
   * postConfig_auxcloud_password() ci-dessus, y compris le ⚠️ sur oublierIncident()).
   */
  public static function postConfig_auxcloud_region($value) {
    smartclimAuxCloudApi::purgerSession();
  }

  /**
   * Règle de normalisation UNIQUE de l'e-mail du compte AUX Home, appliquée à
   * l'identique à l'écriture (preConfig_auxhome_email()) et à la lecture
   * (emailAuxHome()) : double barrière, une seule implémentation. Retire les
   * caractères de contrôle (CR/LF inclus) AVANT le trim final : trim() seul ne retire
   * pas ces octets (hors \t\n\r\0\x0B), donc les retirer après laisserait un espace de
   * tête résiduel (ex. "\x01 a@b.fr" -> " a@b.fr") qui partirait tel quel dans le champ
   * "account" d'UC02, avec un message backend indistinguable d'un mauvais mot de
   * passe. Même raisonnement pour l'espace insécable U+00A0 (octets C2 A0) : ni le
   * preg_replace ci-dessous ni trim() ne le retirent nativement, donc un e-mail collé
   * depuis un PDF ou une page web mise en forme partirait avec un blanc de tête/queue
   * résiduel ; on le convertit d'abord en espace ordinaire, que le trim final élimine
   * s'il est en bordure. Aucun rejet de format : certains comptes de l'écosystème AUX
   * ne sont pas des e-mails. Tolère toute entrée, y compris non scalaire.
   * ⚠️ Traitement en OCTETS, volontairement SANS modificateur /u sur le preg_replace :
   * /u renvoie NULL sur une entrée UTF-8 invalide, ce qui viderait silencieusement
   * l'e-mail stocké (et déclencherait un trim(null) déprécié en PHP 8.1+).
   *
   * @return string
   */
  private static function normaliserEmail($valeur) {
    $valeur = is_scalar($valeur) ? (string) $valeur : '';
    $valeur = str_replace("\xC2\xA0", ' ', $valeur);
    return trim(preg_replace('/[\x00-\x1F\x7F]/', '', $valeur));
  }

  /**
   * Règle de normalisation UNIQUE du code pays, appliquée à l'identique à l'écriture
   * (preConfig_auxhome_country()) et à la lecture (paysAuxHome()) : double barrière,
   * une seule implémentation. Tolère toute entrée, y compris non scalaire (une valeur
   * non conforme en base ne doit jamais atteindre un en-tête HTTP en clair dès UC02) :
   * trim, majuscules, lettres uniquement, puis exige exactement 3 lettres.
   *
   * @return string Code pays à 3 lettres majuscules, ou '' si non conforme.
   */
  private static function normaliserPays($valeur) {
    $valeur = is_scalar($valeur) ? (string) $valeur : '';
    $pays = preg_replace('/[^A-Z]/', '', strtoupper(trim($valeur)));
    if (strlen($pays) == 3) {
      return $pays;
    }
    return '';
  }

  /**
   * Règle de normalisation UNIQUE de la région du compte AUX Cloud legacy, appliquée à
   * l'identique à l'écriture (preConfig_auxcloud_region()) et à la lecture
   * (regionAuxCloud()) : double barrière, une seule implémentation (§ 6.2 de la spec
   * technique). Tolère toute entrée, y compris non scalaire : trim, majuscules, retrait
   * de tout caractère hors [A-Z], puis exige l'appartenance à la table FERMÉE des
   * régions connues (smartclimAuxCloudApi::regionConnue() — c'est elle, et elle seule,
   * qui fait foi ; aucune regex de forme de région ici).
   * ⚠️ Enveloppée d'un try/catch(Throwable) : elle traverse une AUTRE classe, et une
   * classe absente après une mise à jour partielle est un incident déjà observé sur ce
   * plugin (cf. CLAUDE.md) — un jet dans preConfig_auxcloud_region ferait perdre les
   * clés de configuration suivantes de la même sauvegarde.
   *
   * @return string Code de région connu (ex. "EU"), ou '' si non conforme.
   */
  private static function normaliserRegion($valeur) {
    try {
      $valeur = is_scalar($valeur) ? (string) $valeur : '';
      $code = preg_replace('/[^A-Z]/', '', strtoupper(trim($valeur)));
      if (smartclimAuxCloudApi::regionConnue($code)) {
        return $code;
      }
      return '';
    } catch (Throwable $t) {
      log::add('smartclim', 'error', 'Normalisation de la région AUX Cloud legacy impossible : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
      return '';
    }
  }

  /**
   * Règle de normalisation UNIQUE de l'intervalle de rafraîchissement, appliquée à
   * l'identique à l'écriture (preConfig_refresh_interval()) et à la lecture
   * (intervalleRafraichissement()) : double barrière, une seule implémentation.
   * Une valeur non numérique (ex. "abc") retombe sur le défaut, PAS sur le plancher :
   * sans cette distinction, une saisie invalide interrogerait le cloud jusqu'à 5x plus
   * souvent que le défaut, silencieusement. Un zéro explicite ("0") reste ramené au
   * plancher (AC4) : is_numeric('0') est vrai, seule une valeur réellement non numérique
   * tombe dans la branche défaut.
   *
   * @return int Minutes, entre INTERVALLE_MIN et INTERVALLE_MAX.
   */
  private static function normaliserIntervalle($valeur) {
    $valeur = is_scalar($valeur) ? (string) $valeur : '';
    if ($valeur === '' || !is_numeric($valeur)) {
      return self::INTERVALLE_DEFAUT;
    }
    return min(self::INTERVALLE_MAX, max(self::INTERVALLE_MIN, (int) $valeur));
  }

  /**
   * Règle de normalisation UNIQUE d'une borne de température personnalisée (temp_min
   * ou temp_max), appliquée à l'identique côté serveur (preSave() ci-dessous) et
   * délibérément DUPLIQUÉE côté client (desktop/js/smartclim.js::saveEqLogic(), § "double
   * barrière" de la spec technique UC04) : le serveur reste la barrière AUTORITAIRE,
   * silencieuse — jamais de throw ici (§ Validation de la spec technique — preSave()
   * est aussi traversé par le save() du scan, une exception y transformerait un
   * équipement en erreur récurrente à chaque scan).
   * '' (vide) signifie EXPLICITEMENT « non personnalisé » — et rien d'autre : la
   * détection n'écrit JAMAIS la valeur détectée ici, cela rendrait la personnalisation
   * indiscernable du défaut et gèlerait les bornes contre toute redétection future
   * (cœur d'AC3).
   *
   * @param mixed $_valeur
   * @return string Chaîne canonique ('18', '18.5') ou '' (non personnalisé).
   */
  private static function normaliserBorneTemperature($_valeur) {
    if (!is_scalar($_valeur)) {
      return '';
    }
    $valeur = trim(str_replace(',', '.', (string) $_valeur));
    if ($valeur === '' || !is_numeric($valeur)) {
      return '';
    }
    $enveloppe = smartclimCapabilities::enveloppeBornes();
    $nombre = min($enveloppe['max'], max($enveloppe['min'], (float) $valeur));
    // Chaîne canonique : entier sans décimale ('18'), sinon décimale (par ex. '18.5').
    return (fmod($nombre, 1.0) == 0.0) ? (string) (int) $nombre : (string) $nombre;
  }

  /**
   * Règle de normalisation UNIQUE du pas de température personnalisé (temp_pas) :
   * SEULEMENT 3 valeurs admissibles ('' = valeur détectée, '0.5', '1' — cf.
   * smartclimCapabilities::enveloppeBornes()['pasAutorises']), toute autre valeur
   * devient '' (non personnalisé). Même motif de « double barrière silencieuse » que
   * normaliserBorneTemperature() ci-dessus.
   *
   * @param mixed $_valeur
   * @return string '', '0.5' ou '1'.
   */
  private static function normaliserPasTemperature($_valeur) {
    if (!is_scalar($_valeur)) {
      return '';
    }
    $valeur = (string) $_valeur;
    $enveloppe = smartclimCapabilities::enveloppeBornes();
    return in_array($valeur, $enveloppe['pasAutorises'], true) ? $valeur : '';
  }

  /**
   * Formate un nombre décimal en notation FRANÇAISE (virgule) pour l'affichage
   * (profilAffichable() ci-dessous) : '0.5' -> '0,5' ; '16' -> '16'. N'agit que sur la
   * représentation, jamais sur le stockage (toujours en point décimal, cf. les 2
   * normaliseurs ci-dessus).
   *
   * @param float|int|string $_valeur
   * @return string
   */
  private static function formaterDegre($_valeur) {
    $texte = rtrim(rtrim(sprintf('%.1f', (float) $_valeur), '0'), '.');
    if ($texte === '' || $texte === '-') {
      $texte = '0';
    }
    return str_replace('.', ',', $texte);
  }

  /*
  * Permet de déclencher une action après modification d'une variable de configuration du plugin
  * Exemple avec la variable "param3"
  public static function postConfig_param3($value) {
    // no return value
  }
  */

  /*
   * Permet d'indiquer des éléments supplémentaires à remonter dans les informations de configuration
   * lors de la création semi-automatique d'un post sur le forum community
   public static function getConfigForCommunity() {
      // Cette function doit retourner des infos complémentataires sous la forme d'un
      // string contenant les infos formatées en HTML.
      return "les infos essentiel de mon plugin";
   }
   */

  /*     * *********************Méthodes d'instance************************* */

  // Fonction exécutée automatiquement avant la création de l'équipement
  public function preInsert() {
  }

  // Fonction exécutée automatiquement après la création de l'équipement
  public function postInsert() {
  }

  // Fonction exécutée automatiquement avant la mise à jour de l'équipement
  public function preUpdate() {
  }

  // Fonction exécutée automatiquement après la mise à jour de l'équipement
  public function postUpdate() {
  }

  // Fonction exécutée automatiquement avant la sauvegarde (création ou mise à jour) de l'équipement
  //
  // Normalise les 3 bornes de température PERSONNALISÉES (UC04, § Validation de la
  // spec technique — double barrière, serveur AUTORITAIRE et SILENCIEUX) : traversé
  // aussi bien par l'enregistrement du formulaire d'équipement que par le save() du
  // scan (creerEquipement()/appliquerCapacites() plus haut) — ne DOIT jamais lever, au
  // risque de transformer un équipement à configuration douteuse en erreur récurrente
  // à chaque scan (restauration, écriture SQL directe...).
  public function preSave() {
    $min = self::normaliserBorneTemperature($this->getConfiguration(self::CLE_CONF_TEMP_MIN));
    $max = self::normaliserBorneTemperature($this->getConfiguration(self::CLE_CONF_TEMP_MAX));
    // "min >= max" remet LES DEUX à '' (§ Validation) : une paire incohérente ne doit
    // jamais rester à moitié personnalisée.
    if ($min !== '' && $max !== '' && (float) $min >= (float) $max) {
      log::add('smartclim', 'warning', 'Équipement "' . self::neutraliserPourLog($this->getHumanName()) . '" : bornes de température personnalisées incohérentes (min >= max), réinitialisées');
      $min = '';
      $max = '';
    }
    $this->setConfiguration(self::CLE_CONF_TEMP_MIN, $min);
    $this->setConfiguration(self::CLE_CONF_TEMP_MAX, $max);
    $this->setConfiguration(self::CLE_CONF_TEMP_PAS, self::normaliserPasTemperature($this->getConfiguration(self::CLE_CONF_TEMP_PAS)));

    // UC01 du domaine post-mvp/01-transport-broadlink-lan (§ 4.1 de la spec technique) :
    // barrière AUTORITAIRE et SILENCIEUSE, symétrique de celle des bornes de température
    // ci-dessus. lan_ip invalide/publiquement routable -> '' ; lan_mac non conforme -> ''.
    // Ne lève JAMAIS : ce preSave() est aussi traversé par le save() du scan.
    $lanIpBrute = $this->getConfiguration(self::CLE_CONF_LAN_IP);
    $lanIpNormalisee = self::normaliserIpV4($lanIpBrute);
    if (is_scalar($lanIpBrute) && (string) $lanIpBrute !== '' && $lanIpNormalisee === '') {
      log::add('smartclim', 'warning', 'Équipement "' . self::neutraliserPourLog($this->getHumanName()) . '" : adresse IP locale saisie non exploitable, réinitialisée');
    }
    $this->setConfiguration(self::CLE_CONF_LAN_IP, $lanIpNormalisee);

    $lanMacBrute = $this->getConfiguration(self::CLE_CONF_LAN_MAC);
    $lanMacNormalisee = self::normaliserMac($lanMacBrute);
    if (is_scalar($lanMacBrute) && (string) $lanMacBrute !== '' && $lanMacNormalisee === '') {
      log::add('smartclim', 'warning', 'Équipement "' . self::neutraliserPourLog($this->getHumanName()) . '" : adresse MAC locale saisie non exploitable, réinitialisée');
    }
    $this->setConfiguration(self::CLE_CONF_LAN_MAC, $lanMacNormalisee);

    // UC01 du domaine post-mvp/02-strategies-de-transport (§ 5.4 de sa spec technique) :
    // barrière AUTORITAIRE et SILENCIEUSE, même patron que les bornes/adresses LAN
    // ci-dessus — AC1 (« un équipement fraîchement créé par le scan est en AUTO ») est
    // satisfait PAR CONSTRUCTION, sans toucher creerEquipement().
    $this->setConfiguration(self::CLE_CONF_TRANSPORT_MODE, smartclimTransport::normaliserMode($this->getConfiguration(self::CLE_CONF_TRANSPORT_MODE)));

    // UC03 du domaine post-mvp/03-cloud-aux-legacy (§ 3.5 de sa spec technique) :
    // barrière AUTORITAIRE et SILENCIEUSE, même patron — un booléon d'équipement (0/1),
    // au même endroit que transport_mode ci-dessus.
    $this->setConfiguration(self::CLE_CONF_AUXCLOUD_SWING_INVERSE, $this->getConfiguration(self::CLE_CONF_AUXCLOUD_SWING_INVERSE) ? 1 : 0);
  }

  // Fonction exécutée automatiquement après la sauvegarde (création ou mise à jour) de l'équipement
  //
  // UC05 : garantit l'existence des commandes info (§ « Pourquoi postSave() ET
  // appliquerEtat() appellent tous deux creerCommandesInfo() » de la spec technique —
  // sans ce chemin, un équipement déjà pourvu de son profil UC04 ne redéclencherait
  // jamais save() à un scan identique, donc jamais postSave(), donc aucune commande ne
  // serait créée au déploiement d'UC05). try/catch(Throwable) : postSave() est traversé
  // par le save() du scan, il ne doit jamais transformer un équipement en erreur
  // récurrente.
  public function postSave() {
    try {
      $this->creerCommandesInfo();
      // UC06, § 4.1 : DOIT être appelée ici ET dans appliquerEtat() (garde
      // if (!$_optimiste)) — un équipement déjà scanné et inchangé ne redéclenche ni
      // save() ni postSave() à un scan identique, donc aucune commande action ne
      // serait jamais créée sans ce second point d'appel (même piège que UC05 pour
      // creerCommandesInfo()).
      $this->creerCommandesAction();
    } catch (Throwable $t) {
      log::add('smartclim', 'error', 'Création des commandes info/action impossible après sauvegarde (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '") : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
    }
  }

  /**
   * MAC normalisée de CET équipement (UC01 du domaine post-mvp/01-transport-broadlink-lan,
   * § 5.2 de la spec technique) : `configuration.mac` (posée par creerEquipement() d'UC03
   * du MVP) en priorité, sinon le préfixe `mac:` du logicalId. LECTURE SEULE — cette UC
   * n'écrit JAMAIS `configuration.mac` depuis le LAN (c'est UC04 de ce domaine).
   *
   * @return string 12 caractères hexadécimaux minuscules, ou ''.
   */
  public function macEquipement() {
    $macConfig = self::normaliserMac($this->getConfiguration('mac'));
    if ($macConfig !== '') {
      return $macConfig;
    }
    $logicalId = (string) $this->getLogicalId();
    if (strpos($logicalId, 'mac:') === 0) {
      return self::normaliserMac(substr($logicalId, strlen('mac:')));
    }
    return '';
  }

  /**
   * Mémoire de sonde LAN de CET équipement (UC04 du domaine
   * post-mvp/01-transport-broadlink-lan, § 5.11 de sa spec technique) — cherche dans
   * l'ordre : `lan_mac` SAISIE, `macEquipement()`, puis leurs INVERSES (candidats
   * dédoublonnés, garde palindrome comprise). UNIQUE porteur de la règle "essayer aussi
   * l'ordre inversé" côté équipement, réclamée par le docblock de sondeLanMemorisee()
   * ("c'est aux appelants de le faire") — jusqu'ici dupliquée entre adresseLan() et
   * etatConnexionAffichable().
   *
   * ⚠️ EXTENSION FONCTIONNELLE ADDITIVE, pas une factorisation neutre (§ 5.11) : c'est
   * ce qui détecte enfin l'IP d'un équipement dont l'utilisateur n'a saisi QUE
   * `lan_mac` (AC1 en configuration VLAN). Régression sur le chemin recetté : NULLE —
   * pour tout équipement sans `lan_mac` (100 % du parc actuel), le résultat est
   * identique à une recherche par `macEquipement()` seule.
   *
   * @return array|null
   */
  public function sondeLanEquipement() {
    foreach ($this->macsCandidatesLan() as $mac) {
      $sonde = self::sondeLanMemorisee($mac);
      if (is_array($sonde)) {
        return $sonde;
      }
    }
    return null;
  }

  /**
   * MAC candidates de CET équipement pour un rapprochement LAN (UC03 du domaine
   * post-mvp/02-strategies-de-transport, § 4.5 de sa spec technique) — EXTRACTION
   * STRICTE, à comportement RIGOUREUSEMENT IDENTIQUE, de l'ancien corps de
   * sondeLanEquipement() : `lan_mac` SAISIE puis SON INVERSE, puis `macEquipement()`
   * puis SON INVERSE — ordre INTERFOLÉ, PAS groupé —, dédoublonnés, garde palindrome
   * comprise. Désormais consommée par TROIS
   * lecteurs : la sonde ci-dessus, le rapprochement de redécouverte
   * (appareilRedecouvert()) et la résolution de clé d'écriture
   * (memoriserSondeLanEquipement()) — un seul endroit connaît l'ordre des candidats.
   *
   * @return string[]
   */
  private function macsCandidatesLan() {
    $candidats = array();
    $lanMac = $this->getConfiguration(self::CLE_CONF_LAN_MAC);
    $lanMac = is_string($lanMac) ? self::normaliserMac($lanMac) : '';
    if ($lanMac !== '') {
      $candidats[] = $lanMac;
    }
    $macEquipement = $this->macEquipement();
    if ($macEquipement !== '') {
      $candidats[] = $macEquipement;
    }

    $macsAEssayer = array();
    foreach ($candidats as $mac) {
      if (!in_array($mac, $macsAEssayer, true)) {
        $macsAEssayer[] = $mac;
      }
      $inversee = self::macInversee($mac);
      if ($inversee !== '' && !in_array($inversee, $macsAEssayer, true)) {
        $macsAEssayer[] = $inversee;
      }
    }
    return $macsAEssayer;
  }

  /**
   * Adresse LAN EFFECTIVE de cet équipement (UC01 du domaine
   * post-mvp/01-transport-broadlink-lan, § 5.2 de la spec technique, ENRICHIE en UC04 —
   * § 5.11 de sa spec technique) : règle de lecture UNIQUE, analogue de
   * bornesTemperature() — lan_ip PERSONNALISÉE (revalidée À LA LECTURE, double
   * barrière) en priorité, sinon la sonde retrouvée par sondeLanEquipement() (qui
   * essaie désormais aussi `lan_mac`), sinon ''. lan_mac PERSONNALISÉE en priorité
   * pour la MAC, sinon macEquipement().
   *
   * @return array{ip:string, mac:string, port:int, source:string} source = 'manuel'|'detecte'|'aucun'.
   */
  public function adresseLan() {
    $mac = $this->getConfiguration(self::CLE_CONF_LAN_MAC);
    $mac = is_string($mac) ? self::normaliserMac($mac) : '';
    if ($mac === '') {
      $mac = $this->macEquipement();
    }

    $ipPersonnalisee = self::normaliserIpV4($this->getConfiguration(self::CLE_CONF_LAN_IP));
    if ($ipPersonnalisee !== '') {
      return array(
        'ip' => $ipPersonnalisee,
        'mac' => $mac,
        'port' => smartclimBroadlinkLan::PORT,
        'source' => 'manuel',
      );
    }

    $sonde = $this->sondeLanEquipement();
    if (is_array($sonde) && isset($sonde['ip']) && is_string($sonde['ip']) && $sonde['ip'] !== '') {
      return array(
        'ip' => $sonde['ip'],
        'mac' => $mac,
        'port' => isset($sonde['port']) && is_numeric($sonde['port']) ? (int) $sonde['port'] : smartclimBroadlinkLan::PORT,
        'source' => 'detecte',
      );
    }

    return array(
      'ip' => '',
      'mac' => $mac,
      'port' => smartclimBroadlinkLan::PORT,
      'source' => 'aucun',
    );
  }

  /**
   * Bornes de température EFFECTIVES de cet équipement (UC04, § Validation/Lecture de
   * la spec technique) : valeur personnalisée valide en priorité, puis la valeur du
   * profil détecté, puis les constantes smartclimCapabilities::TEMP_*_DEFAUT. Revalide
   * la valeur personnalisée À LA LECTURE (double barrière) et convertit les chaînes en
   * float à ce SEUL endroit.
   *
   * @return array{min:float,max:float,pas:float,personnalise:bool}
   */
  public function bornesTemperature() {
    $profil = $this->getConfiguration(self::CLE_CONF_CAPACITES);
    if (!is_array($profil)) {
      $profil = self::profilVide();
    }
    $temperatureDetectee = isset($profil['temperature']) && is_array($profil['temperature']) ? $profil['temperature'] : smartclimCapabilities::bornesParDefaut();

    $minPerso = self::normaliserBorneTemperature($this->getConfiguration(self::CLE_CONF_TEMP_MIN));
    $maxPerso = self::normaliserBorneTemperature($this->getConfiguration(self::CLE_CONF_TEMP_MAX));
    $pasPerso = self::normaliserPasTemperature($this->getConfiguration(self::CLE_CONF_TEMP_PAS));
    $personnalise = ($minPerso !== '' || $maxPerso !== '' || $pasPerso !== '');

    $min = ($minPerso !== '') ? (float) $minPerso : (float) (isset($temperatureDetectee['min']) ? $temperatureDetectee['min'] : smartclimCapabilities::TEMP_MIN_DEFAUT);
    $max = ($maxPerso !== '') ? (float) $maxPerso : (float) (isset($temperatureDetectee['max']) ? $temperatureDetectee['max'] : smartclimCapabilities::TEMP_MAX_DEFAUT);
    $pas = ($pasPerso !== '') ? (float) $pasPerso : (float) (isset($temperatureDetectee['pas']) ? $temperatureDetectee['pas'] : smartclimCapabilities::TEMP_PAS_DEFAUT);

    return array(
      'min' => $min,
      'max' => $max,
      'pas' => $pas,
      'personnalise' => $personnalise,
    );
  }

  /**
   * Profil de capacités de cet équipement, PRÊT À L'AFFICHAGE (AC1/AC4 de la spec
   * fonctionnelle UC04) : uniquement des chaînes DÉJÀ traduites, aucun code, aucune
   * donnée d'origine externe. Sur un `capacites` absent ou corrompu (non tableau),
   * repli sur profilVide() (même repli qu'appliquerCapacites(), § "Profil de repli et
   * ordre canonique" de la spec technique).
   *
   * @return array
   */
  public function profilAffichable() {
    $profil = $this->getConfiguration(self::CLE_CONF_CAPACITES);
    if (!is_array($profil)) {
      $profil = self::profilVide();
    }
    $concepts = isset($profil['concepts']) && is_array($profil['concepts']) ? $profil['concepts'] : array();
    $modes = isset($profil['modes']) && is_array($profil['modes']) ? $profil['modes'] : array();
    $vitesses = isset($profil['vitesses']) && is_array($profil['vitesses']) ? $profil['vitesses'] : array();
    $source = isset($profil['source']) && is_string($profil['source']) ? $profil['source'] : '';
    $detecteLe = isset($profil['detecte_le']) && is_numeric($profil['detecte_le']) ? (int) $profil['detecte_le'] : 0;
    $temperatureDetectee = isset($profil['temperature']) && is_array($profil['temperature']) ? $profil['temperature'] : smartclimCapabilities::bornesParDefaut();

    $libellesConcepts = array();
    foreach ($concepts as $concept) {
      $libelle = smartclimCapabilities::libelleConcept($concept);
      if ($libelle !== '') {
        $libellesConcepts[] = $libelle;
      }
    }
    $libellesModes = array();
    foreach ($modes as $mode) {
      $libelle = smartclimCapabilities::libelle(smartclimCapabilities::CONCEPT_MODE, $mode);
      if ($libelle !== '') {
        $libellesModes[] = $libelle;
      }
    }
    $libellesVitesses = array();
    foreach ($vitesses as $vitesse) {
      $libelle = smartclimCapabilities::libelle(smartclimCapabilities::CONCEPT_FAN_SPEED, $vitesse);
      if ($libelle !== '') {
        $libellesVitesses[] = $libelle;
      }
    }

    $bornes = $this->bornesTemperature();
    // Gabarit de plage de température : arguments POSITIONNELS, __() enveloppé AVANT
    // sprintf() (§ Impact i18n de la spec technique).
    $gabaritPlage = __('%1$s °C à %2$s °C, pas de %3$s °C', __FILE__);
    $texteDetecte = sprintf(
      $gabaritPlage,
      self::formaterDegre(isset($temperatureDetectee['min']) ? $temperatureDetectee['min'] : smartclimCapabilities::TEMP_MIN_DEFAUT),
      self::formaterDegre(isset($temperatureDetectee['max']) ? $temperatureDetectee['max'] : smartclimCapabilities::TEMP_MAX_DEFAUT),
      self::formaterDegre(isset($temperatureDetectee['pas']) ? $temperatureDetectee['pas'] : smartclimCapabilities::TEMP_PAS_DEFAUT)
    );
    $texteEffectif = sprintf($gabaritPlage, self::formaterDegre($bornes['min']), self::formaterDegre($bornes['max']), self::formaterDegre($bornes['pas']));
    // Qualificatif de la plage EFFECTIVE affichée (§ Impact i18n de la spec technique
    // UC04) : distingue « Bornes personnalisées » (AC3) de « Valeur par défaut du
    // transport » sans obliger le JS à porter cette logique de libellé.
    $qualificatifTemperature = $bornes['personnalise'] ? __('Bornes personnalisées', __FILE__) : __('Valeur par défaut du transport', __FILE__);

    return array(
      'detecte' => ($source !== '' && $detecteLe !== 0),
      'concepts' => implode(', ', $libellesConcepts),
      'modes' => implode(', ', $libellesModes),
      'vitesses' => implode(', ', $libellesVitesses),
      'temperature' => $texteDetecte,
      'effectives' => $texteEffectif,
      'qualificatifTemperature' => $qualificatifTemperature,
      'personnalise' => $bornes['personnalise'],
      'source' => smartclimCapabilities::libelleTransport($source),
      'detecteLe' => ($detecteLe !== 0) ? date('d/m/Y H:i', $detecteLe) : '',
      'placeholderMin' => self::formaterDegre(isset($temperatureDetectee['min']) ? $temperatureDetectee['min'] : smartclimCapabilities::TEMP_MIN_DEFAUT),
      'placeholderMax' => self::formaterDegre(isset($temperatureDetectee['max']) ? $temperatureDetectee['max'] : smartclimCapabilities::TEMP_MAX_DEFAUT),
      'placeholderPas' => self::formaterDegre(isset($temperatureDetectee['pas']) ? $temperatureDetectee['pas'] : smartclimCapabilities::TEMP_PAS_DEFAUT),
    );
  }

  /**
   * État de connexion de CET équipement, PRÊT À L'AFFICHAGE (UC08, AC8, § 4.3/4.5 de
   * la spec technique) : que des chaînes DÉJÀ traduites, aucun code, aucune donnée
   * externe, aucun identifiant cloud. Table de résolution à ORDRE IMPÉRATIF (premier
   * cas gagnant, § 4.5) : équipement désactivé -> compte non configuré -> en ligne ->
   * incident non-réseau -> incident réseau -> hors ligne -> jamais interrogé.
   *
   * ⚠️ UNE seule lecture getCmd(null, null), indexée par logicalId ET FILTRÉE sur
   * getType() === 'info' (§ 4.4 : execCmd() sur une commande ACTION l'EXÉCUTE — sans ce
   * filtre, ouvrir la page d'un équipement pourrait allumer l'appareil).
   *
   * @return array{niveau:string, etat:string, detail:string, transport:string,
   *               fraicheur:string, derniereDonnee:string, incidentLe:string,
   *               lan:string, lanAdresse:string, modeTransport:string, repli:string}
   */
  public function etatConnexionAffichable() {
    $commandesInfo = array();
    foreach ($this->getCmd(null, null) as $cmdExistante) {
      if ($cmdExistante->getType() === 'info') {
        $commandesInfo[$cmdExistante->getLogicalId()] = $cmdExistante;
      }
    }

    $cmdTransport = isset($commandesInfo[self::CMD_TRANSPORT]) ? $commandesInfo[self::CMD_TRANSPORT] : null;
    $valeurTransport = ($cmdTransport instanceof cmd) ? (string) $cmdTransport->execCmd() : '';
    $transport = ($valeurTransport !== '') ? $valeurTransport : __('Inconnu', __FILE__);

    // UC01 du domaine post-mvp/02-strategies-de-transport (§ 5.4 de sa spec technique) :
    // clé ADDITIVE, calculée UNE fois et reportée dans les 7 branches de retour
    // ci-dessous (⚠️ même piège jQuery .text(undefined) que 'lan'/'lanAdresse').
    $modeTransport = smartclimTransport::libelleMode($this);

    // UC02 du domaine post-mvp/02-strategies-de-transport (§ 8 de sa spec technique,
    // AC7) : clé ADDITIVE 'repli', calculée UNE fois et reportée dans les 7 branches de
    // retour ci-dessous (MÊME piège jQuery .text(undefined)) — chaîne VIDE hors repli,
    // JAMAIS null.
    $repli = smartclimTransport::repliCloudActif($this)
      ? sprintf(__('repli cloud actif — %d échec(s) LAN consécutif(s)', __FILE__), $this->echecsLanConsecutifs())
      : '';

    // UC03 du domaine post-mvp/05-temps-reel-et-demon (§ 5.4/7 de sa spec technique) :
    // clé ADDITIVE 'tempsReel', calculée UNE fois et reportée dans les 7 branches de
    // retour ci-dessous (MÊME piège jQuery .text(undefined) que 'lan'/'repli') — chaîne
    // VIDE si le transport legacy ne s'applique pas / battement absent, JAMAIS null.
    $tempsReel = $this->libelleTempsReelAuxCloud();

    // 'derniereDonnee' = la valeur DÉJÀ FORMATÉE de la commande last_update (aucune
    // migration, § 4.2 de la spec technique) ; 'fraicheur' = l'âge calculé sur SA DATE
    // (getValueDate(), format machine), jamais un re-parsing de la chaîne affichée
    // (strtotime() désambiguïserait un format d/m/Y en m/d/Y).
    $cmdDerniereMaj = isset($commandesInfo[self::CMD_DERNIERE_MAJ]) ? $commandesInfo[self::CMD_DERNIERE_MAJ] : null;
    $valeurDerniereMaj = ($cmdDerniereMaj instanceof cmd) ? (string) $cmdDerniereMaj->execCmd() : '';
    $derniereDonnee = ($valeurDerniereMaj !== '') ? $valeurDerniereMaj : __('Jamais', __FILE__);
    $dateDerniereMaj = ($cmdDerniereMaj instanceof cmd) ? $cmdDerniereMaj->getValueDate() : null;
    $tsDerniereMaj = is_string($dateDerniereMaj) ? strtotime($dateDerniereMaj) : false;
    $fraicheur = ($tsDerniereMaj !== false) ? self::dureeHumaine(max(0, time() - $tsDerniereMaj)) : __('Jamais', __FILE__);

    $cmdOnline = isset($commandesInfo[smartclimCapabilities::CONCEPT_ONLINE]) ? $commandesInfo[smartclimCapabilities::CONCEPT_ONLINE] : null;
    $valeurOnline = ($cmdOnline instanceof cmd) ? $cmdOnline->execCmd() : '';

    // UC01 du domaine post-mvp/01-transport-broadlink-lan (§ 5.2 de la spec technique),
    // ENRICHI en UC04 (§ 5.11 de sa spec technique) : 2 clés ADDITIVES 'lan'/'lanAdresse',
    // calculées UNE fois ici via sondeLanEquipement() (essaie désormais aussi `lan_mac`)
    // et reportées dans CHAQUE branche de retour ci-dessous (⚠️ y compris le repli
    // catch(Throwable) de etatsConnexionAffichables() — piège jQuery .text(undefined)
    // documenté § 5.2).
    $sondeLan = $this->sondeLanEquipement();
    $lan = self::libelleStatutLan(is_array($sondeLan) && isset($sondeLan['statut']) && is_string($sondeLan['statut']) ? $sondeLan['statut'] : '');
    $lanAdresse = '';
    if (is_array($sondeLan)) {
      $ipLan = isset($sondeLan['ip']) && is_string($sondeLan['ip']) ? $sondeLan['ip'] : '';
      $tsLan = 0;
      if (isset($sondeLan['vu_le']) && is_numeric($sondeLan['vu_le']) && (int) $sondeLan['vu_le'] > 0) {
        $tsLan = (int) $sondeLan['vu_le'];
      } elseif (isset($sondeLan['echec_le']) && is_numeric($sondeLan['echec_le']) && (int) $sondeLan['echec_le'] > 0) {
        $tsLan = (int) $sondeLan['echec_le'];
      }
      if ($ipLan !== '' && $tsLan > 0) {
        $lanAdresse = $ipLan . ' (' . self::dureeHumaine(max(0, time() - $tsLan)) . ')';
      } elseif ($ipLan !== '') {
        $lanAdresse = $ipLan;
      }
    }

    if ($this->getIsEnable() == 0) {
      return array(
        'niveau' => 'neutre',
        'etat' => __('Équipement désactivé', __FILE__),
        'detail' => '',
        'transport' => $transport,
        'fraicheur' => $fraicheur,
        'derniereDonnee' => $derniereDonnee,
        'incidentLe' => '',
        'lan' => $lan,
        'lanAdresse' => $lanAdresse,
        'modeTransport' => $modeTransport,
        'repli' => $repli,
        'tempsReel' => $tempsReel,
      );
    }

    // UC01 du domaine post-mvp/02-strategies-de-transport (§ 5.4 de sa spec technique,
    // AC2/AC3) : cette branche n'est prise QUE si la lecture cloud est autorisée pour CET
    // équipement — sans cette garde, un équipement LOCAL sur un Jeedom sans compte
    // AUX Home afficherait « Compte non configuré », message d'erreur pour un
    // fonctionnement NOMINAL, exactement ce que la spec proscrit.
    if (smartclimTransport::lectureCloudAutorisee($this) && !self::compteConfigure()) {
      return array(
        'niveau' => 'danger',
        'etat' => __('Compte AUX Home non configuré : renseignez l\'e-mail et le mot de passe', __FILE__),
        'detail' => '',
        'transport' => $transport,
        'fraicheur' => $fraicheur,
        'derniereDonnee' => $derniereDonnee,
        'incidentLe' => '',
        'lan' => $lan,
        'lanAdresse' => $lanAdresse,
        'modeTransport' => $modeTransport,
        'repli' => $repli,
        'tempsReel' => $tempsReel,
      );
    }

    $online = (is_string($valeurOnline) || is_numeric($valeurOnline)) && (string) $valeurOnline !== '' ? (int) $valeurOnline === 1 : null;

    if ($online === true) {
      return array(
        'niveau' => 'ok',
        'etat' => self::libelleEnLigne(true),
        'detail' => '',
        'transport' => $transport,
        'fraicheur' => $fraicheur,
        'derniereDonnee' => $derniereDonnee,
        'incidentLe' => '',
        'lan' => $lan,
        'lanAdresse' => $lanAdresse,
        'modeTransport' => $modeTransport,
        'repli' => $repli,
        'tempsReel' => $tempsReel,
      );
    }

    // UC01 du domaine post-mvp/02-strategies-de-transport (§ 5.4 de sa spec technique,
    // AC2/AC3) : incident de connexion du COMPTE CLOUD, non consulté pour un équipement
    // LOCAL — même motif que la garde ci-dessus.
    $incident = smartclimTransport::lectureCloudAutorisee($this) ? self::incidentMemorise() : null;
    if (is_array($incident)) {
      $incidentLe = date('d/m/Y H:i:s', $incident['ts']);
      if ($incident['type'] !== smartclimException::TYPE_RESEAU) {
        return array(
          'niveau' => 'danger',
          'etat' => __('Erreur de connexion', __FILE__),
          'detail' => self::messageErreurAuxHome($incident['type'], $incident['contexte']),
          'transport' => $transport,
          'fraicheur' => $fraicheur,
          'derniereDonnee' => $derniereDonnee,
          'incidentLe' => $incidentLe,
          'lan' => $lan,
          'lanAdresse' => $lanAdresse,
          'modeTransport' => $modeTransport,
          'repli' => $repli,
          'tempsReel' => $tempsReel,
        );
      }
      return array(
        'niveau' => 'warning',
        'etat' => self::libelleEnLigne(false),
        'detail' => self::messageErreurAuxHome(smartclimException::TYPE_RESEAU, ''),
        'transport' => $transport,
        'fraicheur' => $fraicheur,
        'derniereDonnee' => $derniereDonnee,
        'incidentLe' => $incidentLe,
        'lan' => $lan,
        'lanAdresse' => $lanAdresse,
        'modeTransport' => $modeTransport,
        'repli' => $repli,
        'tempsReel' => $tempsReel,
      );
    }

    if ($online === false) {
      return array(
        'niveau' => 'warning',
        'etat' => self::libelleEnLigne(false),
        'detail' => '',
        'transport' => $transport,
        'fraicheur' => $fraicheur,
        'derniereDonnee' => $derniereDonnee,
        'incidentLe' => '',
        'lan' => $lan,
        'lanAdresse' => $lanAdresse,
        'modeTransport' => $modeTransport,
        'repli' => $repli,
        'tempsReel' => $tempsReel,
      );
    }

    // $online === null : la commande 'online' n'a encore jamais été poussée (équipement
    // jamais interrogé par un cycle/scan).
    return array(
      'niveau' => 'warning',
      'etat' => __('État inconnu', __FILE__),
      'detail' => __('Aucun cycle de rafraîchissement n\'a encore eu lieu', __FILE__),
      'transport' => $transport,
      'fraicheur' => $fraicheur,
      'derniereDonnee' => $derniereDonnee,
      'incidentLe' => '',
      'lan' => $lan,
      'lanAdresse' => $lanAdresse,
      'modeTransport' => $modeTransport,
      'repli' => $repli,
      'tempsReel' => $tempsReel,
    );
  }

  /**
   * Durée écoulée en français, prête à l'affichage (UC08, § 4.3 de la spec technique).
   * __() enveloppé AVANT sprintf(), arguments POSITIONNELS dès qu'il y en a plusieurs
   * (§ Impact i18n).
   *
   * @param int $_secondes
   * @return string
   */
  private static function dureeHumaine($_secondes) {
    $secondes = max(0, (int) $_secondes);
    if ($secondes < 60) {
      return __('à l\'instant', __FILE__);
    }
    $minutes = (int) floor($secondes / 60);
    if ($minutes < 60) {
      return sprintf(__('il y a %d min', __FILE__), $minutes);
    }
    $heures = (int) floor($minutes / 60);
    $minutesRestantes = $minutes % 60;
    if ($heures < 24) {
      return sprintf(__('il y a %1$d h %2$d min', __FILE__), $heures, $minutesRestantes);
    }
    $jours = (int) floor($heures / 24);
    return sprintf(__('il y a %d jour(s)', __FILE__), $jours);
  }

  /**
   * Fusionne un profil de capacités DÉTECTÉ (smartclimAuxHomeApi::capacitesAppareil())
   * avec le profil déjà STOCKÉ de cet équipement, par UNION canonique (§ "Profil de
   * repli et ordre canonique" de la spec technique UC04 — un profil ne s'ampute
   * JAMAIS : un scan pendant que le climatiseur est hors ligne ne peut pas faire
   * disparaître des capacités déjà connues). N'appelle JAMAIS save() elle-même :
   * l'appelant (scannerAuxHome()/creerEquipement()) décide du save() unique.
   *
   * @param array $_detecte Renvoyé par smartclimAuxHomeApi::capacitesAppareil().
   * @return bool true si le profil stocké a changé (l'appelant doit alors save()).
   */
  private function appliquerCapacites(array $_detecte) {
    $actuel = $this->getConfiguration(self::CLE_CONF_CAPACITES);
    if (!is_array($actuel)) {
      $actuel = self::profilVide();
    }
    $conceptsActuels = isset($actuel['concepts']) && is_array($actuel['concepts']) ? $actuel['concepts'] : array();
    $modesActuels = isset($actuel['modes']) && is_array($actuel['modes']) ? $actuel['modes'] : array();
    $vitessesActuelles = isset($actuel['vitesses']) && is_array($actuel['vitesses']) ? $actuel['vitesses'] : array();

    $conceptsDetectes = isset($_detecte['concepts']) && is_array($_detecte['concepts']) ? $_detecte['concepts'] : array();
    $modesDetectes = isset($_detecte['modes']) && is_array($_detecte['modes']) ? $_detecte['modes'] : array();
    $vitessesDetectees = isset($_detecte['vitesses']) && is_array($_detecte['vitesses']) ? $_detecte['vitesses'] : array();

    // UC03 du domaine post-mvp/03-cloud-aux-legacy (§ 3.1/D1 de sa spec technique) :
    // neutralise le CATALOGUE publié par un transport qui ne sait RIEN exclure — colonne
    // DÉCLARATIVE 'catalogue_par_defaut' (JAMAIS un test 'source === TRANSPORT_AUX_CLOUD_LEGACY'
    // en dur, cf. le docblock de smartclimAuxCloudApi::capacitesAppareil()) — DÈS QUE cet
    // équipement est AUSSI connu d'AUX Home (auxhome_device_id non vide) : sinon l'union
    // ci-dessous réintroduirait un mode qu'AUX Home vient de PROUVER absent, exactement
    // la régression du 2026-08-26. Un équipement legacy PUR (sans compte AUX Home) garde
    // le catalogue admis (AC1). ⚠️ Seuls 'modes'/'vitesses' sont neutralisés : 'concepts'
    // reste unionné SANS CONDITION (une preuve de présence de clé, pas un catalogue).
    // ⚠️ 'catalogue_par_defaut' n'est PAS recopiée dans $fusion ci-dessous (construit clé
    // par clé) : elle n'entre donc ni dans le profil stocké ni dans la comparaison
    // json_encode — aucun save() de migration sur le parc existant.
    if (!empty($_detecte['catalogue_par_defaut']) && $this->getConfiguration('auxhome_device_id') !== '') {
      $modesDetectes = array();
      $vitessesDetectees = array();
    }

    // SEULE chose qui puisse AMPUTER un profil, et la seule exception à l'union ci-dessous
    // (« un profil ne s'ampute jamais »). L'exception est légitime parce que ce n'est pas
    // une absence de détection mais une PREUVE fournie par l'appareil lui-même : sans
    // elle, un HEAT stocké avant que la restriction n'existe survivrait indéfiniment à sa
    // correction. Vide dès que le transport n'a rien pu établir -> union pure.
    $modesExclus = isset($_detecte['modes_exclus']) && is_array($_detecte['modes_exclus']) ? $_detecte['modes_exclus'] : array();

    $fusion = array(
      'version' => self::VERSION_PROFIL,
      'concepts' => self::ordonnerParReference(array_values(array_unique(array_merge($conceptsActuels, $conceptsDetectes))), smartclimCapabilities::conceptsConnus()),
      'modes' => self::ordonnerParReference(array_diff(array_values(array_unique(array_merge($modesActuels, $modesDetectes))), $modesExclus), smartclimCapabilities::valeursLisibles(smartclimCapabilities::TRANSPORT_AUX_HOME, smartclimCapabilities::CONCEPT_MODE)),
      'vitesses' => self::ordonnerParReference(array_values(array_unique(array_merge($vitessesActuelles, $vitessesDetectees))), smartclimCapabilities::valeursLisibles(smartclimCapabilities::TRANSPORT_AUX_HOME, smartclimCapabilities::CONCEPT_FAN_SPEED)),
      // Défaut du transport, JAMAIS les bornes personnalisées (espaces de nommage
      // disjoints, cf. CLE_CONF_TEMP_* — cœur d'AC3).
      'temperature' => isset($_detecte['temperature']) && is_array($_detecte['temperature']) ? $_detecte['temperature'] : smartclimCapabilities::bornesParDefaut(),
      'source' => isset($_detecte['source']) && is_string($_detecte['source']) ? $_detecte['source'] : '',
    );

    // Comparaison HORS 'detecte_le' (§ "Profil de repli et ordre canonique") : c'est ce
    // qui garantit l'invariant UC03 « un scan strictement identique n'émet aucun save() ».
    $actuelSansDate = $actuel;
    unset($actuelSansDate['detecte_le']);
    if (json_encode($fusion) === json_encode($actuelSansDate)) {
      return false;
    }

    $fusion['detecte_le'] = time();
    $this->setConfiguration(self::CLE_CONF_CAPACITES, $fusion);

    // Un mode qui QUITTE le profil laisse derrière lui les commandes déjà créées pour lui
    // (CLAUDE.md : « une capacité qui disparaît ne supprime jamais une commande »). La
    // règle est conservée — rien n'est supprimé — mais laisser un bouton « Chauffage »
    // visible sur un appareil dont on vient d'établir qu'il ne chauffe pas serait rendre
    // la correction invisible là où l'utilisateur l'a signalée. Les commandes concernées
    // sont donc MASQUÉES, une seule fois, au moment de la transition.
    $modesPartis = array_values(array_diff($modesActuels, $fusion['modes']));
    if (!empty($modesPartis)) {
      $this->masquerCommandesModes($modesPartis);
    }
    return true;
  }

  /**
   * Masque (isVisible = 0, JAMAIS de suppression) les commandes d'action des modes passés
   * en paramètre. Appelée UNIQUEMENT à la transition, depuis appliquerCapacites() : un
   * masquage rejoué à chaque scan écraserait le choix d'un utilisateur qui aurait
   * délibérément réaffiché la commande.
   *
   * Ne lève jamais et ne présume pas de l'existence des commandes : sur un équipement
   * pas encore enregistré (chemin creerEquipement -> appliquerCapacites avant le premier
   * save()), getId() est vide et il n'y a rien à masquer.
   *
   * @param array<int,string> $_modes Codes génériques de mode (smartclimCapabilities::MODE_*).
   * @return int Nombre de commandes masquées.
   */
  private function masquerCommandesModes(array $_modes) {
    if ($this->getId() == '') {
      return 0;
    }
    $cibles = array();
    foreach ($_modes as $mode) {
      $cibles[self::PREFIXE_CMD_MODE . strtolower($mode)] = true;
    }

    $masquees = 0;
    foreach ($this->getCmd(null, null) as $cmd) {
      if (!isset($cibles[$cmd->getLogicalId()]) || !$cmd->getIsVisible()) {
        continue;
      }
      try {
        $cmd->setIsVisible(0);
        $cmd->save();
        $masquees++;
        log::add('smartclim', 'info', 'Commande masquée (mode non supporté par l\'appareil) : ' . $cmd->getLogicalId() . ' sur ' . self::neutraliserPourLog($this->getHumanName()));
      } catch (Throwable $t) {
        log::add('smartclim', 'warning', 'Masquage impossible pour ' . $cmd->getLogicalId() . ' : ' . self::neutraliserPourLog($t->getMessage()));
      }
    }
    return $masquees;
  }

  /**
   * Réordonne un ensemble de valeurs selon un ordre de RÉFÉRENCE (ex.
   * smartclimCapabilities::conceptsConnus()) : les valeurs présentes dans l'ordre de
   * référence viennent D'ABORD, dans CET ordre ; toute valeur de $_ensemble ABSENTE de
   * l'ordre de référence (profil écrit par une version antérieure, valeur retirée de la
   * table depuis) est conservée EN FIN de liste, dans son ordre d'apparition — l'union
   * ne retire JAMAIS rien, y compris ce qu'elle ne sait plus ordonner (§ "ordre
   * canonique" de la spec technique UC04 : sans ce réordonnancement, 2 ensembles égaux
   * mais ordonnés différemment compareraient "différents" et réécriraient à chaque scan).
   *
   * @param array<int,string> $_ensemble
   * @param array<int,string> $_ordreReference
   * @return array<int,string>
   */
  private static function ordonnerParReference(array $_ensemble, array $_ordreReference) {
    $ordonne = array();
    foreach ($_ordreReference as $valeur) {
      if (in_array($valeur, $_ensemble, true)) {
        $ordonne[] = $valeur;
      }
    }
    foreach ($_ensemble as $valeur) {
      if (!in_array($valeur, $ordonne, true)) {
        $ordonne[] = $valeur;
      }
    }
    return $ordonne;
  }

  /**
   * Crée les commandes info MANQUANTES depuis configuration.capacites['concepts']
   * (UC05, § Signatures de la spec technique). IDEMPOTENTE et NON DESTRUCTIVE : une
   * commande existante n'est jamais relue, jamais modifiée, jamais supprimée
   * (AC7/AC9) — ses propriétés ne sont posées QU'À LA CRÉATION. try/catch PAR COMMANDE :
   * ne lève JAMAIS.
   *
   * GATING, à lire précisément : la condition « profil absent ou sans concept » ne
   * s'applique QU'AUX 6 COMMANDES DE CONCEPT. Les 2 commandes MÉTA ('transport',
   * 'last_update', marquées 'meta' => true) sont créées dans TOUS les cas, y compris sur
   * un équipement sans profil de capacités.
   *
   * COÛT : les commandes déjà présentes sont lues en UN SEUL appel getCmd(null, null)
   * (indexé par logicalId), pas par un cmd::byEqLogicIdAndLogicalId() par concept —
   * cette méthode est appelée à chaque cycle de scan (postSave()/appliquerEtat()) puis à
   * chaque cycle cron (UC07), pour chaque équipement.
   *
   * @return int Nombre de commandes créées.
   */
  private function creerCommandesInfo() {
    $profil = $this->getConfiguration(self::CLE_CONF_CAPACITES);
    $concepts = (is_array($profil) && isset($profil['concepts']) && is_array($profil['concepts'])) ? $profil['concepts'] : array();

    $existantes = array();
    foreach ($this->getCmd(null, null) as $cmdExistante) {
      $existantes[$cmdExistante->getLogicalId()] = $cmdExistante;
    }

    // UC02 du domaine post-mvp/04-fonctions-avancees (§ 5.5.3 de sa spec technique) :
    // réalignement CIBLÉ du nom des commandes info d'oscillation déjà existantes. Le nom
    // n'est posé qu'À LA CRÉATION (boucle ci-dessous, `continue` si déjà existante) ; sans
    // ce correctif, un appareil où AC4 finit par être satisfait ('lecture' bascule à
    // true APRÈS que la commande a été créée avec 'confirme' => true) afficherait
    // INDÉFINIMENT « (état commandé) » alors que la valeur est réellement relue. Ne
    // renomme QUE si le nom courant est EXACTEMENT l'autre variante (nu <-> suffixé) —
    // jamais un nom personnalisé par l'utilisateur — et n'émet un save() QUE si le nom
    // change effectivement. Précédent identique : realignerBornesConsigne() (§ 8.3).
    foreach (smartclimCapabilities::conceptsOscillation() as $concept) {
      if (!isset($existantes[$concept])) {
        continue;
      }
      $fonction = smartclimCapabilities::fonctionOscillation($concept);
      if (empty($fonction) || $fonction['libelle'] === '') {
        continue;
      }
      $nomAttendu = smartclimCapabilities::libelleCommande($concept);
      // Le nom courant doit être EXACTEMENT l'AUTRE variante de $nomAttendu : jamais un
      // nom personnalisé par l'utilisateur, jamais un renommage si le nom est déjà juste.
      // Aucun __() ICI (§ 7 de la spec technique) : la contrepartie est calculée par
      // smartclimCapabilities, seul endroit qui porte la chaîne '%s (état commandé)'.
      $autreVariante = smartclimCapabilities::libelleCommandeAutreVariante($concept);
      $nomCourant = $existantes[$concept]->getName();
      if ($nomCourant === $autreVariante && $nomCourant !== $nomAttendu) {
        try {
          $existantes[$concept]->setName($nomAttendu);
          $existantes[$concept]->save();
        } catch (Throwable $t) {
          log::add('smartclim', 'error', 'Réalignement du nom de "' . $concept . '" impossible (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '") : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
        }
      }
    }

    $crees = 0;
    foreach (self::definitionsCommandesInfo() as $logicalId => $definition) {
      if (isset($existantes[$logicalId])) {
        continue;
      }
      if (!$definition['meta'] && !in_array($logicalId, $concepts, true)) {
        continue;
      }
      if ($definition['name'] === '') {
        // libelleCommande() renvoie '' pour un concept inconnu : cmd::save() lèverait
        // sur un name vide, on ne crée alors AUCUNE commande (spec technique § Validation).
        continue;
      }
      try {
        $cmd = new smartclimCmd();
        $cmd->setEqLogic_id($this->getId());
        $cmd->setLogicalId($logicalId);
        $cmd->setName($definition['name']);
        $cmd->setType('info');
        $cmd->setSubType($definition['subType']);
        if ($definition['unite'] !== '') {
          $cmd->setUnite($definition['unite']);
        }
        if ($definition['generic_type'] !== '') {
          $cmd->setGeneric_type($definition['generic_type']);
        }
        $cmd->setIsVisible(1);
        $cmd->setIsHistorized($definition['isHistorized']);
        $cmd->setOrder($definition['ordre']);
        // Volontairement AUCUN minValue/maxValue posé sur les commandes numériques (spec
        // technique § Validation) : cmd::event() jette silencieusement une valeur hors
        // bornes, des bornes personnalisées feraient disparaître sans un mot une lecture
        // réelle hors plage. Les bornes appartiennent à la commande action d'UC06.
        $cmd->save();
        $crees++;
      } catch (Throwable $t) {
        log::add('smartclim', 'error', 'Création de la commande info "' . $logicalId . '" impossible (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '") : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
      }
    }
    return $crees;
  }

  /**
   * Définition Jeedom des commandes ACTION de CET équipement (UC06, § 5.3/6 de la
   * spec technique). MÉTHODE D'INSTANCE (contrairement à definitionsCommandesInfo()) :
   * les entrées mode_ / fan_ sont DÉRIVÉES du profil de capacités, jamais d'un
   * catalogue de modèles. Une valeur dont versTransport() renvoie null est ABSENTE de
   * la liste (AC6). La colonne intent_confirme n'est JAMAIS lue (D-MVP04-02).
   *
   * @return array<string, array{name:string, subType:string, infoLiee:string, ordre:array, ordreCmd:int}>
   */
  private function definitionsCommandesAction() {
    $profil = $this->getConfiguration(self::CLE_CONF_CAPACITES);
    $concepts = (is_array($profil) && isset($profil['concepts']) && is_array($profil['concepts'])) ? $profil['concepts'] : array();
    $modes = (is_array($profil) && isset($profil['modes']) && is_array($profil['modes'])) ? $profil['modes'] : array();
    $vitesses = (is_array($profil) && isset($profil['vitesses']) && is_array($profil['vitesses'])) ? $profil['vitesses'] : array();

    $definitions = array();

    if (in_array(smartclimCapabilities::CONCEPT_POWER, $concepts, true)) {
      $definitions[self::CMD_ON] = array(
        'name' => __('Marche', __FILE__),
        'subType' => 'other',
        'infoLiee' => smartclimCapabilities::CONCEPT_POWER,
        'ordre' => array(smartclimCapabilities::CONCEPT_POWER => 1),
        'ordreCmd' => 10,
      );
      $definitions[self::CMD_OFF] = array(
        'name' => __('Arrêt', __FILE__),
        'subType' => 'other',
        'infoLiee' => smartclimCapabilities::CONCEPT_POWER,
        'ordre' => array(smartclimCapabilities::CONCEPT_POWER => 0),
        'ordreCmd' => 11,
      );
    }

    if (in_array(smartclimCapabilities::CONCEPT_TARGET_TEMP, $concepts, true)) {
      // 'ordre' non renseigné ici : construit dynamiquement par ordreEffectifConsigne()
      // au moment de l'exécution (§ 5.3 de la spec technique).
      $definitions[self::CMD_CONSIGNE] = array(
        'name' => __('Régler la consigne', __FILE__),
        'subType' => 'slider',
        'infoLiee' => smartclimCapabilities::CONCEPT_TARGET_TEMP,
        'ordre' => array(),
        'ordreCmd' => 12,
      );
    }

    $ordreCmd = 13;
    if (in_array(smartclimCapabilities::CONCEPT_MODE, $concepts, true)) {
      foreach ($modes as $mode) {
        if (smartclimCapabilities::versTransport(smartclimCapabilities::TRANSPORT_AUX_HOME, smartclimCapabilities::CONCEPT_MODE, $mode) === null) {
          continue;
        }
        $libelle = smartclimCapabilities::libelle(smartclimCapabilities::CONCEPT_MODE, $mode);
        if ($libelle === '') {
          continue;
        }
        $logicalId = self::PREFIXE_CMD_MODE . strtolower($mode);
        $definitions[$logicalId] = array(
          'name' => sprintf(__('Mode %s', __FILE__), $libelle),
          'subType' => 'other',
          'infoLiee' => smartclimCapabilities::CONCEPT_MODE,
          'ordre' => array(smartclimCapabilities::CONCEPT_POWER => 1, smartclimCapabilities::CONCEPT_MODE => $mode),
          'ordreCmd' => $ordreCmd++,
        );
      }
    }

    if (in_array(smartclimCapabilities::CONCEPT_FAN_SPEED, $concepts, true)) {
      foreach ($vitesses as $vitesse) {
        if (smartclimCapabilities::versTransport(smartclimCapabilities::TRANSPORT_AUX_HOME, smartclimCapabilities::CONCEPT_FAN_SPEED, $vitesse) === null) {
          continue;
        }
        $libelle = smartclimCapabilities::libelle(smartclimCapabilities::CONCEPT_FAN_SPEED, $vitesse);
        if ($libelle === '') {
          continue;
        }
        $logicalId = self::PREFIXE_CMD_VITESSE . strtolower($vitesse);
        $definitions[$logicalId] = array(
          'name' => sprintf(__('Vitesse %s', __FILE__), $libelle),
          'subType' => 'other',
          'infoLiee' => smartclimCapabilities::CONCEPT_FAN_SPEED,
          // Pas d'allumage implicite sur un simple réglage de ventilation (§ 3.3 de la
          // spec technique) : AC2 ne le demande que pour le mode et la consigne.
          'ordre' => array(smartclimCapabilities::CONCEPT_FAN_SPEED => $vitesse),
          'ordreCmd' => $ordreCmd++,
        );
      }
    }

    // UC01 du domaine post-mvp/04-fonctions-avancees (§ 5.4 de sa spec technique) :
    // deux commandes par fonction de confort LIVRÉE (conceptsConfortLivres(), § 11 de
    // sa spec technique — vide tant qu'aucune fonction n'a été validée en recette, AC7)
    // ET détectée sur CET appareil (concept présent dans le profil, comme mode_/fan_
    // ci-dessus). 'ordre' est une map STATIQUE, jamais dérivée de $_options (§ 4 : rien
    // à valider côté client pour ces commandes).
    foreach (smartclimCapabilities::conceptsConfortLivres() as $concept) {
      if (!in_array($concept, $concepts, true)) {
        continue;
      }
      $fonction = smartclimCapabilities::fonctionConfort($concept);
      if (empty($fonction) || $fonction['libelle'] === '') {
        continue;
      }
      $ordreOn = array($concept => 1);
      if (!empty($fonction['allumer'])) {
        // sleep/health EXIGENT l'appareil allumé côté backend (§ 2.1) — même forme
        // que mode_* ci-dessus : deux clés dans le même intent.
        $ordreOn[smartclimCapabilities::CONCEPT_POWER] = 1;
      }
      // ⚠️ clean/mildew sont des fonctions de l'état ARRÊT côté backend AUX Home
      // (§ 2.1 de la spec technique) : leur ordre ON ne porte JAMAIS power => 1 —
      // dérogation EXPLICITE à la règle générale de CLAUDE.md (scopée « mode ou
      // consigne »), au même titre que fan_* ci-dessus qui n'allume jamais
      // implicitement. NE PAS « corriger » en ajoutant power => 1 ici : le backend
      // masque/force clean=0 dès que l'appareil s'allume, ce qui éteindrait
      // silencieusement la fonction qu'on vient d'activer.
      $definitions[$concept . self::SUFFIXE_CMD_ON] = array(
        'name' => sprintf(__('%s - Activer', __FILE__), $fonction['libelle']),
        'subType' => 'other',
        'infoLiee' => $concept,
        'ordre' => $ordreOn,
        'ordreCmd' => $fonction['ordre'],
      );
      $definitions[$concept . self::SUFFIXE_CMD_OFF] = array(
        'name' => sprintf(__('%s - Désactiver', __FILE__), $fonction['libelle']),
        'subType' => 'other',
        'infoLiee' => $concept,
        // Jamais de power ici : désactiver une fonction de confort n'éteint jamais
        // l'appareil (contrat identique pour les 5 fonctions).
        'ordre' => array($concept => 0),
        'ordreCmd' => $fonction['ordre'] + 1,
      );
    }

    // UC02 du domaine post-mvp/04-fonctions-avancees (§ 5.5.2 de sa spec technique) :
    // deux commandes par concept d'oscillation LIVRÉ (conceptsOscillationLivres()) ET
    // détecté sur CET appareil — STRICTEMENT calqué sur le bloc confort ci-dessus.
    // Gabarits '%s - Activer'/'%s - Désactiver' déjà déclarés (UC01) : AUCUNE clé i18n
    // nouvelle ici. L'ordre ON porte TOUJOURS power => 1 (§ 2.2 de la spec technique :
    // les deux axes exigent l'appareil allumé) ; l'ordre OFF n'en porte JAMAIS (§ 2.2 :
    // désactiver une fonction ne doit pas allumer l'appareil).
    foreach (smartclimCapabilities::conceptsOscillationLivres() as $concept) {
      if (!in_array($concept, $concepts, true)) {
        continue;
      }
      $fonction = smartclimCapabilities::fonctionOscillation($concept);
      if (empty($fonction) || $fonction['libelle'] === '') {
        continue;
      }
      $definitions[$concept . self::SUFFIXE_CMD_ON] = array(
        'name' => sprintf(__('%s - Activer', __FILE__), $fonction['libelle']),
        'subType' => 'other',
        'infoLiee' => $concept,
        'ordre' => array($concept => 1, smartclimCapabilities::CONCEPT_POWER => 1),
        'ordreCmd' => $fonction['ordre'],
      );
      $definitions[$concept . self::SUFFIXE_CMD_OFF] = array(
        'name' => sprintf(__('%s - Désactiver', __FILE__), $fonction['libelle']),
        'subType' => 'other',
        'infoLiee' => $concept,
        // Jamais de power ici : désactiver une oscillation n'éteint jamais l'appareil.
        'ordre' => array($concept => 0),
        'ordreCmd' => $fonction['ordre'] + 1,
      );
    }

    // UC03 du domaine post-mvp/04-fonctions-avancees (§ 5.3 de sa spec technique) :
    // bloc protection — deux commandes par concept de protection LIVRÉ
    // (conceptsProtectionLivres()) ET détecté sur CET appareil. ⚠️ Calqué sur DEUX blocs
    // différents, ne pas chercher le tout dans un seul : la structure de scan et les
    // ordreCmd viennent du bloc oscillation ci-dessus, mais la construction de l'ordre ON
    // vient du bloc CONFORT — seules fonctionsConfort() et fonctionsProtection() portent
    // une colonne 'allumer', là où le bloc oscillation force power => 1 sans condition.
    // Seul ajout de mécanique du cycle : la colonne 'confirmation' sur
    // l'ordre ON, consommée UNIQUEMENT par creerCommandesAction() ci-dessous, à la
    // création (§ 5.3.1 de la spec technique) — un execCmd() forgé ou scénarisé n'est
    // PAS bloqué par ce drapeau, ce n'est qu'un anti-fausse-manip côté IHM (dialogue
    // natif du core, cf. core/ajax/cmd.ajax.php).
    foreach (smartclimCapabilities::conceptsProtectionLivres() as $concept) {
      if (!in_array($concept, $concepts, true)) {
        continue;
      }
      $fonction = smartclimCapabilities::fonctionProtection($concept);
      if (empty($fonction) || $fonction['libelle'] === '') {
        continue;
      }
      $ordreOn = array($concept => 1);
      if (!empty($fonction['allumer'])) {
        $ordreOn[smartclimCapabilities::CONCEPT_POWER] = 1;
      }
      $definitions[$concept . self::SUFFIXE_CMD_ON] = array(
        'name' => sprintf(__('%s - Activer', __FILE__), $fonction['libelle']),
        'subType' => 'other',
        'infoLiee' => $concept,
        'ordre' => $ordreOn,
        'ordreCmd' => $fonction['ordre'],
        // ⚠️ Risque R1 de la spec technique : si le backend applique toastMutex,
        // activer la sécurité enfant depuis Jeedom rend tout le reste du plugin
        // inopérant. Ce drapeau (dialogue de confirmation natif) est la mitigation
        // retenue — pas une garde de sécurité, un anti-fausse-manip.
        'confirmation' => true,
      );
      $definitions[$concept . self::SUFFIXE_CMD_OFF] = array(
        'name' => sprintf(__('%s - Désactiver', __FILE__), $fonction['libelle']),
        'subType' => 'other',
        'infoLiee' => $concept,
        // Jamais de power ici — même contrat que confort/oscillation ci-dessus. ⚠️ Ne
        // PAS en ajouter non plus : ce serait une mitigation illusoire de l'impasse
        // « éteint + verrouillé » (§ 2.2 de la spec technique, R1/D-UC03-05).
        'ordre' => array($concept => 0),
        'ordreCmd' => $fonction['ordre'] + 1,
      );
    }

    // Commande méta hors profil de capacités (UC07, § 7 de la spec technique) —
    // inconditionnelle, comme les commandes méta d'UC05 : rafraîchir a du sens même
    // sur un équipement au profil vide. ordreCmd = 40 > maximum atteignable par les
    // boucles ci-dessus (13 + 5 modes + 8 vitesses = 26) : le bouton se place APRÈS
    // les autres actions.
    $definitions[self::CMD_RAFRAICHIR] = array(
      'name' => __('Rafraîchir', __FILE__),
      'subType' => 'other',
      'infoLiee' => '',
      'ordre' => array(),
      'ordreCmd' => 40,
    );

    return $definitions;
  }

  /**
   * Pose le widget de tuile « climatiseur » sur la commande info 'power', SI ET
   * SEULEMENT SI aucun template n'est encore posé sur elle (AC9 : ne jamais écraser un
   * widget choisi à la main), puis masque les commandes reprises par la tuile (D3, § 6
   * de la spec technique UC01 du domaine post-mvp/06-ergonomie-jeedom).
   *
   * ⚠️ Le masquage est joué UNE SEULE FOIS par équipement, mais PAS seulement au passage
   * qui pose le template : il l'est aussi quand la tuile est déjà en place sans avoir
   * jamais masqué (widget sélectionné à la main par l'utilisateur, ou pose antérieure au
   * correctif de templateLibre()). Son marqueur d'unicité est CLE_MASQUAGE_TUILE, posé
   * sur la CONFIGURATION DE LA COMMANDE 'power' — cf. cette constante pour la raison pour
   * laquelle il n'est pas sur l'équipement (récursion par postSave()). Ne lève jamais.
   *
   * @param array<string, cmd> $_existantes Commandes indexées par logicalId (getCmd(null, null)).
   * @return bool Vrai si le TEMPLATE a été posé à ce passage (le masquage, lui, peut avoir
   *              été joué sans que le template ait bougé).
   */
  private function poserWidgetTuile(array $_existantes) {
    if (!isset($_existantes[smartclimCapabilities::CONCEPT_POWER])) {
      return false;
    }
    $power = $_existantes[smartclimCapabilities::CONCEPT_POWER];
    if ($power->getType() !== 'info') {
      return false;
    }
    $template = (string) $power->getTemplate('dashboard', '');
    if (!self::templateLibre($template) && $template !== self::WIDGET_TUILE) {
      // Widget choisi à la main (le nôtre ou celui d'un autre plugin) : on ne touche ni
      // au template, ni à la visibilité des commandes (AC9).
      return false;
    }

    $pose = false;
    if (self::templateLibre($template)) {
      try {
        $power->setTemplate('dashboard', self::WIDGET_TUILE);
        $power->setTemplate('mobile', self::WIDGET_TUILE);
        $power->save();
        $pose = true;
      } catch (Throwable $t) {
        log::add('smartclim', 'error', 'Pose du widget "climatiseur" impossible (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '") : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
        return false;
      }
    }

    // ⚠️ Le masquage n'est PLUS conditionné à la pose de CE passage : il est joué une
    // fois dès que 'power' porte la tuile, y compris quand l'utilisateur l'a
    // sélectionnée lui-même dans la liste des widgets — sans quoi il se retrouve avec
    // la tuile ET les commandes qu'elle reprend, affichées en double. Le marqueur
    // d'unicité est CLE_MASQUAGE_TUILE, porté par 'power' : le template ne pouvait plus
    // jouer ce rôle dès lors qu'il peut être déjà posé à l'entrée.
    if (!$power->getConfiguration(self::CLE_MASQUAGE_TUILE)) {
      // masquerCommandesTuile() ne lève jamais (try/catch PAR commande, cf. son propre
      // docblock) : pas de wrapping supplémentaire ici, même patron que l'appel à
      // masquerCommandesModes() depuis appliquerCapacites().
      $this->masquerCommandesTuile($_existantes);
      try {
        $power->setConfiguration(self::CLE_MASQUAGE_TUILE, 1);
        $power->save();
      } catch (Throwable $t) {
        log::add('smartclim', 'warning', 'Marqueur de masquage de la tuile non enregistré (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '") : ' . self::neutraliserPourLog($t->getMessage()));
      }
    }
    return $pose;
  }

  /**
   * Vrai si AUCUN widget n'est sélectionné sur une commande, c'est-à-dire si le template
   * est libre et peut recevoir le nôtre (§ 8.2 de la spec technique UC06 du MVP).
   *
   * ⚠️ Le critère n'est PAS `=== ''` : le core n'enregistre pas nécessairement la chaîne
   * vide pour « widget par défaut » (il y stocke une sentinelle, 'default'), et la garde
   * historique empêchait alors TOUTE pose sur une commande déjà sauvegardée — cas de la
   * tuile, posée sur 'power' que creerCommandesInfo() vient d'enregistrer, là où les
   * widgets d'action sont posés sur un objet cmd neuf dont le display est encore vierge.
   * Le critère retenu est la PRÉSENCE DU SÉPARATEUR '::' : tout widget réellement
   * sélectionné, de plugin comme du core, s'écrit '<domaine>::<nom>' — toute valeur qui
   * n'en porte pas est une sentinelle du core, jamais un choix de l'utilisateur.
   *
   * @param string $_template Valeur de cmd::getTemplate().
   * @return bool
   */
  private static function templateLibre($_template) {
    return strpos((string) $_template, '::') === false;
  }

  /**
   * Masque (isVisible = 0, JAMAIS de suppression) les commandes reprises par la tuile
   * « climatiseur » (§ 6 « Périmètre exact du masquage » de la spec technique UC01 du
   * domaine post-mvp/06-ergonomie-jeedom). Appelée UNIQUEMENT depuis poserWidgetTuile(),
   * et UNE SEULE FOIS par équipement (marqueur CLE_MASQUAGE_TUILE sur la commande
   * 'power') — un masquage rejoué à chaque cycle écraserait le choix d'un utilisateur qui
   * aurait réaffiché la commande (bord assumé, D3).
   *
   * ⚠️ JAMAIS 'power' (hôte de la tuile), JAMAIS 'refresh' (bouton d'en-tête du core,
   * § 1.7), JAMAIS 'ambient_temp'/'target_temp' (D9, accès aux graphiques historisés), et
   * jamais les concepts confort/oscillation/protection (hors périmètre de cette UC).
   *
   * @param array<string, cmd> $_existantes Commandes indexées par logicalId.
   * @return int Nombre de commandes masquées.
   */
  private function masquerCommandesTuile(array $_existantes) {
    $cibles = array(
      smartclimCapabilities::CONCEPT_ONLINE => true,
      smartclimCapabilities::CONCEPT_MODE => true,
      smartclimCapabilities::CONCEPT_FAN_SPEED => true,
      self::CMD_TRANSPORT => true,
      self::CMD_DERNIERE_MAJ => true,
      self::CMD_ON => true,
      self::CMD_OFF => true,
      self::CMD_CONSIGNE => true,
    );
    foreach ($_existantes as $logicalId => $cmd) {
      if (strpos($logicalId, self::PREFIXE_CMD_MODE) === 0 || strpos($logicalId, self::PREFIXE_CMD_VITESSE) === 0) {
        $cibles[$logicalId] = true;
      }
    }

    $masquees = 0;
    foreach (array_keys($cibles) as $logicalId) {
      if (!isset($_existantes[$logicalId])) {
        continue;
      }
      $cmd = $_existantes[$logicalId];
      if (!$cmd->getIsVisible()) {
        continue;
      }
      try {
        $cmd->setIsVisible(0);
        $cmd->save();
        $masquees++;
        log::add('smartclim', 'info', 'Commande masquée (reprise par la tuile "climatiseur") : ' . $logicalId . ' sur ' . self::neutraliserPourLog($this->getHumanName()));
      } catch (Throwable $t) {
        log::add('smartclim', 'warning', 'Masquage impossible pour ' . $logicalId . ' (tuile "climatiseur") : ' . self::neutraliserPourLog($t->getMessage()));
      }
    }
    return $masquees;
  }

  /**
   * Crée les commandes action MANQUANTES, pose le widget si aucun n'est choisi, et
   * réaligne minValue/maxValue/step de set_target_temp (UC06, § 4.1/5.3/8 de la spec
   * technique). Appelée APRÈS creerCommandesInfo() (besoin des id d'info pour
   * setValue()). try/catch PAR COMMANDE, ne lève JAMAIS.
   *
   * @return int Nombre de commandes créées.
   */
  private function creerCommandesAction() {
    $definitions = $this->definitionsCommandesAction();

    $existantes = array();
    foreach ($this->getCmd(null, null) as $cmdExistante) {
      $existantes[$cmdExistante->getLogicalId()] = $cmdExistante;
    }

    $bornes = $this->bornesTemperature();
    $crees = 0;

    foreach ($definitions as $logicalId => $definition) {
      if (isset($existantes[$logicalId])) {
        if ($logicalId === self::CMD_CONSIGNE) {
          try {
            $this->realignerBornesConsigne($existantes[$logicalId], $bornes);
          } catch (Throwable $t) {
            log::add('smartclim', 'error', 'Réalignement des bornes de "' . $logicalId . '" impossible (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '") : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
          }
        } elseif (self::templateLibre($existantes[$logicalId]->getTemplate('dashboard', ''))) {
          // Repose le widget si l'utilisateur est explicitement revenu au widget par
          // défaut du core (§ 8.2 de la spec technique) : pose idempotente, jamais
          // d'écrasement d'un template déjà posé (le nôtre ou celui choisi à la main).
          try {
            $existantes[$logicalId]->setTemplate('dashboard', 'smartclim::etat');
            $existantes[$logicalId]->setTemplate('mobile', 'smartclim::etat');
            $existantes[$logicalId]->save();
          } catch (Throwable $t) {
            log::add('smartclim', 'error', 'Repose du widget de "' . $logicalId . '" impossible (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '") : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
          }
        }
        continue;
      }
      if ($definition['name'] === '') {
        continue;
      }
      try {
        $cmd = new smartclimCmd();
        $cmd->setEqLogic_id($this->getId());
        $cmd->setLogicalId($logicalId);
        $cmd->setName($definition['name']);
        $cmd->setType('action');
        $cmd->setSubType($definition['subType']);
        $cmd->setIsVisible(1);
        $cmd->setOrder($definition['ordreCmd']);

        if ($definition['infoLiee'] !== '' && isset($existantes[$definition['infoLiee']])) {
          $cmd->setValue($existantes[$definition['infoLiee']]->getId());
        } elseif ($definition['infoLiee'] !== '') {
          // Le pilotage ne doit pas dépendre d'une info que l'utilisateur aurait
          // supprimée (§ 10 de la spec technique) : la commande action est créée quand
          // même, simplement sans lien de modèle. Micro-correctif UC07 : une infoLiee
          // VOLONTAIREMENT vide (ex. CMD_RAFRAICHIR) n'est pas une info supprimée, ce
          // log ne doit donc pas se déclencher pour elle.
          log::add('smartclim', 'debug', 'Commande action "' . $logicalId . '" créée sans commande info liée (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '")');
        }

        if ($logicalId === self::CMD_CONSIGNE) {
          $cmd->setConfiguration('minValue', $bornes['min']);
          $cmd->setConfiguration('maxValue', $bornes['max']);
          $cmd->setConfiguration('step', $bornes['pas']);
          $cmd->setDisplay('parameters', array_merge((array) $cmd->getDisplay('parameters'), array('step' => $bornes['pas'])));
        } elseif (self::templateLibre($cmd->getTemplate('dashboard', ''))) {
          // Pose idempotente (§ 8.2 de la spec technique) : n'écrase jamais un widget
          // choisi à la main.
          $cmd->setTemplate('dashboard', 'smartclim::etat');
          $cmd->setTemplate('mobile', 'smartclim::etat');
        }

        // UC03 du domaine post-mvp/04-fonctions-avancees (§ 5.3.1 de sa spec technique) :
        // seul ajout de mécanique du cycle. Accès par !empty() UNIQUEMENT — l'absence de
        // la clé (toutes les définitions existantes, dont les boucles mode_*/fan_*) est
        // traitée exactement comme false, aucune n'est donc affectée. Posé qu'À LA
        // CRÉATION (commande neuve ici, sans conséquence).
        if (!empty($definition['confirmation'])) {
          $cmd->setConfiguration('actionConfirm', 1);
        }

        $cmd->save();
        $crees++;
        // UC01 du domaine post-mvp/06-ergonomie-jeedom (§ 6 de la spec technique) : sans
        // cette ligne, une action créée DANS CETTE MÊME passe échapperait au masquage de
        // poserWidgetTuile() ci-dessous (elle n'était pas dans $existantes au moment où
        // la boucle a commencé).
        $existantes[$logicalId] = $cmd;
      } catch (Throwable $t) {
        log::add('smartclim', 'error', 'Création de la commande action "' . $logicalId . '" impossible (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '") : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
      }
    }

    // UC01 du domaine post-mvp/06-ergonomie-jeedom (§ 3 D3 de la spec technique) : posée
    // ICI pour être atteinte à la fois par postSave() et par appliquerEtat() (§ "Pourquoi
    // là" de la spec technique — même mécanisme qu'UC06 pour lier on/off à l'info power).
    $this->poserWidgetTuile($existantes);

    return $crees;
  }

  /**
   * Réaligne minValue/maxValue/step (configuration ET display.parameters, § 8.3 de la
   * spec technique) de la commande de consigne sur les bornes EFFECTIVES courantes.
   * Comparaison NUMÉRIQUE À TOLÉRANCE (R6) : sans elle, la chaîne '16' issue du
   * formulaire diffère du float 16.0 et chaque cycle de cron émettrait un cmd::save().
   * Le tableau display.parameters existant est FUSIONNÉ, jamais remplacé (R6).
   *
   * @param cmd $_cmd Commande action set_target_temp déjà existante.
   * @param array{min:float,max:float,pas:float} $_bornes
   */
  private function realignerBornesConsigne($_cmd, array $_bornes) {
    $modifie = false;
    if (!self::bornesEgales((float) $_cmd->getConfiguration('minValue'), $_bornes['min'])) {
      $_cmd->setConfiguration('minValue', $_bornes['min']);
      $modifie = true;
    }
    if (!self::bornesEgales((float) $_cmd->getConfiguration('maxValue'), $_bornes['max'])) {
      $_cmd->setConfiguration('maxValue', $_bornes['max']);
      $modifie = true;
    }
    if (!self::bornesEgales((float) $_cmd->getConfiguration('step'), $_bornes['pas'])) {
      $_cmd->setConfiguration('step', $_bornes['pas']);
      $modifie = true;
    }
    $parametres = $_cmd->getDisplay('parameters');
    if (!is_array($parametres)) {
      $parametres = array();
    }
    if (!isset($parametres['step']) || !self::bornesEgales((float) $parametres['step'], $_bornes['pas'])) {
      $parametres['step'] = $_bornes['pas'];
      $_cmd->setDisplay('parameters', $parametres);
      $modifie = true;
    }
    if ($modifie) {
      $_cmd->save();
    }
  }

  /**
   * Comparaison NUMÉRIQUE À TOLÉRANCE (0,001) dédiée à realignerBornesConsigne() (R6) —
   * distincte de memeValeur() ci-dessous (tolérance 0,01, usage grâce/optimiste).
   *
   * @return bool
   */
  private static function bornesEgales($_a, $_b) {
    return abs($_a - $_b) < 0.001;
  }

  /**
   * Construit l'ordre GÉNÉRIQUE d'une commande action (UC03 du domaine
   * post-mvp/01-transport-broadlink-lan, § 5.3 de sa spec technique) : extraction
   * VERBATIM des lignes de construction de executerCommandeAction() (consigne via
   * ordreEffectifConsigne(), sinon $definition['ordre']), PLUS la garde d'existence
   * qu'elle porte désormais elle-même — nécessaire ici, car envoyerCommandeActionLan()
   * (le chemin LOCAL) ne passe PAS par les gardes cloud d'executerCommandeAction()
   * (compteConfigure, auxhome_device_id) qui n'ont pas de sens pour ce transport.
   * Reste PRIVATE : les DEUX chemins (cloud, local) restent responsables de leurs
   * propres gardes AVANT de l'appeler (commande RAFRAICHIR notamment).
   *
   * @param string $_logicalId
   * @param array $_options
   * @return array Map GÉNÉRIQUE concept => valeur générique.
   * @throws smartclimException Message DÉJÀ CURATÉ en français, littéral EXISTANT
   *   ('Commande inconnue pour cet équipement') — aucune clé i18n nouvelle.
   */
  private function ordreDeCommandeAction($_logicalId, array $_options) {
    $definitions = $this->definitionsCommandesAction();
    if (!isset($definitions[$_logicalId])) {
      throw new smartclimException(__('Commande inconnue pour cet équipement', __FILE__), smartclimException::TYPE_INTERNE);
    }
    $definition = $definitions[$_logicalId];

    if ($_logicalId === self::CMD_CONSIGNE) {
      return array(
        smartclimCapabilities::CONCEPT_POWER => 1,
        smartclimCapabilities::CONCEPT_TARGET_TEMP => $this->ordreEffectifConsigne($_options),
      );
    }
    return $definition['ordre'];
  }

  /**
   * Point d'entrée UNIQUE du pilotage, appelé par smartclimCmd::execute() (UC06, §
   * 5.3/10 de la spec technique ; RESTRUCTURÉE par UC01 du domaine
   * post-mvp/02-strategies-de-transport, § 5.3 de sa spec technique — ORDRE IMPÉRATIF).
   * Recrée une exception CURATÉE en français à chaque point de sortie en échec (contrat
   * @throws) : jamais un message technique affiché tel quel côté navigateur.
   *
   * 1. session_write_close() gardé (§ 10.1 — n'affecte jamais un contexte cron/scénario)
   * 2. liste blanche definitionsCommandesAction() — SEULE garde d'autorisation du
   *    plugin, elle reste la PREMIÈRE, AVANT toute garde cloud (§ 5.3 étape 2)
   * 3. CMD_RAFRAICHIR, précédé d'une garde CLOUD DÉDIÉE (décision § 0.2 : « Rafraîchir »
   *    conserve son erreur en mode CLOUD) — en AUTO et en LOCAL, aucune garde, le
   *    silence y est le comportement voulu par la spec
   * 4. transport RETENU (smartclimTransport::transportRetenu())
   * 5. construction de l'ordre GÉNÉRIQUE (+ power => 1 pour mode et consigne)
   * 6. déduplication (AC7/AC10, § 7 de la spec technique) — TRANSPORT-NEUTRE
   * 7. aiguillage, deux blocs try/catch DISTINCTS (LAN puis cloud, gardes cloud
   *    DÉPLACÉES dans la branche cloud — cœur d'AC2/AC3 de l'UC de ce domaine)
   *
   * @param string $_logicalId
   * @param array $_options
   * @throws smartclimException Message DÉJÀ CURATÉ en français (affiché par displayException()).
   */
  public function executerCommandeAction($_logicalId, array $_options = array()) {
    // § 10.1 : cmd.ajax.php n'appelle jamais session_write_close() lui-même, et un
    // ordre de 3 à 18 s figerait sinon toute l'interface (session fichier).
    if (session_status() === PHP_SESSION_ACTIVE) {
      session_write_close();
    }

    $definitions = $this->definitionsCommandesAction();
    if (!isset($definitions[$_logicalId])) {
      throw new smartclimException(__('Commande inconnue pour cet équipement', __FILE__), smartclimException::TYPE_INTERNE);
    }

    if ($_logicalId === self::CMD_RAFRAICHIR) {
      // Décision § 0.2 de la spec technique de ce domaine : un équipement dont
      // l'utilisateur a EXPLICITEMENT choisi CLOUD doit dire pourquoi il ne fait rien,
      // d'autant que toute autre commande du même équipement lève l'erreur —
      // rafraichirAuxHome() a bien sa garde compteConfigure(), mais SILENCIEUSE PAR
      // CONCEPTION (écrite pour le cron, pas pour un clic). En AUTO et en LOCAL,
      // aucune garde ici : rafraichirMaintenant() sait déjà router vers le LAN.
      if (smartclimTransport::mode($this) === smartclimTransport::MODE_CLOUD) {
        if (!self::compteConfigure()) {
          throw new smartclimException(__('Compte AUX Home non configuré : renseignez l\'e-mail et le mot de passe', __FILE__), smartclimException::TYPE_AUTH);
        }
        $identifiantAppareilRafraichir = $this->getConfiguration('auxhome_device_id');
        if (!is_string($identifiantAppareilRafraichir) || $identifiantAppareilRafraichir === '') {
          throw new smartclimException(__('Cet équipement n\'est pas relié à un appareil AUX Home — relancez un scan', __FILE__), smartclimException::TYPE_INTERNE);
        }
      }
      // UC07, § 7 de la spec technique : sort AVANT le calcul d'empreinte de
      // déduplication, AVANT le cache::set du marqueur de dédup et AVANT
      // appliquerOrdre() — un rafraîchissement est en lecture seule et AC6 exige une
      // mise à jour IMMÉDIATE, avaler un second clic contredirait le critère.
      $this->rafraichirMaintenant();
      return;
    }

    $transport = smartclimTransport::transportRetenu($this);

    // UC02 du domaine post-mvp/03-cloud-aux-legacy (§ 3.6/D7 de sa spec technique) :
    // message HONNÊTE pour un équipement legacy PUR, routé vers le LAN faute de LAN
    // joignable ET de compte AUX Home (transportRetenu() ne renvoie jamais de chaîne
    // vide, § 5.1 de smartclimTransport) — sans cette garde, messageErreurLan()
    // afficherait « lancez un scan ou renseignez l'adresse IP », un message AFFIRMATIF
    // et FAUX pour un appareil qui ne parle pas Broadlink (arbitrage du 2026-09-08,
    // § 7.1 d'UC01 de ce domaine). Placée ICI, juste après transportRetenu() et AVANT
    // ordreDeCommandeAction() : c'est le SEUL point de cette UC hors découverte pure.
    // UC03 du domaine post-mvp/03-cloud-aux-legacy (§ 5.3 de sa spec technique) : garde
    // D7 d'UC02 REMPLACÉE, PAS supprimée. Elle est désormais INATTEIGNABLE dès que le
    // compte legacy est configuré : transportRetenu() route alors cet équipement vers
    // TRANSPORT_AUX_CLOUD_LEGACY (jamais LAN) dans exactement ce cas de figure. Elle
    // reste utile — avec un message CORRIGÉ, TYPE_AUTH — pour le cas où seul un scan a
    // relié cet équipement au cloud historique SANS que son compte soit configuré :
    // l'ANCIEN message (« ne peut pas encore être piloté ») y était devenu FAUX, ce
    // pilotage étant désormais possible dès la configuration du compte.
    if ($transport === smartclimCapabilities::TRANSPORT_BROADLINK_LAN && $this->adresseLan()['ip'] === '' && !smartclimTransport::cloudDisponible($this)) {
      $endpointAuxCloud = $this->getConfiguration(self::CLE_CONF_AUXCLOUD_ENDPOINT_ID);
      if (is_string($endpointAuxCloud) && $endpointAuxCloud !== '' && !smartclimTransport::cloudLegacyDisponible($this)) {
        throw new smartclimException(__('Cet équipement est relié au cloud historique (AC Freedom) — configurez ce compte (e-mail, mot de passe, région) pour le piloter', __FILE__), smartclimException::TYPE_AUTH);
      }
    }

    // UC02 du domaine post-mvp/02-strategies-de-transport (§ 6.2 de sa spec technique) :
    // garde de TEMPORISATION cloud — disjoncteur à DEMI-OUVERTURE, refus immédiat DATÉ,
    // AUCUN usleep(), AUCUN rejeu HTTP. Placée AVANT ordreDeCommandeAction() et donc
    // AVANT le marqueur de déduplication (rien à nettoyer), et APRÈS CMD_RAFRAICHIR
    // ci-dessus (§ 6.1 : « Rafraîchir » n'est JAMAIS temporisé, c'est l'échappatoire
    // manuelle de l'utilisateur).
    if ($transport === smartclimCapabilities::TRANSPORT_AUX_HOME && smartclimTransport::repliCloudActif($this)) {
      $attente = $this->attenteCloudRestante();
      if ($attente > 0) {
        // ⚠️ TYPE_RESEAU est un choix PAR DÉFAUT (§ 6.2 de la spec technique) : aucun
        // type « refus local » n'existe parmi les 4 constantes de smartclimException,
        // et AUCUN paquet n'a été émis ici — ne pas en déduire qu'un appel réseau a eu
        // lieu à la lecture d'un futur getType() === TYPE_RESEAU.
        throw new smartclimException(sprintf(__('Cloud indisponible, nouvelle tentative possible dans %d s', __FILE__), $attente), smartclimException::TYPE_RESEAU);
      }
    }

    // UC03 du domaine post-mvp/01-transport-broadlink-lan (§ 5.3 de sa spec technique) :
    // construction de l'ordre EXTRAITE dans ordreDeCommandeAction(), réutilisée à
    // l'identique par le pilotage local (envoyerCommandeActionLan()) — c'est ce qui
    // rend AC4 vrai PAR CONSTRUCTION (même power => 1, même quantification, même
    // liste blanche de logicalId que le chemin cloud). Double appel à
    // definitionsCommandesAction() ASSUMÉ (elle ne fait aucune E/S).
    $ordre = $this->ordreDeCommandeAction($_logicalId, $_options);

    $ordreTrie = $ordre;
    ksort($ordreTrie);
    $empreinte = sha1(json_encode($ordreTrie));
    $cleDedup = self::CLE_CACHE_DEDUP . $this->getId() . '::' . $empreinte;
    if (cache::byKey($cleDedup)->getValue(null) !== null) {
      // Retour SILENCIEUX (§ 10 de la spec technique) : aucune exception, aucun réseau,
      // aucune écriture d'état — le premier ordre l'a déjà fait. TRANSPORT-NEUTRE (§ 7
      // de la spec technique de ce domaine) : la clé est le CONTENU de l'ordre, pas
      // l'équipement seul — l'appareil bipe quel que soit le chemin emprunté.
      log::add('smartclim', 'debug', 'Ordre dédupliqué (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '", commande "' . $_logicalId . '")');
      return;
    }
    // Marqueur posé AVANT l'appel réseau (§ 7) : couvre le double-clic pendant que le
    // 1er ordre est en vol.
    cache::set($cleDedup, '1', self::DUREE_DEDUP_ORDRE);

    if ($transport === smartclimCapabilities::TRANSPORT_BROADLINK_LAN) {
      try {
        $this->envoyerOrdreLan($ordre);
        return;
      } catch (smartclimException $e) {
        // ⚠️ NE PAS re-curer : envoyerOrdreLan() rend déjà un message français curaté
        // par messageErreurLan() (§ 5.3 de la spec technique de ce domaine). Un ordre
        // échoué doit rester rejouable immédiatement (§ 7).
        cache::delete($cleDedup);
        throw $e;
      } catch (Throwable $t) {
        cache::delete($cleDedup);
        log::add('smartclim', 'error', 'Commande action "' . $_logicalId . '" (LAN) échouée (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '") : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
        throw new smartclimException(__('Erreur interne lors de l\'envoi de la commande — consultez les logs du plugin', __FILE__), smartclimException::TYPE_INTERNE);
      }
    }

    // UC03 du domaine post-mvp/03-cloud-aux-legacy (§ 5.3 de sa spec technique) : +1
    // branche, CALQUÉE sur la branche LAN ci-dessus (même try/catch(smartclimException)
    // puis catch(Throwable) en DERNIER bloc, même purge du marqueur de déduplication en
    // échec — un ordre échoué doit rester rejouable immédiatement).
    if ($transport === smartclimCapabilities::TRANSPORT_AUX_CLOUD_LEGACY) {
      try {
        $this->envoyerOrdreAuxCloud($ordre);
        return;
      } catch (smartclimException $e) {
        // ⚠️ NE PAS re-curer : envoyerOrdreAuxCloud() rend déjà un message français
        // curaté par messageErreurAuxCloud() (ou un littéral déjà curaté à la source).
        cache::delete($cleDedup);
        throw $e;
      } catch (Throwable $t) {
        cache::delete($cleDedup);
        log::add('smartclim', 'error', 'Commande action "' . $_logicalId . '" (AUX Cloud legacy) échouée (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '") : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
        throw new smartclimException(__('Erreur interne lors de l\'envoi de la commande — consultez les logs du plugin', __FILE__), smartclimException::TYPE_INTERNE);
      }
    }

    // TRANSPORT_AUX_HOME : gardes cloud DÉPLACÉES ici (§ 5.3 étape 7 de la spec
    // technique de ce domaine — cœur d'AC2/AC3) : un équipement LOCAL sur un Jeedom
    // sans compte AUX Home ne doit JAMAIS les rencontrer.
    if (!self::compteConfigure()) {
      throw new smartclimException(__('Compte AUX Home non configuré : renseignez l\'e-mail et le mot de passe', __FILE__), smartclimException::TYPE_AUTH);
    }
    $identifiantAppareil = $this->getConfiguration('auxhome_device_id');
    if (!is_string($identifiantAppareil) || $identifiantAppareil === '') {
      throw new smartclimException(__('Cet équipement n\'est pas relié à un appareil AUX Home — relancez un scan', __FILE__), smartclimException::TYPE_INTERNE);
    }

    try {
      $ordreApplique = smartclimAuxHomeApi::appliquerOrdre($identifiantAppareil, $ordre);
    } catch (smartclimException $e) {
      // Un ordre échoué doit rester rejouable immédiatement (§ 7).
      cache::delete($cleDedup);
      log::add('smartclim', 'error', 'Commande action "' . $_logicalId . '" échouée (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '", type ' . $e->getType() . ') : ' . self::neutraliserPourLog($e->getMessage()));
      // UC02 du domaine post-mvp/02-strategies-de-transport (§ 5.3/6.3 de sa spec
      // technique) : les 3 écritures cloud sont CONDITIONNÉES à repliCloudActif($this)
      // — ce compteur n'existe QUE pour armer/désarmer la temporisation EN repli, pas
      // pour un équipement en mode CLOUD choisi à la main (D-MVP08-05, dette inchangée).
      if (smartclimTransport::repliCloudActif($this)) {
        $this->memoriserEchecCloud();
      }
      if ($e->getType() == smartclimException::TYPE_INTERNE) {
        // Littéral DÉDIÉ (§ 10 de la spec technique) : le message existant de
        // messageErreurAuxHome() pour TYPE_INTERNE parle de "préparation de la
        // connexion", faux dans ce contexte.
        throw new smartclimException(__('Erreur interne lors de l\'envoi de la commande — consultez les logs du plugin', __FILE__), $e->getType());
      }
      throw new smartclimException(self::messageErreurAuxHome($e->getType(), $e->getContexte()), $e->getType());
    } catch (Throwable $t) {
      // catch(Throwable) en DERNIER bloc (§ 10 de la spec technique) : une Error PHP 8
      // traverserait sinon catch(Exception), et core/ajax/cmd.ajax.php cesserait de
      // renvoyer du JSON.
      cache::delete($cleDedup);
      log::add('smartclim', 'error', 'Commande action "' . $_logicalId . '" échouée (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '") : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
      if (smartclimTransport::repliCloudActif($this)) {
        $this->memoriserEchecCloud();
      }
      throw new smartclimException(__('Erreur interne lors de l\'envoi de la commande — consultez les logs du plugin', __FILE__), smartclimException::TYPE_INTERNE);
    }

    if (smartclimTransport::repliCloudActif($this)) {
      $this->oublierEchecsCloud();
    }
    $this->enregistrerOrdre($ordreApplique);
    // État OPTIMISTE (AC3) : la valeur poussée est celle RÉELLEMENT envoyée (après
    // quantification par appliquerOrdre()), jamais celle demandée.
    $this->appliquerEtat($ordreApplique, true);
  }

  /**
   * Rafraîchissement manuel (AC6, § 7 de la spec technique) : exécute le CYCLE
   * GLOBAL (smartclim::rafraichirAuxHome(), tous les équipements ciblés — pas
   * seulement celui-ci) et ré-ancre l'échéance du cron au passage (marquerCycle()) —
   * conséquence mécanique du fait que le cloud ne sait renvoyer que la liste
   * complète des appareils, pas un seul.
   *
   * ⚠️ Seul point de bascule "message technique -> message curaté" de cette UC : le
   * message d'une smartclimException née dans la brique de transport n'est jamais
   * affiché tel quel.
   *
   * @throws smartclimException Message DÉJÀ CURATÉ en français.
   */
  public function rafraichirMaintenant() {
    // UC03 du domaine post-mvp/03-cloud-aux-legacy (§ 5.3 de sa spec technique) : +1
    // branche, placée AVANT le test MODE_LOCAL existant — un équipement dont
    // transportRetenu() désigne le cloud historique (quel que soit le mode configuré)
    // rafraîchit CET équipement SEUL, comme le fait déjà la branche LOCAL/LAN
    // ci-dessous — jamais le cycle GLOBAL cloud, qui ne le concerne pas.
    if (smartclimTransport::transportRetenu($this) === smartclimCapabilities::TRANSPORT_AUX_CLOUD_LEGACY) {
      $this->rafraichirAuxCloudEquipement();
      return;
    }
    // UC01 du domaine post-mvp/02-strategies-de-transport (§ 5.4 de sa spec
    // technique) : LOCAL -> LAN (cet équipement seul) ; AUTO et CLOUD -> cycle cloud
    // GLOBAL inchangé. Pour AUTO, le cycle cloud reste la lecture la plus riche (seul
    // porteur de `online`) — tenter LAN PUIS cloud violerait « jamais deux transports
    // pour une même opération » (UC02 du même domaine, AC3).
    if (smartclimTransport::mode($this) === smartclimTransport::MODE_LOCAL) {
      $this->rafraichirLanEquipement();
      return;
    }
    $resultat = self::rafraichirAuxHome();
    if ($resultat['echecType'] !== null) {
      throw new smartclimException(self::messageErreurAuxHome($resultat['echecType'], $resultat['echecContexte']), $resultat['echecType']);
    }
  }

  /**
   * Bouton « Rafraîchir » d'un équipement en mode LOCAL (UC01 du domaine
   * post-mvp/02-strategies-de-transport, § 5.5 de sa spec technique) : budget GLOBAL
   * BUDGET_ORDRE_LAN (même enveloppe qu'un ordre interactif : hello + session + 1
   * échange). LÈVE une smartclimException au message DÉJÀ CURATÉ par
   * messageErreurLan() — chemin INTERACTIF, un échec silencieux y est interdit
   * (contrairement au cycle périodique rafraichirLan(), qui ne lève jamais).
   *
   * @throws smartclimException Message DÉJÀ CURATÉ en français.
   */
  public function rafraichirLanEquipement() {
    $debut = microtime(true);
    $lecture = null;
    try {
      $appareil = $this->sonderAppareilLan(self::BUDGET_ORDRE_LAN);
      $budgetRestant = max(1, self::BUDGET_ORDRE_LAN - (microtime(true) - $debut));
      $lecture = smartclimBroadlinkLan::lireEtat($appareil, min(self::BUDGET_LECTURE_LAN, $budgetRestant));
      if (self::statutEnEchec($lecture['statut'])) {
        throw new smartclimException('Broadlink LAN : lecture d\'état en échec (statut ' . $lecture['statut'] . ')', smartclimException::TYPE_RESEAU);
      }
    } catch (smartclimException $e) {
      log::add('smartclim', 'error', 'Rafraîchissement LAN échoué (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '", type ' . $e->getType() . ') : ' . self::neutraliserPourLog($e->getMessage()));
      // UC02 du domaine post-mvp/02-strategies-de-transport (§ 5.3 de sa spec
      // technique) : compteur de repli, AVANT le throw.
      $this->memoriserEchecLan();
      throw new smartclimException(self::messageErreurLan($e->getType(), $e->getContexte()), $e->getType());
    } catch (Throwable $t) {
      log::add('smartclim', 'error', 'Rafraîchissement LAN échoué (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '") : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
      $this->memoriserEchecLan();
      throw new smartclimException(__('Erreur interne lors de l\'envoi de la commande — consultez les logs du plugin', __FILE__), smartclimException::TYPE_INTERNE);
    }
    // UC02 du domaine post-mvp/02-strategies-de-transport (§ 5.3/5.1 de sa spec
    // technique) : couvre les DEUX issues (succès ET STATUT_ETAT_ILLISIBLE, qui ne
    // lève PAS ci-dessus mais compte comme un échec de repli via echecOperationLan()) —
    // ⚠️ ne JAMAIS substituer un simple !statutEnEchec() : rafraichirLanEquipement()
    // teste statutEnEchec(), qui WHITELISTE STATUT_ETAT_ILLISIBLE comme un succès —
    // un memoriserSuccesLan() posé ici sur cette base remettrait le compteur à zéro sur
    // une lecture pourtant inutilisable pour le pilotage.
    $this->noterOperationLan($lecture['statut']);
    // Lecture d'état SEULE (rafraîchissement, pas scan) : appliquerLectureLan() n'est
    // PAS appelée ici, cf. son docblock — un appel ici ferait diverger 'source' du
    // profil stocké sur un équipement AUTO découvert par le cloud. Appel
    // VOLONTAIREMENT hors du try/catch ci-dessus : ce chemin est INTERACTIF, un échec
    // doit LEVER (contrairement à rafraichirLan(), qui ne lève jamais).
    $this->appliquerEtat(smartclimBroadlinkLan::etatAppareil($lecture));
  }

  /**
   * POINT D'ENTRÉE du pilotage LOCAL (UC03 du domaine post-mvp/01-transport-broadlink-lan,
   * § 5.3 de sa spec technique), appelé PAR LA CLI (core/php/commande-lan.php) — un
   * script CLI ne peut pas appeler une méthode privée, cette méthode est donc PUBLIQUE.
   * C'est elle qui rend « AC4 par construction » vrai : elle passe par la MÊME
   * ordreDeCommandeAction() que le chemin cloud, donc la même injection power => 1, la
   * même quantification de consigne, la même liste blanche de logicalId.
   *
   * ⚠️ REVALIDE ICI la présence de $_logicalId et REFUSE CMD_RAFRAICHIR (lecture, pas un
   * ordre) : ces deux gardes ne sont plus portées par executerCommandeAction() sur ce
   * chemin, qui ne l'appelle pas.
   *
   * @param string $_logicalId
   * @param array $_options
   * @return array Ordre RÉELLEMENT appliqué (affiché par la CLI).
   * @throws smartclimException Message DÉJÀ CURATÉ en français.
   */
  public function envoyerCommandeActionLan($_logicalId, array $_options = array()) {
    $definitions = $this->definitionsCommandesAction();
    if (!isset($definitions[$_logicalId])) {
      throw new smartclimException(__('Commande inconnue pour cet équipement', __FILE__), smartclimException::TYPE_INTERNE);
    }
    if ($_logicalId === self::CMD_RAFRAICHIR) {
      // Message DÉDIÉ (§ 12 de la spec technique, revue croisée) : « Rafraîchir »
      // EXISTE bel et bien dans definitionsCommandesAction() — « Commande inconnue »
      // serait FAUX ici, c'est une lecture, pas un ordre à transmettre en local.
      throw new smartclimException(__('La commande « Rafraîchir » ne s\'envoie pas à l\'appareil', __FILE__), smartclimException::TYPE_INTERNE);
    }
    $ordre = $this->ordreDeCommandeAction($_logicalId, $_options);
    return $this->envoyerOrdreLan($ordre);
  }

  /**
   * Façade du pilotage LOCAL (UC03 de ce domaine, § 5.3/6 de sa spec technique) et
   * POINT DE BRANCHEMENT du futur domaine post-mvp/02-strategies-de-transport : il
   * l'appellera depuis executerCommandeAction() sans rien réécrire ici.
   *
   * ⚠️ Fusionne l'ordre DEMANDÉ avec la MÉMOIRE DES VALEURS COMMANDÉES encore sous
   * grâce (§ 6 de la spec technique — mécanisme d'AC5) : sans elle, une relecture faite
   * juste après un ordre précédent pourrait encore renvoyer l'ANCIEN état, et l'écriture
   * suivante le réécrirait, annulant silencieusement la commande précédente. Les clés
   * DEMANDÉES écrasent toujours celles héritées de la grâce (array_merge()).
   *
   * @param array $_ordreGenerique Map GÉNÉRIQUE concept => valeur générique.
   * @return array Ordre RÉELLEMENT appliqué (consigne après quantification).
   * @throws smartclimException Message DÉJÀ CURATÉ en français.
   */
  public function envoyerOrdreLan(array $_ordreGenerique) {
    $debut = microtime(true);
    try {
      $appareil = $this->sonderAppareilLan(self::BUDGET_ORDRE_LAN);
      $ordre = array_merge($this->valeursCommandees(), $_ordreGenerique);
      $budgetRestant = max(1, self::BUDGET_ORDRE_LAN - (microtime(true) - $debut));
      $applique = smartclimBroadlinkLan::appliquerOrdre($appareil, $ordre, $budgetRestant);
    } catch (smartclimException $e) {
      log::add('smartclim', 'error', 'Commande LAN échouée (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '", type ' . $e->getType() . ') : ' . self::neutraliserPourLog($e->getMessage()));
      // UC02 du domaine post-mvp/02-strategies-de-transport (§ 5.3/5.5 de sa spec
      // technique) : une écriture ratée est aussi une PREUVE d'échec LAN pour cet
      // appareil — memoriserEchecLan() est déjà no-op si $e->getContexte() vaut
      // CONTEXTE_LAN_MAC_DIVERGENTE (frontière identité/joignabilité, § 12.1 M2).
      $this->memoriserEchecLan($e->getContexte());
      throw new smartclimException(self::messageErreurLan($e->getType(), $e->getContexte()), $e->getType());
    } catch (Throwable $t) {
      // catch(Throwable) EN DERNIER bloc (même motif qu'executerCommandeAction()) :
      // une Error PHP 8 traverserait sinon catch(smartclimException).
      log::add('smartclim', 'error', 'Commande LAN échouée (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '") : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
      $this->memoriserEchecLan('');
      throw new smartclimException(__('Erreur interne lors de l\'envoi de la commande — consultez les logs du plugin', __FILE__), smartclimException::TYPE_INTERNE);
    }

    // UC02 du domaine post-mvp/02-strategies-de-transport (§ 5.3/12.1 M4 de sa spec
    // technique) : SEUL appel DIRECT à memoriserSuccesLan() en dehors de
    // noterOperationLan() — une écriture APPLIQUÉE EST la preuve, par construction.
    $this->memoriserSuccesLan();

    $this->enregistrerOrdre($applique);
    // Pose au passage la commande info "transport" (mécanisme d'UC02 de ce domaine, §
    // 5.5 de sa spec technique) : ÉTAT OPTIMISTE, la valeur poussée est celle
    // RÉELLEMENT envoyée (après quantification), jamais celle demandée.
    $this->appliquerEtat($applique + array('source' => smartclimCapabilities::TRANSPORT_BROADLINK_LAN), true);

    return $applique;
  }

  /**
   * Reconstitue l'appareil legacy PILOTABLE de cet équipement (UC03 du domaine
   * post-mvp/03-cloud-aux-legacy, § 3.7/D7 de sa spec technique) : identité stable
   * depuis la configuration (`auxcloud_*`, en clair), jetons d'appairage depuis la 7ᵉ
   * mémoire — et SI ABSENTS SEULEMENT (contrat imposé par l'UC02 § 4.1 : l'absence de
   * cette mémoire est le cas NOMINAL, TTL 1800 s pour un usage « scan le matin,
   * commande le soir ») via un `dev/query` ciblé sur `auxcloud_family_id`, jamais un
   * relistage complet.
   *
   * @param int $_budget Budget PROPAGÉ à smartclimAuxCloudApi::jetonsAppareil() si les
   *   jetons doivent être re-obtenus.
   * @return array{identifiant:string,type_produit:string,mac:string,devicetype_flag:string,cookie:string,dev_session:string,swing_inverse:bool}
   * @throws smartclimException Message DÉJÀ CURATÉ en français.
   */
  private function appareilAuxCloud($_budget) {
    $familyId = $this->getConfiguration(self::CLE_CONF_AUXCLOUD_FAMILY_ID);
    if (!is_string($familyId) || $familyId === '') {
      throw new smartclimException(__('Cet équipement n\'est pas relié à un appareil du cloud historique — relancez un scan', __FILE__), smartclimException::TYPE_INTERNE);
    }
    $identifiant = $this->getConfiguration(self::CLE_CONF_AUXCLOUD_ENDPOINT_ID);
    $identifiant = is_string($identifiant) ? $identifiant : '';
    $typeProduit = $this->getConfiguration(self::CLE_CONF_AUXCLOUD_PRODUCT_ID);
    $typeProduit = is_string($typeProduit) ? $typeProduit : '';
    $devicetypeFlag = $this->getConfiguration(self::CLE_CONF_AUXCLOUD_DEVICETYPE_FLAG);
    $devicetypeFlag = is_string($devicetypeFlag) ? $devicetypeFlag : '';
    $partage = (bool) $this->getConfiguration(self::CLE_CONF_AUXCLOUD_PARTAGE);
    $swingInverse = (bool) $this->getConfiguration(self::CLE_CONF_AUXCLOUD_SWING_INVERSE);

    $jetons = $this->jetonsAuxCloud();
    if (empty($jetons)) {
      try {
        $jetons = smartclimAuxCloudApi::jetonsAppareil($familyId, $identifiant, $partage, $_budget);
      } catch (smartclimException $e) {
        log::add('smartclim', 'error', 'AUX Cloud legacy : obtention des jetons d\'appairage échouée (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '", type ' . $e->getType() . ') : ' . self::neutraliserPourLog($e->getMessage()));
        throw new smartclimException(self::messageErreurAuxCloud($e->getType(), $e->getContexte()), $e->getType());
      }
      $this->memoriserJetonsAuxCloud($jetons);
    }

    return array(
      'identifiant' => $identifiant,
      'type_produit' => $typeProduit,
      'mac' => $this->macEquipement(),
      'devicetype_flag' => $devicetypeFlag,
      'cookie' => isset($jetons['cookie']) ? $jetons['cookie'] : '',
      'dev_session' => isset($jetons['dev_session']) ? $jetons['dev_session'] : '',
      'swing_inverse' => $swingInverse,
    );
  }

  /**
   * Lit la 7ᵉ mémoire de cache (CHIFFRÉE) de CET équipement (UC03, § 3.7/5.3 de la spec
   * technique) — VALIDE LA FORME et renvoie array() plutôt qu'un contenu forgé (même
   * patron que incidentMemorise()). Ne fait QUE lire ; l'écriture est
   * memoriserJetonsAuxCloud() ci-dessous.
   *
   * @return array{cookie:string,dev_session:string}
   */
  private function jetonsAuxCloud() {
    $brut = cache::byKey(self::CLE_CACHE_APPAREIL_AUXCLOUD . $this->getId())->getValue(null);
    if (!is_string($brut) || $brut === '') {
      return array();
    }
    $dechiffre = utils::decrypt($brut);
    if (!is_string($dechiffre) || $dechiffre === '') {
      return array();
    }
    $jetons = json_decode($dechiffre, true);
    if (!is_array($jetons) || !isset($jetons['cookie'], $jetons['dev_session']) || !is_string($jetons['cookie']) || $jetons['cookie'] === '' || !is_string($jetons['dev_session'])) {
      return array();
    }
    return array('cookie' => $jetons['cookie'], 'dev_session' => $jetons['dev_session']);
  }

  /**
   * Écrit la 7ᵉ mémoire de cache (CHIFFRÉE) de CET équipement — TTL
   * DUREE_MEMOIRE_APPAREIL_AUXCLOUD (§ 3.7 de la spec technique). Sans effet si
   * `cookie` est vide : ne mémorise JAMAIS un secret vide sous une clé valide.
   *
   * @param array $_jetons {cookie, dev_session}
   */
  private function memoriserJetonsAuxCloud(array $_jetons) {
    if ($this->getId() == '' || empty($_jetons['cookie'])) {
      return;
    }
    $contenu = json_encode(array(
      'cookie' => $_jetons['cookie'],
      'dev_session' => isset($_jetons['dev_session']) ? $_jetons['dev_session'] : '',
      'cree_le' => time(),
    ));
    if ($contenu === false) {
      return;
    }
    cache::set(self::CLE_CACHE_APPAREIL_AUXCLOUD . $this->getId(), utils::encrypt($contenu), self::DUREE_MEMOIRE_APPAREIL_AUXCLOUD);
  }

  /**
   * État de marche COURANT de cet équipement, 4 sources DANS CET ORDRE (AC2 de la spec
   * fonctionnelle, § 3.3/D3 de la spec technique UC03) : (a) — gérée par l'APPELANT,
   * qui n'invoque cette méthode QUE si l'ordre ne porte pas déjà `power` — ; (b) mémoire
   * d'ordres, dans la période de grâce ; (c) commande info `power`, si sa dernière
   * valeur n'est pas plus vieille qu'AGE_MAX_POWER_AUXCLOUD ; (d) lecture réseau
   * fraîche, SI le budget le permet.
   *
   * ⚠️ PROPAGE toute smartclimException levée par la lecture de repli (d) : une panne
   * réseau ne doit jamais se travestir en « état inconnu » — seule l'ABSENCE de valeur
   * exploitable, SANS exception, renvoie `null`.
   *
   * @param array $_appareil Cf. appareilAuxCloud().
   * @param float|int $_budget Budget RESTANT de l'appelant.
   * @return int|null 0, 1, ou null si aucune des 4 sources n'a permis de conclure.
   * @throws smartclimException Si la lecture de repli (d) échoue (message TECHNIQUE).
   */
  private function etatMarcheCourant(array $_appareil, $_budget) {
    $memoire = $this->memoireOrdres();
    if (isset($memoire[smartclimCapabilities::CONCEPT_POWER])) {
      return $memoire[smartclimCapabilities::CONCEPT_POWER]['valeur'] ? 1 : 0;
    }

    $cmdPower = null;
    foreach ($this->getCmd(null, null) as $cmdExistante) {
      if ($cmdExistante->getType() === 'info' && $cmdExistante->getLogicalId() === smartclimCapabilities::CONCEPT_POWER) {
        $cmdPower = $cmdExistante;
        break;
      }
    }
    if ($cmdPower instanceof cmd) {
      $dateValeur = $cmdPower->getValueDate();
      $ts = is_string($dateValeur) ? strtotime($dateValeur) : false;
      if ($ts !== false && (time() - $ts) <= self::AGE_MAX_POWER_AUXCLOUD) {
        return ((int) $cmdPower->execCmd()) ? 1 : 0;
      }
    }

    // (d) — l'écriture est servie D'ABORD (§ 4.1 de la spec technique) : cette source
    // ne se déclenche QUE s'il reste assez de budget pour ELLE ET pour le `set` qui
    // suivra.
    if ($_budget < self::RESERVE_ECRITURE_AUXCLOUD + 3) {
      return null;
    }
    // ⚠️ Correctif post-review UC03 (point 1) : appel DIRECT à
    // smartclimAuxCloudApi::lireParametres(), JAMAIS lireParametresAvecRejeuAppairage() —
    // cette méthode est appelée DEPUIS envoyerOrdreAuxCloud(), qui porte DÉJÀ son propre
    // rejeu d'appairage (purge 7ᵉ mémoire + jetonsAppareil() + un seul rejeu). Passer par
    // le wrapper ici doublerait la purge/ré-obtention pour UNE SEULE commande — exactement
    // la rafale que le § 3.8/D8 de la spec technique interdit.
    $valeurs = smartclimAuxCloudApi::lireParametres($_appareil, $_budget - self::RESERVE_ECRITURE_AUXCLOUD);
    if (array_key_exists('pwr', $valeurs) && is_scalar($valeurs['pwr'])) {
      return ((int) $valeurs['pwr']) ? 1 : 0;
    }
    return null;
  }

  /**
   * Lit les paramètres d'UN appareil legacy avec REJEU D'APPAIRAGE (correctif
   * post-review UC03, point 1 — factorisation du patron déjà écrit dans
   * envoyerOrdreAuxCloud()) : sur `TYPE_PROTOCOLE` + `CONTEXTE_APPAIRAGE_REFUSE`
   * UNIQUEMENT, purge la 7ᵉ mémoire de CET équipement, ré-obtient les jetons via
   * appareilAuxCloud() (le SEUL chemin qui connaît `auxcloud_family_id`, une clé de
   * CONFIGURATION D'ÉQUIPEMENT structurellement indisponible dans smartclimAuxCloudApi),
   * et rejoue UNE SEULE fois. Booléen local, JAMAIS de récursion, conditionné au budget
   * RESTANT — même patron que le rejeu d'écriture.
   *
   * ⚠️ RÉSERVÉ aux DEUX cycles de LECTURE D'ÉTAT (rafraichirAuxCloudEquipement(),
   * rafraichirAuxCloud()) — JAMAIS à etatMarcheCourant() (cf. son propre commentaire :
   * elle est appelée DEPUIS un rejeu déjà en cours). ⚠️ JAMAIS non plus à
   * diagnosticAuxCloud()/sonderParametreAuxCloud()/sonderSpecialAuxCloud() : ce sont des
   * INSTRUMENTS DE MESURE, ils doivent rendre le comportement BRUT du backend — un
   * rejeu automatique masquerait précisément le refus qu'on cherche à observer, même
   * doctrine que « ces méthodes ne rendent jamais la mémoire d'ordres ni le marqueur de
   * déduplication ».
   *
   * @param array $_appareil Passé PAR RÉFÉRENCE : mis à jour en place si un rejeu a eu
   *   lieu (l'appelant continue de l'utiliser après l'appel, ex. pour etatAppareil()).
   * @param int $_budget Budget de temps GLOBAL de CETTE lecture (session comprise).
   * @return array<string, mixed> nom brut => valeur brute.
   * @throws smartclimException Message TECHNIQUE (à curer par l'appelant).
   */
  private function lireParametresAvecRejeuAppairage(array &$_appareil, $_budget) {
    $debut = microtime(true);
    $rejoueAppairage = false;
    while (true) {
      $restant = $_budget - (microtime(true) - $debut);
      try {
        return smartclimAuxCloudApi::lireParametres($_appareil, max(3, $restant));
      } catch (smartclimException $e) {
        $restant = $_budget - (microtime(true) - $debut);
        if (
          !$rejoueAppairage
          && $e->getType() === smartclimException::TYPE_PROTOCOLE
          && $e->getContexte() === smartclimAuxCloudApi::CONTEXTE_APPAIRAGE_REFUSE
          && $restant >= smartclimAuxCloudApi::BUDGET_REJEU_ORDRE
        ) {
          $rejoueAppairage = true;
          log::add('smartclim', 'info', 'AUX Cloud legacy : rejeu (lecture) après ré-obtention des jetons d\'appairage (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '")');
          cache::delete(self::CLE_CACHE_APPAREIL_AUXCLOUD . $this->getId());
          // appareilAuxCloud() lève déjà une exception CURATÉE si elle échoue : elle
          // sort donc telle quelle de cette boucle, sans passer par un second catch.
          $_appareil = $this->appareilAuxCloud($restant);
          continue;
        }
        throw $e;
      }
    }
  }

  /**
   * Façade du pilotage AUX CLOUD LEGACY (UC03 du domaine post-mvp/03-cloud-aux-legacy,
   * § 3.3/4.1/5.3 de sa spec technique) — JUMELLE d'envoyerOrdreLan() : fusionne
   * l'ordre DEMANDÉ avec la mémoire des valeurs COMMANDÉES encore sous grâce (mécanisme
   * d'AC5), complète `power` via etatMarcheCourant() si absent (AC2 de la spec
   * fonctionnelle : une écriture inclut TOUJOURS l'état de marche courant), puis
   * appliquerOrdre() et pousse l'état OPTIMISTE.
   *
   * ⚠️ Contrairement à envoyerOrdreLan(), la fusion de grâce ne passe PAS par
   * valeursCommandees() (restreinte à smartclimFrame::conceptsEncodables(), LAN
   * UNIQUEMENT) : le seul concept en lecture seule de ce transport (`ambient_temp`)
   * n'est de toute façon jamais COMMANDÉ, donc jamais présent dans cette mémoire.
   *
   * Budget PARTAGÉ BUDGET_COMMANDE_AUXCLOUD (§ 4.1) : mesuré depuis l'entrée, propagé
   * en secondes RESTANTES à chaque étape — l'écriture est refusée (daté, propre) sous
   * RESERVE_ECRITURE_AUXCLOUD, jamais une requête tronquée.
   *
   * @param array $_ordreGenerique Map GÉNÉRIQUE concept => valeur générique.
   * @return array Ordre RÉELLEMENT appliqué (consigne après quantification).
   * @throws smartclimException Message DÉJÀ CURATÉ en français.
   */
  public function envoyerOrdreAuxCloud(array $_ordreGenerique) {
    $debut = microtime(true);
    // appareilAuxCloud() lève déjà une exception CURATÉE (§ 3.7) : appelée HORS du
    // try/catch ci-dessous pour ne jamais la faire retraduire par messageErreurAuxCloud().
    $appareil = $this->appareilAuxCloud(self::BUDGET_COMMANDE_AUXCLOUD);

    $grace = array();
    foreach ($this->memoireOrdres() as $concept => $entree) {
      $grace[$concept] = $entree['valeur'];
    }
    $ordre = array_merge($grace, $_ordreGenerique);

    // § 3.8/D8 de la spec technique : DEUXIÈME rejeu, INDÉPENDANT de celui de
    // smartclimAuxCloudApi (TYPE_AUTH, interne à la session) — CONTEXTE_APPAIRAGE_REFUSE
    // exige de RE-OBTENIR cookie/devSession, connaissance EXCLUSIVE de la 7ᵉ mémoire,
    // donc de CET orchestrateur. Booléen local, jamais de récursion, borné à UN rejeu.
    $rejoueAppairage = false;
    while (true) {
      try {
        if (!array_key_exists(smartclimCapabilities::CONCEPT_POWER, $ordre)) {
          $restant = self::BUDGET_COMMANDE_AUXCLOUD - (microtime(true) - $debut);
          $power = $this->etatMarcheCourant($appareil, $restant);
          if ($power === null) {
            // AC2 : le refus est le comportement VOULU (§ 3.3/D3 de la spec technique) —
            // un ordre non envoyé vaut mieux qu'un appareil éteint par erreur.
            throw new smartclimException(__('État de marche inconnu pour cet équipement — réessayez dans quelques instants', __FILE__), smartclimException::TYPE_INTERNE, smartclimAuxCloudApi::CONTEXTE_ETAT_INCONNU);
          }
          $ordre[smartclimCapabilities::CONCEPT_POWER] = $power;
        }

        $restant = self::BUDGET_COMMANDE_AUXCLOUD - (microtime(true) - $debut);
        if ($restant < self::RESERVE_ECRITURE_AUXCLOUD) {
          // § 4.1 de la spec technique : refus DATÉ et PROPRE, jamais une requête
          // tronquée — patron EXACT de smartclimBroadlinkLan::appliquerOrdre(). Correctif
          // post-review UC03 (point 2) : CONTEXTE_MESSAGE_DEJA_CURATE (marqueur LOCAL
          // « ne pas retraduire »), PAS CONTEXTE_ETAT_INCONNU — ce refus n'a AUCUN
          // rapport avec l'état de marche, réutiliser ce contexte-là mentirait sur son
          // sens à un futur lecteur.
          throw new smartclimException(__('Budget de temps insuffisant pour joindre le cloud historique — réessayez', __FILE__), smartclimException::TYPE_RESEAU, self::CONTEXTE_MESSAGE_DEJA_CURATE);
        }
        $applique = smartclimAuxCloudApi::appliquerOrdre($appareil, $ordre, $restant);
        break;
      } catch (smartclimException $e) {
        $restant = self::BUDGET_COMMANDE_AUXCLOUD - (microtime(true) - $debut);
        if (
          !$rejoueAppairage
          && $e->getType() === smartclimException::TYPE_PROTOCOLE
          && $e->getContexte() === smartclimAuxCloudApi::CONTEXTE_APPAIRAGE_REFUSE
          && $restant >= smartclimAuxCloudApi::BUDGET_REJEU_ORDRE
        ) {
          $rejoueAppairage = true;
          log::add('smartclim', 'info', 'AUX Cloud legacy : rejeu après ré-obtention des jetons d\'appairage (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '")');
          cache::delete(self::CLE_CACHE_APPAREIL_AUXCLOUD . $this->getId());
          // appareilAuxCloud() lève déjà une exception CURATÉE : elle sort donc DIRECTEMENT
          // de ce catch sans passer par la traduction ci-dessous.
          $appareil = $this->appareilAuxCloud($restant);
          continue;
        }
        log::add('smartclim', 'error', 'Commande AUX Cloud legacy échouée (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '", type ' . $e->getType() . ') : ' . self::neutraliserPourLog($e->getMessage()));
        // Correctif post-review UC03 (point 2) : DEUX contextes distincts partagent ce
        // garde-fou « ne pas retraduire » — CONTEXTE_ETAT_INCONNU garde son sens RÉEL
        // (§ 3.3/D3, état de marche inconnu) ET son message est déjà curaté ; le refus
        // de budget (§ 4.1) porte, LUI, CONTEXTE_MESSAGE_DEJA_CURATE, PAS
        // CONTEXTE_ETAT_INCONNU (qui mentirait sur son sens).
        if ($e->getContexte() === smartclimAuxCloudApi::CONTEXTE_ETAT_INCONNU || $e->getContexte() === self::CONTEXTE_MESSAGE_DEJA_CURATE) {
          throw new smartclimException($e->getMessage(), $e->getType(), $e->getContexte());
        }
        throw new smartclimException(self::messageErreurAuxCloud($e->getType(), $e->getContexte()), $e->getType());
      } catch (Throwable $t) {
        // catch(Throwable) EN DERNIER bloc (même motif qu'executerCommandeAction()) :
        // une Error PHP 8 traverserait sinon catch(smartclimException).
        log::add('smartclim', 'error', 'Commande AUX Cloud legacy échouée (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '") : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
        throw new smartclimException(__('Erreur interne lors de l\'envoi de la commande — consultez les logs du plugin', __FILE__), smartclimException::TYPE_INTERNE);
      }
    }

    $this->enregistrerOrdre($applique);
    // ÉTAT OPTIMISTE (AC3) : la valeur poussée est celle RÉELLEMENT envoyée (après
    // quantification), jamais celle demandée.
    $this->appliquerEtat($applique + array('source' => smartclimCapabilities::TRANSPORT_AUX_CLOUD_LEGACY), true);

    return $applique;
  }

  /**
   * Point d'entrée de la 5ᵉ CLI (§ 5.3/8 de la spec technique) — JUMEAU
   * d'envoyerCommandeActionLan() : revalide le `logicalId`, refuse CMD_RAFRAICHIR
   * (lecture, pas un ordre), passe par la MÊME ordreDeCommandeAction() que le chemin
   * cloud/LAN interactif — garantit que la surface de commandes legacy est identique
   * (AC8 de la spec fonctionnelle : un scénario écrit pour un autre transport
   * fonctionne à l'identique sur ce transport).
   *
   * @param string $_logicalId
   * @param array $_options
   * @return array Ordre RÉELLEMENT appliqué.
   * @throws smartclimException Message DÉJÀ CURATÉ en français.
   */
  public function envoyerCommandeActionAuxCloud($_logicalId, array $_options = array()) {
    $definitions = $this->definitionsCommandesAction();
    if (!isset($definitions[$_logicalId])) {
      throw new smartclimException(__('Commande inconnue pour cet équipement', __FILE__), smartclimException::TYPE_INTERNE);
    }
    if ($_logicalId === self::CMD_RAFRAICHIR) {
      // Littéral EXISTANT (envoyerCommandeActionLan()) : « Rafraîchir » existe bel et
      // bien dans definitionsCommandesAction(), « Commande inconnue » serait FAUX ici.
      throw new smartclimException(__('La commande « Rafraîchir » ne s\'envoie pas à l\'appareil', __FILE__), smartclimException::TYPE_INTERNE);
    }
    $ordre = $this->ordreDeCommandeAction($_logicalId, $_options);
    return $this->envoyerOrdreAuxCloud($ordre);
  }

  /**
   * Bouton « Rafraîchir » d'un équipement piloté par le cloud historique (UC03 du
   * domaine post-mvp/03-cloud-aux-legacy, § 5.3 de sa spec technique) — chemin
   * INTERACTIF : LÈVE un échec (jamais silencieux), contrairement à
   * rafraichirAuxCloud() (cycle, ne lève jamais). Ne pousse PAS `online` (le cloud
   * historique ne l'apprend qu'au SCAN, via l'état groupé PAR FAMILLE — § 3.2 d'UC02) :
   * c'est une LECTURE D'ÉTAT SEULE des paramètres.
   *
   * @throws smartclimException Message DÉJÀ CURATÉ en français.
   */
  public function rafraichirAuxCloudEquipement() {
    // appareilAuxCloud() lève déjà une exception CURATÉE (§ 3.7) : appelée HORS du
    // try/catch ci-dessous, même motif qu'envoyerOrdreAuxCloud().
    $appareil = $this->appareilAuxCloud(self::BUDGET_ETAT_AUXCLOUD);
    try {
      // Correctif post-review UC03 (point 1) : REJEU D'APPAIRAGE — un jeton présent en
      // cache mais rejeté par le backend échouerait sinon À L'IDENTIQUE pendant toute
      // la TTL de la 7ᵉ mémoire (30 min), sans aucun rattrapage sur ce chemin
      // INTERACTIF.
      $valeurs = $this->lireParametresAvecRejeuAppairage($appareil, self::BUDGET_ETAT_AUXCLOUD);
    } catch (smartclimException $e) {
      log::add('smartclim', 'error', 'Rafraîchissement AUX Cloud legacy échoué (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '", type ' . $e->getType() . ') : ' . self::neutraliserPourLog($e->getMessage()));
      throw new smartclimException(self::messageErreurAuxCloud($e->getType(), $e->getContexte()), $e->getType());
    } catch (Throwable $t) {
      log::add('smartclim', 'error', 'Rafraîchissement AUX Cloud legacy échoué (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '") : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
      throw new smartclimException(__('Erreur interne lors de l\'envoi de la commande — consultez les logs du plugin', __FILE__), smartclimException::TYPE_INTERNE);
    }
    $appareil['valeurs'] = $valeurs;
    $this->appliquerEtat(smartclimAuxCloudApi::etatAppareil($appareil));
  }

  /**
   * Diagnostic BRUT du transport AUX Cloud legacy (UC03, § 5.3/8 de la spec
   * technique) — surface de la 5ᵉ CLI (`--etat`), MÊME PATRON que diagnosticTransport()
   * / sonderIntentAuxHome() : une méthode publique plutôt que l'ouverture de 5
   * accesseurs privés. ⚠️ Rend la lecture BRUTE : jamais la mémoire d'ordres ni le
   * marqueur de déduplication, sans quoi l'instrument confirmerait ce qu'on vient
   * d'envoyer au lieu de ce que l'appareil a réellement fait.
   *
   * ⚠️ Correctif post-review UC03 (point 1) : appel DIRECT à
   * smartclimAuxCloudApi::lireParametres(), DÉLIBÉRÉMENT SANS
   * lireParametresAvecRejeuAppairage() — c'est un INSTRUMENT DE MESURE, il doit rendre
   * le comportement BRUT du backend ; un rejeu automatique masquerait précisément le
   * refus d'appairage qu'on cherche à observer.
   *
   * @return array{valeurs:array, etat:array}
   * @throws smartclimException Message DÉJÀ CURATÉ en français.
   */
  public function diagnosticAuxCloud() {
    $appareil = $this->appareilAuxCloud(self::BUDGET_ETAT_AUXCLOUD);
    try {
      $valeurs = smartclimAuxCloudApi::lireParametres($appareil, self::BUDGET_ETAT_AUXCLOUD);
    } catch (smartclimException $e) {
      throw new smartclimException(self::messageErreurAuxCloud($e->getType(), $e->getContexte()), $e->getType());
    }
    return array(
      'valeurs' => $valeurs,
      'etat' => smartclimAuxCloudApi::etatAppareil($appareil + array('valeurs' => $valeurs)),
    );
  }

  /**
   * Sonde d'UN paramètre BRUT (UC03, § 5.3/8 de la spec technique) — surface de la 5ᵉ
   * CLI (`--parametre`). Forme de `$_cle` validée DEUX FOIS (script + ici, dans
   * smartclimAuxCloudApi::sonderParametre()) : défense en profondeur.
   *
   * ⚠️ Correctif post-review UC03 (point 1) : DÉLIBÉRÉMENT SANS rejeu d'appairage,
   * même motif que diagnosticAuxCloud() — un instrument de mesure doit rendre le
   * comportement BRUT du backend, pas le masquer derrière une ré-obtention automatique.
   *
   * @param string $_cle
   * @param int $_valeur
   * @throws smartclimException Message DÉJÀ CURATÉ en français.
   */
  public function sonderParametreAuxCloud($_cle, $_valeur) {
    $appareil = $this->appareilAuxCloud(self::BUDGET_ETAT_AUXCLOUD);
    try {
      smartclimAuxCloudApi::sonderParametre($appareil, $_cle, $_valeur, self::BUDGET_ETAT_AUXCLOUD);
    } catch (smartclimException $e) {
      throw new smartclimException(self::messageErreurAuxCloud($e->getType(), $e->getContexte()), $e->getType());
    }
  }

  /**
   * Surface de la 5ᵉ CLI (`--special`, § 1.7/8/9 R2 de la spec technique) : émet le
   * SECOND `get` conditionné (`params: ["mode"]`), NON émis sur le chemin périodique —
   * seul moyen de trancher FACTUELLEMENT si le backend complète `envtemp` sur certains
   * modèles (point à valider en recette n° 3 de la spec technique).
   *
   * ⚠️ Correctif post-review UC03 (point 1) : DÉLIBÉRÉMENT SANS rejeu d'appairage,
   * même motif que diagnosticAuxCloud()/sonderParametreAuxCloud() — instrument de
   * mesure, comportement BRUT du backend.
   *
   * @return array<string, mixed> nom brut => valeur brute.
   * @throws smartclimException Message DÉJÀ CURATÉ en français.
   */
  public function sonderSpecialAuxCloud() {
    $appareil = $this->appareilAuxCloud(self::BUDGET_ETAT_AUXCLOUD);
    try {
      return smartclimAuxCloudApi::lireParametreSpecial($appareil, self::BUDGET_ETAT_AUXCLOUD);
    } catch (smartclimException $e) {
      throw new smartclimException(self::messageErreurAuxCloud($e->getType(), $e->getContexte()), $e->getType());
    }
  }

  /**
   * Prédicat PUR (UC03 du domaine post-mvp/05-temps-reel-et-demon, § 5.4 de sa spec
   * technique) : le push temps réel est-il CONFIRMÉ actif pour CET équipement ? Reçoit
   * le battement en PARAMÈTRE (jamais une relecture de cache ici — l'appelant l'a déjà
   * lu UNE FOIS, cf. rafraichirAuxCloud()). Vrai si et seulement si : le battement existe
   * et n'est pas trop vieux (FRAICHEUR_RELAIS), son état vaut 'connecte', ET
   * l'endpointId de cet équipement figure dans la liste des endpoints CONFIRMÉS (§ 1.3 :
   * la preuve qu'un `sub` a effectivement produit un push pour LUI, pas seulement pour
   * le compte).
   *
   * @param array|null $_battement Renvoyé par smartclimDemon::battementRelais().
   * @return bool
   */
  public function pushAuxCloudActif(array $_battement = null) {
    if ($_battement === null) {
      return false;
    }
    if (!isset($_battement['ts']) || !is_numeric($_battement['ts'])) {
      return false;
    }
    $age = time() - (int) $_battement['ts'];
    if ($age < 0 || $age >= smartclimDemon::FRAICHEUR_RELAIS) {
      return false;
    }
    if (!isset($_battement['etat']) || $_battement['etat'] !== 'connecte') {
      return false;
    }
    $endpointId = $this->getConfiguration(self::CLE_CONF_AUXCLOUD_ENDPOINT_ID);
    if (!is_string($endpointId) || $endpointId === '') {
      return false;
    }
    $confirmes = isset($_battement['confirmes']) && is_array($_battement['confirmes']) ? $_battement['confirmes'] : array();
    return in_array($endpointId, $confirmes, true);
  }

  /**
   * Libellé d'état du temps réel AUX Cloud legacy pour CET équipement, prêt à
   * l'affichage (UC03 de ce domaine, § 5.4/7 de sa spec technique) — consommé par
   * etatConnexionAffichable(). Chaîne VIDE si le transport legacy ne s'applique pas à cet
   * équipement ou si le battement est absent/périmé (rien à afficher, même convention
   * que 'lan'/'repli').
   *
   * @return string
   */
  private function libelleTempsReelAuxCloud() {
    if (!smartclimTransport::lectureLegacyAutorisee($this)) {
      return '';
    }
    $battement = smartclimDemon::battementRelais();
    if ($battement === null) {
      return '';
    }
    $age = time() - $battement['ts'];
    if ($age < 0 || $age >= smartclimDemon::FRAICHEUR_RELAIS) {
      return '';
    }
    if ($battement['etat'] === 'refus') {
      return __('Connexion au relais perdue', __FILE__);
    }
    if ($battement['etat'] === 'reconnexion') {
      return __('Reconnexion au relais en cours', __FILE__);
    }
    // 'connecte' : distingue « abonnement confirmé pour CET endpoint » de « session
    // établie mais aucun push reçu pour lui pour l'instant » (§ 1.3 de la spec
    // technique).
    return $this->pushAuxCloudActif($battement)
      ? __('Temps réel actif', __FILE__)
      : __('Temps réel en attente de confirmation', __FILE__);
  }

  /**
   * Diagnostic LECTURE SEULE du mécanisme de repli (UC02 du domaine
   * post-mvp/02-strategies-de-transport, § 9 de sa spec technique) — instrument de
   * CONSTAT de core/php/commande-lan.php --transport, AUCUN paquet réseau émis : c'est
   * un rapport de lecture d'état INTERNE (cache), pas une sonde. Valeurs BRUTES/déjà
   * traduites, aucun secret, prêtes à l'affichage (convention FR sans __() des CLI de ce
   * plugin, sauf pour les libellés déjà traduits par ailleurs).
   *
   * @return array{nom:string, modeConfigure:string, transportRetenu:string,
   *   lanJoignable:bool, echecsLan:int, ageDernierePreuve:int|null, repliActif:bool,
   *   attenteCloud:int}
   */
  public function diagnosticTransport() {
    $memoire = $this->memoireEchecsTransport();
    return array(
      // Défense en profondeur (sortie terminal, CLI --transport) : cohérent avec le reste
      // du fichier, un nom d'équipement peut provenir d'une découverte LAN non authentifiée.
      'nom' => self::neutraliserPourLog($this->getHumanName()),
      'modeConfigure' => smartclimTransport::libelleMode($this),
      'transportRetenu' => smartclimCapabilities::libelleTransport(smartclimTransport::transportRetenu($this)),
      'lanJoignable' => smartclimTransport::lanJoignable($this),
      'echecsLan' => $this->echecsLanConsecutifs(),
      'ageDernierePreuve' => ($memoire['preuve'] > 0) ? max(0, time() - $memoire['preuve']) : null,
      'repliActif' => smartclimTransport::repliCloudActif($this),
      'attenteCloud' => $this->attenteCloudRestante(),
    );
  }

  /**
   * Diagnostic LECTURE SEULE de la redécouverte par diffusion (UC03 de ce domaine, § 9
   * de sa spec technique) — instrument de CONSTAT de core/php/commande-lan.php
   * --redecouvrir, MÊME PATRON que diagnosticTransport(), lireTrameAuxHome() et
   * sonderIntentAuxHome() : UNE méthode publique plutôt que l'ouverture de six
   * accesseurs privés (macsCandidatesLan(), decouverteParMac()…).
   *
   * ⚠️ Émet UNE diffusion (contrairement à diagnosticTransport(), qui n'émet AUCUN
   * paquet réseau) : c'est le SEUL moyen de vérifier réellement la redécouverte sur une
   * installation sans matériel climatiseur Broadlink (deux RM4 Pro y répondent, cf.
   * brief.md § 19). Aucune écriture en cache/base/disque : les verdicts sont calculés en
   * LECTURE SEULE, la mémoire de sonde et la 6ᵉ mémoire ne sont jamais touchées.
   *
   * @return array{diffusion:bool, decouverts:int, lignes:array}
   */
  public static function diagnosticRedecouverteLan() {
    $decouverts = self::decouverteParMac(smartclimBroadlinkLan::FENETRE_DECOUVERTE);
    $adoptes = array();
    $lignes = array();
    foreach (eqLogic::byType('smartclim', true) as $eqLogic) {
      if (!($eqLogic instanceof smartclim)) {
        continue;
      }
      if (smartclimTransport::mode($eqLogic) === smartclimTransport::MODE_CLOUD) {
        continue;
      }
      $adresse = $eqLogic->adresseLan();
      $candidats = $eqLogic->macsCandidatesLan();

      $macTrouvee = '';
      foreach ($candidats as $mac) {
        if (isset($decouverts[$mac])) {
          $macTrouvee = $mac;
          break;
        }
      }

      $verdict = 'aucune_correspondance';
      $ipTrouvee = '';
      if ($macTrouvee !== '') {
        $macTrouveeInversee = self::macInversee($macTrouvee);
        if ($macTrouveeInversee !== '' && $macTrouveeInversee !== $macTrouvee && isset($decouverts[$macTrouveeInversee])) {
          // Même garde d'ambiguïté (A3) qu'appareilRedecouvert() — mais elle ne peut
          // pas être réutilisée telle quelle : elle ne distingue pas, dans son 'null'
          // de retour, l'ambiguïté du doublon ou de l'absence de correspondance, alors
          // que ce rapport DOIT les distinguer.
          $verdict = 'ambigu';
        } elseif (isset($adoptes[$macTrouvee])) {
          $verdict = 'deja_adopte';
        } else {
          $adoptes[$macTrouvee] = true;
          $ipTrouvee = $decouverts[$macTrouvee]['ip'];
          $verdict = ($adresse['ip'] !== '' && $ipTrouvee === $adresse['ip']) ? 'identique' : 'changement';
        }
      }

      $lignes[] = array(
        'nom' => self::neutraliserPourLog($eqLogic->getHumanName()),
        'mode' => smartclimTransport::libelleMode($eqLogic),
        'macsCandidates' => $candidats,
        'adresseConnue' => $adresse['ip'],
        'adresseSource' => $adresse['source'],
        'macTrouvee' => $macTrouvee,
        'ipTrouvee' => $ipTrouvee,
        'verdict' => $verdict,
      );
    }

    return array(
      // Diffusible sur cet hôte (extension sockets/flux natif) — decouverteParMac()
      // ne lève jamais, donc AUCUN autre moyen de distinguer "hôte incapable" de
      // "réseau filtré, 0 appareil" à partir de son seul résultat.
      'diffusion' => smartclimBroadlinkLan::diffusionDisponible(),
      'decouverts' => count($decouverts),
      'lignes' => $lignes,
    );
  }

  /**
   * Instrument de MESURE (UC01 du domaine post-mvp/04-fonctions-avancees, § 5.5 de sa
   * spec technique), CLI UNIQUEMENT — garde INTERNE, au plus près du risque (même
   * patron que smartclimAuxHomeApi::sonderIntent()). Un SEUL listerAppareils(),
   * appariement sur configuration.auxhome_device_id.
   *
   * ⚠️ Ne renvoie JAMAIS l'identifiant cloud, la MAC ni le passcode — seulement la
   * trame de contrôle et l'état décodé.
   *
   * @return array{trame:string, etat:array}
   * @throws smartclimException Message CURATÉ (compte non configuré, équipement non
   *   relié à un appareil AUX Home, appareil absent de la réponse).
   */
  public function lireTrameAuxHome() {
    if (php_sapi_name() !== 'cli') {
      throw new smartclimException(__('Instrument de mesure réservé à la ligne de commande', __FILE__), smartclimException::TYPE_INTERNE);
    }
    if (!self::compteConfigure()) {
      throw new smartclimException(__('Compte AUX Home non configuré : renseignez l\'e-mail et le mot de passe', __FILE__), smartclimException::TYPE_AUTH);
    }
    $identifiantAppareil = $this->getConfiguration('auxhome_device_id');
    if (!is_string($identifiantAppareil) || $identifiantAppareil === '') {
      throw new smartclimException(__('Cet équipement n\'est pas relié à un appareil AUX Home — relancez un scan', __FILE__), smartclimException::TYPE_INTERNE);
    }
    try {
      $appareils = smartclimAuxHomeApi::listerAppareils();
    } catch (smartclimException $e) {
      throw new smartclimException(self::messageErreurAuxHome($e->getType(), $e->getContexte()), $e->getType());
    }
    foreach ($appareils as $appareil) {
      if ($appareil['identifiant'] === $identifiantAppareil) {
        return array(
          'trame' => $appareil['trame_controle'],
          'etat' => smartclimFrame::decoderEtat(smartclimCapabilities::TRANSPORT_AUX_HOME, $appareil['trame_controle'], $appareil['trame_running']),
        );
      }
    }
    throw new smartclimException(__('Appareil absent de la réponse AUX Home — relancez un scan', __FILE__), smartclimException::TYPE_PROTOCOLE);
  }

  /**
   * Instrument de MESURE PRINCIPAL de l'UC (§ 5.5 de la spec technique) : lit → écrit
   * (si $_ordre n'est pas vide) → attend → relit. CLI UNIQUEMENT, garde INTERNE.
   *
   * ⚠️ N'ÉCRIT NI la mémoire d'ordres, NI le marqueur de déduplication, NI AUCUNE
   * commande : c'est un INSTRUMENT DE MESURE, il doit rendre la lecture BRUTE. Écrire
   * la mémoire d'ordres ferait filtrer la relecture par filtrerEtatSelonOrdres() et la
   * mesure confirmerait ce qu'on vient d'envoyer au lieu de ce que l'appareil a
   * réellement fait (risque R3 de la spec technique).
   *
   * @param array $_ordre Map GÉNÉRIQUE concept => valeur générique (mode normal), OU
   *   clé AUX brute => entier si $_brut vaut true (échappatoire d'investigation, §
   *   5.6 : essayer une clé non déclarée, ou chercher un bit "eco"). Vide = lecture
   *   seule.
   * @param int $_attente Secondes d'attente entre écriture et relecture, bornées à [0, 180].
   * @param bool $_brut true : passe par smartclimAuxHomeApi::sonderIntent() (clé AUX
   *   brute, non traduite) ; false (défaut) : passe par appliquerOrdre() (chemin
   *   normal du plugin, concept générique).
   * @return array{avant:string, apres:string, etat_avant:array, etat_apres:array, ecrit:bool}
   * @throws smartclimException Message CURATÉ.
   */
  public function sonderIntentAuxHome(array $_ordre, $_attente = 15, $_brut = false) {
    if (php_sapi_name() !== 'cli') {
      throw new smartclimException(__('Instrument de mesure réservé à la ligne de commande', __FILE__), smartclimException::TYPE_INTERNE);
    }
    $attente = (int) min(180, max(0, $_attente));

    $avant = $this->lireTrameAuxHome();

    $ecrit = false;
    if (!empty($_ordre)) {
      $identifiantAppareil = $this->getConfiguration('auxhome_device_id');
      try {
        if ($_brut) {
          smartclimAuxHomeApi::sonderIntent($identifiantAppareil, $_ordre);
        } else {
          smartclimAuxHomeApi::appliquerOrdre($identifiantAppareil, $_ordre);
        }
        $ecrit = true;
      } catch (smartclimException $e) {
        throw new smartclimException(self::messageErreurAuxHome($e->getType(), $e->getContexte()), $e->getType());
      }
      if ($attente > 0) {
        // Instrument de mesure MANUEL (§ 9/R12 de la spec technique) : jamais de
        // boucle automatique autour de cet appel, un usage humain unique par mesure.
        sleep($attente);
      }
    }

    $apres = $this->lireTrameAuxHome();

    return array(
      'avant' => $avant['trame'],
      'apres' => $apres['trame'],
      'etat_avant' => $avant['etat'],
      'etat_apres' => $apres['etat'],
      'ecrit' => $ecrit,
    );
  }

  /**
   * Mémoire des valeurs COMMANDÉES, restreinte aux concepts que le transport LOCAL sait
   * ÉCRIRE (UC03 de ce domaine, § 6.2 de sa spec technique — mécanisme d'AC5).
   *
   * ⚠️ memoireOrdres() rend concept => array('valeur' => …, 'ts' => …) et a DÉJÀ purgé
   * les concepts hors grâce (self::DUREE_GRACE) : ici, EXTRACTION SEULE, AUCUN
   * refiltrage temporel — ce serait dupliquer la règle des 60 s à un second endroit.
   * `array_column($memoire, 'valeur')` N'EST PAS une alternative valide : elle PERD les
   * clés de concept (tableau indexé, la clé de concept n'étant pas une colonne du
   * sous-tableau) — la boucle foreach explicite est OBLIGATOIRE.
   *
   * Ne retient que smartclimFrame::conceptsEncodables() (LISTE BLANCHE) : cette liste
   * s'élargit MÉCANIQUEMENT avec smartclimFrame::champsEcriture(), et depuis l'UC02 du
   * domaine post-mvp/04-fonctions-avancees, swing_v/swing_h y figurent et DOIVENT y
   * figurer — le cas est désormais COUVERT, y compris avant recette : ces deux concepts
   * sont encodables même à 'confirme' => false (champsEcriture() n'est PAS filtrée par
   * ce marqueur), ce qui rend le déploiement transitoire sûr dans les deux sens. Ne
   * retenir qu'un concept réellement ABSENT de conceptsEncodables() ferait sinon lever
   * TYPE_INTERNE à smartclimFrame::encoderOrdre() et casserait TOUTES les commandes LAN
   * pendant 60 s — l'inverse exact de ce que ce mécanisme est là pour faire.
   *
   * @return array<string, mixed> concept => valeur GÉNÉRIQUE scalaire.
   */
  private function valeursCommandees() {
    $memoire = $this->memoireOrdres();
    $encodables = smartclimFrame::conceptsEncodables();
    $valeurs = array();
    foreach ($memoire as $concept => $entree) {
      if (!in_array($concept, $encodables, true)) {
        continue;
      }
      $valeurs[$concept] = $entree['valeur'];
    }
    return $valeurs;
  }

  /**
   * Sonde l'adresse LAN CONNUE de cet équipement (UC03 de ce domaine, § 4.2/5.3 de sa
   * spec technique) : hello préalable OBLIGATOIRE, seule source de 'octets_mac' et de
   * 'type_appareil' qu'exige smartclimBroadlinkLan::appliquerOrdre() (ni adresseLan()
   * ni la mémoire de sonde ne les portent).
   *
   * MODIFIÉE par l'UC03 du domaine post-mvp/02-strategies-de-transport (§ 4.3 de sa
   * spec technique) : le chemin NOMINAL (l'appareil répond, MAC concordante) reste
   * STRICTEMENT inchangé — zéro paquet, zéro lecture de cache supplémentaire. Sur
   * échec de la sonde unicast (silence OU MAC divergente), une redécouverte UNIQUE par
   * diffusion est tentée AVANT de conclure : si elle retrouve l'appareil, sa nouvelle
   * adresse est mémorisée (memoriserAdresseLan(), AC2) et RENVOYÉE — sinon
   * l'EXCEPTION D'ORIGINE (mêmes message, type, contexte) est relevée telle quelle,
   * messageErreurLan() n'étant PAS touchée (aucune chaîne nouvelle).
   *
   * @param float $_budget
   * @return array Ligne normalisée (smartclimBroadlinkLan::decouvrir()/interroger()).
   * @throws smartclimException Message TECHNIQUE + contexte CONTEXTE_LAN_* dédié — la
   *   curation finale vit dans messageErreurLan(), SEUL point de bascule (§ 4.3).
   */
  private function sonderAppareilLan($_budget) {
    $debut = microtime(true);
    $adresse = $this->adresseLan();
    if ($adresse['ip'] === '') {
      // Chemin INCHANGÉ (§ 2.2, règle générale) : un équipement sans adresse connue
      // n'est pas "injoignable", il est "jamais découvert" — le scan reste son vecteur.
      throw new smartclimException('Broadlink LAN : aucune adresse connue pour cet équipement', smartclimException::TYPE_RESEAU, self::CONTEXTE_LAN_ADRESSE_INCONNUE);
    }

    $appareil = smartclimBroadlinkLan::interroger($adresse['ip'], max(1, min(smartclimBroadlinkLan::TIMEOUT_ECHANGE, $_budget)));
    if ($appareil !== null) {
      $macAttendue = $adresse['mac'];
      if ($macAttendue === '' || $appareil['mac'] === $macAttendue || $appareil['mac'] === self::macInversee($macAttendue)) {
        // Chemin NOMINAL STRICTEMENT inchangé (§ 0.1) : aucune branche nouvelle n'est
        // exécutée ici.
        return $appareil;
      }
      $messageOrigine = 'Broadlink LAN : MAC de l\'appareil répondant différente de celle attendue';
      $contexteOrigine = self::CONTEXTE_LAN_MAC_DIVERGENTE;
    } else {
      $messageOrigine = 'Broadlink LAN : aucune réponse de l\'appareil sur le réseau local';
      $contexteOrigine = '';
    }

    // UC03 (§ 4.3, étape 4) : fenêtre de redécouverte bornée par le budget RESTANT de
    // cet ordre interactif, réserve RESERVE_APRES_REDECOUVERTE_LAN déduite. Sous 1 s,
    // AUCUNE diffusion : l'exception d'origine part immédiatement (R6).
    $restant = $_budget - (microtime(true) - $debut);
    $fenetre = min(self::BUDGET_REDECOUVERTE_LAN, $restant - self::RESERVE_APRES_REDECOUVERTE_LAN);
    if ($fenetre >= 1) {
      $parMac = self::decouverteParMac((int) $fenetre);
      $adoptes = array();
      $trouve = self::appareilRedecouvert($this, $parMac, $adoptes);
      if ($trouve !== null) {
        self::memoriserAdresseLan($this, $trouve);
        if ($trouve['ip'] !== $adresse['ip']) {
          // Journalisation § 7 : condition UNIQUE, adresse RETROUVÉE différente de
          // l'adresse enregistrée. Une adresse SAISIE reste prioritaire (AC5) mais un
          // avertissement actionnable invite à mettre la configuration à jour.
          if ($adresse['source'] === 'manuel') {
            log::add('smartclim', 'warning', 'Équipement "' . self::neutraliserPourLog($this->getHumanName()) . '" : l\'adresse IP locale SAISIE (' . $adresse['ip'] . ') ne répond plus, appareil retrouvé en ' . $trouve['ip'] . ' — mettez à jour la configuration de l\'équipement (l\'adresse saisie reste prioritaire)');
          } else {
            log::add('smartclim', 'info', 'Équipement "' . self::neutraliserPourLog($this->getHumanName()) . '" : adresse IP locale mise à jour automatiquement (' . $adresse['ip'] . ' -> ' . $trouve['ip'] . ')');
          }
        }
        return $trouve;
      }
      log::add('smartclim', 'debug', 'Redécouverte LAN : aucune correspondance pour l\'équipement "' . self::neutraliserPourLog($this->getHumanName()) . '"');
    } else {
      log::add('smartclim', 'debug', 'Redécouverte LAN : budget insuffisant pour l\'équipement "' . self::neutraliserPourLog($this->getHumanName()) . '"');
    }

    // Échec confirmé : l'exception D'ORIGINE, mêmes message/type/contexte (§ 4.3,
    // étape 5) — le compteur d'échecs reste écrit par les catch() des APPELANTS.
    throw new smartclimException($messageOrigine, smartclimException::TYPE_RESEAU, $contexteOrigine);
  }

  /**
   * Valeur de consigne EFFECTIVE (UC06, § 5.3/10 de la spec technique) : lit
   * $_options['slider'], rejette si non numérique ou hors bornes (bornesTemperature(),
   * UC04), puis quantifie sur la grille de bornesTemperature()['pas'] ancrée sur le
   * minimum — c'est le pas AFFICHÉ au curseur (AC4), pas le pas d'écriture du
   * transport. Le second arrondi, celui de smartclimCapabilities::echelleTemperature(),
   * est appliqué par appliquerOrdre() et reste SEUL autoritaire sur la valeur
   * réellement envoyée puis poussée en état optimiste.
   *
   * @param array $_options
   * @return float
   * @throws smartclimException Message DÉJÀ CURATÉ en français.
   */
  private function ordreEffectifConsigne(array $_options) {
    if (!isset($_options['slider']) || !is_scalar($_options['slider']) || !is_numeric($_options['slider'])) {
      throw new smartclimException(__('Valeur de consigne absente ou non numérique', __FILE__), smartclimException::TYPE_INTERNE);
    }
    $valeur = (float) $_options['slider'];
    $bornes = $this->bornesTemperature();
    if ($valeur < $bornes['min'] || $valeur > $bornes['max']) {
      throw new smartclimException(sprintf(__('Consigne hors des bornes de l\'équipement (%1$s à %2$s °C)', __FILE__), $bornes['min'], $bornes['max']), smartclimException::TYPE_INTERNE);
    }
    $pas = ($bornes['pas'] > 0) ? $bornes['pas'] : smartclimCapabilities::TEMP_PAS_DEFAUT;
    return $bornes['min'] + round(($valeur - $bornes['min']) / $pas) * $pas;
  }

  /**
   * Mémoire des valeurs COMMANDÉES (dette D-MVP05-07, § 9 de la spec technique) : lit
   * le cache et purge les concepts EXPIRÉS (TTL individuel par concept, indépendant du
   * TTL de l'entrée de cache elle-même). JSON NON chiffré : aucun secret, un mode de
   * climatisation n'est pas une donnée sensible.
   *
   * @return array<string, array{valeur:mixed, ts:int}>
   */
  private function memoireOrdres() {
    $brut = cache::byKey(self::CLE_CACHE_ORDRES . $this->getId())->getValue(null);
    if (!is_string($brut) || $brut === '') {
      return array();
    }
    $memoire = json_decode($brut, true);
    if (!is_array($memoire)) {
      return array();
    }
    $maintenant = time();
    $valide = array();
    foreach ($memoire as $concept => $entree) {
      if (!is_array($entree) || !array_key_exists('valeur', $entree) || !isset($entree['ts']) || !is_numeric($entree['ts'])) {
        continue;
      }
      if (($maintenant - (int) $entree['ts']) > self::DUREE_GRACE) {
        continue;
      }
      $valide[$concept] = $entree;
    }
    return $valide;
  }

  /**
   * Fusionne l'ordre RÉELLEMENT appliqué (renvoyé par
   * smartclimAuxHomeApi::appliquerOrdre()) dans la mémoire des valeurs commandées (§ 9
   * de la spec technique). Relit l'entrée, purge les concepts expirés (via
   * memoireOrdres()), écrit/écrase les concepts commandés, réécrit l'entrée : un
   * nouvel ordre n'efface donc jamais la mémoire d'un autre concept encore sous grâce.
   *
   * @param array $_ordre Map générique EFFECTIVEMENT envoyée.
   */
  private function enregistrerOrdre(array $_ordre) {
    $memoire = $this->memoireOrdres();
    $maintenant = time();
    foreach ($_ordre as $concept => $valeur) {
      $memoire[$concept] = array('valeur' => $valeur, 'ts' => $maintenant);
    }
    cache::set(self::CLE_CACHE_ORDRES . $this->getId(), json_encode($memoire), self::DUREE_GRACE);
  }

  /**
   * Filtre un état scruté selon la mémoire des valeurs commandées (§ 9 de la spec
   * technique — anti-rollback) : pour chaque concept mémorisé et non expiré, valeur
   * mémorisée ÉGALE à la valeur scrutée -> le cloud a confirmé, le concept est retiré
   * de la mémoire (fin de grâce anticipée) ; valeur DIFFÉRENTE -> la clé est retirée de
   * $_etat (commande info non touchée, valueDate intact) + log debug.
   *
   * @param array $_etat
   * @return array
   */
  private function filtrerEtatSelonOrdres(array $_etat) {
    $memoire = $this->memoireOrdres();
    if (empty($memoire)) {
      return $_etat;
    }
    $modifie = false;
    foreach ($memoire as $concept => $entree) {
      if (!array_key_exists($concept, $_etat)) {
        continue;
      }
      if (self::memeValeur($entree['valeur'], $_etat[$concept])) {
        unset($memoire[$concept]);
        $modifie = true;
      } else {
        log::add('smartclim', 'debug', 'Équipement "' . self::neutraliserPourLog($this->getHumanName()) . '" : valeur commandée ' . self::neutraliserPourLog((string) $entree['valeur']) . ', valeur relue ' . self::neutraliserPourLog((string) $_etat[$concept]) . ', période de grâce');
        unset($_etat[$concept]);
      }
    }
    if ($modifie) {
      if (empty($memoire)) {
        cache::delete(self::CLE_CACHE_ORDRES . $this->getId());
      } else {
        cache::set(self::CLE_CACHE_ORDRES . $this->getId(), json_encode($memoire), self::DUREE_GRACE);
      }
    }
    return $_etat;
  }

  /**
   * Comparaison GÉNÉRIQUE (grâce/optimiste, § 9 de la spec technique) : numérique à
   * 0,01 près si les deux valeurs sont numériques, sinon comparaison de chaînes.
   * Tolérance DISTINCTE de bornesEgales() ci-dessus (0,001, dédiée à R6).
   *
   * @return bool
   */
  private static function memeValeur($_a, $_b) {
    if (is_numeric($_a) && is_numeric($_b)) {
      return abs((float) $_a - (float) $_b) < 0.01;
    }
    return (string) $_a === (string) $_b;
  }

  /**
   * Applique un état NORMALISÉ (clés = codes de concept, cf.
   * smartclimAuxHomeApi::etatAppareil()) aux commandes info : garantit d'abord
   * l'existence des commandes (creerCommandesInfo()), puis pousse les seules clés
   * PRÉSENTES via checkAndUpdateCmd(). Une clé absente laisse la commande — et son
   * valueDate — intacte (mécanisme d'AC10 : « valeur non confirmable = commande non
   * touchée »). SURFACE D'APPEL d'UC06 (état optimiste : un état partiel est un cas
   * nominal) et d'UC07 (cron).
   *
   * AC6 : l'horodatage 'last_update' ne bouge QUE si au moins un checkAndUpdateCmd() de
   * CONCEPT (les 6 de smartclimCapabilities::conceptsConnus(), hors les 2 commandes
   * méta) a renvoyé true — contrat vérifié du core : checkAndUpdateCmd() renvoie true
   * SI ET SEULEMENT SI il a émis un event(). Deux cycles sans changement laissent donc
   * 'last_update' et son valueDate figés : l'utilisateur lit l'âge RÉEL de la donnée.
   *
   * ⚠️ Limite connue et assumée : le contrat du core est event() émis si
   * execCmd() !== formatValue($value) OU si repeatEventManagement == 'always'. Un
   * utilisateur qui règle une commande info sur « toujours notifier » fera repartir
   * 'last_update' à chaque cycle, même sans changement réel — comportement Jeedom natif,
   * hors du contrôle du plugin (le contourner écraserait un réglage utilisateur, ce
   * qu'AC7 interdit).
   *
   * Il n'existe AUCUN horodatage fourni par le cloud dans /app/user_device :
   * 'last_update' est donc, par construction, la date à laquelle LE PLUGIN a constaté un
   * changement — une borne INFÉRIEURE de la fraîcheur, jamais l'instant réel du
   * changement sur l'appareil (cohérent avec l'avertissement d'AC11).
   *
   * @param array $_etat Renvoyé par smartclimAuxHomeApi::etatAppareil() (ou un état
   *   PARTIEL construit par UC06).
   * @param bool $_optimiste UC06, § 4.1/9 de la spec technique. true (juste après un
   *   ordre réussi) : AUCUN filtrage de grâce (on ne filtre pas son propre ordre) et
   *   AUCUNE recréation des commandes action (celle qu'on vient d'exécuter existe déjà
   *   forcément). false (défaut : UC05, et UC07 par héritage) : filtrage de grâce
   *   (filtrerEtatSelonOrdres()) et recréation des commandes action manquantes.
   * @return bool true si au moins une valeur de CONCEPT a changé.
   */
  public function appliquerEtat(array $_etat, $_optimiste = false) {
    if (!$_optimiste) {
      $_etat = $this->filtrerEtatSelonOrdres($_etat);
    }

    $this->creerCommandesInfo();
    if (!$_optimiste) {
      // Cf. § 4.1 de la spec technique : creerCommandesAction() DOIT être appelée ici
      // ET dans postSave() — postSave() seul ne suffit pas sur un équipement déjà
      // scanné et inchangé (aucun save() -> aucun postSave()), panne silencieuse sans
      // ce second point d'appel.
      $this->creerCommandesAction();
    }

    $change = false;
    // conceptsConnus() = les 6 concepts du modèle générique, 'online' INCLUS : une
    // bascule en ligne / hors ligne est un changement d'état réel, elle compte donc
    // pour 'last_update' au même titre que le mode ou la consigne.
    foreach (smartclimCapabilities::conceptsConnus() as $concept) {
      if (!array_key_exists($concept, $_etat)) {
        continue;
      }
      if ($this->checkAndUpdateCmd($concept, $_etat[$concept])) {
        $change = true;
      }
    }

    // Depuis UC02 du domaine post-mvp/01-transport-broadlink-lan (§ 5.5 de sa spec
    // technique) : le transport AFFICHÉ suit 'source' de l'état APPLIQUÉ quand il est
    // fourni. Le repli TRANSPORT_AUX_HOME préserve EXACTEMENT le comportement des deux
    // appelants qui ne fournissent pas 'source' (basculerHorsLigne(), état optimiste
    // d'UC06) — "change" au premier cycle uniquement, hors de l'agrégation ci-dessus
    // (spec technique § AC6 en détail). 'source' n'est PAS un concept (la boucle de
    // conceptsConnus() ci-dessus l'ignore déjà) et filtrerEtatSelonOrdres() ne lit que les
    // clés présentes dans memoireOrdres(), jamais 'source' : rien à filtrer ici.
    $this->checkAndUpdateCmd(self::CMD_TRANSPORT, smartclimCapabilities::libelleTransport(isset($_etat['source']) ? $_etat['source'] : smartclimCapabilities::TRANSPORT_AUX_HOME));

    if ($change) {
      $this->checkAndUpdateCmd(self::CMD_DERNIERE_MAJ, date('d/m/Y H:i:s'));
    }

    return $change;
  }

  // === SONDE AUXLINK (UC04 post-mvp/05) — début ===

  /**
   * Arme la campagne de la sonde de découverte AUXLink (UC04 du domaine
   * post-mvp/05-temps-reel-et-demon, § 6.4 de sa spec technique) — appelée
   * UNIQUEMENT par core/php/pont-demon.php --auxlink-armer. Normalise la durée,
   * complète les hôtes par hotesSondeAuxlink() si vides, calcule l'empreinte de
   * campagne puis purge le rapport en cache SOUS CONDITION SEULEMENT (M4 : une
   * purge inconditionnelle sur une relance de la même campagne effacerait la
   * preuve déjà accumulée). NE LÈVE JAMAIS (appelée par une CLI).
   *
   * @param mixed $_duree
   * @param array $_hotes
   * @return array{lance:bool, duree:int, hotes:string[], motif:string}
   */
  public static function armerSondeAuxlink($_duree = null, array $_hotes = array()) {
    try {
      $duree = is_numeric($_duree) ? (int) $_duree : smartclimDemon::DUREE_OBSERVATION_DEFAUT;
      $motif = '';
      if ($duree < smartclimDemon::DUREE_OBSERVATION_MIN || $duree > smartclimDemon::DUREE_OBSERVATION_MAX) {
        $motif = 'Durée hors bornes, ' . smartclimDemon::DUREE_OBSERVATION_DEFAUT . ' s retenu.';
        $duree = smartclimDemon::DUREE_OBSERVATION_DEFAUT;
      }
      $duree = max(smartclimDemon::DUREE_OBSERVATION_MIN, min(smartclimDemon::DUREE_OBSERVATION_MAX, $duree));

      $hotes = array();
      foreach ($_hotes as $hote) {
        $ip = self::normaliserIpV4($hote);
        if ($ip !== '' && !in_array($ip, $hotes, true) && count($hotes) < 4) {
          $hotes[] = $ip;
        }
      }
      if (empty($hotes)) {
        $hotes = self::hotesSondeAuxlink();
      }

      $empreinte = sha1((string) json_encode(array('duree' => $duree, 'hotes' => $hotes)));

      // M4 : purge CONDITIONNELLE — seulement si la campagne en cache diffère de
      // celle qu'on s'apprête à (re)lancer.
      $rapportActuel = smartclimDemon::rapportSondeAuxlink();
      if ($rapportActuel !== null && $rapportActuel['empreinte'] !== $empreinte) {
        smartclimDemon::oublierSondeAuxlink();
      }

      $lance = smartclimDemon::envoyerSondeAuxlink(array(
        'actif' => true,
        'duree' => $duree,
        'hotes' => $hotes,
        'empreinte' => $empreinte,
      ));

      return array('lance' => $lance, 'duree' => $duree, 'hotes' => $hotes, 'motif' => $motif);
    } catch (Throwable $t) {
      log::add('smartclim', 'error', 'Armement de la sonde AUXLink : erreur interne : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
      return array('lance' => false, 'duree' => 0, 'hotes' => array(), 'motif' => 'Erreur interne.');
    }
  }

  /**
   * Désarme la campagne de la sonde de découverte AUXLink — appelée UNIQUEMENT
   * par core/php/pont-demon.php --auxlink-desarmer.
   *
   * @return bool
   */
  public static function desarmerSondeAuxlink() {
    return smartclimDemon::envoyerSondeAuxlink(array('actif' => false));
  }

  /**
   * Diagnostic LECTURE SEULE du rapport de campagne AUXLink (UC04 de ce domaine,
   * § 6.5 de sa spec technique) — instrument de CONSTAT de
   * core/php/pont-demon.php --auxlink, AUCUNE émission réseau. Rapproche chaque
   * MAC observée d'un équipement par macEquipement() ET SA MAC INVERSÉE (règle du
   * plugin : les implémentations Broadlink de référence lisent des ordres
   * d'octets opposés).
   *
   * @return array{rapport:?array, age:?int, verdict:string, correspondances:array}
   */
  public static function diagnosticSondeAuxlink() {
    $rapport = smartclimDemon::rapportSondeAuxlink();
    if ($rapport === null) {
      return array('rapport' => null, 'age' => null, 'verdict' => 'non_arme', 'correspondances' => array());
    }

    $age = max(0, time() - $rapport['ts']);
    if ($age > 3 * smartclimDemon::FRAICHEUR_SONDE) {
      return array('rapport' => $rapport, 'age' => $age, 'verdict' => 'perime', 'correspondances' => array());
    }

    $trames = $rapport['trames'];
    $reponsesAuxlink = array_filter($trames, function ($trame) {
      return $trame['famille'] === 'auxlink' && $trame['sens'] === 'reponse';
    });
    $reponsesGagent = array_filter($trames, function ($trame) {
      return $trame['famille'] === 'gagent' && $trame['sens'] === 'reponse';
    });
    $requetesNonLocales = array_filter($trames, function ($trame) {
      return $trame['sens'] === 'requete' && empty($trame['locale']);
    });
    $portOuvert = false;
    foreach ($rapport['ports'] as $statut) {
      if ($statut === 'ouvert') {
        $portOuvert = true;
        break;
      }
    }
    $fenetreExpiree = time() >= $rapport['expire_le'];

    $correspondances = array();
    if (!empty($reponsesAuxlink)) {
      $index = eqLogic::byType('smartclim', true);
      foreach ($reponsesAuxlink as $trame) {
        if ($trame['mac'] === '') {
          continue;
        }
        $macInversee = self::macInversee($trame['mac']);
        $nomTrouve = null;
        foreach ($index as $eqLogic) {
          if (!($eqLogic instanceof smartclim)) {
            continue;
          }
          $macEquipement = $eqLogic->macEquipement();
          if ($macEquipement !== '' && ($macEquipement === $trame['mac'] || $macEquipement === $macInversee)) {
            $nomTrouve = self::neutraliserPourLog($eqLogic->getHumanName());
            break;
          }
        }
        $correspondances[] = array(
          'mac' => $trame['mac'],
          'device_id' => $trame['device_id'],
          'equipement' => $nomTrouve,
        );
      }
    }

    if (!empty($reponsesAuxlink)) {
      $verdict = empty(array_filter($correspondances, function ($c) {
        return $c['equipement'] !== null;
      })) ? 'positif' : 'positif_confirme';
    } elseif (!empty($reponsesGagent)) {
      $verdict = 'piste_gagent';
    } elseif (!empty($requetesNonLocales)) {
      $verdict = 'piste_requete';
    } elseif ($portOuvert) {
      $verdict = 'piste_port';
    } elseif (!$fenetreExpiree) {
      $verdict = 'en_cours';
    } else {
      $verdict = 'negatif_provisoire';
    }

    return array('rapport' => $rapport, 'age' => $age, 'verdict' => $verdict, 'correspondances' => $correspondances);
  }

  /**
   * Hôtes connus à sonder en priorité (unicast, en complément de la diffusion) :
   * les adresses LAN déjà connues du parc, validées et bornées à 4 (§ 6.4 de la
   * spec technique).
   *
   * @return string[]
   */
  private static function hotesSondeAuxlink() {
    $hotes = array();
    foreach (eqLogic::byType('smartclim', true) as $eqLogic) {
      if (count($hotes) >= 4) {
        break;
      }
      if (!($eqLogic instanceof smartclim)) {
        continue;
      }
      $adresse = $eqLogic->adresseLan();
      $ip = isset($adresse['ip']) ? self::normaliserIpV4($adresse['ip']) : '';
      if ($ip !== '' && !in_array($ip, $hotes, true)) {
        $hotes[] = $ip;
      }
    }
    return $hotes;
  }

  // === SONDE AUXLINK (UC04 post-mvp/05) — fin ===

  // Fonction exécutée automatiquement avant la suppression de l'équipement
  //
  // UC06, § 5.3 : hygiène — purge la mémoire des valeurs commandées de cet équipement,
  // sans quoi l'entrée de cache resterait orpheline jusqu'à expiration naturelle (60 s,
  // sans conséquence fonctionnelle, mais évite un déchet inutile).
  public function preRemove() {
    cache::delete(self::CLE_CACHE_ORDRES . $this->getId());
    // UC03 du domaine post-mvp/05-temps-reel-et-demon (§ 5.4 de sa spec technique) : un
    // équipement supprimé resterait sinon abonné côté démon jusqu'au prochain tick de
    // synchro (<= INTERVALLE_SYNC_RELAIS). Le chemin « endpointId inconnu du parc =>
    // ignoré » d'appliquerPushAuxCloud() couvre déjà la SÛRETÉ ; cet appel ferme la
    // FENÊTRE, pour le coût d'un cache::delete().
    self::invaliderSyncRelais();
  }

  // Fonction exécutée automatiquement après la suppression de l'équipement
  public function postRemove() {
  }

  /*
  * Permet de crypter/décrypter automatiquement des champs de configuration des équipements
  * Exemple avec le champ "Mot de passe" (password)
  public function decrypt() {
    $this->setConfiguration('password', utils::decrypt($this->getConfiguration('password')));
  }
  public function encrypt() {
    $this->setConfiguration('password', utils::encrypt($this->getConfiguration('password')));
  }
  */

  /*
  * Permet de modifier l'affichage du widget (également utilisable par les commandes)
  public function toHtml($_version = 'dashboard') {}
  */

  /*     * **********************Getteur Setteur*************************** */
}

class smartclimCmd extends cmd {
  /*     * *************************Attributs****************************** */

  /*
  public static $_widgetPossibility = array();
  */

  /*     * ***********************Methode static*************************** */


  /*     * *********************Methode d'instance************************* */

  /*
  * Permet d'empêcher la suppression des commandes même si elles ne sont pas dans la nouvelle configuration de l'équipement envoyé en JS
  public function dontRemoveCmd() {
    return true;
  }
  */

  // Exécution d'une commande (UC06, § 5.3 de la spec technique) : délégation PURE,
  // aucune logique métier, aucun catch — la curation du message vit dans smartclim::
  // (messageErreurAuxHome() y est private) et l'exception curatée remonte au core.
  public function execute($_options = array()) {
    if ($this->getType() !== 'action') {
      return;
    }
    $eqLogic = $this->getEqLogic();
    if (!($eqLogic instanceof smartclim)) {
      return;
    }
    $eqLogic->executerCommandeAction($this->getLogicalId(), $_options);
  }

  /**
   * Rendu HTML de la commande (UC01 du domaine post-mvp/06-ergonomie-jeedom, §§ 1.4/6/9
   * de la spec technique). ⚠️⚠️ SIGNATURE RECOPIÉE À L'IDENTIQUE de cmd::toHtml() du
   * core Jeedom (branche V4-stable, relue le 2026-09-12) — ce n'est PAS un point
   * d'extension documenté (contrairement à formatValueWidget()/preToHtml()/
   * dontRemoveCmd()), c'est un OVERRIDE d'une méthode PUBLIQUE du core, atteint par
   * polymorphisme via cmd::cast() (toute commande d'un équipement smartclim est
   * instanciée en smartclimCmd). Toute divergence de signature avec une montée future du
   * core (paramètre ajouté) casse le CHARGEMENT DE LA CLASSE ENTIÈRE — donc TOUT le
   * plugin — sans qu'aucune erreur ne soit visible à `php -l` ni en CI (R1 de la spec
   * technique). Forme STRICTE retenue (D8) : à re-vérifier à chaque montée de
   * 'compatibility' dans plugin_info/info.json.
   *
   * Sur la commande info 'power' porteuse du widget de tuile smartclim::WIDGET_TUILE,
   * injecte la charge de la tuile (smartclimWidget::optionsTuile()) dans $_options puis
   * délègue au parent ; dans tous les autres cas, délègue telle quelle. try/catch
   * (Throwable) : toute erreur d'assemblage retombe sur le rendu par défaut du core, le
   * dashboard ne casse jamais.
   *
   * @param string $_version
   * @param mixed $_options
   * @return string
   */
  public function toHtml($_version = 'dashboard', $_options = '') {
    // On aliase POUR LE TEST de template ci-dessous ; $_version est transmis NON
    // MODIFIÉ à parent::toHtml(), qui ré-aliase lui-même (§ 6 de la spec technique :
    // l'aliasing est idempotent, mais ne pas pré-transformer un argument que le core
    // transforme déjà est la règle sûre).
    $version = jeedom::versionAlias($_version);
    if ($_options === ''
        && $this->getType() === 'info'
        && $this->getTemplate($version, '') === smartclim::WIDGET_TUILE
        && ($eqLogic = $this->getEqLogic()) instanceof smartclim) {
      try {
        $charge = smartclimWidget::optionsTuile($eqLogic);
        if ($charge !== '') {
          return parent::toHtml($_version, $charge);
        }
      } catch (Throwable $t) {
        log::add('smartclim', 'error', 'Assemblage de la tuile "climatiseur" impossible (équipement "' . smartclim::neutraliserPourLog($eqLogic->getHumanName()) . '") : ' . get_class($t) . ' : ' . smartclim::neutraliserPourLog($t->getMessage()));
      }
    }
    return parent::toHtml($_version, $_options);
  }

  /*     * **********************Getteur Setteur*************************** */
}
