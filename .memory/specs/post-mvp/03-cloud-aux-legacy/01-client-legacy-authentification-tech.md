# Spec technique — UC01 du domaine post-mvp/03 : client AUX Cloud legacy, authentification multi-régions

> **Spec fonctionnelle** : `01-client-legacy-authentification.md` (même dossier) · **Écrite le** 2026-09-08
> **Dépend de** : UC02 du MVP (patron d'authentification, cache de session, messages d'erreur curatés)

## 0. Périmètre et non-objectifs

Cette UC livre la **quatrième brique de transport** du plugin — `smartclimAuxCloudApi` — et uniquement sa
couche d'**authentification** : configuration plugin dédiée, table des régions, `login()`, cache de session
chiffré, et un bouton « Tester la connexion » propre à ce compte.

**Non-objectifs explicites** (chacun a son UC) : découverte des familles/pièces/appareils (UC02 de ce
domaine), lecture et écriture d'état (UC03), WebSocket relay (domaine 05), arbitrage entre transports
(domaine 02). Aucune commande Jeedom, aucun `eqLogic`, aucun `cron` n'est touché.

**Invariant de non-régression** : le socle MVP AUX Home n'est pas modifié. `compteConfigure()`, `cron()`,
`rafraichirAuxHome()`, `scannerClimatiseurs()`, `messageErreurAuxHome()` et les mémoires de cache
existantes restent **intactes**. C'est ce qui tient l'AC8.

## 1. Contrats externes

Sources : `maeek/ha-aux-cloud` (MIT, `custom_components/aux_cloud/api/aux_cloud.py`) et
`GijsZwegers/com.zwegersit.auxairco` (MIT, `lib/auxcloud/legacyClient.ts` + `legacyConstants.ts`), recoupées
ligne à ligne. Ce qui suit est **vérifié dans du code source lu**, sauf mention contraire.

### 1.1 Hôtes par région

```
EU   https://app-service-deu-f0e9ebbb.smarthomecs.de
USA  https://app-service-usa-fd7cc04c.smarthomecs.com
CHN  https://app-service-chn-31a93883.ibroadlink.com
RUS  https://app-service-rus-b8bbc3be.smarthomecs.com
```

⚠️ **RUS est à source unique** (`maeek` seul ; `LEGACY_REGION_URLs` du client TypeScript ne porte que
EU/USA/CN). Cohérent avec le § 1 de `.memory/analyse/smartclim-transport-aux-cloud-legacy.md`. L'hôte
répond en TLS (§ 1.4) mais son acceptation d'un login n'est établie par aucune source — cf. R6.

### 1.2 Route et corps de requête

`POST <hôte>/account/login`

```
sha_password = sha1(password + PASSWORD_ENCRYPT_KEY)               -> hex minuscule
payload      = {"email":…, "password":sha_password,
                "companyid":COMPANY_ID, "lid":LICENSE_ID}
json_payload = JSON COMPACT (separators=(",", ":") cote Python)
token        = md5(json_payload + BODY_ENCRYPT_KEY)                -> hex, en-tete "token"
aes_key      = md5(<horodatage> + TIMESTAMP_TOKEN_ENCRYPT_KEY)     -> 16 octets BRUTS
corps HTTP   = AES-128-CBC(IV = AES_INITIAL_VECTOR, aes_key, json_payload), ZERO padding
```

En-têtes : `Content-Type: application/x-java-serialized-object`, `timestamp`, `token`, `licenseId`, `lid`,
`language: en`, `appVersion`, `User-Agent`, `system`, `appPlatform`. `maeek` ajoute toujours `loginsession`
et `userid` (vides au login), le client TS les omet → **on ne les émet que non vides**.

### 1.3 Réponse — deux pièges de copier-coller

La réponse est du **JSON en clair** : seul le *corps de requête* est chiffré, il n'y a **rien à déchiffrer
en retour** (`_make_request` fait `response.text()` puis `json.loads`).

⚠️⚠️ **La sentinelle de succès est `status == 0`, PAS `code == 200`.** AUX Home utilise `code == 200` ; un
`!== 200` recopié depuis `smartclimAuxHomeApi` ferait échouer **tous** les logins, et l'échec serait
classé `TYPE_AUTH` — donc affiché « vérifiez vos identifiants » sur des identifiants parfaitement valides.
C'est le défaut le plus coûteux que cette UC puisse produire.

⚠️ `loginsession` et `userid` sont **au premier niveau** de l'objet, pas sous une clé `data`.

### 1.4 TLS — le « À confirmer » n° 2 de la spec fonctionnelle est FERMÉ, favorablement

Sondé le **2026-09-08** avec vérification **activée** (`curl` sans `-k`) sur les 4 hôtes : les quatre
répondent `ssl_verify_result=0` (HTTP 404 attendu, `/account/login` étant en POST seul). Chaîne **et** nom
d'hôte vérifiés. Certificats : `*.smarthomecs.de` par *Amazon RSA 2048 M04* (expire 2026-11-10),
`*.smarthomecs.com` par *RapidSSL TLS RSA CA G1* / DigiCert (expire 2026-11-14).

**Conséquence** : la règle « TLS toujours vérifié » du `brief.md` § 16 n'a **aucun coût fonctionnel** ici.
Les trois implémentations de référence désactivent la vérification **sans nécessité**. À reporter dans
`.memory/analyse/smartclim-transport-aux-cloud-legacy.md` § 1 et § 8.

### 1.5 Constantes de protocole

`PASSWORD_ENCRYPT_KEY` (ASCII 11), `BODY_ENCRYPT_KEY` (ASCII 16), `TIMESTAMP_TOKEN_ENCRYPT_KEY` (ASCII 16),
`AES_INITIAL_VECTOR` (16 octets), `LICENSE_ID` (hex 32), `COMPANY_ID` (hex 32), `SPOOF_APP_VERSION`,
`SPOOF_USER_AGENT`, `SPOOF_SYSTEM`, `SPOOF_APP_PLATFORM`.

À recopier **verbatim** depuis `legacyConstants.ts` (MIT) avec bloc d'attribution en commentaire — même
traitement que `smartclimAuxHomeApi::STATIC_APP_TOKEN` / `ACCOUNT_AES_KEY`. `LEGACY_LICENSE` (base64 ~172)
sert à `sdkcontrol` : **ne pas l'embarquer**, elle relève de l'UC03.

## 2. Le format de l'horodatage graine — « À confirmer » n° 1 FERMÉ : le format est LIBRE

C'est le point le plus important de cette spec, parce qu'une erreur ici produit un échec **silencieux et
indistinguable d'un mauvais mot de passe** (clé AES différente → corps indéchiffrable → `status != 0`).

Les deux références divergent, et **les deux fonctionnent** :

| Référence | Code | Chaîne produite |
|---|---|---|
| `maeek/aux_cloud.py` | `time.time()`, puis `f"{current_time}"` en en-tête **et** dans la graine | `str(float)` Python 3 : jusqu'à 7 décimales, nombre variable |
| `GijsZwegers/legacyClient.ts` | `(Date.now() / 1000).toString()` | au plus 3 décimales, et **aucun point** quand `ms % 1000 == 0` |

Les deux formats sont mutuellement incompatibles (l'un peut n'avoir aucun séparateur décimal, l'autre en a
toujours un). Puisque les deux clients s'authentifient, **le backend réutilise la chaîne de l'en-tête
`timestamp` telle quelle comme graine MD5 et ne la reparse jamais en nombre.**

> **L'invariant n'est donc PAS le format : c'est l'identité octet à octet entre la valeur de l'en-tête
> `timestamp` et la graine du MD5.** → calculer la chaîne **une seule fois**, la mettre en variable, et
> l'utiliser aux deux endroits. Toute future retouche doit préserver cette propriété, pas un format.

### 2.1 Reproduction PHP — sans aucune arithmétique flottante

Format retenu : **3 décimales** (celui du client TS, en production dans une application Homey publiée).

```php
$morceaux = explode(' ', microtime());   // "0.45645700 1757339027" -> [fraction, secondes]
// horodatage = <secondes> . '.' . <3 premiers chiffres de la fraction>
```

Garde-fou : valider `$morceaux[1]` par `/\A[0-9]{1,12}\z/`, extraire la fraction par `/\A0[.,]([0-9]{3})/`
avec repli `'000'`. On **tolère** une virgule en entrée, on n'en **émet jamais** une en sortie.

**Trois pièges PHP que ce choix évite**, chacun silencieux :

| À ne pas écrire | Pourquoi c'est faux |
|---|---|
| `(string) microtime(true)` | dépend de `precision` du `php.ini` (notation scientifique possible) et, **avant PHP 8.0**, de `LC_NUMERIC` (virgule décimale) — Jeedom tourne encore sur des PHP 7.x |
| `sprintf('%.3f', …)` | `%f` est documenté **locale aware** en PHP (`%F` ne l'est pas) → virgule possible. Le `sprintf('%.0f', …)` existant dans `smartclimAuxHomeApi::login()` n'est sûr que parce qu'il n'émet **aucun** séparateur |
| `(int) (microtime(true) * 1000)` | l'epoch en millisecondes (~1,75 × 10¹²) **dépasse un entier 32 bits**, et le plugin cible Raspberry Pi OS armhf (même famille de piège que la note `ip2long()` de `CLAUDE.md`) |

## 3. Chiffrement du corps — pièges PHP

- **Zero padding, jamais PKCS7** : `$rembourrage = (16 - (strlen($json) % 16)) % 16;` → **aucun octet
  ajouté si la longueur est déjà multiple de 16** (`cipher.setAutoPadding(false)` côté TS,
  `data + b"\0" * pad` côté Python).
- `openssl_encrypt($donnees, 'aes-128-cbc', $cle, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv)`.
  ⚠️ En PHP, `OPENSSL_ZERO_PADDING` signifie **« ne pas ajouter de remplissage »**, pas « remplir de
  zéros ». Sans ce drapeau PHP ajoute un PKCS7 (jusqu'à un bloc entier de `0x10`) → corps indéchiffrable
  par le backend. Avec ce drapeau et une entrée non alignée, `openssl_encrypt()` renvoie `false` — d'où le
  rembourrage explicite **avant** l'appel.
- Clé : `md5($horodatage . TIMESTAMP_TOKEN_ENCRYPT_KEY, true)` (2ᵉ argument `true` = 16 octets bruts).
- IV : constante **hexadécimale** + `hex2bin()` à l'usage (greppable, aucun piège d'échappement `"\x…"`).
- `json_encode()` produit déjà du JSON compact. Ajouter `JSON_UNESCAPED_SLASHES` par **parité d'octets**
  avec les références : le backend recalcule le MD5 sur ce qu'il déchiffre, donc l'ordre des clés nous est
  indifférent, mais la parité coûte zéro et retire une variable du diagnostic au premier essai réel.

## 4. Architecture — fichiers

| Fichier | Action | Contenu | Indentation / EOL |
|---|---|---|---|
| `core/class/smartclimAuxCloudApi.class.php` | **créé** | Toute la brique : constantes de protocole, table des régions, `login()`, `session()`, `purgerSession()`, crypto, cURL, classement d'erreurs | **2 espaces**, CRLF |
| `core/php/smartclim.inc.php` | modifié | `require_once` de la nouvelle classe, **après** la ligne `smartclimAuxHomeApi` ; et **corriger le commentaire de la ligne 44** (« La classe a venir (smartclimAuxCloudApi) viendra s'ajouter a cette liste. ») devenu faux | 2 espaces, CRLF |
| `core/class/smartclim.class.php` | modifié | `REGION_DEFAUT`, `$_encryptConfigKey` (+1 entrée), 4 accesseurs, `testerConnexionAuxCloud()`, `messageErreurAuxCloud()`, `normaliserRegion()`, 2 `preConfig_` + 3 `postConfig_` | 2 espaces, CRLF |
| `core/ajax/smartclim.ajax.php` | modifié | **une seule** branche `if (init('action') == 'testerConnexionAuxCloud')`. `session_write_close()` est déjà en ligne 36, juste après `ajax::init()` → **rien à ajouter** | ⚠️ **4 espaces**, CRLF |
| `plugin_info/configuration.txt` | modifié | Nouveau `<fieldset>`, préparation serveur de la liste des régions, 1 handler JS. ⚠️ **Puis `cp plugin_info/configuration.txt plugin_info/configuration.php`**, vérifié par `git status --short plugin_info/configuration.php` (le `.php` est illisible) | 2 espaces, CRLF |
| `core/config/smartclim.config.ini` | modifié | `auxcloud_region = EU` + commentaire de synchronisation triple. **Aucun défaut** pour `auxcloud_email` / `auxcloud_password` | 2 espaces, CRLF |

**Sans objet, explicitement** : `packages.json` (aucune dépendance nouvelle — `curl` et `openssl` sont déjà
les prérequis d'AUX Home) · `info.json` · tous les `.htaccess` (aucun nouveau point d'entrée web hors
`core/ajax`, qui n'en a pas et ne doit pas en avoir) · `smartclimCapabilities` (aucun code de transport,
aucune table de modes/vitesses : c'est l'UC03) · `smartclimTransport` (domaine 02) · `smartclimFrame`,
`smartclimDiagnostic`, `smartclimDemon` · `desktop/js/smartclim.js` (**vérifié** : ce fichier ne porte
aucune référence à la page de configuration — le JS du formulaire vit dans `configuration.txt`) ·
`desktop/php/smartclim.php` · aucune CLI · `docs/`.

### 4.1 Décision : la brique porte son propre cURL

`CLAUDE.md` décrit `smartclimAuxHomeApi` comme « seul point cURL du plugin ». C'est un **constat**, pas une
règle : la règle est « tous les appels HTTP passent par la brique du transport concerné ». Les deux clouds
ne partagent presque rien au niveau HTTP (enveloppe JSON + `Authorization: bearer` contre corps binaire
chiffré + 9 en-têtes d'usurpation + sentinelle `status`) — un helper commun serait un sac de paramètres.
C'est la structure déjà retenue pour l'UDP (`smartclimBroadlinkLan`) et le socket du démon
(`smartclimDemon`). → **la phrase de `CLAUDE.md` est à amender** en fin de cycle.

## 5. Server vs Client

Tout le protocole, toute la crypto, toute la table des régions et tout le classement d'erreurs sont
**côté serveur**. Le navigateur n'envoie **jamais** d'hôte, d'URL, de chemin ni de code de région libre :
la seule chose qu'il émet est `action=testerConnexionAuxCloud`, sans paramètre. L'hôte est **dérivé** d'une
clé de config déjà normalisée contre une table serveur — **aucune entrée utilisateur n'atteint jamais
`CURLOPT_URL`**, même doctrine anti-SSRF que `smartclimAuxHomeApi::routesDiagnostic()`.

Le client ne porte que : le rendu du `<select>` (options produites côté serveur), un handler de clic, et
l'affichage du message rendu par le serveur. **Aucune chaîne d'erreur métier n'est construite en JS.**

## 6. Signatures

### 6.1 `smartclimAuxCloudApi` (nouvelle classe — aucun `eqLogic`, aucune commande, aucun socket)

Constantes : `TIMEOUT_CONNEXION = 5`, `TIMEOUT_REQUETE = 10`, `BUDGET_LOGIN = 12`,
`CLE_CACHE_SESSION = 'smartclim::session_auxcloud'`, `DUREE_CACHE_SESSION = 1800`,
`CONTEXTE_CERTIFICAT = 'certificat'`, `CONTEXTE_MAGASIN_LOCAL = 'magasin_local'`, plus les constantes de
protocole du § 1.5.

| Méthode | Rôle | Lève |
|---|---|---|
| `private static regions()` | Table **unique** `code => ['hote' => string, 'libelle' => __('…', __FILE__)]` pour `EU`, `USA`, `CHN`, `RUS`. « Ajouter une région = ajouter une entrée. » **Ordre fixe, Europe d'abord** — pas de `uasort` : 4 entrées, et un tri alphabétique exigerait la désaccentuation (`États-Unis`) pour zéro bénéfice | — |
| `public static regionsDisponibles()` | `code => libellé traduit`. Consommée via `smartclim::regionsDisponiblesAuxCloud()` | — |
| `public static regionConnue($_code)` | `bool`, prédicat pur d'appartenance à la table — **c'est la liste blanche** ; il n'y a pas de regex de région | — |
| `private static hoteRegion($_code)` | URL de base, **entièrement issue de la table serveur** | `TYPE_INTERNE` si inconnu |
| `public static login($_budget = self::BUDGET_LOGIN)` | Authentifie, valide, **écrit** le cache, renvoie `array{loginsession, userid, cree_le}`. Garde en ligne : mot de passe vide → `TYPE_AUTH` **avant tout appel réseau**. ⚠️ Le `catch` final **recrée** l'exception à ce point d'appel : la trace des frames internes porte le JSON contenant le sha1 du mot de passe et le corps chiffré | `TYPE_*` |
| `public static session($_budgetLogin = BUDGET_LOGIN)` | **Lit** le cache ; entrée valide (déchiffrable, JSON, formes conformes, empreinte identique) → réutilise, sinon purge + `login()`. Garde d'entrée `smartclim::compteAuxCloudConfigure()` → `TYPE_AUTH` « compte non configuré » (sinon l'échec surviendrait plus loin en `TYPE_INTERNE`, message trompeur — piège déjà payé sur AUX Home). Renvoie **exactement les 3 mêmes clés dans les deux branches** | `TYPE_*` |
| `public static purgerSession()` | `cache::delete(CLE_CACHE_SESSION)` | — |
| `private static empreinteIdentifiants()` | `sha1(smartclim::emailAuxCloud() . '\|' . smartclim::regionAuxCloud())`. 🚫 **jamais le mot de passe** (le remettrait sur la pile d'appel). ⚠️ La **région en fait partie** : mêmes identifiants sur une autre région = autre hôte, donc autre session | — |
| `private static horodatageGraine()` | La chaîne du § 2, calculée **une fois** et rendue à l'appelant, qui la place dans l'en-tête **et** dans la graine MD5 | — |
| `private static empreinteMotDePasse()` | `sha1(config::byKey('auxcloud_password','smartclim') . PASSWORD_ENCRYPT_KEY)`. ⚠️ **Sans paramètre** : elle lit la config elle-même, au plus près de l'usage (doctrine `chiffrerMotDePasse()`). Sa sortie est un **équivalent de mot de passe** — jamais journalisée, jamais rendue hors du corps chiffré | `TYPE_AUTH` si vide |
| `private static chiffrerCorps($_json, $_horodatage)` | Zero padding + `openssl_encrypt` (§ 3). Purge la file OpenSSL en 1ʳᵉ ligne, journalise sur **chaque** chemin d'échec, `try/catch (Throwable)` **sans** `getMessage()` ni `getTraceAsString()` | `TYPE_INTERNE` |
| `private static requete($_chemin, $_json, $_horodatage, $_token, $_tempsRequete, $_session = null)` | cURL → enveloppe JSON décodée, classement du § 7. 🚫 `CURLOPT_VERBOSE` / `STDERR` / `DEBUGFUNCTION` **interdits** | `TYPE_RESEAU`, `TYPE_PROTOCOLE` |
| `private static codesCertificat()` | Table des `curl_errno()` **univoquement** liés à la validation d'un certificat (§ 7). ⚠️ **Littéraux entiers commentés, jamais de constantes `CURLE_*`** : 83/90/91 ne sont pas définies par tous les builds PHP, et un nom de constante inexistant devient en PHP la **chaîne littérale du nom** — le test serait faux **sans aucune erreur** | — |
| `private static valeurEnteteConforme($_valeur, $_max)` | `/\A[\x21-\x7E]{1,<max>}\z/`. Valide `loginsession` **et** `userid` avant mise en cache et avant tout usage en en-tête. ⚠️ Ancre **`\z`, jamais `$`** : sans le modificateur `D`, `$` accepte un `\n` final → injection/troncature du bloc d'en-têtes (finding réel déjà corrigé sur `jetonConforme()`, `smartclimAuxHomeApi.class.php:1165`). Classe volontairement **plus large** que `jetonConforme()` — cf. R2 | — |
| `private static journaliserErreurLegacy($_donnees)` | `status` (casté `int`) + un éventuel `message`/`msg` neutralisé, dans l'**ordre impératif** de `journaliserErreurBackend()` : contrôles → garde UTF-8 → neutralisation base64 `{16,}` → **neutralisation des suites hexadécimales `{32,}`** → troncature 120. 🚫 **jamais** `json_encode($donnees)` (ce que font les trois références) | — |

⚠️ **La neutralisation hexadécimale est nouvelle et reste CONFINÉE ici** — ne **jamais** la porter dans
`smartclimDiagnostic::masquerParRessemblance()`, où les suites hexadécimales **sont** la donnée utile
(trames HVAC).

⚠️⚠️ **Correction apportée en fin de cycle — la justification initiale de cette spec était fausse, et le
sens de l'erreur importe.** Elle présentait ce 4ᵉ filtre comme *la* protection du sha1 du mot de passe
(40 caractères hex, si le backend le renvoyait dans un message d'erreur). C'est inexact : **le charset
hexadécimal est un sous-ensemble de `[A-Za-z0-9+/]`**, donc toute suite de 32 caractères hex est **déjà**
neutralisée par la passe base64 (étape 3, seuil 16), qui s'exécute **avant**. Le sha1 est donc bien masqué
— mais par l'étape 3, et le filtre hexadécimal est **redondant en pratique**. Il est conservé (coût nul,
intention explicite), et le code porte cette précision.

> **Corollaire à ne pas perdre** : ne jamais retirer ni relâcher la passe **base64** en croyant le sha1
> couvert par la passe hexadécimale. C'est l'inversion exacte que la formulation initiale invitait à faire.
> Leçon générale : une passe de masquage « supplémentaire » n'est une protection que si l'on a vérifié
> qu'elle est la **première** à matcher — deux filtres dont les charsets s'emboîtent ne sont pas deux
> couches de défense.

### 6.2 `smartclim` — ajouts seuls, aucune signature existante modifiée

```php
const REGION_DEFAUT = 'EU';   // 3-way sync : cette constante, une cle de
                              // smartclimAuxCloudApi::regions(), et l'INI
public static $_encryptConfigKey = array('auxhome_password', 'auxcloud_password');
```

| Méthode | Rôle | Lève |
|---|---|---|
| `public static emailAuxCloud()` | `normaliserEmail(config::byKey('auxcloud_email', 'smartclim'))` — **réutilise** le normaliseur existant (mêmes pièges déjà traités : caractères de contrôle, U+00A0, traitement en octets) | — |
| `public static regionAuxCloud()` | `normaliserRegion(…)` sinon `REGION_DEFAUT`. **Jamais vide** | — |
| `public static regionsDisponiblesAuxCloud()` | Délégation vers le transport — ni la page ni le reste du plugin ne parlent directement à une brique | — |
| `public static compteAuxCloudConfigure()` | e-mail ≠ `''` **et** mot de passe ≠ `''` **et** région ≠ `''`. ⚠️ **Méthode NOUVELLE** : `compteConfigure()` reste le garde-fou d'AUX Home et n'est **pas** touchée (elle est appelée par `session()`, `cron()`, `sonderDiagnostic()`, `scannerAuxHome()`) | — |
| `public static testerConnexionAuxCloud()` | Deux gardes séparées (identifiants / région) pour deux messages distincts, puis **`login()`** — jamais `session()`, un test doit prouver les identifiants, pas relire un cache. Log `error` du message technique, `throw` du message curaté | `smartclimException` curatée |
| `private static messageErreurAuxCloud($_type, $_contexte)` | **Seul** endroit du plugin où vivent les `__()` d'erreur de ce transport. Fonction **séparée**, jamais fusionnée avec `messageErreurAuxHome()` / `messageErreurLan()` — doctrine déjà en place : deux transports, deux vocabulaires d'erreur | — |
| `private static normaliserRegion($_valeur)` | Règle **unique** écriture/lecture : `is_scalar` → `trim` → `strtoupper` → retrait de `[^A-Z]` → `regionConnue()` ? code : `''`. ⚠️ Enveloppée d'un `try/catch (Throwable)` (log + `''`) : elle traverse une **autre classe**, et une classe absente après mise à jour partielle est un incident **déjà observé** sur ce plugin ; un jet dans `preConfig_*` ferait perdre les clés suivantes de la même sauvegarde | jamais |
| `public static preConfig_auxcloud_region($v)` | `normaliserRegion($v)` sinon `REGION_DEFAUT`. **Aucun throw** | jamais |
| `public static preConfig_auxcloud_email($v)` | Délégation à `normaliserEmail()`, par symétrie avec AUX Home : la valeur en base doit être propre, car l'**empreinte de session** est calculée sur l'e-mail normalisé des deux côtés | jamais |
| `public static postConfig_auxcloud_password($v)` / `_email($v)` / `_region($v)` | `smartclimAuxCloudApi::purgerSession()`, **et rien d'autre** | — |

> ⚠️⚠️ **Le piège le plus coûteux de cette UC, et il est invisible.** `postConfig_auxhome_*` appelle
> `self::oublierIncident()` (cf. aussi `testerConnexionAuxHome()`, `smartclim.class.php:413`). **Ne pas le
> recopier ici** : `smartclim::dernier_incident` décrit le **cycle automatique AUX Home**. Un changement de
> mot de passe *legacy* effacerait la mémoire d'incident du cycle *AUX Home*, et l'état de connexion
> affiché deviendrait faux jusqu'au cycle suivant. Régression d'AC8 invisible à `php -l`, à la CI et à une
> relecture rapide.

### 6.3 Action AJAX

Une branche unique dans `core/ajax/smartclim.ajax.php` (⚠️ **4 espaces**) :
`testerConnexionAuxCloud` → `smartclim::testerConnexionAuxCloud()` → `ajax::success(<message>)`.
Rattrapage en trois blocs, dans cet ordre : `smartclimException` (message **déjà curaté**, code **figé**,
🚫 **jamais** `displayException()`), puis `Exception`, puis **`Throwable`** — une `Error` PHP 8 traverse
sinon `catch (Exception)` et la réponse cesse d'être du JSON.

### 6.4 Formulaire (`plugin_info/configuration.txt`)

Préparation serveur avant la balise fermante, sur le modèle du champ **Pays**, avec le **même
`try/catch (Throwable)`** — un HTTP 500 ici rend un panneau de configuration blanc, donc un admin privé de
**toute** sa config :

```php
$sc_regions = array();  $sc_regionActuelle = '';
try {
  $sc_regions = smartclim::regionsDisponiblesAuxCloud();
  $sc_regionActuelle = smartclim::regionAuxCloud();
} catch (Throwable $t) {
  log::add('smartclim', 'error', … smartclim::neutraliserPourLog($t->getMessage()) …);
}
```

`<select class="configKey form-control" data-l1key="auxcloud_region" id="sc_selectRegion">` — **c'est la
liste qui porte `configKey`**, et c'est le **seul** contrôle de cette clé.

⚠️ **Le piège de l'option manquante est neutralisé par construction, pas par chance** : on présélectionne
`smartclim::regionAuxCloud()`, **déjà normalisée à une clé de la table** — jamais la valeur brute de
`config::byKey()`. L'option existe donc toujours, le `.val()` du chargement AJAX du core trouve toujours sa
cible, et aucun enregistrement ne peut écraser la région (y compris un enregistrement visant un autre
champ).

**Corollaire : ni option vide « Sélectionnez… », ni option « Autre région ».** Contrairement au champ Pays,
la liste est **fermée** — 4 hôtes connus, une 5ᵉ région n'existe pas côté backend, une saisie libre ne
pourrait produire qu'une valeur inutilisable. Si la liste est indisponible (le `catch` ci-dessus), le
`<select>` est rendu **vide et désactivé** avec un message d'aide — **jamais** un champ libre.

Échappement : `htmlspecialchars($code, ENT_QUOTES, 'UTF-8')` sur la `value`, `ENT_NOQUOTES` sur le libellé.

JS : un seul handler `$('#sc_btnTesterConnexionLegacy').off('click').on('click', …)`, `timeout: 15000`,
`global: false`, résultat dans `#sc_resultatConnexionLegacy`.
⚠️ **IDs tous nouveaux** — réutiliser `#sc_resultatConnexion` ferait diaphoner les deux sections.
⚠️ Chaînes traduisibles en **guillemets doubles** (apostrophes du français et des traductions).
⚠️ **Aucune méta-séquence littérale**, double accolade ouvrante comprise, pas même en commentaire.
⚠️ Ce JS ne touche **aucun** élément `.configKey` — donc il ne vide **jamais** le champ mot de passe. Un
champ mot de passe vidé écraserait le secret stocké par une chaîne vide au prochain enregistrement, y
compris un enregistrement visant un tout autre champ (carve-out `configKey`, cf. `CLAUDE.md`).

> **Décision : pas de bouton « Effacer les identifiants » pour ce compte.** Aucun AC ne le demande ; il
> tirerait derrière lui la machinerie de resynchronisation par rechargement (~50 lignes ayant porté 4
> findings de revue côté AUX Home) pour zéro critère ; et l'utilisateur peut vider les champs puis
> enregistrer — chemin qui **purge bien la session** via `postConfig_auxcloud_*`. Asymétrie visuelle avec
> la section AUX Home **assumée**, candidate `/change` de premier rang.

## 7. Validation & classement des erreurs

Ordre **impératif**. Les étapes 1 et 2 sont un **ajout réel de cette UC** : le transport AUX Home ne classe
rien par `curl_errno()` (il ne lit que le texte de `curl_error()`) — ce n'est donc pas une doctrine reprise
telle quelle, et c'est ce qui tient l'AC4 et l'AC5.

| # | Condition | Type | Contexte | Cause affichée (AC4) |
|---|---|---|---|---|
| 1 | `curl_errno()` ∈ `{51, 58, 60, 83, 90, 91}` | `TYPE_RESEAU` | `CONTEXTE_CERTIFICAT` | certificat non vérifiable (serveur **ou** magasin local) |
| 2 | `curl_errno() == 77` | `TYPE_RESEAU` | `CONTEXTE_MAGASIN_LOCAL` | magasin de certificats **de ce Jeedom** illisible |
| 3 | Autre erreur cURL (`6`, `7`, `28`, `35`, `59`, …) | `TYPE_RESEAU` | — | service / région injoignable |
| 4 | HTTP ≥ 500, ou 429 | `TYPE_RESEAU` | — | service / région injoignable |
| 5 | Corps non-JSON, ou `status` absent | `TYPE_PROTOCOLE` | — | réponse inattendue |
| 6 | `status != 0` (HTTP < 500) | `TYPE_AUTH` | — | identifiants invalides |
| 7 | `loginsession` absente ou non conforme | `TYPE_PROTOCOLE` | — | réponse inattendue |

### 7.1 Arbitrage du 2026-09-08 — le découpage des codes TLS

Le plan initial versait **tous** les codes de couche TLS (dont `35` et `77`) dans le bucket « certificat »,
au motif qu'aucun n'est un problème de DNS ou de route. **Ce critère a été rejeté en revue** : « n'est pas
un problème de route » ne veut pas dire « est un problème de certificat serveur ».

- **`77` (`SSL_CACERT_BADFILE`)** décrit un magasin d'AC **local** illisible ou malformé sur le Jeedom, et
  ne dit **rien** du serveur distant. Un message affirmant « certificat invalide **sur le serveur** »
  enverrait l'utilisateur chercher du mauvais côté. → **message dédié**, 4ᵉ cause.
- **`35` (`SSL_CONNECT_ERROR`)** et **`59` (`SSL_CIPHER`)** sont des échecs de **négociation** (version
  TLS, cipher, coupure de handshake par un intermédiaire), pas de validation de certificat. → bucket
  **« injoignable »**.
- **`60` (`SSL_CACERT`)** reste dans le bucket certificat, mais son libellé dit **« serveur ou magasin
  local »** : c'est en pratique le code le plus fréquent d'un `ca-certificates` **périmé**, et un libellé
  qui n'accuserait que le serveur déplacerait simplement le défaut de `77` vers `60`.

> **Principe retenu, réutilisable** : un message affirmatif et faux est **pire** qu'un message générique
> honnête. L'AC4 demande des causes actionnables, pas seulement trois buckets distincts.

### 7.2 `userid` — non bloquant, délibérément

`userid` absent ou non conforme → `log::add('warning')`, stocké **vide**, et **le login réussit quand
même**. Décision identique à celle prise pour l'`uid` d'AUX Home après revue croisée : ne pas durcir un
contrat non observé. C'est l'UC02, qui en fera un en-tête, qui portera la garde.

### 7.3 Pas de 5ᵉ type d'exception — un `contexte`

Un `TYPE_TLS` toucherait une classe **partagée**, dont plusieurs branches du plugin discriminent sur
`TYPE_RESEAU` (niveau de log du cron, `memoriserIncident()`, `basculerHorsLigne()`, décompte d'échecs de
`smartclimTransport`, `smartclimBroadlinkLan.class.php:542`, `smartclim.class.php:2980`). Un échec TLS
**est** un échec de couche réseau ; lui donner un type neuf le ferait sortir **silencieusement** de toutes
ces branches le jour où la constante circulerait.

Le mécanisme du `contexte` existe déjà **exactement pour ce cas** : `CONTEXTE_ORDRE_REFUSE` est défini sur
la classe transport (`smartclimAuxHomeApi.class.php:89`) et interprété dans `smartclim` par
`messageErreurAuxHome()` (ligne 2394). Nos deux constantes suivent ce patron **à l'identique** — ce n'est
pas un couplage nouveau. Si AUX Home les adopte un jour, elles remontent sur `smartclimException` :
renommage pur, zéro migration.

### 7.4 Budget de temps

Le login legacy est **une seule requête** (pas de `getPubkey`) : toute l'arithmétique de budget global de
`smartclimAuxHomeApi::login()` n'existe que parce que son login en chaîne **deux**. Ici `CURLOPT_TIMEOUT`
borne effectivement l'opération → `$temps = (int) max(3, min(TIMEOUT_REQUETE, $_budget))`, **aucune**
soustraction d'écoulé.

Le **paramètre** `$_budget` est néanmoins conservé (même forme que l'existant) : l'UC02 enchaînera
`getfamilylist` + `room/query` + `dev/query` et devra le propager — l'alternative serait de rouvrir la
signature de `login()` à l'UC suivante, pour une ligne. `CURLOPT_CONNECTTIMEOUT = 5` **et**
`CURLOPT_NOSIGNAL = true` sont conservés : `CURLOPT_TIMEOUT` peut rester inopérant pendant `getaddrinfo()`
sur un build de libcurl sans AsynchDNS.

### 7.5 Pas de boucle de rejeu dans cette UC

La doctrine du re-login réactif borné à **un** rejeu est reprise **à sa place** — chez l'**appelant**,
jamais dans la requête (précédent : `requeteControle()` purge, `appliquerOrdre()` décide). Aucun appelant
authentifié n'existant dans ce périmètre, une boucle serait du **code mort** qu'on ne pourrait même pas
exercer : on ne l'écrit pas (même règle que `dependancy_info()` et `preConfig_demon_port`).

Ce qui est livré, et rend le rejeu trivial à l'UC02 : le classement `TYPE_AUTH` (pour que le
`$e->getType() === TYPE_AUTH` du futur appelant soit vrai), `purgerSession()` **publique**, et `cree_le` en
cache pour la télémétrie d'âge.

> Ce n'est **pas** en contradiction avec la conservation de `$_budget` : un paramètre de signature coûte
> une ligne et aucune logique, une boucle de rejeu est de la logique non exerçable.

### 7.6 Cache de session

Clé `smartclim::session_auxcloud` — parallèle de `session_auxhome` et `session_lan::<mac>`, **globale au
compte** (pas par appareil). TTL **1800 s**, alignée sur AUX Home avec le raisonnement de la décision
D-MVP08-04 : la TTL est un réglage d'**économie de requêtes**, pas de correction, **dès lors qu'un rejeu
réactif existe**. ⚠️ Ici le rejeu n'arrive qu'à l'UC02 — la TTL deviendra donc un problème de
**correction** à ce moment-là, d'où `cree_le` livré **dès maintenant** : la durée de vie réelle de
`loginsession` est le 3ᵉ « À confirmer » de la spec fonctionnelle, aucune référence ne la documente, et
l'instrumenter vaut mieux que la deviner.

Contenu : `utils::encrypt(json_encode(['loginsession','userid','empreinte','cree_le']))` — **chiffré**,
parce qu'il porte un jeton (contrairement à `dernier_incident` et `echecs_transport`, non chiffrées faute
de donnée d'origine backend).

Purge : les trois `postConfig_auxcloud_*`. ⚠️ Si un bouton d'effacement était ajouté plus tard, la purge
devrait y être **explicite** — `config::remove()` ne déclenche **pas** les hooks.

### 7.7 Clés de config

`auxcloud_email`, `auxcloud_password`, `auxcloud_region`. `snake_case` anglais, **sans tiret** (les noms de
méthodes `preConfig_auxcloud_region` / `postConfig_auxcloud_password` en dérivent). Préfixe `auxcloud_`
**déjà réservé** à ce transport par l'analyse interne § 3 (`auxcloud_endpoint_id` côté équipement) ; aucune
collision avec `auxhome_`.

Défaut INI : **`auxcloud_region = EU` seulement**. C'est le seul défaut que voie `config::byKeys()`, donc le
chargement AJAX du formulaire. Le piège documenté (« `config::save()` d'une valeur **égale** au défaut INI
supprime la ligne et court-circuite `preConfig_` ») est **sans conséquence ici pour la même raison que sur
`auxhome_country`** : la lecture applique la **même** normalisation et le **même** défaut → double
barrière, une seule implémentation (`normaliserRegion()`).

🚫 **Aucun défaut INI pour `auxcloud_password`** : le core traiterait le clair comme un chiffré. (Hypothèse
non vérifiée dans le core, et sans objet ici puisqu'on n'en pose pas — mais à ne pas « compléter ».)

## 8. Dépendances

**Aucune.** `curl` et `openssl` sont déjà les prérequis du transport AUX Home ; `packages.json` n'est pas
touché. Le plugin reste sans dépendance nouvelle et le démon n'est **pas** sollicité — cette brique est du
PHP synchrone appelé depuis une action AJAX.

## 9. Couverture des critères d'acceptation

| AC | Porté par | Statut |
|---|---|---|
| AC1 | `<fieldset>` dédié, 3 clés `auxcloud_*`, `compteConfigure()` non touchée | couvert |
| AC2 | `regions()` (EU/USA/CHN/RUS) → `<select configKey>`, défaut INI `EU`, hôte **dérivé** de la région | couvert |
| AC3 | Action AJAX → `testerConnexionAuxCloud()` → **`login()`**, jamais `session()` | couvert |
| AC4 | Classement du § 7 : **4** causes discriminées (§ 7.1) | couvert |
| AC5 | `SSL_VERIFYPEER`/`VERIFYHOST` en dur, **aucun** paramètre de contournement nulle part, message dédié | couvert |
| AC6 | `session()` : cache → sinon `login()` ; empreinte divergente → purge + `login()` | **partiel — cf. R1** |
| AC7 | Mot de passe lu au plus près de l'usage, exception recréée à la frontière, préfixe tronqué, `journaliserErreurLegacy()`, pas de `CURLOPT_VERBOSE`, pas de `displayException()` | couvert |
| AC8 | Zéro modification du socle AUX Home ; **pas** de `oublierIncident()` dans les `postConfig_auxcloud_*` | couvert |

## 10. Risques

- **R1 — AC6 n'est pleinement vérifiable qu'à l'UC02.** Le texte de l'AC parle d'« une opération
  **suivante** » : aucune opération authentifiée n'existe dans ce périmètre (découverte et pilotage sont
  explicitement hors périmètre par la spec elle-même). ⚠️ Et il faut être précis sur ce qui est
  « testable » : **aucune action AJAX de cette UC n'appelle `session()`** — la réutilisation de cache et la
  purge sur empreinte divergente sont **livrées et vérifiables par relecture**, mais **pas exerçables en
  recette fonctionnelle** avant qu'un appelant réel existe. Le rejeu après une session *refusée par le
  backend* est à porter en recette d'UC02.
  Précédent qui rend ce choix légitime : le docblock de `smartclimAuxHomeApi::session()` porte déjà
  « Premier consommateur réel : UC03+ » — livrer un cache de session sans appelant dans son UC d'origine
  est **déjà arrivé dans ce plugin**.
- **R2 — Le format réel de `loginsession` et `userid` est inconnu.** Aucune référence ne le documente,
  aucun échantillon. Si le jeton réel porte un caractère hors ASCII imprimable, `valeurEnteteConforme()`
  rejette une authentification **réussie** — la panne la plus trompeuse possible. D'où une classe
  volontairement **plus large** que `jetonConforme()` (tout l'ASCII imprimable hors espace, plutôt qu'une
  classe base64url qui rejetterait un jeton contenant `:` ou `|`), un `TYPE_PROTOCOLE` explicite, et le
  message technique journalisé. **Premier point à confirmer en recette.**
- **R3 — Aucun matériel de recette.** Le compte legacy de l'utilisateur n'est pas établi ; comme le
  transport LAN Broadlink, cette brique risque d'être livrée **non recettée**. Le code est vérifié contre
  deux références MIT recoupées, jamais contre un backend réel. Le premier essai réel tranchera d'un coup
  trois inconnues : format d'horodatage, zero padding, forme du jeton.
- **R4 — Certificats valides *au 2026-09-08*,** mais l'un expire le 2026-11-10. Une rotation vers une AC
  absente d'un magasin Debian ancien produirait un échec TLS : c'est **traité** (messages dédiés du § 7),
  pas éliminé.
- **R5 — `status` est notre seule discrimination « identifiants invalides ».** Un backend répondant
  `status != 0` pour une autre raison (quota, maintenance, région fermée) afficherait « vérifiez vos
  identifiants ». Compromis identique à celui déjà assumé sur AUX Home ; le message technique reste au log.
- **R6 — Russie à source unique.** L'hôte RUS ne figure que chez `maeek`. Il répond en TLS, mais son
  acceptation d'un login n'est établie par aucune source. AC2 est satisfait au plan de l'interface ; la
  disponibilité fonctionnelle reste à confirmer.
- **R7 — Extension future contrainte.** `regions()` devra porter une colonne `relais` (hôte
  `wss://app-relay-…`) pour l'UC03 et le domaine 05. C'est **une colonne**, pas une seconde table : ne pas
  l'ajouter maintenant, mais ne pas figer la table en simple `code => hôte`.
- **R8 — `Expect: 100-continue`.** cURL l'ajoute au-delà de ~1 Ko de corps. Le corps de login fait
  ~200 octets, donc sans effet ici ; l'UC02/UC03 devront peut-être neutraliser cet en-tête si le backend
  Java ne le gère pas (symptôme : ~1 s de latence fixe par requête).
- **R9 — Vérifications mécaniques.** Ce fichier introduit des docblocks denses portant des motifs regex
  commentés (donc des barres obliques) et des descriptions de protocole. Lancer
  `python .claude/scripts/verif-plugin.py` **avant commit** — c'est le seul filet en place contre les
  méta-séquences littérales (`*/`, balise fermante PHP, double accolade), `php` n'étant pas installé sur la
  machine de dev et la CI ne se déclenchant pas sur push `master`.

## 11. Documentation à amender en fin de cycle

- `CLAUDE.md` — la phrase « `smartclimAuxHomeApi` … seul point cURL du plugin » (cf. § 4.1) ; et la ligne
  « Classe annexe encore à créer … `smartclimAuxCloudApi` », qui devient fausse.
- `core/php/smartclim.inc.php` **ligne 44** — commentaire « La classe a venir (smartclimAuxCloudApi)
  viendra s'ajouter a cette liste. » (vérifié au caractère près).
- `.memory/analyse/smartclim-transport-aux-cloud-legacy.md` § 1 et § 8 — reporter les deux « À confirmer »
  fermés : **horodatage de format libre** (§ 2) et **certificats TLS valides** (§ 1.4).

## 12. Dette (hors périmètre de ce cycle)

- **D-1 — Pas de bouton « Effacer les identifiants »** pour le compte legacy (§ 6.4). Asymétrie assumée
  avec la section AUX Home ; contournement fonctionnel : vider les champs et enregistrer, ce qui purge bien
  la session.
- **D-2 — Aucun backoff** après échecs répétés du login legacy, comme sur AUX Home (dette D-MVP08-05).
- **D-3 — `session()` sans appelant** dans cette UC (R1) : à exercer en recette d'UC02.
- **D-4 — TTL de session à 1800 s par alignement**, pas par mesure. `cree_le` est livré pour la calibrer
  factuellement à l'UC02 (§ 7.6).
