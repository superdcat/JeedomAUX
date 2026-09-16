# Spec technique — UC01 du domaine post-mvp/07 « Tolérance aux modèles et générations inconnus »

> Spec fonctionnelle : `01-tolerance-generations-inconnues.md` (même dossier).
> Cycle `/feature` du 2026-09-16. Plan produit par `jeedom-tech-planner`, challengé par `code-reviewer`
> en mode advisor, quatre arbitrages tranchés par l'utilisateur (§ 1).

## 0. Nature de cette UC — à lire avant le reste

**La moitié de cette UC est déjà livrée par les domaines 01 à 06.** Le principe directeur du brief
(« le critère de prise en charge est le protocole joignable, jamais une liste de références
commerciales ») a été appliqué à chaque UC de découverte ; cette UC ne le *crée* pas, elle **ferme les
trois trous qui restent** et **écrit la preuve** que la promesse est tenue.

Audit du code livré, critère par critère — chaque ligne a été tracée jusqu'à sa source, puis
**re-vérifiée indépendamment** par l'advisor (qui a confirmé les références au caractère près) :

| AC | Verdict | Preuve dans le code existant |
|---|---|---|
| **AC1** | **déjà tenu**, les 3 transports | ci-dessous § 0.1 |
| **AC2** | **trou réel sur le LAN** | § 0.2 → travail § 3.1 |
| **AC3** | **déjà tenu**, 4 mécanismes | § 0.3 |
| **AC4** | **non tenu**, et interdit par un docblock | § 0.4 → travail § 3.3 |
| **AC5** | **non tenu**, aucune table candidate | § 0.5 → travail § 3.2 |
| **AC6** | recette, partiellement exécutable | § 7 |

⚠️ **Corollaire de méthode pour l'implémentation** : sur AC1 et AC3, **il n'y a rien à écrire**.
Une « amélioration » spontanée sur ces chemins serait une régression du périmètre, pas un bonus.

### 0.1 AC1 — un appareil au modèle inconnu n'est jamais écarté : déjà vrai

- **AUX Home** — `modelId` n'est **jamais** un critère de décision : `normaliserAppareil()`
  (`smartclimAuxHomeApi.class.php:793-794`) le nettoie en simple champ d'affichage `modele`. Le seul
  rejet de `scannerAuxHome()` est `ignore_identifiant` (`smartclim.class.php:929-933`), conditionné à
  **MAC vide ET deviceId vide**.
- **Legacy** — `produitsClimatiseurConnus()` (`smartclimAuxCloudApi.class.php:1866-1871`) **ne gouverne
  plus la création** depuis le correctif de reviews d'UC02 du domaine 03 ; son docblock l'écrit. Un
  `productId` inconnu ne produit qu'une **ligne `log info`** (970-978). La création tient à la preuve
  **fonctionnelle** `estClimatiseur()` (`pwr` + (`temp` | `ac_mode`), 1882-1884), exigée seulement si
  `chercherEquipementExistant()` n'a rien rapproché (`smartclim.class.php:1160-1164`).
- **LAN** — `categorieLigneLan()` (`smartclim.class.php:2161-2169`) trie sur `STATUT_ETAT_ILLISIBLE`,
  **jamais sur `type_appareil`/devtype**, avec un commentaire qui l'interdit. La création LAN
  (1428-1438) tient à `STATUT_ETAT_LU` — une preuve de **trame**, pas d'identité.
- « sans interrompre le scan des autres » — `try/catch` **par appareil** sur les trois scanners
  (1009-1023, 1212-1220, 1465-1467), plus trois `try` indépendants dans `scannerClimatiseurs()`
  (747-824).
- « avec les informations qu'on a pu en tirer » — `lignesFusionScan()` (3328-3335) fait déjà tomber la
  colonne *Modèle* sur `modelId` → `type_produit` → `typeAppareil`/devtype.

**Réserve honnête** : il n'existe **aucune notion de « marque »** dans le plugin. La spec dit « marque
*si connue* » — non bloquant, et rien n'est ajouté pour cela (ce serait un catalogue, explicitement hors
périmètre).

### 0.2 AC2 — le socle pilotable : trou réel, et il est exactement sur le cas cible

Chaîne : `capacitesAppareil()` du transport → `appliquerCapacites()` (5563-5633, **union**) →
`definitionsCommandesAction()` (5816-6035).

- `on` / `off` / `set_target_temp` sont conditionnées aux **concepts** (5824-5851) : présents dès que la
  trame est assez longue. ✔ pour les trois transports.
- `mode_*` et `fan_*` sont conditionnées à `$profil['modes']` / `$profil['vitesses']` (5854-5894). Or :
  - AUX Home publie son catalogue (`smartclimAuxHomeApi.class.php:1053-1066`) ✔
  - Legacy publie son catalogue + le drapeau `catalogue_par_defaut`
    (`smartclimAuxCloudApi.class.php:2025-2030`) ✔
  - **LAN publie `'modes' => array(), 'vitesses' => array()` EN DUR**
    (`smartclimBroadlinkLan.class.php:467-468`) ✘

⚠️ **Conséquence, tracée jusqu'au bout** : un équipement créé par la **seule diffusion LAN** (chemin
livré à l'UC04 du domaine 01, `smartclim.class.php:1429`) n'obtient **aucune commande de mode ni de
vitesse** — seulement `on`, `off`, `set_target_temp`, `refresh`. Il porte pourtant la commande **info**
`mode` (créée depuis les concepts) : **l'utilisateur voit le mode courant sans pouvoir le changer.**
C'est précisément l'« appareil jamais vu, découvert par le seul protocole commun » que cette UC existe
pour servir. **AC2 est faux aujourd'hui.**

⚠️ **Le motif documenté de ce vide est CADUC.** Le docblock (`smartclimBroadlinkLan.class.php:436-445`)
justifie le catalogue vide par l'**union** de `appliquerCapacites()`, qui réintroduirait « Chauffage »
sur une unité froid-seul — la régression corrigée le 2026-08-26. Mais l'UC03 du domaine 03 a inventé
**exactement le mécanisme manquant** pour le legacy : `catalogue_par_defaut`, consommé en
`smartclim.class.php:5588`, dont la condition exacte est
`!empty($_detecte['catalogue_par_defaut']) && $this->getConfiguration('auxhome_device_id') !== ''`.
Le vide LAN n'est plus le seul moyen d'éviter la régression — il en est devenu la cause d'un autre défaut.

### 0.3 AC3 — aucune commande sans effet : déjà vrai, par quatre mécanismes

1. `'fil' => null` dans `smartclimCapabilities::tables()` → `valeursLisibles()` (276-288) exclut
   `SILENT` / `MEDIUM_LOW` / `MEDIUM_HIGH`.
2. `versTransport() === null` → `continue` dans les deux boucles de `definitionsCommandesAction()`
   (5856, 5876).
3. `conceptsConfortLivres()` / `conceptsOscillationLivres()` / `conceptsProtectionLivres()` — **tous
   vides à ce jour** (378-382, 467-468, 570) : aucune fonction avancée n'apparaît.
4. `modes_exclus` + `masquerCommandesModes()` (5598, 5648-5672) pour l'amputation sur preuve.

⚠️ **Fait à consigner, relevé par l'advisor et non par le plan** : le mécanisme 2 appelle
`versTransport(smartclimCapabilities::TRANSPORT_AUX_HOME, …)` — **toujours AUX Home, jamais le transport
réel de l'équipement**. Ce garde-fou est aujourd'hui un **no-op** : la table AUX Home a un `intent`
non nul pour les 5 modes et les 8 vitesses (180-196), donc il ne filtre jamais rien qui aurait pu
arriver par `valeursLisibles()` d'un autre transport. Cela reste vrai pour le catalogue LAN introduit
ici (§ 3.1). ⚠️ Mais c'est une **concordance de conception, pas une garantie structurelle** : un futur
transport dont le catalogue lisible porterait une valeur absente de l'`intent` AUX Home verrait ses
commandes disparaître **silencieusement**. Ne pas citer cette ligne comme une garantie générale.

### 0.4 AC4 — journalisation de la charge brute : non tenu, et contractuellement interdit

Aucun des trois transports ne journalise la charge. C'est **interdit par contrat écrit** : docblock de
`smartclimFrame::decoderEtat()` (`smartclimFrame.class.php:352-355`) — « *les trames ne sont NI
journalisées NI persistées telles quelles* ». L'existant se limite à des **métadonnées** : AUX Home
longueurs seules (1069), LAN premier octet + longueur (751, 547), legacy rien.

**Point favorable, et c'est la clé du § 3.3** : les trois charges concernées sont **déjà passées par une
frontière d'assainissement** — `nettoyerTrame()` (973-985, hex minuscule strict),
`nettoyerCapacitesBrutes()` (839-867, clés `[A-Za-z0-9_]{1,40}`, valeurs `[0-9A-Za-z,._-]{0,200}`),
`requeteParametres()` (noms `[A-Za-z0-9_]{1,40}`), et le `bin2hex()` du LAN. **Aucun nouveau mécanisme
de masquage n'est nécessaire.**

⚠️ **`smartclimDiagnostic::jeton()` n'a PAS lieu de servir ici**, et ce n'est pas un oubli : AC4 vise
« identifiants de **compte**, jeton, mot de passe ». Une MAC et un `deviceId` figurent déjà en clair
dans des dizaines de lignes de log existantes — les masquer ici seulement produirait une incohérence,
pas une protection.

### 0.5 AC5 — la table indexée par référence commerciale n'existe pas

Inventaire exhaustif des tables du plugin. **Aucune n'est indexée par une référence commerciale** :

| Table | Clé réelle | Effet |
|---|---|---|
| `smartclimCapabilities::tables()` | **transport** → concept → valeur | codes propriétaires |
| `fonctionsConfort/Oscillation/Protection()` | concept générique | exposition |
| `codesOscillation()` | concept + **transport** | surcharge de codes |
| `smartclimFrame::champs*()` | concept | offsets d'octets |
| `smartclimAuxHomeApi::exclusionsAuxHome()` (895-901) | **nom déclaré dans `feature`** (`coolType`) → valeur → modes exclus | **seul raffinement PAR APPAREIL du plugin** |
| `produitsClimatiseurConnus()` / `produitsPompeAChaleur()` | `productId` | une ligne de log / exclusion hors périmètre |

`exclusionsAuxHome()` est **structurellement** la bonne table (« nom déclaré → valeur observée → codes
génériques non supportés », doctrine d'exclusion sur preuve positive), mais sa clé est un champ de
`feature` — la référence commerciale (`modelId`) n'y entre jamais.

## 1. Arbitrages utilisateur — tranchés le 2026-09-16

| # | Question | Décision |
|---|---|---|
| **A1** | Socle LAN : publier le catalogue, ou rester vide ? | **Publier le catalogue LAN** (§ 3.1), en acceptant qu'un LAN-seul froid-seul affiche un bouton « Mode Chauffage » inerte jusqu'à contradiction par un scan cloud |
| **A2** | Nature de la preuve d'AC5 | **Preuve par exclusion** : une ligne de table fait **disparaître** un mode d'un appareil réel. La lettre « obtient le profil correspondant » est reformulée ici (§ 3.2 et § 7) |
| **A3** | Placement du log legacy (AC4) | **En amont des trois `continue`** de rejet (§ 3.3) — symétrie avec le choix LAN |
| **A4** | Les 3 chaînes UI anticipées par la spec | **Rejetées toutes les trois** (§ 5). Aucune chaîne nouvelle, aucun fichier i18n touché |

**A1 — pourquoi aucune troisième voie.** Planner et advisor l'ont cherchée indépendamment. Deux
alternatives examinées et écartées : (a) décoder un « bitmap de capacités » depuis la trame LAN —
**n'existe pas dans le protocole**, seule la valeur *courante* de chaque champ est lisible, jamais la
liste des valeurs supportées ; (b) exclure `HEAT` par défaut sur le LAN puisque c'est le cas de
régression connu — ce serait une amputation **sans preuve pour cet appareil précis**, l'inverse exact de
la doctrine `exclusionsAuxHome()`, et une incohérence de méthode plus grave que le défaut qu'elle
corrige. Le statu quo, lui, laisse l'appareil non pilotable en mode et **vide l'UC de son objet**.

## 2. Contrats externes

**Aucun appel réseau nouveau.** Cette UC ne sonde rien : aucun endpoint, aucun paquet, aucun budget de
temps, aucune route ajoutée. Les contrats déjà établis sont seulement **relus** :

- **AUX Home, champ `feature`** de `GET /app/user_device?getStatus=1` : chaîne contenant du JSON,
  entrées en couples `[valeur, drapeau]`. Source : `.memory/analyse/smartclim-transport-aux-home.md`
  § 3.2 + `nettoyerCapacitesBrutes()`. ⚠️ **Seule valeur dont le sens est PROUVÉ** : `coolType = '1'`
  ⇒ pas de chauffage (observé le 2026-08-26 sur l'unité de recette). **`coolType = '0'` reste de sens
  inconnu — ne pas l'ajouter à la table.**
- **`modelId`** (top-level de la même réponse), déjà consommé comme `configuration.modele`. Aucune
  sémantique n'en est déduite aujourd'hui ; **cette UC n'en déduit rien non plus** — elle en fait
  une **clé de table**, ce qui est exactement l'inverse d'une whitelist (l'absence d'entrée n'exclut
  rien).
- **Trame HVAC `bb00…`** commune aux trois générations :
  `.memory/analyse/smartclim-ecosysteme-aux-broadlink.md` § 3. C'est **le** fondement du socle minimal
  d'AC2, déjà implémenté dans `smartclimFrame`.

⚠️ **Hypothèse héritée, NON fermée par cette UC** : que la disposition de bits de la trame soit
identique sur un appareil d'un fabricant jamais testé. Le plugin ne la **présume pas** —
`conceptsLisibles()` ne déclare un concept que si la longueur le porte. Cette UC ne change rien à cette
prudence, et le « À confirmer » de la spec fonctionnelle **reste ouvert** (§ 8).

## 3. Architecture — fichiers

| Fichier | État | Contenu | Indentation |
|---|---|---|---|
| `core/class/smartclimBroadlinkLan.class.php` | **modifié** | `capacitesAppareil()` : catalogue + `catalogue_par_defaut` ; `lireEtat()` : appel de journalisation | 2 espaces |
| `core/class/smartclimAuxHomeApi.class.php` | **modifié** | `exclusionsAuxHome()` docblock/sémantique de clé ; `modesExclusAuxHome()` 2ᵉ paramètre ; `capacitesAppareil()` transmet la référence | 2 espaces |
| `core/class/smartclim.class.php` | **modifié** | `journaliserChargeBrute()` + 2 constantes ; appels dans `scannerAuxHome()` et `scannerAuxCloud()` | 2 espaces |
| `core/class/smartclimFrame.class.php` | **modifié** | docblock de `decoderEtat()` amendé (devient faux, cf. § 3.3) | 2 espaces |
| `core/php/smartclim.inc.php` | **non modifié** | **aucune classe nouvelle** — rien à ajouter à l'autoload | — |
| `smartclim.config.ini`, `packages.json`, `configuration.txt`/`.php`, `desktop/**`, `core/ajax/**`, `core/template/**`, `core/i18n/*` | **non modifiés** | aucune clé de config, aucune dépendance, **aucune surface web**, aucune chaîne UI | — |

Fins de ligne **CRLF**, comme l'existant. Contrôle avant commit :
`python .claude/scripts/verif-plugin.py` (colonne `meta=`).

### 3.1 `smartclimBroadlinkLan::capacitesAppareil()` — le socle pilotable LAN (AC2)

**Signature inchangée** : `public static function capacitesAppareil(array $_lecture)`.
Clés de retour existantes inchangées, trois évolutions :

- `'modes'` = `in_array(CONCEPT_MODE, $concepts, true)
  ? smartclimCapabilities::valeursLisibles(TRANSPORT_BROADLINK_LAN, CONCEPT_MODE)
  : array()` — **strictement calqué** sur `smartclimAuxHomeApi::capacitesAppareil()` (1053-1055).
- `'vitesses'` = idem avec `CONCEPT_FAN_SPEED`.
- **nouvelle clé** `'catalogue_par_defaut' => true`.

**Ce que cela produit, vérifié dans la table** : `valeursLisibles(BROADLINK_LAN, MODE)` = AUTO / COOL /
DRY / HEAT / FAN (5) ; `valeursLisibles(BROADLINK_LAN, FAN_SPEED)` = AUTO / LOW / MEDIUM / HIGH / TURBO
(5 — `SILENT`, `MEDIUM_LOW` et `MEDIUM_HIGH` portent `'fil' => null`, donc **exclus par construction**).

⚠️ **C'est exactement ce que `smartclimFrame::encoderOrdre()` sait écrire pour ce transport** : colonne
`'intent'` = colonne `'fil'` (`smartclimCapabilities.class.php:214-232`, docblock 206-213). **Aucune
commande sans effet ne peut naître de ce catalogue** — c'est ce qui tient AC3 malgré A1.

**Non-régression, par construction** : `appliquerCapacites()` neutralise `modes`/`vitesses` dès que
`auxhome_device_id !== ''` (5588). Un équipement AUX Home également scruté en LAN garde **exactement**
son comportement d'aujourd'hui. Le catalogue LAN n'est admis que sur un équipement **sans compte
AUX Home rattaché** — le cas cible de l'UC.

**Ordonnancement** : `ordonnerParReference()` prend `valeursLisibles(AUX_HOME, …)` comme référence
(5603-5604) ; les 5 modes et 5 vitesses LAN en sont des sous-ensembles → ordre canonique préservé,
**aucun `save()` parasite**.

⚠️ **Le docblock (436-445) est à RÉÉCRIRE entièrement** : il affirme aujourd'hui le contraire de ce qui
est livré, **avec sa justification**. Y inscrire que le motif d'UC02 du domaine 01 (« l'union
réintroduirait un mode exclu ») est désormais tenu par `catalogue_par_defaut`, **jamais** par un
catalogue vide.

**Migration du parc : aucun script.** Un équipement LAN existant gagne ses commandes de mode au
**premier scan LAN** : `scannerReseauLocal()` → `appliquerLectureLan()` (1581-1591) →
`appliquerCapacites()` renvoie `true` → `save()` → `postSave()` (5057-5069) → `creerCommandesAction()`.
⚠️ Le **cycle LAN de 15 min n'y suffit pas** : il n'appelle que `appliquerEtat()`. Le vecteur de
migration reste le **scan**, doctrine constante du projet.

### 3.2 `exclusionsAuxHome()` ouverte à la référence commerciale (AC5)

**Décision d'architecture, tranchée (A2)** : **pas** de dimension « modèle » dans
`smartclimCapabilities::tables()`. Les trois numérotations connues sont **par transport** ; aucune
source ne documente un modèle qui numéroterait ses modes autrement que son transport. Cet axe serait
construit pour une **hypothèse** et contredirait la raison d'être de `tables()` (source unique par
transport).

L'axe qui varie **réellement** d'un appareil à l'autre est le **raffinement de capacités**, et il a
déjà sa table. On l'ouvre à la référence commerciale, **sans une ligne de logique nouvelle** :

- **`private static function exclusionsAuxHome()`** — signature et forme de retour **inchangées**
  (`array<string, array<string, array<int,string>>>`). Sémantique de la clé de 1er niveau **élargie** :
  « **attribut déclaré par l'appareil** », soit une entrée de `feature`, soit l'attribut réservé
  **`modelId`**. Livrée avec la **seule** ligne existante (`coolType` → `'1'` → `MODE_HEAT`) :
  ⚠️ **aucune référence commerciale réelle n'est ajoutée** (hors périmètre explicite de la spec).
- **`private static function modesExclusAuxHome(array $_capacitesBrutes, $_modele = '')`** — second
  paramètre, défaut `''` (aucun autre appelant à casser). Corps :
  `$attributs = array_merge($_capacitesBrutes, ($_modele !== '') ? array('modelId' => $_modele) : array());`
  puis boucle **inchangée**. ⚠️ `array_merge` **dans cet ordre** : la valeur *top-level* (autoritaire)
  l'emporte sur un hypothétique `feature.modelId`. Le `$_modele` reçu est **déjà assaini** par
  `nettoyerTexteExterne()` (793-794).
- **`capacitesAppareil()`** — signature et clés de retour **inchangées** ; un seul appel modifié
  (1051) : `self::modesExclusAuxHome($capacitesBrutes, isset($_appareil['modele']) &&
  is_string($_appareil['modele']) ? $_appareil['modele'] : '')`.

**Effet observable, et c'est littéralement AC5** : ajouter la prise en charge d'une référence = ajouter
**une ligne** dans cette table, **aucune autre partie du plugin touchée** ; l'appareil portant cette
référence obtient automatiquement le profil correspondant (mode retiré du profil, commande masquée par
`masquerCommandesModes()`).

⚠️⚠️ **À écrire dans le docblock — ce que cette table sait faire et ce qu'elle ne sait PAS faire.**
Elle **exclut des modes**, rien d'autre : ni vitesses, ni ajout de capacité. Ce **n'est pas une limite
d'implémentation** mais une conséquence de `appliquerCapacites()` — `modes_exclus` est la **seule** voie
d'amputation acceptée par l'union (5593-5598). Une `vitesses_exclues` exigerait une **nouvelle branche
d'amputation dans la fusion**, donc du **code**, pas de la donnée : exactement contre l'objectif de
l'UC. Ne pas l'ajouter sans preuve d'un besoin réel.

⚠️ **Conséquence assumée sur la nature de la preuve (A2)** : la démonstration d'AC5 fait **disparaître**
un mode d'un appareil réel, elle ne fait pas **apparaître** un profil entier pour une référence fictive.
La formulation littérale de la spec fonctionnelle (« obtient automatiquement le profil de capacités
correspondant ») est **reformulée ici** et dans le protocole de recette (§ 7).

⚠️ **Pas de jumeau legacy ni LAN dans cette UC** : aucune référence commerciale n'y est exploitable
aujourd'hui (le `productId` legacy est un opaque de 32 hex sans sémantique lue ; le devtype LAN est
**explicitement interdit** comme critère, cf. `.memory/analyse/smartclim-transport-broadlink-lan.md`
§ 14). Un `exclusionsAuxCloud()` vide serait du **code mort non exerçable** sur un transport non
recetté. **Documenter l'emplacement prévu en commentaire, ne rien livrer.**

### 3.3 `smartclim::journaliserChargeBrute()` — la charge brute en debug (AC4)

Nouvelle méthode dans `core/class/smartclim.class.php`, **placée à côté de `neutraliserPourLog()`**
(même rôle : hygiène de log, point d'implémentation **unique**).

```php
const LONGUEUR_MAX_TRAME_LOG = 400;      // caractères hexadécimaux
const LONGUEUR_MAX_ATTRIBUT_LOG = 64;    // caractères par valeur d'attribut

public static function journaliserChargeBrute($_transport, $_reference, array $_trames, array $_attributs)
```

- `$_transport` : constante `smartclimCapabilities::TRANSPORT_*`, journalisée via `libelleTransport()`.
- `$_reference` : repère d'appareil **déjà journalisé ailleurs en clair** (MAC normalisée, `deviceId`,
  `endpointId`) — passé par `neutraliserPourLog()`.
- `$_trames` : `array<string,string>` libellé court → trame **hexadécimale**.
- `$_attributs` : `array<string,scalar>` nom d'attribut → valeur.
- Retour : `void`. ⚠️ **Ne lève JAMAIS** — aucun accès disque ni réseau, uniquement `log::add`.

#### Trois barrières, indépendantes et ordonnées de la plus forte à la plus faible

⚠️ La leçon projet « filtres de masquage emboîtés » impose que chacune **protège seule**, sans
dépendre du déclenchement d'une autre.

1. **Allowlist au point d'appel — la barrière principale.** On ne passe **JAMAIS** `$appareil` entier :
   `normaliserAppareilLegacy()` y met `cookie` et `dev_session`
   (`smartclimAuxCloudApi.class.php:1967-1968`). C'est une **liste d'autorisation**, jamais une liste de
   blocage — le sens sûr, à l'inverse de l'incident du 2026-08-26.
2. **Forme de trame** : journalisée seulement si hexadécimale minuscule (2 caractères minimum) **ET**
   `strlen <= LONGUEUR_MAX_TRAME_LOG` ; sinon remplacée par un marqueur `(<n> car., forme inattendue)`.
3. **Forme d'attribut** : paire journalisée seulement si le **nom** vérifie `[A-Za-z0-9_]{1,40}` **ET**
   la **valeur** `[0-9A-Za-z._-]{0,64}` ; sinon la paire est **omise**.

**Ce que ces barrières attrapent, vérifié contre les secrets réellement présents dans les structures
voisines** : un `cookie` legacy (base64, donc `+` `/` `=`, et > 400 caractères) tombe sur les **deux**
barrières de forme *et* n'est pas passé (barrière 1) ; un `devSession` (jusqu'à 512 caractères) tombe
sur la longueur ; un jeton bearer AUX Home et un mot de passe ne sont **structurellement pas** dans les
tableaux passés.

#### Trois points d'appel, un par transport

| Transport | Site | Charge passée |
|---|---|---|
| **AUX Home** | `smartclim::scannerAuxHome()`, juste après la ligne 939 | `trames` = `controle` + `running` ; `attributs` = `capacites_brutes` + `modelId` ; `reference` = `$identifiant` |
| **Broadlink LAN** | `smartclimBroadlinkLan::lireEtat()`, juste avant le `return` (406) | `trames` = `controle` + `longue` ; `attributs` = `statut` (+ devtype si en main) ; `reference` = `$macNorm` |
| **Legacy** | `smartclim::scannerAuxCloud()`, **EN AMONT des trois `continue`** (avant 1130) | `trames` = `array()` ; `attributs` = `valeurs` + `productId` ; `reference` = `$identifiant` |

⚠️⚠️ **Le LAN est journalisé depuis `lireEtat()`, pas depuis `capacitesAppareil()` ni depuis le
scanner, et c'est le point le plus important de ce paragraphe** : un appareil LAN dont la charge est
inexploitable (`STATUT_ETAT_ILLISIBLE`) **n'est jamais créé** et ne traverse donc **jamais**
`appliquerCapacites()` — or c'est **précisément** l'appareil « non reconnu » qu'AC4 existe pour
diagnostiquer. `lireEtat()` est le seul site traversé par **tous** les appareils, reconnus ou non.
*Corollaire assumé* : la ligne est aussi émise à chaque cycle LAN de 15 min, **en `debug` uniquement**.

⚠️⚠️ **Le legacy suit la MÊME règle (A3), et c'est un correctif du plan initial.** `scannerAuxCloud()`
pose **trois `continue`** avant la construction du profil : `ignore_pompe_chaleur` (1130-1133),
`ignore_budget` (1134-1138) et surtout **`ignore_non_climatiseur`** (1160-1164, gardé par
`empty($appareil['preuve_climatiseur'])` sur un appareil non rapproché — soit exactement
« appareil jamais vu, `productId` inconnu, aucune preuve »). Journaliser **après** ces branches raterait
structurellement la cible d'AC4 : `ignore_non_climatiseur` est l'**analogue exact** de
`STATUT_ETAT_ILLISIBLE` côté LAN. L'appel se fait donc **avant 1130**, sur les données déjà normalisées
(`$appareil['valeurs']`, `type_produit` y sont disponibles).

⚠️ **La journalisation n'est PAS conditionnée à « appareil non reconnu », délibérément.** Gater sur
l'inconnu ferait dépendre le contrôle du **signal que le contrôle cherche** (leçon « un critère
d'exemption qui aveugle le contrôle ») : un appareil catalogué mais au comportement anormal deviendrait
invisible, et côté LAN la notion n'existe même pas. **Le niveau `debug`, désactivé par défaut, EST le
gate.**

⚠️ **Nouveau couplage à énoncer** : `smartclimBroadlinkLan` appellera `smartclim::` pour la **première
fois**. Le précédent est établi — `smartclimAuxHomeApi` (374, 1141, 1295, 1421) et
`smartclimAuxCloudApi` (236, 280, 381, 436, 561) le font déjà — et le couplage est statique, sans cycle
de chargement (l'appel est au runtime). L'alternative (trois helpers privés jumeaux) est **refusée** :
c'est exactement le « 3ᵉ mécanisme » que le projet a évité pour `smartclimDiagnostic::jeton()`.

⚠️ **Où ce helper ne doit PAS vivre** : ni dans `smartclimDiagnostic` (contractuellement « jamais
sollicité par le plugin en fonctionnement » — une journalisation de scan invaliderait ce contrat), ni
dans `smartclimFrame` / `smartclimCapabilities` (tables de données pures, aucune E/S).

⚠️ **Le docblock de `smartclimFrame::decoderEtat()` (352-355) devient FAUX** et doit être amendé **dans
le même geste** : la règle « les trames ne sont jamais journalisées » devient « les trames ne sont
journalisées **que** par `smartclim::journaliserChargeBrute()`, en `debug`, sous ses barrières —
**jamais** par ce décodeur ni par un transport en direct ».

## 4. Validation & erreurs

- **Aucune entrée utilisateur nouvelle** : aucune validation client ni serveur, aucun message d'erreur,
  **aucun endpoint AJAX touché**. `core/ajax/smartclim.ajax.php` reste inchangé (`session_write_close()`
  et le `catch (Throwable)` final en place tels quels).
- `journaliserChargeBrute()` **n'a aucun chemin d'échec** : une valeur non conforme est **omise ou
  marquée**, jamais une exception. ⚠️ Elle est appelée depuis `lireEtat()`, dont le contrat est de **ne
  jamais lever** — à vérifier en revue que rien dans le helper ne puisse jeter : **pas de `hex2bin`, pas
  de `json_decode`**, uniquement `preg_match` / `strlen` / `substr` / `implode`.
- `capacitesAppareil()` des trois transports conserve son contrat « **ne lève JAMAIS** ».
- `modesExclusAuxHome()` : `$_modele` non-chaîne ⇒ **ignoré** (garde `is_string` au point d'appel) ;
  référence absente de la table ⇒ **aucune exclusion** — comportement voulu pour un appareil inconnu,
  **l'absence de preuve n'ampute rien**.
- **Aucune exception nouvelle**, aucun type ajouté à `smartclimException`.

## 5. Impact i18n — français uniquement

**Aucune chaîne française nouvelle. Zéro `__()`, zéro `{{…}}`, aucun `core/i18n/*.json` à toucher.**
L'étape `translator` du cycle est donc **sans objet** pour cette UC.

Les trois chaînes anticipées par la spec fonctionnelle sont **écartées (A4)**, chacune pour un motif
propre :

- **« Modèle non reconnu »** — ⚠️ **à rejeter fermement** : elle suppose une notion de « modèle
  reconnu » qui n'existe pas et **ne doit pas exister**. Après cette UC, « reconnu » signifierait
  « présent dans `exclusionsAuxHome()` », c'est-à-dire **un seul appareil au monde** : l'afficher
  marquerait « non reconnu » la quasi-totalité d'un parc parfaitement supporté, et **suggérerait
  exactement la whitelist que le brief interdit**.
- **« Profil de capacités minimal »** — sans objet : `catalogue_par_defaut` est **volontairement non
  persisté** dans le profil (5585-5587, pour ne pas provoquer un `save()` de migration sur tout le
  parc). L'afficher exigerait de **casser cet invariant**, pour une information sans action associée.
- **« Capacités détectées automatiquement »** — doublon : la page porte déjà
  `{{Profil de capacités détecté}}` (`desktop/php/smartclim.php:395`), `{{Capacités détectées}}` (82) et
  `{{Valeur par défaut du transport}}`.

**Effet visible de l'UC sans une chaîne nouvelle** : la colonne *Capacités détectées* d'un équipement
LAN-seul passe de la liste des concepts à la liste des **modes** (repli déjà codé, 3362-3369).

## 6. Risques

- **R1 — fenêtre transitoire sur une première découverte double.** L'ordre de `scannerClimatiseurs()`
  est LAN (763) → legacy (785) → AUX Home (813-824), et `array_replace($lanProfils, $legacy, $cloud)`
  (831-832) fait **gagner le cloud en dernier**. Un appareil AUX Home découvert *d'abord* par le LAN a
  `auxhome_device_id` vide au moment du scan LAN : son catalogue est admis, `mode_heat` est créé. La
  phase AUX Home du **même clic** le rapproche, pose `modes_exclus = [HEAT]`, retire le mode et masque
  la commande. **Résultat final correct** ; *séquelle* : une commande `mode_heat` en `isVisible = 0`
  subsiste indéfiniment (le masquage n'est jamais annulé, par conception). Blemish borné, **accepté** —
  ⚠️ ne pas « corriger » en réordonnant les phases (décision « le cloud passe en dernier et gagne »).
- **R2 — cas limite PRÉ-EXISTANT, non aggravé.** Un équipement vu par le **legacy** et par le **LAN**
  mais pas par AUX Home reçoit du legacy le catalogue incluant `SILENT` ; routé vers le LAN,
  `encoderOrdre()` lève `TYPE_INTERNE` (`smartclimFrame.class.php:664-667`) — **message d'erreur propre,
  pas un silence**. Existe déjà aujourd'hui, **non traité ici** : le corriger supposerait de filtrer
  `definitionsCommandesAction()` par transport, donc de faire **disparaître des commandes au gré de la
  disponibilité réseau** et de casser les scénarios utilisateur. Non recettable (exige un compte legacy
  **et** un appareil Broadlink).
- **R3 — des charges de protocole partent en log, et les deux transports ne sont PAS au même niveau de
  protection.** Précisé par la review sécurité du 2026-09-16, qui a remonté chaque valeur jusqu'à sa
  source plutôt que de juger sur l'intention :
  - **AUX Home** — `capacites_brutes` est **déjà filtrée à la source** par `nettoyerCapacitesBrutes()`
    (noms `[A-Za-z0-9_]{1,40}`, valeurs `[0-9A-Za-z,._-]{0,200}`), et les trames par `nettoyerTrame()`.
    La barrière 3 du journal y est une **seconde** ligne de défense.
  - ⚠️ **Legacy** — `recomposerValeurs()` (`smartclimAuxCloudApi.class.php:1412-1430`) filtre les **NOMS**
    mais **PAS les VALEURS** (`$valeurs[$nom] = $vals[$index][0]['val']`, brut du backend). Sur ce
    chemin, **la barrière 3 porte donc SEULE la protection**. Elle tient — `cookie` (base64, `+` `/` `=`,
    > 400 car.) et `dev_session` (jusqu'à 512 car.) échouent sur la forme **et** sur la longueur, et
    n'entrent de toute façon jamais dans `valeurs` (barrière 1) — mais **elle n'a pas de filet derrière
    elle**. ⚠️ Ne jamais relâcher `LONGUEUR_MAX_ATTRIBUT_LOG` ni la classe de caractères en croyant
    qu'un assainissement amont compense : côté legacy, il n'y en a pas.
  - **Risque résiduel commun** : un backend qui publierait un jour un secret **court et alphanumérique**
    passerait les bornes de forme. Mitigation : le **contrôle exécuté** de recette (§ 7), **à rejouer**
    si de nouvelles clés `feature` (AUX Home) ou de nouveaux paramètres (legacy) apparaissent.
  - ⚠️ **Conséquence du placement en amont des rejets (A3)** : le journal legacy couvre aussi les
    appareils que le plugin va écarter, donc des **produits non-HVAC du même compte** (prise,
    humidificateur…). Leurs paramètres d'état passent les mêmes barrières ; aucun identifiant de compte
    ne transite structurellement par `sdkcontrol get`. Le contrôle de recette doit **inclure
    explicitement un appareil non-climatiseur** le jour où un compte legacy est disponible (§ 7.2).
- **R4 — la partie LAN d'AC2 est livrée NON RECETTÉE**, comme tout le transport Broadlink. Le catalogue
  est vérifié par **cohérence interne** (`intent` = `fil`, concordance avec `champsEcriture()`), jamais
  mesuré. `intent_confirme` reste `false` pour ce transport.
- **R5 — dette héritée inchangée, mais plus visible.** `conceptsLisibles()` ne teste que des
  **longueurs**, jamais le magic `bb00` (dette UC04 domaine 01 § 12.2). Cette UC ne l'aggrave pas (elle
  n'ajoute des modes qu'à un équipement ayant **déjà** franchi `STATUT_ETAT_LU`) mais elle en augmente
  la **visibilité** : 5 boutons de mode au lieu d'aucun sur un faux positif. Argument supplémentaire
  pour traiter la dette — **hors périmètre ici**.
- **R6 — `CLAUDE.md` porte une affirmation devenue fausse.** « Le profil LAN et le profil legacy
  publient tous deux `modes`/`vitesses` VIDES » : **faux pour le legacy depuis l'UC03 du domaine 03**
  (`smartclimAuxCloudApi.class.php:2025-2030`), et faux pour le LAN après cette UC. À corriger en
  capitalisation, avec les deux docblocks (§ 3.1, § 3.3).
- **R7 — irréversibilité de l'union.** Un mode entré au profil n'en sort que par `modes_exclus`. Un
  appareil LAN-seul froid-seul portera donc un bouton « Mode Chauffage » jusqu'à ce qu'une preuve
  arrive — **c'est le prix assumé d'A1**.
- **Piège de relecture** : `smartclimFrame::champs()` porte `'octets'` (**pluriel**) là où
  `champsBinaires()` / `champsOscillation()` portent `'octet'` (singulier). ⚠️ Aucune modification
  planifiée ne touche ces tables — si une revue propose de le faire, relire `longueursMinimales()`
  (197-209) **d'abord**.

## 7. Recette

**Matériel de l'utilisateur : un climatiseur AUX Home, aucun appareil Broadlink, aucun compte
AC Freedom.**

### 7.1 Recettable, et directement probant

- **AC1** — son unité porte déjà une référence (`modelId`) qu'**aucune table ne connaît** : elle est,
  au sens de l'UC, un « modèle inconnu ». Le scan la fait apparaître et la crée. *Vérifié par le simple
  fonctionnement actuel.*
- **AC2 / AC3 sur AUX Home** — marche/arrêt, mode et consigne présents et effectifs ; **aucune**
  commande de vitesse Silencieux / Moyen-faible / Moyen-fort ; **aucune** fonction de confort,
  d'oscillation ni de protection (les trois `conceptsXxxLivres()` sont vides).
- **AC4** — activer le niveau **debug** du plugin, lancer un scan, puis **exécuter** les deux contrôles
  (⚠️ les **lancer**, pas les prescrire ; coller la sortie dans la recette) :
  1. `grep -i -e '<e-mail du compte>' -e '<mot de passe>' -e 'bearer' -e 'passcode' -e 'devSession'
     -e 'loginsession' <log/smartclim>` → **zéro occurrence attendue** ;
  2. `grep 'charge brute' <log/smartclim>` → **au moins une ligne** portant une trame `bb00…`.
- **AC5** — protocole complet sur son propre appareil :
  1. relever la référence dans la colonne *Modèle* du tableau de scan ;
  2. ⚠️ **copier-coller** cette valeur (ne **jamais** la retaper : `nettoyerTexteExterne()` ne normalise
     **ni la casse ni les espaces internes**, et le rapprochement se fait par **égalité stricte de
     chaîne** — une clé retapée ne matchera pas, et la recette serait lue comme un échec du mécanisme) ;
  3. ajouter **une seule ligne** dans `exclusionsAuxHome()` :
     `'modelId' => array('<la référence>' => array(smartclimCapabilities::MODE_DRY))` ;
  4. relancer le scan → « Déshumidification » **disparaît** du *Profil de capacités détecté*, et la
     commande `mode_dry` passe en non visible (message `info` « Commande masquée… » dans le log) ;
  5. retirer la ligne, relancer le scan → le mode **revient au profil** ;
  6. ⚠️ **la commande `mode_dry` reste masquée** — le masquage n'est **jamais** annulé, par conception.
     La réafficher à la main. **Ce point doit figurer dans le protocole**, sinon l'étape 6 sera lue
     comme un échec.
- **AC6 sur AUX Home** — couvert par AC1 + AC2 ci-dessus, son appareil étant de fait « non catalogué ».

### 7.2 Non recettable — à livrer explicitement comme tel

Cohérent avec les transports LAN et legacy, déjà livrés non recettés :

- le **socle LAN d'AC2** (§ 3.1) — aucun appareil Broadlink. Vérifiable seulement par cohérence interne
  (`intent` = `fil` pour les 5 modes et 5 vitesses, concordance avec `champsEcriture()`) et par lecture
  croisée de `mjg59/python-broadlink`.
- la **journalisation LAN d'AC4** (site `lireEtat()`) — jamais exercée.
- la **journalisation legacy d'AC4** — aucun compte AC Freedom. `core/php/commande-auxcloud.php` reste
  l'instrument prévu si un rapport communautaire arrive. ⚠️ **Le jour où ce contrôle devient exécutable,
  il doit porter sur un appareil NON-CLIMATISEUR du compte**, et pas seulement sur un climatiseur : le
  placement en amont des rejets (A3) fait entrer ces appareils dans le journal, et c'est le chemin où la
  barrière 3 est **seule** (R3). Un `grep` qui ne couvrirait que les climatiseurs laisserait ce sous-cas
  non vérifié.
- le **cas R2** — exige un compte legacy **et** un appareil Broadlink simultanément.
- l'**hypothèse de fond de la spec** (« la trame `bb00…` reste décodable sur un appareil d'un fabricant
  jamais testé ») — **elle ne sera pas fermée par cette UC** : un seul appareil de référence. À laisser
  marquée « à confirmer » dans `.memory/analyse/smartclim-ecosysteme-aux-broadlink.md` § 3.

## 8. Dette

- **D-1 — garde `versTransport()` câblée sur `TRANSPORT_AUX_HOME`** (`smartclim.class.php:5856`, 5876),
  quel que soit le transport réel de l'équipement. **No-op aujourd'hui** (§ 0.3), y compris pour le
  catalogue LAN introduit ici, parce que les trois tables ont été construites pour concorder. Mais c'est
  une **concordance de conception, pas une garantie structurelle** : un futur transport dont le
  catalogue lisible porterait une valeur absente de l'`intent` AUX Home verrait la création de sa
  commande échouer **silencieusement**. À traiter le jour où un 4ᵉ transport arrive.
- **D-2 — séquelle de R1** : une commande `mode_*` masquée à la transition n'est jamais réaffichée
  automatiquement. Conception assumée (rejouer le masquage écraserait le choix d'un utilisateur), mais
  laisse une commande invisible sur un équipement dont le profil s'est ensuite élargi.
- **D-3 — pas d'exclusion par référence sur le legacy ni le LAN** (§ 3.2). Emplacement documenté en
  commentaire, rien livré : le `productId` legacy n'a aucune sémantique lue et le devtype LAN est
  interdit comme critère. À rouvrir si un rapport communautaire fournit une correspondance prouvée.
- **D-4 — R5, dette héritée** : `conceptsLisibles()` ne vérifie pas le magic `bb00`. Rendue plus
  visible par cette UC, non traitée (hors périmètre).
