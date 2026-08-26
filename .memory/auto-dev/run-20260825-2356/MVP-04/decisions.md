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
