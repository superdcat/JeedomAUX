# SmartClim — Transport `AUX_HOME` (nouveau cloud `eu-smthome-api.aux-global.com`)

> **Transport du MVP.** C'est le seul dont on sait qu'il pilote l'appareil de validation (`.memory/brief.md` § 19).
>
> **Statut des informations** : ✅ = vérifié dans du code source lu ou dans une réponse HTTP réellement
> observée · ⚠️ = rapporté par une source unique · ❓ = **à confirmer en recette sur le matériel**.
>
> **Source de vérité principale** : `GijsZwegers/com.zwegersit.auxairco` (**MIT**), fichiers
> `lib/auxcloud/client.ts`, `lib/auxcloud/constants.ts`, `drivers/airco/device.ts`, `drivers/airco/driver.ts`
> (branche `main`, lus le 2026-08-24). Complété par l'article de l'auteur
> `https://zwegersit.nl/projecten/airco-homey/` et, pour le backend cousin CN, par
> `latentharbor/ha-aux-a-plus` (**MIT**).
>
> **Date** : 2026-08-24.

---

## 1. Enveloppe HTTP commune

- **Hôte** : `https://eu-smthome-api.aux-global.com` ✅
- **Enveloppe de réponse** : `{"code": <int>, "message": "<string>", "data": <any>}` ✅
  Le **succès est `code == 200`** — indépendamment du code HTTP. Un `HTTP 200` avec `code != 200` est une
  **erreur métier** (ex. `9023` mauvais chiffrement, `64033` clé publique expirée sur le backend CN).
  → côté PHP : toujours tester `code`, jamais seulement `http_code`.
- **En-têtes** (repris de `client.ts::baseHeaders`) ✅ :

  | En-tête | Valeur | Remarque |
  |---|---|---|
  | `Accept` | `*/*` | |
  | `Accept-Language` | `en-US` | |
  | `aid` | `1` | identifiant d'application |
  | `os` | `2` | `2` = iOS dans l'implémentation de référence |
  | `country` | **code ISO-3166 alpha-3** (`FRA`, `NLD`, `DEU`…) | ⚠️ **critique**, cf. § 5 |
  | `User-Agent` | `AUXAC/<version> (iPhone; iOS …)` | identité applicative usurpée |
  | `Authorization` | `bearer <token>` (minuscule `bearer`) | avant login : **jeton applicatif statique** |
  | `Content-Type` | `application/json` | uniquement si corps |

- **Formats des constantes de protocole — confirmés le 2026-08-25** (lecture de
  `com.zwegersit.auxairco/lib/auxcloud/constants.ts`, MIT). Les **valeurs** restent volontairement hors
  de ce fichier (leur place est le code de `smartclimAuxHomeApi`), mais leurs **formats** évitent une
  erreur d'implémentation coûteuse :
  - `ACCOUNT_AES_KEY` est un **texte ASCII brut de 16 caractères** (`Buffer` UTF-8) → **utilisable
    directement** comme clé AES-128 en PHP, **sans** décodage hexadécimal ni base64. C'est exactement le
    genre de détail qui coûte un cycle complet s'il est deviné de travers.
  - `AUX_USER_AGENT` = `AUXAC/2.3.2 (iPhone; iOS 18.6.2; Scale/3.00)` — chaîne exacte.
  - `STATIC_APP_TOKEN` : base64, ~88 caractères.
  - La table `TIMEZONE_TO_COUNTRY` de la référence ne compte que **30 entrées** et retombe sur `NLD`
    quand le fuseau est inconnu ; SmartClim en a **66** (Europe) et retombe sur **vide** — écart
    délibéré, cf. UC01 (un pays faux mais plausible produit un échec de login au message trompeur).
- **Jeton applicatif pré-login** : constante `STATIC_APP_TOKEN` de
  `com.zwegersit.auxairco/lib/auxcloud/constants.ts` (base64, ~88 caractères). Ce n'est **pas un secret
  utilisateur** — il identifie l'application elle-même et est utilisé pour `getPubkey`/`login`.
  → **Ne pas le recopier ici** ; le lire dans le dépôt MIT au moment de l'implémentation.
- **TLS** : validation **activée** (contrairement au cloud legacy, cf.
  `smartclim-transport-aux-cloud-legacy.md` § 1). Ne jamais poser `CURLOPT_SSL_VERIFYPEER = false`.

**Vérification live effectuée le 2026-08-24** ✅ : `GET /app/auth/getPubkey` avec le jeton applicatif
statique et `country: FRA` répond `HTTP 200` / `{"code":200,"message":"","data":"MIGfMA0GCSq…IDAQAB"}`
(clé publique DER base64, RSA-1024). Le contrat d'enveloppe et l'acceptation du jeton statique pré-login
sont donc confirmés en conditions réelles.

## 2. Authentification

### 2.1 Séquence

```text
GET  /app/auth/getPubkey            → data = clé publique RSA (DER, base64)   ✅
POST /app/auth/login/pwd            → data = { token: { token }, appUser: { uid, nickName } }   ✅
```

Corps du `login/pwd` ✅ :

```json
{ "password": "<base64>", "account": "<base64>", "ts": "<epoch ms, string>", "publicKeyBase64": "<la clé du GET>" }
```

### 2.2 Chiffrement des identifiants (le point délicat)

| Champ | Algorithme | Détail | Statut |
|---|---|---|---|
| `password` | **RSA/ECB/PKCS1Padding** avec la clé publique fraîchement récupérée | découpage en blocs de **117 octets** (charge utile max PKCS#1 v1.5 pour une clé 1024 bits), concaténation des blocs chiffrés, puis base64 | ✅ `client.ts::encryptPassword` |
| `account` (e-mail) | **AES-128-ECB / PKCS5Padding**, clé **fixe embarquée dans l'APK Android AUX Home** | résultat en base64 ; indépendant de la clé RSA | ✅ `client.ts::encryptAccount` + `constants.ts::ACCOUNT_AES_KEY` |
| `ts` | horodatage epoch **millisecondes**, en chaîne | | ✅ |
| `publicKeyBase64` | la valeur **exacte** renvoyée par `getPubkey` | renvoyée telle quelle | ✅ |

Détails d'implémentation vérifiés :

- La clé publique arrive en **DER base64 nu** : il faut la reconstituer en PEM
  (`-----BEGIN PUBLIC KEY-----` + retours ligne tous les 64 caractères) avant usage.
  ✅ `client.ts::derBase64ToPem`. En PHP : `openssl_public_encrypt($chunk, $out, $pem, OPENSSL_PKCS1_PADDING)`.
- **Toujours redemander une clé publique juste avant le login.** Réutiliser une clé mémorisée renvoie une
  erreur d'expiration (`code 64033`, « 公钥已过期 ») sur le backend cousin CN ⚠️
  (`latentharbor/ha-aux-a-plus/docs/RESEARCH_PLAYBOOK.md` § 2). Comportement supposé identique en EU ❓.
- **Clé AES du champ `account` — ✅ décision actée le 2026-08-24 : elle sera EMBARQUÉE dans le code du
  plugin.** Elle est publiée telle quelle dans `com.zwegersit.auxairco/lib/auxcloud/constants.ts` (MIT) ;
  l'auteur de l'article d'origine l'avait volontairement omise au nom de la *responsible disclosure* (avis
  NCSC), mais sans elle aucun login n'est possible. Ce n'est pas un secret utilisateur : c'est une
  **constante de protocole**. ⚠️ Elle reste néanmoins **volontairement non recopiée dans ce fichier
  d'analyse** — sa place est le code de `smartclimAuxHomeApi`, avec un commentaire citant sa source et sa
  licence.

### 2.3 Session

- Le login renvoie **un seul jeton de session**, **pas de refresh token** — vérifié explicitement par
  l'auteur de la référence (« *AUX's login response only returns a single session token, no separate
  refresh token (confirmed against the live API)* », `drivers/airco/device.ts`). ✅
- **Stratégie de renouvellement** : purement réactive. En cas d'échec d'un appel authentifié → **re-login
  puis un seul rejeu** de la requête (`device.ts::sendNewIntent` / `pollNewState`). ✅
- Durée de vie du jeton : ❓ inconnue. → traiter tout `code != 200` sur un appel authentifié comme une
  possible expiration, avec **anti-boucle** (une seule tentative de re-login par cycle).

## 3. Découverte des appareils

```text
GET /app/user_device?getStatus=1     → data = AuxDevice[]     ✅
```

Champs exploités par l'implémentation de référence ✅ :

| Champ | Type | Usage SmartClim |
|---|---|---|
| `deviceId` | string | identifiant de commande (`intent.deviceId`) → config d'équipement `auxhome_device_id` |
| `mac` | string | **clé de rapprochement inter-transports** → `logicalId` de l'eqLogic |
| `alias` | string | nom convivial affiché dans AUX Home → nom de l'eqLogic à la création |
| `modelId` | string | modèle → info d'affichage / futur profil de capacités |
| `online` | bool | disponibilité → commande info `online` |
| `status.running` | hex string | trame HVAC « longue » (température ambiante) |
| `status.control` | hex string | trame HVAC « courte » (dernier état commandé) |
| `status.type` | string | `"uart"` |

> ⚠️ **Il n'y a pas d'appel « état d'un appareil »** dans l'implémentation de référence : l'état est
> obtenu en **relistant tous les appareils** (`getStatus=1`) puis en filtrant sur `deviceId`. Un
> rafraîchissement plugin = **un seul appel**, quel que soit le nombre d'équipements → excellent pour les
> quotas, mais impose un **cron global** plutôt qu'un cron par équipement.

Un **endpoint de configuration/capacités** existe : `GET /app/getConfig?id=deviceMutex` — confirmé
fonctionnel par l'utilisateur (`.memory/brief.md` § 19 : `HTTP 200`, `code: 200`, JSON valide avec un bearer AUX
Home). Il expose la **table générique** des concepts (`on_off`, `temperature`, `air_con_func`, `wind_speed`,
`up_down_swing`, `left_right_swing`, `screen`, `sleep_mode`, `eco`, `clean`, `healthy`, `anti_fungus`,
`ultra_silence`…). L'auteur de la référence le décrit comme « *un endpoint de configuration contenant tous
les réglages possibles du climatiseur : vitesses de ventilation, modes, oscillations, minuteries* ».
❓ **Schéma exact et lien avec les capacités d'un appareil donné à établir en recette.**

> Le backend cousin CN utilise `GET /app/device_bindings?configId=…&getStatus=1` au lieu de
> `/app/user_device` (`ha-aux-a-plus/README.md`). Les deux backends partagent l'espace de noms `/app/…`
> mais **pas toutes les routes** : ne pas transposer une route de l'un à l'autre sans vérification.

## 4. Commandes

```text
POST /app/device/v2/control          ✅
```

Corps ✅ (`client.ts::sendControl` ; identique à l'exemple de l'article) :

```json
{ "intent": { "on_off": 1, "temperature": 22 }, "dst": 1, "deviceId": "<deviceId>" }
```

- `dst` = `1` (constante, sens non élucidé ❓).
- `intent` = dictionnaire **clé conceptuelle → valeur numérique**.
- Le backend CN distingue `POST /app/device/v2/control` (marche/arrêt) et `POST /app/device/control`
  (mode, consigne, ventilation, oscillation) ⚠️ (`ha-aux-a-plus/api.py::_control`). En EU, la référence
  utilise **`v2/control` pour tout** ✅. ❓ Vérifier si `/app/device/control` existe aussi en EU (utile en
  repli si `v2` refuse certaines intentions).

### 4.1 Règle vérifiée : une intention par requête

> « *Als ik meerdere instellingen tegelijk stuurde terwijl de airco uit stond, werden sommige daarvan
> gewoon genegeerd. Eén instelling per request werkte wel iedere keer.* » — article Zwegers § 06. ⚠️

**Décision SmartClim** : par défaut **une seule clé par `intent`**, avec sérialisation des envois
(petit délai entre deux requêtes). Un groupage multi-clés reste possible en option avancée, jamais par
défaut. Cas particulier : changer de mode alors que l'appareil est éteint impose d'envoyer aussi
`on_off: 1` (le backend CN le fait explicitement : `climate.py::set_hvac_mode` ajoute `on_off` à l'intent).

### 4.2 Clés d'intention connues

| Clé | Sens | Codes | Statut |
|---|---|---|---|
| `on_off` | marche/arrêt | `0` / `1` | ✅ |
| `temperature` | consigne | **entier en °C** dans la réf. EU (`22`), arrondi au demi-degré côté client | ✅ EU / ⚠️ **le backend CN envoie ×10** (`int(round(t*10))`) → **❓ à confirmer en recette EU** |
| `air_con_func` | mode | `0` AUTO, `1` COOL, `2` DRY, `4` HEAT, `6` FAN | ✅ (`constants.ts::AuxMode`, identique côté CN) |
| `wind_speed` | vitesse ventilation | **contradiction entre sources — cf. § 4.3** | ❓ |
| `up_down_swing` | oscillation verticale | `0` = oscille, `7` = fixe | ⚠️ (`ha-aux-a-plus/climate.py::set_swing_mode`) — cohérent avec le LAN Broadlink (`Fixation.ON = 0`, `OFF = 7`) |
| `left_right_swing` | oscillation horizontale | idem | ⚠️ |
| `screen` / `screen_on_off` | afficheur | `0`/`1` ❓ | ⚠️ nom exact incertain (`screen` dans `deviceMutex`, `screen_on_off` dans l'état CN) |
| `sleep_mode`, `eco`, `clean`, `healthy`, `anti_fungus`, `ultra_silence` | fonctions | `0`/`1` ❓ | ⚠️ noms issus de `deviceMutex` (`.memory/brief.md` § 2) ; valeurs non vérifiées |

### 4.3 ⚠️ Contradiction ouverte sur `wind_speed`

Deux tables incompatibles coexistent :

| Valeur | `com.zwegersit.auxairco/constants.ts::AuxFanSpeed` (EU, issue du endpoint de configuration) | `ha-aux-a-plus/climate.py::FAN_TO_CODE`/`CODE_TO_FAN` (CN) |
|---|---|---|
| 0 | LOW | silent |
| 1 | MEDIUM | low |
| 2 | HIGH | medium |
| 3 | MUTE | high |
| 4 | AUTO | high |
| 5 | TURBO | turbo |
| 6 | MEDIUM_LOW | low |
| 7 | MEDIUM_HIGH | high |

**Décision SmartClim** : ne **pas** figer cette table en dur. La retenir depuis
`GET /app/getConfig?id=deviceMutex` quand le schéma sera établi, avec la table EU
(`com.zwegersit.auxairco`) comme valeur par défaut, et **valider en recette** vitesse par vitesse. C'est
l'argument principal en faveur d'une table de correspondance **donnée** et non **codée en dur** (cf.
`smartclim-modele-abstrait-capacites.md`).

## 5. En-tête `country` : cause d'échec de login documentée

> Le `country` (ISO-3) était figé à `NLD` jusqu'à ce qu'un utilisateur slovaque échoue au login avec
> « identifiant ou mot de passe incorrect » malgré des identifiants valides. La crypto étant indépendante
> de la région, l'explication retenue est que **le backend route la requête vers un jeu de clés / une table
> utilisateurs régionale** à partir de cet en-tête. ⚠️ (`com.zwegersit.auxairco/lib/auxcloud/constants.ts`,
> commentaire de `TIMEZONE_TO_COUNTRY`)

**Décision SmartClim** : exposer le pays comme **clé de configuration plugin** (`auxhome_country`), avec
une valeur par défaut déduite du fuseau horaire Jeedom (`config::byKey('timezone')`) via une table
`fuseau → ISO-3` (la table de la référence couvre l'Europe et est directement portable). Un échec de login
doit **explicitement suggérer de vérifier le pays** dans le message d'erreur.

## 6. Lecture de l'état : décodage des trames `status.*`

Les deux champs sont des **trames HVAC hexadécimales** (même famille que le LAN Broadlink, cf.
`smartclim-transport-broadlink-lan.md` § 5).

### 6.1 `status.control` — dernier état **commandé** ✅ (`client.ts::parseControlState`)

| Champ | Extraction | Note |
|---|---|---|
| marche/arrêt | `(octet[18] >> 5) & 1` | |
| mode | `octet[15] >> 5` | valeurs `AuxMode` (§ 4.2) |
| consigne | `(octet[10] >> 3) + 8` **+ 0,5** si `octet[12] & 0x80` | |
| vitesse (fil) | `octet[13] >> 5` | **table du fil**, ≠ `wind_speed` de l'intent — cf. § 6.3 |
| oscillation active | `octet[11] != 0x20` | ⚠️ **ne distingue pas vertical / horizontal** — limitation assumée par la référence |

### 6.2 `status.running` — trame longue ✅ (`client.ts::parseAmbientTemperature`)

- **Température ambiante = `octet[15] - 32`**, en degrés **entiers**.
  Établi sur 8 mesures horodatées automatiquement, dont une isolée (24 °C → 20 °C, mode/ventilation/consigne
  inchangés, l'octet baisse exactement de 4). Une première tentative naïve (`octet - 23` sur d'autres
  octets) s'était révélée fausse : elle suivait en réalité une température de batterie/soufflage.
  → **Leçon reprise dans nos critères d'acceptation** : ne jamais valider une formule d'octet sur moins de
  trois mesures couvrant plusieurs modes et vitesses.

### 6.3 ⚠️ Deux tables de vitesse différentes dans le même transport

Le **fil** (`status.control` octet 13) utilise les **valeurs Broadlink historiques**, pas les valeurs de
l'intent ✅ (`constants.ts::WIRE_FAN_TO_HOMEY`) :

`1` = high · `2` = medium · `3` = low · `4` = turbo · `5` = auto

Il **n'existe aucun équivalent fil** pour `medium_low`, `medium_high` et `mute` → **ces vitesses sont
écrivables mais non relisables**. Le plugin doit donc conserver la **dernière vitesse commandée**
(état optimiste persistant) plutôt que de rétrograder l'affichage sur la valeur relue.

### 6.4 ⚠️ Fraîcheur : le point faible du transport

> « *Het probleem was namelijk dat dat `running`-veld helemaal niet steeds meteen ververst. Soms gebeurde
> dat na een paar minuten, soms pas na een half uur.* » — article Zwegers § 08.

La **température ambiante peut avoir plusieurs dizaines de minutes de retard**, y compris dans
l'application officielle. Conséquences pour SmartClim :

- Un intervalle de scrutation agressif **n'améliore pas** la fraîcheur. Défaut raisonnable : **5 min**
  (`cron5`), configurable ; ne pas descendre sous 1 min.
- **Exposer explicitement l'âge de la donnée** (commande info `derniere_maj` + affichage) plutôt que de
  laisser croire à du temps réel.
- Ne **jamais** brancher une régulation fine (thermostat Jeedom) sur cette température sans avertissement
  dans la documentation utilisateur.

## 7. Temps réel / push

- **Aucun WebSocket ni push n'est utilisé** par l'implémentation de référence EU : elle scrute
  `/app/user_device` toutes les **60 s** (`device.ts::POLL_INTERVAL_MS`). ✅
- Le backend **CN** possède un **broker MQTT TLS** (`smthomem2m.aux-home.com:8883`, souscription
  `dev2app/<uid>/#`, publication `app2dev/<deviceId>/#`) ✅ (`ha-aux-a-plus/mqtt.py`).
- **[HYPOTHÈSE]** un équivalent EU existe probablement (même famille de backend). **À confirmer** par
  capture réseau de l'application AUX Home. Tant que ce n'est pas confirmé : **pas de démon** pour ce
  transport (cf. `smartclim-daemon-choix.md`).

## 8. Robustesse & sécurité — règles retenues

1. **Ne jamais journaliser** mot de passe, `account` chiffré, ou jeton complet. Journaliser au plus
   `bearer <6 premiers caractères>…` (`.memory/brief.md` § 16).
2. Le mot de passe est stocké via `$_encryptConfigKey` (config plugin) ; le jeton via le cache Jeedom
   **chiffré** (`utils::encrypt` avant `cache::set`).
3. **Timeouts — valeur révisée le 2026-08-25 (UC02 du MVP).** ⚠️ La recommandation initiale
   « connexion 5 s, total 15 s **par requête** » est **inapplicable telle quelle au login**, qui enchaîne
   **deux** requêtes (`getPubkey` puis `login/pwd`) : 2 × 15 s = 30 s, au-delà du plafond de 20 s exigé
   pour un échec réseau. → Valeurs retenues : `TIMEOUT_CONNEXION = 5`, `TIMEOUT_REQUETE = 10`, et surtout
   un **budget GLOBAL `BUDGET_LOGIN = 18`** dont la 2ᵉ requête reçoit le **reste** (`max(3, budget −
   écoulé)`).
   ⚠️ **Ne jamais se reposer sur le timeout par requête pour tenir une exigence exprimée en budget
   global** : `CURLOPT_TIMEOUT` peut être inopérant pendant `getaddrinfo()` selon le build de libcurl
   (absence de `AsynchDNS` combinée à `CURLOPT_NOSIGNAL`, que le plugin pose).
   ⚠️ **`CURLOPT_DNS_CACHE_TIMEOUT` est sans effet** si le handle cURL est créé et détruit à chaque
   appel : le cache DNS est porté **par le handle**. Il faudrait un `curl_share_init()`
   (`CURL_LOCK_DATA_DNS`) partagé — non fait au MVP, gain marginal.
   ⚠️ **Verrou de session PHP** : un handler AJAX qui tient 18 s **sérialise toute l'interface Jeedom**
   derrière lui (sessions fichier). `session_write_close()` est obligatoire avant tout appel réseau —
   cf. `jeedom-config-plugin-et-cycle-de-vie.md` § 9.
4. Sur `code != 200` d'un appel authentifié : **un** re-login + **un** rejeu, puis échec propre.
5. **Protection anti-état-périmé** : après une commande, mémoriser l'état optimiste pendant une période de
   grâce (≈ 60 s, à calibrer) pendant laquelle un état scruté plus ancien ne doit pas écraser la valeur
   commandée. Symptômes documentés en l'absence de cette protection : consigne 21,5 °C qui revient à 22 °C,
   arrêt qui repasse en marche, oscillations d'état, bips multiples ⚠️
   (`ha-aux-a-plus/docs/RESEARCH_PLAYBOOK.md` § 4).
6. **Déduplication** des commandes identiques rapprochées (évite les bips répétés du climatiseur) ⚠️ (idem).
7. Aucun quota documenté ❓ → prudence : un seul appel de liste par cycle, sérialisation des commandes.

## 9. À confirmer (recette)

- [ ] `temperature` : entier °C ou ×10 sur le backend EU ?
- [ ] Table `wind_speed` réelle de l'appareil (8 valeurs ?) et correspondance avec l'afficheur.
- [ ] Noms et valeurs exacts des intentions de confort (`screen`/`screen_on_off`, `sleep_mode`, `eco`,
      `clean`, `healthy`, `anti_fungus`, `ultra_silence`).
- [ ] Schéma de `GET /app/getConfig?id=deviceMutex` et **façon d'en dériver les capacités d'un appareil
      donné** (par `modelId` ? par un champ de `/app/user_device` ?).
- [ ] `POST /app/device/control` (sans `v2`) existe-t-il en EU ?
- [ ] Durée de vie du jeton ; code d'erreur exact d'expiration.
- [ ] Existence d'un push (MQTT/WebSocket) EU.
- [ ] Séparation vertical/horizontal de l'oscillation dans `status.control` (octet 11 : `!= 0x20` ne
      suffit pas).
