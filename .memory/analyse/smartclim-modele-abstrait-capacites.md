# SmartClim — Modèle abstrait, capacités et tables de correspondance

> **Le cœur de l'architecture.** Traduit l'exigence du `.memory/brief.md` § 20
> (`Device → Capabilities → Generic AC API → Transport`) en un contrat interne précis.
>
> **Règle d'or** : la partie Jeedom (eqLogic, commandes, widgets, scénarios) ne connaît **que** le
> vocabulaire générique de ce fichier. Aucun code propriétaire (`ac_mode`, `air_con_func`, octet 15…) ne
> doit apparaître ailleurs que dans un adaptateur de transport.
>
> **Date** : 2026-08-24.

---

## 1. Les quatre couches

```text
┌─────────────────────────────────────────────────────────────┐
│ Jeedom : eqLogic smartclim + commandes (logicalId génériques)│
├─────────────────────────────────────────────────────────────┤
│ Generic AC API : lireEtat() / appliquer(EtatGenerique)       │
│                  + profil de capacités                       │
├─────────────────────────────────────────────────────────────┤
│ Tables de correspondance générique ↔ codes propriétaires     │
├─────────────────────────────────────────────────────────────┤
│ Transports : AUX_HOME · BROADLINK_LAN · AUX_CLOUD_LEGACY     │
│              (· AUXLINK_LAN à confirmer)                     │
└─────────────────────────────────────────────────────────────┘
```

Contrat minimal d'un transport (toute classe `smartclim<Transport>` l'implémente) :

| Méthode | Rôle |
|---|---|
| `decouvrir()` | renvoie une liste d'appareils normalisés (§ 5) |
| `sonder($appareil)` | l'appareil est-il joignable par ce transport ? (booléen, rapide, sans effet de bord) |
| `lireEtat($appareil)` | renvoie un **état générique** (§ 2) + horodatage de fraîcheur |
| `lireCapacites($appareil)` | renvoie un **profil de capacités** (§ 4) |
| `appliquer($appareil, $etatPartiel)` | applique un état générique partiel |

## 2. État générique

| Clé générique | Type | Unité / domaine |
|---|---|---|
| `online` | bool | |
| `power` | bool | |
| `mode` | énum | `AUTO`, `COOL`, `DRY`, `HEAT`, `FAN` |
| `targetTemperature` | float | °C, pas de 0,5 |
| `ambientTemperature` | float | °C |
| `fanSpeed` | énum | `AUTO`, `SILENT`, `LOW`, `MEDIUM_LOW`, `MEDIUM`, `MEDIUM_HIGH`, `HIGH`, `TURBO` |
| `verticalSwing` | bool | (position fine = extension) |
| `horizontalSwing` | bool | |
| `display` | bool | afficheur LED |
| `sleep` | bool | |
| `eco` | bool | |
| `health` | bool | ioniseur / purification |
| `mildew` | bool | anti-moisissure (séchage après arrêt) |
| `clean` | bool | auto-nettoyage |
| `childLock` | bool | |
| `comfortWind` | bool | |
| `auxHeat` | bool | chauffage d'appoint |
| `errorCode` | int/string | |

Champs **méta** (produits par le plugin, pas par l'appareil) : `transport` (transport ayant fourni la
valeur), `updatedAt` (horodatage), `stale` (donnée périmée).

> ⚠️ `SILENT`/`MUTE` et `TURBO` sont modélisés comme **vitesses de ventilation**, pas comme des
> interrupteurs séparés — c'est ainsi que les transports les portent (`ac_mark = 4/5` côté legacy, bits
> `mute`/`turbo` côté LAN). Une commande action « Turbo » reste possible côté Jeedom : elle pose
> `fanSpeed = TURBO`.

## 3. Tables de correspondance — la partie critique

> ⚠️ **Le piège n°1 de tout l'écosystème** : il existe **au moins trois numérotations différentes** pour
> `mode` et pour `fanSpeed`. Les mélanger produit un plugin qui « marche presque » (chauffe au lieu de
> refroidir). Ces tables doivent vivre **dans un seul fichier de définitions**, jamais dupliquées.

### 3.1 Modes

| Générique | AUX Home `air_con_func` | Fil HVAC (`status.control` oct.15 `>>5`, LAN oct.17) | Legacy `ac_mode` |
|---|---|---|---|
| `AUTO` | **0** | **0** | **4** |
| `COOL` | **1** | **1** | **0** |
| `DRY` | **2** | **2** | **2** |
| `HEAT` | **4** | **4** | **1** |
| `FAN` | **6** | **6** | **3** |

Sources ✅ : `com.zwegersit.auxairco/lib/auxcloud/constants.ts::AuxMode` (AUX Home) et
`legacyConstants.ts::LEGACY_MODE_TO_HOMEY` (legacy) ; `fparrav/src/api/broadlink/Protocol.ts::BroadlinkMode`
+ `AUX_MODE_TO_BROADLINK` (fil ↔ legacy) ; `azadaydinli/ac_freedom/const.py::AcMode` (fil).

> **AUX Home et le fil HVAC partagent la même numérotation.** Seul le cloud **legacy** en a une autre.
> ⚠️ Un commentaire de `maeek/ha-aux-cloud/api/const.py` affirme l'inverse (« *0=Auto, 1=Cool, 2=Dry,
> 3=Fan, 4=Heat* ») — il **contredit le code du même fichier** (`AC_MODE_COOLING = {ac_mode: 0}`,
> `AC_MODE_AUTO = {ac_mode: 4}`). Le **code** fait foi, confirmé par deux autres implémentations.

### 3.2 Vitesses de ventilation

| Générique | AUX Home `wind_speed` **[❓ à confirmer]** | Fil HVAC (oct.13 `>>5`) | Legacy `ac_mark` |
|---|---|---|---|
| `AUTO` | 4 | **5** | **0** |
| `SILENT` / mute | 3 | *(bit `mute` dédié, oct.14/16)* | **5** |
| `LOW` | 0 | **3** | **1** |
| `MEDIUM_LOW` | 6 | — | *(→ LOW)* |
| `MEDIUM` | 1 | **2** | **2** |
| `MEDIUM_HIGH` | 7 | — | *(→ HIGH)* |
| `HIGH` | 2 | **1** | **3** |
| `TURBO` | 5 | **4** | **4** |

Sources ✅ : `com.zwegersit.auxairco/constants.ts::{AuxFanSpeed, WIRE_FAN_TO_HOMEY}`,
`legacyConstants.ts::LEGACY_FAN_TO_HOMEY` ; `fparrav/Protocol.ts::BroadlinkFanSpeed`.

**Trois avertissements** :

1. La colonne AUX Home est **contestée** : `latentharbor/ha-aux-a-plus/climate.py` (backend CN) donne une
   table incompatible (`0`=silent, `1`=low, `2`=medium, `4`=high, `5`=turbo). Cf.
   `smartclim-transport-aux-home.md` § 4.3. → **à confirmer en recette**, ne pas figer en dur.
2. `MEDIUM_LOW` / `MEDIUM_HIGH` / `SILENT` **n'ont pas d'équivalent sur le fil** → écrivables mais
   **non relisables** en AUX Home. Le plugin conserve la dernière valeur commandée.
3. Côté LAN/legacy, `SILENT` passe par un **bit dédié** (`mute`) et non par la valeur de vitesse ; côté
   legacy c'est `ac_mark = 5`. La couche générique doit gérer les deux formes.

### 3.3 Oscillations

| Générique | AUX Home `up_down_swing` / `left_right_swing` | Fil HVAC (3 bits) | Legacy `ac_vdir` / `ac_hdir` |
|---|---|---|---|
| oscille | `0` ⚠️ | `0` ✅ | `0` ⚠️ **ou** `1` ⚠️ (contradiction, cf. `smartclim-transport-aux-cloud-legacy.md` § 5.1) |
| fixe | `7` ⚠️ | `7` ✅ | `1` ⚠️ |

### 3.4 Interrupteurs de confort

| Générique | AUX Home (intent) ⚠️ | Fil HVAC | Legacy |
|---|---|---|---|
| `display` | `screen` / `screen_on_off` ❓ | oct.20 bit 4 (écriture) / oct.22 bit 4 (lecture) | `scrdisp` |
| `sleep` | `sleep_mode` | oct.15 bit 2 / oct.17 bit 2 | `ac_slp` |
| `eco` | `eco` | — ❓ | `ecomode` |
| `health` | `healthy` | oct.18 bit 1 / oct.20 bit 1 | `ac_health` |
| `clean` | `clean` | oct.18 bit 2 / oct.20 bit 2 | `ac_clean` |
| `mildew` | `anti_fungus` | oct.20 bit 3 / oct.22 bit 3 | `mldprf` |
| `childLock` | ❓ | — | `childlock` |
| `comfortWind` | ❓ | — | `comfwind` |
| `auxHeat` | ❓ | — | `ac_astheat` |

Les noms AUX Home proviennent de la table `deviceMutex` citée au `.memory/brief.md` § 2 ; leurs **valeurs** ne sont
pas vérifiées ❓.

### 3.5 Échelles de température

| Transport | Consigne | Ambiante |
|---|---|---|
| AUX Home (intent) | **entier °C** ⚠️ (le backend CN utilise ×10 ❓) | — |
| AUX Home (`status.control`) | `(oct.10 >> 3) + 8` `+0,5` si `oct.12 & 0x80` | `status.running` : `oct.15 - 32` (entier) |
| LAN Broadlink | `(T - 8) << 3` + bit demi-degré | entier `oct.17 & 0x1F` (+32 si >63) + `oct.33/10` |
| Legacy | `temp` **×10** | `envtemp` **×10** |

> ⚠️ **Trois échelles différentes.** La couche générique manipule **toujours des °C flottants** ; la
> conversion est strictement locale à l'adaptateur.

### 3.6 Implémentation recommandée

Une classe `smartclimCapabilities` (fichier dédié, autoload) exposant :

- les énumérations génériques (constantes de classe) ;
- une table `TRANSPORT → { concept → { valeurGénérique → codePropriétaire } }` en **structure de données**
  (tableau PHP), pas en `switch` ;
- des helpers `versTransport($transport, $concept, $valeur)` / `depuisTransport(...)` qui renvoient `null`
  si la correspondance est absente (fonction non supportée) — **jamais** de valeur par défaut silencieuse ;
- ⚠️ i18n : les libellés lisibles (« Refroidissement », « Vitesse auto »…) sont posés **dans la table**
  via `__('Refroidissement', __FILE__)` — **littéraux**, jamais `__($variable)` (cf. `CLAUDE.md`, le
  sous-agent `translator` fait un scan statique).

## 4. Profil de capacités

**Définition** : ce que **cet appareil-ci** sait faire, via **ce transport-ci**.

```text
capacites = {
  "supported":   ["power","mode","targetTemperature","ambientTemperature","fanSpeed", ...],
  "modes":       ["AUTO","COOL","DRY","HEAT","FAN"],
  "fanSpeeds":   ["AUTO","LOW","MEDIUM","HIGH","TURBO"],
  "tempRange":   {"min":16,"max":32,"step":0.5},
  "readOnly":    ["ambientTemperature"],
  "writeOnly":   ["fanSpeed:MEDIUM_LOW","fanSpeed:MEDIUM_HIGH","fanSpeed:SILENT"],
  "source":      "AUX_HOME",
  "detectedAt":  "2026-08-24T10:00:00+02:00"
}
```

### 4.1 Comment le déduire, par transport

| Transport | Méthode de détection |
|---|---|
| `AUX_HOME` | intersection : concepts de `GET /app/getConfig?id=deviceMutex` ❓ (schéma à établir) ∩ champs décodables des trames `status.*` ∩ **retours d'écriture observés**. À défaut : profil par défaut « climatiseur générique ». |
| `AUX_CLOUD_LEGACY` | ✅ **le plus fiable** : un `get` avec `params: []` renvoie **exactement** les paramètres supportés par l'appareil (`smartclim-transport-aux-cloud-legacy.md` § 4). |
| `BROADLINK_LAN` | profil **fixe** : la trame a une disposition figée. Toutes les fonctions du § 3.4 marquées « oct. » sont supposées présentes. |

### 4.2 Bornes de température

Défaut `16` – `32` °C, pas `0,5` (`azadaydinli/ac_freedom/const.py::TEMP_MIN/TEMP_MAX/TEMP_STEP_HALF`) ✅.
En mode `HEAT` certains modèles descendent plus bas ❓. Les bornes doivent rester **surchargeables par
équipement** (configuration) : c'est le garde-fou le plus simple contre un modèle exotique.

### 4.3 Règles d'évolution du profil

1. Le profil est **persisté** dans la configuration de l'eqLogic (`capabilities`, JSON) avec sa date.
2. Il est **recalculé** à chaque découverte/scan, et au premier rafraîchissement suivant un changement de
   transport.
3. **Un profil qui s'enrichit crée des commandes ; un profil qui s'appauvrit ne supprime rien** — les
   commandes devenues sans objet sont marquées (par ex. renommées avec un préfixe ou passées en
   `isVisible = 0`) mais conservées, pour ne pas casser les scénarios, les widgets et l'historique de
   l'utilisateur. La suppression reste une action **manuelle et explicite**.
4. Le profil et le transport actif sont **affichés** dans la page de configuration de l'équipement
   (exigence `.memory/brief.md` § 4 et § 11).

## 5. Appareil normalisé (résultat de découverte)

```text
{ "mac", "nom", "marque"?, "modele"?, "ip"?,
  "transport", "identifiantTransport",     // deviceId | endpointId | mac
  "online", "capacites", "brut" }          // "brut" = charge utile d'origine, pour le débogage
```

Le rapprochement inter-transports se fait sur `mac` normalisée — voir
`smartclim-architecture-jeedom.md` § 4 pour la règle exacte et ses pièges.

## 6. À confirmer

- [ ] Table `wind_speed` réelle d'AUX Home (§ 3.2, contradiction ouverte).
- [ ] Échelle de `temperature` dans l'intent AUX Home (§ 3.5).
- [ ] Sens de `ac_vdir`/`ac_hdir` legacy (§ 3.3).
- [ ] Noms/valeurs des intentions de confort AUX Home (§ 3.4).
- [ ] Schéma de `deviceMutex` et dérivation d'un profil **par appareil** (§ 4.1).
- [ ] Bornes de température par mode.
