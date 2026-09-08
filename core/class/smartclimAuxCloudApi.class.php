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

/**
 * Brique de transport "AUX Cloud legacy" (cloud historique Broadlink, connu aussi sous
 * AC Freedom / AUX Cloud, hôtes app-service-(region).smarthomecs.* ou ibroadlink.com).
 *
 * UC01 du domaine post-mvp/03-cloud-aux-legacy — périmètre : authentification
 * multi-régions UNIQUEMENT (login(), session(), purgerSession()). La découverte des
 * familles/pièces/appareils (UC02) et la lecture/écriture d'état (UC03) sont HORS
 * périmètre de cette classe pour l'instant.
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
        self::journaliserErreurLegacy($donnees);
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
   *   optionnels (non utilisés par le login lui-même — préparés pour l'UC02, qui
   *   authentifiera ses appels via ces deux en-têtes plutôt qu'un bearer).
   * @return array Enveloppe JSON décodée (contient au moins 'status').
   * @throws smartclimException TYPE_RESEAU ou TYPE_PROTOCOLE.
   */
  private static function requete($_chemin, $_corpsChiffre, $_horodatage, $_token, $_tempsRequete, $_session = null) {
    $hote = self::hoteRegion(smartclim::regionAuxCloud());

    $entetes = array(
      'Content-Type: application/x-java-serialized-object',
      'timestamp: ' . $_horodatage,
      'token: ' . $_token,
      'licenseId: ' . self::LICENSE_ID,
      'lid: ' . self::LICENSE_ID,
      'language: en',
      'appVersion: ' . self::SPOOF_APP_VERSION,
      'User-Agent: ' . self::SPOOF_USER_AGENT,
      'system: ' . self::SPOOF_SYSTEM,
      'appPlatform: ' . self::SPOOF_APP_PLATFORM,
    );
    // On n'émet "loginsession"/"userid" QUE non vides (§ 1.2 de la spec technique) : au
    // login ils n'existent pas encore ($_session est null ici), l'UC02 les fournira pour
    // ses appels authentifiés.
    if (is_array($_session)) {
      if (isset($_session['loginsession']) && $_session['loginsession'] !== '') {
        $entetes[] = 'loginsession: ' . $_session['loginsession'];
      }
      if (isset($_session['userid']) && $_session['userid'] !== '') {
        $entetes[] = 'userid: ' . $_session['userid'];
      }
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $hote . $_chemin);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT_CONNEXION);
    curl_setopt($ch, CURLOPT_TIMEOUT, $_tempsRequete);
    curl_setopt($ch, CURLOPT_NOSIGNAL, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $_corpsChiffre);
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

    // Étape 5 : corps non-JSON, ou "status" absent.
    $donnees = json_decode((string) $reponse, true);
    if (!is_array($donnees) || !isset($donnees['status'])) {
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
   * @param array $_donnees Enveloppe décodée renvoyée par requete().
   */
  private static function journaliserErreurLegacy($_donnees) {
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
    log::add('smartclim', 'error', 'AUX Cloud legacy (login) : status=' . $status . ' message=' . $message);
  }
}
