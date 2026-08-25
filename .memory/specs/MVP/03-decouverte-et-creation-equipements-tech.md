# Spec technique — UC03 « Découverte des climatiseurs AUX Home et création des équipements »

> **Domaine** : MVP · **Spec fonctionnelle** : `03-decouverte-et-creation-equipements.md` (AC1→AC8)
> **Dépend de** : UC02 (livrée) · **Date** : 2026-08-25
> Plan produit par `jeedom-tech-planner`, challengé par advisor (1 major + 3 minors traités),
> validé par l'utilisateur.

## 0. Périmètre et décisions actées

UC03 s'arrête à **l'identité de l'équipement**. Sont **hors périmètre et interdits dans ce cycle** :
création de `smartclimCmd`, détection de capacités, décodage de trame HVAC (→ UC04/UC05).

Décisions tranchées avec l'utilisateur au moment de la validation du plan :

| Question | Décision |
|---|---|
| Filtrage des appareils non-climatiseurs | **Aucun filtre.** Le compte de recette ne contient que des climatiseurs, et l'API n'expose aucun champ de type. Un équipement est créé pour **tout** appareil renvoyé — comme l'implémentation de référence. |
| Signalement d'un appareil disparu (AC6) | **Écran de résultat + `log::add(...'warning'...)` uniquement.** Pas de `message::add` dans le centre de messages Jeedom : il se réempilerait à chaque scan tant que l'utilisateur n'a pas supprimé l'équipement. |
| Rafraîchissement de la liste après création (AC1) | **Bouton explicite « Afficher les nouveaux équipements »** (rechargement de page), affiché seulement si `compteurs.crees > 0`. Pas de rechargement automatique : il effacerait le tableau de résultat exigé par AC2 et AC6. |

## 1. Couverture des critères d'acceptation

| AC | Mécanisme |
|---|---|
| **AC1** | `smartclim::scannerAuxHome()` crée un eqLogic par appareil ; le JS affiche le bouton de rechargement dès que `compteurs.crees > 0`. |
| **AC2** | Charge utile AJAX en **liste blanche** : `nom`, `modele`, `mac`, `identifiant`, `enLigne`, `enLigneLibelle`, `statut`, `statutLibelle`. Ni `jeton`, ni `uid`, ni e-mail n'y entrent — aucun de ces champs n'est même lu par la méthode. |
| **AC3** | `setName()` **uniquement** dans `creerEquipement()` ; la branche « équipement existant » ne touche jamais `name`. |
| **AC4** | `setObject_id()` **jamais appelé**, ni à la création ni à la mise à jour. |
| **AC5** | Rapprochement `mac:` → MAC inversée → `auxhome_device_id` **avant** tout `new smartclim()` ; en plus, écriture conditionnée (§ 4.3). |
| **AC6** | `appareilsDisparus()` → bloc « Climatiseurs introuvables sur le compte » + log `warning`. **Aucun `remove()`, aucun `setIsEnable(0)` nulle part dans UC03.** |
| **AC7** | `data == []` est un **succès**, jamais une exception → résumé « Aucun climatiseur trouvé sur ce compte ». |
| **AC8** | Libellés d'état/statut traduits **côté serveur** (`__()` dans `smartclim.class.php`), renvoyés prêts à l'affichage ; 4 chaînes purement client enveloppées dans `desktop/js/smartclim.js`. |

Les deux points « À confirmer » de la spec fonctionnelle (changement de `deviceId` à MAC constante ;
MAC inversée) sont **implémentés** mais non observables au MVP — cf. § 7.

## 2. Contrat externe — liste des appareils AUX Home

Seul appel réseau ajouté par UC03.

```text
GET https://eu-smthome-api.aux-global.com/app/user_device?getStatus=1
En-têtes : ceux déjà posés par smartclimAuxHomeApi::requete()
           + Authorization: bearer <jeton de session>   (jeton UTILISATEUR, pas STATIC_APP_TOKEN)
Corps     : aucun
Réponse   : {"code":200,"message":"","data":[ AuxDevice, … ]}
```

**Source primaire, relue verbatim le 2026-08-25** : `GijsZwegers/com.zwegersit.auxairco` (MIT),
`lib/auxcloud/client.ts`, branche `main` —

- `client.ts:159-161` : `listDevices(bearer, country)` → `request('GET', '/app/user_device', { bearer, country, query: { getStatus: '1' } })` ;
- `client.ts:73-83` : `baseHeaders()` pose exactement les en-têtes que `requete()` pose déjà ;
- `client.ts:105-108` : succès **ssi `json.code === 200`**, puis `return json.data` — ⚠️ **le code HTTP ne fait pas foi** (même règle qu'UC02) ;
- `client.ts:10-21` : schéma d'un élément.

| Champ | Type déclaré | Usage UC03 |
|---|---|---|
| `deviceId` | string | → `configuration.auxhome_device_id` ; repli de `logicalId` ; affiché (AC2) |
| `mac` | string | → normalisée → `logicalId = mac:<12 hex>` + `configuration.mac` ; affichée |
| `alias` | string | → `name` **à la création seulement** ; affiché |
| `modelId` | string | → `configuration.modele` ; affiché |
| `online` | boolean | → affiché « En ligne / Hors ligne ». **Non persisté** (la commande info est UC05) |
| `status.running` / `status.control` / `status.type` | string | **ignorés en UC03** (décodage = UC05/UC07) |

Observations complémentaires sur la référence :
- `drivers/airco/driver.ts` (`list_devices`) : `name: device.alias || 'AUX Airco'` → **`alias` peut être vide** ;
  et **aucun filtrage par type** : un appareil est créé pour tout élément renvoyé.
- `drivers/airco/device.ts:152-165` : en cas d'échec de `listDevices`, re-login puis **un seul rejeu**,
  sans discriminer la cause.

**Limites assumées — à confirmer contre le compte réel** (marquer les constats en recette dans
`.memory/analyse/smartclim-transport-aux-home.md` § 3) :
- L'interface `AuxDevice` décrit **les champs consommés par la référence**, pas le contrat complet du
  backend : la charge utile réelle en contient probablement d'autres (`productId`, `familyId`, `roomId`…).
  On ne lit que les 5 champs ci-dessus ; **tout le reste est ignoré sans erreur**.
- **Format de `mac` inconnu** (séparateurs, casse, longueur) → normalisation défensive + repli explicite.
- **Type réel de `online`** : `boolean` en TS, mais `0/1` ou `"1"` JSON est plausible → normalisation stricte.
- Le backend cousin CN utilise `/app/device_bindings?configId=…&getStatus=1` : **route non transposable**,
  ne pas l'essayer en repli.
- Concorde avec `.memory/analyse/smartclim-transport-aux-home.md` § 3 — **aucune contradiction relevée**.

## 3. Architecture

| Fichier | État | Contenu ajouté | Indentation / fins de ligne |
|---|---|---|---|
| `core/class/smartclimAuxHomeApi.class.php` | modifié | `const BUDGET_SCAN = 25;` · `listerAppareils()` (publique) · `requeteAppareils()`, `normaliserAppareil()`, `nettoyerTexteExterne()` (privées). Reste le **seul point cURL** du plugin. | **2 espaces**, ⚠️ **LF seul** (vérifié : CR=0) — **ne pas convertir le fichier en CRLF** |
| `core/class/smartclim.class.php` | modifié | `scannerAuxHome()` (publique) + helpers privés ; 2 constantes de verrou. **Tous** les `__()` utilisateur d'UC03. | 2 espaces, **CRLF** |
| `core/ajax/smartclim.ajax.php` | modifié | 1 branche `if (init('action') == 'scannerClimatiseurs')`, **après** les branches existantes et **après** `session_write_close()`, **avant** le `throw` final | ⚠️ **4 espaces** (héritage), **CRLF** |
| `desktop/php/smartclim.php` | modifié | bouton `#bt_scannerClimatiseurs` ; panneau masqué `#div_scanResultat` (légende, `#table_scanTrouves` avec `thead` figé et `tbody` vide, `#table_scanDisparus`, `#span_scanResume`, `#bt_scanRecharger`). Placé **dans** `.eqLogicThumbnailDisplay`, entre le conteneur de boutons et la légende « Mes smartclims » → masqué automatiquement à l'ouverture d'un équipement | ⚠️ **tabulations**, **CRLF** |
| `desktop/js/smartclim.js` | modifié | handler `$('#bt_scannerClimatiseurs').off('click').on('click', …)`, rendu des tableaux, gestion `error`/`timeout`, bouton de rechargement | 2 espaces, **CRLF** |
| `core/php/smartclim.inc.php` | **non touché** | **Délibéré** : UC03 n'introduit **aucune classe nouvelle** (uniquement des méthodes sur des classes déjà en `require_once`) → aucune ligne à ajouter, aucun risque d'autoload. |
| `core/config/smartclim.config.ini`, `plugin_info/configuration.txt`/`.php`, `packages.json`, `info.json`, `core/i18n/*.json`, `core/template/` | **non touchés** | aucune clé de config, aucune dépendance, aucune valeur par défaut ; `pluginVersion` est bumpée par le hook `pre-commit` |

## 4. Server vs Client

**Tout le métier est serveur.** Le client n'envoie **aucun paramètre** et ne fait qu'afficher.

Justification : la découverte enchaîne un appel cloud authentifié et des écritures en base — jamais
exposables au navigateur. Les **libellés d'état et de statut sont traduits côté serveur** et renvoyés
prêts à l'affichage : cela concentre les `__()` dans une seule classe et garantit AC8 sans dupliquer
la logique de libellé en JS.

Le rendu de la liste des équipements de `desktop/php/smartclim.php` étant **serveur**, un équipement
fraîchement créé n'apparaît qu'après rechargement — d'où le bouton explicite (§ 0).

## 5. Signatures et responsabilités

### 5.1 `smartclimAuxHomeApi` — brique de transport

Seule classe qui connaît les noms de champs AUX. Elle n'en laisse **aucun** sortir : elle renvoie des
clés génériques françaises.

```php
const BUDGET_SCAN = 25;   // budget GLOBAL d'un scan, toutes requêtes comprises (§ 6.1)

/**
 * @return array<int, array{mac:string, identifiant:string, nom:string, modele:string, enLigne:bool}>
 *         Tableau NORMALISÉ. Tableau vide = compte sans appareil (SUCCÈS, pas une erreur).
 * @throws smartclimException  message TECHNIQUE, recréée sur place avant propagation
 *         (même motif que login()/session() : la trace de requete() porte le jeton).
 */
public static function listerAppareils()

/** @throws smartclimException — classement délégué à classerCodeMetier('user_device', …, TYPE_AUTH) */
private static function requeteAppareils($_jeton, $_tempsRequete)      // → array (enveloppe décodée)
private static function normaliserAppareil($_brut)                     // → array|null (null = inexploitable)
private static function nettoyerTexteExterne($_valeur, $_longueurMax)  // → string sûre (log, DOM, base)
```

**Séquence interne de `listerAppareils()`** :
`$debut = microtime(true)` → `self::session()` (cache 30 min, sinon `login()`) → `requeteAppareils()` →
si `TYPE_AUTH` **et** budget restant ≥ `BUDGET_LOGIN + 3` : `purgerSession()` + `login()` + **un seul**
rejeu (anti-boucle par **booléen local**, jamais de récursion) → `normaliserAppareil()` sur chaque élément.

- `requeteAppareils()` appelle
  `self::requete('GET', '/app/user_device?getStatus=1', null, $_tempsRequete, $_jeton)` : la query string
  est un **littéral constant** (aucune entrée utilisateur dans l'URL) ; `requete()` valide déjà
  `jetonConforme($_jeton)`.
- `nettoyerTexteExterne()` : `is_scalar` → retrait des octets de contrôle → si le sujet n'est pas de
  l'UTF-8 valide (`preg_match` avec le modificateur `u`), repli sur un filtre imprimable → `trim` →
  troncature **puis** retrait des octets de queue jusqu'à redevenir de l'UTF-8 valide.
  ⚠️ **Sans fonctions `mb_*`** (non garanties sur un Jeedom minimal) — même arbitrage que `cleDeTri()`.
  Bornes : `nom` 127, `modele` 64, `identifiant` 100.
  ⚠️ Volontairement **distincte** de `journaliserErreurBackend()` (qui ajoute la neutralisation base64,
  propre aux messages backend) : **on ne refactore pas du code UC02 livré**.

### 5.2 `smartclim` — API générique

Seul endroit des `__()` utilisateur, et seul endroit appelé par la couche AJAX.

```php
const CLE_CACHE_VERROU_SCAN = 'smartclim::scan_en_cours';
const DUREE_VERROU_SCAN = 60;

/**
 * @return array{resume:string, compteurs:array<string,int>, appareils:array, disparus:array}
 * @throws smartclimException  message DÉJÀ curaté en français (via messageErreurAuxHome()).
 */
public static function scannerAuxHome()

private static function indexerEquipements()                                                 // → array{parLogicalId,parDeviceId,tous,noms}
private static function chercherEquipementExistant($_macNorm, $_deviceId, array $_index)     // → smartclim|null
private static function creerEquipement($_logicalId, array $_appareil, $_macNorm, array &$_noms) // → smartclim
private static function appareilsDisparus(array $_index, array $_consommes)                  // → array
private static function normaliserMac($_valeur)      // → 12 hex minuscules, ou ''
private static function macInversee($_macNorm)       // → ordre d'octets inversé, ou ''
private static function nomUnique($_souhaite, array &$_noms)  // → string
private static function libelleStatut($_statut)      // → string traduit
private static function libelleEnLigne($_enLigne)    // → string traduit
private static function resumeScan(array $_compteurs)         // → phrase française
```

**Déroulé de `scannerAuxHome()`** :

1. Garde `compteConfigure()` → **zéro requête** sinon, message réutilisant **le littéral existant** d'UC02.
2. Verrou de cache (§ 6.3).
3. `try { smartclimAuxHomeApi::listerAppareils(); }`
   `catch (smartclimException $e) { log::add('smartclim','error', …type… …message technique…); throw new smartclimException(self::messageErreurAuxHome($e->getType(), $e->getContexte()), $e->getType()); }`
   ⚠️ **C'est le point de bascule message technique → message curaté**. Une `smartclimException` qui
   remonterait sans curation mettrait « code métier 9023 » dans le DOM (piège § 9 d'UC02).
4. `indexerEquipements()` — **une seule** requête `eqLogic::byType('smartclim')`.
5. Boucle **`try/catch` par appareil** (§ 6.4).
6. `appareilsDisparus()`, puis `resumeScan()`.
7. `finally { cache::delete(self::CLE_CACHE_VERROU_SCAN); }`.

**Ordre de rapprochement** (`chercherEquipementExistant`, conforme à
`.memory/analyse/smartclim-architecture-jeedom.md` § 4) :

1. `eqLogic::byLogicalId('mac:' . $macNorm, 'smartclim')` ;
2. `eqLogic::byLogicalId('mac:' . self::macInversee($macNorm), 'smartclim')` → journalisé en `warning` ;
3. index `auxhome_device_id` en mémoire (couvre un `deviceId` conservé alors que la MAC remonte autrement) ;
4. sinon → création.

⚠️ `eqLogic::byLogicalId()` renvoie **`false`** quand rien ne correspond → tester `is_object()`,
**jamais** `!== null`.
⚠️ `macInversee()` inverse **les octets** (`str_split($m, 2)` + `array_reverse`), **jamais** `strrev()`
(qui inverserait aussi les quartets).
⚠️ Un équipement déjà rapproché pendant ce scan est marqué **« consommé »** : un second appareil qui
retomberait dessus est renvoyé en `ignore_doublon`, **jamais écrasé**.
⚠️ `logicalId` **n'est jamais réécrit** après création (c'est l'identité de l'équipement, et rien n'en
garantit l'unicité au niveau SQL).

### 5.3 Point d'entrée AJAX

```text
POST core/ajax/smartclim.ajax.php   action=scannerClimatiseurs

succès → ajax::success(array(
           'resume'    => '<phrase FR>',
           'compteurs' => array('trouves','crees','existants','ignores','erreurs','disparus'),
           'appareils' => [ {nom, modele, mac, identifiant, enLigne, enLigneLibelle, statut, statutLibelle} ],
           'disparus'  => [ {nom, mac, identifiant, statutLibelle} ]))

échec  → ajax::error($e->getMessage(), $e->getType())   // type 1..4, code FIGÉ
```

Rappels **déjà arbitrés** dans le projet, à ne pas re-litiger :
`isConnect('admin')` en tête · `session_write_close()` **après** `ajax::init()` et **avant** tout appel
réseau (déjà en place — **ne pas insérer la nouvelle branche avant cette ligne**) ·
`catch (smartclimException)` → `getMessage()`, **jamais** `displayException()` · puis `catch (Exception)` ·
puis **`catch (Throwable)` en dernier bloc**.
La branche n'appelle **que** `smartclim::`, jamais `smartclimAuxHomeApi` (règle « centraliser les accès
externes » de `CLAUDE.md`).

## 6. Validation et erreurs

### 6.1 Budget de temps — exigence GLOBALE, pas par requête

`BUDGET_SCAN = 25 s`, mesuré depuis l'entrée de `listerAppareils()` — **login compris**. Chaque requête
reçoit `max(3, min(TIMEOUT_REQUETE, BUDGET_SCAN − écoulé))`. Le re-login réactif n'est tenté **que si**
`BUDGET_SCAN − écoulé ≥ BUDGET_LOGIN + 3` (soit ≈ 4 s écoulées au plus) :

| Cas | Calcul | Total |
|---|---|---|
| Session en cache + refus rapide du backend | 4 + 18 + 3 | ≤ 25 s |
| Cache vide (login complet) | 18 + 7, **pas** de rejeu (un jeton frais refusé n'est pas une expiration) | ≤ 25 s |
| Liste en timeout (10 s) | budget restant 15 < 21 → **pas** de rejeu | ≤ 25 s |

Borne dure ≈ 25 s, sous un `max_execution_time` par défaut de 30 s.
Côté client : `timeout: 30000`, `global: false` — ⚠️ **le timeout jQuery n'interrompt pas le PHP**, la
seule borne réelle est serveur.

### 6.2 Classement des erreurs

Réutilise **intégralement** l'ordre impératif d'UC02. **Aucune règle nouvelle, aucun message AUX nouveau** :
`messageErreurAuxHome()` est réutilisée telle quelle.

| Situation | Type | Message affiché |
|---|---|---|
| Compte non configuré | `TYPE_AUTH`, **zéro requête** | littéral **déjà existant** « Compte AUX Home non configuré… » |
| Scan déjà en cours (verrou) | `TYPE_INTERNE`, zéro requête | « Un scan est déjà en cours, réessayez dans quelques instants » |
| Erreur cURL / HTTP ≥ 500 / 429 | `TYPE_RESEAU` | « Service AUX Home injoignable… » (existant) |
| Corps non-JSON, enveloppe absente, `data` non tableau | `TYPE_PROTOCOLE` | « Réponse inattendue du service AUX Home… » (existant) |
| `code` ∈ {9023, 64033} | `TYPE_PROTOCOLE` | idem, via `classerCodeMetier()` (déjà factorisée pour UC03) |
| Autre `code != 200` sur `/app/user_device` | `TYPE_AUTH` | « Échec de la connexion — vérifiez vos identifiants et le pays sélectionné » (existant) — **après** re-login + 1 rejeu si le budget le permet |

### 6.3 Verrou de scan

`cache::byKey()` puis `cache::set()` ne sont **pas atomiques** : c'est une **atténuation** (double-clic,
deux onglets), pas un mutex. TTL de 60 s pour qu'un fatal ne laisse pas le plugin bloqué. Libéré dans
un `finally`.

### 6.4 Validation des données entrantes (serveur)

- `data` doit être un `array` **liste** ; un élément non-`array` est ignoré (log `warning`), **il
  n'interrompt pas le scan**.
- `mac` : `strtolower` → `preg_replace('/[^0-9a-f]/', '', …)` → **exactement 12 caractères**, sinon `''`.
  Aucune tolérance : cette valeur devient un `logicalId`.
- `identifiant` (`deviceId`) : chaîne non vide après nettoyage, ≤ 100 caractères (le `logicalId` de repli
  `auxhome:<id>` doit tenir dans `varchar(127)`). Vide **et** MAC vide → `ignore_identifiant`, **jamais de
  création anonyme**.
- `enLigne` : comparaison **stricte** contre `true`, `1`, `'1'`, `'true'` — ⚠️ **pas** de `!empty()`
  (la chaîne `"false"` passerait).
- `nom` : nettoyé, puis testé avec **`cleanComponanteName()`** (la fonction même qu'appliquera
  `setName()`) : vide → repli `Climatiseur <4 derniers hexa de la MAC>`, ou `Climatiseur` seul si pas de
  MAC ; puis `nomUnique()` (suffixe parenthésé puis numéro d'ordre, **borné à 50 essais**).
  Motif : `eqLogic::save()` **lève une exception** si `name` est vide, et la table porte un index
  **UNIQUE (name, object_id)**.
- **Boucle protégée par appareil** : `catch (Exception)` puis `catch (Throwable)` autour du traitement
  d'un appareil → statut `erreur`, log `error`, **le scan continue** (règle « un équipement en erreur
  n'interrompt pas la boucle »).

### 6.5 Validation côté client

**Aucune** : le JS n'envoie aucun paramètre. Il **affiche** uniquement, et **toujours via `.text()`** sur
chaque cellule — `nom`, `modele`, `identifiant` sont des données tierces, et les noms d'équipements des
données utilisateur : ⚠️ **aucune concaténation HTML** avec ces valeurs.

### 6.6 Sécurité

Rien du jeton, de l'`uid` ou de l'e-mail dans la réponse AJAX ni dans le DOM (AC2) ; le jeton n'est
journalisé nulle part (il ne transite que par `requete()`, qui ne l'écrit pas) ; TLS toujours vérifié ;
`CURLOPT_VERBOSE` interdit. Les logs de scan portent la MAC et le `deviceId` — **identifiants d'appareil,
pas des secrets** — et l'`alias` **nettoyé**.

## 7. Idempotence — la table qui fait foi

| Champ | Création | Chaque scan | Motif |
|---|---|---|---|
| `eqType_name`, `logicalId` | ✅ | ❌ | identité |
| `name` | ✅ `nomUnique(alias nettoyé ?: 'Climatiseur <4 hexa>')` | ❌ | **AC3** |
| `object_id` | ❌ (jamais appelé) | ❌ | **AC4** |
| `isEnable` = 1, `isVisible` = 1 | ✅ | ❌ | un équipement désactivé par l'utilisateur **reste** désactivé |
| `category['heating'] = 1` | ✅ | ❌ | filtre de dashboard ; `wellness` est la catégorie **market**, pas la catégorie eqLogic |
| `configuration.mac` | ✅ | ❌ | miroir du `logicalId` ; le réécrire désynchroniserait les deux sur un rapprochement par MAC inversée |
| `configuration.auxhome_device_id` | ✅ | ✅ | identité cloud, peut changer au ré-appairage |
| `configuration.modele` | ✅ | ✅ | donnée cloud, pas une personnalisation |
| tout le reste (`comment`, `display`, `timeout`, commandes, `transport_mode`) | ❌ | ❌ | hors périmètre UC03 |

### 7.1 ⚠️ Écriture conditionnée — comparer AVANT d'écrire (finding advisor, major)

Sur un équipement **existant**, les deux seuls champs rafraîchissables se traitent ainsi :

```text
si $eq->getConfiguration('auxhome_device_id') !== $identifiant  → setConfiguration(...) ; $modifie = true
si $eq->getConfiguration('modele')            !== $modele       → setConfiguration(...) ; $modifie = true
si ($modifie) { $eq->save(); }
```

**Ne PAS se reposer sur `getChanged()` pour décider d'écrire.** Motif : le plan initial supposait que
`setConfiguration()` alimente `utils::attrChanged()` **exactement** comme les setters scalaires. Cette
hypothèse n'est pas vérifiable dans ce dépôt (le core n'y est pas vendored), et **les deux échecs
possibles sont graves et symétriques** :

- si `setConfiguration()` marque **toujours** l'objet modifié → chaque scan réécrit **tous** les
  équipements (l'idempotence promise par AC5 devient cosmétique) ;
- si elle ne le marque **jamais** → un `deviceId` qui change réellement (le cas « À confirmer » de la spec
  fonctionnelle) **n'est jamais persisté, silencieusement**.

La comparaison explicite est **indépendante du comportement interne du core** et lève les deux risques
d'un coup. `getChanged()` peut rester en garde **supplémentaire**, jamais comme unique condition.

### 7.2 Contrat transmis à UC04/UC05

Un scan **sans changement n'appelle pas `save()`**, donc **pas `postSave()`**. La (re)création des
commandes en UC04 ne doit **pas** se reposer implicitement sur un `postSave` déclenché par le scan : elle
devra être **déclenchée explicitement** et rester **idempotente**.

## 8. Dépendances

**Aucune.** Pas de nouveau paquet, pas de démon, pas de classe nouvelle, pas de clé de configuration.
`plugin_info/packages.json` reste vide, `hasDependency` et `hasOwnDeamon` restent à `false`.

## 9. Impact i18n — français uniquement

⚠️ **Ne toucher à aucun `core/i18n/*.json` dans ce cycle** : la traduction est faite en fin de cycle par
le sous-agent `translator`, sur le code figé.

**`core/class/smartclim.class.php`** — `__('…', __FILE__)`, chaînes **littérales** :
`Créé` · `Déjà présent` · `Ignoré — aucun identifiant exploitable` · `Ignoré — doublon dans la réponse du
cloud` · `Erreur lors de la création — consultez les logs du plugin` · `Introuvable au dernier scan` ·
`En ligne` · `Hors ligne` · `Aucun climatiseur trouvé sur ce compte` · le résumé chiffré (arguments
positionnels) · les fragments « ignoré(s) » et « en erreur » · le résumé des disparus ·
`Un scan est déjà en cours, réessayez dans quelques instants` · `Climatiseur` (préfixe du nom de repli).

*Réutilisées sans nouvelle clé* : « Compte AUX Home non configuré… » et les 4 messages de
`messageErreurAuxHome()`.

⚠️ **Message pluralisé — l'ordre compte** (finding advisor) : envelopper **d'abord**, formater **ensuite**.
`sprintf(__('%d climatiseur(s) déjà connu(s) sont introuvables sur le compte', __FILE__), $n)` — **jamais**
construire la chaîne puis la passer à `__()`. Le scan d'extraction i18n est **statique** : `__($variable)`
échappe à la traduction (règle `CLAUDE.md`).

**`desktop/php/smartclim.php`** — chaînes enveloppées : `Scanner les climatiseurs` ·
`Climatiseurs trouvés` · `Climatiseurs introuvables sur le compte` · `Modèle` · `Adresse MAC` ·
`Identifiant cloud` · `État` · `Résultat`. (`Nom` existe déjà dans ce fichier → même clé.)

**`desktop/js/smartclim.js`** — chaînes enveloppées, ⚠️ **obligatoirement entre guillemets DOUBLES**
(apostrophes en français et dans les traductions cibles) : `Scan en cours…` ·
`Le scan n'a pas répondu à temps` · `Erreur de communication avec le serveur Jeedom` ·
`Afficher les nouveaux équipements`.

⚠️ `desktop/php/*.php` et `desktop/js/*.js` sont des fichiers **rendus** : **aucune méta-séquence
littérale** (double accolade ouvrante, délimiteur de fin de commentaire collé à du texte, balise fermante
PHP en commentaire), **pas même en commentaire**.
**Lancer `python .claude/scripts/verif-plugin.py` avant tout commit.**

## 10. Points de recette

Au-delà des AC1→AC8 de la spec fonctionnelle, à observer sur le matériel réel :

1. **Format réel de `mac`** (risque n° 1) : relever la valeur brute au premier scan. Si ce ne sont pas
   48 bits, tous les appareils basculent sur le repli `auxhome:<deviceId>` — la découverte fonctionne,
   mais **la clé de fusion inter-transports est perdue**. Reporter le constat dans
   `.memory/analyse/smartclim-transport-aux-home.md` § 3.
2. **Verrou de scan** (finding advisor) : cliquer deux fois rapidement sur « Scanner les climatiseurs »,
   ou lancer le scan depuis deux onglets → doit afficher « Un scan est déjà en cours », jamais un doublon
   ni une erreur brute.
3. **Absence d'écriture au second scan** (finding advisor) : AC5 ne teste que la non-duplication.
   Relancer un scan strictement identique et vérifier **en log debug** qu'aucun `save()` n'est émis pour
   les équipements déjà connus.
4. **Fréquence des re-logins** : si les logs montrent un re-login à **chaque** scan, c'est le signal que
   `DUREE_CACHE_SESSION` (30 min, pari d'UC02) est trop longue → à calibrer en UC08.
5. **Équipement créé hors scan** (finding review tour 1) : créer manuellement un eqLogic `smartclim`
   (sans passer par la découverte), puis lancer un scan AUX Home → il **ne doit pas** apparaître dans
   « Climatiseurs introuvables sur le compte », ni produire de log `warning` à chaque scan.
   Motif : seuls les équipements plausiblement issus d'AUX Home (`auxhome_device_id` ou `mac` non vide)
   sont candidats au signalement de disparition — cf. § 5.2.

## 11. Risques

1. **Contrat de `mac` non vérifié** — cf. point de recette 1.
2. **Aucun champ de type d'appareil** → un compte contenant un appareil AUX non climatiseur produirait un
   eqLogic parasite. **Aucun filtre au MVP** (décision § 0, la référence n'en a pas non plus).
3. **`deviceId` qui change à MAC constante** : traité (priorité MAC). Le cas **inverse** (MAC qui change)
   crée un doublon que l'utilisateur devra supprimer à la main — conforme à « la suppression reste une
   décision de l'utilisateur », mais à dire dans la doc utilisateur.
4. **Branche MAC inversée non testable au MVP** : elle ne s'active que face à un second transport
   (post-MVP 01/03). Elle est écrite, journalisée en `warning`, et **ne réécrit jamais** le `logicalId` —
   c'est ce qui la rend inoffensive tant qu'elle n'est pas éprouvée.
5. **Durée de vie réelle du jeton inconnue** — cf. point de recette 4.
6. **Verrou non atomique** — cf. § 6.3.
7. **Budget 25 s vs `max_execution_time`** : borné par construction, mais si l'hôte impose 20 s, un cas
   pathologique renverra du non-JSON (HTTP 500) — le JS l'affichera comme « Erreur de communication »,
   pas comme une trace. Acceptable ; à revoir seulement si observé.
8. **Contrat transmis à UC04/UC05** — cf. § 7.2.
9. **Contrat transmis à UC05/UC07** : `listerAppareils()` renvoie aujourd'hui 5 clés et **jette**
   `status.running` / `status.control`. Choix de périmètre ; ces UC élargiront la structure
   (`trame_controle`, `trame_running`) — `/app/user_device?getStatus=1` est **déjà** l'appel unique qui
   rapporte tout l'état, aucune requête supplémentaire ne sera nécessaire.
10. **Recréation d'un équipement supprimé volontairement** : au scan suivant, il revient. Aucune liste
    d'exclusion au MVP — comportement attendu d'une découverte, à documenter côté utilisateur.
11. **Résultat de scan transient** : AC6 n'est visible qu'au moment du scan (décision § 0).
12. **`smartclimAuxHomeApi.class.php` est en LF seul** : un outil qui « normaliserait » les fins de ligne
    produirait un diff de plusieurs centaines de lignes et masquerait la revue. **Éditer chirurgicalement.**

## 12. Dette

Bilan des deux tours de review. **Tout ce qui atteignait la gate a été corrigé** ; il ne reste aucun
`critical`/`high`/`blocker`/`major`.

**Corrigé pendant le cycle** (tour 1, lot unique) :
- `major` — `appareilsDisparus()` ne filtrait pas ses candidats : un eqLogic créé hors scan aurait été
  signalé « Introuvable au dernier scan » à chaque scan. Filtre `auxhome_device_id` **ou** `mac` non vide.
- `medium` (sécu) — log injection via `getName()` (entrée client renommable) : neutralisation par une
  nouvelle méthode privée `smartclim::neutraliserPourLog()`.
- `low` (sécu) — `$t->getMessage()` non neutralisé dans le `catch (Throwable)` de la boucle par appareil.
- `minor` — `resumeScan()` court-circuitait le fragment « disparus » quand `trouves === 0`.
- `minor` — point de recette manquant → ajouté en § 10.5.
- `low` (sécu, tour 2) — asymétrie entre `catch (Exception)` et `catch (Throwable)` de la boucle par
  appareil : le premier journalisait `getMessage()` brut. Corrigé en passe de finition.

**Findings écartés — prémisse incorrecte, aucune action** (tour 2, sécurité) :
- `medium` « `getLogicalId()` journalisé brut → vecteur d'injection de log via `auxhome:<identifiant>` »
  et le `low` jumeau sur `$identifiant` journalisé brut. **Vérifié : la prémisse est fausse.**
  `identifiant` est produit par `smartclimAuxHomeApi::normaliserAppareil()`, qui lui applique
  `nettoyerTexteExterne()` — laquelle retire précisément `[\x00-\x1F\x7F]` — **avant** que la valeur
  n'atteigne `smartclim::`. Ni `$identifiant` ni le `logicalId` qui en dérive ne peuvent donc porter un
  saut de ligne. Le reviewer a lu le cast `(string)` de `smartclim::` sans remonter à la brique de
  transport. Aucun durcissement ajouté : il serait purement redondant.
  ⚠️ **Invariant à préserver** : cette sûreté repose entièrement sur `nettoyerTexteExterne()` appliquée
  **à la frontière du transport**. Toute future source d'`identifiant` (UC03 du domaine post-mvp/03,
  transport LAN) doit passer par un nettoyage équivalent **avant** de construire un `logicalId`.
