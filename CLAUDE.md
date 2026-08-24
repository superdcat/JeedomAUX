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
- **`.claude/`** — commandes `/init-plugin` (cadrage, déjà joué) et `/feature` (implémentation d'une UC),
  sous-agents (`jeedom-plugin-architect`, `spec-writer`, `php-jeedom-dev`, `code-reviewer`,
  `security-reviewer`, `translator`), skills `spec` et `dev`, mémoire d'agent.
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
- **Classes annexes prévues** (chacune dans **son propre** fichier `<Classe>.class.php`, cf. Autoload) :
  `smartclimException` (erreurs typées auth/réseau/protocole), `smartclimCapabilities` (énumérations
  génériques + tables de correspondance), `smartclimFrame` (décodage/encodage de la trame HVAC),
  `smartclimTransport` (sélection du transport actif), et **une brique par transport** :
  `smartclimAuxHomeApi`, `smartclimAuxCloudApi`, `smartclimBroadlinkLan`.
- **`core/ajax/smartclim.ajax.php`** — endpoint AJAX **admin** de la page de configuration : inclut le core,
  `isConnect('admin')`, `ajax::init()`, puis aiguille sur `init('action')` en branches
  `if (init('action') == '...')`. Pour un endpoint **non-admin** (widget de dashboard, page-panneau), créer
  un fichier AJAX **distinct** avec `isConnect()` + contrôle fin `hasRight('r')` par équipement.
- **`core/php/smartclim.inc.php`** — includes/constantes internes du plugin.
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
  `config::byKey/save(..., 'smartclim')`).

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

- **`plugin_info/info.json`** — manifeste (id, `name`, version, `require`, OS min/max, `category`,
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
  que la réponse du LAN Broadlink → **un seul décodeur** (`smartclimFrame`) sert aux deux transports.

## Configuration & secrets

- **Config plugin** (`config::save/byKey(..., 'smartclim')`) : compte(s) cloud et options globales —
  identifiants AUX Home (e-mail, mot de passe, pays ISO-3), intervalle de rafraîchissement, et plus tard le
  compte AUX Cloud legacy + sa région. Les clés **sensibles** se déclarent dans
  `public static $_encryptConfigKey = array('<clé_mot_de_passe>', …);` sur la classe principale → le core
  les **chiffre/déchiffre automatiquement**. Les hooks `preConfig_<clé>($value)` permettent de
  valider/normaliser avant enregistrement (⚠️ `preConfig_<clé>` est un **nom de méthode fixe** — pas
  d'itération dynamique sur des clés inconnues).
- **Config par équipement** (`$eqLogic->getConfiguration('<clé>')`) : identifiants de transport
  (identifiant cloud, IP/MAC locale), **profil de capacités**, mode de transport (AUTO/LOCAL/CLOUD),
  bornes de température. Les champs sensibles d'un **équipement** (ex. un passcode d'appairage local) se
  chiffrent via les méthodes d'instance `encrypt()`/`decrypt()`.
- **Jetons de session** : cache **chiffré** via la classe `cache` (`cache::set/byKey/delete`), purgé au
  changement d'identifiants. ⚠️ Le cloud AUX Home n'expose **aucun refresh token** : la stratégie est
  re-login réactif, avec anti-boucle (une seule tentative par cycle).
- ⚠️ **Jamais** de secret/token/mot de passe en clair dans les logs (y compris dans une trace
  d'exception), le DOM, les réponses AJAX ou les commentaires. Au plus un préfixe tronqué.
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

## Conventions

- **Français = langue source** : code, commentaires, noms de variables, messages de `log::add` et chaînes
  UI sont écrits en français (langue **par défaut** de Jeedom — pas de `fr_FR.json`).
- **Autoload Jeedom (règle critique, fatale au runtime, invisible à `php -l`)** : l'autoloader mappe
  **1 classe ↔ 1 fichier** `<NomClasse>.class.php` (`glob('plugins/*/core/class/<NomClasse>.class.php')`).
  Toute classe référencée depuis un **point d'entrée externe** (`core/ajax/*.ajax.php`, hooks cron,
  `desktop/php/*.php`, `install.php`) — via `Classe::`, `new Classe`, `catch (Classe …)` — doit soit avoir
  son **propre** fichier `<Classe>.class.php`, soit voir son chargement assuré en transitant par la classe
  principale `smartclim`/`smartclimCmd` (dont le fichier `smartclim.class.php` charge du même coup les
  classes annexes qu'il contient). Un appel **direct** à une classe annexe (ex. `smartclimAuxHomeApi`)
  depuis un point d'entrée externe = `Fatal error: Class not found` au runtime.
- **Centraliser les accès externes** : **tous** les appels HTTP passent par la brique du transport concerné
  (`smartclimAuxHomeApi`, `smartclimAuxCloudApi`) et tout le LAN par `smartclimBroadlinkLan` — jamais de
  cURL ou de socket épars. Le reste du plugin ne parle qu'à l'**API générique**, jamais à un transport.
- **Aucun code propriétaire hors des adaptateurs de transport** : offsets d'octets, noms de champs d'API et
  numérotations de modes restent confinés dans la brique du transport (ou dans les tables de
  `smartclimCapabilities`).
- Indentation **2 espaces** en PHP/JS pour `core/class`, `core/ajax`, `desktop/js`… ; ⚠️ **exception** :
  `desktop/php/*.php` (pages) sont en **tabulations + CRLF** — respecter l'existant fichier par fichier.
- Logs via `log::add('smartclim', 'debug'|'info'|'warning'|'error', $msg)` ; **jamais** de secret exposé.
- **Robustesse cron** : un équipement en erreur ne doit **pas** interrompre la boucle → `try/catch` **par
  équipement**. Un seul appel réseau global par cycle quand l'API le permet, puis distribution.
  ⚠️ **Période de grâce après commande** (~60 s) : un état scruté plus ancien qu'une commande envoyée ne
  doit pas écraser les champs commandés (anti-rollback de consigne/marche).
- Les `.htaccess` de `core/php`, `core/class`, `core/ajax`, `resources/`… interdisent l'accès web direct —
  **les conserver**.
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

L'outillage `/` fonctionne en **deux temps** :

- **Bootstrap — `/init-plugin`** : ✅ **déjà joué** (cadrage, analyses, renommage du squelette, specs
  fonctionnelles). Ne pas le relancer : il refuserait d'écraser un cadrage existant.
- **Implémentation — `/feature <spec>`** (à lancer par UC) : à partir d'une spec fonctionnelle, produit le
  plan technique, le fait valider, délègue l'implémentation à l'agent `php-jeedom-dev` (skill `dev`), lance
  les reviews croisées (`code-reviewer`, `security-reviewer`), puis la traduction (`translator`) et la
  capitalisation mémoire.

> **Maintenance de ce fichier** : `CLAUDE.md` est lu par **toute** future session. Le tenir à jour quand
> l'architecture, les conventions ou l'outillage changent. En revanche, l'**avancement détaillé** (quelle
> UC est faite, quel contrat a été confirmé) se consigne dans `.memory/specs/` et `.memory/analyse/`, pas
> ici.
