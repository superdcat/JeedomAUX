# Jeedom — page de plugin au menu (panel) & toggle d'affichage natif

> Connaissance **générique Jeedom** (indépendante du domaine). Sujet : comment ajouter une **page** de
> plugin au menu d'accueil Jeedom (≠ widget de commande, ≠ page de gestion admin), et comment son
> affichage est **conditionné nativement** par le core. Distinct de `jeedom-widgets-commandes.md` (widgets
> de commande sur dashboard). Les exemples utilisent l'id `<id>` (= `template` tant qu'il n'est pas
> renommé).

## 1. Trois choses différentes à ne pas confondre

| Élément | Fichier | Où ça apparaît |
|---|---|---|
| **Page de gestion** du plugin | `desktop/php/<id>.php` (ex. `template.php`) | menu **Plugins** (admin), via `gotoPluginConf` / liste des plugins |
| **Page-panneau** (vue utilisateur) | `desktop/php/<fichier>.php` déclaré par `info.json "display"` | menu **d'accueil** Jeedom |
| **Widget** de commande | `core/template/.../cmd.<type>.<subType>.<nom>.html` | sur un **dashboard**, posé sur une commande |

## 2. Enregistrer une page-panneau au menu

- `info.json` → `"display": "<fichier>"` (**sans extension**, fichier sous `desktop/php/`). Le core ajoute
  une entrée au **menu d'accueil**, routée par `index.php?v=d&p=<fichier>&m=<plugin>`.
- `"mobile": "<fichier>"` → panneau de l'**app mobile**. ⚠⚠ **Corrigé le 2026-09-16 (UC03 du domaine
  post-mvp/06) — la version précédente de cette ligne était fausse sur deux points.** Source réelle,
  `core/class/plugin.class.php:122-125` :
  ```php
  $plugin->mobile = '';
  if (file_exists(__DIR__ . '/../../plugins/' . $data['id'] . '/mobile/html')) {
      $plugin->mobile = (isset($data['mobile'])) ? $data['mobile'] : $data['id'];
  }
  ```
  Le dossier testé est **`mobile/html`** (et **non** `mobile/php`), et la clé n'est lue **que si ce dossier
  existe**, avec **repli sur l'id du plugin**. ⇒ Conséquence pratique, et elle est favorable : un plugin qui
  ne déclare que `"display"` n'affiche **qu'une seule** case (« Afficher le panneau desktop »,
  `desktop/js/plugin.js:214-224`) et **aucune case mobile morte** — `plugin.js:226-235` est conditionné à
  `data.mobile != ''`. Le panneau mobile peut donc rester hors périmètre **sans effet de bord d'IHM**.
- Plugin de référence officiel : **`jeedom/plugin-gsl`** (`desktop/php/panel.php`, `info.json
  "display":"panel"`, `"mobile":"panel"`).

## 3. Toggle d'affichage = NATIF (rien à coder côté plugin)

Dès que `display` est posé, le core génère automatiquement, dans la **zone de gestion du plugin**
(`#div_plugin_panel`, à côté des réglages cron/dépendances), les cases **« Afficher le panneau
desktop / mobile »** :

- liées aux clés de config **`displayDesktopPanel`** / **`displayMobilePanel`**
  (`config::save/byKey(..., '<id>')`) ;
- **décochées par défaut** (config absente ⇒ valeur falsy) ⇒ l'entrée de menu est **masquée par défaut** ;
  cocher l'affiche, décocher la retire — **le core lit ces clés** pour construire/masquer l'entrée ;
- source vérifiée : core `desktop/js/plugin.js` (construction de `#div_plugin_panel`).

⇒ **Ne pas** inventer un interrupteur maison (`panelEnabled`) ni réécrire `info.json` au runtime pour
masquer la page. `plugin::getDisplay()` (core `core/class/plugin.class.php`) renvoie la **valeur brute
statique** d'`info.json`, **sans** condition : le conditionnel vient **uniquement** des clés
`displayDesktopPanel/Mobile`. (Écart à signaler si une doc laisse croire l'inverse : la doc
`structure_info_json` ne décrit que `display`/`mobile`, pas le mécanisme de toggle — il est dans le code du
core.)

## 4. Contrat de la page panel & sélection par équipement

- En-tête : **rien à réinclure**. ⚠ **Corrigé le 2026-09-16 (UC03 post-mvp/06)** — cette ligne prescrivait
  `require_once core/php/core.inc.php` + `include_file('core','authentification','php')` : c'est
  **superflu**. `desktop/php/index.php:24` fait déjà le premier, et `:84` le second, **avant** d'inclure la
  page (`:630`). La page s'ouvre donc directement sur sa garde. **Suivre le fichier existant du plugin**
  (`desktop/php/smartclim.php`, `desktop/php/panel.php`), pas une prescription d'en-tête.
- Garde d'accès : `isConnect()` **sans argument** (utilisateur connecté, **pas** admin → usage quotidien).
  Refus = `throw new Exception`, rattrapé par `index.php:88-100` et rendu en `alert-danger`.
- Contrôle d'accès **par eqLogic** : n'afficher une cellule/un équipement que si `hasRight('r')`
  (+ `getIsEnable()`).
- **Sélection** d'un équipement dans le panel : ⚠ **`getConfiguration('isVisiblePanel')` n'est PAS un
  mécanisme du core** — corrigé le 2026-09-16 (UC03 post-mvp/06). C'est une **convention du plugin GSL**,
  que le core ignore totalement. Avant d'en réimplanter une : le core offre déjà **`eqLogic::getIsVisible()`**
  (case « Visible » de la configuration d'équipement), que le dashboard honore (`core/ajax/eqLogic.ajax.php:38`)
  et qu'une page-panneau peut filtrer à l'identique, **à coût zéro**.
  ⚠ Distinguer les deux besoins : `isVisible` (et `isVisiblePanel`) sont des réglages **d'administrateur,
  globaux à tous les utilisateurs**. Un filtre réellement **par utilisateur** n'a **aucun support natif** :
  il exigerait une clé dans `user::setOptions()`, un **endpoint AJAX non-admin en écriture** et une IHM de
  sélection — décision à prendre pour elle-même, pas à glisser dans une UC d'affichage.
- Contenu : la page **réutilise** les commandes existantes (`jeedom.cmd.execute`) et, pour afficher une
  ressource externe (image/carte), un endpoint same-origin (cf. `jeedom-widgets-commandes.md` § 7 — la CSP
  interdit une image externe directe).
- **Image externe dans un panel : `data:` URI plutôt que proxy.** La page panel étant **rendue côté
  serveur**, elle peut appeler directement le code PHP qui récupère la ressource externe et **l'embarquer
  inline** en `data:image/…;base64,…` — autorisé par la CSP (`default-src` inclut `data:`), **sans**
  aller-retour HTTP vers un endpoint proxy. Le **proxy same-origin** (`core/ajax/*.ajax.php`) reste
  nécessaire **uniquement** pour un **widget de dashboard** (HTML rendu côté client, qui ne peut pas
  exécuter de PHP). Dans les deux cas, mutualiser la même méthode PHP (fetch + validation + cache),
  consommée par le panel en `data:` et par le proxy en relai binaire. ⚠️ Un panel rendant N ressources
  synchrones = N fetch au chargement à froid → cache serveur (fichier) indispensable.

## 5. ⚠⚠ Droits Jeedom : ce que `hasRight()` fait VRAIMENT

Vérifié en source le 2026-09-16 (UC03 post-mvp/06), `core/class/eqLogic.class.php:1228-1248` :

```php
public function hasRight($_right, $_user = null) {
  …
  if (!isConnect()) { return false; }
  if (isConnect('admin') || isConnect('user')) { return true; }
  if (strpos($_SESSION['user']->getRights('eqLogic' . $this->getId()), $_right) !== false) { return true; }
  return false;
}
```

**Deux faits contre-intuitifs, et ils coûtent cher à redécouvrir :**

1. **Les profils `admin` ET `user` ont tous les droits sur tous les équipements**, inconditionnellement.
   Les droits **par équipement** n'existent **que** pour le profil **`restricted`**.
   ⇒ **Toute exigence de la forme « tel utilisateur ne voit / ne pilote que tel équipement » est
   INVÉRIFIABLE en recette avec un profil `user`** : la page affichera tout et tout sera pilotable, **quel
   que soit le code**. Créer un utilisateur `restricted` est donc une **étape 0 non contournable** du
   protocole de recette, pas une précaution.
2. **Il n'existe aucun droit `w` sur un eqLogic.** L'IHM du core (`desktop/modal/user.rights.php:46-48,
   65-68`) ne propose que trois valeurs : `n` = Aucun, `r` = Visualisation, **`rx`** = Visualisation et
   exécution. ⇒ Une spec qui dit « lecture sans écriture » se traduit en **« `r` sans `x` »**.

**Corollaire pour le code — un trou à combler soi-même** : `cmd::hasRight()` existe
(`cmd.class.php:2873-2878` : `action ⇒ eqLogic->hasRight('x')`, `info ⇒ hasRight('r')`), mais **le core ne
l'appelle pas depuis `cmd::toHtml()`**. Un widget rendu à un utilisateur `r`-seul affiche donc **ses
commandes d'action comme si elles étaient actionnables**. C'est au plugin de les neutraliser.
⚠ Cette neutralisation est de l'**ergonomie**, jamais une frontière de sécurité : la barrière réelle est
`core/ajax/cmd.ajax.php:80-104`, qui refuse déjà toute action sans `hasRight('x')`. Ne jamais présenter un
drapeau d'IHM comme un contrôle d'accès.

**Deux barrières natives à connaître, elles font le travail gratuitement** :
- `eqLogic::preToHtml()` (`:754`) rend `''` si `!hasRight('r') || !getIsEnable()` — un équipement interdit
  ne se rend **pas**, sans une ligne de code. ⚠ Mais il réévalue les droits **au moment du rendu** : une
  liste filtrée en amont peut donc voir une de ses tuiles rendre `''`, d'où les **en-têtes de groupe
  orphelins** si l'on émet un titre avant d'avoir le contenu. Tamponner.
- `eqLogic::toHtml()` appelle `getCmd(null, null, true)` — 3ᵉ paramètre = **visibles seulement** : un
  masquage de commandes déjà posé par le plugin s'applique **nativement** dans toute page qui rend un
  équipement.

## Sources
- Core : `core/class/plugin.class.php` (`getDisplay()`), `desktop/js/plugin.js` (cases panel).
- Doc : `structure_info_json` (champs `display`/`mobile`).
- Réf. : `jeedom/plugin-gsl`.
