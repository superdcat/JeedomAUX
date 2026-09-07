# Spec technique — UC01 post-mvp/02 : choix du mode de transport par équipement

> **Spec fonctionnelle** : `01-selecteur-de-transport.md` (8 critères d'acceptation)
> **Dépend de** : UC03 de `post-mvp/01` (écriture LAN livrée), UC04 de `post-mvp/01` (fusion multi-transport)
> **Statut** : plan validé par l'utilisateur le 2026-09-07, avec deux arbitrages tranchés en gate (§ 0.2)

## 0. Ce que fait cette UC, en une phrase

Elle introduit un réglage **par équipement** (`AUTO` / `LOCAL` / `CLOUD`) qui décide, **sans aucune E/S
réseau au moment de la décision**, quel transport porte une commande et une lecture — la décision vivant
dans une classe dédiée `smartclimTransport`, l'exécution restant dans `smartclim` (`envoyerOrdreLan()`
d'un côté, `smartclimAuxHomeApi::appliquerOrdre()` de l'autre).

C'est ici, et pas avant, que `executerCommandeAction()` cesse d'être **cloud en dur** — y brancher le LAN
au domaine 01 aurait été « coder en dur un mode AUTO ».

### 0.1 La propriété la plus importante à préserver

**Le socle est conçu pour être inerte** sur une installation où aucun appareil ne répond en Broadlink —
c'est-à-dire l'installation de recette de l'utilisateur. `lanJoignable()` y renvoie **toujours** `false`,
donc AUTO ⇒ cloud, donc **comportement observable strictement identique à aujourd'hui**. La recette de
cette UC vérifie **l'absence de régression**, pas le fonctionnement du LAN (cf. § 11).

### 0.2 Les deux arbitrages tranchés en gate utilisateur

- **Cadence du cycle LAN automatique : 15 minutes** (option (a) proposée). Motif retenu : c'est ce qui rend
  AC4 honnête — un équipement LOCAL garde un état à jour et reste pilotable **même 24 h après le dernier
  scan**, là où la mémoire d'adresse expire. Coût assumé et connu : le protocole Broadlink n'admet **qu'une
  session par appareil**, donc chaque cycle décroche l'application constructeur (AC Freedom) ou tout autre
  logiciel pilotant le même climatiseur en local, et réciproquement (cf. R3).
- **« Rafraîchir » conserve son erreur en mode CLOUD.** Le plan initial déplaçait le traitement de
  `CMD_RAFRAICHIR` **avant** les gardes cloud, ce qui rendait le clic **silencieux** sur un équipement CLOUD
  d'un Jeedom sans compte configuré — `rafraichirAuxHome()` a bien sa garde `compteConfigure()`, mais
  **silencieuse par conception** (`log::add('debug')`, `echecType` reste `null`), parce qu'elle a été écrite
  pour le cron, pas pour un clic. Un équipement dont l'utilisateur a **explicitement** choisi CLOUD doit dire
  pourquoi il ne fait rien, d'autant que toute autre commande du même équipement lève l'erreur.
  Implémentation au § 5.3, étape 3.

## 1. Architecture

### 1.1 Fichiers

| Fichier | État | Contenu | Indentation |
|---|---|---|---|
| `core/class/smartclimTransport.class.php` | **créé** | Couche de **décision pure** : constantes de mode, libellés, normalisation, prédicats `lanJoignable()`/`cloudDisponible()`, `transportRetenu()`, filtres `lectureCloudAutorisee()`/`sondeLanAutorisee()`. **Aucun socket, aucun cURL, aucune écriture de cache, aucun `config::save`, aucun `save()`** | 2 espaces, CRLF |
| `core/php/smartclim.inc.php` | modifié | ⚠️ **Ligne obligatoire** : `require_once __DIR__ . '/../class/smartclimTransport.class.php';`, après `smartclimBroadlinkLan`, avant `smartclimDiagnostic`. Retirer `smartclimTransport` de la liste « classes à venir » du bloc de commentaire | 2 espaces, CRLF |
| `core/class/smartclim.class.php` | modifié | 3 constantes ; `preSave()` (+1 barrière) ; `executerCommandeAction()` (restructurée) ; `rafraichirMaintenant()` (+ branche LOCAL) ; `rafraichirAuxHome()` (+ filtre de cibles) ; `cron()` (+ 2ᵉ garde) ; nouvelles `rafraichirLan()`, `cycleLanEchu()`, `marquerCycleLan()`, `sonderAdresseLan()`, `rafraichirLanEquipement()` ; `scannerReseauLocal()` (2 gardes + réutilisation de `sonderAdresseLan()`) ; `etatConnexionAffichable()` (+1 clé sur 7 branches, 2 gardes) ; `etatsConnexionAffichables()` (+1 clé dans le repli) ; `libelleStatutLan()` (+1 statut) | 2 espaces, CRLF |
| `desktop/php/smartclim.php` | modifié | Bloc `<legend>{{Transport}}` + `<select class="eqLogicAttr" data-l1key="configuration" data-l2key="transport_mode">` (options **générées côté serveur** depuis `smartclimTransport::modes()`, `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` sur **valeur et libellé**) ; 1 ligne d'affichage « Mode de transport » dans le bloc « État de connexion » | ⚠️ **tabulations**, CRLF |
| `desktop/js/smartclim.js` | modifié | `afficherEtatConnexion()` : +1 `.text()` avec **repli chaîne vide obligatoire** ; `saveEqLogic()` : aide à la saisie non autoritaire du mode | 2 espaces, CRLF |
| `core/config/smartclim.config.ini` | **non touché** | La clé est **par équipement**, pas en config plugin : aucun défaut INI, donc **aucun piège `preConfig_` court-circuité** |
| `plugin_info/configuration.txt` + `.php` | **non touchés** | Rien à ajouter au formulaire de config **plugin** ; le miroir `cp` n'a pas lieu d'être |
| `core/ajax/smartclim.ajax.php` | **non touché** | Aucune action AJAX nouvelle : le réglage passe par le formulaire d'équipement natif du core (`eqLogicAttr`) |
| `plugin_info/packages.json` | **non touché** | Aucune dépendance introduite |
| `core/php/commande-lan.php` | **non touché** | Reste le forçage LAN explicite (cf. R4) |

### 1.2 Pourquoi une classe dédiée plutôt que des méthodes sur `smartclim`

`smartclimTransport` est annoncée « à créer » dans `CLAUDE.md` depuis le cadrage, et son contenu est de même
nature que `smartclimCapabilities` et `smartclimFrame` : une **couche de décision sans effet de bord**,
testable par lecture, dont toutes les entrées passent par des accesseurs publics existants
(`smartclim::compteConfigure()`, `$eq->adresseLan()`, `$eq->sondeLanEquipement()`, `$eq->getConfiguration()`).
La mettre dans `smartclim.class.php` — déjà le plus gros fichier du plugin — mêlerait décision et exécution,
qui est exactement la confusion que l'abstraction multi-transport existe pour éviter.

⚠️ **Rappel mécanique, fatal et invisible** : sans sa ligne dans `core/php/smartclim.inc.php`, la classe est
introuvable au runtime — et l'oubli ne casse **ni `php -l`, ni la CI, ni les reviews**, seulement l'exécution,
et ici sur **toutes** les commandes (cf. `CLAUDE.md` → Conventions → Autoload).

## 2. Contrat externe

**Aucun contrat nouveau.** Cette UC n'introduit ni endpoint, ni topic, ni payload : elle **aiguille** vers
deux chemins déjà livrés.

- **Cloud AUX Home** — `GET /app/user_device?getStatus=1` et `POST /app/device/v2/control` : inchangés,
  appelés par `smartclimAuxHomeApi` seule. Source : `.memory/analyse/smartclim-transport-aux-home.md` §§ 3-4.
- **Broadlink LAN** — hello de découverte / sonde unicast, auth `0x65`, requête `0x6A`
  (`CHARGE_ETAT` / `CHARGE_INFO`), écriture encapsulée : inchangés, appelés par `smartclimBroadlinkLan` seule.
  Source : `.memory/analyse/smartclim-transport-broadlink-lan.md` §§ 1-6, 11-13.
- Le seul échange **nouvellement cadencé** (et non nouvellement spécifié) est le couple *hello unicast +
  `lireEtat()`* du cycle LAN périodique — **mêmes trames** que la phase 2 de `scannerReseauLocal()`.

⚠️ **Hypothèse héritée et non levée ici** : le transport LAN n'est recetté sur aucun matériel (le climatiseur
de validation ignore Broadlink). Tout le chemin LAN de cette UC hérite de ce statut.

## 3. Server vs Client

**Tout est serveur.** La décision de transport, sa normalisation et son application sont exclusivement PHP :
le client ne fait qu'afficher un `<select>` natif du core (`eqLogicAttr`) et une ligne de texte.

| Rôle | Côté | Justification |
|---|---|---|
| Choix du transport (`transportRetenu()`) | **serveur seul** | Un scénario, l'API JSON-RPC ou un `execCmd()` d'un autre plugin n'ont aucun code client : une décision côté navigateur serait contournée par construction |
| Normalisation de `transport_mode` | **serveur autoritaire** (`preSave()`) + aide client non autoritaire | Double barrière, même patron que `temp_*` et `lan_*` |
| Rendu du `<select>` (liste des options) | **serveur** (`smartclimTransport::modes()`) | Une liste en dur dans le HTML divergerait de la table à la première évolution ; et les libellés passent par `__()` |
| Affichage « Transport actif » / « Mode de transport » | **serveur** (`etatConnexionAffichable()`), rendu client | Le JS ne calcule rien, il pose le texte |

Aucune action AJAX nouvelle : le réglage transite par le formulaire d'équipement natif du core.

## 4. Validation

| Quoi | Où | Comportement |
|---|---|---|
| `transport_mode` hors des 3 valeurs (API JSON-RPC, restauration, SQL direct) | **serveur, autoritaire** : `preSave()` → `smartclimTransport::normaliserMode()` | Silencieux, valeur forcée à `auto`. ⚠️ **Ne lève jamais** : ce `preSave()` est traversé par le `save()` du scan |
| Valeur lue en base corrompue malgré tout | **serveur, 2ᵉ barrière** : `smartclimTransport::mode()` | Repasse par `normaliserMode()` à chaque lecture. Aucun appelant ne lit `getConfiguration('transport_mode')` directement |
| idem, aide à la saisie | **client** : `saveEqLogic()` | Force `"auto"` si la valeur n'est pas dans `["auto","local","cloud"]`. Ne bloque jamais l'enregistrement, n'affiche aucune alerte (le `<select>` rend le cas quasi impossible). ⚠️ **`return _eqLogic` obligatoire** en fin de fonction |
| Valeur absente au chargement du formulaire | **client**, par construction | ⚠️ La **1ʳᵉ `<option>` doit être AUTO** : `.val(undefined)` sur un `<select>` laisse la liste sur son premier item, et l'enregistrement suivant écrirait cette valeur. Même piège que la liste Pays de `plugin_info/configuration.php` |
| Commande sur un équipement LOCAL sans adresse LAN | `envoyerOrdreLan()` → `sonderAppareilLan()` | `TYPE_RESEAU` + `CONTEXTE_LAN_ADRESSE_INCONNUE` → message **existant** de `messageErreurLan()`. Aucune chaîne nouvelle |
| Commande sur un équipement CLOUD sans `auxhome_device_id` | branche cloud | Message **existant** « Cet équipement n'est pas relié à un appareil AUX Home — relancez un scan ». Honnête : l'utilisateur a explicitement choisi CLOUD |
| « Rafraîchir » sur un équipement CLOUD sans compte / sans identifiant | garde dédiée, § 5.3 étape 3 | Message **existant**, identique à celui de toute autre commande cloud. Décision § 0.2 |
| Commande sur un équipement AUTO, LAN injoignable, sans cloud | branche LAN (repli d'AC3) | Échec **propre** par le message LAN existant. **Aucun message parlant du cloud** — c'est le mécanisme d'AC3 |
| Sonde / lecture LAN périodique en échec | `rafraichirLan()` | `debug`/`warning` uniquement, **jamais `error`**, jamais d'exception, jamais d'`online = false`. Mécanisme d'AC2 : « une MAC Broadlink qui ne répond pas est un résultat de sonde nominal » |

**Aucun type d'exception nouveau.** `smartclimException` conserve ses 4 types et ses contextes existants.

## 5. Signatures

### 5.1 `smartclimTransport` (nouvelle classe, tout en statique)

```php
const MODE_AUTO  = 'auto';
const MODE_LOCAL = 'local';
const MODE_CLOUD = 'cloud';

public static function modes()                              // array<string,string> : code => libellé FR, ordre AUTO/LOCAL/CLOUD
public static function normaliserMode($_valeur)             // string dans {auto,local,cloud} ; défaut AUTO ; ne lève JAMAIS ; accepte un non-scalaire
public static function mode(smartclim $_eqLogic)            // string — barrière de LECTURE
public static function libelleMode(smartclim $_eqLogic)     // string, déjà traduit
public static function lanJoignable(smartclim $_eqLogic)    // bool — ZÉRO réseau
public static function cloudDisponible(smartclim $_eqLogic) // bool
public static function transportRetenu(smartclim $_eqLogic) // string smartclimCapabilities::TRANSPORT_*, jamais ''
public static function lectureCloudAutorisee(smartclim $_e) // bool — mode !== LOCAL
public static function sondeLanAutorisee(smartclim $_e)     // bool — mode !== CLOUD
```

**Aucune ne lève.** Règles, à écrire telles quelles :

- **`lanJoignable()`** = `$_eqLogic->adresseLan()['ip'] !== ''` **ET**
  `$_eqLogic->sondeLanEquipement()['statut'] === smartclimBroadlinkLan::STATUT_ETAT_LU`.

  ⚠️ **Critère volontairement plus strict que `smartclim::statutEnEchec()`** : ce prédicat-là compte
  `STATUT_ETABLIE` / `STATUT_REUTILISEE` / `STATUT_ETAT_ILLISIBLE` comme des succès, or **aucun des trois ne
  prouve que l'appareil parle le HVAC** — router un ordre vers un appareil `ETAT_ILLISIBLE` ferait échouer
  `smartclimFrame::encoderOrdre()` au lieu de partir au cloud. `STATUT_ETAT_LU` est la **même preuve** que
  celle qui autorise la création d'un équipement depuis le LAN (UC04 post-mvp/01 § 5.5). Effet de bord
  bénéfique : `statutEnEchec()` reste `private`, aucune visibilité à élargir.

- **`cloudDisponible()`** = `smartclim::compteConfigure()` **ET**
  `is_string($_eqLogic->getConfiguration('auxhome_device_id')) && $_eqLogic->getConfiguration('auxhome_device_id') !== ''`.

  **C'est la réponse à AC3** : le test est bien **par équipement**, `compteConfigure()` (global au plugin)
  n'en est qu'un des deux termes.

- **`transportRetenu()`** : `LOCAL → TRANSPORT_BROADLINK_LAN` ; `CLOUD → TRANSPORT_AUX_HOME` ;
  `AUTO → lanJoignable() ? TRANSPORT_BROADLINK_LAN : (cloudDisponible() ? TRANSPORT_AUX_HOME : TRANSPORT_BROADLINK_LAN)`.

  ⚠️ Le dernier repli (**LAN**, et non une erreur dédiée) est ce qui rend AC3 vrai **sans écrire une seule
  ligne de message** : « un équipement sans identifiant cloud se comporte comme LOCAL » (spec fonctionnelle,
  § Comportement attendu). Elle ne renvoie **jamais** `''` — donc aucun chemin d'erreur nouveau à inventer.

### 5.2 `smartclim` — constantes

```php
const CLE_CONF_TRANSPORT_MODE     = 'transport_mode';
const CLE_CACHE_DERNIER_CYCLE_LAN = 'smartclim::dernier_cycle_lan';
const INTERVALLE_CYCLE_LAN        = 900;   // 15 min, en secondes — cadence FIXE, cf. § 6
```

### 5.3 `smartclim::executerCommandeAction()` — restructurée

Nouvel ordre des étapes, **impératif** :

1. `session_write_close()` — inchangé, en tête.
2. **Liste blanche** `definitionsCommandesAction()` → `TYPE_INTERNE` « Commande inconnue… » — inchangé, et
   **remonté avant toute garde cloud**. C'est la seule garde d'autorisation du plugin, elle doit rester la
   première.
3. **`CMD_RAFRAICHIR`** → `rafraichirMaintenant()` → `return`. ⚠️ **Précédé d'une garde cloud dédiée**
   (décision § 0.2) : si `smartclimTransport::mode($this) === MODE_CLOUD`, appliquer les mêmes tests que la
   branche cloud (`compteConfigure()` puis `auxhome_device_id`) et lever le **message existant**. En AUTO et
   en LOCAL, aucune garde — le silence y est le comportement voulu par la spec.
4. `$transport = smartclimTransport::transportRetenu($this);`
5. `$ordre = $this->ordreDeCommandeAction($_logicalId, $_options);` — inchangé (c'est lui qui porte la liste
   blanche du contenu, le `power => 1` et la quantification de consigne).
6. **Déduplication** : empreinte, `cache::byKey`, `cache::set` — inchangés, **transport-neutres** (cf. § 7).
7. **Aiguillage**, en **deux blocs `try/catch` distincts** :
   - **`TRANSPORT_BROADLINK_LAN`** → `$this->envoyerOrdreLan($ordre);` puis `return`.
     `catch (smartclimException $e) { cache::delete($cleDedup); throw $e; }` — ⚠️ **ne pas re-curer** :
     `envoyerOrdreLan()` rend déjà un message français curaté par `messageErreurLan()`. `catch (Throwable)`
     en dernier bloc, `cache::delete($cleDedup)` + message interne générique.
     ⚠️ **Ne pas rappeler `enregistrerOrdre()` ni `appliquerEtat()`** : `envoyerOrdreLan()` les fait déjà.
     Asymétrie assumée avec la branche cloud — elle évite de démonter une méthode livrée et recettée.
   - **`TRANSPORT_AUX_HOME`** → gardes cloud **déplacées ici** (`compteConfigure()` puis
     `auxhome_device_id`), puis le corps actuel **inchangé** (`smartclimAuxHomeApi::appliquerOrdre()`,
     `enregistrerOrdre()`, `appliquerEtat(..., true)`) et ses trois `catch`.

⚠️ **Le déplacement des deux gardes cloud est le cœur d'AC2/AC3.** Aujourd'hui elles sont les **premières**
instructions de la méthode : un équipement LOCAL sur un Jeedom sans compte AUX Home échouerait donc sur
« Compte AUX Home non configuré » — exactement le message que la spec interdit pour un fonctionnement nominal.

### 5.4 `smartclim` — autres méthodes modifiées

```php
public function preSave()
public function rafraichirMaintenant()
public static function cron()
private static function rafraichirAuxHome()
private static function scannerReseauLocal()
public function etatConnexionAffichable()
public static function etatsConnexionAffichables(array $_e)
private static function libelleStatutLan($_statut)
```

**`preSave()`** — ajouter, à la suite des barrières `temp_*` / `lan_*` :

```php
$this->setConfiguration(self::CLE_CONF_TRANSPORT_MODE,
  smartclimTransport::normaliserMode($this->getConfiguration(self::CLE_CONF_TRANSPORT_MODE)));
```

Ne lève jamais. Conséquence voulue : **AC1 « un équipement fraîchement créé par le scan est en AUTO » est
satisfait par construction**, sans toucher `creerEquipement()`, et le parc existant se voit poser `'auto'`
explicitement au premier `save()`. La « migration silencieuse » est donc un **no-op** : `mode()` renvoyait
déjà AUTO avant, par absence de clé.

**`rafraichirMaintenant()`** —
`if (smartclimTransport::mode($this) === smartclimTransport::MODE_LOCAL) { $this->rafraichirLanEquipement(); return; }`
puis le corps actuel (cycle cloud global).

Arbitrage : **LOCAL → LAN ; AUTO et CLOUD → cycle cloud** (inchangé). Motif : pour AUTO, le cycle cloud est
la lecture la plus riche (il est le **seul** porteur de `online`) et c'est le comportement actuel — aucune
régression ; tenter LAN **puis** cloud violerait « jamais deux transports pour une même opération » (UC02 du
même domaine, AC3).

**`cron()`** — deux gardes d'échéance **indépendantes**, chacune dans **son propre** `try/catch (Throwable)` :
un cycle LAN en échec ne doit pas empêcher le cycle cloud du tick suivant, et réciproquement. ⚠️ Ne pas
mutualiser le `try` — c'est la règle « un équipement en erreur n'interrompt pas la boucle », transposée aux
deux cycles.

**`rafraichirAuxHome()`** — une seule modification, **avant** `equipementsParIdentifiant()` : filtrer la liste
par `smartclimTransport::lectureCloudAutorisee()` avec un **`foreach` explicite** (⚠️ `os.min: 10` ⇒
Debian 10 ⇒ **PHP 7.3** : pas de fonction fléchée, pas de `str_contains`). Effets, tous voulus :

- un parc 100 % LOCAL retourne sur `if (empty($cibles))` **avant** `listerAppareils()` → **zéro requête
  cloud**, ce qui est la lettre d'AC4 ;
- un équipement LOCAL n'est jamais atteint par `basculerHorsLigne()` → une panne WAN ne le passe pas
  `online = false` alors qu'il répond parfaitement en local.

**`scannerReseauLocal()`** — deux gardes AC5 :

- **Phase 1** : **remonter `chercherEquipementExistant()` avant `lireEtat()`**. Vérifié : c'est un lookup
  **purement en mémoire** sur `$index`, sans dépendance au résultat de `lireEtat()`, dont le seul effet de
  bord est un `log::add('warning')` sur rapprochement par MAC inversée. Si un équipement est rapproché **et**
  en mode CLOUD : `$rencontres[$mac] = true`, push d'une `ligneResultatLan()` au statut
  `'ignore_mode_cloud'`, `continue` — **aucune session ouverte, aucune écriture de mémoire de sonde**.
- **Phase 2** : `continue` en tête de boucle si `!smartclimTransport::sondeLanAutorisee($eqLogic)` — c'est là
  que se trouve le vrai paquet unicast vers l'IP de l'appareil.

**`etatConnexionAffichable()`** — ajouter une clé `'modeTransport'` (= `smartclimTransport::libelleMode($this)`).

⚠️ **La méthode a SEPT branches de retour**, pas six — les lister explicitement en implémentant, et vérifier
qu'aucune n'est oubliée : (1) équipement désactivé ; (2) compte non configuré ; (3) `online === true` ;
(4) incident non-réseau ; (5) incident réseau ; (6) `online === false` ; (7) repli « État inconnu ».
⚠️ **Et le repli `catch (Throwable)` de `etatsConnexionAffichables()`** est un **8ᵉ point d'écriture** à ne pas
oublier : un champ omis reproduit le piège `.text(undefined)` documenté en commentaire dans le fichier — le
texte de l'équipement précédemment consulté reste affiché.

Deux gardes à ajouter, porteuses d'AC2/AC3 :

- la branche « Compte AUX Home non configuré » (`niveau: danger`) n'est prise **que si**
  `smartclimTransport::lectureCloudAutorisee($this)` ;
- le bloc `incidentMemorise()` (incident de connexion **du compte cloud**) n'est consulté **que si**
  `lectureCloudAutorisee($this)`.

Sans elles, un équipement LOCAL sur un Jeedom sans WAN afficherait « Erreur de connexion » alors qu'il est
piloté normalement — exactement le « message d'erreur pour un fonctionnement nominal » que la spec proscrit.

### 5.5 `smartclim` — méthodes nouvelles

```php
private static function cycleLanEchu()    // bool  — même patron que cycleEchu() : seuil INTERVALLE_CYCLE_LAN - MARGE_ECHEANCE_CYCLE, horloge reculée neutralisée
private static function marquerCycleLan() // void  — cache::set(CLE_CACHE_DERNIER_CYCLE_LAN, (string) time(), DUREE_MEMOIRE_CYCLE)

private static function rafraichirLan()   // array{lance:bool, sondes:int, lus:int, injoignables:int, erreurs:int}
public  function rafraichirLanEquipement()// void
private static function sonderAdresseLan(smartclim $_eqLogic, $_budget) // array{appareil:array|null, statut:string, mac:string}
```

**`rafraichirLan()`** — **ne lève JAMAIS** (`try/catch (Throwable)` global + un `try/catch` **par
équipement**). Marqueur d'échéance posé **AVANT tout paquet**. **Une** lecture SQL
(`eqLogic::byType('smartclim', true)`). Pour chaque équipement :

- `!sondeLanAutorisee()` → skip (AC5) ;
- `adresseLan()['ip'] === ''` → skip, **zéro paquet** ;
- budget global `BUDGET_LAN` évalué **avant chaque appareil** (arrêt dur) ;
- `sonderAdresseLan()` → `lireEtat(min(BUDGET_LECTURE_LAN, restant))` →
  `appliquerEtat(smartclimBroadlinkLan::etatAppareil($lecture))`.

⚠️ `appliquerEtat()` appelée **SANS second argument** : `$_optimiste` reste `false`, donc
`filtrerEtatSelonOrdres()` s'applique — le cycle LAN hérite gratuitement de la période de grâce, exactement
comme le cron cloud d'UC07 du MVP.
⚠️ **AUCUN `appliquerCapacites()`, AUCUN `$eqLogic->save()`** : lecture d'état **seule**, invariant du cycle
cloud repris tel quel. Le vecteur de migration du parc reste le **scan**.
⚠️ **AUCUN `basculerHorsLigne()`** : un LAN muet ne prouve pas qu'un appareil est hors ligne (VLAN, pare-feu,
diffusion filtrée) — seul le cloud sait le dire.

**`sonderAdresseLan()`** — extraction de la phase 2 de `scannerReseauLocal()` :
`adresseLan()` → `interroger()` → contrôle MAC (**directe ET inversée**). Ne lève jamais.
Deux appelants : `scannerReseauLocal()` phase 2 (qui conserve ses compteurs et ses lignes de résultat) et
`rafraichirLan()`.

⚠️⚠️ **Elle n'écrit `memoriserSondeLan()` que pour les DEUX issues TERMINALES** (`INJOIGNABLE`,
`MAC_DIVERGENTE`). **L'issue « trouvé » ne l'écrit PAS** : elle rend la main à l'appelant, qui écrira la
mémoire **après son propre `lireEtat()`**, avec le **statut réellement lu** (`STATUT_ETAT_LU` /
`STATUT_ETAT_ILLISIBLE` / …). C'est le comportement du code actuel, et c'est **non négociable** : écrire la
mémoire au moment du simple `interroger()` ferait qu'elle ne recevrait **jamais** `STATUT_ETAT_LU`, donc que
`lanJoignable()` serait **toujours faux** — AC2 et AC8 morts en silence, et **invisibles en recette** puisque
le LAN n'est recettable sur aucun matériel (R1).

**`rafraichirLanEquipement()`** — bouton « Rafraîchir » d'un équipement LOCAL. Budget global
`BUDGET_ORDRE_LAN` (même enveloppe qu'un ordre interactif : hello + session + 1 échange). **LÈVE** une
`smartclimException` au message **déjà curaté** par `messageErreurLan()` — c'est un chemin **interactif**,
un échec silencieux y est interdit.

## 6. La sonde de joignabilité LAN

**Source de vérité** : la mémoire de sonde **existante** `smartclim::lan_appareil::<mac>` (cache non chiffré,
TTL 24 h), lue via `sondeLanEquipement()` (qui essaie déjà `lan_mac` saisie, `macEquipement()` et leurs
inverses). **Aucune nouvelle mémoire n'est créée.**

**Coût sur le chemin de commande : zéro paquet, zéro timeout.** La décision est **exclusivement** une lecture
de cache. Écarté explicitement : la sonde paresseuse au moment de la commande (« si la mémoire est périmée,
un `interroger()` de ≤ 2 s »). Motif : sur l'installation de recette, **tous** les équipements sont en AUTO et
**aucun** ne répond en Broadlink — une sonde paresseuse ajouterait jusqu'à 2 s à chaque commande cloud, sur un
chemin qui n'aboutira jamais, pour un bénéfice nul.

**Qui alimente la mémoire** : (a) le **scan manuel**, comme aujourd'hui ; (b) le **cycle de sonde LAN dans
`cron()`**, cadencé à **15 minutes fixes**, avec sa **propre** garde d'échéance
(`smartclim::dernier_cycle_lan`) — **jamais** `refresh_interval`.

Motifs de la cadence (la spec fonctionnelle la laissait explicitement ouverte) :

1. **Découplée de `refresh_interval`** : un utilisateur réglé à 1 min transformerait sinon le processus
   **partagé** `plugin::cron` en générateur de trafic UDP (N appareils × jusqu'à `BUDGET_LAN` = 18 s **par
   minute**).
2. **15 min ≪ 24 h de TTL** de la mémoire : l'auto-guérison (dette D-5/R7 d'UC02 post-mvp/01) est acquise avec
   une large marge — un équipement LOCAL sans `lan_ip` saisie ne devient plus impilotable après 24 h sans
   scan. Gain réel pour AC4.
3. La joignabilité d'un appareil fixe ne varie pas à l'échelle de la minute ; la fenêtre de « faux positif »
   (appareil débranché, mémoire encore positive) est bornée à 15 min — au-delà, ce sont **les compteurs
   d'échec d'UC02** qui sont propriétaires du sujet, pas cette UC.
4. **Marqueur posé AVANT le premier paquet** (même règle que `marquerCycle()`) : un réseau hostile ne doit pas
   être re-sondé chaque minute.

**Portée du cycle LAN** : mode ∈ {AUTO, LOCAL} **et** adresse LAN connue. Deux conséquences importantes :

- **Pas d'interblocage** : un équipement AUTO sans adresse connue n'est jamais sondé et reste cloud — c'est
  cohérent, le **scan** reste le vecteur de découverte, le cycle n'est qu'un vecteur de **maintenance**.
- **Inerte sur l'installation de recette** : aucun équipement n'y a d'adresse LAN détectée → le cycle ne fait
  **aucun** appel réseau, aucun `save()`, aucune écriture de commande. Coût réel : une lecture de cache par
  minute, une lecture SQL toutes les 15 min.

### 6.1 Chemin de lecture — arbitrage explicite

| Mode | Lecture cloud (cycle global) | Lecture LAN (cycle 15 min) |
|---|---|---|
| **CLOUD** | oui (inchangé) | **jamais** (AC5) |
| **AUTO** | **oui, maintenue** | oui si adresse connue |
| **LOCAL** | **non — retirée** | oui si adresse connue |

- **LOCAL est retiré du cycle cloud** parce que la spec l'écrit noir sur blanc (« Aucune requête cloud n'est
  jamais émise », « Doit fonctionner intégralement sans accès Internet ») et parce que, sans ce retrait, une
  panne WAN ferait basculer l'équipement en `online = false` alors qu'il répond parfaitement — contradiction
  directe avec l'esprit d'AC4. Le cycle LAN lui rend sa lecture d'état ; sans lui, son état **gèlerait**, ce
  qui serait une régression nette.
- **AUTO est maintenu dans le cycle cloud**, même quand la commande part en LAN. Motifs : le cycle cloud est
  **un seul appel pour tout le compte** — exclure un équipement de la distribution n'économise **aucune**
  requête ; le cloud est le **seul** porteur de `online` (`smartclimBroadlinkLan::etatAppareil()` ne pose
  jamais `online => false`, par construction) ; et un AUTO piloté en LAN dont le cycle LAN serait inerte
  verrait son état gelé.
- **Écarté** : « lecture hors périmètre, renvoyée à UC02/UC03 ». UC02 est propriétaire des **compteurs
  d'échec** et UC03 du **changement d'IP** ; aucune des deux n'a la lecture périodique dans son objet, et la
  spec technique d'UC02 post-mvp/01 renvoie explicitement « le *périodique* d'AC1 se recettera au domaine 02 ».
  C'est ici, ou nulle part.

## 7. Interaction avec les caches existants

| Cache | Faut-il le qualifier par transport ? | Décision |
|---|---|---|
| `smartclim::ordre_recent::<id>::<empreinte>` (dédup, 10 s) | **Non** | La clé est le **contenu de l'ordre** ; l'appareil bipe quel que soit le chemin emprunté, et deux envois du même ordre en 10 s via deux transports **doivent** être dédupliqués. ⚠️ **Changement de couverture** : `envoyerOrdreLan()` ne posait aucun marqueur (chemin CLI) ; passant désormais par `executerCommandeAction()`, le LAN est couvert — l'amélioration que la spec technique d'UC03 post-mvp/01 anticipait. Le marqueur **doit** être supprimé en cas d'échec **des deux** branches |
| `smartclim::ordres::<id>` (grâce, 60 s) | **Non** | Décrit l'**état physique** de l'appareil, pas un chemin. Le partager entre transports est une **propriété recherchée** : un ordre LAN suivi d'une lecture cloud est protégé de l'anti-rollback, et réciproquement |
| `smartclim::dernier_cycle` (48 h) | **Non** | Reste le marqueur du **cycle cloud**. Le cycle LAN reçoit sa **propre** clé `smartclim::dernier_cycle_lan` : mutualiser les deux lierait deux cadences volontairement distinctes |
| `smartclim::dernier_incident` (48 h) | **Non**, mais sa **lecture** est conditionnée | L'incident porte sur le **compte cloud** : il reste global et non qualifié. En revanche `etatConnexionAffichable()` ne le consulte plus pour un équipement LOCAL (§ 5.4) |
| `smartclim::lan_appareil::<mac>` (24 h) | sans objet | Déjà par MAC, donc par appareil et par nature LAN |
| `smartclim::session_lan::<mac>` (30 min, chiffré) | sans objet | Inchangé |

## 8. Impact i18n (français uniquement)

Chaînes **littérales** dans `__()`, jamais `__($variable)` — l'extraction est un scan statique.

- `core/class/smartclimTransport.class.php` : `Automatique`, `Local`, `Cloud`
- `core/class/smartclim.class.php` : `Ignoré — équipement en mode Cloud` (`libelleStatutLan()`)
- `desktop/php/smartclim.php` : `{{Transport}}` (legend), `{{Mode de transport}}` (label du `<select>` **et**
  label de la ligne d'affichage), `{{AUTO privilégie le réseau local quand l'appareil y répond, sinon le cloud}}`
  (infobulle)

Total : **7 clés** (3 + 1 + 3). Traduction (`en_US`, `de_DE`, `es_ES`) déléguée au sous-agent `translator` en fin de cycle.

⚠️ « Automatique » existe déjà comme clé dans `smartclimCapabilities` : les fichiers i18n étant indexés **par
chemin de fichier**, c'est bien une entrée **nouvelle**, pas un doublon.
⚠️ Les noms de transport (`AUX Home`, `Broadlink LAN`) restent **hors `__()`** : ce sont des marques
(`smartclimCapabilities::libelleTransport()`), et `libelleMode()` les concatène sans gabarit traduisible —
donc sans piège d'arguments positionnels.

## 9. Périmètre

**Dans le périmètre** : le réglage à 3 valeurs et sa persistance, la décision de transport, l'aiguillage de la
commande, l'aiguillage de la lecture, le cycle LAN périodique, l'affichage du mode et du transport actif.

**Hors périmètre**, explicitement :

- Les **compteurs d'échec, seuils et temporisation** de bascule automatique LAN → cloud → UC02 du même domaine.
  Cette UC livre la décision **statique** ; UC02 la rendra **dynamique**.
- La **résilience au changement d'IP DHCP** → UC03 du même domaine.
- L'affichage riche du transport dans une tuile de dashboard → domaine post-mvp/06.
- Le cloud legacy comme cible de mode CLOUD → domaine post-mvp/03. Aujourd'hui `TRANSPORT_AUX_HOME` est le
  seul cloud.

## 10. Dépendances

**Aucune.** Aucun paquet système, aucun paquet pip, aucune extension PHP nouvelle. `plugin_info/packages.json`
n'est pas touché. Le démon (domaine post-mvp/05) n'est **pas** sollicité : l'invariant « le démon est latéral »
est préservé — aucune méthode de cette UC ne référence `smartclimDemon`.

## 11. Recette — ce qui est vérifiable, et ce qui ne l'est pas

**Vérifiable sur l'installation de l'utilisateur** :

- AC1 : le `<select>` à 3 valeurs apparaît, un équipement existant s'affiche en **Automatique** sans avoir
  jamais été configuré, un scan crée un équipement en AUTO.
- AC6 : la ligne « Mode de transport » s'affiche à côté de « Transport actif », sur **toutes** les branches
  d'état (désactivé, hors ligne, incident, inconnu…) — c'est le test du piège `.text(undefined)`.
- AC7 : changer de mode dans les deux sens, vérifier qu'aucune commande n'est créée, renommée ni supprimée
  (comparer la liste des `logicalId` avant/après) et qu'un scénario existant continue de fonctionner.
- **Non-régression, le point le plus important** : en AUTO (défaut), toutes les commandes continuent de partir
  au cloud et de fonctionner exactement comme avant ; le cron cloud tourne à l'identique.
- Mode CLOUD : le scan affiche « Ignoré — équipement en mode Cloud » au lieu de sonder.

**Non vérifiable ici** (le climatiseur de validation ignore Broadlink) : AC2, AC3, AC4, AC5 et AC8 dans leur
partie LAN, ainsi que le cycle LAN périodique. `lanJoignable()` y renvoie toujours `false`. Ces critères sont
**vérifiés par lecture** contre `mjg59/python-broadlink` et les specs techniques du domaine 01, jamais contre
du matériel — statut hérité, cf. R1.

## 12. Risques

- **R1 — Le chemin LAN n'est recettable sur aucun matériel.** Sur l'installation de l'utilisateur, le seul
  comportement observable nouveau est le `<select>` et la ligne « Mode de transport ». Propriété à vérifier en
  priorité : *aucune régression*, pas *le LAN fonctionne*.
- **R2 — « Transport actif » alternera sur un équipement AUTO piloté en LAN** : le cycle cloud repose
  « AUX Home » toutes les `refresh_interval` minutes après qu'une commande LAN a posé « Broadlink LAN ». C'est
  la **lettre d'AC6** (« celui de la dernière lecture/commande réussie ») et le comportement livré depuis UC02
  post-mvp/01 ; la nouvelle ligne « Mode de transport » lève l'ambiguïté. Bascule vers la sémantique
  « transport retenu » = une ligne dans `appliquerEtat()`, mais elle contredirait le libellé de l'AC. UC02
  (AC7 : distinguer un repli d'un mode CLOUD manuel) est le bon moment pour rouvrir.
- **R3 — Le cycle LAN vole la session Broadlink de l'application constructeur.** Le protocole n'admet qu'une
  session par appareil ; toutes les 15 min (session en cache 30 min ⇒ ré-authentification une fois sur deux),
  le plugin décroche AC Freedom / Home Assistant, et réciproquement. Inhérent à toute scrutation LAN, arbitré
  en gate avec l'utilisateur le 2026-09-07 en connaissance de ce coût.
- **R4 — Trou AC5 par la CLI** : `core/php/commande-lan.php` continue de forcer le LAN, y compris sur un
  équipement en mode CLOUD. Conservé délibérément (outil de diagnostic admin/SSH, nommé pour ce qu'il fait,
  hors « pendant une commande » au sens de l'AC). Le refermer coûterait 3 lignes mais supprimerait la seule
  façon de tester le LAN sur un équipement réglé en CLOUD.
- **R5 — Fenêtre de faux positif de 15 min** : un appareil LAN débranché reste « joignable » jusqu'à la
  prochaine sonde ; en AUTO, ses commandes échouent proprement au lieu de partir au cloud. **Conforme** à
  UC02 AC3 (« avant le seuil, échec propre, pas de tentative cloud pour la même opération ») et explicitement
  renvoyé à UC02 par la spec fonctionnelle.
- **R6 — AC7 et `postSave()`** : enregistrer un changement de mode déclenche `postSave()` →
  `creerCommandesInfo()` + `creerCommandesAction()`. Vérifié : les deux ne **créent** que ce qui manque, ne
  renomment ni ne suppriment jamais, et `definitionsCommandes*()` dérivent du **profil de capacités**, jamais
  du mode. La seule écriture possible est la repose idempotente du widget sur une commande dont le template a
  été vidé — comportement pré-existant, indépendant du mode.
  ⚠️ **Invariant à ne pas casser en implémentant** : ne **jamais** faire dépendre
  `definitionsCommandesAction()` du transport — notamment ne pas remplacer le filtre
  `versTransport(TRANSPORT_AUX_HOME, …)` par le transport retenu. La surface de commandes basculerait avec le
  mode, et c'est précisément ce qu'AC7 interdit.
- **R7 — Vérifié, pas d'orphelin de correspondance** : le profil ne publie que
  `valeursLisibles(TRANSPORT_AUX_HOME, …)`, soit les modes {auto, cool, dry, heat, fan} et les vitesses
  {auto, low, medium, high, turbo} — **toutes** ont un `intent` non nul dans la table LAN. Aucune commande
  existante ne peut donc lever `TYPE_INTERNE` à `smartclimFrame::encoderOrdre()` du seul fait de l'aiguillage.
  ⚠️ À re-vérifier si une ligne `'fil' => null` passe un jour à une valeur.
- **R8 — Dépassement de budget hérité** : le plancher `max(1, …)` par échange (dettes D-1 d'UC02 et D-1 d'UC03
  post-mvp/01) fait que `rafraichirLan()` peut dépasser légèrement `BUDGET_LAN` dans le pire cas. Non aggravé,
  mais il vit désormais dans un cron **partagé avec tous les plugins** : le garde-fou reste l'arrêt dur évalué
  **avant chaque appareil**.
- **R9 — Rappel mécanique** : `smartclimTransport` est inutilisable sans sa ligne dans
  `core/php/smartclim.inc.php`, et l'oubli ne casse **ni `php -l`, ni la CI, ni les reviews** — seulement le
  runtime, et sur *toutes* les commandes. Lancer `python .claude/scripts/verif-plugin.py` avant commit
  (colonne `meta=` : les nouveaux docblocks parlent de transports et de trames, terrain propice à un `*/`
  collé au texte).
- **R10 — Contrainte transmise à UC02** : les compteurs d'échec voudront écrire la mémoire de sonde depuis le
  **chemin d'écriture** (`sonderAppareilLan()` / `envoyerOrdreLan()`), laissé intact ici pour ne pas modifier
  le chemin interactif livré. C'est le point d'extension naturel — ne pas le préempter.

## 13. Dette

### D-1 — Le garde-fou de joignabilité LAN devient un critère de ROUTAGE, et il est faible

Relevé en review sécurité (finding `low`, tour 1). `lanJoignable()` s'adosse à `STATUT_ETAT_LU`, lui-même
produit par `smartclimFrame::conceptsLisibles()`, qui **ne teste que des longueurs** (≥ 13 octets) et
**jamais le magic `bb00`** — dette déjà consignée à la spec technique d'UC04 post-mvp/01 § 12.2, et arbitrée
avec l'utilisateur le 2026-09-03.

Ce qui change ici : jusqu'à cette UC, la conséquence d'un faux positif était **bornée à un équipement de
trop**, supprimable à la main. Elle devient le critère qui fait **router automatiquement une commande
réelle**, en tâche de fond et sans action de l'utilisateur (mode AUTO + cycle de 15 min). Le périmètre
d'attaque reste celui, déjà accepté, du LAN local (il faut usurper IP ou MAC), et ce n'est **pas** une
régression de frontière d'authentification — c'est l'élargissement du rayon d'impact d'une dette existante.

**Ce qu'il faudra faire au durcissement** : vérifier explicitement le magic `bb00` (`smartclimFrame::estTrameHvac()`
existe déjà, et est aujourd'hui un simple signal de journalisation) **avant d'autoriser le basculement
automatique** — et pas seulement avant de créer un équipement. ⚠️ Le rendre bloquant sur le chemin de lecture
LAN rendrait ce chemin inopérant **en silence**, le préfixe n'ayant jamais été observé sur une réponse LAN
réelle : c'est bien le **routage** qu'il faut durcir, pas le décodage.

Non traité dans cette UC : le durcissement demande du matériel Broadlink réel pour ne pas remplacer un faux
positif par un faux négatif silencieux (R1).

### D-2 — `appliquerLectureLan()` et le rafraîchissement : une frontière qui tient par convention

Relevé en review qualité (`blocker`, corrigé dans ce cycle). `appliquerLectureLan()` appelle
`appliquerCapacites()` puis `$eqLogic->save()` — légitime sur un **scan**, interdit sur un
**rafraîchissement** (§ 5.5). `rafraichirLan()` et `rafraichirLanEquipement()` l'empruntaient : comme
`smartclimBroadlinkLan::capacitesAppareil()` pose toujours `'source' => broadlink_lan` et que
`appliquerCapacites()` compare `json_encode($fusion) === json_encode($actuelSansDate)` — comparaison qui
**inclut `source`** —, un équipement en AUTO découvert par le cloud (`source = auxhome`) déclenchait un
`save()` à **chaque** cycle de 15 min, le scan cloud suivant remettant `auxhome` : oscillation permanente
du champ en base, dans le processus `plugin::cron` partagé par tous les plugins.

Corrigé : les deux chemins de rafraîchissement appellent `appliquerEtat()` **en direct**, et
`appliquerLectureLan()` n'a plus que les appelants du **scan**. Forme retenue : deux appels directs plutôt
qu'une méthode extraite — les deux appelants ont des **contrats d'erreur opposés** (`rafraichirLan()` ne
lève jamais, `rafraichirLanEquipement()` doit laisser remonter), qu'une méthode partagée aurait dû trancher
ou paramétrer.

⚠️ **Ce qui reste en dette, c'est la fragilité de la frontière** : rien dans le code n'empêche un futur
chemin de rafraîchissement d'appeler `appliquerLectureLan()` — seuls les docblocks le disent. Le défaut est
passé **deux fois** en review (le tour 1 a validé l'invariant sur l'appel direct sans suivre la délégation).
Piste si le domaine 02 en ajoute d'autres : rendre `appliquerCapacites()` inatteignable depuis un chemin de
lecture autrement que par convention.
