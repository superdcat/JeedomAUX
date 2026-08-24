# Spec technique — UC01 « Configuration du plugin et stockage sécurisé des identifiants »

> **Domaine** : MVP · **Spec fonctionnelle** : `01-configuration-plugin.md` (AC1→AC7) · **Dépend de** : —
> **Date** : 2026-08-24 · Plan validé par l'utilisateur après revue advisor (1 blocker + 4 majors traités).

## 0. Contrats externes

**Aucun appel réseau dans cette UC.** Le seul contrat externe touché *indirectement* est l'en-tête HTTP
`country` du cloud AUX Home (ISO-3166 alpha-3, ex. `FRA`) : cf.
`.memory/analyse/smartclim-transport-aux-home.md` § 5 — un pays incorrect provoque un échec de login
au message trompeur (« identifiant ou mot de passe incorrect »). D'où le nom de clé `auxhome_country`,
décision déjà actée dans cette analyse.

### Contrats du core Jeedom — **vérifiés sur la source** (`jeedom/core@master`, lue le 2026-08-24)

Ces quatre faits conditionnent toute la conception ci-dessous ; ne pas les redécouvrir.

1. **`config::save($k, $v, 'smartclim')`** :
   - si `$v ==` le défaut du fichier INI du plugin → `config::remove()` + `postConfig_*` +
     **`return` immédiat : `preConfig_*` n'est PAS appelé** ;
   - sinon → `preConfig_<clé>` **puis** chiffrement si la clé est dans `$_encryptConfigKey` **puis**
     `REPLACE config` **puis** `postConfig_<clé>`.
   - Corollaire : `postConfig_` d'une clé chiffrée ne reçoit **que le chiffré**.
   - Nom de méthode calculé par `str_replace(array('::', ':', '-'), '_', $clé)` →
     **aucun tiret dans les noms de clés**.
2. **`config::byKey()` ET `config::byKeys()` fusionnent tous deux les défauts INI** lus dans
   `plugins/smartclim/core/config/smartclim.config.ini`, section `[smartclim]`.
   → le formulaire affiche donc le défaut INI même sans aucune ligne en base.
3. **`config.ajax.php?action=addKey` boucle clé par clé, sans transaction.** Une exception levée dans un
   `preConfig_*` **abandonne la boucle** : les clés déjà traitées sont écrites, **les suivantes sont
   perdues**, et l'utilisateur voit une erreur laissant croire que rien n'a été enregistré.
4. **`config.ajax.php?action=getKey`** reçoit la liste de clés **du client** (`init('key')`, JSON) puis
   `config::byKeys()` **déchiffre et renvoie en clair** les clés de `$_encryptConfigKey`, sans masquage.
5. ⚠️ **`<id>_remove()` est appelée à chaque DÉSACTIVATION du plugin**, pas seulement à la
   désinstallation — `plugin::setIsEnable()`, branche `$_state == 0` :
   `if ($alreadyActive == 1) { $out = $this->callInstallFunction('remove'); }`
   (symétriquement, `<id>_install()` est rejouée à chaque activation, et `<id>_update()` si le plugin
   était déjà actif). **Jeedom n'expose aucun hook distinguant désactivation et suppression.**
   → Conséquence directe, cf. § 1.6 : **rien de destructif** ne doit vivre dans `smartclim_remove()`.

## 1. Architecture

Ni équipement, ni commande, ni démon, ni endpoint AJAX : UC01 = **formulaire de config plugin** +
**couche d'accès normalisée** + **amorçage du pays**.

| Fichier | État | Rôle |
|---|---|---|
| `core/config/smartclim.config.ini` | **nouveau** | défaut `refresh_interval = 5` (AC5) |
| `core/config/.htaccess` | **nouveau** | `Order allow,deny` / `Deny from all` (copie de `core/class/.htaccess`) |
| `core/class/smartclimAuxHomeApi.class.php` | **nouveau** | table fuseau → ISO-3 + `paysParDefaut()` |
| `core/class/smartclim.class.php` | modifié | `$_encryptConfigKey`, accesseurs normalisés, hooks `preConfig_*` |
| `plugin_info/configuration.txt` (→ `.php`) | modifié | le formulaire + amorçage paresseux du pays |
| `plugin_info/install.php` | modifié | amorçage du pays à l'installation et à la mise à jour ; `smartclim_remove()` **volontairement vide** (§ 1.6 — n'y jamais mettre de purge) |
| `plugin_info/packages.json` | modifié | nettoyage de la dette du template (hors AC, validé) |
| `plugin_info/.htaccess` | modifié | retrait de `txt` de la liste d'extensions servies (cf. § 1.8) |
| `.memory/.htaccess`, `.claude/.htaccess` | **nouveaux** | fermeture de l'accès web aux dossiers internes (§ 1.9) |
| `desktop/php/smartclim.php` | modifié | retrait du champ mot de passe résiduel du template (§ 1.10) |

**Non touchés** : `core/ajax/smartclim.ajax.php`, `desktop/js/smartclim.js`, `desktop/modal/`,
`core/template/`, `core/php/smartclim.inc.php`, `info.json`.
*(`desktop/php/smartclim.php` l'était dans le plan initial ; il est finalement modifié — au seul titre du
§ 1.10, retrait d'un piège à secret.)*

### 1.1 Clés de configuration plugin — **nomenclature figée pour tout le MVP**

| Clé | Type | Défaut | Chiffrée |
|---|---|---|---|
| `auxhome_email` | string | vide | non |
| `auxhome_password` | string | vide | **oui** (`$_encryptConfigKey`) |
| `auxhome_country` | ISO-3 majuscules | déduit du fuseau, amorcé en base | non |
| `refresh_interval` | entier 1..1440 (minutes) | **5** (via INI) | non |

`snake_case` anglais (aligné sur `auxhome_country`, déjà acté), libellés et commentaires en **français**,
**aucun tiret** (contrainte du § 0.1).

> **Pourquoi `auxhome_email` et non `auxhome_account`** : `account` est le **nom de champ du protocole**,
> qui doit rester confiné dans l'adaptateur de transport (`CLAUDE.md` § Conventions). La correspondance
> `auxhome_email` → `account` sera faite par `smartclimAuxHomeApi` en UC02.

### 1.2 `core/config/smartclim.config.ini`

```ini
[smartclim]
refresh_interval = 5
```

Couvre **AC5** nativement, en lecture unitaire *et* au chargement du formulaire (§ 0.2).
⚠️ Corollaire : **ne jamais amorcer `refresh_interval` en base** — `config::save(…, 5)` supprimerait
aussitôt la ligne (§ 0.1) ; l'amorçage serait inutile et trompeur.

### 1.3 `core/class/smartclimAuxHomeApi.class.php` — nouveau

Porte la **connaissance de protocole** liée au pays, conformément à `CLAUDE.md` (« les noms de champs
d'API … restent confinés dans la brique du transport »). UC02 enrichira cette même classe
(`getPubkey`, `login/pwd`, en-tête `country`).

- `private static $_fuseauVersPays` — table **fuseau IANA → ISO-3**, **Europe uniquement** :
  `Europe/Paris`→`FRA`, `Europe/Brussels`→`BEL`, `Europe/Luxembourg`→`LUX`, `Europe/Zurich`→`CHE`,
  `Europe/Amsterdam`→`NLD`, `Europe/Berlin`→`DEU`, `Europe/Vienna`→`AUT`, `Europe/Madrid`→`ESP`,
  `Atlantic/Canary`→`ESP`, `Europe/Lisbon`→`PRT`, `Atlantic/Madeira`→`PRT`, `Atlantic/Azores`→`PRT`,
  `Europe/Rome`→`ITA`, `Europe/Malta`→`MLT`, `Europe/London`→`GBR`, `Europe/Dublin`→`IRL`,
  `Europe/Copenhagen`→`DNK`, `Europe/Oslo`→`NOR`, `Europe/Stockholm`→`SWE`, `Europe/Helsinki`→`FIN`,
  `Europe/Tallinn`→`EST`, `Europe/Riga`→`LVA`, `Europe/Vilnius`→`LTU`, `Europe/Warsaw`→`POL`,
  `Europe/Prague`→`CZE`, `Europe/Bratislava`→`SVK`, `Europe/Budapest`→`HUN`, `Europe/Ljubljana`→`SVN`,
  `Europe/Zagreb`→`HRV`, `Europe/Sarajevo`→`BIH`, `Europe/Belgrade`→`SRB`, `Europe/Podgorica`→`MNE`,
  `Europe/Skopje`→`MKD`, `Europe/Tirane`→`ALB`, `Europe/Athens`→`GRC`, `Europe/Bucharest`→`ROU`,
  `Europe/Sofia`→`BGR`, `Europe/Chisinau`→`MDA`, `Europe/Kyiv`→`UKR`, `Europe/Kiev`→`UKR` (alias
  historique), `Europe/Minsk`→`BLR`, `Europe/Moscow`→`RUS`, `Europe/Kaliningrad`→`RUS`,
  `Europe/Istanbul`→`TUR`, `Europe/Nicosia`→`CYP`, `Asia/Nicosia`→`CYP`, `Asia/Famagusta`→`CYP`,
  **`Atlantic/Reykjavik`**→`ISL` *(⚠️ `Europe/Reykjavik` **n'existe pas** dans la base IANA — le fuseau
  islandais est en région `Atlantic`, comme `Atlantic/Faroe`/`Canary`/`Madeira` ; une première version de
  cette spec portait cette erreur, et le code l'avait fidèlement recopiée : clé morte, aucun pays déduit
  pour un Jeedom islandais)*, `Europe/Mariehamn`→`FIN`, `Europe/Belfast`→`GBR`, `Europe/Tiraspol`→`MDA`,
  `Europe/Andorra`→`AND`, `Europe/Monaco`→`MCO`, `Europe/Gibraltar`→`GIB`, `Atlantic/Faroe`→`FRO`,
  `Europe/Vaduz`→`LIE`, `Europe/Vatican`→`VAT`, `Europe/San_Marino`→`SMR`, `Europe/Busingen`→`DEU`,
  `Africa/Ceuta`→`ESP`, `Europe/Simferopol`/`Europe/Uzhgorod`/`Europe/Zaporozhye`→`UKR`,
  `Europe/Volgograd`/`Europe/Samara`/`Europe/Saratov`/`Europe/Astrakhan`/`Europe/Ulyanovsk`/
  `Europe/Kirov`→`RUS`.
  🚫 **Ne PAS mapper** `Europe/Jersey`, `Europe/Guernsey`, `Europe/Isle_of_Man`, `Arctic/Longyearbyen` :
  leurs codes ISO-3 (`JEY`/`GGY`/`IMN`/`SJM`) sont probablement inconnus du backend AUX — le vide reste
  plus honnête, conformément à la décision ci-dessous.
- `public static function paysParDefaut()` :
  `config::byKey('timezone')`, repli `date_default_timezone_get()` si vide → **correspondance exacte**
  dans la table → **si aucune correspondance : chaîne vide**.

> **Décisions actées** :
> - **Pas de devinette hors Europe** : un pays faux mais plausible (`FRA` pour un utilisateur brésilien)
>   produirait un échec de login au message trompeur. Le champ vide + le texte d'aide sont plus honnêtes,
>   et c'est exactement la limite acceptée par le « À confirmer » de la spec fonctionnelle.
> - **Table reconstruite depuis la nomenclature IANA/ISO publique**, et non recopiée du dépôt MIT de
>   référence : la correspondance fuseau→pays est un **fait factuel**, et le backend route sur le *pays*,
>   pas sur notre table — la « parité avec la référence » n'apporterait rien de plus qu'une valeur
>   factuellement juste. Aucune question de licence.

### 1.4 `core/class/smartclim.class.php` — modifié (classe `smartclim`)

- `public static $_encryptConfigKey = array('auxhome_password');` → **AC1/AC2**, chiffré au repos.
- **Constantes de bornes** : `const INTERVALLE_MIN = 1;` · `const INTERVALLE_MAX = 1440;` ·
  `const INTERVALLE_DEFAUT = 5;` — une seule source de vérité. Les attributs `min`/`max` du champ HTML et
  le fichier INI restent en littéral (contextes non-PHP) avec un commentaire pointant vers ces constantes.
- **Trois normaliseurs privés partagés** — chaque règle est écrite **une seule fois** et appelée des deux
  côtés de la double barrière :
  - `private static function normaliserEmail($valeur)` : retrait des caractères de contrôle
    (`/[\x00-\x1F\x7F]/`) **puis** `trim`. ⚠️ **Cet ordre est le bon** : `trim()` ne retire pas les
    caractères de contrôle autres que `\t\n\r\0\x0B`, donc filtrer après aurait laissé l'espace adjacent
    (`"\x01 a@b.fr"` → `" a@b.fr"`), et cet e-mail à blanc de tête partirait tel quel dans le champ
    `account` d'UC02, avec un message backend indistinguable d'un mauvais mot de passe.
    Appelée par `preConfig_auxhome_email()` **et** `emailAuxHome()`.
  - `private static function normaliserPays($valeur)` : `trim` + `strtoupper` + filtre `[^A-Z]` + test
    `strlen == 3`. Appelée par `preConfig_auxhome_country()` **et** `paysAuxHome()`.
  - `private static function normaliserIntervalle($valeur)` : si vide, `null` ou **non numérique** →
    `INTERVALLE_DEFAUT` ; sinon `min(INTERVALLE_MAX, max(INTERVALLE_MIN, (int) $valeur))`. Appelée par
    `preConfig_refresh_interval()` **et** `intervalleRafraichissement()`.
    ⚠️ Le garde `is_numeric` est **fonctionnel, pas cosmétique** : sans lui, `(int) 'abc'` vaut `0` donc
    `max(1, 0)` = **1 minute**, et une saisie invalide ferait interroger le cloud 5× plus souvent que le
    défaut, silencieusement. `'0'` reste bien ramené à `1` (`is_numeric` vrai) — c'est ce qu'exige AC4.
- **Durcissement des hooks** : toute méthode recevant une valeur de configuration commence par
  `$value = is_scalar($value) ? (string) $value : '';`. Ce n'est pas défensif : `preConfig_*` est
  `public static` et `config::save()` est appelable depuis un scénario ou l'API JSON-RPC — sur PHP ≥ 8.0
  un tableau passé à `trim()`/`strtoupper()` lève une **`TypeError` non rattrapée**, exactement le
  scénario interdit au § 3.1 (la boucle `addKey` abandonne, les clés suivantes sont perdues).
- `public static function paysAuxHome()` : `normaliserPays()` de la valeur configurée ; si le résultat
  n'est pas un code à 3 lettres → `smartclimAuxHomeApi::paysParDefaut()`. **Point d'accès neutre** du
  reste du plugin et des points d'entrée externes.
  *(nommée `paysAuxHome()` et non `pays()` : le cloud legacy apportera une notion de **région** en
  post-mvp/03, la collision de nom est évitée d'emblée.)*
- `public static function intervalleRafraichissement()` : `normaliserIntervalle()` de la valeur
  configurée. **Normalisation en lecture ET en écriture** : indispensable, car `preConfig_*` est
  court-circuité lorsque la valeur enregistrée égale le défaut INI (§ 0.1). La borne haute empêche de
  désactiver silencieusement le rafraîchissement avec une valeur type `999999`.
- `public static function compteConfigure()` : `bool` — e-mail, mot de passe **et** pays non vides.
  Garde-fou du « cas dégradé » de la spec fonctionnelle : **UC02 et UC03 doivent l'appeler avant tout
  appel cloud**, pour ne jamais tenter une connexion avec des identifiants vides.
  ⚠️ Tester **en ligne**, sans variable locale nommée portant le mot de passe déchiffré : une locale
  n'apparaît pas dans un backtrace PHP (seuls les arguments le font), mais autant ne pas prolonger la
  durée de vie du clair.
- `public static function emailAuxHome()` : `normaliserEmail()` de la valeur configurée.
  **Pourquoi un accesseur pour l'e-mail aussi** : sans lui, c'était la seule clé sans barrière en lecture,
  donc UC02 — qui doit la lire pour alimenter le champ `account` — l'aurait lue via `config::byKey` en
  direct. C'est précisément l'asymétrie qui produit le contournement suivant.
- `public static function amorcerPaysAuxHome()` : écrit `auxhome_country` **si et seulement si** la clé
  est vide et que le pays déduit ne l'est pas. Appelée par `configuration.php` **et** par `install.php`
  (§ 1.5, § 1.6) : le **doublon d'appel** est volontaire, la **duplication d'implémentation** ne l'est pas.
  ⚠️ La garde teste la valeur **normalisée** (`normaliserPays(config::byKey(…)) == ''`), pas la valeur
  brute : sinon une valeur non conforme présente en base — le scénario même qui justifie la double
  barrière — ne serait **jamais réparée**, et le formulaire afficherait `XX` pendant que le plugin
  enverrait le pays déduit au cloud. Divergence invisible au diagnostic. L'idempotence est préservée :
  après réparation la valeur est conforme, donc no-op.
- `public static function preConfig_auxhome_email($value)` : `normaliserEmail()`.
- `public static function preConfig_auxhome_country($value)` : `normaliserPays()` ; à défaut de code à
  3 lettres, repli sur `smartclimAuxHomeApi::paysParDefaut()` ; sinon **chaîne vide acceptée**.
  🚫 **Aucun `throw` dans ce hook** — voir § 3.1.
- `public static function preConfig_refresh_interval($value)` : `normaliserIntervalle()` → **AC4/AC5**.
  ℹ️ Cas limite documenté : une entrée **vide** retourne `5` **après** le test du défaut INI, donc
  `config::save` écrit réellement une ligne `refresh_interval = 5` en base, identique au défaut INI
  (un `preConfig_*` ne peut pas demander la suppression de la clé). Inoffensif, mais si le défaut INI
  changeait un jour, les utilisateurs ayant vidé le champ une fois resteraient figés à `5` sans
  migration : il faudrait alors une migration dans `smartclim_update()`.

### 1.5 `plugin_info/configuration.txt` → `configuration.php`

⚠️ **Rappel de procédure** (`CLAUDE.md`) : le `.php` est **illisible et non éditable** par l'outillage.
Éditer **uniquement** le `.txt`, puis `cp plugin_info/configuration.txt plugin_info/configuration.php`,
et vérifier par `git status --short plugin_info/configuration.php` — **jamais** en relisant le `.php`.

**(a) Garde d'authentification — `isConnect('admin')`, pas `isConnect()`.** Le template livre
`isConnect()`, mais ce fichier ne se contente plus d'afficher une vue : il **écrit** en configuration
(point (b)). Les trois autres points d'entrée du plugin sont tous en `isConnect('admin')`
(`desktop/php/smartclim.php`, `desktop/modal/modal.smartclim.php`, `core/ajax/smartclim.ajax.php`) et
`CLAUDE.md` l'exige pour les pages de configuration : il serait absurde que la page portant les
identifiants cloud soit la seule avec la garde la plus faible. Aucune régression fonctionnelle — la page
n'est atteignable que par un admin dans l'UI, et `config.ajax.php` est déjà admin-only.

**(b) Amorçage paresseux du pays**, juste après la garde :

```php
smartclim::amorcerPaysAuxHome();
```

La logique vit dans `smartclim::amorcerPaysAuxHome()` (§ 1.4), appelée **aussi** par `install.php`.
Le doublon d'**appel** est volontaire (ceinture + bretelles) ; la duplication d'**implémentation**, non.

Motif : `install.php` seul ne garantit pas **AC3** (« à la toute première ouverture ») pour un plugin
posé à la main ou cloné en git — le moment d'appel de `smartclim_install()` dans ce mode n'est pas
vérifié, et l'échec serait **silencieux** (champ vide). `configuration.php` est un point d'entrée PHP à
part entière, exécuté **avant** le `getKey` de la modale : l'amorçage y est vrai **par construction**.
Écriture idempotente sur un GET admin, assumée.

**(c) Le formulaire**, remplaçant les `param1/2/3` du template. Deux `fieldset` :

- `{{Compte AUX Home}}`
  - `auxhome_email` — `type="email"`, `class="configKey form-control"`.
    *Le serveur ne rejette **jamais** un format (§ 3.2) ; `type="email"` n'est qu'une **aide de saisie
    côté client**, non bloquante (la modale sauvegarde en JS, sans validation native). Pour le transport
    AUX Home EU — le seul du MVP — le compte **est** une adresse e-mail ; les comptes non-e-mail de
    l'écosystème relèvent du cloud legacy, qui aura ses propres clés en post-mvp/03.*
  - `auxhome_password` — **`type="password"`, `class="configKey form-control"`,
    `autocomplete="new-password"`** (décision utilisateur, option (A) — cf. § 4).
  - `auxhome_country` — `maxlength="3"`, `style="text-transform:uppercase"` (**AC3** « affiché en
    majuscules »), champ **texte libre éditable**.
    *Pas de `<select>` : AC3 exige un champ éditable et la spec prévoit la saisie manuelle hors Europe
    (un `<select>` imposerait la liste ISO-3 complète). Le besoin de validation qui motiverait un
    `<select>` disparaît avec la normalisation silencieuse.*
- `{{Rafraîchissement}}`
  - `refresh_interval` — `type="number" min="1" max="1440" step="1"`, `class="configKey form-control"`.
  - **texte d'aide VISIBLE** dans un `<span class="help-block">` — **pas** un simple tooltip, sans quoi
    **AC6** (« le formulaire **affiche** … un texte explicatif ») n'est pas satisfait.

Style Bootstrap de l'existant conservé (`form-horizontal` / `form-group` / `col-md-4 control-label` /
`configKey form-control`), **indentation 2 espaces** comme le fichier actuel. Toutes les chaînes en
`{{…}}` français → **AC7**.

### 1.6 `plugin_info/install.php`

- `smartclim_install()` **et** `smartclim_update()` : appellent `smartclim::amorcerPaysAuxHome()`.
  Ceinture + bretelles avec § 1.5(b). **Ne pas** amorcer `refresh_interval` (§ 1.2).
- `smartclim_remove()` : **volontairement VIDE**, avec un commentaire expliquant pourquoi elle doit le
  rester.
  🚫 **Ne jamais y purger les clés de configuration.** Le core appelle cette fonction à **chaque
  désactivation** du plugin (§ 0.5), pas seulement à la désinstallation : une purge y détruirait
  silencieusement l'e-mail et le mot de passe du compte cloud lors d'un simple cycle
  désactiver/réactiver — y compris ceux que le core déclenche lui-même sur échec de dépendances ou
  pendant une mise à jour. Au retour, `smartclim_install()` ne réamorcerait que le pays : l'utilisateur
  devrait tout re-saisir, et les scénarios pilotant ses climatiseurs resteraient cassés entre-temps.
  ℹ️ **Historique de la décision** : une purge des 4 clés avait été demandée puis retirée, après
  vérification du moment d'appel sur la source du core. L'objectif d'hygiène est conservé mais déplacé :
  l'effacement des identifiants sera une **action volontaire de l'utilisateur** (bouton dédié en UC02,
  cf. § 9), jamais un effet de bord du cycle de vie du plugin. Le mot de passe reste de toute façon
  chiffré au repos par la clé de l'instance.
  ⚠️ Corollaire assumé : e-mail et mot de passe chiffré **survivent** à une désinstallation — ce qui a
  aussi l'avantage de restituer sa configuration à l'utilisateur après une réinstallation.

Autoload : les deux classes ont **leur propre fichier**, donc appelables depuis ce point d'entrée externe.

### 1.7 `plugin_info/packages.json` — nettoyage adjacent (hors AC, validé par l'utilisateur)

Vider tous les groupes, **en conservant le fichier** (conforme à `CLAUDE.md` « vide au MVP »). Il déclare
aujourd'hui `pip3: pyserial/requests`, `npm`/`yarn` sur `plugins/smartclim/ressources/demond` (chemin
**inexistant** : faute de frappe *ressources* + `resources/` supprimé au renommage) et `composer` sur la
racine du plugin — en contradiction avec `CLAUDE.md` et `info.json` (`hasDependency: false`).

### 1.8 `plugin_info/.htaccess` — fermeture d'une divulgation de source

Le fichier livré par le template contient :

```apache
Order allow,deny
<Files ~ "\.(jpg|jpeg|png|gif|pdf|txt|bmp)$">
   allow from all
</Files>
Deny from all
```

La section `<Files>` **neutralise** le `Deny from all` du dossier pour ces extensions (c'est ce qui rend
`plugin_info/smartclim_icon.png` affichable dans l'UI). Or `plugin_info/` est **le seul répertoire du
plugin dont le `.htaccess` autorise les `.txt`** — donc
`GET /plugins/smartclim/plugin_info/configuration.txt` renvoie le **source complet** de la page de
configuration, en `text/plain`, **sans aucune authentification** : Apache sert un fichier statique, aucune
session PHP n'est évaluée. Le fichier étant commité, l'exposition est livrée sur chaque Jeedom installé.
Aucun secret n'y figure aujourd'hui, mais le miroir doit rester identique au `.php` exécuté : **toute
logique future ajoutée à cette page deviendrait automatiquement publique**.

**Correction** : retirer **`txt`** de la liste d'extensions autorisées (les formats d'image restent
servis). ⚠️ Fichier en **CRLF** — à préserver.

> **Portée générale** : cette exposition **préexistait** à UC01 (le miroir `.txt` portait déjà le
> formulaire du template). La règle vaut pour **tout futur miroir** `.txt` : à inscrire dans `CLAUDE.md`,
> section du miroir `configuration.txt`.

### 1.9 `.memory/.htaccess` et `.claude/.htaccess` — **NOUVEAUX**

Même classe de problème que le § 1.8, autre vecteur. Sur un Jeedom installé,
`GET /plugins/smartclim/.memory/analyse/<fichier>.md` renvoie le document **en clair, sans
authentification** : Apache/Debian ne bloque par défaut que `^\.ht`, **pas** les répertoires dont le nom
commence par un point. Or le dépôt livre 200+ fichiers internes (`.memory/specs/**`,
`.memory/analyse/**`, `.memory/brief.md`, `.claude/**`) et ne porte de `.htaccess` que dans `core/class`,
`core/php`, `core/config` et `plugin_info`.

Aucun secret ne s'y trouve (les constantes de protocole sont volontairement non recopiées dans les
analyses), donc l'exposition se limite à de la **reconnaissance** : architecture, roadmap, faiblesses
connues et résidus de template d'un plugin identifié par sa version. Suffisant pour être fermé.

**Correction** : `.memory/.htaccess` et `.claude/.htaccess`, copie exacte des 2 lignes de
`core/class/.htaccess` (`Order allow,deny` / `Deny from all`), en **CRLF**.

### 1.10 `desktop/php/smartclim.php` — retrait d'un piège à secret hérité du template

Le fichier porte un champ résiduel du squelette :
`<input type="text" class="eqLogicAttr form-control inputPassword" data-l1key="configuration"
data-l2key="password">`. Or les méthodes d'instance `encrypt()`/`decrypt()` de `smartclim` sont
**commentées** : toute valeur qui y serait saisie serait stockée **en clair** dans la colonne
`configuration` de l'eqLogic **et** renvoyée en clair dans le JSON de l'équipement. Aucun consommateur
n'existe, et l'exploitation suppose un admin qui remplisse un champ inutile — mais c'est un piège à
secret laissé ouvert dans un plugin dont tout l'enjeu est le stockage d'identifiants.

**Correction** : supprimer le `form-group` contenant ce seul champ.
⚠️ Ce fichier est en **tabulations + CRLF** (contrairement à `core/class/*.php`) — respecter
scrupuleusement l'existant. **Ne pas toucher** au champ `data-l2key="param1"` : il est inerte (aucun
secret) et relève d'UC03/UC04.

## 2. Server vs Client

**Tout est serveur.** Aucune ligne de JavaScript n'est ajoutée, aucun endpoint AJAX n'est créé.

Justification : les quatre traitements de l'UC (déduction du pays, normalisation, bornage de l'intervalle,
chiffrement) doivent être **inviolables et valoir pour toute écriture**, y compris une écriture
programmatique future (`config::save` appelé depuis un script ou une autre UC). Un contrôle en JS ne
serait qu'un confort d'affichage, contournable et à maintenir en double. Le chargement et la sauvegarde du
formulaire sont entièrement pris en charge par le mécanisme `configKey` du core.

Les seuls attributs à visée « client » sont **déclaratifs** et redondants avec le serveur :
`type="email"`, `type="number" min="1" max="1440"`, `style="text-transform:uppercase"`.

⚠️ **Interdit — n'ajouter AUCUN JS qui vide le champ mot de passe.** La modale de config réenvoie
**toutes** les clés `configKey` à chaque sauvegarde : un champ vidé côté client **écraserait le mot de
passe stocké par une chaîne vide**, y compris lors d'un enregistrement ne visant que l'intervalle. Perte
de données silencieuse. Avec l'option (A), le champ est **masqué**, jamais **vidé**.

## 3. Validation

### 3.1 Règle absolue — normalisation silencieuse, jamais d'exception dans un `preConfig_*`

`config.ajax.php?action=addKey` **boucle clé par clé, sans transaction** (§ 0.3). Une exception levée dans
`preConfig_auxhome_country` aurait donc trois effets, tous certains :

1. les clés déjà traitées sont écrites, **`refresh_interval` (traité après) est perdu** → régression
   directe sur AC4/AC5, avec un message d'erreur laissant croire que rien n'a été enregistré ;
2. le chemin `vide → paysParDefaut() → '' → non conforme → throw` se déclencherait sur **tout Jeedom
   hors table Europe** : l'utilisateur ne pourrait **jamais** enregistrer son compte en une passe, alors
   que le pays vide est un état **légitime** prévu par la spec ;
3. l'exception se reproduirait à **chaque** enregistrement ultérieur, même sans toucher au champ pays.

Aucun AC ne demande de refus dur sur le pays (seul **AC4**, sur l'intervalle, ouvre l'option « refus
explicite » — et on retient l'autre branche, « valeur ramenée à 1 »).

### 3.2 Tableau de validation

| Clé | Entrée | Résultat |
|---|---|---|
| `auxhome_email` | ` a@b.fr ` / `"\x01 a@b.fr"` | `a@b.fr` dans les **deux** cas (filtre des caractères de contrôle **puis** `trim` — cet ordre est obligatoire, cf. § 1.4) — **aucun rejet de format** côté serveur |
| `auxhome_password` | quoi que ce soit | **inchangé, non trimé** (un mot de passe peut légitimement contenir des espaces), puis chiffré par le core |
| `auxhome_country` | `fra` / ` f2r@a ` / `xx` / vide | `FRA` / `FRA` / repli `paysParDefaut()` puis vide / repli puis vide — **toujours enregistrable** |
| `refresh_interval` | `0` / vide / `5` / `99999` / **`abc`** | `1` / `5` / `5` (ligne supprimée en base, relue depuis l'INI) / `1440` / **`5`** |
| n'importe laquelle | `null` ou **tableau** | traité comme chaîne vide en **entrée** (`is_scalar`) — **jamais** de `TypeError` (§ 3.1). ⚠️ Ce qui est *stocké* est ensuite la règle de la clé : `refresh_interval` → `5`, et `auxhome_country` → **le pays déduit du fuseau**, pas `''` (le repli s'applique après la normalisation) |

**Double barrière** : chaque règle est appliquée **à l'écriture** (`preConfig_*`) **et à la lecture**
(`intervalleRafraichissement()`, `paysAuxHome()`), via les **normaliseurs privés partagés** du § 1.4 —
une seule implémentation par règle, appelée des deux côtés. C'est obligatoire, pas défensif :
`preConfig_*` est court-circuité quand la valeur égale le défaut INI (§ 0.1).

⚠️ **Distinguer « garbage » de « zéro explicite »** : `abc` → `5` (défaut) et `0` → `1` (plancher, exigé
par AC4). Confondre les deux ferait interroger le cloud 5× plus souvent que prévu, silencieusement, sur
une simple faute de saisie — c'est UC07 qui le paierait.

## 4. Sécurité — décisions actées

**Option (A) retenue par l'utilisateur** : le mot de passe est un champ `configKey` `type="password"`,
chiffré au repos par `$_encryptConfigKey`, **masqué** à l'écran — ce que la lettre d'AC1 autorise
explicitement (« champ vide **ou masqué** »).

**Résidu connu et assumé** : `config.ajax.php?action=getKey` **déchiffre** les clés de
`$_encryptConfigKey` et les renvoie **en clair** au navigateur, où elles atterrissent dans l'attribut
`value` du DOM (§ 0.4). C'est le comportement **natif de tout Jeedom** — la page de configuration du core
renvoie de la même façon les mots de passe SMTP et les clés d'API — sur une surface **admin
authentifiée**, sur le même canal. La phrase de `CLAUDE.md` « jamais de secret … dans le DOM, les
réponses AJAX » reçoit donc un **carve-out nommé pour le mécanisme `configKey` du core** (à inscrire dans
`CLAUDE.md`, étape 13 du workflow), afin que les reviews futures ne ressortent pas ce finding en boucle.

**Interdits explicites — à respecter à la lettre** :

- aucun `log::add` du mot de passe, ni de la config brute (`AC2`) ;
- **jamais** de `preConfig_auxhome_password` (même pour un simple `trim`) : le clair apparaîtrait dans
  une frame PHP, donc dans toute trace d'exception ;
- **aucun `postConfig_auxhome_password` en UC01** (aucun jeton n'existe encore), et **jamais** un
  `postConfig_auxhome_password` qui **lise ou journalise** la valeur — il ne recevrait de toute façon que
  le chiffré (§ 0.1).
  ✅ **En revanche, UC02 est explicitement autorisée** à en définir un dont le seul effet est de **purger
  le cache de jeton** au changement de mot de passe : cela ne nécessite pas le clair, la seule
  *notification* du changement suffit à déclencher `cache::delete`. C'est même le hook naturel pour
  satisfaire l'exigence de `CLAUDE.md` (« jetons de session … purgés au changement d'identifiants ») ;
- **jamais** de `getConfigForCommunity()` retournant de la configuration : elle finirait dans un post de
  forum public ;
- **aucune** fonction du plugin ne prend le mot de passe en paramètre (une trace d'exception PHP expose
  les arguments) — d'où l'absence d'accesseur exposant le secret en UC01 : `compteConfigure()` teste la
  non-vacuité **en interne**.

## 5. Server Actions / API

**Aucune.** `core/ajax/smartclim.ajax.php` reste inchangé (l'endpoint admin sera créé en UC02 pour le
test de connexion). Les seules signatures publiques introduites, destinées aux UC suivantes :

```php
smartclim::emailAuxHome()               // string — e-mail normalisé (UC02 : champ « account »)
smartclim::paysAuxHome()                // string — ISO-3 majuscules, ou '' si indéductible
smartclim::intervalleRafraichissement() // int    — 1..1440, défaut 5
smartclim::compteConfigure()            // bool   — garde-fou avant tout appel cloud (UC02/UC03)
smartclim::amorcerPaysAuxHome()         // void   — écriture idempotente du pays déduit
smartclimAuxHomeApi::paysParDefaut()    // string — déduit du fuseau Jeedom, ou ''
```

## 6. Dépendances

**Aucune.** Pas de démon, pas de paquet système, pas de pip, pas de composer.
`plugin_info/packages.json` est **vidé** (§ 1.7) ; `info.json` conserve `hasDependency: false` et
`hasOwnDeamon: false`. Tout est couvert par PHP natif.

## 7. Impact i18n — **français uniquement** (traduction différée à l'étape 10 du workflow)

Chaînes UI introduites, toutes dans `plugin_info/configuration.txt` → `.php` :

`{{Compte AUX Home}}` · `{{Adresse e-mail}}` · `{{Adresse e-mail du compte AUX Home (celle de
l'application mobile)}}` · `{{Mot de passe}}` · `{{Mot de passe du compte AUX Home. Il est stocké chiffré
et n'est jamais journalisé.}}` · `{{Pays}}` · `{{Code pays ISO à 3 lettres (FRA, BEL, CHE, DEU…).
Pré-rempli depuis le fuseau horaire de Jeedom ; hors Europe, saisissez-le manuellement. Un pays erroné
fait échouer la connexion au cloud AUX Home.}}` · `{{Rafraîchissement}}` ·
`{{Intervalle de rafraîchissement (minutes)}}` · `{{La température ambiante remontée par AUX Home se
rafraîchit lentement (jusqu'à environ 30 minutes) ; réduire cet intervalle n'accélère pas la donnée.}}` ·
`{{Entre 1 et 1440 minutes, 5 minutes par défaut.}}` ·
`{{Sans pays valide — ni saisi, ni déduit du fuseau horaire de Jeedom — aucune connexion au cloud n'est
tentée.}}` *(aide **visible** du champ pays, cf. § 1.5 — le tooltip conserve le détail long)*

Soit **12 chaînes**. Aucune chaîne d'exception (§ 3.1 : plus de `throw`). Aucun `__()` PHP nécessaire
dans cette UC.

⚠️ **Deux formulations ont été corrigées avant traduction** — ne pas revenir aux versions initiales :
- « Sans pays **renseigné**… » était **factuellement faux** : un champ vidé est aussitôt re-rempli par le
  repli sur le fuseau, donc sur un Jeedom en `Europe/Paris` la connexion **est** tentée. L'affirmation
  n'est vraie que si le pays n'est **ni saisi ni déductible**, c'est-à-dire hors table Europe.
- « **Minimum** 1 minute, 5 minutes par défaut » recopiait deux bornes en littéral dans une chaîne
  traduisible (donc à répercuter dans 3 fichiers i18n à chaque évolution) et **omettait la borne haute**,
  alors que le serveur écrête silencieusement à 1440.

⚠️ **Consigne pour l'étape 10 — bornes en littéral dans une clé traduite** : la clé
`{{Entre 1 et 1440 minutes, 5 minutes par défaut.}}` contient les **3 bornes** en toutes lettres. Toute
évolution de `INTERVALLE_MIN`/`MAX`/`DEFAUT` impose donc de mettre à jour cette clé dans les **3 fichiers
i18n cibles** — sinon `en_US`/`de_DE`/`es_ES` affirmeront des valeurs fausses, ce que ni `php -l` ni la CI
ne verront. Le commentaire HTML de rattachement ne couvre que la source française.

⚠️ **Périmètre de traduction d'UC01** : **uniquement les 12 clés ci-dessus**, dans
`plugin_info/configuration.php`. Le plugin ne possède **aucun** fichier `core/i18n/*.json` à ce jour, et
les chaînes préexistantes du squelette (`desktop/php/smartclim.php`, `desktop/js/smartclim.js`) **ne
relèvent pas d'UC01** : le balayage i18n complet du plugin a sa propre UC (`post-mvp/07`, UC04
« internationalisation et publication »).

⚠️ **Consigne pour l'étape 10** : indexer les clés sous
`plugins/smartclim/plugin_info/configuration.php` — le fichier **réellement exécuté** par Jeedom — et
**pas** sous le miroir `.txt`. Le scan statique verra les **deux** fichiers porter des chaînes
identiques : sans cette consigne, on obtient des clés en double ou de fausses « orphelines ».

## 8. Checklist de recette — à exécuter sur le Jeedom réel

1. Intervalle `0` → enregistré à `1`. Une valeur `< 1` n'est **jamais** appliquée. *(AC4)*
2. Intervalle vidé → valeur appliquée `5`. *(AC5)*
3. Intervalle `5` → vérifier la **disparition de la ligne en base** *et* la valeur relue `5`
   (comportement normal : défaut INI).
4. Intervalle `99999` → ramené à `1440`.
5. Pays `fra` → `FRA` · pays `f2r@a` → normalisé ou repli · pays **vidé** sur un fuseau hors table →
   **enregistrement réussi**, champ vide accepté (non-régression § 3.1).
6. Rechargement de la page : e-mail et pays réaffichés ; mot de passe **masqué**. *(AC1)*
7. `grep -ri '<motdepasse>' /var/www/html/log/` après enregistrement → **aucun résultat**. *(AC2)*
8. **Second enregistrement consécutif** sans toucher au champ mot de passe → mot de passe **toujours
   valide** en base (non-régression de l'interdit § 2).
9. Mot de passe contenant `& ' " < +` et un espace → relu **à l'identique**. Motif : un mot de passe
   abîmé par `init()` / `json_decode` produirait en UC02 **exactement le même** message backend qu'un
   mauvais pays → diagnostic impossible.
10. Première ouverture de la page de configuration sur un Jeedom en `Europe/Paris`, sans configuration
    préexistante → champ pays déjà rempli `FRA`, éditable. *(AC3)*
11. Intervalle **non numérique** (`abc`) → valeur appliquée **5**, et **pas 1**. Motif : une saisie
    invalide ne doit pas basculer sur le rythme le plus agressif.
12. Enregistrement complet **avec pays vide** sur un Jeedom hors table (ex. fuseau non européen) :
    l'enregistrement **réussit**, puis vérifier que le plugin reste **inerte** (`compteConfigure()` faux,
    donc aucun appel cloud) — et que l'utilisateur peut comprendre pourquoi.
13. `curl -i http://<jeedom>/plugins/smartclim/plugin_info/configuration.txt` → **403 attendu**
    (non-régression du § 1.8) ; `plugin_info/smartclim_icon.png` doit rester servi.
14. ⚠️ **Test de non-régression le plus important** : **désactiver puis réactiver** le plugin →
    les **4 clés survivent** (`SELECT * FROM config WHERE plugin = 'smartclim';` inchangé), e-mail et
    mot de passe toujours en place. Motif : le core appelle `smartclim_remove()` à **chaque
    désactivation** (§ 0.5) — c'est le scénario qui rendait la purge destructrice. *(§ 1.6)*
15. Depuis un scénario ou la console : `config::save('refresh_interval', array(), 'smartclim')` puis
    `config::save('auxhome_country', null, 'smartclim')` → **aucune exception**, intervalle relu `5`,
    pays relu = pays déduit, **et** une autre clé enregistrée dans la même passe n'est **pas perdue**.
    Motif : c'est le seul comportement du tableau § 3.2 qu'aucun autre point ne vérifie, et c'est
    précisément celui qu'introduit le durcissement `is_scalar`.
16. `curl -i http://<jeedom>/plugins/smartclim/.memory/analyse/INDEX.md` → **403 attendu** (§ 1.9).
17. E-mail saisi avec un caractère de contrôle en tête (`\x01` suivi d'un espace), **et** e-mail collé
    depuis un PDF avec un espace insécable (U+00A0) en bordure → relus **sans** blanc de tête ni de
    queue. Motif : un e-mail à blanc produirait en UC02 le même message backend qu'un mauvais mot de
    passe — diagnostic impossible.
18. Enregistrer avec le champ **mot de passe vide** → `smartclim::compteConfigure()` renvoie **faux**,
    et aucun appel cloud n'est tenté. Motif : c'est la seule des 4 clés sans normalisation, donc le seul
    garde-fou est ce test de non-vacuité.

## 9. Contrats transmis aux UC suivantes

- **UC02 / UC03** : appeler `smartclim::compteConfigure()` **avant** tout appel réseau (cas dégradé de la
  spec fonctionnelle) ; en cas d'échec de login, le message d'erreur doit **explicitement suggérer de
  vérifier le pays** (analyse § 5). `smartclimAuxHomeApi` est déjà créée et n'attend que
  `getPubkey`/`login`, plus la correspondance `auxhome_email` → champ `account`.
  Trois exigences supplémentaires issues des reviews d'UC01 :
  - **construire les en-têtes via `CURLOPT_HTTPHEADER`** à partir de valeurs **déjà filtrées**, sans
    concaténation libre — le pays est filtré `[A-Z]{3}` des deux côtés de la barrière (§ 1.4), ne pas
    réintroduire un chemin non filtré ;
  - le message du **test de connexion** doit **distinguer** « pays manquant » de « compte non
    configuré » : un utilisateur hors table Europe enregistre son compte sans erreur puis voit le plugin
    rester inerte, il doit savoir que c'est le pays qui bloque (cf. § 1.5, aide visible du champ) ;
  - un `postConfig_auxhome_password` **purgeant uniquement le cache de jeton** est explicitement
    autorisé (§ 4) ;
  - ⚠️ **le mot de passe doit partir exclusivement dans le corps JSON** (`json_encode`), **jamais** dans
    un en-tête HTTP ni une query string. C'est la seule des 4 clés **sans** normalisation — à raison,
    trimmer un mot de passe serait un bug fonctionnel — donc la double barrière ne le couvre pas et il
    redeviendrait un vecteur d'injection d'en-tête.
- **UC07** : `refresh_interval` est libre en minutes (1..1440), alors que les hooks cron Jeedom sont des
  **paliers fixes** (1/5/10/15/30/60). UC07 devra donc implémenter `cron()` **chaque minute** + un
  **horodatage de dernier rafraîchissement** par équipement, et **non** choisir un `cronN`.
- **UC02 — bouton « Effacer les identifiants »** : c'est là que se règle l'hygiène que
  `smartclim_remove()` ne peut pas assurer (§ 1.6). Effacement **volontaire** déclenché par l'utilisateur
  depuis la page de configuration, via l'endpoint AJAX admin qu'UC02 crée de toute façon pour le test de
  connexion — **jamais** un effet de bord du cycle de vie du plugin.
- **UC02 — lecture du mot de passe** : `auxhome_password` est la **seule** des 4 clés sans accesseur, et
  c'est délibéré (une normalisation serait un bug fonctionnel, § 3.2). Contrat : le lire **au point
  d'usage** dans `smartclimAuxHomeApi`, via `config::byKey('auxhome_password', 'smartclim')` — qui
  **déchiffre bien** automatiquement, la branche `property_exists($_plugin, '_encryptConfigKey')` de
  `config::byKey()` ayant été vérifiée sur la source au même titre que celle de `byKeys()` — sans
  variable locale nommée (§ 4) et sans jamais le passer en paramètre.
- **Dette d'exposition web non traitée** : `.git/` reste servi sur une installation **clonée en git**
  (`GET /plugins/smartclim/.git/config` expose l'URL du dépôt distant, et un **jeton** si le clone
  utilisait une URL `https://user:token@…`). Aucun `.htaccess` interne ne peut couvrir ce cas : il
  faudrait une règle `RedirectMatch` à la **racine** du plugin, non testable ici et susceptible
  d'interférer avec le service des ressources. Le répertoire n'existe pas sur une installation par le
  market → **recommander l'installation par archive** dans la documentation utilisateur (post-mvp/07).
- **Hors UC01, résidus de template repérés** (à traiter en UC03/UC04, ou en post-mvp/07 pour l'i18n) :
  `desktop/php/smartclim.php` (`data-l2key="param1"` ; l. 28 `{{Mes smartclims}}`, artefact du renommage
  automatique ; l. 31 mentionne encore « Template » ; l. 148 `title="Assistant cron"` **non enveloppé**),
  `desktop/js/smartclim.js` (l. 60 `placeholder="Unité"` non enveloppé), et
  `core/template/*/cmd.action.other.templeteTemplate.html`.
  ⚠️ Le champ `data-l2key="password"` du même fichier, lui, a été **supprimé dans UC01** (§ 1.10) : ne
  pas le réintroduire. Si un secret **par équipement** devient nécessaire un jour, décommenter d'abord
  les méthodes d'instance `encrypt()`/`decrypt()` de `smartclim` — sans elles, la valeur serait stockée
  **en clair** dans la colonne `configuration` de l'eqLogic et renvoyée en clair dans son JSON.
