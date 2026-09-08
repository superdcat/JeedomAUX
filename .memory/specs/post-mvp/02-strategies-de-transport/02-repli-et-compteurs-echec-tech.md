# Spec technique — UC02 · Repli LAN → cloud avec compteurs d'échec et temporisation

> **Domaine** : post-mvp/02-strategies-de-transport · **Spec fonctionnelle** :
> `02-repli-et-compteurs-echec.md` · **Dépend de** : UC01 de ce domaine
> (`01-selecteur-de-transport-tech.md`) · **Écrite le** : 2026-09-08

## 0. Ce que cette UC change, en une phrase

L'UC01 a livré une décision de transport **statique** (mode configuré + preuve de sonde). Cette UC la rend
**dynamique** : un compteur d'échecs LAN consécutifs par équipement fait basculer un équipement AUTO vers
son cloud au 3ᵉ échec, le cycle de sonde LAN existant (15 min) le ramène au LAN au premier succès, et
l'IHM d'administration distingue ce repli d'un mode CLOUD choisi à la main.

### 0.1 Propriété directrice — inerte hors repli, et **code mort sur le matériel de recette**

Héritée de l'UC01 § 0.1 et à préserver : **aucune écriture, et aucun coût sur le chemin de commande, hors
repli.** Le climatiseur de validation de l'utilisateur ignore le protocole Broadlink, donc :

- `adresseLan()['ip']` reste `''` pour tout équipement → `envoyerOrdreLan()`, `rafraichirLan()` et
  `sonderAdresseLan()` ne sont **jamais** invoquées → `echecsLanConsecutifs()` reste à 0 ;
- donc `smartclimTransport::repliCloudActif()` est **toujours faux** ;
- donc **les trois insertions cloud d'`executerCommandeAction()` et celle de `rafraichirAuxHome()` ne
  s'exécuteront jamais** sur cette installation.

⚠️ **Conséquence de recette, à énoncer et non à découvrir** : ce qui est vérifiable sur le matériel de
l'utilisateur est **l'absence de régression** du chemin cloud, pas le fonctionnement du repli. AC1–AC6 et
AC8 sont vérifiés **par lecture**. C'est le motif direct du § 9 (CLI `--transport`), seul instrument de
constat.

⚠️ **Une seule inflexion à la formule « strictement inerte » de l'UC01** : `rafraichirAuxHome()` fait
désormais **une lecture de cache par équipement et par cycle cloud** (`oublierEchecsCloud()`, qui sort
immédiatement sur `cloud === 0`). Ordre de grandeur : ce que `rafraichirLan()` fait déjà toutes les 15 min.
Aucune écriture n'en résulte hors repli.

---

## 1. Décisions arbitrées avec l'utilisateur (2026-09-08)

| # | Question | Décision retenue |
|---|---|---|
| **Q1** | Mécanique de temporisation cloud (AC5) | **Refus immédiat daté** — disjoncteur à demi-ouverture, 30/60/120/240/300 s. Aucun `usleep()`, aucun rejeu HTTP |
| **Q2** | Correctif du défaut d'UC01 dans `sonderAdresseLan()` | **Pris dans cette UC** (aucun AC ne tient sans lui) |
| **Q3** | Libellé IHM du repli (AC7) | **Avec le compteur** : `Automatique (repli cloud actif — 3 échec(s) LAN consécutif(s))` |
| **Q4** | Instrument de constat | **`--transport` ajouté** à `core/php/commande-lan.php`, lecture seule |

Ces quatre décisions sont **fermées**. Les révisions issues de la revue croisée du plan (advisor
`code-reviewer`) sont intégrées ci-dessous et tracées au § 12.

---

## 2. Architecture

### 2.1 Où vit le compteur — arbitrage central

**Le stockage et toutes les écritures vivent dans `smartclim` ; la politique (seuil, délais, prédicats)
reste dans `smartclimTransport`, qui n'accède au compteur que par un accesseur public de `smartclim`.**

Le contrat de `smartclimTransport` (« aucune E/S, aucune écriture de cache, aucun `config::save`, aucun
`save()` », `CLAUDE.md` § Architecture) n'est **pas** relâché. Tel qu'il est réellement implémenté, il ne
dit pas « cette classe ne lit rien » : `lanJoignable()` lit **déjà** le cache
`smartclim::lan_appareil::<mac>`, indirectement via `$_eqLogic->sondeLanEquipement()`. Ajouter
`$_eqLogic->echecsLanConsecutifs()` est **exactement le même geste**, au même endroit, sur la même ligne
de contrat.

Branches écartées, et leur coût :

- **(a) Relâcher le contrat** (mettre `cache::set()` dans `smartclimTransport`). La classe cesserait
  d'être vérifiable par lecture seule et rejoindrait le groupe des classes à effets de bord —
  `smartclimCapabilities`, `smartclimFrame` et `smartclimTransport` ne seraient plus de même statut dans
  `CLAUDE.md`. Aucun gain : la classe n'a pas besoin d'écrire, ce sont les **chemins d'exécution** qui
  savent si une opération a réussi.
- **(c1) Stocker en `configuration` d'équipement.** Coût **rédhibitoire** : un `$eqLogic->save()` par
  échec ⇒ une écriture SQL par échec ⇒ `postSave()` ⇒ `creerCommandesInfo()` + `creerCommandesAction()` à
  chaque échec, **dans `plugin::cron` partagé par tous les plugins**. `CLAUDE.md` l'énonce déjà : *« aucune
  [des mémoires de cache] ne vit en configuration d'équipement »*.
- **(c2) Réutiliser la mémoire de sonde `smartclim::lan_appareil::<mac>`** (y ajouter un champ `echecs`).
  Coût : elle est **clé par MAC**, et les écrivains ne s'accordent pas sur la MAC utilisée —
  `sonderAdresseLan()` écrit sous `$macAttendue`, `rafraichirLan()` sous `$resultatSonde['mac']`, qui peut
  être la **MAC inversée**. Un compteur y serait incrémenté sous une clé et remis à zéro sous une autre.
  De plus les 5 écrivains font un `cache::set()` de **remplacement intégral** d'un tableau de forme figée :
  tout écrivain oubliant le nouveau champ le perdrait en silence.
  ⚠️ **Leçon retenue et appliquée** : le nouveau compteur est **clé par `getId()` d'équipement**, comme
  `smartclim::ordres::<id>` et `smartclim::ordre_recent::<id>` — **jamais par MAC**.

### 2.2 La 6ᵉ mémoire de cache

`CLAUDE.md` en documente **cinq**. Cette UC en ajoute **une, et une seule** — le volet cloud est logé dans
la même entrée, il n'y a **pas de septième**.

| Clé | TTL | Contenu | Écrite par | Lue par |
|---|---|---|---|---|
| `smartclim::echecs_transport::<id>` (`CLE_CACHE_ECHECS_TRANSPORT`, `DUREE_MEMOIRE_ECHECS_TRANSPORT` = 86400) | 24 h | `{preuve:int, lan:int, cloud:int, cloud_le:int}` — **par équipement**, **non chiffrée** (aucun secret : 3 entiers, 2 horodatages) | `smartclim` seule, aux 16 points du § 5 | `smartclimTransport::lanJoignable()` / `repliCloudActif()` via accesseurs, `smartclim::attenteCloudRestante()`, `etatConnexionAffichable()` |

TTL aligné sur `DUREE_MEMOIRE_LAN` (24 h) **délibérément** : les deux décrivent ce qu'on sait du lien LAN
de cet appareil. À l'expiration, `preuve = 0` ⇒ `lanJoignable()` faux ⇒ cloud, sans oscillation.

⚠️ **`smartclim::dernier_incident` (5ᵉ mémoire) sort ENTIÈREMENT du périmètre de cette UC** — ni écrite,
ni **lue**. Motif au § 4.2 (révision M1). Son invariant *« seul le cycle automatique l'écrit ; toute
connexion réussie l'efface »* est intact.

⚠️ **Les caches #5 (sonde LAN) et #6 (échecs) sont délibérément DISJOINTS.** Aucun code ne lit l'un pour
écrire l'autre, et il n'existe **aucun point de co-écriture**. C'est une décision, pas un oubli : voir la
réfutation détaillée au § 12.1 (révision M4) et la dette D-2.

### 2.3 Fichiers

| Fichier | État | Ce qui y entre | Indentation |
|---|---|---|---|
| `core/class/smartclimTransport.class.php` | modifié | 3 constantes de **politique** ; `lanJoignable()` réécrite ; 2 méthodes pures nouvelles ; docblock de classe amendé. **Toujours aucun `cache::`, aucun `config::`, aucun socket, aucune écriture** | 2 espaces, CRLF |
| `core/class/smartclim.class.php` | modifié | 2 constantes de stockage ; la 6ᵉ mémoire et ses accesseurs ; le prédicat `echecOperationLan()` ; l'aiguillage `noterOperationLan()` ; 16 points d'appel ; garde de temporisation dans `executerCommandeAction()` ; `oublierEchecsCloud()` dans `rafraichirAuxHome()` ; correctif `sonderAdresseLan()` ; nouvelle branche d'échec dans `rafraichirLan()` ; clé `'repli'` sur 8 points d'écriture | 2 espaces, CRLF |
| `desktop/php/smartclim.php` | modifié | **1 seul ajout** : un `<small>` vide dans la ligne « Mode de transport » du bloc « État de connexion ». Aucun `echo` de donnée externe → aucun `htmlspecialchars()` requis **dans cet ajout** | ⚠️ **tabulations**, CRLF |
| `desktop/js/smartclim.js` | modifié | 2 lignes : reset et rendu du span de repli | 2 espaces, CRLF |
| `core/php/commande-lan.php` | modifié | 3ᵉ usage **lecture seule** `--transport` (§ 9) | respecter l'existant |
| **`core/php/smartclim.inc.php`** | **NON touché** | ⚠️ **Aucune classe nouvelle** — donc aucune ligne de `require_once`. Conséquence directe de l'arbitrage § 2.1 : le compteur est un état de `smartclim`, la politique une constante de `smartclimTransport` ; les deux classes existent et sont déjà chargées | — |
| `core/config/smartclim.config.ini` | **NON touché** | Seuil et délais sont des **constantes**, pas des clés de config : une clé exigerait un champ de formulaire, un `preConfig_`, la duplication du défaut dans l'INI et la double barrière écriture/lecture, pour un réglage qu'aucun scénario utilisateur ne justifie |
| `plugin_info/configuration.txt` + `.php` | **NON touchés** | Rien à ajouter au formulaire de config **plugin** → **pas de `cp` de synchronisation** |
| `core/ajax/smartclim.ajax.php` | **NON touché** | Voir § 8 : AC7 ne nécessite **aucun** aller-retour AJAX nouveau |
| `plugin_info/install.php` | **NON touché** | **Aucune migration** : la mémoire est un cache, son absence vaut « zéro échec, aucune preuve » — le parc existant est nominal au premier tick, exactement comme `transport_mode` en UC01 |
| `plugin_info/packages.json`, `resources/`, démon | **NON touchés** | Aucune dépendance ; l'invariant « le démon est latéral » est préservé — aucune méthode de cette UC ne référence `smartclimDemon` |

---

## 3. Server vs Client

**Tout est serveur.** Le client (`desktop/js/smartclim.js`) ne fait que **rendre** une chaîne déjà
construite et déjà traduite côté PHP.

Motif, et il n'est pas cosmétique : le seuil, le compteur et la temporisation gouvernent le **routage
d'ordres réels vers un appareil**. Un calcul côté navigateur serait contournable et, surtout,
désynchronisé du cache qui fait foi. Le JS ne reçoit donc **jamais** le compteur brut ni le seuil : il
reçoit la clé `'repli'` d'`etatConnexionAffichable()`, qui est soit une **chaîne vide**, soit la phrase
finale.

⚠️ **La chaîne vide est obligatoire, jamais `null` ni une clé absente** — piège `.text(undefined)` déjà
rencontré en UC01 : le texte de l'équipement précédemment consulté resterait affiché.

---

## 4. Politique de transport — `smartclimTransport`

### 4.1 Constantes et signatures

```php
const SEUIL_ECHECS_LAN = 3;      // AC1 — aligné sur LAN_FAILURE_THRESHOLD
                                 // (.memory/analyse/smartclim-transport-broadlink-lan.md § 7)
const DELAI_BASE_CLOUD = 30;     // secondes
const DELAI_MAX_CLOUD  = 300;    // secondes — plafond de FRÉQUENCE, jamais un abandon

public static function lanJoignable(smartclim $_eqLogic)      // bool — RÉÉCRITE, toujours zéro réseau
public static function repliCloudActif(smartclim $_eqLogic)   // bool — NOUVELLE
public static function delaiTemporisationCloud($_echecs)      // int  — NOUVELLE, arithmétique pure
```

⚠️ Docblock de la classe à amender : la liste des accesseurs d'entrée gagne
`$eqLogic->echecsLanConsecutifs()`.

### 4.2 `lanJoignable()` — forme exacte, dans cet ordre

```
1. adresseLan()['ip'] === ''                              -> false   (inchangé UC01)
2. $echecs = $_eqLogic->echecsLanConsecutifs()
   $echecs >= SEUIL_ECHECS_LAN                            -> false   (AC1 : seuil atteint)
3. sondeLanEquipement()['statut'] === STATUT_ETAT_LU       -> true    (inchangé UC01)
4. return $echecs > 0                                                (série en cours)
```

⚠️⚠️ **L'étape 4 inverse l'intuition — elle renvoie VRAI quand il y a des échecs — et elle n'est sûre
QUE parce que `memoriserEchecLan()` refuse d'incrémenter tant que `preuve === 0`.** Sans ce garde-fou, un
appareil qui n'a **jamais** parlé Broadlink (cas réel : l'utilisateur saisit un `lan_ip` sur le
climatiseur de validation) verrait son compteur passer à 1 au premier échec de sonde, et l'étape 4 le
déclarerait **joignable** — routant vers le LAN un appareil qui n'a jamais répondu.

**Invariant à maintenir, et il est le cœur de cette UC** :

> `0 < lan < SEUIL_ECHECS_LAN` **implique** qu'une preuve `STATUT_ETAT_LU` a été constatée dans les 24 h.

Il est garanti **entièrement dans le cache #6** (`preuve > 0 ⟺ un STATUT_ETAT_LU a été constaté`), écrit
par la seule `memoriserSuccesLan()`, elle-même atteignable presque exclusivement via
`noterOperationLan()`. Il ne dépend **pas** du cache #5 — cf. § 12.1.

### 4.3 `repliCloudActif()`

```
mode() === MODE_AUTO
  ET echecsLanConsecutifs() >= SEUIL_ECHECS_LAN
  ET cloudDisponible($_eqLogic)
```

Deux consommateurs, et deux seulement : l'affichage (§ 8) et la garde de temporisation (§ 6.2).

⚠️ **Le terme `cloudDisponible()` est ce qui rend AC4 visible** : un équipement sans identifiant cloud
n'est **jamais** « en repli », il est **bloqué en LAN** — et l'IHM ne doit pas appeler cela un repli.

### 4.4 `delaiTemporisationCloud($_echecs)`

```
$_echecs <= 0  -> 0
sinon          -> min(DELAI_MAX_CLOUD, DELAI_BASE_CLOUD * pow(2, $_echecs - 1))
```

→ 30 · 60 · 120 · 240 · 300 · 300 … Fonction **pure**, retour `int`, ne lève jamais.

⚠️ **Le plafond porte sur la FRÉQUENCE, pas sur un nombre total d'essais** : un abandon définitif rendrait
l'équipement impilotable et contredirait l'objectif de l'UC. C'est l'interprétation actée d'AC5
(cf. § 12.2, m5).

---

## 5. Compteurs — `smartclim`

### 5.1 Constantes, prédicat et accesseurs

```php
const CLE_CACHE_ECHECS_TRANSPORT     = 'smartclim::echecs_transport::';   // 6ᵉ mémoire
const DUREE_MEMOIRE_ECHECS_TRANSPORT = 86400;                             // 24 h

private static function echecOperationLan($_statut)   // bool — VRAI dès que $_statut !== STATUT_ETAT_LU

public  function echecsLanConsecutifs()               // int — PUBLIQUE : consommée par smartclimTransport
private function memoireEchecsTransport()             // array{preuve,lan,cloud,cloud_le} — VALIDÉE, JAMAIS null
private function ecrireMemoireEchecsTransport(array $_memoire)  // void

private function noterOperationLan($_statut)          // void — AIGUILLAGE UNIQUE
private function memoriserSuccesLan()                 // void
private function memoriserEchecLan($_motif = '')      // void
private function memoriserEchecCloud()                // void
private function oublierEchecsCloud()                 // void
private function attenteCloudRestante()               // int
```

⚠️⚠️ **`echecOperationLan()` NE DOIT JAMAIS être confondue avec `statutEnEchec()`** (existante,
`smartclim.class.php:1108-1115`), et le docblock doit le dire. Les deux répondent à des questions
différentes :

| Méthode | Question posée | `STATUT_ETAT_ILLISIBLE` |
|---|---|---|
| `statutEnEchec()` (existante) | « la communication a-t-elle abouti ? » — sert à dater `echec_le` | compte comme un **succès** |
| `echecOperationLan()` (nouvelle) | « **le LAN est-il utilisable pour piloter cet appareil ?** » | compte comme un **échec** |

Une trame indécodable rend le LAN inutilisable tout autant qu'un timeout. **Ne jamais substituer l'une à
l'autre** : c'est précisément ce qui ferait remettre un compteur à zéro sur une lecture illisible.

### 5.2 Sémantique des méthodes d'écriture

- **`memoireEchecsTransport()`** suit la discipline d'`incidentMemorise()` : décodage JSON, validation de
  forme **champ par champ**, et **retour d'une structure à zéro** sur toute anomalie — jamais d'état forgé,
  **jamais `null`** (les appelants n'ont donc aucune branche à écrire).
- **`noterOperationLan($_statut)`** — **aiguillage unique** :
  `echecOperationLan($_statut) ? memoriserEchecLan($_statut) : memoriserSuccesLan()`.
  ⚠️ **C'est la pièce qui rend le seul scénario dangereux inatteignable** (§ 12.1) : aucun site de lecture
  n'appelle `memoriserSuccesLan()` directement.
- **`memoriserSuccesLan()`** — `preuve = time()`, `lan = 0`. Log `'info'` **seulement si** transition
  depuis `lan >= SEUIL_ECHECS_LAN` (retour au pilotage local).
- **`memoriserEchecLan($_motif = '')`** — **deux no-op**, tous deux essentiels :
  1. **si `preuve === 0`** → garde-fou de l'étape 4 de `lanJoignable()` ;
  2. **si `$_motif === STATUT_MAC_DIVERGENTE` ou `CONTEXTE_LAN_MAC_DIVERGENTE`** → frontière
     identité/joignabilité (§ 12.1, M2).
  Sinon `lan = min(lan + 1, 999)`. Log `'info'` **seulement au franchissement** du seuil.
- **`memoriserEchecCloud()`** — `cloud = min(cloud + 1, 999)`, `cloud_le = time()`.
- **`oublierEchecsCloud()`** — **no-op si `cloud === 0`** : évite une écriture par commande réussie et par
  équipement à chaque cycle cloud.
- **`attenteCloudRestante()`** — corps exact, **sans aucune consultation de `incidentMemorise()`** :
  ```
  $m = memoireEchecsTransport();
  si $m['cloud'] <= 0            -> return 0;
  return max(0, smartclimTransport::delaiTemporisationCloud($m['cloud']) - (time() - $m['cloud_le']));
  ```

⚠️ **Journalisation aux TRANSITIONS uniquement, jamais par opération** — même discipline que
`masquerCommandesModes()` (« une seule fois, à la transition ») : sinon un parc replié inonderait le
journal 4 fois par heure. Ce sont des **logs**, donc du français **sans `__()`**.

⚠️ **Pas de `echecsCloudConsecutifs()`, et c'est délibéré** (révision du 2026-09-08, review qualité) : la
rédaction initiale de ce paragraphe le déclarait par **symétrie** avec `echecsLanConsecutifs()`, mais il
n'aurait eu **aucun consommateur** — `attenteCloudRestante()` et `diagnosticTransport()` lisent déjà la
mémoire entière, donc passer par un accesseur y ajouterait une **seconde lecture de cache**. La symétrie
n'est ici qu'apparente : `echecsLanConsecutifs()` n'est publique que parce que `smartclimTransport` en
dépend, et le volet cloud n'a aucun lecteur externe. Ne pas le « rétablir par cohérence ».

⚠️ `memoriserSuccesLan()`, `memoriserEchecLan()` et `noterOperationLan()` sont **privées** et appelées
depuis des méthodes **statiques de la même classe** sur d'autres instances
(`$eqLogic->noterOperationLan(...)`) — ce que PHP autorise. **`echecsLanConsecutifs()` est la seule rendue
publique**, et uniquement parce que `smartclimTransport` en dépend.

### 5.3 Les 16 points d'appel — liste exhaustive à cocher

⚠️ **C'est la principale surface d'oubli de cette UC** : un `noterOperationLan()` manquant produit un
repli permanent, **invisible en recette** (le LAN n'est pas testable sur le matériel de l'utilisateur).

| Méthode | Sites | Détail |
|---|---|---|
| `envoyerOrdreLan()` (~4215) | **3** | succès après `appliquerOrdre()` → `memoriserSuccesLan()` · `catch (smartclimException)` → `memoriserEchecLan($e->getContexte())` (**no-op** si `CONTEXTE_LAN_MAC_DIVERGENTE`) · `catch (Throwable)` → `memoriserEchecLan('')` |
| `rafraichirLanEquipement()` (~4144) | **4** | les 2 `catch` → `memoriserEchecLan()` **avant** le `throw` · après le `try/catch` : `noterOperationLan($lecture['statut'])` ⚠️ **et non `!statutEnEchec()`** (2 issues) |
| `rafraichirLan()` (1023-1096) | **3** | (a) ~1059, **conditionné** : `if ($resultatSonde['statut'] === STATUT_INJOIGNABLE) memoriserEchecLan(STATUT_INJOIGNABLE);` · (b)/(c) ~1076 : `noterOperationLan($lecture['statut'])` **remplaçant le `if` sans `else`** (2 issues) · ⚠️ **RIEN dans le `catch (Throwable)`** |
| `scannerReseauLocal()` (~816, ~892) | **2** | `noterOperationLan($lecture['statut'])` **juste avant** `appliquerLectureLan()` — succès seulement par construction (§ 5.4) |
| `executerCommandeAction()`, branche cloud (~4077-4102) | **3** | les 2 `catch` → `memoriserEchecCloud()` (après le `cache::delete($cleDedup)` existant) · succès → `oublierEchecsCloud()` — ⚠️ **les 3 conditionnés à `repliCloudActif($this)`** |
| `rafraichirAuxHome()` (~2544) | **1** | `oublierEchecsCloud()` dans la boucle de distribution, **dans le `try` existant**, juste après `appliquerEtat()` |

⚠️ **Le `catch (Throwable)` par équipement de `rafraichirLan()` ne compte pas** : une erreur interne PHP ne
dit rien du LAN — même raisonnement que le `catch (Throwable)` global de `rafraichirAuxHome()`, qui
s'abstient délibérément de `basculerHorsLigne()`.

⚠️ **`rafraichirLan()` gagne une BRANCHE QUI N'EXISTE PAS aujourd'hui** : le `if` de la ligne ~1076 n'a
**aucun `else`**, donc le cas « lecture aboutie mais pas `STATUT_ETAT_LU` » n'a actuellement aucun
traitement. `noterOperationLan()` le couvre en remplaçant le test.

### 5.4 Le scan ne compte jamais une ABSENCE, mais compte une lecture illisible

⚠️ **Formulation corrigée le 2026-09-08, sur remontée de l'agent d'implémentation.** La rédaction
initiale de ce paragraphe disait « le scan n'incrémente jamais », ce qui **contredisait la table du
§ 5.3** : `noterOperationLan()` aiguille vers `memoriserEchecLan()` dès que le statut n'est pas
`STATUT_ETAT_LU`, donc les deux appels du scan **peuvent** incrémenter. L'erreur était dans la spec, pas
dans le code. Règle réelle, en deux propositions qui ne se recouvrent pas :

- **Le scan ne compte JAMAIS une absence de réponse.** C'est porté par les **sites** : les deux appels
  sont posés **après une lecture aboutie**, jamais sur l'issue de découverte ou de sonde. Un appareil qui
  ne répond pas au scan ne fait donc monter aucun compteur.
- **Une lecture aboutie mais illisible compte comme un échec, y compris dans le scan.** C'est porté par
  la **méthode** (`noterOperationLan()` → `echecOperationLan()`), et c'est cohérent avec la règle générale
  du § 5.5 : l'appareil a répondu, mais **le LAN n'est pas utilisable pour le piloter** — exactement le
  même fait que dans le cycle de 15 min. Le compter est donc juste, et sans danger (le no-op
  `preuve === 0` protège l'appareil jamais prouvé).

Motif de la première proposition : la phase 1 du scan est pilotée par **diffusion**, où « absent »
signifie « diffusion filtrée » aussi souvent que « en panne » — une absence n'y est pas une preuve
d'indisponibilité. Même discipline que `dernier_incident`, dont `CLAUDE.md` dit qu'un scan en échec **ne
l'écrit pas**. Le cycle de 15 min assure l'incrément sur les absences, 4 fois par heure.

⚠️⚠️ **NE JAMAIS poser l'appel DANS `appliquerLectureLan()`** (`smartclim.class.php:931-943`) : elle est
appelée **inconditionnellement** après `lireEtat()` aux lignes ~816 et ~892, **quel que soit le statut**,
et son docblock la réserve au **profil de capacités**. C'est le défaut exact de la dette D-2 d'UC01 — une
méthode partagée entre deux appelants aux contrats différents finit par trancher pour l'un contre l'autre.
Emplacement exact :

- **Phase 1**, ~816, dans le `else` où `$rapprochesLan[$id]` vient d'être posé, **juste avant**
  `self::appliquerLectureLan($eqLogicRapproche, $lecture);` ;
- **Phase 2**, ~892, même forme sur `$eqLogic`, **juste avant** `self::appliquerLectureLan($eqLogic, $lecture);`.

### 5.5 Portée du compteur : écritures **et** lectures, confondues

Écriture et lecture empruntent **le même chemin physique** (`sonderAppareilLan()` / `sonderAdresseLan()`
→ hello unicast → session `0x65` → échange `0x6A`). Un appareil qui ne répond pas à une lecture ne
répondra pas à une écriture : ce sont deux mesures du **même fait**. Conséquences voulues :

- **AC8 tient sans action de l'utilisateur** : le cycle de 15 min atteint le seuil en **45 min** au pire.
  Si le compteur ne comptait que les écritures, AC1 exigerait que l'utilisateur appuie **trois fois** sur
  un bouton en échec avant que quoi que ce soit ne se produise.
- **AC8 tient aussi avec action** : trois commandes en échec suffisent, sans attendre le cycle. Le premier
  des deux qui arrive gagne.

**Règle unique, sans discrimination du TYPE d'exception** : *toute opération LAN échouée incrémente, toute
opération LAN réussie remet à zéro, rien d'autre ne touche le compteur.* Écarté : ne compter que
`TYPE_RESEAU` — la propriété recherchée n'est pas « le lien répond » mais « **le LAN est utilisable pour
cet appareil** », et exclure un `STATUT_ETAT_ILLISIBLE` produirait un équipement **bloqué en LAN pour
toujours** (le seuil ne serait jamais atteint).

⚠️ **La seule discrimination est par MOTIF, jamais par type** : `MAC_DIVERGENTE` ne compte pas (§ 12.1,
M2). Elle applique la frontière identité/joignabilité aux deux chemins (statut de sonde côté cycle,
contexte d'exception côté commande) et ne rouvre pas la règle ci-dessus.

---

## 6. Chemin de commande — `executerCommandeAction()`

**Deux insertions, pas une restructuration.** L'ordre des étapes d'UC01 est conservé.

### 6.1 Ordre des étapes (rappel, inchangé)

1. `CMD_RAFRAICHIR` traité et **retourné** ;
2. `$transport = smartclimTransport::transportRetenu($this);`
3. *(nouveau)* garde de temporisation — § 6.2 ;
4. `ordreDeCommandeAction()` puis marqueur de déduplication ;
5. branche LAN ou branche cloud.

⚠️ **`CMD_RAFRAICHIR` n'est JAMAIS temporisé** : il sort à l'étape 1, **avant** le calcul du transport.
C'est l'**échappatoire manuelle** de l'utilisateur, et son succès désarme la temporisation par le § 6.3.

### 6.2 Garde de temporisation (étape 3)

Placée **avant** `ordreDeCommandeAction()`, donc **avant le marqueur de déduplication** — ce qui évite
d'avoir à le nettoyer :

```
si $transport === TRANSPORT_AUX_HOME ET smartclimTransport::repliCloudActif($this) :
    $attente = $this->attenteCloudRestante();
    si $attente > 0 -> throw smartclimException(
        sprintf(__('Cloud indisponible, nouvelle tentative possible dans %d s', __FILE__), $attente),
        smartclimException::TYPE_RESEAU);
```

⚠️ **Commentaire obligatoire au point de `throw`** : `TYPE_RESEAU` est un **choix par défaut**, faute d'un
type « refus local » parmi les quatre existants, et **aucun paquet n'a été émis**. Un futur lecteur de
`getType() === TYPE_RESEAU` ne doit pas en déduire qu'un appel réseau a eu lieu.

### 6.3 Le disjoncteur à demi-ouverture — pourquoi rien ne peut le coincer

La temporisation est une **fenêtre**, pas un verrou. Passé `delai` secondes, **exactement une tentative
passe** : succès → `oublierEchecsCloud()`, échec → `cloud++` et fenêtre plus longue. La pénalité maximale
au retour du cloud est donc **`DELAI_MAX_CLOUD` = 300 s, structurellement**, sans qu'aucun cycle n'ait à
tourner.

Quatre désarmements, **aucun ne dépend d'un marqueur de fraîcheur non bornée** :

1. **La demi-ouverture elle-même** — mécanisme primaire, tient même à `refresh_interval` = 1440 min ;
2. **`oublierEchecsCloud()` dans `rafraichirAuxHome()`** — un `appliquerEtat()` réussi prouve que le cloud
   répond **pour cet appareil** : c'est un fait **daté et propre à l'équipement**. Au défaut de 5 min, ce
   désarmement précède toujours la fenêtre de 300 s ;
3. **« Rafraîchir »**, jamais temporisé, exécute le même cycle → même désarmement, immédiat et à la main
   de l'utilisateur ;
4. **Le retour du LAN** rend `repliCloudActif()` faux : la garde n'est plus consultée du tout.

### 6.4 AC3 est vrai par construction, et doit le rester

`executerCommandeAction()` sélectionne **un** transport et **ne retombe jamais** sur l'autre pour la même
opération. **Aucune ligne n'est ajoutée pour AC3.**

⚠️⚠️ **Piège futur nommé** : ne **jamais** ajouter un `catch` de la branche LAN qui rejouerait sur le
cloud. L'appareil **bipe à chaque ordre reçu** (`.memory/analyse/smartclim-architecture-jeedom.md` § 7) —
l'utilisateur entendrait deux bips et l'état deviendrait incohérent. **La bascule est INTER-opérations,
jamais intra-opération.**

---

## 7. Correctif du cycle LAN — `sonderAdresseLan()`

### 7.1 Le défaut d'UC01, et pourquoi il est bloquant ici

`sonderAdresseLan()` écrit aujourd'hui `'ip' => ''` dans la mémoire de sonde sur l'issue
`STATUT_INJOIGNABLE` (`smartclim.class.php` ~964-994). Chaîne de conséquences vérifiée sur le code :
`adresseLan()` retombe sur `source => 'aucun'` → `rafraichirLan()` fait `continue` (~1048-1050) →
**l'équipement sort définitivement du cycle dès son premier échec**. Le cycle de sonde d'UC01
**s'auto-désarme** ; seul un **scan manuel** (diffusion) le réarme. Donc :

- **AC6 serait vide** : plus aucune opération LAN n'est jamais tentée, le LAN ne « redevient » jamais
  joignable ;
- **AC1 serait faux dans l'autre sens** : la bascule se produirait au **1ᵉʳ** échec (adresse perdue ⇒
  `lanJoignable()` faux), pas au 3ᵉ.

⚠️ **Portée exacte du défaut, précisée par la revue** : `adresseLan()` (~2971-2979) renvoie la `lan_ip`
**personnalisée de façon inconditionnelle**, avant toute consultation de la sonde. Le défaut ne concerne
donc que `source === 'detecte'` ; pour `source === 'manuel'` le correctif est un **no-op bénin**. À dire
en recette : un utilisateur ayant saisi une IP n'a jamais eu le défaut, un utilisateur en découverte
automatique l'avait toujours.

### 7.2 Correctif retenu

Signature **inchangée**. Sur la branche `$trouve === null` **et statut `STATUT_INJOIGNABLE` uniquement** :
lire l'entrée précédente **via `$_eqLogic->sondeLanEquipement()`** — et **non** `sondeLanMemorisee($macAttendue)`,
car c'est l'accesseur qui connaît la règle « essayer aussi `lan_mac` et les MAC inversées » — puis
reporter `ip`, `port`, `type_appareil`, `nom`, `verrouille`, `vu_le` dans le `memoriserSondeLan()`
d'échec. **Seuls `statut` et `echec_le` sont neufs.**

⚠️ **`STATUT_MAC_DIVERGENTE` continue d'effacer l'IP — strictement inchangé.** Là, l'adresse héberge
démontrablement un **autre appareil** : insister serait marteler la machine d'un tiers.

### 7.3 Retour au LAN (AC6) — aucun cadencement nouveau

`rafraichirLan()` est gardé par `sondeLanAutorisee()` (mode ≠ CLOUD) et par `adresseLan()['ip'] !== ''` —
**jamais par `lanJoignable()`**. Un équipement replié continue donc d'être sondé toutes les 15 minutes,
gratuitement. Au premier `STATUT_ETAT_LU`, `noterOperationLan()` → `memoriserSuccesLan()` remet le
compteur à zéro et repose la preuve → `lanJoignable()` redevient vrai → **l'opération suivante repart en
LAN**. Aucune sonde dédiée, aucune nouvelle cadence, aucune 7ᵉ mémoire.

---

## 8. IHM — AC7, sans aucun AJAX nouveau

**Aucun aller-retour AJAX n'est ajouté**, et c'est vérifié sur le code :
`desktop/php/smartclim.php` calcule déjà `smartclim::etatsConnexionAffichables($eqLogics)` au rendu de la
page et le pousse par `sendVarToJS('smartclimEtatsConnexion', …)` ; les actions de scan renvoient déjà
`'etatsConnexion'`, fusionné côté JS par `$.extend(smartclimEtatsConnexion, resultat.etatsConnexion)`.
**La nouvelle clé y transite gratuitement.**

| Fichier | Modification |
|---|---|
| `core/class/smartclim.class.php` | clé `'repli'` ajoutée à `etatConnexionAffichable()` — ⚠️ **8 points d'écriture** : les **7 branches de retour** + le repli `catch (Throwable)` d'`etatsConnexionAffichables()`. Contenu : `sprintf(__('repli cloud actif — %d échec(s) LAN consécutif(s)', __FILE__), $n)` si `repliCloudActif()`, **chaîne vide** sinon |
| `desktop/php/smartclim.php` | ⚠️ **tabulations + CRLF** — un `<small class="text-muted" id="span_etatConnexionRepli"></small>` dans la ligne « Mode de transport » existante du bloc « État de connexion ». **Aucun `echo` de donnée externe → aucun `htmlspecialchars()` dans cet ajout** |
| `desktop/js/smartclim.js` | 2 lignes : reset (`$("#span_etatConnexionRepli").text("")` dans la branche `!isset(etat)`) et rendu (`etat.repli ? "(" + etat.repli + ")" : ""`) — même patron que `fraicheur` |

Rendu attendu : `Mode de transport : Automatique (repli cloud actif — 3 échec(s) LAN consécutif(s))`, à
comparer à un simple `Cloud` pour un mode choisi à la main. **C'est AC7.**

⚠️ **Un champ omis parmi les 8 reproduit le piège `.text(undefined)`** : le texte de l'équipement
précédemment consulté resterait affiché.

---

## 9. Instrument de constat — `core/php/commande-lan.php --transport`

**4ᵉ CLI du plugin, 3ᵉ usage de ce fichier.** Même moule que les autres : garde
`php_sapi_name() === 'cli'` **déjà en place avant tout `require_once`**, aucun POST, aucune écriture (base,
cache ou disque), sorties FR **sans `__()`**.

Affiche, par équipement : nom, mode configuré, transport retenu, LAN joignable oui/non, échecs LAN
consécutifs, âge de la dernière preuve, repli actif oui/non, temporisation cloud restante.

⚠️ **Ajout au § 5.1, acté le 2026-09-08** : les accesseurs de la 6ᵉ mémoire étant `private`, la CLI passe
par **`smartclim::diagnosticTransport()`** — **publique**, qui encapsule l'accès et rend des valeurs déjà
prêtes à l'affichage. C'est le **patron déjà en place** pour les autres CLI du plugin
(`lireTrameAuxHome()`, `sonderIntentAuxHome()`), et il est préférable à l'ouverture des accesseurs
individuels : la surface publique reste **une** méthode de lecture, au lieu de six.

⚠️ **Aucun paquet réseau émis** : c'est un **rapport de lecture d'état interne**, pas une sonde. Il est le
**seul** moyen de constater le mécanisme sur une installation sans matériel Broadlink (§ 0.1), et le seul
moyen de détecter la dette D-2.

⚠️ À lancer sous `www-data` (`sudo -u www-data php core/php/commande-lan.php --transport`) : les caches
sont écrits par le processus Apache et par le cron.

---

## 10. Validation & erreurs

| Quoi | Où | Comportement / message |
|---|---|---|
| Entrée de cache absente, corrompue, de forme non conforme | `memoireEchecsTransport()` | Structure à zéro (`preuve = 0`) ⇒ « appareil jamais prouvé » ⇒ comportement identique à UC01. **Aucun état forgé, jamais `null`** |
| Incrément sur un appareil jamais prouvé | `memoriserEchecLan()` | **No-op silencieux** — garde-fou de l'étape 4 de `lanJoignable()` |
| Incrément sur un motif de MAC divergente | `memoriserEchecLan()` | **No-op silencieux** — frontière identité/joignabilité (→ UC03) |
| Croissance non bornée | `min($n + 1, 999)` | Plafond de sûreté ; le seuil reste `>= 3`, l'affichage reste informatif |
| Échec LAN sous le seuil | branche LAN de `executerCommandeAction()` | **Message LAN existant**, curaté par `messageErreurLan()`. ⚠️ **Aucun message ne parle du cloud** — c'est le mécanisme d'AC3, inchangé depuis UC01 |
| Commande cloud pendant une temporisation | garde § 6.2 | `smartclimException(TYPE_RESEAU)`, message *« Cloud indisponible, nouvelle tentative possible dans %d s »*. **Aucun paquet émis, réponse immédiate** |
| « Rafraîchir » pendant une temporisation | ordre des étapes § 6.1 | **Jamais temporisé** — échappatoire manuelle |
| Cycle LAN sur un équipement replié | `rafraichirLan()` | `debug`/`warning` uniquement, **jamais `error`**, jamais d'exception, jamais d'`online = false`. Inchangé |
| Erreur interne PHP dans la boucle du cycle LAN | `catch (Throwable)` par équipement | Compté dans `$resultat['erreurs']`, **pas** dans le compteur d'échecs |

⚠️ **Aucun type d'exception nouveau** : `smartclimException` conserve ses 4 types et ses contextes
existants. Aucun `catch` supprimé ni élargi ; le `catch (Throwable)` reste en **dernier bloc** partout.

⚠️ **Message d'exception en secondes brutes, PAS `dureeHumaine()`** : cette dernière rend un temps
**écoulé** (« il y a … »), faux au futur.

---

## 11. Server Actions / API & Dépendances

**Aucune.** Cette UC n'ouvre aucun endpoint, aucun socket, aucune trame, et ne modifie **ni**
`smartclimAuxHomeApi`, **ni** `smartclimBroadlinkLan`, **ni** `smartclimFrame`, **ni**
`smartclimCapabilities`. Aucun paquet système ou pip n'est introduit (`packages.json` non touché).

Faits externes mobilisés, tous déjà établis :

- **Seuil de 3, bascule au seuil, remise à zéro au premier succès** —
  `.memory/analyse/smartclim-transport-broadlink-lan.md` § 7 (référence `fparrav`,
  `LAN_FAILURE_THRESHOLD = 3`). Repris tel quel.
- **Backoff `500 ms · 2^n` plafonné à 3 s** — même source, § 7. ⚠️ **Écarté** (Q1) : cette politique
  appartient à un *daemon Homebridge* pilotant un transport local sub-seconde ; la transposer à un REST
  cloud dont le budget global est déjà de 18 s ajouterait des requêtes HTTP et du sommeil **sur un chemin
  où l'utilisateur attend** (`core/ajax/cmd.ajax.php`), allongeant exactement ce que le brief § 15
  interdit.
- **Aucun backoff côté AUX Home aujourd'hui** — dette assumée **D-MVP08-05**. Cette UC **ne la lève pas**
  et **ne touche pas `appliquerOrdre()`**.
- **Ce qui tient déjà la clause 1 d'AC5**, et qu'il ne faut pas doubler : `BUDGET_COMMANDE` = 18 s
  **wall-clock global, login compris** ; `RESERVE_ORDRE` = 4 s ; `TIMEOUT_REQUETE` = 10 s ;
  `TIMEOUT_CONNEXION` = 5 s ; rejeu re-login **borné à 1** gardé par `BUDGET_REJEU_ORDRE` = 10 s ;
  marqueur `dernier_cycle` posé **avant** l'appel réseau.
- **`TIMEOUT_ECHANGE` = 2 s** — coût unitaire d'un hello sur un appareil injoignable, dimensionnant pour
  R5.

⚠️ **Hypothèse héritée, non levée ici** : tout le chemin LAN reste vérifié par **lecture** contre
`mjg59/python-broadlink`, jamais contre du matériel.

---

## 12. Traçabilité des révisions du plan

### 12.1 Les 4 `major` de la revue croisée et leur arbitrage

**M1 — `attenteCloudRestante()` couplée à `incidentMemorise()` : CONCÉDÉ, et durci.**
La revue a montré le trou : `dernier_incident` est écrite **uniquement par le cycle automatique**, à la
cadence `refresh_interval` (jusqu'à 1440 min). Si le cloud tombe alors que le dernier cron a réussi, un
équipement en repli enchaîne des commandes en échec avec `attenteCloudRestante() === 0` à chaque fois — la
clause 2 d'AC5 est morte pendant toute la fenêtre.

Mais la **combinaison** proposée (temporisation depuis `cloud_le` **plus** désarmement si
`incidentMemorise() === null`) souffre du **même défaut, à l'identique** : `null` ne signifie pas « le
cloud répond maintenant », mais « le dernier cycle, **qui peut dater de 24 h**, avait réussi ». Un fait
dont la **fraîcheur n'est pas bornée** ne peut servir **ni à armer ni à désarmer** :

- comme terme d'**armement** → un `null` périmé empêche la temporisation de s'armer (le trou de M1) ;
- comme terme de **désarmement** → un `null` périmé désarme une temporisation légitime.

**Décision : le retirer, pas le déplacer.** `attenteCloudRestante()` ne consulte plus `incidentMemorise()`,
et la 5ᵉ mémoire sort entièrement du périmètre d'UC02. Ce qui la remplace est au § 6.3 — quatre
désarmements dont aucun ne dépend d'un cron. **Le risque R7 du plan initial n'existe plus** ; il est
remplacé par un risque plus petit : jusqu'à 300 s de refus après retour du cloud si aucun cycle cloud
n'intervient (impossible au défaut de 5 min).

**M2 — Décompte des points d'appel faux, prédicat unique adopté, `MAC_DIVERGENTE` non compté.**
Le décompte initial (« `rafraichirLan()` ×4 ») était **faux** : il n'y a que **3** branches d'issue par
équipement, et il en **manquait une dans le code actuel** (le `if` ~1076 n'a aucun `else`). R10 est
corrigé à **16 points** (§ 5.3).
Le prédicat `echecOperationLan()` est adopté, et sa nécessité est plus grande que la revue ne le disait :
`rafraichirLanEquipement()` teste `statutEnEchec()`, qui **whiteliste `STATUT_ETAT_ILLISIBLE`** — une
lecture illisible n'y lève donc pas, atteint `appliquerEtat()`, et un `memoriserSuccesLan()` posé après le
`try/catch` remettrait le compteur à zéro. **Second site du même piège.**
**`STATUT_MAC_DIVERGENTE` n'est pas compté**, pour trois motifs dont un est une preuve :
1. **Nature** : le lien a répondu — c'est l'**identité** qui diverge, pas la joignabilité, et la spec
   fonctionnelle renvoie explicitement le changement d'IP à l'UC03 ;
2. **Il serait inopérant par construction** : `sonderAdresseLan()` efface l'IP sur cette issue
   (comportement conservé, § 7.2), donc l'équipement sort du cycle — un compteur qui ne peut jamais
   atteindre son seuil est du **code mort trompeur** ;
3. **C'est prouvablement sans danger**, y compris quand l'IP survit (cas `lan_ip` saisie) :
   `lanJoignable()` évalue terme 1 = vrai, terme 3 = faux (`statut === MAC_DIVERGENTE`), terme 4 =
   `echecs > 0` = **faux** → renvoie **faux**. En AUTO l'équipement part au cloud sans compteur ; en LOCAL
   il échoue sur le message de MAC divergente, qui est le diagnostic actionnable. **Aucun blocage
   possible.**

**M3 — Garde de statut dans le scan : CONFIRMÉE**, et posée sur les **sites** plutôt que dans
`appliquerLectureLan()` — motif et emplacements exacts au § 5.4.

**M4 — Point d'écriture unique des deux caches : REFUSÉ, prémisse réfutée.**
L'invariant `0 < lan < SEUIL ⇒ preuve` ne dépend **pas** du cache #5 : il s'énonce entièrement dans le
cache #6. Les quatre désynchronisations possibles :

| Scénario | Effet sur `lanJoignable()` | Gravité |
|---|---|---|
| #6 expire, #5 dit `ETAT_LU` | terme 3 vrai → vrai (correct) ; l'échec suivant est un no-op → bascule au **1ᵉʳ** échec au lieu du 3ᵉ | dégradé, **jamais dangereux** |
| #5 expire, #6 dit `lan = 1` | terme 1 faux → faux | **sûr** |
| Futur écrivain de #5 sans #6 | comme ligne 1 | dégradé, **jamais dangereux** |
| **Futur écrivain de #6 sans #5** (`memoriserSuccesLan()` sur une issue non prouvée) | `preuve` posée → un échec porte `lan = 1` → **terme 4 vrai sur un appareil jamais prouvé** | ⚠️ **seul danger réel** |

Le point d'écriture unique aurait protégé les lignes 1 et 3 (**inoffensives**) et **pas du tout** la ligne
4. Il n'est de plus **pas réalisable** sans dénaturer les sites : les 5 appels à `memoriserSondeLan()`
portent **trois MAC différentes** (`$macAttendue`, `$macTrouvee`, `$resultatSonde['mac']` — potentiellement
inversée) et **l'un d'eux n'a aucun équipement en main** (~770, phase 1 : le rapprochement est
postérieur). Une signature à `$eqLogic` nullable ne garantirait rien. C'est mot pour mot l'argument opposé
à l'option (c2) au § 2.1.
**Ce qui est fait à la place** : déplacer les gardes **dans les méthodes** (`noterOperationLan()`,
§ 5.2), ce qui rend le **scénario 4 inatteignable** — aucun site de lecture n'appelle
`memoriserSuccesLan()` directement. Le seul appel direct restant est le succès d'`envoyerOrdreLan()`, où
une écriture appliquée **est** la preuve, par construction.

### 12.2 Les `minor` acceptés

- **m1** — Portée du correctif limitée à `source === 'detecte'` : § 7.1.
- **m2** — `MAC_DIVERGENTE` éjecte l'équipement du cycle jusqu'au prochain scan manuel → **dette D-1**.
- **m3** — Commentaire au point de `throw` sur le choix de `TYPE_RESEAU` : § 6.2.
- **m4** — Race non atomique sur le cache #6 → **dette D-3**.
- **m5** — « borné » s'entend au sens **espacement croissant plafonné à 300 s**, pas au sens d'un nombre
  maximal de tentatives : § 4.4. Un abandon définitif rendrait l'équipement impilotable.
- **Code mort sur le matériel testé** — formulé comme statut au § 0.1, à la manière de R1 d'UC01.

---

## 13. Risques

- **R1 — L'UC entière est non recettable sur le matériel de l'utilisateur.** Voir § 0.1. Ce qui doit être
  vérifié en recette est **l'absence de régression** du chemin cloud. Statut hérité de R1 d'UC01.
- **R2 — Le correctif § 7.2 modifie un comportement livré en UC01.** Effet de bord **visible** : la ligne
  « Réseau local » de l'IHM affiche désormais `192.168.x.y (il y a 12 min)` au lieu d'être vide.
  Information meilleure, mais **changement d'affichage à annoncer en recette**. Ne concerne que
  `source === 'detecte'`.
- **R3 — AC3 est vrai par construction et doit le rester** : § 6.4.
- **R4 — Une preuve fausse fait router une commande réelle, et la dette D-1 d'UC01 s'aggrave d'un cran.**
  `preuve` dérive de `STATUT_ETAT_LU`, donc de `smartclimFrame::conceptsLisibles()`, qui **ne teste que
  des longueurs** (≥ 13 octets) et **jamais** le magic `bb00`. Un faux positif ouvrait jusqu'ici la voie à
  un équipement de trop ; il ouvre désormais une **fenêtre de tolérance de 3 échecs** pendant laquelle des
  commandes réelles partent vers un appareil non prouvé. Périmètre d'attaque inchangé (LAN local,
  usurpation IP/MAC). Le durcissement (`estTrameHvac()` bloquant sur le **routage**, jamais sur le
  décodage) reste en dette et exige du matériel Broadlink.
- **R5 — Coût du cycle LAN en hausse, dans un cron partagé.** Avant le correctif § 7.2, un équipement en
  échec sortait du cycle et celui-ci devenait gratuit. Désormais il reste sondé : `TIMEOUT_ECHANGE` = 2 s
  par appareil injoignable, arrêt dur à `BUDGET_LAN` = 18 s, toutes les 900 s → **2 % de rapport cyclique
  au pire** dans `plugin::cron`. Acceptable, et c'est le prix d'AC6 ; régression de coût à connaître sur un
  parc à 9 appareils LAN morts.
- **R6 — Un LAN qui oscille produit un va-et-vient de transport** au rythme de 15 min. La spec interdit
  « d'osciller nerveusement » ; 15 min n'est pas nerveux et le seuil de 3 amortit. **Aucun hystérésis
  supplémentaire** n'est ajouté : il faudrait un second seuil de retour, non demandé, et il retarderait
  AC6. À rouvrir si une recette matérielle montre du battement.
- **R7** *(remplacé — voir § 12.1, M1)* — **Jusqu'à 300 s de refus après le retour du cloud** si aucun
  cycle cloud n'intervient. Impossible au défaut `refresh_interval` = 5 min.
- **R8 — Une adresse IP périmée est désormais martelée indéfiniment** (une trame UDP toutes les 15 min) au
  lieu que le plugin devienne silencieux. **Contrainte transmise à l'UC03**, qui possède le changement
  d'IP DHCP et devra ajouter une re-découverte par diffusion avant de déclarer un appareil perdu. **Ne pas
  préempter ici.**
- **R9 — R2 d'UC01 est refermé sans changer « Transport actif ».** UC01 renvoyait à cette UC la question
  de faire porter à « Transport actif » la sémantique « transport retenu » plutôt que « dernière opération
  réussie ». **Décision : ne rien changer.** L'annotation de repli sur la ligne « Mode de transport » lève
  l'ambiguïté sans contredire le libellé de l'AC6 d'UC01, et sans toucher `appliquerEtat()`, seul chemin
  réellement recetté.
- **R10 — Les 16 points d'appel sont la principale surface d'oubli** : § 5.3. Un `noterOperationLan()`
  manquant produit un repli permanent, **invisible en recette**.
- **R11 — Les 8 points d'écriture de la clé `'repli'`** : § 8. Un champ omis reproduit le piège
  `.text(undefined)`.
- **R12 — `CLAUDE.md` passe de cinq à six mémoires de cache.** Le tableau de la section « Architecture »
  doit gagner sa ligne, sans quoi la prochaine session raisonnera sur un inventaire faux.

---

## 14. Impact i18n (français, langue source)

⚠️ Chaînes **littérales** dans `__()`, **jamais** `__($variable)` — l'extraction est un scan statique.

**2 clés nouvelles, toutes deux dans `core/class/smartclim.class.php`** :

| Clé française | Emplacement |
|---|---|
| `'repli cloud actif — %d échec(s) LAN consécutif(s)'` | clé `'repli'` d'`etatConnexionAffichable()`, `sprintf()` à **un seul argument** |
| `'Cloud indisponible, nouvelle tentative possible dans %d s'` | message d'exception de la garde § 6.2 |

Aucune clé dans `desktop/php/smartclim.php` (le label `{{Mode de transport}}` existe déjà, l'ajout est un
`<small>` **vide**), `desktop/js/smartclim.js`, `core/class/smartclimTransport.class.php` ni
`core/php/commande-lan.php` (CLI : sorties FR **sans `__()`**, comme les 3 autres).

**Écart assumé avec la liste indicative de la spec fonctionnelle** (« Repli cloud actif », « Échecs LAN
consécutifs : N », « Retour au pilotage local », « Cloud indisponible, nouvelle tentative dans … ») : les
deux premières sont **fusionnées** en une phrase (une seule clé, un seul span, un seul point d'écriture) et
la troisième devient un **log de transition**, donc du français **hors `__()`** — les logs de ce plugin ne
sont pas traduits. La spec autorise explicitement cet arbitrage (« Libellé exact … laissé à la spec
technique / i18n »).

⚠️ **Aucune méta-séquence littérale** dans les nouveaux docblocks et commentaires (`*/` collé au texte,
balise fermante PHP, double accolade ouvrante dans un fichier rendu). Lancer
`python .claude/scripts/verif-plugin.py` (colonne `meta=`) avant commit.

---

## 15. Dette (non traitée dans ce cycle)

- **D-1 — `STATUT_MAC_DIVERGENTE` éjecte définitivement l'équipement du cycle LAN** (`source = 'detecte'`)
  jusqu'au prochain scan manuel, sans lien avec le compteur d'échecs. Cohérent avec le hors-périmètre
  IP/UC03, mais **consigné plutôt que laissé implicite**. Renvoi explicite à l'**UC03** : re-découverte
  par diffusion avant de déclarer un appareil perdu.
- **D-2 — Désynchronisation résiduelle des caches #5 et #6** (scénarios 1 et 3 du tableau § 12.1) :
  dégrade la tolérance de 3 échecs à 1, **jamais la sûreté**. **Détectable** par
  `core/php/commande-lan.php --transport` (`preuve = 0` alors que le statut LAN est `ETAT_LU`).
- **D-3 — Race non atomique sur le cache #6** entre `plugin::cron` et un ordre interactif sur le même
  équipement : `cache::byKey()`/`cache::set()` ne sont pas atomiques. Conséquence bornée à **un incrément
  perdu**, même classe de risque que `dernier_incident`. Signalé, non traité.
- **D-4 — Aucun backoff cloud en cas d'échecs répétés hors repli** : dette **D-MVP08-05** inchangée.
  Cette UC n'ajoute de temporisation **qu'en repli**, et ne touche pas `appliquerOrdre()`.
- **D-5 — Pas d'hystérésis au retour du LAN** (R6) : à rouvrir si une recette matérielle montre du
  battement.
