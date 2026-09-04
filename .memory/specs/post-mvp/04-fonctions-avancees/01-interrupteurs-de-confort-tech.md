# Spec technique — UC01 post-MVP/04 « Interrupteurs de confort »

> **Spec fonctionnelle** : `.memory/specs/post-mvp/04-fonctions-avancees/01-interrupteurs-de-confort.md`
> **Dépend de** : UC04 du MVP (profil de capacités), UC05 du MVP (commandes info), UC06 du MVP (commandes
> action), UC07 du MVP (cycle de rafraîchissement), UC02 du domaine post-MVP/01 (`smartclimFrame`,
> décodage mutualisé), UC03 du domaine post-MVP/01 (`champsEcriture()`, encodage d'ordre).
> **Date du plan** : 2026-09-04 · **Statut de recette** : ⚠️ **livré désactivé** — les cinq fonctions
> arrivent avec `'confirme' => false`, donc **aucune commande n'apparaît** avant validation sur matériel
> (cf. § 11). C'est l'application littérale de l'AC7, pas une livraison partielle.

---

## 0. Ce que fait cette UC, en une phrase

Elle étend le modèle générique existant à des **concepts booléens de confort** (afficheur, sommeil,
ioniseur, nettoyage, anti-moisissure) en réutilisant intégralement les quatre cycles déjà en place — profil
de capacités → commandes info → commandes action → grâce/état optimiste —, et livre l'**instrument de
mesure CLI** sans lequel l'AC7 rendrait l'UC inactivable.

**Aucune classe nouvelle.** Le seul fichier créé est un script en ligne de commande.

---

## 1. L'arbitrage central — deux mondes asymétriques

Ces fonctions ne vivent **pas** au même endroit selon le sens :

- **Écriture (cloud AUX Home)** : par **clés d'intent** (`POST /app/device/v2/control`), déclarées par le
  backend lui-même dans `deviceMutex`.
- **Lecture (cloud *et* LAN)** : par des **bits de la trame HVAC**, donc par `smartclimFrame` — le même
  décodeur pour les deux transports, comme pour tous les concepts existants.
- **Écriture (LAN)** : par les **mêmes bits**, via `champsEcriture()` et la recopie de trame d'UC03.

**Conséquence non négociable** : une fonction dont le **bit de lecture** est inconnu ne peut pas satisfaire
AC3 (l'info reflète l'état réellement lu), AC4 (retour à l'inactif constaté) ni AC6 (pilotage hors Jeedom
répercuté). Elle sort donc du périmètre livrable — ce n'est pas une facilité d'implémentation mais la seule
lecture cohérente des critères d'acceptation.

| Concept générique | Libellé FR | Intent (écriture cloud) | Bit de lecture (charge HVAC nue) | Statut |
|---|---|---|---|---|
| `display` | Afficheur | `screen` | octet 20, bit 4 | **livrable après recette** |
| `sleep` | Mode sommeil | `sleep_mode` | octet 15, bit 2 | **livrable après recette** |
| `health` | Ioniseur | `healthy` | octet 18, bit 1 | **livrable après recette** |
| `clean` | Nettoyage automatique | `clean` | octet 18, bit 2 | **livrable après recette** |
| `mildew` | Anti-moisissure | `anti_fungus` | octet 20, bit 3 | **livrable après recette** |
| — | Éco | `eco` | **aucun** | **hors périmètre** (décision D-UC01-Q1) |
| — | Ultra-silence | `ultra_silence` (codes **1/2**) | **aucun** | **hors périmètre** (décision D-UC01-Q1) |

### 1.1 Décision D-UC01-Q1 — Éco et Ultra-silence ne sont pas livrées

**Tranché avec l'utilisateur le 2026-09-04** : option (a), ne pas les livrer.

Aucune des **quatre** implémentations publiques lues ne décode de bit `eco` (`broadlink_ac_mqtt`,
`homebridge-aux-cloud`, `ac_freedom`, `com.zwegersit.auxairco`). L'alternative écartée était de les livrer
**en écriture seule** (commandes action sans commande info) : elle aurait demandé d'amender l'AC3 de la spec
fonctionnelle et exposé un bouton dont l'état n'est jamais vérifiable — précisément ce que l'AC3 interdit.

**Ce que la décision n'interdit pas** : le § 11.5 du protocole de recette prévoit de **chercher** un bit
`eco` par diff d'octets, tant que le matériel est disponible. Si un bit apparaît, la fonction devient
livrable dans un second temps (dette **D2**). Coût d'un revirement : faible et additif — une constante de
concept, une ligne de table dans `fonctionsConfort()` et une ligne dans `champsBinaires()`.

**Conséquence structurelle voulue** : `eco` et `ultra_silence` n'ont **aucune constante `CONCEPT_*`**. Elles
sont donc inatteignables par construction, pas seulement filtrées — un `execCmd()` forgé ne peut pas les
viser. Un docblock doit dire que cette absence est le mécanisme, pas un oubli.

### 1.2 `ultra_silence` : fonction distincte, question de périmètre close

La spec fonctionnelle demandait de trancher si `ultra_silence` est une fonction réelle ou un alias de la
vitesse « Silencieux » déjà livrée en UC06. **Réponse : fonction distincte**, prouvée par le backend — elle
porte sa propre entrée `configContent` avec ses `key`/`specs`, et son `controlMutex` **pilote**
`wind_speed = 3` plus `eco = 0` / `ai_eco = 0`. Un alias n'aurait pas à commander la vitesse.

Elle est donc **distincte mais couplée** à `VITESSE_SILENT`, et **non livrable** faute de lecture. Aucune
commande de cette UC ne double `fan_silent` ni `fan_turbo`.

---

## 2. Contrats externes

### 2.1 Écriture — `POST /app/device/v2/control` (endpoint existant, inchangé)

Corps déjà en place : `{"intent": {<clé>: <entier>}, "dst": 1, "deviceId": "<id>"}`, succès = `code == 200`
dans l'enveloppe.

**Source des clés : le backend lui-même** — `GET /app/getConfig?id=deviceMutex` →
`data.configContent.<clé>`, relevé par la sonde de diagnostic le 2026-08-26 et versionné (masqué) dans
`.memory/analyse/smartclim-diagnostic-20260826-152439.json`. Chaque entrée porte `key` (le nom d'intent),
`keyN` (libellé constructeur) et `specs` (les valeurs admises).

**Statut** : ✅ noms et codes **déclarés par le backend** — c'est la source la plus forte disponible, plus
forte qu'un recoupement d'implémentations tierces. ⚠️ **L'acceptation de ces clés par `v2/control` n'est
corroborée par aucune implémentation tierce** : `com.zwegersit.auxairco/lib/auxcloud/constants.ts` (MIT,
l'implémentation de référence EU) n'implémente **aucune** intention de confort. Que le backend déclare une
clé ne prouve pas que l'endpoint de contrôle l'accepte — d'où l'instrument de mesure (§ 5.6) et le repli du
risque **R2**.

Règles de disponibilité extraites du dump, **qui commandent trois décisions de conception** :

| Clé | Codes | Refusé par le backend quand |
|---|---|---|
| `screen` | `0` arrêt · `1` marche · `2` capteur de luminosité | `electric_lock=1` |
| `sleep_mode` | `0` / `1` | `on_off=0` · `air_con_func ∈ {0,6}` · `sleep_diy=1` · `electric_lock=1` |
| `healthy` | `0` / `1` | `on_off=0` · `electric_lock=1` |
| `clean` | `0` / `1` | **`on_off=1`** · `electric_lock=1` |
| `anti_fungus` | `0` / `1` | **`on_off=1`** · `electric_lock=1` |

1. **`clean` et `anti_fungus` sont des fonctions de l'état ARRÊT.** `on_off.specs[1].showMutex` les masque
   quand l'appareil est allumé, et allumer force `clean = 0`. ⚠️ **Leur ordre `ON` ne porte donc JAMAIS
   `power => 1`** — dérogation explicite à la règle générale de `CLAUDE.md`, qui est scopée « mode ou
   consigne ». Précédent déjà en place : `fan_*` n'allume pas implicitement (commentaire dans
   `creerCommandesAction()`). **Ne pas « corriger » cela** : ajouter `power => 1` inverserait le contrat
   backend et éteindrait la fonction qu'on vient d'activer.
2. **`sleep_mode` et `healthy` exigent l'appareil allumé** ⇒ leur ordre `ON` porte `power => 1`, dans la
   forme exacte déjà recettée pour `mode_*` (deux clés dans le même intent).
3. **`air_con_func.controlMutex` remet à 0 `sleep_mode`, `eco`, `silence` et `turbo` à chaque changement de
   mode** — mais **pas** `healthy`, `clean`, `anti_fungus`, `screen`. Règle d'IHM appliquée côté application
   constructeur ; **le plugin ne la réimplémente pas** (risque **R6**).

### 2.2 Lecture — `status.control` (champ existant, inchangé)

Trame HVAC hexadécimale : **25 octets** sur l'appareil de recette (relevé du 2026-08-26) = 23 octets de
charge + 2 octets de somme. Les octets 15, 18 et 20 y sont donc **tous lisibles**.

Formules, **dans l'espace « charge HVAC nue »** — celui de `smartclimFrame`, cf. le piège d'espace de
comptage documenté dans `CLAUDE.md` (`offset charge HVAC = offset réponse LAN − 2`) :

| Concept | Lecture | Composition d'octet en écriture |
|---|---|---|
| `sleep` | `(b15 >> 2) & 1` | `b15 = mode<<5 \| sleep<<2` |
| `health` | `(b18 >> 1) & 1` | `b18 = power<<5 \| health<<1 \| clean<<2` |
| `clean` | `(b18 >> 2) & 1` | idem |
| `display` | `(b20 >> 4) & 1` | `b20 = display<<4 \| mildew<<3` |
| `mildew` | `(b20 >> 3) & 1` | idem |

**Sources — trois implémentations concordantes, lecture *et* écriture aux mêmes octets** :

- `liaan/broadlink_ac_mqtt` → `broadlink_ac_mqtt/classes/broadlink/ac_db.py`, fonctions `get_ac_states()`
  et `set_ac_status()`. Elle retire d'abord le préfixe (`response_payload = response_payload[2:]`), puis
  lit `sleep = payload[15]>>2&1`, `display = payload[20]>>4&1`, `mildew = payload[20]>>3&1`,
  `health = payload[18]>>1&1`, `clean = payload[18]>>2&1`, et **reconstruit** aux mêmes places.
  ⚠️ Elle déclare une charge de `0x19` = **25 octets**, soit **le même compte que la trame cloud** : c'est
  ce qui referme le dernier doute sur l'identité des deux espaces d'offsets.
- `fparrav/homebridge-aux-cloud/src/api/broadlink/Protocol.ts` (MIT) et `azadaydinli/ac_freedom` (sans
  licence, **lecture seule**) — déjà consignés dans
  `.memory/analyse/smartclim-transport-broadlink-lan.md` §§ 5.2 / 5.4, à l'espace « réponse LAN » (offsets
  17 / 20 / 22, soit **−2**).

**Statut d'ensemble** : ✅ vérifié dans **trois codes sources lus**, lecture et écriture concordantes ;
⚠️ **jamais observé variant sur une trame réelle**. L'unique échantillon disponible provient d'un appareil
**éteint**, tous ses bits de confort à 0 — ce qui est **cohérent** avec `on_off.specs[0].controlMutex` (qui
force `sleep_mode=0`, `healthy=0`, `eco=0` à l'extinction), donc **ni contradiction ni preuve**. C'est le
risque **R1**, et la raison d'être de l'instrument CLI.

### 2.3 Capacités par appareil — rien de neuf, et c'est établi

Le dump complet de `deviceMutex` a été passé au filtre « existe-t-il une règle de visibilité indexée sur
`deviceSupport` / `appSupport` / `healthPlus` / `use_type` pour une fonction de confort ? » → **aucune**
(la seule règle de ce type concerne `temperature`, masquée si `deviceSupport` ne contient pas `37`). Le sens
des index de `feature.deviceSupport` reste **indécodable**, et `feature.screen = "1"` ne prouve rien dans un
sens ni dans l'autre.

⇒ **Il n'existe aucun signal par appareil pour ces fonctions au-delà de la longueur de trame.** Ne pas
rouvrir ce chantier ici. Le levier reste `smartclimAuxHomeApi::exclusionsAuxHome()` le jour où un second
appareil de référence sera disponible.

---

## 3. Architecture — fichiers

Indentation **2 espaces**, fins de ligne **CRLF** pour tous les fichiers PHP ci-dessous (respecter
l'existant fichier par fichier ; les templates HTML suivent leur propre convention déjà en place).

| Chemin | État | Contenu |
|---|---|---|
| `core/class/smartclimCapabilities.class.php` | modifié | 5 constantes `CONCEPT_*` ; **`fonctionsConfort()`** (LA table de l'UC) ; `conceptsConfort()` / `conceptsConfortLivres()` ; `conceptsConnus()` étendu ; repli de `libelleConcept()` et `libelleCommande()` |
| `core/class/smartclimFrame.class.php` | modifié | **`champsBinaires()`** ; `longueursMinimales()` dérivée des **deux** tables ; `conceptsLisibles()` et `decoderEtat()` étendus **et filtrés** ; 5 lignes `'binaire'` dans `champsEcriture()` ; court-circuit de `versTransport()` dans `encoderOrdre()` |
| `core/class/smartclimAuxHomeApi.class.php` | modifié | 5 lignes `'nature' => 'booleen'` dans `intentionsAuxHome()` ; `CONTEXTE_ORDRE_REFUSE` ; `requeteControle()` passe ce contexte ; **`sonderIntent()`** (CLI uniquement) |
| `core/class/smartclim.class.php` | modifié | `SUFFIXE_CMD_ON` / `SUFFIXE_CMD_OFF` ; `definitionsCommandesInfo()` +5 ; bloc confort dans `definitionsCommandesAction()` ; branche `messageErreurAuxHome()` ; **`lireTrameAuxHome()`** et **`sonderIntentAuxHome()`** (CLI uniquement) |
| `core/class/smartclimDiagnostic.class.php` | modifié | **`texteTrameHvac()`** — mise en forme pure, aucun offset en dur (lit `smartclimFrame::champsBinaires()`) |
| `core/php/sonde-intent-auxhome.php` | **créé** | Instrument CLI. Garde `php_sapi_name() !== 'cli'` **avant tout `require_once`**. Aiguillage pur : aucune logique métier, aucune écriture disque ni base. Sorties FR **sans `__()`** (convention des deux CLI existantes) |
| `core/template/dashboard/cmd.action.other.etat.html` | modifié | Règle de suffixe `_on` → `'1'`, `_off` → `'0'`, **après** les tests exacts `on`/`off` et les préfixes `mode_`/`fan_` |
| `core/template/mobile/cmd.action.other.etat.html` | modifié | **Strictement identique** au précédent (deux fichiers synchronisés) |
| `core/php/smartclim.inc.php` | **inchangé** | **Sans objet, et c'est intentionnel** : aucune classe annexe nouvelle ⇒ aucun `require_once` à ajouter. ⚠️ Si l'implémenteur crée malgré tout une classe (`smartclimConfort` ou autre), il **doit** l'y déclarer — l'oubli est une panne runtime invisible à `php -l` **et** à la CI. **Recommandation : ne pas créer de classe.** |
| `core/config/smartclim.config.ini`, `plugin_info/**`, `desktop/**`, `core/ajax/**` | **inchangés** | Aucune clé de configuration, aucune dépendance, aucun champ de formulaire, **aucune surface web nouvelle** |

---

## 4. Server vs Client

**Tout côté serveur.** Aucune ligne de JavaScript, aucune action AJAX, aucun champ de formulaire, aucune
sortie HTML nouvelle. Les seules modifications côté client sont les **deux templates de widget**, qui ne
font que traduire un `logicalId` en valeur de comparaison — la logique de l'ordre, elle, est **entièrement**
construite côté serveur à partir du `logicalId`, comme l'exige `CLAUDE.md`.

Justification : l'ordre de confort est une **map statique** (`array('sleep' => 1, 'power' => 1)`), jamais
dérivée d'une saisie. `$_options` n'est **pas lu** pour ces commandes — contrairement à la consigne
(`slider`), seul cas où il l'est. Il n'y a donc **rien à valider côté client**.

---

## 5. Signatures

### 5.1 `smartclimCapabilities`

```php
const CONCEPT_DISPLAY = 'display';
const CONCEPT_SLEEP   = 'sleep';
const CONCEPT_HEALTH  = 'health';
const CONCEPT_CLEAN   = 'clean';
const CONCEPT_MILDEW  = 'mildew';
```

Ces valeurs **sont** les `logicalId` des commandes info — stables par contrat, ils ne changent jamais lors
d'une bascule de transport.

⚠️ **Docblock obligatoire** : l'absence de `CONCEPT_ECO` et `CONCEPT_ULTRA_SILENCE` est le **mécanisme**
d'AC7 (§ 1.1), pas un oubli. Ne pas les ajouter « pour compléter la table ».

```php
private static function fonctionsConfort()
// -> array<concept, array{libelle:string, confirme:bool, allumer:bool, ordre:int}>
```

- `libelle` — chaîne **littérale** dans `__()`, jamais `__($variable)` (l'extraction i18n est un scan
  statique). Sert **à la fois** de libellé de concept et de base de nom de commande : un seul `__()` par
  fonction, jamais deux pour un texte identique.
- `confirme` — **le filtre de l'UC**. `false` = aucune commande, aucune entrée de profil.
- `allumer` — l'ordre `ON` porte-t-il `power => 1` (cf. § 2.1 : `true` pour `sleep`/`health`, **`false` pour
  `clean`/`mildew`**, `false` pour `display` qui est indifférent).
- `ordre` — base d'affichage : `ON` = `ordre`, `OFF` = `ordre + 1`.

**Les cinq lignes sont livrées avec `'confirme' => false`.**

> ### ⚠️ 5.1.1 Ne pas confondre avec `intent_confirme` — deux marqueurs de recette, un seul est LU
>
> `smartclimCapabilities::tables()` porte déjà une colonne **`intent_confirme`**, dont le docblock dit
> explicitement qu'elle est **« jamais lue par UC04 »** : c'est un marqueur **déclaratif**, une note de
> traçabilité sur la solidité d'un code d'écriture.
>
> Le `confirme` de `fonctionsConfort()` est d'une autre nature : il est **effectivement lu**, et il
> **gouverne l'exposition** de la fonction (profil, commandes info, commandes action). C'est le premier
> marqueur de recette **actif** du plugin.
>
> Il ne faut pas davantage l'assimiler au mécanisme **`'fil' => null`**, qui encode un **fait de
> protocole** (aucune correspondance de lecture n'existe) et non un **état de recette** (une correspondance
> est supposée exister, non encore vérifiée sur matériel).
>
> **À faire dans le code, pas seulement ici** : un commentaire dans `fonctionsConfort()` distinguant ces
> trois familles, et une mention croisée dans le docblock de `tables()` signalant qu'il existe désormais un
> second marqueur de recette, celui-là effectif. Sans cela, un futur mainteneur (oscillations d'UC02,
> domaine 02) confondra un flag inerte avec un interrupteur de livraison.

```php
public static function conceptsConfort()       // -> array<int,string> : les 5, toujours
public static function conceptsConfortLivres() // -> array<int,string> : ceux dont confirme === true
```

`conceptsConfortLivres()` est l'**unique point de filtrage** de l'UC, consommé par `conceptsConnus()`,
`conceptsLisibles()` et `decoderEtat()`. Un seul endroit à éditer pour activer une fonction.

- `conceptsConnus()` → les 6 concepts existants **puis** `conceptsConfortLivres()`, dans l'ordre de la
  table. ⚠️ C'est l'ordre canonique consommé par `ordonnerParReference()` **et** la boucle de
  `appliquerEtat()` : cette dernière itère sur `conceptsConnus()`, **pas** sur les clés de l'état — c'est
  pourquoi étendre cette seule fonction suffit à faire circuler les nouveaux concepts dans le cycle info
  existant, sans une ligne dans `appliquerEtat()`.
- `libelleConcept()` / `libelleCommande()` → **repli** sur `fonctionsConfort()[$c]['libelle']` quand le
  concept n'est pas dans la liste statique. Chaîne vide si inconnu — contrat inchangé, et
  `creerCommandesInfo()` ne crée alors rien. `profilAffichable()` exploite ce repli sans modification.

### 5.2 `smartclimFrame`

```php
public static function champsBinaires()
// -> array<concept, array{trame:string, octet:int, bit:int}>
```

Cinq lignes, **non filtrées** — des offsets restent des offsets, quel que soit l'état de recette :
`sleep` → (`controle`, 15, 2) · `health` → (`controle`, 18, 1) · `clean` → (`controle`, 18, 2) ·
`display` → (`controle`, 20, 4) · `mildew` → (`controle`, 20, 3).

⚠️ **Commentaire croisé obligatoire** avec `champs()` et `champsEcriture()` : **l'octet 15 est partagé avec
le mode, l'octet 18 avec la marche**. Toute modification de l'une des trois tables se vérifie contre les
deux autres. Un futur contributeur qui écrirait un octet entier au lieu de masquer casserait deux concepts
d'un coup (risque **R10**).

```php
private static function longueursMinimales()  // dérivée de champs() UNION champsBinaires()
```

⚠️ **Piège de forme à ne pas rater** : `champs()` porte `'octets' => array(...)` (**pluriel**, une liste
d'indices) alors que `champsBinaires()` porte `'octet' => int` (**singulier**). La fusion se fait en **deux
boucles distinctes** — `max($champ['octets'])` d'un côté, `$champ['octet']` de l'autre — jamais en
confondant les deux schémas. `longueursMinimales()` reste la **source unique** des longueurs : ne pas coder
un seuil en dur ailleurs.

Longueurs induites : `sleep` → 16 octets · `health`/`clean` → 19 · `display`/`mildew` → **21**.

```php
public static function conceptsLisibles($_trameControle, $_trameLongue)
public static function decoderEtat(...)
```

Les deux bouclent en plus sur `champsBinaires()`, et **n'y retiennent un concept que s'il figure dans
`smartclimCapabilities::conceptsConfortLivres()`**. `decoderEtat()` pose
`$etat[$concept] = ($octet >> $bit) & 1`.

⚠️ **Une clé absente reste absente** (trame trop courte, octet illisible) — l'invariant « une clé absente de
l'état ne touche pas sa commande » est préservé, et c'est ce qui tient l'AC5 côté lecture. **Ne jamais
substituer 0 à une lecture impossible** : ce serait afficher « inactif » pour « inconnu ».
Les deux fonctions continuent de **ne jamais lever**.

**Sur la dépendance nouvelle** `smartclimFrame` → `smartclimCapabilities` : `smartclimFrame` est décrite
comme une table de données pure, ce qu'elle reste (aucune E/S, aucun `cache::`, aucun `config::`, aucun
`eqLogic`). Placer le filtre ici plutôt que dans les deux `capacitesAppareil()` évite de le **dupliquer**
dans les deux transports, où il divergerait tôt ou tard. Le décodeur reste ignorant du transport ; il
consulte seulement la table de rollout. Choix retenu, à documenter (§ 5.1.1).

```php
private static function champsEcriture()  // +5 lignes portant 'binaire' => true
```

`display` (20, `0x10`, 4) · `mildew` (20, `0x08`, 3) · `sleep` (15, `0x04`, 2) · `health` (18, `0x02`, 1) ·
`clean` (18, `0x04`, 2). Masques **vérifiés disjoints** de ceux des concepts partageant l'octet : `sleep`
`0x04` contre `mode` `0xE0` ; `health` `0x02` / `clean` `0x04` contre `power` `0x20`.

```php
public static function encoderOrdre(...)
```

Pour une ligne `'binaire'`, `$code = $_valeur ? 1 : 0` **sans** appeler `versTransport()` — ces concepts
sont absents de `tables()`, `versTransport()` renverrait `null` et l'ordre lèverait `TYPE_INTERNE` à chaque
commande LAN. Aucune autre modification : le garde-fou « base ≥ 21 octets » couvre déjà l'octet 20, et la
recopie de trame reste la règle (⚠️ **l'écriture porte un état complet, jamais un delta**).

`conceptsEncodables()` s'élargit **mécaniquement** (elle dérive de `champsEcriture()`), ce qui étend
correctement la liste blanche de `smartclim::valeursCommandees()` : un ordre de confort sous période de
grâce sera réaffirmé par la prochaine écriture LAN.

### 5.3 `smartclimAuxHomeApi`

```php
private static function intentionsAuxHome()  // +5 lignes 'nature' => 'booleen'
```

`display` → `screen` · `sleep` → `sleep_mode` · `health` → `healthy` · `clean` → `clean` ·
`mildew` → `anti_fungus`. **Seul endroit du plugin** où ces noms propriétaires apparaissent.

`'nature' => 'booleen'` **existe déjà** (c'est le cas de `power`) : `appliquerOrdre()` sait donc router un
booléen **sans modification**.

```php
const CONTEXTE_ORDRE_REFUSE = 'ordre_refuse';
```

Nom **neutre**, aucun nom d'endpoint — même discipline que `CONTEXTE_REQUETE_INITIALE`. Patron déjà établi
pour des contextes définis hors `smartclimException` (`smartclimFrame::CONTEXTE_BASE_ILLISIBLE`,
`smartclimBroadlinkLan::CONTEXTE_ECRITURE_NON_CONFIRMEE`).

`requeteControle()` → passe ce contexte à `classerCodeMetier()`. **Aucun changement de type, aucun
changement de purge, aucun changement de rejeu** : seul le message final change (§ 6).

```php
public static function sonderIntent($_identifiantAppareil, array $_intentBrut)  // -> void
```

**Lève** `TYPE_INTERNE` si `php_sapi_name() !== 'cli'` (garde **dans le transport**, au plus près du risque,
comme la sonde de diagnostic), si la table est vide ou porte plus de 2 clés, si une clé ne vérifie pas
`/\A[a-z][a-z0-9_]{1,30}\z/`, ou si une valeur n'est pas un entier de `[-1, 255]`. Puis `session()` +
`requeteControle()`. Recrée l'exception à ce point d'appel — la frame de `requete()` porte le jeton.

### 5.4 `smartclim`

```php
const SUFFIXE_CMD_ON  = '_on';
const SUFFIXE_CMD_OFF = '_off';
```

**`definitionsCommandesInfo()`** — +5 entrées : `subType` `binary`, `unite` `''`, `generic_type` `''`
(aucun type générique Jeedom pertinent), `isHistorized` 0, `ordre` 20 à 24, `meta` false. Aucun
`minValue`/`maxValue` (règle existante). Bande vérifiée libre : le maximum actuellement atteignable est 26.

**`definitionsCommandesAction()`** — pour chaque concept de `conceptsConfortLivres()` **présent dans
`capacites['concepts']`**, deux entrées :

| Clé | Nom | `ordre` (l'ordre à envoyer) | `ordreCmd` |
|---|---|---|---|
| `<concept>_on` | `sprintf(__('%s - Activer', __FILE__), $libelle)` | `array($concept => 1)` **+ `power => 1` si `allumer`** | 30/32/34/36/38 |
| `<concept>_off` | `sprintf(__('%s - Désactiver', __FILE__), $libelle)` | `array($concept => 0)` — **jamais** de `power` | 31/33/35/37/39 |

`subType` `other`, `infoLiee` = `<concept>`. ⚠️ **Bande 30-39 saturée** par cinq fonctions ; `refresh` reste
à 40. UC02 et UC03 de ce domaine devront prendre 50 et au-delà.

Le lien à l'info et le widget `smartclim::etat` sont posés **génériquement et idempotemment** par
`creerCommandesAction()` pour toute commande hors consigne dont le template est vide : **aucune
modification côté PHP** n'est nécessaire pour que les nouvelles commandes en héritent.

**Inchangées** : `ordreDeCommandeAction()`, `executerCommandeAction()`, `envoyerCommandeActionLan()`,
`envoyerOrdreLan()`. Les ordres de confort étant des maps statiques, ils traversent la garde
`isset($definitions[$_logicalId])`, la déduplication, `appliquerOrdre()`, `enregistrerOrdre()` et l'état
optimiste **sans une ligne nouvelle** — vérifié dans le code : ce chemin ne comporte aucune liste blanche de
valeurs ni `switch`. Le pilotage **LAN** de ces fonctions est donc livré par la CLI existante
`commande-lan.php`, gratuitement.

### 5.5 Les deux méthodes d'instrumentation (CLI uniquement)

```php
public function lireTrameAuxHome()
// -> array{trame:string, etat:array}
```

Garde CLI interne. Un `listerAppareils()`, appariement sur `configuration.auxhome_device_id`, renvoie
**uniquement** la trame de contrôle et l'état décodé — ⚠️ **jamais** l'identifiant cloud, la MAC ni le
`passcode`. Lève une `smartclimException` **curatée** (compte non configuré, équipement non relié à un
appareil, appareil absent de la réponse).

```php
public function sonderIntentAuxHome(array $_ordre, $_attente = 15, $_brut = false)
// -> array{avant:string, apres:string, etat_avant:array, etat_apres:array, ecrit:bool}
```

Garde CLI interne. Lit → écrit (`appliquerOrdre()` si `$_brut === false`, `sonderIntent()` sinon) → attend
`min(180, max(0, $_attente))` → relit.

⚠️ **N'écrit ni la mémoire d'ordres, ni le marqueur de déduplication, ni aucune commande.** C'est un
**instrument de mesure** : il doit rendre la lecture **brute**. Écrire la mémoire d'ordres ferait filtrer la
relecture par `filtrerEtatSelonOrdres()` et la mesure confirmerait ce qu'on vient d'envoyer au lieu de ce
que l'appareil a fait (risque **R3**). `$_ordre` vide ⇒ lecture seule. Lève curaté.

### 5.6 `core/php/sonde-intent-auxhome.php`

```
php core/php/sonde-intent-auxhome.php --equipement=<id> --etat
php core/php/sonde-intent-auxhome.php --equipement=<id> --concept=<code> --valeur=<n> [--allumer] [--attente=<s>]
php core/php/sonde-intent-auxhome.php --equipement=<id> --intent=<cle>   --valeur=<n> [--allumer] [--attente=<s>]
```

- `--concept` — code générique, traduit par le transport (chemin normal, celui du plugin).
- `--intent` — **clé brute**, l'échappatoire d'investigation : essayer `screen_on_off` si `screen` est
  refusé, ou chercher un bit `eco`. C'est le pendant exact du « chemin libre » de `diagnostic-auxhome.php`,
  et **la raison pour laquelle cet instrument est une CLI et non un bouton** : un chemin libre relève de la
  ligne de commande par règle de projet.
- `--allumer` — ajoute `power => 1` **explicitement**. ⚠️ L'instrument ne dérive **jamais** ce comportement
  de la table `fonctionsConfort()` : une mesure dont la commande dépend d'une table qu'on cherche
  justement à valider est une mesure ambiguë.
- `--etat` — n'émet **rien** en écriture sur le réseau.

Structure : garde `php_sapi_name() !== 'cli'` **avant tout `require_once`**, aucun POST, aucune écriture en
base ni sur disque. **Aiguillage pur** — il ne construit **jamais** de map de concepts à la main, comme
`commande-lan.php` (c'est ce qui garantit que la surface mesurée est celle du plugin).

⚠️ **Ne jamais écrire de rapport dans le dossier du plugin** : sa racine n'a pas de `.htaccess`. Sortie sur
la sortie standard uniquement.

### 5.7 `smartclimDiagnostic`

```php
public static function texteTrameHvac($_avant, $_apres, array $_etatAvant, array $_etatApres)
// -> string
```

Mise en forme **pure** : une ligne par octet (index, hexadécimal, binaire, marqueur de différence), puis un
bloc « bits documentés » construit depuis `smartclimFrame::champsBinaires()` (concept, octet, bit,
avant → après), puis les deux états génériques. **Aucun offset en dur, aucune E/S, aucun masquage** — une
trame HVAC n'est pas un secret, et `CLAUDE.md` interdit explicitement de masquer du 12-hex nu (ce sont les
trames, la donnée la plus utile d'un rapport).

⚠️ **Le rapport doit afficher TOUS les octets**, pas seulement ceux qu'on suppose porteurs : si le bit qui
bascule n'est pas celui attendu, c'est la seule façon de le voir. Leçon de la température ambiante
(`.memory/analyse/smartclim-transport-aux-home.md` § 6.2).

---

## 6. Validation & classement des erreurs

| Ce qui est validé | Où | Type / message |
|---|---|---|
| `logicalId` de commande action | `definitionsCommandesAction()` — liste blanche existante, dérivée du profil | `TYPE_INTERNE`, littéral **existant** « Commande inconnue pour cet équipement ». Un `execCmd()` de vieux scénario visant une fonction retirée échoue **proprement** — c'est ce qui tient l'AC5 hors de l'interface |
| Valeur de la bascule | **rien à valider** : map statique, `$_options` jamais lu | — |
| Concept sans ligne d'intent / sans ligne d'écriture | `appliquerOrdre()` / `encoderOrdre()` | `TYPE_INTERNE`, messages existants. Impossible si les tables sont complètes ⇒ garde de non-régression |
| **Refus fonctionnel du backend** | `requeteControle()` → `classerCodeMetier(..., TYPE_AUTH, CONTEXTE_ORDRE_REFUSE)` | **Nouveau littéral** : « Le service AUX Home a refusé la commande — cette fonction est peut-être indisponible dans l'état actuel de l'appareil » |
| Clé d'intent brute, valeur, mode CLI | `smartclimAuxHomeApi::sonderIntent()` | `TYPE_INTERNE`, message technique, jamais affiché hors CLI |
| Arguments CLI (`--equipement` numérique, `--attente` ∈ [0,180], exclusivité `--concept`/`--intent`) | le script | `die()` avec un usage en français, **sans `__()`** |

### 6.1 Le piège d'erreur central de cette UC — à lire avant de coder

`requeteControle()` classe **tout** code métier autre que 200 (hors 9023 / 64033) en **`TYPE_AUTH`**,
**purge la session** et déclenche **un** re-login avec rejeu (UC08).

Ce défaut était sans conséquence au MVP : marche, mode, consigne et vitesse sont quasi toujours acceptés.
Les fonctions de confort introduisent une **classe de refus attendue et légitime** — activer « Nettoyage
automatique » sur une clim allumée, « Mode sommeil » en Ventilation (§ 2.1).

**Arbitrage retenu : ne toucher ni au classement ni à la purge.** Le code d'expiration réel du jeton est
toujours inconnu (UC08 a tranché d'instrumenter plutôt que de deviner) ; inverser le défaut casserait le
re-login réactif, et l'anti-rafale d'UC08 borne déjà le dégât à **une** tentative. On corrige **uniquement
le message**, qui devient vrai dans les deux cas, et **on mesure** : le rapport CLI affiche le code métier
de chaque refus. Dès qu'un code de refus fonctionnel est observé, la correction propre est une table
`code => type` dans le transport (dette **D1**).

**Alternative écartée** : conditionner le type au contenu de la requête. Le type d'une exception doit
dépendre de la **réponse**, jamais de la question.

⚠️ **Non-régression à vérifier explicitement en recette** : ce nouveau message s'affichera pour **tout**
échec de contrôle AUX Home non classé, y compris sur une commande de base du MVP (marche, mode, consigne,
vitesse). C'est assumé — le message reste vrai — mais il touche du code déjà recetté : faire échouer
volontairement une commande de base et vérifier que le message affiché reste compréhensible.

### 6.2 Sécurité

**Aucune surface web nouvelle** : pas d'action AJAX, pas de champ de formulaire, pas de sortie HTML, donc
aucun nouveau point d'échappement à traiter. Trois gardes CLI indépendantes (`sonderIntent()` dans le
transport, `sonderIntentAuxHome()` et `lireTrameAuxHome()` dans `smartclim`, plus la garde en tête de
script), et le `.htaccess` existant de `core/php/`. La CLI n'imprime **jamais** `deviceId`, `mac`,
`thirdDid`, `did` ni `passcode`. Aucun secret nouveau, aucune clé de cache nouvelle, aucun fichier écrit.

---

## 7. Impact i18n (français uniquement à l'implémentation)

`core/class/smartclimCapabilities.class.php` — 5 littéraux dans `fonctionsConfort()` :
`'Afficheur'` · `'Mode sommeil'` · `'Ioniseur'` · `'Nettoyage automatique'` · `'Anti-moisissure'`

`core/class/smartclim.class.php` — 3 littéraux :
`'%s - Activer'` · `'%s - Désactiver'` · le message de refus du § 6.

Quatre points à ne pas rater :

- ⚠️ **« Santé / Ioniseur » de la spec fonctionnelle devient « Ioniseur »** : `cleanComponanteName()` du
  core supprime la barre oblique d'un **nom de commande** — « Santé / Ioniseur » deviendrait
  « Santé  Ioniseur » (double espace). Même précédent que « Marche/Arrêt » → « Marche-Arrêt ». Aucun
  libellé de cette UC ne contient d'apostrophe ni de barre oblique — d'où le gabarit `'%s - Activer'`
  plutôt que « Activer l'afficheur ».
- Le libellé sert **à la fois** de nom de commande et de libellé de concept : **un seul** `__()` par
  fonction.
- **`'Actif'` / `'Inactif'` ne sont PAS introduits** : les commandes info sont de sous-type `binary` et
  gardent le widget natif du core, comme `online` et `power`. Aucun widget d'info nouveau.
- Le script CLI n'introduit **aucune** clé (convention des deux CLI existantes).

Traduction `en_US` / `de_DE` / `es_ES` : **différée** au sous-agent `translator`, sur le code figé.

---

## 8. Périmètre

**Dans le périmètre** : les cinq fonctions du § 1 (mécanisme complet, livré désactivé), l'instrument de
mesure CLI, la correction du message de refus.

**Hors périmètre** : Éco et Ultra-silence (§ 1.1) · la détection du profil de capacités elle-même (UC04 du
MVP) · les oscillations verticale et horizontale (UC02 de ce domaine) · codes d'erreur, sécurité enfant,
limitation de puissance (UC03 de ce domaine) · l'arbitrage entre transports (domaine 02) · la tuile
dashboard (domaine 06) · la vitesse de ventilation et ses valeurs `SILENT`/`MUTE`/`TURBO`, déjà livrées en
UC06 du MVP — **aucune commande de cette UC ne les double**.

---

## 9. Risques

- **R1 — Les bits de lecture ne sont pas mesurés sur une trame réelle.** Trois implémentations
  concordantes, mais un seul échantillon, appareil **éteint**, tous bits à 0. C'est *le* risque de l'UC, et
  ce que l'instrument CLI existe pour lever, fonction par fonction. Méthodologie imposée : **jamais valider
  une formule d'octet sur moins de trois mesures**, et afficher **tous** les octets.
- **R2 — L'acceptation des intents de confort par `v2/control` n'est corroborée par aucune implémentation
  tierce.** Replis, dans l'ordre : `--intent=screen_on_off`, puis `POST /app/device/control` sans `v2`
  (existence en EU non vérifiée).
- **R3 — La période de grâce peut faire passer un échec pour un succès pendant 60 s.** Après un ordre,
  l'état optimiste affiche « actif » et `filtrerEtatSelonOrdres()` écarte la lecture divergente. Une
  recette faite en 20 s conclurait à tort. ⇒ mesurer par la CLI (qui n'écrit pas la mémoire d'ordres) ou
  attendre plus de 60 s.
- **R4 — AC6 par télécommande IR n'est pas garanti.** `status.control` est le dernier état *commandé* ; un
  ordre IR ne transite pas par le backend. Valider **d'abord** par l'application constructeur (même
  backend), puis constater le comportement IR **sans en faire un échec d'UC**.
- **R5 — `clean` et `mildew` ne sont utilisables qu'à l'ARRÊT**, et allumer l'appareil force `clean = 0`.
  Un utilisateur qui active « Nettoyage automatique » sur une clim en marche verra un refus ou un ordre
  inerte. À écrire dans la documentation utilisateur ; ⚠️ **ne pas « corriger » en ajoutant `power => 1`**.
- **R6 — Le plugin n'implémente pas `controlMutex`.** Un changement de mode depuis Jeedom n'effacera pas
  `sleep` côté plugin comme le fait l'application constructeur. Divergence de comportement d'IHM, **jamais
  un état faux** : la commande info affichera la vérité de l'appareil au cycle suivant.
- **R7 — Aucun gating par mode.** `sleep` est refusé en Ventilation et en Automatique. Effet : ordre inerte
  ou refusé, jamais dangereux — statu quo déjà accepté pour la vitesse en déshumidification. Un gating
  exigerait un profil de capacités **par mode** (dette **D5**, partagée avec UC02 de ce domaine).
- **R8 — L'entrée d'un concept dans un profil est irréversible.** `appliquerCapacites()` unionne les
  `concepts` sans équivalent de `modes_exclus` (vérifié : aucun `array_diff` sur cette clé, contrairement
  aux modes). Une fonction activée à tort ne se retire **ni** du profil **ni** des commandes déjà créées.
  C'est la justification du défaut `confirme => false`, et la raison pour laquelle l'ordre « mesurer, puis
  activer » n'est pas négociable.
- **R9 — Les commandes n'apparaissent qu'après un SCAN.** Le cycle cron est en lecture d'état seule et ne
  touche jamais les capacités (invariant UC07). Après mise à jour du plugin, tant qu'aucun scan n'est
  relancé, aucune commande de confort n'apparaît — même avec `confirme => true`.
- **R10 — Octets partagés** : 15 porte mode + sommeil, 18 porte marche + ioniseur + nettoyage. Le masquage
  par champ est déjà correct dans `encoderOrdre()` ; le commentaire croisé entre les trois tables est ce
  qui protège la suite.
- **R11 — Le troisième code de `screen`** (`2`, capteur de luminosité) sort du modèle booléen : un appareil
  réglé sur `2` depuis l'application constructeur relira un bit imprévisible, et « Afficheur - Activer »
  enverra `1`, écrasant le réglage. Non traité (dette **D3**).
- **R12 — La CLI consomme deux `listerAppareils()` par mesure.** Aucun quota documenté ; usage manuel, sans
  cadencement. **À ne pas transformer en boucle automatique.**

---

## 10. Dépendances

**Aucune.** Pas de paquet système, pas de `pip3`, `packages.json` reste vide, `hasDependency` reste `false`,
`hasOwnDeamon` reste `false`. Tout est couvert par PHP nativement (opérations de bits, cURL déjà en place).

---

## 11. Recette — protocole de mesure, à exécuter avant d'activer quoi que ce soit

Sur le Jeedom, en SSH, depuis `<jeedom>/plugins/smartclim` :

1. `php core/php/sonde-intent-auxhome.php --equipement=<id> --etat` → **référence** : les 25 octets, les
   cinq bits documentés, l'état décodé.
2. Pour chaque fonction, appareil dans l'état **permis par le backend** — allumé pour `sleep` et `health`
   (ajouter `--allumer`), **éteint** pour `clean` et `mildew`, indifférent pour `display` :
   `--concept=<display|sleep|health|clean|mildew> --valeur=1 [--allumer]`, puis `--valeur=0`.
3. Noter pour chaque essai : **(a)** le code métier renvoyé, **(b)** *quel* octet et *quel* bit ont changé,
   **(c)** l'effet physique constaté (afficheur, bruit, ventilation, ou état dans l'application AUX Home).
4. Une fonction passe à `'confirme' => true` — **une ligne** — si et seulement si **(a)** l'ordre est
   accepté, **(b)** le bit attendu bascule **dans les deux sens**, **(c)** un effet est constatable. Sinon
   elle reste à `false`, **avec l'observation consignée en commentaire** dans la table et reportée dans
   `.memory/analyse/smartclim-transport-aux-home.md` § 9.
5. **Bonus d'investigation, à faire tant que le matériel est disponible** : `--intent=eco --valeur=1` puis
   `0`, et `--intent=ultra_silence --valeur=2` puis `1`. Le diff d'octets répond en une commande à la
   question laissée ouverte par la décision D-UC01-Q1 (dette **D2**).
6. **AC1 / AC5** : relancer un **scan** (le profil ne bouge qu'au scan, cf. R9), vérifier l'apparition des
   commandes des **seules** fonctions activées, et l'absence de toute commande pour les autres.
7. **AC6** : basculer la fonction depuis l'application AUX Home, puis « Rafraîchir » dans Jeedom. Puis, sans
   en faire un critère bloquant, essayer la télécommande IR (R4).
8. **Non-régression du § 6.1** : faire échouer volontairement une commande de base (marche/mode/consigne) et
   vérifier que le nouveau message reste compréhensible.

**Attente réaliste** sur un climatiseur **portable** (`m_00010001_portable`) : le jeu réellement livrable
peut se réduire à **`display`** et **`sleep`**. C'est le résultat **normal** de l'AC7, pas un échec d'UC —
le mécanisme, lui, est complet et prêt pour le prochain appareil.

---

## 12. Dette

- **D1** — Classement des codes métier du endpoint de contrôle : table `code => type` dans le transport, et
  suppression de la purge de session sur un refus fonctionnel. À ouvrir dès que la recette fournit un code
  de refus réel. **Candidat de premier rang.**
- **D2** — Éco et Ultra-silence non livrées, faute de bit de lecture. À rouvrir si (et seulement si) le diff
  d'octets du § 11.5 révèle un bit qui bascule.
- **D3** — `screen = 2` (capteur de luminosité) non exposé ; risque d'écrasement du réglage (R11).
- **D4** — `concepts_exclus`, symétrique de `modes_exclus`, pour pouvoir **retirer** un concept d'un profil
  (R8). Aucune fonction du plugin ne sait le faire aujourd'hui.
- **D5** — Gating par mode / profil de capacités par mode (R7), partagée avec UC02 de ce domaine.
- **D6** — `texteTrameHvac()` restera peut-être à exposer côté page admin pour UC03 de ce domaine (codes
  d'erreur).
