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
      $ordreSansCle = $_message;
      log::add('smartclim', 'debug', 'Envoi au démon : ' . json_encode($ordreSansCle));
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
}
