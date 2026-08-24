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
| `licence` | `AGPL` (inchangé) | crédits MIT/Apache-2.0 à conserver, cf. `smartclim-ecosysteme-aux-broadlink.md` § 6 |
| `description` | ≥ 80 caractères **par langue**, **dans `info.json`** | règle market ; ⚠️ **pas** dans `core/i18n/*.json` (cf. `CLAUDE.md`) |

## 2. Classes et fichiers (⚠️ autoload : 1 classe ↔ 1 fichier)

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

> ⚠️ **Règle critique** (`CLAUDE.md`) : toute classe appelée depuis un point d'entrée externe
> (`core/ajax/smartclim.ajax.php`, hooks cron, `desktop/php/*.php`, `install.php`) doit avoir **son propre**
> fichier `<Classe>.class.php`. Ici c'est le cas de chacune. Le `Fatal error: Class not found` correspondant
> est **invisible à `php -l`**.
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

| Clé | Contenu |
|---|---|
| `mac` | MAC normalisée |
| `transport_mode` | `AUTO` (défaut — LAN prioritaire + repli cloud) / `LOCAL` / `CLOUD` |
| `transport_actif` | dernier transport ayant réussi (lecture seule, affiché) |
| `auxhome_device_id` | identifiant AUX Home |
| `auxcloud_endpoint_id`, `auxcloud_region` | identifiants legacy *(post-MVP)* |
| `lan_ip`, `lan_mac_source` | LAN *(post-MVP)* — `lan_ip` **saisissable manuellement** (VLAN / broadcast bloqué) |
| `capabilities` | profil JSON (§ 4 du modèle abstrait) |
| `modele`, `marque` | informatif |
| `temp_min`, `temp_max`, `temp_step` | bornes surchargeables |
| `etat_optimiste` | état commandé + horodatage (protection anti-état-périmé) |

Aucun secret au niveau équipement dans le périmètre actuel. Si un jour un secret par équipement apparaît
(passcode AUXLink), utiliser `encrypt()`/`decrypt()` **d'instance** — pas `$_encryptConfigKey` (qui ne vaut
que pour la config **plugin**).

### 3.3 Configuration plugin (`plugin_info/configuration.php`)

> ⚠️ Rappel `CLAUDE.md` : **lire/écrire uniquement `plugin_info/configuration.txt`**, puis
> `cp plugin_info/configuration.txt plugin_info/configuration.php`. Vérifier par
> `git status --short plugin_info/configuration.php`, ne jamais relire le `.php`.

| Clé | Type | Chiffrée |
|---|---|---|
| `auxhome_login` | e-mail | non |
| `auxhome_password` | mot de passe | **oui** |
| `auxhome_country` | code ISO-3 (défaut déduit du fuseau Jeedom) | non |
| `refresh_interval` | minutes (défaut 5, min 1) | non |
| `auxcloud_login` / `auxcloud_password` / `auxcloud_region` *(post-MVP)* | | **mot de passe : oui** |

```php
public static $_encryptConfigKey = array('auxhome_password', 'auxcloud_password');
```

Hooks disponibles : `preConfig_auxhome_country()` (normaliser en majuscules, valider 3 lettres),
`postConfig_auxhome_password()` (invalider le jeton en cache). ⚠️ Ce sont des **noms de méthode fixes**,
pas une boucle dynamique.

**Jeton de session** : jamais en configuration. `cache::set('smartclim::auxhome::token', utils::encrypt($token), <ttl>)`,
lu via `cache::byKey(...)` + `utils::decrypt`. Purge sur changement d'identifiants.

## 4. Fusion des doublons LAN / cloud

Algorithme de rapprochement, dans l'ordre :

1. **MAC normalisée** (identique) → même équipement.
2. **MAC inversée** — ⚠️ les implémentations Broadlink lisent la MAC dans des **ordres d'octets opposés**
   (`ac_freedom` inverse, `fparrav` non ; cf. `smartclim-transport-broadlink-lan.md` § 6). Toujours tester
   la MAC **et** son inverse avant de conclure à un nouvel appareil.
3. Identifiant de transport déjà mémorisé sur un équipement existant.
4. Sinon → nouvel équipement.

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
| `cron5()` | **rafraîchissement principal** (défaut) : un seul appel `/app/user_device?getStatus=1` pour tous les équipements AUX Home, puis distribution |
| `cron()` (chaque minute) | uniquement si l'utilisateur choisit un intervalle d'1 min |
| `cronDaily()` | re-détection des capacités, nettoyage du cache |

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
2. Pendant une **période de grâce** (≈ 60 s, configurable), un état scruté ne remplace pas les champs
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
