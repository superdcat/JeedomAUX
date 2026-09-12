# Spec technique — UC01 « Widget de commande "climatiseur" »

> **Domaine** : post-mvp/06-ergonomie-jeedom · **Spec fonctionnelle** : `01-widget-tuile-climatiseur.md`
> **Statut** : plan validé le 2026-09-12 · **Dépend de** : UC06 du MVP (commandes action)
>
> Toutes les affirmations sur le comportement du **core Jeedom** de cette spec ont été lues dans la
> source (`jeedom/core`, branche `V4-stable`, relue le 2026-09-12). Les numéros de ligne cités dérivent :
> le **nom de méthode** et le **comportement** sont ce qui fait foi.

## 0. Ce que fait cette UC, en une phrase

Elle **habille** des commandes déjà créées par le socle MVP : un widget de plugin, posé sur la commande
info `power`, rend une **tuile unique** (marche/arrêt, ambiante, consigne, mode, vitesse, transport,
fraîcheur) et les commandes qu'elle reprend sont masquées. **Aucune commande n'est créée, aucun
`logicalId` n'est touché, aucune clé de configuration n'est ajoutée.**

⚠️ **Invariant de conception de cette UC : zéro surface web nouvelle.** Aucun fichier `*.ajax.php` n'est
créé, aucun endpoint n'est ajouté à `core/ajax/smartclim.ajax.php`, et la tuile n'émet **aucune requête au
chargement**. Tout l'état initial et toute la structure sont **injectés par le serveur** dans le HTML
rendu. C'est ce qui fait tenir l'AC10 (utilisateur non-administrateur) sans écrire une seule ligne de
contrôle d'accès : le rendu est déjà gardé par `eqLogic::preToHtml()` (`hasRight('r')`) et l'exécution par
`core/ajax/cmd.ajax.php` (`isConnect()` + `hasRight('x')`).

## 1. Contrats externes

Aucun service tiers : **cette UC ne sort pas du plugin**, ni vers AUX Home, ni vers le cloud legacy, ni
vers le LAN. Les seuls contrats sont ceux du core Jeedom.

### 1.1 Résolution du fichier de widget — `cmd::getWidgetTemplateCode()`

`cmd::getWidgetTemplateCode()` → `getTemplate('core', $_version, 'cmd.<type>.<subType>.<nom>',
$this->getEqType())` → `plugins/smartclim/core/template/<version>/cmd.<type>.<subType>.<nom>.html`
(`core/php/utils.inc.php:110-128`).

Valeur à poser : `setTemplate('dashboard'|'mobile', 'smartclim::climatiseur')`, donc fichiers
`core/template/{dashboard,mobile}/cmd.info.binary.climatiseur.html`.

### 1.2 Jetons de substitution réellement construits — `cmd::toHtml()`

`cmd.class.php:1633+`. Disponibles dans **tout** template de commande :

- toujours : `#id# #name# #name_display# #history# #hide_history# #unite# #raw_unite# #minValue#
  #maxValue# #logicalId# #uid# #version# #eqLogic_id# #generic_type# #hide_name# #value_history#
  #listValue#` ;
- type **info** : `#value# #state# #tendance# #collectDate# #valueDate# #alertLevel#` (+ jetons de
  statistiques si la commande est historisée) ;
- type **action** : `#value_id# #state# #valueName# #unite# #collectDate# #valueDate# #alertLevel#`,
  issus de la **commande info liée** ;
- **toutes** les clés de `getDisplay('parameters')` deviennent `#clé#` — c'est par là, et **jamais** par
  `configuration.step`, qu'arriverait un `#step#`.

⚠️ **Aucun jeton n'expose `configuration`**, ni une **autre** commande que celle qui se rend. C'est le
fait qui commande toute l'architecture de cette UC : une tuile qui agrège N commandes ne peut pas se
contenter des jetons natifs.

### 1.3 Le canal `$_options` — pièce maîtresse

`cmd::toHtml($_version = 'dashboard', $_options = '')` fait, **avant** tout traitement spécifique au
type :

```php
$options = jeedom::toHumanReadable($_options);
$options = is_json($options, $options);
if (is_array($options)) {
  foreach ($options as $key => $value) {
    $replace['#' . $key . '#'] = $value;
  }
}
```

Et `eqLogic::toHtml()` appelle **systématiquement** `$cmd->toHtml($_version, '')` (deux occurrences,
branches `table` et `default`) : le canal est donc **libre**, rien de ce que nous y mettons n'écrase une
valeur du core.

`jeedom::toHumanReadable()` ne réécrit que les motifs `#\d*#`, `#eqLogic\d+#`, `#scenario\d+#`,
`#object\d+#`. D'où le choix d'une charge **base64** : son alphabet (`A-Za-z0-9+/=`) est structurellement
dépourvu de `#`, `{{`, `'` et `<`.

### 1.4 Pourquoi un override de `smartclimCmd::toHtml()` est légitime

⚠️ **Ce n'est PAS un point d'extension documenté par le squelette de plugin.** Le stub
`core/class/smartclim.class.php:7239-7242` — souvent cité par erreur — est **commenté**, porte **un seul
paramètre**, et se trouve dans la classe **`smartclim` (eqLogic)**, pas dans `smartclimCmd` (qui ne
commence qu'à la ligne 7247). Le recopier donnerait la mauvaise classe et la mauvaise signature.

Ce qui rend l'override légitime est ailleurs, et vérifié en source :

- **`cmd::cast()`** (`cmd.class.php`, appelée par `byId`/`byEqLogicId`/`byEqLogicIdAndLogicalId`) :
  `if (is_object($_inputs) && class_exists($_inputs->getEqType() . 'Cmd')) { … cast($_inputs,
  $_inputs->getEqType() . 'Cmd'); }` → **toute** commande d'un équipement `smartclim` est instanciée par
  le core en `smartclimCmd`, jamais en `cmd`.
- `eqLogic::toHtml()` appelle `$cmd->toHtml($_version, '')` sur ces objets : l'appel est **polymorphe** et
  atterrit dans notre classe.

C'est donc l'**override d'une méthode publique du core**, pas un hook. Aucun `method_exists()` du core ne
va le chercher — contrairement à `formatValueWidget()`, `dontRemoveCmd()` ou `preToHtml()`, qui sont, eux,
des points d'extension au sens strict. Conséquence : cf. **R1** au § 9.

### 1.5 Exécution d'une commande côté client — `jeedom.cmd.execute`

`core/js/cmd.class.js:53` : `jeedom.cmd.execute({id, value?, notify?, success?, error?})`. Le core
s'occupe du jeton CSRF, des droits, du code PIN et du dialogue de confirmation
(`configuration.actionConfirm`).

⚠️ **Un `value` objet est `JSON.stringify`é par le core**, et `cmd.ajax.php` fait
`$options = is_json(init('value'), array())`. Pour la consigne, il faut donc envoyer :

```js
jeedom.cmd.execute({ id: <id de set_target_temp>, value: { slider: <nombre> } })
```

et **pas** un nombre nu — qui produirait `$_options = array()` côté serveur, donc l'exception « Valeur de
consigne absente ou non numérique ». Patron identique au widget core `cmd.action.slider.slider.html`.

### 1.6 Mise à jour sans rechargement — `jeedom.cmd.addUpdateFunction`

`cmd.class.js:453` : `jeedom.cmd.addUpdateFunction(<cmd_id>, fn)`.

⚠️ **Un abonnement sur une commande MASQUÉE fonctionne** : `jeedom.cmd.refreshValue()` (l. 428) n'exige
aucun élément DOM pour la commande (`?.hasClass('noRefresh')` sur un nœud absent ⇒ non filtré). C'est ce
qui permet à la tuile de suivre l'état de commandes dont l'affichage individuel a été retiré.

Charge utile d'un événement info (`cmd::event()`) : `{cmd_id, value, display_value, unit, raw_unit,
valueDate, collectDate, alertLevel}`.

### 1.7 Le bouton « Rafraîchir » est DÉJÀ pris en charge par le core

`eqLogic::preToHtml()` cherche `getCmd('action', 'refresh')`, le place en `#refresh_id#` dans l'en-tête de
l'équipement **et l'exclut de la liste des commandes du corps**.

Notre `CMD_RAFRAICHIR` porte `logicalId = 'refresh'` : c'est donc **déjà** le bouton d'en-tête. Trois
corollaires : elle ne peut pas héberger la tuile, elle ne doit **jamais** être masquée, et la tuile n'a
pas besoin de son propre bouton de rafraîchissement.

### 1.8 Fraîcheur — `collectDate` contre `valueDate`

`eqLogic::checkAndUpdateCmd()` (`eqLogic.class.php`) :

```php
$oldValue = $cmd->execCmd();
if ($oldValue !== $cmd->formatValue($_value) || $oldValue === '') { $cmd->event(...); return true; }
if ($_updateTime !== null && $_updateTime !== false) { … }
else if ($cmd->getConfiguration('repeatEventManagement', 'never') == 'always') { $cmd->event(...); return true; }
if ($_updateTime !== false) {
  $cmd->setCache('collectDate', date('Y-m-d H:i:s'));
  $this->setStatus(array('lastCommunication' => date('Y-m-d H:i:s'), 'timeout' => 0));
}
return false;
```

`appliquerEtat()` appelle `checkAndUpdateCmd($concept, $valeur)` **sans** `$_updateTime` : sur une valeur
inchangée, **`collectDate` est réécrit à chaque cycle, `valueDate` non**. Cela confirme
`.memory/analyse/jeedom-widgets-commandes.md` § 8.3.

⚠️ **C'est ce qui condamne `CMD_DERNIERE_MAJ` comme source de fraîcheur** : elle n'est alimentée que dans
`if ($change)` (`smartclim.class.php:7020-7022`), donc un climatiseur stable afficherait « il y a 6
heures » alors que la scrutation vient de confirmer l'état. Ce serait l'inverse de ce que demande l'AC6.
Cf. **D7** au § 3.

`cmd::getCollectDate()` est un **getter public** (`cmd.class.php:3068-3073`) qui déclenche un `execCmd()`
si la propriété est vide.

⚠️ **`utils::o2a()` ignore les propriétés préfixées `_`** (`utils.class.php:69`) : `_collectDate` et
`_valueDate` ne sortent donc **jamais** par `byEqLogic` côté client. C'est un argument supplémentaire pour
l'injection serveur (**D1**).

## 2. Écarts constatés avec `.memory/analyse/jeedom-widgets-commandes.md`

### 2.1 § 3 — `byEqLogic` ne rend AUCUNE valeur courante (fait établi)

`byEqLogic` renvoie `utils::o2a(cmd::byEqLogicId(...))`, et `utils::o2a` (`utils.class.php:48-82`) ne
sérialise que les **propriétés persistées** : `id, logicalId, name, type, subType, unite, configuration,
display, template, value, isVisible, isHistorized, order, generic_type, eqType, eqLogic_id, alert`. La
**valeur courante n'y est pas** — elle vit dans le cache (`cmd::execCmd()`), et `_collectDate` /
`_valueDate` sont exclus par le préfixe.

**`byEqLogic` donne la structure et les bornes, jamais l'état.** L'analyse laisse croire qu'il suffit pour
« une tuile qui lit plusieurs commandes info ensemble » : il ne suffit pas. Corroboré par le § 8.3 de la
même analyse. **À consigner dans l'analyse** — c'est un fait de lecture directe.

### 2.2 § 1 — i18n des templates de widget (HYPOTHÈSE, à confirmer en recette)

⚠️ **Ne pas corriger l'analyse sur ce point avant mesure.** Ce qui a été lu : `cmd::toHtml()` termine par
`if ($isCorewidget) return translate::exec($template, 'core/template/widgets.html');`, et
`getWidgetTemplateCode()` renvoie `'isCoreWidget' => true` **y compris pour un fichier de plugin** (seule
la branche `customtemp::` renvoie `false`). `translate::exec()` résoudrait alors le domaine par
`getPluginFromName('core/template/widgets.html')` = **`core`**, si bien que les entrées
`plugins/smartclim/core/template/...` des fichiers i18n du plugin **ne seraient jamais lues** (en fr_FR
les `{{…}}` sont simplement dépouillés ; dans les autres langues la chaîne source serait renvoyée telle
quelle).

C'est une **déduction de lecture, pas un fait mesuré**. Protocole de confirmation, à jouer en recette :
poser un `{{Test i18n}}` littéral dans un des deux templates, ajouter sa traduction dans
`core/i18n/en_US.json` sous le chemin du fichier, basculer l'interface en anglais, observer. Tant que ce
test n'est pas joué, `.memory/analyse/jeedom-widgets-commandes.md` § 1 reste marqué « à vérifier ».

Indice empirique disponible, qui ne tranche pas le mécanisme mais valide la décision : le widget déjà
livré `cmd.action.other.etat.html` ne contient **aucun** `{{…}}`, et aucune entrée `core/template/…`
n'existe dans les trois `core/i18n/*.json`.

## 3. Décisions d'architecture

- **D1 — Injection serveur, pas d'AJAX.** La tuile reçoit ids, libellés, bornes **et valeurs courantes**
  dans le HTML rendu, via un override `smartclimCmd::toHtml()` qui prépare `$_options` puis délègue à
  `parent::toHtml()`.
  Écartés : (a) `byEqLogic` seul — ne rend aucune valeur (§ 2.1), la tuile serait vide au chargement ;
  (b) `byEqLogic` + un `execCmd` par info — 6 à 8 requêtes par tuile et par affichage, et un patron
  dangereux à recopier (un `execCmd()` sur une **action** actionne le matériel, cf. § 5) ; (c) endpoint
  plugin non-admin dédié — **surface web nouvelle** pour un besoin que le rendu serveur couvre déjà, plus
  un aller-retour et un flash de tuile vide.
  **Coût assumé** : une requête SQL supplémentaire par tuile et par rendu (`getCmd(null, null)`), contre
  un aller-retour HTTP dans toutes les autres options.

- **D2 — Commande porteuse = `power` (info/binary).** C'est une commande **info**, donc jamais templatée
  jusqu'ici : l'AC9 (« si vide ») s'applique telle quelle au parc existant. Elle porte l'état central, son
  `#state#` reste exploitable en mode dégradé, et son `#collectDate#` est la source de fraîcheur (D7).
  Écartés : `refresh` (consommée par `#refresh_id#`, exclue du corps — § 1.7), `set_target_temp`
  (séduisante pour ses `#minValue#`/`#maxValue#` natifs, mais déjà porteuse de `display.parameters` et
  absente d'un profil sans consigne), `transport` (méta, sémantiquement faux).
  **Conséquence assumée et vérifiée** : `power` est créée sous condition de profil
  (`creerCommandesInfo()`, l. 5162 : `if (!$definition['meta'] && !in_array($logicalId, $concepts, true))
  continue;`), donc un profil sans `CONCEPT_POWER` n'obtient **pas** de tuile. Cas limite réel mais
  théorique : cet équipement n'aurait de toute façon ni `on` ni `off`.

- **D3 — Pose ET masquage dans `creerCommandesAction()`** (option (a), arbitrée avec l'utilisateur le
  2026-09-12), donc atteints par `postSave()` **et** par `appliquerEtat()` quand `!$_optimiste` (l. 6994),
  c'est-à-dire **à chaque cycle de lecture**.
  ⚠️ **Ne pas justifier ce choix par `masquerCommandesModes()`** : celle-ci n'a qu'un appelant,
  `appliquerCapacites()` (l. 5022), atteint **uniquement par les chemins de scan** (l. 836, 1031, 1439,
  2637) — elle ne tourne **jamais** dans le cycle de lecture. Le précédent pertinent est ailleurs :
  `creerCommandesAction()` **écrit déjà en production depuis ce chemin** — création de commandes,
  `realignerBornesConsigne()` (`$_cmd->save()`), et surtout la **repose de template « si vide »**
  (l. 5456-5467 : `setTemplate()` ×2 + `save()`). L'invariant « lecture d'état seule » de `CLAUDE.md`
  porte sur les **capacités** et sur le `save()` **d'équipement**, pas sur l'absence de tout
  `cmd::save()`.
  Ce que cette UC ajoute de nouveau est le `isVisible = 0` — une écriture qui change ce que l'utilisateur
  voit, déclenchée par une tâche de fond. Elle est **bornée par construction à un seul passage par
  équipement** (elle vit dans la branche « le template vient d'être posé », et un template ne reste vide
  qu'une fois) et n'ajoute **aucun** `save()` d'équipement.
  **Écarté d'emblée** : mémoriser « déjà masqué » en configuration d'**équipement** — le
  `setConfiguration()` + `save()` déclencherait `postSave()` → `creerCommandesAction()` →
  `poserWidgetTuile()` → **récursion**, en plus de violer l'invariant. **Le marqueur d'unicité est le
  template lui-même.**

  > ⚠️ **CORRIGÉ EN RECETTE LE 2026-09-12 — ce raisonnement était juste sur la récursion et faux sur la
  > conclusion.** Le marqueur est désormais `smartclim::CLE_MASQUAGE_TUILE`, posé sur la configuration de
  > la **commande** `power` : `cmd::save()` n'appelle pas `eqLogic::postSave()`, il n'y a donc aucune
  > récursion — l'emplacement « configuration d'équipement » n'était pas le *seul* disponible, il était
  > seulement le seul examiné. Deux faits de recette l'ont imposé : (1) la garde `getTemplate() === ''`
  > n'a **jamais** posé la tuile en automatique (le core enregistre une sentinelle `default` sur une
  > commande déjà sauvegardée — cf. `smartclim::templateLibre()`), si bien que l'utilisateur devait
  > sélectionner le widget à la main sur chaque équipement ; (2) dans ce cas le template est **déjà** posé
  > à l'entrée de `poserWidgetTuile()`, donc il ne peut plus signifier « masquage pas encore joué », et
  > l'utilisateur se retrouvait avec la tuile **et** les commandes qu'elle reprend.
  **Écarté aussi** : tout mettre dans `postSave()` seul — cela ressusciterait la panne silencieuse que
  `CLAUDE.md` documente deux fois (UC05 et UC06 : un scan qui ne change rien n'émet aucun `save()`, donc
  aucun `postSave()`, donc rien n'apparaîtrait sur un parc stable).

  ⚠️ **Bord assumé, à écrire dans le code comme UC06 a écrit le sien** : le masquage hérite du bord de la
  branche « si vide ». Un utilisateur qui remet volontairement le widget par défaut du core sur `power`
  fait re-poser la tuile au cycle suivant — et donc **re-masquer** les commandes qu'il aurait réaffichées.
  C'est le prix de la couverture automatique du parc, accepté en connaissance de cause.

- **D4 — Zéro `{{…}}` dans les deux templates.** Toutes les chaînes visibles sont injectées par PHP, déjà
  traduites par `__()` depuis un `.class.php` — domaine i18n dont le fonctionnement est prouvé par tout le
  reste du plugin. Décision **maintenue indépendamment** de l'hypothèse du § 2.2 : elle ne coûte rien et
  suit le précédent du widget `etat`. Corollaire : aucune clé i18n indexée sous
  `plugins/smartclim/core/template/…` (ce que `verif-plugin.py::_cles_source()` exigerait sinon, l.
  371-391, pour des entrées que le core n'ouvrirait peut-être jamais).

- **D5 — Icône FontAwesome + libellé texte, jamais icône seule.** Classes `fas fa-…` déjà servies par
  Jeedom (déjà employées par `desktop/php/smartclim.php`). Si la fonte n'est pas chargée (interface
  mobile, cf. R6), la tuile reste **entièrement utilisable**. Table d'icônes dans `smartclimWidget`,
  **pas** dans `smartclimCapabilities` (aucune notion de présentation dans la table de capacités) ; code
  inconnu ⇒ pas d'icône, **jamais d'erreur**.

- **D6 — Aucun helper à espace de noms divergent.** `domUtils.issetWidgetOptParam()` (dashboard) et
  `$.issetWidgetOptParam()` (mobile) sont **deux API différentes** dans les widgets core équivalents : la
  tuile n'en utilise **aucune**, bornes et pas venant de la charge injectée. C'est ce qui permet aux deux
  fichiers d'être identiques (AC8).

- **D7 — La fraîcheur affichée est le `collectDate` de `power`**, pas le `valueDate` de
  `CMD_DERNIERE_MAJ` (cf. § 1.8). Disponible **nativement** dans le template comme jeton `#collectDate#`
  (branche `info` de `cmd::toHtml()` : `$replace['#collectDate#'] = $this->getCollectDate();`) : zéro
  plomberie, zéro clé de charge utile.
  Pourquoi `power` et non `online` ou `transport`, qui bougent eux aussi à chaque cycle :
  `basculerHorsLigne()` (l. 3898-3916) ne pousse **que** `online => false`, donc sur un appareil
  injoignable le `collectDate` de `power` **ne bouge pas** alors que celui d'`online` si ;
  `CMD_TRANSPORT` est réécrite **inconditionnellement** à chaque `appliquerEtat()` (l. 7018). Ces deux-là
  répondent « quand le dernier cycle a-t-il tourné », tandis que `power` répond « de quand date l'état que
  je regarde » — c'est la question de l'AC6.
  **Sort de `CMD_DERNIERE_MAJ`** : conservée, affichée **en second rang** (seconde ligne ou infobulle),
  et **présentée pour ce qu'elle est** — un « dernier changement d'état », pas une fraîcheur. Aucune
  commande ajoutée, aucun `logicalId` touché.

- **D8 — Signature stricte à 2 paramètres** pour l'override (arbitrée avec l'utilisateur le 2026-09-12) :
  `($_version = 'dashboard', $_options = '')`, identique au core V4-stable. La variante variadique
  (`...$_reste`) a été écartée : la forme stricte est la seule où R1 se vérifie d'un coup d'œil par
  comparaison avec le core.

- **D9 — `ambient_temp` et `target_temp` restent VISIBLES** (arbitré avec l'utilisateur le 2026-09-12) :
  ces deux commandes sont `isHistorized = 1`, et les masquer ferait disparaître l'accès en un clic à leurs
  graphiques (cf. R2). La tuile ne peut pas le restituer proprement — le clic `.history` se résout sur le
  `data-cmd_id` du conteneur, donc sur l'hôte `power`. Deux lignes subsistent donc sous la tuile ;
  l'AC1 reste tenu, la tuile regroupant bien l'état, la consigne, le mode et la vitesse.

- **D10 — Hors ligne : bandeau seul, contrôles actifs** (arbitré avec l'utilisateur le 2026-09-12). Un
  `online` périmé ne doit pas rendre la tuile inutilisable ; si l'ordre échoue réellement, le message déjà
  curaté en français par `messageErreurAuxHome()` / `messageErreurLan()` s'affiche via la notification du
  core.

## 4. Architecture — fichiers

| Chemin | État | Contenu | Convention |
|---|---|---|---|
| `core/template/dashboard/cmd.info.binary.climatiseur.html` | **créé** | La tuile : balisage + un seul `<script>` inline. Racine `<div class="cmd cmd-widget" data-type="info" data-subtype="binary" data-template="climatiseur" data-cmd_id="#id#" data-cmd_uid="#uid#" data-version="#version#" data-eqLogic_id="#eqLogic_id#">`, scoping par `#uid#` | **CRLF**, 2 espaces (aligné sur `cmd.action.other.etat.html`, vérifié : 66 CR / 66 LF) |
| `core/template/mobile/cmd.info.binary.climatiseur.html` | **créé** | **Copie octet à octet** du précédent (comme la paire `etat` existante, vérifiée identique). Adaptation d'écran par CSS (`flex-wrap`), **jamais** par branche JS | idem |
| `core/class/smartclimWidget.class.php` | **créé** | Assemblage de la charge de la tuile + table d'icônes. **Aucune E/S, aucun `config::`, aucun `cache::`, aucun réseau, aucun `save()`** — même statut que `smartclimDiagnostic` (mise en forme pure) | CRLF, 2 espaces |
| `core/php/smartclim.inc.php` | **modifié** | Ligne à ajouter **en fin de liste** : `require_once __DIR__ . '/../class/smartclimWidget.class.php';` — la convention de facto du fichier est l'ordre chronologique d'ajout, et aucune des 8 classes n'a de dépendance de chargement sur une autre. ⚠️ **Oubli = « Class not found » au runtime**, invisible à `php -l` et à la CI | CRLF, 2 espaces |
| `core/class/smartclim.class.php` | **modifié** | Constantes `WIDGET_TUILE` / `JETON_TUILE` ; `poserWidgetTuile()` ; `masquerCommandesTuile()` ; `libelleEnLigne()` passe `private static` → `public static` ; 2 lignes dans `creerCommandesAction()` ; override `smartclimCmd::toHtml()` | CRLF, 2 espaces |
| `core/ajax/smartclim.ajax.php` | **intact** | **Sans objet** : aucun endpoint ajouté, ni admin ni utilisateur. Aucun nouveau fichier `*.ajax.php` | — |
| `plugin_info/packages.json`, `info.json`, `core/config/smartclim.config.ini`, `plugin_info/configuration.txt`/`.php` | **intacts** | Aucune dépendance, aucune clé de config, aucun défaut INI, aucun champ de formulaire | — |
| `core/i18n/*.json` | **intacts à ce stade** | Étape `translator` en fin de cycle, sur les clés de `smartclimWidget.class.php` | — |

## 5. Server vs Client

**Serveur** — tout ce qui suppose un accès au modèle :

- résolution des commandes sœurs (`getCmd(null, null)`, une seule lecture) ;
- lecture des **valeurs courantes** des commandes **info** (§ 2.1 : impossible côté client) ;
- lecture du `collectDate` de `power` (§ 1.8 : jamais exposé par `byEqLogic`) ;
- bornes de consigne (`bornesTemperature()`, l. 4603-4625) ;
- **traduction** de toutes les chaînes (D4) ;
- validation finale et **quantification** de la consigne (`ordreEffectifConsigne()`, l. 6833-6844) ;
- liste blanche des `logicalId` exécutables (`definitionsCommandesAction()`).

**Client** — uniquement de la présentation et du transport :

- décodage de la charge, construction des contrôles par API DOM ;
- bornage + quantification **d'affichage** de la consigne (première barrière, la seconde est serveur) ;
- appels `jeedom.cmd.execute` et abonnements `jeedom.cmd.addUpdateFunction` ;
- rendu relatif de la fraîcheur (`jeedom.cmd.displayDuration()`, `cmd.class.js:1219`), avec repli en
  horodatage absolu.

⚠️ **Garde fonctionnelle non négociable** : `smartclimWidget::valeurInfo()` teste `getType() === 'info'`
**avant tout `execCmd()`**. Un `execCmd()` sur une commande **action** actionnerait le matériel — donc
allumerait le climatiseur au simple affichage du dashboard (cf.
`.memory/analyse/jeedom-widgets-commandes.md` § 8.7).

## 6. Signatures

### `smartclimWidget` (nouvelle classe)

```php
/** Charge utile de la tuile : ids, libellés DÉJÀ traduits, valeurs courantes, bornes.
 *  Lit UNIQUEMENT des commandes de type 'info' avant tout execCmd(). */
public static function chargeTuile($_eqLogic)                 // smartclim -> array
/** json_encode(array(smartclim::JETON_TUILE => base64_encode(json_encode(chargeTuile)))),
 *  prêt pour parent::toHtml(). '' si l'équipement n'est pas exploitable. */
public static function optionsTuile($_eqLogic)                // smartclim -> string
/** Classe FontAwesome d'un MODE générique ; '' si inconnu (jamais d'erreur). */
private static function iconeMode($_codeGenerique)            // string -> string
/** Valeur courante d'une commande info ; '' si $_cmd n'est pas une info. GARDE FONCTIONNELLE. */
private static function valeurInfo($_cmd)                     // cmd -> string
```

Forme de `chargeTuile()` — clés stables, consommées **à l'identique** par les deux templates :

```
version, eqLogic
infos    : power|mode|fan_speed|ambient_temp|target_temp|online|transport|last_update
           -> {id, valeur, unite, nom, date}            (date = valueDate 'Y-m-d H:i:s')
actions  : on, off, consigne                            -> {id, nom}
           modes    -> [{id, code, libelle, icone, nom}]  (dérivé de definitionsCommandesAction)
           vitesses -> [{id, code, libelle, nom}]
bornes   : {min, max, pas}                              (smartclim::bornesTemperature())
libelles : {hors_ligne, moins, plus}                    (le reste vient de 'nom')
```

### `smartclim`

```php
const WIDGET_TUILE = 'smartclim::climatiseur';
const JETON_TUILE  = 'tuile_smartclim';                 // -> jeton #tuile_smartclim#
/** Pose le template de tuile sur la commande info 'power', SI ET SEULEMENT SI aucun
 *  template n'est posé (AC9), puis masque les commandes reprises par la tuile (D3).
 *  try/catch par commande, ne lève JAMAIS. */
private function poserWidgetTuile(array $_existantes)         // -> bool (posé à ce passage)
/** isVisible = 0 sur les commandes reprises par la tuile. JAMAIS 'refresh' (#refresh_id#),
 *  JAMAIS 'power' (l'hôte), JAMAIS ambient_temp/target_temp (D9), jamais les concepts
 *  confort/oscillation/protection (hors périmètre). */
private function masquerCommandesTuile(array $_existantes)    // -> int
public static function libelleEnLigne($_enLigne)              // visibilité élargie (était private)
```

**Périmètre exact du masquage** (arbitré avec l'utilisateur le 2026-09-12) :

| Masqué | Laissé visible |
|---|---|
| infos `online`, `mode`, `fan_speed`, `transport`, `last_update` | `power` (hôte de la tuile) |
| actions `on`, `off`, `set_target_temp`, `mode_*`, `fan_*` | `refresh` (bouton d'en-tête du core, § 1.7) |
| | `ambient_temp`, `target_temp` (**D9**, graphiques) |
| | toutes les capacités secondaires (confort, oscillation, protection) — **hors périmètre** |

### Modifications chirurgicales de `creerCommandesAction()` (l. 5437-5522)

Deux lignes, **aucune requête supplémentaire** :

1. après `$cmd->save(); $crees++;` → `$existantes[$logicalId] = $cmd;` — sans quoi les actions créées
   **dans la même passe** échapperaient au masquage ;
2. avant `return $crees;` → `$this->poserWidgetTuile($existantes);`.

Pourquoi là : `creerCommandesAction()` est **déjà** appelée par `postSave()` (l. 4457) **et** par
`appliquerEtat()` (l. 6994), et son index `getCmd(null, null)` est reconstruit **à chaque appel**, après
`creerCommandesInfo()` — il contient donc déjà la commande hôte `power` fraîchement créée. Ce mécanisme
est éprouvé : UC06 en dépend déjà pour lier `on`/`off` à l'info `power` (`infoLiee`, l. 5483).

### `smartclimCmd`

```php
/** Signature IDENTIQUE au core V4-stable (cf. R1 — recopie volontaire, à re-vérifier à chaque
 *  montée de 'compatibility' dans info.json). Sur la commande porteuse de WIDGET_TUILE, injecte
 *  la charge dans $_options puis délègue ; sinon délègue tel quel.
 *  try/catch (Throwable) : toute erreur -> parent::toHtml($_version, '') + log error. */
public function toHtml($_version = 'dashboard', $_options = '')
```

Gardes, dans cet ordre :

```php
$version = jeedom::versionAlias($_version);
if ($_options === ''                                              // ne jamais écraser les options d'un autre appelant
    && $this->getType() === 'info'
    && $this->getTemplate($version, '') === smartclim::WIDGET_TUILE
    && ($eqLogic = $this->getEqLogic()) instanceof smartclim) { … }
return parent::toHtml($_version, $_options);
```

⚠️ **`jeedom::versionAlias()` est nécessaire, et sans danger** (source relue,
`core/class/jeedom.class.php`) : elle rend `'mobile'` **à l'identique** (donc la garde n'est jamais fausse
sur mobile — AC8 n'est pas menacé), et elle ramène `mview` → `mobile`, `dview`/`dplan`/`plan`/`view` →
`dashboard`. Sans elle, un `getTemplate('plan', '')` renverrait `''` sur les vues `plan`/`view` (nous
n'écrivons que les clés `dashboard` et `mobile`) et la tuile s'y afficherait en mode dégradé.
`cmd::toHtml()` fait exactement le même appel en première ligne.
**On aliase pour le TEST, on transmet `$_version` non modifié au parent** — qui ré-aliase lui-même ;
l'aliasing est idempotent, mais ne pas pré-transformer un argument que le core transforme déjà est la
règle sûre.

### Côté template

Un seul `<script>` inline, scopé par `#uid#` :

- décodage `JSON.parse(atob('#tuile_smartclim#'))`, protégé par un test « la chaîne ne commence pas par
  `#` » (jeton non substitué) **et** un `try/catch` ;
- construction des contrôles par API DOM ;
- ⚠️ **`textContent` UNIQUEMENT** pour toute valeur issue de la charge : les `nom` de commandes sont
  modifiables par l'utilisateur et `cleanComponanteName()` du core **ne filtre ni `<` ni `>`** (cf.
  `CLAUDE.md`, § XSS stocké corrigé à l'UC04 du domaine 01) ;
- un `jeedom.cmd.addUpdateFunction(<id>, fn)` par commande info de la charge ;
- **aucun amorçage** : les valeurs sont déjà dans la charge (le `refreshValue` d'amorçage du widget `etat`
  existait faute de charge serveur).

## 7. Validation & erreurs

| Point | Où | Comportement |
|---|---|---|
| Lecture de valeurs | `smartclimWidget::valeurInfo()` | **`getType() === 'info'` avant tout `execCmd()`** — garde fonctionnelle (§ 5) |
| Échec d'assemblage de la charge | `smartclimCmd::toHtml()` | `try/catch (Throwable)` → `parent::toHtml($_version, '')` + `log::add('smartclim', 'error', …)`. **Le dashboard ne casse jamais** |
| Jeton non substitué / charge illisible | template | Tuile **dégradée** : état marche/arrêt depuis `#state#` + nom, aucun contrôle ; jamais d'exception JS, jamais de cadre vide |
| Consigne hors bornes | client **puis** serveur | Client : `−`/`+` désactivés aux bornes, valeur **quantifiée** sur le pas avant envoi. Serveur : `ordreEffectifConsigne()` borne et quantifie, littéral d'erreur **existant** — double barrière |
| Commande absente du profil | — | Le contrôle n'existe pas dans la tuile ; un `execCmd()` forgé reste refusé par la liste blanche de `definitionsCommandesAction()` |
| Erreur d'exécution | `jeedom.cmd.execute` | `notify` laissé à **true** : le message déjà curaté en français s'affiche. Contrôle désactivé pendant le vol, réactivé en `success`/`error` |
| Droits | core | Rendu : `preToHtml()` → `hasRight('r')`. Exécution : `cmd.ajax.php` → `isConnect()` + `hasRight('x')`. **Aucun contrôle d'accès écrit par le plugin** |
| Masquage | `masquerCommandesTuile()` | `try/catch` **par commande** + `log info` ; n'intervient **que** si `poserWidgetTuile()` a effectivement posé le template à ce passage |

## 8. Impact i18n (FR uniquement — étape `translator` en fin de cycle)

Nouvelles chaînes, toutes **littérales** dans `core/class/smartclimWidget.class.php`
(`__('…', __FILE__)`) :

- `'Diminuer la consigne'`
- `'Augmenter la consigne'`

Réutilisées **sans nouvelle clé** : `smartclimCapabilities::libelle()` (« Automatique »,
« Refroidissement », « Déshumidification », « Chauffage », « Ventilation », « Silencieux », « Faible »,
« Moyen », « Fort », « Turbo »…), `smartclim::libelleEnLigne()` (« En ligne » / « Hors ligne », d'où
l'élargissement de visibilité plutôt qu'une redéclaration), et les **noms des commandes** eux-mêmes
(« Consigne », « Température ambiante », « Mode », « Vitesse de ventilation », « Transport actif »,
« Dernière mise à jour », « Marche », « Arrêt »), transportés par la charge.

**Aucune clé dans les deux `.html`** (D4).

## 9. Risques

- **R1 — Override d'une méthode publique du core, sans contrat de stabilité.** `smartclimCmd::toHtml()`
  doit déclarer **exactement** `($_version = 'dashboard', $_options = '')`. Un core futur qui ajouterait
  un paramètre rendrait la déclaration incompatible → **erreur fatale au chargement de la classe**, donc
  `smartclimCmd` introuvable, donc **tout le plugin hors service** (même famille de panne que l'oubli
  d'autoload : invisible à `php -l` et à la CI, fatale au runtime). Trois exigences : (1) commentaire
  d'en-tête rappelant que la signature recopie celle du core et citant la version de référence
  (V4-stable, relue le 2026-09-12) ; (2) re-vérification à chaque montée de `compatibility` dans
  `info.json` ; (3) forme stricte retenue (**D8**), la variante variadique ayant été écartée.
- **R2 — Accès aux graphiques** : traité par **D9** (`ambient_temp` / `target_temp` laissées visibles).
- **R3 — La fraîcheur est exacte au rendu, puis dérive pendant la session.** Quand rien ne change,
  `checkAndUpdateCmd()` **n'émet aucun événement** (`return false` sans `cmd::event()`), et
  `eqLogic::setStatus()` n'appelle `refreshWidget()` que pour les clés d'alerte du core — aucun
  `cmd::update` ni `eqLogic::update` ne parvient donc au navigateur. Un onglet resté ouvert verra l'âge
  croître même si les cycles s'enchaînent normalement ; il est ré-ancré à chaque rechargement de page
  **et** à chaque changement réel (événement reçu ⇒ âge remis à zéro). **Aucune scrutation client n'est
  ajoutée** pour compenser : ce serait une requête récurrente par tuile. Seul contournement existant,
  côté utilisateur et non côté plugin : `repeatEventManagement = 'always'` sur `power`, réglable commande
  par commande — à documenter, pas à forcer.
- **R4 — `jeedom.cmd.displayDuration` sur l'interface mobile** : présente dans `core/js/cmd.class.js` et
  tolérante aux éléments jQuery, mais **non vérifiée** sur l'application mobile réelle. Garde
  `typeof jeedom.cmd.displayDuration === 'function' && typeof moment !== 'undefined'`, repli =
  horodatage absolu. À confirmer en recette (AC8).
- **R5 — Largeur par défaut 230 px** (`eqLogic::preToHtml()`, `#width#` = `auto` → `230px`) : la tuile
  doit rester lisible à cette largeur **sans réglage**, sinon l'AC1 sera jugé non tenu alors que le code
  est correct. Conception en colonne, `flex-wrap`, **aucune largeur fixe**.
- **R6 — FontAwesome sur l'interface mobile** non vérifié : mitigé par **D5** (icône + texte).
- **R7 — Coût SQL** : un `getCmd(null, null)` supplémentaire par tuile et par rendu de dashboard.
  Négligeable devant un aller-retour HTTP, mais réel sur un dashboard à N climatiseurs — **à surveiller à
  l'UC03 de ce domaine**, qui rendra la tuile sur une page-panneau listant tout le parc.
- **R8 — Aucun scan manuel n'est nécessaire.** `creerCommandesAction()` — donc la pose de la tuile — est
  appelée par `appliquerEtat()` à **chaque cycle de lecture** (l. 6994), pas seulement au scan : le parc
  existant obtient la tuile au prochain **cycle de rafraîchissement** (≤ `refresh_interval`, 5 min par
  défaut), sans geste de l'utilisateur. Conséquence pour la recette : ne pas conclure à un échec avant
  d'avoir laissé passer un cycle, et **ne pas confondre « scan »** (découverte, qui écrit les capacités)
  **et « cycle de lecture »** (état seul) — deux notions distinctes dans le vocabulaire du projet.
- **R9 — Deux fichiers à garder identiques** : toute correction faite dans un seul des deux `.html`
  produit une divergence dashboard/mobile **invisible en CI**. Contrôle à ajouter à la recette : `diff`
  des deux fichiers (aujourd'hui identiques pour la paire `etat`).

## 10. Critères d'acceptation — couverture

| AC | Mécanisme | Validable |
|---|---|---|
| **AC1** tuile unique | Template posé sur `power` + `masquerCommandesTuile()` (one-shot, lié à la pose). D9 laisse 2 lignes d'historique sous la tuile | recette |
| **AC2** clic → commande + état sans rechargement | `jeedom.cmd.execute({id})` ; retour d'état par l'**état optimiste** déjà poussé par `executerCommandeAction()` (l. 5816) → `cmd::event()` → `jeedom.cmd.addUpdateFunction`. ⚠️ L'état optimiste ne contient que les clés de l'ordre réellement envoyé (`power` + concept visé) : AC2 est tenu **pour les commandes affectées par le clic**, ce qui est exactement ce que l'AC demande | recette |
| **AC3** modes | La tuile ne rend **que** les `mode_*` réellement créées, listées dans la charge ; `definitionsCommandesAction()` ne crée déjà que celles du profil et filtre `versTransport() === null` (l. 5246-5264) | code + recette |
| **AC4** vitesses | Idem `fan_*` (l. 5266-5286) | code + recette |
| **AC5** bornes de consigne | `bornesTemperature()` injectée ; bornage + **quantification** client, puis re-bornage serveur par `ordreEffectifConsigne()` — double barrière | code + recette |
| **AC6** transport + fraîcheur | `transport` injecté ; fraîcheur = `#collectDate#` de `power` (**D7**), rendue en relatif par `jeedom.cmd.displayDuration()`, repli absolu. `last_update` en second rang | recette |
| **AC7** capacité absente | Aucun contrôle écrit « en dur » : chaque contrôle n'existe que si sa commande est dans la charge | code |
| **AC8** mobile = desktop | **Deux fichiers strictement identiques**, aucune API divergente (**D6**) | recette |
| **AC9** choix utilisateur préservé | Pose **« si vide »** déjà en place (l. 5456-5467), rejouée à l'identique ; l'hôte est une commande **info**, jamais templatée → le parc existant l'obtient au premier cycle. Masquage **conditionné à la pose effective**. ⚠️ Bord assumé de D3 à documenter | code + recette |
| **AC10** non-administrateur | **Aucun endpoint plugin.** Rendu : `preToHtml()` → `hasRight('r')`. Exécution : `cmd.ajax.php` → `isConnect()` + `hasRight('x')` | recette |
| **AC11** aucune ressource externe | Icônes FontAwesome déjà servies par Jeedom, **doublées d'un libellé texte** ; aucune `<img>`, aucune police, aucun `fetch`. Script **inline**, comme les widgets du core | recette (console) |

## 11. Dette

> Reviews croisées du 2026-09-12 : **un seul tour a suffi**. Sécurité — aucun finding, toutes catégories
> (le trajet de la donnée a été suivi de bout en bout : le double encodage JSON → base64 et un rendu
> client exclusivement en `.text()` / `.attr()` neutralisent le XSS **par construction**, la garde
> `execCmd()` est même doublée par le filtre de la boucle appelante). Qualité — **aucun `blocker`, aucun
> `major`** ; les trois `minor` sont traités ou consignés ci-dessous.

- **Hypothèse i18n des templates non mesurée** (§ 2.2) : `.memory/analyse/jeedom-widgets-commandes.md`
  § 1 reste marqué « à vérifier » jusqu'au test empirique décrit. La décision D4 n'en dépend pas.
- **Bord de re-masquage** (D3) : un retour volontaire au widget par défaut du core sur `power` fait
  re-poser la tuile au cycle suivant. Accepté avec l'utilisateur le 2026-09-12. ⚠️ **Le re-masquage,
  lui, n'a plus lieu** depuis le correctif du même jour (cf. § D3) : `CLE_MASQUAGE_TUILE` sur la commande
  `power` borne le masquage à un seul passage pour la vie de l'équipement, quel que soit le nombre de
  poses de template. Les commandes réaffichées à la main le restent.
- **`version` et `eqLogic.id` de la charge ne sont lus par aucun des deux templates** (`minor` de review,
  **documenté et conservé**) : ils anticipent l'UC03 de ce domaine (page-panneau multi-climatiseurs), qui
  aura besoin de distinguer les tuiles entre elles. Le commentaire de `chargeTuile()` le dit
  explicitement, pour couper court à une review future. À **retirer** si l'UC03 ne s'en sert finalement
  pas.
- **Déclarations de fonction dans un bloc `if`** (`majModeActif()`, `majVitesseActive()` dans les deux
  templates, `minor` de review) : sémantique Annex B, fonctionnellement correcte sur tous les navigateurs
  ciblés et invoquée immédiatement après. **Volontairement non corrigé** : le gain est cosmétique, alors
  que toucher au JS impose de re-synchroniser deux fichiers qui doivent rester identiques **octet à
  octet** (R9) — le risque de divergence dépasse le bénéfice. À reprendre si ces fonctions sont un jour
  déplacées hors du bloc pour une autre raison.
