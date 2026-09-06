# Spec technique — UC02 « Oscillations verticale et horizontale distinctes »

> **Domaine** : post-mvp/04-fonctions-avancees · **Spec fonctionnelle** : `02-oscillations-fines.md`
> **Patron structurel** : `01-interrupteurs-de-confort-tech.md` (UC01 du même domaine) — cette UC en est la
> réplique quasi exacte, avec **un** mécanisme neuf (le marqueur `lecture`).
> **Dépend de** : UC04/05/06/07 du MVP, UC02 et UC03 du domaine post-mvp/01 (`smartclimFrame`), UC01 de ce
> domaine.
> **Plan validé par l'utilisateur le 2026-09-06**, après challenge advisor : les 3 findings `major` et les
> 4 `minor` de la revue de plan sont **intégrés ci-dessous**, ils ne sont pas de la dette.

## 0. Ce que fait cette UC, en une phrase

Ajouter deux concepts génériques d'oscillation (`swing_v`, `swing_h`), pilotables **séparément** sur les
deux transports, en réutilisant **intégralement** les quatre cycles déjà en place (profil → commandes info
→ commandes action → grâce/état optimiste) et l'instrument de mesure CLI livré par l'UC01.

**Aucune classe nouvelle, aucun fichier nouveau.** Six fichiers modifiés. Livré **désactivé**.

## 1. L'arbitrage central — AC5 sans exception à l'invariant

### 1.1 La tension, et pourquoi elle n'est qu'apparente

`CLAUDE.md` pose un invariant catégorique : « **Une clé absente de l'état ne touche pas sa commande** […]
Ne jamais le remplacer par une valeur de repli. » Or AC5 demande d'afficher une valeur **non confirmée**
(le dernier état commandé). Contradiction frontale — en apparence.

Les deux énoncés ne parlent pas du même chemin :

- l'invariant interdit de **substituer un repli à une lecture manquante**, dans `decoderEtat()` /
  `appliquerEtat()` — c'est-à-dire sur le chemin de **lecture** ;
- AC5 demande une valeur écrite depuis le chemin d'**écriture**.

Ce sont deux écritures de **sources différentes** vers la même commande, et le plugin possède déjà le
mécanisme : `executerCommandeAction()` appelle `appliquerEtat($ordreApplique, true)`
(`smartclim.class.php:3597-3600`, état optimiste). Avec `'lecture' => false`, `decoderEtat()` ne produit
**jamais** la clé `swing_v`/`swing_h` ; la lecture ne peut donc **jamais** écraser la valeur optimiste.
La valeur affichée est la dernière commandée, indéfiniment, **par construction**.

**Vérifié en revue de plan, chemin par chemin** — aucun de ces chemins ne peut produire ni vider la clé
tant que `lecture => false` : le cycle cron (`decoderEtat()`), `basculerHorsLigne()` (`:2133`, ne pousse
que `online`), `appliquerCapacites()`, le scan LAN (`appliquerLectureLan()` `:904-910`).

### 1.2 Options pesées et écartées

- **Réutiliser `CLE_CACHE_ORDRES` / `filtrerEtatSelonOrdres()`** — inutile **et inopérant**. Ce cache dure
  `DUREE_GRACE` = 60 s et ne sert qu'à **retirer** une clé d'un état scruté divergent. L'état scruté ne
  contenant jamais la clé, `filtrerEtatSelonOrdres()` fait `array_key_exists() === false → continue` : il
  ne fait rien. La mémoire d'ordres expire seule, sans fuite ni effet de bord. Ce n'est **pas** le porteur
  de l'affichage.
- **Un mécanisme dédié aux oscillations** (repli en `configuration`, réécriture périodique) — **rejeté** :
  il créerait la **première** exception à l'invariant, pour un besoin déjà couvert.

### 1.3 Ce que devient la valeur dans le temps

Elle n'est pas dans le cache d'ordres, elle est dans le **cache de valeur de la commande info**
(`cmd::event()` via `checkAndUpdateCmd()`), au même titre que `power` ou `mode`. Elle survit donc à
l'expiration des 60 s, au redémarrage et à un cycle cron.

⚠️ Elle ne survit **pas** à un « Vider le cache » de Jeedom — mais c'est vrai de **toutes** les commandes
info du plugin ; simplement, celles-ci ne se réalimenteront pas au cycle suivant.

⚠️ **À l'état initial (jamais commandé), la commande info est SANS VALEUR** : le widget `binary` natif
affichera « inactif », ce qui n'est pas « inconnu ». C'est le seul point où AC5 est en léger défaut, et il
se referme au premier ordre. **Documenté en risque R7, non corrigé** : poser une valeur initiale serait
exactement le repli que l'invariant interdit.

### 1.4 Prérequis mécanique à ne pas rater

`appliquerEtat()` itère sur `smartclimCapabilities::conceptsConnus()` — **pas** sur les clés de l'état
(`smartclim.class.php:4040-4047`). **Sans l'extension de `conceptsConnus()` par
`conceptsOscillationLivres()`, l'état optimiste ne serait jamais poussé et AC5 échouerait silencieusement.**

## 2. Contrats externes

### 2.1 Écriture cloud — `POST /app/device/v2/control` (endpoint existant, corps inchangé)

```json
{"intent": {"up_down_swing": 0, "on_off": 1}, "dst": 1, "deviceId": "<id>"}
```

Succès = `code == 200`.

**Codes déclarés par le backend lui-même** — `GET /app/getConfig?id=deviceMutex` →
`data.configContent.<clé>.specs`, dump versionné
`.memory/analyse/smartclim-diagnostic-20260826-152439.json`, `routes[2]` :

| Code | `valueN` | Sens |
|---|---|---|
| `0` | 开启 | **balayage (oscille)** |
| `1`–`5` | 定格1 … 定格5 | **positions figées** |
| `7` | 关闭 | **arrêt (fixe)** |

- `up_down_swing.specs` est un **dictionnaire** à clés `0,1,2,3,4,5,7` — le code `6` est **absent**.
- `left_right_swing.specs` est une **liste** de 8 entrées : `0` 开启, `1`–`3` 定格1-3, **`4` 开启
  (doublon)**, `5` 定格5, `6` 定格4, `7` 关闭. ⚠️ Anomalie de la source (doublon + inversion 5/6) ; sans
  effet sur `0`/`7`.

✅ **Le sens `0` = oscille / `7` = fixe cesse d'être une hypothèse.** Il était marqué douteux (source unique
`ha-aux-a-plus/climate.py::set_swing_mode`) dans `smartclim-transport-aux-home.md` § 4.2 et
`smartclim-modele-abstrait-capacites.md` § 3.3 ; il est désormais **déclaré par le backend**, même autorité
que celle qui a clos `wind_speed` (§ 4.3) et les fonctions de confort (§ 4.4).

⚠️ **Reste non corroboré** : que `v2/control` **accepte** ces clés. Aucune implémentation EU ne les
implémente, et le backend **CN** route l'oscillation par `POST /app/device/control` **sans `v2`**
(`ha-aux-a-plus/api.py::_control`). Risque **R2**, repli identique à celui d'UC01.

### 2.2 Conditions de disponibilité — preuve double

| Source dans le dump | Contenu |
|---|---|
| `configContent.up_down_swing.toastMutex` et `.left_right_swing.toastMutex` | refus si `electric_lock=1` ; refus si **`on_off=0`** (`common_device_control_open_use_function`) |
| `configContent.on_off.specs[0].showMutex[0].func` | contient **`up_down_swing`** et **`left_right_swing`** — masqués quand l'appareil est éteint |

⇒ **Les deux axes exigent l'appareil ALLUMÉ.** L'ordre `ON` porte donc `power => 1`, exactement comme
`mode_*` (MVP) et `sleep`/`health` (UC01). L'ordre `OFF` n'en porte **jamais** — mêmes motifs qu'UC01 :
désactiver une fonction ne doit pas allumer l'appareil. Conséquence assumée : « Désactiver » sur un
appareil éteint sera **refusé par le backend**, avec le message déjà livré par UC01. Aucun littéral
nouveau.

### 2.3 `controlMutex` — non réimplémenté

Commander une oscillation force côté backend `comfort_wind=0`, `wind_smart=0`, `wind_ring=0`,
`wind_light=0`, `wind_light2=0`, `wind_flow=1`, `ai_ctl=0`, `zero_wind_feeling=0`, `wind_child=0` ; et sous
`comfort_wind=1 && wind_speed=0 && air_con_func ∈ {1,6} && deviceSupport=15`, force `wind_speed=1`. Aucun
de ces concepts n'existe dans le plugin. Même arbitrage que **R6 d'UC01** : divergence d'IHM avec
l'application constructeur, **jamais un état faux**.

⚠️ Coquille backend connue : `left_right_swing.controlMutex` écrit `useType` là où tout le reste écrit
`use_type` — ne pas « corriger » en lisant.

### 2.4 Lecture — offsets dans la charge HVAC nue (espace `smartclimFrame`)

| Concept | Octet | Masque | Décalage | Statut |
|---|---|---|---|---|
| `swing_v` | **10** | `0x07` | 0 | ✅ **concordant lecture/écriture** |
| `swing_h` | **11** | `0xE0` | 5 | ⚠️ **hypothèse forte** (voir 2.5) |

Sources :

- **Écriture** — `fparrav/homebridge-aux-cloud/src/api/broadlink/Protocol.ts::buildCommandPayload` (**MIT**) :
  octet 10 = `(consigne − 8) << 3 | vertical (3 bits)`, octet 11 = `horizontal << 5`. Consigné
  `smartclim-transport-broadlink-lan.md` § 5.4.
- **Lecture** — `azadaydinli/ac_freedom` (**sans licence, lecture seule**), espace « réponse LAN » :
  `octet[12] & 0x07` (= charge 10, **concorde** pour le vertical) et `octet[13] & 0x07` (= charge 11
  **bits 2-0**, **diverge** pour l'horizontal). Divergence ouverte au § 13.4 de
  `smartclim-transport-broadlink-lan.md`.

### 2.5 Ce que tranche la trame réelle — et jusqu'où seulement

Trame du 2026-08-26, `routes[0]`, `status.control` =
`bb00070000000f0001115be000a0002000000000000000d14d` (25 octets = 23 de charge + 2 de somme) :

- octet 10 = `0x5b` → consigne `(0x5b >> 3) + 8` = **19 °C** *et* bits 2-0 = **3** = 定格3, code **valide**
  de `specs`. Cohérent.
- octet 11 = `0xe0` → bits 7-5 = **7** = 关闭 (arrêt), code **valide** et cohérent avec un appareil
  **éteint** (octet 18 = `0x00` ⇒ `power = 0`). L'interprétation « bits 2-0 » donnerait `0` = 开启
  (balayage) sur un appareil éteint : **incohérent**.

⇒ La divergence se tranche **provisoirement** en faveur des **bits 7-5** pour l'axe horizontal.

⚠️⚠️ **Statut exact, et il ne doit pas être surestimé** (finding `minor` de la revue de plan, retenu) :
**un seul échantillon**, sur un appareil **éteint**, et l'argument est une **inférence de cohérence, pas
une mesure**. C'est exactement le motif de `'lecture' => false` et de **R1**. Le § 13.4 de
`smartclim-transport-broadlink-lan.md` doit être amendé en **« hypothèse forte retenue provisoirement,
à confirmer par la mesure § 11 étape 4 »**, et **jamais** en « refermée » avant cette mesure.

⚠️ En revanche, la démonstration suivante, elle, est **solide** et l'amendement est ferme :
`com.zwegersit.auxairco::parseControlState` fait `octet[11] != 0x20` = « oscillation active ». Sur la
trame réelle, `0xe0 != 0x20` → « active », alors que le champ déclare 关闭. **Cette heuristique est
fausse** ; c'est une limite de **cette implémentation**, **pas du protocole**. C'est ce point qui invalide
la prémisse d'AC4 dans la spec fonctionnelle (§ « À confirmer », renvoyant à
`smartclim-transport-aux-home.md` §§ 6.1/9) : **AC4 est atteignable**, contrairement à ce qu'elle affirme.

### 2.6 Aucun signal de capacité PAR APPAREIL

Filtre appliqué au dump : « existe-t-il une règle de visibilité indexée sur `deviceSupport` / `appSupport`
/ `healthPlus` / `use_type` pour un axe d'oscillation ? » → les seules `showMutex` citant un swing sont
`on_off.specs[0]` et `electric_lock.specs[1]`, **aucune** n'est indexée sur un champ de `feature`. Le bloc
`feature` de l'appareil de recette ne contient aucun champ d'oscillation. **Négatif établi**, identique à
celui d'UC01 § 2.3. Ne pas rouvrir sans un second appareil.

### 2.7 Legacy AUX Cloud — hors périmètre

`ac_vdir` / `ac_hdir`, contradiction `0` vs `1` ouverte au § 5.1 de
`smartclim-transport-aux-cloud-legacy.md`. Hors périmètre (domaine 03). Mentionné pour une seule raison :
la modélisation retenue (booléen générique + codes par concept) rend son ajout **purement déclaratif** le
jour venu.

## 3. Architecture — fichiers

Tous les fichiers PHP ci-dessous : **2 espaces, CRLF** (respecter l'existant fichier par fichier).

| Chemin | État | Ce qui y entre |
|---|---|---|
| `core/class/smartclimCapabilities.class.php` | modifié | 2 constantes `CONCEPT_SWING_*` ; **`fonctionsOscillation()`** ; `conceptsOscillation()` / `conceptsOscillationLivres()` / `conceptsOscillationRelus()` / `fonctionOscillation()` ; `conceptsConnus()` étendu ; replis de `libelleConcept()` et `libelleCommande()` |
| `core/class/smartclimFrame.class.php` | modifié | **`champsOscillation()`** (publique) ; **`conceptsOscillables()`** ; `longueursMinimales()` : **3ᵉ boucle** ; `decoderEtat()` : boucle oscillation filtrée ; `champsEcriture()` : lignes d'oscillation **dérivées** (§ 5.2.4) ; `encoderOrdre()` : branche `'oscillation'`. ⚠️ **`conceptsLisibles()` reste INCHANGÉE** |
| `core/class/smartclimAuxHomeApi.class.php` | modifié | `intentionsAuxHome()` : +2 lignes `'nature' => 'oscillation'` ; `appliquerOrdre()` : branche `'oscillation'` ; `capacitesAppareil()` : fusion avec `conceptsOscillables()` |
| `core/class/smartclimBroadlinkLan.class.php` | modifié | `capacitesAppareil()` : **même** fusion, strictement symétrique |
| `core/class/smartclim.class.php` | modifié | `definitionsCommandesInfo()` : +2 entrées (ordres 25/26) ; `definitionsCommandesAction()` : bloc oscillation (ordres 50-53) ; **`creerCommandesInfo()` : réalignement ciblé du `name`** (§ 5.5.3) |
| `core/class/smartclimDiagnostic.class.php` | modifié | `texteTrameHvac()` : section « champs d'oscillation » lue depuis `champsOscillation()`, **aucun offset en dur** |
| `core/php/smartclim.inc.php` | **inchangé** | Aucune classe annexe nouvelle ⇒ aucun `require_once`. ⚠️ Si l'implémenteur crée malgré tout une classe, il **doit** l'y déclarer — l'oubli est une panne runtime invisible à `php -l` **et** à la CI. **Recommandation : ne pas créer de classe.** |
| `core/php/sonde-intent-auxhome.php`, `core/php/commande-lan.php` | **inchangés** | Aiguillages purs. `--concept=swing_v --valeur=1 --allumer` et `--intent=up_down_swing --valeur=3` fonctionnent **sans une ligne** dès que les tables sont remplies (`sonde-intent-auxhome.php:115` coerce `--concept` en booléen, `:121` laisse `--intent` brut) |
| `core/template/{dashboard,mobile}/cmd.action.other.etat.html` | **inchangés** | Les branches `_off` / `_on` d'UC01 couvrent déjà les quatre `logicalId`, et le test `_off` **précède** `_on` dans les deux fichiers (vérifié) |
| `core/config/*.ini`, `plugin_info/**`, `desktop/**`, `core/ajax/**` | **inchangés** | Aucune clé de config, aucune dépendance, aucun champ de formulaire, **aucune surface web nouvelle** |

## 4. Server vs Client

**Tout côté serveur.** Aucune ligne de JavaScript, aucune action AJAX, aucun champ de formulaire, aucune
sortie HTML nouvelle, **aucun nouveau point d'échappement**. Les deux templates de widget sont inchangés.

L'ordre envoyé est construit **entièrement** côté serveur à partir du `logicalId`, validé contre
`definitionsCommandesAction()` ; `$_options` n'est **jamais lu** pour ces commandes (seule la consigne le
lit). C'est ce qui tient l'exigence « aucune valeur non supportée » **hors** de l'interface.

## 5. Signatures

### 5.1 `smartclimCapabilities`

```php
const CONCEPT_SWING_V = 'swing_v';
const CONCEPT_SWING_H = 'swing_h';
```

Ces valeurs **sont** les `logicalId` des commandes info, et sont exactement celles déjà actées dans
`smartclim-architecture-jeedom.md` §§ 5.1/5.2. Stables par contrat.

#### 5.1.1 La table

```php
private static function fonctionsOscillation()
// -> array<concept, array{libelle:string, confirme:bool, lecture:bool,
//                         code_actif:int, code_fixe:int, ordre:int}>
```

| Concept | `libelle` | `confirme` | `lecture` | `code_actif` | `code_fixe` | `ordre` |
|---|---|---|---|---|---|---|
| `swing_v` | `__('Oscillation verticale', __FILE__)` | **false** | **false** | 0 | 7 | 50 |
| `swing_h` | `__('Oscillation horizontale', __FILE__)` | **false** | **false** | 0 | 7 | 52 |

- **`confirme`** — gouverne l'**exposition** (profil + commandes info + commandes action). Sémantique
  **identique** à celle de `fonctionsConfort()`.
- **`lecture`** — gouverne le **décodage** (`decoderEtat()`). **Neuf, et indépendant** : c'est lui qui
  matérialise la frontière AC4 / AC5.
- **`code_actif` / `code_fixe`** — codes propriétaires **partagés** par l'intent cloud et le fil HVAC (les
  deux valent 0/7).
- **`ordre`** — base d'affichage : `ON` = `ordre`, `OFF` = `ordre + 1`.

#### 5.1.2 Docblock obligatoire — la famille des marqueurs passe de TROIS à CINQ

À écrire **dans le fichier**, pas seulement ici, avec une **mention croisée** dans le docblock de
`fonctionsConfort()` :

| Marqueur | Lu ? | Rôle |
|---|---|---|
| `intent_confirme` (`tables()`) | **jamais** | purement **déclaratif** — note de traçabilité |
| `'fil' => null` (`tables()`) | oui | **fait de protocole** : aucune correspondance de lecture n'existe |
| `'confirme'` (`fonctionsConfort()`) | oui | **exposition** |
| `'confirme'` (`fonctionsOscillation()`) | oui | **exposition** |
| `'lecture'` (`fonctionsOscillation()`) | oui | **décodage** |

⚠️ **Deux invariants à écrire dans ce docblock** (finding `minor` retenu) :

1. `lecture => true` **implique** `confirme => true`. Appliqué dans `conceptsOscillationRelus()`, qui est
   le **seul** consommateur — c'est suffisant, mais c'est un **invariant**, pas un détail
   d'implémentation.
2. `code_actif`/`code_fixe` **et `lecture`** sont modélisés **transport-agnostiques**. Aujourd'hui
   inoffensif (les deux transports partagent `decoderEtat()`), mais AC4/AC5 sont formulés « sur un
   transport où… » : **si un transport diverge un jour, introduire une dimension « transport » dans la
   table — jamais un second littéral ailleurs.**

#### 5.1.3 Accesseurs

```php
public static function conceptsOscillation()          // -> les 2, toujours
public static function conceptsOscillationLivres()    // -> confirme === true
public static function conceptsOscillationRelus()     // -> confirme === true ET lecture === true
public static function fonctionOscillation($_concept) // -> colonnes, ou array() si inconnu
```

- `conceptsConnus()` → 6 historiques + `conceptsConfortLivres()` + `conceptsOscillationLivres()`.
  **Prérequis d'AC5** (§ 1.4).
- `libelleConcept()` → repli sur le libellé **nu** (consommé par `profilAffichable()`).
- `libelleCommande()` → repli sur `sprintf(__('%s (état commandé)', __FILE__), $libelle)` **si
  `lecture === false`**, libellé **nu** sinon. C'est **le porteur d'AC5**.
  ℹ️ `libelleCommande()` n'a qu'**un seul appelant**, `definitionsCommandesInfo()` : ce repli n'a aucun
  effet de bord ailleurs.

### 5.2 `smartclimFrame`

#### 5.2.1 La table d'offsets

```php
public static function champsOscillation()
// -> array<concept, array{trame:string, octet:int, masque:int, decalage:int}>
// swing_v => (controle, 10, 0x07, 0)   swing_h => (controle, 11, 0xE0, 5)
```

Publique (comme `champsBinaires()`) : second consommateur `smartclimDiagnostic::texteTrameHvac()`.
**Non filtrée** par les marqueurs — des offsets restent des offsets.

⚠️ **Commentaire croisé obligatoire** entre `champs()` (octet 10 = consigne, bits 7-3),
`champsBinaires()`, `champsEcriture()` et `champsOscillation()`. Masques vérifiés **disjoints** :
octet 10 → consigne `0xF8` contre `swing_v` `0x07` ; octet 11 → `swing_h` `0xE0`, aucun autre écrivain.

#### 5.2.2 ⚠️ Le point d'entrée du profil — avec sa garde explicite (correction `major` n° 2)

```php
public static function conceptsOscillables($_trameControle, $_trameLongue) // -> array<int,string>
```

Concepts de `conceptsOscillationLivres()` dont la trame de contrôle couvre l'octet requis
(`swing_v` → 11 octets, `swing_h` → 12). **Seul** point d'entrée du profil pour les oscillations ; appelée
par les **deux** `capacitesAppareil()`.

⚠️⚠️ **Garde en tête de méthode, non négociable :**

```php
if (empty(self::conceptsLisibles($_trameControle, ''))) { return array(); }
```

⚠️ **La trame longue est passée VIDE, délibérément** (correction du 2026-09-06, review qualité du tour 1 —
la première rédaction de cette spec passait `$_trameLongue` et était **fausse**). Les deux offsets
d'oscillation vivent dans la trame de **contrôle** ; or `conceptsLisibles()` peut être non vide par la
seule trame **longue** (`CONCEPT_AMBIENT_TEMP`, seuil 16 octets), et `lireEtat()` lit les deux trames
indépendamment. En passant les deux, une trame de contrôle de **11 octets** accompagnée d'une trame longue
valide **franchissait la garde** — exactement ce que cette garde existe pour empêcher. Le seuil effectif
doit être celui de la **trame de contrôle** (13 octets).

**Pourquoi.** `conceptsLisibles()` est lue par `smartclimBroadlinkLan::lireEtat()` (`:403-404`) pour
produire `STATUT_ETAT_LU`, **seul garde-fou de la création d'équipement depuis le LAN** (UC04 du domaine
post-mvp/01, arbitré avec l'utilisateur le 2026-09-03). Son seuil est de **13 octets**. Y ajouter les
oscillations l'abaisserait à **11** et **affaiblirait ce garde-fou** — d'où le choix de ne pas la toucher.

Mais ne pas la toucher **ne suffit pas** : `appliquerLectureLan()` (`smartclim.class.php:904`) appelle
`appliquerCapacites(smartclimBroadlinkLan::capacitesAppareil($lecture))` sur tout équipement **déjà
rapproché**, **quel que soit** le statut de lecture. Sans cette garde, une trame de 11 octets (appareil
Broadlink non-climatiseur, réponse tronquée) suffirait à faire entrer `swing_v` dans `concepts` — et
l'union d'`appliquerCapacites()` (`:3017`) est **irréversible** (aucun équivalent de `modes_exclus` pour
les concepts, dette D4 d'UC01).

Cette garde remplace l'asymétrie *implicite* du plan initial par un **seuil explicite** : `conceptsLisibles()`
garde son seuil de 13 octets **et** aucune trame de contrôle courte ne peut peupler un profil. Elle rend
inutile le commentaire d'asymétrie qu'il aurait fallu poser dans deux méthodes.

⚠️ **Leçon à retenir pour les UC suivantes** : réutiliser un prédicat existant comme garde ne suffit pas —
il faut vérifier **sur quelles entrées** il se prononce. `conceptsLisibles()` répond « cet appareil expose
des concepts lisibles », **pas** « la trame de contrôle est exploitable » : deux questions voisines dont
une seule était la bonne ici.

#### 5.2.3 Longueurs et décodage

```php
private static function longueursMinimales()  // champs() UNION champsBinaires() UNION champsOscillation()
```

⚠️ **Troisième boucle distincte.** Rappel du piège déjà documenté : `champs()` porte `'octets'`
(**pluriel**, liste d'indices), `champsBinaires()` et `champsOscillation()` portent `'octet'`
(**singulier**). Confondre les deux schémas casse silencieusement le calcul de longueur, donc les
garde-fous de trame courte.

```php
public static function decoderEtat($_transport, $_trameControle, $_trameLongue)
```

\+ une boucle sur `champsOscillation()`, ne retenant un concept **que s'il figure dans
`conceptsOscillationRelus()`** et que la trame est assez longue :

```php
$etat[$concept] = ((($octet & $masque) >> $decalage) === $codeActif) ? 1 : 0;
```

⚠️ **Une position figée (1-5) est rendue « inactif »** — c'est **vrai** (le volet ne balaie pas) et c'est
le seul mappage honnête d'un champ à 7 valeurs sur un booléen. À dire dans le docblock.

⚠️ **Une clé absente reste absente** : c'est l'invariant, et c'est ce qui tient AC5. **Ne jamais
substituer 0.** `decoderEtat()` continue de ne jamais lever et de ne poser ni `online` ni `source`.

#### 5.2.4 ⚠️ Écriture — lignes DÉRIVÉES, jamais retapées (correction `major` n° 3)

```php
private static function champsEcriture()
public static function encoderOrdre($_transport, $_trameControleLue, array $_ordre)
```

⚠️⚠️ **Les lignes d'oscillation de `champsEcriture()` sont CONSTRUITES depuis `champsOscillation()`**
(boucle ajoutant `'oscillation' => true`), **jamais recopiées à la main.**

**Pourquoi.** Contrairement à `champsBinaires()` (schéma `octet`/`bit`, non dérivable),
`champsOscillation()` porte **exactement** le schéma de `champsEcriture()` (`octet`/`masque`/`decalage`).
Retaper `10 / 0x07 / 0` et `11 / 0xE0 / 5` ferait qu'une faute de frappe écrirait **silencieusement les
mauvais bits** — sur le chemin LAN, **non recettable** (R3). Une source d'offsets, pas deux.

Branche `'oscillation'` dans `encoderOrdre()` :

```php
$code = $valeurGenerique ? fonctionOscillation($concept)['code_actif'] : fonctionOscillation($concept)['code_fixe'];
```

puis la ligne de masquage **générique existante** (`smartclimFrame.class.php:531`) applique
`masque`/`decalage`. Motif identique à celui de la branche `'binaire'` : ces concepts sont **absents de
`tables()`**, donc `versTransport()` renverrait `null` et **chaque** commande LAN lèverait `TYPE_INTERNE`.

✅ **Commutativité avec la consigne, vérifiée** : `encoderConsigne()` fait
`($_octet10 & 0x07) | (((entier − 8) & 0x1F) << 3)` (`:457`) — elle **préserve** les bits 2-0. Les deux
patches de l'octet 10 sont donc indépendants de l'ordre d'itération de `$_ordre`. ⚠️ **À re-vérifier
explicitement si `encoderConsigne()` est un jour retouchée.**

✅ **Liste blanche `conceptsEncodables()`** : elle dérive de `champsEcriture()` (`:431-433`), donc elle
s'élargit **mécaniquement**. C'est **nécessaire** — sans cela, une entrée d'oscillation en mémoire d'ordres
ferait lever `TYPE_INTERNE` sur **toutes** les commandes LAN pendant 60 s (le commentaire de
`valeursCommandees()`, `:3808`, anticipe littéralement ce cas en nommant « oscillation, domaine
post-mvp/04 »). ℹ️ Vérifié en revue : `champsEcriture()` n'étant **pas** filtrée par `confirme`,
`conceptsEncodables()` conserve `swing_*` même si `confirme` rebasculait à `false` — **le déploiement
transitoire est sûr dans les deux sens**.

### 5.3 `smartclimAuxHomeApi`

```php
private static function intentionsAuxHome()  // + 2 lignes
// swing_v => array('cle' => 'up_down_swing',    'nature' => 'oscillation')
// swing_h => array('cle' => 'left_right_swing', 'nature' => 'oscillation')
```

**Seul endroit du plugin** où ces deux noms propriétaires apparaissent.

ℹ️ La colonne `'nature'` existe déjà (`:526-543`) avec les branches `booleen`/`table`/`temperature`
(`:568-609`). ⚠️ `'booleen'` n'est **pas** réutilisable (0/7 ≠ 0/1) : une branche neuve est bien nécessaire.

```php
public static function appliquerOrdre($_identifiantAppareil, array $_ordre)  // + branche 'oscillation'
// $valeurIntent = $valeur ? code_actif : code_fixe ;  $ordreApplique[$concept] = $valeur ? 1 : 0;
```

⚠️ **L'ordre appliqué rendu est GÉNÉRIQUE et BOOLÉEN (0/1), jamais le code propriétaire** — c'est lui qui
part en état optimiste et en mémoire d'ordres. Aucun changement de budget, de rejeu, de classement
d'erreur ni de purge de session.

```php
public static function capacitesAppareil(array $_appareil)
// concepts = array_values(array_unique(array_merge(
//   array(CONCEPT_ONLINE),
//   smartclimFrame::conceptsLisibles($ctrl, $long),
//   smartclimFrame::conceptsOscillables($ctrl, $long))))
```

ℹ️ **Commentaire à poser correctement** (finding `minor` retenu — le plan initial le justifiait faussement) :
`array_unique` est de la **défense en profondeur**. La déduplication **autoritaire** vit dans
`appliquerCapacites()` (`:3017`, `array_unique` puis `ordonnerParReference()` `:3110-3114`, **avant** la
comparaison `json_encode`). Ne pas écrire que le transport porte la déduplication : un futur lecteur le
croirait.

### 5.4 `smartclimBroadlinkLan`

`capacitesAppareil()` : **exactement la même** ligne de fusion. Aucune autre modification — `lireEtat()`,
`appliquerOrdre()`, `requete()`, la session et le verrou sont inchangés.

⚠️ Le profil LAN continue de publier `modes` et `vitesses` **vides** (le LAN ne peut **rien exclure**).
Il **peut** en revanche publier les concepts d'oscillation : contrairement aux modes, l'union n'y
réintroduit rien qui aurait été exclu sur preuve, puisque **aucune exclusion d'oscillation n'existe côté
cloud** (§ 2.6).

### 5.5 `smartclim`

#### 5.5.1 Commandes info

```php
private static function definitionsCommandesInfo()   // + swing_v (ordre 25), swing_h (ordre 26)
```

`subType` `binary`, `unite` `''`, `generic_type` `''`, `isHistorized` 0, `meta` false, **aucun**
`minValue`/`maxValue`. ✅ Bande 25/26 vérifiée libre (concepts 0-5, méta 6-7, confort 20-24).

#### 5.5.2 Commandes action

```php
private function definitionsCommandesAction()        // + bloc oscillation
```

Pour chaque concept de `conceptsOscillationLivres()` **présent dans `capacites['concepts']`**, deux
entrées, strictement calquées sur le bloc confort d'UC01 :

| Clé | `name` | ordre à envoyer | `ordreCmd` |
|---|---|---|---|
| `swing_v_on` | `sprintf(__('%s - Activer', __FILE__), $libelle)` | `array('swing_v' => 1, 'power' => 1)` | 50 |
| `swing_v_off` | `sprintf(__('%s - Désactiver', __FILE__), $libelle)` | `array('swing_v' => 0)` — **jamais** de `power` | 51 |
| `swing_h_on` | idem | `array('swing_h' => 1, 'power' => 1)` | 52 |
| `swing_h_off` | idem | `array('swing_h' => 0)` | 53 |

`subType` `other`, `infoLiee` = `<concept>`. Les gabarits `'%s - Activer'` / `'%s - Désactiver'` existent
depuis UC01 : **aucune clé i18n nouvelle ici**. Le libellé injecté est le libellé **nu** (sans « (état
commandé) »).

#### 5.5.3 ⚠️ Réalignement du nom de la commande info (correction `major` n° 1)

**Le problème.** Le nom d'une commande n'est posé qu'à la **création** : `creerCommandesInfo()` fait
`if (isset($existantes[$logicalId])) continue;` (`:3147-3150`). Or le protocole de recette impose
`confirme => true` **d'abord** (la commande est créée, avec le suffixe « (état commandé) »), puis
`lecture => true` **ensuite**. Sans correction, **tout appareil où AC4 finit par être satisfait afficherait
indéfiniment « (état commandé) » alors que la valeur est réellement relue**. C'est le **chemin nominal**,
pas un cas de bord — et **une indication fausse est pire qu'une indication absente**, ce qui est
exactement le critère qu'AC5 exige.

**La correction.** Dans `creerCommandesInfo()`, un réalignement **ciblé** du `name`, pour les seuls
concepts de `conceptsOscillation()` :

- ne renommer **que si** le nom courant est **exactement** l'autre variante (nu ↔ suffixé) — donc
  **jamais** un nom que l'utilisateur a personnalisé ;
- n'émettre un `save()` **que si** le nom change effectivement.

ℹ️ **Précédent identique déjà en place** : `realignerBornesConsigne()`, appelé sur commande existante dans
`creerCommandesAction()` (`:3359-3364`). ~15 lignes, **zéro littéral i18n nouveau**.

#### 5.5.4 Inchangé — et c'est le cœur du dividende

`ordreDeCommandeAction()`, `executerCommandeAction()`, `envoyerCommandeActionLan()`, `envoyerOrdreLan()`,
`appliquerEtat()`, `enregistrerOrdre()`, `filtrerEtatSelonOrdres()`, `creerCommandesAction()`,
`appliquerCapacites()`, `messageErreurAuxHome()`, `cron()`.

Les ordres d'oscillation sont des **maps statiques** : ils traversent la garde `isset($definitions[...])`,
la déduplication, `appliquerOrdre()`, l'état optimiste et la période de grâce **sans une ligne nouvelle**.
Le lien à l'info et le widget `smartclim::etat` sont posés génériquement et idempotemment par
`creerCommandesAction()`.

### 5.6 `smartclimDiagnostic`

```php
public static function texteTrameHvac($_avant, $_apres, array $_etatAvant, array $_etatApres)
```

\+ un bloc « champs d'oscillation » (concept, octet, masque, valeur 3 bits avant → après) construit depuis
`smartclimFrame::champsOscillation()`. **Aucun offset en dur, aucun masquage, aucune E/S.** Tous les octets
restent affichés — sans quoi un bit qui bascule ailleurs qu'attendu resterait invisible, ce qui est
précisément l'objet de la mesure.

## 6. Validation & classement des erreurs

| Ce qui est validé | Où | Type / message |
|---|---|---|
| `logicalId` de commande action | `definitionsCommandesAction()` — liste blanche dérivée du profil | `TYPE_INTERNE`, littéral **existant**. Un `execCmd()` de vieux scénario visant un axe absent du profil échoue **proprement** |
| Valeur de la bascule | **rien à valider** : map statique, `$_options` **jamais lu** | — |
| Concept sans ligne d'intent / d'écriture | `appliquerOrdre()` / `encoderOrdre()` | `TYPE_INTERNE`, messages **existants**. Impossible si les tables sont complètes ⇒ garde de non-régression |
| Trame de base trop courte (LAN) | `encoderOrdre()`, garde existante `>= 21 octets` | `TYPE_PROTOCOLE` + `CONTEXTE_BASE_ILLISIBLE`. Couvre déjà les octets 10 et 11 |
| Trame trop courte pour le **profil** | **`conceptsOscillables()`, garde § 5.2.2** | pas d'exception : le concept n'entre simplement pas au profil |
| Refus fonctionnel du backend (appareil éteint, `electric_lock=1`) | `requeteControle()` → `classerCodeMetier(..., TYPE_AUTH, CONTEXTE_ORDRE_REFUSE)` | Message **existant** d'UC01 : « Le service AUX Home a refusé la commande — cette fonction est peut-être indisponible dans l'état actuel de l'appareil ». **Exact** pour ce cas |
| Arguments CLI | inchangés | aucune modification des deux scripts |

**Sécurité** : aucune surface web nouvelle, aucun secret nouveau, aucune clé de cache nouvelle, aucun
fichier écrit, aucun `echo` nouveau. Le contenu d'une trame HVAC n'est ni journalisé ni persisté
(inchangé).

## 7. Impact i18n (français uniquement à l'implémentation)

**Trois littéraux nouveaux, tous dans `core/class/smartclimCapabilities.class.php`** :

- `'Oscillation verticale'` — dans `fonctionsOscillation()`
- `'Oscillation horizontale'` — dans `fonctionsOscillation()`
- `'%s (état commandé)'` — dans `libelleCommande()`

**Zéro littéral nouveau dans `smartclim.class.php`** : `'%s - Activer'`, `'%s - Désactiver'` et le message
de refus existent depuis UC01. Le réalignement du § 5.5.3 n'en introduit **aucun**.

Quatre points à ne pas rater :

- Chaînes **littérales** dans `__()`, **jamais** `__($variable)` : l'extraction i18n est un scan statique.
  Le libellé vit **dans la table**, pas au point d'usage.
- Aucun caractère supprimé par `cleanComponanteName()` du core (`& # ] [ % \ / ' " *`) dans ces libellés.
  ⚠️ Les **parenthèses** de `'%s (état commandé)'` ne figurent pas dans cette liste — **à re-vérifier au
  moment de coder** ; repli sans risque : `'%s - état commandé'`.
- **Un seul `__()` par fonction** : le libellé sert à la fois de libellé de concept et de base des noms de
  commandes.
- Pas de `'Actif'`/`'Inactif'` : les commandes info sont `binary` et gardent le widget natif du core.

Traduction `en_US` / `de_DE` / `es_ES` : **différée** au sous-agent `translator`, sur le code figé.

## 8. Périmètre

### 8.1 Couverture des critères d'acceptation

| AC | Statut |
|---|---|
| **AC1** | mécanisme **couvert**, livré **désactivé** — à valider en recette après mesure |
| **AC2 / AC3** | **couvert par construction** côté cloud (`intent` à une seule clé métier) ; côté LAN, `encoderOrdre()` recopie la trame et patche des masques **disjoints** sur des octets **distincts** — non recettable (matériel absent) |
| **AC4** | **atteignable** (§ 2.5 — la prémisse de la spec est fausse), livré `lecture => false`, à confirmer en recette |
| **AC5** | **couvert, sans exception à l'invariant** (§ 1), y compris la cohérence de l'indication après bascule (§ 5.5.3) |
| **AC6** | **trivialement satisfait** — voir écart § 8.2 |
| **AC7** | **couvert par construction** : `confirme` et `lecture` sont indépendants |

### 8.2 Écarts avec la spec fonctionnelle — à amender

Quatre écarts, tous assumés et validés par l'utilisateur le 2026-09-06 :

1. ⚠️ **L'objectif de la spec est faux** : « Le socle MVP (UC06) pilote l'oscillation des volets ».
   **Aucune commande de swing n'existe** dans le plugin (vérifié : aucune occurrence de `swing` dans
   `core/` hors commentaires d'anticipation). Cette UC n'ajoute pas un pilotage fin à un pilotage global,
   elle **introduit le pilotage d'oscillation tout court**. → amender § Objectif.
2. ⚠️ **AC6 porte sur une commande globale inexistante** — il n'y a **rien à régresser**. Ce qu'AC6 protège
   réellement ici : ne pas faire apparaître les commandes sur un appareil non confirmé, assuré par
   `confirme => false` **et** la garde du § 5.2.2. → amender AC6.
3. ⚠️ **AC1 dit « deux commandes action distinctes », le plan en livre quatre** (`_on`/`_off` par axe) —
   justifié par le patron UC01 (widget `smartclim::etat`, lien à l'info).
4. ⚠️ **La chaîne d'AC5 est substituée** : « État approximatif : relecture séparée non disponible sur ce
   transport » → le **nom de la commande info** porte `'%s (état commandé)'`. Le support d'indication
   retenu est le nom de commande, pas un texte d'aide séparé.

### 8.3 Hors périmètre

- Détection du profil de capacités → UC04 du MVP.
- Tuile dashboard des oscillations → domaine post-mvp/06.
- Legacy AUX Cloud (`ac_vdir`/`ac_hdir`) → domaine post-mvp/03.
- Le pilotage LAN passe toujours par la CLI ; `executerCommandeAction()` reste **cloud** (domaine
  post-mvp/02).

## 9. Risques

- **R1 — Le bit horizontal n'a jamais été vu varier.** Un seul échantillon, appareil éteint,
  `swing_h = 7`. La divergence lecture/écriture est tranchée par **cohérence, pas par mesure**. C'est *le*
  risque de l'UC et la raison de `'lecture' => false`. ⚠️ Méthodologie imposée : **jamais valider une
  formule d'octet sur moins de trois mesures**, et afficher **tous** les octets.
- **R2 — `v2/control` n'accepte peut-être pas ces clés.** Aucune implémentation EU ne les envoie ; le
  backend CN passe par `/app/device/control` **sans `v2`**. Replis, dans l'ordre :
  `--intent=up_down_swing` (échappatoire brute), puis `POST /app/device/control` (existence en EU **non
  vérifiée**, question ouverte d'UC01 toujours ouverte).
- **R3 — L'appareil de recette est un climatiseur PORTABLE.** Rien ne garantit qu'il possède un volet
  **horizontal** motorisé : AC2/AC3 pourraient être invérifiables sur cet axe. ⚠️ Dans ce cas, **ne pas
  activer `swing_h`** — l'entrée d'un concept dans un profil est irréversible (R11).
- **R4 — « Activer »/« Désactiver » écrase une position figée.** Le champ a **7 valeurs** et l'appareil de
  recette est **actuellement en 定格3** sur l'axe vertical : le cas n'est pas théorique. « Activer » écrira
  `0` et perdra la position réglée dans l'application constructeur. Analogue à R11 d'UC01. Non traité
  (dette **D1**). ⚠️ **Ne pas « corriger » en mémorisant/restaurant une position** : ce serait inventer un
  comportement que le backend ne décrit pas.
- **R5 — Un ordre sur un appareil éteint est refusé.** Les deux axes sont masqués quand `on_off = 0`.
  L'ordre `ON` porte `power => 1` et passe ; l'ordre `OFF` sera refusé, avec le message d'UC01.
- **R6 — `controlMutex` non réimplémenté** (§ 2.3) : divergence d'IHM, **jamais un état faux**.
- **R7 — Avant tout ordre, la commande info est sans valeur** et le widget `binary` affichera « inactif »,
  ce qui n'est pas « inconnu ». Se referme au premier ordre. Corriger reviendrait à poser un repli —
  précisément ce que l'invariant interdit.
- **R8 — Quatre tables partagent des octets.** Octet 10 : consigne **et** `swing_v`. Octet 11 : `swing_h`
  seul. Plus les octets 13/15/18/20 déjà partagés. Le **commentaire croisé** et la **dérivation** du
  § 5.2.4 sont ce qui protège la suite ; un octet écrit **en entier** au lieu d'être masqué casserait deux
  concepts d'un coup.
- **R9 — `conceptsLisibles()` conditionne un acte irréversible** (création d'équipement depuis le LAN).
  **Traité** par la garde explicite du § 5.2.2, qui la laisse inchangée.
- **R10 — Les commandes n'apparaissent qu'après un SCAN.** Le cycle cron est en lecture d'état seule et ne
  touche jamais les capacités (invariant UC07). Après mise à jour, tant qu'aucun scan n'est relancé, rien
  n'apparaît — **même avec `confirme => true`**.
- **R11 — Entrée d'un concept dans un profil : IRRÉVERSIBLE.** `appliquerCapacites()` unionne `concepts`
  sans équivalent de `modes_exclus` (dette **D4** d'UC01). C'est ce qui rend l'ordre « **mesurer, puis
  activer** » non négociable, et ce qui **interdit d'activer `swing_h` par extrapolation depuis
  `swing_v`**.
- **R12 — Ordre d'affichage** : `refresh` (`ordreCmd` 40) apparaîtra **avant** les quatre commandes
  d'oscillation (50-53), conformément à la réservation posée par UC01. Cosmétique ; ne pas renuméroter
  l'existant.
- **R13 — La sonde CLI consomme deux `listerAppareils()` par mesure.** Aucun quota documenté ; usage
  manuel. **À ne pas transformer en boucle.**

## 10. Dépendances

**Aucune.** Pas de paquet, pas de démon, pas de clé de configuration, pas de migration de données.
`plugin_info/packages.json` reste vide, `hasDependency` et `hasOwnDeamon` restent à `false`.

## 11. Recette — protocole de mesure, à exécuter avant d'activer quoi que ce soit

Sur le Jeedom, en SSH, depuis `<jeedom>/plugins/smartclim` :

1. `php core/php/sonde-intent-auxhome.php --equipement=<id> --etat` → référence (25 octets, champs
   d'oscillation, état décodé). **Attendu** : octet 10 bits 2-0 et octet 11 bits 7-5 affichés.
2. Appareil **allumé**, pour chaque axe : `--concept=swing_v --valeur=1 --allumer`, puis `--valeur=0
   --allumer`. Idem `swing_h`. Noter **(a)** le code métier renvoyé, **(b)** *quel* octet et *quels* bits
   basculent, **(c)** l'effet physique constaté sur les volets.
3. `'confirme' => true` **si et seulement si** l'ordre est accepté **et** un mouvement est constatable sur
   **cet** axe **sans** que l'autre bouge (AC2/AC3).
4. `'lecture' => true` **si et seulement si** le champ attendu bascule **dans les deux sens** aux
   octets/bits prévus. Si un autre octet bouge : **le corriger dans `champsOscillation()`**, et reporter
   dans les analyses. ⚠️ C'est cette étape, et elle seule, qui autorise à passer § 13.4 de
   `smartclim-transport-broadlink-lan.md` de « hypothèse forte » à « refermée » (§ 2.5).
5. **Investigation, tant que le matériel est disponible** : `--intent=up_down_swing --valeur=3` (position
   figée) — répond en une commande à la dette **D1** et confirme que le champ a bien 7 valeurs.
6. **AC1** : relancer un **scan** (le profil ne bouge qu'au scan, R10), vérifier l'apparition des seules
   commandes activées.
7. **AC5** : avec `lecture => false`, commander « Activer », attendre **plus de 60 s** (au-delà de la
   période de grâce), puis « Rafraîchir » — la commande info doit **rester** à « actif ». Test direct de
   l'arbitrage central du § 1.
8. **AC5, cohérence de l'indication** : après bascule `lecture => true` **puis** scan, vérifier que le nom
   de la commande info **ne porte plus** « (état commandé) » (§ 5.5.3).
9. **Garde de profil** : scan LAN sur un appareil Broadlink **non-climatiseur** (ou réponse tronquée) →
   vérifier qu'**aucun** équipement n'est créé **et** qu'**aucun** concept d'oscillation n'entre dans un
   profil existant (§ 5.2.2). Seul acte **irréversible** touché par cette UC.
   ⚠️ Cas à couvrir **explicitement**, car c'est celui qui a échappé à la première rédaction : trame de
   contrôle **courte** (11-12 octets) **accompagnée d'une trame longue valide** (≥ 16 octets). Une trame
   longue exploitable ne doit **jamais** suffire à faire entrer un concept d'oscillation dans un profil.
10. **LAN** : non recettable (l'appareil de validation ignore le protocole Broadlink).
    `php core/php/commande-lan.php --equipement=<id> --commande=swing_v_on` reste le point d'entrée, sans
    modification.

## 12. Dette

- **D1 — Positions figées non exposées.** Le champ porte 7 valeurs (`0` balayage, `1`-`5` positions, `7`
  arrêt) ; le plugin n'expose qu'un booléen. « Activer » écrase une position réglée ailleurs (R4).
  Candidat naturel à une commande `select` dans une UC ultérieure, **après** la mesure de l'étape 5.
- **D2 — `controlMutex` non réimplémenté** (§ 2.3, R6).
- **D3 — Pas de `concepts_exclus`.** Hérité d'UC01 (D4). Un concept entré à tort dans un profil n'en sort
  plus ; il n'existe pas d'équivalent de `modes_exclus` pour les concepts. C'est ce qui rend la garde du
  § 5.2.2 et l'ordre « mesurer puis activer » structurants plutôt que prudentiels.
- **D4 — Marqueurs transport-agnostiques.** `code_actif`/`code_fixe` et `lecture` ne portent pas de
  dimension « transport », alors qu'AC4/AC5 sont formulés par transport. Inoffensif tant que les deux
  transports partagent `decoderEtat()` (§ 5.1.2).
- **D5 — Documentation utilisateur.** `docs/` est encore le squelette du template : l'indication d'AC5
  repose entièrement sur le nom de la commande. Réécriture prévue au domaine post-mvp/07.
