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
// Rend ce fichier autonome : toutes les methodes publiques de cette classe levent une
// smartclimException, que l'autoloader du core ne resoudra jamais seul (cf.
// core/php/smartclim.inc.php). require_once est idempotent : aucun cout quand
// smartclim.inc.php l'a deja chargee juste avant.
require_once __DIR__ . '/smartclimException.class.php';
// Même motif : capacitesAppareil() ci-dessous appelle smartclimCapabilities::valeursLisibles().
// require_once idempotent, sans coût quand smartclim.inc.php l'a déjà chargée.
require_once __DIR__ . '/smartclimCapabilities.class.php';

/**
 * Brique de transport "AUX Home" (cloud eu-smthome-api.aux-global.com).
 *
 * Conformément à CLAUDE.md (« les noms de champs d'API… restent confinés dans la
 * brique du transport »), c'est ici et nulle part ailleurs que vit la connaissance de
 * protocole liée au pays : la liste des pays proposables (paysDisponibles(), servie à la
 * page de configuration via smartclim::paysDisponiblesAuxHome()) et l'authentification
 * complète (getPubkey(), login/pwd, en-tête "country", correspondance auxhome_email ->
 * champ "account").
 */
class smartclimAuxHomeApi {
  /*     * *************************Attributs****************************** */

  // Hôte du cloud AUX Home (transport "AUX Home", cf. CLAUDE.md § Architecture / Transports).
  const HOST = 'https://eu-smthome-api.aux-global.com';

  // Timeouts cURL (secondes) — cf. .memory/specs/MVP/02-client-aux-home-authentification-tech.md
  // § 1.3. Un login = 2 requêtes séquentielles (getPubkey puis login/pwd) :
  // TIMEOUT_REQUETE borne la 1ère, BUDGET_LOGIN borne le TOTAL des deux (la 2e requête
  // reçoit le temps restant, cf. login() ci-dessous). Ne jamais se reposer sur le seul
  // timeout par requête pour tenir ce budget global : CURLOPT_TIMEOUT peut être
  // inopérant pendant getaddrinfo() selon le build de libcurl (absence d'AsynchDNS +
  // CURLOPT_NOSIGNAL), un DNS injoignable bloquerait alors bien au-delà.
  const TIMEOUT_CONNEXION = 5;
  const TIMEOUT_REQUETE = 10;
  const BUDGET_LOGIN = 18;

  // Budget de temps GLOBAL d'un scan de découverte (UC03, § 6.1 de la spec technique),
  // mesuré depuis l'entrée de listerAppareils() — login éventuel COMPRIS. Ce n'est pas
  // un timeout par requête : chaque requête reçoit une part de ce budget (cf.
  // listerAppareils() ci-dessous), et un rejeu après re-login n'est tenté que si le
  // budget restant permet encore un login complet.
  const BUDGET_SCAN = 25;

  // Cache (chiffré) de la session AUX Home — cf. § 1.5 de la spec technique.
  const CLE_CACHE_SESSION = 'smartclim::session_auxhome';
  const DUREE_CACHE_SESSION = 1800; // 30 minutes — pari documenté, à calibrer en UC08.

  // Plausibilité de la température ambiante décodée (octet[15] de status.running - 32
  // est mathématiquement borné à [-32, 223], cf. spec technique UC05 § Contrats
  // externes). Une trame à zéros (appareil éteint ?) donne -32 : hors de cette borne,
  // le concept est OMIS (jamais une valeur par défaut, jamais null poussé).
  const AMBIANTE_MIN_PLAUSIBLE = -20;
  const AMBIANTE_MAX_PLAUSIBLE = 60;

  // --- Constantes de protocole reverse-engineered depuis l'application mobile AUX
  // Home, publiées par GijsZwegers/com.zwegersit.auxairco (fichiers
  // lib/auxcloud/constants.ts et lib/auxcloud/client.ts, branche "main", licence MIT),
  // recoupées le 2026-08-25. Ce ne sont PAS des secrets utilisateur : sans elles, aucun
  // login n'est possible auprès du cloud AUX Home (cf. spec fonctionnelle UC02,
  // "Décisions actées"). Ne jamais les journaliser ni les exposer dans une réponse AJAX.

  // Jeton APPLICATIF (pas un jeton utilisateur) : sert de porteur par défaut de
  // l'en-tête "Authorization: bearer" tant qu'aucune session utilisateur n'existe
  // (getPubkey, login/pwd) — cf. client.ts, baseHeaders()/request(), qui retombe sur ce
  // jeton quand aucun "bearer" de session n'est fourni. Détail non explicité par la
  // spec technique (§ 0.3), mais indispensable : sans lui, getPubkey/login échouent.
  const STATIC_APP_TOKEN = 'MDczZTVlYzk2NTJjNGM2N2JjOWE1ZmI0YWU2NGRhMzZAZGUyMTRjNDZmOGY2NGZjMmEzNjQ1ODM5YmI1OTQyZjU=';

  // Clé AES-128-ECB fixe utilisée pour chiffrer le champ "account" (e-mail) du corps de
  // login. Texte ASCII brut de 16 caractères — utilisable directement comme clé AES-128
  // en PHP, sans décodage hex ni base64 (format confirmé le 2026-08-25).
  const ACCOUNT_AES_KEY = '4083aux63e3444a2';

  const AUX_USER_AGENT = 'AUXAC/2.3.2 (iPhone; iOS 18.6.2; Scale/3.00)';

  /*     * ***********************Methode static*************************** */

  /**
   * Pays proposables pour le compte AUX Home : code ISO-3166 alpha-3 => libellé traduit,
   * TRIÉ par libellé dans la langue affichée. Sert à peupler la liste déroulante de la
   * page de configuration — un code à 3 lettres saisi à la main est à la fois pénible et
   * facile à rater (« FR » pour « FRA »), et rien dans l'interface ne signalait l'erreur
   * avant l'échec du login.
   *
   * Couverture volontairement limitée à l'Europe : le transport AUX Home n'a qu'un point
   * d'entrée régional (self::HOST = eu-…), et proposer un code plausible mais non
   * confirmé ferait échouer le login sur un message backend trompeur. Les comptes hors de
   * cette liste restent servis par la saisie libre de la page de configuration ; ici, on
   * ne propose que ce dont le code est certain.
   *
   * ⚠️ Libellés en chaînes LITTÉRALES dans __() — jamais __($variable) : l'extraction
   * i18n est un scan statique (cf. CLAUDE.md § Internationalisation).
   *
   * @return array<string,string>
   */
  public static function paysDisponibles() {
    $pays = array(
      'ALB' => __('Albanie', __FILE__),
      'AND' => __('Andorre', __FILE__),
      'AUT' => __('Autriche', __FILE__),
      'BEL' => __('Belgique', __FILE__),
      'BGR' => __('Bulgarie', __FILE__),
      'BIH' => __('Bosnie-Herzégovine', __FILE__),
      'BLR' => __('Biélorussie', __FILE__),
      'CHE' => __('Suisse', __FILE__),
      'CYP' => __('Chypre', __FILE__),
      'CZE' => __('Tchéquie', __FILE__),
      'DEU' => __('Allemagne', __FILE__),
      'DNK' => __('Danemark', __FILE__),
      'ESP' => __('Espagne', __FILE__),
      'EST' => __('Estonie', __FILE__),
      'FIN' => __('Finlande', __FILE__),
      'FRA' => __('France', __FILE__),
      'FRO' => __('Îles Féroé', __FILE__),
      'GBR' => __('Royaume-Uni', __FILE__),
      'GIB' => __('Gibraltar', __FILE__),
      'GRC' => __('Grèce', __FILE__),
      'HRV' => __('Croatie', __FILE__),
      'HUN' => __('Hongrie', __FILE__),
      'IRL' => __('Irlande', __FILE__),
      'ISL' => __('Islande', __FILE__),
      'ITA' => __('Italie', __FILE__),
      'LIE' => __('Liechtenstein', __FILE__),
      'LTU' => __('Lituanie', __FILE__),
      'LUX' => __('Luxembourg', __FILE__),
      'LVA' => __('Lettonie', __FILE__),
      'MCO' => __('Monaco', __FILE__),
      'MDA' => __('Moldavie', __FILE__),
      'MKD' => __('Macédoine du Nord', __FILE__),
      'MLT' => __('Malte', __FILE__),
      'MNE' => __('Monténégro', __FILE__),
      'NLD' => __('Pays-Bas', __FILE__),
      'NOR' => __('Norvège', __FILE__),
      'POL' => __('Pologne', __FILE__),
      'PRT' => __('Portugal', __FILE__),
      'ROU' => __('Roumanie', __FILE__),
      'RUS' => __('Russie', __FILE__),
      'SMR' => __('Saint-Marin', __FILE__),
      'SRB' => __('Serbie', __FILE__),
      'SVK' => __('Slovaquie', __FILE__),
      'SVN' => __('Slovénie', __FILE__),
      'SWE' => __('Suède', __FILE__),
      'TUR' => __('Turquie', __FILE__),
      'UKR' => __('Ukraine', __FILE__),
      'VAT' => __('Vatican', __FILE__),
    );
    // Tri sur une clé désaccentuée : en comparaison octet à octet, les caractères
    // accentués UTF-8 sont > « z » et enverraient « Îles Féroé » (ou « Österreich » en
    // allemand) en fin de liste. Volontairement ni intl/Collator (extension non garantie
    // sur un Jeedom minimal), ni strcoll() (dépend d'un setlocale global qu'un plugin
    // n'a pas à modifier).
    uasort($pays, function ($a, $b) {
      return strcmp(smartclimAuxHomeApi::cleDeTri($a), smartclimAuxHomeApi::cleDeTri($b));
    });
    return $pays;
  }

  /**
   * Clé de tri ASCII d'un libellé de pays : diacritiques ramenés à leur lettre de base,
   * puis majuscules. Table explicite plutôt qu'iconv('//TRANSLIT'), dont le rendu varie
   * selon la libc (jusqu'à rendre un simple point d'interrogation), et plutôt que
   * les extensions mb_* ou intl, non garanties sur un Jeedom minimal : elle
   * couvre les diacritiques effectivement présents dans les quatre langues du plugin.
   *
   * @return string
   */
  private static function cleDeTri($_libelle) {
    $table = array(
      'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
      'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
      'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
      'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o',
      'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
      'æ' => 'ae', 'œ' => 'oe', 'ß' => 'ss',
      'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ã' => 'A', 'Å' => 'A',
      'Ç' => 'C', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
      'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ñ' => 'N',
      'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Õ' => 'O', 'Ø' => 'O',
      'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y',
      'Æ' => 'AE', 'Œ' => 'OE',
    );
    return strtoupper(strtr($_libelle, $table));
  }

  /**
   * Authentifie le compte AUX Home configuré, écrit la session en cache (chiffrée,
   * avec empreinte, § 1.5) puis la renvoie. Redemande TOUJOURS une clé publique
   * fraîche (jamais mise en cache, § 0.2) et rejoue le couple getPubkey/login/pwd —
   * jamais de réutilisation d'une session ou d'une clé mises en cache d'une tentative
   * précédente (AC6) : le cache n'est ici qu'en ÉCRITURE, jamais lu. session()
   * ci-dessous ne fait que LIRE ce cache, et retombe sur login() si absent/invalide.
   * ⚠️ Vérifie la non-vacuité du mot de passe EN LIGNE, avant tout appel réseau (piège
   * signalé pour UC03 : un appelant qui contournerait smartclim::compteConfigure(),
   * ex. un futur cron, ne doit pas déclencher une requête réseau avec un mot de passe
   * vide — contrat "zéro requête si compte non configuré" d'UC01).
   * ⚠️ Ne prend et ne lit AUCUN mot de passe en paramètre local nommé : chiffrerMotDePasse()
   * lit config::byKey('auxhome_password', ...) elle-même, au plus près de l'usage (cf.
   * § 3.1 de la spec technique — une trace PHP expose les arguments de chaque frame).
   *
   * @return array{jeton:string,uid:string,pseudo:string}
   * @throws smartclimException Toujours une exception "propre" : recréée juste avant
   *   propagation (catch ci-dessous) pour ne jamais laisser filtrer, via la frame de
   *   requete(), le corps de requête chiffré (finding sécurité LOW de la revue croisée).
   */
  public static function login() {
    try {
      if (config::byKey('auxhome_password', 'smartclim') == '') {
        throw new smartclimException('Mot de passe AUX Home vide en base', smartclimException::TYPE_AUTH);
      }

      $debut = microtime(true);
      $derBase64 = self::clePublique();
      $ecoule = microtime(true) - $debut;
      // CURLOPT_TIMEOUT attend un entier : arrondi au SUPÉRIEUR (jamais tronqué vers
      // le bas, ce qui grignoterait le temps réellement disponible pour la 2e requête).
      $tempsRestant = (int) ceil(max(3, self::BUDGET_LOGIN - $ecoule));

      $pem = self::derVersPem($derBase64);
      $motDePasseChiffre = self::chiffrerMotDePasse($pem);
      if ($motDePasseChiffre === false) {
        throw new smartclimException('Échec du chiffrement RSA du mot de passe', smartclimException::TYPE_INTERNE);
      }
      $compteChiffre = self::chiffrerCompte();
      if ($compteChiffre === false) {
        throw new smartclimException('Échec du chiffrement AES du compte', smartclimException::TYPE_INTERNE);
      }

      $corps = array(
        'password' => $motDePasseChiffre,
        'account' => $compteChiffre,
        // epoch millisecondes en chaîne : sprintf('%.0f', ...) plutôt que (string) round(...),
        // qui bascule en notation scientifique ("1.7869E+12") si precision < 13 dans le
        // php.ini de l'hôte — le login serait alors rejeté avec un message indistinguable
        // d'un mauvais mot de passe (minor de la revue croisée).
        'ts' => sprintf('%.0f', microtime(true) * 1000),
        'publicKeyBase64' => $derBase64,
      );

      $donnees = self::requete('POST', '/app/auth/login/pwd', $corps, $tempsRestant);
      // Cast partagé avec clePublique() via codeMetierVersInt() (jamais l'expression
      // inline dupliquée) : c'est justement ce que la factorisation en classerCodeMetier()
      // visait à empêcher de diverger (finding sécurité LOW de la revue croisée, 2e tour).
      $code = self::codeMetierVersInt($donnees);

      if ($code !== 200) {
        self::classerCodeMetier('login', $donnees, smartclimException::TYPE_AUTH);
      }

      $jeton = isset($donnees['data']['token']['token']) ? $donnees['data']['token']['token'] : '';
      if (!self::jetonConforme($jeton)) {
        throw new smartclimException('Jeton absent ou de format inattendu dans la réponse de login', smartclimException::TYPE_PROTOCOLE);
      }
      $uid = isset($donnees['data']['appUser']['uid']) ? (string) $donnees['data']['appUser']['uid'] : '';
      $pseudo = isset($donnees['data']['appUser']['nickName']) ? (string) $donnees['data']['appUser']['nickName'] : '';
      if ($uid === '') {
        // Pas d'échec du login pour autant (contrat non durci, décision du coordinateur
        // après revue croisée) : seul un avertissement, pour que sa cause ne se manifeste
        // pas très loin de son origine quand post-mvp/05 s'appuiera sur ce uid pour la
        // souscription MQTT dev2app/<uid>/# (§ 9 de la spec technique).
        log::add('smartclim', 'warning', 'AUX Home : connexion réussie mais uid absent de la réponse de login');
      }
      // Jeton tronqué à 6 caractères max en debug — jamais le jeton complet (§ 3.2).
      log::add('smartclim', 'debug', 'AUX Home : connexion réussie (jeton=' . substr($jeton, 0, 6) . '...)');

      cache::set(self::CLE_CACHE_SESSION, utils::encrypt(json_encode(array(
        'jeton' => $jeton,
        'uid' => $uid,
        'empreinte' => self::empreinteIdentifiants(),
      ))), self::DUREE_CACHE_SESSION);

      return array('jeton' => $jeton, 'uid' => $uid, 'pseudo' => $pseudo);
    } catch (smartclimException $e) {
      // Recrée l'exception À CE POINT D'APPEL : sa trace d'origine peut embarquer, via
      // la frame de requete(), le corps chiffré ($_corps) envoyé au login. La reconstruire
      // ici (frame de login(), qui ne prend aucun paramètre sensible) fait mourir cette
      // trace sur place, quel que soit l'appelant en amont (§ 3.1 ; finding sécu LOW).
      throw new smartclimException($e->getMessage(), $e->getType(), $e->getContexte());
    }
  }

  /**
   * Renvoie une session valide : celle en cache si elle correspond toujours aux
   * identifiants actuellement enregistrés (empreinte e-mail+pays) ET porte un jeton de
   * forme conforme, sinon retombe sur login() — qui, lui, réauthentifie ET écrit la
   * nouvelle session en cache (§ 1.5). Cette fonction ne fait donc plus qu'une chose :
   * LIRE le cache, jamais y écrire elle-même. Premier consommateur réel : UC03+.
   *
   * @return array{jeton:string,uid:string}
   * @throws smartclimException Toujours une exception "propre" (même motif que login()
   *   ci-dessus, § 3.1 / finding sécu LOW).
   */
  public static function session() {
    // Garde-fou explicite : sans elle, un appelant qui l'oublierait (ex. un futur cron
    // d'UC03) verrait login() échouer sur le mot de passe vide avec TYPE_AUTH -- message
    // correct -- mais SANS cette garde ici, un compte/pays vide (pas seulement le mot de
    // passe) échouerait plus loin dans la chiffrement AES/RSA avec TYPE_INTERNE, un
    // message "erreur interne" trompeur au lieu de "compte non configuré" (piège signalé
    // pour UC03 par la revue croisée).
    if (!smartclim::compteConfigure()) {
      throw new smartclimException('Compte AUX Home non configuré (email/mot de passe/pays)', smartclimException::TYPE_AUTH);
    }
    try {
      $brut = cache::byKey(self::CLE_CACHE_SESSION)->getValue(null);
      // getValue(null) renvoie null aussi bien pour une entrée ABSENTE que pour une
      // entrée EXPIRÉE : vérifié sur jeedom/core (cache.class.php, FileCache::fetch() et
      // MariadbCache::fetch()) — chaque moteur purge lui-même l'entrée expirée et renvoie
      // null à cache::byKey() AVANT même que celle-ci ne construise l'objet "cache" de
      // repli ; il n'existe donc aucun chemin où byKey() renverrait l'ANCIENNE valeur
      // d'une entrée expirée. Pas de test de fraîcheur supplémentaire nécessaire ici
      // (réponse au point ❓ du § 0.4 de la spec technique).
      if ($brut !== null) {
        $dechiffre = utils::decrypt($brut);
        if (is_string($dechiffre) && $dechiffre !== '') {
          $session = json_decode($dechiffre, true);
          if (
            is_array($session)
            && isset($session['jeton'], $session['uid'], $session['empreinte'])
            && self::jetonConforme($session['jeton'])
            && $session['empreinte'] === self::empreinteIdentifiants()
          ) {
            log::add('smartclim', 'debug', 'AUX Home : session en cache valide, réutilisée');
            return array('jeton' => $session['jeton'], 'uid' => $session['uid']);
          }
        }
        // Empreinte différente, jeton non conforme, ou entrée corrompue : les
        // identifiants ont changé par un chemin qui ne passe pas par config::save()
        // (restauration, écriture SQL, migration) — les hooks postConfig_* ne l'auraient
        // pas vu. On purge et on relogue. Seule instrumentation permettant à UC08 de
        // calibrer la durée réelle de vie du jeton (DUREE_CACHE_SESSION = 30 min, un pari
        // documenté § 9 de la spec technique) : jamais le jeton complet.
        log::add('smartclim', 'debug', 'AUX Home : session en cache invalide (empreinte ou jeton), purge et nouvelle connexion');
        self::purgerSession();
      } else {
        log::add('smartclim', 'debug', 'AUX Home : aucune session en cache, nouvelle connexion');
      }
      // Ne PAS renvoyer self::login() directement : login() renvoie 3 clés (dont
      // 'pseudo', inutile ici), alors que le contrat annoncé de session() est
      // array{jeton,uid} — SEULEMENT 2 clés, comme la branche cache ci-dessus. Un appel
      // programmatique à $session['pseudo'] fonctionnerait au premier essai (cache vide)
      // puis lèverait une erreur "undefined array key" pendant les 30 min suivantes dès
      // que le cache redevient valide — panne intermittente signalée pour UC03 par la
      // revue croisée.
      $frais = self::login();
      return array('jeton' => $frais['jeton'], 'uid' => $frais['uid']);
    } catch (smartclimException $e) {
      // Même motif que login() ci-dessus : recrée l'exception à ce point d'appel avant
      // de la laisser remonter, pour ne jamais dépendre de la discipline de l'appelant.
      throw new smartclimException($e->getMessage(), $e->getType(), $e->getContexte());
    }
  }

  /**
   * Liste les appareils du compte AUX Home configuré (UC03, § 2/5.1 de la spec
   * technique), normalisés en clés génériques françaises. Un tableau vide est un
   * SUCCÈS (compte sans appareil), jamais une exception.
   *
   * Budget de temps GLOBAL (BUDGET_SCAN = 25 s), login compris (§ 6.1) : chaque
   * requête reçoit max(3, min(TIMEOUT_REQUETE, BUDGET_SCAN - écoulé)). Un re-login
   * réactif + UN SEUL rejeu (anti-boucle par booléen local, jamais de récursion)
   * n'est tenté que si l'échec initial est TYPE_AUTH ET que le budget restant permet
   * encore un login complet (BUDGET_LOGIN + 3 s de marge).
   *
   * @return array<int, array{mac:string, identifiant:string, nom:string, modele:string, enLigne:bool}>
   * @throws smartclimException message TECHNIQUE, recréée sur place avant propagation
   *   (même motif que login()/session() : la trace de requete() porte le jeton).
   */
  public static function listerAppareils() {
    try {
      $debut = microtime(true);
      $session = self::session();
      $rejoue = false;
      while (true) {
        $ecoule = microtime(true) - $debut;
        $tempsRequete = (int) max(3, min(self::TIMEOUT_REQUETE, self::BUDGET_SCAN - $ecoule));
        try {
          $donnees = self::requeteAppareils($session['jeton'], $tempsRequete);
          break;
        } catch (smartclimException $e) {
          $budgetRestant = self::BUDGET_SCAN - (microtime(true) - $debut);
          if (!$rejoue && $e->getType() === smartclimException::TYPE_AUTH && $budgetRestant >= self::BUDGET_LOGIN + 3) {
            $rejoue = true;
            self::purgerSession();
            $session = self::login();
            continue;
          }
          throw $e;
        }
      }

      $appareils = array();
      foreach ($donnees['data'] as $element) {
        if (!is_array($element)) {
          log::add('smartclim', 'warning', 'AUX Home : élément de user_device ignoré (type inattendu)');
          continue;
        }
        $normalise = self::normaliserAppareil($element);
        if ($normalise !== null) {
          $appareils[] = $normalise;
        }
      }
      return $appareils;
    } catch (smartclimException $e) {
      // Recrée l'exception À CE POINT D'APPEL, même motif que login()/session()
      // ci-dessus (§ 3.1 de la spec technique UC02, réutilisée pour UC03).
      throw new smartclimException($e->getMessage(), $e->getType(), $e->getContexte());
    }
  }

  /**
   * Appelle GET /app/user_device?getStatus=1 (§ 2 de la spec technique UC03). La query
   * string est un LITTÉRAL constant (aucune entrée utilisateur dans l'URL) ; requete()
   * valide déjà jetonConforme($_jeton).
   *
   * @param string $_jeton Jeton de session UTILISATEUR (pas STATIC_APP_TOKEN).
   * @param int $_tempsRequete Timeout de cette requête, en secondes.
   * @return array Enveloppe décodée, avec 'data' garanti être un tableau.
   * @throws smartclimException classement délégué à classerCodeMetier('user_device', …, TYPE_AUTH).
   */
  private static function requeteAppareils($_jeton, $_tempsRequete) {
    $donnees = self::requete('GET', '/app/user_device?getStatus=1', null, $_tempsRequete, $_jeton);
    $code = self::codeMetierVersInt($donnees);
    if ($code !== 200) {
      self::classerCodeMetier('user_device', $donnees, smartclimException::TYPE_AUTH);
    }
    if (!isset($donnees['data']) || !is_array($donnees['data'])) {
      throw new smartclimException('AUX Home user_device : champ data absent ou non tableau', smartclimException::TYPE_PROTOCOLE);
    }
    return $donnees;
  }

  /**
   * Normalise un élément brut de la réponse user_device en tableau générique
   * français, ou null si le brut lui-même n'est pas exploitable (défensif ; le seul
   * appelant, listerAppareils(), a déjà écarté tout élément qui n'est pas un tableau).
   * ⚠️ Ne filtre PAS un appareil sans MAC ni identifiant exploitables : cette
   * décision ("ignore_identifiant") est portée par smartclim::scannerAuxHome() sur le
   * tableau normalisé (§ 6.4/§ 9 de la spec technique UC03 — le libellé "Ignoré —
   * aucun identifiant exploitable" doit rester visible à l'utilisateur dans le
   * résultat du scan, pas disparaître silencieusement ici). Seule classe du plugin qui
   * connaît les noms de champs AUX ("mac", "deviceId", "alias", "modelId", "online",
   * "status.control", "status.running") : elle n'en laisse aucun sortir. Enrichie pour
   * UC04 de 2 clés ADDITIVES (les 5 clés d'UC03 sont inchangées) : les trames HVAC
   * hexadécimales brutes, nettoyées, à destination EXCLUSIVE de capacitesAppareil()
   * ci-dessous — jamais journalisées ni persistées telles quelles (cf. spec technique
   * UC04 § Sécurité).
   *
   * @param array $_brut
   * @return array{mac:string, identifiant:string, nom:string, modele:string, enLigne:bool, trame_controle:string, trame_running:string}|null
   */
  private static function normaliserAppareil($_brut) {
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

    $identifiantBrut = isset($_brut['deviceId']) ? $_brut['deviceId'] : '';
    $identifiant = is_scalar($identifiantBrut) ? self::nettoyerTexteExterne($identifiantBrut, 100) : '';

    $nomBrut = isset($_brut['alias']) ? $_brut['alias'] : '';
    $nom = is_scalar($nomBrut) ? self::nettoyerTexteExterne($nomBrut, 127) : '';

    $modeleBrut = isset($_brut['modelId']) ? $_brut['modelId'] : '';
    $modele = is_scalar($modeleBrut) ? self::nettoyerTexteExterne($modeleBrut, 64) : '';

    $enLigneBrut = isset($_brut['online']) ? $_brut['online'] : null;
    $enLigne = in_array($enLigneBrut, array(true, 1, '1', 'true'), true);

    $statut = isset($_brut['status']) && is_array($_brut['status']) ? $_brut['status'] : array();
    $trameControle = isset($statut['control']) ? self::nettoyerTrame($statut['control']) : '';
    $trameRunning = isset($statut['running']) ? self::nettoyerTrame($statut['running']) : '';

    return array(
      'mac' => $mac,
      'identifiant' => $identifiant,
      'nom' => $nom,
      'modele' => $modele,
      'enLigne' => $enLigne,
      'trame_controle' => $trameControle,
      'trame_running' => $trameRunning,
    );
  }

  /**
   * Nettoie une trame HVAC hexadécimale brute (status.control / status.running) avant
   * tout usage (cf. spec technique UC04 § Architecture) : hex minuscule NU, ou '' si
   * inexploitable (non scalaire, non hexadécimal, longueur impaire, moins de 2
   * caractères). Frontière d'assainissement au même titre que nettoyerTexteExterne() :
   * hex minuscule uniquement, donc aucune injection de log possible.
   *
   * @param mixed $_valeur
   * @return string
   */
  private static function nettoyerTrame($_valeur) {
    if (!is_string($_valeur)) {
      return '';
    }
    $valeur = strtolower(trim($_valeur));
    if (strlen($valeur) < 2 || strlen($valeur) % 2 !== 0) {
      return '';
    }
    if (preg_match('/^[0-9a-f]+\z/', $valeur) !== 1) {
      return '';
    }
    return $valeur;
  }

  /**
   * Emplacement de CHAQUE concept dans les trames HVAC (cf. spec technique UC05 §
   * Contrats externes) : 'trame' ('control'|'running') + 'octets' (indices 0-based lus
   * pour ce concept). SOURCE UNIQUE de l'emplacement — offsetsAuxHome() ci-dessous en
   * DÉRIVE ses longueurs minimales, et etatAppareil() ci-dessous lit directement cette
   * table pour savoir dans quelle trame piocher.
   *
   * @return array<string, array{trame:string, octets:array<int,int>}>
   */
  private static function champsEtatAuxHome() {
    return array(
      smartclimCapabilities::CONCEPT_TARGET_TEMP => array('trame' => 'control', 'octets' => array(10, 12)),
      smartclimCapabilities::CONCEPT_FAN_SPEED => array('trame' => 'control', 'octets' => array(13)),
      smartclimCapabilities::CONCEPT_MODE => array('trame' => 'control', 'octets' => array(15)),
      smartclimCapabilities::CONCEPT_POWER => array('trame' => 'control', 'octets' => array(18)),
      smartclimCapabilities::CONCEPT_AMBIENT_TEMP => array('trame' => 'running', 'octets' => array(15)),
    );
  }

  /**
   * Longueur MINIMALE (en octets) de status.control / status.running requise par
   * concept, avant d'en tirer une correspondance générique (cf. spec technique UC04 §
   * Stratégie de détection) : offsets 0-based, donc une trame de longueur N couvre
   * l'octet d'indice N-1. INCHANGÉE dans sa signature ET sa forme de retour (consommée
   * telle quelle par capacitesAppareil()) : désormais DÉRIVÉE de champsEtatAuxHome(),
   * longueur minimale = max(octets) + 1. Contrôlé arithmétiquement (spec technique UC05,
   * R9) : 13/14/16/19 (control) et 16 (running), identiques aux anciens littéraux d'UC04.
   *
   * @return array{control:array<string,int>, running:array<string,int>}
   */
  private static function offsetsAuxHome() {
    $offsets = array('control' => array(), 'running' => array());
    foreach (self::champsEtatAuxHome() as $concept => $champ) {
      $offsets[$champ['trame']][$concept] = max($champ['octets']) + 1;
    }
    return $offsets;
  }

  /**
   * Octet d'indice $_index (0-based) d'une trame hexadécimale déjà nettoyée
   * (nettoyerTrame()), ou null si la trame est trop courte / non exploitable. Ne lève
   * jamais.
   *
   * @param string $_trame
   * @param int $_index
   * @return int|null
   */
  private static function octetTrame($_trame, $_index) {
    if (!is_string($_trame) || $_index < 0) {
      return null;
    }
    $hex = substr($_trame, $_index * 2, 2);
    if (strlen($hex) !== 2) {
      return null;
    }
    return hexdec($hex);
  }

  /**
   * État GÉNÉRIQUE de CET appareil, via CE transport (cf. spec technique UC05 §
   * Signatures) : décode les trames déjà rapportées par listerAppareils() (aucun appel
   * réseau nouveau). Ne lève JAMAIS (contrôles is_array/is_string), au même titre que
   * capacitesAppareil(). Un concept dont la valeur n'est PAS déterminable (trame trop
   * courte, code fil sans correspondance, température ambiante implausible) est ABSENT
   * du tableau renvoyé — jamais une valeur par défaut, jamais null poussé (mécanisme
   * d'AC10). Aucun nom de champ AUX, aucun code propriétaire ne sort d'ici : uniquement
   * des constantes smartclimCapabilities::CONCEPT_x/MODE_x/VITESSE_x, des entiers et des
   * flottants. Les trames ne sont NI journalisées NI persistées ; seuls les codes fil
   * (entiers) et les longueurs peuvent apparaître en 'debug'.
   *
   * ⚠️ status.control est documenté comme le "dernier état COMMANDÉ" (cf. spec
   * technique UC05, risque R1) : s'il ne reflète pas un changement fait à la
   * télécommande infrarouge, basculer un concept vers 'running' se fait en changeant
   * uniquement sa ligne dans champsEtatAuxHome() ci-dessus — à confirmer en recette.
   *
   * @param array $_appareil Ligne normalisée de listerAppareils()/normaliserAppareil().
   * @return array{online:bool, power?:int, mode?:string, target_temp?:float, ambient_temp?:int, fan_speed?:string, source:string}
   */
  public static function etatAppareil(array $_appareil) {
    $enLigne = isset($_appareil['enLigne']) ? (bool) $_appareil['enLigne'] : false;
    $trameControle = isset($_appareil['trame_controle']) && is_string($_appareil['trame_controle']) ? $_appareil['trame_controle'] : '';
    $trameRunning = isset($_appareil['trame_running']) && is_string($_appareil['trame_running']) ? $_appareil['trame_running'] : '';
    $offsets = self::offsetsAuxHome();
    $octetsControle = strlen($trameControle) / 2;
    $octetsRunning = strlen($trameRunning) / 2;

    $etat = array(
      'online' => $enLigne,
      'source' => smartclimCapabilities::TRANSPORT_AUX_HOME,
    );

    if ($octetsControle >= $offsets['control'][smartclimCapabilities::CONCEPT_POWER]) {
      $octet18 = self::octetTrame($trameControle, 18);
      if ($octet18 !== null) {
        $etat[smartclimCapabilities::CONCEPT_POWER] = ($octet18 >> 5) & 1;
      }
    }

    if ($octetsControle >= $offsets['control'][smartclimCapabilities::CONCEPT_MODE]) {
      $octet15 = self::octetTrame($trameControle, 15);
      if ($octet15 !== null) {
        $codeMode = $octet15 >> 5;
        $mode = smartclimCapabilities::depuisTransport(smartclimCapabilities::TRANSPORT_AUX_HOME, smartclimCapabilities::CONCEPT_MODE, $codeMode);
        if ($mode !== null) {
          $etat[smartclimCapabilities::CONCEPT_MODE] = $mode;
        } else {
          log::add('smartclim', 'debug', 'AUX Home : code mode inconnu dans status.control (' . $codeMode . ')');
        }
      }
    }

    if ($octetsControle >= $offsets['control'][smartclimCapabilities::CONCEPT_TARGET_TEMP]) {
      $octet10 = self::octetTrame($trameControle, 10);
      $octet12 = self::octetTrame($trameControle, 12);
      if ($octet10 !== null && $octet12 !== null) {
        // Cast explicite : sans lui, la consigne est un int aux degrés pleins et un
        // float aux demi-degrés. Le type doit rester STABLE d'un cycle à l'autre, sinon
        // l'aller-retour par le cache du core peut faire varier execCmd() et déclencher
        // un faux changement, donc un 'last_update' qui repart sans raison (AC6, R4).
        $temp = (float) (($octet10 >> 3) + 8);
        if (($octet12 & 0x80) !== 0) {
          $temp += 0.5;
        }
        $etat[smartclimCapabilities::CONCEPT_TARGET_TEMP] = $temp;
      }
    }

    if ($octetsControle >= $offsets['control'][smartclimCapabilities::CONCEPT_FAN_SPEED]) {
      $octet13 = self::octetTrame($trameControle, 13);
      if ($octet13 !== null) {
        $codeVitesse = $octet13 >> 5;
        $vitesse = smartclimCapabilities::depuisTransport(smartclimCapabilities::TRANSPORT_AUX_HOME, smartclimCapabilities::CONCEPT_FAN_SPEED, $codeVitesse);
        if ($vitesse !== null) {
          $etat[smartclimCapabilities::CONCEPT_FAN_SPEED] = $vitesse;
        } else {
          log::add('smartclim', 'debug', 'AUX Home : code vitesse inconnu dans status.control (' . $codeVitesse . ')');
        }
      }
    }

    if ($octetsRunning >= $offsets['running'][smartclimCapabilities::CONCEPT_AMBIENT_TEMP]) {
      $octet15Running = self::octetTrame($trameRunning, 15);
      if ($octet15Running !== null) {
        $ambiante = $octet15Running - 32;
        if ($ambiante >= self::AMBIANTE_MIN_PLAUSIBLE && $ambiante <= self::AMBIANTE_MAX_PLAUSIBLE) {
          $etat[smartclimCapabilities::CONCEPT_AMBIENT_TEMP] = $ambiante;
        } else {
          log::add('smartclim', 'debug', 'AUX Home : température ambiante implausible dans status.running (' . $ambiante . ')');
        }
      }
    }

    return $etat;
  }

  /**
   * Profil de capacités GÉNÉRIQUE de CET appareil, via CE transport (cf. spec technique
   * UC04 § Stratégie de détection). Ne lève JAMAIS, quel que soit le contenu de
   * $_appareil (contrôles is_array/is_string) : au pire renvoie concepts => array('online').
   * Aucun nom de champ AUX, aucun code propriétaire ne sort de cette méthode :
   * uniquement des codes génériques (constantes smartclimCapabilities::CONCEPT_x, MODE_x, VITESSE_x).
   *
   * @param array $_appareil Ligne normalisée de listerAppareils()/normaliserAppareil().
   * @return array{concepts:array<int,string>, modes:array<int,string>, vitesses:array<int,string>, temperature:array{min:int,max:int,pas:float}, source:string}
   */
  public static function capacitesAppareil(array $_appareil) {
    $trameControle = isset($_appareil['trame_controle']) && is_string($_appareil['trame_controle']) ? $_appareil['trame_controle'] : '';
    $trameRunning = isset($_appareil['trame_running']) && is_string($_appareil['trame_running']) ? $_appareil['trame_running'] : '';
    $octetsControle = strlen($trameControle) / 2;
    $octetsRunning = strlen($trameRunning) / 2;
    $offsets = self::offsetsAuxHome();

    $concepts = array(smartclimCapabilities::CONCEPT_ONLINE);
    if ($octetsControle >= $offsets['control'][smartclimCapabilities::CONCEPT_TARGET_TEMP]) {
      $concepts[] = smartclimCapabilities::CONCEPT_TARGET_TEMP;
    }
    if ($octetsControle >= $offsets['control'][smartclimCapabilities::CONCEPT_FAN_SPEED]) {
      $concepts[] = smartclimCapabilities::CONCEPT_FAN_SPEED;
    }
    if ($octetsControle >= $offsets['control'][smartclimCapabilities::CONCEPT_MODE]) {
      $concepts[] = smartclimCapabilities::CONCEPT_MODE;
    }
    if ($octetsControle >= $offsets['control'][smartclimCapabilities::CONCEPT_POWER]) {
      $concepts[] = smartclimCapabilities::CONCEPT_POWER;
    }
    if ($octetsRunning >= $offsets['running'][smartclimCapabilities::CONCEPT_AMBIENT_TEMP]) {
      $concepts[] = smartclimCapabilities::CONCEPT_AMBIENT_TEMP;
    }

    $modes = in_array(smartclimCapabilities::CONCEPT_MODE, $concepts, true)
      ? smartclimCapabilities::valeursLisibles(smartclimCapabilities::TRANSPORT_AUX_HOME, smartclimCapabilities::CONCEPT_MODE)
      : array();
    $vitesses = in_array(smartclimCapabilities::CONCEPT_FAN_SPEED, $concepts, true)
      ? smartclimCapabilities::valeursLisibles(smartclimCapabilities::TRANSPORT_AUX_HOME, smartclimCapabilities::CONCEPT_FAN_SPEED)
      : array();

    if ($octetsControle < $offsets['control'][smartclimCapabilities::CONCEPT_TARGET_TEMP] && $octetsRunning < $offsets['running'][smartclimCapabilities::CONCEPT_AMBIENT_TEMP]) {
      log::add('smartclim', 'debug', 'AUX Home : trames HVAC inexploitables pour la détection de capacités (control=' . $octetsControle . ' octets, running=' . $octetsRunning . ' octets)');
    }

    return array(
      'concepts' => $concepts,
      'modes' => $modes,
      'vitesses' => $vitesses,
      'temperature' => smartclimCapabilities::bornesParDefaut(),
      'source' => smartclimCapabilities::TRANSPORT_AUX_HOME,
    );
  }

  /**
   * Nettoie une chaîne d'origine externe (nom, modèle, identifiant) avant log, DOM ou
   * base (§ 5.1 de la spec technique UC03) : is_scalar → retrait des octets de
   * contrôle → repli sur un filtre imprimable UNIQUEMENT si le résultat n'est pas de
   * l'UTF-8 valide → trim → troncature PUIS retrait des octets de queue jusqu'à
   * redevenir de l'UTF-8 valide (une troncature brute pourrait couper au milieu d'un
   * caractère multi-octets). ⚠️ Volontairement SANS fonctions mb_* (non garanties sur
   * un Jeedom minimal — même arbitrage que cleDeTri()). Volontairement DISTINCTE de
   * journaliserErreurBackend() (qui ajoute la neutralisation base64, propre aux
   * messages backend d'erreur) : on ne refactore pas du code UC02 déjà livré.
   *
   * @param mixed $_valeur
   * @param int $_longueurMax
   * @return string
   */
  private static function nettoyerTexteExterne($_valeur, $_longueurMax) {
    if (!is_scalar($_valeur)) {
      return '';
    }
    $valeur = preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) $_valeur);
    if (preg_match('//u', $valeur) !== 1) {
      $valeur = preg_replace('/[^\x20-\x7E]/', ' ', $valeur);
    }
    $valeur = trim($valeur);
    $valeur = substr($valeur, 0, $_longueurMax);
    while ($valeur !== '' && preg_match('//u', $valeur) !== 1) {
      $valeur = substr($valeur, 0, -1);
    }
    return $valeur;
  }

  /**
   * Purge la session AUX Home en cache. Appelée explicitement par les hooks
   * postConfig_auxhome_* de smartclim (config::remove() ne déclenche pas postConfig_*,
   * § 0.4) et par smartclim::effacerIdentifiantsAuxHome().
   */
  public static function purgerSession() {
    cache::delete(self::CLE_CACHE_SESSION);
  }

  /**
   * Empreinte des identifiants actuellement enregistrés, utilisée par session() pour
   * détecter un changement qui ne serait pas passé par config::save() (§ 1.5). Ne
   * contient JAMAIS le mot de passe (cela le remettrait sur la pile du cache, § 1.5).
   *
   * @return string
   */
  private static function empreinteIdentifiants() {
    return sha1(smartclim::emailAuxHome() . '|' . smartclim::paysAuxHome());
  }

  /**
   * Valide la FORME d'un jeton avant de le renvoyer à l'appelant, de le mettre en
   * cache, ou de le relire du cache — jamais avant de le concaténer dans l'en-tête
   * "Authorization" d'une requête ultérieure. Seule valeur d'en-tête d'origine
   * EXTERNE (contrairement à "country", déjà filtré [A-Z]{3} des deux côtés de la
   * double barrière d'UC01) : sans ce filtre, un jeton porteur d'un CRLF injecterait
   * des en-têtes arbitraires dans toute requête authentifiée ultérieure, empoisonnés
   * jusqu'à 30 min via le cache (finding sécurité LOW de la revue croisée).
   *
   * ⚠️ Ancre de fin en `\z`, PAS `$` : en PCRE sans le modificateur `D`, `$` matche
   * aussi juste avant un `\n` FINAL — un jeton `"AAAAAAAA\n"` aurait alors passé la
   * validation, terminé en cache 30 min, puis concaténé dans l'en-tête aurait produit
   * `Authorization: bearer AAAAAAAA\n\r\n`, une ligne vide qui clôt prématurément le
   * bloc d'en-têtes pour un parseur permissif (finding sécurité LOW de la revue
   * croisée — l'injection d'un en-tête arbitraire était déjà bloquée, pas la
   * troncature du bloc).
   *
   * @param mixed $_jeton
   * @return bool
   */
  private static function jetonConforme($_jeton) {
    return is_string($_jeton) && preg_match('/^[A-Za-z0-9._~+\/=-]{8,4096}\z/', $_jeton) === 1;
  }

  /**
   * Récupère une clé publique RSA-1024 fraîche (DER base64 nu). Jamais mise en cache
   * (§ 0.2 : une clé réutilisée est rejetée par le backend cousin CN, comportement
   * supposé identique en EU).
   *
   * @return string DER base64, tel que renvoyé par le backend.
   * @throws smartclimException
   */
  private static function clePublique() {
    $donnees = self::requete('GET', '/app/auth/getPubkey', null, self::TIMEOUT_REQUETE);
    // Même cast que login(), via codeMetierVersInt() (finding sécurité LOW de la revue
    // croisée, 2e tour : les deux expressions inline restaient divergentes malgré la
    // factorisation de classerCodeMetier()).
    $code = self::codeMetierVersInt($donnees);

    if ($code !== 200) {
      // Sur cette requête initiale (contrairement au login), un code métier inconnu
      // reste PROTOCOLE, avec un contexte dédié : cet appel envoie déjà l'en-tête
      // "country", donc un pays invalide échoue ici, en premier — le message doit
      // inviter à le vérifier (§ 1.1).
      self::classerCodeMetier('getPubkey', $donnees, smartclimException::TYPE_PROTOCOLE, smartclimException::CONTEXTE_REQUETE_INITIALE);
    }

    $der = isset($donnees['data']) ? $donnees['data'] : '';
    if (!is_string($der) || $der === '') {
      throw new smartclimException('Clé publique absente de la réponse getPubkey', smartclimException::TYPE_PROTOCOLE);
    }
    return $der;
  }

  /**
   * Classe un code métier AUX Home non-200 et lève l'exception correspondante, après
   * journalisation (§ 1.1, étapes 5-6 : codes 9023/64033 -> TYPE_PROTOCOLE, sinon le
   * type par défaut de l'appelant). Factorisée : cet ordre impératif n'est écrit
   * qu'UNE fois pour les deux endpoints (getPubkey et login) — un piège signalé pour
   * UC03/UC08, qui l'auraient sinon dupliqué une 3e puis une 4e fois, avec un risque
   * de divergence silencieuse.
   *
   * @param string $_contexte 'getPubkey' ou 'login' (pour le log uniquement).
   * @param array $_donnees Enveloppe décodée renvoyée par requete().
   * @param int $_typeParDefaut Type à lever si le code n'est ni 9023 ni 64033.
   * @param string $_contexteException '' ou smartclimException::CONTEXTE_REQUETE_INITIALE.
   * @throws smartclimException Toujours (ne retourne jamais).
   */
  private static function classerCodeMetier($_contexte, $_donnees, $_typeParDefaut, $_contexteException = '') {
    self::journaliserErreurBackend($_contexte, $_donnees);
    $code = self::codeMetierVersInt($_donnees);
    if ($code === 9023 || $code === 64033) {
      throw new smartclimException('AUX Home ' . $_contexte . ' : code métier ' . $code, smartclimException::TYPE_PROTOCOLE);
    }
    throw new smartclimException('AUX Home ' . $_contexte . ' : code métier ' . $code, $_typeParDefaut, $_contexteException);
  }

  /**
   * Chiffre le mot de passe du compte AUX Home avec la clé publique fraîchement
   * obtenue (RSA/PKCS1, blocs de 117 octets concaténés, puis base64). Prend la clé
   * PUBLIQUE en paramètre, JAMAIS le mot de passe (§ 3.1, couche 1) : lit
   * config::byKey('auxhome_password', 'smartclim') elle-même, au plus près de l'usage.
   * Enveloppée dans un try/catch(Throwable) (§ 3.1, couche 2) : même si un fragment de
   * 117 octets du mot de passe en clair apparaît en argument de openssl_public_encrypt(),
   * une exception née ici est capturée et jetée sur place, jamais propagée avec sa
   * trace. 🚫 Ne journalise JAMAIS $t->getMessage() ni $t->getTraceAsString() : message
   * fixe + openssl_error_string() uniquement (la pile OpenSSL ne contient jamais de
   * données).
   * ⚠️ Journalise sur CHAQUE chemin `return false` (pas seulement dans le catch) :
   * openssl_public_encrypt() sur un PEM inexploitable renvoie false en émettant un
   * simple WARNING PHP, jamais une exception — le catch(Throwable) ne couvre pas ce
   * chemin, identifié par la revue croisée comme le plus probable au premier test
   * contre le vrai backend (finding MAJOR).
   * ⚠️ Vide la file d'erreurs OpenSSL EN TOUTE PREMIÈRE LIGNE, retour ignoré : cette
   * file est globale au PROCESSUS et PHP ne la remet jamais à zéro entre deux appels —
   * une erreur laissée par un OpenSSL sans rapport (utils::decrypt() du cache, poignée
   * TLS de cURL, un autre plugin dans la même requête) serait sinon concaténée dans le
   * log et attribuée à tort à CE chiffrement, précisément sur le chemin identifié
   * ci-dessus comme le plus probable au premier test réel (finding minor de la revue
   * croisée, dans le sens inverse du finding MAJOR déjà corrigé).
   *
   * @param string $_pem Clé publique au format PEM (cf. derVersPem()).
   * @return string|false Mot de passe chiffré, en base64 ; false si impossible.
   */
  private static function chiffrerMotDePasse($_pem) {
    try {
      self::purgerErreursOpenssl();
      $motDePasse = config::byKey('auxhome_password', 'smartclim');
      if (!is_string($motDePasse) || $motDePasse === '') {
        log::add('smartclim', 'error', 'Échec du chiffrement RSA du mot de passe : mot de passe vide en base');
        return false;
      }
      $chiffre = '';
      $longueur = strlen($motDePasse);
      for ($decalage = 0; $decalage < $longueur; $decalage += 117) {
        $fragment = substr($motDePasse, $decalage, 117);
        $sortie = '';
        if (!openssl_public_encrypt($fragment, $sortie, $_pem, OPENSSL_PKCS1_PADDING)) {
          log::add('smartclim', 'error', 'Échec du chiffrement RSA du mot de passe (OpenSSL) : ' . self::purgerErreursOpenssl());
          return false;
        }
        $chiffre .= $sortie;
      }
      return base64_encode($chiffre);
    } catch (Throwable $t) {
      // get_class($t) et fichier:ligne ne portent AUCUNE donnée applicative (confirmé
      // par la revue croisée sur le catch(Throwable) de smartclim.ajax.php) : sans eux,
      // une TypeError ici ne laissait qu'"aucune erreur OpenSSL rapportée" dans le log,
      // rendant l'incident invisible (finding, 2e tour). 🚫 Toujours SANS
      // $t->getMessage() ni $t->getTraceAsString() : c'est là que le mot de passe
      // pourrait apparaître (§ 3.1) — raison d'être de ce try/catch.
      log::add('smartclim', 'error', 'Échec du chiffrement RSA du mot de passe (OpenSSL, exception ' . get_class($t) . ' dans ' . basename($t->getFile()) . ':' . $t->getLine() . ') : ' . self::purgerErreursOpenssl());
      return false;
    }
  }

  /**
   * Chiffre l'e-mail du compte AUX Home (champ "account" du protocole) en AES-128-ECB
   * + PKCS7, avec la clé fixe ACCOUNT_AES_KEY, puis base64. Lit l'e-mail elle-même
   * (smartclim::emailAuxHome()), par la même précaution qu'au § 3.1 (même si l'e-mail
   * n'est pas le secret directement visé par cette règle). Journalise sur CHAQUE
   * chemin `return false`, et vide la file OpenSSL en tête de `try` (même motif que
   * chiffrerMotDePasse() ci-dessus).
   *
   * @return string|false E-mail chiffré, en base64 ; false si impossible.
   */
  private static function chiffrerCompte() {
    try {
      self::purgerErreursOpenssl();
      $email = smartclim::emailAuxHome();
      if ($email === '') {
        log::add('smartclim', 'error', 'Échec du chiffrement AES du compte : e-mail vide en base');
        return false;
      }
      $sortie = openssl_encrypt($email, 'aes-128-ecb', self::ACCOUNT_AES_KEY, OPENSSL_RAW_DATA);
      if ($sortie === false) {
        log::add('smartclim', 'error', 'Échec du chiffrement AES du compte (OpenSSL) : ' . self::purgerErreursOpenssl());
        return false;
      }
      return base64_encode($sortie);
    } catch (Throwable $t) {
      // Même motif que chiffrerMotDePasse() ci-dessus : get_class($t) et fichier:ligne
      // sont sans donnée applicative, jamais $t->getMessage()/getTraceAsString() (§ 3.1).
      log::add('smartclim', 'error', 'Échec du chiffrement AES du compte (OpenSSL, exception ' . get_class($t) . ' dans ' . basename($t->getFile()) . ':' . $t->getLine() . ') : ' . self::purgerErreursOpenssl());
      return false;
    }
  }

  /**
   * Vide entièrement la file d'erreurs OpenSSL et la renvoie concaténée.
   * openssl_error_string() ne dépile qu'UNE erreur par appel : sans cette boucle, les
   * erreurs restantes resteraient dans la file et seraient attribuées à un appel
   * OpenSSL ultérieur sans rapport (finding MAJOR de la revue croisée). La pile
   * OpenSSL ne contient jamais de données applicatives (mot de passe, clé) : rien à
   * filtrer avant journalisation.
   *
   * @return string
   */
  private static function purgerErreursOpenssl() {
    $messages = array();
    while (($erreur = openssl_error_string()) !== false) {
      $messages[] = $erreur;
    }
    return $messages ? implode(' | ', $messages) : 'aucune erreur OpenSSL rapportée';
  }

  /**
   * Reconstitue un PEM exploitable par openssl_public_encrypt() à partir du DER base64
   * nu renvoyé par getPubkey. Trimme la copie locale AVANT le découpage en lignes de
   * 64 caractères (un blanc en bordure décalerait tout le découpage, produisant un PEM
   * inexploitable — finding minor de la revue croisée) ⚠️ SANS jamais toucher la
   * variable $derBase64 de l'appelant, qui doit rester la valeur EXACTE renvoyée par
   * le backend pour repartir dans "publicKeyBase64" du corps de login (§ 0.2).
   *
   * @param string $_derBase64
   * @return string
   */
  private static function derVersPem($_derBase64) {
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(trim($_derBase64), 64, "\n") . "-----END PUBLIC KEY-----\n";
  }

  /**
   * Seul point cURL du plugin (CLAUDE.md : "centraliser les accès externes"). Impose
   * TLS vérifié, les en-têtes attendus par le backend (§ 0.1) et le budget de temps
   * (§ 1.3). 🚫 CURLOPT_VERBOSE / CURLOPT_STDERR / CURLOPT_DEBUGFUNCTION sont INTERDITS
   * (§ 3.2) : le mode verbose écrirait l'en-tête Authorization en clair sur stderr.
   *
   * Ordre de classement des erreurs (§ 1.1 de la spec technique, impératif) :
   * 1. erreur cURL -> TYPE_RESEAU ; 2. HTTP >= 500 ou 429 -> TYPE_RESEAU ; 3. corps
   * non-JSON / enveloppe absente -> TYPE_PROTOCOLE. Les étapes suivantes (code==200,
   * codes 9023/64033, cas par défaut) sont propres à chaque endpoint et traitées par
   * l'appelant, via classerCodeMetier() (clePublique()/login()).
   *
   * @param string $_methode 'GET' ou 'POST'.
   * @param string $_chemin Chemin de l'API, ex. '/app/auth/login/pwd'.
   * @param array|null $_corps Corps JSON (POST uniquement) ; null si GET.
   * @param int $_tempsRequete Timeout de CETTE requête, en secondes (§ 1.3).
   * @param string|null $_jeton Jeton "Authorization: bearer" ; STATIC_APP_TOKEN si null.
   * @return array Enveloppe décodée ('code', 'message', 'data').
   * @throws smartclimException TYPE_RESEAU ou TYPE_PROTOCOLE.
   */
  private static function requete($_methode, $_chemin, $_corps, $_tempsRequete, $_jeton = null) {
    // Tous les appelants ACTUELS sont sûrs (null, ou un jeton déjà passé par
    // jetonConforme() dans login()/session()) — mais UC03 passera un jeton en
    // paramètre pour les appels authentifiés (listDevices, etc.) : cette validation
    // rend l'invariant CONTRACTUEL plutôt que local aux seuls appelants d'aujourd'hui
    // (durcissement signalé pour UC03 par la revue croisée).
    if ($_jeton !== null && !self::jetonConforme($_jeton)) {
      throw new smartclimException('Jeton fourni à requete() non conforme', smartclimException::TYPE_PROTOCOLE);
    }
    $entetes = array(
      'Accept: */*',
      'Accept-Language: en-US',
      'aid: 1',
      'os: 2',
      'country: ' . smartclim::paysAuxHome(),
      'User-Agent: ' . self::AUX_USER_AGENT,
      'Authorization: bearer ' . (($_jeton !== null && $_jeton !== '') ? $_jeton : self::STATIC_APP_TOKEN),
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, self::HOST . $_chemin);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT_CONNEXION);
    curl_setopt($ch, CURLOPT_TIMEOUT, $_tempsRequete);
    // Sans effet tant que le handle n'est pas partagé (curl_share_init) : un handle est
    // créé et détruit à chaque appel de requete(), il n'y a donc pas de cache DNS entre
    // les 2 requêtes d'un même login. Laissé (inoffensif) ; UC03 ne doit pas s'y fier
    // pour un quelconque gain de latence (finding minor de la revue croisée).
    curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, self::BUDGET_LOGIN);
    curl_setopt($ch, CURLOPT_NOSIGNAL, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    if ($_methode === 'POST') {
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($_corps));
      $entetes[] = 'Content-Type: application/json';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $entetes);

    $reponse = curl_exec($ch);
    $erreurCurl = curl_error($ch);
    $codeHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $tempsTotal = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    $tempsDns = curl_getinfo($ch, CURLINFO_NAMELOOKUP_TIME);
    curl_close($ch);

    log::add('smartclim', 'debug', 'AUX Home ' . $_methode . ' ' . $_chemin . ' : http=' . $codeHttp . ' total=' . $tempsTotal . 's dns=' . $tempsDns . 's');

    if ($reponse === false || $erreurCurl !== '') {
      throw new smartclimException('Erreur cURL : ' . $erreurCurl, smartclimException::TYPE_RESEAU);
    }
    if ($codeHttp >= 500 || $codeHttp === 429) {
      throw new smartclimException('HTTP ' . $codeHttp, smartclimException::TYPE_RESEAU);
    }
    $donnees = json_decode($reponse, true);
    if (!is_array($donnees) || !isset($donnees['code'])) {
      throw new smartclimException('Enveloppe JSON invalide ou absente', smartclimException::TYPE_PROTOCOLE);
    }
    return $donnees;
  }

  /**
   * Cast UNIQUE du code métier backend en entier (0 si absent ou non scalaire) —
   * partagé par classerCodeMetier() (comparaison à 9023/64033) et
   * journaliserErreurBackend() (journalisation) : une seule implémentation, pour que
   * les deux ne puissent plus diverger (finding sécurité LOW de la revue croisée —
   * l'ancienne journaliserErreurBackend() journalisait le code métier BRUT, acceptant
   * n'importe quelle chaîne au lieu du même cast que classerCodeMetier() juste
   * au-dessus).
   *
   * @param array $_donnees Enveloppe décodée renvoyée par requete().
   * @return int
   */
  private static function codeMetierVersInt($_donnees) {
    $codeBrut = isset($_donnees['code']) ? $_donnees['code'] : 0;
    return is_scalar($codeBrut) ? (int) $codeBrut : 0;
  }

  /**
   * Journalise le code/message métier renvoyés par le backend (indispensable au
   * diagnostic, § 1.1) — jamais le corps de requête, le mot de passe, le champ
   * "account" chiffré ni un jeton complet (§ 3.2). `code` ET `message` sont des
   * valeurs intégralement contrôlées par le serveur DISTANT. `code` passe par
   * codeMetierVersInt() (jamais journalié brut). `message` est neutralisé en 4 ÉTAPES,
   * dans cet ORDRE IMPÉRATIF — corrigé après un 2e tour de revue croisée : la version
   * précédente attribuait à tort la protection anti-écho du champ "account" au simple
   * filtre ASCII imprimable, qui ne bloque RIEN puisque le base64 EST composé de
   * caractères ASCII imprimables ; seule la neutralisation base64 de l'étape 3 protège
   * réellement :
   * 1. filtre les seuls caractères de CONTRÔLE (`[\x00-\x1F\x7F]`) — ferme l'injection
   *    de log par un "\n" forgé, sans toucher au reste du texte ;
   * 2. vérifie la validité UTF-8 (`preg_match('//u', …) !== 1`) et, UNIQUEMENT si
   *    invalide, retombe sur un filtre ASCII imprimable — un message non-UTF8
   *    casserait le json_encode() de la visionneuse de logs Jeedom, mais appliquer ce
   *    filtre par défaut détruirait le diagnostic sur tout message backend non
   *    latin (les backends AUX répondent aussi en chinois, cf. spec § 8) ;
   * 3. neutralise les suites de 16 caractères base64 ou plus
   *    (`preg_replace('/[A-Za-z0-9+\/]{16,}={0,2}/', '[b64]', …)`) — c'est CETTE
   *    étape, et elle SEULE, qui borne un écho du champ "account" chiffré
   *    (AES-128-ECB, clé ACCOUNT_AES_KEY fixe et PUBLIQUE dans ce code source) :
   *    l'ECB chiffrant bloc par bloc, 24 caractères non neutralisés livreraient déjà
   *    les 16 premiers octets (l'e-mail) sans attendre une troncature — 120
   *    caractères en portent 5 blocs complets, la troncature seule ne protégeait
   *    donc rien ;
   * 4. tronque à 120 caractères (borne finale, après neutralisation, pas avant).
   *
   * @param string $_contexte 'getPubkey' ou 'login'.
   * @param array $_donnees Enveloppe décodée renvoyée par requete().
   */
  private static function journaliserErreurBackend($_contexte, $_donnees) {
    $code = self::codeMetierVersInt($_donnees);
    $message = (isset($_donnees['message']) && is_string($_donnees['message'])) ? $_donnees['message'] : '';
    // 1. Caractères de CONTRÔLE uniquement.
    $message = preg_replace('/[\x00-\x1F\x7F]/', ' ', $message);
    // 2. Garde UTF-8 : repli sur le filtre imprimable UNIQUEMENT si invalide.
    if (preg_match('//u', $message) !== 1) {
      $message = preg_replace('/[^\x20-\x7E]/', ' ', $message);
    }
    // 3. Neutralisation des suites base64 — la protection réelle contre un écho du
    //    champ "account" chiffré (cf. docblock ci-dessus).
    $message = preg_replace('/[A-Za-z0-9+\/]{16,}={0,2}/', '[b64]', $message);
    // 4. Troncature finale.
    $message = substr($message, 0, 120);
    log::add('smartclim', 'error', 'AUX Home (' . $_contexte . ') : code=' . $code . ' message=' . $message);
  }
}
