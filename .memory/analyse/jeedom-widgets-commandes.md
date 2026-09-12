# Widgets de commande Jeedom — mécanisme, tokens, multi-commandes (vérifié source du core)

> Connaissance **générique Jeedom** (vérifiée directement dans la source du core : `cmd.class.php`,
> `cmd.class.js`, `cmd.ajax.php`). **Corrige deux hypothèses fausses** souvent admises :
> `#cmd_id[logicalId]#` et `jeedom.cmd.byEqLogicId` **n'existent pas**. À relire avant tout nouveau widget
> de commande. Les exemples utilisent l'id de plugin `<id>` (= `template` tant qu'il n'est pas renommé).

## 1. Déclaration & fichiers

- Un widget de commande = un fichier `core/template/<version>/cmd.<type>.<subType>.<nom>.html`,
  `<version>` ∈ `{dashboard, mobile}`. Ex. : `cmd.info.string.<nom>.html`,
  `cmd.action.other.<nom>.html`, `cmd.info.numeric.<nom>.html`.
- Assignation côté PHP : `$cmd->setTemplate('dashboard'|'mobile', '<id>::<nom>')` (préfixe = id plugin,
  `<nom>` = suffixe du fichier). Le core résout le fichier d'après `type`/`subType` de la commande.
- **Dashboard et mobile sont deux fichiers séparés** (souvent copies identiques → l'en-tête HTML rappelle
  de les synchroniser). **i18n : une entrée de chemin par fichier** (`plugins/<id>/core/template/
  dashboard/<f>` ET `.../mobile/<f>`), même pour des chaînes identiques.
- ⚠️⚠️ **L'i18n d'un template de widget de plugin est DOUTEUSE — hypothèse non mesurée (2026-09-12).**
  Le point ci-dessus décrit ce qu'on **croyait**. Lecture du core (`V4-stable`) : `cmd::toHtml()` termine
  par `if ($isCorewidget) return translate::exec($template, 'core/template/widgets.html');`, et
  `getWidgetTemplateCode()` renvoie `'isCoreWidget' => true` **y compris pour un fichier de plugin**
  (seule la branche `customtemp::` renvoie `false`). `translate::exec()` résoudrait alors le domaine par
  `getPluginFromName('core/template/widgets.html')` = **`core`**, si bien que les entrées
  `plugins/<id>/core/template/…` des `core/i18n/*.json` **ne seraient jamais lues** : en `fr_FR` les
  `{{…}}` sont simplement dépouillés, dans les autres langues la chaîne source serait renvoyée telle
  quelle. **C'est une déduction de lecture, PAS un fait mesuré** — ne pas la propager comme acquise.
  **Protocole pour trancher** : poser un `{{Test i18n}}` littéral dans un template `cmd.*` de plugin,
  ajouter sa traduction dans `core/i18n/en_US.json` sous le chemin du fichier, basculer l'interface en
  anglais, observer. Tant que ce test n'est pas joué, cette section reste **à vérifier**.
  ⚠️ **Conséquence pratique, elle, applicable dès maintenant** : ne mettre **aucun `{{…}}`** dans un
  template de widget et **injecter les chaînes déjà traduites** depuis un `.class.php` (domaine i18n dont
  le fonctionnement est, lui, prouvé). C'est ce que fait `smartclim::climatiseur` (UC01 du domaine
  post-MVP 06), et les deux widgets du plugin sont dépourvus de `{{…}}`.

## 2. Tokens disponibles dans le HTML (remplacés par `cmd::toHtml()`)

`#id#`, `#logicalId#`, `#eqLogic_id#`, `#name#`, `#name_display#`, `#uid#`, `#version#`.
Pour une **action liée** à une info (via `setValue`) : `#value_id#`, `#state#`.

- `#uid#` = id DOM unique de l'instance → **scoper le script** : `document.querySelector('.cmd[data-cmd_uid=#uid#]')`.
- ⚠️ **Aucun token ne référence une AUTRE commande par logicalId.** `#cmd_id[<autre>]#` & co
  **n'existent pas**. Le widget ne « voit » nativement que sa propre commande + sa commande liée.

## 3. Résoudre des commandes sœurs (widgets multi-commandes)

Besoin typique : un pavé qui pilote plusieurs commandes action d'un même équipement, ou une tuile qui lit
plusieurs commandes info ensemble.

- ⚠️ **`jeedom.cmd.byEqLogicId` n'existe pas** en JS (seul `refreshByEqLogic`, déprécié, pour le
  rafraîchissement d'affichage).
- Voie réelle : `fetch('core/ajax/cmd.ajax.php', { method:'POST', credentials:'same-origin',
  headers:{'Content-Type':'application/x-www-form-urlencoded'},
  body: new URLSearchParams({action:'byEqLogic', eqLogic_id:'#eqLogic_id#'}) })`.
  Réponse `{state:'ok', result:[ … ]}` : **toutes** les commandes de l'eqLogic
  (`utils::o2a(cmd::byEqLogicId(...))`), incluant `logicalId`, `id`, `isVisible` — **même les masquées**.
  Action `byEqLogic` = **utilisateur connecté** suffit (pas admin).
- Pattern robuste : résoudre la map `logicalId→id` **puis** câbler les boutons ; bouton **désactivé** si sa
  commande est absente (ex. équipement ne disposant pas de telle capacité). L'ancre connaît son propre id
  sans réseau (`#id#`).
- **Corollaire clé : masqué ≠ non-exécutable.** `isVisible=0` retire la tuile du dashboard mais la commande
  reste dans `byEqLogic`, exécutable, et listée dans la table admin → on peut masquer les boutons unitaires
  et laisser un pavé les piloter.
- ⚠️⚠️ **`byEqLogic` donne la STRUCTURE et les bornes, JAMAIS l'état** (fait établi, lu dans la source
  le 2026-09-12). `utils::o2a()` (`utils.class.php:48-82`) ne sérialise que les **propriétés
  persistées** : `id, logicalId, name, type, subType, unite, configuration, display, template, value,
  isVisible, isHistorized, order, generic_type, eqType, eqLogic_id, alert`. Les propriétés
  `_collectDate` et `_valueDate` sont **exclues par leur préfixe `_`**, et la **valeur courante n'y est
  pas** — elle vit dans le cache (cf. § 8.3). ⚠️ Le champ `value` de cette liste n'est **pas** la
  valeur : c'est la colonne `value` d'une commande **action**, soit l'id de son info liée.
  **Donc la phrase d'ouverture de cette section est un piège** : pour « une tuile qui lit plusieurs
  commandes info ensemble », `byEqLogic` **ne suffit pas** — la tuile s'afficherait vide au chargement.
  Les deux seules voies réelles sont un `execCmd()` par commande info (N requêtes par affichage, et le
  piège du § 8.7 à chaque site d'appel) ou, bien mieux, l'**injection serveur** du § 9.

## 4. Exécuter une action depuis un widget

- `jeedom.cmd.execute({ id, value?, notify?, success?, error? })` — gère **token CSRF + droits +
  prompts** (code PIN -32005 / confirmation -32006).
- `success(data)` reçoit `{state, result}` où **`result` = la valeur de retour PHP de `cmd::execute()`**.
  ⇒ Faire `return $payload;` dans l'action PHP livre la donnée au widget en **un seul aller-retour**.
  `notify:false` supprime le toast (utile pour un refresh périodique d'une tuile).
- ⚠️ **Activer la confirmation d'une action (anti-fausse-manip)** — vérifié source `jeedom/core` : côté
  **serveur**, poser `$cmd->setConfiguration('actionConfirm', 1)` (à la création, avant `save()`).
  `core/ajax/cmd.ajax.php` fait alors, **avant** `cmd::execCmd()` :
  `if ($cmd->getType()=='action' && $cmd->getConfiguration('actionConfirm')==1 && init('confirmAction')!=1)
  throw new Exception(__('Cette action nécessite une confirmation', __FILE__), -32006);`. Le **-32006** est
  intercepté par `jeedom.cmd.execute` (JS core) qui affiche un `jeeDialog.confirm` (desktop) / `confirm`
  (mobile) natif et rejoue avec `confirmAction=1`. **Zéro code JS/HTML custom** ; la chaîne « Cette action
  nécessite une confirmation » est **traduite par le core** (ne pas la mettre dans les i18n du plugin).
  ⚠️ **Ce n'est PAS une frontière d'autorisation** : la garde vit uniquement dans le contrôleur AJAX de
  l'UI web. Un scénario Jeedom, l'API JSON-RPC (apikey) ou un autre plugin appelant `execCmd()` directement
  **contournent** le dialog. La vraie protection repose sur la maîtrise des droits Jeedom.
- **Commande action PARAMÉTRÉE (saisie utilisateur)** — pour recueillir **une valeur libre**,
  `subType='message'` : le widget natif rend un champ texte + bouton d'envoi, et `cmd::execute($_options)`
  reçoit la valeur dans **`$_options['message']`** (le widget expose **aussi** un champ « Titre » →
  `$_options['title']`, à lire en repli défensif). Bonnes pratiques : lire
  `is_string($_options['message'] ?? null)` avant tout cast (un scénario/API peut passer un tableau → sinon
  warning « Array to string conversion ») ; **valider/parser côté serveur** (rejet net, jamais de clamp
  d'une saisie utilisateur) car le widget natif n'impose aucune contrainte de format ; faire porter le
  **format attendu par le nom** de la commande (ex. `Régler l'heure (HHMM)`). Alternative écartée :
  `subType='slider'` (`$_options['slider']`) — inadapté à une saisie précise. ⚠️ Contrat
  `$_options['message']` = **convention core** ; sans interpréteur PHP local, le repli `title` + rejet
  serveur bornent le risque → à confirmer en recette.

## 5. Auth AJAX (core 4.4+)

- Authentification par **session** (cookie) ; protection CSRF des actions **mutantes** = **forçage du
  POST** (pas de token par requête pour une simple lecture). D'où : `byEqLogic` en `fetch` brut
  fonctionne ; les **mutations** passent par `jeedom.cmd.execute` (qui ajoute le token via `getParamsAJAX`).
- NB : `core/ajax/<id>.ajax.php` (l'AJAX de la page de config plugin) est typiquement **admin-only**
  (`isConnect('admin')` global) → **inutilisable** depuis un widget de dashboard (session utilisateur). Tout
  pilotage widget passe donc par le modèle de commandes + l'AJAX **core** (`byEqLogic`, `execCmd`), ou par
  un **endpoint AJAX plugin dédié non-admin** (`isConnect()` + `hasRight('r')`).

## 6. Appliquer un template sans écraser le choix utilisateur

⚠️⚠️ **« Si vide » NE VEUT PAS DIRE `=== ''` — mesuré dans `cmd::save()` (V4-stable, 2026-09-12) :**

```php
if ($this->getTemplate('dashboard', '') == '') { $this->setTemplate('dashboard', 'core::default'); }
if ($this->getTemplate('mobile', '') == '')    { $this->setTemplate('mobile', 'core::default'); }
```

Une commande **qui a été enregistrée une seule fois** ne porte donc **jamais** un template vide : le core
y grave la sentinelle `core::default`. Conséquences, vécues **deux fois** sur le plugin smartclim :

- une garde `getTemplate($v,'') === ''` ne matche plus dès le premier `save()` → le widget du plugin
  n'est **jamais** posé en automatique, et il faut le sélectionner à la main sur chaque commande ;
- une garde « le template ne contient pas `::` » ne matche pas davantage — la sentinelle en contient un.

**Le prédicat correct** : `$t === '' || $t === 'default' || $t === 'core::default'`. `default` nu est à
accepter parce que `cmd::getWidgetTemplateCode()` le traite exactement comme `core::default`. Toute autre
valeur est un widget réellement choisi (du core comme d'un plugin) et ne doit jamais être écrasée.

⚠️ **Évaluer `dashboard` et `mobile` SÉPARÉMENT** : l'IHM du core les règle par deux champs distincts.

⚠️ **Corollaire de conception** : ne jamais placer une action secondaire (masquage de commandes,
initialisation…) **en aval** de cette garde sans le savoir — un seul critère faux fait alors disparaître
deux comportements, et le second symptôme masque la cause du premier.

- Bord assumé : si l'utilisateur repasse explicitement au widget « par défaut » du core, le nôtre est
  re-posé au prochain sync. Toléré (cosmétique, rare) — et indissociable du prédicat ci-dessus.

## 7. ⚠️ CSP Jeedom : tout média/image EXTERNE est bloqué côté navigateur → proxy same-origin obligatoire

**Constat clé (Jeedom réel)** : la CSP des pages Jeedom est `default-src 'self' file: data: blob:
filesystem:` **sans `img-src`/`media-src`/`connect-src` explicites**. Tout ce qui n'est pas listé retombe
sur `default-src 'self'` → **le navigateur bloque le chargement de TOUTE ressource externe** : `<img src>`
vers une image/tuile distante (carte OSM/Mapbox, image de modèle…), `fetch` cross-origin, etc.
Symptôme : image cassée + texte `alt`, ou erreur console « violates Content Security Policy directive ».
La CSP est posée hors `core.inc.php`/`index.php` (front/reverse-proxy), sans réglage admin évident.

**Conséquence d'architecture** : tout widget affichant du **contenu externe** doit le faire **servir par
Jeedom lui-même** (origine `'self'`) via un endpoint du plugin. Le **serveur** n'a pas de CSP (règle
navigateur) → il peut récupérer la ressource externe et la relayer.

**Recette générique (ex. mini-carte / image distante dans un widget dashboard)** :
- Widget : une `<img>` pointant `core/ajax/<endpoint>.ajax.php?eqLogic_id=#eqLogic_id#` (même-origine → CSP
  OK) ; l'endpoint récupère côté serveur la ressource externe et la relaie en binaire.
- Endpoint `core/ajax/<endpoint>.ajax.php` (**séparé** de l'AJAX admin-only du plugin) : `isConnect()` **+
  `$eqLogic->hasRight('r')`** (admin/user OK) ; fetch durci (HTTPS, timeout, taille bornée, content-type en
  allow-list, jamais de header d'auth du plugin) ; **cache court** de la ressource.
- **Alternative sans réseau externe** : afficher les données en texte + un lien cliquable (pas de média) →
  aucun proxy, aucune dépendance, mais moins visuel. Décider selon le besoin.
- Voir aussi `jeedom-panel-page-menu.md` § 4 : dans une **page-panneau** (rendue serveur), on peut embarquer
  l'image externe en **`data:` URI inline** (autorisé par la CSP), sans endpoint proxy.

## 8. Créer et alimenter des commandes INFO : les contrats du core à connaître

> Vérifié dans la source du core (V4-stable) au cycle UC05 du MVP, en écrivant les commandes info du
> plugin. Ces cinq points sont **génériques à tout plugin Jeedom** et coûteux à redécouvrir : chacun
> échoue **silencieusement**, sans erreur ni log côté plugin.

### 8.1 ⚠️ `cmd::setName()` ampute silencieusement le nom

`cmd::setName()` passe la valeur par `cleanComponanteName()` (`core/php/utils.inc.php`), qui **supprime**
les caractères `& # ] [ % \ / ' " *` puis compacte les espaces.

- « Marche/Arrêt » devient **« MarcheArrêt »**, « Qualité de l'air » devient **« Qualité de lair »**.
- Aucune erreur, aucun log : le nom amputé est simplement enregistré.
- **Conséquence pour l'i18n** : un nom de commande traduit subit le même traitement. La contrainte
  s'applique donc aux **traductions** autant qu'à la source française — une tournure anglaise avec
  apostrophe (`Today's setpoint`) ou une barre oblique (`On/Off`) sera amputée dans l'interface.
- **Règle** : réserver une table de libellés **dédiée aux noms de commandes**, distincte de celle des
  libellés de phrase, et bannir ces caractères dans les deux sens (source et cibles).

⚠️⚠️ **Ce que `cleanComponanteName()` ne filtre PAS : `<` et `>`.** Ce n'est pas un filtre HTML — il
nettoie pour son propre usage, pas pour le contexte de sortie de l'appelant. Un nom d'équipement ou de
commande peut donc porter du balisage, et le squelette `jeedom/plugin-template` le rend **sans
échappement**. Détail, sink exact et correction :
`jeedom-config-plugin-et-cycle-de-vie.md` § 12.

### 8.2 `checkAndUpdateCmd()` : ce que renvoie son booléen, et ce qu'il écrit vraiment

`eqLogic::checkAndUpdateCmd($logicalId, $value)` appelé **sans** `$_updateTime` :

- si `execCmd() === formatValue($value)` **et** que la commande n'est pas réglée sur
  `repeatEventManagement == 'always'`, alors **`cmd::event()` n'est PAS appelé** ; seuls
  `cmd::setCache('collectDate', now)` et `eqLogic::setStatus('lastCommunication', now)` sont écrits ;
- il renvoie `true` **si et seulement si** un `event()` a été émis, c'est-à-dire si la valeur a
  réellement changé.

Ce booléen est donc un **détecteur de changement fiable et gratuit** : il évite de tenir un état
parallèle pour savoir si un cycle de scrutation a rapporté du neuf.

⚠️ **La seule échappatoire est le réglage `repeatEventManagement = 'always'`**, positionnable par
l'utilisateur commande par commande. Un mécanisme bâti sur ce booléen (horodatage « dernière donnée
fraîche », compteur de changements) repartira alors à chaque cycle. Ce n'est pas contournable sans
écraser un réglage utilisateur : cela se **documente**, cela ne se corrige pas.

### 8.3 `collectDate` et `valueDate` ne veulent pas dire la même chose

Dans `cmd::event()` (`core/class/cmd.class.php`) : `$repeat = ($oldValue === $value && …)`, puis
`setCollectDate(now)` **toujours**, et `setValueDate($repeat ? ancienne valeur : collectDate)`.

| Champ | Sens réel | Bouge quand ? |
|---|---|---|
| `collectDate` | date de **collecte** — « on a interrogé la source » | à **chaque** cycle, même sans changement |
| `valueDate` | date du **dernier changement** de valeur | seulement quand la valeur change |

Corollaire pratique : pour exposer à l'utilisateur **l'âge réel** d'une donnée d'API lente, il n'y a rien
à écrire — `valueDate` le porte déjà. Une commande « dernière mise à jour » n'a de sens que si on
l'alimente **conditionnellement** (cf. § 8.2), sinon elle ne fait que redire `collectDate`.

⚠️ L'état d'une commande info (`value`, `valueDate`, `collectDate`) vit **dans le cache**, pas en colonne
SQL : `execCmd()` lit `getCache(...)`. De même, `eqLogic::setStatus()` écrit dans le cache et **jamais**
en base — donc un cycle de scrutation qui ne fait que pousser des valeurs **n'émet aucun `save()`**
d'équipement.

### 8.4 ⚠️ `cmd::event()` jette silencieusement une valeur numérique hors bornes

Si `configuration.minValue` / `maxValue` sont posées sur une commande `numeric`, une valeur en dehors
est **abandonnée** : un `log::add('cmd', 'info', …)` dans le log **du core**, puis un retour — la
commande garde son ancienne valeur, et rien n'apparaît dans le log du plugin.

- Recopier des bornes « métier » (une plage de consigne personnalisée, par exemple) sur une commande
  **info** fait donc **disparaître sans un mot** les lectures réelles hors plage.
- Sans ces clés, le contrôle est neutre (`getConfiguration('maxValue', $value)` retombe sur la valeur
  elle-même).
- **Règle** : les bornes appartiennent aux commandes **action** (slider), pas aux commandes info. Pour
  une info, filtrer en amont dans le plugin et **journaliser** le rejet — échec bruyant plutôt que
  silencieux.

### 8.5 Créer des commandes de façon idempotente sans requête inutile

`cmd::byEqLogicIdAndLogicalId()` **interroge la base à chaque appel** (aucun cache statique), alors que
`eqLogic::getCmd()` ne met en cache que les résultats **trouvés**. Une boucle de création qui teste
l'existence concept par concept produit donc N requêtes **à chaque cycle**, indéfiniment — pas seulement
au premier.

**Motif à retenir** : lire **une seule fois** l'ensemble des commandes de l'équipement
(`getCmd(null, null)`), l'indexer par `logicalId`, puis itérer sur les définitions attendues. Bénéfice
annexe : l'index contient aussi les commandes **action**, ce qui évite une collision de `logicalId`
entre deux cycles de développement.

⚠️ Et le corollaire du § 6 reste valable : les propriétés d'une commande (`name`, `isVisible`,
`isHistorized`, `order`, template, `generic_type`) ne se posent qu'**à la création**. Les reposer à
chaque cycle réinitialiserait les réglages de l'utilisateur.

### 8.6 `generic_type` n'est pas décoratif

Poser un `generic_type` (`TEMPERATURE`, `THERMOSTAT_*`, `ONLINE`…) **enrôle automatiquement** la commande
dans les résumés d'objet, les widgets standard et les intégrations tierces (assistants vocaux,
thermostats). C'est une **décision fonctionnelle**, pas une étiquette : une valeur peu fiable ou lente
(donnée de cloud rafraîchie en dizaines de minutes) ne doit pas être déclarée comme une sonde de pièce.
Le laisser vide est réversible en une valeur ; le retirer après coup ne l'est pas, les intégrations
l'ayant déjà consommé.

### 8.7 ⚠️⚠️ `execCmd()` sur une commande ACTION l'EXÉCUTE — lire une valeur peut actionner le matériel

**Le piège** : pour lire la valeur courante d'une commande, on écrit naturellement `$cmd->execCmd()`.
Sur une commande **info**, c'est inoffensif — la valeur sort du cache (`getCache('value')`, cf. § 8.3),
aucun effet de bord. Sur une commande **action**, `execCmd()` **exécute réellement l'action** : elle
dispatche vers `cmd::execute()`, donc vers le `switch ($this->getLogicalId())` du plugin, donc vers un
**vrai ordre envoyé à l'appareil**.

**Conséquence concrète, vécue en conception au cycle UC08 de SmartClim** : une méthode d'affichage qui
parcourt `getCmd(null, null)` pour lire l'état d'un équipement et appelle `execCmd()` sur chaque commande
trouvée **allumerait le climatiseur en ouvrant simplement la page de configuration**. Le symptôme serait
incompréhensible pour l'utilisateur (l'appareil démarre « tout seul » quand on consulte Jeedom) et
invisible en relecture de code : rien ne distingue visuellement l'appel fautif de l'appel légitime.

**La règle** : dès qu'on lit des valeurs depuis un ensemble de commandes non trié, **filtrer sur le type
avant tout `execCmd()`** :

```php
foreach ($this->getCmd(null, null) as $cmd) {
  if ($cmd->getType() === 'info') {          // garde FONCTIONNELLE, pas cosmétique
    $index[$cmd->getLogicalId()] = $cmd;
  }
}
```

C'est une garde **fonctionnelle**, à traiter comme telle en review — pas un raffinement de style qu'on
peut « simplifier ». Elle est d'autant plus nécessaire que les `logicalId` d'un plugin sont souvent
**appariés** (une commande info `power` et une commande action `on`/`off` décrivant le même concept) :
un index construit par `logicalId` sans filtre de type peut voir la commande action écraser l'info, et
transformer chaque lecture en actionnement.

⚠️ Corollaire pour la conception : préférer, quand c'est possible, une méthode de lecture qui **reçoit**
les commandes info déjà filtrées plutôt qu'une méthode qui les redécouvre — le filtre oublié une seule
fois suffit à produire l'incident.

## 9. ⚠️⚠️ Injecter un état SERVEUR dans un widget : le canal `$_options` de `cmd::toHtml()`

Vérifié dans la source (`V4-stable`, 2026-09-12) et **mis en œuvre** par la tuile
`smartclim::climatiseur` (UC01 du domaine post-MVP 06). C'est la réponse au besoin du § 3 — un widget
qui agrège N commandes — **sans aucune requête au chargement, et sans aucun endpoint AJAX**.

Le point de départ est une limite : ⚠️ **aucun jeton de `cmd::toHtml()` n'expose `configuration`, ni une
AUTRE commande** que celle qui se rend (cf. § 2). Un widget agrégateur n'a donc rien à se mettre sous
la dent dans les jetons natifs.

Mais `cmd::toHtml($_version = 'dashboard', $_options = '')` fait, **avant** tout traitement propre au
type :

```php
$options = jeedom::toHumanReadable($_options);
$options = is_json($options, $options);
if (is_array($options)) {
  foreach ($options as $key => $value) { $replace['#' . $key . '#'] = $value; }
}
```

**Chaque clé du JSON passé en `$_options` devient donc un jeton `#clé#`** utilisable dans le template.
Et `eqLogic::toHtml()` appelle **systématiquement** `$cmd->toHtml($_version, '')` (branches `table` et
`default`) : le canal est **libre**, rien de ce qu'on y met n'écrase une valeur du core.

### Comment s'y brancher

⚠️ **Il n'existe AUCUN hook pour cela** : on **surcharge** `<id>Cmd::toHtml()`, une méthode publique du
core. Ce qui rend l'override légitime est `cmd::cast()` — appelée par `byId`/`byEqLogicId`/
`byEqLogicIdAndLogicalId` : `if (is_object($_inputs) && class_exists($_inputs->getEqType() . 'Cmd'))
{ … cast($_inputs, $_inputs->getEqType() . 'Cmd'); }`. **Toute** commande d'un équipement du plugin est
donc instanciée en `<id>Cmd`, et l'appel de `eqLogic::toHtml()` est **polymorphe**.

⚠️ **Piège de justification, vécu (2026-09-12)** : le squelette `jeedom/plugin-template` contient un
stub **commenté** `public function toHtml($_version = 'dashboard') {}` précédé de « *Permet de modifier
l'affichage du widget (également utilisable par les commandes)* ». Ce stub vit dans la classe
**eqLogic**, porte **un seul** paramètre, et n'est **pas** le point d'extension de `cmd::toHtml()` : le
recopier donne la mauvaise classe et la mauvaise signature. Ce n'est pas un hook documenté — c'est un
override, avec la fragilité qui va avec (ci-dessous).

### Les cinq règles à respecter

1. ⚠️ **Signature recopiée EXACTEMENT** (`($_version = 'dashboard', $_options = '')`). Un core futur qui
   ajouterait un paramètre rendrait la déclaration incompatible → **erreur fatale au chargement de la
   classe**, donc `<id>Cmd` introuvable, donc **tout le plugin hors service** — invisible à `php -l` et
   à la CI, fatale au runtime. À re-vérifier à chaque montée de `compatibility`. (Une forme variadique
   `...$_reste` survit à l'ajout d'un paramètre, au prix d'un risque moins évident à auditer.)
2. ⚠️ **Encoder la charge en base64.** Son alphabet (`A-Za-z0-9+/=`) est structurellement dépourvu de
   `#`, `{{`, `'` et `<` : c'est ce qui la rend inoffensive dans le HTML rendu — **et non** un
   filtrage. Sans cela, un nom de commande (modifiable par l'utilisateur, et `cleanComponanteName()` ne
   retire ni `<` ni `>`, cf. § 8.1) s'échapperait du littéral JS. Motif supplémentaire :
   `jeedom::toHumanReadable()` réécrit les motifs `#\d*#`, `#eqLogic\d+#`, `#scenario\d+#`,
   `#object\d+#` — une charge en clair pourrait les contenir.
3. ⚠️ **`textContent` / `.text()` côté client, jamais `innerHTML`** sur une donnée de la charge. Le
   base64 protège le **transport**, pas le **rendu**.
4. ⚠️ **Ne jamais laisser échapper une exception** : `try/catch (Throwable)` avec repli
   `parent::toHtml($_version, $_options)`. Un jet ici casse le rendu du **dashboard entier**.
5. ⚠️ **Garder une garde `$_options === ''`** avant d'injecter, pour ne pas écraser les options d'un
   autre appelant, et **aliaser par `jeedom::versionAlias()` pour le TEST seulement** (elle ramène
   `mview` → `mobile` et `dview`/`dplan`/`plan`/`view` → `dashboard` ; `'mobile'` et `'dashboard'` sont
   rendus à l'identique). Sans l'aliasing, un `getTemplate('plan', '')` renvoie `''` sur les vues
   `plan`/`view` et le widget y tombe en mode dégradé. **Transmettre `$_version` NON modifié au
   parent**, qui ré-aliase lui-même.

### Ce que ça coûte, et ce que ça évite

Coût : **une** lecture `getCmd(null, null)` par widget et par rendu (cf. § 8.5 — jamais N requêtes).
Évité : un aller-retour HTTP, un flash de widget vide au chargement, **et un endpoint AJAX**. Ce dernier
point est le plus important : un widget de dashboard est vu par des utilisateurs
**non-administrateurs**, or un `core/ajax/<id>.ajax.php` est gardé par `isConnect('admin')` (cf. § 5).
L'injection serveur fait donc tenir le besoin **sans écrire une seule ligne de contrôle d'accès** : le
rendu est déjà gardé par `eqLogic::preToHtml()` (`hasRight('r')`) et l'exécution par
`core/ajax/cmd.ajax.php` (`isConnect()` + `hasRight('x')`).

⚠️ **Corollaire pour un widget qui affiche une FRAÎCHEUR** : prendre le `#collectDate#` d'une commande
que le cycle **relit** réellement, jamais le `valueDate` d'une commande « dernière mise à jour » écrite
sous condition de changement (cf. § 8.3) — sinon un appareil stable affiche un âge de plusieurs heures
alors que la scrutation vient de confirmer son état. Et choisir la commande avec soin : celle qui
décrit l'**état regardé**, pas celle qui bouge à chaque cycle quoi qu'il arrive.

## 10. ⚠️⚠️ `jeedom.cmd.addUpdateFunction()` déduplique par le TEXTE de la fonction

Code du core (`core/js/cmd.class.js`, V4-stable, relu le 2026-09-12) :

```js
jeedom.cmd.addUpdateFunction = function(_cmd_id, _function) {
  ...
  for (var i in jeedom.cmd.update[_cmd_id]) {
    if (jeedom.cmd.update[_cmd_id][i].toString() == _function.toString()) {
      return                       // <- abonnement SILENCIEUSEMENT ignoré
    }
  }
  jeedom.cmd.update[_cmd_id].push(_function)
}
```

**Le piège.** Un widget dont la fonction d'abonnement est une closure au texte source **constant**
(typiquement une fonction générique qui relaie vers un tableau de gestionnaires) n'est enregistrée
**qu'au tout premier rendu**. Or un widget est **ré-rendu** couramment : `cmd::save()` appelle
`eqLogic::refreshWidget()`, et le dashboard remplace alors le HTML de la tuile. Au ré-rendu :

1. le nouveau script s'exécute, ses closures pointent vers le **nouveau** DOM ;
2. `addUpdateFunction()` voit un `toString()` identique et **retourne sans rien faire** ;
3. seule la closure du rendu **précédent** reste abonnée — elle écrit dans un DOM **détaché**.

Résultat : le widget se **fige**, sans aucune erreur console, sans rien dans les logs PHP. Vécu sur
smartclim (2026-09-12) : le bouton Marche/Arrêt de la tuile ne reflétait plus jamais l'état.

**La parade, c'est celle des widgets du core** : leur fonction enregistrée contient le littéral `#uid#`,
substitué par `cmd::toHtml()` avec un `mt_rand()` — son `toString()` **change donc à chaque rendu**. À
reproduire tel quel, en faisant figurer `#uid#` **dans le corps** de la fonction (une variable qui le
contient ne suffit pas : c'est le texte SOURCE qui est comparé), et à compléter par une garde
`if (document.querySelector('[data-cmd_uid="#uid#"]') === null) return` — le core n'expose aucune API de
désabonnement, les closures des rendus remplacés restent enregistrées et doivent devenir **inertes**.

⚠️ Corollaire de style : **tenir l'état d'une commande dans une variable JS**, jamais le relire depuis
une classe CSS (`hasClass('btn-primary')`). Sinon un affichage figé ne fait pas qu'afficher faux : il
fait **envoyer l'ordre inverse** de celui attendu.
