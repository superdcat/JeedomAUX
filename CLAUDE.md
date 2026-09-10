# CLAUDE.md

Ce fichier guide Claude Code (claude.ai/code) lorsqu'il travaille sur ce dépôt.

> **Langue des réponses** : toujours s'adresser à l'utilisateur **en français** (explications, résumés,
> questions, messages de commit et de PR). Le français est la langue de travail du projet — ne jamais
> répondre en anglais.

## Présentation

**SmartClim** (id **`smartclim`**, catégorie Jeedom `wellness` / « Confort ») est un plugin Jeedom qui
pilote les **climatiseurs Wi-Fi de l'écosystème AUX / Broadlink / AC Freedom**, *quelle que soit la marque
imprimée dessus* (AUX, Ballu, Centek, Dunham Bush, Kenwood, Rinnai, Rcool, Tornado, Akai, Hyundai, Hisense,
Royal Clima… liste **non exhaustive et non bloquante**). Le critère de prise en charge est le **protocole
joignable** et les **capacités observées** — jamais une liste de références commerciales.

Trois **transports** interchangeables sont visés, derrière une abstraction commune :

| Transport | Statut | Nature |
|---|---|---|
| **AUX Home** (`eu-smthome-api.aux-global.com`) | **socle MVP** | cloud récent, REST, 100 % PHP |
| **Broadlink LAN** (UDP port 80) | post-MVP domaine 01, **UC01 livrée** (découverte + session) | pilotage local, sans Internet |
| **AUX Cloud legacy / AC Freedom** | post-MVP domaine 03, **UC01 livrée** (authentification multi-régions) | cloud historique, multi-régions |

Le principe directeur (brief utilisateur, `.memory/brief.md`) :

```text
Device → Capabilities → Generic AC API → Transport
                                            ├── Broadlink Local
                                            ├── AUX Cloud legacy
                                            └── AUX Home Cloud
```

La partie Jeedom **ne dépend pas** du protocole utilisé : une nouvelle génération de firmware s'ajoute en
enrichissant une **table de données**, pas en modifiant la logique.

- Un plugin Jeedom **n'est pas autonome** : il s'installe sous `<jeedom>/plugins/smartclim/`, et tout le PHP
  dépend du core Jeedom, atteint via `require_once __DIR__ . '/../../../../core/php/core.inc.php';`.
- **Pas de build local** ; la validation se fait en CI (voir « Workflows / CI ») et la recette sur un Jeedom
  réel avec un vrai climatiseur.
- ⚠️ Tout repose sur du **reverse engineering** de protocoles tiers : une mise à jour de firmware ou de
  backend peut casser un transport. C'est précisément ce qui justifie l'abstraction et le multi-transport.

Ce dépôt embarque, en plus du code du plugin, un **outillage Claude Code** pour développer les features de
manière structurée :
- **`.claude/`** — commandes `/init-plugin` (cadrage, déjà joué), `/feature` (implémentation d'une UC),
  `/auto-dev` (enchaînement autonome de plusieurs UC) et `/change` (revenir sur une décision de
  `/auto-dev`), sous-agents (`jeedom-plugin-architect`, `jeedom-tech-planner`, `spec-writer`,
  `php-jeedom-dev`, `auto-dev-runner`, `code-reviewer`, `security-reviewer`, `translator`), skills
  `spec` et `dev`, templates partagés (`.claude/templates/`), scripts (`.claude/scripts/`), mémoire
  d'agent.
  ⚠️ **Chaque sous-agent épingle son `effort` dans son frontmatter** : sans cette ligne il **hérite de
  l'effort de la session**, et la boucle d'édition mécanique tourne au niveau de réflexion de
  l'orchestrateur — c'est là que partent les tokens, pas dans le raisonnement de l'orchestrateur lui-même.
  La réflexion coûteuse est concentrée là où une erreur se paie cher : le **plan**
  (`jeedom-tech-planner`, Opus `xhigh`) et les **reviews** (`code-reviewer`/`security-reviewer`, `high`).
  Elle est volontairement basse là où le travail est mécanique et déjà cadré (`php-jeedom-dev` `medium`,
  `spec-writer` `medium`, `translator` `low`). Les commandes s'élèvent elles-mêmes : `/feature` en `high`
  (il ne fait plus que piloter), `/init-plugin` en `xhigh` (cadrage = arbitrage).
- **`.memory/`** — connaissance interne **versionnée** : `specs/` (specs fonctionnelles/techniques des
  features), `analyse/` (décisions/pièges Jeedom **et** protocoles AUX/Broadlink), `external/doc/` (index
  de la doc externe).

## Architecture

Disposition Jeedom fixe (type MVC). Pièces principales, nommées d'après l'id `smartclim` :

- **`core/class/smartclim.class.php`** — le cœur du plugin. Deux classes (**1 classe ↔ 1 fichier**, cf.
  Conventions/Autoload) :
  - `smartclim extends eqLogic` — **une instance par climatiseur**. Hooks de cycle de vie
    (`preSave`/`postSave`, `preInsert`/`postInsert`, `preRemove`/`postRemove`…), hooks cron statiques
    (`cron()` chaque minute, `cron5`, `cron10`, `cron15`, `cron30`, `cronHourly`, `cronDaily`), hooks
    `preConfig_<clé>`/`postConfig_<clé>` pour valider/réagir à la config plugin. `$_encryptConfigKey`
    chiffre automatiquement les champs de config **plugin** sensibles.
  - `smartclimCmd extends cmd` — commande (info ou action). `execute($_options)` exécute une action
    (typiquement un `switch` sur `logicalId`).

  Depuis l'UC05, la classe porte aussi le **cycle des commandes info** : `definitionsCommandesInfo()`
  (table privée : `logicalId` générique → type/subType/unité/template, libellés pris à
  `smartclimCapabilities::libelleCommande()`), `creerCommandesInfo()` (création **idempotente**,
  conditionnée au profil de capacités, en **une** lecture `getCmd(null, null)` — jamais N requêtes) et
  `appliquerEtat(array $_etat)` (pousse par `checkAndUpdateCmd()` les **seules clés présentes** dans
  l'état). Deux commandes méta hors profil : `smartclim::CMD_TRANSPORT` et `CMD_DERNIERE_MAJ`.
  ⚠️ **`modes_exclus` est la SEULE exception à l'union de `appliquerCapacites()`** (« un profil ne
  s'ampute jamais ») : c'est une **preuve** fournie par l'appareil, pas une absence de détection. Sans
  elle, un mode stocké avant l'existence de la restriction survivrait indéfiniment à sa correction — la
  migration du parc est donc automatique au premier scan, sans script. Un mode qui **quitte** le profil
  voit ses commandes d'action **masquées** par `masquerCommandesModes()` (`isVisible = 0`, jamais de
  suppression), **une seule fois, à la transition** : rejoué à chaque scan, le masquage écraserait le
  choix d'un utilisateur qui aurait réaffiché la commande.
  ⚠️ **Une clé absente de l'état ne touche pas sa commande** — c'est le mécanisme, volontaire et unique,
  qui évite d'afficher une valeur non confirmée (vitesse/mode sans correspondance de lecture, trame trop
  courte, appareil hors ligne, température ambiante implausible). Ne jamais le remplacer par une valeur
  de repli.
  ⚠️ `creerCommandesInfo()` est appelée par **`postSave()` ET `appliquerEtat()`** : un scan qui ne change
  rien n'émet aucun `save()`, donc aucun `postSave()` — sans le second appel, aucune commande
  n'apparaîtrait sur un parc déjà découvert avant UC05.

  Depuis l'UC06, elle porte **symétriquement le cycle des commandes action** :
  `definitionsCommandesAction()` (table privée dérivée du **profil de capacités**, jamais d'un catalogue
  de modèles : `on`/`off`, `mode_<MODE_*>`, `fan_<VITESSE_*>` via `PREFIXE_CMD_MODE`/`PREFIXE_CMD_VITESSE`,
  et `set_target_temp` en `slider`), `creerCommandesAction()` (idempotente, **même** unique lecture
  `getCmd(null, null)`, appelée par **`postSave()` ET `appliquerEtat()`** pour la raison exacte décrite
  ci-dessus) et `executerCommandeAction()`, sur laquelle `smartclimCmd::execute()` se contente
  d'aiguiller.
  ⚠️ **Une commande action est LIÉE à son info** (`setValue(<id de l'info>)`) et porte le widget
  `smartclim::etat` : c'est ce qui fait que le bouton reflète l'état courant au lieu d'être un simple
  déclencheur. La pose est **idempotente dans les deux branches** — création *et* commande déjà existante
  dont le template est vide (retour volontaire au widget par défaut du core). Elle n'écrase **jamais** un
  template posé, et n'émet un `save()` que si elle a effectivement reposé le widget.
  ⚠️ **L'ordre envoyé est construit ENTIÈREMENT côté serveur** à partir du `logicalId`, validé contre
  `definitionsCommandesAction()` ; `$_options` n'est lu que pour la consigne (`slider`), puis borné et
  **quantifié** sur la grille du pas par `ordreEffectifConsigne()`. Un `execCmd()` de vieux scénario
  visant un mode sorti du profil échoue proprement — c'est ce qui tient l'exigence « aucune valeur non
  supportée » **hors** de l'interface, pas seulement dans l'UI.
  ⚠️ **Tout ordre de mode ou de consigne porte TOUJOURS `power => 1`** : changer le mode d'un appareil
  éteint l'allume, en **une** requête. Ne pas « optimiser » en retirant cette clé.
  ⚠️ **Sept mémoires de cache, sept rôles distincts, à ne pas confondre** — aucune ne vit en
  configuration d'équipement (la cinquième, `smartclim::dernier_cycle_lan`, est arrivée avec l'UC01 du
  domaine post-MVP 02 : cf. sa ligne après `dernier_incident` ; la sixième,
  `smartclim::echecs_transport::<id>`, avec l'UC02 du même domaine ; la septième,
  `smartclim::appareil_auxcloud::<id>`, avec l'UC02 du domaine post-MVP 03) :
  - `smartclim::ordre_recent::<id>` (`CLE_CACHE_DEDUP`, **10 s**) — empreinte du **contenu** de l'ordre,
    posée **avant** l'appel réseau et supprimée en cas d'échec : anti-double-bip. La clé est le contenu,
    **pas** l'équipement — deux ordres *différents* rapprochés passent donc bien tous les deux.
  - `smartclim::ordres::<id>` (`CLE_CACHE_ORDRES`, `DUREE_GRACE` = **60 s**) — dernière valeur commandée
    par concept, consommée par `filtrerEtatSelonOrdres()` **dans `appliquerEtat()`** : un état scruté plus
    ancien qu'un ordre envoyé n'écrase pas la valeur commandée (anti-rollback). C'est là, et nulle part
    ailleurs, que le cron d'UC07 hérite de la période de grâce — il n'a effectivement pas coûté une ligne :
    le cycle appelle `appliquerEtat($etat)` **sans** second argument, donc `$_optimiste = false`, donc
    filtrage actif.
  - `smartclim::dernier_cycle` (`CLE_CACHE_DERNIER_CYCLE`, `DUREE_MEMOIRE_CYCLE` = 48 h) — horodatage du
    dernier cycle de rafraîchissement (UC07), **globale au plugin** et non par équipement, contrairement
    aux deux précédentes. Lue par `cycleEchu()`, écrite par `marquerCycle()`.
  - `smartclim::dernier_incident` (`CLE_CACHE_DERNIER_INCIDENT`, `DUREE_MEMOIRE_CYCLE` = 48 h) — dernier
    échec de connexion du **cycle automatique** (UC08), également **globale au compte** et non par
    équipement : l'incident porte sur le compte cloud, pas sur un appareil (un appareil individuellement
    injoignable est déjà décrit par son `online = false`). Seul cache **non chiffré** des quatre, parce
    qu'il ne contient qu'un type d'exception, une constante de contexte et un horodatage — jamais de
    donnée d'origine backend. Écrite par `memoriserIncident()`, effacée par `oublierIncident()`, relue par
    `incidentMemorise()` (qui **valide sa forme** et renvoie `null` plutôt qu'un état forgé).
    ⚠️ **Invariant en une phrase** : *seul le cycle automatique l'écrit ; toute connexion réussie
    l'effface.* Un scan ou un test de connexion en échec ne l'écrit **pas** — ce sont des chemins
    interactifs, dont l'erreur est déjà affichée à l'utilisateur.
  - `smartclim::dernier_cycle_lan` (`CLE_CACHE_DERNIER_CYCLE_LAN`, `DUREE_MEMOIRE_CYCLE` = 48 h) —
    horodatage du dernier **cycle de sonde LAN** (UC01 du domaine post-MVP 02), **globale au plugin** comme
    `dernier_cycle`, dont elle est le jumeau. ⚠️ **Clé SÉPARÉE, et c'est le mécanisme** : le cycle LAN est
    cadencé à `INTERVALLE_CYCLE_LAN` = **900 s FIXES**, délibérément **découplées de `refresh_interval`** —
    un utilisateur réglé à 1 min transformerait sinon `plugin::cron` (partagé par tous les plugins) en
    générateur de trafic UDP. Mutualiser les deux clés lierait deux cadences volontairement distinctes.
  - `smartclim::echecs_transport::<id>` (`CLE_CACHE_ECHECS_TRANSPORT`,
    `DUREE_MEMOIRE_ECHECS_TRANSPORT` = 24 h) — **compteurs de repli** de l'UC02 du domaine post-MVP 02 :
    `{preuve, lan, cloud, cloud_le}`, **par équipement** comme les deux premières (et non globale comme
    les trois suivantes), **non chiffrée** (4 entiers, aucune donnée d'origine backend). Écrite par la
    seule `smartclim`, aux 16 points listés au § 5.3 de sa spec technique ; lue par
    `smartclimTransport::lanJoignable()`/`repliCloudActif()` via `echecsLanConsecutifs()`.
    ⚠️ **Clé par `getId()`, JAMAIS par MAC** : les 5 écrivains de `lan_appareil::<mac>` ne s'accordent pas
    sur la MAC utilisée (attendue, trouvée, ou **inversée**) — un compteur y serait incrémenté sous une
    clé et remis à zéro sous une autre.
    ⚠️ **Elle est délibérément DISJOINTE de `lan_appareil::<mac>`** : aucun code ne lit l'une pour écrire
    l'autre, et il n'existe **aucun point de co-écriture**. Une co-écriture n'aurait couvert que les
    désynchronisations inoffensives, jamais la seule dangereuse (une `preuve` posée sans
    `STATUT_ETAT_LU`) — celle-là est rendue **inatteignable** par l'aiguillage `noterOperationLan()`, seul
    chemin des sites de lecture vers `memoriserSuccesLan()`.
  - `smartclim::appareil_auxcloud::<id>` (`CLE_CACHE_APPAREIL_AUXCLOUD`,
    `DUREE_MEMOIRE_APPAREIL_AUXCLOUD` = **1800 s**) — **jetons d'appairage legacy** de l'UC02 du domaine
    post-MVP 03 : `{cookie, dev_session, cree_le}`, **par équipement** et **chiffrée** (`utils::encrypt`),
    contrairement aux deux précédentes — le `cookie` est un base64 portant une **clé AES d'appairage**.
    Clé par `getId()`, **jamais** par MAC ni par `endpointId`, même leçon que `echecs_transport`.
    ⚠️ Elle a **enfin un lecteur depuis l'UC03 du même domaine** (`appareilAuxCloud()`), et elle est le
    plus souvent **expirée** à son premier usage réel : l'usage est « scan le matin, commande le soir ».
    Contrat honoré : son absence est le **cas nominal**, et les jetons sont re-obtenus par un `dev/query`
    ciblé sur `auxcloud_family_id`. ⚠️ Ne pas la rapprocher de `session_auxcloud` : une session se
    régénère seule par `login()`, ces jetons non — **d'où sa purge sur preuve de refus** (UC03) : sa TTL
    est **absolue et n'est jamais raccourcie par un échec**, donc sans purge explicite un jeton rejeté par
    le backend ferait échouer lecture et commande **à l'identique pendant 30 min**. Le rejeu d'appairage
    qui la purge vit dans `smartclim`, **jamais dans le transport** (il lui faut `auxcloud_family_id` et
    `getId()`), et **ni `etatMarcheCourant()` ni les trois sondes ne le portent** : la première est déjà
    dans le rejeu de son appelant (double purge sinon), les secondes sont des instruments de mesure et
    doivent rendre le comportement **brut** du backend.
  ⚠️ L'**état optimiste** poussé après succès est celui **réellement envoyé** (après quantification), pas
  celui demandé par l'utilisateur.

  Depuis l'UC07, elle porte enfin le **cadencement** : `cron()` — **seul hook cron implémenté**, appelé
  chaque minute par le core, `cron5()`…`cronDaily()` restant **commentées donc inexistantes** — ouvre par
  la garde d'échéance `cycleEchu()` (cache `smartclim::dernier_cycle`, marge de 30 s) puis appelle
  `rafraichirAuxHome()` ; ⚠️ **elle porte TROIS cycles** — le LAN depuis l'UC01 du domaine post-MVP 02,
  le **cloud legacy** depuis l'UC03 du domaine post-MVP 03 (`rafraichirAuxCloud()`,
  `INTERVALLE_CYCLE_AUXCLOUD` = **900 s fixes**, marqueur `smartclim::dernier_cycle_auxcloud` **séparé**
  de celui du LAN, budget 20 s à arrêt dur, **groupé par `auxcloud_family_id`** donc O(familles) et non
  O(N)) —, chacun avec sa **propre** garde d'échéance et son **propre** `try/catch (Throwable)` — jamais
  de `return` dans un bloc, qui court-circuiterait les suivants (le cycle cloud n'étant presque jamais
  échu à un tick donné, les cycles LAN et legacy ne tourneraient **jamais**) : **un seul**
  `listerAppareils()`, puis distribution via
  `equipementsParIdentifiant()` et `appliquerEtat()`, et `basculerHorsLigne()` sur les équipements dont
  l'appareil n'a pas été renvoyé. La commande d'action `refresh` (`CMD_RAFRAICHIR`, libellé
  « Rafraîchir ») déclenche le **même cycle complet** via `rafraichirMaintenant()`.
  ⚠️ **Un seul hook, et c'est un arbitrage, pas une simplification** : `cron5()` ne peut structurellement
  pas honorer un intervalle réglé sur 1 minute, et deux hooks exposeraient deux interrupteurs core
  (`functionality::cron::enable`, `functionality::cron5::enable`) désynchronisables — donc un risque de
  double exécution. Détail : `.memory/analyse/smartclim-architecture-jeedom.md` § 6.
  ⚠️ **Le marqueur d'échéance est posé AVANT l'appel réseau**, et après la garde `compteConfigure()` :
  sinon un cloud en panne serait re-sollicité chaque minute dans le processus `plugin::cron`, qui exécute
  séquentiellement les crons de **tous** les plugins. C'est aussi ce qui tient lieu de sérialisation entre
  cycle automatique et « Rafraîchir » — il n'y a **volontairement aucun verrou**, un verrou dégraderait
  l'exigence de mise à jour immédiate du bouton.
  ⚠️ **Le cycle est en LECTURE D'ÉTAT SEULE** : il ne touche jamais les capacités et n'émet aucun `save()`
  d'équipement, alors que la réponse contient bien `capacites_brutes`. Le vecteur de migration du parc
  reste le **scan** (UC03). Un `appliquerCapacites()` ici produirait une écriture SQL par cycle.
  ⚠️ **`rafraichirAuxHome()` ne lève JAMAIS** — `try/catch (Throwable)` global, plus un `try/catch` par
  équipement dans la distribution (un climatiseur en erreur n'interrompt pas la boucle). Un échec interne
  se signale par `echecType` dans le tableau de retour, **jamais** par une exception : c'est ce qui évite
  qu'un clic sur « Rafraîchir » rende un succès silencieux. `setStatus()` n'est **pas** utilisé — la
  commande info `online` est le seul porteur de l'état de joignabilité.

  Depuis l'UC04 du domaine post-MVP 01, elle porte enfin la **fusion multi-transport** : le rapprochement
  unique `chercherEquipementExistant()` (cf. § Modèle de données), `memoriserMacEquipement()`,
  `creerEquipement()` rendue **neutre de transport** (elle sert les deux sens de scan), et la synthèse
  d'affichage `lignesFusionScan()` — une ligne par climatiseur : LAN oui/non, cloud oui/non, transport
  actif. **`scannerReseauLocal()` peut désormais CRÉER un équipement** depuis la seule découverte LAN,
  conditionné à la preuve `STATUT_ETAT_LU`.
  ⚠️ **Ce garde-fou est plus faible qu'il n'en a l'air** : `conceptsLisibles()` ne teste que des
  **longueurs** (≥ 13 octets), **jamais** le magic `bb00`. Il vaut « un appareil Broadlink a répondu à
  `0x6A` avec un code d'erreur nul et une charge exploitable » — rien de plus. Arbitré avec l'utilisateur
  le 2026-09-03 en connaissance de ce risque (conséquence d'un faux positif bornée : un équipement de
  trop, supprimable). Le durcissement est en dette, cf. la spec technique d'UC04 § 12.2.
  ⚠️ **Un équipement créé par le LAN n'entre PAS dans le cycle cron** : `equipementsParIdentifiant()`
  n'indexe que par `auxhome_device_id`. Voulu — il n'est donc jamais basculé `online = false` par un cycle
  auquel il n'appartient pas.
  ⚠️ **Depuis l'UC01 du domaine post-MVP 02, ses commandes d'action fonctionnent** : `transportRetenu()`
  l'aiguille vers le LAN (aucun `auxhome_device_id`, donc `cloudDisponible()` faux). Il reste en revanche
  hors du **cycle de lecture cloud** — c'est le **cycle LAN** de 15 min qui lui rend son état.
  ⚠️ **`appareilsDisparus()` ne teste plus que `auxhome_device_id`**, et **plus** la MAC : un équipement
  créé par le LAN en porte une sans avoir jamais existé sur le compte cloud — l'ancien critère l'aurait
  signalé « introuvable au dernier scan » à chaque cycle, indéfiniment.

  Depuis l'UC03 du domaine post-MVP 04, `definitionsCommandesAction()` porte une colonne **`'confirmation'`**,
  consommée **uniquement à la création** par `creerCommandesAction()`
  (`setConfiguration('actionConfirm', 1)` → dialogue de confirmation **natif du core**, `-32006` levé par
  `core/ajax/cmd.ajax.php` : zéro JS, zéro HTML, zéro clé i18n).
  ⚠️ **Ce n'est PAS une frontière d'autorisation** — un scénario, l'API JSON-RPC ou un `execCmd()` d'un
  autre plugin la contournent : c'est un **anti-fausse-manip**, et la seule garde reste la liste blanche
  de `definitionsCommandesAction()`. ⚠️ Elle se lit par **`!empty()`**, jamais par `isset()` : l'absence de
  la clé vaut `false`, ce qui laisse **intactes** toutes les définitions antérieures (dont les boucles
  dynamiques `mode_*` / `fan_*`) — ne pas « compléter » celles-ci par un `'confirmation' => false`.
- **`core/class/smartclimAuxHomeApi.class.php`** — brique du transport **AUX Home**, seul point cURL du
  transport **AUX Home**.
  ⚠️ **Ce n'est plus « le seul point cURL du plugin » depuis l'UC01 du domaine post-MVP 03** :
  `smartclimAuxCloudApi` porte le sien. La **règle** n'a pas changé — « tous les appels HTTP passent par la
  brique du transport concerné » —, c'est le **constat d'unicité** qui est devenu faux. Les deux clouds ne
  partagent presque rien au niveau HTTP (enveloppe JSON + `Authorization: bearer` d'un côté ; corps binaire
  chiffré + 9 en-têtes d'usurpation + sentinelle `status` de l'autre) : un helper commun serait un sac de
  paramètres. Ne pas « factoriser » les deux.
  Porte la liste des pays proposables `paysDisponibles()` (UC01, amendée en recette : plus
  aucune déduction depuis le fuseau horaire, cf. § Configuration & secrets), puis l'authentification
  complète (UC02) : `login()` (toujours frais — `getPubkey` + `login/pwd` — **et écrit** la session en
  cache), `session()` (**lit** le cache, sinon `login()`), `purgerSession()`, la crypto RSA/AES et les
  constantes de protocole embarquées (source + licence MIT citées en commentaire), et enfin la
  **découverte des appareils** (UC03) : `listerAppareils()` (`GET /app/user_device?getStatus=1`, budget
  de temps global `BUDGET_SCAN`, re-login réactif borné à **un** rejeu) qui renvoie des lignes
  **normalisées à clés génériques françaises** — aucun nom de champ AUX (`deviceId`, `alias`, `modelId`,
  `online`) n'en sort. Enfin, la **lecture d'état** (UC05) : `etatAppareil(array $_appareil)`.
  ⚠️ **Les offsets d'octets de la trame HVAC n'y vivent PLUS** depuis l'UC02 du domaine post-MVP 01 : ils
  ont migré dans `smartclimFrame` (second transport = second appelant), et `etatAppareil()` /
  `capacitesAppareil()` n'en sont plus que des **délégations** — signatures et clés de retour
  **inchangées**. Ne pas y réintroduire d'offset. Ce qui reste ici est ce qui relève du **cloud** :
  `nettoyerTrame()` (assainissement d'un champ backend) et la normalisation des appareils.
  Depuis le 2026-08-26, elle porte aussi la **restriction des capacités PAR APPAREIL** — ce que le
  profil UC04 ne savait pas faire, d'où un « Chauffage » proposé sur une unité froid-seul :
  `nettoyerCapacitesBrutes()` récolte le champ d'origine (JSON **imbriqué dans une chaîne**, entrées en
  couples `[valeur, drapeau]`) et l'expose sous la clé générique `capacites_brutes` — destination
  **exclusive** `capacitesAppareil()`, même statut que les trames ; `exclusionsAuxHome()` est la table
  `valeur observée => codes génériques NON supportés`.
  Depuis l'UC03 du domaine post-MVP 04, elle porte aussi `conceptsProtectionAuxHome()` — **voie d'entrée
  au profil** de `child_lock`, 3ᵉ terme de fusion de `capacitesAppareil()`, **AUX Home seul** (le LAN ne
  publie pas ce concept : il ne sait pas l'écrire, et cette asymétrie **est** le contrat). Elle vit ici,
  et pas dans `smartclimFrame`, parce qu'il n'y a **aucun offset** à consulter — c'est le pendant exact
  de `modesExclusAuxHome()`. ⚠️ Sa garde `empty(smartclimFrame::conceptsLisibles($trame, ''))` passe la
  **trame longue VIDE, délibérément**, pour la raison exacte décrite plus bas à `conceptsOscillables()`.
  ⚠️ **Exclusions, jamais inclusions, et ce sens ne s'inverse pas** : une exclusion s'appuie sur une
  preuve positive (valeur observée sur un appareil dont l'IHM constructeur masque la fonction) et tient
  donc avec un seul appareil de référence ; une inclusion exigerait de décoder la liste complète des
  capacités, ce qui n'est **pas** le cas (`feature.mode` déclare 5 modes là où l'application n'en propose
  que 4 — index de sens inconnu, ne rien construire dessus). Détail et preuves :
  `.memory/analyse/smartclim-transport-aux-home.md` § 3.3.
  ⚠️ **`nettoyerTexteExterne()` est la frontière d'assainissement du transport** : c'est elle, et elle
  seule, qui garantit qu'un champ du cloud (dont l'`identifiant` d'où dérive un `logicalId`) ne porte pas
  de caractère de contrôle. Toute nouvelle source d'appareil doit passer par un nettoyage équivalent
  **avant** de construire un `logicalId` ou d'être journalisée.
  ⚠️ Depuis l'UC04 du domaine post-MVP 01, elle retire aussi **`<` et `>`**, exactement comme son jumeau
  `smartclimBroadlinkLan::nettoyerNomExterne()` : `cleanComponanteName()` du core **n'est pas un filtre
  HTML** (il ne retire ni l'un ni l'autre), et un nom d'équipement finit dans du HTML rendu. ⚠️ Elles sont
  **TROIS** depuis l'UC02 du domaine post-MVP 03 (`smartclimAuxCloudApi::nettoyerTexteExterne()`), et les
  trois doivent rester **symétriques** — même jeu de caractères, même emplacement dans le pipeline
  (après la validation UTF-8, avant le `trim()`) : sinon un appareil vu par deux transports porterait
  deux noms différents selon le chemin de découverte. ⚠️ Ce filtrage est de la **défense en profondeur**,
  jamais la protection principale : l'échappement se fait **au point de sortie**
  (`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` dans `desktop/php/smartclim.php`). Détail :
  `.memory/analyse/jeedom-config-plugin-et-cycle-de-vie.md` § 12.
  ⚠️ **Budget de temps global** : un login enchaîne **deux** requêtes, donc les timeouts par requête ne
  suffisent pas à tenir une exigence exprimée en budget total — cf.
  `.memory/analyse/smartclim-transport-aux-home.md` § 8.3.
- **`core/class/smartclimException.class.php`** — **existe** depuis l'UC02 du MVP. Exception **typée** à
  4 types (`TYPE_RESEAU`, `TYPE_AUTH`, `TYPE_PROTOCOLE`, `TYPE_INTERNE`) + un `contexte` optionnel.
  ⚠️ **Deux usages distincts du message** : levée par une brique de transport, il est **technique** et
  n'est jamais affiché ; levée par `smartclim::`, il est **déjà curaté en français** et affiché tel quel.
  Le passage de l'un à l'autre se fait **exclusivement** par `smartclim::messageErreurAuxHome()`.
- **`core/class/smartclimCapabilities.class.php`** — **existe** depuis l'UC04 du MVP. **LA** table de
  correspondance du plugin, et rien d'autre : aucune E/S, aucun `config::`, aucun `eqLogic`. Porte les
  constantes génériques (`CONCEPT_*`, `MODE_*`, `VITESSE_*`), les bornes de température par défaut
  (16-32 °C, pas 0,5) et leur enveloppe personnalisable (5-35 °C), les libellés français `__()` et les
  accesseurs de lecture (`valeursLisibles()`, `versTransport()`, `depuisTransport()`, `libelle()`,
  `libelleConcept()`, `libelleTransport()`, `conceptsConnus()`).
  ⚠️ Depuis l'UC02 du domaine post-MVP 03 elle porte `TRANSPORT_AUX_CLOUD_LEGACY` et son libellé
  (« AUX Cloud (AC Freedom) », **nom de marque sans `__()`**), et **depuis l'UC03 du même domaine son
  entrée dans `tables()`** : la numérotation legacy (`ac_mode` : 0 COOL, 1 HEAT, 2 DRY, 3 FAN, 4 AUTO —
  **une troisième**, différente d'AUX Home *et* du LAN ; `ac_mark` : 0 AUTO, 1 LOW, 2 MEDIUM, 3 HIGH,
  4 TURBO, 5 MUTE), avec le pilotage qui la consomme. ⚠️ `MEDIUM_LOW` et `MEDIUM_HIGH` y portent
  `'intent' => null` **et** `'fil' => null` — aucun code `ac_mark` ne leur correspond : c'est un **fait de
  protocole**, pas une non-confirmation. Depuis la même UC, elle porte aussi `codesOscillation($_concept,
  $_transport)`, accesseur **additif** qui introduit la dimension transport **dans** la table plutôt qu'un
  second littéral ailleurs.
  ⚠️ **La colonne `'fil' => null` exclut une valeur au niveau du TRANSPORT** : une valeur sans
  correspondance de **lecture** vérifiée n'apparaît jamais dans l'interface plutôt que d'y figurer
  approximativement. ⚠️ Ce n'est plus le **seul** mécanisme d'exclusion depuis le 2026-08-26 : le second
  agit à l'échelle de l'**appareil** et vit dans le transport
  (`smartclimAuxHomeApi::exclusionsAuxHome()`, cf. ci-dessous). Les deux répondent à la même règle —
  on n'ampute que sur preuve. `versTransport()`/`depuisTransport()` renvoient `null` quand la
  correspondance manque — **jamais** de repli silencieux. Ajouter une capacité, c'est éditer cette
  table, pas ajouter un `switch`.
  Depuis l'UC01 du domaine post-MVP 04, elle porte aussi les **concepts booléens de confort** :
  `CONCEPT_DISPLAY`, `CONCEPT_SLEEP`, `CONCEPT_HEALTH`, `CONCEPT_CLEAN`, `CONCEPT_MILDEW`, la table
  `fonctionsConfort()` et ses accesseurs `conceptsConfort()` / `conceptsConfortLivres()` /
  `fonctionConfort()`.
  Depuis l'UC02 du même domaine, elle porte **symétriquement les concepts d'oscillation** :
  `CONCEPT_SWING_V`, `CONCEPT_SWING_H`, la table `fonctionsOscillation()` et ses accesseurs
  `conceptsOscillation()` / `conceptsOscillationLivres()` / `conceptsOscillationRelus()` /
  `fonctionOscillation()`, plus le gabarit **unique** `nomSuffixe()` (suffixe « (état commandé) »).
  Depuis l'UC03 du même domaine, elle porte enfin les **concepts de protection** : `CONCEPT_CHILD_LOCK`,
  la table `fonctionsProtection()` et ses accesseurs `conceptsProtection()` /
  `conceptsProtectionLivres()` / `fonctionProtection()`.
  ⚠️ **`fonctionsProtection()` est une table SÉPARÉE bien que ses colonnes soient identiques à celles de
  `fonctionsConfort()`**, et la raison est dirimante : `conceptsConfortLivres()` est consommée par
  `smartclimFrame`, où **chaque** concept de confort possède un bit dans `champsBinaires()` — y glisser un
  concept **sans aucun offset** ferait cesser cette table de signifier « fonction booléenne portée par un
  bit de trame ». `child_lock` est en effet la **première capacité du plugin sans aucun offset** : écriture
  connue (`electric_lock`), lecture **structurellement impossible**. D'où l'absence délibérée de colonne
  `lecture` — elle suggérerait qu'elle pourrait un jour passer à `true` — et un `libelleCommande()` qui
  **suffixe inconditionnellement** (le nom ne réalignera jamais, contrairement à l'oscillation).
  ⚠️⚠️ **SIX familles de marqueurs coexistent maintenant, et trois seulement sont LUES — ne pas les
  confondre** : `'fil' => null` est un **fait de protocole** (aucune correspondance de lecture n'existe) ;
  `intent_confirme`, dans `tables()`, est **déclaratif et jamais lu** (une note de traçabilité sur la
  solidité d'un code d'écriture) ; `'confirme'`, dans `fonctionsConfort()`, `fonctionsOscillation()` **et**
  `fonctionsProtection()`, est **effectivement lu et gouverne l'exposition** — ⚠️ celui de
  `fonctionsProtection()` étant d'une **nature à part** : il expose un concept qu'**aucun bit ne porte**,
  là où les deux autres s'adossent toujours à un offset identifié ; `'lecture'`, dans
  `fonctionsOscillation()` seule, est **lu et gouverne le DÉCODAGE**. Prendre le déclaratif pour un
  marqueur lu, c'est croire qu'on a livré une fonction qui n'apparaîtra jamais, ou l'inverse.
  ⚠️ **`confirme` et `lecture` sont INDÉPENDANTS, et c'est le mécanisme d'UC02** : `confirme` fait
  apparaître les commandes (écriture), `lecture` autorise `decoderEtat()` à produire la clé. Tant que
  `lecture => false`, la lecture ne peut **jamais** écraser l'état optimiste posé à l'écriture — c'est
  ainsi, et sans exception à l'invariant « une clé absente ne touche pas sa commande », qu'est tenue
  l'exigence d'afficher le dernier état **commandé** quand la relecture par axe n'est pas confirmée.
  Invariant à respecter : `lecture => true` **implique** `confirme => true`.
  ⚠️ **`conceptsConfortLivres()` est le point d'édition UNIQUE pour activer une fonction** (consommé par
  `conceptsConnus()`, `smartclimFrame::conceptsLisibles()`/`decoderEtat()` et
  `definitionsCommandesAction()`). Les cinq fonctions sont livrées à `false` : **rien n'apparaît** avant
  une mesure sur matériel — et c'est **irréversible dans l'autre sens**, `appliquerCapacites()` unionnant
  les `concepts` sans équivalent de `modes_exclus`. D'où l'ordre non négociable : mesurer, puis activer.
  ⚠️ **Pas de `CONCEPT_ECO` ni `CONCEPT_ULTRA_SILENCE`, et c'est le mécanisme** : sans bit de lecture
  connu, leur état ne pourrait pas être relu — les omettre les rend inatteignables par construction, pas
  seulement filtrées. Ne pas « compléter la table ».
- **`core/class/smartclimDiagnostic.class.php`** — **outillage de reverse engineering**, jamais sollicité
  par le plugin en fonctionnement : met en forme les rapports de **sonde de diagnostic** (masquage des
  identifiants par jetons stables, section « pistes », résumé, rendu texte). Aucune E/S, aucun réseau
  (délégué au transport), aucun `eqLogic`. Deux appelants — et c'est ce qui justifie une classe plutôt
  qu'un bout de script : le bouton **« Sonde de diagnostic »** de `desktop/php/smartclim.php`
  (action AJAX `sonderDiagnostic` → `smartclim::sonderDiagnostic()`) et la CLI
  `core/php/diagnostic-auxhome.php`. Les deux doivent rendre **exactement** le même rapport.
  Depuis l'UC01 du domaine post-MVP 04 s'y ajoute `texteTrameHvac()` — table octet/hex/binaire avec diff
  avant/après, pour la CLI `core/php/sonde-intent-auxhome.php`. ⚠️ Elle lit ses offsets dans
  `smartclimFrame::champsBinaires()`, **jamais en dur**, et n'applique **aucun masquage** : une trame HVAC
  n'est pas un secret, c'est la donnée utile. Elle affiche **tous** les octets, pas seulement ceux qu'on
  suppose porteurs — sans quoi un bit qui bascule ailleurs qu'attendu resterait invisible.
  Son rôle : trancher les « À confirmer » de contrat externe contre le matériel réel, le premier étant
  **où le backend expose les capacités d'un appareil donné** (cf.
  `.memory/analyse/smartclim-transport-aux-home.md` § 3.1 — le profil UC04 affiche aujourd'hui le
  catalogue du transport, donc « Chauffage » sur une unité froid-seul, contre l'objectif et l'AC6 d'UC04).
  ⚠️ **Ce qui rend la sonde exposable au web sans ouvrir un SSRF** : le **catalogue de routes est une
  donnée serveur** (`smartclimAuxHomeApi::routesDiagnostic()`), le navigateur n'envoie **aucun** chemin.
  Un chemin **libre** (ou un rapport non masqué) exige `php_sapi_name() === 'cli'`, et tout chemin passe
  en plus une liste blanche de forme. Ne jamais relâcher l'une de ces deux gardes.
  ⚠️ **Le masquage se fait en DEUX passes, et aucune n'est facultative** (incident du 2026-08-26) : par
  **nom de clé** (`$clesSensibles`) puis par **valeur** (`masquerParRessemblance()`). La première seule a
  laissé publier, sur ce dépôt **public**, l'identifiant et le mot de passe d'un climatiseur : le backend
  republiait `deviceId` sous `did`, la MAC sous `thirdDid` et le mot de passe sous `passcode`. Une liste
  de noms de clés ne peut pas suivre un backend tiers ; la seconde passe raisonne sur les valeurs (toute
  chaîne contenant une valeur déjà masquée, forme normalisée). ⚠️ Ne jamais anchorer du 12-hex nu dans
  cette passe : ce sont les **trames HVAC**, la donnée la plus utile du rapport.
  ⚠️ **Ne jamais committer un rapport de sonde brut**, ni dans `.memory/` : `.htaccess` ne protège que
  l'accès web d'une installation Jeedom, pas GitHub. Vérifier la sortie réelle (`grep` des champs
  d'identifiants) avant d'annoncer qu'un rapport est partageable.
- **`core/class/smartclimBroadlinkLan.class.php`** — **existe** depuis l'UC01 du domaine post-MVP 01.
  Brique du transport **Broadlink LAN**, seul point du plugin qui ouvre des **sockets UDP**. Porte la
  découverte par diffusion (`decouvrir()`, deux chemins : `socket_*` si l'extension `sockets` est là,
  sinon repli `stream_socket_server` avec l'option de contexte `so_broadcast`), la sonde **unicast**
  d'une adresse connue (`interroger()`, qui ne dépend d'**aucune** extension), et le cycle de
  **session par appareil** (`ouvrirSession()` / `purgerSession()`, authentification `0x65`,
  AES-128-CBC, en-tête `0x38`). Elle renvoie des lignes **normalisées à clés génériques françaises** —
  aucun offset ni nom de champ du protocole n'en sort.
  ⚠️ **`ouvrirSession()` ne lève JAMAIS** : tout échec devient un statut (`STATUT_*`) plus un log. Et
  elle ne renvoie **jamais** l'identifiant ni la clé de session.
  ⚠️ **Session sérialisée par `flock`** (fichier par MAC dans `jeedom::getTmpFolder('smartclim')`),
  jamais par un verrou en cache : `cache::byKey()` + `cache::set()` ne sont pas atomiques. Motif de
  fond — le protocole n'admet **qu'une seule session par appareil**, et s'authentifier **invalide**
  celle du logiciel qui l'avait avant (application du constructeur, Home Assistant…). Deux processus
  PHP concurrents non sérialisés se décrocheraient donc mutuellement en boucle.
  ⚠️ **La clé de session est stockée en HEXADÉCIMAL** dans le cache (`bin2hex()`), pas en octets
  bruts : `json_encode()` renvoie `false` sur toute chaîne non-UTF-8, ce qui rendait la session
  silencieusement illisible et forçait une ré-authentification à chaque scan. Tout appelant futur
  (la `requete()` de l'UC02) doit repasser par `hex2bin()`.
  Depuis l'UC02 du même domaine, elle porte aussi la **lecture d'état** : `requete()` (contrat figé au
  § 7 de la spec technique d'UC01, implémenté là), `lireEtat()`, `etatAppareil()`, `capacitesAppareil()`
  et `sessionEnCache()`. Depuis l'**UC03**, enfin, l'**écriture** : `appliquerOrdre()`,
  `encapsulerChargeHvac()` et `sommeChargeHvac()`.
  ⚠️ **`appliquerOrdre()` LÈVE, elle** — contrairement à `ouvrirSession()` et `lireEtat()` : c'est un
  chemin **interactif**, et un ordre perdu en silence est précisément ce que la spec interdit. Elle tient
  la **lecture de base ET l'écriture sous le MÊME verrou** (sinon un autre processus s'intercale et notre
  écriture réécrit un état déjà périmé), et refuse d'émettre s'il reste moins de `RESERVE_ECRITURE`
  secondes de budget : un ordre non envoyé vaut mieux qu'un ordre dont on ignore le sort.
  ⚠️ **`sommeChargeHvac()` (complément à un, mots 16 bits big-endian) est DISTINCTE de
  `sommeControle()`** (`0xBEAF`, paquet `0x38`) — deux fonctions, **jamais** fusionnées. Sur une longueur
  **impaire** (la charge d'écriture fait 23 octets), le dernier octet est traité comme **poids fort d'un
  mot complété par `0x00`**. Les quatre vecteurs de contrôle sont au § 2.4 de la spec technique d'UC03 :
  ⚠️ les deux premiers, de longueur paire, **n'exercent pas** ce cas — ce sont les vecteurs 3 et 4 qui
  discriminent poids fort et poids faible.
  ⚠️ **`lireEtat()` ne lève JAMAIS non plus** : comme `ouvrirSession()`, tout échec devient un statut.
  ⚠️ **`etatAppareil()` du LAN ne pose JAMAIS `online => false`** : un LAN muet ne prouve pas qu'un
  appareil est hors ligne (VLAN, pare-feu, diffusion filtrée) — seul le cloud sait le dire.
  ⚠️ **Le profil LAN publie `modes` et `vitesses` VIDES**, et ce n'est pas une paresse : le LAN n'a aucun
  équivalent de `feature.coolType`, donc il ne peut **rien exclure**. S'il publiait son catalogue complet,
  l'**union** de `appliquerCapacites()` réintroduirait « Chauffage » sur une unité froid-seul — la
  régression corrigée le 2026-08-26 — dès qu'un scan LAN tourne sans qu'un scan cloud repasse derrière.
  ⚠️ **`requete()` appelle `authentifier()` et JAMAIS `ouvrirSession()`** : le verrou est déjà tenu par
  l'appelant (`lireEtat()` ou `appliquerOrdre()`), et `flock` **n'est pas réentrant** entre deux
  descripteurs du même processus. Elle reçoit **16 octets en lecture, 32 en écriture** — `construirePaquet()`
  complète à un multiple de 16 quelle que soit la longueur d'entrée.
  ⚠️ **Son rejeu s'applique aussi à l'ÉCRITURE, et c'est volontaire** : réémettre le même ordre est sans
  danger, parce que la trame porte un état **absolu et complet** — l'écriture est **idempotente**. Ne pas
  « corriger » cela en désactivant le rejeu sur ce chemin. Son rejeu
  est borné à **un** par appel, par booléen local — jamais de récursion : le protocole n'admettant qu'une
  session par appareil, une rafale d'authentifications décrocherait en boucle l'application du
  constructeur.
  ⚠️ **Le compteur de paquet est PAR PROCESSUS, jamais persisté** : `cache::set()` réarmerait la TTL de
  30 min de la session à chaque lecture, faussant sa durée de vie réelle. `python-broadlink` initialise
  ce compteur aléatoirement — l'appareil n'en contrôle aucune monotonie.
  ⚠️ **Écho de MAC et sommes de charge sont journalisés, NON bloquants** sur une réponse `0x6A` : la
  source de référence ne les vérifie pas, et un contrôle **invérifiable** sur un chemin non recettable
  est un déni de service auto-infligé. Ce qui est bloquant : longueur de charge non multiple de 16, et
  champ `longueur` incohérent avec la charge reçue.
  ⚠️ Ce transport est livré **non recetté** : le climatiseur de validation de l'utilisateur ignore le
  protocole Broadlink. Le code est vérifié contre `mjg59/python-broadlink` (MIT), jamais contre du
  matériel.
- **`core/class/smartclimFrame.class.php`** — **existe** depuis l'UC02 du domaine post-MVP 01. **LE**
  décodeur de la trame HVAC, **partagé par les deux transports** — c'est ici, et nulle part ailleurs, que
  vivent désormais les offsets d'octets. Même statut que `smartclimCapabilities` : table de données pure,
  aucune E/S, aucun `cache::`, aucun `config::`, aucun `eqLogic`, aucun réseau. Elle ne connaît ni AUX
  Home ni Broadlink — elle reçoit deux trames en hexadécimal et un identifiant de transport. Porte
  `champs()` (concept → trame + index d'octet), `longueursMinimales()` (**une seule source d'offsets**,
  dérivée de `champs()` **et**, depuis l'UC01 du domaine post-MVP 04, de `champsBinaires()`), `octet()`,
  `conceptsLisibles()`, `decoderEtat()` et — depuis l'**UC04 du même
  domaine** — le prédicat pur `estTrameHvac()` (préfixe `MAGIC_TRAME_HVAC` = `bb00`).
  ⚠️ **`champs()` porte `'octets'` (PLURIEL, une liste d'indices) tandis que `champsBinaires()` et
  `champsOscillation()` portent `'octet'`
  (singulier)** : `longueursMinimales()` les fusionne en **trois boucles distinctes**. Confondre les deux
  schémas casse silencieusement le calcul de longueur, donc les garde-fous de trame courte.
  Depuis l'UC01 du domaine post-MVP 04, elle porte les **bits des fonctions de confort** :
  `champsBinaires()` (publique — second appelant `smartclimDiagnostic::texteTrameHvac()`), et les cinq
  lignes `'binaire' => true` de `champsEcriture()`.
  ⚠️ **`encoderOrdre()` court-circuite `versTransport()` pour une ligne `'binaire'`** : ces concepts sont
  absents de `tables()`, donc `versTransport()` renverrait `null` et **chaque** commande LAN lèverait
  `TYPE_INTERNE`.
  ⚠️ **Des octets sont PARTAGÉS entre les QUATRE tables** — `champs()`, `champsBinaires()`,
  `champsEcriture()` et, depuis l'UC02 du domaine post-MVP 04, `champsOscillation()` : octet 15 (mode +
  sommeil), octet 18 (marche + ioniseur + nettoyage), et **octet 10 (consigne bits 7-3 `0xF8` +
  `swing_v` bits 2-0 `0x07`)**. D'où le commentaire croisé que les quatre portent. Écrire un octet entier
  au lieu de masquer casserait deux concepts d'un coup. ⚠️ La commutativité consigne/`swing_v` sur
  l'octet 10 tient parce qu'`encoderConsigne()` fait `($octet10 & 0x07) | …` : elle **préserve** les bits
  de l'oscillation. À re-vérifier si elle est un jour retouchée.
  ⚠️ **`conceptsLisibles()` et `decoderEtat()` filtrent les concepts de confort** par
  `smartclimCapabilities::conceptsConfortLivres()` — dépendance de ce décodeur vers la table de
  capacités, assumée pour ne pas dupliquer le filtre dans les deux `capacitesAppareil()`, où il
  divergerait. `decoderEtat()` filtre de même les oscillations par `conceptsOscillationRelus()`.
  ⚠️⚠️ **Les oscillations n'entrent PAS au profil par `conceptsLisibles()`, mais par la voie séparée
  `conceptsOscillables()`** (UC02 du domaine post-MVP 04) — et ce n'est pas un doublon : `conceptsLisibles()`
  produit `STATUT_ETAT_LU`, **seul garde-fou de la création d'équipement depuis le LAN**, et y ajouter les
  oscillations abaisserait son seuil de 13 à 11 octets. `conceptsOscillables()` ouvre donc par la garde
  `empty(conceptsLisibles($_trameControle, ''))` — ⚠️ **trame longue passée VIDE, délibérément** : sans
  cela, une température ambiante lisible (seuil 16 octets) compenserait une trame de contrôle **courte**,
  et un concept entrerait dans un profil sur une trame qui ne le porte pas. L'entrée d'un concept dans un
  profil est **irréversible** (`appliquerCapacites()` unionne, sans équivalent de `modes_exclus`).
  ⚠️ Leçon générale : réutiliser un prédicat existant comme garde ne suffit pas — vérifier **sur quelles
  entrées** il se prononce.
  ⚠️ `estTrameHvac()` n'est **qu'un signal de journalisation** pour le transport appelant, **jamais** un
  critère bloquant : le préfixe est établi côté cloud et par les magics de lecture, mais **jamais observé
  sur une réponse LAN réelle**. Le rendre bloquant rendrait le chemin LAN inopérant **en silence**. Depuis
  l'**UC03 du même
  domaine**, elle porte **symétriquement l'encodage d'écriture** : `enteteEcriture()`,
  `champsEcriture()`, `conceptsEncodables()`, `encoderConsigne()` et `encoderOrdre()`.
  ⚠️ **« Ne lève jamais » ne vaut que pour le DÉCODAGE** : `encoderOrdre()` lève, elle, une
  `smartclimException` typée avec un contexte dédié.
  ⚠️ **L'ÉCRITURE PORTE UN ÉTAT COMPLET, JAMAIS UN DELTA — un champ absent vaut 0, donc l'appareil
  s'éteint tout seul.** D'où la règle qui commande toute la conception d'`encoderOrdre()` : on **recopie
  la trame que l'appareil vient de renvoyer** et on ne patche que les bits des concepts visés. La fusion
  se fait **au niveau des OCTETS, jamais des paramètres décodés** — c'est ce qui fait traverser intacts
  les champs que le plugin ne sait même pas lire (oscillations, veille, santé, afficheur…). Une fusion
  par paramètres perdrait tout ce qu'elle ne décode pas : c'est le défaut de l'implémentation de
  référence, et la cause directe des retours en arrière qu'elle produit.
  ⚠️ **Le marqueur `0x0F` de l'octet 12 est FORCÉ inconditionnellement** : sans lui, l'appareil **ignore
  silencieusement** la commande. Et les octets 0-9 sont **posés** depuis `enteteEcriture()`, jamais
  recopiés de la réponse lue.
  ⚠️ **Les octets 13/15/18 (vitesse, mode, marche) sont PARTAGÉS** entre `champs()` (lecture) et
  `champsEcriture()` — les deux tables portent un commentaire croisé : toute modification de l'une se
  vérifie dans l'autre.
  ⚠️ **Le bit turbo (octet 14, bit 6) est DÉRIVÉ de `fan_speed` à CHAQUE commande de vitesse** — posé
  pour `VITESSE_TURBO`, **effacé** pour toute autre : sans l'effacement, commander « Faible » sur un
  appareil en turbo enverrait « vitesse = 3 **et** turbo = 1 », deux informations contradictoires. Le bit
  `mute` voisin, lui, est **recopié** (aucun concept générique ne le porte). Dissymétrie délibérée.
  ⚠️ **C'est ce partage, et lui seul, qui rend l'AC3 d'UC02 vrai PAR CONSTRUCTION** (« l'état affiché par
  le LAN et par le cloud est identique ») : les deux `etatAppareil()` appellent la **même**
  `decoderEtat()`. Y glisser une amélioration qui ne vaut que pour un transport casse cette propriété —
  et modifie au passage le chemin cloud, le seul qui soit réellement recetté.
  ⚠️ **`decoderEtat()` ne pose NI `online` NI `source`** : la joignabilité et l'identité du transport sont
  l'affaire de l'appelant, pas de la trame. Et `conceptsLisibles()` n'inclut jamais `CONCEPT_ONLINE`.
- **`core/class/smartclimDemon.class.php`** — **existe** depuis l'UC02 du domaine post-MVP 05. Toute la
  mécanique du **démon Python** et du **pont** : port (constante `PORT_DEMON` = 55112 + clé de config
  `demon_port` **sans champ de formulaire**), chemins, détection d'état, lancement/arrêt, ping/pong — et
  **seul point du plugin qui ouvre un socket vers le démon** (`envoyer()`, en `stream_socket_client` :
  l'extension `sockets` n'est pas garantie, cf. `smartclimBroadlinkLan`). Aucun `eqLogic`, aucune commande,
  aucun accès AUX/LAN.
  ⚠️ Les trois hooks `smartclim::deamon_info()` / `deamon_start()` / `deamon_stop()` vivent
  **obligatoirement** dans `smartclim.class.php` — le core les appelle en **statique sur la classe
  principale** — mais ce ne sont que des **délégations** vers cette classe. `deamon_start()` est déclarée
  **sans paramètre** : le core résout sa signature par `ReflectionMethod` et ne transmet `$_auto` que si la
  méthode déclare au moins un paramètre obligatoire.
  ⚠️⚠️ **`etat()` ne lève JAMAIS, et c'est structurel** : `plugin::deamon_info()` n'est **pas** entourée
  d'un `try/catch` par le core (contrairement à `deamon_start`/`deamon_stop`), donc un jet ici casserait la
  tâche cron `plugin::checkDeamon` **de tous les plugins** du Jeedom. Elle ne fait pour la même raison
  **aucun appel shell sur le chemin nominal** (elle est appelée toutes les 5 min **et** à chaque affichage
  du panneau) : la sonde `system::ps()` est un **repli**, jamais le chemin principal.
  ⚠️ **`etat()` ne renvoie que `log`/`state`/`launchable`/`launchable_message`** — jamais la commande de
  lancement, ni la clé d'API, ni un chemin de venv : `setIsEnable(1)` journalise
  `json_encode(deamon_info())` **en clair**, côté core.
  ⚠️ Le repli par sonde `ps` n'est pas un confort de diagnostic : `plugin::deamon_stop()` ne délègue que si
  `state == 'ok'`, et `state` dérive du fichier PID — or `systemd-tmpfiles-clean` purge `/tmp` des fichiers
  non touchés depuis **10 jours**. Sans ce repli, un démon vivant depuis plus longtemps devient un
  **orphelin** que rien ne sait plus arrêter.
- **`core/php/jeeSmartclim.php`** — **existe** depuis l'UC02 du domaine post-MVP 05. Point d'entrée du
  **rappel HTTP du démon vers Jeedom** (sens démon → PHP du pont), gardé par
  `jeedom::apiAccess(init('apikey'), 'smartclim')` — **pas** par `isConnect` : il est appelé par un
  processus, pas par un navigateur authentifié. ⚠️ Il est le **seul** fichier mis en liste blanche dans
  `core/php/.htaccess` (bloc `<Files>`, sur le modèle de `plugin_info/.htaccess`) : sans cela, le
  `Deny from all` du dossier rendrait un **403** et le démon s'arrêterait au démarrage. ⚠️ Il renvoie
  **401** sur clé invalide, là où le patron officiel renvoie 200 : `jeedom_com.test()` traite tout code
  ≠ 200 comme fatal, donc une clé fausse se voit **au démarrage** au lieu de dégénérer en pont muet.
  ⚠️ Pas de `session_write_close()` ici, contrairement à `core/ajax/smartclim.ajax.php` : ce point d'entrée
  n'inclut pas `authentification`, aucune session n'est ouverte.
  ⚠️⚠️ **Depuis l'UC03 du domaine post-MVP 05, il porte QUATRE branches, et ce sont des `if`
  INDÉPENDANTS — jamais `elseif`** (`pont.pong`, `auxcloud.push`, `auxcloud.relais`, `pont.demarre`). Le
  thread de battement et le thread de push du démon écrivent tous deux dans `add_changes()`, fusionnés par
  `merge_dict()` avant le POST du cycle de 0,5 s : **un même corps peut légitimement porter plusieurs clés
  de premier niveau**, et un `elseif` en perdrait une **sans aucune trace**. Le style naturel de
  continuation est ici précisément le piège.
  ⚠️ Il reste **sans aucun appel réseau et sans écriture de configuration** : il valide, écrit un cache ou
  applique un état, et rend la main.
- **`core/php/pont-demon.php`** — **existe** depuis l'UC02 du domaine post-MVP 05. **4ᵉ CLI** du plugin
  (même moule que les trois autres : garde `php_sapi_name() === 'cli'` **avant tout `require_once`**, aucun
  POST, aucune écriture en base, sorties FR **sans `__()`**). `--etat` affiche l'état sans rien émettre ;
  `--ping [--attente=<s>]` fait l'**aller-retour complet du pont** et sort en code 0/1. C'est
  l'instrument qui démontre le pont dans les deux sens — retenu contre un bouton de page pour ne pas
  ajouter de chaîne UI ni de surface web à un outil d'infrastructure.
  Depuis l'UC03 du même domaine s'y ajoutent **deux usages pour le relais temps réel** : `--relais`
  (rapport d'état interne — battement, abonnés, confirmés, cadence effective par équipement — **sans
  aucune émission**, patron de `--transport` de `commande-lan.php`) et `--relais-sync` (**force la
  synchro**, donc **émet** : login et éventuels `dev/query`). ⚠️ Les deux passent par
  `smartclim::diagnosticRelaisAuxCloud()`, qui n'expose que des **comptes**, jamais les listes
  d'identifiants d'endpoint ni l'empreinte de session.
  ⚠️ À lancer sous `www-data` (`sudo -u www-data php …`) : le pong est écrit par le processus Apache dans
  le cache, et la CLI le relit. Vaut aussi pour les trois CLI existantes.
- **`core/php/commande-auxcloud.php`** — **existe** depuis l'UC03 du domaine post-MVP 03. **5ᵉ et dernière
  CLI** du plugin, même moule que les quatre autres (garde `php_sapi_name() === 'cli'` **avant tout
  `require_once`**, aucun POST, aucune écriture en base ni sur disque, sorties FR **sans `__()`**).
  Cinq usages : `--lister`, `--etat`, `--commande=<logicalId> [--valeur=]`, `--parametre=<clé> --valeur=<n>`
  et `--special` (le seul moyen de trancher factuellement l'utilité du second `get`).
  ⚠️ **C'est un AIGUILLAGE, sans aucune logique métier** — comme `commande-lan.php` : il ne construit
  **jamais** de map de concepts à la main, il passe par `ordreDeCommandeAction()`. C'est cela, et rien
  d'autre, qui garantit que la surface de commandes legacy est identique à celle du cloud et du LAN.
  ⚠️ **Il existe parce que ce transport est livré NON RECETTÉ** (aucun compte AC Freedom) : il est le seul
  instrument capable de fermer, par un rapport communautaire, la casse de `devicetypeflag`, le sens des
  oscillations, l'utilité du second `get` et l'acceptation d'un `temp` impair. Même raison d'être que
  `sonde-intent-auxhome.php`.
  ⚠️ `--parametre` accepte une **clé brute** : sa forme est validée **deux fois** (dans le script pour un
  message utilisable, dans `smartclimAuxCloudApi::sonderParametre()` comme barrière) — défense en
  profondeur, ne supprimer ni l'une ni l'autre comme « redondante ».
- **`core/class/smartclimTransport.class.php`** — **existe** depuis l'UC01 du domaine post-MVP 02. Couche
  de **décision de transport**, et rien d'autre : même statut que `smartclimCapabilities` et
  `smartclimFrame` — aucune E/S, aucun socket, aucun cURL, aucune écriture de cache, aucun `config::save`,
  aucun `save()`. Porte les trois modes (`MODE_AUTO`/`MODE_LOCAL`/`MODE_CLOUD`, clé d'équipement
  `transport_mode`), leurs libellés, `normaliserMode()`, les prédicats `lanJoignable()` /
  `cloudDisponible()`, l'arbitre `transportRetenu()` et les deux filtres `lectureCloudAutorisee()` /
  `sondeLanAutorisee()`.
  Depuis l'UC03 du domaine post-MVP 03 s'y ajoutent `cloudLegacyDisponible()` (pendant exact de
  `cloudDisponible()` : compte legacy configuré **et** `auxcloud_endpoint_id` par équipement) et
  `lectureLegacyAutorisee()`, plus **deux branches** dans `transportRetenu()` — en `MODE_CLOUD` le legacy
  passe **après** AUX Home, en `MODE_AUTO` l'ordre est LAN → AUX Home → legacy → LAN.
  ⚠️ **Non-régression par construction** : sur un parc sans compte legacy ces deux branches sont
  **mortes**, le comportement est strictement identique.
  ⚠️ **`lectureLegacyAutorisee()` est PLUS STRICTE que `lectureCloudAutorisee()`, délibérément** : le cycle
  AUX Home est **une** requête pour tout le parc (exclure un équipement n'économise rien), le cycle legacy
  est **O(N)**. Un équipement vu par les deux clouds n'entre donc **jamais** dans le cycle legacy.
  ⚠️ `repliCloudActif()` et la temporisation restent adossées à `cloudDisponible()` (**AUX Home**) : un
  équipement legacy n'a **pas** de disjoncteur — dette assumée, cohérente avec D-MVP08-05.
  Depuis l'UC02 du même domaine, elle porte aussi la **politique de repli** — et rien que la politique,
  le stockage restant dans `smartclim` : `SEUIL_ECHECS_LAN` (**3**), `DELAI_BASE_CLOUD` (30 s),
  `DELAI_MAX_CLOUD` (300 s), le prédicat `repliCloudActif()` et la fonction pure
  `delaiTemporisationCloud()`.
  ⚠️ **`DELAI_MAX_CLOUD` plafonne une FRÉQUENCE, jamais un nombre d'essais** : la temporisation est un
  **disjoncteur à demi-ouverture** (passé le délai, une tentative passe), pas un verrou — un abandon
  définitif rendrait l'équipement impilotable. Et c'est un **refus instantané et daté**, jamais un
  `usleep()` : le chemin `core/ajax/cmd.ajax.php` est interactif et `plugin::cron` est partagé.
  ⚠️ **`repliCloudActif()` exige `cloudDisponible()`** : un équipement sans identifiant cloud n'est jamais
  « en repli », il est **bloqué en LAN** — l'IHM ne doit pas appeler cela un repli.
  ⚠️ **`lanJoignable()` ne fait AUCUN appel réseau** : elle lit la mémoire de sonde
  `smartclim::lan_appareil::<mac>` et exige `STATUT_ETAT_LU` — critère **plus strict** que
  `statutEnEchec()`, qui compte `ETABLIE`/`REUTILISEE`/`ETAT_ILLISIBLE` comme des succès alors qu'aucun des
  trois ne prouve que l'appareil parle le HVAC. Une sonde paresseuse au moment de la commande ajouterait un
  timeout UDP à un chemin cloud qui n'aboutira jamais sur un parc sans Broadlink.
  ⚠️⚠️ **Depuis l'UC02 elle a une 4ᵉ étape qui INVERSE l'intuition** — `return $echecs > 0`, donc **vrai
  quand il y a des échecs** : c'est la série en cours (< seuil) qui maintient l'équipement en LAN, pour ne
  pas basculer au premier accroc. Elle n'est sûre **que** parce que `memoriserEchecLan()` refuse
  d'incrémenter tant qu'aucune preuve n'existe. **Invariant à ne jamais casser** :
  `0 < lan < SEUIL_ECHECS_LAN` **implique** qu'un `STATUT_ETAT_LU` a été constaté dans les 24 h — sans
  quoi un appareil qui n'a **jamais** parlé Broadlink (cas réel : une `lan_ip` saisie à la main sur un
  climatiseur non Broadlink) serait déclaré joignable.
  ⚠️ **`echecOperationLan()` (dans `smartclim`) n'est PAS `statutEnEchec()`** : la première répond « le LAN
  est-il utilisable pour piloter cet appareil ? » et compte `ETAT_ILLISIBLE` comme un **échec** ; la
  seconde répond « la communication a-t-elle abouti ? » et le compte comme un **succès**. Les substituer
  l'une à l'autre remet un compteur à zéro sur une lecture illisible — piège présent sur **deux** sites
  (`rafraichirLanEquipement()` et `rafraichirLan()`).
  ⚠️ **`transportRetenu()` ne renvoie JAMAIS de vide** : en AUTO sans LAN joignable **et** sans cloud
  disponible, elle replie sur le **LAN** — c'est ainsi, sans écrire un seul message, qu'un équipement sans
  identifiant cloud « se comporte comme LOCAL » au lieu d'afficher une erreur de configuration cloud.
  ⚠️ `cloudDisponible()` teste `compteConfigure()` (global au plugin) **ET** `auxhome_device_id` (par
  équipement) : le second terme est ce qui rend le test réellement par appareil.
- **`core/class/smartclimAuxCloudApi.class.php`** — **existe** depuis l'UC01 du domaine post-MVP 03. Brique
  du transport **AUX Cloud legacy / AC Freedom**, second point cURL du plugin (cf. `smartclimAuxHomeApi`
  ci-dessus). Cette UC n'en livre que l'**authentification** : table des 4 régions (`regions()`,
  `regionsDisponibles()`, `regionConnue()`, `hoteRegion()`), `login()`, `session()`, `purgerSession()`, la
  crypto (SHA1/MD5/AES-128-CBC), le classement d'erreurs et `journaliserErreurLegacy()`.
  **Depuis l'UC02 du même domaine, elle porte aussi la découverte** : `listerAppareils()` (familles →
  appareils propres **et** partagés → un `sdkcontrol get params:[]` par appareil), `capacitesAppareil()`,
  `etatAppareil()`, `parametresAuxCloud()`, `estClimatiseur()` et la
  **3ᵉ frontière d'assainissement** du plugin, `nettoyerTexteExterne()` — **strictement symétrique** de ses
  deux jumelles (cf. `smartclimAuxHomeApi`). Aucun `eqLogic`, aucune commande.
  **Depuis l'UC03 du même domaine, elle porte enfin la lecture ET l'écriture d'état** : `lireParametres()`,
  `appliquerOrdre()`, `decoderParametres()`, `jetonsAppareil()`, `sonderParametre()`, et le **point unique**
  `requeteSdkControl()` dont `requeteParametres()` est devenue un appelant. Le `temp`/`envtemp` legacy est
  en **dixièmes de degré** (×10), décodé sous une **garde de plausibilité** (50..500) qui rend une valeur
  fausse **structurellement inatteignable** : hors bornes, la clé est **absente** — jamais « 2,4 » ni
  « 240 ».
  ⚠️⚠️ **`sdkcontrol` n'a AUCUN `status`, à aucun niveau** — sa sentinelle est
  `event.header.name === 'Response'`, plus un `event.payload.data` qui est une **chaîne JSON à re-parser**.
  Troisième illustration de la règle « la sentinelle appartient à chaque appelant » : recopier celle d'une
  autre route transforme une erreur backend en **succès silencieux**.
  ⚠️ Sur `ErrorResponse`, journaliser `journaliserErreurLegacy('sdkcontrol', $donnees['event']['payload'])`
  — **le sous-tableau, jamais `$donnees`** : la fonction lit `message`/`msg` au **premier niveau**, la
  passer entière perdrait le message **en silence**. Et un `ErrorResponse` ne se classe **pas** en
  `TYPE_AUTH` : cela armerait un re-login inutile.
  ⚠️ **Le format du jeton d'appairage est FERMÉ** (`cookieMappe()`, livrée à l'UC02) : `base64` d'un JSON
  compact `{"device":{id, key, devSession, aeskey, did, pid, mac}}`, concordance stricte de deux
  références. Ne pas le « corriger ». ⚠️ Reste un **écart non tranché** entre les deux sources sur la casse
  de `devicetypeflag` dans `devicePairedInfo` — la minuscule est implémentée, et c'est le **premier
  suspect** si tout `sdkcontrol` rend `ErrorResponse` en recette.
  ⚠️⚠️ **Les routes de découverte n'ont NI corps chiffré NI en-têtes `timestamp`/`token`** — seule
  `/account/login` les porte. Recopier l'enveloppe de `login()` ferait échouer **toute** la découverte.
  C'est le symétrique exact du piège `status == 0` ci-dessous : les invariants de ce transport ne sont pas
  uniformes d'une route à l'autre.
  ⚠️⚠️ **`requete()` ne valide plus qu'une enveloppe JSON — la sentinelle de statut appartient à CHAQUE
  appelant**, et c'est structurel : `querystate` porte son statut sous `event.payload.status`, `sdkcontrol`
  n'en a aucun (il s'identifie par `event.header.name`). L'exigence d'un `status` de premier niveau, héritée
  de l'UC01, faisait échouer ces deux routes **à chaque appel**. Une 7ᵉ route ajoutée sans sa sentinelle
  transformerait donc une erreur backend en **succès silencieux**. Même doctrine qu'AUX Home, où le
  classement des codes métier appartient à `classerCodeMetier()`, jamais à la fonction de requête.
  ⚠️ **Le transport ne FILTRE aucun appareil** : il renvoie tout, avec `preuve_climatiseur` (fait) et
  `motif_exclusion` (`''` / `'pompe_a_chaleur'` / `'budget_epuise'`). La **création** est décidée par
  `smartclim::scannerAuxCloud()`, qui n'exige la preuve que si `chercherEquipementExistant()` n'a rien
  trouvé — un appareil déjà rapproché pose ses clés et son `online` **sans preuve**. Le motif
  `'non_climatiseur'` n'est **jamais** posé par le transport : c'est une décision, pas un fait de protocole.
  ⚠️ **Le `productId` inconnu se journalise HORS de `journaliserErreurLegacy()`** : 32 caractères
  hexadécimaux, il serait masqué en `[hex]` par la passe de neutralisation, et l'exigence de traçabilité
  deviendrait invérifiable **sans aucune erreur visible**.
  ⚠️ **Le profil LAN et le profil legacy publient tous deux `modes`/`vitesses` VIDES**, pour la raison
  identique : le jeu de paramètres dit que `ac_mode` existe, jamais **quelles valeurs** l'appareil accepte —
  aucun équivalent de `feature.coolType`, donc **rien à exclure**. Publier le catalogue réintroduirait
  « Chauffage » sur une unité froid-seul par l'union de `appliquerCapacites()`.
  ⚠️⚠️ **La sentinelle de succès de ce backend est `status == 0`, PAS `code == 200`** comme AUX Home. Un
  `!== 200` recopié depuis le transport jumeau ferait échouer **tous** les logins **et** classerait l'échec
  en `TYPE_AUTH` — donc afficherait « vérifiez vos identifiants » sur des identifiants parfaitement valides.
  ⚠️ **L'invariant central du protocole n'est pas un format, c'est une identité** : la chaîne d'horodatage
  doit être **identique octet à octet** entre l'en-tête `timestamp` et la graine du MD5 de la clé AES — les
  deux implémentations de référence produisent des formats **incompatibles** et s'authentifient toutes les
  deux, ce qui prouve que le backend ne reparse jamais cette chaîne en nombre. D'où `horodatageGraine()`,
  appelée **une seule fois** par login, et construite **sans aucune arithmétique flottante**
  (`explode(' ', microtime())`) : `(string) microtime(true)` dépend de `precision` et de `LC_NUMERIC`,
  `sprintf('%.3f')` est *locale aware*, et `microtime(true) * 1000` dépasse un entier 32 bits sur armhf.
  ⚠️ **`OPENSSL_ZERO_PADDING` signifie « ne pas remplir », pas « remplir de zéros »** : le zero padding du
  corps se fait **à la main avant** `openssl_encrypt()`, par `(16 - (len % 16)) % 16` — qui n'ajoute rien si
  la longueur est déjà alignée.
  ⚠️ **Le profil TLS est en dur et sans échappatoire** (`VERIFYPEER`/`VERIFYHOST`), et les erreurs cURL sont
  classées en **4 causes**, pas 3 : `{51,58,60,83,90,91}` → certificat (libellé « serveur **ou** magasin
  local »), `77` → **magasin de certificats local** (message propre : il ne dit rien du serveur distant),
  `35`/`59` → **injoignable** (ce sont des échecs de *négociation*, pas de validation). Le critère de
  regroupement « n'est pas un problème de DNS/route » a été **explicitement rejeté** en revue : un message
  affirmatif et faux est pire qu'un message générique honnête.
  ⚠️ Livrée **non recettée** (aucun compte legacy disponible), comme le transport Broadlink LAN : vérifiée
  contre deux références MIT recoupées, jamais contre le backend réel. Détail, preuves et dette :
  `.memory/specs/post-mvp/03-cloud-aux-legacy/01-client-legacy-authentification-tech.md`.
- **`core/ajax/smartclim.ajax.php`** — endpoint AJAX **admin** de la page de configuration : inclut le core,
  `isConnect('admin')`, `ajax::init()`, puis aiguille sur `init('action')` en branches
  `if (init('action') == '...')`. Pour un endpoint **non-admin** (widget de dashboard, page-panneau), créer
  un fichier AJAX **distinct** avec `isConnect()` + contrôle fin `hasRight('r')` par équipement.
  ⚠️ **Ce fichier est indenté en 4 espaces** (héritage du squelette, contrairement à la règle générale de
  2 espaces ci-dessous) — respecter l'existant.
  ⚠️ **`session_write_close()` juste après `ajax::init()`, avant tout appel réseau** : `ajax::init()` ne
  ferme pas la session, et Jeedom utilise des sessions **fichier** — un handler qui tient plusieurs
  secondes **fige toute l'interface**. Détail : `.memory/analyse/jeedom-config-plugin-et-cycle-de-vie.md`
  § 9.
  ⚠️ **Rattraper `Throwable` en dernier bloc** (après `smartclimException` puis `Exception`) : une `Error`
  PHP 8 traverse sinon `catch (Exception)` et la réponse cesse d'être du JSON. Message curaté, code
  **figé**, **jamais** `displayException()` sur une `smartclimException`. Idem § 10.
- **`core/php/smartclim.inc.php`** — ⚠️ **pièce critique** : la liste des `require_once` des classes
  annexes (+ constantes internes). Incluse en tête de `core/class/smartclim.class.php`, c'est elle
  qui rend les classes annexes chargeables — l'autoload du core ne le fait pas (cf. Conventions →
  Autoload Jeedom).
- **`core/php/diagnostic-auxhome.php`** — variante **ligne de commande** de la sonde de diagnostic (la voie
  normale est le bouton « Sonde de diagnostic » de la page du plugin, cf. `smartclimDiagnostic` ci-dessus).
  N'existe que pour les deux choses que la page ne peut pas faire, et que le transport **refuse hors CLI** :
  sonder des **chemins libres** passés en argument (suivre une piste ouverte par un premier rapport) et
  retirer le masquage (`--brut`). Aucun POST, aucune écriture en base.
  ⚠️ Ne jamais écrire un rapport dans le dossier du plugin : sa racine n'a **pas** de `.htaccess`, le
  fichier y serait téléchargeable **sans authentification** (même piège que `configuration.txt`).
- **`core/php/commande-lan.php`** — **existe** depuis l'UC03 du domaine post-MVP 01. Déclencheur **en
  ligne de commande** du pilotage local, calqué sur `diagnostic-auxhome.php` (garde
  `php_sapi_name() === 'cli'` **avant** tout `require_once`, aucun POST, aucune écriture en base ni sur
  disque). Deux usages : `--lister` (affiche les `logicalId` des commandes d'action, sans rien émettre sur
  le réseau) et `--commande=<logicalId> [--valeur=<consigne>]`.
  Depuis l'UC02 du domaine post-MVP 02 s'y ajoute un **3ᵉ usage en lecture seule**, `--transport` : par
  équipement, mode configuré, transport retenu, LAN joignable, échecs LAN consécutifs, âge de la preuve,
  repli actif, temporisation restante. Il n'émet **aucun paquet réseau** — c'est un rapport d'état interne,
  pas une sonde — et passe par `smartclim::diagnosticTransport()` (patron des autres CLI :
  `lireTrameAuxHome()`, `sonderIntentAuxHome()`), qui garde privés les accesseurs de la 6ᵉ mémoire.
  Depuis l'UC03 du même domaine s'y ajoute un **4ᵉ usage**, `--redecouvrir` (via
  `smartclim::diagnosticRedecouverteLan()`) : par équipement de mode ≠ CLOUD, MAC candidates, adresse connue
  et sa source, MAC/IP retrouvées, et un verdict parmi `aucune_correspondance` / `identique` / `changement` /
  `ambigu` / `deja_adopte`. ⚠️ **Il n'écrit rien (base, cache, disque) mais il ÉMET UNE DIFFUSION** — c'est
  la seule différence de nature avec `--transport`, qui n'émet aucun paquet ; ne pas les décrire comme
  équivalents. Il existe parce que le `brief.md` § 19 établit que **deux RM4 Pro répondent à la diffusion**
  chez l'utilisateur : c'est le premier instrument du domaine qui exerce réellement du code LAN sur du
  matériel, au lieu de le vérifier par lecture.
  ⚠️ **Il existe parce que tout le repli est du CODE MORT sur le matériel de recette** : le climatiseur de
  validation ignorant Broadlink, `repliCloudActif()` y est toujours faux. Sans cet instrument, la recette
  se limite à « rien n'a changé », et la dette D-2 d'UC02 (désynchronisation `preuve`/sonde) n'est
  détectable par aucun autre moyen.
  ⚠️ **C'est un AIGUILLAGE, sans aucune logique métier** : il ne construit **jamais** de map de concepts à
  la main. Il appelle `smartclim::envoyerCommandeActionLan()`, qui passe par la **même**
  `ordreDeCommandeAction()` que le chemin cloud — donc le même `power => 1`, la même quantification de
  consigne, la même liste blanche de `logicalId`. C'est **cela** qui garantit que la surface de commandes
  LAN est identique à celle du cloud ; réimplémenter la construction d'ordre ici la ferait diverger.
  ⚠️ **Depuis l'UC01 du domaine post-MVP 02, il n'est plus le seul chemin LAN** :
  `executerCommandeAction()` aiguille désormais selon `smartclimTransport::transportRetenu()`. Il subsiste
  comme **forçage LAN explicite**, y compris sur un équipement en mode CLOUD — c'est un **trou assumé** de
  l'AC5 de cette UC (« en CLOUD, aucun paquet LAN »), conservé parce qu'il est la seule façon de tester le
  LAN sur un équipement réglé en CLOUD, et qu'un outil CLI admin/SSH nommé pour ce qu'il fait n'est pas
  « une commande » au sens de l'AC.
- **`core/php/sonde-intent-auxhome.php`** — **existe** depuis l'UC01 du domaine post-MVP 04. Troisième et
  dernière CLI du plugin, calquée sur les deux précédentes (garde `php_sapi_name() === 'cli'` **avant**
  tout `require_once`, aucun POST, aucune écriture en base ni sur disque, sorties FR **sans `__()`**).
  C'est l'**instrument de mesure** des fonctions de confort : `--etat` (lecture seule),
  `--concept=<code>`, ou `--intent=<clé brute>` — il lit la trame, envoie l'ordre, attend, relit, et
  affiche le **diff octet par octet** via `smartclimDiagnostic::texteTrameHvac()`. Il appelle
  `smartclim::lireTrameAuxHome()` / `sonderIntentAuxHome()`.
  ⚠️ **Il existe parce que l'UC01 du domaine 04 est livrée DÉSACTIVÉE** : les cinq fonctions de confort
  portent `'confirme' => false` dans `smartclimCapabilities::fonctionsConfort()`, donc **aucune commande
  n'apparaît** avant qu'une mesure sur matériel ne fasse passer une ligne à `true`. Sans cet instrument,
  l'AC7 rendrait l'UC inactivable. Protocole au § 11 de sa spec technique.
  ⚠️ **Ces méthodes ne rendent JAMAIS la mémoire d'ordres ni le marqueur de déduplication** : un
  instrument de mesure doit rendre la lecture **brute**, sinon `filtrerEtatSelonOrdres()` confirmerait ce
  qu'on vient d'envoyer au lieu de ce que l'appareil a fait.
  ⚠️ `--intent` accepte une **clé brute** : sa forme est validée **deux fois** (dans le script pour un
  message utilisable, dans `smartclimAuxHomeApi::sonderIntent()` comme barrière) — défense en profondeur,
  ne supprimer ni l'une ni l'autre comme « redondante ».
- **`core/template/{dashboard,mobile}/cmd.<type>.<subType>.<nom>.html`** — widgets de commande
  personnalisés (dashboard + mobile = **deux fichiers synchronisés**). ⚠️ Le dossier s'appelle bien
  `core/template/` : c'est le **nom standard Jeedom** du dossier de widgets, il ne se renomme pas avec l'id
  du plugin. Un widget se pose sur une commande via `setTemplate('dashboard'|'mobile', 'smartclim::<nom>')`.
  Détail : `.memory/analyse/jeedom-widgets-commandes.md`.
- **`desktop/php/smartclim.php`** — page de configuration admin (HTML), protégée par `isConnect('admin')`.
  Liaison au modèle via `data-l1key`/`data-l2key`. i18n via `{{...}}`. Se termine en incluant le JS du
  plugin puis le JS générique de page plugin **fourni par le core**
  (`include_file('core', 'plugin.template', 'js')` → asset du core, **à ne pas renommer/modifier** : le
  `template` de cette ligne n'est pas l'ancien id du plugin).
  ⚠️ **Ces fichiers `desktop/php/*.php` sont indentés en tabulations + fins de ligne CRLF** (contrairement
  à `core/class/*.php` en 2 espaces) — cf. mémoire d'agent `feedback-edit-tool-tab-indented-files`.
  ⚠️⚠️ **Toute donnée d'origine externe rendue par un `echo` ici s'échappe** —
  `htmlspecialchars($valeur, ENT_QUOTES, 'UTF-8')`. Le squelette `jeedom/plugin-template` livrait
  `echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';` **sans échappement** :
  XSS stocké corrigé à l'UC04 du domaine post-MVP 01, exploitable **sans aucun identifiant** dès lors
  qu'un nom d'équipement pouvait venir d'une découverte LAN non authentifiée. ⚠️ Ne jamais se fier au
  filtrage d'entrée pour s'en dispenser : `cleanComponanteName()` ne retire **ni `<` ni `>`**. Détail :
  `.memory/analyse/jeedom-config-plugin-et-cycle-de-vie.md` § 12.
- **`desktop/js/smartclim.js`** — front-end (lignes de commandes, tri, helpers `jeedom.*`).
- **`desktop/modal/modal.smartclim.php`** — modale(s) de la page de config.
- **`desktop/php/<fichier>.php` déclaré par `info.json "display"`** — page-panneau optionnelle au **menu
  d'accueil** Jeedom (usage utilisateur, `isConnect()` non-admin). Prévue au domaine post-MVP 06. Détail :
  `.memory/analyse/jeedom-panel-page-menu.md`.
- **`plugin_info/configuration.php`** — formulaire de la page de config **plugin** (`gotoPluginConf`).
  Champs liés en `class="configKey" data-l1key="<clé>"` (auto-load/save core via
  `config::byKey/save(..., 'smartclim')`). Gardé par **`isConnect('admin')`**, comme les autres points
  d'entrée. ⚠️ Le champ **Pays** est une **liste déroulante** (`<select class="configKey">`) construite
  côté serveur : c'est **elle** qui porte `configKey`, jamais deux contrôles à la fois pour la même clé.
  Un champ texte annexe (masqué, **sans** `configKey`) sert de saisie d'appoint pour un pays hors liste et
  n'alimente que la `value` de l'option « Autre pays ». ⚠️ Une option doit **toujours** porter la valeur
  enregistrée, sinon le chargement AJAX du core (`.val()` sur une valeur sans option) laisserait la liste
  sur son premier item et **écraserait** le pays au premier enregistrement — même déclenché par un autre
  champ. ⚠️ Et **jamais de double accolade ouvrante littérale** dans ce fichier, pas même en commentaire :
  le core y voit un début de clé de traduction sur le HTML rendu et avale tout jusqu'à la fermeture
  suivante — la chaîne d'après cesse silencieusement d'être traduite. Cas particulier de la règle
  générale « aucune méta-séquence littérale » (cf. Conventions), qui couvre aussi `*/` et `?>`.
- **`core/config/smartclim.config.ini`** — **valeurs par défaut** de la config plugin, section
  `[smartclim]`. Mécanisme natif du core : `config::byKey()` **et** `config::byKeys()` y retombent quand
  la clé est absente ou vide en base. ⚠️ Corollaire à connaître : `config::save()` d'une valeur **égale**
  au défaut INI **supprime la ligne** en base et **court-circuite `preConfig_<clé>`** — d'où la règle de
  **double barrière** (normaliser à l'écriture *et* à la lecture) appliquée dans `smartclim`.

> ⚠️ **Accès restreint à `plugin_info/configuration.php`** — Claude Code **ne peut ni lire ni éditer**
> ce fichier via les outils Read/Edit/Write (refusé par les permissions de session), et même un
> `diff`/`md5sum`/`cat` Bash dessus est refusé. Une copie synchronisée **`plugin_info/configuration.txt`**
> sert de miroir éditable, et le `.php` est régénéré depuis le `.txt` par une simple copie :
> - **Lecture** : toujours lire `configuration.txt` (jamais `configuration.php`).
> - **Écriture** : modifier **uniquement** `configuration.txt` (outils Edit/Write). Le `.txt` est la
>   **source de vérité éditable** du formulaire de config.
> - **Synchronisation** : le `.php` étant la version réellement exécutée par Jeedom, **les deux fichiers
>   doivent rester identiques**. Après **chaque** modification du `.txt`, écraser le `.php` via bash :
>   ```bash
>   cp plugin_info/configuration.txt plugin_info/configuration.php
>   ```
>   La copie remplace intégralement le fichier (pas de fusion). Le `cp` **write** passe sans erreur ; ne
>   pas tenter de vérifier le résultat en **relisant** `configuration.php` (refusé) — utiliser
>   `git status --short plugin_info/configuration.php`. Cf. mémoire
>   `feedback-configuration-php-permission-scope`.

- **`plugin_info/info.json`** — manifeste (id, `name`, version — ⚠️ `pluginVersion` est bumpée
  automatiquement au commit, cf. « Workflows / CI » —, `require`, OS min/max, `category`,
  `hasDependency`, `hasOwnDeamon`, langues, `compatibility`, liens doc/forum). La `description` multilingue
  se met **dans `info.json`** (objet à clés de langue), pas dans les fichiers i18n (cf. i18n).
- **`plugin_info/install.php`** — `smartclim_install/update/remove()` ; `pre_install.php` →
  `smartclim_pre_update()`.
- **`plugin_info/packages.json`** — dépendances système/pip du démon (voir Démon & dépendances). Depuis
  l'UC03 du domaine post-MVP 05, il déclare **deux paquets** : `requests` **`2.31.0`** (UC02) et
  `websocket-client` **`1.6.1`** (UC03) — dans les **deux** cas un plancher contraint par `os.min: 10`
  (Debian 10 = Python 3.7 ; `websocket-client` 1.6.2 et au-delà exigent 3.8), **pas** un choix esthétique
  (cf. Démon & dépendances). ⚠️ Chaque paquet ajouté re-déclenche un cycle d'installation **sur tout le
  parc** : ne pas en ajouter à la légère, ni « moderniser » une version sans relire ce point.
- **`plugin_info/helperConfiguration.php` / `.py`** — assistant de renommage du squelette. **Déjà joué**
  (`template` → `smartclim`) : ces fichiers ne servent plus, ils sont conservés comme outillage hérité du
  template d'origine.
- **`resources/smartclimd/`** — **restauré à l'UC02 du domaine post-MVP 05** (il avait été supprimé au
  renommage, le MVP n'ayant pas de démon). Contient `smartclimd.py` (point d'entrée), **`relais_auxcloud.py`
  depuis l'UC03 du même domaine** (cf. ci-dessous) et la lib
  `jeedom/` (`jeedom_com`, `jeedom_socket`, `jeedom_utils`). Restauré depuis `git checkout ceed01b --
  resources/` — commit initial du dépôt, dont il a été vérifié **octet à octet** qu'il est identique à
  `jeedom/plugin-template@master` : la question « ce dépôt ou l'amont ? » est donc **sans enjeu**.
  ⚠️ **`demond/demond.py` a été renommé en `smartclimd/smartclimd.py`, et ce n'est pas cosmétique** :
  `system::kill()` et `system::ps()` du core travaillent par **motif `grep` sur la ligne de commande**, et
  `demond.py` est le nom que produit **tout** plugin dérivé du même gabarit — un `system::kill('demond.py')`
  d'un autre plugin tuerait notre démon, et réciproquement. Le renommage est la seule protection possible.
  ⚠️ `jeedom/jeedom.js` (lib Node du gabarit) n'a **pas** été restauré. ⚠️ `resources/python_venv/` est
  créé **par le core** dans cette arborescence : il est en `.gitignore`, et `resources/.htaccess` le couvre
  (il est **hors** de `smartclimd/`).
  ⚠️ **Les `.py` sont en 4 espaces / LF, et se vérifient sur l'INDEX git** (`git show ":<f>"`), pas sur le
  blob source : ce clone est en `core.autocrlf=true`, donc un `git checkout` écrit du CRLF sur disque. Sur
  disque `LF-pur` comme `CRLF` sont acceptables (aucune règle projet pour `.py`) ; **`MIXTE` est interdit**.
- **`resources/smartclimd/relais_auxcloud.py`** — **existe** depuis l'UC03 du domaine post-MVP 05. Classe
  `RelaisAuxCloud` : **seul point du plugin qui ouvre une session WebSocket**, vers le relais temps réel du
  cloud legacy (`apprelay/relayconnect`). Séquence `init` → `initk` → **`sub`** → `ping`/`pingk` (10 s,
  timeout 30 s), backoff **10 → 300 s**, battement toutes les 60 s, transfert des événements par
  `add_changes('auxcloud::push::<endpointId>', …)`.
  ⚠️ **Elle ne parle PAS HVAC** : elle valide une forme et transmet du brut. Le décodage se fait en PHP par
  le `decoderParametres()` **déjà livré** — un décodeur Python serait une divergence garantie.
  ⚠️⚠️ **Le message `sub` est OBLIGATOIRE et son oubli est SILENCIEUX** : sans lui la session s'établit, le
  relais la **confirme**, et aucun événement n'arrive jamais. Aucun `subk` n'est documenté, donc un `sub`
  refusé l'est aussi — d'où l'obligation de journaliser explicitement l'abonnement puis chaque `msgtype`
  reçu. Détail et échantillons : `.memory/analyse/smartclim-transport-aux-cloud-legacy.md` § 6.
  ⚠️ **`import websocket` est sous `try/except ImportError`** : dépendance non installée ⇒ relais désactivé,
  **un seul** `logging.error`, et le démon continue de servir le pont — c'est ce qui tient l'invariant
  « le démon est latéral ». Même leçon que l'`import pyudev` non déclaré du gabarit officiel.
  ⚠️ **Jamais de `sslopt`/`CERT_NONE`, jamais `enableTrace(True)`**, et les exceptions se journalisent par
  `type(erreur).__name__` — **jamais `str(erreur)`**, qui peut porter la liste d'en-têtes.

## Modèle de données du plugin

- **1 eqLogic `smartclim` = 1 climatiseur** (unité intérieure).
- **`logicalId` d'équipement = `mac:<MAC normalisée minuscule sans séparateur>`** — la MAC est le **seul
  identifiant commun aux trois transports**, donc la clé de **fusion des doublons** LAN/cloud. Replis
  documentés si la MAC manque : `auxhome:<deviceId>`, `auxcloud:<endpointId>`, `lan:<mac>`.
  ⚠️ Le rapprochement doit tester la MAC **et la MAC inversée** : les implémentations Broadlink de
  référence lisent des ordres d'octets opposés.
  **Depuis l'UC04 du domaine post-MVP 01**, ce rapprochement est implémenté et **unique** :
  `smartclim::chercherEquipementExistant($mac, $deviceId, $index, $transport = '')`, empruntée par les
  **deux** sens de scan, essaie 7 clés dans un ordre figé — les trois **directes**
  (`logicalId`, `configuration.mac`, `lan_mac`) **avant** les trois **inversées**, puis
  `auxhome_device_id`. Deux gardes non négociables : `lan_mac` ne rapproche **que** pour le transport LAN
  (c'est une déclaration de l'utilisateur *pour le LAN* ; s'en servir côté cloud attacherait, sur une
  faute de frappe, un appareil neuf à l'équipement d'un autre), et les étapes inversées sont **sautées sur
  une MAC palindrome** (sinon un `warning` trompeur sur ce qui est le même équipement que l'étape 1).
  ⚠️ **Un `logicalId` d'équipement n'est JAMAIS réécrit** — rien n'en garantit l'unicité au niveau SQL (un
  renommage vers un `mac:<x>` déjà pris rendrait `eqLogic::byLogicalId()` non déterministe), et c'est
  l'identité exposée à l'API Jeedom. La fusion passe par `configuration.mac`, posée **seulement si elle
  est vide** par `memoriserMacEquipement()` : c'est **la** migration du parc, sans script.
  ⚠️ **Corollaire** : `configuration.mac` peut désormais **diverger du suffixe du `logicalId`** sur un
  équipement resté en `auxhome:<id>`. Tout lecteur passe par `macEquipement()`, **jamais** par un
  `substr()` du `logicalId`.
- **`logicalId` de commande = générique et stable** (`power`, `mode`, `target_temp`, `fan_speed`,
  `mode_cool`, `fan_turbo`…). Il ne change **jamais** lors d'une bascule de transport : c'est ce qui
  garantit qu'un scénario utilisateur survit au passage LAN ↔ cloud.
- **Commandes créées dynamiquement** à partir du **profil de capacités** détecté sur l'appareil — jamais
  d'après un catalogue de modèles. Une capacité qui disparaît d'un profil ne supprime jamais une commande
  déjà créée.
- ⚠️ **Piège majeur** : `mode` et `fanSpeed` ont **trois numérotations différentes** selon le transport.
  Elles vivent dans une **table de données unique** (`smartclimCapabilities`), jamais dupliquées ni codées
  en `switch`.
- **Découverte structurante, désormais actée dans le code** : le champ d'état renvoyé par le cloud AUX
  Home est **la même trame HVAC** que la réponse du LAN Broadlink → **un seul décodeur sert aux deux
  transports**. Depuis l'UC02 du domaine post-MVP 01, il vit dans `smartclimFrame` (extraite de
  `smartclimAuxHomeApi`, cf. Architecture).
  ⚠️ **Les deux références publiques comptent leurs octets dans DEUX espaces différents**, et confondre
  les deux fait décaler tout le décodage : la réponse LAN déchiffrée commence par un **préfixe de
  longueur de 2 octets**, la trame cloud `status.control` non. `offset charge HVAC = offset réponse
  LAN − 2`. C'est ce qui explique la fausse « divergence 12 contre 14 » sur le bit de demi-degré : les
  deux sources désignaient le même bit. `smartclimBroadlinkLan::requete()` retire ce préfixe, si bien que
  ce qui entre dans `smartclimFrame` est **toujours** de la charge HVAC nue.

## Configuration & secrets

- **Config plugin** (`config::save/byKey(..., 'smartclim')`) : compte(s) cloud et options globales.
  **Clés figées pour tout le MVP** (UC01), en `snake_case` anglais, **sans tiret** (`preConfig_<clé>`
  dérive son nom de méthode de la clé) : `auxhome_email`, `auxhome_password` (**chiffrée**),
  `auxhome_country` (ISO-3 majuscules, **défaut constant `smartclim::PAYS_DEFAUT` = `FRA`**),
  `refresh_interval` (1..1440 min, défaut 5 via l'INI).
  **Depuis l'UC01 du domaine post-MVP 03** s'y ajoute le compte du **cloud legacy**, strictement disjoint :
  `auxcloud_email`, `auxcloud_password` (**chiffrée**, 2ᵉ entrée de `$_encryptConfigKey`) et
  `auxcloud_region` (`EU`/`USA`/`CHN`/`RUS`, **défaut constant `smartclim::REGION_DEFAUT` = `EU`**,
  **dupliqué en littéral** dans l'INI comme `auxhome_country`). Accesseurs normalisés
  `emailAuxCloud()` / `regionAuxCloud()`, garde-fou **distinct** `compteAuxCloudConfigure()`.
  ⚠️ **`compteConfigure()` reste le garde-fou d'AUX Home et n'a PAS été touchée** : les deux comptes sont
  indépendants (on peut renseigner l'un, l'autre, les deux, ou aucun).
  ⚠️⚠️ **Les `postConfig_auxcloud_*` purgent la session legacy et RIEN D'AUTRE** — ne jamais y recopier le
  `self::oublierIncident()` des `postConfig_auxhome_*` : `smartclim::dernier_incident` décrit le **cycle
  automatique AUX Home**, et un changement de mot de passe *legacy* effacerait donc la mémoire d'incident
  d'un *autre* transport. Régression invisible à `php -l`, à la CI et à une relecture rapide.
  **Depuis l'UC02 du domaine post-MVP 05** s'y ajoute `demon_port` (défaut `smartclimDemon::PORT_DEMON`
  = 55112, **dupliqué en littéral** dans l'INI comme `auxhome_country`). ⚠️ C'est la **seule** clé de
  config sans **aucun champ de formulaire** : elle n'existe que comme échappatoire en cas de collision de
  port, réglable par l'API JSON-RPC du core. Corollaire assumé : **pas** de `preConfig_demon_port` — sans
  chemin d'écriture depuis l'IHM il serait du code mort, et la normalisation (entier, 1024..65535) se fait
  donc **à la lecture** dans `smartclimDemon::port()`, barrière unique.
  ⚠️ **Aucune déduction du pays depuis le fuseau horaire de Jeedom** — arbitré en recette d'UC01, contre
  la conception d'origine : le fuseau ne dit rien du pays d'un **compte cloud** (une installation
  française réglée sur `Europe/Brussels` se voyait proposer `BEL`), et un pays faux échoue au login sur un
  message trompeur. Un défaut constant, corrigeable en un clic dans la **liste déroulante** du formulaire,
  vaut mieux qu'une devinette : la table `fuseau IANA → ISO-3` et `paysParDefaut()` ont donc été
  **supprimées** (avec l'amorçage en base : `smartclim_install/update()` sont redevenues vides).
  ⚠️ `PAYS_DEFAUT` est **dupliqué en littéral** dans `core/config/smartclim.config.ini` — seul défaut vu
  par `config::byKeys()`, donc par le chargement AJAX du formulaire ; les deux doivent rester identiques.
  Les accesseurs normalisés `smartclim::emailAuxHome()`, `paysAuxHome()`, `intervalleRafraichissement()`
  sont le **seul** point de lecture — ne jamais relire ces clés via `config::byKey` ailleurs. La liste des
  pays du formulaire passe par `smartclim::paysDisponiblesAuxHome()` (délégation vers le transport).
  `smartclim::compteConfigure()` est le **garde-fou à appeler avant tout appel réseau**. Les clés **sensibles** se déclarent dans
  `public static $_encryptConfigKey = array('<clé_mot_de_passe>', …);` sur la classe principale → le core
  les **chiffre/déchiffre automatiquement**. Les hooks `preConfig_<clé>($value)` permettent de
  valider/normaliser avant enregistrement (⚠️ `preConfig_<clé>` est un **nom de méthode fixe** — pas
  d'itération dynamique sur des clés inconnues).
- **Config par équipement** (`$eqLogic->getConfiguration('<clé>')`) : identifiants de transport
  (identifiant cloud, IP/MAC locale), **profil de capacités**, mode de transport (AUTO/LOCAL/CLOUD),
  bornes de température. Les champs sensibles d'un **équipement** (ex. un passcode d'appairage local) se
  chiffrent via les méthodes d'instance `encrypt()`/`decrypt()`.
  **Clés posées depuis l'UC04** : `capacites` (profil **détecté**, réécrit par chaque scan) et
  `temp_min` / `temp_max` / `temp_pas` (bornes **personnalisées** par l'utilisateur, `''` = « non
  personnalisé »). **Depuis l'UC02 du domaine post-MVP 03** : `auxcloud_endpoint_id`,
  `auxcloud_product_id`, `auxcloud_devicetype_flag`, `auxcloud_family_id`, `auxcloud_partage` — identité
  legacy de l'appareil, **en clair** parce que non sensible et stable ; `auxcloud_family_id` est ce qui
  permettra à l'UC03 de re-obtenir les jetons d'appairage sans relister tout le compte.
  ⚠️ **Les secrets, eux, ne sont PAS ici** : `cookie` et `dev_session` vivent dans le cache chiffré
  `smartclim::appareil_auxcloud::<id>` (cf. la 7ᵉ mémoire ci-dessus) — même séparation que
  détecté/personnalisé, appliquée cette fois à identité/secret. Ne jamais graver un `cookie` en
  configuration : `dev/query` le re-livre gratuitement.
  ⚠️ `auxcloud_endpoint_id` est indexée par `indexerEquipements()` et sert la **8ᵉ étape** de
  `chercherEquipementExistant()`, **gardée par le transport** comme l'est `lan_mac`.
  **Depuis l'UC01 du domaine post-MVP 02** : `transport_mode` — mode de transport de
  l'équipement (`auto` / `local` / `cloud`), lu **exclusivement** par `smartclimTransport::mode()` et
  normalisé en **double barrière** (`preSave()` autoritaire et silencieux, plus `mode()` à la lecture).
  ⚠️ **Le défaut AUTO tient à l'ABSENCE de clé**, pas à une valeur écrite : `normaliserMode()` renvoie
  `MODE_AUTO` sur tout ce qui n'est pas l'un des trois codes — d'où **aucun script de migration** pour le
  parc existant, et un équipement créé par le scan en AUTO sans que `creerEquipement()` soit touchée.
  ⚠️ Elle n'a **aucun** défaut dans `smartclim.config.ini` : c'est une clé d'**équipement**, pas de config
  plugin — le piège du `preConfig_` court-circuité ne s'applique donc pas.
  **Depuis l'UC01 du domaine post-MVP 01** : `lan_ip` et `lan_mac` — adresses locales
  **saisies par l'utilisateur** (secours quand la diffusion n'atteint pas l'appareil : VLAN, réseau
  segmenté), `''` = « non personnalisé ». ⚠️ L'adresse **détectée** ne vit **jamais** là : elle est en
  **cache** (`smartclim::lan_appareil::<mac>`, 24 h) — même séparation détecté/personnalisé que
  `capacites` contre `temp_*`. Lecture unique par `smartclim::adresseLan()` (personnalisé → détecté →
  aucun), qui **revalide l'IP à la lecture**.
  ⚠️ **Depuis l'UC02 du domaine post-MVP 02, une sonde `STATUT_INJOIGNABLE` CONSERVE l'IP** au lieu de
  l'effacer (seuls `statut` et `echec_le` sont réécrits) — correctif d'un défaut d'UC01 sans lequel le
  cycle LAN **s'auto-désarmait** : IP effacée → `adresseLan()` renvoie « aucun » → `rafraichirLan()` fait
  `continue` → l'équipement sortait **définitivement** du cycle dès son premier échec, et seul un scan
  manuel le réarmait. C'est ce qui rend possible le retour automatique au LAN. ⚠️ Ne concernait que la
  source **détectée** : une `lan_ip` personnalisée est renvoyée inconditionnellement par `adresseLan()`,
  avant toute consultation de la sonde. ⚠️ `STATUT_MAC_DIVERGENTE` continue d'**effacer** l'IP — là,
  l'adresse héberge démontrablement un autre appareil, insister serait marteler la machine d'un tiers.
  ⚠️ **La R8 d'UC02 (« une IP périmée est martelée indéfiniment ») est TRAITÉE depuis l'UC03 du même
  domaine** — pas supprimée : le hello unicast de 2 s / 15 min **est le détecteur** du changement d'IP,
  l'enlever enlèverait le déclencheur. Ce qui change, c'est qu'une **redécouverte par diffusion** tranche
  dès le **premier** cycle en échec, donc le martèlement n'est plus indéfini *sur une adresse fausse* : il ne
  subsiste que pour un appareil réellement disparu, à un coût **O(1) par cycle** (la diffusion est
  mutualisée pour tout le parc, elle ne monte pas avec N). ⚠️ **Aucune règle d'abandon n'a été ajoutée**, et
  c'est délibéré : abandonner rendrait impossible le retour automatique au LAN (AC6 d'UC02). L'UC03 referme
  au passage la **dette D-1 d'UC02** — une divergence de MAC **est** la signature d'un bail DHCP réattribué,
  donc un équipement éjecté par `STATUT_MAC_DIVERGENTE` reste éligible à la redécouverte au lieu d'attendre
  un scan manuel.
  ⚠️ **Valider une IP sans `ip2long()`** : cet appel renvoie un entier **signé** et PHP est **32 bits**
  sur Raspberry Pi OS armhf — un seuil comme `224.0.0.0` y devient négatif et fait rejeter tout le
  `10.0.0.0/8`. Comparer des **octets**. Détail :
  `.memory/analyse/smartclim-transport-broadlink-lan.md` § 9.
  ⚠️ **Ces deux espaces de nommage sont disjoints par construction, et doivent le rester** : c'est cette
  séparation — pas une convention de nommage — qui garantit qu'une redétection n'écrase jamais une
  personnalisation. Aucun code ne doit écrire une valeur détectée dans `temp_*`, ni une valeur
  personnalisée dans `capacites`. Lecture unique par `smartclim::bornesTemperature()` (personnalisé →
  détecté → constante), validation en **double barrière** (`smartclim::preSave()` autoritaire et
  **silencieux** — il ne lève jamais, car il est aussi traversé par le `save()` du scan —, plus une aide
  à la saisie côté JS).
- **Jetons de session** : cache **chiffré** via la classe `cache` (`cache::set/byKey/delete`), purgé au
  changement d'identifiants. **En place depuis l'UC02** : clé `smartclim::session_auxhome`, **30 min**
  (la durée de vie réelle du jeton reste **inconnue** : UC08 a tranché de garder 30 min et d'instrumenter
  plutôt que de deviner — cf. ci-dessous), contenu `utils::encrypt(json_encode(...))` avec
  `jeton`, `uid`, `cree_le` (horodatage de création, **télémétrie UC08**) et une **empreinte
  `sha1(email|pays)`** — invalidée si l'empreinte diverge, ce qui
  rattrape les changements d'identifiants qui ne passent pas par `config::save` (restauration, SQL
  direct). 🚫 **Jamais le mot de passe dans l'empreinte** : cela le remettrait sur la pile d'appel.
  La purge est câblée sur `postConfig_auxhome_password/email/country` **et** explicitement dans l'action
  d'effacement (`config::remove()` ne déclenche **pas** les hooks).
  **Depuis l'UC01 du domaine post-MVP 01**, une **seconde** famille de sessions existe, indépendante :
  `smartclim::session_lan::<mac>`, **30 min**, chiffrée elle aussi (elle contient une clé de session),
  une entrée **par appareil** — cf. `smartclimBroadlinkLan` ci-dessus pour le `flock` qui la sérialise
  et le stockage hexadécimal de la clé. À ne pas confondre avec `smartclim::lan_appareil::<mac>`
  (mémoire de sonde, **non** chiffrée, aucun secret dedans).
  **Depuis l'UC01 du domaine post-MVP 03**, une **troisième** famille existe :
  `smartclim::session_auxcloud`, **30 min**, chiffrée (elle porte un `loginsession`), **globale au compte**
  comme sa jumelle AUX Home et non par appareil. Contenu `{loginsession, userid, empreinte, cree_le}`,
  empreinte `sha1(email|région)` — ⚠️ **la région en fait partie** : les mêmes identifiants sur une autre
  région désignent un autre hôte, donc une autre session. 🚫 **Jamais le mot de passe dans l'empreinte**,
  même règle qu'AUX Home. ⚠️ `cree_le` est de la **télémétrie livrée d'avance** : la durée de vie réelle du
  `loginsession` n'est documentée par aucune source, et la TTL de 30 min est un **alignement**, pas une
  mesure — elle sera à calibrer à l'UC02, factuellement.
  ⚠️ **Aucune boucle de rejeu réactif n'est écrite pour ce transport**, et c'est délibéré : aucun appelant
  authentifié n'existe avant l'UC02, une boucle serait du code mort **non exerçable**. Ce qui est livré pour
  la rendre triviale alors : le classement `TYPE_AUTH`, `purgerSession()` **publique**, et `cree_le`.
  ⚠️ Le cloud AUX Home n'expose **aucun refresh token** : la stratégie est
  re-login réactif, avec anti-boucle (une seule tentative par cycle).
  Depuis l'UC08, ce rejeu couvre les **deux** chemins authentifiés, avec un seuil de budget **dédié** à
  chacun : la **lecture** (`listerAppareils()`, garde `BUDGET_LOGIN + 3`) et l'**écriture**
  (`appliquerOrdre()`, garde `BUDGET_REJEU_ORDRE` = 10 s). ⚠️ Le seuil d'écriture ne peut **pas** être
  celui de la lecture : `BUDGET_COMMANDE` et `BUDGET_LOGIN` valant tous deux 18 s, la garde de lecture
  serait ici du **code mort**. ⚠️ Dans les deux cas le `try` n'entoure **que** la requête métier, jamais
  `session()` : un `TYPE_AUTH` levé par l'ouverture de session ne doit jamais déclencher de rejeu —
  c'est précisément la rafale que l'UC08 interdit. **Aucun backoff** après échecs répétés en revanche :
  non-objectif assumé, journalisé en dette (D-MVP08-05), candidat `/change` de premier rang.
- ⚠️ **Jamais** de secret/token/mot de passe en clair dans les logs (y compris dans une trace
  d'exception), le DOM, les réponses AJAX ou les commentaires. Au plus un préfixe tronqué.
  **Unique exception, nommée et délibérée** : le mécanisme **`configKey` du core**. `config.ajax.php`
  (`action=getKey` → `config::byKeys()`) **déchiffre** les clés de `$_encryptConfigKey` et les renvoie
  **en clair** au navigateur, où elles atterrissent dans l'attribut `value` du champ. C'est le
  comportement natif de **tout** plugin Jeedom — et du core lui-même (mots de passe SMTP, clés d'API) —
  sur une surface **admin authentifiée**. Arbitré par l'utilisateur au cycle UC01 du MVP. Le champ est
  donc `type="password"` (**masqué**, jamais vidé), et le secret reste chiffré au repos.
  ⚠️ **Corollaire critique** : ne **jamais** vider en JS un champ mot de passe porteur de `configKey` —
  la modale réenvoie **toutes** les clés à chaque sauvegarde, un champ vidé **écraserait le secret
  stocké par une chaîne vide**, y compris lors d'un enregistrement visant un tout autre champ.
- ⚠️ **Ne jamais mettre de purge de configuration dans `<id>_remove()`** : le core l'appelle à chaque
  **désactivation** du plugin (`plugin::setIsEnable(0)` → `callInstallFunction('remove')`), pas seulement
  à la désinstallation, et n'expose **aucun** hook distinguant les deux. Un effacement d'identifiants
  doit être une **action volontaire** de l'utilisateur (bouton dédié).
- ⚠️ **TLS toujours vérifié** — les implémentations publiques de référence du cloud legacy le désactivent ;
  ce plugin ne le fait pas. Si un certificat pose problème, l'anomalie est remontée, jamais contournée.

## Démon & dépendances

**Le plugin a un démon Python depuis l'UC02 du domaine post-MVP 05** (`hasOwnDeamon: true`,
`hasDependency: true`, `packages.json` déclarant `requests`) — il a été **100 % PHP sans aucune dépendance
de l'init à la fin de ce domaine**, et la section ci-dessous garde le motif de ce choix initial, qui reste
valable : le cloud AUX Home ne rafraîchit son état qu'en **quelques minutes à ~30 min** (y compris dans
l'application officielle), donc un démon n'apporte **aucun gain de fraîcheur** sur ce transport. PHP couvre
nativement tout le besoin du socle (cURL, RSA/AES via `openssl_*`, opérations de bits pour la trame HVAC),
et le LAN Broadlink (UDP + AES-CBC) est en PHP.

⚠️⚠️ **Le démon est donc LATÉRAL, et cet invariant est la première chose à préserver** : **aucune** méthode
du socle MVP (`cron()`, `cycleEchu()`, `rafraichirAuxHome()`, `executerCommandeAction()`,
`envoyerOrdreLan()`) ni aucune des quatre briques n'appelle `smartclimDemon`. Le pilotage par scrutation
reste **pleinement opérant** démon arrêté, en erreur, ou dépendances non installées. Le démon **accélère**,
il ne **conditionne** jamais.

**Ce protocole réel est arrivé à l'UC03 de ce domaine** (relais WebSocket du cloud legacy), et il l'a fait
comme prévu : en **ajoutant un appelant** — un **4ᵉ bloc** dans `cron()` —, jamais en insérant le démon sur
le chemin de la scrutation.
⚠️ **Une seule nuance, à énoncer exactement** : `rafraichirAuxCloud()` **lit** désormais le cache de
battement (`smartclimDemon::battementRelais()`) pour décider d'espacer un équipement. Ce n'est pas un appel
bloquant ni socket, et la dégradation est **sûre par construction** : démon mort ⇒ battement `null` ⇒
`pushAuxCloudActif()` faux ⇒ le cycle redevient identique au comportement d'avant l'UC. Ne pas écrire
« aucun appel à `smartclimDemon` » dans un commentaire de cette fonction : c'est faux à la lettre, et ce
projet recopie ses invariants d'une UC à l'autre.

Raisons du choix Python (jamais Node) : le squelette Jeedom (`resources/smartclimd/` + pont
`jeedom_socket`/`jeedom_com`) est en Python, `packages.json` ne gère officiellement que `pip3`, et tout le
code public réutilisable (WebSocket/MQTT/TCP) est en Python.

⚠️⚠️ **Trois pièges génériques Jeedom découverts en livrant ce socle, chacun silencieux** — détail,
sources et commandes de vérification : `.memory/analyse/smartclim-daemon-choix.md` § 7 :
- **Sur Debian ≥ 12, les paquets pip d'un plugin vivent dans un venv PAR PLUGIN**
  (`resources/python_venv`), créé **seulement s'il y a au moins un paquet à déclarer**. Donc : lancer le
  démon par **`system::getCmdPython3('smartclim')`** (⚠️ sa valeur **finit par une espace**), jamais
  `python3` en dur — sinon les paquets du venv sont invisibles ; et une section `pip3` **vide** rend le
  démon **inlançable sans aucun message**.
- **La version déclarée dans `packages.json` se choisit contre `os.min`, PAS contre la dernière version
  publiée.** Un `Requires-Python` trop haut fait échouer `pip`, et le script généré par le core **n'a pas
  de `set -e`** : l'état repasse `nok` et l'installation **repart toutes les 5 minutes, sans borne**. Cas
  vécu : `requests` 2.34.2 exige Python ≥ 3.10 alors qu'`os.min: 10` vaut Debian 10 (Python 3.7) —
  d'où le plancher **`2.31.0`**, seul à couvrir toute la matrice. Ne pas le « moderniser » sans relire
  `.memory/specs/post-mvp/05-temps-reel-et-demon/02-socle-demon-python-tech.md` § 9 R3.
- **Le squelette de démon officiel est cassé à la sortie de la boîte** : `jeedom_utils.stripped()` **tue le
  démon au premier message reçu**, `import pyudev` n'est pas déclaré dans le `packages.json` du gabarit, et
  `jeedom_socket_handler.handle()` journalise la **charge brute** (donc l'`apikey`) tout en lisant **sans
  timeout ni taille max** sur un serveur **mono-thread**. Les écarts du portage sont consignés dans un bloc
  d'en-tête de chaque `.py` — **les y maintenir** : sans cette trace, une resynchronisation amont les
  réintroduit en silence.

⚠️ **Deux crons du core à ne pas confondre** : `plugin::cron` est en `* * * * *`, **`plugin::checkDeamon`
en `*/5 * * * *`** — deux tâches distinctes, chacune dans son propre processus détaché. Et deux gardes à
connaître **avant de conclure qu'une recette échoue** : `deamon_start()` refuse deux lancements à moins de
**45 s** d'intervalle, et `checkDeamon` **relance le démon tout seul** dans les 5 minutes (tester « démon
arrêté » exige donc de basculer « Gestion automatique » à 0).

⚠️ **« MQTT » dans la phrase ci-dessus ne vaut que pour une SOUSCRIPTION PERMANENTE** — précision acquise
au spike du domaine post-MVP 05 (UC01, 2026-09-07), qui a **confirmé l'existence** d'un broker MQTT côté
AUX Home européen (`eu-smthome-m2m.aux-global.com`) tout en établissant que « MQTT ⇒ démon » est **faux** :
une connexion **ponctuelle** (CONNECT/PUBLISH/lecture/fermeture dans un cycle de cron) tient en **PHP
pur** — l'implémentation de référence n'utilise elle-même aucune bibliothèque MQTT, elle construit ses
paquets à la main sur un socket brut. Seul un **processus long** exige un démon. ⚠️ Et le transport
AUX Home **reste en scrutation** : le certificat TLS de ce broker est **expiré et ne couvre pas son propre
nom d'hôte**, donc inaccessible sous la règle « TLS toujours vérifié » ci-dessous, et l'acceptation de nos
identifiants n'est pas établie. Détail, preuves et décision : `.memory/analyse/smartclim-transport-aux-home.md`
§ 7 et `smartclim-daemon-choix.md` § 6 (trois marches).

Les dépendances (Python/pip) se déclarent dans **`plugin_info/packages.json`** (uniquement `pip3`).
⚠️⚠️ **Pièges connus du format `packages.json`** (règles génériques Jeedom, coûteuses à redécouvrir) :
- La **version se met dans la VALEUR, pas dans la clé** : `"paho-mqtt": {"version": "1.6.1"}`, **jamais**
  `"paho-mqtt==1.6.1": {}`. Le core compare la **clé** (nom nu) à `pip list` via
  `isset($installPackage[strtolower($clé)])` (`system::checkAndInstall`). Une clé contenant `==x.y.z` ne
  matche jamais le nom installé → paquet vu « à installer » en permanence → indicateur bloqué NOK +
  réinstallation forcée à chaque passe.
- **Jamais de `<`/`>` dans le champ `version`** (ex. `"<2.0.0"`) : `installPackage` colle `$package .=
  $version` non quoté → redirection shell (`2.0.0: No such file or directory`, paquet jamais installé).
  Toujours une **version exacte** sans opérateur.
- **Ne PAS définir `smartclim::dependancy_info()`** : dès que `packages.json` existe, le core calcule
  l'état **uniquement** depuis `checkAndInstall(packages.json)` et n'appelle jamais cette méthode statique
  (code mort). Pour un contrôle *supplémentaire* (post-`packages.json`), le hook officiel est
  `additionnalDependancyCheck()` (appelé seulement si l'état `packages.json` est déjà `ok`).

## Workflows / CI

CI déléguée aux workflows réutilisables de Jeedom (`jeedom/workflows`) :
- **`.github/workflows/work.yml`** — check complet du plugin sur push/PR vers `beta`, PR vers `master`.
- **`.github/workflows/prettier.yml`** — pousser sur la branche **`prettier`** déclenche un bot qui
  reformate le code et commite (formatage automatique ; pas de config prettier locale).

Pas de commande de lint/test locale ; la validation tourne dans ces workflows contre un Jeedom réel. La
recette fonctionnelle est **manuelle**, sur le matériel de l'utilisateur : ce sont les **critères
d'acceptation** des specs (`.memory/specs/`) qui en tiennent lieu.

### Version du plugin — incrémentée automatiquement (ne jamais le faire à la main)

`plugin_info/info.json` → `pluginVersion` est bumpée **par un hook git `pre-commit`**, pas par une
consigne à ne pas oublier. Motif : le plugin se déploie via le **Market GitHub** de Jeedom, qui ne
propose pas de mise à jour (et ne rejoue pas `smartclim_update()`) si la version n'a pas changé — le
code poussé n'atteint alors jamais l'installation. La version est restée `0.1` de l'init à la fin
d'UC02, deux UC livrées sans bump, avec pour symptôme un Jeedom qui affiche encore l'ancienne page.

- **`.githooks/pre-commit`** → délègue tout à **`.claude/scripts/bump-version.py`**.
- **Activation, à refaire dans chaque clone** (la config git n'est pas versionnée) :
  ```bash
  git config core.hooksPath .githooks
  ```
- Le hook n'incrémente **que** si le commit touche `core/`, `desktop/`, `plugin_info/` ou
  `resources/` : un commit qui ne modifie que `.memory/`, `.claude/`, `.github/` ou `docs/` ne
  consomme pas de version. Il ajoute lui-même `info.json` au commit, et **s'abstient** si `info.json`
  porte des modifications non indexées (il n'embarque jamais un changement non choisi). Il ne bloque
  jamais un commit.
- Il incrémente la **dernière composante numérique** du format en place (`0.1` → `0.2`,
  `1.4.2` → `1.4.3`) : monter le major/minor reste une décision **humaine** — l'écrire à la main dans
  `info.json`, le hook repart de cette valeur.
- ⚠️ **Ne pas bumper `pluginVersion` manuellement** dans un commit de code : le hook incrémenterait
  par-dessus (double saut de version).
- ⚠️ **`.gitattributes`** force `.githooks/** text eol=lf`. C'est la seule règle du fichier —
  volontairement **pas** de `* text=auto`, qui réécrirait en masse les fichiers du plugin. En CRLF,
  `/bin/sh` refuse le shebang du hook, qui devient inopérant **sans aucun message**.
- ⚠️ Le hook doit rester en mode **100755 dans l'index** : git **n'exécute pas** un hook non exécutable
  (silencieusement, là encore). Windows n'enregistre pas le bit `+x` tout seul (`core.fileMode=false`) —
  un `chmod +x` local ne suffit donc pas, il faut :
  ```bash
  git update-index --chmod=+x .githooks/pre-commit
  ```
  À vérifier avec `git ls-files -s .githooks/pre-commit` (doit afficher `100755`). Sans cela le hook
  fonctionne sur Windows mais est ignoré sur tout clone Linux/macOS.

## Conventions

- **Français = langue source** : code, commentaires, noms de variables, messages de `log::add` et chaînes
  UI sont écrits en français (langue **par défaut** de Jeedom — pas de `fr_FR.json`).
- **Autoload Jeedom (règle critique, fatale au runtime, invisible à `php -l` ET à la CI)** — ⚠️ **corrigée
  en recette UC02, l'ancienne version de cette règle était fausse et a causé la panne** : il n'y a
  **aucun `glob`**. `jeedomAutoload()` (core/php/core.inc.php) ne charge, pour tout un plugin, qu'**un
  seul fichier** : `plugins/<id>/core/class/<id>.class.php`. Son code réel :
  ```php
  $classname = str_replace(array('Real', 'Cmd'), '', $_classname);
  $plugin_active = config::byKey('active', $classname, null);
  if (($plugin_active === null || …) && strpos($classname, '_') !== false) {
      $classname = explode('_', $classname)[0];      // seule porte de sortie
      $plugin_active = config::byKey('active', $classname, null);
  }
  if ($plugin_active == 1) { include_file('core', $classname, 'class', $classname); }
  ```
  Conséquences, à connaître par cœur :
  - Un nom de classe **sans `_`** qui n'est pas l'id du plugin (ex. `smartclimAuxHomeApi`) ne prend jamais
    la branche de repli : `$plugin_active` reste `null` et l'autoloader **ne fait RIEN — sans erreur, sans
    log, sans warning**. Le plantage arrive plus tard, en « Class not found », uniquement sur le chemin de
    code concerné.
  - Même **avec** un `_`, il n'inclurait que `<id>.class.php` : un fichier `<Classe>.class.php` séparé
    **n'est jamais chargé tout seul**. `smartclimCmd` fonctionne parce qu'elle vit **dans**
    `smartclim.class.php` — c'est la raison d'être du `str_replace('Cmd')` ci-dessus.
  - ⚠️ Donc **« 1 classe ↔ 1 fichier » ne suffit PAS** : c'est une convention de lisibilité de ce plugin,
    pas un mécanisme de chargement.
  **La règle à appliquer** : toute classe annexe se déclare dans son fichier `<Classe>.class.php` **et**
  s'ajoute à la liste de `require_once` de **`core/php/smartclim.inc.php`**, lui-même inclus en tête de
  `core/class/smartclim.class.php` (le seul fichier que l'autoloader charge). Toutes les classes annexes
  sont ainsi disponibles dès que `smartclim`/`smartclimCmd` est résolue, donc depuis **tous** les points
  d'entrée (`core/ajax/*.ajax.php`, crons, `desktop/php/*.php`, `install.php`). Chaque nouvelle classe
  (`smartclimCapabilities`, `smartclimFrame`, `smartclimTransport`, `smartclimAuxCloudApi`,
  `smartclimBroadlinkLan`) **doit** être ajoutée à cette liste — l'oublier ne casse ni `php -l` ni la CI.
- **Aucune méta-séquence littérale dans un commentaire ou une chaîne (fatale, et invisible à la
  relecture)** — un délimiteur écrit au milieu d'une phrase n'est pas du texte : le parseur le prend pour
  lui. **Cas vécu** (recette UC01) : `mb_*/intl` dans un docblock de `smartclimAuxHomeApi`. Le `*/` du
  milieu a **fermé le commentaire**, `intl, …` a été relu comme du code (`syntax error, unexpected
  'intl'`), donc `smartclim.class.php` n'a plus été chargée en entier et **toute la classe `smartclim`
  est devenue introuvable** — symptôme visible : la liste déroulante des pays disparue de la page de
  configuration. Trois séquences, un seul mécanisme :

  | Séquence | Où elle est fatale | Ce qui se passe |
  |---|---|---|
  | `*/` **collé à du texte** | commentaire `/* … */` | ferme le commentaire ici ; la suite est relue comme du code |
  | `?>` | commentaire `//` ou `#` | PHP **quitte le mode PHP** ; la fin de la ligne part telle quelle au navigateur |
  | `{{` | fichier **rendu** (`desktop/`, `plugin_info/configuration.*`, `core/template/`), **commentaire compris** | le moteur i18n du core y voit un début de clé et avale tout jusqu'à la fermeture suivante |

  Écrire `mb_* ou intl`, « balise fermante PHP », « double accolade ouvrante ». ⚠️ Aucun de ces cas
  n'est rattrapé ici : `php` **n'est pas installé** sur la machine de dev, et la CI ne se déclenche ni
  sur push `master` ni hors PR. Le seul filet réellement en place est
  `python .claude/scripts/verif-plugin.py` (colonne **`meta=`**) — **à lancer avant chaque commit**.
  ⚠️ **Les fichiers Python de `resources/` échappent à ce filet** : le script ne parcourt que `core/`,
  `desktop/` et `plugin_info/`, et ne balaye ni l'i18n ni les octets de contrôle sur `.py` (il en vérifie
  en revanche les **fins de ligne** si on les lui passe **explicitement en argument**, et il refuse le
  `MIXTE`). Contrairement à PHP, **Python EST installé** sur la machine de dev : le contrôle syntaxique
  existe donc, et il est à lancer avec le script —
  `python -m py_compile resources/smartclimd/*.py resources/smartclimd/jeedom/*.py`. ⚠️ Il valide la
  **syntaxe** seulement : ni un import manquant, ni une erreur au niveau module (un `re.compile()` avec
  `import re` oublié passe `py_compile` et tue le démon à l'import). Tout le reste ne se voit qu'en recette,
  dans `log/smartclim_demon`.
- **Centraliser les accès externes** : **tous** les appels HTTP passent par la brique du transport concerné
  (`smartclimAuxHomeApi`, `smartclimAuxCloudApi`) et tout le LAN par `smartclimBroadlinkLan` — jamais de
  cURL ou de socket épars. Le reste du plugin ne parle qu'à l'**API générique**, jamais à un transport.
- **Aucun code propriétaire hors des adaptateurs de transport** : offsets d'octets, noms de champs d'API et
  numérotations de modes restent confinés dans la brique du transport (ou dans les tables de
  `smartclimCapabilities`).
- Indentation **2 espaces** en PHP/JS pour `core/class`, `desktop/js`, `plugin_info/configuration.txt`… ;
  ⚠️ **deux exceptions héritées du squelette, à respecter telles quelles** : `desktop/php/*.php` (pages)
  sont en **tabulations**, et `core/ajax/smartclim.ajax.php` est en **4 espaces**.
  **Fins de ligne CRLF partout** — et pour le vérifier, **compter les octets**
  (`tr -cd '\r' | wc -c` vs `'\n'`), **jamais** `grep -c $'\r'`, qui peut retourner le nombre total de
  lignes et donner l'illusion d'un fichier CRLF.
  Règle générale : **respecter l'existant fichier par fichier**.
- Logs via `log::add('smartclim', 'debug'|'info'|'warning'|'error', $msg)` ; **jamais** de secret exposé.
- **Robustesse cron** : un équipement en erreur ne doit **pas** interrompre la boucle → `try/catch` **par
  équipement**. Un seul appel réseau global par cycle quand l'API le permet, puis distribution.
  ⚠️ **Période de grâce après commande** (~60 s) : un état scruté plus ancien qu'une commande envoyée ne
  doit pas écraser les champs commandés (anti-rollback de consigne/marche).
- Les `.htaccess` interdisent l'accès web direct — **les conserver**. Présents dans `core/php`,
  `core/class`, `core/config`, `plugin_info`, `.memory`, `.claude`, `.github`, et **`resources`** (depuis
  l'UC02 du domaine post-MVP 05 : il couvre `python_venv/`, que le core crée **hors** de `smartclimd/`,
  lequel a le sien). ⚠️ `core/ajax` n'en a **pas** et ne doit pas en avoir : il est appelé par le
  navigateur.
  ⚠️ **`core/php/.htaccess` whiteliste UN SEUL fichier** depuis l'UC02 du domaine post-MVP 05 —
  `jeeSmartclim.php`, via un bloc `<Files>` sur le modèle de `plugin_info/.htaccess` : c'est le rappel HTTP
  du démon, il **doit** être joignable, sinon le démon s'arrête au démarrage sur un 403. Le `Deny from all`
  continue de protéger `smartclim.inc.php` et les **5 CLI** — n'y ajouter aucun autre fichier sans vérifier
  ce qu'il rendrait public, et ne jamais élargir le bloc à une **extension** (ce serait ouvrir tout le
  dossier).
  ⚠️ **`plugin_info/.htaccess` whiteliste des extensions** (`allow from all` sur les images) pour servir
  `smartclim_icon.png` — cette section **neutralise** le `Deny from all` du dossier pour les extensions
  listées. `txt` en a été **retiré** : sans quoi le miroir `configuration.txt` était téléchargeable **sans
  authentification**, exposant le source de la page de configuration. **Ne jamais y remettre `txt`**, et
  n'y ajouter aucune extension sans vérifier ce qu'elle rendrait public.
  ⚠️ **Non couvert** : `.git/` reste servi sur une installation clonée en git — `GET .../.git/config`
  expose l'URL du dépôt distant, et un **jeton** si le clone utilisait `https://user:token@…`. Aucun
  `.htaccess` interne ne peut le fermer. Préférer une installation **par archive**.
- `docs/<langue>/` = documentation **utilisateur** ; `.memory/` = analyse & specs **internes** (français).

## Internationalisation (i18n) — natif multilingue

Le plugin est **nativement multilingue**. Langue **source = français** (`fr_FR`, pas de fichier de
traduction : la clé EST le texte français). Langues cibles : **`en_US`**, **`de_DE`**, **`es_ES`**
(cf. `info.json "language"`).

- Toute chaîne UI est **enveloppée** : `{{Texte français}}` en HTML/JS, `__('Texte français', __FILE__)`
  en PHP. La clé est **toujours** le texte source français.
  - ⚠️ **Toujours une chaîne LITTÉRALE** dans `__()` — jamais `__($variable)`. L'extraction i18n (dont le
    sous-agent `translator`) est un **scan statique** : un nom stocké dans une variable puis passé à `__()`
    échappe à la traduction. Mettre `__('Libellé', __FILE__)` **dans** la table de définitions (modes,
    vitesses, fonctions de confort…), pas `__($nom)` au moment de l'usage.
- Les traductions vivent dans `core/i18n/<langue>.json`, **un fichier par langue cible** (pas de
  `fr_FR.json`). Format :
  ```json
  { "plugins/smartclim/<chemin/relatif/fichier>": { "Texte français": "Traduction" } }
  ```
- ⚠️ **Exception `info.json` — mécanisme DISTINCT** : la `description` (et le `name`) du manifeste se
  traduit via un **objet à clés de langue INLINE dans `plugin_info/info.json`** —
  `"description": {"fr_FR": …, "en_US": …, …}` — **PAS** via une section `"info.json"` des fichiers
  `core/i18n/*.json`. La `description` doit faire **≥ 80 caractères** par langue (règle du market Jeedom).
  ✅ Déjà en place pour les 4 langues.
- **Règle d'or** : toute clé UI **livrée** doit avoir ses traductions dans toutes les langues cibles.
  *Quand* les produire :
  - **Dans `/feature`** : traduction faite **en fin de cycle** par le sous-agent `translator` (code figé,
    contexte isolé). Pendant le dev on enveloppe en français mais on **ne touche pas** aux `*.json`.
  - **Hors workflow** : ajouter/mettre à jour la clé dans les fichiers cibles dès qu'on l'introduit.

## Feuille de route, specs & mémoire interne

- **`.memory/brief.md`** — le **brief utilisateur d'origine** (objectif, projets de référence à étudier,
  modes de connexion, exigences de sécurité et de licences). Il fait autorité sur l'intention.
- **`.memory/specs/`** — la roadmap réelle du plugin, découpée en **UC implémentables une par une** :
  - `MVP/` — **8 UC ordonnées** menant au pilotage complet d'un climatiseur AUX Home
    (configuration → authentification → découverte → modèle générique → commandes info → commandes action
    → cron → robustesse).
  - `post-mvp/<NN-domaine>/` — **7 domaines** : `01-transport-broadlink-lan`, `02-strategies-de-transport`,
    `03-cloud-aux-legacy`, `04-fonctions-avancees`, `05-temps-reel-et-demon`, `06-ergonomie-jeedom`,
    `07-multimarque-documentation-et-diffusion`.

  Convention : une feature = une spec **fonctionnelle** `NN-nom.md` (critères d'acceptation = *definition of
  done*) + une spec **technique** `NN-nom-tech.md` (plan d'implémentation, écrite par `/feature`).
  Numérotation **locale à chaque dossier** : une référence croisée se cite en toutes lettres
  (« UC06 du MVP », « UC03 du domaine post-mvp/01 »). Voir `.memory/specs/README.md` pour l'arborescence
  complète et l'ordre de développement.
- **`.memory/analyse/`** — connaissance **transverse et réutilisable**, **découvrable via
  `.memory/analyse/INDEX.md`** :
  - propre au plugin : `smartclim-ecosysteme-aux-broadlink.md` (générations d'appareils et marques),
    `smartclim-transport-aux-home.md`, `smartclim-transport-broadlink-lan.md`,
    `smartclim-transport-aux-cloud-legacy.md`, `smartclim-modele-abstrait-capacites.md`,
    `smartclim-architecture-jeedom.md`, `smartclim-daemon-choix.md` ;
  - générique Jeedom : `jeedom-widgets-commandes.md`, `jeedom-panel-page-menu.md`.

  ⚠️ Ces analyses distinguent **ce qui est vérifié dans du code source lu** de ce qui est **hypothèse**.
  Toute incertitude de contrat externe est marquée « À confirmer » : la trancher **au moment de coder**,
  contre le matériel réel — et **mettre l'analyse à jour** avec le résultat.
- **`.memory/external/doc/jeedom/INDEX.md`** — index de la doc développeur Jeedom (pour un `WebFetch`
  ciblé sans re-parcourir le sommaire).

L'outillage `/` fonctionne en **trois temps** :

- **Bootstrap — `/init-plugin`** : ✅ **déjà joué** (cadrage, analyses, renommage du squelette, specs
  fonctionnelles). Ne pas le relancer : il refuserait d'écraser un cadrage existant.
- **Implémentation — `/feature <spec>`** (à lancer par UC) : à partir d'une spec fonctionnelle, **fait
  produire le plan technique par l'agent `jeedom-tech-planner`**, le fait valider par l'utilisateur, écrit
  la spec technique, délègue l'implémentation à l'agent `php-jeedom-dev` (skill `dev`), lance les reviews
  croisées (`code-reviewer`, `security-reviewer`), puis la traduction (`translator`) et la capitalisation
  mémoire.
- **Enchaînement autonome — `/auto-dev "<liste d'UC>"`** puis **`/change <explication>`** : le mode
  « sans humain dans la boucle », détaillé ci-dessous.

### Mode autonome — `/auto-dev` et `/change`

`/auto-dev "MVP 04 .. MVP 08"` (intervalle) ou `/auto-dev "MVP 04, MVP 06, MVP 08"` (liste) enchaîne des
cycles `/feature` complets **sans poser de question** : à chaque gate humaine, il **tranche** selon la
grille `.claude/templates/principes-arbitrage.md` puis **journalise** l'arbitrage. Une UC = **un
sous-agent `auto-dev-runner`** en contexte neuf (l'orchestrateur ne lit jamais un fichier de code ou de
spec : c'est ce qui garde son contexte plat sur tout un run), et **un commit sur `master`** — jamais de
`push`.

- **Reprise après coupure** (crédit épuisé, réseau, session fermée) : relancer la **même** demande
  reprend le run existant et saute les UC déjà terminées ; `/auto-dev` **sans argument** reprend le
  dernier run interrompu. L'état vit dans `.memory/auto-dev/<run>/etat.json` + `journal.jsonl`, écrits
  **au fil de l'eau** par les runners — et une reprise se fie d'abord au **constat sur le disque**
  (spec technique présente ? arbre sale ? commit existant ?), pas à la phase journalisée.
  ⚠️ **Un run interrompu puis committé À LA MAIN casse ces trois indices d'un coup** (vécu le
  2026-08-26 sur UC06) : `etat.json` annonçait `phase: verif`, `commit: null`, l'arbre était propre — et
  pourtant le code était déjà dans `HEAD`, reviews et traduction jamais jouées. Le tell fiable est
  ailleurs : **`python .claude/scripts/verif-plugin.py --tous` remontant des clés i18n manquantes**. La
  traduction étant *toujours* la dernière étape d'un cycle, une UC dont le code est en place mais dont
  l'i18n est incomplète est une UC **non terminée** — reprendre à l'étape « reviews croisées », pas
  rejouer le plan ni l'implémentation.
- **`recap.md` à la racine** — ⚠️ **fichier GÉNÉRÉ**, jamais édité à la main : il est réassemblé par
  `python .claude/scripts/auto-dev.py recap` depuis les `decisions.md` des runners et les révisions.
  Chaque entrée est autoportante (question, décision, alternatives écartées, portée dans le code, coût
  d'un revirement, migration) parce que son lecteur cible — `/change` — démarre en **contexte vide**.
- **`/change <explication>`** (ou `/change D-MVP04-02 <explication>`, `/change --liste`) : retrouve la
  décision dans `recap.md`, charge **uniquement** ce qu'elle cite, applique l'autre option (code, spec
  technique, **migration de l'existant** : clé de config renommée, `logicalId` déjà posé, cache au
  format précédent), puis ajoute une **révision** — l'ancienne décision reste visible, marquée révisée.
- **`.claude/scripts/auto-dev.py`** porte tout le mécanique (résolution de la demande en specs, journal,
  assemblage du récap) : `resolve`, `init`, `status`, `event`, `recap`. Le format des entrées de décision
  est figé dans `.claude/templates/recap-section.md` — **ses marqueurs sont lus à la lettre** par le
  script (`### D-<UC><NN> — …`, `- **Statut** : …`, `- **Révise** : D-…`) : les changer sans toucher au
  script casse silencieusement l'index et le lien de révision.

> **Maintenance de ce fichier** : `CLAUDE.md` est lu par **toute** future session. Le tenir à jour quand
> l'architecture, les conventions ou l'outillage changent. En revanche, l'**avancement détaillé** (quelle
> UC est faite, quel contrat a été confirmé) se consigne dans `.memory/specs/` et `.memory/analyse/`, pas
> ici.
