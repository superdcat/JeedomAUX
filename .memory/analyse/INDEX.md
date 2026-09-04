# Index des analyses internes — connaissance Jeedom réutilisable

> **But** : rendre la connaissance interne (décisions d'architecture, limites/pièges, apprentissages
> durables) **découvrable et lazy-loadable** par le workflow de dev, sans tout charger. L'agent lit cet
> index (gratuit, local), repère le fichier d'analyse utile, puis ouvre **uniquement** ce fichier.
>
> `.memory/analyse/` complète `.memory/specs/` (intention des features) et la doc externe
> (`.memory/external/doc/`) : ici on consigne ce que **le projet a tranché** ou ce qu'on a **appris en
> codant** — ce que ni le code, ni git, ni `CLAUDE.md` ne disent déjà.
>
> **Maintenance** : à chaque enseignement durable (Étape 12 du workflow `/feature`), écrire dans le bon
> fichier thématique (ou en créer un) **et mettre à jour cet index** (ligne + déclencheurs § 0 + date).
>
> Le template est livré avec **deux analyses génériques Jeedom** (vérifiées contre la source du core),
> réutilisables par tout plugin. S'y ajoutent les analyses **propres au plugin SmartClim** (climatiseurs
> AUX / Broadlink / AC Freedom), produites lors du cadrage `/init-plugin`.
>
> **Dernière mise à jour de cet index : 2026-09-03** (UC04 du domaine `post-mvp/01` : § 12 ajouté à
> `jeedom-config-plugin-et-cycle-de-vie.md` — **XSS stocké du squelette Jeedom** : `desktop/php/<id>.php`
> rend `getHumanName()` sans échappement, et `cleanComponanteName()` ne filtre **ni `<` ni `>`** ; la
> sévérité bascule dès qu'une source de découverte **non authentifiée** peut écrire le nom. Précédemment,
> UC03 du domaine `post-mvp/01` : § 13 ajouté à
> `smartclim-transport-broadlink-lan.md` — algorithme complet de la somme de contrôle de la charge
> HVAC **avec ses vecteurs, dont le cas de longueur impaire** ; en-tête d'écriture contre en-tête de
> lecture ; stratégie de fusion **par octets** et pourquoi la référence a tort ; écriture
> **idempotente**. Précédente : 2026-09-02, UC02 du domaine `post-mvp/01` : § 12 ajouté à
> `smartclim-transport-broadlink-lan.md`, et **§ 5.2 corrigé** — la « divergence » d'offset du
> demi-degré était un **décalage d'espace de comptage**, pas un désaccord de fait ; décodeur de
> trame HVAC désormais **mutualisé** entre les deux transports dans `smartclimFrame`).
> Précédente : 2026-08-28 (UC01 du domaine `post-mvp/01` : § 11 ajouté à
> `smartclim-transport-broadlink-lan.md` — compteur, codes d'erreur appareil, verrou d'appareil,
> diffusion UDP en PHP ; 3 divergences ⚠️ tranchées contre `python-broadlink`). Précédente (création de
> `jeedom-config-plugin-et-cycle-de-vie.md` au cycle UC01, étendu de 4 sections au cycle UC02 ;
> révision des timeouts dans `smartclim-transport-aux-home.md` § 8 ; au cycle UC04, fermeture de l'incertitude
> « détection des capacités AUX Home » dans `smartclim-modele-abstrait-capacites.md` §§ 4.1, 4.3 et 6 ; au cycle UC05, ajout de la
> section 8 « Créer et alimenter des commandes INFO » à `jeedom-widgets-commandes.md` ; au cycle UC07,
> réécriture du § 6 « Cycle de vie et crons » de `smartclim-architecture-jeedom.md` — **un seul hook
> `cron()`**, l'ancien montage `cron5()` + `cron()` y était devenu faux — et précision du § 7 sur la
> période de grâce, qui est une **constante** et non une clé de configuration) ; au cycle UC08, ajout de
> la section 8.7 « `execCmd()` sur une commande ACTION l'EXÉCUTE » à `jeedom-widgets-commandes.md`.

---

## 0. Correspondance « incertitude » → fichier d'analyse (raccourci)

| Si l'incertitude porte sur… | Fichier |
|---|---|
| **Widget de commande** Jeedom (fichier `cmd.<type>.<subType>.<nom>.html`, `setTemplate`, tokens `#id#`…) | `jeedom-widgets-commandes.md` §§ 1-2 |
| Widget pilotant **plusieurs commandes** (tuile + actions) ; résoudre les sœurs par `byEqLogic` | `jeedom-widgets-commandes.md` § 3 |
| Exécuter une action depuis un widget + récupérer le retour PHP ; auth/CSRF AJAX ; AJAX plugin admin-only | `jeedom-widgets-commandes.md` §§ 4-5 |
| **Confirmation avant une action sensible** (dialog anti-fausse-manip) : comment l'activer côté serveur | `jeedom-widgets-commandes.md` § 4 (`actionConfirm=1` → -32006) |
| **Commande action PARAMÉTRÉE** (saisie utilisateur : subType `message`, valeur dans `$_options['message']`) | `jeedom-widgets-commandes.md` § 4 |
| Appliquer un **template de widget sans écraser** le choix utilisateur (« si vide ») | `jeedom-widgets-commandes.md` § 6 |
| **CSP Jeedom bloque tout média/image EXTERNE** → proxy same-origin (ex. tuile carte) | `jeedom-widgets-commandes.md` § 7 |
| ⚠️ **Nom de commande ampute silencieusement** par `cleanComponanteName()` (`/`, `'`, `"`, `&`…) — traductions comprises | `jeedom-widgets-commandes.md` § 8.1 |
| ⚠️⚠️ **Lire la valeur d'une commande** (`execCmd()`) : inoffensif sur une **info**, **exécute un ordre réel** sur une **action** → filtrer `getType() === 'info'` | `jeedom-widgets-commandes.md` § 8.7 |
| **Détecter qu'un cycle de scrutation a rapporté du neuf** : le booléen de `checkAndUpdateCmd()`, et son échappatoire `repeatEventManagement` | `jeedom-widgets-commandes.md` § 8.2 |
| **`collectDate` vs `valueDate`** : âge réel d'une donnée d'API lente ; l'état d'une commande vit dans le **cache**, pas en base | `jeedom-widgets-commandes.md` § 8.3 |
| ⚠️ **`cmd::event()` jette silencieusement** une valeur `numeric` hors `minValue`/`maxValue` | `jeedom-widgets-commandes.md` § 8.4 |
| **Créer des commandes de façon idempotente** sans N requêtes par cycle (`getCmd(null, null)` indexé) | `jeedom-widgets-commandes.md` § 8.5 |
| **`generic_type`** : enrôle automatiquement la commande dans les résumés d'objet et les intégrations tierces | `jeedom-widgets-commandes.md` § 8.6 |
| Ajouter une **PAGE** au menu Jeedom (panel) ; toggle natif `displayDesktopPanel/Mobile` ; page non-admin | `jeedom-panel-page-menu.md` |
| **Afficher une image externe dans un panel** (carte…) : `data:` URI inline (panel serveur) vs proxy (widget client) | `jeedom-panel-page-menu.md` § 4 |
| **Config plugin** : ordre réel de `config::save`, pourquoi `preConfig_<clé>` est parfois **court-circuité**, pourquoi valider **en lecture ET en écriture** | `jeedom-config-plugin-et-cycle-de-vie.md` § 1 |
| **Valeur par défaut** d'une clé de config (`core/config/<id>.config.ini`) ; défaut **dynamique** non exprimable en INI | `jeedom-config-plugin-et-cycle-de-vie.md` § 2 |
| `$_encryptConfigKey` : chiffré au repos **mais renvoyé en clair au navigateur** ; ne jamais vider un champ mot de passe en JS | `jeedom-config-plugin-et-cycle-de-vie.md` § 3 |
| **Un `throw` dans `preConfig_*` fait PERDRE les clés suivantes** (`addKey` boucle sans transaction) | `jeedom-config-plugin-et-cycle-de-vie.md` § 4 |
| Écrire un défaut dynamique **par construction** ; garde d'auth de `plugin_info/configuration.php` | `jeedom-config-plugin-et-cycle-de-vie.md` § 5 |
| ⚠️ **`<id>_remove()` est appelée à chaque DÉSACTIVATION** du plugin → n'y rien mettre de destructif | `jeedom-config-plugin-et-cycle-de-vie.md` § 6 |
| **Fichier du plugin exposé au web sans authentification** (`.txt` dans `plugin_info/`, dossiers en point, `.git/`) | `jeedom-config-plugin-et-cycle-de-vie.md` § 7 |
| ⚠️⚠️ **Afficher un nom d'équipement / une donnée externe dans une page `desktop/`** → le squelette Jeedom `echo` **sans échappement** (XSS stocké) | `jeedom-config-plugin-et-cycle-de-vie.md` § 12 |
| **`cleanComponanteName()` protège-t-il du HTML ?** → NON (ni `<` ni `>`) : un filtre hérité ne couvre que son propre contexte | `jeedom-config-plugin-et-cycle-de-vie.md` § 12.2 |
| **Durcir une fonction de nettoyage** dont la valeur sert aussi à construire une clé (`logicalId`, cache, index) | `jeedom-config-plugin-et-cycle-de-vie.md` § 12.5 |
| `cache::byKey()` et une entrée **expirée** ; comment invalider une entrée non expirée mais devenue fausse | `jeedom-config-plugin-et-cycle-de-vie.md` § 8 |
| ⚠️ **Un appel AJAX long FIGE toute l'interface Jeedom** (verrou de session fichier) → `session_write_close()` | `jeedom-config-plugin-et-cycle-de-vie.md` § 9 |
| **Manipuler un SECRET sans le laisser fuir** : trace d'exception, warnings `openssl_*`, `catch (Throwable)`, `displayException()` | `jeedom-config-plugin-et-cycle-de-vie.md` § 10 |
| **Journaliser une donnée externe** (API tierce ou entrée client) : injection de log, UTF-8, écho de champ chiffré | `jeedom-config-plugin-et-cycle-de-vie.md` § 11 |
| Valider une valeur destinée à un **en-tête HTTP** (`\z` et non `$`) ; caster ce qui doit être numérique | `jeedom-config-plugin-et-cycle-de-vie.md` § 11 |

### SmartClim — plugin climatiseurs AUX / Broadlink / AC Freedom

| Si l'incertitude porte sur… | Fichier |
|---|---|
| **Quel protocole parle CET appareil ?** générations G1/G2/G3, marques, matrice de décision, **une MAC Broadlink ne garantit rien** | `smartclim-ecosysteme-aux-broadlink.md` §§ 1-4 |
| **Licences** des projets étudiés : qui est réutilisable, sous quelle condition (AGPL du plugin) | `smartclim-ecosysteme-aux-broadlink.md` § 6 |
| **AUX Home** (`eu-smthome-api.aux-global.com`) : `getPubkey`, chiffrement RSA/AES du login, bearer, `/app/user_device`, `/app/device/v2/control` | `smartclim-transport-aux-home.md` §§ 1-4 |
| En-tête **`country` (ISO-3)** : cause documentée d'échec de login AUX Home | `smartclim-transport-aux-home.md` § 5 |
| Décoder `status.control` / `status.running` (trames `bb00…`) ; **température ambiante = octet[15] − 32** ; fraîcheur très lente | `smartclim-transport-aux-home.md` § 6 |
| **Broadlink LAN** : découverte broadcast, auth `0x65`, AES-128-CBC, structure de paquet, décodage/encodage d'état | `smartclim-transport-broadlink-lan.md` §§ 1-6 |
| **Deux sources de reverse engineering se contredisent sur un offset ?** Vérifier D'ABORD dans quel **espace** chacune compte : réponse LAN déchiffrée (préfixe de longueur de 2 octets) *contre* charge HVAC nue. `offset charge HVAC = offset réponse LAN - 2` | `smartclim-transport-broadlink-lan.md` § 5.2 (encadré) et § 12 |
| **Où vit le décodeur de trame HVAC** (offsets d'octets), et pourquoi il est mutualisé entre les transports | `smartclim-transport-broadlink-lan.md` § 12 |
| ⚠️⚠️ **Écrire un état en LAN** : l'écriture porte un **état COMPLET, jamais un delta** (champ absent = 0 = extinction). Fusionner **par OCTETS** en recopiant la trame lue, jamais par paramètres décodés | `smartclim-transport-broadlink-lan.md` §§ 5.4-5.5 et § 13.3 |
| **Générer la somme de contrôle de la charge HVAC** : algorithme, traitement du **dernier octet d'une longueur impaire**, et 4 vecteurs de contrôle (les 2 magics de lecture ne suffisent pas à valider) | `smartclim-transport-broadlink-lan.md` § 13.1 |
| En-tête d'**écriture** contre en-tête de **lecture** : 2 octets d'écart (6 et 8) ; ne pas confondre l'octet 6 avec le marqueur `0x0F` de l'octet 12 | `smartclim-transport-broadlink-lan.md` § 13.2 |
| ⚠️ LAN : l'écriture est **IDEMPOTENTE** (état absolu) — un rejeu réseau sur une écriture est légitime, ne pas le désactiver | `smartclim-transport-broadlink-lan.md` § 13.3 |
| ⚠️ LAN : plage de consigne **encodable** `[8, 39] °C` (5 bits), plus étroite que l'enveloppe personnalisable du plugin | `smartclim-transport-broadlink-lan.md` § 13.3 |
| LAN : **somme de contrôle de la charge HVAC** (complément à un 16 bits big-endian) — **distincte** de celle du paquet `0x38` ; nécessaire à l'**écriture** | `smartclim-transport-broadlink-lan.md` § 12 |
| LAN : pourquoi le profil de capacités LAN ne publie **ni modes ni vitesses** (il ne peut rien exclure, et l'union réintroduirait un mode exclu sur preuve) | `smartclim-transport-broadlink-lan.md` § 12 |
| ⚠️ LAN : **code d'erreur** renvoyé par l'appareil (signé, en `0x22`), sens de `-7` / `-4012` / `-1`, appareil **verrouillé** depuis l'application constructeur, bit 15 du compteur | `smartclim-transport-broadlink-lan.md` § 11 |
| **Diffusion UDP en PHP** : extension `sockets` absente, socket connecté qui ne voit pas les réponses, dégradation acceptable | `smartclim-transport-broadlink-lan.md` § 9 |
| ⚠️ **Valider une adresse IP en PHP** : `ip2long()` est signé et PHP est 32 bits sur armhf — `10.x` rejetée à tort, masque `0xFF000000` devenu flottant. Raisonner sur les octets | `smartclim-transport-broadlink-lan.md` § 9 |
| ⚠️ LAN : **une seule session par appareil** — s'authentifier décroche l'autre logiciel (et réciproquement) ; pourquoi la session ne peut pas vivre en mémoire du process PHP | `smartclim-transport-broadlink-lan.md` § 11 |
| ⚠️ LAN : l'écriture est un **état COMPLET** (un champ manquant = 0 = extinction) ; marqueur `0x0F` obligatoire | `smartclim-transport-broadlink-lan.md` §§ 5.4-5.5 |
| Repli LAN → cloud (seuil de 3 échecs, backoff) | `smartclim-transport-broadlink-lan.md` § 7 |
| **AUX Cloud legacy** (`app-service-*.smarthomecs.*`) : login AES, familles, `sdkcontrol`, `cookie` à recomposer, WebSocket relay | `smartclim-transport-aux-cloud-legacy.md` |
| ⚠️ Toujours inclure `pwr` dans une commande cloud legacy | `smartclim-transport-aux-cloud-legacy.md` § 4.1 |
| **Tables de correspondance** générique ↔ codes propriétaires (modes, vitesses, oscillations, échelles de température) — ⚠️ 3 numérotations différentes | `smartclim-modele-abstrait-capacites.md` § 3 |
| **Profil de capacités** : comment le détecter par transport, comment le faire évoluer sans casser les scénarios | `smartclim-modele-abstrait-capacites.md` § 4 |
| ⚠️ **Détection AUX Home tranchée (UC04)** : profil déduit de la **longueur des trames `status.*`** du scan, `getConfig` **jamais** interrogé ; clé persistée `capacites`, bornes personnalisées dans des clés **disjointes** `temp_*` | `smartclim-modele-abstrait-capacites.md` §§ 4.1 et 4.3 |
| **Modèle eqLogic**, `logicalId` (MAC normalisée), fusion des doublons LAN/cloud, config plugin chiffrée, crons | `smartclim-architecture-jeedom.md` §§ 1-6 |
| **Nomenclature des `logicalId` de commande** + règles de création dynamique | `smartclim-architecture-jeedom.md` § 5 |
| **Quel hook cron utiliser** pour un intervalle réglable (1..N min), garde d'échéance en cache, un seul appel réseau par cycle | `smartclim-architecture-jeedom.md` § 6 |
| **État optimiste / anti-état-périmé** après une commande, durée de la période de grâce | `smartclim-architecture-jeedom.md` § 7 |
| **Démon ou pas ?** Python vs Node vs PHP pur, dépendances `packages.json`, ce qui déclencherait une révision | `smartclim-daemon-choix.md` |

> Si aucun fichier ne couvre le sujet : ce n'est pas (encore) analysé en interne → passer à la doc externe
> (`.memory/external/doc/jeedom/INDEX.md` pour le core Jeedom, ou la doc de l'API tierce du plugin), et
> penser à capitaliser en Étape 12.

---

## 1. Catalogue des analyses

| Fichier | Sujet | Points clés indexés |
|---|---|---|
| `jeedom-widgets-commandes.md` | Widgets de commande Jeedom (templates dashboard/mobile), vérifié contre la source du core. | `cmd.<type>.<subType>.<nom>.html` + `setTemplate('<id>::<nom>')` ; tokens (`#id#`/`#logicalId#`/`#eqLogic_id#`/`#uid#`…) ; `#cmd_id[…]#` & `jeedom.cmd.byEqLogicId` **n'existent pas** → résoudre par AJAX **`byEqLogic`** ; **masqué ≠ non-exécutable** ; `jeedom.cmd.execute` (CSRF/droits, `success.result`=retour PHP) ; confirmation d'action `actionConfirm=1` → -32006 ; commande **paramétrée** subType `message` ; AJAX plugin admin-only inutilisable au dashboard ; **§ 7 CSP : média/image externe bloqué → proxy same-origin**. **§ 8 (cycle UC05) : créer et alimenter des commandes INFO** — `setName()` ampute le nom via `cleanComponanteName()` (traductions comprises) ; le booléen de `checkAndUpdateCmd()` est un détecteur de changement fiable, sauf `repeatEventManagement = 'always'` ; `collectDate` (collecte) ≠ `valueDate` (dernier changement), et l'état vit dans le **cache**, donc pousser des valeurs n'émet **aucun `save()`** ; ⚠️ `cmd::event()` **jette silencieusement** une valeur `numeric` hors `minValue`/`maxValue` → pas de bornes sur une commande info ; création idempotente en **une** lecture `getCmd(null, null)` plutôt que N `byEqLogicIdAndLogicalId()` ; `generic_type` enrôle automatiquement la commande dans les résumés d'objet et les intégrations tierces. |
| `jeedom-config-plugin-et-cycle-de-vie.md` | **Config plugin, hooks `preConfig`/`postConfig` et cycle de vie install/remove** — intégralement vérifié dans la source du core. | Ordre réel de `config::save` (défaut INI → `remove()` + **`preConfig_` NON appelé**) → d'où la **double barrière** valider-en-écriture-ET-en-lecture ; `postConfig_` d'une clé chiffrée reçoit **le chiffré** ; **pas de tiret dans les clés** (`preConfig_`/`postConfig_` ne manglent pas pareil) ; défauts via `core/config/<id>.config.ini` section `[<id>]`, fusionnés par `byKey` **et** `byKeys` ; `$_encryptConfigKey` déchiffré et **renvoyé en clair au navigateur** par `getKey` → ne **jamais** vider un champ mot de passe `configKey` en JS (écrasement silencieux) ; **`addKey` boucle sans transaction** → un `throw` dans `preConfig_*` perd les clés suivantes, donc normalisation silencieuse + `is_scalar` ; `configuration.php` = point d'entrée exécuté avant `getKey` (amorçage paresseux) et à passer en `isConnect('admin')` dès qu'il écrit ; ⚠️⚠️ **`<id>_remove()` appelée à chaque DÉSACTIVATION** (`plugin::setIsEnable(0)`) → rien de destructif dedans ; **exposition web** : `.txt` dans `plugin_info/`, dossiers en point, `.git/config` et son éventuel jeton. **§§ 8-11 (cycle UC02)** : `cache::byKey()` purge lui-même les entrées expirées (`getValue(null) !== null` suffit) mais **l'invalidation métier reste à la charge du plugin** (empreinte des identifiants) ; ⚠️ **`ajax::init()` NE ferme PAS la session PHP** → un appel AJAX long **fige toute l'interface** (sessions fichier), `session_write_close()` obligatoire, et un `timeout` jQuery **n'interrompt pas le PHP** ; **manipuler un secret** sans le laisser fuir par une trace (aucun secret en paramètre, `catch (Throwable)` qui capture sur place, ⚠️ `openssl_public_encrypt()` renvoie `false` **sans** lever d'exception, file d'erreurs OpenSSL globale à vider **en entrée aussi**, recréation d'exception dans les méthodes publiques, `Throwable` rattrapé à l'AJAX, jamais `displayException()`) ; **journaliser une donnée externe** (contrôle → UTF-8 → base64 → troncature ; ⚠️ « imprimables » ne bloque pas le base64, aucune troncature ne protège d'un chiffré **ECB**, et un filtre imprimable seul détruit les messages non-ASCII) ; regex d'en-tête en **`\z`, pas `$`**. **§ 12 (cycle UC04 post-mvp/01) : rendre une donnée externe dans une page `desktop/`** — le squelette `jeedom/plugin-template` `echo` `getHumanName()` **sans échappement** (XSS stocké réel) ; ⚠️ `cleanComponanteName()` **n'est pas un filtre HTML** (ni `<` ni `>`) : un assainissement hérité ne couvre que **son** contexte, jamais celui du point de sortie ; la gravité du sink dépend de **qui peut écrire le nom** (self-XSS pour un compte cloud, XSS stocké sans identifiant pour une découverte LAN non authentifiée) → un sink pré-existant **change de sévérité** quand le plugin ajoute une source non authentifiée ; correction en deux couches (`htmlspecialchars` au point de sortie + retrait `<>` **symétrique** entre transports) ; ⚠️ avant de durcir une fonction de nettoyage, tracer ses autres appelants — une valeur qui sert à construire une **clé** change de clé. |
| `jeedom-panel-page-menu.md` | Page de plugin au **menu** Jeedom (panel) & toggle d'affichage natif. | `info.json "display"`/`"mobile"` enregistre une page-panneau ; le core ajoute nativement les cases « Afficher le panneau desktop/mobile » (`displayDesktopPanel`/`displayMobilePanel`, masqué par défaut) → aucun toggle custom ; `plugin::getDisplay()` statique ; page panel = `isConnect()` non-admin + accès par eqLogic `hasRight('r')` + sélection par équipement ; **image externe : `data:` URI inline en panel serveur vs proxy same-origin en widget client** ; réf. `jeedom/plugin-gsl`. |

### Analyses propres au plugin **SmartClim** (créées le 2026-08-24, phase `/init-plugin`)

| Fichier | Sujet | Points clés indexés |
|---|---|---|
| `smartclim-ecosysteme-aux-broadlink.md` | Panorama de l'écosystème AUX / Broadlink / AC Freedom, matrice de décision de transport, licences des sources. | **3 générations** : G1 Broadlink UDP 80 · G2 AUX Cloud legacy (`smarthomecs`/`ibroadlink`) · G3 AUX Home / AUX A+ (`aux-global.com`, LAN **AUXLink** TCP 12416) ; ⚠️ **une MAC Broadlink ne garantit PAS le pilotage UDP** (cas de l'appareil de validation) ; **les 3 transports véhiculent la même trame HVAC `bb00…`** → décodeur mutualisable ; multimarque = critère protocole, pas whitelist ; ⚠️ les deux dépôts `azadaydinli` sont **sans licence** (pas de copie de code). |
| `smartclim-transport-aux-home.md` | **Transport du MVP** : nouveau cloud `eu-smthome-api.aux-global.com`. | Enveloppe `{code,message,data}` — **succès = `code == 200`**, pas le code HTTP ; `GET /app/auth/getPubkey` (**vérifié live**) → `POST /app/auth/login/pwd` (mot de passe **RSA/ECB/PKCS1** par blocs de 117 o., compte **AES-128-ECB** clé fixe de l'APK) ; **pas de refresh token** → re-login réactif ; en-tête **`country` ISO-3 critique** ; `GET /app/user_device?getStatus=1` (**un seul appel = tous les états**) ; `POST /app/device/v2/control` avec `{intent, dst, deviceId}` ; ⚠️ **une intention par requête** ; ambiante = `status.running` octet[15] − 32, **fraîcheur de plusieurs minutes à 30 min** ; **aucun push confirmé** ; ❓ table `wind_speed` contestée. |
| `smartclim-transport-broadlink-lan.md` | Transport LAN historique (UDP 80, protocole Broadlink AC). | Découverte broadcast (**port 80 seul**) ; auth `0x65` → clé + id de session, **attendre 200 ms** ; AES-128-CBC **zero padding**, ⚠️ IV octet 3 = `0x99` ; 2 sommes de contrôle distinctes ; magics `getState`(32 o.)/`getInfo`(48 o.) ; ⚠️ **l'écriture est un état COMPLET** (champ absent = 0 = extinction) et le marqueur `0x0F` de l'octet 12 est **obligatoire** ; repli LAN→cloud au **3ᵉ** échec ; ⚠️ MAC lue à des offsets/ordres **opposés** selon les références (ordre **inversé** confirmé, `fparrav` est l'exception) ; faisable **en PHP pur** (⚠️ diffusion = extension `sockets` **non garantie**, et jamais depuis un socket connecté) ; **§ 11 = ce qu'a établi UC01** (compteur, codes d'erreur, verrou d'appareil, session unique) ; **§ 13 = ce qu'a établi UC03** (somme de la charge **avec vecteurs impairs**, en-tête d'écriture, fusion par octets, écriture idempotente, consigne `[8, 39]`) ; **§ 12 = ce qu'a établi UC02** (les **deux espaces d'offsets** — `charge HVAC = réponse LAN - 2`, qui referme la fausse divergence du demi-degré ; somme de contrôle de la charge HVAC ; décodeur mutualisé `smartclimFrame` ; profil LAN sans modes ni vitesses). |
| `smartclim-transport-aux-cloud-legacy.md` | Transport cloud historique AC Freedom / AUX Cloud. | 4 régions (`smarthomecs.de/.com`, `ibroadlink.com`) ; login = SHA1+MD5+**AES-128-CBC** sur corps binaire ; `getfamilylist` → `dev/query` → `sdkcontrol` (get **et** set) ; ⚠️ `cookie` à **décoder puis recomposer** ; ⚠️ **toujours inclure `pwr`** sinon extinction ; `get` avec `params: []` = **meilleure détection de capacités** ; **WebSocket relay** (keep-alive 10 s) = **seul push confirmé de l'écosystème** ; ⚠️ les références désactivent TLS — **SmartClim ne le fera pas**. |
| `smartclim-modele-abstrait-capacites.md` | Abstraction `Device → Capabilities → Generic AC API → Transport` + tables de correspondance. | Contrat de transport (`decouvrir`/`sonder`/`lireEtat`/`lireCapacites`/`appliquer`) ; état générique ; ⚠️ **3 numérotations différentes** de `mode` (AUX Home = fil ≠ legacy) et de `fanSpeed` ; ⚠️ **3 échelles de température** (entier / bits / ×10) ; `SILENT`/`MEDIUM_LOW`/`MEDIUM_HIGH` **écrivables mais non relisables** en AUX Home ; profil de capacités persisté, **enrichi jamais amputé** (ne pas casser les scénarios). |
| `smartclim-architecture-jeedom.md` | Traduction Jeedom : eqLogic, commandes, config, crons, UI. | Catégorie **`wellness`** ; 8 classes, **1 classe ↔ 1 fichier** — ⚠️ mais l'autoload du core **ne charge que `<id>.class.php`** (aucun glob) : toute classe annexe DOIT être en `require_once` dans `core/php/smartclim.inc.php`, sinon « Class not found » au runtime, invisible en CI (§ 2, vérifié en recette UC02) ; **1 eqLogic = 1 climatiseur**, `logicalId = mac:<MAC normalisée>` ; fusion des doublons (⚠️ tester **MAC ET MAC inversée**) ; `$_encryptConfigKey` + jeton en **cache chiffré** ; ⚠️ `configuration.txt` → `cp` vers `.php` ; nomenclature **stable** des `logicalId` de commande (survit à un changement de transport) ; création dynamique idempotente **sans écraser les choix utilisateur** ; ⚠️ **un seul hook `cron()`** (chaque minute) avec garde d'échéance en cache, `cron5()`…`cronDaily()` non implémentées — un intervalle réglable à 1 min l'exige et deux hooks exposeraient deux interrupteurs core désynchronisables (§ 6, tranché en UC07) ; un appel réseau global par cycle + `try/catch` par équipement ; **état optimiste** + période de grâce `DUREE_GRACE` = 60 s **constante** + déduplication (anti-rollback, anti-bips). |
| `smartclim-daemon-choix.md` | Arbitrage démon : PHP pur vs Python vs Node.js. | **MVP sans démon** (`hasOwnDeamon: false`, `hasDependency: false`) : AUX Home est du REST et sa lenteur vient du backend ; PHP couvre RSA/AES/UDP nativement ; **le code réutilisable est massivement Python (MIT)** et couvre justement WebSocket/MQTT/TCP persistant ; Node = squelette et `packages.json` npm inexploitables en l'état ; ⚠️ `--daemon no` **supprime `resources/`** → à récupérer si un démon arrive ; rappels des pièges `packages.json` (version dans la valeur, exacte, pas de `dependancy_info()`). |
