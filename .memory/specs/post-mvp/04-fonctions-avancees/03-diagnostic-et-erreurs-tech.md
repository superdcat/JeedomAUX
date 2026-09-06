# Spec technique — UC03 « Codes d'erreur, sécurité enfant et limitation de puissance »

> **Domaine** : post-mvp/04-fonctions-avancees · **Spec fonctionnelle** : `03-diagnostic-et-erreurs.md`
> **Dépend de** : UC01 de ce domaine (patron `fonctionsConfort()`, sonde `sonde-intent-auxhome.php`) et
> UC02 (patron `fonctionsOscillation()`, gabarit `nomSuffixe()`, voie d'entrée séparée au profil).
>
> **Arbitrages validés par l'utilisateur le 2026-09-06** (questions ouvertes du plan technique) :
> Q1 → volet « erreur » **reporté au domaine post-MVP 03**, rien n'est codé ;
> Q2 → `power_limit` **différée**, rien n'est codé ;
> Q3 → sécurité enfant livrée en **état commandé**, **avec confirmation d'action**.

## 0. Ce que fait cette UC, en une phrase

Elle livre **un seul concept** — la sécurité enfant (`child_lock`), **cloud AUX Home uniquement**,
**désactivée** (`confirme => false`) en attendant une mesure sur matériel — et **referme par la preuve**
les trois « À confirmer » de la spec fonctionnelle, dont deux par un **négatif établi** : sur le transport
MVP, il n'existe **aucun canal de lecture d'erreur**, et **aucun signal de capacité par appareil** pour la
sécurité enfant.

C'est la **troisième application** du patron « table de fonctions + marqueur de recette » des UC01 et UC02,
avec un mécanisme neuf que le plugin n'avait encore jamais eu : **une capacité sans aucun offset de trame**.

---

## 1. L'arbitrage central — livrer une capacité sans canal de lecture

### 1.1 La tension

L'AC6 de la spec fonctionnelle demande que la commande info « reflète cet état **après rafraîchissement** ».
C'est **structurellement impossible** ici : aucun bit de la trame HVAC ne porte la sécurité enfant (§ 2.3),
et la réponse cloud n'expose pas davantage la valeur. Le plugin peut **écrire** `electric_lock`, il ne peut
pas la **relire**.

### 1.2 Ce qui est retenu, et pourquoi ce n'est pas une entorse

Le mécanisme d'UC02 s'applique tel quel : la commande info porte l'**état commandé** (poussé par l'état
optimiste après un ordre accepté), et son nom porte le suffixe **« (état commandé) »** — gabarit
`smartclimCapabilities::nomSuffixe()`, **déjà existant**, aucun littéral nouveau.

⚠️ **L'invariant « une clé absente ne touche pas sa commande » est respecté sans exception** : l'état scruté
ne porte **jamais** `child_lock`, donc la lecture ne peut **jamais** écraser la valeur commandée. Ce n'est
pas un contournement de l'invariant, c'en est une **conséquence directe**.

### 1.3 Ce que l'utilisateur voit, et ce qu'il ne verra pas

- Tant qu'aucun ordre n'a été envoyé, la commande info est **sans valeur** — le widget `binary` affichera
  « inactif ». Effet **bénin** ici : une climatisation non verrouillée est le cas nominal. ⚠️ **Ne pas poser
  de valeur initiale** : ce serait exactement le repli que l'invariant interdit.
- Un déverrouillage fait **au panneau physique** ou **depuis l'application constructeur** ne se verra
  **jamais** dans Jeedom. C'est la limite assumée, et c'est ce que le suffixe du nom annonce.

### 1.4 Prérequis mécanique à ne pas rater

Identique au § 1.4 d'UC02, et tout aussi silencieux s'il est oublié : `appliquerEtat()` itère sur
`smartclimCapabilities::conceptsConnus()` (`core/class/smartclim.class.php:4134`), **et non sur les clés de
l'état**. Sans l'extension de `conceptsConnus()` par `conceptsProtectionLivres()`, l'état optimiste ne
serait **jamais** poussé et la commande info resterait éternellement vide — sans la moindre erreur.

---

## 2. Contrats externes

### 2.1 Écriture cloud — `POST /app/device/v2/control` (endpoint existant, corps inchangé)

Source : `GET /app/getConfig?id=deviceMutex` → `data.configContent.electric_lock`, dump versionné
`.memory/analyse/smartclim-diagnostic-20260826-152439.json`, `routes[2]`.

```
key = "electric_lock" · keyN = 童锁 (« verrou enfant »)
specs = [ {valueN: 关闭} , {valueN: 开启} ]   → codes 0 = arrêt, 1 = marche (BOOLÉEN)
toastMutex = [ { key: "on_off", value: "0", toast: "common_device_control_open_use_function" } ]
controlMutex = [ { control: [ { key: "ai_ctl", value: "0" } ] } ]
```

Corps émis : `{"intent":{"electric_lock":1,"on_off":1},"dst":1,"deviceId":"<id>"}`, succès = `code == 200`
dans l'enveloppe. Forme **déjà recettée** pour `mode_*`, `sleep`, `health`.

⇒ L'ordre **ON porte `power => 1`** (`allumer => true` dans la table) ; l'ordre **OFF n'en porte jamais**
(règle établie en UC01 et UC02, ne pas la remettre en cause ici).

### 2.2 ⚠️⚠️ L'effet de bord central, absent de la spec fonctionnelle

`electric_lock.specs[1].showMutex[0].func` masque **26 fonctions**, et — preuve plus forte, vérifiée clé
par clé dans le dump — les `toastMutex` des commandes de base portent **explicitement** le refus quand
`electric_lock = 1` :

| Clé backend | Concept du plugin | `toastMutex` sur `electric_lock = 1` |
|---|---|---|
| `on_off` | `power` | ✅ `common_device_control_lock_message` (**seule** règle de cette clé) |
| `temperature` | `target_temp` | ✅ (1ʳᵉ règle) |
| `air_con_func` | `mode` | ✅ (1ʳᵉ règle) |
| `wind_speed` | `fan_speed` | ✅ (1ʳᵉ règle) |
| `up_down_swing` / `left_right_swing` | `swing_v` / `swing_h` | ✅ |
| `screen`, `sleep_mode`, `healthy`, `clean`, `anti_fungus`, `power_limit` | concepts de confort | ✅ |

⇒ **Sécurité enfant active = toutes les commandes du plugin refusées.** `electric_lock` **n'est pas** dans
sa propre liste : « Désactiver » reste possible.

⚠️ **Impasse théorique, à connaître** : appareil **éteint** ET **verrouillé** ⇒ `on_off` est refusé par le
verrou, `electric_lock` est refusé par `on_off = 0` — **aucune sortie depuis Jeedom**. Peu atteignable en
pratique (notre ordre ON allume l'appareil), mais réelle. ⚠️ **Ne pas « corriger » en ajoutant `power => 1`
à l'ordre OFF** : `on_off` est lui-même refusé par le verrou, la mitigation serait **illusoire** et
introduirait une écriture non désirée.

⚠️ **Statut de cette contrainte** : `toastMutex` décrit une règle d'**IHM** de l'application constructeur.
Que le **backend** l'applique aussi est **plausible mais non prouvé** — à trancher par la mesure (§ 11,
point 3). Les deux réponses sont structurantes et mauvaises différemment : s'il l'applique, le plugin peut
se verrouiller lui-même ; s'il ne l'applique pas, le verrou physique et le pilotage cloud divergent.

### 2.3 Lecture — **le canal n'existe pas** (négatif établi)

**Aucun bit de trame HVAC.** Quatre implémentations de référence lues, aucune ne décode un verrou :

| Source | Ce qui a été vérifié |
|---|---|
| `liaan/broadlink_ac_mqtt` → `classes/broadlink/ac_db.py` | `get_ac_states()` décode 14 champs (`temp`, `power`, `fixation_v/h`, `mode`, `sleep`, `display`, `mildew`, `health`, `fanspeed`, `ifeel`, `mute`, `turbo`, `clean`) ; `get_ac_info()` n'ajoute que l'ambiante. **Aucun** champ verrou/puissance/défaut |
| `fparrav/homebridge-aux-cloud` → `src/api/broadlink/Protocol.ts` (MIT) | parsing 32 octets, mêmes octets 10-20 ; aucune mention de `childLock`, `powerLimit`, `error`, `fault` |
| `azadaydinli/ac_freedom`, `com.zwegersit.auxairco` | déjà consignés dans `.memory/analyse/smartclim-transport-broadlink-lan.md` §§ 5.2/5.4 et `smartclim-transport-aux-home.md` § 6.1 — même jeu de champs |

`childlock` existe bien en **paramètre JSON du cloud legacy**, jamais dans une trame — autre transport,
autre contrat (domaine post-MVP 03).

### 2.4 Aucun signal de capacité PAR APPAREIL

Filtre appliqué au dump complet — « existe-t-il une règle indexée sur `deviceSupport` / `appSupport` /
`healthPlus` / `use_type` / `faultSupport` pour `electric_lock` ou `power_limit` ? » → **aucune**. Les seules
règles indexées sur `feature` concernent `ai_eco`, `air_con_func` et `eco` (valeurs `34`, `38`, `!37`), plus
une dizaine de `use_type`.

**Négatif établi**, identique à UC01 § 2.3 et UC02 § 2.6. ⚠️ **Ne pas rouvrir sans un second appareil de
référence** : c'est la limite d'AC8 (§ 8.2).

### 2.5 Volet « erreur » — reporté (Q1 validée)

Recherche menée et close, trois sources, **rien** :

1. **Trame HVAC** : aucun champ d'erreur dans les quatre implémentations du § 2.3.
   ⚠️ **Piège à ne pas confondre** : le `err = response[0x22] | (response[0x23] << 8)` de `ac_db.py` est le
   **code d'erreur du protocole Broadlink** (paquet `0x38`), déjà traité par le plugin depuis l'UC01 du
   domaine post-mvp/01. Il dit si l'**échange réseau** a réussi — **jamais** si le climatiseur est en défaut.
2. **Réponse cloud** : les 22 champs d'une ligne de `GET /app/user_device?getStatus=1` réénumérés depuis le
   dump (`deviceId, productKey, mac, did, alias, subDevice, sn, modelId, suitType, useType, password,
   feature, deviceMainUri, userTag, wash, address, transport, customerFuncs, thirdDid, online,
   status{running,control,type}, passcode`). **Aucun champ de défaut, d'alarme ou de code d'erreur.**
3. **`deviceMutex`** : table de contrôle, aucune entrée de diagnostic.

**Le seul indice, et il ne suffit pas** : `feature.faultSupport = ["0","0"]` — couple `[valeur, drapeau]`,
drapeau `0` = valeur scalaire (convention `smartclim-transport-aux-home.md` § 3.2). Lecture retenue,
**hypothèse** : « cet appareil ne remonte pas de défaut ». Mais le sens de `faultSupport = 1` est inconnu, et
**même à `1` on ne saurait pas OÙ lire le code** — c'est un drapeau de capacité, pas un canal. Il n'apparaît
nulle part ailleurs dans le dump, et aucune règle ne s'y indexe. ⚠️ **Ne rien construire dessus.**

**Décision (Q1)** : **rien n'est codé** — ni constante `CONCEPT_ERROR`/`CONCEPT_ERROR_CODE`, ni commande
info, ni table de correspondance des codes. Le concept reste **inatteignable par construction**, exactement
comme `CONCEPT_ECO` et `CONCEPT_ULTRA_SILENCE` (doctrine UC01). Le volet redevient livrable au **domaine
post-MVP 03** (cloud legacy : `err_flag`, `ac_errcode1` —
`.memory/analyse/smartclim-transport-aux-cloud-legacy.md` § 5).

⚠️ **Verdict provisoire sur un point** : l'investigation à coût nul du § 11 point 5
(`diagnostic-auxhome.php '/app/getConfig?id=deviceFault'`, et `faultCode`, `deviceAlarm`) **n'a pas été
jouée** — le chemin libre est déjà supporté en CLI, aucun code à écrire. Le dépôt ne contient aucune trace
de ces routes (vérifié : absentes des 35 entrées de `deviceMutex` et de `routesDiagnostic()`), donc la
piste n'est pas *manquée* ; mais le négatif n'est **définitif** qu'après cette vérification.

**Forme tranchée pour le jour où un canal existera** (3ᵉ « À confirmer » de la spec, refermé) :
`error_code` en `subType` **`string`**, **une seule** commande. Trois motifs : (a) seul type satisfaisant
AC3 (libellé FR) **et** AC4 (valeur brute) dans une même commande ; (b) `cmd::event()` **jette
silencieusement** une valeur `numeric` hors `minValue`/`maxValue` (`jeedom-widgets-commandes.md` § 8.4) —
inacceptable pour un code de diagnostic ; (c) `error_code` / `string` est **déjà acté** dans
`.memory/analyse/smartclim-architecture-jeedom.md` § 5.1.
⚠️ **Écart à trancher le jour venu** : la spec fonctionnelle demande **deux** commandes (« Erreur » binaire
+ « Code erreur ») là où la nomenclature actée n'en prévoit **qu'une**.

### 2.6 Volet « limitation de puissance » — différé (Q2 validée)

Même source (`routes[2].configContent.power_limit`) :

```
key = "power_limit" · keyN = 匹数可调 (« puissance/tonnage ajustable »)
specs = [ 关闭 , 低（最省电）, 中 , 高（强性能） ]   → 4 codes : 0 arrêt · 1 bas · 2 moyen · 3 haut
toastMutex : refusé si electric_lock=1 · on_off=0 · eco=1 · ai_eco=1 · eight_heat=2
```

⚠️ Ce **n'est pas** « une limitation on/off » mais un **sélecteur de puissance à 4 niveaux**. ⚠️ Le nom
legacy `pwrlimit`/`pwrlimitswitch` (0/1 + valeur) est **un autre modèle** : deux transports, deux contrats —
**ne pas les fusionner**. Lecture ❌, signal par appareil ❌ (§ 2.4), aucun `logicalId` acté, et couplage à
`eco`/`ai_eco`/`eight_heat` que le plugin ne modélise pas (`eco` étant hors périmètre par D-UC01-Q1).

**Décision (Q2)** : **rien n'est codé**. Trois commandes de sonde CLI (§ 11 point 6) suffisent à savoir si
l'appareil l'accepte, **sans une ligne de code**. Si la mesure est concluante, `power_limit` reviendra dans
une UC dédiée avec une vraie modélisation du sélecteur.

---

## 3. Architecture — fichiers

Tous les fichiers PHP : **2 espaces, CRLF** (respecter l'existant fichier par fichier).

| Chemin | État | Ce qui y entre |
|---|---|---|
| `core/class/smartclimCapabilities.class.php` | **modifié** | `const CONCEPT_CHILD_LOCK` ; `fonctionsProtection()` ; `conceptsProtection()` / `conceptsProtectionLivres()` / `fonctionProtection()` ; `conceptsConnus()` étendu ; replis de `libelleConcept()` et `libelleCommande()` |
| `core/class/smartclimAuxHomeApi.class.php` | **modifié** | `intentionsAuxHome()` : **+1 ligne** ; `conceptsProtectionAuxHome()` (privée) ; `capacitesAppareil()` : 3ᵉ terme de fusion |
| `core/class/smartclim.class.php` | **modifié** | `definitionsCommandesInfo()` : +1 entrée (ordre 27) ; `definitionsCommandesAction()` : bloc protection (ordres 60/61) ; `creerCommandesAction()` : pose de `actionConfirm` à la création |
| `core/class/smartclimFrame.class.php` | **inchangé** | ⚠️ **Point remarquable** : première capacité du plugin **sans aucun offset**. Ni `champs()`, ni `champsBinaires()`, ni `champsOscillation()`, ni `champsEcriture()`, ni `longueursMinimales()` ne bougent |
| `core/class/smartclimBroadlinkLan.class.php` | **inchangé** | Le LAN **ne publie pas** ce concept : il ne sait pas l'écrire. ⚠️ Ne pas « harmoniser » les deux `capacitesAppareil()` — l'asymétrie **est** le contrat |
| `core/class/smartclimDiagnostic.class.php` | **inchangé** | `texteTrameHvac()` affiche **déjà tous** les octets avec diff : c'est l'instrument de recherche d'un éventuel bit `electric_lock`, rien à y ajouter |
| `core/php/sonde-intent-auxhome.php` | **inchangé** | ⚠️ Vérifié ligne à ligne : `--intent=electric_lock --valeur=1 --allumer` fonctionne **aujourd'hui, sans une ligne de code** (`:96` valide `[a-z][a-z0-9_]{1,30}`, `:93` accepte un entier signé, `:127` ajoute `on_off=1`). La mesure est **déjà outillée** |
| `core/php/commande-lan.php` | **inchangé** | `--commande=child_lock_on` échoue **proprement** en `TYPE_INTERNE` (§ 6) |
| `core/template/{dashboard,mobile}/cmd.action.other.etat.html` | **inchangés** | ✅ Vérifié : les branches `slice(-4) === '_off'` (testée **avant**) et `slice(-3) === '_on'` couvrent déjà `child_lock_off` / `child_lock_on`. Aucune collision avec les préfixes `mode_` / `fan_` |
| `core/php/smartclim.inc.php` | **inchangé** | **Sans objet et intentionnel** : aucune classe annexe nouvelle. ⚠️ Si l'implémenteur en crée une malgré tout, il **doit** l'y déclarer — l'oubli est une panne runtime invisible à `php -l` **et** à la CI. **Recommandation : ne pas créer de classe** |
| `core/config/*.ini`, `plugin_info/**`, `desktop/**`, `core/ajax/**` | **inchangés** | Aucune clé de configuration, aucun champ de formulaire, **aucune surface web nouvelle**, aucun `echo` nouveau, aucune dépendance |

**Points durs Jeedom passés en revue** — autoload : sans objet (aucune classe). Points d'entrée : sans objet.
Secrets : aucun secret nouveau, aucune clé de cache nouvelle, aucun fichier écrit. Cron : **inchangé** (le
cycle est en lecture d'état seule ; le verrou n'affecte pas `GET /app/user_device`). Budget de temps :
inchangé (`BUDGET_COMMANDE` = 18 s, rejeu UC08 inchangé). `packages.json` : reste vide.

---

## 4. Server vs Client

**100 % serveur.** Aucune ligne de JS, aucun HTML, aucun endpoint AJAX nouveau.

Le seul mécanisme touchant l'interface est le drapeau **`actionConfirm`** du **core** (§ 5.3), qui déclenche
un `jeeDialog.confirm` natif — **zéro JS, zéro HTML, zéro clé i18n de notre côté** (la chaîne de
confirmation est traduite par le core, ⚠️ **ne pas l'ajouter aux i18n du plugin**).

---

## 5. Signatures

### 5.1 `smartclimCapabilities`

```php
const CONCEPT_CHILD_LOCK = 'child_lock';   // logicalId DÉJÀ acté (smartclim-architecture-jeedom.md § 5.1)

private static function fonctionsProtection()
// -> array<concept, array{libelle:string, confirme:bool, allumer:bool, ordre:int}>
//    child_lock => (__('Sécurité enfant', __FILE__), false, true, 60)

public static function conceptsProtection()        // -> le concept, toujours
public static function conceptsProtectionLivres()  // -> confirme === true (VIDE à la livraison)
public static function fonctionProtection($_c)     // -> les colonnes, ou array()
```

**Pas de colonne `lecture`, et c'est un fait, pas un oubli** : la lecture est **structurellement
impossible** sur ce transport (aucun bit n'existe), pas « non encore confirmée ». Une colonne `lecture`
suggérerait qu'elle pourrait passer à `true` — elle ne le peut pas. Si un bit était un jour découvert
(§ 11 point 2b), la fonction **migrerait** vers le patron `fonctionsConfort()` + `champsBinaires()`.

#### 5.1.1 ⚠️ Pourquoi une table SÉPARÉE et pas une 6ᵉ ligne de `fonctionsConfort()`

Question soulevée en revue de plan, à **écrire dans le docblock** de `fonctionsProtection()` — les colonnes
sont identiques à celles de `fonctionsConfort()`, la tentation de fusionner est donc réelle :

1. **Argument dirimant** : `conceptsConfortLivres()` est consommée par **`smartclimFrame`**
   (`conceptsLisibles()` et `decoderEtat()`), où **chaque** concept de confort possède un bit dans
   `champsBinaires()`. Y glisser un concept **sans aucun offset** casserait cette propriété — la table
   cesserait de signifier « fonction booléenne portée par un bit de trame ».
2. `fonctionsProtection()` est l'**ancrage du domaine protection**, où `power_limit` (sélecteur à 4
   niveaux, § 2.6) viendra avec une forme **différente** de celle du confort.
3. Séparation par **domaine fonctionnel**, cohérente avec `fonctionsOscillation()`.

#### 5.1.2 ⚠️ La famille des marqueurs passe de CINQ à SIX

`CLAUDE.md` documente cinq familles ; le sixième marqueur est d'une **nature nouvelle** : le `'confirme'` de
`fonctionsProtection()` gouverne l'exposition **d'un concept sans offset**. Docblock obligatoire, et
**mention croisée** à poser dans `fonctionsConfort()` **et** `fonctionsOscillation()`.

Récapitulatif à jour — trois marqueurs seulement sont **lus** :

| Marqueur | Table | Lu ? | Gouverne |
|---|---|---|---|
| `'fil' => null` | `tables()` | ✅ | exclusion d'une valeur au niveau du transport |
| `intent_confirme` | `tables()` | ❌ déclaratif | traçabilité seule |
| `'confirme'` | `fonctionsConfort()`, `fonctionsOscillation()`, **`fonctionsProtection()`** | ✅ | l'exposition (écriture) |
| `'lecture'` | `fonctionsOscillation()` seule | ✅ | le décodage |

#### 5.1.3 Accesseurs dérivés

- `conceptsConnus()` → 6 historiques + `conceptsConfortLivres()` + `conceptsOscillationLivres()` +
  **`conceptsProtectionLivres()`** (⚠️ cf. § 1.4 — sans cela, l'état optimiste n'est jamais poussé).
- `libelleConcept()` → repli sur le libellé **nu**.
- `libelleCommande()` → repli sur **`self::nomSuffixe($libelle)` inconditionnellement** (gabarit
  `'%s (état commandé)'` existant, **zéro littéral nouveau**).
  ⚠️ **Aucun réalignement de nom à prévoir**, contrairement au § 5.5.3 d'UC02 : le nom ne changera
  **jamais**, `lecture` ne pouvant pas basculer. `libelleCommandeAutreVariante()` et la boucle de
  `creerCommandesInfo()` sur `conceptsOscillation()` restent **strictement inchangées**.

### 5.2 `smartclimAuxHomeApi`

```php
private static function intentionsAuxHome()   // + child_lock => ('electric_lock', 'booleen')
private static function conceptsProtectionAuxHome($_trameControle)  // -> array<int,string>
public  static function capacitesAppareil(array $_appareil)         // 3e terme de fusion
```

`conceptsProtectionAuxHome()` est **la voie d'entrée au profil**, et elle vit **dans le transport** — pas
dans `smartclimFrame` (aucun offset à consulter), pas dans `smartclimCapabilities` (qui ne connaît pas les
trames). C'est le pendant exact de `modesExclusAuxHome()` : la logique **propre à ce transport** reste ici.

⚠️ **Garde en tête de méthode, non négociable, calquée sur `conceptsOscillables()`** (UC02 § 5.2.2) :

```php
if (empty(smartclimFrame::conceptsLisibles($_trameControle, ''))) { return array(); }
```

**Trame longue passée VIDE, délibérément** : `conceptsLisibles()` répond « cet appareil expose des concepts
lisibles », **pas** « la trame de contrôle est exploitable ». Sans ce vide, une température ambiante lisible
(seuil 16 octets) compenserait une trame de contrôle **courte**, et un concept entrerait dans un profil
**irréversible** sur une trame qui ne le porte pas.

⚠️ **Ne pas toucher `conceptsLisibles()`** : elle produit `STATUT_ETAT_LU`, **seul garde-fou de la création
d'équipement depuis le LAN**. Y ajouter un concept abaisserait son seuil de longueur.

**Ce que la garde vaut, honnêtement** : « c'est un climatiseur AUX Home dont la trame de contrôle est
exploitable » — **pas** « cet appareil supporte la sécurité enfant ». C'est le mieux disponible (§ 2.4),
c'est la limite d'AC8, et c'est **le même niveau de garantie** que celui déjà accepté et documenté en UC02
pour `conceptsOscillables()` — poser une **référence croisée** vers son docblock.

```php
// capacitesAppareil() :
$concepts = array_values(array_unique(array_merge(
  array(CONCEPT_ONLINE),
  smartclimFrame::conceptsLisibles($ctrl, $long),
  smartclimFrame::conceptsOscillables($ctrl, $long),
  self::conceptsProtectionAuxHome($ctrl)          // <- 3e terme, AUX Home SEUL
)));
```

`appliquerOrdre()` : **aucune modification**. `'nature' => 'booleen'` existe déjà (cas de `power`), la
branche `:595-597` route le concept **sans une ligne nouvelle**.

### 5.3 `smartclim`

```php
private static function definitionsCommandesInfo()   // + child_lock : subType 'binary', unite '',
                                                     //   generic_type '', isHistorized 0, ordre 27, meta false
private function definitionsCommandesAction()        // + bloc protection
private function creerCommandesAction()              // + pose de actionConfirm à la création
```

Bloc protection **strictement calqué** sur le bloc oscillation (`:3386-3409`) — pour chaque concept de
`conceptsProtectionLivres()` **présent dans `capacites['concepts']`** :

| `logicalId` | `name` | ordre envoyé | `ordreCmd` | `confirmation` |
|---|---|---|---|---|
| `child_lock_on` | `sprintf(__('%s - Activer', __FILE__), $libelle)` | `array('child_lock' => 1, 'power' => 1)` | 60 | **true** |
| `child_lock_off` | `sprintf(__('%s - Désactiver', __FILE__), $libelle)` | `array('child_lock' => 0)` — **jamais** de `power` | 61 | false |

`subType` `other`, `infoLiee` = `child_lock`, libellé injecté = le libellé **nu** (sans suffixe).
✅ **Bandes vérifiées libres** : info 27 (0-5 concepts, 6-7 méta, 20-24 confort, 25-26 oscillation) ;
action 60-61 (13-26 modes/vitesses, 30-39 confort, 40 refresh, 50-53 oscillation).

#### 5.3.1 La colonne `'confirmation'` — seul ajout de mécanique du cycle

Consommée **uniquement à la création**, dans `creerCommandesAction()`, juste avant le `save()` :

```php
if (!empty($definition['confirmation'])) { $cmd->setConfiguration('actionConfirm', 1); }
```

⚠️ **Accès par `!empty()` UNIQUEMENT, et AUCUNE définition existante n'est touchée.** Point relevé en revue
de plan : ajouter `'confirmation' => false` aux ~20 blocs de définitions déjà recettés (dont les boucles
dynamiques `mode_*` / `fan_*`) serait un risque de régression **gratuit**. `!empty()` traite l'absence de
clé exactement comme `false`.

Mécanisme **core**, vérifié dans la source (`jeedom-widgets-commandes.md` § 4) : `core/ajax/cmd.ajax.php`
lève `-32006` **avant** `execCmd()`, `jeedom.cmd.execute` affiche un `jeeDialog.confirm` natif et rejoue
avec `confirmAction=1`.

⚠️ **Ce n'est PAS une frontière d'autorisation** : un scénario, l'API JSON-RPC ou un `execCmd()` d'un autre
plugin la contournent. C'est un **anti-fausse-manip**, pas une garde de sécurité.

⚠️ `actionConfirm` n'est posé **qu'à la création**. Sans conséquence ici (commande neuve) ; si le drapeau
devait un jour être ajouté à une commande **existante**, il faudrait un réalignement ciblé sur le modèle de
`realignerBornesConsigne()`.

#### 5.3.2 Ce qui reste inchangé — le dividende du patron

`ordreDeCommandeAction()`, `executerCommandeAction()`, `envoyerCommandeActionLan()`, `envoyerOrdreLan()`,
`appliquerEtat()`, `enregistrerOrdre()`, `filtrerEtatSelonOrdres()`, `appliquerCapacites()`,
`messageErreurAuxHome()`, `cron()`, `creerCommandesInfo()`.

Vérifié dans le code : l'ordre `array('child_lock' => 1, 'power' => 1)` est une **map statique** qui
traverse la garde `isset($definitions[$_logicalId])` (`:3629`), la déduplication (`:3652`),
`appliquerOrdre()`, `enregistrerOrdre()` et `appliquerEtat($ordreApplique, true)` **sans une ligne
nouvelle**.

⚠️ **La mémoire d'ordres est inerte pour ce concept, et c'est correct** : `filtrerEtatSelonOrdres()`
(`:4038-4064`) fait `array_key_exists($concept, $_etat) === false → continue`, puisque l'état scruté ne
porte **jamais** `child_lock` ; l'entrée expire seule au bout de `DUREE_GRACE`. Et `valeursCommandees()`
(`:3908-3919`) l'écarte par la liste blanche `smartclimFrame::conceptsEncodables()` — donc **aucune**
réaffirmation LAN parasite. Analyse identique au § 1.2 d'UC02.

---

## 6. Validation & classement des erreurs

| Ce qui est validé | Où | Type / message |
|---|---|---|
| `logicalId` de commande action | `definitionsCommandesAction()` — liste blanche **dérivée du profil** | `TYPE_INTERNE`, littéral **existant** « Commande inconnue pour cet équipement ». Un `execCmd()` de vieux scénario visant `child_lock_on` hors profil échoue **proprement** — c'est ce qui tient AC8 **hors** de l'interface |
| Valeur de la bascule | **rien à valider** : map statique, `$_options` **jamais lu** (seule la consigne le lit) | — |
| Refus fonctionnel du backend (appareil éteint, `ai_ctl`, verrou…) | `requeteControle()` → `classerCodeMetier(..., TYPE_AUTH, CONTEXTE_ORDRE_REFUSE)` | Message **existant** d'UC01 : « Le service AUX Home a refusé la commande — cette fonction est peut-être indisponible dans l'état actuel de l'appareil ». **Exact** pour ce cas, aucun littéral nouveau |
| Pilotage LAN de ce concept | `smartclimFrame::encoderOrdre()` (`:643-645`) | `TYPE_INTERNE` « concept sans entrée d'écriture », curaté par `messageErreurLan()`. **Comportement voulu** : `child_lock` est cloud-only (§ 9, R6) |
| Trame trop courte pour le profil | `conceptsProtectionAuxHome()`, garde § 5.2 | Pas d'exception : le concept **n'entre simplement pas** au profil |
| Confirmation avant action | `core/ajax/cmd.ajax.php` (core) | `-32006`, dialogue natif. **Pas une garde d'autorisation** |

**Sécurité** : aucune surface web nouvelle, aucun endpoint AJAX, aucun champ de formulaire, aucun `echo`,
donc **aucun nouveau point d'échappement**. Aucun secret, aucune clé de cache, aucun fichier écrit, aucun
élargissement de la surface CLI (les deux scripts sont inchangés et gardés par `php_sapi_name() === 'cli'`).

---

## 7. Impact i18n (français uniquement à l'implémentation)

**Un seul littéral nouveau dans tout le cycle** :

- `'Sécurité enfant'` — dans `fonctionsProtection()`, `core/class/smartclimCapabilities.class.php`

Points à ne pas rater :

- Chaîne **littérale** dans `__()`, **dans la table**, jamais `__($variable)` — l'extraction i18n est un
  scan **statique**.
- **Un seul `__()`** : le libellé sert à la fois de libellé de concept et de base des **deux** noms de
  commandes action.
- ✅ Aucun caractère supprimé par `cleanComponanteName()` (`& # ] [ % \ / ' " *`) dans « Sécurité enfant ».
- **Zéro littéral nouveau** dans `smartclim.class.php` : `'%s - Activer'`, `'%s - Désactiver'` (UC01),
  `'%s (état commandé)'` (UC02) et le message de refus (UC01) existent **tous**.
- **Pas de `'Actif'`/`'Inactif'`** : la commande info est `binary` et garde le widget natif du core.
- ⚠️ La chaîne de confirmation est **traduite par le core** — **ne pas** l'ajouter aux i18n du plugin.

Traduction `en_US` / `de_DE` / `es_ES` : **différée** au sous-agent `translator`, sur le code figé.

---

## 8. Périmètre

### 8.1 Couverture des critères d'acceptation

| AC | Verdict | Motif |
|---|---|---|
| **AC1** | **Reporté** (domaine 03) | Aucun canal de lecture d'erreur (§ 2.5). Une commande `binary` sans valeur afficherait « inactif » = « aucun défaut » : **indication fausse** sur un critère de diagnostic |
| **AC2** | **Reporté** | idem |
| **AC3** | **Reporté** | Sans canal, la table de correspondance serait du **code mort**. ⚠️ Les codes constructeur existent (E1 capteur plastique, E2/E3 capteur cuivre, E4 retour moteur PG) mais **aucun protocole ne les remonte** |
| **AC4** | **Reporté** | Forme **tranchée** pour le jour venu : `error_code` en `string` (§ 2.5) |
| **AC5** | **Reporté** | Rien à journaliser |
| **AC6** | **Livré désactivé** — à valider en recette | `confirme => false`. ⚠️ Tenu au sens d'UC02 (« état commandé »), **pas** au sens littéral « reflète cet état après rafraîchissement » (§ 1) |
| **AC7** | **Différé** | `power_limit` n'est pas un booléen (§ 2.6) |
| **AC8** | **Tenu par la doctrine, pas au sens littéral** | `confirme => false` ⇒ rien n'apparaît. Mais **aucun signal par appareil** n'existe (§ 2.4) : une fois activée, la fonction s'exposera sur tout appareil AUX Home à trame lisible. Identique à R8 d'UC01 et R11 d'UC02 |
| **AC9** | **Reporté** pour « Erreur » ; **gratuit** pour `child_lock` | `checkAndUpdateCmd()` émet l'événement standard, les scénarios se déclenchent sans mécanisme supplémentaire |

### 8.2 Écarts avec la spec fonctionnelle — à amender

1. **AC1-AC5 et AC9 (volet erreur)** → à marquer « **reportés au domaine post-MVP 03**, motif contrat :
   aucun canal de lecture sur AUX Home » (§ 2.5). ⚠️ Marquer le report **provisoire** tant que
   l'investigation § 11 point 5 n'est pas jouée.
2. **AC7** → à marquer « **différé** : `power_limit` est un sélecteur à 4 niveaux, pas un interrupteur »
   (§ 2.6).
3. **AC6** → à reformuler : « la commande info reflète le **dernier état commandé** » — la formulation
   « après rafraîchissement » est inatteignable sur ce transport (§ 1).
4. **AC8** → même nuance que celle déjà portée par UC01 et UC02 : tenue par le marqueur de recette, pas par
   un signal par appareil.
5. **Impact i18n de la spec fonctionnelle** → sept chaînes anticipées, **une seule** est livrée
   (« Sécurité enfant ») ; « Activer »/« Désactiver » existent déjà sous forme de gabarits.

### 8.3 Hors périmètre

Détection du profil (UC04 du MVP) · tuile dashboard de synthèse (domaine 06) · notifications dédiées ·
codes et fonctions de pompe à chaleur · interrupteurs de confort (UC01) · oscillations (UC02) ·
choix du transport actif (domaine 02).

---

## 9. Risques

- **R1 — L'auto-verrouillage du plugin.** Si le backend applique `toastMutex` (§ 2.2), **activer la sécurité
  enfant depuis Jeedom rend tout le reste du plugin inopérant**. Mitigations retenues : confirmation
  d'action sur « Activer », et « Désactiver » toujours disponible. ⚠️ **Impasse résiduelle** : appareil
  éteint **et** verrouillé ⇒ aucune sortie depuis Jeedom. ⚠️ **Ne pas** ajouter `power => 1` à l'ordre OFF :
  mitigation illusoire (§ 2.2).
- **R2 — On ne sait pas si le backend applique `toastMutex`.** C'est une règle d'**IHM**. À trancher par la
  mesure (§ 11 point 3) ; les **deux** réponses sont structurantes.
- **R3 — L'information affichée est un ordre, pas un état.** Après un « Activer » accepté, la commande info
  reste à 1 indéfiniment, même si l'appareil a été déverrouillé au panneau physique ou depuis l'app.
- **R4 — Avant tout ordre, la commande info est SANS VALEUR** et le widget `binary` affiche « inactif ».
  Effet **bénin** ici, contrairement à R7 d'UC02. Se referme au premier ordre. ⚠️ **Ne pas poser de valeur
  initiale.**
- **R5 — L'entrée d'un concept dans un profil est IRRÉVERSIBLE.** `appliquerCapacites()` (`:3040`) unionne
  les `concepts` sans équivalent de `modes_exclus`. Basculer `confirme => true` expose la fonction sur
  **tout le parc** AUX Home à trame lisible, **définitivement**. C'est ce qui rend l'ordre « **mesurer, puis
  activer** » non négociable.
- **R6 — `child_lock` est le PREMIER concept asymétrique entre transports.** Absent de `champsEcriture()` ⇒
  `commande-lan.php --commande=child_lock_on` lève `TYPE_INTERNE`. Voulu, mais **nouveauté structurelle** :
  jusqu'ici tout concept commandable en cloud l'était aussi en LAN. À signaler dans les analyses, sinon un
  futur mainteneur le prendra pour un bug.
- **R7 — Les commandes n'apparaissent qu'après un SCAN.** Le cycle cron est en lecture d'état seule et ne
  touche jamais les capacités (invariant UC07) — **même avec `confirme => true`**.
- **R8 — Le verdict « pas de canal d'erreur » repose sur des sources tierces et un seul appareil.** Les
  octets **16, 17, 19, 21, 22** de la trame de contrôle sont **non identifiés** (tous à `0x00` sur l'unique
  échantillon, appareil éteint) — un champ de défaut pourrait s'y trouver. L'instrument existe déjà :
  afficher la trame d'un appareil **réellement en défaut**.
- **R9 — `feature.faultSupport = 0` est une hypothèse d'interprétation.** Aucun appareil à `faultSupport = 1`
  n'a jamais été observé. **Ne rien construire dessus.**
- **R10 — La sonde CLI consomme deux `listerAppareils()` par mesure.** Usage manuel, aucun cadencement.
  ⚠️ **À ne pas transformer en boucle.**

---

## 10. Dépendances

**Aucune.** `packages.json` reste vide, `hasDependency: false`, `hasOwnDeamon: false`.

---

## 11. Recette — protocole de mesure, à exécuter avant d'activer quoi que ce soit

Aucun développement préalable n'est nécessaire : **les instruments existent déjà**.

1. `php core/php/sonde-intent-auxhome.php --equipement=<id> --etat` → trame de référence (tous les octets
   sont affichés).
2. Appareil **allumé** : `--intent=electric_lock --valeur=1 --allumer`, puis `--valeur=0`. Noter :
   **(a)** le code métier renvoyé ; **(b)** **quel octet et quel bit** bascule, s'il en bascule un — c'est
   la **seule** façon de découvrir un bit de lecture et de faire migrer la fonction vers le patron
   `champsBinaires()` ; **(c)** le verrouillage **physique** effectif du panneau (AC6).
3. **Test décisif de R1/R2**, verrou actif : envoyer une commande de base (« Arrêt », un mode) et noter si
   le backend **refuse**. C'est ce test, et lui seul, qui dit si le plugin s'auto-verrouille.
4. Basculer `'confirme' => true` **si et seulement si** l'ordre est accepté **ET** le verrouillage physique
   est constaté **ET** le comportement du point 3 est acceptable.
5. **Investigation erreur, tant que le matériel est disponible** (clôture définitive de Q1) :
   `php core/php/diagnostic-auxhome.php '/app/getConfig?id=deviceFault'`, puis `faultCode`, `deviceAlarm` —
   le chemin libre est **déjà supporté** en CLI, aucun code à écrire. Puis, si un défaut survient un jour
   réellement, `--etat` **immédiatement**, pour différencier les octets 16/17/19/21/22.
6. **Clôture de Q2** : `--intent=power_limit --valeur=1|2|3` puis `0` — répond en **trois commandes**, sans
   une ligne de code, à la question « cet appareil accepte-t-il la limitation de puissance, et un octet
   bouge-t-il ? ».

---

## 12. Dette

- **D-UC03-01 — Report du volet erreur** (AC1-AC5, AC9) au domaine post-MVP 03. ⚠️ Le négatif n'est
  **définitif** qu'après le § 11 point 5, non joué à la livraison.
- **D-UC03-02 — `power_limit` différée** (AC7), en attente du § 11 point 6.
- **D-UC03-03 — Aucun signal de capacité par appareil** pour `electric_lock` : `confirme => true` exposera
  la fonction sur tout appareil AUX Home à trame lisible, **irréversiblement** (R5). Même dette que D4
  d'UC01 et D3 d'UC02 — la vraie réponse serait un équivalent de `modes_exclus` pour les `concepts`.
- **D-UC03-04 — Message LAN peu explicite** : `commande-lan.php --commande=child_lock_on` rend un message
  générique là où « ce concept n'est pilotable qu'en cloud » serait utile. Non corrigé : ce serait un
  littéral pour un chemin non recettable.
- **D-UC03-05 — Impasse « éteint + verrouillé »** (R1) : sans sortie depuis Jeedom. Non mitigeable côté
  plugin (§ 2.2).
