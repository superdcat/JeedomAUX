# Spec technique — UC06 « Commandes action : pilotage complet du climatiseur »

> **Domaine** : MVP · **Dépend de** : UC03 (découverte), UC04 (capacités), UC05 (commandes info)
> **Spec fonctionnelle** : `.memory/specs/MVP/06-commandes-action-pilotage.md`
> **Écrite par** : `/auto-dev` (mode autonome) — arbitrages journalisés dans
> `.memory/auto-dev/run-20260825-2356/MVP-06/decisions.md`

## 1. Objectif technique

Trois blocs, aucun de plus :

1. **Créer les commandes action** de chaque équipement, dérivées du profil de capacités (UC04) — jamais
   d'un catalogue de modèles.
2. **Câbler `smartclimCmd::execute()`** sur un émetteur d'ordre unique
   (`POST /app/device/v2/control`), avec budget de temps, déduplication et état optimiste.
3. **Payer la dette D-MVP05-07** : mémoire de la dernière valeur commandée + période de grâce,
   consommée par `smartclim::appliquerEtat()` (donc héritée par le cron d'UC07 sans une ligne de plus).

Aucune classe nouvelle, aucune dépendance, aucun démon, aucun endpoint AJAX de plugin nouveau, aucune
clé de configuration **plugin** nouvelle.

## 2. Couverture des critères d'acceptation

| AC | Réalisation | Statut |
|---|---|---|
| **AC1** — « Marche » allume en < ~15 s | `smartclim::executerCommandeAction()` → `smartclimAuxHomeApi::appliquerOrdre()`, **une seule** requête quand la session est en cache ; `BUDGET_COMMANDE = 18` s borne le pire cas | couvert |
| **AC2** — mode sur appareil éteint l'allume | l'ordre générique de toute commande `mode_*` **et** de `set_target_temp` contient **toujours** `power => 1` → intent à 2 clés en **une** requête | couvert — **à valider en recette** (risque R1) |
| **AC3** — consigne appliquée, info immédiate | `appliquerEtat($ordreApplique, true)` après succès ; la valeur poussée est celle **réellement envoyée** (après quantification), pas celle demandée | couvert — échelle à valider (R2) |
| **AC4** — bornes/pas du curseur | **client** : `configuration.minValue`/`maxValue` **et** `display.parameters['step']` de `set_target_temp` (le `step` ne passe **pas** par `configuration`, cf. § 8.3) ; **serveur** : rejet hors bornes + quantification sur la grille du pas | couvert |
| **AC5** — vitesse réellement changée | `fan_*` → `smartclimCapabilities::versTransport('AUX_HOME','fan_speed',$v)` | couvert structurellement — **à valider vitesse par vitesse** (R3) |
| **AC6** — rien pour une valeur non supportée | création conditionnée à l'appartenance au profil **et** à `versTransport(...) !== null` ; **aucune** commande d'oscillation (concept absent du profil, D-MVP04-03) | couvert |
| **AC7** — double-clic = un seul bip | empreinte du **contenu** de l'ordre + marqueur cache posé **avant** l'appel réseau, TTL `DUREE_DEDUP_ORDRE = 10` s, supprimé en cas d'échec | couvert |
| **AC8** — échec < ~20 s, sans blocage, trace | `BUDGET_COMMANDE = 18` s login compris + `session_write_close()` gardé + `log::add('error')` neutralisé | couvert |
| **AC9** — association visuelle action ↔ info | `setValue(<id de l'info>)` sur les 13 commandes **et** widget de plugin `smartclim::etat` (dashboard + mobile) qui reflète l'état courant sur le bouton | couvert — cf. § 8 |
| **AC10** — deux ordres différents passent | la clé de déduplication est le **contenu** de l'ordre, pas l'équipement : aucun verrou par équipement — les deux requêtes partent bien toutes les deux | **partiellement couvert** : garanti au niveau du plugin et de l'exemple de la spec (mode puis vitesse) ; l'ordre **inverse** sur un appareil éteint peut voir la vitesse ignorée par l'appareil lui-même (risque R4, point de recette 6) |

## 3. Contrat externe — écriture AUX Home

**Un seul appel réseau nouveau.**

```text
POST https://eu-smthome-api.aux-global.com/app/device/v2/control
En-têtes : ceux de smartclimAuxHomeApi::requete() (inchangés) + Content-Type: application/json
           Authorization: bearer <jeton de session utilisateur>
Corps    : {"intent": { <cle> : <entier>, ... }, "dst": 1, "deviceId": "<auxhome_device_id>"}
Réponse  : enveloppe {code, message, data} — SUCCES = code == 200 (jamais le seul http_code)
           'data' n'est PAS exploité (schéma inconnu, aucun besoin fonctionnel)
Erreurs  : classement délégué à classerCodeMetier('control', $donnees, TYPE_AUTH) — déjà en place
```

Source : `.memory/analyse/smartclim-transport-aux-home.md` § 4 (implémentation de référence
`GijsZwegers/com.zwegersit.auxairco`, licence MIT, `lib/auxcloud/client.ts::sendControl`, recoupée par
l'article de l'auteur § 06). `dst = 1` est une constante dont le sens n'est pas élucidé — envoyée telle
quelle.

### 3.1 Clés d'intention

| Concept générique | Clé AUX | Valeur envoyée | Statut de la source |
|---|---|---|---|
| `power` | `on_off` | `0` / `1` | vérifié |
| `mode` | `air_con_func` | colonne `intent` de `smartclimCapabilities` (AUTO 0, COOL 1, DRY 2, HEAT 4, FAN 6) | vérifié (2 implémentations concordantes) |
| `fan_speed` | `wind_speed` | colonne `intent` (AUTO 4, LOW 0, MEDIUM 1, HIGH 2, TURBO 5) | **contesté** — `intent_confirme => false` sauf TURBO (D-MVP04-02) |
| `target_temp` | `temperature` | `(int) round(°C × facteur)`, **facteur = 1** (entier °C) | référence EU vérifiée / backend CN en ×10 → **à valider en recette** |

⚠️ La colonne `intent` est bien la voie d'écriture prévue par UC04 ; ce qui est marqué non confirmé,
c'est la **valeur** de certaines entrées de la table des vitesses, pas le mécanisme. Le sens
lecture/écriture ne se devine jamais : `versTransport()` est le seul appel autorisé.

### 3.2 Échelle de température — tranché

`facteur = 1` (entier °C), **pas d'écriture = 1,0 °C**. Motif : la référence EU est le *même* backend que
celui du plugin (déjà vérifié en direct sur `getPubkey` en UC02) ; la piste ×10 vient du backend cousin CN
dont l'analyse rappelle qu'il ne partage pas toutes les routes.

Ces deux valeurs vivent **à un seul endroit** — `smartclimCapabilities::echelleTemperature('AUX_HOME')` —
donc basculer en ×10 / 0,5 °C après recette coûte **deux littéraux**.

⚠️ **Deux notions distinctes, jamais confondues** : le **pas de lecture** reste 0,5 °C (le fil rapporte
réellement des demi-degrés, UC05 AC4) ; seul le **pas d'écriture** vaut 1,0 °C.

### 3.3 Règle « une intention par requête » et son exception

`.memory/analyse/smartclim-transport-aux-home.md` § 4.1 impose une clé par requête, **avec l'exception
explicitement documentée** : changer de mode alors que l'appareil est éteint impose d'envoyer aussi
`on_off: 1`. UC06 envoie donc **au plus 2 clés** en **une seule** requête — seule façon de tenir AC2
(allumage implicite) **et** AC7 (un ordre = un bip).

`on_off => 1` est ajouté **inconditionnellement** aux ordres `mode_*` et `set_target_temp` — jamais
conditionné à l'état `power` connu, qui peut être absent du profil ou périmé. Les ordres `fan_*`
n'embarquent **pas** `on_off` : la spec fonctionnelle ne demande l'allumage implicite que pour le mode et
la consigne (AC2), et l'ajouter allumerait un appareil éteint sur un simple réglage de ventilation.

### 3.4 Pas de rejeu d'authentification

**Aucun re-login ni rejeu sur `TYPE_AUTH`** dans le chemin de pilotage : hors périmètre (UC08) et
incompatible avec le budget d'AC8 (login jusqu'à 14 s + rejeu 4 s > 18 s).

Mitigation d'une ligne, assumée : `purgerSession()` sur `TYPE_AUTH`, pour que la **tentative suivante de
l'utilisateur** reparte sur un login frais au lieu d'attendre l'expiration du cache (30 min). Ce n'est pas
un rejeu : aucune boucle, aucun compteur.

## 4. Architecture — fichiers

| Fichier | État | Contenu ajouté | Indentation / fins de ligne |
|---|---|---|---|
| `core/class/smartclimCapabilities.class.php` | modifié | `echelleTemperature($_transport)` + constantes `FACTEUR_TEMP_AUX_HOME`, `PAS_ECRITURE_AUX_HOME`. Emplacement **déjà réservé** par le docblock du fichier | 2 espaces, **CRLF** |
| `core/class/smartclimAuxHomeApi.class.php` | modifié | `BUDGET_COMMANDE`, `RESERVE_ORDRE`, `intentionsAuxHome()`, `appliquerOrdre()`, `requeteControle()` ; **3 ajouts additifs** de paramètre optionnel : `login()`, `session()`, `clePublique()` | 2 espaces, **CRLF** |
| `core/class/smartclim.class.php` | modifié | constantes, `definitionsCommandesAction()`, `creerCommandesAction()`, `executerCommandeAction()`, `ordreEffectifConsigne()`, `memoireOrdres()`/`enregistrerOrdre()`/`filtrerEtatSelonOrdres()`, `memeValeur()`, `appliquerEtat()` **modifiée** (2ᵉ paramètre, filtrage de grâce **et** appel de `creerCommandesAction()` — cf. § 4.1), `postSave()` (+1 ligne), `preRemove()` (+1 ligne), `smartclimCmd::execute()` rempli | 2 espaces, **CRLF** |
| `core/template/dashboard/cmd.action.other.etat.html` | **créé** | widget d'action reflétant l'état de l'info liée (§ 8) | 2 espaces, **CRLF** |
| `core/template/mobile/cmd.action.other.etat.html` | **créé** | **copie synchronisée** du précédent | 2 espaces, **CRLF** |
| `core/template/{dashboard,mobile}/cmd.action.other.templeteTemplate.html` | **supprimés** | fichiers **vides** hérités du squelette, jamais référencés (nom porteur d'une coquille du template d'origine) | — |
| `core/php/smartclim.inc.php` | **non touché** | UC06 ne crée **aucune classe**. ⚠️ Si le développeur en créait une, la ligne `require_once` y serait **obligatoire** — l'oubli est invisible à `php -l` **et** à la CI, fatal au runtime | — |
| `core/ajax/smartclim.ajax.php` | **non touché** | l'exécution d'une commande passe par `core/ajax/cmd.ajax.php` du **core** (`isConnect()` + `hasRight('x')` + CSRF/PIN/confirmation), jamais par l'AJAX **admin** du plugin | — |
| `desktop/php/smartclim.php`, `desktop/js/smartclim.js` | **non touchés** | `addCmdToTable()` porte déjà le sélecteur « Commande info liée » (`data-l1key="value"`) et les champs `minValue`/`maxValue` | — |
| `core/config/smartclim.config.ini`, `plugin_info/*`, `core/i18n/*.json` | **non touchés** | aucune clé de config plugin nouvelle (les durées sont des constantes de code, pas des réglages) ; traduction = étape `translator` ; `pluginVersion` bumpée par le hook `pre-commit` | — |

### 4.1 ⚠️ Deux points d'appel pour la création des commandes, pas un

**Défaut corrigé au tour d'advisor : `postSave()` seul ne suffit pas.** `postSave()` ne se déclenche que
si `eqLogic::save()` est réellement appelé, et `scannerAuxHome()` n'appelle `save()` que lorsqu'un
changement est détecté (identifiant, modèle, ou divergence de profil vue par `appliquerCapacites()`). Sur
un équipement **déjà scanné et inchangé** — le cas normal d'une mise à jour du plugin sur une
installation existante — il n'y a ni `save()`, ni `postSave()`, donc **aucune commande action ne serait
jamais créée**. Panne silencieuse : aucun log, aucune erreur, invisible à `php -l` comme à la CI.

C'est **exactement** le piège que UC05 a déjà rencontré et corrigé pour `creerCommandesInfo()`, en
dupliquant l'appel dans `appliquerEtat()` — qui, elle, tourne à **chaque** scan, changement ou non.

**Règle à appliquer** : `creerCommandesAction()` est appelée aux **deux** endroits où
`creerCommandesInfo()` l'est déjà — `postSave()` **et** `appliquerEtat()` — et toujours **après**
`creerCommandesInfo()`, puisqu'elle a besoin des identifiants des commandes info pour poser `setValue()`.

⚠️ Dans `appliquerEtat()`, la paire de créations est **gardée par `if (!$_optimiste)`** : l'appel
optimiste qui suit immédiatement un ordre réussi n'a rien à créer (la commande qu'on vient d'exécuter
existe forcément) et deux `SELECT` de commandes à chaque clic seraient du gaspillage pur.

## 5. Signatures

### 5.1 `smartclimCapabilities` — la table, et rien d'autre

```php
const FACTEUR_TEMP_AUX_HOME = 1;      // intent "temperature" = degre Celsius entier (reference EU)
const PAS_ECRITURE_AUX_HOME = 1.0;    // granularite d'ECRITURE (distincte du pas de LECTURE, 0,5)

// Echelle de temperature d'un transport : facteur d'echelle de l'intent + pas d'ecriture.
// Renvoie array() si le transport est inconnu (jamais de repli silencieux).
// @return array facteur => int, pas_ecriture => float
public static function echelleTemperature($_transport)
```

### 5.2 `smartclimAuxHomeApi` — seule classe à connaître les noms de champs AUX

```php
// Budget de temps GLOBAL d'un ordre, login compris (AC8 : echec en moins de ~20 s).
// Plus serre que BUDGET_SCAN (25 s, UC03) parce qu'un ordre est INTERACTIF.
const BUDGET_COMMANDE = 18;
const RESERVE_ORDRE   = 4;   // temps reserve a la requete de controle quand un login est necessaire

// Concept generique -> cle de l'intent AUX + nature de conversion
// ('booleen' | 'table' (colonne intent de smartclimCapabilities) | 'temperature').
// SEUL endroit du plugin ou vivent 'on_off', 'air_con_func', 'wind_speed', 'temperature'.
private static function intentionsAuxHome()

// Envoie UN ordre (UNE requete POST /app/device/v2/control) pour CET appareil.
// $_ordre : map GENERIQUE concept => valeur generique (aucun code AUX en entree).
// Traduit, valide, envoie, verifie code == 200, puis RENVOIE l'ordre REELLEMENT applique
// (valeurs apres quantification) : c'est cette valeur, jamais celle demandee, que l'appelant
// pousse en etat optimiste (AC3 ne doit pas afficher une valeur qui n'a pas ete envoyee).
// Recree l'exception A CE POINT D'APPEL : obligatoire, la frame de requete() porte le JETON.
// @return array map generique effectivement envoyee
// @throws smartclimException TYPE_RESEAU|TYPE_AUTH|TYPE_PROTOCOLE|TYPE_INTERNE (message TECHNIQUE)
public static function appliquerOrdre($_identifiantAppareil, array $_ordre)

// POST /app/device/v2/control : corps intent + dst:1 + deviceId, verification code == 200.
private static function requeteControle($_jeton, array $_intent, $_identifiantAppareil, $_tempsRequete)

// MODIFIEES — additif pur, comportement inchange pour tous les appelants existants :
public static function login($_budget = self::BUDGET_LOGIN)
public static function session($_budgetLogin = self::BUDGET_LOGIN)
private static function clePublique($_tempsRequete = self::TIMEOUT_REQUETE)
```

**Arithmétique du budget, à écrire telle quelle** : `appliquerOrdre()` mémorise `$debut`, appelle
`session(BUDGET_COMMANDE - RESERVE_ORDRE)` (= 14), puis donne à la requête de contrôle
`(int) max(3, min(TIMEOUT_REQUETE, BUDGET_COMMANDE - ecoule))`. Dans `login($_budget)` : 1ʳᵉ requête
`(int) max(3, min(TIMEOUT_REQUETE, $_budget - 4))`, 2ᵈᵉ requête `(int) ceil(max(3, $_budget - $ecoule))`
(logique inchangée, `BUDGET_LOGIN` remplacé par `$_budget`). Pire cas total : 10 + 4 + 4 = **18 s**.

⚠️ **Le budget doit être PROPAGÉ explicitement, sinon l'ajout des paramètres ne sert à rien** (défaut
signalé au tour d'advisor — l'oubli est silencieux et casse AC8 sans un message) :

- dans `session($_budgetLogin)`, l'appel actuel `self::login();` devient **`self::login($_budgetLogin);`**
  — sans quoi le login retombe sur `BUDGET_LOGIN` (18 s) et le pire cas réel devient 10 + 8 + 4 = **22 s**,
  au-delà du seuil d'AC8 ;
- dans `login($_budget)`, l'appel actuel `self::clePublique();` devient
  **`self::clePublique($tempsPremiereRequete);`** avec le temps calculé ci-dessus.

### 5.3 `smartclim` — orchestration, déduplication, état optimiste, grâce

```php
const CMD_ON = 'on';  const CMD_OFF = 'off';  const CMD_CONSIGNE = 'set_target_temp';
const PREFIXE_CMD_MODE    = 'mode_';
const PREFIXE_CMD_VITESSE = 'fan_';
const CLE_CACHE_ORDRES  = 'smartclim::ordres::';        // + id d'equipement
const CLE_CACHE_DEDUP   = 'smartclim::ordre_recent::';  // + id d'equipement + empreinte
const DUREE_DEDUP_ORDRE = 10;   // AC7 — fenetre anti-double-bip
const DUREE_GRACE       = 60;   // CLAUDE.md Conventions / analyse transport § 8.5

// Definition Jeedom des commandes ACTION de CET equipement.
// METHODE D'INSTANCE (contrairement a definitionsCommandesInfo()) : les entrees mode_* / fan_*
// sont DERIVEES du profil de capacites, jamais d'un catalogue de modeles.
// Une valeur dont versTransport() renvoie null est ABSENTE de la liste (AC6).
// La colonne intent_confirme n'est JAMAIS lue (D-MVP04-02).
private function definitionsCommandesAction()

// Cree les commandes action MANQUANTES, pose le widget si aucun n'est choisi, et realigne
// minValue/maxValue/step de set_target_temp. Appelee APRES creerCommandesInfo() (besoin des id
// d'info pour setValue()). try/catch PAR COMMANDE, ne leve JAMAIS.
// @return int nombre de commandes creees
private function creerCommandesAction()

// Point d'entree UNIQUE du pilotage, appele par smartclimCmd::execute().
// 1. session_write_close() garde  2. gardes (compteConfigure, auxhome_device_id, commande connue)
// 3. construction de l'ordre GENERIQUE (+ power => 1 pour mode et consigne)
// 4. validation de la consigne  5. deduplication  6. appliquerOrdre()
// 7. enregistrerOrdre() + appliquerEtat(..., true)
// @throws smartclimException message DEJA CURATE en francais (affiche par displayException())
public function executerCommandeAction($_logicalId, array $_options = array())

// Valeur de consigne EFFECTIVE : lit $_options['slider'], rejette si non numerique ou hors bornes
// (bornesTemperature(), UC04), puis quantifie sur la grille de bornesTemperature()['pas'] ancree
// sur le minimum -- c'est le pas AFFICHE au curseur (AC4), pas le pas d'ecriture du transport.
// Le second arrondi, celui de smartclimCapabilities::echelleTemperature(), est applique par
// appliquerOrdre() et reste SEUL autoritaire sur la valeur reellement envoyee puis poussee en
// etat optimiste. Ne jamais quantifier ici sur le pas d'ecriture : double arrondi incoherent.
// @return float
private function ordreEffectifConsigne(array $_options)

// Memoire des valeurs COMMANDEES (dette D-MVP05-07). Cache Jeedom, UNE entree par equipement,
// JSON NON chiffre (aucun secret : un mode de climatisation n'est pas une donnee sensible).
// Forme : concept => array('valeur' => <valeur generique>, 'ts' => <epoch>). TTL = DUREE_GRACE.
private function memoireOrdres()                       // array (vide si absente/expiree/corrompue)
private function enregistrerOrdre(array $_ordre)       // fusionne, purge les concepts expires, ecrit
private function filtrerEtatSelonOrdres(array $_etat)  // array
private static function memeValeur($_a, $_b)           // bool — numerique a 0,01 pres, sinon (string)

// MODIFIEE : 2e parametre.
// $_optimiste = true  -> AUCUN filtrage de grace (on ne filtre pas son propre ordre)
// $_optimiste = false -> filtrage (defaut : UC05, et UC07 par heritage)
public function appliquerEtat(array $_etat, $_optimiste = false)

public function preRemove()   // + cache::delete(CLE_CACHE_ORDRES . id) — hygiene
public function postSave()    // + creerCommandesAction() dans le meme try/catch(Throwable)
```

`smartclimCmd::execute($_options)` : garde `getType() === 'action'`, résolution de l'eqLogic
(`$this->getEqLogic()`, contrôle `instanceof smartclim`), puis **délégation**
`$eqLogic->executerCommandeAction($this->getLogicalId(), $_options)`. Aucune logique métier, aucun
`catch` : la curation du message vit dans `smartclim::` (où `messageErreurAuxHome()` est `private`), et
l'exception curatée remonte au core. Pas de valeur de retour (aucun consommateur en UC06).

## 6. Commandes action créées

| `logicalId` | type / subType | `name` français | Condition de création | `value` (info liée) | Ordre générique envoyé |
|---|---|---|---|---|---|
| `on` | action / other | Marche | `power` dans les concepts du profil | `power` | `power => 1` |
| `off` | action / other | Arrêt | idem | `power` | `power => 0` |
| `set_target_temp` | action / **slider** | Régler la consigne | `target_temp` dans les concepts | `target_temp` | `power => 1`, `target_temp => <float>` |
| `mode_auto` | action / other | Mode Automatique | `AUTO` dans `capacites['modes']` **et** `versTransport ≠ null` | `mode` | `power => 1`, `mode => 'AUTO'` |
| `mode_cool` | action / other | Mode Refroidissement | `COOL` dans `modes` | `mode` | `power => 1`, `mode => 'COOL'` |
| `mode_dry` | action / other | Mode Déshumidification | `DRY` dans `modes` | `mode` | idem |
| `mode_heat` | action / other | Mode Chauffage | `HEAT` dans `modes` | `mode` | idem |
| `mode_fan` | action / other | Mode Ventilation | `FAN` dans `modes` | `mode` | idem |
| `fan_auto` | action / other | Vitesse Automatique | `AUTO` dans `capacites['vitesses']` | `fan_speed` | `fan_speed => 'AUTO'` |
| `fan_low` | action / other | Vitesse Faible | `LOW` dans `vitesses` | `fan_speed` | idem |
| `fan_medium` | action / other | Vitesse Moyen | `MEDIUM` dans `vitesses` | `fan_speed` | idem |
| `fan_high` | action / other | Vitesse Fort | `HIGH` dans `vitesses` | `fan_speed` | idem |
| `fan_turbo` | action / other | Vitesse Turbo | `TURBO` dans `vitesses` | `fan_speed` | idem |

- `logicalId` d'un mode/vitesse = `<prefixe> . strtolower(<valeur generique>)` → conforme à la
  nomenclature arrêtée (`CLAUDE.md` § Modèle de données : `mode_cool`, `fan_turbo`). Un futur `SILENT` ou
  `MEDIUM_LOW` confirmé en recette donnera `fan_silent` / `fan_medium_low` **sans code nouveau** : il
  suffira que la valeur entre dans le profil.
- Noms composés par `sprintf(__('Mode %s', __FILE__), smartclimCapabilities::libelle(...))` et
  `sprintf(__('Vitesse %s', __FILE__), ...)` : **2 clés i18n au lieu de 10**, et réutilisation obligatoire
  des libellés canoniques d'UC04 (« Refroidissement » / « Chauffage », **pas** « Froid » / « Chaud » comme
  l'anticipait la section i18n de la spec fonctionnelle — sinon l'interface afficherait deux mots pour la
  même notion).
- Aucun caractère amputé par `cleanComponanteName()` dans ces 13 noms, aucune collision avec les noms
  d'info d'UC05.
- **Aucune commande d'oscillation** : le concept est absent du profil de capacités (D-MVP04-03, UC05 AC2
  non applicable) — en créer une violerait AC6. Les chaînes « Oscillation verticale — Marche/Arrêt »
  annoncées par la section i18n de la spec fonctionnelle **ne sont pas introduites**.
- `isVisible = 1` ; `isHistorized` non posé (une action ne s'historise pas) ; `generic_type` **laissé
  vide** partout (poser un `THERMOSTAT_*` partiel enrôlerait l'équipement dans les intégrations tierces
  avec une sonde d'ambiance dont UC05 AC11 dit qu'elle n'est pas fiable — se pose plus tard en une valeur,
  ne se retire pas) ; `actionConfirm` non posé (aucun AC ne le demande, et ce n'est pas une frontière
  d'autorisation).

## 7. Déduplication (AC7) contre ordres simultanés (AC10)

Les deux critères se tendent : AC7 veut qu'un ordre répété n'agisse qu'une fois, AC10 veut que deux
ordres **différents** quasi simultanés passent tous les deux. La clé de déduplication porte donc sur le
**contenu de l'ordre**, jamais sur l'équipement.

```text
empreinte = sha1( json_encode( ordre generique trie par cle ) )
cle       = CLE_CACHE_DEDUP . <idEqLogic> . '::' . empreinte

1. cache::byKey(cle)->getValue(null) !== null  ->  log debug, RETOUR IMMEDIAT
                                                  (aucun reseau, aucune ecriture d'etat :
                                                   le premier ordre l'a deja fait)
2. cache::set(cle, '1', DUREE_DEDUP_ORDRE)     ->  AVANT l'appel reseau, pour couvrir le
                                                   double-clic pendant que le 1er ordre est en vol
3. appliquerOrdre()  echec  ->  cache::delete(cle) puis exception
                                (un ordre echoue doit rester rejouable immediatement)
                     succes ->  marqueur conserve jusqu'a expiration
```

- **AC7 tenu** : deux clics sur « Vitesse Turbo » à 2 s d'intervalle → même empreinte → un seul POST, un
  seul bip.
- **AC10 tenu** : « Mode Refroidissement » puis « Vitesse Turbo » → empreintes différentes → deux POST.
  **Aucun verrou par équipement** (contrairement au verrou de scan d'UC03) : c'est délibéré, un verrou
  par équipement ferait échouer AC10.
- ⚠️ `cache::byKey()` puis `cache::set()` ne sont **pas atomiques** : c'est une **atténuation** (double-clic,
  deux onglets), jamais un mutex — même formulation que le verrou de scan d'UC03.

## 8. AC9 — association visuelle action ↔ info

Deux mécanismes complémentaires, les deux nécessaires :

1. **Lien de modèle** : `setValue(<id de la commande info>)` sur les 13 commandes action → alimente les
   jetons `#state#`, `#value_id#`, `#valueName#`, `#valueDate#` du widget, rend l'association visible dans
   la table admin, et positionne nativement le curseur de consigne (`cmd.action.slider.slider.html`).
2. **Widget de plugin** `core/template/{dashboard,mobile}/cmd.action.other.etat.html`, posé par
   `setTemplate('dashboard'|'mobile', 'smartclim::etat')` sur les 12 commandes `action/other` — parce que
   le widget core par défaut de `action/other` (`cmd.action.other.default.html`) **n'exploite pas
   `#state#`** et ne s'abonne à rien : c'est un bouton sans état, AC9 ne serait pas tenu.

### 8.1 Contrat core vérifié en source (`jeedom/core` @ `V4-stable`)

Ces cinq points ont été relus au cycle UC06 dans la **source réelle** du core — dépôt `jeedom/core`,
branche `V4-stable`, fichiers `core/js/cmd.class.js`, `core/js/jeedom.class.js`,
`core/class/cmd.class.php` (`toHtml()` et `event()`) et les widgets
`core/template/dashboard/cmd.action.other.{default,binaryDefault}.html` /
`cmd.action.slider.slider.html`. Le core n'étant **pas** embarqué dans ce dépôt, ces points sont à
recapitaliser dans `.memory/analyse/jeedom-widgets-commandes.md` en fin de cycle, faute de quoi la
prochaine UC les redécouvrira. Ils **corrigent** deux hypothèses naturelles mais fausses :

1. ⚠️ **L'abonnement se fait sous `#id#` — l'id de la commande ACTION — jamais sous `#value_id#`.**
   `cmd::event()` de la commande **info** émet un **événement supplémentaire** portant le `cmd_id` de
   chaque commande action qui la référence via `value` (`cmd::byValue()`), avec `value` et
   `display_value` de l'info. Le JS (`jeedom.cmd.refreshValue`) n'indexe que
   `jeedom.cmd.update[payload.cmd_id]` : **aucun re-routage** de l'info vers les widgets d'action.
   `jeedom.cmd.update['#value_id#'] = fn` ne serait **jamais** appelé.
   → Utiliser l'API officielle **`jeedom.cmd.addUpdateFunction('#id#', fn)`** (elle gère la
   multi-inscription ; l'affectation directe `jeedom.cmd.update[id] = fn` écraserait les autres widgets).
2. **Amorçage explicite obligatoire** : le core n'appelle rien au rendu. Le widget doit terminer par
   `jeedom.cmd.refreshValue([{ cmd_id: '#id#', value: '#state#', display_value: '#state#', valueDate: '#valueDate#', collectDate: '#collectDate#', unit: '#unite#' }])`
   — sinon le bouton reste sans état jusqu'au premier événement temps réel.
3. **`#state#` d'une action liée = valeur BRUTE de l'info** (`$cmdValue->execCmd()`), sans
   `formatValueWidget` ni mise à l'échelle. C'est donc exactement le code générique poussé par UC05
   (`COOL`, `TURBO`) ou `0`/`1` pour `power` — ce qui rend la comparaison directe possible.
4. **Tous les champs du payload sont optionnels.** Le payload destiné à une commande **action** est émis
   **sans `raw_unit` ni `alertLevel`** : tester `if (_options.value != undefined)` avant usage, ne jamais
   supposer `alertLevel` présent.
5. ⚠️ **Aucun jeton n'expose la `configuration` d'une commande.** Seuls `minValue`, `maxValue` et
   `listValue` en sont extraits nommément ; un paramètre maison ne peut arriver que par
   `display.parameters` (bloc `template` du widget). C'est pourquoi le widget déduit la valeur qu'il
   représente de son propre **`#logicalId#`** (jeton bien présent dans la table de base).

### 8.2 Conception du widget

**Aucune clé de configuration nouvelle** : le widget déduit la valeur générique qu'il représente de son
`#logicalId#` — `mode_cool` → `COOL`, `fan_turbo` → `TURBO`, `on` → `1`, `off` → `0` — et la compare à la
valeur de l'info reçue. C'est la raison pour laquelle les `logicalId` de commande action sont dérivés
mécaniquement des valeurs génériques (§ 6) : **la convention de nommage est la donnée**.

Comparaison à faire en JS, sans mapping métier : préfixe retiré (`mode_` / `fan_`), reste passé en
majuscules, comparaison de chaînes ; pour `on` / `off`, comparaison numérique à `1` / `0`. Bouton marqué
actif (classe CSS + icône) quand il correspond, neutre sinon. Aucun appel réseau, aucune dépendance JS.

⚠️ **Ne pas s'appuyer sur `jeedom.cmd.normalizeName()`** : elle mappe bien les libellés français
« marche » / « arrêt » vers `on` / `off`, mais **retourne le nom inchangé** pour tout libellé hors liste
(« Turbo », « Refroidissement ») — le widget core `binaryDefault` tomberait alors systématiquement dans
la branche « état différent » et afficherait une icône trompeuse. Rien dans le core ne mappe un libellé
métier vers un état.

**Pose idempotente** : `if ($cmd->getTemplate($version, '') === '') $cmd->setTemplate(...)` — couvre les
installations existantes sans écraser un widget choisi à la main
(`.memory/analyse/jeedom-widgets-commandes.md` § 6). Bord assumé : si l'utilisateur repasse
explicitement au widget par défaut du core, le nôtre est reposé au cycle suivant.

**Dashboard et mobile sont deux fichiers à garder synchronisés**, et l'i18n exige **une entrée de chemin
par fichier** (même pour des chaînes identiques).

### 8.3 ⚠️ Le `step` du curseur ne vient PAS de `configuration.step`

Correction d'une hypothèse fausse, vérifiée dans `cmd.action.slider.slider.html` :

- `#minValue#` et `#maxValue#` sont bien résolus côté PHP depuis `getConfiguration('minValue'|'maxValue')`
  de la commande **action** → les poser en `configuration` suffit, et **AC4 est tenu côté client**.
- `#step#`, en revanche, **n'est pas dans la table de jetons de base** : il n'arrive que par
  `display.parameters` (`$cmd->getDisplay('parameters')`), avec un **repli à `1`** dans le widget core
  (`issetWidgetOptParam`). Poser `configuration.step` n'a donc **aucun effet** sur le curseur.

**Conséquence à implémenter** : le pas se pose dans `display.parameters['step']`, en **fusionnant** le
tableau existant (jamais en l'écrasant : il porte d'autres paramètres de widget réglés par
l'utilisateur), et se réaligne comme `minValue`/`maxValue` — sinon un changement de `temp_pas` en UC04 ne
serait pas suivi par le curseur. `configuration.step` est **aussi** posée, comme référence lisible dans
la table des commandes, mais ce n'est pas elle qui pilote l'affichage.

Coïncidence utile à ne pas confondre avec une garantie : le repli core (`1`) vaut exactement notre pas
d'écriture actuel (§ 3.2) — donc un oubli de `display.parameters` serait **invisible** aujourd'hui et
casserait AC4 le jour où R2 fait passer le pas à 0,5.

## 9. Mémoire de la valeur commandée et période de grâce — dette D-MVP05-07

| Question | Réponse |
|---|---|
| **Où** | Cache Jeedom, **une entrée par équipement** : `smartclim::ordres::<idEqLogic>`. **Pas** en `configuration` d'équipement (ce que suggérait `smartclim-architecture-jeedom.md` § 3.2, clé `etat_optimiste`) : un `setConfiguration` + `save()` à chaque clic déclencherait `preSave`/`postSave` et une écriture SQL pour une donnée qui vit 60 s. Écart assumé et signalé. |
| **Forme** | `json_encode(array(<concept> => array('valeur' => <valeur generique>, 'ts' => <epoch>)))`. **Non chiffré** : aucun secret — le chiffrement du cache de session existe parce qu'il porte un jeton. |
| **Durée** | TTL de cache = `DUREE_GRACE = 60` s, **et** chaque concept porte son propre `ts` : le filtre relit `ts` concept par concept, de sorte qu'un ordre `mode` posé il y a 55 s ne prolonge pas la grâce d'un ordre `fan_speed` posé il y a 5 s. |
| **Écriture** | `enregistrerOrdre($ordreApplique)` relit l'entrée, **purge les concepts expirés**, écrit ou écrase les concepts commandés, réécrit l'entrée. Un nouvel ordre n'efface donc jamais la mémoire d'un autre concept encore sous grâce. |
| **Lecture** | `appliquerEtat()` fait `if (!$_optimiste) { $_etat = $this->filtrerEtatSelonOrdres($_etat); }` **avant** la boucle de poussée. Pour chaque concept mémorisé et non expiré : valeur mémorisée **égale** à la valeur scrutée → le cloud a confirmé, le concept est **retiré de la mémoire** (fin de grâce anticipée, ce qui réduit au minimum la fenêtre pendant laquelle un changement réel à la télécommande serait masqué) ; valeur **différente** → la **clé est retirée de `$_etat`** (commande info non touchée, `valueDate` intact) + `log::add('debug')` « valeur commandée X, valeur relue Y, période de grâce ». |
| **Pourquoi ce mécanisme** | Il réutilise **à l'identique** la règle livrée par UC05 (« clé absente ⇒ commande non touchée ») : aucune voie de poussée nouvelle, donc aucun risque de divergence. C'est exactement ce que la spec technique UC05 § « AC10 en détail » point 4 annonçait. |
| **Pré-requis UC07** | Le filtrage vivant **dans** `appliquerEtat()`, le cron d'UC07 en hérite sans une ligne supplémentaire. |

⚠️ Ce filtre paie la moitié manquante d'**UC05 AC10** (commander « Silencieux », relire « Faible ») et
l'anti-rollback de consigne / marche décrit par `smartclim-transport-aux-home.md` § 8.5.

## 10. Validation et classement des erreurs

| Point validé | Où | Comportement |
|---|---|---|
| Commande info liée introuvable | `creerCommandesAction()` | commande action créée **sans** `setValue()` + `log debug` — le pilotage ne doit pas dépendre d'une info que l'utilisateur aurait supprimée |
| `name` vide (libellé inconnu) | `definitionsCommandesAction()` | entrée non produite (`cmd::save()` lèverait sur un `name` vide) |
| Échec de création d'une commande | `creerCommandesAction()` | `try/catch (Throwable)` **par commande** + `log error`, la boucle continue ; ne lève jamais |
| `logicalId` orphelin (profil changé) | `executerCommandeAction()` | `smartclimException(TYPE_INTERNE)` — « Commande inconnue pour cet équipement » |
| Compte non configuré | `executerCommandeAction()`, **avant tout réseau** | `compteConfigure()` puis **réutilisation du littéral existant** « Compte AUX Home non configuré : renseignez l'e-mail et le mot de passe » (aucune clé i18n nouvelle) |
| `auxhome_device_id` absent ou vide | `executerCommandeAction()` | « Cet équipement n'est pas relié à un appareil AUX Home — relancez un scan » |
| `$_options['slider']` absent, non scalaire, non numérique | `ordreEffectifConsigne()` (**serveur**) | **rejet** — « Valeur de consigne absente ou non numérique ». `is_numeric` **avant** tout cast (un scénario ou l'API JSON-RPC peut passer un tableau) |
| Consigne hors bornes de l'équipement | idem | **rejet** (jamais de clamp silencieux d'une valeur demandée) — « Consigne hors des bornes de l'équipement (%1$s à %2$s °C) », bornes issues de `bornesTemperature()` |
| Consigne hors grille du pas | idem | **quantifiée** silencieusement (`min + round((v - min) / pas) * pas`) : ce n'est pas un changement de sens, c'est la granularité du transport |
| Valeur générique sans correspondance `intent` | `appliquerOrdre()` | `TYPE_INTERNE` (incohérence de table : la création aurait dû l'écarter, AC6) |
| `intent` vide après traduction | `appliquerOrdre()` | `TYPE_INTERNE` — jamais de POST vide |
| `code != 200` | `requeteControle()` | `classerCodeMetier('control', ..., TYPE_AUTH)` — journalisation backend déjà neutralisée |
| Exception du transport | `executerCommandeAction()` | `log::add('error', ...)` avec logicalId + nom d'équipement **neutralisé** (`neutraliserPourLog()`) + type + message technique, **puis** `throw new smartclimException(<message curate>, $type)` : `messageErreurAuxHome($type, $contexte)` pour RESEAU/AUTH/PROTOCOLE, et un littéral dédié pour `TYPE_INTERNE` (« Erreur interne lors de l'envoi de la commande — consultez les logs du plugin » : le message existant parle de « préparation de la connexion », faux dans ce contexte) |
| Ordre **dédupliqué** (marqueur déjà posé) | `executerCommandeAction()`, étape 5 | **retour silencieux** : `log debug`, aucune exception, aucun réseau, aucune écriture d'état. ⚠️ Comportement accepté et à ne pas prendre pour un bug : si le **premier** ordre échoue *après* le retour du second, le second a déjà rendu un succès à son appelant. AC7 ne demande pas de propager l'échec du premier au second, et le faire exigerait d'attendre le premier — donc de bloquer |
| `Error` PHP 8 | `executerCommandeAction()`, **dernier bloc** | `catch (Throwable)` obligatoire : `core/ajax/cmd.ajax.php` ne rattrape que `Exception` — une `Error` y produirait une réponse **non JSON**. Log neutralisé + `smartclimException` curatée |

### 10.1 Sécurité — trois points non négociables

1. `core/ajax/cmd.ajax.php` fait `ajax::error(displayException($e), $e->getCode())` : le message de
   **notre** exception est **affiché**, et `displayException()` peut y adjoindre la trace selon le niveau
   de log. Une exception née dans `requete()` — dont la frame porte le **jeton** — remontant telle quelle
   **fuiterait le jeton dans le navigateur**. `appliquerOrdre()` **doit** donc recréer l'exception à son
   propre point d'appel (même motif que `login()` / `session()` / `listerAppareils()`), et
   `executerCommandeAction()` en recrée une seconde, curatée.
2. `session_write_close()` **gardé par `session_status() === PHP_SESSION_ACTIVE`**, en tête de
   `executerCommandeAction()`, **avant tout appel réseau** : `cmd.ajax.php` ne l'appelle pas, et Jeedom
   utilise des sessions **fichier** — un ordre de 3 à 18 s figerait sinon toute l'interface, ce qu'AC8
   interdit. La garde rend l'appel idempotent et inoffensif en contexte cron ou scénario.
3. Contrôle d'accès entièrement porté par le core (`isConnect()` + `eqLogic::hasRight('x')` avant
   `execCmd()`). Aucune surface `isConnect('admin')` nouvelle ; **aucun** secret, jeton, e-mail ni trame
   HVAC dans un message, un log ou une réponse. `deviceId` ne voyage que dans le corps JSON, jamais dans
   une URL.

## 11. Impact i18n (français uniquement)

**Ne toucher à aucun `core/i18n/*.json`** (étape `translator`, sur code figé). Chaînes **littérales**
dans `__()`, jamais `__($variable)`.

`core/class/smartclim.class.php` — **10 clés nouvelles** :

- « Marche » · « Arrêt » · « Régler la consigne »
- « Mode %s » · « Vitesse %s » (gabarits : `__()` **d'abord**, `sprintf()` **ensuite**)
- « Commande inconnue pour cet équipement »
- « Cet équipement n'est pas relié à un appareil AUX Home — relancez un scan »
- « Valeur de consigne absente ou non numérique »
- « Consigne hors des bornes de l'équipement (%1$s à %2$s °C) » (arguments **positionnels**)
- « Erreur interne lors de l'envoi de la commande — consultez les logs du plugin »

`core/template/{dashboard,mobile}/cmd.action.other.etat.html` : aucune chaîne UI si le widget se contente
du `#name_display#` fourni par le core — à défaut, **une entrée de chemin par fichier** dans les JSON i18n.

Réutilisés **sans clé nouvelle** : « Compte AUX Home non configuré : renseignez l'e-mail et le mot de
passe », les 5 messages de `messageErreurAuxHome()`, et tous les libellés de
`smartclimCapabilities::libelle()`.

⚠️ **Consigne au `translator`** : les traductions de « Marche », « Arrêt », « Régler la consigne »,
« Mode %s », « Vitesse %s » deviennent des **noms de commande** → bannir apostrophe, barre oblique et
tous les caractères amputés par `cleanComponanteName()`.

## 12. Risques

1. **R1 — Intent à 2 clés ignoré par le backend EU.** L'article de référence rapporte que plusieurs
   réglages envoyés ensemble **appareil éteint** étaient parfois ignorés. Si `on_off: 1` + `air_con_func`
   n'allume pas ou ne change pas le mode, **AC2 échoue**. *Repli documenté, non implémenté* : deux
   requêtes séquentielles dans le même budget — au prix d'un second bip, ce qui contredit partiellement
   AC7. Point de recette 1.
2. **R2 — Échelle de température.** Si le backend EU attend ×10, toute consigne part à 1/10 de sa valeur
   (2 °C au lieu de 22) : spectaculaire, détecté au premier essai. Correction =
   `FACTEUR_TEMP_AUX_HOME = 10` et `PAS_ECRITURE_AUX_HOME = 0.5`. Point de recette 2.
3. **R3 — Table `wind_speed` d'écriture contestée** (D-MVP04-02). Commander « Automatique » pourrait
   régler « Fort ». Le plugin **s'auto-diagnostique** : après la période de grâce, `appliquerEtat()`
   journalise en `debug` « valeur commandée X, valeur relue Y » — c'est l'instrument de recette.
   Correction = 5 entiers dans la table. Point de recette 3.
4. **R4 — Ordonnancement de deux ordres sur un appareil éteint.** Un scénario qui envoie *vitesse* **puis**
   *mode* alors que l'appareil est éteint peut voir l'ordre de vitesse ignoré par le cloud (`fan_*`
   n'embarque pas `on_off`). L'état optimiste afficherait une vitesse fausse pendant 60 s, puis le scan
   corrige. AC10 est tenu pour l'exemple de la spec (mode puis vitesse). Point de recette 6.
5. **R5 — État optimiste `power = 1` sur un ordre de mode ou de consigne.** Si l'allumage implicite échoue
   (R1), la commande info `power` affiche « en marche » pendant la grâce alors que l'appareil est éteint.
   Assumé : c'est le prix de l'état optimiste, borné à 60 s.
6. **R6 — Réécriture de `minValue`/`maxValue`/`step` à chaque cycle.** Ces trois valeurs sont **reposées**
   (elles dérivent des bornes de l'équipement, modifiables en UC04) : la comparaison avant écriture **doit
   être numérique à tolérance** (`abs(a - b) < 0.001`) et la valeur stockée un **float**, sinon la chaîne
   `'16'` du formulaire diffère du float `16.0` et **chaque cycle de cron émet un `cmd::save()`** par
   équipement. Même classe de bug que UC05 R4. ⚠️ Le `step` vivant dans `display.parameters` (§ 8.3), la
   même précaution s'applique à `setDisplay('parameters', ...)`, et le tableau existant doit être
   **fusionné**, jamais remplacé.
7. **R7 — Bornes du curseur non modifiables dans la table des commandes.** Les champs `Min`/`Max` de
   `#table_cmd` sont éditables mais seront **réalignés** au prochain scan ou cycle de cron : le levier
   utilisateur documenté reste `temp_min` / `temp_max` / `temp_pas` de l'équipement (UC04). À écrire dans
   le docblock, sinon cela ressemble à un bug.
8. **R8 — Jeton périmé avant les 30 min du cache.** Sans rejeu (UC08), le premier ordre échoue sur un
   message « vérifiez vos identifiants » trompeur ; la purge de session sur `TYPE_AUTH` fait réussir la
   **tentative suivante**. Limite connue, périmètre UC08.
9. **R9 — Valeurs d'info non traduites.** La commande info `mode` affiche `COOL`, pas « Refroidissement »
   (décision UC05 : `listValue` ne traduit pas une info). Le retour visuel d'AC3 et AC9 est donc un code
   générique pour le mode et la vitesse. Hors périmètre UC06, mais c'est ce que l'utilisateur verra.
10. **R10 — Deux `SELECT` de commandes par cycle et par équipement.** `creerCommandesInfo()` puis
    `creerCommandesAction()` font chacun un `getCmd(null, null)`, aux **deux** points d'appel de la paire
    (`postSave()` et `appliquerEtat()`, cf. § 4.1) — **volontaire** : `eqLogic::getCmd()` ne mémoïse que
    les appels avec `$_logicalId !== null`, donc le second appel voit bien les infos fraîchement créées.
    Fusionner les deux méthodes économiserait une requête au prix d'une réécriture du code UC05 livré :
    refusé. La garde `if (!$_optimiste)` évite au moins que ces deux `SELECT` soient payés à chaque clic
    de pilotage.
11. **R11 — Aucun quota documenté** sur le cloud. Un scénario en boucle serrée enverrait autant de POST
    que d'itérations ; la déduplication n'absorbe que les ordres **identiques**. Aucune limitation de débit
    implémentée (aucun AC ne la demande).
12. **R12 — Pire cas de budget supérieur au seuil d'AC1 quand la session est expirée.** AC1 vise un
    allumage « en moins d'une quinzaine de secondes ». Avec une session en cache — le cas courant — un
    ordre coûte une seule requête (1 à 3 s). Mais si le cache de session a expiré (30 min), le login
    s'ajoute et le pire cas rejoint les **18 s** bornés pour AC8. Assumé : le seuil d'AC1 est approximatif
    et le cas ne survient qu'après une demi-heure d'inactivité. Le rendre systématiquement court
    exigerait un rafraîchissement de session en tâche de fond, qui appartient à UC07/UC08.

## 13. Points de recette (au-delà des critères d'acceptation)

1. **R1** : appareil **éteint**, actionner « Mode Refroidissement ». Vérifier sur l'afficheur : allumage
   **et** mode. Compter les **bips** (un seul attendu).
2. **R2** : régler la consigne à 22 °C, vérifier l'afficheur, relancer un scan, comparer la commande info
   `target_temp`. Si l'appareil affiche 2 °C ou refuse → passer `FACTEUR_TEMP_AUX_HOME` à 10 et
   `PAS_ECRITURE_AUX_HOME` à 0.5, **et mettre à jour**
   `.memory/analyse/smartclim-transport-aux-home.md` §§ 4.2 / 9.
3. **R3** : commander successivement les **5** vitesses, relever l'afficheur à chaque fois, puis relire la
   valeur après expiration de la grâce. Consigner la table réelle et retirer `intent_confirme => false`
   des entrées validées (reprend le point de recette 6 d'UC04).
4. **AC7** : double-clic rapide sur « Vitesse Turbo » → un seul bip, une seule ligne
   `POST /app/device/v2/control` en log `debug`, plus une ligne `debug` de déduplication.
5. **AC8** : couper l'accès Internet du Jeedom, actionner « Marche », chronométrer (< 20 s), vérifier
   qu'un autre onglet Jeedom **reste réactif** pendant l'appel (contrôle direct de
   `session_write_close()`), et qu'une ligne `error` nomme l'équipement et la commande.
6. **AC10 / R4** : scénario « Mode Refroidissement » puis « Vitesse Turbo » sur appareil éteint → les deux
   appliquées. Puis l'ordre inverse, pour documenter le comportement réel.
7. **Grâce** : commander une consigne, relancer un scan **immédiatement** — la commande info **ne doit
   pas** revenir à l'ancienne valeur (anti-rollback). Attendre 60 s, rescanner : la valeur du cloud reprend
   la main.
8. **Grâce contre télécommande infrarouge** : commander « Mode Refroidissement », changer le mode **à la
   télécommande** dans les 60 s, puis scanner → le changement est **masqué** jusqu'à la fin de la grâce.
   Comportement voulu, à constater pour ne pas le prendre pour un bug (et à recouper avec le risque R1
   d'UC05 : `status.control` reflète-t-il seulement le dernier ordre *cloud* ?).
9. **AC9** : sur le dashboard, vérifier que le bouton du mode courant et celui de la vitesse courante sont
   distingués visuellement des autres, et que l'affichage suit un changement d'état après rafraîchissement.
   Idem sur l'interface mobile.
10. **Non-régression UC03/UC04** : deux scans identiques consécutifs après un premier passage d'UC06 →
    **aucun** `save()` d'équipement et **aucun** `cmd::save()` (contrôle direct de R6).
11. Vérifier dans la table des commandes de l'équipement que le sélecteur « Commande info liée » est bien
    renseigné sur les 13 commandes action (contrôle direct d'AC9 côté modèle).
12. Lancer `python .claude/scripts/verif-plugin.py` (colonne `meta=`) **avant** tout commit.

## 14. Dette

_(rempli en fin de cycle, après les reviews)_
