# SmartClim — Architecture Jeedom (modèle eqLogic, commandes, config, cycle de vie)

> **Traduction du modèle abstrait (`smartclim-modele-abstrait-capacites.md`) en objets Jeedom.**
> S'appuie sur `CLAUDE.md` (conventions, autoload, i18n, secrets) et sur les analyses génériques
> `jeedom-widgets-commandes.md` / `jeedom-panel-page-menu.md` — **non reproduites ici**.
>
> **Date** : 2026-08-24.

---

## 1. Identité du plugin

| Élément | Valeur | Justification |
|---|---|---|
| `id` | `smartclim` | validé |
| `name` | `SmartClim` | validé |
| `category` | **`wellness`** | Le plugin rend un service de **confort thermique** à l'utilisateur final (« Bien-être » dans le market FR), catégorie où vivent les plugins de chauffage/climatisation/thermostat. `devicecommunication` décrirait un **pont d'appareils** (l'aspect multi-transport est un moyen, pas la finalité). Repli si la modération du market préfère : `devicecommunication`. |
| `hasOwnDeamon` | **`false`** au MVP | cf. `smartclim-daemon-choix.md` |
| `hasDependency` | **`false`** au MVP | aucun paquet pip/apt tant qu'il n'y a pas de démon |
| `licence` | **`GPL`** (était `AGPL`, aligné à l'UC02 du domaine post-MVP 07 : `LICENSE` portait GPL **v2**, les en-têtes GPL **v3**) | crédits MIT/Apache-2.0 à conserver, cf. `smartclim-ecosysteme-aux-broadlink.md` § 6 et `docs/fr_FR/credits.md` |
| `description` | ≥ 80 caractères **par langue**, **dans `info.json`** | règle market ; ⚠️ **pas** dans `core/i18n/*.json` (cf. `CLAUDE.md`) |

## 2. Classes et fichiers (⚠️ 1 classe ↔ 1 fichier + `require_once` obligatoire)

| Fichier | Classe(s) | Rôle |
|---|---|---|
| `core/class/smartclim.class.php` | `smartclim extends eqLogic`, `smartclimCmd extends cmd` | cycle de vie, crons, orchestration |
| `core/class/smartclimException.class.php` | `smartclimException extends Exception` | erreurs du plugin (avec un code : auth, réseau, protocole, capacité) |
| `core/class/smartclimCapabilities.class.php` | `smartclimCapabilities` | énumérations génériques + tables de correspondance + profils |
| `core/class/smartclimTransport.class.php` | `smartclimTransport` | routage `AUTO`/`LOCAL`/`CLOUD`, compteurs d'échec, repli |
| `core/class/smartclimAuxHomeApi.class.php` | `smartclimAuxHomeApi` | **brique d'accès unique** au cloud AUX Home |
| `core/class/smartclimAuxCloudApi.class.php` | `smartclimAuxCloudApi` | cloud legacy *(post-MVP)* |
| `core/class/smartclimBroadlinkLan.class.php` | `smartclimBroadlinkLan` | LAN Broadlink UDP *(post-MVP)* |
| `core/class/smartclimFrame.class.php` | `smartclimFrame` | décodage/encodage des trames HVAC `bb00…` **mutualisé LAN ↔ AUX Home** |

> ⚠️⚠️ **VÉRIFIÉ EN RECETTE UC02 — l'ancienne version de cette règle était FAUSSE.** Il n'y a **aucun
> `glob`** dans l'autoloader Jeedom : `jeedomAutoload()` (`core/php/core.inc.php`) ne charge qu'**un seul
> fichier par plugin**, `plugins/<id>/core/class/<id>.class.php`. Code réel du core :
>
> ```php
> $classname = str_replace(array('Real', 'Cmd'), '', $_classname);
> $plugin_active = config::byKey('active', $classname, null);
> if (($plugin_active === null || …) && strpos($classname, '_') !== false) {
>     $classname = explode('_', $classname)[0];      // seule porte de sortie
>     $plugin_active = config::byKey('active', $classname, null);
> }
> if ($plugin_active == 1) { include_file('core', $classname, 'class', $classname); }
> ```
>
> - Un nom **sans `_`** qui n'est pas l'id du plugin (`smartclimAuxHomeApi`, `smartclimException`) ne prend
>   jamais la branche de repli : `$plugin_active` reste `null`, l'autoloader **ne fait RIEN — sans erreur,
>   sans log**. Symptôme constaté sur Jeedom réel (2026-08-25) :
>   `Error : Class 'smartclimAuxHomeApi' not found (smartclim.class.php:76)` et
>   `Class 'smartclimException' not found (smartclim.class.php:131)`.
> - Même **avec** un `_`, seul `<id>.class.php` serait inclus : un fichier `<Classe>.class.php` séparé
>   **n'est jamais chargé tout seul**. `smartclimCmd` marche parce qu'elle est **dans**
>   `smartclim.class.php` — d'où le `str_replace('Cmd')` du core.
> - Donc **avoir son propre fichier ne suffit PAS** : « 1 classe ↔ 1 fichier » est une convention de
>   lisibilité de ce plugin, **pas** un mécanisme de chargement.
>
> **Règle réelle** : chaque classe du tableau ci-dessus est déclarée dans son fichier **et** listée en
> `require_once` dans **`core/php/smartclim.inc.php`**, inclus en tête de `core/class/smartclim.class.php`
> (le seul fichier que l'autoloader charge). Ainsi tout est disponible dès que `smartclim`/`smartclimCmd`
> est résolue, depuis n'importe quel point d'entrée. **Ajouter chaque nouvelle classe à cette liste** —
> l'oubli est invisible à `php -l` **et** à la CI, et ne se voit qu'au runtime, sur le seul chemin de code
> qui touche la classe manquante.
>
> ⚠️ **Centralisation** (`CLAUDE.md`) : aucun `curl_*` ni socket hors de ces classes de transport.

## 3. Modèle eqLogic

> **1 eqLogic `smartclim` = 1 climatiseur (une unité intérieure pilotable).**

Un compte AUX peut porter plusieurs appareils : les identifiants de compte sont donc en **configuration
plugin** (globale), pas par équipement. Un équipement est **créé par la découverte**, jamais à la main
(mais son nom, son objet parent et ses options restent éditables).

### 3.1 `logicalId` de l'équipement — la clé stable

```text
logicalId = "mac:" + MAC normalisée         → ex.  mac:a1b2c3d4e5f6
```

Normalisation : minuscules, séparateurs retirés (`:`, `-`, `.`).

Repli si aucune MAC n'est disponible :

```text
"auxhome:"  + deviceId        |  "auxcloud:" + endpointId  |  "lan:" + mac
```

**Pourquoi la MAC ?** C'est le **seul identifiant présent dans les trois transports** :
`/app/user_device` renvoie `mac` ✅, le cloud legacy renvoie `mac` ✅, la découverte Broadlink renvoie la MAC
✅. C'est donc la clé de fusion demandée au `.memory/brief.md` § 12.

### 3.2 Configuration d'équipement

> ⚠️ **Table réalignée sur le code livré le 2026-09-16** (UC02 du domaine post-MVP 06). La version
> précédente datait de la conception et listait des clés **prévisionnelles qui n'ont jamais existé**
> (`capabilities`, `temp_step`, `lan_mac_source`, `transport_actif`, `etat_optimiste`, `marque`,
> `auxcloud_region` au niveau équipement) : elle a été citée comme référence par une spec fonctionnelle
> avant qu'on s'aperçoive qu'aucune de ces clés n'était dans le code. **Le code fait foi** — les clés
> ci-dessous sont les constantes `smartclim::CLE_CONF_*`, sauf les trois littérales signalées.

| Clé | Contenu |
|---|---|
| `mac` *(littérale)* | MAC normalisée — posée par `memoriserMacEquipement()` **seulement si vide** |
| `modele` *(littérale)* | informatif |
| `auxhome_device_id` *(littérale)* | identifiant AUX Home |
| `transport_mode` | `auto` (défaut) / `local` / `cloud` — **minuscules**, et le défaut tient à l'**absence** de la clé |
| `capacites` | profil de capacités **détecté**, réécrit par chaque scan |
| `lan_ip`, `lan_mac` | adresses locales **saisies par l'utilisateur** (secours VLAN / diffusion filtrée), vide = non personnalisé |
| `temp_min`, `temp_max`, `temp_pas` | bornes **personnalisées**, vide = non personnalisé |
| `auxcloud_endpoint_id`, `auxcloud_product_id`, `auxcloud_devicetype_flag`, `auxcloud_family_id`, `auxcloud_partage`, `auxcloud_swing_inverse` | identité legacy de l'appareil, **en clair** (non sensible et stable) |

⚠️ **Trois choses qu'on chercherait ici à tort** : le **transport actif** est une **commande info**
(`smartclim::CMD_TRANSPORT`), pas une clé de configuration ; l'**état optimiste** vit en **cache**
(`smartclim::ordres::<id>`, 60 s) ; `auxcloud_region` est une clé de config **plugin**, jamais équipement.
⚠️ `CLE_MASQUAGE_TUILE` (`tuile_masquage_joue`) n'est pas non plus une clé d'équipement : elle est posée
sur la **configuration de la commande `power`** — un `setConfiguration()` sur l'eqLogic y provoquerait une
récursion `save()` puis `postSave()`.
⚠️ **Détecté et personnalisé sont deux espaces disjoints, et doivent le rester** : `capacites` contre
`temp_*`, adresse LAN détectée (en cache `smartclim::lan_appareil::<mac>`) contre `lan_ip`/`lan_mac`.
C'est cette séparation — pas une convention de nommage — qui garantit qu'une redétection n'écrase jamais
une personnalisation.

Aucun secret au niveau équipement dans le périmètre actuel. Si un jour un secret par équipement apparaît
(passcode AUXLink), utiliser `encrypt()`/`decrypt()` **d'instance** — pas `$_encryptConfigKey` (qui ne vaut
que pour la config **plugin**).

### 3.3 Configuration plugin (`plugin_info/configuration.php`)

> ⚠️ Rappel `CLAUDE.md` : **lire/écrire uniquement `plugin_info/configuration.txt`**, puis
> `cp plugin_info/configuration.txt plugin_info/configuration.php`. Vérifier par
> `git status --short plugin_info/configuration.php`, ne jamais relire le `.php`.

> ⚠️ **Table réalignée sur le code livré le 2026-09-16.** La version précédente écrivait `auxhome_login`
> et `auxcloud_login` (réels : `auxhome_email`, `auxcloud_email`) et affirmait que le pays était **déduit
> du fuseau horaire de Jeedom** — heuristique **révoquée en recette d'UC01** : le fuseau ne dit rien du
> pays d'un compte cloud, et un pays faux échoue au login sur un message trompeur.

| Clé | Type | Chiffrée |
|---|---|---|
| `auxhome_email` | e-mail | non |
| `auxhome_password` | mot de passe | **oui** |
| `auxhome_country` | code ISO-3 majuscules, **défaut constant** `smartclim::PAYS_DEFAUT` = `FRA` | non |
| `refresh_interval` | minutes (1..1440, défaut 5 via l'INI) | non |
| `auxcloud_email` | e-mail du compte legacy | non |
| `auxcloud_password` | mot de passe du compte legacy | **oui** |
| `auxcloud_region` | `EU` / `USA` / `CHN` / `RUS`, **défaut constant** `smartclim::REGION_DEFAUT` = `EU` | non |
| `demon_port` | port du démon, défaut 55112 — ⚠️ **sans aucun champ de formulaire** (échappatoire API) | non |

⚠️ Les deux comptes sont **indépendants** : on peut renseigner l'un, l'autre, les deux, ou aucun —
`compteConfigure()` (AUX Home) et `compteAuxCloudConfigure()` (legacy) sont deux garde-fous distincts.
⚠️ Les valeurs par défaut vivent **en double** : dans la constante PHP **et** en littéral dans
`core/config/smartclim.config.ini` (seul défaut vu par `config::byKeys()`, donc par le formulaire).

```php
public static $_encryptConfigKey = array('auxhome_password', 'auxcloud_password');
```

Hooks disponibles : `preConfig_auxhome_country()` (normaliser en majuscules, valider 3 lettres),
`postConfig_auxhome_password()` (invalider le jeton en cache). ⚠️ Ce sont des **noms de méthode fixes**,
pas une boucle dynamique.

**Jeton de session** : jamais en configuration. Clé réelle **`smartclim::session_auxhome`** (30 min,
`utils::encrypt(json_encode(...))`), et non `smartclim::auxhome::token` comme l'écrivait la version
précédente de cette section. Deux autres familles existent : `smartclim::session_auxcloud` (legacy,
globale au compte) et `smartclim::session_lan::<mac>` (une par appareil). Purge sur changement
d'identifiants — ⚠️ `config::remove()` ne déclenche **pas** les hooks `postConfig_`.

## 4. Fusion des doublons LAN / cloud

> ⚠️ **Section réalignée sur le code livré le 2026-09-16.** Elle décrivait 4 étapes ; l'implémentation en
> a **8**, et surtout elle applique **tous les ordres directs avant tous les ordres inversés** (correction
> apportée à l'UC04 du domaine post-MVP 01) — l'ordre naïf « MAC puis MAC inversée, clé par clé » est
> précisément ce qu'il ne faut pas faire.

Rapprochement **unique**, implémenté par
`smartclim::chercherEquipementExistant($mac, $deviceId, $index, $transport = '', $endpointAuxCloud = '')`
et emprunté par les **trois** sens de scan. Ordre figé :

1. à 3. les trois clés **directes** : `logicalId`, `configuration.mac`, `lan_mac` ;
4. à 6. les **mêmes**, sur la **MAC inversée** — ⚠️ les implémentations Broadlink lisent la MAC dans des
   **ordres d'octets opposés** (`ac_freedom` inverse, `fparrav` non ; cf.
   `smartclim-transport-broadlink-lan.md` § 6) ;
7. `auxhome_device_id` ;
8. `auxcloud_endpoint_id` ;

sinon → nouvel équipement.

⚠️ **Trois gardes non négociables** : `lan_mac` ne rapproche **que** pour le transport LAN et
`auxcloud_endpoint_id` **que** pour le transport legacy (ce sont des déclarations liées à un transport ;
s'en servir ailleurs attacherait, sur une faute de frappe, un appareil neuf à l'équipement d'un autre) ;
et les étapes inversées sont **sautées sur une MAC palindrome**.

Un équipement fusionné cumule les identifiants de plusieurs transports (`auxhome_device_id` **et**
`lan_ip`), ce qui est précisément ce qui rend le mode `AUTO` possible.

**Idempotence** : relancer un scan ne doit jamais dupliquer ni recréer. Les créations/mises à jour passent
par `eqLogic::byLogicalId($logicalId, 'smartclim')`.

## 5. Commandes : nomenclature des `logicalId`

> Les `logicalId` de commande sont **génériques et stables** : ils ne changent jamais quand le transport
> change. C'est ce qui garantit qu'un scénario utilisateur survit à une bascule cloud → LAN.

### 5.1 Informations

| `logicalId` | `subType` | Unité | Notes |
|---|---|---|---|
| `online` | binary | | |
| `power` | binary | | |
| `mode` | string | | valeur générique (`COOL`…), libellé traduit au widget |
| `target_temp` | numeric | °C | |
| `ambient_temp` | numeric | °C | ⚠️ **fraîcheur faible en AUX Home** (§ 7) |
| `fan_speed` | string | | valeur générique |
| `swing_v`, `swing_h` | binary | | |
| `display`, `sleep`, `eco`, `health`, `mildew`, `clean`, `child_lock`, `comfort_wind`, `aux_heat` | binary | | **créées seulement si supportées** |
| `error_code` | string | | |
| `transport` | string | | transport actif (`AUX Home`, `LAN`, …) |
| `last_update` | string | | horodatage de la dernière donnée fraîche |

### 5.2 Actions

| `logicalId` | `subType` | Notes |
|---|---|---|
| `on`, `off` | other | |
| `set_target_temp` | **slider** | `minValue`/`maxValue`/`step` issus du profil de capacités |
| `mode_auto`, `mode_cool`, `mode_dry`, `mode_heat`, `mode_fan` | other | **une par mode supporté** |
| `fan_auto`, `fan_silent`, `fan_low`, `fan_medium`, `fan_high`, `fan_turbo`, … | other | idem |
| `swing_v_on` / `swing_v_off`, `swing_h_on` / `swing_h_off` | other | |
| `display_on`/`off`, `sleep_on`/`off`, `eco_on`/`off`, … | other | |
| `refresh` | other | force une lecture immédiate |

**Choix assumé** : des commandes **unitaires** plutôt qu'une commande paramétrée unique. Motifs : elles se
posent directement dans un scénario ou sur un dashboard sans manipulation de valeur, elles reflètent
exactement les capacités détectées, et elles évitent de dépendre de la disponibilité du `subType` `select`
selon les versions de core. Une commande paramétrée (`message`) reste une extension possible pour les
usages avancés.

**Liens `value`** : chaque commande action est liée à sa commande info correspondante (`setValue`) pour que
Jeedom affiche l'état sur le bouton.

### 5.3 Création dynamique — règles

1. Une commande n'est créée que si `capabilities.supported` (et, pour les modes/vitesses, la liste
   d'énumération) la couvre.
2. La création est **idempotente** : `cmd::byEqLogicIdAndLogicalId()` avant tout `new smartclimCmd`.
3. ⚠️ **Ne jamais écraser un choix utilisateur** : nom, `isVisible`, `isHistorized`, template de widget ne
   sont posés qu'**à la création** (ou « si vide »), jamais à chaque `postSave` — cf.
   `jeedom-widgets-commandes.md` § 6.
4. Une capacité qui disparaît **ne supprime pas** la commande (§ 4.3 du modèle abstrait).

## 6. Cycle de vie et crons

| Hook | Usage |
|---|---|
| `postSave()` | (re)création des commandes depuis le profil de capacités ; **jamais** d'appel réseau bloquant |
| `preRemove()` | purge du cache d'état de l'équipement |
| `cron()` (chaque minute) | **SEUL hook de rafraîchissement**, depuis UC07 : garde d'échéance en cache (`smartclim::dernier_cycle`, marge de 30 s) puis, si l'échéance est atteinte, un seul appel `/app/user_device?getStatus=1` pour tous les équipements AUX Home, puis distribution |
| `cron5()` … `cronDaily()` | **non implémentés** — restent commentés dans la classe, donc invisibles du core |

⚠️ **Un seul hook, décidé en UC07** (D-MVP07-01, `.memory/auto-dev/run-20260826-1904/MVP-07/decisions.md`) —
cette section prévoyait à l'origine `cron5()` comme hook principal plus `cron()` en renfort à 1 minute. Ce
montage ne peut **structurellement pas** honorer un intervalle réglé sur 1 min (critère AC8 d'UC07), et il
crée deux interrupteurs core désynchronisables (`functionality::cron::enable` et
`functionality::cron5::enable`, réglables indépendamment dans l'onglet « Fonctionnalités ») donc un risque
de double exécution du cycle. Le coût d'un tick non échu est d'**une lecture de cache**, sans aucune requête
SQL. La re-détection des capacités **n'est pas** portée par un cron : le vecteur de migration du parc est le
**scan manuel** (UC03) — le cycle de rafraîchissement est en **lecture d'état seule** et n'émet aucun
`save()` d'équipement.

⚠️ **Robustesse cron** (`CLAUDE.md`) : `try/catch` **par équipement**. Un climatiseur en erreur ne doit
jamais interrompre la boucle. L'appel réseau global (liste) est lui aussi en `try/catch` : en cas d'échec,
tous les équipements passent en `online = 0` sans effacer leurs dernières valeurs.

**Choix d'intervalle** : le `.memory/brief.md` § 14 demande de privilégier le push. Comme **aucun push n'est
confirmé** sur AUX Home (`smartclim-transport-aux-home.md` § 7) **et** que la donnée d'ambiance y est
intrinsèquement lente (minutes à 30 min), la scrutation à 5 min est un choix **informé**, pas un pis-aller.
Le push est traité en post-MVP, avec démon.

## 7. Fraîcheur, état optimiste et affichage

1. Après une commande : appliquer immédiatement l'**état optimiste** sur les commandes info (retour visuel
   instantané) et l'horodater.
2. Pendant une **période de grâce** (`smartclim::DUREE_GRACE` = **60 s**, constante — pas une clé de
   configuration, à recalibrer en recette si besoin), un état scruté ne remplace pas les champs
   commandés. Sans cela : consigne qui « revient » à sa valeur précédente, arrêt qui repasse en marche,
   oscillation d'état ⚠️ (`ha-aux-a-plus/docs/RESEARCH_PLAYBOOK.md` § 4 ; `fparrav/src/platform.ts`
   `pendingCommands`).
3. **Dédupliquer** les commandes identiques rapprochées (le climatiseur bipe à chaque ordre reçu).
4. Exposer `last_update` **et** `transport` : l'utilisateur doit voir d'où vient la donnée et de quand elle
   date (`.memory/brief.md` § 4 « le plugin doit afficher clairement quel transport est actuellement utilisé »).

## 8. Points d'entrée UI

- **`desktop/php/smartclim.php`** — page d'administration (⚠️ **tabulations + CRLF**, cf. `CLAUDE.md`) :
  liste des équipements, bouton **« Scanner les climatiseurs »**, panneau « Capacités détectées »,
  « Transport actif », IP LAN manuelle.
- **`core/ajax/smartclim.ajax.php`** — `isConnect('admin')` + `ajax::init()` ; actions : `testConnexion`,
  `scan`, `rafraichir`, `sonderLan` *(post-MVP)*.
- **Widget de commande** (post-MVP) : tuile « climatiseur » agrégeant plusieurs commandes — mécanisme,
  tokens et résolution des commandes sœurs : voir `jeedom-widgets-commandes.md` §§ 1-4. ⚠️ Un widget de
  dashboard **ne peut pas** appeler `smartclim.ajax.php` (admin only) → passer par `jeedom.cmd.execute`
  (§ 4-5 de cette analyse).
- **Page-panneau** (post-MVP) : vue utilisateur multi-climatiseurs — voir `jeedom-panel-page-menu.md`
  (déclaration `info.json "display"`, toggles natifs, `isConnect()` non-admin + `hasRight('r')`).

## 9. Sécurité (rappels applicables ici)

- Aucun mot de passe / jeton complet dans les logs, le DOM, les réponses AJAX. Masquage :
  `bearer abc123…`.
- Les réponses AJAX de scan ne renvoient **jamais** le jeton ni les identifiants.
- TLS **toujours vérifié** (contrairement aux implémentations de référence, cf.
  `smartclim-transport-aux-cloud-legacy.md` § 1).
- Conserver les `.htaccess` de `core/php`, `core/class`, `core/ajax`, `resources/`.

## 10. À confirmer

- [ ] Catégorie market : `wellness` accepté par la modération Jeedom ?
- [ ] Disponibilité du `subType` action `select` sur le core cible (`require: 4.2`) si l'on veut un jour
      une commande de mode unique.
- [ ] Nombre d'équipements typique par compte (impacte le choix cron global vs par équipement).
