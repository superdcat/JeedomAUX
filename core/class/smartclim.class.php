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

  // Le mot de passe du compte AUX Home est chiffré au repos par le core Jeedom.
  public static $_encryptConfigKey = array('auxhome_password');

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
  // aux 6 commandes de CONCEPT, conditionnées à configuration.capacites['concepts'].
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
      log::add('smartclim', 'error', 'Test de connexion AUX Home échoué (type ' . $e->getType() . ') : ' . $e->getMessage());
      throw new smartclimException(self::messageErreurAuxHome($e->getType(), $e->getContexte()), $e->getType());
    }
    return __('Connexion réussie au compte AUX Home', __FILE__);
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
      log::add('smartclim', 'error', 'Sonde de diagnostic AUX Home échouée (type ' . $e->getType() . ') : ' . $e->getMessage());
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
    // purge de session doit donc être explicite ici, pas déléguée au hook.
    smartclimAuxHomeApi::purgerSession();
    return __('Identifiants effacés', __FILE__);
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
   * @return array{resume:string, compteurs:array<string,int>, appareils:array, disparus:array}
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
        log::add('smartclim', 'error', 'Scan AUX Home échoué (type ' . $e->getType() . ') : ' . $e->getMessage());
        throw new smartclimException(self::messageErreurAuxHome($e->getType(), $e->getContexte()), $e->getType());
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
            $appareilsResultat[] = self::ligneResultatScan($nomAffiche, $appareil['modele'], $macNorm, $identifiant, $appareil['enLigne'], 'ignore_doublon');
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
            if ($modifie) {
              $eqLogic->save();
            }
            $compteurs['existants']++;
            $appareilsResultat[] = self::ligneResultatScan($eqLogic->getName(), $appareil['modele'], $macNorm, $identifiant, $appareil['enLigne'], 'existant');
            $eqLogicsTouches[] = $eqLogic;
          } else {
            $eqLogic = self::creerEquipement($logicalId, $appareil, $macNorm, $index['noms'], $capacites);
            $consommes[] = $logicalId;
            $compteurs['crees']++;
            $appareilsResultat[] = self::ligneResultatScan($eqLogic->getName(), $appareil['modele'], $macNorm, $identifiant, $appareil['enLigne'], 'cree');
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
      );
    } finally {
      cache::delete(self::CLE_CACHE_VERROU_SCAN);
    }
  }

  /**
   * Construit une ligne du tableau "appareils" renvoyé par scannerAuxHome() : liste
   * BLANCHE de champs (AC2 — jamais de jeton, uid ou e-mail).
   *
   * @return array{nom:string, modele:string, mac:string, identifiant:string, enLigne:bool, enLigneLibelle:string, statut:string, statutLibelle:string}
   */
  private static function ligneResultatScan($_nom, $_modele, $_mac, $_identifiant, $_enLigne, $_statut) {
    return array(
      'nom' => $_nom,
      'modele' => $_modele,
      'mac' => $_mac,
      'identifiant' => $_identifiant,
      'enLigne' => (bool) $_enLigne,
      'enLigneLibelle' => self::libelleEnLigne($_enLigne),
      'statut' => $_statut,
      'statutLibelle' => self::libelleStatut($_statut),
    );
  }

  /**
   * Charge tous les équipements smartclim en UNE seule requête
   * (eqLogic::byType('smartclim')) et construit les index utilisés par le
   * rapprochement (§ 5.2 de la spec technique UC03) : par logicalId, par
   * auxhome_device_id, la liste complète (pour appareilsDisparus()) et l'ensemble des
   * noms déjà utilisés (pour nomUnique()).
   *
   * @return array{parLogicalId:array<string,smartclim>, parDeviceId:array<string,smartclim>, tous:smartclim[], noms:array<string,bool>}
   */
  private static function indexerEquipements() {
    $tous = eqLogic::byType('smartclim');
    $parLogicalId = array();
    $parDeviceId = array();
    $noms = array();
    foreach ($tous as $eqLogic) {
      $parLogicalId[$eqLogic->getLogicalId()] = $eqLogic;
      $deviceId = $eqLogic->getConfiguration('auxhome_device_id');
      if (is_string($deviceId) && $deviceId !== '') {
        $parDeviceId[$deviceId] = $eqLogic;
      }
      $noms[$eqLogic->getName()] = true;
    }
    return array(
      'parLogicalId' => $parLogicalId,
      'parDeviceId' => $parDeviceId,
      'tous' => $tous,
      'noms' => $noms,
    );
  }

  /**
   * Rapproche un appareil renvoyé par le cloud avec un équipement Jeedom déjà connu,
   * dans l'ordre imposé par § 5.2 de la spec technique UC03 (conforme à
   * .memory/analyse/smartclim-architecture-jeedom.md § 4) : MAC normalisée, puis MAC
   * inversée (journalisée en warning), puis auxhome_device_id déjà mémorisé.
   * `logicalId` n'est JAMAIS réécrit après création — c'est l'identité de
   * l'équipement, rien n'en garantit l'unicité au niveau SQL.
   *
   * @param string $_macNorm
   * @param string $_deviceId
   * @param array $_index Index construit par indexerEquipements().
   * @return smartclim|null
   */
  private static function chercherEquipementExistant($_macNorm, $_deviceId, array $_index) {
    if ($_macNorm !== '') {
      if (isset($_index['parLogicalId']['mac:' . $_macNorm])) {
        return $_index['parLogicalId']['mac:' . $_macNorm];
      }
      $macInversee = self::macInversee($_macNorm);
      if ($macInversee !== '' && isset($_index['parLogicalId']['mac:' . $macInversee])) {
        log::add('smartclim', 'warning', 'AUX Home : appareil rapproché via la MAC inversée (' . $_macNorm . ' / ' . $macInversee . ')');
        return $_index['parLogicalId']['mac:' . $macInversee];
      }
    }
    if ($_deviceId !== '' && isset($_index['parDeviceId'][$_deviceId])) {
      return $_index['parDeviceId'][$_deviceId];
    }
    return null;
  }

  /**
   * Crée un équipement Jeedom pour un appareil AUX Home nouvellement découvert
   * (AC1) : setName() UNIQUEMENT ici (AC3 — jamais réappelé sur un équipement
   * existant), setObject_id() JAMAIS appelé (AC4). Aucune smartclimCmd, aucune
   * capacité, aucune trame HVAC : hors périmètre UC03 (§ 0 de la spec technique).
   *
   * @param string $_logicalId
   * @param array $_appareil Normalisé par smartclimAuxHomeApi::normaliserAppareil().
   * @param string $_macNorm
   * @param array $_noms Noms déjà utilisés, par référence (nomUnique()).
   * @param array $_capacites Profil de capacités détecté (smartclimAuxHomeApi::capacitesAppareil()), UC04.
   * @return smartclim
   */
  private static function creerEquipement($_logicalId, array $_appareil, $_macNorm, array &$_noms, array $_capacites) {
    $eqLogic = new smartclim();
    $eqLogic->setEqType_name('smartclim');
    $eqLogic->setLogicalId($_logicalId);

    // Même fonction que celle appliquée par setName() (§ 6.4 de la spec technique) :
    // un alias non vide après nettoyage côté transport peut le redevenir une fois
    // passé au filtre du core -> repli sur le nom par défaut dans ce cas.
    $alias = $_appareil['nom'];
    $souhaite = (trim(cleanComponanteName($alias)) !== '') ? $alias : self::nomAppareilParDefaut($_macNorm);
    $eqLogic->setName(self::nomUnique($souhaite, $_noms));

    $eqLogic->setIsEnable(1);
    $eqLogic->setIsVisible(1);
    $eqLogic->setCategory('heating', 1);
    if ($_macNorm !== '') {
      $eqLogic->setConfiguration('mac', $_macNorm);
    }
    $eqLogic->setConfiguration('auxhome_device_id', $_appareil['identifiant']);
    $eqLogic->setConfiguration('modele', $_appareil['modele']);
    // Pose le profil de capacités AVANT le save() unique (UC04) : jamais un 2e save()
    // dédié, qui romprait l'invariant « une création = un seul save() ».
    $eqLogic->appliquerCapacites($_capacites);
    $eqLogic->save();

    return $eqLogic;
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
   * Équipements smartclim déjà connus, plausiblement issus d'AUX Home (§ 1 AC6 / § 5.2
   * de la spec technique — `auxhome_device_id` OU `mac` non vide), mais non retrouvés
   * dans la réponse de ce scan (AC6) : jamais supprimés ni désactivés ici — écran de
   * résultat + log 'warning' uniquement (§ 0 de la spec technique, décision actée avec
   * l'utilisateur). ⚠️ Un équipement smartclim créé manuellement (ou plus tard par un
   * autre transport) sans AUCUNE des deux clés n'est jamais candidat "disparu" : rien
   * ne le rapprochera jamais d'un scan AUX Home, il serait sinon signalé à chaque
   * cycle, indéfiniment (finding MAJOR de la revue croisée UC03, tour 1).
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
      $issuAuxHome = (is_string($deviceId) && $deviceId !== '') || (is_string($mac) && $mac !== '');
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
  private static function neutraliserPourLog($_valeur) {
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
    if ($_statut === 'erreur') {
      return __('Erreur lors de la création — consultez les logs du plugin', __FILE__);
    }
    return '';
  }

  /**
   * Libellé traduit de l'état en ligne/hors ligne d'un appareil (AC8).
   *
   * @param bool $_enLigne
   * @return string
   */
  private static function libelleEnLigne($_enLigne) {
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
   * @param string $_contexte '' ou smartclimException::CONTEXTE_REQUETE_INITIALE (seul
   *   cas où le message dépend du contexte).
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
    // smartclimException::TYPE_AUTH, et repli par défaut pour tout type inattendu.
    return __('Échec de la connexion — vérifiez vos identifiants et le pays sélectionné', __FILE__);
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
      if (!self::cycleEchu()) {
        return;
      }
      self::rafraichirAuxHome();
    } catch (Throwable $t) {
      log::add('smartclim', 'error', 'Cycle de rafraîchissement AUX Home : erreur inattendue : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
    }
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

      $cibles = self::equipementsParIdentifiant(eqLogic::byType('smartclim', true));
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
        $resultat['horsLigne'] = self::basculerHorsLigne($cibles, 'appareil AUX Home injoignable au dernier cycle');
        return $resultat;
      } catch (Throwable $t) {
        log::add('smartclim', 'error', 'Cycle de rafraîchissement AUX Home : erreur inattendue lors de la lecture du compte : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
        $resultat['echecType'] = smartclimException::TYPE_INTERNE;
        $resultat['echecContexte'] = '';
        $resultat['horsLigne'] = self::basculerHorsLigne($cibles, 'appareil AUX Home injoignable au dernier cycle');
        return $resultat;
      }

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
   * Purge la session AUX Home en cache dès que le mot de passe change. Ce hook ne
   * reçoit que le CHIFFRÉ ($value inutilisé, cf. spec technique § 0.4 : postConfig_*
   * d'une clé chiffrée ne voit jamais le clair) — mais la seule NOTIFICATION du
   * changement suffit à invalider une session obtenue avec l'ancien mot de passe
   * (AC6) : cache::delete() n'a besoin que de la clé de cache, jamais du secret.
   * Explicitement autorisé par UC01 § 4 : « le hook ne lit rien, il purge ».
   */
  public static function postConfig_auxhome_password($value) {
    smartclimAuxHomeApi::purgerSession();
  }

  /**
   * Purge la session AUX Home en cache dès que l'e-mail change (même motif que
   * postConfig_auxhome_password() ci-dessus).
   */
  public static function postConfig_auxhome_email($value) {
    smartclimAuxHomeApi::purgerSession();
  }

  /**
   * Purge la session AUX Home en cache dès que le pays change (même motif que
   * postConfig_auxhome_password() ci-dessus) : le cloud AUX Home route potentiellement
   * vers un jeu de clés différent par pays.
   */
  public static function postConfig_auxhome_country($value) {
    smartclimAuxHomeApi::purgerSession();
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
      $existantes[$cmdExistante->getLogicalId()] = true;
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
        } elseif ($existantes[$logicalId]->getTemplate('dashboard', '') === '') {
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
        } elseif ($cmd->getTemplate('dashboard', '') === '') {
          // Pose idempotente (§ 8.2 de la spec technique) : n'écrase jamais un widget
          // choisi à la main.
          $cmd->setTemplate('dashboard', 'smartclim::etat');
          $cmd->setTemplate('mobile', 'smartclim::etat');
        }

        $cmd->save();
        $crees++;
      } catch (Throwable $t) {
        log::add('smartclim', 'error', 'Création de la commande action "' . $logicalId . '" impossible (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '") : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
      }
    }
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
   * Point d'entrée UNIQUE du pilotage, appelé par smartclimCmd::execute() (UC06, §
   * 5.3/10 de la spec technique). Recrée une exception CURATÉE en français à chaque
   * point de sortie en échec (contrat @throws) : jamais un message technique affiché
   * tel quel côté navigateur.
   *
   * 1. session_write_close() gardé (§ 10.1 — n'affecte jamais un contexte cron/scénario)
   * 2. gardes (compteConfigure, auxhome_device_id, commande connue)
   * 3. construction de l'ordre GÉNÉRIQUE (+ power => 1 pour mode et consigne)
   * 4. validation de la consigne
   * 5. déduplication (AC7/AC10, § 7 de la spec technique)
   * 6. appliquerOrdre()
   * 7. enregistrerOrdre() + appliquerEtat(..., true)
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

    if (!self::compteConfigure()) {
      throw new smartclimException(__('Compte AUX Home non configuré : renseignez l\'e-mail et le mot de passe', __FILE__), smartclimException::TYPE_AUTH);
    }

    $identifiantAppareil = $this->getConfiguration('auxhome_device_id');
    if (!is_string($identifiantAppareil) || $identifiantAppareil === '') {
      throw new smartclimException(__('Cet équipement n\'est pas relié à un appareil AUX Home — relancez un scan', __FILE__), smartclimException::TYPE_INTERNE);
    }

    $definitions = $this->definitionsCommandesAction();
    if (!isset($definitions[$_logicalId])) {
      throw new smartclimException(__('Commande inconnue pour cet équipement', __FILE__), smartclimException::TYPE_INTERNE);
    }
    $definition = $definitions[$_logicalId];

    if ($_logicalId === self::CMD_RAFRAICHIR) {
      // UC07, § 7 de la spec technique : sort AVANT le calcul d'empreinte de
      // déduplication, AVANT le cache::set du marqueur de dédup et AVANT
      // appliquerOrdre() — un rafraîchissement est en lecture seule et AC6 exige une
      // mise à jour IMMÉDIATE, avaler un second clic contredirait le critère.
      $this->rafraichirMaintenant();
      return;
    }

    if ($_logicalId === self::CMD_CONSIGNE) {
      $ordre = array(
        smartclimCapabilities::CONCEPT_POWER => 1,
        smartclimCapabilities::CONCEPT_TARGET_TEMP => $this->ordreEffectifConsigne($_options),
      );
    } else {
      $ordre = $definition['ordre'];
    }

    $ordreTrie = $ordre;
    ksort($ordreTrie);
    $empreinte = sha1(json_encode($ordreTrie));
    $cleDedup = self::CLE_CACHE_DEDUP . $this->getId() . '::' . $empreinte;
    if (cache::byKey($cleDedup)->getValue(null) !== null) {
      // Retour SILENCIEUX (§ 10 de la spec technique) : aucune exception, aucun réseau,
      // aucune écriture d'état — le premier ordre l'a déjà fait.
      log::add('smartclim', 'debug', 'Ordre dédupliqué (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '", commande "' . $_logicalId . '")');
      return;
    }
    // Marqueur posé AVANT l'appel réseau (§ 7) : couvre le double-clic pendant que le
    // 1er ordre est en vol.
    cache::set($cleDedup, '1', self::DUREE_DEDUP_ORDRE);

    try {
      $ordreApplique = smartclimAuxHomeApi::appliquerOrdre($identifiantAppareil, $ordre);
    } catch (smartclimException $e) {
      // Un ordre échoué doit rester rejouable immédiatement (§ 7).
      cache::delete($cleDedup);
      log::add('smartclim', 'error', 'Commande action "' . $_logicalId . '" échouée (équipement "' . self::neutraliserPourLog($this->getHumanName()) . '", type ' . $e->getType() . ') : ' . self::neutraliserPourLog($e->getMessage()));
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
      throw new smartclimException(__('Erreur interne lors de l\'envoi de la commande — consultez les logs du plugin', __FILE__), smartclimException::TYPE_INTERNE);
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
    $resultat = self::rafraichirAuxHome();
    if ($resultat['echecType'] !== null) {
      throw new smartclimException(self::messageErreurAuxHome($resultat['echecType'], $resultat['echecContexte']), $resultat['echecType']);
    }
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

    // Littéral figé (le transport AUX Home est le seul actif au MVP) : "change" au
    // premier cycle uniquement, hors de l'agrégation ci-dessus (spec technique § AC6 en
    // détail).
    $this->checkAndUpdateCmd(self::CMD_TRANSPORT, smartclimCapabilities::libelleTransport(smartclimCapabilities::TRANSPORT_AUX_HOME));

    if ($change) {
      $this->checkAndUpdateCmd(self::CMD_DERNIERE_MAJ, date('d/m/Y H:i:s'));
    }

    return $change;
  }

  // Fonction exécutée automatiquement avant la suppression de l'équipement
  //
  // UC06, § 5.3 : hygiène — purge la mémoire des valeurs commandées de cet équipement,
  // sans quoi l'entrée de cache resterait orpheline jusqu'à expiration naturelle (60 s,
  // sans conséquence fonctionnelle, mais évite un déchet inutile).
  public function preRemove() {
    cache::delete(self::CLE_CACHE_ORDRES . $this->getId());
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

  /*     * **********************Getteur Setteur*************************** */
}
