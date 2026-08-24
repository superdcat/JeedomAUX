# SmartClim — Transport `AUX_CLOUD_LEGACY` (AC Freedom / AUX Cloud historique, infra Broadlink)

> **Transport post-MVP.** Cible les générations G1/G2 (cf. `smartclim-ecosysteme-aux-broadlink.md`).
> **Aucun appareil de test disponible** → développement contre les implémentations de référence, recette
> communautaire.
>
> **Statut** : ✅ = vérifié dans du code lu · ⚠️ = source unique · ❓ = à confirmer.
>
> **Sources de vérité** : `maeek/ha-aux-cloud` (**MIT**) — `custom_components/aux_cloud/api/{aux_cloud.py,
> aux_cloud_ws.py, const.py, util.py}` ; `fparrav/homebridge-aux-cloud` (**MIT**) —
> `src/api/{AuxCloudClient.ts, constants.ts}` ; `GijsZwegers/com.zwegersit.auxairco` (**MIT**) —
> `lib/auxcloud/legacy{Client,Constants}.ts`. Les trois sont cohérents entre eux (dérivés d'un même
> reverse engineering). `azadaydinli/ac_freedom/cloud_api/` est une reprise du même protocole mais
> **sans licence** → lecture seule.
>
> **Date** : 2026-08-24.

---

## 1. Endpoints et régions

| Région | Hôte API | Hôte WebSocket |
|---|---|---|
| Europe | `https://app-service-deu-f0e9ebbb.smarthomecs.de` | `wss://app-relay-deu-f0e9ebbb.smarthomecs.de` |
| USA | `https://app-service-usa-fd7cc04c.smarthomecs.com` | `wss://app-relay-usa-fd7cc04c.smarthomecs.com` |
| Chine | `https://app-service-chn-31a93883.ibroadlink.com` | `wss://app-relay-chn-31a93883.ibroadlink.com` |
| Russie | `https://app-service-rus-b8bbc3be.smarthomecs.com` | *(non listé)* ⚠️ `maeek` uniquement |

✅ (`maeek/aux_cloud.py`, `aux_cloud_ws.py` ; identique chez `fparrav` et `com.zwegersit.auxairco`).

**Ajouter une région = ajouter une entrée de table.** Aucune logique régionale ailleurs → exigence
`.memory/brief.md` § 6 satisfaite par conception.

> ⚠️ **Point de sécurité** : les trois implémentations de référence désactivent la vérification TLS
> (`ssl=False` chez `maeek`, `rejectUnauthorized` implicite chez `fparrav`). Le `.memory/brief.md` § 16 l'interdit
> explicitement. **Décision SmartClim : TLS vérifié.** Si un certificat de ces hôtes s'avérait invalide, la
> question remonte en `openQuestions` — on ne désactive pas la validation en silence.

**Routes** ✅ (toutes en `POST`) :

| Route | Rôle |
|---|---|
| `account/login` | authentification |
| `appsync/group/member/getfamilylist` | liste des familles (« homes ») |
| `appsync/group/room/query` | pièces d'une famille |
| `appsync/group/dev/query?action=select` | appareils d'une famille (corps `{"pids":[]}`) |
| `appsync/group/sharedev/querylist?querytype=shared` | appareils partagés (corps `{"endpointId":""}`) |
| `device/control/v2/querystate` | état en ligne/hors ligne (lot) |
| `device/control/v2/sdkcontrol?license=<LICENSE>` | **lecture ET écriture** des paramètres |

**Enveloppe** : succès = `status == 0` pour les routes `appsync`/`account` ; pour les routes
`device/control/*`, succès = `event.payload.status == 0` **et** `event.header.name == "Response"` ✅.

## 2. Authentification

Séquence ✅ (`maeek/aux_cloud.py::login`) :

```text
sha_password = sha1(password + PASSWORD_ENCRYPT_KEY)              # hex
payload      = {"email", "password": sha_password, "companyid", "lid"}   # JSON compact
token        = md5(payload_json + BODY_ENCRYPT_KEY)               # hex, en-tête "token"
ts           = time.time()                                        # flottant, en-tête "timestamp"
aes_key      = md5(ts + TIMESTAMP_TOKEN_ENCRYPT_KEY)              # 16 octets bruts
corps        = AES-128-CBC(zero padding, IV fixe, aes_key, payload_json)   # binaire brut
```

- `Content-Type: application/x-java-serialized-object` (le corps est **binaire**, pas du JSON).
- Constantes fixes de l'application (`PASSWORD_ENCRYPT_KEY`, `BODY_ENCRYPT_KEY`,
  `TIMESTAMP_TOKEN_ENCRYPT_KEY`, `LICENSE`, `LICENSE_ID`, `COMPANY_ID`, IV AES) : identiques dans les trois
  références MIT → à reprendre de `com.zwegersit.auxairco/lib/auxcloud/legacyConstants.ts` (le fichier le
  mieux commenté). **Non recopiées ici** : ce sont des constantes d'identité applicative, pas des secrets
  de l'utilisateur, mais elles n'ont rien à faire dans une note d'analyse.
- ⚠️ Piège : l'horodatage envoyé est le **flottant Python** (`time.time()`, ex. `1712345678.123456`) et il
  sert **tel quel** de graine au MD5. Une implémentation PHP doit reproduire exactement le même format de
  chaîne (`microtime(true)` formaté à l'identique) sinon la clé AES diffère ❓ **à valider**.

**Session** : la réponse fournit `loginsession` + `userid`, envoyés ensuite en **en-têtes** de chaque
requête ✅. Pas de refresh token ; sur échec → re-login + rejeu unique
(`com.zwegersit.auxairco/drivers/airco/device.ts`).

**En-têtes communs** ✅ : `licenseId`, `lid`, `language`, `appVersion`, `User-Agent`, `system`,
`appPlatform`, `loginsession`, `userid`, plus `familyid` sur les routes de groupe.

## 3. Découverte

```text
getfamilylist  → data.familyList[] : { familyid, name }
room/query     → data.roomList[]   : { roomid, familyid, name }
dev/query      → data.endpoints[]  : appareils
```

Champs d'appareil exploités ✅ :

| Champ | Usage |
|---|---|
| `endpointId` | identifiant de commande → config d'équipement `auxcloud_endpoint_id` |
| `mac` | **clé de rapprochement** → `logicalId` |
| `friendlyName` | nom de l'eqLogic |
| `productId` | type d'appareil (climatiseur / pompe à chaleur) |
| `devicetypeFlag` | requis dans l'enveloppe de commande |
| `devSession` | jeton de session par appareil, requis à chaque appel |
| `cookie` | base64 d'un JSON `{terminalid, aeskey, …}` → **recomposé** en `devicePairedInfo.cookie` |

⚠️ **Le `cookie` doit être décodé puis réencodé** dans un format différent
(`{"device":{"id":terminalid,"key":aeskey,"devSession","aeskey","did","pid","mac"}}` en base64) ✅
(`maeek/aux_cloud.py::_act_device_params`). C'est un passage obligé, non évident.

**Types de produits connus** ✅ (`productId`) :

- climatiseur générique : `000000000000000000000000c0620000`, `0000000000000000000000002a4e0000`
- pompe à chaleur : `000000000000000000000000c3aa0000`

> ⚠️ Ces listes sont **fermées** dans les références et provoquent un « appareil inconnu » pour tout
> nouveau `productId`. **Décision SmartClim** : traiter un `productId` inconnu comme un climatiseur
> générique (tentative de lecture des paramètres AC), et journaliser le `productId` pour enrichissement —
> conformément à l'exigence anti-whitelist du `.memory/brief.md` § 10.

**État en ligne** : `device/control/v2/querystate` en lot, `payload.data[].state` (`1` = en ligne) ✅.
Un seul appel pour tous les appareils.

## 4. Lecture / écriture des paramètres

Les deux passent par **`device/control/v2/sdkcontrol`** avec `payload.act` = `"get"` ou `"set"` ✅.

- **Lecture** : `params: []` (liste vide) → l'appareil renvoie **son jeu de paramètres par défaut**.
  C'est le mécanisme de **détection de capacités** le plus fiable de ce transport : les clés absentes
  correspondent à des fonctions non supportées.
- Certains paramètres exigent une requête séparée : `mode` (nécessaire pour obtenir `envtemp` sur certains
  modèles) est déclaré « paramètre spécial » et interrogé à part ✅ (`AC_SPECIAL_PARAMS`).
- Convention singulière : pour un `get` d'**un seul** paramètre, il faut envoyer
  `vals = [[{"val": 0, "idx": 1}]]` ⚠️.
- **Écriture** : `params = ["temp"]`, `vals = [[{"idx":1,"val":240}]]`.
- Réponse : `event.payload.data` est une **chaîne JSON** à re-parser, contenant `params[]` et `vals[]`
  parallèles → recomposer un dictionnaire ✅.

### 4.1 ⚠️ Toujours inclure `pwr` dans une commande cloud

> « *Without this, the cloud uses its cached `pwr=0` when building the device packet, turning the AC off on
> every mode/temp command sent concurrently with a `pwr=1` command.* »
> ✅ `fparrav/src/api/AuxDeviceControl.ts::sendCommand`

**Décision SmartClim** : toute commande sur ce transport embarque l'état `pwr` courant si l'appel ne le
définit pas explicitement.

## 5. Paramètres d'un climatiseur (clés JSON)

✅ (identiques dans les trois références) :

| Clé | Sens | Échelle / valeurs |
|---|---|---|
| `pwr` | marche/arrêt | 0/1 |
| `ac_mode` | mode | **0 COOL · 1 HEAT · 2 DRY · 3 FAN · 4 AUTO** |
| `ac_mark` | vitesse | **0 AUTO · 1 LOW · 2 MEDIUM · 3 HIGH · 4 TURBO · 5 MUTE** |
| `temp` | consigne | **×10** (`240` = 24,0 °C) |
| `envtemp` | ambiante | **×10** (`236` = 23,6 °C) |
| `ac_vdir` | oscillation verticale | 0/1 ⚠️ **contradiction, cf. § 5.1** |
| `ac_hdir` | oscillation horizontale | 0/1 ⚠️ idem |
| `ac_slp` | sommeil | 0/1 |
| `sleepdiy` | sommeil personnalisé | 0/1 |
| `ecomode` | éco | 0/1 |
| `ac_health` | santé / ioniseur | 0/1 |
| `ac_clean` | auto-nettoyage | 0/1 |
| `mldprf` | anti-moisissure | 0/1 |
| `scrdisp` | afficheur | 0/1 |
| `childlock` | sécurité enfant | 0/1 |
| `comfwind` | vent confortable | 0/1 |
| `ac_astheat` | chauffage d'appoint | 0/1 |
| `pwrlimit`, `pwrlimitswitch` | limitation de puissance | 0/1 + valeur |
| `err_flag`, `ac_errcode1` | erreurs | |
| `tempunit` | unité (1 = °C) | |
| `ac_tempconvert`, `new_type`, `tenelec` | inconnus ❓ | |

Pompe à chaleur ✅ : `ac_pwr`, `ac_temp`, `hp_pwr`, `hp_hotwater_temp`, `hp_water_tank_temp`,
`hp_auto_wtemp`, `hp_fast_hotwater`, `qtmode`, avec un `ac_mode` **différent** (0 AUTO, 1 COOL, 4 HEAT) et,
pour les modèles v3+, une température de ballon encodée dans `key_states` (`octet[2] - 32`) ⚠️.
→ **hors périmètre SmartClim** (le plugin cible les climatiseurs) ; à considérer comme extension.

### 5.1 ⚠️ Contradiction ouverte : sens de `ac_vdir` / `ac_hdir`

| Source | Interprétation |
|---|---|
| `maeek/ha-aux-cloud/api/const.py` | `AC_SWING_VERTICAL_ON = {ac_vdir: 1}` → **1 = oscille** |
| `fparrav/src/api/constants.ts` | `AC_SWING_VERTICAL_ON = {ac_vdir: 0}` + commentaire « *0 = oscilar, 1 = fijo* » → **0 = oscille** |
| Analogie LAN & AUX A+ | `Fixation.ON = 0`, `OFF = 7` ; `up_down_swing: 0` = oscille, `7` = fixe |

Le faisceau d'indices penche pour **0 = oscille**, mais la valeur « fixe » diffère (1 vs 7). **À confirmer
en recette.** En attendant : ne pas coder de constante « magique », passer par la table de correspondance
paramétrable (`smartclim-modele-abstrait-capacites.md` § 3).

## 6. Temps réel : WebSocket relay

✅ (`maeek/aux_cloud_ws.py`) :

```text
wss://app-relay-<region>/appsync/apprelay/relayconnect
```

- En-têtes = ceux de l'API + `CompanyId` + `Origin`.
- Message d'ouverture : `{"msgtype":"init","data":{"relayrule":"share"},"scope":{"loginsession","userid"},"messageid":"<epoch>000"}` → réponse `msgtype: "initk"`, `status: 0`.
- **Keep-alive** : `{"msgtype":"ping"}` **toutes les 10 s** → réponse `pingk`.
- Tout `status != 0` sur `initk`/`pingk` → fermer et reconnecter (boucle de 10 s).
- Les autres messages sont les **événements d'état poussés**. ❓ Leur schéma exact n'est pas décodé dans la
  référence (elle se contente de les diffuser à des écouteurs).

> C'est **le seul push confirmé de tout l'écosystème** accessible depuis un compte utilisateur. C'est donc
> **la justification n°1 d'un démon** — mais pour un transport qui n'est pas celui du MVP. Cf.
> `smartclim-daemon-choix.md`.

## 7. Limites & robustesse

- Scrutation : `fparrav` impose un intervalle **≥ 15 s**, défaut **30 s**, plafond 600 s ✅ ; `maeek`
  abandonne un appareil après **5 scrutations en échec** (`MAX_FAILED_POLLS`) ✅.
- Aucun quota documenté ❓ → rester conservateur (défaut 5 min côté Jeedom, cohérent avec `AUX_HOME`).
- `devSession` peut se périmer → il est relu à chaque `dev/query`, donc **toujours relister avant une
  série de commandes** si la session est ancienne ❓.
- La réponse de `sdkcontrol` peut être un « non-Response » (erreur) avec un `HTTP 200` → tester
  `event.header.name`.

## 8. À confirmer

- [ ] Sens exact de `ac_vdir`/`ac_hdir` (0 vs 1 vs 7).
- [ ] Format de l'horodatage servant de graine MD5 (flottant Python) reproductible en PHP.
- [ ] Schéma des messages poussés par le WebSocket relay.
- [ ] Validité des certificats TLS des hôtes `smarthomecs.*` (les références désactivent la vérification).
- [ ] Durée de vie de `loginsession` et de `devSession`.
- [ ] Comportement d'un `productId` inconnu sur un `get` à `params: []`.
