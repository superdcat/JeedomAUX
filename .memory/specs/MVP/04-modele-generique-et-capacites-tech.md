# Spec technique — UC04 « Modèle générique, tables de correspondance et profil de capacités »

> **Domaine** : MVP · **Dépend de** : UC03 · **Spec fonctionnelle** :
> `.memory/specs/MVP/04-modele-generique-et-capacites.md`
> Écrite par `/auto-dev` (run `run-20260825-2356`). Arbitrages journalisés :
> `.memory/auto-dev/run-20260825-2356/MVP-04/decisions.md`.

## Objectif et critères couverts

Introduire `smartclimCapabilities` — **table de données unique** traduisant les codes AUX Home en
concepts génériques — et faire produire par le scan UC03, pour chaque climatiseur, un **profil de
capacités persisté** (concepts, modes, vitesses, plage de température, transport, date), affiché en
français sur la page de configuration de l'équipement.
**Aucun appel réseau nouveau, aucune commande Jeedom créée, aucune dépendance.**

| AC | Où il est réalisé | Statut |
|---|---|---|
| **AC1** — profil affiché : modes, vitesses, date, transport, en français | `smartclim::profilsAffichables()` (chaînes déjà traduites) → `sendVarToJS('smartclimProfils', …)` dans `desktop/php/smartclim.php` → `printEqLogic()` dans `desktop/js/smartclim.js` ; bloc « Profil de capacités détecté » de `#eqlogictab` | couvert |
| **AC2** — 16-32 °C, pas 0,5 par défaut | `smartclimCapabilities::TEMP_MIN_DEFAUT/TEMP_MAX_DEFAUT/TEMP_PAS_DEFAUT` (16 / 32 / 0.5) → clé `temperature` du profil, **toujours présente**, + `placeholder` des 3 champs personnalisables | couvert |
| **AC3** — bornes personnalisées non réécrasées par une redétection | Espaces de nommage **disjoints** : détecté dans `configuration.capacites['temperature']`, personnalisé dans `configuration.temp_min` / `temp_max` / `temp_pas`. La détection n'écrit **jamais** `temp_*` — garantie *structurelle*. Lecture effective : `smartclim::bornesTemperature()` | couvert |
| **AC4** — aucun code brut affiché | Tous les libellés viennent de la colonne `libelle` de la table (`__('Refroidissement', __FILE__)`…). Le JS n'assemble aucun libellé : il injecte des chaînes déjà rendues, en `.text()` | couvert |
| **AC5** — aucune valeur sans correspondance vérifiée dans le profil | Règle mécanique unique : **une valeur entre dans le profil si et seulement si sa correspondance en lecture (`'fil'`) n'est pas `null`**. `SILENT`, `MEDIUM_LOW`, `MEDIUM_HIGH` ont `'fil' => null` → absentes. Oscillations : aucune entrée de table → absentes | couvert |
| **AC6** — deux modèles, deux profils différents | La liste `concepts` est dérivée **des trames de cet appareil-ci** (longueur exploitable de `status.control` / `status.running`), pas d'un catalogue de modèles. En revanche `modes`/`vitesses` proviennent du catalogue du transport et seront identiques entre deux appareils AUX Home | **partiel** — à constater « non testable » en recette si un seul appareil, comme la spec l'autorise (cf. R4) |

## Contrats externes

**Aucun appel réseau nouveau.** UC04 consomme **deux champs supplémentaires** de la réponse déjà émise
par UC03, et rien d'autre :

```text
GET https://eu-smthome-api.aux-global.com/app/user_device?getStatus=1   (inchangé, UC03 § 2)
-> data[i].status.control   : trame HVAC hexadecimale « courte » (dernier etat commande)
-> data[i].status.running   : trame HVAC hexadecimale « longue »  (temperature ambiante)
```

Sources retenues :

- `GijsZwegers/com.zwegersit.auxairco` (MIT), `lib/auxcloud/client.ts` — `parseControlState()` :
  marche/arrêt octet 18, mode octet 15 décalé de 5 bits, consigne octets 10 et 12, vitesse « fil »
  octet 13 décalé de 5 bits ; `parseAmbientTemperature()` : ambiante = octet 15 de `status.running`
  moins 32. Recoupé dans `.memory/analyse/smartclim-transport-aux-home.md` §§ 6.1-6.2 (marqué vérifié).
- Table des modes `air_con_func` = `0` AUTO, `1` COOL, `2` DRY, `4` HEAT, `6` FAN — `constants.ts`,
  identique côté backend CN (`latentharbor/ha-aux-a-plus`) et identique au fil
  (`.memory/analyse/smartclim-modele-abstrait-capacites.md` § 3.1, trois implémentations concordantes).
- Table des vitesses **du fil** = `1` HIGH, `2` MEDIUM, `3` LOW, `4` TURBO, `5` AUTO — `constants.ts`,
  corroborée par `fparrav/src/api/broadlink/Protocol.ts` et par le protocole Broadlink LAN.
- Table des vitesses **d'intention** `wind_speed` = `0` LOW, `1` MEDIUM, `2` HIGH, `3` SILENT, `4` AUTO,
  `5` TURBO, `6` MEDIUM_LOW, `7` MEDIUM_HIGH — source **unique**, contestée : cf. D-MVP04-02.
- Bornes 16-32 °C / pas 0,5 : `azadaydinli/ac_freedom/const.py`
  (`.memory/analyse/smartclim-modele-abstrait-capacites.md` § 4.2, vérifié).

**Endpoint volontairement NON appelé** : `GET /app/getConfig?id=deviceMutex` — cf. D-MVP04-01.

### Contrats du core Jeedom vérifiés en source pendant ce plan (à ne pas redécouvrir)

| Fait | Source lue |
|---|---|
| `utils::a2o()` sur `configuration` (tableau) appelle `setConfiguration($sousCle, $valeur)` **par sous-clé** : un enregistrement depuis le formulaire d'équipement **fusionne** et ne détruit pas les clés absentes du DOM (`capacites`, `mac`, `auxhome_device_id`, `modele` survivent) | `core/class/utils.class.php` l. 84-113 + `core/ajax/eqLogic.ajax.php` l. 498-501 (`V4-stable`) |
| `DB::save()` appelle `preSave()` avant l'écriture et `postSave()` après (sauf `$_direct = true`) | `core/class/DB.class.php` l. 178-179, 249-250 |
| `plugin.template.js` **réinitialise** tous les `.eqLogicAttr`, **puis** `setJeeValues(data)`, **puis** appelle `printEqLogic(data)` : pas de report de valeurs d'un équipement à l'autre, et `printEqLogic` s'exécute après le remplissage du formulaire | `core/js/plugin.template.js` l. 97-108 |
| **`preSaveEqLogic()` n'existe pas** : le seul point d'accroche avant enregistrement est `saveEqLogic(eqLogic)`, dont la **valeur de retour remplace** l'objet envoyé au serveur | `core/js/plugin.template.js` l. 307-309 — **contredit le wiki** `doc.jeedom.com/fr_FR/dev/plugin_template`, qui annonce un `preSaveEqLogic`. La source fait foi |
| « Ajouter » enregistre puis **recharge la page** avec le nouvel identifiant : le nouvel équipement est présent dans `sendVarToJS` au rendu suivant | `core/js/plugin.template.js` l. 260-288 |
| `sendVarToJS($nom, $tableau)` émet `JSON.parse("…")` avec `json_encode(..., JSON_UNESCAPED_UNICODE)` puis `addslashes` : balise de fermeture de script et retours ligne neutralisés | `core/php/utils.inc.php` l. 156-168 |

## Architecture — fichiers

| Fichier | État | Ce qui y entre | Indentation / fins de ligne |
|---|---|---|---|
| `core/class/smartclimCapabilities.class.php` | **créé** | Énumérations génériques, table de correspondance par transport, libellés `__()`, bornes par défaut et enveloppe, accesseurs. **Aucune E/S, aucun `config::`, aucun `eqLogic`** | 2 espaces, **CRLF** |
| `core/php/smartclim.inc.php` | modifié | **1 ligne** : `require_once __DIR__ . '/../class/smartclimCapabilities.class.php';` placée **entre** `smartclimException` et `smartclimAuxHomeApi` ; retirer `smartclimCapabilities` de la liste « classes à venir » du commentaire | 2 espaces, CRLF |
| `core/class/smartclimAuxHomeApi.class.php` | modifié | `require_once` de `smartclimCapabilities` (symétrie avec l'existant), `normaliserAppareil()` renvoie 2 clés de plus, `nettoyerTrame()`, `capacitesAppareil()` + table privée des offsets | 2 espaces, **CRLF** (aligné sur les autres fichiers du livrable à l’étape 7 — cf. décision D-MVP04-10 ; `core.autocrlf=true` rend la conversion neutre pour le contenu versionné) |
| `core/class/smartclim.class.php` | modifié | 5 constantes, `enveloppeTemperature()`, `bornesTemperature()`, `profilAffichable()`, `profilsAffichables()`, `appliquerCapacites()`, 2 normaliseurs, `preSave()` rempli, branchement dans `scannerAuxHome()` et `creerEquipement()` | 2 espaces, **CRLF** |
| `desktop/php/smartclim.php` | modifié | `sendVarToJS('smartclimProfils', smartclim::profilsAffichables($eqLogics))`, bloc « Profil de capacités détecté » en colonne droite de `#eqlogictab`, 3 champs « Bornes personnalisées » en colonne gauche | **TABULATIONS**, CRLF |
| `desktop/js/smartclim.js` | modifié | `printEqLogic(_eqLogic)`, `saveEqLogic(_eqLogic)`, 1 ligne dans le `success` du scan pour rafraîchir `smartclimProfils` | 2 espaces, CRLF ; chaînes en **guillemets doubles** |
| `core/ajax/smartclim.ajax.php` | **non touché** | Délibéré : aucune action AJAX nouvelle. Le profil voyage par `sendVarToJS` (rendu) et par la charge utile déjà existante de `scannerClimatiseurs` | — |
| `core/config/smartclim.config.ini`, `plugin_info/configuration.txt`/`.php`, `packages.json`, `info.json`, `core/i18n/*.json`, `core/template/` | **non touchés** | Aucune clé de **config plugin** (les bornes sont **par équipement**), aucune dépendance, aucun défaut INI : pas de duplication de littéral, pas de miroir à resynchroniser, `pluginVersion` bumpée par le hook `pre-commit` | — |

## Server vs Client

**Tout le rendu de texte est serveur.** `smartclim::profilsAffichables()` produit des chaînes **déjà
traduites** (`__()` côté PHP) ; `sendVarToJS` les transporte ; le JS se contente de les injecter en
`.text()`. Justification : AC4 exige des libellés français, et dupliquer une table de libellés en JS
créerait une seconde source de vérité (interdit par la règle « table de données unique » de
`CLAUDE.md`). Le client ne porte que deux responsabilités : le remplissage du bloc d'affichage
(`printEqLogic`) et la normalisation défensive des bornes saisies (`saveEqLogic`).

## Signatures et responsabilités

### `smartclimCapabilities` (nouveau) — la table, et rien d'autre

```php
const TRANSPORT_AUX_HOME = 'AUX_HOME';

const CONCEPT_ONLINE='online'; CONCEPT_POWER='power'; CONCEPT_MODE='mode';
const CONCEPT_TARGET_TEMP='target_temp'; CONCEPT_AMBIENT_TEMP='ambient_temp'; CONCEPT_FAN_SPEED='fan_speed';

const MODE_AUTO='AUTO'; MODE_COOL='COOL'; MODE_DRY='DRY'; MODE_HEAT='HEAT'; MODE_FAN='FAN';
const VITESSE_AUTO='AUTO'; VITESSE_SILENT='SILENT'; VITESSE_LOW='LOW'; VITESSE_MEDIUM_LOW='MEDIUM_LOW';
const VITESSE_MEDIUM='MEDIUM'; VITESSE_MEDIUM_HIGH='MEDIUM_HIGH'; VITESSE_HIGH='HIGH'; VITESSE_TURBO='TURBO';

const TEMP_MIN_DEFAUT = 16;  const TEMP_MAX_DEFAUT = 32;  const TEMP_PAS_DEFAUT = 0.5;
const TEMP_ENVELOPPE_MIN = 5; const TEMP_ENVELOPPE_MAX = 35;   // bornes PERSONNALISEES admissibles

private static function tables()                                   // array — LA table
public  static function valeursLisibles($_transport, $_concept)     // array<string> — valeurs dont 'fil' !== null, ORDRE de declaration
public  static function versTransport($_transport, $_concept, $_valeurGenerique)  // int|null  (colonne 'intent')
public  static function depuisTransport($_transport, $_concept, $_codeFil)        // string|null (valeur generique)
public  static function libelle($_concept, $_valeurGenerique)       // string traduit ; '' si inconnu
public  static function libelleConcept($_concept)                   // string traduit ; '' si inconnu
public  static function libelleTransport($_transport)               // 'AUX Home' ; '' si inconnu
public  static function conceptsConnus()                            // array<string> — ordre d'affichage canonique
public  static function bornesParDefaut()                           // array{min,max,pas}
public  static function enveloppeBornes()                           // array{min,max,pasAutorises}
```

`versTransport()` / `depuisTransport()` **renvoient `null`** quand la correspondance est absente — jamais
de repli silencieux (`.memory/analyse/smartclim-modele-abstrait-capacites.md` § 3.6). Elles ne sont
**pas** consommées par UC04 : elles existent pour que le contrat « absence implique `null` » vive à **un
seul endroit** et qu'UC05/UC06 n'aillent pas lire le tableau brut. Aucune autre méthode spéculative.

### `smartclimAuxHomeApi` (modifiée) — seule à connaître les octets et les noms de champs AUX

```php
// Longueur MINIMALE (en octets) de status.control / status.running requise par concept.
private static function offsetsAuxHome()   // array{control:array<concept,int>, running:array<concept,int>}

/** Ligne normalisee enrichie de 2 cles (additif : les 5 cles d'UC03 sont inchangees).
 *  @return array{mac,identifiant,nom,modele,enLigne,trame_controle:string,trame_running:string} */
private static function normaliserAppareil($_brut)

/** Hex minuscule nu, ou '' si inexploitable (non scalaire, non hex, longueur impaire, moins de 2 caracteres). */
private static function nettoyerTrame($_valeur)

/** Profil de capacites GENERIQUE de CET appareil, via CE transport. Ne leve jamais.
 *  @param array $_appareil ligne normalisee de listerAppareils()
 *  @return array{concepts:array<string>, modes:array<string>, vitesses:array<string>,
 *                temperature:array{min,max,pas}, source:string} */
public static function capacitesAppareil(array $_appareil)
```

`capacitesAppareil()` ne renvoie **aucun** nom de champ AUX, aucun code propriétaire : uniquement des
codes génériques. `status.control`, `status.running`, `wind_speed`, `air_con_func` ne franchissent jamais
la frontière de ce fichier.

### `smartclim` (modifiée) — orchestration, persistance, rendu

```php
const CLE_CONF_CAPACITES = 'capacites';
const CLE_CONF_TEMP_MIN  = 'temp_min';
const CLE_CONF_TEMP_MAX  = 'temp_max';
const CLE_CONF_TEMP_PAS  = 'temp_pas';
const VERSION_PROFIL     = 1;

public  static function enveloppeTemperature()          // delegation vers smartclimCapabilities::enveloppeBornes()
public  static function profilsAffichables(array $_eqLogics)  // array<int idEqLogic, array profilAffichable>
public  function bornesTemperature()                    // array{min,max,pas,personnalise}
public  function profilAffichable()                     // array de chaines DEJA traduites
private function appliquerCapacites(array $_detecte)    // bool — true si le profil stocke a change
private static function normaliserBorneTemperature($_valeur)  // string ('' = non personnalise)
private static function normaliserPasTemperature($_valeur)    // string ('' | '0.5' | '1')
public  function preSave()                              // normalise temp_min/temp_max/temp_pas — ne leve JAMAIS
private static function formaterDegre($_valeur)         // '0.5' -> '0,5' ; '16' -> '16'
```

`profilAffichable()` renvoie **uniquement des chaînes prêtes à l'affichage** (aucun code, aucune donnée
d'origine externe) :

```text
array(
  'detecte'      => bool,                  // false : le JS affiche l'unique message de repli
  'concepts'     => 'Disponibilite, Marche/Arret, Mode, Consigne de temperature, Temperature ambiante, Vitesse de ventilation',
  'modes'        => 'Automatique, Refroidissement, Deshumidification, Chauffage, Ventilation',
  'vitesses'     => 'Automatique, Faible, Moyen, Fort, Turbo',
  'temperature'  => '16 °C a 32 °C, pas de 0,5 °C',        // plage DETECTEE (defaut du transport)
  'effectives'   => '18 °C a 30 °C, pas de 0,5 °C',        // plage REELLEMENT appliquee
  'personnalise' => bool,
  'source'       => 'AUX Home',
  'detecteLe'    => '25/08/2026 22:41',
  'placeholderMin' => '16', 'placeholderMax' => '32', 'placeholderPas' => '0,5'
)
```

### Branchement dans `scannerAuxHome()` (modifications minimales du code UC03 livré)

1. Dans la boucle par appareil, **avant** le rapprochement :
   `$capacites = smartclimAuxHomeApi::capacitesAppareil($appareil);` — appel direct autorisé, `smartclim::`
   **est** le routeur.
2. Branche « équipement existant » : `if ($eqLogic->appliquerCapacites($capacites)) { $modifie = true; }`
   — se fond dans le `$modifie` existant : **un seul `save()`, conditionné**.
3. Branche « création » : `creerEquipement()` prend `$_capacites` en paramètre et pose
   `configuration.capacites` **avant** son `save()` unique.
4. Après la boucle : `'profils' => smartclim::profilsAffichables(...)` ajouté à la valeur de retour.

**Invariant UC03 à préserver** (point de recette 3 d'UC03) : un scan strictement identique ne doit émettre
**aucun** `save()`.

### Côté client

```js
function printEqLogic(_eqLogic)   // lit smartclimProfils[_eqLogic.id], injecte en .text() dans les <span> du bloc
                                  // + pose les 3 placeholder ; ne touche AUCUN champ .eqLogicAttr
function saveEqLogic(_eqLogic)    // normalise temp_min/temp_max/temp_pas, alerte si correction, RETOURNE _eqLogic
```

**`saveEqLogic()` DOIT terminer par `return _eqLogic;`** : le core fait `eqLogic = saveEqLogic(eqLogic)`
(l. 307-309) — un `return` oublié pousse `undefined` dans la charge utile et **l'enregistrement de
l'équipement est perdu**, silencieusement. C'est le seul piège fatal de ce cycle côté JS.
`printEqLogic()` ne touche que des éléments **non liés** à `.eqLogicAttr` (des `span` et des
`placeholder`) : aucun effet de bord possible sur les valeurs du formulaire.

## Structure de données — la table de correspondance

`smartclimCapabilities::tables()`, structure `TRANSPORT -> concept -> valeur générique -> correspondances` :

```text
'AUX_HOME' => array(
  'mode' => array(
    'AUTO' => array('intent'=>0, 'fil'=>0, 'intent_confirme'=>true, 'libelle'=>__('Automatique', __FILE__)),
    'COOL' => array('intent'=>1, 'fil'=>1, 'intent_confirme'=>true, 'libelle'=>__('Refroidissement', __FILE__)),
    'DRY'  => array('intent'=>2, 'fil'=>2, 'intent_confirme'=>true, 'libelle'=>__('Deshumidification', __FILE__)),
    'HEAT' => array('intent'=>4, 'fil'=>4, 'intent_confirme'=>true, 'libelle'=>__('Chauffage', __FILE__)),
    'FAN'  => array('intent'=>6, 'fil'=>6, 'intent_confirme'=>true, 'libelle'=>__('Ventilation', __FILE__)),
  ),
  'fan_speed' => array(
    'AUTO'        => array('intent'=>4, 'fil'=>5,    'intent_confirme'=>false, 'libelle'=>__('Automatique', __FILE__)),
    'SILENT'      => array('intent'=>3, 'fil'=>null, 'intent_confirme'=>false, 'libelle'=>__('Silencieux', __FILE__)),
    'LOW'         => array('intent'=>0, 'fil'=>3,    'intent_confirme'=>false, 'libelle'=>__('Faible', __FILE__)),
    'MEDIUM_LOW'  => array('intent'=>6, 'fil'=>null, 'intent_confirme'=>false, 'libelle'=>__('Moyen-faible', __FILE__)),
    'MEDIUM'      => array('intent'=>1, 'fil'=>2,    'intent_confirme'=>false, 'libelle'=>__('Moyen', __FILE__)),
    'MEDIUM_HIGH' => array('intent'=>7, 'fil'=>null, 'intent_confirme'=>false, 'libelle'=>__('Moyen-fort', __FILE__)),
    'HIGH'        => array('intent'=>2, 'fil'=>1,    'intent_confirme'=>false, 'libelle'=>__('Fort', __FILE__)),
    'TURBO'       => array('intent'=>5, 'fil'=>4,    'intent_confirme'=>true,  'libelle'=>__('Turbo', __FILE__)),
  ),
)
```

Sémantique des trois colonnes, **à écrire dans le docblock du fichier** :

- **`intent`** — valeur du champ envoyé en écriture (`air_con_func`, `wind_speed`). **Non consommée par UC04.**
- **`fil`** — valeur lue dans la trame HVAC (`status.control` octet 15 décalé de 5 bits pour le mode,
  octet 13 décalé de 5 bits pour la vitesse). **`null` = aucune correspondance en lecture, donc la valeur
  n'entre JAMAIS dans un profil.** C'est l'unique mécanisme d'AC5, et il n'a besoin d'aucun drapeau.
- **`intent_confirme`** — marqueur de recette destiné à UC06 : `false` = code d'écriture issu d'une source
  unique et contestée. **UC04 ne le lit pas** ; il documente ce qu'il faudra tester vitesse par vitesse.
  `TURBO` est à `true` parce que c'est la **seule** valeur sur laquelle les deux sources contradictoires
  concordent.

**Ce qui n'est PAS dans la table, délibérément** : oscillations (aucune lecture par axe possible),
fonctions de confort (post-mvp/04), transports `BROADLINK_LAN` et `AUX_CLOUD_LEGACY` (post-mvp/01 et 03),
échelles de température par transport (UC06). Le docblock indique l'emplacement prévu, sans code mort.

### Profil persisté — `configuration.capacites`

```text
array(
  'version'     => 1,
  'concepts'    => array('online','power','mode','target_temp','ambient_temp','fan_speed'),
  'modes'       => array('AUTO','COOL','DRY','HEAT','FAN'),
  'vitesses'    => array('AUTO','LOW','MEDIUM','HIGH','TURBO'),
  'temperature' => array('min'=>16, 'max'=>32, 'pas'=>0.5),
  'source'      => 'AUX_HOME',
  'detecte_le'  => 1787000000,     // epoch entier
)
```

Codes de concept **identiques aux futurs `logicalId` de commandes info**
(`.memory/analyse/smartclim-architecture-jeedom.md` § 5.1) : UC05 n'aura rien à renommer. Ordre des clés
**fixe** (indispensable à la comparaison). Aucune trame brute n'est persistée.

## Stratégie de détection

### Ce qu'on n'interroge pas, et pourquoi

`GET /app/getConfig?id=deviceMutex` **n'est pas appelé**. `.memory/brief.md` § 2 (constat de
l'utilisateur, requête réellement jouée) : « Cette table est générique et ne signifie pas que chaque
appareil supporte toutes ces fonctions. » Un profil qui en dériverait serait **identique pour tous les
appareils** — exactement ce contre quoi AC6 met en garde — au prix d'une requête réseau supplémentaire
dans le budget `BUDGET_SCAN` et d'un schéma inconnu à parser.

### Règles, par appareil

| Concept | Condition d'entrée dans `concepts` |
|---|---|
| `online` | **toujours** — provient du champ `online` de `/app/user_device` (contrat vérifié d'UC03), pas d'une trame |
| `target_temp` | `trame_controle` exploitable et au moins **13** octets (offsets 10 et 12) |
| `fan_speed` | `trame_controle` au moins **14** octets (offset 13) |
| `mode` | `trame_controle` au moins **16** octets (offset 15) |
| `power` | `trame_controle` au moins **19** octets (offset 18) |
| `ambient_temp` | `trame_running` exploitable et au moins **16** octets (offset 15) |

- `modes` = `valeursLisibles('AUX_HOME','mode')` **si et seulement si** `mode` est dans `concepts`, sinon
  tableau vide.
- `vitesses` = `valeursLisibles('AUX_HOME','fan_speed')` **si et seulement si** `fan_speed` est dans
  `concepts`, sinon tableau vide.
- `temperature` = `bornesParDefaut()` — **toujours**, indépendamment des trames. Ce n'est pas une
  détection : c'est le défaut documenté du transport, et l'affichage le dit. AC5 ne vise que « un mode ou
  une vitesse » ; AC2 exige inconditionnellement 16-32 / 0,5.

### Le cas « le cloud ne dit rien »

Si `status` est absent, non tableau, ou si les trames sont vides / non hexadécimales / trop courtes
(appareil hors ligne, firmware non-uart, réponse tronquée) : `concepts` se réduit à `array('online')`,
`modes` et `vitesses` sont **vides**. **Aucun profil générique de repli n'est inventé** — lecture stricte
d'AC5.

### Profil de repli et ordre canonique (obligatoire — chemin le plus emprunté)

**Aucun équipement créé par UC03 ne possède la clé `configuration.capacites`.** Le premier scan après
déploiement d'UC04 passe donc systématiquement par le cas « profil absent ». Une méthode privée unique
sert de repli, et elle est utilisée **à la fois** par `appliquerCapacites()` et par `profilAffichable()`
— pour qu'elles ne puissent pas diverger sur ce même cas :

```php
private static function profilVide()   // array
// array('version'=>self::VERSION_PROFIL, 'concepts'=>array(), 'modes'=>array(), 'vitesses'=>array(),
//       'temperature'=>smartclimCapabilities::bornesParDefaut(), 'source'=>'', 'detecte_le'=>0)
```

Règle d'usage : `$actuel = $this->getConfiguration(self::CLE_CONF_CAPACITES);` puis, si le résultat n'est
**pas un tableau** (absent, `''`, `null`, ou corrompu), `$actuel = self::profilVide();`. Chaque clé
attendue est ensuite lue avec un contrôle `is_array()` avant usage — jamais d'indexation directe, qui
produirait un avertissement PHP sur clé indéfinie. `profilAffichable()` conserve son propre verdict
`'detecte' => false` lorsque `source` est vide ou `detecte_le` vaut `0`.

**Ordre canonique — quelle méthode fait référence pour quel champ** (ne pas laisser à l'interprétation) :

| Champ fusionné | Ordre de référence |
|---|---|
| `concepts` | `smartclimCapabilities::conceptsConnus()`, filtré sur l'ensemble fusionné |
| `modes` | `smartclimCapabilities::valeursLisibles('AUX_HOME', 'mode')`, filtré sur l'ensemble fusionné |
| `vitesses` | `smartclimCapabilities::valeursLisibles('AUX_HOME', 'fan_speed')`, filtré sur l'ensemble fusionné |

Une valeur fusionnée **absente de l'ordre de référence** (profil écrit par une version antérieure, valeur
retirée de la table depuis) est **conservée en fin de liste, dans son ordre d'apparition** : l'union ne
retire jamais rien, y compris ce qu'elle ne sait plus ordonner. Sans ce filtrage par un ordre stable,
deux ensembles égaux mais ordonnés différemment compareraient « différents » et provoqueraient une
écriture — donc un `save()` — **à chaque scan** (R10).

Le garde-fou contre l'appauvrissement accidentel est la **règle d'union**
(`smartclim-modele-abstrait-capacites.md` § 4.3 règle 3) :

```text
fusion['concepts'] = ordre_canonique( union(actuel['concepts'], detecte['concepts']) )
fusion['modes']    = ordre_canonique( union(actuel['modes'],    detecte['modes'])    )
fusion['vitesses'] = ordre_canonique( union(actuel['vitesses'], detecte['vitesses']) )
fusion['temperature'] = detecte['temperature']       // defaut transport, jamais les bornes personnalisees
fusion['source']      = detecte['source']
comparer json_encode(fusion sans 'detecte_le') a json_encode(actuel sans 'detecte_le')
  different -> fusion['detecte_le'] = time() ; setConfiguration('capacites', fusion) ; return true
  identique -> return false     // AUCUNE ecriture, AUCUN save()
```

Conséquences voulues :

1. **Un profil ne s'ampute jamais** : un scan lancé pendant que le climatiseur est hors ligne ne peut pas
   faire disparaître ses capacités (et donc, en UC05, ses commandes).
2. **AC5 reste vrai** : l'union de deux ensembles construits uniquement de valeurs à `'fil' !== null` ne
   contient que de telles valeurs.
3. **`detecte_le` ne bouge que si le profil change** : le point de recette 3 d'UC03 (« aucun `save()` sur
   un scan identique ») est préservé.
4. **Le réordonnancement canonique est obligatoire** : sans lui, deux ensembles égaux mais ordonnés
   différemment compareraient « différents » et provoqueraient une écriture à chaque scan.

### Ce qui déclenche une détection

**Uniquement le scan `smartclim::scannerAuxHome()`** (bouton « Scanner les climatiseurs » d'UC03), pour
**tous** les appareils rapportés, créés ou existants. Pas de bouton « redétecter » par équipement, pas de
`cronDaily()` (UC07). AC3 est satisfait : « relancer un scan » est bien le geste de redétection.

### Rafraîchissement de l'affichage

`sendVarToJS` est rendu au chargement de la page ; un scan lancé ensuite rendrait l'affichage périmé —
cas le **plus fréquent au déploiement d'UC04** (équipements créés en UC03, sans profil, qui en gagnent un
au premier scan). Le `success` du scan fait donc, en une ligne :
`if (resultat.profils) { $.extend(smartclimProfils, resultat.profils) }`.
Aucun rechargement de page requis, bouton « Afficher les nouveaux équipements » d'UC03 inchangé.
Si `smartclimProfils` est un tableau vide (cas « aucun équipement »), `$.extend` le transforme en objet ;
la lecture par identifiant fonctionne dans les deux cas.

## Validation

### Bornes personnalisées — double barrière

| Champ | Client (`saveEqLogic`) | Serveur (`preSave`) | Stockage |
|---|---|---|---|
| `temp_min`, `temp_max` | `input type="text"`. Virgule vers point ; vide ou non numérique vers `''` ; hors `[5, 35]` ramené dans l'enveloppe ; `min >= max` remet **les deux** à `''`. Une correction déclenche une alerte `warning`. **Jamais de `throw`** | Même règle, **silencieuse**, `log::add(…,'warning',…)` sur correction | **chaîne** canonique (`'18'`, `'18.5'`) ou `''` |
| `temp_pas` | `select` à **3 options exactement** : `''` (« Valeur détectée »), `'0.5'`, `'1'` | Toute valeur hors de cet ensemble devient `''` | `''`, `'0.5'` ou `'1'` |

- **`''` signifie « non personnalisé »** et rien d'autre. La détection n'écrit jamais la valeur détectée
  dans `temp_*` : cela rendrait la personnalisation indiscernable du défaut et **gèlerait** les bornes
  contre toute redétection future. C'est le cœur d'AC3.
- **Aucun `throw` dans `preSave()`** : ce hook est aussi traversé par le `save()` du scan ; une exception
  y transformerait un équipement à configuration douteuse (écriture SQL, restauration) en erreur
  récurrente à chaque scan. Le message utilisateur est porté par le client, la robustesse par le serveur.
- **`type="text"` et non `type="number"`** : avec `type="number"`, une saisie invalide fait renvoyer `''`
  par `.value` dans Chrome/Firefox — la personnalisation disparaîtrait **silencieusement**, ce qui
  ressemblerait à une violation d'AC3.
- **`select` pour le pas** : supprime toute analyse syntaxique et respecte le piège d'UC01 (« une option
  doit toujours porter la valeur enregistrée ») — l'ensemble des options couvre **exactement** l'ensemble
  des valeurs que `preSave()` peut écrire.

### Lecture

`smartclim::bornesTemperature()` applique, dans l'ordre : valeur personnalisée valide, puis valeur du
profil détecté, puis constantes `smartclimCapabilities::TEMP_*_DEFAUT`. Elle revalide la valeur
personnalisée à la lecture (double barrière) et convertit les chaînes en `float` à ce seul endroit.

### Exceptions

- `smartclimAuxHomeApi::capacitesAppareil()` est **totale** : elle ne lève jamais, quel que soit le
  contenu de la ligne d'appareil (contrôles `is_array`/`is_string`). Au pire elle renvoie
  `concepts => array('online')`.
- `appliquerCapacites()` ne peut lever que par `eqLogic::save()`, déjà couvert par le `try/catch
  (Exception)` puis `catch (Throwable)` **par appareil** d'UC03 : l'appareil est compté en erreurs, le
  scan continue.
- `profilAffichable()` sur un `capacites` corrompu (non tableau, clés manquantes) renvoie
  `array('detecte' => false)`, jamais d'erreur PHP.
- **Aucun type d'exception nouveau**, aucun message d'erreur nouveau : `smartclimException` et
  `messageErreurAuxHome()` sont réutilisés tels quels. UC04 n'ajoute aucun chemin réseau.

### Sécurité

- Aucune donnée d'origine externe dans la charge utile `profils` (uniquement des littéraux traduits, des
  entiers et une date formatée) : rien à assainir côté `sendVarToJS`, et le JS injecte en `.text()`.
- Les trames HVAC ne sont **ni journalisées ni persistées** ; seule leur longueur est journalisée en
  `debug` quand un concept est écarté.
- `nettoyerTrame()` est une frontière d'assainissement au même titre que `nettoyerTexteExterne()` : hex
  minuscule uniquement, donc aucune injection de log possible.
- Aucun secret, aucun jeton, aucun identifiant de session, aucun e-mail dans le nouveau contenu.

## Server Actions / API

**Aucune action AJAX nouvelle.** `core/ajax/smartclim.ajax.php` n'est pas modifié. Les deux canaux
existants suffisent :

1. **Rendu de page** — `desktop/php/smartclim.php` appelle `smartclim::profilsAffichables($eqLogics)` et
   émet `sendVarToJS('smartclimProfils', …)`.
2. **Réponse du scan** — `smartclim::scannerAuxHome()` (action `scannerClimatiseurs` déjà existante)
   ajoute une clé `profils` à sa valeur de retour ; le `success` JS fusionne dans `smartclimProfils`.

## Dépendances

**Aucune.** PHP natif uniquement (opérations de bits, `hexdec`, `substr`). `plugin_info/packages.json`
reste vide, `hasDependency` et `hasOwnDeamon` restent à `false`.

## Impact i18n (français seulement dans ce cycle)

**Ne toucher à aucun `core/i18n/*.json`** : la traduction est faite en fin de cycle par le sous-agent
`translator`, sur le code figé.

- **`core/class/smartclimCapabilities.class.php`** — `__('…', __FILE__)`, littéraux dans la table :
  modes « Automatique », « Refroidissement », « Déshumidification », « Chauffage », « Ventilation » ;
  vitesses « Automatique » (même clé), « Silencieux », « Faible », « Moyen-faible », « Moyen »,
  « Moyen-fort », « Fort », « Turbo » ; concepts « Disponibilité », « Marche/Arrêt », « Mode »,
  « Consigne de température », « Température ambiante », « Vitesse de ventilation ».
  « Silencieux », « Moyen-faible » et « Moyen-fort » sont volontairement définis alors que ces vitesses
  n'entrent pas dans le profil au MVP : l'extraction est statique, donc les clés seront traduites dès
  maintenant et une confirmation en recette ne demandera qu'un passage de `'fil' => null` à une valeur.
  « AUX Home » est un **nom de marque, sans `__()`**.
- **`core/class/smartclim.class.php`** — `__('…', __FILE__)` : le gabarit de plage de température
  (arguments **positionnels**, enveloppé **avant** `sprintf`), « Valeur par défaut du transport »,
  « Bornes personnalisées ».
- **`desktop/php/smartclim.php`** — clés de traduction HTML : « Profil de capacités détecté »,
  « Fonctions détectées », « Modes disponibles », « Vitesses de ventilation », « Plage de température »,
  « Détecté le », « Transport source », « Bornes de température personnalisées », « Température
  minimale », « Température maximale », « Pas de réglage », « Valeur détectée », « Laisser vide pour
  utiliser la valeur détectée ».
- **`desktop/js/smartclim.js`** — **guillemets doubles obligatoires** (apostrophes en français) :
  « Aucun profil de capacités détecté — lancez un scan des climatiseurs », « Bornes de température
  corrigées : vérifiez les valeurs saisies ».

`desktop/php/*.php` et `desktop/js/*.js` sont des fichiers **rendus** : aucune méta-séquence littérale
(double accolade ouvrante, délimiteur de fin de commentaire collé à du texte, balise fermante PHP en
commentaire), pas même en commentaire. **Lancer `python .claude/scripts/verif-plugin.py` avant tout
commit.**

**Contrat transmis à UC06** : les libellés de mode canoniques sont « Refroidissement » et « Chauffage ».
La section i18n d'UC06 anticipe « Froid » et « Chaud » — il faudra **réutiliser
`smartclimCapabilities::libelle()`**, sans quoi l'interface affichera deux mots différents pour la même
notion.

## Risques et pièges

1. **R1 — Table `wind_speed` d'écriture non confirmée.** Les codes `intent` des vitesses viennent d'une
   source unique contredite par le backend CN. UC04 ne les utilise pas, mais UC06 en dépend entièrement.
   Le marqueur `intent_confirme` liste exactement ce qu'il faut tester. *Impact d'une erreur : commander
   « Auto » règle en fait « Fort ».*
2. **R2 — Les oscillations sortent du MVP.** Aucune commande d'oscillation ne sera créée en UC05/UC06
   tant que la lecture par axe n'est pas établie. **UC05 AC2 devient non applicable.** Perte
   fonctionnelle réelle et assumée (cf. D-MVP04-03).
3. **R3 — `status.control` d'un appareil hors ligne.** Comportement inconnu : si le cloud renvoie des
   trames vides pour un appareil éteint, le premier scan produit un profil réduit à `online`. L'union le
   rattrape au scan suivant, mais l'utilisateur peut voir temporairement un profil quasi vide.
4. **R4 — AC6 structurellement limité.** `modes`/`vitesses` viennent du catalogue du transport : deux
   appareils AUX Home auront les mêmes listes. Seule la ligne `concepts` peut différer.
5. **R5 — Pas de désenrichissement.** L'union ne retire jamais rien : une capacité entrée à tort ne peut
   être retirée qu'en supprimant/recréant l'équipement.
6. **R6 — Pas de 0,5 °C contre intention entière.** Le fil lit le demi-degré, mais le champ de
   température de l'intention est un **entier** dans la source EU. Si la consigne n'est pilotable qu'au
   degré, le pas par défaut devra passer à `1` — le `select` par équipement le permet déjà, et le défaut
   est une constante à un seul endroit. **Point à trancher en UC06.**
7. **R7 — `saveEqLogic()` sans `return`.** Perte silencieuse d'un enregistrement d'équipement.
8. **R8 — ~~`smartclimAuxHomeApi.class.php` est en LF seul~~ — risque levé (décision D-MVP04-10).**
   Le fichier a été aligné sur **CRLF** à l'étape 7 : `core.autocrlf=true` étant actif et
   `.gitattributes` ne portant aucun `text=auto`, git stocke le contenu en LF dans tous les cas — la
   conversion a laissé `git diff --numstat` inchangé (116 ajouts / 3 suppressions). Le diff massif
   redouté n'existait pas.
9. **R9 — Oubli d'autoload.** `smartclimCapabilities` non ajoutée à `core/php/smartclim.inc.php` produit
   un « Class not found » au runtime, **invisible à `php -l` et à la CI**.
10. **R10 — Écriture à chaque scan.** Si l'ordre canonique ou la comparaison sans `detecte_le` est mal
    implémenté, chaque scan réécrit tous les équipements et invalide le point de recette 3 d'UC03.

## Points de recette (au-delà des critères d'acceptation)

1. Relever la valeur brute de `status.control` / `status.running` au premier scan (longueur en octets) et
   **mettre à jour `.memory/analyse/smartclim-transport-aux-home.md` § 6** avec le constat.
2. Modifier les bornes d'un équipement, **Sauvegarder**, puis vérifier que `capacites`, `mac`,
   `auxhome_device_id` et `modele` sont **toujours** présents en base.
3. Relancer un scan strictement identique et vérifier **en log `debug`** qu'aucun `save()` n'est émis
   (non-régression UC03).
4. Ouvrir un équipement avec bornes personnalisées puis un autre sans : les champs du second doivent être
   **vides**.
5. Scanner alors que le climatiseur est **hors ligne**, puis en ligne : le profil ne doit pas s'appauvrir.
6. **Pour UC06** : commander successivement chacune des 5 vitesses et relire l'afficheur du climatiseur,
   pour valider ou infirmer la colonne `intent`.
7. **Pour débloquer R2** : relever l'octet 11 de `status.control` dans les 4 combinaisons oscillation
   verticale/horizontale. Deux valeurs distinctes rendent les entrées d'oscillation ajoutables **par
   simple édition de la table**.
