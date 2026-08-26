# Décisions automatiques — UC07 du MVP (rafraîchissement périodique et manuel)

> Run `run-20260826-1904` · UC `MVP/07` · spec fonctionnelle
> `.memory/specs/MVP/07-rafraichissement-cron.md` · spec technique
> `.memory/specs/MVP/07-rafraichissement-cron-tech.md`
>
> Chaque entrée ci-dessous est un point où `/feature` aurait demandé un arbitrage humain et où
> `/auto-dev` a tranché seul selon `.claude/templates/principes-arbitrage.md`.

### D-MVP07-01 — Un seul hook cron (`cron()` chaque minute) plutôt que `cron5()`

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 4 de `/feature` (validation du plan) — écart avec une décision antérieure du projet
- **Principes** : P1, P3, P8

**Question**
Le plugin doit rafraîchir les climatiseurs à un intervalle réglable de **1 à 1440 minutes** (clé de
configuration plugin `refresh_interval`, posée par UC01). Jeedom offre plusieurs hooks de cron sur la
classe du plugin : `cron()` (appelé **chaque minute**), `cron5()`, `cron10()`, `cron15()`, `cron30()`,
`cronHourly()`, `cronDaily()`. Le document d'architecture interne
`.memory/analyse/smartclim-architecture-jeedom.md` § 6 prévoyait un montage à **deux points d'entrée** :
`cron5()` comme hook principal, et `cron()` en plus lorsque l'intervalle est réglé sur 1 minute.
Fallait-il suivre cette décision antérieure (P3 : cohérence avec ce qui a déjà été décidé) ou n'utiliser
qu'un seul hook ?

**Décision**
Un **seul** hook : `smartclim::cron()`, appelé chaque minute par le core, qui commence par une garde
d'échéance `smartclim::cycleEchu()` et ne fait rien si l'échéance n'est pas atteinte. `cron5()`,
`cron10()`, `cron15()`, `cron30()`, `cronHourly()` et `cronDaily()` restent des méthodes **vides** dans
`core/class/smartclim.class.php`. L'échéance est mémorisée dans le cache Jeedom sous la clé
`smartclim::dernier_cycle` (constante `smartclim::CLE_CACHE_DERNIER_CYCLE`), valeur `(string) time()`,
durée de vie `smartclim::DUREE_MEMOIRE_CYCLE` = `INTERVALLE_MAX * 60 * 2` (48 h). La condition d'échéance
est `(time() - $dernier) >= smartclim::intervalleRafraichissement() * 60 - smartclim::MARGE_ECHEANCE_CYCLE`
avec `MARGE_ECHEANCE_CYCLE = 30` secondes. Le § 6 de
`.memory/analyse/smartclim-architecture-jeedom.md` devient **faux** et est corrigé en fin de cycle.

**Pourquoi**
`cron5()` ne peut structurellement pas honorer AC8 (« intervalle réglé sur 1 minute, rafraîchissement
constaté à peu près chaque minute ») : P1 l'emporte donc sur P3, la décision antérieure ayant été prise
avant relecture d'AC8. Le montage à deux hooks aurait par ailleurs créé deux interrupteurs core
désynchronisables (`functionality::cron::enable` et `functionality::cron5::enable`, réglables
indépendamment dans l'onglet « Fonctionnalités » du plugin) et un risque de double exécution du cycle. Le
coût d'un tick non échu est d'**une lecture de cache**, sans aucune requête SQL sur les équipements.

**Alternatives écartées**
1. *`cron5()` principal plus `cron()` si intervalle = 1 min* (la décision d'origine du § 6) — écartée
   parce qu'elle multiplie les points d'entrée et les interrupteurs pour un gain nul. Redeviendrait le
   meilleur choix si le core Jeedom cessait d'appeler `cron()` chaque minute, ou si la lecture de cache
   devenait mesurablement coûteuse.
2. *Cron « avancé » Jeedom par équipement (expression cron dans la configuration de l'équipement)* —
   écartée parce qu'elle contredit AC3 : un cron par équipement produit un appel réseau par équipement,
   là où la spec exige un seul appel global. Redeviendrait pertinente si le cloud AUX Home exposait un
   jour un endpoint « état d'un seul appareil » et que l'on voulait des fréquences différentes par
   appareil.
3. *Marqueur d'échéance en configuration plugin (`config::save`)* — écartée : une écriture SQL par cycle
   et une invalidation du cache de configuration du core, pour une donnée purement volatile.
4. *Marqueur d'échéance en configuration d'équipement (`setConfiguration` puis `save`)* — écartée : le
   `save()` déclencherait `postSave()`, donc `creerCommandesInfo()` et `creerCommandesAction()`, à chaque
   cycle. C'est exactement l'arbitrage déjà pris en UC06 pour la mémoire des ordres
   (`smartclim::ordres::<id>` vit en cache pour la même raison).
5. *`valueDate` de la commande info `last_update` comme marqueur* — écartée : par contrat d'UC05, cette
   date ne bouge **que** s'il y a eu un changement d'état réel ; deux cycles sans changement la
   laisseraient figée et le cycle se relancerait chaque minute.

**Portée dans le code**
- `core/class/smartclim.class.php` → constantes `CLE_CACHE_DERNIER_CYCLE`, `DUREE_MEMOIRE_CYCLE`,
  `MARGE_ECHEANCE_CYCLE`
- `core/class/smartclim.class.php` → `cron()` (corps), `cycleEchu()`, `marquerCycle()`
- `core/class/smartclim.class.php` → `cron5()` à `cronDaily()` restent vides
- `.memory/analyse/smartclim-architecture-jeedom.md` § 6 (tableau des hooks)

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclim.class.php` (déplacer le corps de `cron()` vers `cron5()`,
  ajuster la garde d'échéance)
- Specs à corriger : `.memory/specs/MVP/07-rafraichissement-cron-tech.md` § Cadencement du cycle
- Migration de l'existant : **aucune** — la clé de cache `smartclim::dernier_cycle` expire seule ; aucun
  `logicalId`, aucune clé de configuration n'est concernée
- i18n : **aucune**
- Réversibilité : **facile** — le corps du cycle est dans une méthode dédiée (`rafraichirAuxHome()`), le
  hook n'en est qu'un appelant

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/07-rafraichissement-cron.md` § critères AC3 et AC8
- Spec technique : `.memory/specs/MVP/07-rafraichissement-cron-tech.md` § Cadencement du cycle

### D-MVP07-02 — Pas de verrou de concurrence entre le cycle cron et le rafraîchissement manuel

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 3 de `/feature` — l'advisor (`code-reviewer`) contredit le planner (`jeedom-tech-planner`)
- **Principes** : P1, P4, P6, P8

**Question**
Le cycle de rafraîchissement peut être déclenché par deux chemins : le hook `smartclim::cron()`
(processus du cron Jeedom) et la commande d'action « Rafraîchir » (`logicalId` `refresh`, processus AJAX
du navigateur). Ces deux processus sont distincts et rien ne les empêche de s'exécuter en même temps,
donc d'émettre deux `GET /app/user_device?getStatus=1` simultanés. Le planner a jugé ce chevauchement
inoffensif (les deux lectures sont idempotentes) et n'a prévu **aucun verrou**. L'advisor a jugé, en
sévérité `minor`, qu'il fallait réutiliser le patron de verrou déjà présent dans le fichier pour le scan
(`smartclim::CLE_CACHE_VERROU_SCAN` et `DUREE_VERROU_SCAN`), au motif que deux appels réseau simultanés
contredisent l'esprit d'AC3.

**Décision**
**Aucun verrou n'est posé** ; la position du planner est retenue. Le seul mécanisme de sérialisation est
celui déjà prévu par la décision « marqueur d'échéance posé **avant** l'appel réseau » :
`rafraichirAuxHome()` appelle `marquerCycle()` en étape 2 de sa séquence, c'est-à-dire avant
`smartclimAuxHomeApi::listerAppareils()`. Un rafraîchissement manuel repousse donc l'échéance du cron d'un
intervalle complet **dès son démarrage**, ce qui ferme le sens « manuel démarré, puis tick de cron » — le
seul sens réellement probable. Le sens inverse (« cron en cours, l'utilisateur clique ») reste possible et
est assumé : c'est une action volontaire de l'utilisateur.

**Pourquoi**
Le préjudice évité par un verrou est **une requête HTTP GET redondante**, en lecture seule, sur des
données identiques (`checkAndUpdateCmd()` avec les mêmes valeurs n'émet aucun événement). Le préjudice
**introduit** par un verrou global est une régression d'AC6 : un clic sur « Rafraîchir » survenant pendant
un cycle cron serait soit rejeté par une erreur, soit silencieusement avalé, alors qu'AC6 exige une « mise
à jour immédiate ». P1 (la spec fait loi) départage donc contre P6 : on ne dégrade pas un critère
d'acceptation pour économiser un GET. P4 confirme (pas de mécanisme non demandé). Le verrou de
`scannerAuxHome()` existe, lui, parce que le scan **écrit et crée des équipements** ; le cycle, lui,
n'écrit aucun `eqLogic`.

**Alternatives écartées**
1. *Verrou global partagé cron et manuel* (position de l'advisor) — écartée parce qu'il fait échouer ou
   avaler un « Rafraîchir » cliqué pendant un cycle cron, ce qui contredit AC6. Redeviendrait le meilleur
   choix si le cloud AUX Home se révélait **limité en débit** (réponse HTTP 429 ou blocage temporaire du
   compte observé en recette) : ce serait alors un vrai risque fonctionnel et non plus une requête
   redondante.
2. *Verrou asymétrique : le cron s'abstient si un rafraîchissement manuel est en vol, le manuel n'est
   jamais bloqué* — écartée parce qu'elle n'apporte rien de plus que l'ordonnancement déjà en place
   (`marquerCycle()` avant l'appel réseau ferme exactement le même sens), pour un mécanisme de plus à
   maintenir. Redeviendrait utile si le marqueur d'échéance était un jour déplacé **après** l'appel
   réseau.
3. *Réutiliser `CLE_CACHE_VERROU_SCAN`, la clé du scan elle-même* — écartée d'office : elle mêlerait deux
   opérations de natures différentes, un scan en cours empêcherait un rafraîchissement et réciproquement,
   sans qu'aucun critère ne le demande.

**Portée dans le code**
- `core/class/smartclim.class.php` → `rafraichirAuxHome()` : ordre de la séquence, `marquerCycle()` en
  étape 2, avant `smartclimAuxHomeApi::listerAppareils()`
- `core/class/smartclim.class.php` → `rafraichirMaintenant()` : appelle `rafraichirAuxHome()` sans garde
  de concurrence
- Aucune constante de verrou ajoutée : `CLE_CACHE_VERROU_SCAN` et `DUREE_VERROU_SCAN` restent réservées au
  scan d'UC03

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclim.class.php` (deux constantes et un `if` en tête de
  `rafraichirAuxHome()`, sur le modèle exact de `scannerAuxHome()`)
- Specs à corriger : `.memory/specs/MVP/07-rafraichissement-cron-tech.md` § Concurrence et verrous
- Migration de l'existant : **aucune**
- i18n : une chaîne à ajouter si le verrou doit produire un message utilisateur (« Un rafraîchissement est
  déjà en cours. »)
- Réversibilité : **facile** — moins de dix lignes, patron déjà présent dans le fichier

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/07-rafraichissement-cron.md` § critères AC3 et AC6
- Spec technique : `.memory/specs/MVP/07-rafraichissement-cron-tech.md` § Concurrence et verrous

### D-MVP07-03 — Le rafraîchissement manuel rafraîchit TOUS les équipements, pas seulement le sien

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 4 de `/feature` (validation du plan) — écart de portée avec le libellé littéral d'un critère
- **Principes** : P1, P4, P5

**Question**
Le critère AC6 dit : « Une commande "Rafraîchir" disponible sur chaque équipement force une mise à jour
immédiate de **son** état ». Le cloud AUX Home n'expose, dans ce plugin, qu'un seul endpoint de lecture :
`GET /app/user_device?getStatus=1`, qui renvoie **tous** les appareils du compte avec leur état
(implémenté par `smartclimAuxHomeApi::listerAppareils()`). Une lecture « ciblée sur un appareil » serait
donc exactement le même appel réseau, dont on jetterait les autres lignes. Fallait-il n'appliquer l'état
qu'à l'équipement cliqué (fidélité littérale à AC6) ou à tous (exploiter les données déjà payées) ?

**Décision**
La commande « Rafraîchir » (`logicalId` `refresh`, constante `smartclim::CMD_RAFRAICHIR`) appelle
`smartclim::rafraichirMaintenant()`, qui appelle `smartclim::rafraichirAuxHome()`, c'est-à-dire **le cycle
global complet** : un appel réseau, puis distribution de l'état à **tous** les équipements smartclim
activés porteurs d'une clé de configuration `auxhome_device_id`, bascule hors ligne comprise. Le
rafraîchissement manuel **ré-ancre aussi l'échéance** du cron (il passe par `marquerCycle()`), donc le
cycle automatique suivant est repoussé d'un intervalle complet.

**Pourquoi**
AC6 est satisfait « au moins » : l'équipement cliqué est bien rafraîchi immédiatement. Rafraîchir en plus
les autres ne coûte **rien** (les données sont dans la même réponse HTTP), tandis que les filtrer coûterait
du code pour jeter de l'information utile — P4 et P5 poussent vers le chemin le plus court. P1 est
respecté puisque aucun critère n'interdit de rafraîchir les autres. Conséquence à connaître pour la
recette : cliquer « Rafraîchir » sur l'équipement A met aussi à jour B et C, ce n'est pas une anomalie.

**Alternatives écartées**
1. *N'appliquer l'état qu'à l'équipement cliqué* — écartée parce qu'elle jette des données déjà obtenues
   et laisse les autres équipements affichés avec un état plus ancien que celui qu'on vient de lire.
   Redeviendrait le bon choix si le cloud exposait un endpoint « état d'un seul appareil » réellement
   moins coûteux, ou si le nombre d'équipements rendait la distribution longue.
2. *Créer un endpoint AJAX dédié dans `core/ajax/smartclim.ajax.php`* — écartée : la commande d'action
   passe par `core/ajax/cmd.ajax.php` du core, qui vérifie déjà les droits ; ajouter un endpoint serait
   une surface d'attaque de plus pour un gain nul.

**Portée dans le code**
- `core/class/smartclim.class.php` → `rafraichirMaintenant()` (méthode d'instance déléguant au cycle
  statique)
- `core/class/smartclim.class.php` → `definitionsCommandesAction()`, entrée `refresh`
- `core/class/smartclim.class.php` → `executerCommandeAction()`, branche de sortie anticipée pour `refresh`

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclim.class.php` (`rafraichirMaintenant()` doit filtrer la réponse
  sur son propre `auxhome_device_id` ; le cycle global reste nécessaire, seule la distribution change)
- Specs à corriger : `.memory/specs/MVP/07-rafraichissement-cron-tech.md` § Rafraîchissement manuel
- Migration de l'existant : **aucune** — le `logicalId` `refresh` et son libellé ne changent pas
- i18n : **aucune**
- Réversibilité : **facile**

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/07-rafraichissement-cron.md` § critères AC3 et AC6
- Spec technique : `.memory/specs/MVP/07-rafraichissement-cron-tech.md` § Rafraîchissement manuel

### D-MVP07-04 — Retrait du champ « Auto-actualisation » de la page équipement

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 4 de `/feature` (validation du plan) — dépassement de périmètre
- **Principes** : P1, P4, P6, P8

**Question**
La page des équipements `desktop/php/smartclim.php` contient, hérité du squelette de plugin Jeedom
d'origine, un bloc de formulaire « Auto-actualisation » : une case à cocher et un champ d'expression cron
liés à `configuration.autorefresh`, sous-titrés « Fréquence de rafraîchissement des commandes infos de
l'équipement ». Ce champ n'est lu par **aucun** code du plugin — son propre commentaire le dit (« La
fonction cron de la classe du plugin doit contenir le code prévu pour que ce champ soit fonctionnel »), et
`autorefresh` n'apparaît nulle part dans `core/class/smartclim.class.php`. UC07 livre le vrai mécanisme de
rafraîchissement, piloté par la clé **globale** `refresh_interval` de la configuration du plugin.
Fallait-il retirer ce champ mort (hors périmètre strict de la spec) ou le laisser en place ?

**Décision**
Le bloc est **retiré** : suppression du bloc « Auto-actualisation » de `desktop/php/smartclim.php`
(commentaires inclus), et rien d'autre dans ce fichier. Le champ `param1`, autre reliquat du squelette,
n'est **pas** touché : il est hors sujet. Aucun code ne lisant `configuration.autorefresh`, aucune
migration n'est nécessaire ; une valeur éventuellement enregistrée par un utilisateur reste dans la
configuration de l'équipement, inerte.

**Pourquoi**
Laisser, dans la même interface, un champ nommé « Fréquence de rafraîchissement » qui ne fait rien à côté
d'un intervalle global qui, lui, agit, est un mensonge d'interface — et il porte précisément sur le sujet
de cette UC. P6 (ne pas induire l'utilisateur en erreur) et P1 (l'objectif de la spec est que Jeedom se
synchronise réellement) l'emportent sur la lettre de P4 : le retrait est directement adjacent au
périmètre, pas une généralisation spéculative. Le planner et l'advisor sont d'accord sur ce point.

**Alternatives écartées**
1. *Laisser le champ en place* — écartée parce qu'elle laisse coexister deux réglages d'apparence
   équivalente dont un seul fonctionne. Redeviendrait le bon choix si un domaine post-MVP décidait
   d'implémenter un intervalle **par équipement** : le champ serait alors le support naturel, et sa
   suppression devrait être annulée.
2. *Laisser le champ et le rendre fonctionnel* (un intervalle par équipement) — écartée d'office par P4 :
   aucun critère d'acceptation ne le demande, et cela contredirait AC3 (un seul appel réseau par cycle,
   quel que soit le nombre d'équipements) puisque des fréquences différentes par équipement imposeraient
   plusieurs appels.
3. *Griser le champ avec une mention « non utilisé »* — écartée : autant de bruit visuel, sans le bénéfice
   de la clarté.

**Portée dans le code**
- `desktop/php/smartclim.php` → bloc « Auto-actualisation » supprimé ; fichier en **tabulations et CRLF**,
  à respecter
- `core/i18n/en_US.json`, `core/i18n/de_DE.json`, `core/i18n/es_ES.json` → 3 clés deviennent orphelines :
  « Auto-actualisation », « Fréquence de rafraîchissement des commandes infos de l'équipement », « Cliquer
  sur ? pour afficher l'assistant cron ». Sans effet fonctionnel

**Coût d'un revirement**
- Fichiers à modifier : `desktop/php/smartclim.php` (restaurer le bloc depuis
  `git show 042b432:desktop/php/smartclim.php`)
- Specs à corriger : `.memory/specs/MVP/07-rafraichissement-cron-tech.md` § Fichiers touchés
- Migration de l'existant : **aucune** — aucune donnée n'est lue ni écrite par ce champ
- i18n : les 3 clés ci-dessus redeviendraient utiles (elles existent déjà dans les fichiers de langue)
- Réversibilité : **facile** — un bloc HTML isolé, restaurable depuis l'historique git

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/07-rafraichissement-cron.md` § Objectif (aucun critère direct)
- Spec technique : `.memory/specs/MVP/07-rafraichissement-cron-tech.md` § Fichiers touchés
