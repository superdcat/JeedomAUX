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
require_once __DIR__ . '/../../../../core/php/core.inc.php';

/**
 * Pont de communication vers le démon Python SmartClim (UC02 du domaine
 * post-mvp/05-temps-reel-et-demon). AUCUN eqLogic, aucune commande, aucun accès
 * AUX Home/LAN ici — c'est l'UNIQUE point socket du plugin vers le démon, même
 * règle que « tout le LAN par smartclimBroadlinkLan, jamais de socket épars »
 * (CLAUDE.md § Conventions).
 *
 * ⚠️ Le démon est LATÉRAL, jamais un passage obligé : aucune méthode du socle MVP
 * (cron(), rafraichirAuxHome(), executerCommandeAction()...) n'appelle cette
 * classe. Le seul couplage introduit est DESCENDANT (PHP -> démon), le pont ne
 * remonte rien vers le métier — cf. spec technique UC02 § 0.
 *
 * Aucune méthode de cette classe ne lève de smartclimException : les trois
 * surfaces d'appel (le core sans try/catch pour deamon_info(), un booléen
 * exploitable pour deamon_start()/deamon_stop(), un code de sortie pour la CLI)
 * exigent le contraire d'une exception (spec technique § 6.1). Le contrat est
 * « statut + journal », comme smartclimBroadlinkLan::ouvrirSession()/lireEtat().
 */
class smartclimDemon {
  /*     * *************************Attributs****************************** */

  // Port d'écoute par défaut du démon. Volontairement HORS des bandes 5500x du
  // gabarit officiel (55009) et de blea (55008), où tout plugin dérivé du même
  // squelette se bousculerait (spec technique § 5.1).
  const PORT_DEMON = 55112;

  // Clé de config SANS champ de formulaire : échappatoire en cas de collision de
  // port, réglable uniquement par l'API JSON-RPC du core. Défaut DUPLIQUÉ en
  // littéral dans core/config/smartclim.config.ini (seul défaut vu par
  // config::byKeys()) : les deux doivent rester identiques.
  const CLE_CONF_PORT = 'demon_port';

  // log::getLogLevel('smartclim_demon') retombe PAR REGEX sur log::level::smartclim
  // (core log.class.php) : le niveau du démon suit celui du plugin, sans réglage
  // supplémentaire.
  const NOM_LOG = 'smartclim_demon';

  // ⚠️ Nom RENOMMÉ (jamais demond.py) : system::kill()/system::ps() travaillent par
  // motif grep sur la ligne de commande — un system::kill('demond.py') d'un autre
  // plugin dérivé du même gabarit tuerait notre démon, et réciproquement (spec
  // technique § 2.1). Le renommage est la SEULE protection possible.
  const NOM_SCRIPT = 'smartclimd.py';

  const CLE_CACHE_PONG = 'smartclim::pont_pong';
  const DUREE_MEMOIRE_PONG = 300;

  // UC03 du domaine post-mvp/05-temps-reel-et-demon (§ 5.2 de sa spec technique) :
  // battement du relais WebSocket AUX Cloud legacy — mémoire GLOBALE au compte (une
  // seule session WS pour tout le parc legacy), NON chiffrée (aucun secret : un code
  // d'état, deux listes d'identifiants d'endpoint et une empreinte). FRAICHEUR_RELAIS
  // (180 s) borne la fenêtre pendant laquelle un battement est considéré à jour par
  // smartclim::pushAuxCloudActif() — STRICTEMENT < DUREE_MEMOIRE_RELAIS (300 s), pour
  // qu'un battement "frais" au sens fonctionnel soit toujours lisible en cache.
  const CLE_CACHE_RELAIS = 'smartclim::relais_auxcloud';
  const DUREE_MEMOIRE_RELAIS = 300;
  const FRAICHEUR_RELAIS = 180;

  // === SONDE AUXLINK (UC04 post-mvp/05) — début ===
  // Rapport de la sonde de découverte AUXLink (UC04 du domaine
  // post-mvp/05-temps-reel-et-demon, § 6.3 de sa spec technique) — mémoire GLOBALE
  // au plugin (une seule campagne à la fois), NON chiffrée (aucun secret dans ce
  // cycle : ni session, ni passcode — cf. l'en-tête de sonde_auxlink.py). Durée
  // DÉLIBÉRÉMENT longue (7 j) par rapport à DUREE_MEMOIRE_RELAIS (300 s) : un
  // battement ne vaut que frais, un rapport de campagne doit survivre à sa propre
  // fenêtre pour rester lisible après coup — c'est lui qui porte la preuve du verdict.
  const CLE_CACHE_SONDE_AUXLINK = 'smartclim::sonde_auxlink';
  const DUREE_MEMOIRE_SONDE_AUXLINK = 604800; // 7 j
  const DUREE_OBSERVATION_DEFAUT = 86400; // 24 h
  const DUREE_OBSERVATION_MIN = 60;
  const DUREE_OBSERVATION_MAX = 604800;
  const TRAMES_MAX_SONDE = 20;
  const FRAICHEUR_SONDE = 180;
  // === SONDE AUXLINK (UC04 post-mvp/05) — fin ===

  const TIMEOUT_SOCKET = 2;
  const ATTENTE_DEMARRAGE = 5;
  // Borne l'attente de la MORT du processus après SIGTERM, dans arreter() — distincte
  // d'ATTENTE_DEMARRAGE bien qu'ayant la même valeur aujourd'hui : les deux bornent des
  // transitions d'état opposées, et rien ne garantit qu'elles doivent rester égales si
  // l'une est ajustée plus tard (revue croisée UC02, finding minor).
  const ATTENTE_ARRET = 5;

  /*     * ***********************Methode static*************************** */

  /**
   * Port d'écoute effectif, normalisé À LA LECTURE (barrière unique : sans champ
   * de formulaire, aucun chemin d'écriture par config::save depuis l'IHM,
   * donc pas de preConfig_demon_port possible — qui serait du code mort).
   *
   * @return int
   */
  public static function port() {
    $port = (int) config::byKey(self::CLE_CONF_PORT, 'smartclim', self::PORT_DEMON);
    if ($port < 1024 || $port > 65535) {
      return self::PORT_DEMON;
    }
    return $port;
  }

  /**
   * @return string Chemin absolu du script du démon, ou '' s'il est introuvable.
   */
  public static function cheminScript() {
    $chemin = realpath(__DIR__ . '/../../resources/smartclimd/smartclimd.py');
    return $chemin === false ? '' : $chemin;
  }

  /**
   * @return string Chemin du fichier PID, dans le dossier temporaire du plugin.
   */
  public static function fichierPid() {
    return jeedom::getTmpFolder('smartclim') . '/demon.pid';
  }

  /**
   * Corps de plugin::deamon_info() : état du démon pour le panneau « Démon » du
   * core, et pour la tâche cron plugin::checkDeamon (toutes les 5 minutes).
   *
   * ⚠️ Détection PRINCIPALE : fichier PID présent ET PID vivant (posix_getsid,
   * sous garde function_exists) -> 'ok', un PID PÉRIMÉ étant supprimé au passage.
   * SEULEMENT SI cette détection est négative (pas de posix, pas de PID, PID
   * mort), repli par sonde de PROCESSUS system::ps(NOM_SCRIPT) : coût réel
   * uniquement dans ce cas dégradé — c'est précisément celui où un 'nok' erroné
   * ferait démarrer un SECOND démon (spec technique § 6.2). Zéro appel shell sur
   * le chemin nominal.
   *
   * ⚠️ NE LÈVE JAMAIS : plugin::deamon_info() n'est entourée d'AUCUN try/catch
   * par le core — un jet ici casserait plugin::checkDeamon DE TOUS LES PLUGINS.
   *
   * @return array ('log' => NOM_LOG, 'state' => 'ok'|'nok', 'launchable' => 'ok'|'nok'[, 'launchable_message' => ...])
   */
  public static function etat() {
    try {
      $etat = array(
        'log' => self::NOM_LOG,
        'state' => 'nok',
        'launchable' => 'ok',
      );

      $actif = false;
      $pidFichier = self::fichierPid();
      $pid = null;
      if (is_file($pidFichier)) {
        $contenu = trim((string) @file_get_contents($pidFichier));
        if (ctype_digit($contenu)) {
          $pid = (int) $contenu;
        }
      }

      if ($pid !== null && function_exists('posix_getsid')) {
        if (@posix_getsid($pid) !== false) {
          $actif = true;
        } else {
          // PID périmé : nettoyage au passage (n'affecte pas le résultat courant).
          @unlink($pidFichier);
        }
      }

      if (!$actif) {
        // Repli : coûte un appel shell (~10-30 ms), UNIQUEMENT ici.
        $processus = system::ps(self::NOM_SCRIPT);
        $actif = !empty($processus);
      }

      $etat['state'] = $actif ? 'ok' : 'nok';

      $script = self::cheminScript();
      $interpreteur = self::interpreteurAtteignable();
      if ($script === '') {
        $etat['launchable'] = 'nok';
        $etat['launchable_message'] = __('Fichier du démon introuvable — réinstallez le plugin', __FILE__);
      } elseif (!$interpreteur) {
        $etat['launchable'] = 'nok';
        $etat['launchable_message'] = __('Dépendances non installées ou interpréteur Python introuvable', __FILE__);
      }

      return $etat;
    } catch (Throwable $t) {
      return array(
        'log' => self::NOM_LOG,
        'state' => 'nok',
        'launchable' => 'nok',
      );
    }
  }

  /**
   * @return bool L'interpréteur Python3 du plugin (venv ou système) est-il atteignable.
   */
  private static function interpreteurAtteignable() {
    $cmd = trim((string) system::getCmdPython3('smartclim'));
    if ($cmd === '') {
      return system::checkHasExec('python3');
    }
    // Un chemin absolu de venv est vérifiable directement ; sinon on retombe sur
    // la sonde d'exécutable du core.
    if (strpos($cmd, '/') !== false) {
      return file_exists($cmd);
    }
    return system::checkHasExec('python3');
  }

  /**
   * Corps de plugin::deamon_start(). AUCUN paramètre : le core résout la
   * signature par ReflectionMethod et ne transmet $_auto que si la méthode
   * déclare au moins un paramètre OBLIGATOIRE (spec technique § 1.1).
   *
   * Lance le script SANS sudo (ni root ni port < 1024 nécessaires), journalise la
   * commande MASQUÉE uniquement, puis attend l'état 'ok' par pas de 500 ms,
   * ATTENTE_DEMARRAGE secondes au plus. Ne lève pas.
   *
   * @return bool
   */
  public static function lancer() {
    try {
      $script = self::cheminScript();
      if ($script === '') {
        log::add('smartclim', 'error', 'Démarrage du démon impossible : script introuvable');
        return false;
      }

      $commande = self::commandeLancement();
      log::add('smartclim', 'info', 'Démarrage du démon : ' . self::masquerCle($commande));

      exec($commande . ' >> ' . log::getPathToLog(self::NOM_LOG) . ' 2>&1 &');

      $attente = 0;
      while ($attente < self::ATTENTE_DEMARRAGE) {
        usleep(500000);
        $attente += 0.5;
        $etat = self::etat();
        if (isset($etat['state']) && $etat['state'] === 'ok') {
          return true;
        }
      }

      log::add('smartclim', 'error', 'Démarrage du démon : aucun état actif observé après ' . self::ATTENTE_DEMARRAGE . ' s');
      return false;
    } catch (Throwable $t) {
      log::add('smartclim', 'error', 'Démarrage du démon : erreur interne : ' . get_class($t) . ' : ' . smartclim::neutraliserPourLog($t->getMessage()));
      return false;
    }
  }

  /**
   * Commande complète de lancement du démon (interpréteur du venv du plugin,
   * JAMAIS 'python3' en dur — sinon 'requests', installé dans le venv, est
   * invisible). ⚠️ system::getCmdPython3() renvoie déjà une espace finale.
   *
   * @return string
   */
  private static function commandeLancement() {
    $callback = rtrim((string) network::getNetworkAccess('internal', 'http:127.0.0.1:' . self::port() . ':comp'), '/')
      . '/plugins/smartclim/core/php/jeeSmartclim.php';

    return system::getCmdPython3('smartclim') . self::cheminScript()
      . ' --loglevel ' . escapeshellarg(log::convertLogLevel(log::getLogLevel('smartclim')))
      . ' --socketport ' . self::port()
      . ' --callback ' . escapeshellarg($callback)
      . ' --apikey ' . escapeshellarg(jeedom::getApiKey('smartclim'))
      . ' --pid ' . escapeshellarg(self::fichierPid())
      . ' --cycle 0.5';
  }

  /**
   * SEULE forme journalisable de la commande de lancement (AC6, point de fuite
   * n°1 de la spec technique § 6.3) : la commande brute n'est JAMAIS passée à
   * log::add.
   *
   * @param string $_commande
   * @return string
   */
  public static function masquerCle($_commande) {
    return preg_replace('/(--apikey\s+)\S+/', '$1***', $_commande);
  }

  /**
   * Corps de plugin::deamon_stop(). SIGTERM par PID quand il est disponible ET
   * que posix est là, attente bornée, puis REPLI INCONDITIONNEL
   * system::kill(NOM_SCRIPT) + system::fuserk((string) port()) : c'est ce repli
   * qui referme le cas orphelin (spec technique § 6.2 — la sonde `ps` de etat()
   * rend `state = ok` alors que le PID n'est plus sur disque). Idempotente et
   * silencieuse quand il n'y a rien à arrêter (AC2). Ne lève pas.
   *
   * @return bool
   */
  public static function arreter() {
    try {
      $signalEnvoye = false;

      $pidFichier = self::fichierPid();
      if (is_file($pidFichier) && function_exists('posix_kill')) {
        $contenu = trim((string) @file_get_contents($pidFichier));
        if (ctype_digit($contenu)) {
          $pid = (int) $contenu;
          if (@posix_getsid($pid) !== false) {
            system::kill($pid);
            $signalEnvoye = true;
            $attente = 0;
            while ($attente < self::ATTENTE_ARRET) {
              usleep(500000);
              $attente += 0.5;
              if (@posix_getsid($pid) === false) {
                break;
              }
            }
          }
        }
      }

      // Repli inconditionnel : referme le cas orphelin (fichier PID absent/périmé
      // alors qu'un processus tourne encore).
      system::kill(self::NOM_SCRIPT);
      system::fuserk((string) self::port());

      if ($signalEnvoye) {
        log::add('smartclim', 'info', 'Démon arrêté');
      }
      return true;
    } catch (Throwable $t) {
      log::add('smartclim', 'error', 'Arrêt du démon : erreur interne : ' . get_class($t) . ' : ' . smartclim::neutraliserPourLog($t->getMessage()));
      return false;
    }
  }

  /**
   * UNIQUE point socket du plugin vers le démon. Ajoute l'apikey, encode en
   * JSON, écrit une LIGNE terminée par "\n" (le démon lit par
   * rfile.readline()). Journalise l'ordre SANS la clé. Ne lève pas.
   *
   * @param array $_message
   * @return bool
   */
  public static function envoyer(array $_message) {
    try {
      $message = $_message;
      $message['apikey'] = jeedom::getApiKey('smartclim');
      $json = json_encode($message);
      if ($json === false) {
        log::add('smartclim', 'error', 'Envoi au démon impossible : message non encodable en JSON');
        return false;
      }

      $fp = @stream_socket_client('tcp://127.0.0.1:' . self::port(), $errno, $errstr, self::TIMEOUT_SOCKET);
      if ($fp === false) {
        log::add('smartclim', 'debug', 'Envoi au démon impossible : démon injoignable sur le port ' . self::port() . ' (' . $errstr . ')');
        return false;
      }

      stream_set_timeout($fp, self::TIMEOUT_SOCKET);
      // ⚠️⚠️ Correctif de sécurité (UC03 du domaine post-mvp/05-temps-reel-et-demon,
      // § 6.1 de sa spec technique) : NE JAMAIS journaliser $_message en entier, même
      // sans l'apikey — dès que le paquet relais (session.loginsession, devSession par
      // appareil) transite par cette méthode, cela écrirait ces secrets en clair dans
      // log/smartclim. Seuls 'cmd' et le NOMBRE D'OCTETS sont journalisés.
      $cmd = isset($_message['cmd']) && is_scalar($_message['cmd']) ? (string) $_message['cmd'] : '?';
      log::add('smartclim', 'debug', 'Envoi au démon : cmd=' . $cmd . ' (' . strlen($json) . ' octets)');
      fwrite($fp, $json . "\n");
      fclose($fp);
      return true;
    } catch (Throwable $t) {
      log::add('smartclim', 'error', 'Envoi au démon : erreur interne : ' . get_class($t) . ' : ' . smartclim::neutraliserPourLog($t->getMessage()));
      return false;
    }
  }

  /**
   * @param string $_jeton
   * @return bool
   */
  public static function ping($_jeton) {
    return self::envoyer(array('cmd' => 'ping', 'jeton' => $_jeton));
  }

  /**
   * Appelée UNIQUEMENT par core/php/jeeSmartclim.php (rappel du démon). Valide
   * la FORME du jeton avant toute écriture en cache : jamais une valeur reçue
   * non validée en clé ou en valeur de cache. Forme identique à REGEX_JETON de
   * resources/smartclimd/smartclimd.py — les deux barrières doivent rester
   * identiques. \A/\z (et non ^/$) : ancres strictes, insensibles à un '\n'
   * final qui ferait passer un jeton du type "abcd1234\n".
   *
   * @param mixed $_jeton
   */
  public static function enregistrerPong($_jeton) {
    $jeton = (string) $_jeton;
    if (preg_match('/\A[0-9a-f]{8,32}\z/', $jeton) !== 1) {
      log::add('smartclim', 'warning', 'Pont : jeton de pong reçu de forme invalide, ignoré');
      return;
    }
    cache::set(self::CLE_CACHE_PONG, $jeton, self::DUREE_MEMOIRE_PONG);
  }

  /**
   * @return string|null
   */
  public static function pongMemorise() {
    $valeur = cache::byKey(self::CLE_CACHE_PONG)->getValue(null);
    return is_string($valeur) && $valeur !== '' ? $valeur : null;
  }

  public static function oublierPong() {
    cache::delete(self::CLE_CACHE_PONG);
  }

  /**
   * Transmet le paquet de synchronisation du relais AUX Cloud legacy au démon (UC03 du
   * domaine post-mvp/05-temps-reel-et-demon, § 5.2 de sa spec technique) — simple
   * délégation à envoyer(), même contrat (NE LÈVE JAMAIS, jamais de secret journalisé
   * grâce au correctif ci-dessus).
   *
   * @param array $_relais {url, entetes, session, appareils, empreinte} ou {appareils: []}.
   * @return bool
   */
  public static function envoyerRelais(array $_relais) {
    return self::envoyer(array('cmd' => 'auxcloud_relais', 'relais' => $_relais));
  }

  /**
   * Appelée UNIQUEMENT par core/php/jeeSmartclim.php (branche 'auxcloud.relais') : valide
   * la FORME du battement AVANT toute écriture en cache (§ 4.1 de la spec technique) —
   * 'etat' dans une liste blanche FERMÉE, listes d'endpoints validées et BORNÉES à 200,
   * horodatage SERVEUR (jamais celui du démon). Non chiffrée (aucun secret).
   *
   * @param mixed $_battement
   */
  public static function enregistrerBattement($_battement) {
    if (!is_array($_battement)) {
      log::add('smartclim', 'warning', 'Pont : battement du relais AUX Cloud legacy reçu de forme invalide, ignoré');
      return;
    }
    $etat = isset($_battement['etat']) && is_string($_battement['etat']) ? $_battement['etat'] : '';
    if (!in_array($etat, array('connecte', 'reconnexion', 'refus'), true)) {
      log::add('smartclim', 'warning', 'Pont : battement du relais AUX Cloud legacy reçu avec un état inconnu, ignoré');
      return;
    }
    $empreinte = isset($_battement['empreinte']) && is_string($_battement['empreinte']) && preg_match('/\A[0-9a-f]{0,64}\z/', $_battement['empreinte']) === 1
      ? $_battement['empreinte']
      : '';

    $valide = array(
      'etat' => $etat,
      'abonnes' => self::listeEndpointsValidee(isset($_battement['abonnes']) ? $_battement['abonnes'] : null),
      'confirmes' => self::listeEndpointsValidee(isset($_battement['confirmes']) ? $_battement['confirmes'] : null),
      'empreinte' => $empreinte,
      // ⚠️ Horodatage SERVEUR, jamais celui du démon (§ 4.1 de la spec technique) : le
      // démon ne dicte jamais une donnée temporelle de confiance.
      'ts' => time(),
    );
    $json = json_encode($valide);
    if ($json === false) {
      return;
    }
    cache::set(self::CLE_CACHE_RELAIS, $json, self::DUREE_MEMOIRE_RELAIS);
  }

  /**
   * Filtre une liste d'identifiants d'endpoint reçue du démon : chaînes de forme
   * conforme UNIQUEMENT, bornée à 200 entrées (§ 4.1 de la spec technique).
   *
   * @param mixed $_valeur
   * @return string[]
   */
  private static function listeEndpointsValidee($_valeur) {
    if (!is_array($_valeur)) {
      return array();
    }
    $sortie = array();
    foreach ($_valeur as $item) {
      if (count($sortie) >= 200) {
        break;
      }
      if (is_string($item) && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $item) === 1) {
        $sortie[] = $item;
      }
    }
    return $sortie;
  }

  /**
   * Lit le battement du relais AUX Cloud legacy en cache — VALIDE LA FORME et renvoie
   * `null` plutôt qu'un contenu forgé (même patron que smartclim::incidentMemorise()).
   *
   * @return array{etat:string,abonnes:string[],confirmes:string[],empreinte:string,ts:int}|null
   */
  public static function battementRelais() {
    $brut = cache::byKey(self::CLE_CACHE_RELAIS)->getValue(null);
    if (!is_string($brut) || $brut === '') {
      return null;
    }
    $battement = json_decode($brut, true);
    if (
      !is_array($battement)
      || !isset($battement['etat'], $battement['ts'])
      || !in_array($battement['etat'], array('connecte', 'reconnexion', 'refus'), true)
      || !is_numeric($battement['ts'])
    ) {
      return null;
    }
    return array(
      'etat' => $battement['etat'],
      'abonnes' => isset($battement['abonnes']) && is_array($battement['abonnes']) ? $battement['abonnes'] : array(),
      'confirmes' => isset($battement['confirmes']) && is_array($battement['confirmes']) ? $battement['confirmes'] : array(),
      'empreinte' => isset($battement['empreinte']) && is_string($battement['empreinte']) ? $battement['empreinte'] : '',
      'ts' => (int) $battement['ts'],
    );
  }

  // === SONDE AUXLINK (UC04 post-mvp/05) — début ===

  /**
   * Transmet une configuration à la sonde de découverte AUXLink (UC04 du domaine
   * post-mvp/05-temps-reel-et-demon, § 6.1 de sa spec technique) — simple
   * délégation à envoyer(), même contrat (NE LÈVE JAMAIS).
   *
   * @param array $_sonde {actif, duree, hotes, empreinte} ou {actif: false}.
   * @return bool
   */
  public static function envoyerSondeAuxlink(array $_sonde) {
    return self::envoyer(array('cmd' => 'auxlink_sonde', 'sonde' => $_sonde));
  }

  /**
   * Appelée UNIQUEMENT par core/php/jeeSmartclim.php (branche 'auxlink.sonde') :
   * valide INTÉGRALEMENT la forme AVANT toute écriture en cache. Horodatage
   * SERVEUR (time()), jamais celui du démon — même invariant que
   * enregistrerBattement()/enregistrerPong(). Cache NON chiffré (aucun secret dans
   * ce cycle).
   *
   * @param mixed $_rapport
   */
  public static function enregistrerSondeAuxlink($_rapport) {
    if (!is_array($_rapport)) {
      log::add('smartclim', 'warning', 'Sonde AUXLink : rapport de forme invalide, ignoré');
      return;
    }
    $empreinte = isset($_rapport['empreinte']) && is_string($_rapport['empreinte']) && preg_match('/\A[0-9a-f]{0,40}\z/', $_rapport['empreinte']) === 1
      ? $_rapport['empreinte']
      : '';
    $expireLe = isset($_rapport['expire_le']) && is_numeric($_rapport['expire_le']) ? (int) $_rapport['expire_le'] : 0;
    if ($expireLe < 0 || $expireLe > time() + self::DUREE_OBSERVATION_MAX + 3600) {
      $expireLe = 0;
    }

    $valide = array(
      'empreinte' => $empreinte,
      'expire_le' => $expireLe,
      'trames' => self::tramesValidees(isset($_rapport['trames']) ? $_rapport['trames'] : null),
      'ports' => self::portsValides(isset($_rapport['ports']) ? $_rapport['ports'] : null),
      // ⚠️ Horodatage SERVEUR, jamais celui du démon (même règle qu'ailleurs dans
      // cette classe) : c'est CE champ qui porte la fraîcheur du rapport.
      'ts' => time(),
    );
    $json = json_encode($valide);
    if ($json === false) {
      return;
    }
    cache::set(self::CLE_CACHE_SONDE_AUXLINK, $json, self::DUREE_MEMOIRE_SONDE_AUXLINK);
  }

  /**
   * Lit le rapport de campagne AUXLink en cache — VALIDE LA FORME et renvoie
   * `null` plutôt qu'un contenu forgé (même patron que battementRelais() /
   * smartclim::incidentMemorise()).
   *
   * @return array{empreinte:string, expire_le:int, trames:array, ports:array, ts:int}|null
   */
  public static function rapportSondeAuxlink() {
    $brut = cache::byKey(self::CLE_CACHE_SONDE_AUXLINK)->getValue(null);
    if (!is_string($brut) || $brut === '') {
      return null;
    }
    $rapport = json_decode($brut, true);
    if (!is_array($rapport) || !isset($rapport['ts']) || !is_numeric($rapport['ts'])) {
      return null;
    }
    return array(
      'empreinte' => isset($rapport['empreinte']) && is_string($rapport['empreinte']) ? $rapport['empreinte'] : '',
      'expire_le' => isset($rapport['expire_le']) && is_numeric($rapport['expire_le']) ? (int) $rapport['expire_le'] : 0,
      'trames' => self::tramesValidees(isset($rapport['trames']) ? $rapport['trames'] : null),
      'ports' => self::portsValides(isset($rapport['ports']) ? $rapport['ports'] : null),
      'ts' => (int) $rapport['ts'],
    );
  }

  /**
   * ⚠️ Appelée SOUS CONDITION SEULEMENT par smartclim::armerSondeAuxlink() (M4, §
   * 6.3 de la spec technique) : une purge inconditionnelle sur une relance de la
   * même campagne effacerait la preuve déjà accumulée.
   */
  public static function oublierSondeAuxlink() {
    cache::delete(self::CLE_CACHE_SONDE_AUXLINK);
  }

  /**
   * Borne la liste de trames à TRAMES_MAX_SONDE, liste blanche FERMÉE de
   * `famille`/`sens`, regex sur `hex`/`mac`/`device_id`/`ip_source` (§ 7 de la
   * spec technique) — appliquée aussi bien à la réception du démon qu'à la
   * relecture du cache (défense en profondeur symétrique).
   *
   * @param mixed $_valeur
   * @return array
   */
  private static function tramesValidees($_valeur) {
    if (!is_array($_valeur)) {
      return array();
    }
    $sortie = array();
    foreach ($_valeur as $trame) {
      if (count($sortie) >= self::TRAMES_MAX_SONDE) {
        break;
      }
      if (!is_array($trame)) {
        continue;
      }
      $famille = isset($trame['famille']) && is_string($trame['famille']) ? $trame['famille'] : '';
      if (!in_array($famille, array('auxlink', 'gagent', 'autre'), true)) {
        continue;
      }
      $sens = isset($trame['sens']) && is_string($trame['sens']) ? $trame['sens'] : '';
      if (!in_array($sens, array('requete', 'reponse', 'autre'), true)) {
        $sens = 'autre';
      }
      $hex = isset($trame['hex']) && is_string($trame['hex']) && preg_match('/\A[0-9a-f]{0,128}\z/', $trame['hex']) === 1 ? $trame['hex'] : '';
      $mac = isset($trame['mac']) && is_string($trame['mac']) && preg_match('/\A[0-9a-f]{0,12}\z/', $trame['mac']) === 1 ? $trame['mac'] : '';
      $deviceId = isset($trame['device_id']) && is_string($trame['device_id']) && preg_match('/\A[A-Za-z0-9_-]{0,64}\z/', $trame['device_id']) === 1 ? $trame['device_id'] : '';
      $ipSource = isset($trame['ip_source']) && is_string($trame['ip_source']) && preg_match('/\A[0-9]{1,3}(\.[0-9]{1,3}){3}\z/', $trame['ip_source']) === 1 ? $trame['ip_source'] : '';
      $locale = !empty($trame['locale']);
      $sortie[] = array(
        'famille' => $famille,
        'sens' => $sens,
        'hex' => $hex,
        'mac' => $mac,
        'device_id' => $deviceId,
        'ip_source' => $ipSource,
        'locale' => $locale,
      );
    }
    return $sortie;
  }

  /**
   * Borne la liste des statuts de test de port TCP 12416, ip => 'ouvert'|'refuse'|'timeout',
   * bornée à 4 entrées (§ 7 de la spec technique).
   *
   * @param mixed $_valeur
   * @return array<string,string>
   */
  private static function portsValides($_valeur) {
    if (!is_array($_valeur)) {
      return array();
    }
    $sortie = array();
    foreach ($_valeur as $ip => $statut) {
      if (count($sortie) >= 4) {
        break;
      }
      if (!is_string($ip) || preg_match('/\A[0-9]{1,3}(\.[0-9]{1,3}){3}\z/', $ip) !== 1) {
        continue;
      }
      if (!is_string($statut) || !in_array($statut, array('ouvert', 'refuse', 'timeout'), true)) {
        continue;
      }
      $sortie[$ip] = $statut;
    }
    return $sortie;
  }
  // === SONDE AUXLINK (UC04 post-mvp/05) — fin ===
}
