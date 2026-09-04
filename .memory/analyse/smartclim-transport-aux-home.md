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

### 3.1 Capacités PAR APPAREIL — trou identifié en recette le 2026-08-26

Constat de recette sur le matériel de test : une unité **qui ne chauffe pas** se voit afficher
« Modes disponibles : Automatique, Refroidissement, Déshumidification, Chauffage, Ventilation », et
UC06 lui crée un bouton « Chauffage ». Ce n'est pas un défaut de la table de correspondance mais
**l'absence de source par appareil** : `smartclimAuxHomeApi::capacitesAppareil()` déduit des trames HVAC
les **concepts lisibles** (la longueur de `status.control` dit si le mode est décodable), puis retourne,
pour les modes et les vitesses, le **catalogue du transport** — donc le même profil pour toutes les
unités. C'est un écart à l'objectif d'UC04 (« l'utilisateur ne doit jamais se voir proposer une commande
que son appareil ne sait pas exécuter ») et à son **AC6**.

Ce qui est acquis, et qui ne suffit pas :

| Source | Ce qu'elle donne | Pourquoi elle ne répond pas |
|---|---|---|
| Longueur des trames `status.control` / `status.running` | les **concepts** décodables (mode, consigne, vitesse, ambiante) | dit qu'un champ *mode* existe, pas **quelles** valeurs l'appareil accepte |
| `getConfig?id=deviceMutex` | table **générique** des concepts | générique par construction (`.memory/brief.md` : « ne signifie pas que chaque appareil supporte toutes ces fonctions ») |
| Trame HVAC (LAN comme cloud) | état courant | aucun octet de capacité connu — cf. `smartclim-transport-broadlink-lan.md` § 5.2, aucune information de modèle exploitable |
| Observation des états successifs | les modes **effectivement utilisés** | un mode jamais utilisé serait déclaré absent : profil trop étroit, pas plus juste |

L'application AUX Home masque le chauffage sur une unité froid-seul : **l'information existe côté
backend**. Elle se trouve donc dans un champ de `/app/user_device` que le plugin jette à la
normalisation (candidats plausibles : `productId`, `deviceType`, un drapeau de fonctions), ou derrière
une route non encore identifiée.

**Comment trancher** (outillage livré le 2026-08-26) : bouton **« Sonde de diagnostic »** sur la page du
plugin (Plugins → Confort → SmartClim). Il exécute en **GET** la route de référence puis un catalogue de
routes candidates (`smartclimAuxHomeApi::routesDiagnostic()`) et affiche leur réponse **brute** —
identifiants masqués par jetons stables, donc le rapport se copie et se partage tel quel. Deux sections à
relire d'abord : le **résumé** (quelle route répond quoi) et les **pistes** (les clés dont le nom évoque
une capacité, un modèle ou un type). Le rapport complet, charges utiles incluses, se télécharge en JSON.

Pour suivre une piste (chemin non catalogué) ou lever le masquage, il faut la variante CLI — le transport
refuse ces deux choses hors ligne de commande :

```bash
cd /var/www/html/plugins/smartclim
php core/php/diagnostic-auxhome.php '/app/getConfig?id=uneAutrePiste'
```

⚠️ Le champ trouvé devra **remplacer** le catalogue, pas s'y unir : l'union de
`smartclim::appliquerCapacites()` ne retire jamais rien, un `HEAT` déjà stocké survivrait à la correction —
la migration du profil déjà en base, et des commandes `mode_heat` déjà créées, fait partie du travail.

### 3.2 Réponse de la sonde (2026-08-26) : `feature`, dans `/app/user_device`

Premier passage de sonde sur le compte de recette. **Aucune route nouvelle n'est nécessaire** : la source
par appareil est un champ que `normaliserAppareil()` jetait, dans la réponse **déjà appelée** par le
plugin.

Éliminé, et à ne pas re-tenter :

| Route sondée | Verdict |
|---|---|
| `GET /app/getConfig?id=deviceFunction` / `deviceType` / `product` / `all` | HTTP 200 mais **code métier `-1`** — l'identifiant de config n'existe pas. `deviceMutex` est le seul `id` connu qui réponde `200/200`. |
| `GET /app/device/config` / `device/function` / `device/v2/config` / `user_device/config` (`?deviceId=…`) | **HTTP 404** — ces routes n'existent pas en EU. |
| `GET /app/product?productId=…` | non sondable : **il n'y a pas de `productId`** dans `/app/user_device` (le champ voisin s'appelle `productKey`). |

Ce que porte **chaque ligne** de `/app/user_device` (relevé sur l'unité de test, un **portable** —
`modelId` = `m_00010001_portable`, `productKey` = `00010006`) :

```json
"feature": {
  "coolType":        ["1", "0"],
  "frenquency":      ["0", "0"],
  "mode":            ["0,1,2,3,4", "1"],
  "tempInterval":    ["0", "0"],
  "roomTempDisplay": ["1", "0"],
  "deviceSupport":   ["0,1,2,3,4,5,6,7,9,10,14,25,26,27,36", "1"],
  "screen":          ["1", "0"],
  "windSpeed":       ["2", "0"],
  "appSupport":      ["0,4,5", "1"],
  "faultSupport":    ["0", "0"],
  "timing":          ["0", "0"],
  "healthPlus":      ["0,3,4", "1"],
  "voice":           ["0", "1"],
  "tvoc":            ["1", "1"]
}
```

Lecture, en distinguant le vérifié de l'hypothèse :

- ✅ `feature` est **par appareil** : c'est la source cherchée depuis le § 3.1.
- ✅ Chaque entrée est un **couple** `[valeur, drapeau]`. Hypothèse cohérente sur les 14 entrées
  relevées : **drapeau `1` = la valeur est une liste** séparée par des virgules (`mode`,
  `deviceSupport`, `appSupport`, `healthPlus`, `voice`, `tvoc`), **drapeau `0` = valeur scalaire**
  (`coolType`, `windSpeed`, `screen`, `roomTempDisplay`…). ⚠️ À confirmer sur un 2ᵉ appareil.
- ❓ `coolType` = `1` sur une unité **froid-seul** : candidat n° 1 comme discriminant
  froid-seul / réversible. Le nom, la valeur et le fait que l'appareil ne chauffe pas concordent —
  mais un seul appareil ne prouve pas le sens de `0` vs `1`.
- ⚠️ `mode` = `0,1,2,3,4` (5 entrées) sur cette même unité froid-seul : ces valeurs ne peuvent donc
  **pas** être les codes protocole `air_con_func` (`0` AUTO, `1` COOL, `2` DRY, `4` HEAT, `6` FAN — noter
  l'absence de `3` et la présence de `6`). Ce sont des **index**, dans une table à identifier — très
  probablement `deviceMutex.configContent.air_con_func.specs`, dont la sonde confirme qu'elle a
  **5 entrées** aux clés `0, 1, 2, 4, 6`. **Ne pas coder de correspondance avant d'avoir lu ces specs.**
- ❓ `windSpeed` = `2` (scalaire) : ressemble à un **identifiant de table** de vitesses, ce qui serait la
  réponse à la contradiction ouverte du § 4.3 (deux tables `wind_speed` incompatibles) —
  `deviceMutex.configContent.wind_speed.specs` a 8 entrées.
- ❓ `deviceSupport` / `appSupport` / `healthPlus` : listes d'index de **fonctions** supportées. Leur
  table de référence est probablement le sommaire de `deviceMutex.configContent` (60+ fonctions :
  `sleep_mode`, `eco`, `clean`, `healthy`, `anti_fungus`, `ultra_silence`, `electric_heating`,
  `eight_heat`, `pet_model`, `zero_wind_feeling`…). Matière pour le domaine post-MVP 04.
- `deviceMutex` répond `configType = 1` et un `configContent` par fonction, chacun avec ses `specs` :
  c'est bien la **table générique**, mais elle devient exploitable **couplée à `feature`** — le lien
  manquant du § 3.1.

#### Ce que le dump complet de `deviceMutex` a fermé (2026-08-26, 2ᵉ passage)

`configContent` compte **35 fonctions**, chacune avec `key`, `keyN` (libellé constructeur), `specs`
(les valeurs possibles, `valueN` = libellé), et trois familles de règles : `toastMutex` (message si
interdit), `showMutex` (masquer telle fonction), `controlMutex` (forcer telle valeur). C'est un moteur de
règles d'IHM, pas un profil d'appareil.

✅ **Codes de mode confirmés à la source** — `configContent.air_con_func.specs` est un objet dont les
**clés sont les codes protocole** : `0` 自动 AUTO, `1` 制冷 COOL, `2` 除湿 DRY, `4` 制热 HEAT, `6` 送风
FAN. Exactement la table déjà en place dans `smartclimCapabilities`. Le trou entre 4 et 6 est réel.

✅ **Table `wind_speed` confirmée** → § 4.3, contradiction close.

⚠️ **Mais `deviceMutex` est générique** : la présence de la clé `4` (制热) ne dit **rien** de cet
appareil-là, et **aucune** règle de `configContent` n'est indexée sur `coolType` ni sur `feature.mode`.
La lecture de `feature` vit donc dans les ressources de l'application, pas dans ce que le backend expose.

Ce que les règles apprennent quand même, et qui servira au domaine post-MVP 04 :

- `deviceSupport` est bien une **liste d'identifiants de capacités**, référencée par les règles sous la
  forme `{"value":"34","key":"deviceSupport"}` ou `"!37"` (le `!` = « ne contient pas »). Ids vus dans les
  règles : `15`, `34`, `37`, `38`. Notre appareil déclare `0..7,9,10,14,25,26,27,36` — donc ni 34, ni 37,
  ni 38. Le **sens** de chaque id n'est pas exposé.
- `use_type` (0/1) sépare deux familles d'appareils : les mêmes fonctions y reçoivent des messages
  différents (`only_use_cool_mode` en `use_type=0` contre `only_support_hot_cool_mode` en `use_type=1`).
  Notre appareil a `useType = 0`. Piste sérieuse, non concluante seule.
- Les valeurs composées existent : `key` = `"comfort_wind&&wind_speed&&air_con_func"` avec
  `value` = `"1&&0&&1"`. Toute exploitation future de `deviceMutex` doit gérer ce séparateur `&&`.
- Coquille backend relevée : `left_right_swing.controlMutex` utilise `useType` là où tout le reste écrit
  `use_type`. Ne pas « corriger » en lisant — accepter les deux graphies.

**Observation à part, utile pour UC05/UC07** : sur cet appareil `status.running` est **`null`** alors que
`status.control` est présent — et `feature.roomTempDisplay` vaut `1`. La température ambiante n'est donc
pas toujours servie, même sur un appareil qui l'affiche. C'est exactement le cas que le mécanisme UC05
« une clé absente de l'état ne touche pas sa commande » couvre : rien à corriger, mais ne jamais supposer
`running` présent.

### 3.3 Tranché : `coolType` — et pourquoi la restriction est une table d'EXCLUSIONS

**Mesure du 2026-08-26** : l'application AUX Home, sur l'unité de recette, propose **froid,
déshumidification, ventilation, automatique** — soit **4 modes, sans chauffage**. Or `feature.mode`
déclare **5 entrées** (`0,1,2,3,4`) et la table générique `deviceMutex` contient bien le mode `4` (制热).
Donc :

- ✅ **`feature.coolType` = `1` ⇒ pas de chauffage.** L'application filtre, et `coolType` est le seul
  champ du profil qui puisse porter ce filtre. Preuve positive, sur un appareil dont on a vérifié le
  comportement réel de l'IHM constructeur.
- ⚠️ **`feature.mode` n'est PAS la liste des modes supportés** : 5 entrées déclarées contre 4 modes
  proposés. Le sens de ses index reste **inconnu** (catalogue de la famille de modèles ? autre
  énumération ?) et il faudra un appareil déclarant une liste plus courte pour le décoder. **Ne rien
  construire dessus.**
- ⚠️ **`coolType = 0` reste de sens inconnu** : un seul appareil observé, et l'inférence n'est valable que
  dans un sens (`1` ⇒ pas de chauffage). Le déduire réversible serait une extrapolation.

#### Conséquence de conception : exclusions, jamais inclusions

`smartclimAuxHomeApi::exclusionsAuxHome()` est une table `nom déclaré => valeur observée => codes
génériques NON supportés`. Ce sens n'est pas un détail d'implémentation :

| | S'appuie sur | Tient avec un seul appareil de référence ? |
|---|---|---|
| **Exclusion** (retenu) | une **preuve positive** : telle valeur observée sur un appareil dont l'IHM constructeur masque effectivement la fonction | **oui** |
| Inclusion (écarté) | savoir décoder la liste **complète** des capacités — c'est-à-dire `feature.mode`, justement indéchiffrable | non : retirerait des modes bien supportés |

Corollaire assumé : **ce qui n'est pas explicitement exclu reste proposé**. On ampute ce dont on est sûr,
jamais ce dont on doute — c'est la même règle que `'fil' => null` dans `smartclimCapabilities`, appliquée
à l'échelle de l'appareil et non plus du transport.

#### Ce que ça change dans le code (2026-08-26)

- `normaliserAppareil()` récolte le champ `feature` via `nettoyerCapacitesBrutes()` (nouvelle frontière
  d'assainissement : le JSON est **imbriqué dans une chaîne**, et chaque entrée est un couple
  `[valeur, drapeau]` dont seul le premier élément porte l'information) et l'expose sous la clé générique
  `capacites_brutes`, à destination **exclusive** de `capacitesAppareil()` — même statut que
  `trame_controle` / `trame_running`.
- `capacitesAppareil()` retire les modes exclus du catalogue **et publie `modes_exclus` à part**. Cette
  séparation est nécessaire : `smartclim::appliquerCapacites()` doit distinguer « cet appareil ne sait pas
  chauffer » (retirer, même d'un profil déjà stocké) de « ce scan n'a rien détecté » (ne rien toucher).
- `smartclim::appliquerCapacites()` : `modes_exclus` est **la seule exception** à la règle « un profil ne
  s'ampute jamais ». Sans elle, le `HEAT` stocké avant l'existence de la restriction survivrait
  indéfiniment à sa correction — la migration est donc automatique au premier scan, sans script.
- `smartclim::masquerCommandesModes()` : les commandes d'action d'un mode qui **quitte** le profil sont
  masquées (`isVisible = 0`), **jamais supprimées** (CLAUDE.md), et **uniquement à la transition** — un
  masquage rejoué à chaque scan écraserait le choix d'un utilisateur qui l'aurait réaffichée.

### 3.4 `deviceMutex` PRÉDIT le comportement de l'application — validation croisée du 2026-08-26

Vitesses proposées par l'application AUX Home, relevées mode par mode sur l'unité de recette :

| Mode | Vitesses proposées |
|---|---|
| Froid | faible, moyen, fort, **auto** |
| Ventilation | faible, moyen, fort (**pas d'auto**) |
| Déshumidification | **grisé** (aucune vitesse) |

Ces trois observations étaient **déjà écrites** dans le dump de `deviceMutex`, et s'y lisent une par une :

| Observation | Règle correspondante dans `deviceMutex` |
|---|---|
| déshumidification : vitesse grisée | `air_con_func.specs["2"].showMutex` contient `wind_speed` (masquer), **et** `wind_speed.toastMutex` contient `{"toast":"…dehumidify_fan_alert","value":"2","key":"air_con_func"}` (message si tentative) |
| ventilation : pas d'auto | `air_con_func.specs["6"].controlMutex` : `{"control":[{"value":"0","key":"wind_speed"}],"value":"4","key":"wind_speed"}` — en mode 6, une vitesse à `4` (auto) est **forcée à `0`** (faible) |
| froid : les 4 vitesses | aucune règle ne les retire en mode `1` |

**Ce que cette concordance verrouille**, et ce n'est pas rien pour un protocole entièrement reverse-engineeré :

- ✅ les **codes de mode** : `2` = déshumidification, `6` = ventilation, `1` = froid — confirmés par le
  comportement observé, pas seulement par un libellé de table ;
- ✅ les **codes de vitesse** : `4` = auto, `0` = faible — mêmes preuves ;
- ✅ la **sémantique du moteur de règles** : `showMutex` masque, `controlMutex` force une valeur,
  `toastMutex` interdit avec message. Trois familles, trois effets distincts, vérifiés.

`deviceMutex` cesse donc d'être une curiosité : c'est une **spécification exécutable de l'IHM
constructeur**, et elle est exacte. Toute question future du genre « telle fonction est-elle permise dans
tel mode ? » s'y répond sans matériel.

#### Conséquence : `turbo` et `silence` ne sont PAS des vitesses dans ce modèle

`wind_speed.specs` liste bien 8 codes, dont `3` 静音 (silence) et `5` 强力 (puissance). Mais
`air_con_func.controlMutex` remet à zéro des clés nommées **`silence`** et **`turbo`**, distinctes de
`wind_speed`. Autrement dit le backend traite turbo et silence comme des **fonctions à part**, ce qui
explique que le sélecteur de vitesses de l'application n'en propose que 4 là où la table en compte 8.

⚠️ **À ne pas transformer en exclusion** : l'absence de « turbo » du sélecteur n'est pas une preuve que
l'appareil ne sait pas faire turbo — c'est un **découpage d'IHM**. C'est exactement la différence avec
`coolType` (§ 3.3), où le nom du champ corroborait le comportement observé. Une exclusion
`windSpeed = 2 ⇒ pas de turbo` serait une corrélation sur un seul appareil, sans corroboration : elle
retirerait une vitesse peut-être supportée. Non retenue.

Note au passage : `VITESSE_TURBO` et `VITESSE_SILENT` sont aujourd'hui modélisées comme des **valeurs du
concept vitesse** dans `smartclimCapabilities`. Le modèle du backend suggère plutôt des **commandes
booléennes séparées** — piste pour le domaine post-MVP 04, pas une correction du MVP (le code d'écriture
`wind_speed = 5` reste dans la table du transport et n'a jamais été démenti).

#### Gating par mode : contrat acquis, exploitation ajournée

Le plugin propose aujourd'hui toutes les vitesses du profil quel que soit le mode courant. Effets réels,
mesurés au regard des règles ci-dessus : en déshumidification une commande de vitesse est **ignorée**, en
ventilation `auto` est **ramenée à faible** par l'appareil. Inerte, jamais dangereux — et jamais un mode
faux.

Le corriger demanderait un profil de capacités **par mode** (aujourd'hui plat) et des commandes d'action
conditionnelles, ce qui dépasse le MVP. Le contrat, lui, est acquis et vérifié : à traiter au domaine
post-MVP 04, ou en UC08 si la recette le juge gênant. ⚠️ Toute exploitation de `deviceMutex` devra gérer
le séparateur `&&` des clés composées (§ 3.2).

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
| `screen` / `screen_on_off` | afficheur | `0` arrêt, `1` marche, **`2` capteur de luminosité** | ⚠️ nom exact incertain (`screen` dans `deviceMutex`, `screen_on_off` dans l'état CN) — codes ✅ **déclarés par le backend** (§ 4.4) |
| `sleep_mode`, `clean`, `healthy`, `anti_fungus`, `eco` | fonctions de confort | `0` / `1` | ✅ noms **et** codes **déclarés par le backend** (§ 4.4) ; ⚠️ acceptation par `v2/control` non vérifiée |
| `ultra_silence` | ultra-silence | ⚠️ **`1` = arrêt, `2` = marche** — *pas* `0`/`1` | ✅ déclaré par le backend ; forme de `specs` différente des autres (§ 4.4) |

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

✅ **TRANCHÉ le 2026-08-26 par le backend lui-même** (sonde de diagnostic →
`getConfig?id=deviceMutex` → `configContent.wind_speed.specs`). Cette branche est une **liste ordonnée
dont l'index EST le code**, avec les libellés constructeur :

| Code | `valueN` | Sens | Générique SmartClim |
|---|---|---|---|
| 0 | 低档 | bas | `VITESSE_LOW` |
| 1 | 中档 | moyen | `VITESSE_MEDIUM` |
| 2 | 高档 | haut | `VITESSE_HIGH` |
| 3 | 静音 | silence | `VITESSE_SILENT` |
| 4 | 自动 | auto | `VITESSE_AUTO` |
| 5 | 强力 | puissance | `VITESSE_TURBO` |
| 6 | 中低 | moyen-bas | `VITESSE_MEDIUM_LOW` |
| 7 | 中高 | moyen-haut | `VITESSE_MEDIUM_HIGH` |

C'est **exactement** la table EU (`com.zwegersit.auxairco::AuxFanSpeed`) : la colonne `intent` de
`smartclimCapabilities` était juste sur les 8 valeurs, et ses `intent_confirme` sont passés à `true`. La
table CN (`ha-aux-a-plus`) est **écartée pour ce backend**.

⚠️ **Ne pas surinterpréter** : ceci confirme la numérotation d'**ÉCRITURE** (`wind_speed` d'un `intent`).
Le codage **LU** dans la trame HVAC (colonne `fil`) est une numérotation **différente**, que cette table
ne documente pas — les `fil => null` restent null. Confondre les deux est précisément le défaut que la
table de correspondance existe pour empêcher.

La décision de fond ne change pas : la table reste une **donnée** et non du code, et
`getConfig?id=deviceMutex` est la source de vérité quand un doute revient (cf.
`smartclim-modele-abstrait-capacites.md`).

### 4.4 Fonctions de confort : le backend déclare noms, codes **et** conditions (2026-09-04)

Établi au cycle UC01 du domaine post-MVP 04, en repassant le dump complet de
`getConfig?id=deviceMutex` (rapport de sonde du 2026-08-26). Ce que ce passage a changé : les intentions de
confort n'étaient jusque-là qu'une liste de noms au statut ⚠️ « source unique, valeurs non vérifiées ».
Chaque entrée de `configContent` porte en réalité `key` (le nom d'intent), `keyN` (le libellé constructeur)
et `specs` (**les valeurs admises**) — c'est-à-dire la même autorité que celle qui a tranché `wind_speed`
au § 4.3.

⚠️ **Deux pièges de forme** : `screen` a **trois** codes (le 3ᵉ, `2` = 智能光感, est un mode « capteur de
luminosité » qui sort de tout modèle booléen), et `ultra_silence` déclare ses `specs` comme un
**dictionnaire à clés `"1"`/`"2"`** au lieu d'une liste — d'où un sens inversé par rapport à l'intuition
(`1` = arrêt). Ne jamais présumer `0`/`1` pour une fonction de confort.

**Les règles de disponibilité, plus structurantes encore que les codes** — lues dans `showMutex` /
`controlMutex` / `off_function_to_support` (moteur décrit au § 3.4) :

| Clé | Refusée / masquée quand |
|---|---|
| `sleep_mode` | `on_off=0` · `air_con_func ∈ {0,6}` · `sleep_diy=1` · `electric_lock=1` |
| `healthy` | `on_off=0` · `electric_lock=1` |
| `eco` | `on_off=0` · `air_con_func ∈ {0,2,4,6}` (donc **froid seul**) · `power_limit ∈ {1,2,3}` |
| `clean`, `anti_fungus` | **`on_off=1`** (`off_function_to_support`) · `electric_lock=1` |
| `screen` | `electric_lock=1` |
| `ultra_silence` | `air_con_func ∈ {0,2,6}` · `eight_heat=2` |

Trois conséquences de conception, toutes actées dans le code du plugin :

1. ⚠️ **`clean` et `anti_fungus` sont des fonctions de l'état ARRÊT** : `on_off.specs[1].showMutex` les
   masque quand l'appareil est allumé, et allumer **force `clean=0`**. Leur ordre ne doit donc **jamais**
   porter `power => 1` — dérogation à la règle générale du projet, qui est scopée « mode ou consigne ».
2. `sleep_mode` et `healthy` exigent l'appareil **allumé** ⇒ leur ordre porte bien `power => 1`.
3. `air_con_func.controlMutex` **remet à 0** `sleep_mode`, `eco`, `silence` et `turbo` à chaque changement
   de mode — mais **pas** `healthy`, `clean`, `anti_fungus`, `screen`. Règle d'IHM que le plugin ne
   réimplémente pas : divergence de confort avec l'application constructeur, jamais un état faux.

**`ultra_silence` est une fonction DISTINCTE de la vitesse « silencieux », pas un alias** — question de
périmètre ouverte depuis le brief, ici close : elle a sa propre entrée `configContent`, et son
`controlMutex` **pilote** `wind_speed = 3` plus `eco = 0` / `ai_eco = 0`. Un alias n'aurait pas à commander
la vitesse. Elle est donc distincte **mais couplée** à `VITESSE_SILENT`.

**Aucun signal par appareil pour ces fonctions.** Le dump a été passé au filtre « existe-t-il une règle de
visibilité indexée sur `deviceSupport` / `appSupport` / `healthPlus` / `use_type` pour une fonction de
confort ? » → **aucune** ; la seule règle de ce type concerne `temperature` (masquée si `deviceSupport` ne
contient pas `37`). Et `feature.screen = "1"` ne prouve rien dans un sens ni dans l'autre. ⇒ **au-delà de
la longueur de trame, rien ne permet de savoir si un appareil donné supporte une fonction de confort** —
ne pas rouvrir ce chantier sans un second appareil de référence.

## 5. En-tête `country` : cause d'échec de login documentée

> Le `country` (ISO-3) était figé à `NLD` jusqu'à ce qu'un utilisateur slovaque échoue au login avec
> « identifiant ou mot de passe incorrect » malgré des identifiants valides. La crypto étant indépendante
> de la région, l'explication retenue est que **le backend route la requête vers un jeu de clés / une table
> utilisateurs régionale** à partir de cet en-tête. ⚠️ (`com.zwegersit.auxairco/lib/auxcloud/constants.ts`,
> commentaire de `TIMEZONE_TO_COUNTRY`)

**Décision SmartClim** : exposer le pays comme **clé de configuration plugin** (`auxhome_country`),
choisi dans une **liste déroulante** des pays d'Europe (portée par
`smartclimAuxHomeApi::paysDisponibles()`), avec un **défaut constant** `FRA`
(`smartclim::PAYS_DEFAUT`). Un échec de login doit **explicitement suggérer de vérifier le pays** dans le
message d'erreur.

> ⚠️ **Amendé en recette, 2026-08-25** : la conception d'origine déduisait ce défaut du fuseau horaire de
> Jeedom (`config::byKey('timezone')`) via la table `TIMEZONE_TO_COUNTRY` de la référence, portée telle
> quelle. **Abandonné** : le fuseau d'une installation domotique ne dit rien du pays d'un **compte cloud**
> — une installation française réglée sur `Europe/Brussels` se voyait proposer `BEL`, et l'échec de login
> qui s'ensuit porte justement le message trompeur décrit ci-dessus. La table et `paysParDefaut()` ont été
> supprimées du plugin (la correspondance fuseau → pays reste un fait exact, elle n'est simplement pas un
> indice fiable du pays d'un compte). Cf. `.memory/specs/MVP/01-configuration-plugin.md`
> § « Décisions actées ».

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

**Bits des fonctions de confort** (ajout du 2026-09-04, cycle UC01 du domaine post-MVP 04) — offsets dans
l'espace **charge HVAC nue**, celui de `smartclimFrame` :

| Concept | Lecture | Composition de l'octet en écriture |
|---|---|---|
| sommeil | `(octet[15] >> 2) & 1` | `octet[15] = mode<<5 \| sleep<<2` |
| ioniseur | `(octet[18] >> 1) & 1` | `octet[18] = power<<5 \| health<<1 \| clean<<2` |
| nettoyage | `(octet[18] >> 2) & 1` | idem |
| afficheur | `(octet[20] >> 4) & 1` | `octet[20] = display<<4 \| mildew<<3` |
| anti-moisissure | `(octet[20] >> 3) & 1` | idem |

Statut : ✅ **vérifié dans trois codes sources lus**, lecture *et* écriture concordantes aux mêmes octets —
`liaan/broadlink_ac_mqtt` (`classes/broadlink/ac_db.py`, `get_ac_states()` / `set_ac_status()`),
`fparrav/homebridge-aux-cloud` (MIT) et `azadaydinli/ac_freedom` (les deux derniers déjà cités dans
`smartclim-transport-broadlink-lan.md` §§ 5.2/5.4, à l'espace « réponse LAN », soit **−2**).
⚠️ **Jamais observé variant sur une trame réelle** : l'unique échantillon disponible vient d'un appareil
**éteint**, tous ces bits à 0 — ce qui est *cohérent* avec `on_off.specs[0].controlMutex` (qui force
`sleep_mode=0`, `healthy=0`, `eco=0` à l'extinction), donc **ni contradiction ni preuve**.

⚠️ **Confirmation de l'identité des deux espaces d'offsets** : `ac_db.py` déclare une charge de `0x19` =
**25 octets**, soit exactement le compte de la trame cloud `status.control` relevée sur l'appareil de
recette. Les octets 15/18/20 y sont donc tous lisibles. Recoupement utile, la « divergence » d'offsets
entre références n'étant qu'un décalage de préfixe (cf. le § 3.3 et
`smartclim-transport-broadlink-lan.md`).

⚠️ **`eco` n'est décodé par AUCUNE des quatre implémentations lues** (les trois ci-dessus plus
`com.zwegersit.auxairco`, dont `parseControlState` ne lit que marche, mode, consigne, vitesse et
oscillation). Son bit, s'il existe, est **inconnu** — c'est pourquoi la fonction « Éco » n'est pas livrée :
sans lecture, aucune commande info ne peut refléter son état.

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
- [x] ~~Table `wind_speed` réelle (8 valeurs ?)~~ → **confirmée par `deviceMutex`** le 2026-08-26,
      identique à la table EU (§ 4.3). Reste à valider l'ADÉQUATION vitesse ↔ ressenti sur l'appareil.
- [x] ~~Noms et valeurs exacts des intentions de confort~~ → **déclarés par le backend** le 2026-09-04
      (§ 4.4), avec leurs conditions de disponibilité. ⚠️ Reste ouvert : **l'endpoint `v2/control`
      accepte-t-il ces clés ?** Aucune implémentation tierce n'en implémente une seule — une clé déclarée
      dans `deviceMutex` ne prouve pas qu'elle soit commandable. Replis à essayer dans l'ordre :
      `screen_on_off`, puis `POST /app/device/control` sans `v2`.
- [ ] **Bits de lecture des fonctions de confort** (octets 15/18/20, § 6.1) : concordants sur trois
      implémentations mais **jamais vus varier** — le seul échantillon vient d'un appareil éteint. À
      mesurer par `core/php/sonde-intent-auxhome.php` (diff d'octets), fonction par fonction, avant
      d'activer quoi que ce soit : c'est ce qui conditionne le passage de `'confirme' => false` à `true`
      dans `smartclimCapabilities::fonctionsConfort()`.
- [ ] **Existe-t-il un bit `eco` ?** Aucune des quatre implémentations lues ne le décode (§ 6.1). À
      chercher par diff d'octets tant que le matériel est disponible (`--intent=eco --valeur=1` puis `0`).
      Même question pour `ultra_silence` (`--valeur=2` puis `1`). Sans bit, ces deux fonctions restent
      hors périmètre.
- [x] ~~Où le backend expose-t-il les capacités RÉELLES d'un appareil donné ?~~ → **`feature`, dans
      chaque ligne de `/app/user_device`** (sonde du 2026-08-26, § 3.2). Aucune route nouvelle.
- [x] ~~`feature.mode` ou `feature.coolType` ?~~ → **`coolType = 1` ⇒ pas de chauffage** (l'application
      ne propose que froid / déshu / ventilation / auto). Implémenté en table d'EXCLUSIONS, § 3.3.
- [ ] Sens de `feature.coolType = 0` : réversible ? → un 2ᵉ appareil, réversible de préférence.
- [ ] Sens des index de `feature.mode` (5 déclarés pour 4 modes proposés) → un appareil déclarant une
      liste plus courte. Tant que c'est ouvert, ne rien construire dessus.
- [ ] `feature.windSpeed` (scalaire, `2` ici) : ni un identifiant de table (elle est unique et confirmée,
      § 4.3), ni un simple compte (l'application propose 4 vitesses en froid). Sens toujours inconnu — et
      surtout, **pas** un candidat à l'exclusion de `turbo`, qui est une fonction séparée dans le modèle
      du backend (§ 3.4).
- [ ] `wind_speed = 5` (turbo) et `= 3` (silence) sont-ils acceptés en écriture, alors que
      l'application expose ces fonctions ailleurs que dans le sélecteur de vitesses (§ 3.4) ? → recette
      UC06, une commande suffit à trancher.
- [ ] Gating des vitesses PAR MODE (rien en déshumidification, pas d'auto en ventilation) : contrat
      vérifié (§ 3.4), exploitation ajournée au domaine post-MVP 04 — demande un profil par mode.
- [ ] Table de référence des index de `feature.deviceSupport` / `appSupport` / `healthPlus` (sommaire de
      `configContent` ?) — matière du domaine post-MVP 04. ⚠️ **Négatif établi le 2026-09-04** : le dump a
      été passé au filtre « une règle de visibilité indexée sur ces champs existe-t-elle pour une fonction
      de confort ? » → **aucune** (§ 4.4). Il n'existe donc **aucun signal par appareil** pour ces
      fonctions au-delà de la longueur de trame ; inutile de rouvrir sans un second appareil.
- [ ] Schéma de `GET /app/getConfig?id=deviceMutex` et **façon d'en dériver les capacités d'un appareil
      donné** (par `modelId` ? par un champ de `/app/user_device` ?).
- [ ] `POST /app/device/control` (sans `v2`) existe-t-il en EU ?
- [ ] Durée de vie du jeton ; code d'erreur exact d'expiration.
- [ ] Existence d'un push (MQTT/WebSocket) EU.
- [ ] Séparation vertical/horizontal de l'oscillation dans `status.control` (octet 11 : `!= 0x20` ne
      suffit pas).
