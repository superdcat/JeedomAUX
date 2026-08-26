# Recapitulatif des decisions - plugin SmartClim

> **Fichier GENERE - ne pas editer a la main.** Il est reassemble par
> `python .claude/scripts/auto-dev.py recap` a partir de
> `.memory/auto-dev/<run>/<UC>/decisions.md` et `.memory/auto-dev/revisions/*.md`.
> Toute correction se fait dans ces fichiers sources, puis on regenere.

Genere le 2026-08-27.

## A quoi sert ce fichier

`/auto-dev` enchaine des cycles `/feature` **sans intervention humaine** : a chaque
point ou le workflow aurait pose une question, l'orchestrateur a tranche seul. Ce
fichier est la **trace complete et autoportante** de ces arbitrages : question posee,
decision retenue, alternatives ecartees, portee dans le code, cout d'un revirement.

Il est concu pour etre lu **a froid, en contexte vide**, par la commande `/change` :

```
/change <explication de ce qui aurait du etre decide>
/change D-MVP04-02 <explication>       # cible une decision precise
/change --liste                        # affiche l index ci-dessous
```

`/change` retrouve la decision, charge **uniquement** les fichiers qu elle cite,
produit un plan de revirement (code + specs + migration de l existant + i18n),
l implemente, puis ajoute une **revision** ici : l'ancienne decision reste visible,
marquee comme revisee.

## Principes d'arbitrage appliques

Ordre de priorite utilise par `/auto-dev` pour trancher. Un revirement demande a
`/change` **ecrase** ces principes : c'est l'utilisateur qui arbitre en dernier.

Ces principes sont la **grille de décision** utilisée quand `/auto-dev` doit répondre seul à une
question que `/feature` aurait posée à l'utilisateur. Ils sont **ordonnés** : P1 l'emporte sur P2,
qui l'emporte sur P3, etc. Chaque décision journalisée cite les principes qui l'ont produite.

- **P1 — La spec fonctionnelle fait loi.** Les critères d'acceptation sont le contrat. On ne
  réduit pas le périmètre pour se simplifier la vie, on ne l'élargit pas parce que « ce serait
  mieux ». Un critère qu'on ne sait pas couvrir se journalise comme tel, il ne se réinterprète pas.
- **P2 — Les invariants de `CLAUDE.md` ne se négocient pas.** Autoload via
  `core/php/smartclim.inc.php`, indentation par fichier, miroir `configuration.txt` → `.php`,
  aucun secret en log/DOM, TLS toujours vérifié, aucune méta-séquence littérale, français langue
  source. Une décision qui les contredit est écartée d'office, quel que soit son avantage.
- **P3 — Cohérence avec ce qui a déjà été décidé.** Les specs techniques des UC précédentes et
  `.memory/analyse/` sont la mémoire du projet : on réutilise la convention existante (nommage,
  classement d'erreurs, clés de config, structure de retour) plutôt que d'en inventer une seconde.
  Deux conventions concurrentes coûtent plus cher que la moins jolie des deux.
- **P4 — Périmètre minimal.** On implémente l'UC courante, pas la suivante ni un domaine post-MVP.
  Pas de généralisation spéculative, pas de crochet « au cas où ». Ce qui n'est pas dans la spec
  n'est pas écrit.
- **P5 — Aucune dépendance nouvelle, aucun démon au MVP.** PHP natif (cURL, `openssl_*`,
  opérations de bits). Si une décision semble exiger un paquet ou un processus, c'est la décision
  qu'il faut changer.
- **P6 — Prudence sur la sécurité et la robustesse.** En cas de doute, l'option la plus
  conservatrice : borner les entrées, assainir avant de journaliser, `try/catch` par équipement,
  timeout/budget explicite, échec bruyant plutôt que silencieux. Jamais de contournement (TLS,
  vérification, garde-fou) pour faire passer un cas.
- **P7 — Préférer le choix le plus facile à défaire.** Une table de données se corrige, une logique
  câblée en dur se réécrit. Entre deux options équivalentes, on retient celle dont un revirement
  ne coûte qu'une valeur — c'est ce qui rend `/change` utile plutôt que théorique.
- **P8 — À égalité, le plus simple, et on journalise l'alternative.** Pas d'arbitrage à pile ou
  face silencieux : l'option écartée et la condition qui la rendrait meilleure sont écrites dans
  `decisions.md`, pour que l'utilisateur puisse la réclamer d'un `/change`.

**Deux règles de procédure qui accompagnent ces principes :**

- **Jamais d'attente utilisateur.** En mode automatique, aucune question n'est posée : elle est
  tranchée puis journalisée. Une décision journalisée est réversible, une session bloquée sur une
  question ne l'est pas.
- **Jamais de décision silencieuse.** Toute question à laquelle `/feature` attendait une réponse
  humaine produit **une entrée** dans `decisions.md`, même si la réponse semblait évidente.

## Index des decisions

| ID | Sujet | Statut | Source |
|---|---|---|---|
| `D-MVP04-01` | Ne pas interroger l'endpoint de configuration du cloud | appliqué | `.memory/auto-dev/run-20260825-2356/MVP-04/decisions.md` |
| `D-MVP04-02` | Table des vitesses : source EU retenue, écriture marquée à confirmer | appliqué | `.memory/auto-dev/run-20260825-2356/MVP-04/decisions.md` |
| `D-MVP04-03` | AC5 implémenté par « lisible sur le fil » : trois vitesses et toutes les oscillations exclues | appliqué | `.memory/auto-dev/run-20260825-2356/MVP-04/decisions.md` |
| `D-MVP04-04` | Bornes de température personnalisées dans des clés distinctes, pas un marqueur | appliqué | `.memory/auto-dev/run-20260825-2356/MVP-04/decisions.md` |
| `D-MVP04-05` | Une seule plage de température par équipement, pas de bornes par mode | appliqué | `.memory/auto-dev/run-20260825-2356/MVP-04/decisions.md` |
| `D-MVP04-06` | Profil fusionné par union, horodatage mis à jour seulement si le contenu change | appliqué | `.memory/auto-dev/run-20260825-2356/MVP-04/decisions.md` |
| `D-MVP04-07` | Affichage du profil par variable de page, sans nouvelle action AJAX | appliqué | `.memory/auto-dev/run-20260825-2356/MVP-04/decisions.md` |
| `D-MVP04-08` | Bornes corrigées et signalées côté client, jamais d'enregistrement bloqué | appliqué | `.memory/auto-dev/run-20260825-2356/MVP-04/decisions.md` |
| `D-MVP04-09` | Profil de repli partagé pour les équipements sans capacités enregistrées | appliqué | `.memory/auto-dev/run-20260825-2356/MVP-04/decisions.md` |
| `D-MVP04-10` | Fins de ligne de `smartclimAuxHomeApi.class.php` alignées sur CRLF | appliqué | `.memory/auto-dev/run-20260825-2356/MVP-04/decisions.md` |
| `D-MVP04-11` | `enveloppeTemperature()` conservée et documentée, plutôt que supprimée | appliqué | `.memory/auto-dev/run-20260825-2356/MVP-04/decisions.md` |
| `D-MVP05-01` | AC2 (commandes info d'oscillation) déclaré non applicable | dette | `.memory/auto-dev/run-20260825-2356/MVP-05/decisions.md` |
| `D-MVP05-02` | Décodage de trame gardé dans le transport, pas de classe smartclimFrame | appliqué | `.memory/auto-dev/run-20260825-2356/MVP-05/decisions.md` |
| `D-MVP05-03` | Valeurs de mode et de vitesse poussées en codes génériques, non traduits | appliqué | `.memory/auto-dev/run-20260825-2356/MVP-05/decisions.md` |
| `D-MVP05-04` | generic_type laissé vide partout sauf ONLINE | appliqué | `.memory/auto-dev/run-20260825-2356/MVP-05/decisions.md` |
| `D-MVP05-05` | Aucune borne min/max sur les commandes info numériques | appliqué | `.memory/auto-dev/run-20260825-2356/MVP-05/decisions.md` |
| `D-MVP05-06` | AC11 traité par ajout d'une phrase, sans réécrire l'aide existante | appliqué | `.memory/auto-dev/run-20260825-2356/MVP-05/decisions.md` |
| `D-MVP05-07` | AC10 couvert par omission de la valeur, moitié restante reportée sur UC06 | dette | `.memory/auto-dev/run-20260825-2356/MVP-05/decisions.md` |
| `D-MVP06-01` | AC9 fermé par un widget de plugin, pas reporté en post-MVP | appliqué | `.memory/auto-dev/run-20260825-2356/MVP-06/decisions.md` |
| `D-MVP06-02` | Échelle de température d'écriture : entier en degrés Celsius, pas de valeur multipliée par dix | appliqué | `.memory/auto-dev/run-20260825-2356/MVP-06/decisions.md` |
| `D-MVP06-03` | Allumage implicite par une seule requête à deux clés, pas deux requêtes | appliqué | `.memory/auto-dev/run-20260825-2356/MVP-06/decisions.md` |
| `D-MVP06-04` | Déduplication par empreinte du contenu de l'ordre, sans verrou par équipement | appliqué | `.memory/auto-dev/run-20260825-2356/MVP-06/decisions.md` |
| `D-MVP06-05` | Mémoire des ordres et période de grâce dans le cache, pas dans la configuration d'équipement | appliqué | `.memory/auto-dev/run-20260825-2356/MVP-06/decisions.md` |
| `D-MVP06-06` | Aucun rejeu d'authentification pendant le pilotage, seule la session est purgée | appliqué | `.memory/auto-dev/run-20260825-2356/MVP-06/decisions.md` |
| `D-MVP06-07` | Aucune commande d'oscillation, malgré la section i18n de la spec | appliqué | `.memory/auto-dev/run-20260825-2356/MVP-06/decisions.md` |
| `D-MVP06-08` | Libellés de mode alignés sur UC04, contre ceux annoncés par la spec d'UC06 | appliqué | `.memory/auto-dev/run-20260825-2356/MVP-06/decisions.md` |
| `D-MVP06-09` | Les commandes de vitesse n'allument pas l'appareil | appliqué | `.memory/auto-dev/run-20260825-2356/MVP-06/decisions.md` |
| `D-MVP06-10` | Création des commandes action câblée aussi dans `appliquerEtat()`, contre le plan initial | appliqué | `.memory/auto-dev/run-20260825-2356/MVP-06/decisions.md` |
| `D-MVP07-01` | Un seul hook cron (`cron()` chaque minute) plutôt que `cron5()` | appliqué | `.memory/auto-dev/run-20260826-1904/MVP-07/decisions.md` |
| `D-MVP07-02` | Pas de verrou de concurrence entre le cycle cron et le rafraîchissement manuel | appliqué | `.memory/auto-dev/run-20260826-1904/MVP-07/decisions.md` |
| `D-MVP07-03` | Le rafraîchissement manuel rafraîchit TOUS les équipements, pas seulement le sien | appliqué | `.memory/auto-dev/run-20260826-1904/MVP-07/decisions.md` |
| `D-MVP07-04` | Retrait du champ « Auto-actualisation » de la page équipement | appliqué | `.memory/auto-dev/run-20260826-1904/MVP-07/decisions.md` |
| `D-MVP08-01` | Rejeu re-login étendu au chemin d'écriture | appliqué | `.memory/auto-dev/run-20260826-2232/MVP-08/decisions.md` |
| `D-MVP08-02` | L'état de connexion affiché est LU, jamais stocké | appliqué | `.memory/auto-dev/run-20260826-2232/MVP-08/decisions.md` |
| `D-MVP08-03` | Format de `last_update` inchangé, âge lu sur la date de la commande | appliqué | `.memory/auto-dev/run-20260826-2232/MVP-08/decisions.md` |
| `D-MVP08-04` | Durée de vie du cache de session maintenue à 30 minutes, avec télémétrie | appliqué | `.memory/auto-dev/run-20260826-2232/MVP-08/decisions.md` |
| `D-MVP08-05` | Aucun backoff après échecs répétés | dette | `.memory/auto-dev/run-20260826-2232/MVP-08/decisions.md` |
| `D-MVP08-06` | Identifiant cloud laissé en clair dans les journaux du scan | dette | `.memory/auto-dev/run-20260826-2232/MVP-08/decisions.md` |
| `D-MVP08-07` | Trace d'exception retirée de la réponse AJAX du plugin | appliqué | `.memory/auto-dev/run-20260826-2232/MVP-08/decisions.md` |
| `D-MVP08-08` | Plan technique auto-validé sans relance du planner | appliqué | `.memory/auto-dev/run-20260826-2232/MVP-08/decisions.md` |
| `D-MVP08-09` | Finding `minor` de review reporté en dette plutôt que corrigé | dette | `.memory/auto-dev/run-20260826-2232/MVP-08/decisions.md` |

## Cycles executes

### run-20260825-2356

- Demande : `MVP 04 .. MVP 08`
- Cree le : 2026-08-25T23:56:51

| UC | Etat | Phase atteinte | Commit | Spec fonctionnelle | Spec technique |
|---|---|---|---|---|---|
| MVP/04 | termine | commit | `432432a` | `.memory/specs/MVP/04-modele-generique-et-capacites.md` | `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md` |
| MVP/05 | termine | commit | `46447a8` | `.memory/specs/MVP/05-commandes-info-etat.md` | `.memory/specs/MVP/05-commandes-info-etat-tech.md` |
| MVP/06 | en_cours | memoire | - | `.memory/specs/MVP/06-commandes-action-pilotage.md` | `.memory/specs/MVP/06-commandes-action-pilotage-tech.md` |
| MVP/07 | a_faire | - | - | `.memory/specs/MVP/07-rafraichissement-cron.md` | `.memory/specs/MVP/07-rafraichissement-cron-tech.md` |
| MVP/08 | a_faire | - | - | `.memory/specs/MVP/08-robustesse-et-etat-connexion.md` | `.memory/specs/MVP/08-robustesse-et-etat-connexion-tech.md` |

### run-20260826-1904

- Demande : `MVP 07, MVP 08`
- Cree le : 2026-08-26T19:04:13

| UC | Etat | Phase atteinte | Commit | Spec fonctionnelle | Spec technique |
|---|---|---|---|---|---|
| MVP/07 | en_cours | memoire | - | `.memory/specs/MVP/07-rafraichissement-cron.md` | `.memory/specs/MVP/07-rafraichissement-cron-tech.md` |
| MVP/08 | a_faire | - | - | `.memory/specs/MVP/08-robustesse-et-etat-connexion.md` | `.memory/specs/MVP/08-robustesse-et-etat-connexion-tech.md` |

### run-20260826-2232

- Demande : `MVP 08`
- Cree le : 2026-08-26T22:32:53

| UC | Etat | Phase atteinte | Commit | Spec fonctionnelle | Spec technique |
|---|---|---|---|---|---|
| MVP/08 | termine | commit | `5d79fff` | `.memory/specs/MVP/08-robustesse-et-etat-connexion.md` | `.memory/specs/MVP/08-robustesse-et-etat-connexion-tech.md` |

## Decisions, par UC

---

## UC MVP/04 - 04-modele-generique-et-capacites

- Cycle : `run-20260825-2356`
- Spec fonctionnelle : `.memory/specs/MVP/04-modele-generique-et-capacites.md`
- Spec technique : `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md`
- Commit : `432432a`
- Source de cette section : `.memory/auto-dev/run-20260825-2356/MVP-04/decisions.md`

# Décisions automatiques — UC MVP/04 (modèle générique et capacités)

> Run `run-20260825-2356` — cycle `/feature` déroulé sans humain dans la boucle.
> Grille d'arbitrage : `.claude/templates/principes-arbitrage.md`.
> Spec fonctionnelle : `.memory/specs/MVP/04-modele-generique-et-capacites.md`
> Spec technique : `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md`

> **Gate étape 4 (validation du plan) : auto-validée** le 2026-08-26. Conditions vérifiées : les 6
> critères d'acceptation sont couverts (AC6 partiellement, report en recette **explicitement autorisé
> par la spec fonctionnelle elle-même**), aucune question ouverte ne subsiste, le périmètre ne dépasse
> pas la spec, aucune dépendance nouvelle, aucun invariant `CLAUDE.md` enfreint. L'advisor
> (`code-reviewer` sur le plan) n'a **pas** contredit le planner : il a remonté une omission de
> spécification, tranchée en D-MVP04-09.

### D-MVP04-01 — Ne pas interroger l'endpoint de configuration du cloud

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 2 de `/feature` (point « À confirmer » n°2 de la spec fonctionnelle)
- **Principes** : P1, P4

**Question**
Le cloud AUX Home expose `GET /app/getConfig?id=deviceMutex`, censé lister les fonctions supportées.
Fallait-il l'appeler pour construire le profil de capacités de chaque climatiseur, ou se contenter des
données déjà rapportées par le scan d'UC03 (`GET /app/user_device?getStatus=1`) ?

**Décision**
`GET /app/getConfig?id=deviceMutex` n'est **pas appelé** en UC04. Le profil est dérivé uniquement de la
réponse déjà obtenue par `smartclimAuxHomeApi::listerAppareils()`, en exploitant deux champs
supplémentaires par appareil (`status.control` et `status.running`, trames HVAC hexadécimales). Aucune
requête réseau n'est ajoutée, le budget de temps global `BUDGET_SCAN` du scan est inchangé.

**Pourquoi**
`.memory/brief.md` § 2 rapporte le constat de l'utilisateur sur une requête réellement jouée : la table
renvoyée par cet endpoint est **générique** et ne dit rien de l'appareil interrogé. Un profil qui en
dériverait serait identique pour tous les climatiseurs — exactement ce que le critère AC6 cherche à
éviter — au prix d'une requête de plus et d'un schéma de réponse inconnu à analyser (P1 : la spec fait
loi ; P4 : périmètre minimal).

**Alternatives écartées**
1. *Appeler l'endpoint de configuration et intersecter avec les capacités lues sur la trame* — écartée
   parce qu'elle coûte une requête par scan pour une donnée non discriminante ; redeviendrait le meilleur
   choix si la recette montrait que la réponse **varie** selon l'appareil, ou qu'elle porte un champ de
   filtrage par modèle.
2. *Profil statique codé en dur par modèle commercial* — écartée parce que `CLAUDE.md` interdit tout
   catalogue de références commerciales ; ne redeviendrait jamais bonne.

**Portée dans le code**
- `core/class/smartclimAuxHomeApi.class.php` → `capacitesAppareil()`, `offsetsAuxHome()`

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclimAuxHomeApi.class.php` (une requête et un analyseur de
  réponse à ajouter)
- Specs à corriger : `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md` § Stratégie de détection
- Migration de l'existant : aucune — les profils déjà stockés seraient simplement enrichis au scan
  suivant par la règle d'union de D-MVP04-06
- i18n : aucune
- Réversibilité : moyenne — il faudrait aussi réexaminer le budget de temps du scan, un login enchaînant
  déjà deux requêtes

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/04-modele-generique-et-capacites.md` § À confirmer, point 2
- Spec technique : `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md` § Stratégie de détection

### D-MVP04-02 — Table des vitesses : source EU retenue, écriture marquée à confirmer

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 2 de `/feature` (point « À confirmer » n°1 de la spec fonctionnelle)
- **Principes** : P3, P7

**Question**
Deux sources publiques donnent des numérotations **incompatibles** pour le champ de vitesse de
ventilation envoyé en commande au cloud AUX Home (`wind_speed`) : l'implémentation européenne
(`GijsZwegers/com.zwegersit.auxairco`) et une implémentation du backend chinois
(`latentharbor/ha-aux-a-plus`). Une erreur ici produit un climatiseur qui « marche presque » (demander
« Automatique » réglerait « Fort »).

**Décision**
La table **européenne** est retenue : `0` = LOW, `1` = MEDIUM, `2` = HIGH, `3` = SILENT, `4` = AUTO,
`5` = TURBO, `6` = MEDIUM_LOW, `7` = MEDIUM_HIGH. Elle est stockée dans la colonne `intent` de
`smartclimCapabilities::tables()`, sous-tableau `['AUX_HOME']['fan_speed']`. Chaque entrée porte en plus
un booléen `intent_confirme`, à `false` partout **sauf** pour `TURBO`. UC04 ne consomme pas cette colonne
— elle est posée pour UC06.

**Pourquoi**
Tout le reste du contrat AUX Home déjà implémenté dans ce plugin (enveloppe de requête, en-têtes, crypto
de login d'UC02, `/app/user_device` d'UC03, table des modes) provient de cette même source européenne et
a été vérifié en conditions réelles (P3 : cohérence avec ce qui a déjà été décidé). La source chinoise
décrit un **autre backend**, dont les routes sont explicitement non transposables, et sa table écrase
deux codes distincts sur une même vitesse — signature d'une correspondance approximative. La valeur `5`
pour turbo est le seul point d'accord entre les deux, d'où le seul `intent_confirme` à `true`.

**Alternatives écartées**
1. *Table chinoise* — écartée pour les raisons ci-dessus ; redeviendrait le meilleur choix si, en
   recette, commander la valeur `4` ne réglait pas l'appareil sur « Automatique ».
2. *N'exposer aucune vitesse tant que rien n'est confirmé* — écartée parce qu'elle viderait la
   fonctionnalité de vitesse du MVP alors que la lecture, elle, est corroborée par trois sources.

**Portée dans le code**
- `core/class/smartclimCapabilities.class.php` → `tables()`, sous-tableau `['AUX_HOME']['fan_speed']`,
  colonnes `intent` et `intent_confirme`

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclimCapabilities.class.php` — **une table de données**, aucune
  ligne de logique
- Specs à corriger : `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md` § Contrats externes
- Migration de l'existant : **aucune** — les profils persistés ne contiennent que des valeurs génériques
  (`AUTO`, `LOW`…), jamais les codes du transport
- i18n : aucune
- Réversibilité : **facile** — c'est précisément pourquoi la correspondance vit dans une table (P7)

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/04-modele-generique-et-capacites.md` § À confirmer, point 1
- Spec technique : `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md` § Structure de données,
  risque R1, point de recette 6

### D-MVP04-03 — AC5 implémenté par « lisible sur le fil » : trois vitesses et toutes les oscillations exclues

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 2 de `/feature` (interprétation du critère AC5)
- **Principes** : P1, P6

**Question**
AC5 exige qu'un mode ou une vitesse « pour lesquels le plugin ne dispose pas de correspondance vérifiée »
n'apparaisse **jamais** dans le profil affiché. Il fallait définir mécaniquement ce que veut dire
« correspondance vérifiée », sans quoi chaque futur développeur en jugerait autrement.

**Décision**
Une valeur entre dans le profil **si et seulement si** sa correspondance en **lecture** (colonne `fil` de
la table, c'est-à-dire la valeur relue dans la trame HVAC) n'est pas `null`. Conséquences concrètes : les
vitesses `SILENT`, `MEDIUM_LOW` et `MEDIUM_HIGH` ont `fil` à `null` et sont donc **absentes** du profil —
il reste 5 vitesses au MVP (`AUTO`, `LOW`, `MEDIUM`, `HIGH`, `TURBO`) ; et **aucune oscillation**
(verticale ou horizontale) n'a d'entrée dans la table, l'octet 11 de la trame ne permettant pas de
distinguer les deux axes.

**Pourquoi**
C'est un fait vérifié, pas une incertitude : ces trois vitesses n'ont pas d'équivalent connu côté
lecture, et un seul bit d'oscillation est observable. Une valeur qu'on ne sait pas relire ne peut être ni
affichée juste, ni vérifiée après commande (P6 : l'option la plus conservatrice). Le mécanisme choisi n'a
besoin d'aucun drapeau de confiance supplémentaire : `null` porte la règle à lui seul, à un seul endroit
(P1 : lecture stricte du critère).

**Alternatives écartées**
1. *Inclure ces vitesses en « écriture seule »* — écartée parce qu'AC5 interdit explicitement qu'elles
   figurent au profil affiché ; redeviendrait bonne si l'utilisateur les jugeait indispensables et
   acceptait un retour d'état approximatif.
2. *Une capacité d'oscillation unique couvrant les deux axes* — écartée parce que l'écriture devrait
   choisir arbitrairement l'un des deux axes ; redeviendrait bonne si le point de recette 7 de la spec
   technique montrait deux valeurs distinctes sur l'octet 11.

**Portée dans le code**
- `core/class/smartclimCapabilities.class.php` → colonne `fil` de `tables()`, méthode `valeursLisibles()`
- `core/class/smartclimAuxHomeApi.class.php` → `capacitesAppareil()` (qui n'appelle que `valeursLisibles`)

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclimCapabilities.class.php` — renseigner la colonne `fil` et,
  pour les oscillations, ajouter les entrées correspondantes
- Specs à corriger : `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md` § Structure de données
- Migration de l'existant : aucune — la règle d'union (D-MVP04-06) ajoutera les valeurs nouvelles au scan
  suivant sans toucher aux profils existants
- i18n : **aucune** — les libellés « Silencieux », « Moyen-faible » et « Moyen-fort » sont déjà écrits
  dans la table et donc déjà traduits, précisément pour rendre ce revirement gratuit
- Réversibilité : **facile** (une valeur dans une table)
- ⚠️ **Conséquence à assumer dès maintenant** : le critère AC2 de l'UC05 (commandes info d'oscillation)
  devient **non applicable** au MVP.

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/04-modele-generique-et-capacites.md` § critère AC5
- Spec technique : `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md` § Structure de données,
  risques R2 et R5, point de recette 7

### D-MVP04-04 — Bornes de température personnalisées dans des clés distinctes, pas un marqueur

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 2 de `/feature` (mise en œuvre du critère AC3)
- **Principes** : P6, P7

**Question**
AC3 exige qu'une borne de température saisie par l'utilisateur ne soit **jamais** réécrasée par une
redétection ultérieure. Fallait-il un jeu de clés unique accompagné d'un marqueur « personnalisé », ou
deux espaces de nommage séparés ?

**Décision**
Deux espaces **disjoints** dans la configuration de l'équipement : la valeur **détectée** vit dans
`configuration.capacites['temperature']` (sous-clés `min`, `max`, `pas`) ; la valeur **personnalisée**
vit dans `configuration.temp_min`, `configuration.temp_max` et `configuration.temp_pas` (constantes
`smartclim::CLE_CONF_TEMP_MIN`, `CLE_CONF_TEMP_MAX`, `CLE_CONF_TEMP_PAS`), stockées en **chaîne**, la
chaîne vide signifiant « non personnalisé ». La détection n'écrit **que** `capacites` et ne touche jamais
`temp_*`. `smartclim::bornesTemperature()` arbitre à la lecture : personnalisé valide, sinon détecté,
sinon constantes par défaut.

**Pourquoi**
Avec des clés disjointes, **aucun chemin de code** ne peut écraser une valeur utilisateur : la garantie
est structurelle, pas disciplinaire (P6). Un jeu de clés unique plus un marqueur booléen dépendrait de la
vigilance de chaque futur chemin de détection — exactement le type d'invariant qui se dégrade avec le
temps. Le choix retenu est aussi le plus facile à défaire (P7) : les clés sont neuves, personne n'en
dépend encore.

**Alternatives écartées**
1. *Clés uniques plus marqueur booléen « bornes personnalisées »* — écartée parce qu'une seule omission
   dans un futur chemin d'écriture suffirait à perdre la saisie de l'utilisateur ; redeviendrait
   acceptable si le nombre de clés de configuration devenait un problème, ce qui n'est pas le cas.
2. *Écrire la valeur détectée dans `temp_*` à la découverte* — écartée parce qu'elle rendrait la
   personnalisation indiscernable du défaut et **gèlerait** définitivement les bornes contre toute
   redétection future.

**Portée dans le code**
- `core/class/smartclim.class.php` → constantes `CLE_CONF_TEMP_MIN`, `CLE_CONF_TEMP_MAX`,
  `CLE_CONF_TEMP_PAS`, `bornesTemperature()`, `preSave()`, `normaliserBorneTemperature()`,
  `normaliserPasTemperature()`
- `desktop/php/smartclim.php` → les 3 champs du bloc « Bornes de température personnalisées »
- `desktop/js/smartclim.js` → `saveEqLogic()`

**Coût d'un revirement**
- Fichiers à modifier : les trois ci-dessus
- Specs à corriger : `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md` § Validation
- Migration de l'existant : **aucune aujourd'hui** (clés neuves, absentes de tous les équipements créés
  par UC03) ; **elle deviendrait nécessaire** dès qu'un utilisateur aura personnalisé des bornes — il
  faudrait alors convertir `temp_min`, `temp_max` et `temp_pas` vers la structure cible
- i18n : les 3 libellés de champ (« Température minimale », « Température maximale », « Pas de réglage »)
- Réversibilité : facile tant qu'aucune personnalisation n'existe en base, moyenne ensuite

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/04-modele-generique-et-capacites.md` § critères AC2 et AC3
- Spec technique : `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md` § Validation

### D-MVP04-05 — Une seule plage de température par équipement, pas de bornes par mode

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 2 de `/feature` (point « À confirmer » n°3 de la spec fonctionnelle)
- **Principes** : P4, P1

**Question**
Certains modèles accepteraient des consignes plus basses en chauffage qu'en refroidissement. Fallait-il
indexer la plage de température par mode dès le MVP ?

**Décision**
**Non** : une seule plage `min` / `max` / `pas` par équipement, valable pour tous les modes. Valeurs par
défaut 16 à 32 °C, pas de 0,5 °C (constantes `smartclimCapabilities::TEMP_MIN_DEFAUT`, `TEMP_MAX_DEFAUT`,
`TEMP_PAS_DEFAUT`), personnalisables par équipement dans l'enveloppe 5 à 35 °C.

**Pourquoi**
Aucune source ne documente ces bornes différenciées — les deux analyses internes les marquent comme
incertaines (P1 : ce qu'on ne sait pas ne s'invente pas). Surtout, une commande de type curseur dans
Jeedom porte **une seule** valeur minimale et maximale : les faire varier par mode imposerait de réécrire
la commande à chaque changement de mode. Les bornes personnalisables couvrent déjà le cas du modèle
exotique (P4 : périmètre minimal).

**Alternatives écartées**
1. *Clé `temperature` indexée par mode* — écartée pour les raisons ci-dessus ; redeviendrait le meilleur
   choix si la recette montrait qu'en chauffage l'appareil accepte des consignes sous 16 °C **et** que
   l'utilisateur en a besoin.

**Portée dans le code**
- `core/class/smartclimCapabilities.class.php` → `bornesParDefaut()`, `enveloppeBornes()`
- `core/class/smartclim.class.php` → clé `temperature` du profil, `bornesTemperature()`

**Coût d'un revirement**
- Fichiers à modifier : les deux ci-dessus, plus tout consommateur en UC06
- Specs à corriger : `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md` § Structure de données
- Migration de l'existant : **oui** — la structure du profil persisté changerait, donc bump de
  `smartclim::VERSION_PROFIL` et lecture rétrocompatible des profils en version 1
- i18n : aucune
- Réversibilité : **coûteuse** (changement de forme du profil stocké chez l'utilisateur)

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/04-modele-generique-et-capacites.md` § À confirmer, point 3
- Spec technique : `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md` § Stratégie de détection,
  risque R6

### D-MVP04-06 — Profil fusionné par union, horodatage mis à jour seulement si le contenu change

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 2 de `/feature` (comportement d'une redétection)
- **Principes** : P3, P6

**Question**
Que fait une redétection quand elle trouve **moins** de capacités que le profil déjà enregistré — cas
réel d'un scan lancé alors que le climatiseur est hors ligne et ne renvoie pas de trame exploitable ?
Remplacer le profil, ou le fusionner ?

**Décision**
Le profil persisté est l'**union** de l'existant et du détecté (concepts, modes, vitesses), réordonnée
selon un ordre canonique. La plage de température détectée et le transport source sont, eux, remplacés.
L'écriture en base — et donc l'appel à `save()` — n'a lieu **que si** le contenu diffère, comparaison
faite en ignorant l'horodatage `detecte_le` ; cet horodatage n'est rafraîchi qu'en cas de changement réel.

**Pourquoi**
Applique la règle « un profil qui s'appauvrit ne supprime rien » de
`.memory/analyse/smartclim-modele-abstrait-capacites.md` § 4.3 (P3), ce qui protège les commandes qui
seront créées en UC05 contre une disparition provoquée par un simple scan mal tombé (P6). La comparaison
hors horodatage préserve un invariant de recette d'UC03 : un scan strictement identique ne doit émettre
aucun `save()` — qu'un horodatage rafraîchi à chaque passage aurait détruit.

**Alternatives écartées**
1. *Remplacement pur, horodatage rafraîchi à chaque scan* — plus intuitif pour l'utilisateur (« la date
   bouge quand je relance »), mais réécrit tous les équipements à chaque scan et autorise l'amputation
   d'un profil ; redeviendrait bon si l'on ajoutait une clé distincte « confirmé le », séparée de
   « détecté le ».

**Portée dans le code**
- `core/class/smartclim.class.php` → `appliquerCapacites()`, `profilVide()`, constante `VERSION_PROFIL`
- `core/class/smartclim.class.php` → branchement dans `scannerAuxHome()` (drapeau `$modifie` existant)

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclim.class.php`, une seule méthode
- Specs à corriger : `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md` § Stratégie de détection
- Migration de l'existant : aucune, mais un profil enrichi à tort ne peut être purgé qu'en supprimant et
  recréant l'équipement (risque R5 de la spec technique)
- i18n : aucune
- Réversibilité : facile

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/04-modele-generique-et-capacites.md` § critère AC3
- Spec technique : `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md` § Stratégie de détection,
  risques R3, R5 et R10

### D-MVP04-07 — Affichage du profil par variable de page, sans nouvelle action AJAX

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 2 de `/feature` (mise en œuvre du critère AC1)
- **Principes** : P2, P4, P8

**Question**
Comment amener jusqu'à la page de configuration d'un équipement un profil de capacités dont **tous les
libellés doivent être en français** (AC4) ? Par une action AJAX dédiée appelée à l'ouverture de chaque
équipement, ou par une variable injectée au rendu de la page ?

**Décision**
Par variable de page. `desktop/php/smartclim.php` appelle `smartclim::profilsAffichables($eqLogics)` —
qui renvoie des chaînes **déjà traduites côté PHP** — et les publie via `sendVarToJS`, sous le nom
`smartclimProfils`. La fonction `printEqLogic()` de `desktop/js/smartclim.js` y puise et injecte le texte
en `.text()`. Après un scan, le `success` existant fusionne les profils rafraîchis renvoyés dans la
nouvelle clé `profils` de la réponse de `scannerAuxHome()`. **`core/ajax/smartclim.ajax.php` n'est pas
modifié.**

**Pourquoi**
Garder tous les appels de traduction côté serveur satisfait AC4 sans dupliquer une table de libellés en
JavaScript — une seconde source de vérité que `CLAUDE.md` proscrit (P2). Aucun aller-retour réseau n'est
ajouté pour une donnée déjà disponible au moment du rendu, et aucun rechargement de page n'est nécessaire
après un scan (P4). L'alternative est écrite ici plutôt que tranchée en silence (P8).

**Alternatives écartées**
1. *Action AJAX dédiée appelée par `printEqLogic()`* — écartée parce qu'elle ajoute une route, une
   authentification à contrôler et un aller-retour pour rien ; redeviendrait le meilleur choix si le
   nombre d'équipements rendait la charge de la page significative, ou si le profil devenait volumineux.

**Portée dans le code**
- `desktop/php/smartclim.php` → appel `sendVarToJS` publiant `smartclimProfils`
- `core/class/smartclim.class.php` → `profilsAffichables()`, `profilAffichable()`, clé `profils` de la
  valeur de retour de `scannerAuxHome()`
- `desktop/js/smartclim.js` → `printEqLogic()`, fusion dans le `success` du scan

**Coût d'un revirement**
- Fichiers à modifier : les trois ci-dessus, plus `core/ajax/smartclim.ajax.php` (route à créer)
- Specs à corriger : `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md` § Server Actions / API
- Migration de l'existant : aucune
- i18n : aucune (les libellés resteraient produits côté PHP)
- Réversibilité : moyenne

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/04-modele-generique-et-capacites.md` § critères AC1 et AC4
- Spec technique : `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md` §§ Server vs Client,
  Server Actions / API

### D-MVP04-08 — Bornes corrigées et signalées côté client, jamais d'enregistrement bloqué

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 2 de `/feature` (stratégie de validation)
- **Principes** : P2, P6

**Question**
Que faire quand l'utilisateur saisit des bornes de température incohérentes (minimum supérieur au
maximum, valeur hors enveloppe, texte non numérique) : refuser l'enregistrement, ou corriger et prévenir ?

**Décision**
**Corriger et prévenir.** Côté client, `saveEqLogic()` normalise les trois champs (virgule vers point,
valeur non numérique vers chaîne vide, valeur hors de l'enveloppe 5-35 °C ramenée dans l'enveloppe,
minimum supérieur ou égal au maximum remettant **les deux** à vide), affiche une alerte de niveau
`warning`, puis **retourne l'objet équipement**. Côté serveur, `smartclim::preSave()` rejoue la même
normalisation, en silence, avec une entrée de log de niveau `warning`. Le pas de réglage est une liste
déroulante à trois options exactement (vide, `0.5`, `1`), ce qui supprime toute analyse syntaxique.
**Aucune exception levée nulle part sur ce chemin.**

**Pourquoi**
Le point d'accroche `preSaveEqLogic()` annoncé par le wiki Jeedom **n'existe pas** dans le core : le seul
disponible est `saveEqLogic()`, dont la valeur de retour **remplace** l'objet envoyé au serveur (vérifié
en source, `core/js/plugin.template.js`). Lever une exception depuis cette fonction produirait un échec
d'enregistrement **sans message** (P2 : contrat réel du core). Côté serveur, `preSave()` est aussi
traversé par l'enregistrement déclenché par le scan : une exception y transformerait un équipement à
configuration douteuse en erreur récurrente à chaque scan (P6).

**Alternatives écartées**
1. *Lever une exception pour bloquer l'enregistrement* — écartée pour les deux raisons ci-dessus ;
   redeviendrait envisageable si le core venait à encadrer cet appel d'une capture affichant le message.
2. *Champs de saisie de type nombre avec pas natif* — écartée parce qu'une saisie invalide fait renvoyer
   une valeur vide par le navigateur (Chrome, Firefox) : la personnalisation disparaîtrait
   silencieusement, ce qui ressemblerait à une violation d'AC3.

**Portée dans le code**
- `desktop/js/smartclim.js` → `saveEqLogic()` (⚠️ doit se terminer par le retour de l'objet équipement)
- `core/class/smartclim.class.php` → `preSave()`, `normaliserBorneTemperature()`,
  `normaliserPasTemperature()`
- `desktop/php/smartclim.php` → type des trois champs de saisie

**Coût d'un revirement**
- Fichiers à modifier : les trois ci-dessus
- Specs à corriger : `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md` § Validation
- Migration de l'existant : aucune
- i18n : le message d'alerte « Bornes de température corrigées : vérifiez les valeurs saisies »
- Réversibilité : facile

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/04-modele-generique-et-capacites.md` § critère AC3
- Spec technique : `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md` § Validation

### D-MVP04-09 — Profil de repli partagé pour les équipements sans capacités enregistrées

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 3 de `/feature` (retour de l'advisor sur le plan)
- **Principes** : P6, P3

**Question**
L'advisor a relevé que le plan ne disait **nulle part** quelle valeur prend le profil « actuel » quand
l'équipement n'en a pas encore — c'est-à-dire pour **tous** les équipements créés par l'UC03 déjà livrée,
donc au premier scan suivant le déploiement d'UC04. Fallait-il laisser l'implémenteur choisir, ou fixer
la valeur de repli dans la spec ?

**Décision**
Une méthode privée unique `smartclim::profilVide()` renvoie le profil de repli (`version` courante,
`concepts`, `modes` et `vitesses` vides, `temperature` aux bornes par défaut, `source` vide, `detecte_le`
à zéro). Elle est utilisée **à la fois** par `appliquerCapacites()` et par `profilAffichable()` dès que
`getConfiguration('capacites')` ne renvoie pas un tableau. Chaque sous-clé est lue avec un contrôle de
type avant usage, jamais par indexation directe. La spec fixe en outre l'ordre canonique de référence de
chaque champ fusionné (`conceptsConnus()` pour les concepts, `valeursLisibles()` pour les modes et les
vitesses), une valeur inconnue de cet ordre étant conservée en fin de liste.

**Pourquoi**
Sans repli explicite, l'implémentation la plus naturelle produirait soit un avertissement PHP sur clé
indéfinie, soit un comportement correct par accident, sur le chemin le plus emprunté de toute l'UC (P6).
Faire porter le repli par **une seule** méthode empêche les deux consommateurs de diverger silencieusement
sur le même cas (P3). L'ordre canonique explicite ferme le risque R10 : sans lui, deux ensembles égaux
mais ordonnés différemment déclencheraient une écriture en base à chaque scan.

**Alternatives écartées**
1. *Laisser l'implémenteur choisir son repli* — écartée parce que les deux méthodes concernées auraient
   pu diverger, et que le cas n'aurait été couvert par aucune vérification ; ne redeviendrait jamais bonne.
2. *Trier les listes par ordre alphabétique plutôt que par ordre de déclaration* — écartée parce que
   l'ordre de déclaration porte un sens pour l'affichage (du plus froid au plus chaud, du plus lent au
   plus rapide) ; redeviendrait bonne si l'affichage cessait d'utiliser directement ces listes.

**Portée dans le code**
- `core/class/smartclim.class.php` → `profilVide()`, `appliquerCapacites()`, `profilAffichable()`
- `core/class/smartclimCapabilities.class.php` → `conceptsConnus()`, `valeursLisibles()` (ordres de
  référence)

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclim.class.php`
- Specs à corriger : `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md` § Profil de repli et
  ordre canonique
- Migration de l'existant : aucune
- i18n : aucune
- Réversibilité : facile

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/04-modele-generique-et-capacites.md` § critères AC1 et AC3
- Spec technique : `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md` § Profil de repli et
  ordre canonique

### D-MVP04-10 — Fins de ligne de `smartclimAuxHomeApi.class.php` alignées sur CRLF

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 7 de `/feature` (vérification du livrable — script bloquant vs consigne de la spec)
- **Principes** : P2, P3

**Question**
La spec technique d'UC04 imposait explicitement, pour `core/class/smartclimAuxHomeApi.class.php`,
« LF SEUL (CR=0, vérifié) — édition chirurgicale, ne pas convertir en CRLF » : le fichier avait été
laissé en LF pur dans le répertoire de travail par un cycle antérieur (UC02/UC03), et le planner a
voulu éviter une réécriture massive du fichier. Mais `python .claude/scripts/verif-plugin.py`
classe « fins de ligne LF-pur » en **PROBLEME** pour un `.php`, et son code de retour 0 est une
condition de commit du mode autonome. Les deux consignes ne pouvaient pas être satisfaites en même
temps : il fallait trancher entre désobéir à la spec technique et commiter avec le script rouge.

**Décision**
Le fichier de travail `core/class/smartclimAuxHomeApi.class.php` a été converti en **CRLF**
(1001 CR / 1001 LF), comme les cinq autres fichiers du livrable. La ligne correspondante du tableau
« Architecture — fichiers » de `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md` a été
corrigée en conséquence (« 2 espaces, CRLF »).

**Pourquoi**
Le dépôt tourne avec `core.autocrlf=true` (aucun `text=auto` dans `.gitattributes`) : git stocke le
contenu en LF quel que soit l'état du répertoire de travail. Vérification faite avant/après par
`git diff --numstat`, la conversion laisse le diff versionné **strictement identique** (116 lignes
ajoutées, 3 supprimées) — elle n'ajoute donc aucun bruit au commit. La consigne du planner reposait
sur la crainte d'un diff massif, crainte factuellement infondée ici. P2 (CLAUDE.md : « fins de ligne
CRLF partout ») et P3 (cohérence avec les cinq autres fichiers) départagent sans ambiguïté.

**Alternatives écartées**
1. *Laisser le fichier en LF et commiter avec le script rouge* — écartée parce qu'elle transforme le
   seul filet de sécurité réellement en place (pas de PHP local, CI non déclenchée sur `master`) en
   alarme qu'on apprend à ignorer ; redeviendrait bonne si `verif-plugin.py` cessait de considérer
   LF comme un problème pour les `.php`.
2. *Ajouter une exception `.gitattributes` pour ce fichier* — écartée parce qu'elle grave une
   singularité permanente dans le dépôt pour un problème de répertoire de travail purement local ;
   redeviendrait bonne si un outil externe exigeait du LF sur ce fichier précis.

**Portée dans le code**
- `core/class/smartclimAuxHomeApi.class.php` → fins de ligne du fichier entier (aucun changement de
  contenu)
- `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md` → tableau « Architecture — fichiers »,
  ligne `smartclimAuxHomeApi.class.php`

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclimAuxHomeApi.class.php` (reconversion en LF)
- Specs à corriger : `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md` § Architecture —
  fichiers
- Migration de l'existant : aucune (le contenu versionné est identique dans les deux cas)
- i18n : aucune
- Réversibilité : facile — une conversion de fins de ligne, sans effet sur le contenu stocké par git

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/04-modele-generique-et-capacites.md` (aucun critère
  directement concerné — contrainte de convention projet)
- Spec technique : `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md` § Architecture —
  fichiers

### D-MVP04-11 — `enveloppeTemperature()` conservée et documentée, plutôt que supprimée

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 9 de `/feature` (traitement d'un finding de review qualité)
- **Principes** : P3, P7, P4

**Question**
La review qualité (tour 1) a relevé que `smartclim::enveloppeTemperature()` — simple délégation vers
`smartclimCapabilities::enveloppeBornes()`, déclarée `public` — n'a **aucun appelant** dans le
livrable d'UC04 : le formulaire d'équipement ne l'utilise pas, et `desktop/js/smartclim.js` duplique
délibérément les bornes d'enveloppe (5 et 35) en dur côté client. Le reviewer proposait deux issues :
retirer la méthode tant qu'aucun code ne l'appelle, ou la conserver en documentant explicitement le
consommateur futur qui la justifie.

**Décision**
La méthode est **conservée**, signature et corps inchangés
(`public static function enveloppeTemperature()` dans `core/class/smartclim.class.php`), et son
docblock est complété pour dire noir sur blanc : qu'aucun code d'UC04 ne l'appelle, que la barrière
autoritaire de validation reste `smartclim::preSave()`, que le client duplique volontairement
l'enveloppe, et qu'elle existe pour qu'UC05/UC06 disposent d'un point d'accès unique sans créer une
seconde source de vérité. C'est exactement le traitement déjà retenu dans ce même cycle pour
`smartclimCapabilities::versTransport()` et `depuisTransport()`, qui sont dans la même situation.

**Pourquoi**
P3 tranche : deux méthodes non consommées par UC04 ont déjà été conservées-et-documentées quelques
heures plus tôt dans ce cycle ; en supprimer une troisième au même titre créerait deux traitements
concurrents pour un cas identique. P4 (périmètre minimal) plaidait pour la suppression, mais il vient
après P3, et la méthode figure au plan technique validé — la retirer imposerait aussi de corriger la
spec. P7 confirme : conserver coûte quatre lignes de commentaire, supprimer coûterait une réécriture
de la spec puis une réintroduction en UC05.

**Alternatives écartées**
1. *Supprimer `enveloppeTemperature()`* — écartée parce qu'elle est déclarée au § « Signatures et
   responsabilités » de la spec technique validée et qu'UC05/UC06 la rappelleront ; redeviendrait le
   bon choix si UC05 et UC06 finissaient par lire l'enveloppe autrement (par exemple en la publiant
   via `sendVarToJS`), rendant la délégation définitivement inutile.
2. *Faire consommer la méthode dès UC04, en publiant l'enveloppe au JS via `sendVarToJS` pour
   supprimer les 5 et 35 codés en dur côté client* — écartée parce qu'elle élargit le périmètre
   d'UC04 (nouvelle donnée transmise au navigateur, nouveau chemin de rendu) pour un gain purement
   esthétique ; redeviendrait bonne si l'enveloppe devenait configurable, auquel cas la duplication
   en dur dans le JS deviendrait un vrai défaut.

**Portée dans le code**
- `core/class/smartclim.class.php` → `enveloppeTemperature()` (docblock uniquement)
- `desktop/js/smartclim.js` → `saveEqLogic()`, variables locales `enveloppeMin` / `enveloppeMax`
  (valeurs 5 et 35 dupliquées volontairement — c'est ce que le docblock documente)

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclim.class.php` (retrait de la méthode), et
  `desktop/js/smartclim.js` si l'on choisit en plus de supprimer la duplication client
- Specs à corriger : `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md` § Signatures et
  responsabilités (bloc `smartclim` modifiée)
- Migration de l'existant : aucune (méthode sans appelant, sans état persisté)
- i18n : aucune
- Réversibilité : facile — suppression d'une méthode de délégation de trois lignes

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/04-modele-generique-et-capacites.md` § critère AC3
  (bornes personnalisées)
- Spec technique : `.memory/specs/MVP/04-modele-generique-et-capacites-tech.md` § Signatures et
  responsabilités, § Validation (double barrière)

---

## UC MVP/05 - 05-commandes-info-etat

- Cycle : `run-20260825-2356`
- Spec fonctionnelle : `.memory/specs/MVP/05-commandes-info-etat.md`
- Spec technique : `.memory/specs/MVP/05-commandes-info-etat-tech.md`
- Commit : `46447a8`
- Source de cette section : `.memory/auto-dev/run-20260825-2356/MVP-05/decisions.md`

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

---

## UC MVP/06 - 06-commandes-action-pilotage

- Cycle : `run-20260825-2356`
- Spec fonctionnelle : `.memory/specs/MVP/06-commandes-action-pilotage.md`
- Spec technique : `.memory/specs/MVP/06-commandes-action-pilotage-tech.md`
- Commit : _non commite_
- Source de cette section : `.memory/auto-dev/run-20260825-2356/MVP-06/decisions.md`

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

---

## UC MVP/07 - 07-rafraichissement-cron

- Cycle : `run-20260826-1904`
- Spec fonctionnelle : `.memory/specs/MVP/07-rafraichissement-cron.md`
- Spec technique : `.memory/specs/MVP/07-rafraichissement-cron-tech.md`
- Commit : _non commite_
- Source de cette section : `.memory/auto-dev/run-20260826-1904/MVP-07/decisions.md`

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

---

## UC MVP/08 - 08-robustesse-et-etat-connexion

- Cycle : `run-20260826-2232`
- Spec fonctionnelle : `.memory/specs/MVP/08-robustesse-et-etat-connexion.md`
- Spec technique : `.memory/specs/MVP/08-robustesse-et-etat-connexion-tech.md`
- Commit : `5d79fff`
- Source de cette section : `.memory/auto-dev/run-20260826-2232/MVP-08/decisions.md`

# Décisions automatiques — UC08 du MVP (robustesse, expiration de session et diagnostic)

> Run `run-20260826-2232`. Chaque entrée est un point où `/feature` aurait demandé un arbitrage humain.
> Grille appliquée : `.claude/templates/principes-arbitrage.md`.

### D-MVP08-01 — Rejeu re-login étendu au chemin d'écriture

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 4 de `/feature` (validation du plan)
- **Principes** : P1, P3

**Question**
La reconnexion automatique après expiration du jeton de session n'existait que sur le chemin de
**lecture** du transport AUX Home (le cycle de rafraîchissement). Sur le chemin d'**écriture** (l'envoi
d'un ordre au climatiseur quand l'utilisateur appuie sur un bouton), un jeton expiré faisait échouer
l'appui : l'utilisateur devait réessayer, le second essai repartant sur une session fraîche. Fallait-il
considérer que l'AC1 (« un appel authentifié échoue pour une cause probable d'expiration de session »)
couvre l'écriture, ou s'en tenir strictement au cycle de rafraîchissement nommé par l'AC ?

**Décision**
Le rejeu est ajouté à `smartclimAuxHomeApi::appliquerOrdre()`, calqué sur celui de `listerAppareils()` :
un booléen local `$rejoue` (jamais de récursion), conditionné à `smartclimException::TYPE_AUTH` **et** à
un budget restant suffisant. Le seuil est une **constante dédiée**,
`smartclimAuxHomeApi::BUDGET_REJEU_ORDRE = 10` (secondes), et non la garde de `listerAppareils()`
(`budgetRestant >= BUDGET_LOGIN + 3`) : `BUDGET_COMMANDE` et `BUDGET_LOGIN` valent tous deux 18 s, la
garde de lecture serait donc **du code mort** sur le chemin d'écriture. Le `try` entoure **uniquement**
`requeteControle()` — un `TYPE_AUTH` levé par `session()` reste hors boucle et ne déclenche jamais de
rejeu.

**Pourquoi**
P1 : le « Comportement attendu » de la spec fonctionnelle parle d'« un appel authentifié », sans
restreindre à la lecture ; un bouton qui échoue une fois sur deux après 30 minutes d'inactivité est
exactement l'incident que l'UC prétend supprimer. P3 : le commentaire de `requeteControle()` renvoyait
déjà explicitement ce sujet à UC08 — la décision avait été prise en UC06, il ne restait qu'à l'appliquer.

**Alternatives écartées**
1. *Ne rien faire sur l'écriture, s'en tenir au cycle de lecture* — écartée parce qu'elle laisse un
   défaut visible de l'utilisateur (le premier appui perdu) alors que le mécanisme existe déjà 300 lignes
   plus haut ; redeviendrait le meilleur choix si le budget interactif ne permettait pas un rejeu sans
   dépasser une vingtaine de secondes.
2. *Réutiliser la garde de budget de `listerAppareils()` (`BUDGET_LOGIN + 3`)* — écartée parce qu'elle est
   arithmétiquement inatteignable avec `BUDGET_COMMANDE = 18` : le code serait présent mais jamais
   exécuté, ce qui est pire qu'absent (il donnerait l'illusion d'une couverture) ; redeviendrait valable
   si `BUDGET_COMMANDE` était relevé au-delà de 21 s.
3. *Rejeu illimité tant que le budget le permet* — écartée par P6 et par l'AC2 (« nombre borné de
   tentatives, jamais une rafale ») ; ne redeviendrait valable sous aucune condition connue.

**Portée dans le code**
- `core/class/smartclimAuxHomeApi.class.php` → constante `BUDGET_REJEU_ORDRE`, méthode
  `appliquerOrdre()` (boucle de rejeu autour de `requeteControle()`), commentaires de `requeteControle()`
  qui annonçaient « hors périmètre UC08 »

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclimAuxHomeApi.class.php` uniquement (retirer la boucle, retirer
  la constante)
- Specs à corriger : `.memory/specs/MVP/08-robustesse-et-etat-connexion-tech.md` § 6 et § 1 (ligne AC1)
- Migration de l'existant : **aucune** — pas de stockage, pas de clé, pas de `logicalId`
- i18n : **aucune** (le log de télémétrie est un message technique français non traduit, comme tous les
  `log::add` du dépôt)
- Réversibilité : **facile** — une boucle locale dans une seule méthode, sans état persistant

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/08-robustesse-et-etat-connexion.md` § Comportement attendu, AC1
- Spec technique : `.memory/specs/MVP/08-robustesse-et-etat-connexion-tech.md` § 6

---

### D-MVP08-02 — L'état de connexion affiché est LU, jamais stocké

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 4 de `/feature` (validation du plan)
- **Principes** : P2, P3, P4

**Question**
L'AC8 demande d'afficher, pour chaque équipement, un état de connexion explicite, le transport actif et
l'âge de la dernière donnée. Trois options : stocker cet état dans la `configuration` de l'équipement
(simple à lire, mais un `save()` par cycle), le stocker dans une entrée de cache par équipement (pas de
`save()`, mais une seconde source de vérité), ou le **dériver** à la lecture des commandes info que le
plugin pousse déjà (`online`, `transport`, `last_update`).

**Décision**
Rien n'est stocké. `smartclim::etatConnexionAffichable()` (méthode d'instance) lit les commandes info de
l'équipement en **une seule** requête `getCmd(null, null)`, filtrée sur `getType() === 'info'`, et en
dérive un tableau de chaînes **déjà traduites** : `niveau`, `etat`, `detail`, `transport`, `fraicheur`,
`derniereDonnee`, `incidentLe`. `smartclim::etatsConnexionAffichables(array $_eqLogics)` en fait
l'agrégation avec un `try/catch (Throwable)` **par équipement**, et
`desktop/php/smartclim.php` la transmet au navigateur par
`sendVarToJS('smartclimEtatsConnexion', …)`. Le JS injecte en `.text()` et ne dérive qu'une classe CSS du
champ `niveau` — il n'assemble aucun libellé.

**Pourquoi**
P2 : les deux espaces de nommage de la configuration d'équipement (`capacites` détecté, `temp_*`
personnalisé) sont disjoints par construction, et un état volatil n'a rien à faire dans l'un ni l'autre ;
y écrire imposerait de plus un `save()` à chaque cycle de cron. P3 : c'est exactement le patron de
`profilsAffichables()` / `profilAffichable()` posé en UC04, y compris le « tout le texte est traduit côté
serveur ». P4 : la donnée existe déjà, une seconde copie serait un mécanisme de plus à maintenir cohérent.

**Alternatives écartées**
1. *Stocker l'état dans `configuration` de l'équipement* — écartée par P2 (espaces de nommage disjoints)
   et par le coût d'un `save()` par équipement par cycle ; redeviendrait le meilleur choix si l'affichage
   devait survivre à une purge complète du cache Jeedom.
2. *Une entrée de cache par équipement* — écartée parce qu'elle crée une seconde source de vérité pour un
   fait déjà porté par la commande `online`, avec le risque de divergence entre la page de configuration
   et le dashboard ; redeviendrait valable si l'affichage devait porter une information que les commandes
   ne portent pas (par exemple un historique des dernières erreurs par appareil).
3. *Faire aussi mémoriser un incident par le SCAN et le TEST DE CONNEXION* (chemins interactifs) —
   écartée parce que l'erreur y est déjà affichée à l'utilisateur dans la fenêtre modale, et que la
   mémoire d'incident doit refléter la santé du **cycle automatique** ; redeviendrait le meilleur choix si
   l'utilisateur devait voir la page se colorer immédiatement après un test raté, sans attendre un cycle.

**Portée dans le code**
- `core/class/smartclim.class.php` → `etatsConnexionAffichables()`, `etatConnexionAffichable()`,
  `dureeHumaine()`
- `desktop/php/smartclim.php` → `sendVarToJS('smartclimEtatsConnexion', …)`, bloc `div_etatConnexion`,
  badge d'état sur la carte d'équipement
- `desktop/js/smartclim.js` → `printEqLogic()` (injection `.text()` et classe CSS)

**Coût d'un revirement**
- Fichiers à modifier : les 3 ci-dessus
- Specs à corriger : `.memory/specs/MVP/08-robustesse-et-etat-connexion-tech.md` § 3, § 4.1, § 4.3
- Migration de l'existant : **aucune** dans le sens choisi. Un revirement vers un état stocké en
  configuration exigerait, lui, d'écrire la clé sur tous les équipements existants au premier cycle
- i18n : les 11 littérales du § 11 de la spec technique (fichier `core/class/smartclim.class.php`) et les
  4 clés de `desktop/php/smartclim.php` disparaîtraient ou se déplaceraient
- Réversibilité : **moyenne** — le rendu HTML et le JS suivraient le déplacement, mais aucune donnée
  utilisateur n'est en jeu

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/08-robustesse-et-etat-connexion.md` AC8
- Spec technique : `.memory/specs/MVP/08-robustesse-et-etat-connexion-tech.md` § 3, § 4

---

### D-MVP08-03 — Format de `last_update` inchangé, âge lu sur la date de la commande

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 4 de `/feature` (validation du plan)
- **Principes** : P4, P6

**Question**
L'AC8 demande « l'âge de la dernière donnée reçue ». La commande info `last_update`
(`smartclim::CMD_DERNIERE_MAJ`) porte une **chaîne formatée** `date('d/m/Y H:i:s')`, pas un horodatage :
il n'existe donc pas d'âge directement calculable. Trois voies : changer le format de la commande pour un
timestamp (parsable, mais illisible sur le dashboard et migration du parc), parser la chaîne existante,
ou lire la **date de la commande** maintenue par le core.

**Décision**
La valeur de `last_update` reste **exactement** `date('d/m/Y H:i:s')` — aucune migration. L'âge est
calculé sur `$cmd->getValueDate()` de cette commande (format core `Y-m-d H:i:s`), converti par
`strtotime()` puis soustrait de `time()`. `strtotime('')` renvoyant `false`, une commande jamais poussée
retombe sur le libellé « Jamais ». Un âge **négatif** (horloge reculée) est ramené à 0, donc rendu
« à l'instant » — même prudence que `cycleEchu()` d'UC07. `getStatus('lastCommunication')` du core est
**explicitement écarté**.

**Pourquoi**
P6 : parser `d/m/Y` avec `strtotime()` serait **faux**, pas seulement fragile — la fonction désambiguïse
une date à séparateur oblique en supposant le format américain `m/d/Y`, donc `01/02/2026` serait lu
« 2 janvier » au lieu du « 1er février ». P4 : la date existe déjà en format machine, maintenue
gratuitement par le core, et `last_update` ne change jamais sans changer de valeur (les secondes en font
partie) — sa date de valeur est donc bien l'instant de la poussée. `lastCommunication` est écarté parce
que le core le rafraîchit **même** quand la seule valeur poussée est le `online = false` forcé pendant une
coupure : il afficherait « donnée reçue il y a 30 s » alors que rien n'a été reçu.

**Alternatives écartées**
1. *Changer `last_update` en timestamp* — écartée par P4 : il faudrait réécrire la valeur chez tous les
   utilisateurs, casser les scénarios qui la lisent et perdre la lisibilité sur le dashboard, pour une
   information déjà disponible ; redeviendrait le meilleur choix si un scénario utilisateur devait
   calculer un âge lui-même.
2. *Parser la chaîne `d/m/Y H:i:s`* — écartée parce que le résultat serait faux 11 mois sur 12
   (ambiguïté jour/mois) ; ne redeviendrait valable qu'avec un format à tirets, c'est-à-dire
   l'alternative 1.
3. *Utiliser `getStatus('lastCommunication')` du core* — écartée parce que `checkAndUpdateCmd()` le
   rafraîchit aussi dans sa branche « valeur inchangée » (vérifié dans la source du core) : il mesure la
   dernière **écriture tentée**, pas la dernière **donnée reçue** ; redeviendrait valable si le plugin
   cessait de pousser `online = false` pendant les coupures, ce que l'AC3 interdit.

**Portée dans le code**
- `core/class/smartclim.class.php` → `etatConnexionAffichable()` (champs `fraicheur` et
  `derniereDonnee`), `dureeHumaine()`
- **Non touché, et c'est le point de la décision** : `appliquerEtat()` et la valeur de
  `smartclim::CMD_DERNIERE_MAJ`

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclim.class.php` (`appliquerEtat()` **et**
  `etatConnexionAffichable()`)
- Specs à corriger : `.memory/specs/MVP/08-robustesse-et-etat-connexion-tech.md` § 4.2, et l'AC6 de
  `.memory/specs/MVP/05-commandes-info-etat.md` qui décrit la sémantique de `last_update`
- Migration de l'existant : **la commande `last_update` est déjà posée chez l'utilisateur** — changer son
  format demande de réécrire sa valeur, et casse tout scénario qui la compare à une chaîne
- i18n : le libellé de la commande est inchangé ; les 4 formats de durée de `dureeHumaine()` seraient à
  revoir
- Réversibilité : **coûteuse** — c'est précisément ce coût qui a motivé la décision

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/08-robustesse-et-etat-connexion.md` AC8
- Spec technique : `.memory/specs/MVP/08-robustesse-et-etat-connexion-tech.md` § 4.2

---

### D-MVP08-04 — Durée de vie du cache de session maintenue à 30 minutes, avec télémétrie

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 4 de `/feature` (validation du plan) — question ouverte laissée par UC02
- **Principes** : P6, P7, P8

**Question**
`smartclimAuxHomeApi::DUREE_CACHE_SESSION` vaut 1800 s depuis l'UC02, avec un commentaire explicite dans
le code : « pari documenté, à calibrer en UC08 ». La durée de vie réelle du jeton AUX Home n'est pas
documentée et le backend n'expose aucun jeton de rafraîchissement. Fallait-il baisser cette valeur (moins
de risque d'utiliser un jeton mort), la monter (moins de logins), ou la garder ?

**Décision**
La valeur reste **1800 s**. Ce qui change est son **statut** : avec le rejeu réactif désormais présent sur
les deux chemins (lecture depuis UC03, écriture depuis D-MVP08-01), le TTL cesse d'être un paramètre de
**correction** pour devenir un réglage d'**économie de requêtes**. Une **télémétrie** est ajoutée pour
permettre une calibration factuelle plus tard : `login()` écrit `'cree_le' => time()` dans la charge
chiffrée du cache et le renvoie ; `session()` renvoie ce `cree_le` (valeur du cache si numérique, sinon
`0` = inconnu) ; les deux branches de rejeu journalisent en `info` l'âge de la session refusée. Les 2 clés
existantes du contrat de `session()` (`jeton`, `uid`) sont inchangées, et `cree_le` est présent dans
**les deux** branches. Le commentaire « à calibrer en UC08 » est réécrit.

**Pourquoi**
P6 : baisser le TTL multiplierait les logins RSA contre un backend tiers **sans quota documenté**, pour un
gain nul puisque le rejeu couvre déjà l'expiration ; le monter transformerait l'échec en cas nominal si la
durée réelle est courte (une requête perdue **par cycle**). P8 : à égalité de risque, on garde la valeur en
place et on écrit l'alternative. P7 : c'est une constante — un revirement ne coûte qu'une valeur, ce qui
rend `/change` utile ici plutôt que théorique.

**Alternatives écartées**
1. *Baisser à 600 s (10 min)* — écartée parce qu'elle triple le nombre de logins sans rien corriger que le
   rejeu ne corrige déjà ; redeviendrait le meilleur choix si la recette montrait des lignes « rejeu après
   re-login » fréquentes avec un âge de session inférieur à 10 minutes.
2. *Monter à 3600 s (1 h) ou plus* — écartée parce que la durée réelle du jeton est inconnue : si elle est
   de 30 minutes, chaque cycle au-delà paierait un aller-retour perdu ; redeviendrait valable si la
   télémétrie montrait qu'aucun rejeu ne se déclenche jamais sur plusieurs jours.
3. *Supprimer le cache de session et se reconnecter à chaque appel* — écartée par P6 (rafale de logins
   contre un backend sans quota) ; ne redeviendrait valable que si le backend signalait un blocage lié à
   la réutilisation de jetons.

**Portée dans le code**
- `core/class/smartclimAuxHomeApi.class.php` → constante `DUREE_CACHE_SESSION` (valeur **inchangée**,
  commentaire réécrit), `login()` (clé `cree_le` dans la charge de cache et dans le retour), `session()`
  (relecture et propagation de `cree_le`), branches de rejeu de `listerAppareils()` et
  `appliquerOrdre()` (log `info` d'âge de session)

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclimAuxHomeApi.class.php` (une valeur de constante)
- Specs à corriger : `.memory/specs/MVP/08-robustesse-et-etat-connexion-tech.md` § 7, et
  `.memory/analyse/smartclim-transport-aux-home.md` § 2.3 / § 9 quand la recette aura tranché
- Migration de l'existant : **aucune** — une entrée de cache sans `cree_le` reste valide (repli `0`), et
  changer le TTL n'invalide pas les entrées déjà posées
- i18n : **aucune** (messages de log techniques)
- Réversibilité : **facile** — une valeur de constante

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/08-robustesse-et-etat-connexion.md` § À confirmer
- Spec technique : `.memory/specs/MVP/08-robustesse-et-etat-connexion-tech.md` § 7

---

### D-MVP08-05 — Aucun backoff après échecs répétés

- **Statut** : dette
- **Date** : 2026-08-26
- **Gate** : étape 4 de `/feature` (validation du plan)
- **Principes** : P1, P4

**Question**
Si le mot de passe du compte AUX Home est durablement faux (changé côté cloud, jamais corrigé dans
Jeedom), le cycle de rafraîchissement retente à chaque intervalle. Avec l'intervalle réglé au minimum
(1 minute), cela fait jusqu'à 2 tentatives de login par minute, indéfiniment, contre un backend tiers dont
aucun quota n'est documenté. Fallait-il ajouter un espacement progressif des tentatives (backoff) ?

**Décision**
**Non** — aucun backoff dans cette UC. L'AC2 exige un nombre de tentatives borné **par cycle**, ce qui est
tenu par les gardes existantes (booléen `$rejoue`, marqueur de cycle posé avant l'appel réseau, cache de
session écrit seulement en cas de succès). Aucun critère d'acceptation ne demande d'espacer les cycles
eux-mêmes. Le résiduel est **documenté comme risque** au § 12 de la spec technique et devient le candidat
`/change` de premier rang de cette UC.

**Pourquoi**
P1 : la spec fait loi, et elle borne les tentatives par cycle, pas la répétition des cycles. P4 : un
backoff est un mécanisme d'état supplémentaire (compteur d'échecs consécutifs, remise à zéro, plafond) que
rien dans l'UC ne réclame — et la piste évidente pour l'implémenter à peu de frais est justement celle qui
casse un autre AC (ci-dessous).

**Alternatives écartées**
1. *Sauter le cycle quand l'incident mémorisé est `TYPE_AUTH` et récent* — écartée parce qu'elle
   sacrifierait AC1 et AC4 : le classement d'erreur du backend étant approximatif (tout code métier
   inattendu est traité comme une expiration possible), une simple expiration de jeton mal classée
   **gèlerait le rafraîchissement** au lieu de le réparer ; redeviendrait valable si le backend exposait un
   code d'erreur distinguant « identifiants invalides » de « jeton expiré ».
2. *Backoff exponentiel avec compteur d'échecs consécutifs en cache* — écartée par P4 (hors périmètre de
   l'UC) ; redeviendrait le meilleur choix si le backend AUX signalait un blocage de compte, ou si la
   recette montrait un rejet pour excès de requêtes.

**Portée dans le code**
- **Aucune ligne écrite.** Un revirement toucherait `core/class/smartclim.class.php` →
  `cycleEchu()` et/ou `rafraichirAuxHome()`, en s'appuyant sur `incidentMemorise()` (déjà en place) et sur
  une nouvelle entrée de cache de compteur d'échecs

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclim.class.php` (`cycleEchu()`, `rafraichirAuxHome()`,
  `memoriserIncident()` pour y ajouter un compteur)
- Specs à corriger : `.memory/specs/MVP/08-robustesse-et-etat-connexion-tech.md` § 12 risque n°3, et
  l'AC2 à préciser si le comportement attendu change
- Migration de l'existant : **aucune** — une entrée de cache nouvelle, absente au départ
- i18n : **aucune** a priori (un log technique), sauf si l'état affiché doit mentionner « prochaine
  tentative dans… », ce qui ajouterait une clé
- Réversibilité : **facile** dans les deux sens — c'est un ajout, pas une modification de contrat

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/08-robustesse-et-etat-connexion.md` AC2, AC9
- Spec technique : `.memory/specs/MVP/08-robustesse-et-etat-connexion-tech.md` § 12 risque n°3

---

### D-MVP08-06 — Identifiant cloud laissé en clair dans les journaux du scan

- **Statut** : dette
- **Date** : 2026-08-26
- **Gate** : étape 4 de `/feature` (validation du plan) — issu de l'audit AC7
- **Principes** : P1, P6

**Question**
L'audit de journalisation demandé par l'AC7 a montré que l'identifiant d'appareil AUX Home
(`auxhome_device_id`) est journalisé en clair en niveau `error` sur 3 lignes du chemin de scan, et
l'adresse MAC en `warning` sur une quatrième. Ce ne sont ni des mots de passe, ni des jetons, ni des
champs chiffrés — donc hors de la liste explicite de l'AC7 — mais `smartclimDiagnostic` **masque** ces
mêmes champs dans un rapport de sonde partageable. Un utilisateur qui colle ses journaux sur un forum les
expose donc, alors que le rapport de sonde ne le ferait pas.

**Décision**
**Non corrigé dans cette UC.** L'incohérence est documentée au § 8.3 de la spec technique et journalisée
ici, avec la mention explicite « à trancher par `/change` ». Les 4 autres écarts trouvés par l'audit
(A7-1 à A7-4) sont, eux, corrigés.

**Pourquoi**
P1 : l'AC7 énumère « mot de passe, jeton complet ou champ chiffré » — l'identifiant d'appareil n'en fait
pas partie, et l'élargir serait réinterpréter le critère. P6 en sens inverse plaiderait pour masquer, mais
le masquer maintenant rendrait le diagnostic du scan **aveugle sur le seul champ qui permet de rapprocher
un appareil du cloud** — c'est-à-dire le champ le plus utile des trois lignes concernées, sur un chemin
qui n'est emprunté qu'à la demande explicite de l'utilisateur.

**Alternatives écartées**
1. *Masquer l'identifiant par jeton stable, comme le fait `smartclimDiagnostic`* — écartée parce qu'un
   jeton stable non corrélable au cloud rend le journal inutilisable pour diagnostiquer un rapprochement
   d'appareil ; redeviendrait le meilleur choix si un utilisateur signalait une fuite après publication de
   ses journaux.
2. *Tronquer l'identifiant (6 premiers caractères), comme le jeton l'est déjà* — écartée par P8 faute
   d'égalité : c'est un compromis plausible mais qui dégrade le diagnostic sans supprimer complètement la
   corrélation ; redeviendrait le meilleur choix si l'on voulait fermer le sujet à moindre coût.

**Portée dans le code**
- **Aucune ligne écrite.** Un revirement toucherait `core/class/smartclim.class.php` → les 3 `log::add`
  de niveau `error` de `scannerAuxHome()` qui concatènent l'identifiant d'appareil, et le `log::add`
  `warning` de `chercherEquipementExistant()` qui journalise la MAC inversée

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclim.class.php` (4 lignes de log)
- Specs à corriger : `.memory/specs/MVP/08-robustesse-et-etat-connexion-tech.md` § 8.3
- Migration de l'existant : **aucune** — les journaux passés ne sont pas réécrits, et ils tournent
- i18n : **aucune** (messages de log techniques)
- Réversibilité : **facile** — 4 concaténations

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/08-robustesse-et-etat-connexion.md` AC7
- Spec technique : `.memory/specs/MVP/08-robustesse-et-etat-connexion-tech.md` § 8.3

---

### D-MVP08-07 — Trace d'exception retirée de la réponse AJAX du plugin

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 4 de `/feature` (validation du plan) — issu de l'audit AC7
- **Principes** : P2, P6

**Question**
`core/ajax/smartclim.ajax.php` rattrape une `Exception` générique et répond
`ajax::error(displayException($e), $e->getCode())`. `displayException()` du core place la **trace de pile**
dans la réponse envoyée au navigateur dès que le niveau de log est assez bas. Or une `Exception` née
pendant `smartclimAuxHomeApi::executerRequete()` porterait dans sa trace la frame de cette méthode, dont
un argument est le **jeton de session** (et un autre le corps chiffré). Fallait-il conserver le confort de
diagnostic (« Show traces » en mode debug) ou fermer ce chemin ?

**Décision**
Le chemin est fermé : `ajax::error($e->getMessage(), $e->getCode())`. On conserve le **message** (donc la
diagnosticabilité, y compris pour l'exception « Aucune méthode correspondante à … » que ce fichier lève
lui-même), on perd le lien « Show traces » en mode debug pour ce seul endpoint. Les deux autres blocs de
rattrapage sont inchangés : `smartclimException` renvoie déjà son message curaté sans
`displayException()`, et le bloc `Throwable` renvoie déjà un message figé.

**Pourquoi**
P2 : « aucun secret dans le DOM » est un invariant de `CLAUDE.md`, et il ne se négocie pas contre du
confort de diagnostic — même sur une surface admin authentifiée. P6 : en cas de doute sur une fuite, on
retient l'option la plus conservatrice. Le coût est réel mais borné : le message reste, seule la trace
disparaît, et le journal du plugin continue de porter classe, message et position.

**Alternatives écartées**
1. *Garder `displayException()` en s'appuyant sur le fait que le transport recrée ses exceptions* —
   écartée parce que cette garantie ne couvre que les `smartclimException` : une `Exception` **générique**
   (ou une erreur du core) née à l'intérieur de la pile du transport n'est pas recréée et conserve ses
   frames ; redeviendrait valable si chaque point d'appel du transport était enveloppé, ce qui reviendrait
   à écrire beaucoup de code pour conserver un lien de debug.
2. *Renvoyer un message figé, comme le fait le bloc `Throwable`* — écartée parce que ce bloc rattrape
   aussi l'exception « Aucune méthode correspondante à … » levée volontairement par ce fichier : un
   message figé rendrait cette erreur de développement indiagnosticable côté navigateur ; redeviendrait
   le meilleur choix si un message d'`Exception` générique s'avérait porter, lui aussi, de la donnée
   sensible.

**Portée dans le code**
- `core/ajax/smartclim.ajax.php` → bloc `catch (Exception $e)` (⚠️ fichier indenté en **4 espaces**)

**Coût d'un revirement**
- Fichiers à modifier : `core/ajax/smartclim.ajax.php` (une ligne)
- Specs à corriger : `.memory/specs/MVP/08-robustesse-et-etat-connexion-tech.md` § 8.2 ligne A7-4
- Migration de l'existant : **aucune**
- i18n : **aucune** (le message vient de l'exception, déjà enveloppé là où il est levé)
- Réversibilité : **facile** — une ligne

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/08-robustesse-et-etat-connexion.md` AC7
- Spec technique : `.memory/specs/MVP/08-robustesse-et-etat-connexion-tech.md` § 8.2

---

### D-MVP08-08 — Plan technique auto-validé sans relance du planner

- **Statut** : appliqué
- **Date** : 2026-08-26
- **Gate** : étape 4 de `/feature` (validation du plan)
- **Principes** : P1, P2

**Question**
En mode `/auto-dev`, la gate « validation du plan technique par l'utilisateur » doit être tranchée sans
lui. Le plan produit pour cette UC était-il validable en l'état, ou fallait-il relancer le planner ?

**Décision**
Plan **auto-validé**, sans relance. Les cinq conditions d'auto-validation sont réunies : les 9 critères
d'acceptation sont couverts ou explicitement classés « couvert par l'existant — recette seule » avec le
symbole qui les tient ; aucune question ouverte n'est restée (le planner a tranché ses 5 arbitrages en
citant le principe qui départage) ; le périmètre ne dépasse pas la spec (aucune classe, aucun endpoint,
aucune clé de configuration, aucune dépendance nouvelle) ; aucun invariant de `CLAUDE.md` n'est enfreint
(autoload inchangé, indentation par fichier respectée fichier par fichier, miroir
`configuration.txt` vers `.php` explicité, traduction différée).

**Pourquoi**
P1 et P2 : la grille d'auto-validation ne laisse pas de marge d'appréciation — elle est passée ou elle ne
l'est pas. Relancer le planner sur un plan conforme consommerait un tour de raisonnement coûteux sans
produire de décision différente.

**Alternatives écartées**
1. *Relancer le planner pour lui faire produire aussi le détail du rendu HTML/CSS du badge d'état* —
   écartée parce que c'est un travail d'implémentation cadré par le patron existant
   (`div_profilCapacites`), pas un arbitrage d'architecture ; redeviendrait utile si le rendu devait
   introduire un composant d'interface nouveau.

**Portée dans le code**
- Aucune — décision de procédure. Le plan validé est
  `.memory/specs/MVP/08-robustesse-et-etat-connexion-tech.md`

**Coût d'un revirement**
- Fichiers à modifier : aucun
- Specs à corriger : aucune
- Migration de l'existant : **aucune**
- i18n : **aucune**
- Réversibilité : sans objet

**Traçabilité**
- Spec technique : `.memory/specs/MVP/08-robustesse-et-etat-connexion-tech.md`

### D-MVP08-09 — Finding `minor` de review reporté en dette plutôt que corrigé

- **Statut** : dette
- **Date** : 2026-08-27
- **Gate** : étape 9 de `/feature` (décision `fix` / `continue` après reviews croisées)
- **Principes** : P1, P8

**Question**
Le tour 1 de reviews croisées est revenu **sans aucun finding au-dessus de la gate** (sécurité : `pass`,
aucun `critical` ni `high` ; qualité : `pass`, aucun `blocker` ni `major`). Un seul finding `minor`
subsiste : la correspondance entre le champ `niveau` de l'état de connexion et la classe CSS du badge
(`label-success` / `label-warning` / `label-danger` / `label-default`) est **dupliquée à l'identique**
dans `desktop/php/smartclim.php` (tableau PHP `$sc_classesNiveau`) et dans `desktop/js/smartclim.js`
(objet `smartclimClassesNiveau`), **sans référence croisée** entre les deux copies. Fallait-il lancer un
lot de correctifs pour ce seul point, ou le reporter ?

**Décision**
**Reporté en dette**, aucun lot de correctifs envoyé, et donc **aucun tour 2 de review** (un tour 2 ne
review qu'un delta — sans correctif, il n'a rien à examiner). Le finding est consigné sous l'identifiant
**DT-1** au § 14 de `.memory/specs/MVP/08-robustesse-et-etat-connexion-tech.md`, avec la correction
attendue : un commentaire croisé dans **chacun** des deux fichiers, sur le modèle de celui qui documente
déjà l'enveloppe de température dupliquée PHP/JS. Le § 14 précise aussi la fausse bonne idée à écarter le
jour où l'on y revient — faire calculer la classe CSS côté serveur ferait entrer de la présentation dans
le contrat de données, alors que `niveau` est précisément le seul champ que le JS a le droit d'interpréter
(§ 3 de la spec technique).

**Pourquoi**
P1 : la grille ne fait corriger que ce qui est **au-dessus** de la gate, et ce finding est explicitement
sous la gate — il n'a aucun impact fonctionnel (les deux copies sont synchrones, et `niveau` est borné à
4 valeurs par `etatConnexionAffichable()`, donc la classe par défaut n'est jamais atteinte aujourd'hui).
P8 : relancer un sous-agent pour écrire **deux lignes de commentaire** coûte un contexte complet — le
rapport coût/valeur est le pire du cycle, alors que la dette écrite au § 14 conserve la totalité de
l'information utile.

**Alternatives écartées**
1. *Envoyer un lot de finition d'un seul point* — écartée parce que le coût d'un lancement d'agent est
   sans commune mesure avec deux commentaires ; redeviendrait le bon choix si un autre finding `minor`
   utile apparaissait dans le même cycle, permettant de grouper (la passe de finition n'a de sens qu'à
   partir de plusieurs points).
2. *Corriger le commentaire directement en tant qu'orchestrateur* — écartée parce que l'orchestrateur ne
   modifie pas le livrable (la séparation plan/implémentation/review perd sa valeur si celui qui juge est
   aussi celui qui écrit) ; redeviendrait acceptable pour un correctif de documentation pure hors code
   livré.
3. *Dédupliquer la correspondance en la calculant côté serveur* — écartée parce qu'elle enfreint la
   décision de contrat du § 3 (le JS ne reçoit que des chaînes traduites plus un `niveau` abstrait, jamais
   de classe CSS) ; ne redeviendrait pertinente que si la présentation du badge devenait elle-même
   configurable.

**Portée dans le code**
- `desktop/php/smartclim.php` → tableau `$sc_classesNiveau` (badge de la carte d'équipement)
- `desktop/js/smartclim.js` → objet `smartclimClassesNiveau` (utilisé par `afficherEtatConnexion()`)
- Aucun code fonctionnel à modifier : la correction attendue est **deux commentaires**.

**Coût d'un revirement**
- Fichiers à modifier : les deux ci-dessus, en commentaire uniquement
- Specs à corriger : retirer l'entrée **DT-1** du § 14 de
  `.memory/specs/MVP/08-robustesse-et-etat-connexion-tech.md`
- Migration de l'existant : **aucune**
- i18n : **aucune** (un commentaire n'est pas une chaîne UI ; attention toutefois à ne pas y écrire de
  double accolade ouvrante littérale dans `desktop/php/smartclim.php`, fichier **rendu**)
- Réversibilité : **facile** — deux lignes de commentaire, aucun comportement touché

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/08-robustesse-et-etat-connexion.md` § critère AC8
- Spec technique : `.memory/specs/MVP/08-robustesse-et-etat-connexion-tech.md` § 3 et § 14 (DT-1)
