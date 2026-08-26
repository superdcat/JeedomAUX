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
