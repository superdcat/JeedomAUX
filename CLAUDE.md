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
| **Broadlink LAN** (UDP port 80) | post-MVP (domaine 01) | pilotage local, sans Internet |
| **AUX Cloud legacy / AC Freedom** | post-MVP (domaine 03) | cloud historique, multi-régions |

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
  ⚠️ **Une clé absente de l'état ne touche pas sa commande** — c'est le mécanisme, volontaire et unique,
  qui évite d'afficher une valeur non confirmée (vitesse/mode sans correspondance de lecture, trame trop
  courte, appareil hors ligne, température ambiante implausible). Ne jamais le remplacer par une valeur
  de repli.
  ⚠️ `creerCommandesInfo()` est appelée par **`postSave()` ET `appliquerEtat()`** : un scan qui ne change
  rien n'émet aucun `save()`, donc aucun `postSave()` — sans le second appel, aucune commande
  n'apparaîtrait sur un parc déjà découvert avant UC05.
- **`core/class/smartclimAuxHomeApi.class.php`** — brique du transport **AUX Home**, seul point cURL du
  plugin. Porte la liste des pays proposables `paysDisponibles()` (UC01, amendée en recette : plus
  aucune déduction depuis le fuseau horaire, cf. § Configuration & secrets), puis l'authentification
  complète (UC02) : `login()` (toujours frais — `getPubkey` + `login/pwd` — **et écrit** la session en
  cache), `session()` (**lit** le cache, sinon `login()`), `purgerSession()`, la crypto RSA/AES et les
  constantes de protocole embarquées (source + licence MIT citées en commentaire), et enfin la
  **découverte des appareils** (UC03) : `listerAppareils()` (`GET /app/user_device?getStatus=1`, budget
  de temps global `BUDGET_SCAN`, re-login réactif borné à **un** rejeu) qui renvoie des lignes
  **normalisées à clés génériques françaises** — aucun nom de champ AUX (`deviceId`, `alias`, `modelId`,
  `online`) n'en sort. Enfin, la **lecture d'état** (UC05) : `etatAppareil(array $_appareil)`, appuyée sur
  la table privée `champsEtatAuxHome()` (concept → trame `control`/`running` + index d'octet) et
  l'accesseur `octetTrame()`. C'est **ici**, et nulle part ailleurs, que vivent les offsets d'octets de la
  trame HVAC — `offsetsAuxHome()` (UC04) en **dérive** désormais ses longueurs minimales : une seule
  source d'offsets.
  ⚠️ **`nettoyerTexteExterne()` est la frontière d'assainissement du transport** : c'est elle, et elle
  seule, qui garantit qu'un champ du cloud (dont l'`identifiant` d'où dérive un `logicalId`) ne porte pas
  de caractère de contrôle. Toute nouvelle source d'appareil doit passer par un nettoyage équivalent
  **avant** de construire un `logicalId` ou d'être journalisée.
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
  ⚠️ **La colonne `'fil' => null` est le seul mécanisme qui exclut une valeur du profil de capacités** :
  une valeur sans correspondance de **lecture** vérifiée n'apparaît jamais dans l'interface plutôt que
  d'y figurer approximativement. `versTransport()`/`depuisTransport()` renvoient `null` quand la
  correspondance manque — **jamais** de repli silencieux. Ajouter une capacité, c'est éditer cette
  table, pas ajouter un `switch`.
- **Classes annexes encore à créer** (chacune dans **son propre** fichier `<Classe>.class.php`, **et
  chacune à ajouter aux `require_once` de `core/php/smartclim.inc.php`** — sans quoi elle sera
  introuvable au runtime, cf. Conventions → Autoload) : `smartclimTransport` (sélection du transport
  actif), et les deux autres briques de transport : `smartclimAuxCloudApi`, `smartclimBroadlinkLan`.
  ⚠️ **`smartclimFrame` est volontairement AJOURNÉE, pas oubliée** (arbitrage UC05) : le décodage de la
  trame HVAC vit dans `smartclimAuxHomeApi` tant qu'il n'a **qu'un seul appelant** — l'en extraire
  imposerait de sortir les offsets de la brique de transport. Elle se crée le jour où un **second**
  transport décode la même trame (domaine post-MVP 01, Broadlink LAN), et ce jour-là son `require_once`
  dans `core/php/smartclim.inc.php` est **obligatoire**. Ne pas la (re)proposer avant.
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
- **`plugin_info/packages.json`** — dépendances système/pip du démon (voir Démon & dépendances). **Vide au
  MVP** : le plugin n'a aucune dépendance.
- **`plugin_info/helperConfiguration.php` / `.py`** — assistant de renommage du squelette. **Déjà joué**
  (`template` → `smartclim`) : ces fichiers ne servent plus, ils sont conservés comme outillage hérité du
  template d'origine.
- **`resources/`** — **supprimé au renommage** (le plugin n'a pas de démon au MVP). Le squelette de démon
  Python (`demond.py` + lib `jeedom/`) reste récupérable à tout moment :
  `git checkout ceed01b -- resources/` (commit initial du dépôt), ou depuis `jeedom/plugin-template`.
  Il sera restauré au domaine post-MVP 05, si et seulement si un canal temps réel le justifie.

## Modèle de données du plugin

- **1 eqLogic `smartclim` = 1 climatiseur** (unité intérieure).
- **`logicalId` d'équipement = `mac:<MAC normalisée minuscule sans séparateur>`** — la MAC est le **seul
  identifiant commun aux trois transports**, donc la clé de **fusion des doublons** LAN/cloud. Replis
  documentés si la MAC manque : `auxhome:<deviceId>`, `auxcloud:<endpointId>`, `lan:<mac>`.
  ⚠️ Le rapprochement doit tester la MAC **et la MAC inversée** : les implémentations Broadlink de
  référence lisent des ordres d'octets opposés.
- **`logicalId` de commande = générique et stable** (`power`, `mode`, `target_temp`, `fan_speed`,
  `mode_cool`, `fan_turbo`…). Il ne change **jamais** lors d'une bascule de transport : c'est ce qui
  garantit qu'un scénario utilisateur survit au passage LAN ↔ cloud.
- **Commandes créées dynamiquement** à partir du **profil de capacités** détecté sur l'appareil — jamais
  d'après un catalogue de modèles. Une capacité qui disparaît d'un profil ne supprime jamais une commande
  déjà créée.
- ⚠️ **Piège majeur** : `mode` et `fanSpeed` ont **trois numérotations différentes** selon le transport.
  Elles vivent dans une **table de données unique** (`smartclimCapabilities`), jamais dupliquées ni codées
  en `switch`.
- **Découverte structurante** : le champ d'état renvoyé par le cloud AUX Home est **la même trame HVAC**
  que la réponse du LAN Broadlink → à terme **un seul décodeur** sert aux deux transports. Au MVP il vit
  dans `smartclimAuxHomeApi` (`champsEtatAuxHome()` / `octetTrame()` / `etatAppareil()`), faute d'un
  second appelant ; son extraction en `smartclimFrame` est la première tâche du transport LAN, pas une
  tâche du MVP.

## Configuration & secrets

- **Config plugin** (`config::save/byKey(..., 'smartclim')`) : compte(s) cloud et options globales.
  **Clés figées pour tout le MVP** (UC01), en `snake_case` anglais, **sans tiret** (`preConfig_<clé>`
  dérive son nom de méthode de la clé) : `auxhome_email`, `auxhome_password` (**chiffrée**),
  `auxhome_country` (ISO-3 majuscules, **défaut constant `smartclim::PAYS_DEFAUT` = `FRA`**),
  `refresh_interval` (1..1440 min, défaut 5 via l'INI). Plus tard s'y ajouteront le compte AUX Cloud
  legacy + sa région.
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
  personnalisé »).
  ⚠️ **Ces deux espaces de nommage sont disjoints par construction, et doivent le rester** : c'est cette
  séparation — pas une convention de nommage — qui garantit qu'une redétection n'écrase jamais une
  personnalisation. Aucun code ne doit écrire une valeur détectée dans `temp_*`, ni une valeur
  personnalisée dans `capacites`. Lecture unique par `smartclim::bornesTemperature()` (personnalisé →
  détecté → constante), validation en **double barrière** (`smartclim::preSave()` autoritaire et
  **silencieux** — il ne lève jamais, car il est aussi traversé par le `save()` du scan —, plus une aide
  à la saisie côté JS).
- **Jetons de session** : cache **chiffré** via la classe `cache` (`cache::set/byKey/delete`), purgé au
  changement d'identifiants. **En place depuis l'UC02** : clé `smartclim::session_auxhome`, **30 min**
  (durée de vie réelle du jeton inconnue jusqu'à UC08), contenu `utils::encrypt(json_encode(...))` avec
  `jeton`, `uid` et une **empreinte `sha1(email|pays)`** — invalidée si l'empreinte diverge, ce qui
  rattrape les changements d'identifiants qui ne passent pas par `config::save` (restauration, SQL
  direct). 🚫 **Jamais le mot de passe dans l'empreinte** : cela le remettrait sur la pile d'appel.
  La purge est câblée sur `postConfig_auxhome_password/email/country` **et** explicitement dans l'action
  d'effacement (`config::remove()` ne déclenche **pas** les hooks). ⚠️ Le cloud AUX Home n'expose **aucun refresh token** : la stratégie est
  re-login réactif, avec anti-boucle (une seule tentative par cycle).
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

**Décision du MVP : pas de démon, aucune dépendance** (`hasOwnDeamon: false`, `hasDependency: false`,
`packages.json` vide). Motif — le cloud AUX Home ne rafraîchit son état qu'en **quelques minutes à ~30 min**
(y compris dans l'application officielle) : un démon n'apporterait **aucun gain de fraîcheur**, seulement un
processus, une dépendance et une surface de panne. PHP couvre nativement tout le besoin (cURL, RSA/AES via
`openssl_*`, opérations de bits pour la trame HVAC), et le LAN Broadlink (UDP + AES-CBC) reste faisable en
PHP.

Un démon **Python** (jamais Node) sera introduit **uniquement** quand un canal réellement **persistant**
arrivera — WebSocket relay du cloud legacy, MQTT, ou session TCP locale à heartbeat : cf. domaine post-MVP
05 et `.memory/analyse/smartclim-daemon-choix.md`. Raisons du choix Python : le squelette Jeedom
(`resources/demond/` + pont `jeedom_socket`/`jeedom_com`) est en Python, `packages.json` ne gère
officiellement que `pip3`, et tout le code public réutilisable (WebSocket/MQTT/TCP) est en Python.

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
  `core/class`, `core/config`, `plugin_info`, `.memory`, `.claude`, `.github`. ⚠️ `core/ajax` n'en a
  **pas** et ne doit pas en avoir : il est appelé par le navigateur.
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
