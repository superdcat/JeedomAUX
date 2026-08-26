# Décisions automatiques — UC MVP/05 (commandes info : lecture de l'état)

> Run `run-20260825-2356` — cycle `/feature` déroulé sans humain dans la boucle.
> Grille d'arbitrage : `.claude/templates/principes-arbitrage.md`.
> Spec fonctionnelle : `.memory/specs/MVP/05-commandes-info-etat.md`
> Spec technique : `.memory/specs/MVP/05-commandes-info-etat-tech.md`

> **Gate étape 4 (validation du plan) : auto-validée** le 2026-08-26. Conditions vérifiées : les
> 11 critères d'acceptation sont couverts ou explicitement journalisés (AC2 non applicable — D-MVP05-01 ;
> AC10 partiel — D-MVP05-07), aucune question ouverte ne subsiste (le planner a conclu « aucune »), le
> périmètre ne dépasse pas la spec (UC06 et UC07 restent hors champ), aucune dépendance nouvelle, aucun
> invariant `CLAUDE.md` enfreint. L'advisor (`code-reviewer` sur le plan) n'a **pas** contredit le
> planner : verdict « aucun blocker ni major », 3 findings `minor` (contournement d'AC6 par le réglage
> natif « toujours notifier », coût N+1 de la vérification d'existence des commandes, ambiguïté de
> gating des 2 commandes méta) — **tous les trois intégrés directement dans la spec technique**, sans
> arbitrage à trancher.

### D-MVP05-01 — AC2 (commandes info d'oscillation) déclaré non applicable

- **Statut** : dette
- **Date** : 2026-08-26
- **Gate** : étape 1 de `/feature` (chargement de la spec fonctionnelle — critère non couvrable)
- **Principes** : P1, P3, P4

**Question**
Le critère AC2 de `.memory/specs/MVP/05-commandes-info-etat.md` demande que, « pour un équipement dont
le profil détecte une ou deux oscillations, la ou les commandes info d'oscillation correspondantes
apparaissent ». Or l'UC04, qui construit le profil de capacités, n'y a fait entrer **aucun concept
d'oscillation** : la lecture par axe (verticale / horizontale) n'est pas établie sur le transport AUX
Home. Fallait-il inventer en UC05 un concept d'oscillation et une lecture d'état associée pour honorer
le critère, ou constater qu'il n'a pas d'objet dans ce cycle ?

**Décision**
AC2 est traité comme **non applicable en l'état** et reporté. Aucune commande info d'oscillation n'est
créée, aucun concept `swing_*` n'est ajouté à `smartclimCapabilities`. La condition d'entrée du critère
(« dont le profil détecte une ou deux oscillations ») n'est jamais vraie tant que le profil produit par
`smartclim::appliquerCapacites()` ne contient pas de concept d'oscillation : le code d'UC05 crée les
commandes info **par itération sur les concepts réellement présents dans le profil**, si bien que le
jour où un concept d'oscillation entrera dans le profil (UC06, ou domaine post-MVP 01 sur le LAN
Broadlink, où la lecture par axe est documentée), les commandes info correspondantes apparaîtront
**sans modification du code d'UC05**.

**Pourquoi**
P1 : un critère qu'on ne sait pas couvrir se journalise, il ne se réinterprète pas — inventer une
lecture d'oscillation non vérifiée produirait une commande info affichant une valeur fausse, ce qui est
pire que son absence. P3 : la décision D-MVP04-03 a explicitement exclu toutes les oscillations du
profil « lisible sur le fil » ; y revenir en UC05 créerait une seconde convention concurrente. P4 :
périmètre minimal — la lecture d'oscillation relève du transport, pas de la couche commandes.

**Alternatives écartées**
1. *Créer les commandes info d'oscillation malgré tout, à valeur toujours inconnue* — écartée parce
   qu'une commande info permanente sans source de valeur pollue le dashboard et ne peut plus être
   retirée (AC9 interdit la suppression de commandes existantes) ; redeviendrait le meilleur choix si
   la lecture d'oscillation était confirmée sur AUX Home mais seulement partiellement fiable.
2. *Ajouter en UC05 un décodage d'oscillation depuis la trame HVAC* — écartée parce que le décodage de
   trame relève de `smartclimFrame` (non écrite) et du transport, hors périmètre UC05 ; redeviendrait
   le meilleur choix si UC06 devait de toute façon écrire ce décodage pour commander les oscillations.

**Portée dans le code**
- `core/class/smartclimCapabilities.class.php` → aucune constante `CONCEPT_SWING_*` (absence assumée)
- `core/class/smartclim.class.php` → la création des commandes info itère sur les concepts du profil,
  elle ne référence aucune oscillation nommément

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclimCapabilities.class.php` (nouveaux concepts + entrées de
  table), le transport qui alimente le profil, et la table des libellés
- Specs à corriger : `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md` (tables de
  correspondance) et `.memory/specs/MVP/05-commandes-info-etat-tech.md`
- Migration de l'existant : **aucune** — l'ajout d'un concept crée de nouvelles commandes au scan
  suivant, sans toucher aux commandes déjà posées
- i18n : deux clés françaises à ajouter (« Oscillation verticale », « Oscillation horizontale »)
- Réversibilité : facile — c'est une entrée de table de données, pas une logique câblée (P7)

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/05-commandes-info-etat.md` § critère AC2
- Décision liée : D-MVP04-03 (exclusion des oscillations du profil « lisible sur le fil »)
- Commit : `feat(MVP-05): ...` sur `master` — retrouvable par `git log --grep "feat(MVP-05)"`

### D-MVP05-02 — Décodage de trame gardé dans le transport, pas de classe smartclimFrame

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 2 de `/feature` (architecture proposée par le planner)
- **Principes** : P3, P4, P7

**Question**
`CLAUDE.md` annonce une future classe `smartclimFrame` (« décodage/encodage de la trame HVAC »), et
UC05 est la première UC à décoder réellement le contenu des trames HVAC du cloud AUX Home. Fallait-il
créer cette classe maintenant, ou laisser le décodage dans la brique de transport
`smartclimAuxHomeApi` ?

**Décision**
**Aucune classe `smartclimFrame` n'est créée en UC05.** Le décodage vit dans
`core/class/smartclimAuxHomeApi.class.php`, sous la forme de trois éléments privés : une table
`champsEtatAuxHome()` (concept vers trame `control`/`running` + indices d'octets), un accesseur
`octetTrame($_trame, $_index)`, et le point d'entrée public `etatAppareil(array $_appareil)`. Aucune
ligne n'est ajoutée à `core/php/smartclim.inc.php` (aucune classe nouvelle, donc aucun `require_once`).
La méthode `offsetsAuxHome()` livrée par UC04 est conservée avec **la même signature et la même forme
de retour**, mais dérive désormais ses longueurs minimales de `champsEtatAuxHome()` (une seule source
d'offsets).

**Pourquoi**
P3 : les offsets d'octets vivent **déjà** dans `smartclimAuxHomeApi` depuis UC04, et `CLAUDE.md`
autorise explicitement « offsets d'octets confinés dans la brique du transport ». Créer la classe
imposerait de les en sortir, donc de refactorer du code UC04 déjà livré et validé. P4 : un seul
transport existe au MVP, la classe n'aurait qu'un appelant — c'est de la généralisation spéculative.
P7 : l'extraction reste mécanique (une table, deux méthodes privées, un seul appelant).

**Alternatives écartées**
1. *Créer `smartclimFrame` dès UC05* — écartée parce qu'elle n'aurait qu'un seul appelant et
   obligerait à déplacer du code UC04 livré ; redeviendrait le meilleur choix dès que le transport
   Broadlink LAN (domaine post-MVP 01) décodera la même trame, ce qui est le cas attendu puisque
   l'analyse établit que les deux transports partagent la trame HVAC.
2. *Décoder au niveau de `smartclim::` plutôt que du transport* — écartée parce qu'elle ferait sortir
   des offsets propriétaires de la brique de transport, en contradiction directe avec `CLAUDE.md`
   (P2) ; ne redeviendrait jamais meilleure.

**Portée dans le code**
- `core/class/smartclimAuxHomeApi.class.php` : `champsEtatAuxHome()`, `octetTrame()`, `etatAppareil()`,
  `offsetsAuxHome()` (réécrite en dérivation), constantes `AMBIANTE_MIN_PLAUSIBLE` et
  `AMBIANTE_MAX_PLAUSIBLE`
- `core/php/smartclim.inc.php` : **aucune ligne ajoutée** — c'est le point à ne pas oublier en cas de
  revirement, créer `smartclimFrame` **impose** d'y ajouter son `require_once`, sans quoi le plugin
  plante en « Class not found » au runtime, sans que `php -l` ni la CI ne le voient

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclimFrame.class.php` (à créer),
  `core/class/smartclimAuxHomeApi.class.php` (retrait des trois éléments), `core/php/smartclim.inc.php`
  (ligne de `require_once` **obligatoire**)
- Specs à corriger : `.memory/specs/MVP/05-commandes-info-etat-tech.md` § Architecture et § Signatures
- Migration de l'existant : **aucune** — rien n'est persisté, rien n'est exposé à l'utilisateur
- i18n : **aucune**
- Réversibilité : facile — déplacement mécanique, un seul appelant

**Traçabilité**
- Spec technique : `.memory/specs/MVP/05-commandes-info-etat-tech.md` § Architecture, risque R8
- Commit : `feat(MVP-05): ...` sur `master` — retrouvable par `git log --grep "feat(MVP-05)"`

### D-MVP05-03 — Valeurs de mode et de vitesse poussées en codes génériques, non traduits

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 2 de `/feature` (contrat de valeur des commandes info)
- **Principes** : P1, P3

**Question**
Les commandes info `mode` et `fan_speed` sont de sous-type `string`. Fallait-il y pousser le **code
générique** du modèle (`COOL`, `HEAT`, `AUTO`, `TURBO`…) ou le **libellé français traduit** produit par
`smartclimCapabilities::libelle()` (« Froid », « Chaud », « Automatique »…) ?

**Décision**
Les commandes `mode` et `fan_speed` reçoivent le **code générique** (`COOL`, `HEAT`, `DRY`, `FAN`,
`AUTO` pour le mode ; `AUTO`, `LOW`, `MEDIUM`, `HIGH`, `TURBO` pour la vitesse), tel que renvoyé par
`smartclimCapabilities::depuisTransport()`. `libelle()` n'est **pas** appelée dans
`smartclim::appliquerEtat()`. Seule la commande méta `transport` reçoit un libellé en toutes lettres
(`AUX Home`, via `libelleTransport()`), parce que le critère AC5 l'exige explicitement.

**Pourquoi**
P1 : la spec fonctionnelle ne réclame « en toutes lettres » que pour le transport (AC5) ; elle ne le
demande ni pour le mode ni pour la vitesse. P3 :
`.memory/analyse/smartclim-architecture-jeedom.md` § 5.1 fixe déjà « valeur générique, libellé traduit
au widget ». Décisif en pratique : une condition de scénario écrite sur « Froid » cesserait de
fonctionner si l'utilisateur passait son Jeedom en anglais — la valeur d'une commande info est une
donnée de scénario, pas un texte d'interface.

**Alternatives écartées**
1. *Pousser le libellé traduit* — écartée parce qu'elle rend les scénarios utilisateur dépendants de la
   langue de l'interface, et parce que la valeur affichée sera de toute façon traduite par le widget
   dédié du domaine post-MVP 06 ; redeviendrait le meilleur choix si l'on renonçait définitivement à ce
   widget et que l'affichage brut devenait la seule vue utilisateur.
2. *Pousser le code générique et une seconde commande « libellé »* — écartée comme redondante (P4) et
   coûteuse en commandes visibles.

**Portée dans le code**
- `core/class/smartclim.class.php` : `appliquerEtat()` — aucun appel à
  `smartclimCapabilities::libelle()`
- `core/class/smartclimAuxHomeApi.class.php` : `etatAppareil()` renvoie des constantes `MODE_*` et
  `VITESSE_*`

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclim.class.php` (un appel à `libelle()` dans `appliquerEtat()`)
- Specs à corriger : `.memory/specs/MVP/05-commandes-info-etat-tech.md` § Impact i18n
- Migration de l'existant : **oui, à ne pas oublier** — les commandes déjà posées chez l'utilisateur
  portent la valeur précédente en cache, et les scénarios écrits sur `COOL` devraient être réécrits à
  la main. C'est le point le plus coûteux de cette décision
- i18n : les libellés existent déjà via `libelle()`, aucune clé nouvelle
- Réversibilité : moyenne — une ligne de code, mais une migration manuelle des scénarios utilisateur

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/05-commandes-info-etat.md` § critère AC5
- Spec technique : `.memory/specs/MVP/05-commandes-info-etat-tech.md` § Impact i18n
- Commit : `feat(MVP-05): ...` sur `master` — retrouvable par `git log --grep "feat(MVP-05)"`

### D-MVP05-04 — generic_type laissé vide partout sauf ONLINE

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 2 de `/feature` (propriétés des commandes créées)
- **Principes** : P1, P6, P7

**Question**
Jeedom propose des **types génériques** de commandes (`generic_type`) qui font entrer une valeur dans
les résumés d'objet, les widgets standard et les intégrations tierces (assistants vocaux, thermostat).
Fallait-il poser `TEMPERATURE` ou `THERMOSTAT_TEMPERATURE` sur la commande `ambient_temp`, et les
types `THERMOSTAT_*` sur les autres commandes info ?

**Décision**
Une seule commande reçoit un `generic_type` : `online` reçoit `ONLINE`. Toutes les autres commandes
info créées par UC05 (`power`, `mode`, `target_temp`, `ambient_temp`, `fan_speed`, `transport`,
`last_update`) sont créées **sans `generic_type`**.

**Pourquoi**
P1 : le critère AC11 impose d'avertir que la température ambiante AUX Home n'est pas temps réel et ne
doit pas servir de sonde de régulation. Poser `TEMPERATURE` ou `THERMOSTAT_TEMPERATURE` ferait
exactement l'inverse : le core l'enrôlerait **automatiquement** comme sonde de pièce dans les résumés
d'objet et les intégrations tierces, sans que l'utilisateur ait rien demandé. P6 : en cas de doute,
l'option la plus conservatrice. P7 : un `generic_type` se pose plus tard en une valeur, par
l'utilisateur ou par UC06 « si vide ».

**Alternatives écartées**
1. *Poser la famille `THERMOSTAT_*` complète dès UC05* — écartée parce que ces types n'ont de sens
   qu'avec les commandes **action** correspondantes, qui n'existent qu'en UC06 ; redeviendrait le
   meilleur choix en UC06, où l'ensemble info plus action est cohérent.
2. *Poser `TEMPERATURE` sur `ambient_temp` seulement* — écartée parce qu'elle contredit frontalement
   l'avertissement d'AC11 ; redeviendrait acceptable si le cloud fournissait un jour une ambiante
   réellement fraîche, ce qui se mesurerait en recette.

**Portée dans le code**
- `core/class/smartclim.class.php` : `definitionsCommandesInfo()`, colonne `generic_type`

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclim.class.php` (une valeur par ligne de table)
- Specs à corriger : `.memory/specs/MVP/05-commandes-info-etat-tech.md` § AC11 en détail
- Migration de l'existant : les commandes **déjà créées** ne sont jamais retouchées par
  `creerCommandesInfo()` (garantie AC7) — un revirement ne s'appliquerait donc qu'aux **nouvelles**
  commandes, sauf à écrire une routine de rattrapage « si vide », chemin prévu pour UC06
- i18n : **aucune**
- Réversibilité : facile pour les nouvelles installations, moyenne pour les installations existantes

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/05-commandes-info-etat.md` § critère AC11
- Spec technique : `.memory/specs/MVP/05-commandes-info-etat-tech.md` § AC11 en détail
- Commit : `feat(MVP-05): ...` sur `master` — retrouvable par `git log --grep "feat(MVP-05)"`

### D-MVP05-05 — Aucune borne min/max sur les commandes info numériques

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 2 de `/feature` (validation des valeurs poussées)
- **Principes** : P6, P1

**Question**
Les commandes info `target_temp` et `ambient_temp` sont numériques, et l'équipement porte déjà des
bornes de température personnalisables (clés de configuration `temp_min` et `temp_max`, UC04).
Fallait-il recopier ces bornes dans `configuration.minValue` et `configuration.maxValue` des commandes
info ?

**Décision**
**Non.** Aucune des commandes info numériques ne reçoit `minValue` ni `maxValue`. La seule protection
contre une valeur aberrante est le filtre de plausibilité **côté transport**
(`AMBIANTE_MIN_PLAUSIBLE = -20`, `AMBIANTE_MAX_PLAUSIBLE = 60`, dans
`smartclimAuxHomeApi::etatAppareil()`), qui **omet le concept** au lieu de pousser une valeur fausse.
Les bornes personnalisées de l'équipement restent réservées à la commande **action slider** d'UC06.

**Pourquoi**
Contrat du core vérifié en source pendant le plan : `cmd::event()` **jette silencieusement** (un
`log::add('cmd','info')` puis un retour) toute valeur numérique hors de `minValue`/`maxValue`. Un
utilisateur ayant restreint sa plage à 18-30 °C verrait donc une lecture réelle de 16 °C **disparaître
sans un mot**, ce qui est le contraire d'un état fidèle (P1) et un échec silencieux (P6, « échec
bruyant plutôt que silencieux »). Sans ces clés, le contrôle du core est neutre.

**Alternatives écartées**
1. *Recopier les bornes de l'équipement sur les commandes info* — écartée à cause du rejet silencieux
   décrit ci-dessus ; ne redeviendrait meilleure que si le core signalait le rejet à l'utilisateur.
2. *Poser des bornes larges fixes, par exemple -20 et 60* — écartée comme redondante avec le filtre de
   plausibilité déjà appliqué en amont, et parce qu'elle déplacerait un rejet silencieux dans le core
   au lieu d'un log explicite dans le plugin (P6).

**Portée dans le code**
- `core/class/smartclim.class.php` : `definitionsCommandesInfo()`, aucune clé `minValue`/`maxValue`
- `core/class/smartclimAuxHomeApi.class.php` : constantes `AMBIANTE_MIN_PLAUSIBLE` et
  `AMBIANTE_MAX_PLAUSIBLE`, et leur usage dans `etatAppareil()`

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclim.class.php`
- Specs à corriger : `.memory/specs/MVP/05-commandes-info-etat-tech.md` § Validation
- Migration de l'existant : les commandes déjà créées ne sont pas retouchées (AC7) ; il faudrait une
  routine de rattrapage explicite
- i18n : **aucune**
- Réversibilité : facile

**Traçabilité**
- Spec technique : `.memory/specs/MVP/05-commandes-info-etat-tech.md` § Validation
- Commit : `feat(MVP-05): ...` sur `master` — retrouvable par `git log --grep "feat(MVP-05)"`

### D-MVP05-06 — AC11 traité par ajout d'une phrase, sans réécrire l'aide existante

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 2 de `/feature` (emplacement de l'avertissement AC11)
- **Principes** : P1, P3, P8

**Question**
Le critère AC11 demande que la page de configuration du plugin indique explicitement que la température
ambiante AUX Home n'est pas temps réel et ne doit pas servir à une régulation fine. Le fichier
`plugin_info/configuration.txt` porte **déjà** depuis UC01, dans le fieldset « Rafraîchissement », la
phrase « La température ambiante remontée par AUX Home se rafraîchit lentement (jusqu'à environ 30
minutes) ; réduire cet intervalle n'accélère pas la donnée. » — qui couvre la lenteur mais pas
l'interdiction d'usage. Fallait-il réécrire cette phrase pour tout dire d'un coup, ou en ajouter une
seconde ?

**Décision**
Une **seconde** ligne d'aide (`help-block`) est ajoutée dans le même fieldset, sans toucher à la
première : « Cette température n'est donc pas une mesure temps réel : ne l'utilisez pas comme sonde
d'une régulation fine (thermostat). » L'édition se fait dans `plugin_info/configuration.txt`, puis
`cp plugin_info/configuration.txt plugin_info/configuration.php`. Cette décision est **complétée** par
D-MVP05-04 (`generic_type` vide sur `ambient_temp`), qui empêche techniquement l'enrôlement automatique
de la valeur comme sonde de pièce.

**Pourquoi**
P3 : réécrire la phrase existante **orphelinerait** sa clé i18n, déjà traduite dans les trois langues
cibles (`en_US`, `de_DE`, `es_ES`) — la clé d'un fichier de traduction Jeedom **est** le texte français
source, donc la modifier casse la traduction. Ajouter ne crée qu'une clé nouvelle. P1 : le critère est
satisfait à la lettre, « la page de configuration du plugin indique explicitement ».

**Alternatives écartées**
1. *Réécrire la phrase existante en une seule* — écartée pour l'orphelinage de trois traductions
   existantes ; redeviendrait le meilleur choix lors d'une refonte assumée des textes d'aide, où l'on
   accepte de repasser le `translator` sur tout le fieldset.
2. *Mettre l'avertissement dans le nom de la commande `ambient_temp`* — écartée parce qu'un nom de
   commande est repris dans les tags de scénario et doit rester court et stable ; ne redeviendrait
   jamais meilleure.
3. *Mettre l'avertissement seulement dans la documentation utilisateur `docs/`* — écartée parce
   qu'AC11 vise explicitement la page de configuration ; redeviendrait complémentaire, et non
   substituable, si la documentation était enrichie.

**Portée dans le code**
- `plugin_info/configuration.txt` : fieldset « Rafraîchissement », un `help-block` ajouté
- `plugin_info/configuration.php` : régénéré par copie, jamais édité directement
- `core/class/smartclim.class.php` : `definitionsCommandesInfo()`, `generic_type` vide sur
  `ambient_temp` — volet technique, cf. D-MVP05-04

**Coût d'un revirement**
- Fichiers à modifier : `plugin_info/configuration.txt` puis copie vers le `.php`
- Specs à corriger : `.memory/specs/MVP/05-commandes-info-etat-tech.md` § AC11 en détail
- Migration de l'existant : **aucune**
- i18n : une clé française à retirer des trois fichiers `core/i18n/*.json`
- Réversibilité : facile

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/05-commandes-info-etat.md` § critère AC11
- Commit : `feat(MVP-05): ...` sur `master` — retrouvable par `git log --grep "feat(MVP-05)"`

### D-MVP05-07 — AC10 couvert par omission de la valeur, moitié restante reportée sur UC06

- **Statut** : dette
- **Date** : 2026-08-26
- **Gate** : étape 2 de `/feature` (critère partiellement couvrable)
- **Principes** : P1, P4, P7

**Question**
Le critère AC10 demande que, pour une vitesse de ventilation « préalablement commandée » dont l'état
relu depuis le cloud ne permet pas de confirmer la valeur exacte, la commande info continue d'afficher
la **dernière vitesse effectivement commandée** plutôt qu'une valeur incohérente. Or UC05 ne commande
rien : les commandes action n'existent qu'en UC06. Fallait-il écrire dès maintenant une mémoire de
« dernière vitesse commandée », ou ne couvrir que ce qui est réalisable sans émetteur de commandes ?

**Décision**
UC05 implémente une règle unique et générale : **« valeur non confirmable, commande non touchée »**.
Concrètement, `smartclimAuxHomeApi::etatAppareil()` **omet la clé** `fan_speed` (idem `mode`) de son
tableau de retour dès que `smartclimCapabilities::depuisTransport('AUX_HOME','fan_speed', $codeFil)`
renvoie `null`, et `smartclim::appliquerEtat()` ne pousse que les clés **présentes**. La commande
conserve donc sa dernière valeur connue et son horodatage de valeur, au lieu d'afficher une valeur par
défaut ou incohérente. La même règle sert pour les trames trop courtes, l'appareil hors ligne et la
température ambiante implausible.
**Ce qui reste en dette** : le cas où le cloud renvoie un code **connu mais différent** de la vitesse
commandée, par exemple commander « Silencieux » et relire « Faible ». Le traiter exige de mémoriser la
dernière vitesse commandée avec une période de grâce, donc un émetteur de commandes, qui n'existe qu'en
UC06. **À trancher par `/change`** si l'utilisateur souhaite une couverture complète avant UC06.

**Pourquoi**
P1 : la moitié réalisable du critère est réellement implémentée, la moitié non réalisable est
journalisée au lieu d'être réinterprétée. P4 : écrire en UC05 une mémoire de commandes alors qu'aucune
commande ne peut être émise serait du code mort. P7 : le mécanisme retenu est une **absence de clé**,
sans donnée persistée ni clé de configuration — le défaire coûte une ligne.

**Alternatives écartées**
1. *Mémoriser dès UC05 une « dernière vitesse commandée » en configuration d'équipement* — écartée
   parce que rien ne peut l'alimenter avant UC06, ce serait du code mort ; redeviendrait le meilleur
   choix si UC06 était repoussée hors du MVP.
2. *Pousser malgré tout la valeur relue, même non confirmée* — écartée parce qu'elle produit exactement
   l'affichage incohérent que le critère cherche à éviter ; ne redeviendrait meilleure que si le cloud
   se révélait parfaitement fidèle en recette, point de recette 1.
3. *Utiliser la colonne `intent` de la table des vitesses pour deviner la valeur commandée* — écartée
   d'office : cette colonne est marquée `intent_confirme => false` depuis UC04, s'y fier ferait
   afficher une vitesse fausse.

**Portée dans le code**
- `core/class/smartclimAuxHomeApi.class.php` : `etatAppareil()`, omission de clé quand
  `depuisTransport()` renvoie `null`
- `core/class/smartclim.class.php` : `appliquerEtat()`, ne pousse que les clés présentes

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclim.class.php` (mémoire et période de grâce dans
  `appliquerEtat()`), `core/class/smartclimAuxHomeApi.class.php`
- Specs à corriger : `.memory/specs/MVP/05-commandes-info-etat-tech.md` § AC10 en détail, et la spec
  technique d'UC06 quand elle sera écrite
- Migration de l'existant : **aucune** tant qu'aucune donnée n'est persistée ; une clé de configuration
  d'équipement apparaîtrait avec la mémoire
- i18n : **aucune**
- Réversibilité : facile

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/05-commandes-info-etat.md` § critère AC10
- Spec technique : `.memory/specs/MVP/05-commandes-info-etat-tech.md` § AC10 en détail, risque R3
- Commit : `feat(MVP-05): ...` sur `master` — retrouvable par `git log --grep "feat(MVP-05)"`
