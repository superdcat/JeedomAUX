# Spec technique — UC04 post-MVP/01 « Fusion d'un même climatiseur découvert en LAN et dans le cloud »

> **Spec fonctionnelle** : `.memory/specs/post-mvp/01-transport-broadlink-lan/04-fusion-lan-cloud.md`
> **Dépend de** : UC01 (découverte broadcast et session), UC02 (lecture d'état LAN), UC03 (écriture LAN) de
> ce même domaine, et de l'UC03 du MVP (scan cloud, création d'équipement).
> **Écrite le** : 2026-09-03. Plan produit par `jeedom-tech-planner`, challengé par `code-reviewer` en
> mode advisor, validé par l'utilisateur le 2026-09-03 (3 corrections de l'advisor intégrées, cf. § 9.0).

## 0. Ce que fait cette UC, en une phrase

Elle fait en sorte qu'un climatiseur joignable par les **deux** voies — diffusion Broadlink LAN et compte
AUX Home — n'existe qu'en **un seul `eqLogic`** porteur simultanément de ses identifiants de transport, et
elle affiche au scan **une ligne par climatiseur** disant LAN oui/non, cloud oui/non, transport actif.

⚠️ **Elle ne DÉCIDE PAS quel transport utiliser** — c'est le domaine `post-mvp/02`. « Transport actif » à
l'affichage est une **observation** (la valeur déjà portée par la commande info `transport`, poussée par le
dernier chemin qui a écrit un état), **jamais** un choix nouveau. Aucun aiguillage de pilotage n'est
introduit : `executerCommandeAction()` reste **cloud**, le pilotage local reste la CLI
`core/php/commande-lan.php` (cf. § 8.2 et R4).

## 1. Architecture

### 1.1 Fichiers

| Fichier | État | Ce qui y entre | Indentation / EOL |
|---|---|---|---|
| `core/class/smartclim.class.php` | modifié | tout le rapprochement, la création depuis le LAN, la consolidation d'affichage, la correction d'`appareilsDisparus()` | **2 espaces**, CRLF |
| `core/class/smartclimFrame.class.php` | modifié | `estTrameHvac()` — prédicat pur, aucune E/S | **2 espaces**, CRLF |
| `core/class/smartclimBroadlinkLan.class.php` | modifié | **un seul** `log::add(warning)` dans `lireEtat()` quand la trame lue n'est pas une trame HVAC | **2 espaces**, CRLF |
| `desktop/php/smartclim.php` | modifié | bloc de synthèse + table `#table_scanClimatiseurs` (5 en-têtes) | ⚠️ **TABULATIONS**, CRLF |
| `desktop/js/smartclim.js` | modifié | vidage du `tbody`, rendu de `resultat.climatiseurs` | **2 espaces**, CRLF |

### 1.2 Fichiers NON touchés — et ce n'est pas un oubli

- **`core/php/smartclim.inc.php`** — **aucune classe nouvelle** n'est introduite (tout vit dans
  `smartclim::`, seul endroit autorisé à toucher un `eqLogic` ; `estTrameHvac()` s'ajoute à une classe
  **déjà** dans la liste des `require_once`). Rien à ajouter. C'est le seul cas de figure où le piège
  d'autoload de `CLAUDE.md` est **sans objet** ici. **Vérifié** par l'advisor contre le fichier réel.
- **`core/ajax/smartclim.ajax.php`** — l'action `scannerClimatiseurs` existe déjà et le retour de
  `smartclim::scannerClimatiseurs()` n'est enrichi que de clés **additives**. Zéro ligne.
- **`core/config/smartclim.config.ini`**, `plugin_info/packages.json`, `plugin_info/info.json` — aucune clé
  de config **plugin**, aucune dépendance : le piège « défaut INI qui court-circuite `preConfig_` » est
  sans objet.
- **`plugin_info/configuration.txt` / `.php`** — le formulaire de config **plugin** ne change pas (les
  identifiants LAN sont **par équipement**, posés depuis UC01) : **pas de `cp` de resynchronisation**.
- **`core/i18n/*.json`** — traduction en fin de cycle par le sous-agent `translator`.
- **`equipementsParIdentifiant()` et `rafraichirAuxHome()`** — le cycle cron (UC07 du MVP) est **cloud
  pur** et n'indexe que par `auxhome_device_id`. Un équipement créé par le LAN n'y entre donc jamais, et
  n'est **pas** basculé `online = false` par un cycle auquel il n'appartient pas. C'est le comportement
  **voulu** ; il changera au domaine 02. `cron()` et `rafraichirMaintenant()` restent inchangés.

## 2. Contrats externes

**AUCUN.** Cette UC n'émet **aucune requête nouvelle** : elle consomme les lignes normalisées déjà
produites par `smartclimBroadlinkLan::decouvrir()` / `interroger()` / `lireEtat()` et par
`smartclimAuxHomeApi::listerAppareils()`.

Deux faits de contrat **déjà tranchés** qu'elle exploite, cités pour que personne ne les rouvre :

### 2.1 Ordre des octets de la MAC

`mjg59/python-broadlink` (MIT) : `scan()` fait `mac = resp[0x3A:0x40][::-1]`, `send_packet()` fait
`packet[0x2A:0x30] = self.mac[::-1]`. La ligne normalisée du transport porte donc déjà `mac` en **ordre
imprimable** (directement comparable au cloud) et `octets_mac` en ordre d'en-tête — cf.
`01-decouverte-lan-et-session-tech.md` § 1.2 et `.memory/analyse/smartclim-transport-broadlink-lan.md` § 6
(`fparrav` est l'exception : il relit l'écho de son propre paquet `0x65`).

⚠️ **Conséquence à garder en tête** : en pratique LAN et cloud annoncent le **même** ordre. Le test de MAC
inversée est le **filet de sécurité exigé par AC5**, pas le chemin nominal. Ne pas le supprimer pour
autant : la clause « ordre inversé » du § 3 de l'analyse est vérifiée sur une des deux références.

### 2.2 Magic de la charge HVAC

Les trames de contrôle commencent par `bb00` (`.memory/analyse/smartclim-transport-broadlink-lan.md`
§§ 5.1 / 5.4). ⚠️ Utilisé **UNIQUEMENT** comme signal de **journalisation** — **jamais** comme critère
bloquant (motif au § 5.6, risque au § 9 R3).

## 3. Server vs Client

**Tout côté serveur**, sans exception, et le navigateur n'envoie **aucun paramètre** :

- le rapprochement, la création, l'enrichissement et la consolidation vivent dans `smartclim::`, appelés
  par l'action AJAX **admin** `scannerClimatiseurs` **existante** ;
- le JS ne fait que **rendre** une liste de lignes déjà construite et déjà traduite côté PHP (mêmes
  libellés `Oui`/`Non` et même nom de transport que la fiche d'équipement) ;
- aucun `logicalId`, aucune MAC, aucune adresse ne remonte du client vers le serveur — c'est la même
  garantie que celle qui rend la sonde de diagnostic exposable au web (le catalogue est une **donnée
  serveur**).

## 4. Validation & erreurs

### 4.1 Entrées externes

| Quoi | Où | Comportement |
|---|---|---|
| MAC issue du LAN, avant tout `logicalId` | `normaliserMac()` (12 hex minuscules) — déjà appliqué côté transport, **revalidation défensive** | vide ⇒ **aucune création**, aucun rapprochement par MAC |
| Nom issu du LAN | `nettoyerNomExterne()` (transport, ≤ 63 car.) puis `cleanComponanteName()` dans `creerEquipement()` | vide après filtrage ⇒ `nomAppareilParDefaut($mac)` ; collision ⇒ `nomUnique()`, borné à 50 essais |
| Trame de contrôle sans préfixe `bb00` | `smartclimBroadlinkLan::lireEtat()` | log `warning` + 4 premiers octets, **non bloquant** |
| Appareil Broadlink non climatiseur | statut `STATUT_ETAT_ILLISIBLE` | **aucune création** ; l'appareil reste visible dans la table LAN de détail |
| Deux appareils LAN pointant sur un même équipement | ensemble `$rapprochesLan` | log `warning`, ligne `ignore_doublon`, **aucun** état appliqué |
| Échec de `save()` pendant une création LAN | `try/catch (Throwable)` **par appareil**, déjà en place | log `error`, boucle **poursuivie** |
| Échec de la consolidation d'affichage | `try/catch (Throwable)` dans `lignesFusionScan()` | tableau vide — la synthèse est vide, les deux tables de détail **restent** |

⚠️ **Aucune entrée utilisateur nouvelle** : la double barrière `lan_ip` / `lan_mac` (aide JS non
autoritaire + `preSave()` autoritaire et **silencieux**) est **inchangée**, `saveEqLogic()` n'est pas
touchée.

### 4.2 Typage et curation des exceptions

**Aucune exception nouvelle.** `scannerReseauLocal()` **ne lève toujours jamais** (tout échec devient un
statut + un log), `scannerAuxHome()` lève toujours un message **déjà curaté en français** par
`messageErreurAuxHome()`, `messageErreurLan()` n'est pas sollicitée (aucun chemin interactif nouveau).

### 4.3 Secrets

La table de synthèse ne porte que : nom, MAC, deux libellés `Oui`/`Non`, un nom de transport. **Ni** clé de
session, **ni** identifiant cloud nouveau dans le DOM. L'`equipementId` ajouté aux lignes de scan est un id
d'`eqLogic`, déjà présent partout dans le DOM de cette page **admin**.

## 5. Signatures

Tout dans `core/class/smartclim.class.php` sauf mention contraire.

### 5.1 `indexerEquipements()` — modifiée

Ajoute **deux** index au retour existant :

- `parMac` — `configuration.mac` normalisée par `normaliserMac()` ;
- `parLanMac` — `configuration.lan_mac` normalisée, **non vide seulement**.

Retour : `array{parLogicalId, parMac, parLanMac, parDeviceId, tous, noms}`. **Premier arrivé gagne** sur les
deux nouveaux index, avec un log `debug` en cas de collision (c'est un doublon **réel** du parc, pas une
anomalie de code).

⚠️ **`preSave()` écrit TOUJOURS `lan_mac`, fût-ce une chaîne vide** — filtrer sur « non vide » avant
d'indexer, sinon tous les équipements sans adresse locale collisionnent sur la clé `''`.

### 5.2 `chercherEquipementExistant($_macNorm, $_deviceId, array $_index, $_transport = '')` — modifiée

Quatrième paramètre **optionnel**, défaut `''` : les appels cloud existants restent **littéralement
identiques**. Ordre de recherche, **premier trouvé gagne** :

| # | Clé | Actif pour |
|---|---|---|
| 1 | `parLogicalId['mac:' . $mac]` | tous |
| 2 | `parMac[$mac]` | tous |
| 3 | `parLanMac[$mac]` | **seulement si `$_transport === smartclimCapabilities::TRANSPORT_BROADLINK_LAN`** |
| 4 | `parLogicalId['mac:' . macInversee($mac)]` | tous (log `warning`) |
| 5 | `parMac[macInversee($mac)]` | tous (log `warning`) |
| 6 | `parLanMac[macInversee($mac)]` | LAN seulement (log `warning`) |
| 7 | `parDeviceId[$deviceId]` | cloud (le LAN passe `''`) |

⚠️ **Cet ordre est celui corrigé par l'advisor, pas celui du plan initial.** Le plan plaçait
`parLanMac[$mac]` — une correspondance **directe**, adossée à une **déclaration explicite de
l'utilisateur**, donc la preuve la plus forte de tout le tableau — **après** les correspondances
**inversées**. C'était en contradiction avec son propre principe, et une correspondance faible pouvait
court-circuiter une forte. Corrigé le 2026-09-03.

Trois décisions à ne pas relitiger :

- **Tous les ordres directs (1-3) avant tous les ordres inversés (4-6).** Un rapprochement direct est
  toujours une meilleure preuve qu'un rapprochement inversé ; cet ordre borne la collision théorique
  « MAC directe de A = MAC inversée de B ».
- ⚠️ **Garde palindrome** : **sauter** les étapes 4, 5, 6 quand `macInversee($mac) === $mac` — sinon le
  `warning` « rapproché via la MAC inversée » se déclencherait à tort sur une MAC symétrique, alors que
  c'est le **même** équipement que l'étape 1.
- ⚠️ **`lan_mac` ne rapproche que pour SON transport** (étapes 3 et 6 gardées par `$_transport`).
  `lan_mac` est une déclaration utilisateur **pour le LAN** ; s'en servir pour rapprocher un appareil
  **cloud** est une erreur de catégorie qui, sur une simple faute de frappe, attacherait un appareil cloud
  neuf à l'équipement d'un autre.
- ⚠️ Conserver la garde « MAC non vide » : ne **jamais** construire un `logicalId` `mac:` à suffixe vide.
  `macInversee('')` rend déjà `''`.

**Non-régression du chemin cloud — vérifiée, pas supposée** : pour tout le parc **déjà créé**,
`configuration.mac` et le suffixe du `logicalId` sont **toujours identiques** (posés ensemble par
`creerEquipement()`, jamais réécrits séparément). Les étapes 2 et 5 sont donc, pour ce parc, **redondantes**
avec 1 et 4, et les étapes 3/6 inactives (le cloud passe `$_transport = ''`). Le seul chemin recetté du
plugin ne change **strictement pas** de comportement. Les étapes `parMac` deviennent réellement porteuses
pour le cas **nouveau** « `auxhome:<id>` enrichi d'une MAC après coup » (§ 5.4).

### 5.3 `creerEquipement($_logicalId, $_nomBrut, $_macNorm, array &$_noms, array $_capacites, array $_configuration = array())` — modifiée (rendue neutre de transport)

Remplace le paramètre `array $_appareil` par `$_nomBrut` (alias **déjà assaini par le transport**) et
`$_configuration` (clés supplémentaires à poser). **Corps inchangé pour tout le reste** : repli
`nomAppareilParDefaut()` si `cleanComponanteName()` vide l'alias, `setName(nomUnique(...))`,
`setIsEnable(1)`, `setIsVisible(1)`, `setCategory('heating', 1)`, `configuration.mac` si MAC non vide,
`appliquerCapacites()` **avant** l'unique `save()`.

Appel cloud :
`self::creerEquipement($logicalId, $appareil['nom'], $macNorm, $index['noms'], $capacites, array('auxhome_device_id' => $identifiant, 'modele' => $appareil['modele']))`

Appel LAN :
`self::creerEquipement('mac:' . $mac, $appareil['nom'], $mac, $index['noms'], smartclimBroadlinkLan::capacitesAppareil($lecture), array())`

*Arbitrage* : une méthode **neutre** plutôt qu'un `creerEquipementLan()` jumeau — dupliquer 20 lignes
**dans la même classe** n'a pas la justification qui vaut pour `normaliserIpV4()` (indépendance entre
classes de transport). Le risque sur le chemin cloud est mécanique et relit en une passe.

⚠️ **`setObject_id()` reste jamais appelé** (AC4 d'UC03 du MVP : l'utilisateur place lui-même l'équipement
dans une pièce, le plugin ne le fait jamais). `setName()` **uniquement ici**.

### 5.4 `memoriserMacEquipement(smartclim $_eqLogic, $_macNorm)` — créée

→ `bool` : `true` si l'objet a été **modifié**. Pose `configuration.mac` **si et seulement si** elle est
actuellement **vide** ET la MAC fournie non vide. **N'écrase jamais** une MAC existante. **N'émet aucun
`save()`** — l'appelant décide.

Motifs de la règle « seulement si vide », dans l'ordre d'importance :

1. elle rend l'écriture **idempotente** (AC4) ;
2. elle empêche un cloud qui annoncerait la MAC dans l'autre ordre de **désynchroniser** `configuration.mac`
   du suffixe du `logicalId` et de provoquer un `save()` à **chaque** scan ;
3. **c'est LA migration du parc** — un équipement `auxhome:<deviceId>` acquiert sa MAC au premier scan qui
   la connaît, **sans script de migration**.

Appelée depuis : `scannerAuxHome()` (branche « équipement existant », **intégrée à la chaîne `$modifie`
existante** — aucun `save()` supplémentaire) et `scannerReseauLocal()` phases 1 et 2 (`save()` propre si
`true`).

### 5.5 `scannerReseauLocal()` — modifiée

Structure de boucle, budgets (`BUDGET_LAN`, arrêt dur **avant** chaque appareil dans les deux phases) et
`try/catch` **par appareil** : **inchangés**. Ajouts :

- deux compteurs : `crees`, `ignores` ;
- un ensemble local **`$rapprochesLan`** (ids d'`eqLogic` déjà rapprochés **dans ce scan LAN**) : si un
  second appareil découvert pointe vers le même équipement → log `warning`, statut de ligne
  `ignore_doublon`, **aucun** `appliquerLectureLan()`. C'est ce qui empêche deux appareils d'écrire l'un
  sur l'autre via une collision de MAC inversée ;
- **phase 1**, dans cet ordre : `chercherEquipementExistant($mac, '', $index, TRANSPORT_BROADLINK_LAN)` →
  si `null` **ET** `$lecture['statut'] === smartclimBroadlinkLan::STATUT_ETAT_LU` → `creerEquipement()`,
  `$compteurs['crees']++`, puis **insertion dans `$index['parLogicalId']`, `$index['parMac']` et
  `$index['noms']`**.
  ⚠️ Sans cette insertion, un second appareil de MAC inversée créerait un doublon **dans le même scan**.
  ⚠️ **Ne PAS ajouter à `$index['tous']`** : cette liste ne sert qu'au balayage des adresses saisies
  (phase 2), et l'appareil est déjà dans `$rencontres`.
- **phases 1 ET 2** : `memoriserMacEquipement()` + `save()` conditionné, **avant** `appliquerLectureLan()` ;
- **phases 1 ET 2** : `ligneResultatLan()` poussée **après** le rapprochement, pour porter `equipementId`.
  ⚠️ **L'appel en phase 2 est une correction de l'advisor** : aujourd'hui la phase 2 (sonde des `lan_ip` /
  `lan_mac` **saisis** — précisément le cas VLAN) **ne pousse aucune ligne** dans `$appareils`. Sans cet
  ajout, un équipement joint **uniquement** par cette voie afficherait « Disponible en LAN : **Non** » dans
  la synthèse d'AC3, alors que sa **propre fiche** affiche « LAN disponible » — incohérence visible, et
  non-conformité à AC3 sur le cas d'usage même que cette UC met en avant.
- `resumeScanLan()` mentionne les créations.

⚠️ **Le critère de création est `STATUT_ETAT_LU`, et c'est un critère de PREUVE, pas de joignabilité.** Un
appareil Broadlink qui n'est pas un climatiseur (prise, télécommande IR) **authentifie très bien** (§ 3.4
de `02-lecture-etat-lan-tech.md`) mais ne rend pas de charge exploitable → `STATUT_ETAT_ILLISIBLE` → **aucun
équipement créé**. C'est **la seule chose irréversible** que fait cette UC.

⚠️⚠️ **Ce que ce garde-fou vérifie RÉELLEMENT — ne pas le surestimer** (finding `major` de l'advisor,
2026-09-03). `lireEtat()` fixe `STATUT_ETAT_LU` sur le seul
`!empty(smartclimFrame::conceptsLisibles($trameControle, $trameLongue))`, et `conceptsLisibles()` ne teste
que des **longueurs** contre les offsets de `champs()` — **jamais** le magic `bb00`. Le minimum réel n'est
donc **pas** 21 octets mais **13** (offset de `CONCEPT_TARGET_TEMP`), et **un seul** concept lisible suffit.
Formulation exacte du garde-fou : *« un appareil de la famille Broadlink a répondu à la commande `0x6A` avec
un code d'erreur nul et une charge déchiffrée d'au moins 13 octets »*. Aucun contenu n'est vérifié. Il
repose entièrement sur l'hypothèse **empirique et non recettée** qu'un appareil Broadlink non climatiseur
rejette `0x6A` avec le code `-4`.

### 5.6 `smartclimFrame::estTrameHvac($_trame)` — créée · `smartclimBroadlinkLan::lireEtat()` — modifiée

`estTrameHvac()` → `bool` : `is_string()` et préfixe `bb00`. **Prédicat pur**, aucune E/S — la classe reste
une **table de données**. Le magic reste ainsi confiné au décodeur, **jamais** dans `smartclim::`.

`lireEtat()` ajoute **un seul** `log::add('smartclim', 'warning', …)` quand la trame de contrôle est non
vide et que `estTrameHvac()` est faux, avec les **quatre premiers octets** en hexadécimal. **NON BLOQUANT** :
statut et retour **inchangés**.

⚠️ **Motif du non-bloquant, à ne pas « corriger »** — même règle que l'écho de MAC et la somme de charge en
UC02 : *un contrôle invérifiable sur un chemin non recettable est un déni de service auto-infligé*. Ici
l'enjeu est plus fort encore : rendre ce test bloquant sur un appareil réel qui n'aurait pas ce préfixe
rendrait UC04 **entièrement inopérante en silence**, sans aucun message. Ce log est **l'instrumentation de
recette du seul acte irréversible de l'UC** — c'est ce qui permettra de trancher R3 le jour où du matériel
Broadlink sera disponible.

### 5.7 `ligneResultatLan(..., $_equipementId = 0)` · `ligneResultatScan(..., $_equipementId = 0)` — modifiées

Une clé de plus dans la liste blanche : `equipementId` (int, `0` = aucun). Le JS existant **n'est pas
impacté** : `ajouterLigneScan()` reçoit une liste **explicite** de valeurs (vérifié dans
`desktop/js/smartclim.js`). Côté cloud : `equipementId` renseigné pour `cree`, `existant` **et**
`ignore_doublon` (l'objet est en main), `0` pour `ignore_identifiant` et `erreur`.

### 5.8 `lignesFusionScan(array $_lignesLan, array $_lignesCloud, array $_etatsConnexion)` — créée

→ `array<int, array{nom, mac, lan, cloud, transport}>`, **une ligne par `eqLogic`** référencé par au moins
une ligne de scan (`equipementId > 0`), **triée par nom**.

Fait **un** `eqLogic::byType('smartclim')` indexé par id, pour disposer du nom **post-scan** (créations
comprises) et de `macEquipement()`. *Arbitrage* : une requête SQL de plus sur une opération de ~40 s, contre
trois nouvelles clés de retour à faire remonter par les deux phases — la requête est le moindre coût.

- `lan` = `libelleDisponibilite()` de « il existe une ligne LAN pour cet id dont le statut n'est **pas** un
  échec » — **réutilise `statutEnEchec()`**, aucun nouveau classement de statut ;
- `cloud` = `libelleDisponibilite()` de « il existe une ligne cloud pour cet id de statut `cree` ou
  `existant` » ;
- `transport` = `$_etatsConnexion[$id]['transport']` (**déjà traduit**), repli `__('Inconnu', __FILE__)` —
  littéral **déjà présent** dans ce fichier, aucune clé nouvelle.

⚠️ **Ne lève JAMAIS** : `try/catch (Throwable)` global, retour tableau vide en dernier recours. Cette
méthode tourne **après** deux phases coûteuses (jusqu'à ~40 s) — elle ne doit pas pouvoir faire perdre leur
résultat.

### 5.9 `libelleDisponibilite($_disponible)` — créée

→ `__('Oui', __FILE__)` / `__('Non', __FILE__)`. **Seul** endroit du plugin où vivent ces deux `__()` (même
règle que `libelleStatutLan()` et `messageErreurAuxHome()`).

### 5.10 `scannerClimatiseurs()` — modifiée

Ajoute **une** clé au retour :
`'climatiseurs' => self::lignesFusionScan($lan['appareils'], $resultatCloud['appareils'], $etatsConnexionFusionnes)`,
calculée **après** les deux phases et sur la carte `etatsConnexion` **déjà fusionnée**
(`array_replace($lan, $cloud)` — le cloud passe **en dernier et gagne**, ce qui reflète l'ordre réel
d'exécution, donc le transport **réellement** en usage). Les 8 clés existantes sont **inchangées**.

### 5.11 `sondeLanEquipement()` — créée (instance) · `adresseLan()` et `etatConnexionAffichable()` — modifiées

`sondeLanEquipement()` → `array|null`. Cherche la mémoire de sonde LAN dans l'ordre : `lan_mac` **saisie**,
`macEquipement()`, puis leurs **inverses** (dédoublonnées, palindrome compris). **Unique** porteur de la
règle « essayer aussi l'ordre inversé » côté équipement — ce que le docblock de `sondeLanMemorisee()`
réclame déjà (« c'est aux appelants de le faire »), et qui est aujourd'hui **dupliqué** entre `adresseLan()`
et `etatConnexionAffichable()`.

Ces deux dernières remplacent leur bloc de lecture de sonde par cet appel. ⚠️ **Contrats de sortie
strictement inchangés**, dont les clés `lan` / `lanAdresse` présentes dans **CHAQUE** branche de retour — le
piège jQuery `.text(undefined)` reste d'actualité.

⚠️ **Ce n'est PAS une factorisation neutre, et l'advisor l'a explicitement qualifié** : c'est une
**extension fonctionnelle additive**. Aujourd'hui, **ni** `adresseLan()` **ni** `etatConnexionAffichable()`
ne cherche la sonde via `lan_mac` — seule `macEquipement()` est utilisée. Effet : un équipement dont
l'utilisateur n'a saisi que `lan_mac` voit **enfin** son IP détectée (AC1 en configuration VLAN). Régression
sur le chemin recetté : **nulle**, vérifiée — pour tout équipement sans `lan_mac` (100 % du parc actuel,
recette comprise), le nouveau code retombe **exactement** sur l'appel d'aujourd'hui.

### 5.12 `appareilsDisparus(array $_index, array $_consommes)` — modifiée

⚠️ **CORRECTION OBLIGATOIRE — sans elle, UC04 introduit une régression visible.** Le critère actuel est
`$issuAuxHome = (auxhome_device_id non vide) || (mac non vide)`. Un équipement créé par le **LAN** porte une
`mac` et **aucun** `auxhome_device_id` : il serait signalé « Introuvable au dernier scan » à **chaque** scan
cloud, **indéfiniment**.

Nouveau critère : **`auxhome_device_id` non vide, seul**. C'est aussi le critère sémantiquement juste —
« disparu du **compte** AUX Home » ne veut rien dire pour un appareil qui n'y a **jamais** été. Le cas de
bord perdu (un appareil cloud renvoyé sans `deviceId`) est dégénéré : il est de toute façon rapproché par sa
MAC à chaque scan, donc **jamais** candidat.

## 6. Idempotence (AC4) — les sept verrous

1. **Aucune création** si `chercherEquipementExistant()` rend un objet, et la recherche est désormais
   **exhaustive** (7 clés, deux ordres d'octets).
2. Un équipement créé par le LAN atterrit dans le **même espace `mac:`** que le cloud → rapproché par les
   deux voies au scan suivant, **sans conversion**.
3. **Insertion immédiate** du nouvel équipement dans l'index **en mémoire** → pas de doublon intra-scan.
4. `memoriserMacEquipement()` n'écrit que si `configuration.mac` est **vide** → **un seul** `save()` dans
   toute la vie de l'équipement.
5. `appliquerCapacites()` compare le profil fusionné (**hors `detecte_le`**) avant d'écrire et rend `false`
   si rien ne change → aucun `save()` sur un scan identique (invariant UC03 du MVP **préservé**).
6. `appliquerEtat()` passe par `checkAndUpdateCmd()` : l'état vit dans le cache du core, **aucun `save()`**
   d'équipement.
7. `$rapprochesLan` (LAN) et `$consommes` (cloud) empêchent deux appareils de revendiquer un même
   équipement dans un même scan. ⚠️ **Les deux ensembles restent DISJOINTS** : `$consommes` alimente
   `appareilsDisparus()` et ne doit **surtout pas** être pré-rempli par la phase LAN.

**Corollaire mesurable en recette** : à partir du 2ᵉ scan, `compteurs.crees == 0` côté cloud **et** côté
LAN, et le nombre total d'équipements est **strictement stable**.

## 7. Déclenchement du scan — inchangé

**Le même unique bouton « Scanner les climatiseurs »**, qui lance déjà depuis UC01 la phase LAN **puis** la
phase cloud dans un seul appel AJAX. **Aucun bouton nouveau, aucun paramètre envoyé par le navigateur.**

Trois raisons :

- la consolidation d'AC3 exige les deux sources dans **la même** passe — deux boutons produiraient une
  synthèse à moitié périmée ;
- l'ordre **LAN → cloud** est précisément ce qui rend **AC2 vrai par construction** :
  `scannerReseauLocal()` s'exécute **avant** `scannerAuxHome()`, qui **reconstruit son propre index
  après** — il voit donc les équipements créés par le LAN **à l'instant**. Deux déclencheurs indépendants
  laisseraient cet ordre à la main de l'utilisateur ;
- le LAN étant non recetté, sa phase **ne lève jamais** et se dégrade en table vide + résumé — elle ne peut
  **pas** empêcher le scan cloud d'aboutir (`cloudErreur` en `warning`, jamais `danger`).

Le `timeout: 60000` du JS couvre le pire cas **inchangé**.

## 8. Périmètre

### 8.1 Ce que l'utilisateur a arbitré le 2026-09-03

**La création automatique d'un `eqLogic` depuis la découverte LAN est AUTORISÉE**, conditionnée à la preuve
`STATUT_ETAT_LU`. Décision prise **en connaissance du risque réel** (§ 5.5, formulation corrigée : aucun
contrôle de contenu, 13 octets et code d'erreur nul), et **contre** les deux alternatives présentées :

- *magic `bb00` bloquant* — écarté : le préfixe n'a **jamais** été observé sur une réponse LAN réelle ; s'il
  diffère sur du matériel, UC04 deviendrait **inopérante en silence**. Il reste donc en **log** (§ 5.6).
- *rapprochement seul, aucune création* — écarté : **AC2 deviendrait vide de sens** (un climatiseur LAN-only
  resterait inutilisable tant qu'aucun compte cloud n'est configuré) et la question serait simplement
  reportée au domaine 02.

Motif retenu : symétrie avec le scan cloud, **qui crée déjà sans confirmation**, et conséquence d'un faux
positif **bornée et non destructive** — un équipement de trop, supprimable en un clic.

### 8.2 Ce qui reste explicitement hors périmètre

- **Le choix du transport** — domaine `post-mvp/02`. « Transport actif » est **observé**, jamais décidé.
- **Le pilotage local depuis l'interface** — `executerCommandeAction()` reste **cloud**. Un équipement créé
  par le LAN a des boutons qui échouent avec le message existant « Cet équipement n'est pas relié à un
  appareil AUX Home — relancez un scan » ; le pilotage local reste la CLI `core/php/commande-lan.php`
  (cf. R4).
- **Le renommage d'un `logicalId` existant** — jamais, décision définitive (cf. R7).
- **Tout rapprochement par nom** — jamais (cf. R5).

## 9. Risques

### 9.0 Les trois corrections de l'advisor, intégrées le 2026-09-03

L'advisor a vérifié **une à une** les prémisses du plan contre le code réel : **toutes exactes sauf une**.
Les trois findings `major` sont **intégrés dans cette spec**, pas laissés en dette :

1. **R1 surestimait le garde-fou de création** (magic HVAC vérifié, 21 octets) alors que le code ne teste
   qu'une longueur ≥ 13 octets, et se contredisait avec R3 dans le même document → **§ 5.5 réécrit** avec
   le mécanisme réel, **avant** que l'utilisateur ne tranche § 8.1.
2. **Ordre des étapes de rapprochement incohérent** avec son propre principe (`parLanMac` direct placé
   après les inversées) → **§ 5.2 renuméroté**.
3. **La phase 2 de `scannerReseauLocal()` ne pousse aucune ligne de scan**, ce qui rendait AC3 faux sur le
   cas VLAN → **appel `ligneResultatLan()` ajouté en phase 2, § 5.5**.

### 9.1 Risques résiduels

- **R1 — La création d'équipement depuis un transport non recetté est le seul acte irréversible de l'UC.**
  Garde-fou réel décrit au § 5.5. Une prise Broadlink devrait répondre `-4` « Command not supported » et ne
  pas franchir le filtre — **hypothèse empirique non vérifiée sur matériel**. Résidu borné : un équipement
  de trop, supprimable, **aucune action destructive**. Instrumenté par le `warning` de R3.
- **R2 — Changement de comportement volontaire d'`appareilsDisparus()`** (§ 5.12), sur le chemin **cloud**.
  À vérifier en recette : un équipement cloud **réellement retiré du compte** doit **toujours** apparaître
  dans « Climatiseurs introuvables sur le compte ».
- **R3 — `estTrameHvac()` repose sur une donnée non mesurée en LAN.** Le préfixe `bb00` est établi côté
  **cloud** (recetté) et par les magics de lecture ; **jamais observé** sur une réponse LAN réelle. D'où le
  choix non bloquant. **À confirmer contre le matériel réel** et à répercuter dans
  `.memory/analyse/smartclim-transport-broadlink-lan.md`.
- **R4 — Un équipement créé par le LAN a des boutons qui échouent.** Son profil porte `modes` et `vitesses`
  **vides** (le LAN ne peut **rien exclure**, faute d'équivalent de `feature.coolType`) : seules `on`,
  `off`, `set_target_temp` et `refresh` sont créées. Ce n'est **pas** une régression : c'est la frontière du
  domaine `post-mvp/02`, qui branchera l'aiguillage sur `envoyerOrdreLan()`.
- **R5 — Cas non résoluble, à documenter et non à contourner** : un équipement créé en `auxhome:<deviceId>`
  **sans aucune MAC** (le cloud n'en a pas renvoyé) et le même appareil découvert en LAN n'ont **rien** en
  commun. Le LAN créera un second équipement. Seul remède, **manuel** : saisir `lan_mac` sur l'équipement
  cloud (étape 3 du rapprochement) — c'est exactement l'usage prévu de ce champ. ⚠️ Ne **PAS** tenter de
  deviner : aucun rapprochement par nom — deux climatiseurs nommés « Salon » chez le même utilisateur sont
  la norme, pas l'exception.
- **R6 — `configuration.mac` peut désormais diverger du suffixe du `logicalId`** sur un équipement dont le
  `logicalId` est `auxhome:<id>`. **C'est voulu.** Tout lecteur doit passer par `macEquipement()` (qui
  privilégie déjà `configuration.mac`), **jamais** par un `substr()` du `logicalId`.
- **R7 — Le `logicalId` d'équipement n'est JAMAIS réécrit, et c'est définitif.** Trois raisons : (a) rien ne
  garantit son unicité au niveau SQL — un renommage vers un `mac:<x>` déjà pris rendrait
  `eqLogic::byLogicalId()` **non déterministe** ; (b) c'est l'identité exposée à l'API Jeedom et aux
  intégrations tierces ; (c) **il n'y a aucun besoin** — la fusion passe par `configuration.mac` et
  l'index. Les `logicalId` de **commande** ne sont **pas touchés du tout** : **aucun scénario utilisateur
  n'est impacté, aucun script de migration n'est nécessaire.**
- **R8 — AC5 et le chemin de création LAN ne sont pas recettables** : aucun matériel Broadlink chez
  l'utilisateur (cf. § 11).
- **R9 — Contrainte transmise au domaine 02** : le jour où le LAN publiera des modes/vitesses, l'exclusion
  sur preuve (`modes_exclus`) devra devenir **persistante dans le profil**, sinon l'union de
  `appliquerCapacites()` réintroduira « Chauffage » sur une unité froid-seul dès qu'un scan LAN tourne seul.
  **Rien à faire aujourd'hui** : le profil LAN reste vide sur ces deux clés.

## 10. Dépendances

**Aucune.** Pas de démon, pas de paquet, pas de dépendance système ou pip. PHP seul, `packages.json` reste
vide, `hasDependency` et `hasOwnDeamon` restent `false`.

## 11. Recette — ce qui est vérifiable, et ce qui ne l'est pas

### 11.1 Vérifiable sur l'installation de l'utilisateur (aucun matériel Broadlink)

- Scan cloud **inchangé** : créations, existants, **disparus** (⚠️ R2), profils de capacités, état de
  connexion.
- Table LAN de détail **vide**, résumé LAN cohérent, **aucune erreur** remontée à l'écran.
- Table de synthèse d'AC3 : une ligne par climatiseur cloud, « Disponible en LAN : **Non** », « Disponible
  dans le cloud : **Oui** », transport « AUX Home ».
- Champ « Réseau local » de la fiche d'équipement : toujours « Jamais détecté sur le réseau local ».
- **AC4** : scans répétés → nombre d'équipements **strictement stable**, `compteurs.crees == 0` dès le 2ᵉ.

### 11.2 Non recettable — à valider le jour où du matériel Broadlink existe

- **AC2** — création d'un équipement depuis un scan LAN seul, et son rapprochement par le scan cloud suivant.
- **AC5** — rapprochement par **MAC inversée**.
- **AC1 en configuration VLAN** — sonde par `lan_mac` saisie seule (§ 5.11).
- **R3** — préfixe `bb00` réellement présent sur une réponse LAN (le `warning` de § 5.6 est là pour ça).

## 12. Dette

Deux tours de review croisée ont été joués (plafond du workflow). Tour 1 : **1 finding `high`** (corrigé,
cf. § 12.1), 0 `blocker`, 0 `major`, 1 `minor`. Tour 2 sur le delta : **0 `critical`/`high`, 0
`blocker`/`major`** sur les deux axes. Ce qui suit n'atteint pas la gate et n'est **pas** corrigé dans ce
cycle.

### 12.1 Corrigé dans ce cycle — pour mémoire, parce que le sink est générique

Un finding `high` a été levé et **corrigé** : **XSS stocké**. Le nom d'appareil issu de la découverte LAN —
donc d'une **réponse de diffusion UDP non authentifiée et forgeable par n'importe quelle machine du réseau
local** — atteignait un `echo` **non échappé** de la carte d'équipement (`desktop/php/smartclim.php`, dans
le bloc « Mes smartclims »).

⚠️ **Ce sink vient du squelette `plugin-template` de Jeedom** : il est donc présent à l'identique dans
beaucoup de plugins. Il préexistait à cette UC (le chemin cloud l'alimentait déjà, mais depuis le compte de
l'utilisateur lui-même — au pire du self-XSS) ; **c'est UC04 qui a ouvert un vecteur d'entrée non
authentifié**, d'où le traitement dans ce cycle.

Deux couches ont été posées, et il faut savoir **pourquoi les deux** :

1. **L'échappement du sink** — `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`, aligné sur ce que faisait déjà
   la ligne voisine du badge d'état. **C'est la correction qui ferme le trou**, quelle que soit l'origine du
   nom (cloud, LAN, renommage manuel, future intégration tierce).
2. **Le retrait de `<` et `>` aux deux frontières d'assainissement** — `nettoyerNomExterne()` (LAN) et
   `nettoyerTexteExterne()` (cloud), en défense en profondeur.
   ⚠️ **Motif à retenir** : `cleanComponanteName()` du core supprime `& # ] [ % \ / ' " *` — **ni `<` ni
   `>`**. Un payload sans guillemet ni slash (`<img src=x onerror=…>`) traversait donc les deux couches de
   nettoyage existantes. Ne jamais supposer que `cleanComponanteName()` protège du HTML.
   ⚠️ Ce retrait doit rester **symétrique entre les deux transports** (même emplacement dans le pipeline :
   après la validation UTF-8, avant le `trim()`) — sinon un appareil vu par les deux voies porterait deux
   noms différents selon le chemin de découverte, ce qui contredirait l'objectif même d'UC04.

Vérifié en tour 2 : aucun autre `echo` de donnée d'origine externe ne reste non échappé dans la page, et un
`identifiant` cloud vidé par le nouveau filtrage est **ignoré proprement** par la garde préexistante
(`$macNorm === '' && $identifiant === ''`) au lieu de produire un `logicalId` corrompu.

### 12.2 Dette assumée

- **D-PMVP0104-01** — La création LAN repose sur un garde-fou qui ne vérifie **aucun contenu** (§ 5.5). Le
  durcissement (magic `bb00` bloquant) est **délibérément différé** jusqu'à ce que le préfixe soit observé
  sur du matériel réel (§ 8.1, R3).
- **D-PMVP0104-02** — `scannerReseauLocal()` atteint ~213 lignes sur deux phases imbriquées (finding
  `minor`, tour 1). Extraction recommandée **à l'occasion** de deux helpers privés : le corps « par
  appareil » de la phase 1 (rapprochement + création conditionnée + gestion de `$rapprochesLan` + ligne de
  résultat) et le corps « par équipement » de la phase 2 (sonde + vérification de MAC + lecture + ligne).
  ⚠️ **Pas de refonte plus large** — et surtout pas dans une UC qui touche par ailleurs au chemin cloud
  recetté.
