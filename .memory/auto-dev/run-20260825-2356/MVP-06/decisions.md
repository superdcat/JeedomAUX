# Décisions automatiques — UC06 du MVP (commandes action : pilotage)

> Run `run-20260825-2356` · UC `MVP/06` · spec fonctionnelle
> `.memory/specs/MVP/06-commandes-action-pilotage.md` · spec technique
> `.memory/specs/MVP/06-commandes-action-pilotage-tech.md`
>
> Chaque entrée ci-dessous est un point où `/feature` aurait demandé un arbitrage humain et où
> `/auto-dev` a tranché seul selon `.claude/templates/principes-arbitrage.md`.

### D-MVP06-01 — AC9 fermé par un widget de plugin, pas reporté en post-MVP

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 2 de `/feature` (question ouverte remontée par le planner), puis étape 4 (validation du plan)
- **Principes** : P1, P6

**Question**
Le critère AC9 d'UC06 exige que chaque commande action de marche, de mode et de vitesse soit
« visuellement associée dans Jeedom à sa commande info correspondante », avec la précision explicite
« l'affichage du bouton reflète l'état courant, pas seulement un déclenchement à sens unique ». Poser le
lien de modèle `setValue()` couvre l'association, mais le widget que Jeedom applique par défaut aux
commandes de type `action`/sous-type `other` (fichier du core
`core/template/dashboard/cmd.action.other.default.html`) est un bouton **sans état** : il n'exploite pas
le jeton `#state#` et ne s'abonne à aucun événement. Le planner recommandait donc de se limiter au lien de
modèle et de renvoyer le retour visuel au domaine post-MVP `06-ergonomie-jeedom` (tuile climatiseur
agrégée). Fallait-il accepter un AC9 partiel, ou écrire un widget de plugin dans UC06 ?

**Décision**
Le widget est écrit dans UC06. Deux fichiers **synchronisés** sont créés,
`core/template/dashboard/cmd.action.other.etat.html` et
`core/template/mobile/cmd.action.other.etat.html`, posés par
`setTemplate('dashboard'|'mobile', 'smartclim::etat')` sur les **12** commandes `action/other` (`on`,
`off`, les 5 `mode_*`, les 5 `fan_*`). La 13ᵉ commande action, `set_target_temp`, est un `action/slider`
et le widget du core `cmd.action.slider.slider.html` positionne déjà nativement le curseur sur `#state#`.

Le widget **ne porte aucune clé de configuration nouvelle** : il déduit la valeur générique qu'il
représente de son propre jeton `#logicalId#` (`mode_cool` donne `COOL`, `fan_turbo` donne `TURBO`, `on`
donne `1`, `off` donne `0`) et la compare à la valeur de la commande info liée. La pose est **idempotente
et non intrusive** : `if ($cmd->getTemplate($version, '') === '') $cmd->setTemplate(...)`, donc un widget
choisi à la main par l'utilisateur n'est jamais écrasé.

Deux fichiers **vides** hérités du squelette du plugin-template,
`core/template/dashboard/cmd.action.other.templeteTemplate.html` et son homologue `mobile` (nom porteur
d'une coquille du template d'origine, jamais référencés par une ligne de code), sont **supprimés** au
passage.

**Pourquoi**
P1 prime sur le périmètre minimal : la parenthèse « l'affichage du bouton reflète l'état courant » est une
exigence écrite du critère, pas un confort. On sait la couvrir, donc on la couvre — un critère qu'on
laisse partiel doit être un critère qu'on ne **sait pas** couvrir. La tuile agrégée du domaine post-MVP 06
est un objet différent et plus vaste ; s'en servir pour ne pas écrire un widget aurait reporté un critère
du MVP sur un domaine facultatif. P6 : le mécanisme n'ajoute ni dépendance, ni appel réseau, ni clé de
configuration — il est purement local et se dégrade en bouton ordinaire s'il est retiré.

**Alternatives écartées**
1. *Se limiter au lien `setValue()` et reporter le retour visuel au domaine post-MVP 06* (recommandation
   du planner) — écartée parce qu'elle laisse un critère d'acceptation du MVP explicitement non tenu ;
   redeviendrait le meilleur choix si la tuile climatiseur de `post-mvp/06-ergonomie-jeedom` était livrée
   **avant** la recette d'UC06, rendant le widget unitaire jetable.
2. *Utiliser le widget du core `binaryDefault` sur `on`/`off` et ne rien poser sur les mode/vitesse* —
   écartée pour deux raisons : elle ne couvre que 2 commandes sur 12, et elle mêlerait deux mécanismes
   d'affichage. Détail vérifié dans la source du core : `jeedom.cmd.normalizeName()` reconnaît bien les
   libellés français « marche » et « arrêt », mais **retourne le nom inchangé** pour tout libellé hors de
   sa liste (« Turbo », « Refroidissement ») — le widget tomberait alors systématiquement dans sa branche
   « état différent » et afficherait une icône trompeuse. Redeviendrait acceptable si UC06 ne créait que
   les commandes de marche et d'arrêt.
3. *Porter la valeur attendue dans une clé `configuration` de chaque commande action* (proposition du
   planner) — écartée parce qu'elle est **inopérante** : vérifié dans `cmd::toHtml()` du core, aucun jeton
   n'expose la `configuration` d'une commande (seuls `minValue`, `maxValue` et `listValue` en sont
   extraits nommément). Il aurait fallu passer par `display.parameters`, plus lourd, alors que
   `#logicalId#` suffit.

**Portée dans le code**
- `core/template/dashboard/cmd.action.other.etat.html` → créé (widget complet)
- `core/template/mobile/cmd.action.other.etat.html` → créé (copie synchronisée)
- `core/template/dashboard/cmd.action.other.templeteTemplate.html` → supprimé
- `core/template/mobile/cmd.action.other.templeteTemplate.html` → supprimé
- `core/class/smartclim.class.php` → `creerCommandesAction()` : pose conditionnelle du template
- `core/class/smartclim.class.php` → `definitionsCommandesAction()` : `logicalId` dérivés mécaniquement
  des valeurs génériques — c'est **cette** convention de nommage que le widget exploite comme donnée

**Coût d'un revirement**
- Fichiers à modifier : supprimer les 2 fichiers de `core/template/`, retirer la pose de template dans
  `creerCommandesAction()`
- Specs à corriger : `.memory/specs/MVP/06-commandes-action-pilotage-tech.md` § 8 en entier, et la ligne
  AC9 du tableau § 2
- Migration de l'existant : **oui** — le nom du template reste inscrit sur les commandes déjà créées chez
  l'utilisateur. Il faut remettre le champ `template` à chaîne vide sur les commandes action des
  équipements existants (`$cmd->setTemplate('dashboard', ''); $cmd->setTemplate('mobile', '');
  $cmd->save();`), sinon Jeedom cherche un fichier de widget absent
- i18n : les 2 entrées de chemin
  `plugins/smartclim/core/template/{dashboard,mobile}/cmd.action.other.etat.html` dans les 3 fichiers de
  langue, si le widget introduit une chaîne UI
- Réversibilité : **moyenne** — le code se retire en quelques lignes, mais la migration du champ
  `template` des commandes déjà posées est indispensable

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/06-commandes-action-pilotage.md` § critère AC9
- Spec technique : `.memory/specs/MVP/06-commandes-action-pilotage-tech.md` § 8
- Analyse : `.memory/analyse/jeedom-widgets-commandes.md` §§ 1, 2, 6

### D-MVP06-02 — Échelle de température d'écriture : entier en degrés Celsius, pas de valeur multipliée par dix

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 2 de `/feature` (contrat externe contradictoire, marqué « À confirmer » par la spec)
- **Principes** : P3, P7

**Question**
La spec fonctionnelle d'UC06 signale que deux sources publiques se contredisent sur le champ de
température d'une commande AUX Home : un **entier en degrés Celsius** (`"temperature": 22`) pour l'une,
une **valeur multipliée par dix** (`220`) pour l'autre. Le plugin n'a aucun moyen de trancher sans
matériel : il faut choisir une valeur, coder avec, et la valider en recette. Se tromper envoie toute
consigne à un dixième de sa valeur — 2 °C au lieu de 22.

**Décision**
Facteur d'échelle **1** (entier en degrés Celsius) et **pas d'écriture de 1,0 °C**. Les deux valeurs sont
des constantes de `core/class/smartclimCapabilities.class.php` : `FACTEUR_TEMP_AUX_HOME = 1` et
`PAS_ECRITURE_AUX_HOME = 1.0`, lues par un unique accesseur
`smartclimCapabilities::echelleTemperature('AUX_HOME')`. Aucun autre endroit du plugin ne connaît
l'échelle.

⚠️ Corollaire explicitement acté : le **pas de lecture** reste 0,5 °C (le fil rapporte réellement des
demi-degrés, UC05 AC4). Pas de lecture et pas d'écriture sont deux notions distinctes et ne doivent jamais
être confondues dans le code.

**Pourquoi**
P3 : la source qui donne l'entier en degrés Celsius décrit le backend **européen**
`eu-smthome-api.aux-global.com`, c'est-à-dire exactement celui que le plugin interroge — déjà vérifié en
direct sur `getPubkey` au cycle UC02. La piste « multipliée par dix » vient du backend cousin chinois,
dont `.memory/analyse/smartclim-transport-aux-home.md` rappelle qu'il ne partage pas toutes les routes.
P7 : le choix est concentré dans **deux littéraux**, donc un revirement après recette est trivial.

**Alternatives écartées**
1. *Facteur 10 et pas de 0,5 °C* — écartée parce que la source qui la porte décrit un autre backend ;
   redeviendrait le bon choix dès que le point de recette 2 montre que l'appareil affiche 2 °C au lieu de
   22, ou refuse la consigne. Il suffit alors de passer `FACTEUR_TEMP_AUX_HOME` à `10` et
   `PAS_ECRITURE_AUX_HOME` à `0.5`, et de mettre à jour l'analyse de transport.
2. *Détecter l'échelle automatiquement en relisant l'état après écriture* — écartée : cela demande un
   aller-retour supplémentaire, une heuristique sur une valeur que le cloud met plusieurs minutes à
   rafraîchir, et une devinette persistée. Un défaut fixe corrigeable en un littéral vaut mieux qu'une
   déduction qui se trompe en silence. Ne redeviendrait envisageable que si le parc réel mélangeait les
   deux échelles selon le modèle d'appareil.

**Portée dans le code**
- `core/class/smartclimCapabilities.class.php` → constantes `FACTEUR_TEMP_AUX_HOME`,
  `PAS_ECRITURE_AUX_HOME` et méthode `echelleTemperature()`
- `core/class/smartclimAuxHomeApi.class.php` → `appliquerOrdre()`, conversion du concept `target_temp`
- `core/class/smartclim.class.php` → `ordreEffectifConsigne()` (quantification sur la grille du pas) et
  `creerCommandesAction()` (pas du curseur)

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclimCapabilities.class.php` (2 littéraux)
- Specs à corriger : `.memory/specs/MVP/06-commandes-action-pilotage-tech.md` § 3.2 et
  `.memory/analyse/smartclim-transport-aux-home.md` §§ 4.2 / 9
- Migration de l'existant : **aucune** en base ; le pas du curseur des commandes `set_target_temp` déjà
  créées est réaligné automatiquement au cycle suivant
- i18n : **aucune**
- Réversibilité : **facile** — deux valeurs dans une table

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/06-commandes-action-pilotage.md` § À confirmer, 1ᵉʳ point
- Spec technique : `.memory/specs/MVP/06-commandes-action-pilotage-tech.md` §§ 3.1, 3.2 · point de
  recette 2

### D-MVP06-03 — Allumage implicite par une seule requête à deux clés, pas deux requêtes

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 2 de `/feature` (deux critères d'acceptation en tension)
- **Principes** : P1, P6

**Question**
AC2 exige qu'actionner une commande de mode sur un climatiseur **éteint** l'allume aussi. AC7 exige que
deux ordres rapprochés ne fassent pas biper deux fois le climatiseur — et, plus largement, la spec veut
« un ordre unique » par interaction. Or `.memory/analyse/smartclim-transport-aux-home.md` § 4.1 pose la
règle « une intention par requête » pour ce cloud. Fallait-il envoyer deux requêtes séquentielles
(`on_off` puis le mode), ce qui fait deux bips, ou une seule requête portant deux clés d'intention, ce qui
sort de la règle générale de l'analyse ?

**Décision**
**Une seule requête portant au plus deux clés** : `{"intent": {"on_off": 1, "air_con_func": <code>}}`.
La même analyse documente explicitement cette **exception** (« changer de mode alors que l'appareil est
éteint impose d'envoyer aussi `on_off: 1` »), donc ce n'est pas une entorse mais l'application du cas
particulier prévu.

Portée exacte de l'allumage implicite :
- les 5 commandes `mode_*` **et** `set_target_temp` embarquent **inconditionnellement** `power => 1` ;
- les 5 commandes `fan_*` **n'embarquent pas** `power` (cf. D-MVP06-09) ;
- `on` envoie `power => 1` seul, `off` envoie `power => 0` seul.

`power => 1` est ajouté **sans jamais consulter l'état `power` connu** de l'équipement : cet état peut
être absent du profil de capacités ou périmé de plusieurs minutes, et un ordre de pilotage ne doit pas
dépendre d'une lecture douteuse.

**Pourquoi**
P1 : c'est la seule construction qui tient AC2 et AC7 **en même temps** ; deux requêtes séquentielles
tiendraient AC2 en cassant le « bip unique ». P6 : envoyer `power => 1` inconditionnellement est l'option
la plus conservatrice — un ordre de mode sur un appareil déjà allumé est inoffensif, alors qu'un ordre de
mode qui n'allume pas laisse l'utilisateur devant un appareil éteint.

**Alternatives écartées**
1. *Deux requêtes séquentielles, `on_off` puis le concept, dans le même budget de temps* — écartée parce
   qu'elle produit deux bips et double le temps d'un ordre ; redeviendrait le seul choix possible si le
   point de recette 1 montre que le backend européen **ignore** l'une des deux clés quand elles sont
   envoyées ensemble (risque R1 de la spec technique). C'est le repli documenté, non implémenté.
2. *N'ajouter `power => 1` que si l'état `power` connu vaut 0* — écartée parce qu'elle fait dépendre le
   pilotage d'une valeur qui peut être absente ou périmée : sur un état inconnu, l'appareil ne
   s'allumerait pas et AC2 échouerait de façon intermittente, donc indébogable.

**Portée dans le code**
- `core/class/smartclim.class.php` → `definitionsCommandesAction()`, colonne « ordre » de chaque
  définition ; `executerCommandeAction()`, construction de l'ordre générique
- `core/class/smartclimAuxHomeApi.class.php` → `intentionsAuxHome()` (concept `power` vers clé `on_off`)
  et `appliquerOrdre()` (assemblage de l'intent)

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclim.class.php` (retirer `power` des ordres de mode et de
  consigne) et `core/class/smartclimAuxHomeApi.class.php` (`appliquerOrdre()` devrait émettre deux
  requêtes séquentielles et rendre le budget de temps à deux appels)
- Specs à corriger : `.memory/specs/MVP/06-commandes-action-pilotage-tech.md` §§ 3.3, 5.2 et risque R1
- Migration de l'existant : **aucune** (aucune donnée persistée)
- i18n : **aucune**
- Réversibilité : **moyenne** — le partage du budget de temps entre deux requêtes doit être réécrit

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/06-commandes-action-pilotage.md` § critères AC2, AC7
- Spec technique : `.memory/specs/MVP/06-commandes-action-pilotage-tech.md` § 3.3 · risque R1 · point de
  recette 1
- Analyse : `.memory/analyse/smartclim-transport-aux-home.md` § 4.1

### D-MVP06-04 — Déduplication par empreinte du contenu de l'ordre, sans verrou par équipement

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 2 de `/feature` (deux critères d'acceptation contradictoires en apparence)
- **Principes** : P1, P6, P7

**Question**
AC7 demande que deux ordres **identiques** rapprochés (double-clic, scénario mal écrit) n'aient qu'un seul
effet réel. AC10 demande l'inverse pour deux ordres **différents** quasi simultanés (un scénario qui
change le mode puis la vitesse) : aucun des deux ne doit être silencieusement perdu. Un verrou
« un ordre à la fois par équipement » — la solution réflexe, déjà utilisée pour le scan en UC03 — tiendrait
AC7 et **casserait** AC10. Sur quoi porte alors la clé d'unicité ?

**Décision**
La clé porte sur le **contenu de l'ordre**, jamais sur l'équipement seul :

```text
empreinte = sha1( json_encode( ordre generique trie par cle ) )
cle       = 'smartclim::ordre_recent::' . <idEqLogic> . '::' . empreinte
```

Le marqueur est posé dans le cache Jeedom avec `DUREE_DEDUP_ORDRE = 10` secondes, **avant** l'appel réseau
(pour couvrir le double-clic pendant que le premier ordre est encore en vol). Si le marqueur existe déjà,
la méthode journalise en `debug` et **retourne immédiatement** sans appel réseau et sans écriture d'état —
le premier ordre s'en est déjà chargé. En cas d'**échec** de l'ordre, le marqueur est **supprimé**, pour
qu'un ordre raté reste rejouable tout de suite.

**Aucun verrou par équipement** n'est posé, contrairement au verrou de scan d'UC03.

**Pourquoi**
P1 : c'est la seule clé qui satisfait les deux critères, puisque deux ordres différents produisent deux
empreintes différentes tandis que deux ordres identiques n'en produisent qu'une. P6 : le marqueur est posé
**avant** le réseau, ce qui est le sens conservateur (mieux vaut ignorer un doublon qu'envoyer deux ordres),
et il est retiré en cas d'échec pour ne pas transformer une panne réseau en dix secondes de blocage. P7 :
la fenêtre est un entier unique (`DUREE_DEDUP_ORDRE`), donc réglable d'une valeur.

⚠️ Limite assumée et écrite dans la spec technique : `cache::byKey()` puis `cache::set()` ne sont **pas
atomiques**. C'est une **atténuation** (double-clic, deux onglets), jamais un mutex — même formulation que
le verrou de scan d'UC03.

**Alternatives écartées**
1. *Verrou « un ordre à la fois par équipement »* — écartée parce qu'elle fait échouer AC10 : le second
   ordre d'un scénario serait rejeté ou perdu. Redeviendrait pertinente si le cloud se révélait incapable
   de traiter deux ordres rapprochés sur le même appareil, auquel cas il faudrait **sérialiser** (file
   d'attente) et non **rejeter**.
2. *Aucune déduplication, en s'appuyant sur l'idempotence supposée du cloud* — écartée parce qu'AC7 parle
   explicitement des **bips** du climatiseur : même si l'état final est le même, l'appareil réagit deux
   fois. Ne redeviendrait acceptable que si la recette montrait qu'un ordre identique répété ne fait pas
   biper l'appareil.
3. *Fenêtre plus longue, de l'ordre de la minute* — écartée : elle empêcherait un utilisateur de renvoyer
   volontairement le même ordre après un doute. Dix secondes couvrent le double-clic sans gêner l'usage.

**Portée dans le code**
- `core/class/smartclim.class.php` → constantes `CLE_CACHE_DEDUP`, `DUREE_DEDUP_ORDRE` ;
  `executerCommandeAction()` étapes 5 (test et pose du marqueur) et 6 (suppression sur échec)

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclim.class.php` uniquement
- Specs à corriger : `.memory/specs/MVP/06-commandes-action-pilotage-tech.md` § 7
- Migration de l'existant : **aucune** — les entrées de cache expirent seules en 10 secondes
- i18n : **aucune**
- Réversibilité : **facile**

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/06-commandes-action-pilotage.md` § critères AC7, AC10
- Spec technique : `.memory/specs/MVP/06-commandes-action-pilotage-tech.md` § 7 · point de recette 4

### D-MVP06-05 — Mémoire des ordres et période de grâce dans le cache, pas dans la configuration d'équipement

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 2 de `/feature` (écart entre le plan et une analyse interne existante)
- **Principes** : P3, P6, P7

**Question**
UC06 doit payer la dette **D-MVP05-07** : mémoriser la dernière valeur **commandée** et l'opposer, pendant
une période de grâce, à la valeur relue du cloud — sans quoi commander « Silencieux » puis relire
« Faible » affiche une vitesse fausse, et un rafraîchissement immédiat annule visuellement une consigne
qu'on vient de régler (anti-rollback). Où cette mémoire vit-elle ? `.memory/analyse/`
`smartclim-architecture-jeedom.md` § 3.2 suggérait une clé `etat_optimiste` dans la **configuration
d'équipement**, ce qui contredisait le plan.

**Décision**
La mémoire vit dans le **cache Jeedom**, **une entrée par équipement** :
`smartclim::ordres::<idEqLogic>`, contenu
`json_encode(array(<concept> => array('valeur' => <valeur generique>, 'ts' => <epoch>)))`, **non chiffré**,
durée de vie `DUREE_GRACE = 60` secondes.

Trois propriétés voulues :
- **Horodatage par concept** : le filtre relit `ts` concept par concept, de sorte qu'un ordre `mode` posé
  il y a 55 s ne prolonge pas la grâce d'un ordre `fan_speed` posé il y a 5 s.
- **Écriture fusionnante** : `enregistrerOrdre()` relit l'entrée, purge les concepts expirés, écrase les
  concepts commandés, réécrit — un nouvel ordre n'efface jamais la mémoire d'un autre concept sous grâce.
- **Consommation dans `appliquerEtat()`** : `appliquerEtat(array $_etat, $_optimiste = false)`. Quand
  `$_optimiste` vaut `false` (donc pour UC05 et, par héritage, pour le cron d'UC07), l'état est filtré
  **avant** la boucle de poussée : pour chaque concept mémorisé et non expiré, si la valeur relue **égale**
  la valeur commandée le concept est **retiré de la mémoire** (fin de grâce anticipée), et si elle
  **diffère** la clé est **retirée de l'état** — la commande info n'est pas touchée, `valueDate` reste
  intact, et un `log::add('debug')` note « valeur commandée X, valeur relue Y ». Quand `$_optimiste` vaut
  `true` (l'appel qui suit immédiatement un ordre réussi), aucun filtrage : on ne filtre pas son propre
  ordre.

**Pourquoi**
P6 et P3 sur le stockage : un `setConfiguration()` suivi de `save()` à **chaque clic** déclencherait les
hooks `preSave`/`postSave` de l'équipement et une écriture SQL, pour une donnée qui vit 60 secondes. Le
cache est le bon support d'un état volatil ; l'analyse est en écart et le sera corrigée. Le contenu n'est
pas chiffré parce qu'il ne porte **aucun** secret — le chiffrement du cache de session existe uniquement
parce que celui-ci porte un jeton. P3 sur le mécanisme de filtrage : il réutilise **à l'identique** la
règle déjà livrée par UC05 (« clé absente de l'état, donc commande non touchée »), donc aucune voie de
poussée nouvelle et aucun risque de divergence de comportement. P7 : le filtre est concentré dans une
méthode et une constante.

⚠️ Effet de bord assumé, à constater en recette : pendant la grâce, un changement fait **à la
télécommande infrarouge** est masqué jusqu'à 60 secondes. La fin de grâce anticipée sur confirmation
réduit cette fenêtre au minimum.

**Alternatives écartées**
1. *Clé `etat_optimiste` dans la configuration d'équipement* (ce que suggérait l'analyse) — écartée pour
   le coût d'écriture et le déclenchement des hooks de sauvegarde ; redeviendrait nécessaire si la grâce
   devait survivre à un vidage de cache ou à un redémarrage de Jeedom, ce qu'aucun critère ne demande.
2. *Période de grâce globale à l'équipement, sans horodatage par concept* — écartée parce qu'un ordre
   récent sur un concept prolongerait indûment la grâce d'un autre, masquant des changements réels plus
   longtemps que nécessaire.
3. *Ne pas filtrer du tout et faire confiance à la valeur relue* — écartée : c'est exactement l'affichage
   incohérent que D-MVP05-07 et l'anti-rollback cherchent à éviter.

**Portée dans le code**
- `core/class/smartclim.class.php` → constantes `CLE_CACHE_ORDRES`, `DUREE_GRACE` ; méthodes
  `memoireOrdres()`, `enregistrerOrdre()`, `filtrerEtatSelonOrdres()`, `memeValeur()` ;
  `appliquerEtat()` (2ᵉ paramètre `$_optimiste` et appel du filtre) ; `preRemove()` (purge de l'entrée) ;
  `executerCommandeAction()` étape 7

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclim.class.php`
- Specs à corriger : `.memory/specs/MVP/06-commandes-action-pilotage-tech.md` § 9,
  `.memory/specs/MVP/05-commandes-info-etat-tech.md` § « AC10 en détail », et
  `.memory/analyse/smartclim-architecture-jeedom.md` § 3.2 (clé `etat_optimiste` à retirer ou à requalifier)
- Migration de l'existant : **aucune** — les entrées de cache expirent seules en 60 secondes ; passer en
  configuration d'équipement demanderait en revanche de purger les clés de cache résiduelles
- i18n : **aucune**
- Réversibilité : **facile**

**Traçabilité**
- Décision d'origine payée : `D-MVP05-07` (statut « dette »), `.memory/auto-dev/run-20260825-2356/MVP-05/decisions.md`
- Spec fonctionnelle : `.memory/specs/MVP/06-commandes-action-pilotage.md` § critère AC3
- Spec technique : `.memory/specs/MVP/06-commandes-action-pilotage-tech.md` § 9 · points de recette 7 et 8

### D-MVP06-06 — Aucun rejeu d'authentification pendant le pilotage, seule la session est purgée

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 2 de `/feature` (arbitrage entre robustesse et budget de temps)
- **Principes** : P1, P4

**Question**
Le jeton de session AUX Home est mis en cache 30 minutes, mais sa durée de vie réelle est inconnue. Si le
cloud le rejette en cours de pilotage, faut-il rejouer un login puis renvoyer l'ordre — ce que fait déjà
`listerAppareils()` en UC03 avec un rejeu borné — ou échouer ?

**Décision**
**Aucun rejeu, aucun re-login réactif** dans le chemin de pilotage. Sur une erreur classée
`smartclimException::TYPE_AUTH`, `appliquerOrdre()` appelle `smartclimAuxHomeApi::purgerSession()` puis
laisse l'exception remonter. Conséquence concrète : l'ordre en cours **échoue**, avec le message curaté
habituel, et la **tentative suivante de l'utilisateur** repart sur un login frais au lieu d'attendre
l'expiration naturelle du cache de 30 minutes.

Ce n'est pas un rejeu : aucune boucle, aucun compteur, une seule ligne.

**Pourquoi**
P1 : AC8 impose un échec en moins d'une vingtaine de secondes, et le budget total retenu est de 18 s. Un
login coûte jusqu'à 14 s (deux requêtes enchaînées) et le rejeu de l'ordre 4 s de plus — le rejeu ferait
donc mécaniquement dépasser le budget, cassant un critère pour en améliorer un autre qui n'existe pas dans
UC06. P4 : la gestion de l'expiration de session en cours de pilotage et l'anti-boucle de reconnexion sont
**explicitement listées hors périmètre** par la spec fonctionnelle (renvoyées à UC08). La purge est la
mitigation minimale qui ne coûte pas de temps réseau.

**Alternatives écartées**
1. *Re-login puis rejeu unique de l'ordre, comme `listerAppareils()`* — écartée pour le dépassement du
   budget d'AC8 ; c'est la solution qu'UC08 devra mettre en place, en révisant alors soit le budget, soit
   la stratégie de fraîcheur du jeton.
2. *Ne rien faire du tout sur `TYPE_AUTH`* — écartée parce que l'utilisateur subirait alors jusqu'à 30
   minutes d'échecs répétés sur un jeton mort, sans aucun moyen d'action ; la purge rend la tentative
   suivante utile pour le prix d'une ligne.

⚠️ Limite connue et écrite (risque R8 de la spec technique) : le premier ordre après expiration du jeton
échoue sur un message « vérifiez vos identifiants », qui est trompeur puisque les identifiants sont bons.
C'est le principal point que UC08 devra corriger.

**Portée dans le code**
- `core/class/smartclimAuxHomeApi.class.php` → `appliquerOrdre()`, branche `TYPE_AUTH` appelant
  `purgerSession()`

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclimAuxHomeApi.class.php` (`appliquerOrdre()`), et l'arithmétique
  de budget de `BUDGET_COMMANDE` / `RESERVE_ORDRE` si le rejeu doit tenir dans le même budget
- Specs à corriger : `.memory/specs/MVP/06-commandes-action-pilotage-tech.md` § 3.4 et risque R8
- Migration de l'existant : **aucune**
- i18n : possiblement un message dédié à l'expiration de session, si UC08 en introduit un
- Réversibilité : **moyenne** — le rejeu impose de revoir le partage du budget de temps

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/06-commandes-action-pilotage.md` § critère AC8 et § Hors
  périmètre (« expiration de session en cours de pilotage → UC08 »)
- Spec technique : `.memory/specs/MVP/06-commandes-action-pilotage-tech.md` § 3.4 · risque R8

### D-MVP06-07 — Aucune commande d'oscillation, malgré la section i18n de la spec

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 2 de `/feature` (contradiction interne à la spec fonctionnelle)
- **Principes** : P1, P3, P4

**Question**
La section « Comportement attendu » d'UC06 mentionne l'« activation/désactivation des oscillations » et sa
section « Impact i18n » annonce les chaînes « Oscillation verticale — Marche/Arrêt » et « Oscillation
horizontale — Marche/Arrêt ». Mais AC6 interdit de proposer une commande pour une valeur non détectée
comme supportée par le profil de capacités, et le profil produit par UC04 **ne contient aucun concept
d'oscillation** : la décision D-MVP04-03 avait exclu les oscillations du profil parce qu'elles ne sont pas
lisibles de façon fiable sur le fil, et UC05 avait pour la même raison déclaré son critère AC2 (commandes
info d'oscillation) non applicable. Fallait-il créer les commandes d'oscillation en s'appuyant sur des
codes non confirmés, ou n'en créer aucune ?

**Décision**
**Aucune commande d'oscillation n'est créée**, et les deux chaînes annoncées par la section i18n de la
spec **ne sont pas introduites**. Le mécanisme de création étant piloté par le profil (`capacites`), il
n'y a rien à coder pour cela : l'absence du concept produit l'absence de la commande. Les oscillations
réapparaîtront **automatiquement**, sans code nouveau, le jour où le concept entrera dans le profil de
capacités — c'est-à-dire quand la recette aura confirmé les codes d'oscillation.

**Pourquoi**
P1 et P3 : AC6 est un critère d'acceptation **testable** (« aucune commande n'est proposée pour une valeur
non détectée comme supportée »), tandis que la mention des oscillations dans le corps de la spec et sa
section i18n sont des **anticipations** rédigées avant que D-MVP04-03 n'exclue le concept. Entre un critère
et une anticipation, le critère gagne. P4 : créer des commandes d'oscillation reposerait sur des codes
que la spec elle-même marque « incertains sur certains transports » — ce serait piloter à l'aveugle un
volet mécanique.

**Alternatives écartées**
1. *Créer les commandes d'oscillation d'après les codes documentés mais non confirmés* — écartée parce
   qu'elle viole AC6 et envoie des ordres non vérifiés à un organe mécanique ; redeviendrait le bon choix
   dès que la recette confirme les codes, mais il faudra alors d'abord les faire entrer dans le profil de
   capacités (UC04), pas contourner le profil.
2. *Créer les commandes en les masquant (`isVisible = 0`) pour préparer le terrain* — écartée : masqué ne
   veut pas dire non exécutable (une commande masquée reste pilotable par scénario et par l'API), donc
   cela n'atténue rien et ajoute du code mort.

**Portée dans le code**
- `core/class/smartclim.class.php` → `definitionsCommandesAction()` : aucune entrée `swing_v_*` /
  `swing_h_*`, la liste est **dérivée du profil**
- `core/i18n/{en_US,de_DE,es_ES}.json` → aucune clé d'oscillation

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclimCapabilities.class.php` (faire entrer le concept
  d'oscillation dans les tables), `core/class/smartclim.class.php`
  (`definitionsCommandesInfo()` et `definitionsCommandesAction()`),
  `core/class/smartclimAuxHomeApi.class.php` (`intentionsAuxHome()` et décodage d'état)
- Specs à corriger : `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md`,
  `.memory/specs/MVP/05-commandes-info-etat-tech.md`,
  `.memory/specs/MVP/06-commandes-action-pilotage-tech.md` § 6
- Migration de l'existant : **aucune** (rien n'a été créé)
- i18n : 2 à 4 clés françaises nouvelles, à traduire dans les 3 langues cibles
- Réversibilité : **facile** dans le sens de l'ajout — rien n'a été posé qu'il faille défaire

**Traçabilité**
- Décisions liées : `D-MVP04-03` (exclusion des oscillations du profil), `D-MVP05-01` (AC2 d'UC05 non
  applicable)
- Spec fonctionnelle : `.memory/specs/MVP/06-commandes-action-pilotage.md` § critère AC6, § Impact i18n,
  § À confirmer 3ᵉ point
- Spec technique : `.memory/specs/MVP/06-commandes-action-pilotage-tech.md` § 6

### D-MVP06-08 — Libellés de mode alignés sur UC04, contre ceux annoncés par la spec d'UC06

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 2 de `/feature` (incohérence de libellés entre deux specs)
- **Principes** : P3, P2

**Question**
La section « Impact i18n » d'UC06 annonce les libellés de mode « Froid » et « Chaud ». Or les libellés
canoniques déjà livrés par UC04 et affichés partout ailleurs dans le plugin sont
« Refroidissement » et « Chauffage ». Lequel retenir pour les **noms de commandes action** ?

**Décision**
Les libellés d'UC04 : les noms de commande sont composés par
`sprintf(__('Mode %s', __FILE__), smartclimCapabilities::libelle(<concept mode>, <valeur>))`, donc
« Mode Refroidissement », « Mode Chauffage », « Mode Automatique », « Mode Déshumidification »,
« Mode Ventilation ». Idem pour les vitesses avec le gabarit `__('Vitesse %s', __FILE__)`.

Bénéfice annexe assumé : **2 clés i18n au lieu de 10**, puisque seuls les gabarits sont des chaînes
littérales et que les libellés viennent de la table déjà traduite d'UC04.

**Pourquoi**
P3 : deux mots pour la même notion dans la même interface est un défaut visible — le bouton dirait
« Mode Froid » pendant que la commande info et le profil de capacités affichent « Refroidissement ». La
table de libellés d'UC04 est la mémoire du projet sur ce point. P2 : le français est langue source et la
chaîne passée à `__()` doit être **littérale** — le gabarit `'Mode %s'` respecte cette contrainte, alors
qu'un `__($nom)` échapperait au scan d'extraction i18n.

⚠️ Contrainte transmise au traducteur : ces chaînes deviennent des **noms de commande**, or
`cmd::setName()` ampute silencieusement les caractères `& # ] [ % \ / ' " *`. Les traductions doivent
donc bannir l'apostrophe et la barre oblique.

**Alternatives écartées**
1. *Suivre la spec d'UC06 et écrire « Mode Froid » / « Mode Chaud »* — écartée pour l'incohérence
   d'interface ; redeviendrait le bon choix si l'utilisateur décidait de renommer **partout** les
   libellés de mode, ce qui serait alors un changement dans la table de `smartclimCapabilities`, pas dans
   UC06.
2. *Dix chaînes littérales complètes, une par commande (« Mode Refroidissement », …)* — écartée : dix clés
   i18n à maintenir au lieu de deux, et un risque de divergence avec les libellés de la table au premier
   renommage.

**Portée dans le code**
- `core/class/smartclim.class.php` → `definitionsCommandesAction()`, colonne `name` : gabarits
  `__('Mode %s', __FILE__)` et `__('Vitesse %s', __FILE__)` combinés à
  `smartclimCapabilities::libelle()`
- `core/i18n/{en_US,de_DE,es_ES}.json` → 2 clés de gabarit au lieu de 10 libellés complets

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclim.class.php` (`definitionsCommandesAction()`)
- Specs à corriger : `.memory/specs/MVP/06-commandes-action-pilotage-tech.md` § 6 et § 11
- Migration de l'existant : **oui** — le `name` d'une commande n'est posé qu'à la création (règle
  d'idempotence), donc les commandes déjà créées chez l'utilisateur garderaient l'ancien libellé. Il
  faudrait un renommage explicite des commandes existantes
- i18n : 2 clés de gabarit à retirer, 10 libellés complets à ajouter dans les 3 langues
- Réversibilité : **moyenne** — à cause du renommage des commandes déjà posées

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/06-commandes-action-pilotage.md` § Impact i18n
- Spec technique : `.memory/specs/MVP/06-commandes-action-pilotage-tech.md` §§ 6, 11
- Analyse : `.memory/analyse/jeedom-widgets-commandes.md` § 8.1 (amputation des noms de commande)

### D-MVP06-09 — Les commandes de vitesse n'allument pas l'appareil

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 2 de `/feature` (portée exacte de l'allumage implicite)
- **Principes** : P1, P4, P6

**Question**
D-MVP06-03 fait embarquer `power => 1` dans les ordres de mode et de consigne, pour tenir AC2. Fallait-il
l'embarquer aussi dans les 5 ordres de vitesse (`fan_auto`, `fan_low`, `fan_medium`, `fan_high`,
`fan_turbo`) ? AC10 mentionne un scénario changeant « mode et vitesse à la suite » sur un équipement
« éteint au départ », ce qui pourrait le suggérer.

**Décision**
**Non** : les ordres `fan_*` envoient uniquement `fan_speed`, sans `power`. AC2 ne demande l'allumage
implicite que pour « une commande de mode », et le comportement attendu de la spec le formule pour le mode
et la consigne (« Changer le mode ou la consigne d'un climatiseur actuellement éteint l'allume
également »). La vitesse n'est pas citée.

**Pourquoi**
P1 : la spec est précise sur le périmètre de l'allumage implicite, et l'étendre serait l'élargir. P6 :
allumer un climatiseur parce que l'utilisateur a touché à la ventilation est un effet de bord
**physique** et coûteux (l'appareil se met à chauffer ou à refroidir) — le sens conservateur est de ne pas
le faire. P4 : AC10 est tenu tel qu'il est écrit, l'exemple de la spec étant « mode **puis** vitesse » —
le mode allume, la vitesse suit.

⚠️ Limite écrite comme risque R4 de la spec technique et point de recette 6 : un scénario qui envoie
**vitesse puis mode** sur un appareil éteint peut voir l'ordre de vitesse ignoré par le cloud (l'appareil
n'est pas encore allumé). L'état optimiste afficherait alors une vitesse fausse pendant la période de
grâce, puis le rafraîchissement corrige. C'est le comportement à documenter en recette.

**Alternatives écartées**
1. *Embarquer `power => 1` dans les ordres de vitesse aussi* — écartée pour l'effet de bord physique ;
   redeviendrait le bon choix si la recette (point 6) montre qu'un ordre de vitesse sur appareil éteint est
   systématiquement perdu **et** que l'utilisateur juge ce comportement plus gênant que l'allumage
   involontaire.
2. *Conditionner l'ajout de `power` à l'état connu de l'appareil pour les seules vitesses* — écartée pour
   la même raison qu'en D-MVP06-03 : l'état `power` connu peut être absent ou périmé, ce qui rendrait le
   comportement intermittent, donc indébogable.

**Portée dans le code**
- `core/class/smartclim.class.php` → `definitionsCommandesAction()`, colonne « ordre » des 5 entrées
  `fan_*` (aucun `power`)

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclim.class.php` (5 entrées de table)
- Specs à corriger : `.memory/specs/MVP/06-commandes-action-pilotage-tech.md` §§ 3.3, 6 et risque R4
- Migration de l'existant : **aucune** (aucune donnée persistée)
- i18n : **aucune**
- Réversibilité : **facile** — cinq entrées d'une table de définitions

**Traçabilité**
- Décision liée : `D-MVP06-03` (allumage implicite du mode et de la consigne)
- Spec fonctionnelle : `.memory/specs/MVP/06-commandes-action-pilotage.md` § Comportement attendu,
  § critères AC2 et AC10
- Spec technique : `.memory/specs/MVP/06-commandes-action-pilotage-tech.md` § 3.3 · risque R4 · point de
  recette 6

### D-MVP06-10 — Création des commandes action câblée aussi dans `appliquerEtat()`, contre le plan initial

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 3 de `/feature` (l'advisor contredit le planner)
- **Principes** : P1, P3

**Question**
Le plan technique produit à l'étape 2 ne câblait la création des commandes action qu'à **un seul**
endroit : le hook `postSave()` de l'équipement. La revue critique de l'étape 3 a soulevé un blocage : dans
`core/class/smartclim.class.php`, la méthode `scannerAuxHome()` n'appelle `eqLogic::save()` que **si
quelque chose a changé** (identifiant d'appareil, modèle, ou divergence de profil détectée par
`appliquerCapacites()`). Sur un équipement déjà scanné et inchangé — c'est-à-dire le cas normal après une
simple mise à jour du plugin — il n'y a donc ni `save()`, ni `postSave()`, donc **aucune commande action ne
serait jamais créée**. Fallait-il relancer le planner pour arbitrer, ou trancher directement ?

**Décision**
La contradiction est tranchée **en faveur de l'advisor**, sans relance du planner.
`creerCommandesAction()` est appelée aux **deux** endroits où `creerCommandesInfo()` l'est déjà :
`postSave()` **et** `appliquerEtat()` — cette dernière tournant à chaque scan, changement ou non. L'appel
est toujours placé **après** `creerCommandesInfo()`, puisqu'il a besoin des identifiants des commandes
info pour poser `setValue()`.

Précision ajoutée au passage, qui n'était dans aucun des deux avis : dans `appliquerEtat()`, la paire de
créations est **gardée par `if (!$_optimiste)`**. L'appel optimiste qui suit immédiatement un ordre réussi
n'a rien à créer — la commande qu'on vient d'exécuter existe forcément — et deux requêtes `SELECT` de
commandes à chaque clic de pilotage seraient du gaspillage pur.

**Pourquoi**
P1 : sans ce câblage, **aucun** critère d'acceptation d'UC06 n'est atteignable sur une installation
existante, et la panne est totalement silencieuse (pas de log, pas d'erreur, invisible à `php -l` comme à
la CI). P3 : ce n'est pas un choix d'architecture nouveau mais l'application d'une leçon **déjà tirée et
déjà écrite** au cycle UC05, où exactement le même piège avait été rencontré pour `creerCommandesInfo()`
et corrigé de la même façon — le commentaire qui l'explique est dans le code depuis UC05.

Le planner n'a **pas** été relancé, et c'est délibéré : il ne s'agissait pas d'une divergence d'opinion
d'architecture — position A contre position B — mais d'une **omission factuelle**, démontrée par citation
du code existant. Il n'y avait donc aucune position adverse à départager, et une relance aurait coûté un
cycle de planification complet pour aboutir à la même ligne de code.

**Alternatives écartées**
1. *Conserver le seul `postSave()` et documenter qu'un enregistrement manuel de l'équipement est requis* —
   écartée : cela transforme un défaut en procédure imposée à l'utilisateur, pour un plugin qui se déploie
   par mise à jour automatique du market. Ne redeviendrait acceptable que si la création des commandes
   devenait une action volontaire et explicite de l'utilisateur.
2. *Forcer un `save()` de l'équipement à chaque scan pour faire déclencher `postSave()`* — écartée parce
   qu'elle enfreint une exigence de non-régression déjà tenue (deux scans identiques ne doivent produire
   aucune écriture en base, point de recette 10) et déclencherait tous les hooks de sauvegarde pour rien.
3. *Relancer le planner avec la contradiction avant de trancher* — écartée pour le motif exposé ci-dessus :
   il n'y avait pas deux positions, il y avait un oubli prouvé.

**Portée dans le code**
- `core/class/smartclim.class.php` → `appliquerEtat()` : appel de `creerCommandesInfo()` puis
  `creerCommandesAction()`, sous garde `if (!$_optimiste)`
- `core/class/smartclim.class.php` → `postSave()` : appel de `creerCommandesAction()` après
  `creerCommandesInfo()`

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclim.class.php` (retirer l'appel dans `appliquerEtat()`)
- Specs à corriger : `.memory/specs/MVP/06-commandes-action-pilotage-tech.md` §§ 4, 4.1 et risque R10
- Migration de l'existant : **aucune** — la création est idempotente par `logicalId`, la retirer ne
  supprime aucune commande déjà créée (elle empêche seulement d'en créer de nouvelles)
- i18n : **aucune**
- Réversibilité : **facile**

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/06-commandes-action-pilotage.md` § Comportement attendu
  (« Pour chaque fonction détectée comme supportée […] une commande action correspondante est disponible »)
- Spec technique : `.memory/specs/MVP/06-commandes-action-pilotage-tech.md` § 4.1 · risque R10 · point de
  recette 10
- Décision UC05 dont ceci applique la leçon : câblage de `creerCommandesInfo()` dans `appliquerEtat()`,
  `.memory/specs/MVP/05-commandes-info-etat-tech.md`
