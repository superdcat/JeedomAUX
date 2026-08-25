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
            if ($modifie) {
              $eqLogic->save();
            }
            $compteurs['existants']++;
            $appareilsResultat[] = self::ligneResultatScan($eqLogic->getName(), $appareil['modele'], $macNorm, $identifiant, $appareil['enLigne'], 'existant');
          } else {
            $eqLogic = self::creerEquipement($logicalId, $appareil, $macNorm, $index['noms']);
            $consommes[] = $logicalId;
            $compteurs['crees']++;
            $appareilsResultat[] = self::ligneResultatScan($eqLogic->getName(), $appareil['modele'], $macNorm, $identifiant, $appareil['enLigne'], 'cree');
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
   * @return smartclim
   */
  private static function creerEquipement($_logicalId, array $_appareil, $_macNorm, array &$_noms) {
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

  /*
  * Fonction exécutée automatiquement toutes les minutes par Jeedom
  public static function cron() {}
  */

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
  public function preSave() {
  }

  // Fonction exécutée automatiquement après la sauvegarde (création ou mise à jour) de l'équipement
  public function postSave() {
  }

  // Fonction exécutée automatiquement avant la suppression de l'équipement
  public function preRemove() {
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

  // Exécution d'une commande
  public function execute($_options = array()) {
  }

  /*     * **********************Getteur Setteur*************************** */
}
