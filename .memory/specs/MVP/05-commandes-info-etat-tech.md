# Spec technique — UC05 « Commandes info : lecture de l'état du climatiseur »

> **Domaine** : MVP · **Dépend de** : UC03 (scan), UC04 (profil de capacités)
> Spec fonctionnelle : `.memory/specs/MVP/05-commandes-info-etat.md`
> Décisions automatiques de ce cycle : `.memory/auto-dev/run-20260825-2356/MVP-05/decisions.md`

## Objectif et critères couverts

Créer, pour chaque climatiseur, les **commandes info** correspondant aux concepts de son profil de
capacités (UC04), et **appliquer à ces commandes un état normalisé** décodé depuis les trames déjà
rapportées par le scan UC03. Aucun appel réseau nouveau, aucune classe nouvelle, aucune dépendance. Le
déclenchement périodique (UC07) et les commandes action (UC06) restent hors périmètre : seule une
surface d'appel minimale leur est laissée.

| AC | Où il est réalisé | Statut |
|---|---|---|
| **AC1** — 5 commandes info présentes | `smartclim::creerCommandesInfo()` pilotée par `configuration.capacites['concepts']` ; `online` s'y ajoute (il est dans le profil depuis UC04) | couvert |
| **AC2** — commandes d'oscillation | **NON APPLICABLE dans ce cycle.** Le concept d'oscillation est **absent du profil de capacités UC04** (aucune entrée dans `smartclimCapabilities`, aucune lecture par axe établie sur AUX Home — cf. D-MVP04-03). Rien n'est inventé ici : ni concept, ni commande, ni entrée de table. Voir D-MVP05-01 | non applicable |
| **AC3** — état modifié à la télécommande IR reflété après un cycle | `smartclimAuxHomeApi::etatAppareil()` décode `status.control` (marche/arrêt, mode, vitesse) ; `smartclim::appliquerEtat()` pousse via `checkAndUpdateCmd()`. Le « cycle de rafraîchissement » d'UC05 = **relancer le scan** (le cron est UC07) | couvert — **à valider en recette** (cf. R1) |
| **AC4** — consigne au demi-degré | `(octet[10] >> 3) + 8`, `+0,5` si `octet[12] & 0x80` ; commande `numeric` **sans** `minValue`/`maxValue` | couvert — à valider en recette |
| **AC5** — transport en toutes lettres | commande `transport`, valeur = `smartclimCapabilities::libelleTransport('AUX_HOME')` -> `AUX Home` | couvert |
| **AC6** — horodatage figé si rien n'a changé | commande `last_update` alimentée **uniquement** si au moins un `checkAndUpdateCmd()` de concept a renvoyé `true` (§ « AC6 en détail ») | couvert — à valider en recette (point 3) |
| **AC7** — réglages utilisateur non réinitialisés | Garantie **structurelle** : `creerCommandesInfo()` ne touche **jamais** une commande existante. Aucune propriété n'est reposée après création | couvert |
| **AC8** — aucune commande pour une fonction non détectée | Le seul déclencheur de création est l'appartenance à `capacites['concepts']` (hors 2 commandes méta, explicitement prévues par la spec) | couvert |
| **AC9** — capacité disparue implique commande conservée | Double garantie : le profil ne s'ampute jamais (règle d'union UC04) **et** UC05 ne supprime aucune commande, jamais | couvert |
| **AC10** — vitesse non confirmable | Règle « valeur non confirmable = commande non touchée » (§ « AC10 en détail ») | **partiel** : la moitié réalisable ici est couverte ; la mémoire « dernière vitesse commandée » suppose un émetteur de commandes -> **UC06** |
| **AC11** — avertissement température ambiante | `plugin_info/configuration.txt` (-> `.php` par `cp`), fieldset « Rafraîchissement », **une phrase ajoutée** ; **plus** `generic_type` laissé **vide** sur `ambient_temp` (§ « AC11 en détail ») | couvert |

## Contrats externes

**Aucun appel réseau nouveau.** UC05 consomme les **deux mêmes champs** qu'UC04, déjà présents dans la
ligne normalisée de `listerAppareils()` (`trame_controle`, `trame_running`), et en décode cette fois le
**contenu** (UC04 n'en mesurait que la longueur).

```text
GET https://eu-smthome-api.aux-global.com/app/user_device?getStatus=1   (inchangé, UC03)
-> data[i].status.control  : trame HVAC « courte »  (dernier état commandé)
-> data[i].status.running  : trame HVAC « longue »  (température ambiante)
-> data[i].online          : booléen (déjà normalisé en 'enLigne' par UC03)
```

Décodage retenu — source : `.memory/analyse/smartclim-transport-aux-home.md` §§ 6.1–6.3 (marqué
« vérifié », établi sur `GijsZwegers/com.zwegersit.auxairco` — MIT — `lib/auxcloud/client.ts` :
`parseControlState()` / `parseAmbientTemperature()`, recoupé `constants.ts::WIRE_FAN_TO_HOMEY`) :

| Concept | Trame | Extraction | Table de correspondance |
|---|---|---|---|
| `power` | `control` | `(octet[18] >> 5) & 1` | aucune (booléen direct) |
| `mode` | `control` | `octet[15] >> 5` | `depuisTransport('AUX_HOME','mode', $code)` (colonne `fil`) |
| `target_temp` | `control` | `(octet[10] >> 3) + 8`, `+0,5` si `octet[12] & 0x80` | aucune |
| `fan_speed` | `control` | `octet[13] >> 5` | `depuisTransport('AUX_HOME','fan_speed', $code)` (colonne `fil` : 1=HIGH, 2=MEDIUM, 3=LOW, 4=TURBO, 5=AUTO) |
| `ambient_temp` | `running` | `octet[15] - 32` (degrés entiers) | aucune |
| `online` | — | champ `online` de `/app/user_device` | aucune |

**La colonne `intent` n'est lue nulle part dans ce cycle** : UC05 ne consomme que `fil` (lecture) via
`depuisTransport()`, et `libelle()`/`libelleTransport()` pour l'affichage. Le marqueur
`intent_confirme => false` d'UC04 reste sans effet ici.

**Bornes de plausibilité** : `(octet[10] >> 3) + 8` est mathématiquement borné à [8, 39] °C, donc
**aucun filtre** (pas de code mort). En revanche `octet[15] - 32` est borné à [-32, 223], d'où un filtre
explicite `AMBIANTE_MIN_PLAUSIBLE = -20` / `AMBIANTE_MAX_PLAUSIBLE = 60` ; hors bornes, le concept est
**omis** (cas typique : trame `running` à zéros, qui donne -32).

### Contrats du core Jeedom vérifiés en source pendant ce plan (V4-stable, à ne pas redécouvrir)

| Fait | Source lue |
|---|---|
| `cmd::event()` : `$repeat = ($oldValue === $value && …)` puis `setCollectDate(now)` **toujours**, `setValueDate($repeat ? ancienne : collectDate)`. Donc **`collectDate` = date de collecte (bouge à chaque cycle), `valueDate` = date du dernier changement (figée si valeur identique)** | `core/class/cmd.class.php` l. 1847-1870 |
| `eqLogic::checkAndUpdateCmd($logicalId, $value)` **sans** `$_updateTime` : si `execCmd() === formatValue($value)` et `repeatEventManagement != 'always'`, **`event()` n'est PAS appelé** — seuls `cmd::setCache('collectDate', now)` et `eqLogic::setStatus('lastCommunication')` sont écrits. Renvoie `true` **si et seulement si** un `event()` a été émis | `core/class/eqLogic.class.php` l. 677-705 |
| L'état d'une commande info (`value`, `valueDate`, `collectDate`) vit **dans le cache**, pas en colonne SQL | `cmd.class.php` l. 1253-1266 |
| `eqLogic::setStatus()` écrit dans le **cache**, jamais en base : l'invariant UC03 « un scan identique n'émet aucun `save()` » est préservé | `eqLogic.class.php`, `setStatus()` |
| `cmd::event()` **jette silencieusement** une valeur `numeric` hors de `configuration.minValue/maxValue` ; **sans** ces clés le contrôle est neutre | `cmd.class.php` l. 1857-1860 |
| `cmd::setName()` applique `cleanComponanteName()`, qui **supprime** les caractères `& # ] [ % \ / ' " *` puis compacte les espaces : **« Marche/Arrêt » deviendrait « MarcheArrêt »** | `cmd.class.php` l. 2955-2957 + `core/php/utils.inc.php::cleanComponanteName()` |
| `cmd::save()` **lève** si `name`, `type`, `subType` ou `eqLogic_id` est vide | `cmd.class.php` l. 1062-1076 |
| `configuration.listValue` ne sert qu'aux widgets d'**action** : il **ne traduit pas** la valeur affichée d'une commande info | `cmd.class.php` l. 1654-1680 |
| `cmd::byEqLogicIdAndLogicalId()` interroge la base à chaque appel ; `eqLogic::getCmd()` ne met en cache que les résultats **trouvés**, donc pas de « négatif périmé » après création | `cmd.class.php` / `eqLogic.class.php` l. 1765-1785 |
| Types renvoyés par `formatValue()` : `binary` -> `intval`, `numeric` -> `floatval`, `string` -> chaîne | `cmd.class.php` l. 1017-1044 |

**Écart signalé** : `.memory/analyse/smartclim-transport-aux-home.md` § 6.4 parle d'une commande
`derniere_maj`, alors que `.memory/analyse/smartclim-architecture-jeedom.md` § 5.1 (table de
nomenclature, qui fait autorité sur les `logicalId`) impose **`last_update`**. Retenu : **`last_update`**.

## Architecture — fichiers

| Fichier | État | Ce qui y entre | Indentation / fins de ligne |
|---|---|---|---|
| `core/class/smartclimAuxHomeApi.class.php` | **modifié** | `champsEtatAuxHome()` (table trame + octets, nouvelle), `offsetsAuxHome()` **réécrite en dérivation** de cette table (signature et forme de retour **inchangées**), `octetTrame()`, `etatAppareil()`, 2 constantes de plausibilité | 2 espaces, **CRLF** |
| `core/class/smartclimCapabilities.class.php` | **modifié** | `libelleCommande($_concept)` — noms destinés à `cmd::setName()`, donc **sans caractère supprimé par `cleanComponanteName()`** | 2 espaces, **CRLF** |
| `core/class/smartclim.class.php` | **modifié** | 2 constantes (`CMD_TRANSPORT`, `CMD_DERNIERE_MAJ`), `definitionsCommandesInfo()`, `creerCommandesInfo()`, `appliquerEtat()`, `postSave()` rempli, 1 bloc dans `scannerAuxHome()` | 2 espaces, **CRLF** |
| `plugin_info/configuration.txt` | **modifié** | **1 `help-block`** ajouté dans le fieldset « Rafraîchissement » (AC11). Aucune méta-séquence littérale | 2 espaces, **CRLF** |
| `plugin_info/configuration.php` | **régénéré** | `cp plugin_info/configuration.txt plugin_info/configuration.php` — jamais édité, jamais relu ; contrôle par `git status --short plugin_info/configuration.php` | — (copie) |
| `core/php/smartclim.inc.php` | **non touché** | **Délibéré** : UC05 ne crée **aucune classe**, donc aucun `require_once` à ajouter. Si le développeur créait finalement un fichier `<Classe>.class.php`, la ligne serait **obligatoire** ici — sans quoi « Class not found » au runtime, invisible à `php -l` et à la CI | — |
| `core/ajax/smartclim.ajax.php` | **non touché** | Aucune action AJAX nouvelle | — |
| `desktop/php/smartclim.php`, `desktop/js/smartclim.js` | **non touchés** | Les commandes s'affichent dans le tableau `#table_cmd` **déjà** présent — mécanisme du core | — |
| `core/config/smartclim.config.ini`, `packages.json`, `info.json`, `core/i18n/*.json`, `core/template/` | **non touchés** | Aucune clé de config plugin nouvelle ; aucune dépendance ; traduction = étape `translator` ; widget dédié = post-mvp/06 ; `pluginVersion` bumpée par le hook `pre-commit` | — |

**Aucune classe `smartclimFrame` n'est créée** — arbitrage assumé, cf. D-MVP05-02.

## Server vs Client

**100 % serveur.** Aucun rendu, aucune action AJAX, aucun JS nouveau : les commandes info sont un objet
du modèle Jeedom, affichées par le tableau `#table_cmd` du squelette de page équipement. Le seul
changement visible côté client est **une phrase** dans le formulaire de configuration plugin
(`plugin_info/configuration.txt`), statique.

## Signatures et responsabilités

### `smartclimAuxHomeApi` (modifiée) — seule à connaître les octets

```php
// Plausibilite de la temperature ambiante decodee (octet[15] - 32 est borne a [-32, 223]).
const AMBIANTE_MIN_PLAUSIBLE = -20;
const AMBIANTE_MAX_PLAUSIBLE = 60;

// Emplacement de chaque concept dans les trames : 'trame' ('control'|'running') + 'octets'.
// SOURCE UNIQUE des offsets : offsetsAuxHome() en derive ses longueurs minimales.
private static function champsEtatAuxHome()

// INCHANGEE dans sa signature ET sa forme de retour (consommee telle quelle par
// capacitesAppareil(), UC04) : desormais DERIVEE de champsEtatAuxHome() — longueur
// minimale = max(octets) + 1. Controle : 13/14/16/19 (control) et 16 (running),
// identiques aux litteraux d'UC04.
private static function offsetsAuxHome()

// Octet d'indice $_index d'une trame hexadecimale nettoyee, ou null si hors longueur.
private static function octetTrame($_trame, $_index)

// Etat GENERIQUE de CET appareil, via CE transport. Ne leve JAMAIS (controles
// is_array/is_string), au meme titre que capacitesAppareil().
// Un concept dont la valeur n'est pas DETERMINABLE est ABSENT du tableau — jamais
// une valeur par defaut, jamais null pousse (c'est le mecanisme d'AC10).
// Retour : array{online:bool, power?:int, mode?:string, target_temp?:float,
//                ambient_temp?:int, fan_speed?:string, source:string}
public static function etatAppareil(array $_appareil)
```

Aucun nom de champ AUX, aucun code propriétaire ne sort de `etatAppareil()` : uniquement des constantes
`smartclimCapabilities::CONCEPT_*` / `MODE_*` / `VITESSE_*`, des entiers et des flottants. Les trames ne
sont **ni journalisées ni persistées** (règle UC04 conservée) ; seuls les **codes fil** (entiers 0 à 7)
et les longueurs peuvent apparaître en `debug`.

### `smartclimCapabilities` (modifiée) — la table, et rien d'autre

```php
// Nom de la commande info d'un concept, destine a cmd::setName().
// Aucun des caracteres supprimes par cleanComponanteName() du core :
// « Marche/Arret » y deviendrait « MarcheArret ». D'ou « Marche-Arret » ici, alors que
// libelleConcept() — destine a une PHRASE, pas a un nom de composant — garde « Marche/Arret ».
// Chaine vide si le concept est inconnu (l'appelant ne cree alors AUCUNE commande).
public static function libelleCommande($_concept)
```

Valeurs : `online` -> « Disponibilité » · `power` -> « Marche-Arrêt » · `mode` -> « Mode » ·
`target_temp` -> « Consigne » · `ambient_temp` -> « Température ambiante » · `fan_speed` -> « Vitesse de
ventilation ». Cinq de ces six littéraux sont **identiques** à ceux de `libelleConcept()` **dans le même
fichier**, donc même clé i18n et aucun travail de traduction supplémentaire.

### `smartclim` (modifiée) — orchestration

```php
const CMD_TRANSPORT = 'transport';
const CMD_DERNIERE_MAJ = 'last_update';

// Definition Jeedom des commandes info : logicalId => nom, subType, unite,
// generic_type, historisation, ordre, meta. La CLE EST le logicalId, et pour les 6
// concepts elle est identique au code de concept d'UC04 (rien a renommer).
// 'meta' => true : commande produite par le PLUGIN (transport, horodatage), creee
// independamment du profil de capacites.
private static function definitionsCommandesInfo()

// Cree les commandes info MANQUANTES depuis configuration.capacites['concepts'].
// IDEMPOTENTE et NON DESTRUCTIVE : une commande existante n'est jamais relue, jamais
// modifiee, jamais supprimee (AC7/AC9). try/catch PAR COMMANDE. Ne leve JAMAIS.
// GATING, a lire precisement : la condition « profil absent ou sans concept » ne
// s'applique QU'AUX 6 COMMANDES DE CONCEPT. Les 2 commandes META ('transport',
// 'last_update', marquees 'meta' => true) sont creees dans TOUS les cas, y compris
// sur un equipement sans profil de capacites.
// COUT : les commandes deja presentes sont lues en UN SEUL appel getCmd(null, null)
// (indexe par logicalId), pas par un cmd::byEqLogicIdAndLogicalId() par concept —
// cette methode est appelee a chaque cycle de scan, puis a chaque cycle cron (UC07),
// pour chaque equipement.
// Retour : nombre de commandes creees.
private function creerCommandesInfo()

// Applique un etat NORMALISE (cles = codes de concept) aux commandes info : garantit
// d'abord l'existence des commandes, puis pousse les seules cles PRESENTES via
// checkAndUpdateCmd(). Une cle absente laisse la commande — et son valueDate — intactes.
// SURFACE D'APPEL d'UC06 (etat optimiste : un etat partiel est un cas nominal) et d'UC07.
// Retour : true si au moins une valeur de CONCEPT a change.
public function appliquerEtat(array $_etat)

// Fonction executee automatiquement apres la sauvegarde de l'equipement
public function postSave()   // -> creerCommandesInfo(), encadree d'un catch(Throwable) + log
```

`smartclimCmd::execute()` reste **vide** : une commande info ne s'exécute pas.

### Branchement dans `scannerAuxHome()` — insertion unique

Une seule insertion, **après** le `if (is_object($eqLogic)) { … } else { … }` (donc après les deux
branches, qui posent déjà `$compteurs`, `$appareilsResultat` et `$eqLogicsTouches`), **à l'intérieur**
du `try` par appareil :

```text
try {
  $eqLogic->appliquerEtat(smartclimAuxHomeApi::etatAppareil($appareil));
} catch (Throwable $t) {
  log::add('smartclim', 'error', 'AUX Home : application de l etat impossible (identifiant='
    . $identifiant . ') : ' . get_class($t) . ' : ' . self::neutraliserPourLog($t->getMessage()));
}
```

(le message réel utilise une apostrophe échappée en PHP ; elle est retirée ici pour ne pas casser la
lecture du bloc)

⚠️ **Ce `try/catch` local est obligatoire, pas décoratif** : sans lui, une exception remonterait au
`catch` par appareil, qui ajouterait une **seconde** ligne `'erreur'` au tableau de résultat pour un
appareil déjà compté `'cree'`/`'existant'` — l'écran de résultat d'UC03 afficherait deux lignes pour un
seul climatiseur.

**Invariants UC03/UC04 préservés** : aucun `eqLogic::save()` nouveau. `checkAndUpdateCmd()` n'écrit que
du cache ; `cmd::save()` n'a lieu qu'à la **création** d'une commande. Un scan strictement identique
reste sans écriture d'équipement.

**Pourquoi `postSave()` ET `appliquerEtat()` appellent tous deux `creerCommandesInfo()`** — deux chemins
réels, aucun « au cas où » :

- `postSave()` seul ne suffit **pas** : après déploiement d'UC05, les équipements ont déjà leur profil
  UC04, donc un scan identique **n'émet aucun `save()`** et `postSave()` ne serait jamais atteint, d'où
  **zéro commande créée**. C'est exactement le piège rencontré au déploiement d'UC04.
- `appliquerEtat()` seul ne couvre pas la réparation manuelle (ouvrir l'équipement et enregistrer
  recrée les commandes supprimées par erreur), et laisse `postSave()` incohérent avec
  `.memory/analyse/smartclim-architecture-jeedom.md` § 6.

### AC6 en détail — quelle donnée horodate quoi

| Notion | Porteur | Comportement |
|---|---|---|
| **Horodatage de collecte** | **natif, non dupliqué** : `collectDate` de chaque commande info + `lastCommunication` de l'équipement | Rafraîchi à **chaque** cycle, même sans changement. Aucune commande dédiée n'est créée pour ça |
| **Horodatage de la donnée** | commande `last_update` (`string`) **plus** le `valueDate` natif de chaque commande | Ne bouge **que** si un concept a réellement changé |

```text
$change = OU logique des retours de checkAndUpdateCmd() sur les 6 CONCEPTS seulement
pousser 'transport'  (hors agregation : litteral fige, il ne "change" qu'au 1er cycle)
si $change -> checkAndUpdateCmd('last_update', date('d/m/Y H:i:s'))    sinon : AUCUN appel
```

C'est le contrat vérifié du core qui fait le travail : `checkAndUpdateCmd()` renvoie `true` **si et
seulement si** il a émis un `event()`. Deux cycles sans changement, donc `last_update` n'est même pas
appelée, donc sa valeur **et** son `valueDate` sont figés : l'utilisateur lit l'âge réel de la donnée.

Il n'existe **aucun horodatage fourni par le cloud** dans `/app/user_device` : `last_update` est donc,
par construction, la date à laquelle **le plugin a constaté** un changement — une **borne inférieure**
de la fraîcheur, jamais l'instant réel du changement sur l'appareil. À écrire dans le docblock, et
cohérent avec l'avertissement d'AC11.

⚠️ **Limite connue et assumée d'AC6, à écrire dans le docblock d'`appliquerEtat()`** : le contrat du
core est `event()` émis si `execCmd() !== formatValue($value)` **ou** si
`repeatEventManagement == 'always'`. Un utilisateur qui règle une commande info sur « toujours
notifier » (réglage natif par commande) fera donc repartir `last_update` à **chaque** cycle, même sans
changement réel. C'est un comportement Jeedom natif, **hors du contrôle du plugin** : on ne le
contourne pas (le contourner reviendrait à écraser un réglage utilisateur, ce qu'AC7 interdit), on le
documente. Point de recette 8.

### AC10 en détail — mécanisme retenu (simple, défaisable, indépendant d'`intent`)

**Règle unique : « valeur non confirmable = commande non touchée »**, portée par l'**absence de clé**
dans l'état normalisé.

1. `etatAppareil()` n'émet `fan_speed` (idem `mode`) que si
   `depuisTransport('AUX_HOME','fan_speed', $codeFil)` renvoie autre chose que `null`. Les codes fil
   sans correspondance (0, 6, 7… — et par construction tout ce qui correspondrait à
   `SILENT`/`MEDIUM_LOW`/`MEDIUM_HIGH`, à `'fil' => null`) donnent une **clé absente** plus un
   `log::add('debug')`.
2. `appliquerEtat()` ne pousse que les clés présentes : la commande conserve sa dernière valeur **et**
   son `valueDate`.
3. **Coût d'un revirement : une ligne.** Aucune donnée persistée, aucune clé de configuration, aucun
   état supplémentaire — P7 satisfait. Et la règle est **uniforme** : elle sert aussi pour les trames
   trop courtes, l'appareil hors ligne et la température ambiante implausible.
4. **Ce qu'elle ne couvre pas, et qui appartient à UC06** : le cas où le fil renvoie un code **mappé
   mais différent** de la vitesse commandée (commander `SILENT` peut relire `LOW`). Il faut alors la
   mémoire « dernière vitesse commandée + période de grâce »
   (`smartclim-architecture-jeedom.md` § 7), qui suppose un **émetteur de commandes** — inexistant en
   UC05. Rien n'est écrit ici en prévision : `appliquerEtat()` acceptant déjà un état **partiel**, UC06
   n'aura qu'à filtrer sa clé avant l'appel.

### AC11 en détail — où mettre l'avertissement, et pourquoi

1. **`plugin_info/configuration.txt`, fieldset « Rafraîchissement »** — lecture littérale d'AC11. Le
   fichier porte **déjà** (depuis UC01) : « La température ambiante remontée par AUX Home se rafraîchit
   lentement (jusqu'à environ 30 minutes) ; réduire cet intervalle n'accélère pas la donnée. » Il manque
   la moitié « ne pas s'en servir pour une régulation fine ». On **ajoute un second `help-block`**
   plutôt que de réécrire le premier : réécrire orphelinerait une clé i18n déjà traduite dans les 3
   langues cibles, ajouter n'en crée qu'une.
2. **`generic_type` laissé VIDE sur `ambient_temp`** — motivé par AC11 lui-même. Poser `TEMPERATURE` ou
   `THERMOSTAT_TEMPERATURE` **enrôlerait automatiquement** cette valeur dans les résumés d'objet Jeedom
   et dans les intégrations tierces (thermostat, Alexa, Google) comme une sonde de pièce — précisément
   l'usage contre lequel la spec met en garde. Un `generic_type` se pose plus tard en une valeur.
3. **Seul `online` reçoit un `generic_type`** (`ONLINE`, Info/binary) : sémantiquement exact et sans
   effet de bord. Toutes les autres commandes restent sans `generic_type` au MVP, la famille
   `THERMOSTAT_*` ne devenant cohérente qu'avec les actions d'UC06.
4. **Pas d'avertissement dans le nom de la commande** : un nom de commande est repris dans les tags de
   scénario, il doit rester court et stable.

## Validation

| Point | Où | Comportement |
|---|---|---|
| Trame absente / trop courte / non hexadécimale | `etatAppareil()` (serveur) | Concept **omis**. `nettoyerTrame()` (UC04) garantit déjà « hexadécimal minuscule nu, ou chaîne vide » : aucune injection possible |
| Code fil sans correspondance (`mode`, `fan_speed`) | `etatAppareil()` | Concept omis plus `log::add('smartclim','debug', … code inconnu (' . $code . ')')` — un entier, jamais la trame |
| Température ambiante implausible | `etatAppareil()` | Concept omis plus `debug` |
| Valeur poussée sur une commande inexistante (concept hors profil) | `checkAndUpdateCmd()` du core | `getCmd('info', …)` renvoie `null`, la méthode retourne `false`, aucun effet. **Aucun garde-fou à écrire** |
| `minValue`/`maxValue` sur les commandes numériques | — | **Volontairement NON posées.** `cmd::event()` **jette silencieusement** une valeur hors bornes : des bornes personnalisées (18 à 30) feraient disparaître sans un mot une lecture réelle de 16 °C. Les bornes appartiennent à la commande **action slider** d'UC06 |
| Nom de commande vide ou concept inconnu | `creerCommandesInfo()` | `libelleCommande()` renvoie une chaîne vide, la commande **n'est pas créée** (`cmd::save()` lèverait) |
| Échec de création d'une commande | `creerCommandesInfo()` | `try/catch (Throwable)` **par commande** plus `log::add('error')`, la boucle continue |
| Échec de `postSave()` | `postSave()` | `try/catch (Throwable)` plus log : `postSave()` est traversé par le `save()` du scan, il ne doit jamais transformer un équipement en erreur récurrente |
| Échec d'application de l'état | `scannerAuxHome()` | `try/catch (Throwable)` local plus log ; l'appareil reste compté `cree`/`existant`, une seule ligne de résultat |
| **Exceptions** | — | **Aucun type nouveau, aucun message utilisateur nouveau.** `smartclimException` et `messageErreurAuxHome()` réutilisés tels quels. Aucune surface `isConnect()`/`session_write_close()` nouvelle |

**Sécurité** — aucun secret, aucun jeton, aucune adresse e-mail dans le nouveau contenu. Point notable :
**aucune donnée textuelle libre du cloud ne devient une valeur de commande** (contrairement aux noms
d'équipement d'UC03) — les valeurs sont des entiers, des flottants, des codes génériques issus d'une
table interne, et deux littéraux produits par le plugin (`AUX Home`, une date serveur).

## Server Actions / API

Aucune action AJAX nouvelle. Surfaces d'appel exposées aux UC suivantes :

| Appelant prévu | Appel |
|---|---|
| UC06 (commandes action) | `$eqLogic->appliquerEtat(array(...))` avec un état **partiel** (mise à jour optimiste après envoi d'un ordre) |
| UC07 (cron) | `$eqLogic->appliquerEtat(smartclimAuxHomeApi::etatAppareil($appareil))` par équipement, dans un `try/catch` par équipement |

## Dépendances

**Aucune.** PHP natif (opérations de bits, `hexdec`/`substr`). `hasDependency` et `hasOwnDeamon` restent
`false`, `packages.json` reste vide.

## Impact i18n (français seulement dans ce cycle)

**Ne toucher à aucun `core/i18n/*.json`** : la traduction est faite en fin de cycle par le sous-agent
`translator`, sur le code figé. Chaînes **littérales** dans `__()` et `{{…}}`, jamais `__($variable)`.

- **`core/class/smartclimCapabilities.class.php`** — `libelleCommande()` : « Disponibilité »,
  « Marche-Arrêt », « Mode », « Consigne », « Température ambiante », « Vitesse de ventilation ».
  Soit **2 clés réellement nouvelles** : « Marche-Arrêt » et « Consigne ».
- **`core/class/smartclim.class.php`** — « Transport actif », « Dernière mise à jour ». **2 clés
  nouvelles.**
- **`plugin_info/configuration.txt`** — « Cette température n'est donc pas une mesure temps réel : ne
  l'utilisez pas comme sonde d'une régulation fine (thermostat). » **1 clé nouvelle.**

Total : **5 chaînes françaises**. Les **valeurs** des commandes `mode` et `fan_speed` **ne sont pas
traduites** : ce sont les codes génériques (`COOL`, `AUTO`…), conformément à
`smartclim-architecture-jeedom.md` § 5.1. AC5 exige « en toutes lettres » pour le **seul** transport.

⚠️ `plugin_info/configuration.txt` est un fichier **rendu** : aucune méta-séquence littérale, **pas même
en commentaire**. Après édition : `cp plugin_info/configuration.txt plugin_info/configuration.php`,
contrôle par `git status --short plugin_info/configuration.php`, puis
`python .claude/scripts/verif-plugin.py` avant tout commit.

⚠️ **Consigne au `translator`** : `cleanComponanteName()` du core ampute les noms de commandes. Les
traductions des 6 libellés de `libelleCommande()` ne doivent contenir **ni apostrophe, ni barre
oblique**, ni aucun des caractères `& # ] [ % \ " *`.

## Risques et pièges

1. **R1 — `status.control` reflète-t-il un changement fait à la télécommande infrarouge ?** L'analyse le
   décrit comme « dernier état **commandé** ». Si le cloud ne le met à jour qu'après un ordre venu du
   cloud, **AC3 échoue** pour marche/arrêt, mode et vitesse. *Mitigation en place* : la trame est une
   **colonne** (`'trame' => 'control'`) de `champsEtatAuxHome()`, donc basculer un concept vers
   `running` est **un mot à changer**. Point de recette 1.
2. **R2 — AC2 non applicable**, perte fonctionnelle réelle et assumée (D-MVP05-01).
3. **R3 — AC10 à moitié couvert.** La partie « conserver la dernière vitesse **commandée** » exige un
   émetteur de commandes : UC06.
4. **R4 — `last_update` qui bougerait à chaque cycle.** Le mécanisme repose sur la comparaison stricte
   `execCmd() !== formatValue($value)` du core, donc sur un aller-retour de type stable à travers le
   cache. Sur une configuration PHP atypique (`serialize_precision`), `24.0` pourrait revenir en entier
   et provoquer un faux changement à chaque cycle. *Détection* : point de recette 3. *Repli* :
   remplacer l'agrégation par une empreinte explicite de l'état stockée en cache de commande, environ
   10 lignes localisées dans `appliquerEtat()`.
5. **R5 — `status.running` d'un appareil éteint.** Comportement inconnu ; si la trame est à zéros,
   `octet[15] - 32 = -32`, le filtre de plausibilité écarte le concept, et la dernière ambiante connue
   reste affichée avec un `valueDate` vieillissant. Comportement voulu, mais qui **ressemble** à une
   panne.
6. **R6 — Une commande supprimée par l'utilisateur est recréée au scan suivant.** Conséquence inhérente
   de la création idempotente. Contournement : masquer la commande (`isVisible`), respecté (AC7).
7. **R7 — `cleanComponanteName()` mange les caractères de nom.** Vérifié en source : tout nom contenant
   `/ ' " # [ ] % \ * &` est silencieusement amputé. C'est la raison d'être de `libelleCommande()`.
8. **R8 — Pas de `smartclimFrame`.** Décision assumée (D-MVP05-02) : le jour où Broadlink LAN
   (post-mvp/01) décodera la même trame, l'extraction est mécanique (une table plus deux méthodes
   privées, **un seul appelant**). Le risque est de l'oublier et de dupliquer le décodage — consigné
   dans `.memory/analyse/smartclim-transport-broadlink-lan.md`.
9. **R9 — `offsetsAuxHome()` réécrite en dérivation.** La forme de retour doit rester **strictement
   identique** (`array('control' => array(concept => int), 'running' => …)`), sinon la détection de
   capacités d'UC04 se dégrade en silence. Contrôle arithmétique : 13/14/16/19 et 16.
10. **R10 — Les commandes n'apparaissent qu'après rechargement de la page.** Le tableau `#table_cmd` est
    rendu au chargement. Aucun code n'est ajouté pour ça (P4) — à écrire dans les points de recette.
11. **R11 — Historisation par défaut de `ambient_temp`.** Un point d'historique n'est écrit **que**
    lorsque la valeur change : courbe clairsemée (donnée entière, cloud lent). Standard Jeedom, mais
    contre-intuitif.

## Points de recette (au-delà des critères d'acceptation)

1. **Débloquer R1** : modifier mode et vitesse à la **télécommande infrarouge**, relancer un scan,
   comparer les valeurs relues à l'afficheur du climatiseur. Si `control` ne suit pas, relever `running`
   sur la même manipulation et **mettre à jour `.memory/analyse/smartclim-transport-aux-home.md` § 6**.
2. Relever la longueur réelle des trames et la valeur brute de l'octet 15 de `running` sur **au moins 3
   mesures** couvrant plusieurs modes (règle de l'analyse § 6.2).
3. **AC6 / R4** : lancer deux scans consécutifs sans toucher au climatiseur, vérifier en log
   qu'**aucun** évènement `cmd::update` n'est émis pour cet équipement, et que `last_update` n'a pas
   bougé.
4. **AC7** : décocher « Afficher » et cocher « Historiser » sur `mode`, relancer un scan, rouvrir
   l'équipement : les deux réglages sont intacts.
5. Scanner alors que le climatiseur est **hors ligne** : `online` passe à 0, **aucune autre commande
   n'est modifiée**.
6. Vérifier que les commandes n'apparaissent qu'après **rechargement** de la page équipement (R10).
7. Vérifier que le nom de la commande d'alimentation s'affiche bien **« Marche-Arrêt »** (et non
   « MarcheArrêt ») : contrôle direct de R7.
8. **AC6, limite `repeatEventManagement`** : vérifier que le point de recette 3 est bien exécuté avec
   le réglage **par défaut** des commandes. Si une commande info est passée à « toujours notifier »,
   `last_update` repartira à chaque cycle : comportement natif du core, à ne pas prendre pour un
   défaut du plugin (cf. § AC6 en détail).

## Dette

*(section alimentée en fin de cycle par les findings de review sous la gate)*
