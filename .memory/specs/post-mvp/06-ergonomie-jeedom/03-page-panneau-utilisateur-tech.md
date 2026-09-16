# Spec technique — UC03 « Page-panneau multi-climatiseurs au menu Jeedom »

> **Domaine** : post-mvp/06-ergonomie-jeedom · **Spec fonctionnelle** : `03-page-panneau-utilisateur.md`
> **Dépend de** : UC01 de ce domaine (tuile `smartclim::climatiseur`)
> **Sources core** : `jeedom/core` branche **V4-stable**, relue le 2026-09-16. Tous les numéros de ligne
> ci-dessous désignant un fichier du **core** sont datés de cette lecture — les revérifier à toute montée
> de `compatibility` dans `info.json`.

## 0. Ce que fait cette UC, en une phrase

Elle **déclare une page** au menu d'accueil, **filtre une liste** par droit de lecture, et **rend inerte**
la tuile d'UC01 pour qui n'a pas le droit d'exécution — le rendu lui-même étant assuré **intégralement par
le mécanisme natif `eqLogic::toHtml()`**.

⚠️ **Corollaire qui commande tout le reste** : cette UC n'ouvre **aucune surface web**. Aucun endpoint AJAX
(ni admin ni utilisateur), aucun JS de page, aucune classe nouvelle, aucune clé de configuration, aucune
dépendance. Les seules requêtes réseau que la page déclenche sont celles du **core** : `core/ajax/cmd.ajax.php`
(exécution d'une commande) et `core/ajax/event.ajax.php` (boucle temps réel). Toute proposition future qui
ajouterait un endpoint à cette page rouvre l'analyse de sécurité faite ici.

## 1. Contrats externes

**Aucun service tiers.** Cette UC ne sort pas du plugin : elle n'appelle ni AUX Home, ni le cloud legacy, ni
le LAN. Les seuls contrats sont ceux du core Jeedom, tous relus en source.

### 1.1 Déclaration de la page — `core/class/plugin.class.php:104`

```php
$plugin->display = (isset($data['display'])) ? $data['display'] : '';
```

Entrée de menu — `desktop/php/index.php:80-83` :

```php
if ($pluginObj->getDisplay() != '' && config::byKey('displayDesktopPanel', $pluginObj->getId(), 0) != 0) {
  $panelLi = '<li><a href="index.php?v=d&m=' . $pluginObj->getId() . '&p=' . $pluginObj->getDisplay() . '">…';
}
```

⇒ `"display"` porte le **nom de fichier sans extension**, sous `desktop/php/`. Le conditionnel d'affichage
vient **uniquement** de `displayDesktopPanel` (défaut **0** ⇒ menu masqué, cf. R2).

⚠️ **Aucune clé `eventjs` n'est nécessaire** : `eventjs` (`plugin.class.php:98`, consommé en
`index.php:84-86`) ne sert qu'à charger un `desktop/js/event.js` de plugin sur **toutes** les pages. Notre
mécanisme d'abonnement est `jeedom.cmd.addUpdateFunction`, déjà global.

⚠️ **Aucune clé `"mobile"` non plus** : `plugin.class.php:122-125` teste l'existence du dossier
**`mobile/html`** et ne lit la clé **que si ce dossier existe**, avec repli sur l'id du plugin. En ne
déclarant que `"display"`, le core affiche **une seule** case (« Afficher le panneau desktop »,
`desktop/js/plugin.js:214-224`) et **aucune case mobile morte** (`plugin.js:226-235` est conditionné à
`data.mobile != ''`). Le panneau mobile reste hors périmètre **sans effet de bord d'IHM**.

### 1.2 Routage et i18n de la page — `index.php:630`, `core/php/utils.inc.php:74-90`

```php
if ($_plugin != '') { $_folder = 'plugins/' . $_plugin . '/' . $_folder; }
…
echo translate::exec(ob_get_clean(), $_folder . '/' . $_fn);
```

⇒ domaine i18n = **`plugins/smartclim/desktop/php/panel.php`**, résolu au plugin `smartclim` par
`translate::getPluginFromName()` (`translate.class.php:84-96`).

⚠️ **Les accolades doubles fonctionnent ici exactement comme dans `desktop/php/smartclim.php`** —
contrairement à `core/template/`, où `CLAUDE.md` les interdit (le core y renvoie `isCoreWidget => true`).
`.claude/scripts/verif-plugin.py` indexe déjà `desktop/**` : les clés du nouveau fichier seront exigées.

### 1.3 Rendu d'un équipement — `core/class/eqLogic.class.php:884-932`, `:749-757`

```php
public function preToHtml($_version = 'dashboard', …) {
  …
  if (!$this->hasRight('r') || !$this->getIsEnable()) { return ''; }
```

et dans `toHtml()` : `foreach ($this->getCmd(null, null, true) as $cmd) { … $cmd->toHtml($_version, ''); }`
— le 3ᵉ paramètre de `getCmd($_type, $_logicalId, $_visible, $_multiple)` (`eqLogic.class.php:1765`) vaut
`true` ⇒ **seules les commandes visibles sont rendues**.

⚠️ **Conséquence majeure, et c'est ce qui rend l'UC si courte** : le masquage posé par
`masquerCommandesTuile()` d'UC01 s'applique **nativement**. La page rend **la tuile seule**, pas les
commandes qu'elle reprend — sans une ligne de code.

⚠️ **Prémisse vérifiée de tout le plan** : `smartclim` (eqLogic) n'override **pas** `toHtml()` — le stub du
squelette est bien **commenté** (`core/class/smartclim.class.php`, dans un bloc `/* … */`). L'appel atterrit
donc dans le core. Seul `smartclimCmd::toHtml()` est overridé (UC01).

### 1.4 Droits — `core/class/eqLogic.class.php:1228-1248`

```php
public function hasRight($_right, $_user = null) {
  …
  if (!isConnect()) { return false; }
  if (isConnect('admin') || isConnect('user')) { return true; }
  if (strpos($_SESSION['user']->getRights('eqLogic' . $this->getId()), $_right) !== false) { return true; }
  return false;
}
```

⚠️⚠️ **Deux faits dirimants pour la recette** :

1. **Les profils `admin` ET `user` ont tous les droits sur tous les équipements.** Les droits par équipement
   n'existent que pour le profil **`restricted`**.
2. **Il n'existe aucun droit `w` sur un eqLogic.** L'IHM du core (`desktop/modal/user.rights.php:46-48, 65-68`)
   ne propose que trois valeurs : `n` = Aucun, `r` = Visualisation, `rx` = Visualisation et exécution.

⇒ L'AC5 « droit de lecture mais pas d'écriture » se lit, dans le vocabulaire du core, **« `r` sans `x` »**.
Et **AC2 comme AC5 sont invérifiables avec un profil `user`** — cf. § 11, point non contournable du protocole
de recette.

Prédicat natif complémentaire : `cmd::hasRight()` (`cmd.class.php:2873-2878`) → `action ⇒ eqLogic->hasRight('x')`,
`info ⇒ hasRight('r')`. ⚠️ **Le core ne l'appelle pas depuis `toHtml()`** : il ne masque donc pas de lui-même
les commandes action à un utilisateur `r`-seul. **C'est ce trou, et lui seul, qui oblige à écrire du code
pour l'AC5.**

### 1.5 Exécution d'une commande — `core/ajax/cmd.ajax.php:23`, `:80-104`

```php
if (!isConnect()) { throw new Exception(__('401 - Accès non autorisé', __FILE__)); }
…
if ($cmd->getType() == 'action' && !$eqLogic->hasRight('x')) {
  throw new Exception(__('Vous n\'êtes pas autorisé à faire cette action', __FILE__));
}
```

⇒ un non-admin est autorisé (AC4), et **la barrière d'exécution existe déjà côté serveur** (AC5).
⚠️ **La garde d'IHM ajoutée par cette UC est de l'ergonomie, jamais la frontière de sécurité** — même statut
que `actionConfirm` dans `CLAUDE.md`.

### 1.6 Pas de rendu HTML hors session — `eqLogic.class.php:1223-1226`

`eqLogic::refreshWidget()` n'émet qu'un **événement** (`eqLogic::update` : ids + drapeaux), **aucun HTML**.
Le client re-demande le rendu par `core/ajax/eqLogic.ajax.php` **dans sa propre session**.

⚠️ **Conséquence de sécurité, et elle est structurelle** : une valeur dérivée des droits placée dans la
charge de tuile (notre `pilotable`) ne peut **pas** fuiter d'un utilisateur à l'autre. `cmd::toHtml()` n'a
par ailleurs **aucun cache HTML** (`cmd.class.php:1633+`), et `eqLogic::emptyCacheWidget()` est vide dans le
core. **Ne jamais introduire de cache HTML sur cette tuile sans rouvrir ce raisonnement.**

### 1.7 Convention `isVisible` — `core/ajax/eqLogic.ajax.php:38`

Le dashboard filtre `getIsVisible() == '1'` **avant** `toHtml()`.
`eqLogic::byType($_eqType_name, $_onlyEnable = false)` (`eqLogic.class.php:197-213`) n'a **pas** de paramètre
`isVisible`, et trie déjà `ORDER BY ob.name, el.name` — **le groupement par objet est donc gratuit**.

### 1.8 Nom de fichier `panel` — trois comportements du core y sont attachés

- `core/php/utils.inc.php:1556-1570` — `pageTitle()` a une branche dédiée `case 'panel':` produisant
  « Panel Smartclim » ; tout autre nom retombe sur `default: $return = $_page;` ;
- `desktop/common/js/utils.js:518` — `['dashboard','view','plan','widgets','panel'].includes(document.body.getAttribute('data-page'))`
  conditionne la diffusion de `changeThemeEvent` **aux widgets** ;
- `jeedom/plugin-gsl` (référence officielle) utilise `panel`.

⇒ **fichier `desktop/php/panel.php`**, décidé. ⚠️ Un autre nom prive la tuile du `changeThemeEvent`, donc du
suivi de thème clair/sombre.

### 1.9 Socle JS d'une page-panneau — **vérifié, aucune inclusion nécessaire**

`desktop/php/index.php` charge inconditionnellement, **avant** le contenu de page (l. 630) :

| Besoin | Chargé par | Ligne |
|---|---|---|
| namespace `jeedom`, `jeedom.init()`, `jeedom.changes()` | `include_file('core', 'jeedom', 'class.js')` | `index.php:177` |
| `jeedom.cmd.execute` / `addUpdateFunction` / `refreshValue` | `include_file('core','js.inc','php')` → `core/php/js.inc.php:21` | `index.php:192` |
| **`moment`** (fraîcheur de la tuile) | `include_file('3rdparty','moment/moment-with-locales.min','js')` | `index.php:251` |
| `jeedomUtils`, `domUtils.loadScript` | `include_file('desktop/common','utils','js')` | `index.php:277` |
| **contenu de la page** | `include_file('desktop', $page, 'php', $plugin->getId())` | `index.php:630` |

Amorçage de la boucle temps réel, également page-indépendant : `desktop/common/js/utils.js:232-233`
(`DOMContentLoaded → jeedom.init()`), `core/js/jeedom.class.js:173-175` (écouteur `cmd::update` →
`jeedom.cmd.refreshValue()`), `:279-281` (`if (typeof user_id !== 'undefined') { jeedom.changes() }`,
`user_id` étant envoyé par `sendVarToJS` pour **tout utilisateur connecté**, `index.php:309`).

⇒ **Rien à inclure dans `panel.php`.** L'architecture « zéro JS de page » est **vérifiée, pas supposée**.

### 1.10 En-tête natif de `core/template/dashboard/eqLogic.html` — **quatre éléments, aucun de plus**

| Élément | Gestionnaire | Sur notre page |
|---|---|---|
| `<span class="cmd refresh" data-cmd_id="#refresh_id#">` | **inline, dans le gabarit lui-même** ; le même script inline **supprime** le span si `#refresh_id#` est vide | **fonctionne**, autonome |
| `<span class="panelLink">` | supprimé par le script inline du gabarit si `#panelLink#` est vide (notre cas) | **absent** |
| `<span class="reportModeVisible">` | aucun | masqué — `desktop/css/desktop.main.css:40-42` |
| `<a href="#eqLink#" class="reportModeHidden">` | aucun (lien HTML) | **c'est l'élément affiché** |

⚠️ **Aucune icône d'édition, d'historique ou de configuration à masquer** : `.cmd.editOptions` n'est **pas**
dans le gabarit, elle est injectée par `desktop/js/dashboard.js:227` en mode édition — donc jamais présente
chez nous. Aucun handler n'habite `plugin.template.js` ni `dashboard.js` pour un élément que nous rendons.
**AC8 n'est pas menacé.**

⚠️ **Un seul accroc réel, et il n'est pas cosmétique** : pour un non-admin, `getLinkToConfiguration()` rend
`'#'` (`eqLogic.class.php:1082-1087`), donc le nom de l'équipement est une ancre `<a href="#">`. Vérifié dans
`desktop/common/js/utils.js` : **aucune interception globale des clics sur `a[href]`** ; `hashchange`
(l. 283-288) ne fait que poser un drapeau 200 ms et `popstate` (l. 290) sort tôt sous ce drapeau ⇒ pas de
rechargement, mais un **saut en haut de page** à chaque clic sur un nom — d'autant plus gênant que cette
page est, par nature, longue. Traité au § 3, D5.

## 2. Écarts entre la documentation interne et le core réel

À corriger dans `.memory/analyse/jeedom-panel-page-menu.md` (fait en fin de cycle, § 12) :

- **§ 2 — `"mobile"` est faux sur deux points** : le dossier testé est **`mobile/html`** (pas `mobile/php`),
  et la clé n'est lue **que si ce dossier existe**, avec repli sur l'id du plugin. Cf. § 1.1.
- **§ 4 — l'en-tête prescrit est superflu** : `index.php:24` fait déjà `require_once core/php/core.inc.php`,
  et `index.php:84` `include_file('core','authentification','php')` **avant** d'inclure la page.
  `desktop/php/smartclim.php` ne les réinclut pas. ⚠️ **Suivre le fichier existant, pas l'analyse.**
- **§ 4 — `getConfiguration('isVisiblePanel')` n'est pas un mécanisme du core**, c'est une convention du
  plugin GSL. Le core offre déjà `eqLogic::getIsVisible()`, honoré par le dashboard.
- **Bonus non documenté, hors périmètre** : `eqLogic::preToHtml()` (`:791-798`) consomme
  `getConfiguration('panelLink')` et pose une icône de lien sur la **tuile de dashboard** pointant vers
  `index.php?v=d&m=smartclim&p=<panelLink>`. Chemin natif dashboard → page-panneau, gratuit si un jour voulu.

## 3. Décisions d'architecture

### D1 — Rendu 100 % serveur par `eqLogic::toHtml()` natif, zéro endpoint, zéro JS de page

La page est un **assembleur**, pas une application. Justification : § 1.3 (le masquage d'UC01 s'applique
nativement), § 1.9 (le socle JS est déjà là), § 1.5 (la barrière d'exécution est déjà là).
⚠️ **C'est ce qui rend l'AC6 vrai par construction** : la page ne peut pas divulguer un secret puisqu'elle
ne construit aucune charge réseau propre.

### D2 — `pilotable` : champ **obligatoire** du schéma de charge, ni fail-open ni fail-closed

⚠️⚠️ **Décision centrale de l'UC, et les deux sens de défaillance « naturels » ont tous deux été écartés.**

La clé ne peut manquer que par **régression de code** (aucun cache HTML — § 1.6 ; PHP et templates sont
livrés dans le même commit, une mise à jour Market ne peut pas les désynchroniser). Les trois options :

| Sens | Effet d'une régression | Verdict |
|---|---|---|
| **Fail-open** (`charge.pilotable !== false`) | contrôles actionnables pour un `restricted` `r`-seul ; le clic échoue proprement (§ 1.5) ⇒ **aucune faille**, mais **AC5 devient faux EN SILENCE**, et par R1 il n'est détectable qu'avec un profil `restricted` — donc en pratique jamais | **écarté** : mode de défaillance silencieux |
| **Fail-closed** (`charge.pilotable === true`) | tuile inerte **pour tout le monde, sur le dashboard** ⇒ détection immédiate, mais panne **totale** de l'IHM principale, **muette dans sa cause** (des boutons disparaissent sans que rien ne dise pourquoi) | **écarté** : coût disproportionné |
| **Champ obligatoire** (retenu) | la tuile bascule dans son **mode dégradé déjà spécifié** (bandeau `#name_display# : #state#`, aucun contrôle) | **retenu** |

Le troisième terme **domine les deux autres** : il donne la sûreté du fail-closed (aucun contrôle ⇒ **AC5
inviolable par construction**, et non par la valeur d'un drapeau) **avec** un symptôme visible et
auto-descriptif (la tuile entre dans son mode documenté, pas un demi-état inexpliqué), **sans ajouter une
seule branche** — la garde de forme existe déjà.

⚠️ **Ne jamais « simplifier » en réintroduisant un opérateur de défaut** (`!==`, `===`, `||`, `?:`) sur cette
clé : chacun rétablit l'un des deux modes écartés.

### D3 — Groupes vides : **deux barrières, deux causes**, aucune ne remplace l'autre

- `climatiseursVisibles()` garantit qu'aucun groupe vide n'est **construit** — l'entrée de groupe est créée
  **paresseusement**, au premier équipement qui franchit les trois filtres. Invariant **structurel**.
- `panel.php` garantit qu'aucun en-tête n'est **émis** si les tuiles du groupe rendent `''`.

⚠️ **La seconde n'est pas de la redondance défensive** : `eqLogic::preToHtml()` (§ 1.3) réévalue `hasRight('r')`
et `getIsEnable()` **au moment du rendu**, et ces deux valeurs peuvent changer entre l'appel à
`climatiseursVisibles()` et la boucle d'affichage (autre onglet admin, script, API JSON-RPC). Sans le tampon,
l'AC2 garde une **course résiduelle** produisant exactement l'en-tête orphelin qu'elle interdit.

### D4 — Un seul message pour « aucun accessible » et « aucun installé »

⚠️ **Décision de sécurité, pas d'ergonomie.** Un message distinguant les deux cas révélerait l'existence
d'équipements que l'utilisateur n'a pas le droit de voir — ce que l'AC2 interdit (« n'apparaît sous aucune
forme »). Ne pas « améliorer » en ajoutant un message plus informatif.

### D5 — Neutralisation de l'ancre `href="#"` par **une** règle CSS, discriminée par la sortie du core

Problème : § 1.10 — chaque clic sur un nom d'équipement fait sauter la page en haut, pour un non-admin.

```html
<style>#smartclim-panel .eqLogic-widget .widget-name a[href="#"]{pointer-events:none;cursor:default;color:inherit;}</style>
```

⚠️ **Le discriminant est la sortie du core elle-même** (`href="#"` ⇔ `!isConnect('admin')`,
`eqLogic.class.php:1083`) : **aucune branche PHP sur le profil** à écrire ni à maintenir, et le lien reste
**pleinement fonctionnel pour un admin** qui consulte la page.
⚠️ Sur l'AC8 : le gabarit du core pose déjà `style="width:#width#"` **en attribut inline**, et la tuile
d'UC01 une douzaine d'autres — toute CSP réellement appliquée sur cette installation autorise donc déjà
`style-src 'unsafe-inline'`. Un bloc `<style>` **n'ajoute aucune exigence nouvelle**.
⚠️ L'`id="smartclim-panel"` sur le conteneur existe **pour que la règle ne puisse pas fuir** vers un autre
écran.

### D6 — La charge de tuile reste **complète** ; seule l'interaction est retirée

⚠️⚠️ **Piste écartée, et la raison est bloquante** : vider `charge.actions` côté PHP quand l'utilisateur n'a
pas `x` — apparemment plus propre, et faux. Le **résumé d'en-tête** de D11 d'UC01 dérive ses libellés de
`charge.actions.modes` / `charge.actions.vitesses` (template l. **381** et **388** :
`libelleDe(charge.actions.modes, charge.infos.mode.valeur)`). Sur une liste vide, `libelleDe()` ne rend pas
une chaîne vide : elle retombe l. **87** sur `return String(_valeur)`, c'est-à-dire le **code générique brut
en majuscules** (`COOL`, `TURBO`). Un utilisateur `r`-seul verrait donc « COOL · TURBO » au lieu de
« Refroidissement · Turbo » : AC3 ne serait satisfait que sous une forme **technique et non traduite**, ce
qui casse au passage la garantie i18n du plugin pour **exactement les utilisateurs que l'AC5 vise**.

### D7 — Pas de filtre d'affichage par utilisateur ; on s'appuie sur `isVisible` du core

Arbitré avec l'utilisateur le **2026-09-16** (question « À confirmer » de la spec fonctionnelle).
Motifs : (1) le patron `isVisiblePanel` de GSL n'est **pas** un mécanisme du core et il est **global à tous
les utilisateurs** — il exprime « l'administrateur retire cet appareil de la vue », pas « je ne veux pas le
voir » ; (2) le core offre déjà cette curation avec `eqLogic::getIsVisible()`, que la page honore
nativement, à **coût zéro** ; (3) un vrai filtre **par utilisateur** n'a aucun support natif et exigerait une
clé dans `user::setOptions()`, **un endpoint AJAX non-admin en écriture** — la première surface web de l'UC,
alors que D1 n'en ouvre aucune — et une IHM de sélection.
⚠️ **Rouvrir ce sujet, c'est rouvrir D1 et toute l'analyse de sécurité de cette UC.** Si le besoin
« par utilisateur » se confirme, il mérite **une UC dédiée**, pas un ajout ici.

### D8 — Disposition : grille flex + regroupement par objet parent, sans pagination

Arbitré avec l'utilisateur le **2026-09-16**. Le regroupement est **gratuit** (`eqLogic::byType` trie déjà
`ORDER BY ob.name, el.name`, § 1.7) et reproduit l'organisation mentale du dashboard ; la grille `flex-wrap`
sur des tuiles de 230 px est responsive **sans média-query** ; une pagination sur un parc domestique
(typiquement 1 à 6 unités) ajouterait un état client pour rien, et une recherche exigerait du JS — donc une
surface, pour un gain nul en dessous de ~20 appareils.

⚠️ **Ne pas réutiliser la classe `div_displayEquipement`** : elle n'a **aucune règle de disposition en CSS**
(seul un `margin-left:1px` en `desktop.main.css:840`) — la mise en page du dashboard est **entièrement en
JS** (Packery). Notre conteneur porte ses propres styles inline ; `eqLogic::preToHtml()` pose déjà
`width:230px` en inline sur chaque tuile (`:801-804`), donc la grille tient sans feuille de style nouvelle.

### D9 — Retrait des clés mortes `version` et `eqLogic` de la charge de tuile

Arbitré avec l'utilisateur le **2026-09-16**. UC01 les avait posées avec l'instruction explicite « à
**retirer** si l'UC03 ne s'en sert finalement pas » (§ 11 de `01-widget-tuile-climatiseur-tech.md`) : la
condition est **résolue maintenant**. L'UC03 rend le nom par `#name_display#` du core et distingue les tuiles
par `#uid#`. Vérifié par `grep` : `charge.version` et `charge.eqLogic` n'apparaissent **nulle part** dans les
deux templates. Laisser ces clés, c'est laisser un commentaire devenu **faux** dans le code, sur une charge
désormais inlinée N fois par page.

## 4. Architecture — fichiers

| Chemin | État | Ce qui y entre | Convention |
|---|---|---|---|
| `plugin_info/info.json` | **modifié** | **une** clé : `"display": "panel"`. Pas de `"mobile"`, pas de `"eventjs"`. ⚠️ **Ne pas toucher `pluginVersion`** (hook `pre-commit`) | tabulations, **LF** (existant — seul fichier du lot dans ce cas) |
| `desktop/php/panel.php` | **CRÉÉ** | garde `isConnect()`, `climatiseursVisibles()`, `<legend>`, bloc `<style>` (D5), conteneur `id="smartclim-panel"`, tampon par groupe (D3), boucle `try/catch` par équipement, état vide. ~80 lignes. **Aucun `include_file` de JS** | **tabulations + CRLF** |
| `core/class/smartclim.class.php` | **modifié** | +1 méthode statique `climatiseursVisibles()` (~30 lignes), près de `profilsAffichables()` | 2 espaces + CRLF |
| `core/class/smartclimWidget.class.php` | **modifié** | +1 clé `'pilotable'` dans `chargeTuile()` ; **retrait** de `'version'` et du sous-tableau `'eqLogic'` (D9) ; docblock de classe amendé | 2 espaces + CRLF |
| `core/template/dashboard/cmd.info.binary.climatiseur.html` | **modifié** | **1 terme ajouté à la garde de forme** (D2) + **4 gardes** `pilotable` | 2 espaces + CRLF |
| `core/template/mobile/cmd.info.binary.climatiseur.html` | **modifié** | **copie octet à octet** du précédent | ⚠️ contrôle `diff` obligatoire (R3) |
| `core/i18n/{en_US,de_DE,es_ES}.json` | **intacts à ce stade** | étape `translator` en fin de cycle | — |
| `core/php/smartclim.inc.php` | **INTACT** | **aucune classe nouvelle** — `climatiseursVisibles()` vit sur `smartclim`, déjà chargée par l'autoloader. Aucun risque de « Class not found » | — |
| `core/ajax/smartclim.ajax.php` | **INTACT** | **aucun endpoint créé**, ni admin ni utilisateur (D1) | — |
| `desktop/js/smartclim.js` | **INTACT** | sert la page admin ; la page-panneau n'embarque aucun JS propre | — |
| `.htaccess`, `packages.json`, `smartclim.config.ini`, `configuration.txt/.php` | **INTACTS** | aucune dépendance, aucune clé de config, aucun champ de formulaire | — |

### ⚠️ Deux inclusions à NE PAS faire

- **`include_file('core', 'plugin.template', 'js')`** — `core/js/plugin.template.js:17` fait
  `document.body.setAttribute('data-type','plugin')` puis pilote la liste d'équipements **admin**
  (`displayEqlogic`, `bt_pluginDisplayAsTable`, `getUrlVars('id')`). Rien à faire sur une page utilisateur.
- **`include_file('desktop', 'dashboard', 'js')`** — dispose les tuiles avec **Packery** sur
  `.div_displayEquipement` et attend l'arbre d'objets, `jeephp2js.rootObjectId` et le mode édition.

## 5. Signatures

### 5.1 `smartclim::climatiseursVisibles()` — nouvelle, `public static`

```php
/** Climatiseurs affichables par l'utilisateur COURANT, groupés par objet parent, pour la
 *  page-panneau (UC03 du domaine post-mvp/06). Triple filtre, dans cet ordre :
 *    1. eqLogic::byType('smartclim', true) -> activés seulement (le core n'a AUCUN filtre de
 *       droits ici : byType est une requête SQL nue) ;
 *    2. getIsVisible()                     -> convention du core (core/ajax/eqLogic.ajax.php:38) ;
 *    3. hasRight('r')                      -> AC2.
 *  Ne lève JAMAIS. Aucun appel réseau, aucun save(), aucune écriture de cache.
 *
 *  POSTCONDITION (porteur de l'AC2 « pas de ligne vide ») : aucun groupe vide n'est JAMAIS
 *  construit. L'entrée de groupe est créée PARESSEUSEMENT, au premier équipement qui franchit
 *  les trois filtres — il n'existe donc pas de chemin par lequel un objet dont tous les
 *  climatiseurs sont écartés obtienne une entrée. Ne pas « nettoyer » après coup par un
 *  array_filter : l'invariant est structurel, pas défensif, et c'est ce qui le rend vérifiable
 *  en dix lignes.
 *
 *  @return array<int, array{nom:string, equipements:smartclim[]}>  clé = id d'objet, 0 = sans
 *          objet ; 'nom' = nom BRUT de l'objet, à échapper AU POINT DE SORTIE ; '' si aucun objet */
public static function climatiseursVisibles()
```

Notes de réalisation :
- L'ordre naturel de `byType` (`ORDER BY ob.name, el.name`) est conservé — **aucun `usort`**.
- Le libellé « Sans objet » n'est **pas** produit ici : la méthode rend `''`, la page rend `{{Sans objet}}`.
  ⚠️ C'est ce qui garde **toutes** les chaînes UI dans le fichier rendu, et **zéro** nouvelle clé i18n dans
  une classe PHP.

### 5.2 `smartclimWidget::chargeTuile()` — modifiée

```php
'pilotable' => $_eqLogic->hasRight('x'),   // bool, TOUJOURS présente
```

- Placée au même niveau que `'infos'` / `'actions'` / `'bornes'` / `'libelles'`.
- **Retraits simultanés** (D9) : `'version' => 1` et `'eqLogic' => array(…)`.
- ⚠️ **Le docblock de la classe DOIT être amendé** : « aucune E/S » reste vrai, mais la classe cesse d'être
  une fonction pure — elle lit désormais le **contexte d'authentification de la requête**. À écrire noir sur
  blanc, sinon une review future la « corrigera » en supprimant l'appel.
- ⚠️ **Ce drapeau n'est pas une frontière d'autorisation** : la seule barrière est `core/ajax/cmd.ajax.php:86`.
  À écrire dans le commentaire, sur le modèle de ce que `CLAUDE.md` dit d'`actionConfirm`.

### 5.3 Les deux templates de tuile — garde de forme + 4 gardes d'interaction

**Garde de forme** — ajouter un terme à la garde **déjà présente** (l. 101 des deux fichiers) :

```js
if (!charge || !charge.infos || !charge.infos.power || !charge.actions
    || typeof charge.pilotable !== 'boolean') {
  return
}
```

puis, en clair et **sans opérateur de défaut** : `var pilotable = charge.pilotable`.

⚠️ Cf. D2 : ni `!== false`, ni `=== true`. Le `return` laisse le bandeau `#name_display# : #state#` en place
(l. 105 n'étant jamais atteinte) ⇒ mode dégradé déjà spécifié, **aucun contrôle actionnable**.

**Les 4 gardes d'interaction** :

1. **Marche/arrêt** (l. ~150-192) — ne **pas** enregistrer le `$bouton.on('click', …)` si `!pilotable`, et
   poser `$bouton.addClass('disabled')`.
   ⚠️ **Inverser le texte dans `majPower()` quand `!pilotable`** : le bouton affiche aujourd'hui le nom de
   **l'action à faire** (« Arrêt » quand l'appareil tourne) ; en lecture seule il doit afficher **l'état**
   (`charge.actions.on.nom` si actif, `off.nom` sinon). **Sans cette inversion, l'AC3 « affiche marche/arrêt »
   est faux pour exactement les utilisateurs de l'AC5.**
2. **Consigne** (l. ~207-277) — conserver la valeur et l'abonnement ; retirer les deux boutons `−`/`+` et ne
   pas enregistrer leurs gestionnaires.
3. **Modes** (l. ~280-328) — construire les boutons **comme aujourd'hui** (ils portent le libellé et la mise
   en évidence du mode actif, donc l'affichage exigé par l'AC3), ajouter `disabled`, **ne pas** enregistrer
   `.on('click')`.
4. **Vitesses** (l. ~331-378) — idem.

⚠️ **Aucune chaîne nouvelle dans les templates** : la règle « zéro `{{…}}` dans `core/template/` » (D4 d'UC01)
tient sans effort.

### 5.4 `desktop/php/panel.php` — structure

```
<?php
  if (!isConnect()) { throw new Exception('{{401 - Accès non autorisé}}'); }   // JAMAIS isConnect('admin')
  $groupes = smartclim::climatiseursVisibles();
?>
<legend>{{Mes climatiseurs}}</legend>
<style>#smartclim-panel .eqLogic-widget .widget-name a[href="#"]{pointer-events:none;…}</style>   // D5

si $groupes vide -> <div class="alert alert-info">{{Aucun climatiseur accessible}}</div>
sinon, dans <div id="smartclim-panel">, par groupe :
    $tuiles = '';                                  // tampon : rien n'est émis avant d'avoir du contenu
    par équipement : try   { $tuiles .= $eqLogic->toHtml('dashboard'); }
                     catch (Throwable $t) { log::add('smartclim','error', …); }
    if (trim($tuiles) === '') { continue; }        // 2e barrière (D3)
    echo en-tête (nom d'objet ÉCHAPPÉ, ou {{Sans objet}});
    echo conteneur flex inline + $tuiles;
```

- `'dashboard'` est **écrit en dur** : la page est desktop. ⚠️ **Ne jamais dériver la version d'un `init()`.**
- Le `try/catch` **par équipement** est la transposition directe de l'invariant cron du projet —
  `smartclimCmd::toHtml()` a déjà son propre filet, mais il ne couvre ni `getObject()`, ni `preToHtml()`, ni
  une commande corrompue (AC7).
- ⚠️ **Tout `echo` d'une donnée passe par `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`** — ici le seul est le
  nom d'objet.

## 6. Server vs Client

| Responsabilité | Où | Pourquoi |
|---|---|---|
| Filtrage par droits | **serveur** (`climatiseursVisibles()` + `preToHtml()`) | AC2 : un filtrage client livrerait le HTML des équipements interdits |
| Composition de la page | **serveur** (`panel.php`) | D1 — aucune surface web ouverte |
| Décision « pilotable » | **serveur** (`chargeTuile()`), **appliquée** client | la valeur est calculée par `hasRight('x')` côté PHP ; le client ne fait qu'obéir |
| Autorisation d'exécution | **serveur** (`cmd.ajax.php:86`) | seule frontière réelle ; le client est **toujours** contournable |
| Mise à jour des valeurs | **client**, via l'abonnement `cmd::update` du core | déjà en place (UC01), page-indépendant (§ 1.9) |

## 7. Validation & erreurs

| Point | Où | Comportement |
|---|---|---|
| Session absente / expirée | `panel.php` | `throw new Exception('{{401 - Accès non autorisé}}')`, rattrapé par `index.php:88-100` et rendu en `alert-danger`. Même patron que `desktop/php/smartclim.php` |
| Droit de lecture manquant | `climatiseursVisibles()` **puis** `preToHtml()` | double barrière ; l'équipement n'apparaît sous **aucune** forme |
| Droit d'exécution manquant | tuile (IHM) **puis** `cmd.ajax.php:86` (autorité) | aucun contrôle actionnable ; un `execCmd` forgé est refusé serveur — plus la liste blanche de `definitionsCommandesAction()` |
| Charge de tuile sans `pilotable` | garde de forme du template | mode dégradé, aucun contrôle (D2) |
| Rendu d'un équipement en échec | `panel.php` | `try/catch (Throwable)` **par équipement** + `log::add('smartclim','error', …)`. La page continue (AC7) |
| Groupe dont toutes les tuiles rendent `''` | tampon de `panel.php` | `continue` avant d'émettre l'en-tête (D3) |
| Aucun climatiseur accessible | `panel.php` | **un seul et même message** que « aucun climatiseur installé » (D4) |
| Erreur d'exécution d'un ordre | `jeedom.cmd.execute` (`notify: true`, déjà en place) | message déjà curaté en français par `messageErreurAuxHome()` / `messageErreurLan()` |
| Exceptions | — | **aucun type nouveau.** Pas de `smartclimException` : aucun contrat externe n'est franchi |

## 8. Sécurité

- **AC6 — aucun secret** : la charge de tuile ne porte que des ids, valeurs génériques, unités, noms de
  commandes, bornes et libellés. Le gabarit `eqLogic.html` du core ne consomme que `#name_display#`,
  `#object_name#`, `#refresh_id#`, `#eqLink#`, `#uid#`. La page **n'appelle pas `sendVarToJS`**. Aucune
  requête réseau propre (D1).
- **Échappement au point de sortie** : `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')` sur le nom d'objet, seule
  donnée externe rendue par un `echo` de cette page.
- ⚠️ **`#name_display#` n'est pas échappé par le CORE** : `preToHtml()` injecte `getName()` **brut**. C'est
  un puits du **core**, commun à tous les plugins et déjà exercé par le dashboard. Il est désamorcé **en
  amont** par les trois nettoyeurs symétriques du plugin (`smartclimAuxHomeApi::nettoyerTexteExterne()`,
  `smartclimAuxCloudApi::nettoyerTexteExterne()`, `smartclimBroadlinkLan::nettoyerNomExterne()`), qui retirent
  `<` et `>`.
  ⚠️⚠️ **Cette UC AUGMENTE la portance de ces trois fonctions** : un nom issu d'une découverte LAN **non
  authentifiée** s'affiche désormais aussi sur une page **utilisateur**, pas seulement sur une page admin.
  **Ne jamais les affaiblir, et ne jamais en ajouter une quatrième asymétrique.**
- **Pas de fuite inter-utilisateurs** : § 1.6 — aucun cache HTML, le rendu se fait toujours dans la session
  du demandeur.
- **Divulgation par message** : D4.

## 9. Risques

- **R1 — AC2 et AC5 sont invérifiables avec un profil `user`.** `hasRight()` rend `true` inconditionnellement
  pour `admin` et `user` (§ 1.4). ⇒ § 11, première étape non contournable.
- **R2 — AC1 a un prérequis natif non codé.** `displayDesktopPanel` vaut **0** par défaut : l'entrée de menu
  n'apparaît pas tant qu'un administrateur n'a pas coché « Afficher le panneau desktop » sur la page de
  gestion du plugin. La spec le classe hors périmètre ; il doit figurer **en tête** du protocole de recette.
- **R3 — Deux fichiers à garder identiques octet à octet** (R9 d'UC01). Contrôle `diff` **obligatoire** avant
  commit — une divergence est **invisible en CI**.
- **R4 — Coût SQL en O(N).** Par climatiseur affiché : `getCmd('action','refresh')` + `getObject()` +
  `getCmd(null,null,true)` + `getCmd(null,null)` ≈ **4 requêtes**. ⚠️ `getCmd(null, null)` n'est **pas**
  mémoïsé (`eqLogic.class.php:1781-1783` ne remplit `$this->_cmds` que dans la branche `$_logicalId !== null`).
  Négligeable pour un parc domestique (≤ 10 appareils) ; **mesurer avant d'optimiser**, et **sans** mutualiser
  les deux lectures — cela imposerait de modifier le contrat d'UC01.
- **R5 — La page ne réagit pas à `eqLogic::update`.** Sans `dashboard.js`, un équipement désactivé, masqué ou
  supprimé pendant que la page est ouverte **n'y disparaît pas** : seul un rechargement le corrige. Les
  **valeurs**, elles, restent vivantes. ⚠️ À ne pas confondre avec une tuile figée.
- **R6 — Fraîcheur qui dérive** (R3 d'UC01, inchangé) : l'âge affiché croît sur un onglet resté ouvert tant
  qu'aucun changement réel ne survient. Se ré-ancre à chaque rechargement. **Aucune scrutation client ajoutée.**
- **R7 — Portance élargie des trois nettoyeurs** : § 8.
- **R8 — Accès HTTP direct au fichier.** `desktop/` n'a pas de `.htaccess` dans ce plugin. Un
  `GET /plugins/smartclim/desktop/php/panel.php` exécuterait le fichier hors contexte (`isConnect()` indéfini
  ⇒ erreur fatale, **pas de fuite de donnée**). Comportement **préexistant et universel**, identique à
  `desktop/php/smartclim.php` et aux pages du core. Un `Deny from all` sur `desktop/php/` serait un
  durcissement valide, **hors périmètre** car il change aussi le statut de la page admin.
- **R9 — Signature de `smartclimCmd::toHtml()`** (R1 d'UC01) : inchangée par cette UC, mais **la page-panneau
  en dépend désormais aussi**. À re-vérifier contre le core à chaque montée de `compatibility`.

## 10. Critères d'acceptation — couverture

| AC | Où | Statut |
|---|---|---|
| **AC1** | `info.json` + `desktop/php/panel.php`, garde `isConnect()` sans argument | couvert — ⚠️ prérequis R2 |
| **AC2** | `climatiseursVisibles()` + `preToHtml()` + tampon de groupe (D3) | couvert — ⚠️ recette `restricted` (R1) |
| **AC3** | tuile UC01 **inchangée** + inversion du texte marche/arrêt en lecture seule (§ 5.3, garde 1) | couvert |
| **AC4** | `jeedom.cmd.execute` de la tuile ; socle JS vérifié (§ 1.9) | couvert |
| **AC5** | `pilotable` (D2) + 4 gardes + barrière serveur `cmd.ajax.php:86` | couvert — ⚠️ recette `restricted` (R1) |
| **AC6** | § 8 | couvert |
| **AC7** | `try/catch (Throwable)` par équipement + bandeau hors-ligne de la tuile | couvert |
| **AC8** | zéro ressource externe ; § 1.10 (aucun handler manquant) ; D5 (le `<style>` n'ajoute aucune exigence) | couvert |

## 11. Points à valider en recette / limites assumées

⚠️⚠️ **Étape 0, NON CONTOURNABLE — sans elle, AC2 et AC5 seront déclarés en échec sur du code correct** :
créer dans *Outils → Utilisateurs* un utilisateur de profil **`restricted`** (ni `admin`, ni `user`), puis
lui régler ses droits **par équipement** dans *Droits* : `n` sur un climatiseur, `r` sur un deuxième,
`rx` sur un troisième. `hasRight()` rend `true` inconditionnellement pour `admin` et `user` (§ 1.4) : avec
l'un de ces deux profils, **la page affiche tout et tout est pilotable, quel que soit le code**.

1. **Prérequis d'AC1 (R2)** : cocher « Afficher le panneau desktop » sur la page de gestion du plugin, sinon
   l'entrée de menu n'apparaît pas.
2. **AC4 — vérifier le socle JS sur la page réelle** : bien que § 1.9 le tranche par lecture de source,
   contrôler en console que `jeedom.cmd.execute` et `jeedom.cmd.addUpdateFunction` sont définies sur
   `panel.php`, et qu'un clic déclenche un aller-retour `cmd.ajax.php`.
3. **AC2** : l'équipement en `n` n'apparaît **sous aucune forme** — ni ligne vide, ni en-tête d'objet
   orphelin (vérifier spécifiquement le cas d'un **objet dont le seul climatiseur** est en `n`).
4. **AC5** : sur l'équipement en `r`, aucun bouton actionnable ; le marche/arrêt affiche l'**état** et non
   l'action ; le mode et la vitesse restent **traduits** (D6).
5. **AC8** : console sans violation CSP ni ressource cassée ; vérifier qu'un clic sur le **nom** d'un
   équipement ne fait pas sauter la page (D5).
6. **Thème** : basculer clair/sombre et vérifier que les tuiles suivent (§ 1.8, `changeThemeEvent`).
7. **Non-régression dashboard** : la tuile est partagée. Vérifier que le dashboard recetté le 2026-09-12 est
   inchangé pour un admin — `pilotable` y vaut **toujours `true`** (§ 1.4), la modification est donc un
   **no-op** pour tout profil autre que `restricted`.
8. **AC7 — robustesse par équipement** : forcer une erreur de rendu sur **un** climatiseur (par exemple en
   supprimant temporairement une de ses commandes en base), puis vérifier que **les autres** s'affichent
   normalement et qu'une ligne apparaît dans `log/smartclim`. ⚠️ Sans ce point, le `try/catch (Throwable)`
   par équipement — comportement **nouveau** introduit par cette UC — n'est exercé par aucune étape de
   recette : il ne se déclenche jamais sur un parc sain.

## 12. Dette

*(Section alimentée en fin de cycle par les findings de review n'atteignant pas la gate.)*

- **Panneau mobile** — non déclaré (§ 1.1). L'ajouter exigerait un dossier `mobile/html`, hors périmètre de
  cette UC (et hors spec fonctionnelle).
- **R5** — la page ne réagit pas à `eqLogic::update` (équipement supprimé/masqué en cours d'affichage).
  Correction possible par un abonnement dédié, au prix du premier JS de page — donc d'une entorse à D1.
- **R8** — `desktop/php/` sans `.htaccess`. Durcissement valide mais transverse (il change aussi le statut de
  la page admin) : à traiter globalement, pas dans cette UC.
- **R4** — coût SQL en O(N), à mesurer avant toute optimisation.
