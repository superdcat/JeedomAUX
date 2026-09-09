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
// Rend ce fichier autonome (même motif que smartclimAuxHomeApi.class.php) : toutes les
// méthodes publiques de cette classe lèvent une smartclimException, que l'autoloader du
// core ne résoudra jamais seul (cf. core/php/smartclim.inc.php). require_once idempotent.
require_once __DIR__ . '/smartclimException.class.php';
// UC02 du domaine post-mvp/03-cloud-aux-legacy : capacitesAppareil() ci-dessous appelle
// smartclimCapabilities::bornesParDefaut()/TRANSPORT_AUX_CLOUD_LEGACY. require_once
// idempotent, sans coût quand smartclim.inc.php l'a déjà chargée.
require_once __DIR__ . '/smartclimCapabilities.class.php';

/**
 * Brique de transport "AUX Cloud legacy" (cloud historique Broadlink, connu aussi sous
 * AC Freedom / AUX Cloud, hôtes app-service-(region).smarthomecs.* ou ibroadlink.com).
 *
 * UC01 du domaine post-mvp/03-cloud-aux-legacy — authentification multi-régions
 * (login(), session(), purgerSession()). UC02 du même domaine y ajoute la DÉCOUVERTE
 * du parc (familles -> appareils propres et partagés -> jeu de paramètres réellement
 * annoncé par chaque appareil -> profil de capacités générique) : listerAppareils(),
 * capacitesAppareil(), etatAppareil(). UC03 du même domaine y ajoute enfin la LECTURE et
 * l'ÉCRITURE continues de l'état (lireParametres(), appliquerOrdre(), decoderParametres(),
 * jetonsAppareil(), sonderParametre()) derrière l'unique route déjà appelée par la
 * découverte (device/control/v2/sdkcontrol) : requeteSdkControl() en est le point unique.
 *
 * Conformément à CLAUDE.md (« tous les appels HTTP passent par la brique du transport
 * concerné »), c'est ici et nulle part ailleurs que vit la connaissance de protocole
 * propre à ce cloud : table des régions, chiffrement du corps de login, en-têtes
 * d'usurpation applicative, et classement des erreurs cURL/HTTP/métier.
 *
 * ⚠️ Ce transport ne partage QUASIMENT rien au niveau HTTP avec smartclimAuxHomeApi
 * (enveloppe JSON + bearer, contre corps binaire chiffré + 9 en-têtes d'usurpation +
 * sentinelle "status") : elle porte donc son PROPRE cURL, comme smartclimBroadlinkLan
 * porte son propre UDP et smartclimDemon son propre socket de pont (§ 4.1 de la spec
 * technique — un helper HTTP commun aurait été un sac de paramètres).
 */
class smartclimAuxCloudApi {
  /*     * *************************Attributs****************************** */

  // Timeouts cURL (secondes). Le login legacy est UNE SEULE requête (pas de getPubkey
  // séparé comme AUX Home) : CURLOPT_TIMEOUT borne donc effectivement toute l'opération,
  // aucune arithmétique de budget global n'est nécessaire ici (§ 7.4 de la spec
  // technique). $_budget reste un PARAMÈTRE conservé pour l'UC02 (qui enchaînera
  // plusieurs requêtes authentifiées et devra le propager) — additif pur, zéro logique
  // nouvelle.
  const TIMEOUT_CONNEXION = 5;
  const TIMEOUT_REQUETE = 10;
  const BUDGET_LOGIN = 12;

  // Budget de temps GLOBAL d'une découverte (UC02, § 6.1 de la spec technique), mesuré
  // depuis l'entrée de listerAppareils() — session (login éventuel) COMPRISE. Parité
  // avec smartclimAuxHomeApi::BUDGET_SCAN : chaque requête reçoit
  // max(3, min(TIMEOUT_REQUETE, restant)), arrêt DUR évalué avant chaque famille et
  // chaque appareil.
  const BUDGET_DECOUVERTE = 25;

  // Longueur maximale acceptée pour un identifiant de produit avant journalisation (AC4,
  // § 7.3 de la spec technique) : 32 caractères hexadécimaux observés (§ 1.3), marge
  // large pour ne jamais tronquer une valeur exploitable en diagnostic.
  const PRODUIT_INCONNU_MAX = 64;

  // Cache (chiffré) de la session AUX Cloud legacy — parallèle de
  // smartclimAuxHomeApi::CLE_CACHE_SESSION, GLOBALE au compte (pas par appareil). TTL
  // alignée sur AUX Home (30 min) par choix d'alignement, pas par mesure : la durée de
  // vie réelle de "loginsession" est inconnue (§ 7.6 de la spec technique) — 'cree_le'
  // est stocké dès cette UC pour permettre de la calibrer factuellement quand l'UC02
  // ajoutera le premier appelant authentifié réel (rejeu réactif).
  const CLE_CACHE_SESSION = 'smartclim::session_auxcloud';
  const DUREE_CACHE_SESSION = 1800;

  // Contextes techniques d'une smartclimException TYPE_RESEAU (§ 7.3 de la spec
  // technique) : pas de 5e type d'exception dédié au TLS — un échec TLS EST un échec de
  // couche réseau, et plusieurs branches du plugin discriminent sur TYPE_RESEAU. Même
  // patron que smartclimAuxHomeApi::CONTEXTE_ORDRE_REFUSE.
  const CONTEXTE_CERTIFICAT = 'certificat';
  const CONTEXTE_MAGASIN_LOCAL = 'magasin_local';

  // UC03 du domaine post-mvp/03-cloud-aux-legacy (§ 5.2 de sa spec technique) : budget
  // de temps GLOBAL d'une lecture de paramètres, réserve d'une requête d'écriture, et
  // budget de rejeu après re-login — mêmes doctrines que BUDGET_DECOUVERTE/BUDGET_LOGIN
  // ci-dessus (session comprise, arithmétique jamais en dur ailleurs).
  const BUDGET_ETAT = 12;
  const RESERVE_ORDRE = 4;
  const BUDGET_REJEU_ORDRE = 10;

  // Garde de plausibilité (AC5, § 7 de la spec technique) : `temp`/`envtemp` sont des
  // entiers en DIXIÈMES de degré (§ 1.4) — hors de cette plage, la clé est traitée comme
  // ABSENTE (jamais un repli), ce qui rend « 2,4 » et « 240 » structurellement
  // inatteignables, y compris si un firmware renvoyait déjà des degrés entiers.
  const TEMP_MIN_PLAUSIBLE = 50;
  const TEMP_MAX_PLAUSIBLE = 500;

  // Contextes techniques DÉDIÉS à ce cycle (§ 5.2/7 de la spec technique) :
  // CONTEXTE_APPAIRAGE_REFUSE arme le rejeu d'obtention de jetons (§ 3.8/D8, côté
  // orchestrateur smartclim::) ; CONTEXTE_ETAT_INCONNU est levé par
  // smartclim::etatMarcheCourant() (§ 3.3/D3) quand aucune des 4 sources ne permet de
  // connaître l'état de marche — vit ICI par cohérence avec les autres contextes de ce
  // transport (même doctrine que smartclimBroadlinkLan::CONTEXTE_ECRITURE_NON_CONFIRMEE,
  // consommée par un point d'entrée hors de sa propre classe).
  const CONTEXTE_APPAIRAGE_REFUSE = 'appairage_refuse';
  const CONTEXTE_ETAT_INCONNU = 'etat_inconnu';

  // --- Constantes de protocole reverse-engineered depuis l'application mobile AUX
  // Cloud legacy (backend Broadlink historique), publiées par
  // GijsZwegers/com.zwegersit.auxairco (fichier lib/auxcloud/legacyConstants.ts,
  // branche "main", licence MIT — lui-même porté de fparrav/homebridge-aux-cloud et
  // maeek/ha-aux-cloud, également MIT), recoupées le 2026-09-08. Ce ne sont PAS des
  // secrets utilisateur : ce sont des constantes d'identité applicative FIXES du
  // fournisseur (même catégorie que smartclimAuxHomeApi::STATIC_APP_TOKEN /
  // ACCOUNT_AES_KEY) — sans elles, aucun login n'est possible auprès de ce cloud.
  // Ne jamais les journaliser ni les exposer dans une réponse AJAX.
  const PASSWORD_ENCRYPT_KEY = '4969fj#k23#';
  const BODY_ENCRYPT_KEY = 'xgx3d*fe3478$ukx';
  const TIMESTAMP_TOKEN_ENCRYPT_KEY = 'kdixkdqp54545^#*';
  // Vecteur d'initialisation AES fixe, en HEXADÉCIMAL (greppable, aucun piège
  // d'échappement d'octet non imprimable comme "\x…") — hex2bin() à l'usage (§ 3 de la
  // spec technique). 16 octets.
  const AES_INITIAL_VECTOR_HEX = 'EAAAAA3ABB5862A21918B5771D1615AA';
  // UC02, § 1.5 de la spec technique : embarquée DÈS cette UC (et non à l'UC03 comme
  // l'annonçait par erreur le § 1.5 de la spec technique d'UC01) — la détection de
  // capacités d'AC2 passe par "sdkcontrol", qui exige "?license=". Recopiée VERBATIM
  // depuis legacyConstants.ts (même licence MIT, même bloc d'attribution que les
  // constantes ci-dessus).
  const LEGACY_LICENSE = 'PAFbJJ3WbvDxH5vvWezXN5BujETtH/iuTtIIW5CE/SeHN7oNKqnEajgljTcL0fBQQWM0XAAAAAAnBhJyhMi7zIQMsUcwR/PEwGA3uB5HLOnr+xRrci+FwHMkUtK7v4yo0ZHa+jPvb6djelPP893k7SagmffZmOkLSOsbNs8CAqsu8HuIDs2mDQAAAAA=';
  const LICENSE_ID = '3c015b249dd66ef0f11f9bef59ecd737';
  const COMPANY_ID = '48eb1b36cf0202ab2ef07b880ecda60d';
  const SPOOF_APP_VERSION = '2.2.10.456537160';
  const SPOOF_USER_AGENT = 'Dalvik/2.1.0 (Linux; U; Android 12; SM-G991B Build/SP1A.210812.016)';
  const SPOOF_SYSTEM = 'android';
  const SPOOF_APP_PLATFORM = 'android';

  /*     * ***********************Methode static*************************** */

  /**
   * Table UNIQUE des régions proposables : code => ['hote' => URL de base,
   * 'libelle' => libellé traduit]. « Ajouter une région = ajouter une entrée » (§ 6.1 de
   * la spec technique). Ordre FIXE, Europe d'abord — pas de tri alphabétique (4
   * entrées ; un tri exigerait la désaccentuation de « États-Unis » pour zéro bénéfice).
   *
   * ⚠️ RUS est à SOURCE UNIQUE (maeek/ha-aux-cloud seul ; le client TypeScript recoupé
   * ne porte que EU/USA/CN) : l'hôte répond en TLS mais son acceptation d'un login n'est
   * établie par aucune source (R6 de la spec technique).
   *
   * @return array<string,array{hote:string,libelle:string}>
   */
  private static function regions() {
    return array(
      'EU' => array(
        'hote' => 'https://app-service-deu-f0e9ebbb.smarthomecs.de',
        'libelle' => __('Europe', __FILE__),
      ),
      'USA' => array(
        'hote' => 'https://app-service-usa-fd7cc04c.smarthomecs.com',
        'libelle' => __('États-Unis', __FILE__),
      ),
      'CHN' => array(
        'hote' => 'https://app-service-chn-31a93883.ibroadlink.com',
        'libelle' => __('Chine', __FILE__),
      ),
      'RUS' => array(
        'hote' => 'https://app-service-rus-b8bbc3be.smarthomecs.com',
        'libelle' => __('Russie', __FILE__),
      ),
    );
  }

  /**
   * Régions proposables pour le compte AUX Cloud legacy : code => libellé traduit.
   * Sert à peupler la liste déroulante (fermée, § 6.4 de la spec technique) de la page
   * de configuration. Simple délégation — consommée via
   * smartclim::regionsDisponiblesAuxCloud() (CLAUDE.md § Conventions).
   *
   * @return array<string,string>
   */
  public static function regionsDisponibles() {
    $sortie = array();
    foreach (self::regions() as $code => $region) {
      $sortie[$code] = $region['libelle'];
    }
    return $sortie;
  }

  /**
   * Prédicat pur d'appartenance à la table des régions — c'est la LISTE BLANCHE : il
   * n'existe aucune regex de forme de région, seule cette table fait foi (§ 6.1 de la
   * spec technique).
   *
   * @param mixed $_code
   * @return bool
   */
  public static function regionConnue($_code) {
    $regions = self::regions();
    return is_string($_code) && isset($regions[$_code]);
  }

  /**
   * Hôte de base d'une région, ENTIÈREMENT issu de la table serveur — aucune entrée
   * utilisateur n'atteint jamais CURLOPT_URL (§ 5 de la spec technique, même doctrine
   * anti-SSRF que smartclimAuxHomeApi::routesDiagnostic()).
   *
   * @param string $_code
   * @return string
   * @throws smartclimException TYPE_INTERNE si la région est inconnue.
   */
  private static function hoteRegion($_code) {
    $regions = self::regions();
    if (!isset($regions[$_code])) {
      throw new smartclimException('AUX Cloud legacy : région inconnue', smartclimException::TYPE_INTERNE);
    }
    return $regions[$_code]['hote'];
  }

  /**
   * Authentifie auprès du cloud AUX Cloud legacy (une seule requête, "account/login")
   * et ÉCRIT la session résultante en cache (§ 1.2/1.5 de la spec technique). Toujours
   * une exception "propre" en cas d'échec : recréée juste avant propagation (catch
   * ci-dessous) pour ne jamais laisser filtrer, via une frame interne, le corps de
   * requête chiffré ou le sha1 du mot de passe (même motif que
   * smartclimAuxHomeApi::login(), § 3.1 de sa propre spec technique).
   *
   * @param int $_budget Budget de temps de CETTE requête, en secondes (paramètre
   *   conservé pour l'UC02, additif pur — § 7.4 de la spec technique).
   * @return array{loginsession:string,userid:string,cree_le:int}
   * @throws smartclimException Toujours une exception "propre".
   */
  public static function login($_budget = self::BUDGET_LOGIN) {
    try {
      // Garde en ligne AVANT tout appel réseau (§ 6.1 de la spec technique) : mot de
      // passe vide -> TYPE_AUTH immédiat.
      $shaMotDePasse = self::empreinteMotDePasse();

      $charge = array(
        'email' => smartclim::emailAuxCloud(),
        'password' => $shaMotDePasse,
        'companyid' => self::COMPANY_ID,
        'lid' => self::LICENSE_ID,
      );
      // JSON_UNESCAPED_SLASHES : parité d'octets avec les références (§ 3 de la spec
      // technique) — le backend recalcule le MD5/AES sur ce qu'il déchiffre, donc
      // l'ordre des clés nous est indifférent, mais la parité retire une variable du
      // diagnostic au premier essai réel.
      $jsonCharge = json_encode($charge, JSON_UNESCAPED_SLASHES);
      if ($jsonCharge === false) {
        throw new smartclimException('AUX Cloud legacy : échec de l\'encodage JSON du corps de login', smartclimException::TYPE_INTERNE);
      }

      // token = md5(json_payload + BODY_ENCRYPT_KEY), hex minuscule (§ 1.2).
      $token = md5($jsonCharge . self::BODY_ENCRYPT_KEY);

      // ⚠️ INVARIANT CRITIQUE (§ 2 de la spec technique) : la chaîne d'horodatage est
      // calculée UNE SEULE FOIS ici, puis utilisée À L'IDENTIQUE comme valeur de
      // l'en-tête "timestamp" ET comme graine du MD5 de dérivation de clé AES. Ne
      // JAMAIS recalculer une 2e fois cette chaîne : la moindre divergence (même d'un
      // caractère) produit une clé AES différente et un échec silencieux, indistinguable
      // d'un mauvais mot de passe.
      $horodatage = self::horodatageGraine();

      $corpsChiffre = self::chiffrerCorps($jsonCharge, $horodatage);

      // Le login legacy est UNE SEULE requête : CURLOPT_TIMEOUT borne effectivement
      // l'opération (§ 7.4 de la spec technique), aucune soustraction d'écoulé.
      $tempsRequete = (int) max(3, min(self::TIMEOUT_REQUETE, $_budget));

      $donnees = self::requete('/account/login', $corpsChiffre, $horodatage, $token, $tempsRequete);

      // ⚠️⚠️ La sentinelle de succès est "status == 0", PAS "code == 200" (§ 1.3 de la
      // spec technique — le piège le plus coûteux que cette UC puisse produire).
      $status = isset($donnees['status']) && is_scalar($donnees['status']) ? (int) $donnees['status'] : -1;
      if ($status !== 0) {
        // § 6.1.1 de la spec technique UC02 : journaliserErreurLegacy() porte désormais
        // $_contexte en 1er paramètre — appel mis à jour DANS LE MÊME GESTE que
        // l'extension de signature (sinon $donnees atterrirait dans $_contexte, un log
        // d'erreur de login silencieusement cassé, invisible à php -l comme en CI).
        self::journaliserErreurLegacy('login', $donnees);
        throw new smartclimException('AUX Cloud legacy login : status ' . $status, smartclimException::TYPE_AUTH);
      }

      // "loginsession" et "userid" sont au PREMIER NIVEAU de l'objet, pas sous une clé
      // "data" (§ 1.3 — 2e piège de copier-coller).
      $loginsession = isset($donnees['loginsession']) ? $donnees['loginsession'] : '';
      if (!self::valeurEnteteConforme($loginsession, 256)) {
        throw new smartclimException('AUX Cloud legacy : jeton de session absent ou de format inattendu', smartclimException::TYPE_PROTOCOLE);
      }
      // userid — NON BLOQUANT, délibérément (§ 7.2 de la spec technique) : un login
      // réussi n'est jamais remis en cause par un userid absent ou non conforme.
      $userid = isset($donnees['userid']) ? $donnees['userid'] : '';
      if (!self::valeurEnteteConforme($userid, 128)) {
        log::add('smartclim', 'warning', 'AUX Cloud legacy : connexion réussie mais userid absent ou de format inattendu');
        $userid = '';
      }

      // Jeton tronqué à 6 caractères max en debug — jamais le jeton complet (AC7).
      log::add('smartclim', 'debug', 'AUX Cloud legacy : connexion réussie (loginsession=' . substr($loginsession, 0, 6) . '...)');

      $creeLe = time();
      cache::set(self::CLE_CACHE_SESSION, utils::encrypt(json_encode(array(
        'loginsession' => $loginsession,
        'userid' => $userid,
        'empreinte' => self::empreinteIdentifiants(),
        'cree_le' => $creeLe,
      ))), self::DUREE_CACHE_SESSION);

      return array('loginsession' => $loginsession, 'userid' => $userid, 'cree_le' => $creeLe);
    } catch (smartclimException $e) {
      // Recrée l'exception À CE POINT D'APPEL : sa trace d'origine peut embarquer, via
      // la frame de requete(), le corps chiffré ou le sha1 du mot de passe. La
      // reconstruire ici (frame de login(), qui ne prend aucun paramètre sensible) fait
      // mourir cette trace sur place (même motif que smartclimAuxHomeApi::login()).
      throw new smartclimException($e->getMessage(), $e->getType(), $e->getContexte());
    }
  }

  /**
   * Renvoie une session AUX Cloud legacy valide : celle en cache si elle correspond
   * toujours aux identifiants actuellement enregistrés (empreinte e-mail+région) ET
   * porte un jeton de forme conforme, sinon retombe sur login() — qui, lui, réauthentifie
   * ET écrit la nouvelle session en cache. Cette fonction ne fait donc qu'une chose :
   * LIRE le cache, jamais y écrire elle-même (même patron que
   * smartclimAuxHomeApi::session()).
   *
   * ⚠️ Aucune action AJAX de cette UC n'appelle cette méthode
   * (testerConnexionAuxCloud() appelle TOUJOURS login()) : premier consommateur réel
   * attendu à l'UC02 (R1/D-3 de la spec technique).
   *
   * @param int $_budgetLogin Budget PROPAGÉ à login() si un login est nécessaire.
   * @return array{loginsession:string,userid:string,cree_le:int} 'cree_le' TOUJOURS
   *   présent (0 = inconnu) dans les DEUX branches.
   * @throws smartclimException Toujours une exception "propre".
   */
  public static function session($_budgetLogin = self::BUDGET_LOGIN) {
    // Garde-fou explicite (même motif que smartclimAuxHomeApi::session()) : sans elle,
    // un compte non configuré échouerait plus loin en TYPE_INTERNE (chiffrement),
    // message trompeur au lieu de "compte non configuré".
    if (!smartclim::compteAuxCloudConfigure()) {
      throw new smartclimException('Compte AUX Cloud legacy non configuré (email/mot de passe/région)', smartclimException::TYPE_AUTH);
    }
    try {
      $brut = cache::byKey(self::CLE_CACHE_SESSION)->getValue(null);
      if ($brut !== null) {
        $dechiffre = utils::decrypt($brut);
        if (is_string($dechiffre) && $dechiffre !== '') {
          $session = json_decode($dechiffre, true);
          if (
            is_array($session)
            && isset($session['loginsession'], $session['userid'], $session['empreinte'])
            && self::valeurEnteteConforme($session['loginsession'], 256)
            && $session['empreinte'] === self::empreinteIdentifiants()
          ) {
            log::add('smartclim', 'debug', 'AUX Cloud legacy : session en cache valide, réutilisée');
            $creeLe = isset($session['cree_le']) && is_numeric($session['cree_le']) ? (int) $session['cree_le'] : 0;
            return array('loginsession' => $session['loginsession'], 'userid' => $session['userid'], 'cree_le' => $creeLe);
          }
        }
        // Empreinte différente, jeton non conforme, ou entrée corrompue : les
        // identifiants ont changé par un chemin qui ne passe pas par config::save()
        // (restauration, écriture SQL, migration) — on purge et on relogue.
        log::add('smartclim', 'debug', 'AUX Cloud legacy : session en cache invalide (empreinte ou jeton), purge et nouvelle connexion');
        self::purgerSession();
      } else {
        log::add('smartclim', 'debug', 'AUX Cloud legacy : aucune session en cache, nouvelle connexion');
      }
      // login() renvoie déjà EXACTEMENT les 3 clés du contrat de session() (contrairement
      // à smartclimAuxHomeApi::login(), qui porte en plus 'pseudo') : pas de repli à
      // reconstruire ici.
      return self::login($_budgetLogin);
    } catch (smartclimException $e) {
      throw new smartclimException($e->getMessage(), $e->getType(), $e->getContexte());
    }
  }

  /**
   * Purge la session AUX Cloud legacy en cache. Appelée explicitement par les hooks
   * postConfig_auxcloud_* de smartclim (config::remove() ne déclenche pas postConfig_*).
   */
  public static function purgerSession() {
    cache::delete(self::CLE_CACHE_SESSION);
  }

  /**
   * Empreinte des identifiants actuellement enregistrés, utilisée par session() pour
   * détecter un changement qui ne serait pas passé par config::save(). Ne contient
   * JAMAIS le mot de passe (cela le remettrait sur la pile du cache). ⚠️ La RÉGION en
   * fait partie : mêmes identifiants sur une autre région = autre hôte, donc autre
   * session (§ 6.1 de la spec technique).
   *
   * @return string
   */
  private static function empreinteIdentifiants() {
    return sha1(smartclim::emailAuxCloud() . '|' . smartclim::regionAuxCloud());
  }

  /**
   * Équivalent de mot de passe pour le corps de login : sha1(mot de passe +
   * PASSWORD_ENCRYPT_KEY). ⚠️ SANS PARAMÈTRE : lit config::byKey('auxcloud_password', …)
   * elle-même, au plus près de l'usage (doctrine identique à
   * smartclimAuxHomeApi::chiffrerMotDePasse()) — une trace PHP expose les arguments de
   * chaque frame, ne jamais faire transiter le mot de passe en clair par un paramètre.
   * Sa sortie est elle-même un ÉQUIVALENT DE MOT DE PASSE : jamais journalisée, jamais
   * rendue hors du corps chiffré.
   *
   * @return string sha1 hex minuscule.
   * @throws smartclimException TYPE_AUTH si le mot de passe est vide en base.
   */
  private static function empreinteMotDePasse() {
    $motDePasse = config::byKey('auxcloud_password', 'smartclim');
    if ($motDePasse == '') {
      throw new smartclimException('AUX Cloud legacy : mot de passe vide en base', smartclimException::TYPE_AUTH);
    }
    return sha1($motDePasse . self::PASSWORD_ENCRYPT_KEY);
  }

  /**
   * Chaîne d'horodatage servant DEUX FOIS : valeur de l'en-tête "timestamp" ET graine du
   * MD5 de dérivation de clé AES (§ 2 de la spec technique). Calculée SANS AUCUNE
   * arithmétique flottante — ni (string) microtime(true) (dépend de la précision du
   * php.ini et, avant PHP 8.0, de LC_NUMERIC), ni sprintf('%.3f', …) (%f est documenté
   * "locale aware" en PHP), ni (int) (microtime(true) * 1000) (l'epoch en millisecondes
   * dépasse un entier 32 bits sur le Raspberry Pi OS armhf ciblé).
   *
   * Format retenu : "<secondes>.<3 premiers chiffres de la fraction>" — celui du client
   * TypeScript recoupé, en production dans une application publiée. L'INVARIANT n'est
   * PAS ce format précis : c'est l'IDENTITÉ OCTET À OCTET entre la valeur émise en
   * en-tête et la graine MD5, garantie ici en ne calculant la chaîne qu'UNE FOIS.
   *
   * @return string
   */
  private static function horodatageGraine() {
    $morceaux = explode(' ', microtime());
    $secondes = (isset($morceaux[1]) && preg_match('/\A[0-9]{1,12}\z/', $morceaux[1]) === 1)
      ? $morceaux[1]
      : (string) time();
    $fraction = '000';
    if (isset($morceaux[0]) && preg_match('/\A0[.,]([0-9]{3})/', $morceaux[0], $correspondance) === 1) {
      $fraction = $correspondance[1];
    }
    // On TOLÈRE une virgule en entrée (locale), on n'en ÉMET jamais une en sortie.
    return $secondes . '.' . $fraction;
  }

  /**
   * Chiffre le corps JSON du login : zero padding PUIS AES-128-CBC (§ 3 de la spec
   * technique). ⚠️ OPENSSL_ZERO_PADDING signifie « ne pas ajouter de remplissage », pas
   * « remplir de zéros » : le rembourrage à zéro se fait donc à la MAIN, AVANT l'appel à
   * openssl_encrypt() — sans quoi PHP ajouterait un PKCS7 (corps indéchiffrable par le
   * backend), et avec ce drapeau sur une entrée non alignée, openssl_encrypt() renverrait
   * simplement false.
   *
   * Vide la file d'erreurs OpenSSL en 1ère ligne (retour ignoré) : cette file est
   * globale au PROCESSUS et jamais remise à zéro par PHP entre deux appels — une erreur
   * laissée par un OpenSSL sans rapport serait sinon attribuée à tort à ce chiffrement.
   * try/catch(Throwable) SANS getMessage() ni getTraceAsString() (§ 6.1 de la spec
   * technique) : même un fragment de mot de passe ne doit jamais atteindre un log via une
   * trace.
   *
   * @param string $_json Corps JSON en clair.
   * @param string $_horodatage Chaîne produite par horodatageGraine() (même appel que
   *   celui qui a servi à l'en-tête "timestamp" — jamais recalculée ici).
   * @return string Corps binaire chiffré, prêt pour CURLOPT_POSTFIELDS.
   * @throws smartclimException TYPE_INTERNE.
   */
  private static function chiffrerCorps($_json, $_horodatage) {
    while (openssl_error_string() !== false) {
      // Purge de la file d'erreurs OpenSSL — retour intentionnellement ignoré.
    }
    try {
      $cle = md5($_horodatage . self::TIMESTAMP_TOKEN_ENCRYPT_KEY, true);
      $iv = hex2bin(self::AES_INITIAL_VECTOR_HEX);
      $donnees = $_json;
      $rembourrage = (16 - (strlen($donnees) % 16)) % 16;
      if ($rembourrage > 0) {
        $donnees .= str_repeat("\0", $rembourrage);
      }
      $chiffre = openssl_encrypt($donnees, 'aes-128-cbc', $cle, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
      if ($chiffre === false) {
        log::add('smartclim', 'error', 'AUX Cloud legacy : échec du chiffrement AES du corps de login : ' . openssl_error_string());
        throw new smartclimException('AUX Cloud legacy : échec du chiffrement AES du corps de login', smartclimException::TYPE_INTERNE);
      }
      return $chiffre;
    } catch (smartclimException $e) {
      throw $e;
    } catch (Throwable $t) {
      // 🚫 Ni getMessage() ni getTraceAsString() ici (§ 6.1 de la spec technique) : un
      // fragment de mot de passe pourrait y figurer.
      log::add('smartclim', 'error', 'AUX Cloud legacy : erreur interne pendant le chiffrement du corps de login (' . get_class($t) . ')');
      throw new smartclimException('AUX Cloud legacy : erreur interne pendant le chiffrement du corps de login', smartclimException::TYPE_INTERNE);
    }
  }

  /**
   * Exécute la requête cURL de login et classe l'erreur — ordre IMPÉRATIF (§ 7 de la
   * spec technique, étapes 1 à 5 ; les étapes 6/7, qui dépendent des champs métier
   * "status"/"loginsession", restent à la charge de login() lui-même). Seul point cURL
   * de cette brique (§ 4.1). 🚫 CURLOPT_VERBOSE / CURLOPT_STDERR / CURLOPT_DEBUGFUNCTION
   * INTERDITS : le mode verbose écrirait le corps chiffré et les en-têtes sur stderr.
   *
   * @param string $_chemin Chemin de l'API, ex. '/account/login'.
   * @param string $_corpsChiffre Corps BINAIRE chiffré, prêt pour CURLOPT_POSTFIELDS.
   * @param string $_horodatage Valeur de l'en-tête "timestamp" (§ 2).
   * @param string $_token Valeur de l'en-tête "token" (§ 1.2).
   * @param int $_tempsRequete Timeout de cette requête, en secondes.
   * @param array{loginsession:string,userid:string}|null $_session En-têtes de session
   *   optionnels : non fournis par le login lui-même, fournis par TOUS les appels
   *   authentifiés de découverte (UC02).
   * @param array{familyid?:string,query?:string,corps_json?:string} $_options Extension
   *   ADDITIVE (UC02, § 6.1 de la spec technique) : 'query' (suffixe de query string,
   *   TOUJOURS un littéral serveur, ex. '?action=select'), 'corps_json' (corps JSON en
   *   CLAIR — remplace $_corpsChiffre pour toutes les routes de découverte, § 1.1 : SEULE
   *   la route de login a un corps chiffré), 'familyid' (donnée backend qui repart dans
   *   un EN-TÊTE HTTP — validée par valeurEnteteConforme() AVANT concaténation, § 7.1).
   * @return array Enveloppe JSON décodée.
   * @throws smartclimException TYPE_RESEAU ou TYPE_PROTOCOLE.
   */
  private static function requete($_chemin, $_corpsChiffre, $_horodatage, $_token, $_tempsRequete, $_session = null, array $_options = array()) {
    $hote = self::hoteRegion(smartclim::regionAuxCloud());
    // 'query' est TOUJOURS un littéral SERVEUR (§ 7.1 de la spec technique — aucune
    // entrée utilisateur n'atteint jamais CURLOPT_URL) : '?action=select',
    // '?querytype=shared', ou '?license=' . LEGACY_LICENSE (constante embarquée).
    $chemin = $_chemin . (isset($_options['query']) && is_string($_options['query']) ? $_options['query'] : '');

    $entetes = array(
      'Content-Type: application/x-java-serialized-object',
    );
    // § 1.1 de la spec technique UC02 : SEULE la route de login envoie "timestamp"/
    // "token" — les routes de découverte n'en émettent AUCUN. Traitement conditionnel
    // (émis SEULEMENT si non vides), même doctrine que "loginsession"/"userid" ci-dessous.
    if ($_horodatage !== '' && $_horodatage !== null) {
      $entetes[] = 'timestamp: ' . $_horodatage;
    }
    if ($_token !== '' && $_token !== null) {
      $entetes[] = 'token: ' . $_token;
    }
    $entetes[] = 'licenseId: ' . self::LICENSE_ID;
    $entetes[] = 'lid: ' . self::LICENSE_ID;
    $entetes[] = 'language: en';
    $entetes[] = 'appVersion: ' . self::SPOOF_APP_VERSION;
    $entetes[] = 'User-Agent: ' . self::SPOOF_USER_AGENT;
    $entetes[] = 'system: ' . self::SPOOF_SYSTEM;
    $entetes[] = 'appPlatform: ' . self::SPOOF_APP_PLATFORM;

    // On n'émet "loginsession"/"userid" QUE non vides (§ 1.2 de la spec technique) : au
    // login ils n'existent pas encore ($_session est null ici), toutes les requêtes de
    // découverte (UC02) les fournissent.
    if (is_array($_session)) {
      if (isset($_session['loginsession']) && $_session['loginsession'] !== '') {
        $entetes[] = 'loginsession: ' . $_session['loginsession'];
      }
      if (isset($_session['userid']) && $_session['userid'] !== '') {
        $entetes[] = 'userid: ' . $_session['userid'];
      }
    }
    // 'familyid' est la SEULE valeur d'origine BACKEND de $_options qui reparte dans un
    // en-tête HTTP (§ 7.1) : validée par valeurEnteteConforme() (ancre \z, jamais $)
    // AVANT concaténation — sans cela, un familyid porteur d'un CRLF injecterait des
    // en-têtes arbitraires dans la requête.
    if (isset($_options['familyid']) && $_options['familyid'] !== '') {
      if (!self::valeurEnteteConforme($_options['familyid'], 128)) {
        throw new smartclimException('AUX Cloud legacy : familyid de forme inattendue', smartclimException::TYPE_PROTOCOLE);
      }
      $entetes[] = 'familyid: ' . $_options['familyid'];
    }

    // 'corps_json' (corps en CLAIR des routes de découverte) prime sur $_corpsChiffre
    // (réservé au corps CHIFFRÉ du login, § 1.1) — les deux ne sont jamais fournis
    // ensemble par un même appelant.
    $corps = (isset($_options['corps_json']) && is_string($_options['corps_json'])) ? $_options['corps_json'] : $_corpsChiffre;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $hote . $chemin);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT_CONNEXION);
    curl_setopt($ch, CURLOPT_TIMEOUT, $_tempsRequete);
    curl_setopt($ch, CURLOPT_NOSIGNAL, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $corps);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $entetes);

    $reponse = curl_exec($ch);
    $erreurCurl = curl_error($ch);
    $numeroErreurCurl = curl_errno($ch);
    $codeHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    log::add('smartclim', 'debug', 'AUX Cloud legacy POST ' . $_chemin . ' : http=' . $codeHttp . ' errno=' . $numeroErreurCurl);

    if ($reponse === false || $erreurCurl !== '') {
      // Étape 1 : codes univoquement liés à la validation d'un certificat.
      if (in_array($numeroErreurCurl, self::codesCertificat(), true)) {
        throw new smartclimException('AUX Cloud legacy : erreur cURL certificat (' . $numeroErreurCurl . ') : ' . $erreurCurl, smartclimException::TYPE_RESEAU, self::CONTEXTE_CERTIFICAT);
      }
      // Étape 2 : magasin de certificats LOCAL illisible (77, SSL_CACERT_BADFILE) — ne
      // dit RIEN du serveur distant (§ 7.1 de la spec technique, arbitrage du
      // 2026-09-08).
      if ($numeroErreurCurl === 77) {
        throw new smartclimException('AUX Cloud legacy : erreur cURL magasin de certificats local (77) : ' . $erreurCurl, smartclimException::TYPE_RESEAU, self::CONTEXTE_MAGASIN_LOCAL);
      }
      // Étape 3 : toute autre erreur cURL (35/59 = échecs de NÉGOCIATION TLS, pas de
      // validation de certificat — § 7.1, restent dans ce bucket générique).
      throw new smartclimException('AUX Cloud legacy : erreur cURL (' . $numeroErreurCurl . ') : ' . $erreurCurl, smartclimException::TYPE_RESEAU);
    }

    // Étape 4 : HTTP >= 500, ou 429 (rate-limit).
    if ($codeHttp >= 500 || $codeHttp === 429) {
      throw new smartclimException('AUX Cloud legacy : HTTP ' . $codeHttp, smartclimException::TYPE_RESEAU);
    }

    // Étape 5 : corps non-JSON. ⚠️ Ajustement UC02 (constat direct sur le code de
    // référence, § 1.2 de la spec technique de cette UC) : SEULES les routes
    // "account/login"/"getfamilylist"/"dev/query"/"sharedev/querylist" portent un champ
    // "status" au PREMIER niveau de l'enveloppe — "querystate" et "sdkcontrol" le portent
    // sous "event.payload.status" (ou pas du tout pour "sdkcontrol", identifié par
    // "event.header.name"). Exiger "status" ICI ferait donc échouer en TYPE_PROTOCOLE
    // ces deux routes à CHAQUE appel. La validation du "status" de premier niveau reste à
    // la charge de chaque appelant (login() ci-dessus, classerStatutLegacy() plus bas) —
    // exactement comme le classement des codes métier AUX Home reste à la charge de
    // classerCodeMetier(), jamais de requeteAppareils().
    $donnees = json_decode((string) $reponse, true);
    if (!is_array($donnees)) {
      throw new smartclimException('AUX Cloud legacy : enveloppe JSON invalide ou absente', smartclimException::TYPE_PROTOCOLE);
    }

    return $donnees;
  }

  /**
   * Table des curl_errno() UNIVOQUEMENT liés à la validation d'un certificat (§ 6.1/7.1
   * de la spec technique). ⚠️ LITTÉRAUX ENTIERS COMMENTÉS, JAMAIS de constantes CURLE_* :
   * 83/90/91 ne sont pas définies par tous les builds PHP, et un nom de constante
   * inexistant devient en PHP la chaîne littérale de son propre nom — le test `in_array`
   * serait alors FAUX SANS AUCUNE ERREUR.
   *
   * @return array<int,int>
   */
  private static function codesCertificat() {
    return array(
      51, // CURLE_PEER_FAILED_VERIFICATION
      58, // CURLE_SSL_CERTPROBLEM
      60, // CURLE_SSL_CACERT — reste ici, libellé "serveur OU magasin local" (§ 7.1)
      83, // CURLE_SSL_ISSUER_ERROR (pas garantie définie sur tous les builds)
      90, // CURLE_SSL_PINNEDPUBKEYNOTMATCH (idem)
      91, // CURLE_SSL_INVALIDCERTSTATUS (idem)
    );
  }

  /**
   * Valide la FORME d'une valeur d'en-tête ("loginsession" ou "userid") avant de la
   * renvoyer à l'appelant, de la mettre en cache, ou de la relire du cache — jamais
   * avant de la concaténer dans un en-tête d'une requête ultérieure (même motif que
   * smartclimAuxHomeApi::jetonConforme()). Classe volontairement PLUS LARGE que celle-ci
   * (tout l'ASCII imprimable hors espace, § 6.1/R2 de la spec technique) : le format réel
   * de ces valeurs n'est documenté par AUCUNE référence, une classe trop stricte
   * rejetterait une authentification RÉUSSIE — la panne la plus trompeuse possible.
   *
   * ⚠️ Ancre de fin en `\z`, JAMAIS `$` : en PCRE sans le modificateur `D`, `$` matche
   * aussi juste avant un `\n` FINAL — cf. le finding déjà corrigé sur
   * smartclimAuxHomeApi::jetonConforme().
   *
   * @param mixed $_valeur
   * @param int $_max Longueur maximale acceptée.
   * @return bool
   */
  private static function valeurEnteteConforme($_valeur, $_max) {
    return is_string($_valeur) && preg_match('/\A[\x21-\x7E]{1,' . (int) $_max . '}\z/', $_valeur) === 1;
  }

  /**
   * Journalise le "status"/message métier renvoyés par le backend sur un login refusé
   * (§ 6.1 de la spec technique) — ordre IMPÉRATIF, identique dans son principe à
   * smartclimAuxHomeApi::journaliserErreurBackend() mais avec une neutralisation
   * SUPPLÉMENTAIRE, CONFINÉE ici : les suites hexadécimales longues (le sha1 du mot de
   * passe en fait 40).
   * ⚠️ Cette 4e étape est REDONDANTE en pratique, et il faut le savoir dans ce sens-là :
   * le charset hexadécimal est un SOUS-ENSEMBLE de [A-Za-z0-9+/], donc toute suite de
   * 32 caractères hex est déjà neutralisée par l'étape 3 (base64, seuil 16). La vraie
   * protection du sha1 est donc l'étape 3 — l'étape 4 n'est qu'une intention explicite,
   * conservée parce qu'elle coûte zéro. 🚫 Corollaire : ne JAMAIS retirer ni relâcher
   * l'étape 3 en croyant le sha1 couvert par l'étape 4. Et ne JAMAIS porter ce filtre
   * hexadécimal dans
   * smartclimDiagnostic::masquerParRessemblance(), où les suites hexadécimales SONT la
   * donnée utile (trames HVAC).
   *
   * 1. filtre les seuls caractères de CONTRÔLE ;
   * 2. garde UTF-8, repli sur un filtre ASCII imprimable UNIQUEMENT si invalide ;
   * 3. neutralise les suites base64 de 16 caractères ou plus (écho possible du champ
   *    "password"/"account" chiffré) ;
   * 4. neutralise les suites hexadécimales de 32 caractères ou plus — intention
   *    explicite, déjà couverte par l'étape 3 (cf. ⚠️ ci-dessus) ;
   * 5. tronque à 120 caractères (borne finale, après neutralisation).
   *
   * 🚫 Ne journalise JAMAIS json_encode($_donnees) brut (ce que font les trois
   * références) : ce serait contourner les 4 neutralisations ci-dessus.
   *
   * ⚠️ § 6.1.1 de la spec technique UC02 : $_contexte est INSÉRÉ en 1er paramètre (donc
   * l'ordre des arguments CHANGE) — l'unique appel existant (login()) a été mis à jour
   * DANS LE MÊME GESTE. Ne jamais toucher à cette signature sans relire cet appel.
   *
   * @param string $_contexte 'login', 'getfamilylist', 'dev_query' ou 'sharedev' (log
   *   uniquement — jamais une donnée backend).
   * @param array $_donnees Enveloppe décodée renvoyée par requete().
   */
  private static function journaliserErreurLegacy($_contexte, $_donnees) {
    $status = isset($_donnees['status']) && is_scalar($_donnees['status']) ? (int) $_donnees['status'] : 0;
    $message = '';
    if (isset($_donnees['message']) && is_string($_donnees['message'])) {
      $message = $_donnees['message'];
    } elseif (isset($_donnees['msg']) && is_string($_donnees['msg'])) {
      $message = $_donnees['msg'];
    }
    // 1. Caractères de CONTRÔLE uniquement.
    $message = preg_replace('/[\x00-\x1F\x7F]/', ' ', $message);
    // 2. Garde UTF-8 : repli sur le filtre imprimable UNIQUEMENT si invalide.
    if (preg_match('//u', $message) !== 1) {
      $message = preg_replace('/[^\x20-\x7E]/', ' ', $message);
    }
    // 3. Neutralisation des suites base64.
    $message = preg_replace('/[A-Za-z0-9+\/]{16,}={0,2}/', '[b64]', $message);
    // 4. Suites hexadécimales : REDONDANT avec l'étape 3 (hex ⊂ base64), conservé comme
    // intention explicite. Protection CONFINÉE à cette classe (cf. docblock).
    $message = preg_replace('/[0-9A-Fa-f]{32,}/', '[hex]', $message);
    // 5. Troncature finale.
    $message = substr($message, 0, 120);
    log::add('smartclim', 'error', 'AUX Cloud legacy (' . $_contexte . ') : status=' . $status . ' message=' . $message);
  }

  /**
   * Classe un "status" != 0 renvoyé par une route AUTHENTIFIÉE de découverte
   * (getfamilylist/dev_query/sharedev) et lève l'exception correspondante, après
   * journalisation (UC02, § 6.1/7 de la spec technique) — patron IMPÉRATIF identique à
   * smartclimAuxHomeApi::classerCodeMetier() : cet ordre n'est écrit qu'UNE fois pour les
   * trois routes authentifiées de découverte. Ne fait RIEN si le "status" vaut 0 (succès).
   *
   * ⚠️ $_typeParDefaut vaut TOUJOURS TYPE_AUTH ici (hypothèse "session expirée", § 1.6 —
   * aucune source ne documente de code dédié) : c'est CE classement qui ARME le rejeu
   * unique de listerAppareils().
   *
   * @param string $_contexte 'getfamilylist', 'dev_query' ou 'sharedev' (log uniquement).
   * @param array $_donnees Enveloppe décodée renvoyée par requete().
   * @param int $_typeParDefaut Type à lever si le "status" est != 0.
   * @throws smartclimException Si et seulement si le "status" est != 0.
   */
  private static function classerStatutLegacy($_contexte, $_donnees, $_typeParDefaut) {
    $status = isset($_donnees['status']) && is_scalar($_donnees['status']) ? (int) $_donnees['status'] : -1;
    if ($status === 0) {
      return;
    }
    self::journaliserErreurLegacy($_contexte, $_donnees);
    throw new smartclimException('AUX Cloud legacy ' . $_contexte . ' : status ' . $status, $_typeParDefaut);
  }

  /**
   * Liste les familles, appareils (propres ET partagés) et jeux de paramètres réellement
   * annoncés par chaque appareil du compte AUX Cloud legacy configuré (UC02, § 2/6.1 de la
   * spec technique). Un tableau vide est un SUCCÈS (compte sans appareil), jamais une
   * exception.
   *
   * Budget de temps GLOBAL (BUDGET_DECOUVERTE = 25 s), session comprise (parité
   * smartclimAuxHomeApi::listerAppareils()) : arrêt DUR évalué avant chaque famille et
   * chaque appareil. Re-login réactif borné à UN SEUL rejeu de la découverte ENTIÈRE
   * (booléen local, JAMAIS de récursion) : le try n'entoure QUE l'exécution métier,
   * jamais session() — un TYPE_AUTH levé par une route authentifiée de découverte
   * (getfamilylist/dev_query/sharedev) fait repartir tout executerDecouverte() une seule
   * fois après re-login ; une erreur SUR UN SEUL appareil (paramètres illisibles) reste,
   * elle, locale au try/catch PAR APPAREIL (AC3 — jamais de rejeu pour ce cas).
   *
   * ⚠️ Correctif reviews croisées (findings major #1/#2) : cette méthode ne FILTRE plus
   * AUCUN appareil — TOUS ceux rencontrés (y compris pompe à chaleur, budget épuisé, ou
   * sans preuve de climatiseur) sont RENVOYÉS, porteurs de leur verdict
   * (`motif_exclusion`/`preuve_climatiseur`). La décision de créer ou non un équipement
   * appartient à smartclim::scannerAuxCloud(), seul endroit qui connaît l'état de
   * rapprochement Jeedom (§ 3.1/D1 de la spec technique).
   *
   * @param int $_budget Budget de temps GLOBAL, en secondes.
   * @return array<int, array> Lignes normalisées à clés génériques françaises
   *   (normaliserAppareilLegacy(), enrichies de `motif_exclusion`/`preuve_climatiseur`
   *   par executerDecouverte()) — aucun nom de champ propriétaire n'en sort.
   * @throws smartclimException message TECHNIQUE, recréée sur place avant propagation
   *   (même motif que login()/session() : la trace de requete() peut porter loginsession).
   */
  public static function listerAppareils($_budget = self::BUDGET_DECOUVERTE) {
    try {
      $debut = microtime(true);
      $session = self::session();
      $rejoue = false;

      while (true) {
        try {
          return self::executerDecouverte($session, $debut, $_budget);
        } catch (smartclimException $e) {
          $budgetRestant = $_budget - (microtime(true) - $debut);
          if (!$rejoue && $e->getType() === smartclimException::TYPE_AUTH && $budgetRestant >= self::BUDGET_LOGIN + 3) {
            $rejoue = true;
            log::add('smartclim', 'info', 'AUX Cloud legacy : rejeu après re-login (découverte)');
            self::purgerSession();
            $session = self::login();
            continue;
          }
          throw $e;
        }
      }
    } catch (smartclimException $e) {
      // Recrée l'exception À CE POINT D'APPEL, même motif que login()/session()
      // ci-dessus : la trace d'origine peut embarquer, via la frame de requete(),
      // loginsession.
      throw new smartclimException($e->getMessage(), $e->getType(), $e->getContexte());
    }
  }

  /**
   * Corps de listerAppareils() une fois la session en main — EXTRAIT pour permettre le
   * rejeu (booléen local de l'appelant), jamais de récursion (§ 6.1 de la spec
   * technique). Un TYPE_AUTH levé par une route authentifiée de découverte REMONTE tel
   * quel (pour armer le rejeu de l'appelant) ; toute autre erreur, PAR FAMILLE ou PAR
   * APPAREIL, est journalisée et absorbée localement (AC1/AC3 : la découverte des autres
   * familles/appareils n'est jamais interrompue).
   *
   * @param array $_session
   * @param float $_debut microtime(true) de l'entrée de listerAppareils().
   * @param int $_budget
   * @return array<int, array>
   * @throws smartclimException UNIQUEMENT TYPE_AUTH (pour armer le rejeu de l'appelant).
   */
  private static function executerDecouverte(array $_session, $_debut, $_budget) {
    $tempsRestant = function () use ($_debut, $_budget) {
      return $_budget - (microtime(true) - $_debut);
    };
    $tempsRequete = function () use ($tempsRestant) {
      return (int) max(3, min(self::TIMEOUT_REQUETE, $tempsRestant()));
    };

    $familles = self::requeteFamilles($_session, $tempsRequete());

    $appareils = array();
    foreach ($familles as $familyId) {
      if ($tempsRestant() <= 0) {
        log::add('smartclim', 'warning', 'AUX Cloud legacy : budget de temps épuisé, familles restantes ignorées');
        break;
      }

      $bruts = array();
      try {
        foreach (self::requeteAppareilsFamille($_session, $familyId, false, $tempsRequete()) as $brut) {
          $bruts[] = array('brut' => $brut, 'partage' => false);
        }
      } catch (smartclimException $e) {
        if ($e->getType() === smartclimException::TYPE_AUTH) {
          throw $e;
        }
        log::add('smartclim', 'warning', 'AUX Cloud legacy : appareils propres d\'une famille ignorés (type ' . $e->getType() . ')');
      }
      try {
        foreach (self::requeteAppareilsFamille($_session, $familyId, true, $tempsRequete()) as $brut) {
          $bruts[] = array('brut' => $brut, 'partage' => true);
        }
      } catch (smartclimException $e) {
        if ($e->getType() === smartclimException::TYPE_AUTH) {
          throw $e;
        }
        log::add('smartclim', 'warning', 'AUX Cloud legacy : appareils partagés d\'une famille ignorés (type ' . $e->getType() . ')');
      }

      if (empty($bruts)) {
        continue;
      }

      $paires = array();
      foreach ($bruts as $entree) {
        $normalise = self::normaliserAppareilLegacy($entree['brut'], $familyId, $entree['partage']);
        if ($normalise !== null) {
          $paires[] = array('brut' => $entree['brut'], 'normalise' => $normalise);
        }
      }
      if (empty($paires)) {
        continue;
      }

      // AC5 : état groupé PAR FAMILLE — O(familles), jamais O(appareils). Ne lève
      // JAMAIS (requeteEtatsGroupe()) : un état groupé indisponible laisse 'online'
      // absent (invariant « une clé absente ne touche pas sa commande »).
      $brutsRaw = array();
      foreach ($paires as $paire) {
        $brutsRaw[] = $paire['brut'];
      }
      $etats = self::requeteEtatsGroupe($_session, $brutsRaw, $tempsRequete());

      foreach ($paires as $paire) {
        $brut = $paire['brut'];
        $normalise = $paire['normalise'];
        $identifiant = $normalise['identifiant'];

        $normalise['enLigne_connue'] = array_key_exists($identifiant, $etats);
        $normalise['enLigne'] = $normalise['enLigne_connue'] ? (bool) $etats[$identifiant] : false;

        // ⚠️ Correctif reviews croisées (findings major #1/#2) : cette méthode ne
        // FILTRE plus AUCUN appareil — chaque appareil rencontré est RENVOYÉ, porteur
        // de son verdict ('motif_exclusion' + 'preuve_climatiseur'). La décision de
        // CRÉER ou non un équipement (qui dépend du RAPPROCHEMENT Jeedom — § 3.1/D1 de
        // la spec technique) n'est structurellement pas de son ressort : elle vit
        // désormais dans smartclim::scannerAuxCloud(), seul endroit qui appelle
        // chercherEquipementExistant(). Seuls les deux motifs INDÉPENDANTS du
        // rapprochement — budget de temps épuisé et pompe à chaleur — sont établis ICI.
        if ($tempsRestant() <= 0) {
          log::add('smartclim', 'warning', 'AUX Cloud legacy : budget de temps épuisé, appareil ignoré (identifiant=' . $identifiant . ')');
          $normalise['motif_exclusion'] = 'budget_epuise';
          $normalise['parametres'] = array();
          $normalise['preuve_climatiseur'] = false;
          $appareils[] = $normalise;
          continue;
        }

        // Pompes à chaleur : HORS PÉRIMÈTRE du plugin (§ 0 de la spec fonctionnelle) —
        // AUCUNE requête sdkcontrol (reconnues par leur productId seul), mais DÉSORMAIS
        // renvoyées (motif_exclusion) pour rester visibles dans l'écran de scan.
        if (in_array($normalise['type_produit'], self::produitsPompeAChaleur(), true)) {
          log::add('smartclim', 'debug', 'AUX Cloud legacy : appareil ignoré (pompe à chaleur, hors périmètre du plugin), identifiant=' . $identifiant);
          $normalise['motif_exclusion'] = 'pompe_a_chaleur';
          $normalise['parametres'] = array();
          $normalise['preuve_climatiseur'] = false;
          $appareils[] = $normalise;
          continue;
        }

        if (!$normalise['type_produit_connu']) {
          // AC4 : ligne 'info' SÉPARÉE, construite APRÈS nettoyerTexteExterne() SEUL —
          // JAMAIS journaliserErreurLegacy() (§ 7.3 : ce productId hexadécimal de 32
          // caractères serait remplacé par '[b64]' dès l'étape 3 de sa neutralisation,
          // rendant AC4 invérifiable sans aucune erreur visible). ⚠️ 'type_produit_connu'
          // ne gouverne plus QUE ce log depuis le correctif reviews croisées — plus la
          // création (cf. plus haut) : produitsClimatiseurConnus() n'a plus d'autre rôle.
          log::add('smartclim', 'info', 'AUX Cloud legacy : identifiant de produit inconnu (' . $normalise['type_produit'] . ') pour un appareil découvert, identifiant=' . $identifiant);
        }

        // ⚠️ § 6.3 de la spec technique UC03 : requeteParametres() rend désormais
        // array{noms, valeurs} (contrat CHANGÉ depuis l'UC02) — appelant mis à jour
        // DANS LE MÊME GESTE, exactement la classe de piège du § 6.1.1 d'UC02.
        $reponseParametres = array('noms' => array(), 'valeurs' => array());
        $parametresLisibles = false;
        try {
          $reponseParametres = self::requeteParametres($_session, $brut, $tempsRequete());
          $parametresLisibles = true;
        } catch (smartclimException $e) {
          if ($e->getType() === smartclimException::TYPE_AUTH) {
            throw $e;
          }
          log::add('smartclim', 'warning', 'AUX Cloud legacy : paramètres illisibles pour un appareil (identifiant=' . $identifiant . ', type ' . $e->getType() . ')');
        }

        // ⚠️ 'preuve_climatiseur' est calculée pour TOUT appareil, QUEL QUE SOIT le
        // productId (correctif reviews croisées) — c'est smartclim::scannerAuxCloud()
        // qui décide si elle est EXIGÉE (appareil non rapproché) ou pas (appareil déjà
        // rapproché, § 3.1/D1 : « on pose seulement les clés auxcloud_* et online »).
        // 'motif_exclusion' reste '' ICI dans tous les cas : ce n'est PAS un motif
        // établi par le transport.
        $normalise['motif_exclusion'] = '';
        $normalise['parametres'] = $reponseParametres['noms'];
        // UC03, § 5.2 de la spec technique : la découverte pose désormais aussi les
        // VALEURS (pour que scannerAuxCloud() puisse pousser l'état complet AU SCAN,
        // sans émettre une seconde requête) — via etatAppareil(), extension additive.
        $normalise['valeurs'] = $reponseParametres['valeurs'];
        $normalise['preuve_climatiseur'] = $parametresLisibles && self::estClimatiseur($reponseParametres['noms']);
        $appareils[] = $normalise;
      }
    }

    return $appareils;
  }

  /**
   * `POST /appsync/group/member/getfamilylist` (§ 1.2/6.1 de la spec technique) :
   * AUCUN corps, AUCUN en-tête supplémentaire. Renvoie la liste des `familyid`, chacun
   * validé par valeurEnteteConforme() — il repart en EN-TÊTE HTTP dans les requêtes
   * suivantes (§ 7.1).
   *
   * @param array $_session
   * @param int $_temps
   * @return string[]
   * @throws smartclimException TYPE_AUTH (via classerStatutLegacy()) ou TYPE_*
   *   (via requete()).
   */
  private static function requeteFamilles(array $_session, $_temps) {
    $donnees = self::requete('/appsync/group/member/getfamilylist', '', '', '', $_temps, $_session);
    self::classerStatutLegacy('getfamilylist', $donnees, smartclimException::TYPE_AUTH);

    $familles = array();
    if (isset($donnees['data']['familyList']) && is_array($donnees['data']['familyList'])) {
      foreach ($donnees['data']['familyList'] as $famille) {
        if (!is_array($famille) || !isset($famille['familyid']) || !is_scalar($famille['familyid'])) {
          continue;
        }
        $familyId = (string) $famille['familyid'];
        if (!self::valeurEnteteConforme($familyId, 128)) {
          log::add('smartclim', 'warning', 'AUX Cloud legacy : famille ignorée (familyid de forme inattendue)');
          continue;
        }
        $familles[] = $familyId;
      }
    }
    return $familles;
  }

  /**
   * Appareils d'UNE famille — propres (`dev/query?action=select`, corps `{"pids":[]}`)
   * ou partagés (`sharedev/querylist?querytype=shared`, corps `{"endpointId":""}`) selon
   * `$_partages` (§ 1.2/Écart 1 de la spec technique : les PIÈCES ne sont PAS un niveau
   * de parcours, `room/query` n'est jamais appelée).
   *
   * @param array $_session
   * @param string $_familyId
   * @param bool $_partages
   * @param int $_temps
   * @return array<int,array> Éléments BRUTS (`data.endpoints[]` ou
   *   `data.shareFromOther[].devinfo`), pas encore normalisés.
   * @throws smartclimException TYPE_AUTH (via classerStatutLegacy()) ou TYPE_*
   *   (via requete()).
   */
  private static function requeteAppareilsFamille(array $_session, $_familyId, $_partages, $_temps) {
    if ($_partages) {
      $donnees = self::requete('/appsync/group/sharedev/querylist', '', '', '', $_temps, $_session, array(
        'query' => '?querytype=shared',
        'corps_json' => '{"endpointId":""}',
        'familyid' => $_familyId,
      ));
      self::classerStatutLegacy('sharedev', $donnees, smartclimException::TYPE_AUTH);
      $bruts = array();
      if (isset($donnees['data']['shareFromOther']) && is_array($donnees['data']['shareFromOther'])) {
        foreach ($donnees['data']['shareFromOther'] as $partage) {
          if (is_array($partage) && isset($partage['devinfo']) && is_array($partage['devinfo'])) {
            $bruts[] = $partage['devinfo'];
          }
        }
      }
      return $bruts;
    }

    $donnees = self::requete('/appsync/group/dev/query', '', '', '', $_temps, $_session, array(
      'query' => '?action=select',
      'corps_json' => '{"pids":[]}',
      'familyid' => $_familyId,
    ));
    self::classerStatutLegacy('dev_query', $donnees, smartclimException::TYPE_AUTH);
    $bruts = array();
    if (isset($donnees['data']['endpoints']) && is_array($donnees['data']['endpoints'])) {
      foreach ($donnees['data']['endpoints'] as $endpoint) {
        if (is_array($endpoint)) {
          $bruts[] = $endpoint;
        }
      }
    }
    return $bruts;
  }

  /**
   * État en ligne/hors ligne de TOUS les appareils BRUTS fournis, en UNE SEULE requête
   * `POST /device/control/v2/querystate` (AC5, § 1.2/3.2/6.1 de la spec technique) —
   * O(familles), jamais O(appareils). ⚠️ NE LÈVE JAMAIS : tout échec devient un
   * `log warning` + tableau vide, ce qui laisse 'online' ABSENT de l'état pour chaque
   * appareil concerné (invariant « une clé absente ne touche pas sa commande »).
   *
   * ⚠️ Directive `DNA.QueryState`/`queryState`, préfixe de messageId = `userid`, extras
   * `messageType: "controlgw.batch"` ET **`timstamp`** — faute de frappe présente dans
   * les DEUX références (§ 1.2), reproduite VERBATIM.
   * ⚠️ Écart 3 (§ 1.4) : lit `data`, replie sur `studata` — coût nul, la forme n'est pas
   * prouvée par les références (aucune des deux ne le fait).
   *
   * @param array $_session
   * @param array $_bruts Éléments BRUTS (endpointId/devSession) d'UNE famille.
   * @param int $_temps
   * @return array<string,bool> did (endpointId) => en ligne.
   */
  private static function requeteEtatsGroupe(array $_session, array $_bruts, $_temps) {
    if (empty($_bruts)) {
      return array();
    }
    try {
      $studata = array();
      foreach ($_bruts as $brut) {
        if (!is_array($brut) || !isset($brut['endpointId']) || !is_scalar($brut['endpointId'])) {
          continue;
        }
        $studata[] = array(
          'did' => (string) $brut['endpointId'],
          'devSession' => isset($brut['devSession']) && is_scalar($brut['devSession']) ? (string) $brut['devSession'] : '',
        );
      }
      if (empty($studata)) {
        return array();
      }

      $entete = self::enveloppeDirective('DNA.QueryState', 'queryState', isset($_session['userid']) ? (string) $_session['userid'] : '', array(
        'messageType' => 'controlgw.batch',
        'timstamp' => (string) time(),
      ));
      $corps = array(
        'directive' => array(
          'header' => $entete,
          'payload' => array('studata' => $studata, 'msgtype' => 'batch'),
        ),
      );
      $corpsJson = json_encode($corps, JSON_UNESCAPED_SLASHES);
      if ($corpsJson === false) {
        log::add('smartclim', 'warning', 'AUX Cloud legacy : encodage JSON du querystate impossible');
        return array();
      }

      $donnees = self::requete('/device/control/v2/querystate', '', '', '', $_temps, $_session, array('corps_json' => $corpsJson));
      if (!isset($donnees['event']['payload']) || !is_array($donnees['event']['payload'])) {
        log::add('smartclim', 'warning', 'AUX Cloud legacy : querystate — enveloppe inattendue');
        return array();
      }
      $payload = $donnees['event']['payload'];
      $status = isset($payload['status']) && is_scalar($payload['status']) ? (int) $payload['status'] : -1;
      if ($status !== 0) {
        log::add('smartclim', 'warning', 'AUX Cloud legacy : querystate refusé (status ' . $status . ')');
        return array();
      }

      $liste = array();
      if (isset($payload['data']) && is_array($payload['data'])) {
        $liste = $payload['data'];
      } elseif (isset($payload['studata']) && is_array($payload['studata'])) {
        // Écart 3 (§ 1.4) : repli, forme non prouvée.
        $liste = $payload['studata'];
      }

      $etats = array();
      foreach ($liste as $ligne) {
        if (!is_array($ligne) || !isset($ligne['did']) || !is_scalar($ligne['did'])) {
          continue;
        }
        $etats[(string) $ligne['did']] = isset($ligne['state']) && (int) $ligne['state'] === 1;
      }
      return $etats;
    } catch (Throwable $t) {
      log::add('smartclim', 'warning', 'AUX Cloud legacy : état groupé indisponible (' . get_class($t) . ')');
      return array();
    }
  }

  /**
   * Gabarit UNIQUE d'en-tête de directive (§ 1.2/6.1 de la spec technique) : 5 clés
   * FIXES (`namespace`, `name`, `interfaceVersion: "2"`, `senderId: "sdk"`,
   * `messageId: "<préfixe>-<epoch s>"`) + extras. Un seul endroit pour ces 5 clés — un
   * piège signalé pour une future UC03, qui l'aurait sinon dupliqué une 3e fois avec un
   * risque de divergence silencieuse.
   *
   * @param string $_namespace
   * @param string $_nom
   * @param string $_prefixeMessage 'userid' pour querystate, 'endpointId' pour sdkcontrol.
   * @param array $_extra Clés additionnelles fusionnées SANS écraser les 5 clés fixes.
   * @return array
   */
  private static function enveloppeDirective($_namespace, $_nom, $_prefixeMessage, array $_extra) {
    return array_merge($_extra, array(
      'namespace' => $_namespace,
      'name' => $_nom,
      'interfaceVersion' => '2',
      'senderId' => 'sdk',
      'messageId' => $_prefixeMessage . '-' . time(),
    ));
  }

  /**
   * Décode le `cookie` base64 d'un appareil BRUT et reconstruit le `cookie` "mappé"
   * attendu par `sdkcontrol` (§ 1.3/3 de la spec technique) : base64 d'un JSON
   * `{device:{id,key,devSession,aeskey,did,pid,mac}}`, où `id`/`key`/`aeskey` viennent
   * du `cookie` d'ORIGINE (`terminalid`/`aeskey`) et `devSession`/`did`/`pid`/`mac`
   * viennent de l'appareil BRUT. Chaîne vide si le `cookie` d'origine est inexploitable
   * (§ 7 : l'appelant traite alors l'échec de `sdkcontrol` comme un échec de requête
   * ordinaire, jamais une exception dédiée).
   *
   * @param array $_brut
   * @return string
   */
  private static function cookieMappe(array $_brut) {
    $cookieBrut = isset($_brut['cookie']) && is_scalar($_brut['cookie']) ? (string) $_brut['cookie'] : '';
    if ($cookieBrut === '') {
      return '';
    }
    $decode = base64_decode($cookieBrut, true);
    if ($decode === false) {
      return '';
    }
    $cookie = json_decode($decode, true);
    if (!is_array($cookie) || !isset($cookie['terminalid']) || !isset($cookie['aeskey'])) {
      return '';
    }
    $enveloppe = array(
      'device' => array(
        'id' => $cookie['terminalid'],
        'key' => $cookie['aeskey'],
        'devSession' => isset($_brut['devSession']) && is_scalar($_brut['devSession']) ? (string) $_brut['devSession'] : '',
        'aeskey' => $cookie['aeskey'],
        'did' => isset($_brut['endpointId']) && is_scalar($_brut['endpointId']) ? (string) $_brut['endpointId'] : '',
        'pid' => isset($_brut['productId']) && is_scalar($_brut['productId']) ? (string) $_brut['productId'] : '',
        'mac' => isset($_brut['mac']) && is_scalar($_brut['mac']) ? (string) $_brut['mac'] : '',
      ),
    );
    $json = json_encode($enveloppe, JSON_UNESCAPED_SLASHES);
    return ($json !== false) ? base64_encode($json) : '';
  }

  /**
   * `POST /device/control/v2/sdkcontrol?license=<LEGACY_LICENSE>` — POINT UNIQUE de
   * cette route (§ 5.2/6 de la spec technique UC03), REFACTORISÉ depuis l'ancien corps
   * de requeteParametres() : `act: "get"`/`params: []` (découverte, ce fichier) ET les
   * usages `get`/`set` d'UC03 (lireParametres()/appliquerOrdre()/sonderParametre())
   * passent tous par lui. Enveloppe de directive, `devicePairedInfo` via cookieMappe()
   * (adapté aux clés GÉNÉRIQUES de $_appareil — pas les noms de champ backend, cf.
   * $_brut de normaliserAppareilLegacy() ci-dessous), convention `vals` du § 1.2.
   *
   * ⚠️ § 6.1 : le corps produit pour `act:'get'`, `params:[]` est OCTET POUR OCTET
   * identique à celui de l'ancien code de découverte — `count($_params) === 0` donne
   * `vals = []`, même contenu, mêmes clés, même ordre.
   * ⚠️ § 6.2 : `vals` n'est PAS exigé dans la réponse à CE niveau (seul `params` tableau
   * l'est) — l'exiger durcirait la validation de la découverte déjà livrée. L'exigence
   * sur `vals` vit UNIQUEMENT dans recomposerValeurs(), appelée par le seul chemin neuf.
   * ⚠️ Sur `ErrorResponse` (ou enveloppe inattendue) : journaliserErreurLegacy('sdkcontrol',
   * …) reçoit le SOUS-TABLEAU `event.payload`, jamais l'enveloppe entière — elle lit
   * `message`/`msg` au premier niveau, lui passer $_donnees en entier perdrait le
   * message EN SILENCE (§ 5.2, piège nommé de ce cycle). Classée TYPE_PROTOCOLE +
   * CONTEXTE_APPAIRAGE_REFUSE (AC4) — jamais TYPE_AUTH, qui armerait un re-login inutile
   * (un appairage refusé ne se résout pas par un nouveau login).
   *
   * @param array $_session
   * @param array $_appareil Clés GÉNÉRIQUES : identifiant, type_produit, mac,
   *   devicetype_flag, cookie (base64 D'ORIGINE, non mappé), dev_session.
   * @param string $_act 'get' ou 'set'.
   * @param array $_params Noms de paramètres (vide = tous, en lecture).
   * @param array $_vals Ignoré en 'get' (calculé selon § 1.2) ; requis en 'set'.
   * @param int $_temps
   * @return array Réponse `sdkcontrol` re-parsée ('params' garanti tableau, 'vals' NON
   *   garanti — cf. § 6.2).
   * @throws smartclimException TYPE_PROTOCOLE (+ CONTEXTE_APPAIRAGE_REFUSE) ou TYPE_*
   *   (via requete()).
   */
  private static function requeteSdkControl($_session, array $_appareil, $_act, array $_params, array $_vals, $_temps) {
    $endpointId = isset($_appareil['identifiant']) && is_scalar($_appareil['identifiant']) ? (string) $_appareil['identifiant'] : '';
    $typeProduit = isset($_appareil['type_produit']) && is_scalar($_appareil['type_produit']) ? (string) $_appareil['type_produit'] : '';
    $mac = isset($_appareil['mac']) && is_scalar($_appareil['mac']) ? (string) $_appareil['mac'] : '';
    $devicetypeFlag = isset($_appareil['devicetype_flag']) && is_scalar($_appareil['devicetype_flag']) ? (string) $_appareil['devicetype_flag'] : '';
    $devSession = isset($_appareil['dev_session']) && is_scalar($_appareil['dev_session']) ? (string) $_appareil['dev_session'] : '';

    $entete = self::enveloppeDirective('DNA.KeyValueControl', 'KeyValueControl', $endpointId, array(
      'timstamp' => (string) time(),
    ));

    // Adapte les clés GÉNÉRIQUES de $_appareil à la forme attendue par cookieMappe()
    // (noms de champ BACKEND : cookie/devSession/endpointId/productId/mac) — cette
    // méthode reste INCHANGÉE (§ 1.3 de la spec technique : « déjà conforme, rien à
    // réécrire »), seul l'appelant adapte sa charge d'entrée.
    $cookieMappe = self::cookieMappe(array(
      'cookie' => isset($_appareil['cookie']) && is_scalar($_appareil['cookie']) ? (string) $_appareil['cookie'] : '',
      'devSession' => $devSession,
      'endpointId' => $endpointId,
      'productId' => $typeProduit,
      'mac' => $mac,
    ));

    $vals = ($_act === 'get')
      ? ((count($_params) === 1) ? array(array(array('val' => 0, 'idx' => 1))) : array())
      : array_values($_vals);

    $corps = array(
      'directive' => array(
        'header' => $entete,
        'endpoint' => array(
          'devicePairedInfo' => array(
            'did' => $endpointId,
            'pid' => $typeProduit,
            'mac' => $mac,
            'devicetypeflag' => $devicetypeFlag,
            'cookie' => $cookieMappe,
          ),
          'endpointId' => $endpointId,
          // {} VIDE, littéralement (source de référence, § 1.2) — stdClass et non un
          // tableau [] PHP, sinon json_encode() produirait "[]" au lieu de "{}".
          'cookie' => new stdClass(),
          'devSession' => $devSession,
        ),
        'payload' => array('act' => (string) $_act, 'params' => array_values($_params), 'vals' => $vals, 'did' => $endpointId),
      ),
    );
    $corpsJson = json_encode($corps, JSON_UNESCAPED_SLASHES);
    if ($corpsJson === false) {
      throw new smartclimException('AUX Cloud legacy : échec de l\'encodage JSON du corps sdkcontrol', smartclimException::TYPE_INTERNE);
    }

    // ⚠️ rawurlencode() : LEGACY_LICENSE porte '+'/'/'='  — une concaténation nue
    // corromprait la query string (§ 7.1 : query TOUJOURS un littéral serveur, mais
    // encodée comme toute valeur placée dans une URL).
    $donnees = self::requete('/device/control/v2/sdkcontrol', '', '', '', $_temps, $_session, array(
      'query' => '?license=' . rawurlencode(self::LEGACY_LICENSE),
      'corps_json' => $corpsJson,
    ));

    if (!isset($donnees['event']['header']['name']) || $donnees['event']['header']['name'] !== 'Response') {
      $payloadErreur = (isset($donnees['event']['payload']) && is_array($donnees['event']['payload'])) ? $donnees['event']['payload'] : array();
      self::journaliserErreurLegacy('sdkcontrol', $payloadErreur);
      throw new smartclimException('AUX Cloud legacy : sdkcontrol — réponse refusée ou inattendue', smartclimException::TYPE_PROTOCOLE, self::CONTEXTE_APPAIRAGE_REFUSE);
    }
    $donneesBrutes = isset($donnees['event']['payload']['data']) ? $donnees['event']['payload']['data'] : '';
    if (!is_string($donneesBrutes) || $donneesBrutes === '') {
      throw new smartclimException('AUX Cloud legacy : sdkcontrol — champ data absent', smartclimException::TYPE_PROTOCOLE);
    }
    $reponse = json_decode($donneesBrutes, true);
    if (!is_array($reponse) || !isset($reponse['params']) || !is_array($reponse['params'])) {
      throw new smartclimException('AUX Cloud legacy : sdkcontrol — champ data non re-parsable', smartclimException::TYPE_PROTOCOLE);
    }

    return $reponse;
  }

  /**
   * `POST /device/control/v2/sdkcontrol?license=<LEGACY_LICENSE>` avec `act: "get"`,
   * `params: []` (§ 1.2/6.3 de la spec technique) : renvoie les NOMS **et** les VALEURS
   * des paramètres effectivement annoncés par CET appareil.
   *
   * ⚠️ § 6.3 : contrat de retour CHANGÉ depuis l'UC02 (`string[]` -> `array{noms,
   * valeurs}`) — pour que le scan pose l'état complet sans émettre une seconde requête.
   * SON UNIQUE APPELANT, executerDecouverte() ci-dessus, est mis à jour DANS LE MÊME
   * GESTE (§ 6.3 : « exactement la classe de piège du § 6.1.1 d'UC02 »).
   *
   * @param array $_session
   * @param array $_brut Élément BRUT (endpointId/productId/mac/devicetypeFlag/cookie/devSession).
   * @param int $_temps
   * @return array{noms:string[], valeurs:array<string,mixed>}
   * @throws smartclimException TYPE_PROTOCOLE ou TYPE_* (via requeteSdkControl()).
   */
  private static function requeteParametres(array $_session, array $_brut, $_temps) {
    // Adapte le $_brut BACKEND (endpointId/productId/mac/devicetypeFlag/cookie/devSession)
    // à la forme GÉNÉRIQUE désormais attendue par requeteSdkControl() — cette méthode-ci
    // reste le SEUL point du fichier qui connaît encore les noms de champ backend de la
    // découverte.
    $appareilGenerique = array(
      'identifiant' => isset($_brut['endpointId']) && is_scalar($_brut['endpointId']) ? (string) $_brut['endpointId'] : '',
      'type_produit' => isset($_brut['productId']) && is_scalar($_brut['productId']) ? (string) $_brut['productId'] : '',
      'mac' => isset($_brut['mac']) && is_scalar($_brut['mac']) ? (string) $_brut['mac'] : '',
      'devicetype_flag' => isset($_brut['devicetypeFlag']) && is_scalar($_brut['devicetypeFlag']) ? (string) $_brut['devicetypeFlag'] : '',
      'cookie' => isset($_brut['cookie']) && is_scalar($_brut['cookie']) ? (string) $_brut['cookie'] : '',
      'dev_session' => isset($_brut['devSession']) && is_scalar($_brut['devSession']) ? (string) $_brut['devSession'] : '',
    );

    $reponse = self::requeteSdkControl($_session, $appareilGenerique, 'get', array(), array(), $_temps);

    $noms = array();
    foreach ($reponse['params'] as $nom) {
      if (is_string($nom) && preg_match('/\A[A-Za-z0-9_]{1,40}\z/', $nom) === 1) {
        $noms[] = $nom;
      }
    }
    return array('noms' => $noms, 'valeurs' => self::recomposerValeurs($reponse));
  }

  /**
   * Zippe `params[i] <-> vals[i][0]['val']` d'une réponse `sdkcontrol` déjà re-parsée
   * (§ 5.2/6.2 de la spec technique) — SEUL endroit qui exige la présence de `vals`
   * (requeteSdkControl() ne l'exige pas, § 6.2). `vals` absent ou mal formé -> map VIDE
   * + `log warning`, JAMAIS d'exception (une lecture illisible laisse la clé ABSENTE,
   * invariant « une clé absente ne touche pas sa commande »). Un index mal formé -> clé
   * OMISE (jamais une valeur forgée). Nom passé à la MÊME regex que requeteParametres().
   *
   * @param array $_reponse Réponse `sdkcontrol` re-parsée ('params' garanti tableau).
   * @return array<string, mixed>
   */
  private static function recomposerValeurs(array $_reponse) {
    $params = isset($_reponse['params']) && is_array($_reponse['params']) ? $_reponse['params'] : array();
    $vals = isset($_reponse['vals']) && is_array($_reponse['vals']) ? $_reponse['vals'] : null;
    if ($vals === null) {
      log::add('smartclim', 'warning', 'AUX Cloud legacy : sdkcontrol — champ vals absent ou mal formé, valeurs ignorées');
      return array();
    }
    $valeurs = array();
    foreach ($params as $index => $nom) {
      if (!is_string($nom) || preg_match('/\A[A-Za-z0-9_]{1,40}\z/', $nom) !== 1) {
        continue;
      }
      if (!isset($vals[$index]) || !is_array($vals[$index]) || !isset($vals[$index][0]) || !is_array($vals[$index][0]) || !array_key_exists('val', $vals[$index][0])) {
        continue;
      }
      $valeurs[$nom] = $vals[$index][0]['val'];
    }
    return $valeurs;
  }

  /**
   * Lecture CONTINUE de l'état d'un appareil legacy (UC03, § 5.2 de la spec technique) :
   * UNE requête `get params: []` -> map `nom => valeur`, via requeteSdkControl().
   * Rejeu réactif borné à UN, sur TYPE_AUTH uniquement (§ 3.8/D8 — le rejeu sur
   * CONTEXTE_APPAIRAGE_REFUSE, qui exige de RE-OBTENIR cookie/devSession, est de la
   * responsabilité de l'APPELANT, seul à connaître la 7ᵉ mémoire).
   *
   * ⚠️ Le `try` n'entoure QUE la requête métier, jamais session() : un TYPE_AUTH levé à
   * l'ouverture de session ne doit jamais déclencher de rejeu (anti-boucle de l'UC08 du
   * MVP).
   *
   * @param array $_appareil Clés GÉNÉRIQUES (cf. requeteSdkControl()).
   * @param int $_budget Budget de temps GLOBAL, session comprise.
   * @return array<string, mixed> nom brut => valeur brute.
   * @throws smartclimException Message TECHNIQUE, recréée À CE POINT D'APPEL (la frame
   *   de requete() porte loginsession).
   */
  public static function lireParametres(array $_appareil, $_budget = self::BUDGET_ETAT) {
    try {
      $debut = microtime(true);
      $session = self::session();
      $rejoue = false;
      while (true) {
        $tempsRequete = (int) max(3, min(self::TIMEOUT_REQUETE, $_budget - (microtime(true) - $debut)));
        try {
          $reponse = self::requeteSdkControl($session, $_appareil, 'get', array(), array(), $tempsRequete);
          return self::recomposerValeurs($reponse);
        } catch (smartclimException $e) {
          $restant = $_budget - (microtime(true) - $debut);
          if (!$rejoue && $e->getType() === smartclimException::TYPE_AUTH && $restant >= self::BUDGET_REJEU_ORDRE) {
            $rejoue = true;
            self::purgerSession();
            $session = self::login();
            continue;
          }
          throw $e;
        }
      }
    } catch (smartclimException $e) {
      throw new smartclimException($e->getMessage(), $e->getType(), $e->getContexte());
    }
  }

  /**
   * Écrit un ordre GÉNÉRIQUE sur CET appareil legacy (UC03, § 3.3/4.1/5.2 de la spec
   * technique) : traduit via parametresAuxCloud() + smartclimCapabilities::
   * versTransport()/echelleTemperature()/codesOscillation(), REFUSE un concept
   * `lecture_seule`, absent de la table, ou d'`intent` `null`. UNE requête `set`.
   * Renvoie l'ordre RÉELLEMENT appliqué (consigne après quantification) — c'est lui,
   * jamais celui demandé, que l'appelant doit pousser en état optimiste.
   *
   * ⚠️ `idx` vaut TOUJOURS 1 dans chaque sous-liste de `vals` (§ 1.2 : la position se
   * lit par l'INDEX du sous-tableau dans `vals`, pas par la valeur d'`idx`) — ne pas la
   * faire incrémenter, ce serait diverger du contrat vérifié sur les deux références.
   *
   * Rejeu réactif borné à UN, sur TYPE_AUTH uniquement — même doctrine que
   * lireParametres() (le rejeu d'appairage reste à la charge de l'appelant).
   *
   * @param array $_appareil Clés GÉNÉRIQUES, PLUS 'swing_inverse' (bool, § 3.5 —
   *   auxcloud_swing_inverse de l'équipement).
   * @param array $_ordre Map GÉNÉRIQUE concept => valeur générique.
   * @param int $_budget Budget de temps GLOBAL, session comprise.
   * @return array Ordre RÉELLEMENT appliqué.
   * @throws smartclimException TYPE_INTERNE (traduction) ou TYPE_* (via requeteSdkControl()).
   */
  public static function appliquerOrdre(array $_appareil, array $_ordre, $_budget) {
    try {
      $debut = microtime(true);
      if ($_budget < self::RESERVE_ORDRE) {
        throw new smartclimException('AUX Cloud legacy : budget insuffisant pour émettre l\'ordre', smartclimException::TYPE_INTERNE);
      }

      $definitions = self::parametresAuxCloud();
      $echelle = smartclimCapabilities::echelleTemperature(smartclimCapabilities::TRANSPORT_AUX_CLOUD_LEGACY);
      $swingInverse = !empty($_appareil['swing_inverse']);

      $params = array();
      $vals = array();
      $ordreApplique = array();

      foreach ($_ordre as $concept => $valeurGenerique) {
        if (!isset($definitions[$concept])) {
          throw new smartclimException('AUX Cloud legacy control : concept générique sans correspondance (' . $concept . ')', smartclimException::TYPE_INTERNE);
        }
        $definition = $definitions[$concept];
        if (!empty($definition['lecture_seule'])) {
          throw new smartclimException('AUX Cloud legacy control : concept en lecture seule (' . $concept . ')', smartclimException::TYPE_INTERNE);
        }

        if ($definition['nature'] === 'booleen') {
          $valeurEcrite = $valeurGenerique ? 1 : 0;
          $ordreApplique[$concept] = (bool) $valeurGenerique;
        } elseif ($definition['nature'] === 'table') {
          $valeurEcrite = smartclimCapabilities::versTransport(smartclimCapabilities::TRANSPORT_AUX_CLOUD_LEGACY, $concept, $valeurGenerique);
          if ($valeurEcrite === null) {
            throw new smartclimException('AUX Cloud legacy control : valeur générique sans correspondance (' . $concept . '=' . $valeurGenerique . ')', smartclimException::TYPE_INTERNE);
          }
          $ordreApplique[$concept] = $valeurGenerique;
        } elseif ($definition['nature'] === 'temperature') {
          if (!isset($echelle['facteur'])) {
            throw new smartclimException('AUX Cloud legacy control : échelle de température inconnue pour ce transport', smartclimException::TYPE_INTERNE);
          }
          $valeurEcrite = (int) round(((float) $valeurGenerique) * $echelle['facteur']);
          // Division INVERSE (même motif que smartclimAuxHomeApi::appliquerOrdre()) :
          // c'est la valeur qui RESSORT de l'arrondi d'écriture qui part en état
          // optimiste, jamais la valeur demandée telle quelle.
          $ordreApplique[$concept] = ($echelle['facteur'] != 0) ? ($valeurEcrite / $echelle['facteur']) : (float) $valeurGenerique;
        } elseif ($definition['nature'] === 'oscillation') {
          $codes = smartclimCapabilities::codesOscillation($concept, smartclimCapabilities::TRANSPORT_AUX_CLOUD_LEGACY);
          if (!isset($codes['actif'], $codes['fixe'])) {
            throw new smartclimException('AUX Cloud legacy control : concept d\'oscillation sans codes déclarés (' . $concept . ')', smartclimException::TYPE_INTERNE);
          }
          // § 3.5 de la spec technique : auxcloud_swing_inverse échange les deux codes,
          // au DÉCODAGE (decoderParametres()) COMME à l'encodage — ici.
          $actif = $swingInverse ? $codes['fixe'] : $codes['actif'];
          $fixe = $swingInverse ? $codes['actif'] : $codes['fixe'];
          $valeurEcrite = $valeurGenerique ? $actif : $fixe;
          $ordreApplique[$concept] = $valeurGenerique ? 1 : 0;
        } else {
          throw new smartclimException('AUX Cloud legacy control : nature de paramètre inconnue (' . $definition['nature'] . ')', smartclimException::TYPE_INTERNE);
        }

        $params[] = $definition['cle'];
        $vals[] = array(array('idx' => 1, 'val' => $valeurEcrite));
      }

      if (empty($params)) {
        throw new smartclimException('AUX Cloud legacy control : ordre vide, aucune requête envoyée', smartclimException::TYPE_INTERNE);
      }

      $session = self::session();
      $rejoue = false;
      while (true) {
        $tempsRequete = (int) max(3, min(self::TIMEOUT_REQUETE, $_budget - (microtime(true) - $debut)));
        try {
          self::requeteSdkControl($session, $_appareil, 'set', $params, $vals, $tempsRequete);
          break;
        } catch (smartclimException $e) {
          $restant = $_budget - (microtime(true) - $debut);
          if (!$rejoue && $e->getType() === smartclimException::TYPE_AUTH && $restant >= self::BUDGET_REJEU_ORDRE) {
            $rejoue = true;
            self::purgerSession();
            $session = self::login();
            continue;
          }
          throw $e;
        }
      }

      return $ordreApplique;
    } catch (smartclimException $e) {
      throw new smartclimException($e->getMessage(), $e->getType(), $e->getContexte());
    }
  }

  /**
   * Conversions INVERSES de parametresAuxCloud() (UC03, § 5.2 de la spec technique) :
   * `null`/clé absente de $_valeurs -> clé ABSENTE de l'état renvoyé, JAMAIS de repli
   * silencieux (invariant « une clé absente ne touche pas sa commande »). Filtre les
   * concepts de confort/oscillation/protection par conceptsConfortLivres()/
   * conceptsOscillationRelus()/conceptsProtectionLivres() — MÊME MÉCANISME que
   * smartclimFrame::decoderEtat(), pour ne pas le dupliquer là où il divergerait.
   *
   * ⚠️ NE LÈVE JAMAIS.
   *
   * @param array $_valeurs nom brut => valeur brute (rendu par lireParametres() ou
   *   recomposerValeurs()).
   * @param bool $_swingInverse Équipement.auxcloud_swing_inverse (§ 3.5) : échange
   *   'actif'/'fixe' au décodage COMME à l'encodage (appliquerOrdre() ci-dessus).
   * @return array<string, mixed> concept générique => valeur générique.
   */
  private static function decoderParametres(array $_valeurs, $_swingInverse = false) {
    $etat = array();
    $echelle = smartclimCapabilities::echelleTemperature(smartclimCapabilities::TRANSPORT_AUX_CLOUD_LEGACY);
    $facteur = (isset($echelle['facteur']) && $echelle['facteur'] > 0) ? $echelle['facteur'] : 1;

    $livresConfort = smartclimCapabilities::conceptsConfortLivres();
    $relusOscillation = smartclimCapabilities::conceptsOscillationRelus();
    $livresProtection = smartclimCapabilities::conceptsProtectionLivres();

    foreach (self::parametresAuxCloud() as $concept => $definition) {
      if (!array_key_exists($definition['cle'], $_valeurs)) {
        continue;
      }
      $brut = $_valeurs[$definition['cle']];

      if ($definition['nature'] === 'booleen') {
        if (in_array($concept, smartclimCapabilities::conceptsConfort(), true) && !in_array($concept, $livresConfort, true)) {
          continue;
        }
        if (in_array($concept, smartclimCapabilities::conceptsProtection(), true) && !in_array($concept, $livresProtection, true)) {
          continue;
        }
        if (!is_scalar($brut)) {
          continue;
        }
        $etat[$concept] = ((int) $brut) !== 0;
        continue;
      }

      if ($definition['nature'] === 'table') {
        if (!is_numeric($brut)) {
          continue;
        }
        $valeurGenerique = smartclimCapabilities::depuisTransport(smartclimCapabilities::TRANSPORT_AUX_CLOUD_LEGACY, $concept, (int) $brut);
        if ($valeurGenerique === null) {
          continue;
        }
        $etat[$concept] = $valeurGenerique;
        continue;
      }

      if ($definition['nature'] === 'temperature') {
        if (!is_numeric($brut)) {
          continue;
        }
        // AC5 : garde de plausibilité — hors bornes, la clé reste ABSENTE (rend « 2,4 »
        // et « 240 » structurellement inatteignables).
        $entier = (int) $brut;
        if ($entier < self::TEMP_MIN_PLAUSIBLE || $entier > self::TEMP_MAX_PLAUSIBLE) {
          continue;
        }
        $etat[$concept] = $entier / $facteur;
        continue;
      }

      if ($definition['nature'] === 'oscillation') {
        if (!in_array($concept, $relusOscillation, true) || !is_numeric($brut)) {
          continue;
        }
        $codes = smartclimCapabilities::codesOscillation($concept, smartclimCapabilities::TRANSPORT_AUX_CLOUD_LEGACY);
        if (!isset($codes['actif'], $codes['fixe'])) {
          continue;
        }
        $actif = $_swingInverse ? $codes['fixe'] : $codes['actif'];
        $fixe = $_swingInverse ? $codes['actif'] : $codes['fixe'];
        $code = (int) $brut;
        if ($code === $actif) {
          $etat[$concept] = true;
        } elseif ($code === $fixe) {
          $etat[$concept] = false;
        }
        // Toute AUTRE valeur (position figée intermédiaire, etc.) : clé ABSENTE, jamais
        // un repli devinée.
      }
    }

    return $etat;
  }

  /**
   * Re-obtient `cookie`/`devSession` d'un appareil déjà connu, sur son `familyId`
   * persisté (UC03, § 3.7/D7/5.2 de la spec technique) — contrat imposé par l'UC02 § 4.1 :
   * l'ABSENCE de la 7ᵉ mémoire est le cas NOMINAL. UNE requête `dev/query?action=select`
   * (ou `sharedev/querylist?querytype=shared` si `$_partage`).
   *
   * @param string $_familyId
   * @param string $_endpointId
   * @param bool $_partage
   * @param int $_budget
   * @return array{cookie:string,dev_session:string,mac:string,type_produit:string,devicetype_flag:string}
   * @throws smartclimException TYPE_PROTOCOLE si l'endpoint n'est pas dans la famille,
   *   ou TYPE_* (via session()/requeteAppareilsFamille()). Message TECHNIQUE, recréée À
   *   CE POINT D'APPEL (même motif que listerAppareils()).
   */
  public static function jetonsAppareil($_familyId, $_endpointId, $_partage, $_budget) {
    try {
      $debut = microtime(true);
      $session = self::session();
      // Correctif post-review UC03 (point 4) : ALIGNÉE sur ses 3 sœurs
      // (listerAppareils()/lireParametres()/appliquerOrdre()) — rejeu réactif borné à
      // UN, sur TYPE_AUTH uniquement (classerStatutLegacy() l'arme si `status != 0`).
      // Sans lui, une session en cache VALIDE SUR LA FORME mais REJETÉE par le backend
      // faisait remonter « Identifiants invalides » sur des identifiants parfaitement
      // valides — un message affirmatif et faux. ⚠️ Le `try` n'entoure QUE la requête
      // métier, JAMAIS session() : un TYPE_AUTH levé à l'ouverture de session ne doit
      // jamais déclencher de rejeu (anti-boucle de l'UC08 du MVP).
      $rejoue = false;
      while (true) {
        $tempsRequete = (int) max(3, min(self::TIMEOUT_REQUETE, $_budget - (microtime(true) - $debut)));
        try {
          $bruts = self::requeteAppareilsFamille($session, (string) $_familyId, (bool) $_partage, $tempsRequete);
          break;
        } catch (smartclimException $e) {
          $restant = $_budget - (microtime(true) - $debut);
          if (!$rejoue && $e->getType() === smartclimException::TYPE_AUTH && $restant >= self::BUDGET_REJEU_ORDRE) {
            $rejoue = true;
            log::add('smartclim', 'info', 'AUX Cloud legacy : rejeu après re-login (jetons d\'appairage)');
            self::purgerSession();
            $session = self::login();
            continue;
          }
          throw $e;
        }
      }

      foreach ($bruts as $brut) {
        $identifiantBrut = (is_array($brut) && isset($brut['endpointId']) && is_scalar($brut['endpointId'])) ? (string) $brut['endpointId'] : '';
        if ($identifiantBrut === '' || $identifiantBrut !== (string) $_endpointId) {
          continue;
        }
        return array(
          'cookie' => isset($brut['cookie']) && is_scalar($brut['cookie']) ? (string) $brut['cookie'] : '',
          'dev_session' => isset($brut['devSession']) && is_scalar($brut['devSession']) ? (string) $brut['devSession'] : '',
          'mac' => isset($brut['mac']) && is_scalar($brut['mac']) ? (string) $brut['mac'] : '',
          'type_produit' => isset($brut['productId']) && is_scalar($brut['productId']) ? (string) $brut['productId'] : '',
          'devicetype_flag' => isset($brut['devicetypeFlag']) && is_scalar($brut['devicetypeFlag']) ? (string) $brut['devicetypeFlag'] : '',
        );
      }
      throw new smartclimException('AUX Cloud legacy : appareil absent de la famille (endpointId tronqué=' . substr((string) $_endpointId, 0, 6) . '...)', smartclimException::TYPE_PROTOCOLE);
    } catch (smartclimException $e) {
      throw new smartclimException($e->getMessage(), $e->getType(), $e->getContexte());
    }
  }

  /**
   * Émet le SECOND `get` CONDITIONNÉ (`params: ["mode"]`, `AC_SPECIAL_PARAMS` des
   * références) — § 1.7/8 de la spec technique UC03. ⚠️ N'est PAS émis sur le chemin
   * périodique (R2 : conditionné au `productId` dans la référence, son rôle n'est établi
   * par AUCUNE source, il doublerait le trafic d'un cycle O(N)) — SEUL accès :
   * l'usage `--special` de la 5ᵉ CLI, seul moyen de trancher FACTUELLEMENT s'il complète
   * `envtemp` sur certains modèles.
   *
   * @param array $_appareil Clés GÉNÉRIQUES (cf. requeteSdkControl()).
   * @param int $_budget
   * @return array<string, mixed> nom brut => valeur brute.
   * @throws smartclimException Message TECHNIQUE, recréée À CE POINT D'APPEL (même
   *   motif que lireParametres()).
   */
  public static function lireParametreSpecial(array $_appareil, $_budget = self::BUDGET_ETAT) {
    try {
      $session = self::session();
      $tempsRequete = (int) max(3, min(self::TIMEOUT_REQUETE, $_budget));
      $reponse = self::requeteSdkControl($session, $_appareil, 'get', array('mode'), array(), $tempsRequete);
      return self::recomposerValeurs($reponse);
    } catch (smartclimException $e) {
      throw new smartclimException($e->getMessage(), $e->getType(), $e->getContexte());
    }
  }

  /**
   * Instrument de la 5ᵉ CLI (§ 5.2/8 de la spec technique) : `set` d'UNE clé BRUTE
   * validée et d'un entier borné — échappatoire d'investigation (chercher un bit non
   * déclaré), MÊME PATRON que smartclimAuxHomeApi::sonderIntent(). ⚠️ Forme validée
   * DEUX FOIS (script + ici) — défense en profondeur, ne supprimer ni l'une ni l'autre.
   *
   * @param array $_appareil Clés GÉNÉRIQUES (cf. requeteSdkControl()).
   * @param string $_cle
   * @param int $_valeur
   * @param int $_budget
   * @throws smartclimException TYPE_INTERNE (forme invalide) ou TYPE_* (via requeteSdkControl()).
   */
  public static function sonderParametre(array $_appareil, $_cle, $_valeur, $_budget) {
    if (!is_string($_cle) || preg_match('/\A[a-z_][a-z0-9_]{0,31}\z/', $_cle) !== 1) {
      throw new smartclimException('AUX Cloud legacy : nom de paramètre de forme inattendue', smartclimException::TYPE_INTERNE);
    }
    try {
      $debut = microtime(true);
      $session = self::session();
      $tempsRequete = (int) max(3, min(self::TIMEOUT_REQUETE, $_budget - (microtime(true) - $debut)));
      $valeur = (int) max(-32768, min(32767, (int) $_valeur));
      self::requeteSdkControl($session, $_appareil, 'set', array($_cle), array(array(array('idx' => 1, 'val' => $valeur))), $tempsRequete);
    } catch (smartclimException $e) {
      throw new smartclimException($e->getMessage(), $e->getType(), $e->getContexte());
    }
  }

  /**
   * Concept générique -> paramètre AUX Cloud legacy (§ 5.2 de la spec technique UC03,
   * ÉTENDUE depuis les 5 entrées d'UC02) — SEUL endroit du plugin où vivent ces 13 noms
   * propriétaires ('pwr'/'ac_mode'/'ac_mark'/'temp'/'envtemp'/'scrdisp'/'ac_slp'/
   * 'ac_health'/'ac_clean'/'mldprf'/'childlock'/'ac_vdir'/'ac_hdir'). Pendant exact
   * d'intentionsAuxHome(). ⚠️ Ne pas la figer en simple liste plate : `err_flag`/
   * `ac_errcode1` (seul canal de code d'erreur d'appareil de l'écosystème) restent hors
   * périmètre, aucun CONCEPT_* générique ne les porte (R9 de la spec technique d'UC02).
   *
   * 'nature' ∈ 'booleen' / 'table' / 'temperature' / 'oscillation' — pilote la
   * traduction dans decoderParametres()/appliquerOrdre() (même doctrine que
   * smartclimAuxHomeApi::intentionsAuxHome()). 'lecture_seule' => true UNIQUEMENT pour
   * `ambient_temp` : appliquerOrdre() refuse tout concept qui la porte.
   *
   * ⚠️ `ecomode`/`comfwind`/`ac_astheat`/`pwrlimit*` NON MAPPÉS : aucun CONCEPT_* ne les
   * porte, en créer un contournerait la décision D-UC01-Q1 du domaine post-mvp/04
   * (aucun bit de lecture connu côté LAN pour Éco/Ultra-silence — les exposer ici
   * romprait la symétrie « même concept, même statut de recette, quel que soit le
   * transport »).
   *
   * @return array<string,array{cle:string,nature:string,lecture_seule?:bool}>
   */
  private static function parametresAuxCloud() {
    return array(
      smartclimCapabilities::CONCEPT_POWER => array('cle' => 'pwr', 'nature' => 'booleen'),
      smartclimCapabilities::CONCEPT_MODE => array('cle' => 'ac_mode', 'nature' => 'table'),
      smartclimCapabilities::CONCEPT_FAN_SPEED => array('cle' => 'ac_mark', 'nature' => 'table'),
      smartclimCapabilities::CONCEPT_TARGET_TEMP => array('cle' => 'temp', 'nature' => 'temperature'),
      // 'envtemp' : seul concept 'lecture_seule' de cette table (§ 5.2 de la spec
      // technique) — le second `get` conditionné qui pourrait le compléter sur certains
      // modèles (`AC_SPECIAL_PARAMS`) n'est PAS émis sur ce chemin (R2 de la spec
      // technique d'UC02) : cf. l'usage `--special` de la 5ᵉ CLI.
      smartclimCapabilities::CONCEPT_AMBIENT_TEMP => array('cle' => 'envtemp', 'nature' => 'temperature', 'lecture_seule' => true),
      smartclimCapabilities::CONCEPT_DISPLAY => array('cle' => 'scrdisp', 'nature' => 'booleen'),
      smartclimCapabilities::CONCEPT_SLEEP => array('cle' => 'ac_slp', 'nature' => 'booleen'),
      smartclimCapabilities::CONCEPT_HEALTH => array('cle' => 'ac_health', 'nature' => 'booleen'),
      smartclimCapabilities::CONCEPT_CLEAN => array('cle' => 'ac_clean', 'nature' => 'booleen'),
      smartclimCapabilities::CONCEPT_MILDEW => array('cle' => 'mldprf', 'nature' => 'booleen'),
      smartclimCapabilities::CONCEPT_CHILD_LOCK => array('cle' => 'childlock', 'nature' => 'booleen'),
      smartclimCapabilities::CONCEPT_SWING_V => array('cle' => 'ac_vdir', 'nature' => 'oscillation'),
      smartclimCapabilities::CONCEPT_SWING_H => array('cle' => 'ac_hdir', 'nature' => 'oscillation'),
    );
  }

  /**
   * `productId` de pompes à chaleur DOCUMENTÉS (§ 1.3 de la spec technique) —
   * EXCLUSION sur preuve positive, jamais une inclusion : hors périmètre du plugin
   * (§ 0 de la spec fonctionnelle), qui cible les climatiseurs.
   *
   * @return string[]
   */
  private static function produitsPompeAChaleur() {
    return array('000000000000000000000000c3aa0000');
  }

  /**
   * `productId` de climatiseurs DOCUMENTÉS (§ 1.3 de la spec technique).
   *
   * ⚠️ Correctif reviews croisées (finding major #1) : NE GOUVERNE PLUS la création ni
   * l'exigence de la preuve estClimatiseur() — cette table ne gouvernait qu'un critère
   * (l'appartenance au catalogue), quand le § 3.1/D1 de la spec technique en visait un
   * autre (l'état de RAPPROCHEMENT Jeedom, décidé dans smartclim::scannerAuxCloud()).
   * Son SEUL rôle restant : `type_produit_connu` gouverne la ligne `log info` d'AC4
   * (« identifiant de produit inconnu ») — rien d'autre.
   *
   * @return string[]
   */
  private static function produitsClimatiseurConnus() {
    return array(
      '000000000000000000000000c0620000',
      '0000000000000000000000002a4e0000',
    );
  }

  /**
   * Preuve qu'un appareil au `productId` INCONNU est bien un climatiseur (§ 3.1/D1 de la
   * spec technique) : `pwr` présent ET (`temp` OU `ac_mode`) présent. ⚠️ `pwr` NU
   * discrimine climatiseur de pompe à chaleur : `HP_PARAMS` porte `ac_pwr`/`ac_temp`,
   * jamais `pwr`/`temp` (vérifié sur `const.py`, § 1.3).
   *
   * @param string[] $_noms
   * @return bool
   */
  private static function estClimatiseur(array $_noms) {
    return in_array('pwr', $_noms, true) && (in_array('temp', $_noms, true) || in_array('ac_mode', $_noms, true));
  }

  /**
   * Ligne normalisée à clés génériques FRANÇAISES pour un appareil BRUT (§ 6.1 de la
   * spec technique) — AUCUN nom de champ propriétaire n'en sort. `cookie`/`dev_session`
   * ont une destination EXCLUSIVE (mémoire chiffrée `smartclim::appareil_auxcloud::<id>`,
   * jamais journalisés ni rendus), même statut que `capacites_brutes` chez AUX Home.
   *
   * ⚠️ Renvoie `null` si `endpointId` est vide après nettoyage — sans identifiant
   * exploitable, aucun rapprochement ni création n'est possible (même doctrine que
   * `ignore_identifiant` côté AUX Home).
   *
   * ⚠️ Correctif reviews croisées (findings major #1/#2) : `motif_exclusion` (`''` /
   * `'pompe_a_chaleur'` / `'budget_epuise'`, JAMAIS `'non_climatiseur'` — ce motif-là se
   * décide en fonction du RAPPROCHEMENT Jeedom, hors de portée de cette classe, cf.
   * smartclim::scannerAuxCloud()) et `preuve_climatiseur` (bool) sont posées ICI en
   * valeurs de base, puis RENSEIGNÉES par executerDecouverte() — cette méthode-ci ne
   * décide plus rien, elle ne fait QUE normaliser les champs bruts.
   *
   * @param mixed $_brut
   * @param string $_familyId
   * @param bool $_partage
   * @return array{mac:string,identifiant:string,nom:string,type_produit:string,type_produit_connu:bool,devicetype_flag:string,famille:string,partage:bool,enLigne:bool,parametres:array,valeurs:array,motif_exclusion:string,preuve_climatiseur:bool,cookie:string,dev_session:string}|null
   */
  private static function normaliserAppareilLegacy($_brut, $_familyId, $_partage) {
    if (!is_array($_brut)) {
      return null;
    }

    $macBrute = isset($_brut['mac']) ? $_brut['mac'] : '';
    $mac = '';
    if (is_scalar($macBrute)) {
      $mac = preg_replace('/[^0-9a-f]/', '', strtolower((string) $macBrute));
      if (strlen($mac) !== 12) {
        $mac = '';
      }
    }

    $identifiantBrut = isset($_brut['endpointId']) ? $_brut['endpointId'] : '';
    $identifiant = is_scalar($identifiantBrut) ? self::nettoyerTexteExterne($identifiantBrut, 100) : '';
    if ($identifiant === '') {
      return null;
    }

    $nomBrut = isset($_brut['friendlyName']) ? $_brut['friendlyName'] : '';
    $nom = is_scalar($nomBrut) ? self::nettoyerTexteExterne($nomBrut, 127) : '';

    $typeProduitBrut = isset($_brut['productId']) ? $_brut['productId'] : '';
    $typeProduit = is_scalar($typeProduitBrut) ? self::nettoyerTexteExterne($typeProduitBrut, self::PRODUIT_INCONNU_MAX) : '';

    $devicetypeFlagBrut = isset($_brut['devicetypeFlag']) ? $_brut['devicetypeFlag'] : '';
    $devicetypeFlag = is_scalar($devicetypeFlagBrut) ? self::nettoyerTexteExterne($devicetypeFlagBrut, 32) : '';

    $cookieBrut = isset($_brut['cookie']) ? $_brut['cookie'] : '';
    $cookie = self::jetonAppareilConforme($cookieBrut, 4096) ? (string) $cookieBrut : '';
    if ($cookieBrut !== '' && ($cookie === '' || !self::cookieDecodable($cookie))) {
      // § 7 de la spec technique : log SANS AUCUN CONTENU (jamais le cookie), la
      // mémorisation du brut a lieu QUAND MÊME (l'appelant, smartclim::, la fera).
      log::add('smartclim', 'warning', 'AUX Cloud legacy : jeton d\'appairage illisible pour un appareil, identifiant=' . $identifiant);
    }

    $devSessionBrut = isset($_brut['devSession']) ? $_brut['devSession'] : '';
    $devSession = self::jetonAppareilConforme($devSessionBrut, 512) ? (string) $devSessionBrut : '';

    return array(
      'mac' => $mac,
      'identifiant' => $identifiant,
      'nom' => $nom,
      'type_produit' => $typeProduit,
      'type_produit_connu' => in_array($typeProduit, self::produitsClimatiseurConnus(), true),
      'devicetype_flag' => $devicetypeFlag,
      'famille' => $_familyId,
      'partage' => (bool) $_partage,
      'enLigne' => false,
      'parametres' => array(),
      // UC03 du domaine post-mvp/03-cloud-aux-legacy (§ 5.2/6.3 de sa spec technique) :
      // placeholder au même titre que 'parametres' ci-dessus — RENSEIGNÉ par
      // executerDecouverte() sur le chemin normal ; reste vide sur les deux motifs
      // d'exclusion INDÉPENDANTS du rapprochement (budget épuisé, pompe à chaleur), qui
      // ne tentent aucun sdkcontrol.
      'valeurs' => array(),
      'motif_exclusion' => '',
      'preuve_climatiseur' => false,
      'cookie' => $cookie,
      'dev_session' => $devSession,
    );
  }

  /**
   * Profil de capacités générique de CET appareil (UC02, § 3.3/6.1 de la spec technique,
   * ÉTENDU par l'UC03 — § 3.1/5.2 de sa spec technique) : `concepts` = UNION sûre des
   * clés RÉELLEMENT annoncées (preuve de présence, jamais une inclusion devinée),
   * désormais étendue aux concepts de confort/oscillation/protection, filtrés par les
   * `conceptsXxxLivres()` — donc RIEN de neuf n'entre au profil aujourd'hui (les cinq
   * fonctions de confort, les deux oscillations et la protection restent `confirme =>
   * false`), l'entrée au profil étant IRRÉVERSIBLE (`appliquerCapacites()` unionne sans
   * équivalent de `modes_exclus`).
   *
   * `modes`/`vitesses` = catalogue `valeursLisibles(TRANSPORT_AUX_CLOUD_LEGACY, …)`,
   * PLUS la colonne DÉCLARATIVE `catalogue_par_defaut => true` (D1, § 3.1 de la spec
   * technique UC03) — « ce que je publie est un catalogue faute de pouvoir rien
   * exclure, ce n'est pas une détection ». C'est `smartclim::appliquerCapacites()`, et
   * UNIQUEMENT elle, qui décide de neutraliser ce catalogue quand l'équipement est
   * AUSSI connu d'AUX Home (`auxhome_device_id` non vide) — cette classe ne teste
   * jamais `source === TRANSPORT_AUX_CLOUD_LEGACY` en dur. ⚠️ `catalogue_par_defaut` ne
   * doit JAMAIS être recopiée dans le profil stocké : c'est le rôle de l'appelant de
   * l'omettre du tableau `$fusion` qu'il construit clé par clé.
   * `modes_exclus` reste VIDE (aucune preuve disponible sur ce transport).
   *
   * ⚠️ Ne lève JAMAIS.
   *
   * @param array $_appareil Ligne normalisée par normaliserAppareilLegacy() (enrichie de
   *   'parametres' par listerAppareils()).
   * @return array{concepts:array,modes:array,vitesses:array,modes_exclus:array,temperature:array,source:string,catalogue_par_defaut:bool}
   */
  public static function capacitesAppareil(array $_appareil) {
    $parametres = isset($_appareil['parametres']) && is_array($_appareil['parametres']) ? $_appareil['parametres'] : array();
    $conceptsDeBase = array(
      smartclimCapabilities::CONCEPT_POWER,
      smartclimCapabilities::CONCEPT_MODE,
      smartclimCapabilities::CONCEPT_TARGET_TEMP,
      smartclimCapabilities::CONCEPT_AMBIENT_TEMP,
      smartclimCapabilities::CONCEPT_FAN_SPEED,
    );
    $conceptsLivres = array_merge(
      smartclimCapabilities::conceptsConfortLivres(),
      smartclimCapabilities::conceptsOscillationLivres(),
      smartclimCapabilities::conceptsProtectionLivres()
    );
    $concepts = array();
    foreach (self::parametresAuxCloud() as $concept => $definition) {
      if (!in_array($definition['cle'], $parametres, true)) {
        continue;
      }
      if (!in_array($concept, $conceptsDeBase, true) && !in_array($concept, $conceptsLivres, true)) {
        continue;
      }
      $concepts[] = $concept;
    }
    return array(
      'concepts' => $concepts,
      'modes' => smartclimCapabilities::valeursLisibles(smartclimCapabilities::TRANSPORT_AUX_CLOUD_LEGACY, smartclimCapabilities::CONCEPT_MODE),
      'vitesses' => smartclimCapabilities::valeursLisibles(smartclimCapabilities::TRANSPORT_AUX_CLOUD_LEGACY, smartclimCapabilities::CONCEPT_FAN_SPEED),
      'modes_exclus' => array(),
      'temperature' => smartclimCapabilities::bornesParDefaut(),
      'source' => smartclimCapabilities::TRANSPORT_AUX_CLOUD_LEGACY,
      'catalogue_par_defaut' => true,
    );
  }

  /**
   * État de CET appareil : `online` (UC02, § 0/6.1 de sa spec technique), ÉTENDU
   * ADDITIVEMENT par l'UC03 (§ 5.2 de sa spec technique) — décode aussi les VALEURS de
   * paramètre quand la clé `valeurs` est présente (posée par `executerDecouverte()` au
   * scan, ou par `smartclim::rafraichirAuxCloudEquipement()`/`rafraichirAuxCloud()` au
   * cycle continu). Signature et clés EXISTANTES inchangées : l'appel d'UC02 dans
   * `scannerAuxCloud()` continue de fonctionner et gagne l'état complet AU SCAN, sans
   * seconde requête. `online` ABSENT si l'état groupé n'a pas couvert ce `did` —
   * invariant « une clé absente ne touche pas sa commande ». ⚠️ Ne lève JAMAIS.
   *
   * @param array $_appareil Ligne enrichie par listerAppareils()
   *   ('enLigne'/'enLigne_connue'), PLUS 'valeurs' (§ 5.2) et 'swing_inverse' (§ 3.5)
   *   pour le décodage des paramètres.
   * @return array{online?:bool,source:string}
   */
  public static function etatAppareil(array $_appareil) {
    $etat = array('source' => smartclimCapabilities::TRANSPORT_AUX_CLOUD_LEGACY);
    if (!empty($_appareil['enLigne_connue'])) {
      $etat['online'] = (bool) $_appareil['enLigne'];
    }
    if (isset($_appareil['valeurs']) && is_array($_appareil['valeurs']) && !empty($_appareil['valeurs'])) {
      $etat = array_merge($etat, self::decoderParametres($_appareil['valeurs'], !empty($_appareil['swing_inverse'])));
    }
    return $etat;
  }

  /**
   * 3e frontière d'assainissement du plugin (UC02, § 6.1 de la spec technique) —
   * STRICTEMENT symétrique de smartclimAuxHomeApi::nettoyerTexteExterne() et
   * smartclimBroadlinkLan::nettoyerNomExterne() : caractères de contrôle -> garde UTF-8
   * -> retrait `<`/`>` -> trim -> troncature UTF-8-safe. Un même appareil vu par deux
   * transports doit porter le même nom.
   *
   * @param mixed $_valeur
   * @param int $_max
   * @return string
   */
  private static function nettoyerTexteExterne($_valeur, $_max) {
    if (!is_scalar($_valeur)) {
      return '';
    }
    $valeur = preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) $_valeur);
    if (preg_match('//u', $valeur) !== 1) {
      $valeur = preg_replace('/[^\x20-\x7E]/', ' ', $valeur);
    }
    $valeur = str_replace(array('<', '>'), '', $valeur);
    $valeur = trim($valeur);
    $valeur = substr($valeur, 0, $_max);
    while ($valeur !== '' && preg_match('//u', $valeur) !== 1) {
      $valeur = substr($valeur, 0, -1);
    }
    return $valeur;
  }

  /**
   * Forme d'un `cookie`/`devSession` AVANT mémorisation (§ 7 de la spec technique) —
   * jamais journalisés, jamais rendus. Classe volontairement LARGE (base64 étendu) :
   * aucune référence ne documente la forme exacte de ces deux jetons.
   *
   * @param mixed $_valeur
   * @param int $_max
   * @return bool
   */
  private static function jetonAppareilConforme($_valeur, $_max) {
    return is_string($_valeur) && $_valeur !== '' && preg_match('/\A[A-Za-z0-9+\/=_.~-]{1,' . (int) $_max . '}\z/', $_valeur) === 1;
  }

  /**
   * `cookie` décodable en base64 STRICT puis JSON, portant `terminalid` ET `aeskey`
   * (§ 7 de la spec technique). Sert UNIQUEMENT à décider d'un `log warning` (jamais de
   * contenu) — la mémorisation du brut a lieu quand même (§ 4 : « la mémoire sera le
   * plus souvent EXPIRÉE », contrat imposé à l'UC03).
   *
   * @param string $_cookie
   * @return bool
   */
  private static function cookieDecodable($_cookie) {
    $decode = base64_decode($_cookie, true);
    if ($decode === false) {
      return false;
    }
    $json = json_decode($decode, true);
    return is_array($json) && isset($json['terminalid']) && isset($json['aeskey']);
  }
}
