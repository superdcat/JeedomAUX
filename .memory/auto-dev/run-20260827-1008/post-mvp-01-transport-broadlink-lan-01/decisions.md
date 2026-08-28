# Décisions automatiques — UC01 du domaine post-MVP 01 (transport Broadlink LAN)

> Run `run-20260827-1008` · UC `post-mvp/01-transport-broadlink-lan/01`
> Spec fonctionnelle : `.memory/specs/post-mvp/01-transport-broadlink-lan/01-decouverte-lan-et-session.md`
> Spec technique : `.memory/specs/post-mvp/01-transport-broadlink-lan/01-decouverte-lan-et-session-tech.md`

### D-POSTMVP0101-01 — Livrer un transport LAN non recettable faute de matériel

- **Statut** : appliqué
- **Date** : 2026-08-27
- **Gate** : étape 4 de `/feature` (validation du plan) — question ouverte n°1 du planner
- **Principes** : P1, P4

**Question**
L'analyse interne `.memory/analyse/smartclim-transport-broadlink-lan.md` porte en en-tête, depuis le
2026-08-24, la mention « **Ne fonctionne PAS sur l'appareil de validation** » : le climatiseur AUX de
l'utilisateur ignore le protocole Broadlink. Aucun des sept critères d'acceptation AC1 à AC7 de cette
UC ne peut donc être constaté sur son installation. Fallait-il livrer quand même un transport dont
personne ne peut vérifier qu'il fonctionne, le réduire à un squelette, ou suspendre le domaine entier
en attendant du matériel compatible ?

**Décision**
Livrer l'UC **intégralement**, et marquer AC1 à AC7 « **non recettés — matériel de référence
incompatible** » dans une section dédiée de la spec technique
`.memory/specs/post-mvp/01-transport-broadlink-lan/01-decouverte-lan-et-session-tech.md`.
Deux contreparties concrètes, qui sont la raison pour laquelle la décision est tenable :
1. **Instrumentation de recette** : le code journalise en `debug` la valeur observée des octets
   `0x26`-`0x27`, le `devtype` (`0x34`-`0x35`) et tout code d'erreur appareil (`0x22`-`0x23`), de sorte
   qu'un premier contact avec un appareil réellement compatible produise un diagnostic exploitable au
   lieu d'un silence.
2. **Aucun effet de bord sur le socle MVP existant** : la voie LAN ne crée, ne modifie et ne supprime
   **aucun** `eqLogic`, **aucune** commande Jeedom, et n'écrit **aucune** clé de configuration
   d'équipement de son propre chef. Un transport LAN entièrement défaillant reste donc, au pire, une
   table de scan vide et une ligne « LAN indisponible ».

**Pourquoi**
P1 : les critères d'acceptation sont le contrat, et « je ne peux pas le tester » n'est pas « je ne dois
pas l'écrire » — la spec fonctionnelle existe, elle est complète, et les trois UC suivantes du run en
dépendent directement. P4 : réduire l'UC à un squelette obligerait à la réécrire entièrement en UC02,
donc à payer deux fois le même arbitrage d'architecture. Le vrai risque n'est pas de livrer du code non
recetté, c'est de livrer du code non recetté **et muet** — d'où l'instrumentation, qui est la seule
partie de cette décision qui ne va pas de soi.

**Alternatives écartées**
1. *Suspendre le domaine post-MVP 01 jusqu'à disposer d'un appareil compatible* — écartée parce
   qu'elle bloque les UC02, 03 et 04 du run en cours et laisse le plugin mono-transport sans date ;
   redeviendrait le meilleur choix si l'utilisateur confirmait n'avoir **aucune** intention
   d'acquérir un appareil Broadlink, auquel cas tout le domaine 01 deviendrait du code mort à
   supprimer plutôt qu'à maintenir.
2. *Livrer un squelette (constantes + signatures, corps non implémentés)* — écartée parce qu'un
   squelette non exécutable ne vaut pas mieux qu'une spec technique, tout en coûtant une revue et un
   commit ; redeviendrait le meilleur choix si le contrat protocolaire était encore incertain, ce qui
   n'est pas le cas depuis D-POSTMVP0101-02.
3. *Recetter contre un simulateur écrit pour l'occasion* — écartée au titre de P4 (périmètre) : écrire
   un faux appareil Broadlink en PHP, c'est écrire une seconde implémentation du protocole, avec le
   risque classique de valider le code contre ses propres hypothèses plutôt que contre le matériel.

**Portée dans le code**
- `core/class/smartclimBroadlinkLan.class.php` → les `log::add('smartclim', 'debug', …)`
  d'instrumentation dans `interroger()`, `authentifier()` et `codeErreur()`
- `.memory/specs/post-mvp/01-transport-broadlink-lan/01-decouverte-lan-et-session-tech.md` → section
  « Recette » portant la mention « non recettés — matériel de référence incompatible »

**Coût d'un revirement**
- Fichiers à modifier : aucun si le revirement consiste simplement à recetter (le code est là) ; la
  seule action est de dérouler AC1..AC7 contre un appareil compatible et de corriger ce qui tombe.
- Specs à corriger : la section « Recette » de la spec technique, et l'en-tête de
  `.memory/analyse/smartclim-transport-broadlink-lan.md` si l'appareil de validation change.
- Migration de l'existant : **aucune**.
- i18n : **aucune**.
- Réversibilité : **facile** — c'est une décision de *statut de validation*, pas de conception. Aucun
  choix technique n'en dépend.

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/post-mvp/01-transport-broadlink-lan/01-decouverte-lan-et-session.md` § Critères d'acceptation (AC1 à AC7)
- Spec technique : `…-01-decouverte-lan-et-session-tech.md` § Recette
- Analyse : `.memory/analyse/smartclim-transport-broadlink-lan.md` en-tête et § 8

---

### D-POSTMVP0101-02 — Trancher les « À confirmer » du protocole contre python-broadlink

- **Statut** : appliqué
- **Date** : 2026-08-27
- **Gate** : étape 2 de `/feature` (questions ouvertes du planner) — les deux points « À confirmer » de la spec fonctionnelle
- **Principes** : P6, P3

**Question**
La spec fonctionnelle laisse deux points de contrat externe ouverts, et l'analyse interne
`.memory/analyse/smartclim-transport-broadlink-lan.md` § 4 et § 10 constate que ses deux sources se
contredisent :
1. jusqu'où remplir d'ASCII `'1'` (`0x31`) la charge utile du paquet d'authentification `0x65` —
   `fparrav/homebridge-aux-cloud` dit jusqu'à `0x0F`, `azadaydinli/ac_freedom` dit jusqu'à `0x12` — et
   quel « nom de terminal » envoyer (`"Test  1"` avec deux espaces, ou `"Tes  1"`) ;
2. faut-il diffuser la découverte sur les ports secondaires `15001` et `2415` en plus du port `80`
   (`ac_freedom` le fait, `fparrav` non).

**Décision**
Prendre `mjg59/python-broadlink` (branche `master`, licence **MIT**, consulté le 2026-08-27) comme
**source de vérité unique** du protocole, en lieu et place des deux sources qui divergeaient, et en
tirer :
1. **Remplissage : `0x04` à `0x13` inclus, soit 16 octets** de `0x31` — `packet[0x04:0x14] = [0x31] * 16`
   dans `broadlink/device.py::auth()`. Ce n'est **ni** `0x0F` **ni** `0x12` : les deux valeurs de
   l'analyse interne étaient toutes les deux fausses.
2. **Nom de terminal : `"Test 1"`** — 6 caractères, **un seul** espace — écrit en `0x30`-`0x35`
   (`packet[0x30:0x36] = "Test 1".encode()`).
3. **Diffusion sur le port `80` uniquement** (`const.py` → `DEFAULT_PORT = 80`,
   `DEFAULT_BCAST_ADDR = "255.255.255.255"`). Les ports `15001` et `2415` ne sont **pas** utilisés.
Constantes de crypto reprises de la même source : `INIT_KEY = 097628343fe99e23765c1513accf8b02`,
`INIT_VECT = 562e17996d093d28ddb3ba695a2e6f58` (l'octet d'index 3 de l'IV vaut bien `0x99`, pas
`0x09` — le piège que l'analyse interne signalait est confirmé). La licence MIT et son attribution
(« Copyright (c) 2014 Mike Ryan / Copyright (c) 2016 Matthew Garrett ») sont citées en commentaire en
tête de `core/class/smartclimBroadlinkLan.class.php`, comme le plugin le fait déjà pour la crypto AUX Home.

**Pourquoi**
P6 (prudence, pas de devinette) : entre deux sources qui se contredisent, on ne tire pas au sort — on
remonte à celle dont elles dérivent toutes les deux. `python-broadlink` est la base de l'intégration
Broadlink de Home Assistant, donc l'implémentation la plus exposée au matériel réel de tout
l'écosystème ; les deux sources de l'analyse interne en sont des réécritures, avec la dérive de
transcription qui va avec. P3 : le plugin cite déjà sa source protocolaire en commentaire pour AUX
Home, on applique la même discipline. Argument décisif sur le point 3 : si un appareil Broadlink
n'écoutait **que** `15001`, `python-broadlink` — donc Home Assistant — ne le trouverait jamais ;
l'hypothèse est donc réfutée par l'usage, et chaque port supplémentaire triple le trafic de diffusion
pour un gain non démontré.

**Alternatives écartées**
1. *Diffuser sur les trois ports « au cas où »* — écartée parce qu'elle triple le trafic de diffusion
   et la durée de la fenêtre d'écoute sans preuve qu'un climatiseur en ait besoin ; redeviendrait le
   meilleur choix si une recette sur appareil compatible montrait une découverte vide sur le port 80
   seul. C'est le **premier levier** à actionner dans ce cas, et il est documenté comme tel dans la
   section « Risques » de la spec technique.
2. *Remplir jusqu'à `0x0F` (retenir `fparrav`)* — écartée parce que c'est une réécriture et non la
   source ; redeviendrait le meilleur choix si un appareil refusait l'authentification avec le
   remplissage de 16 octets. À noter : les trois variantes partagent `0x04`-`0x0F`, le désaccord ne
   porte que sur 3 octets d'une zone de nom que l'appareil n'interprète pas — l'impact réel est
   probablement nul dans les deux sens.

**Portée dans le code**
- `core/class/smartclimBroadlinkLan.class.php` → `const PORT`, `const ADRESSE_DIFFUSION`,
  `const INIT_KEY`, `const INIT_VECT`, `authentifier()` (construction de la charge `0x50`),
  `decouvrir()` (port de diffusion), et le bloc de commentaire d'attribution MIT en tête de fichier

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclimBroadlinkLan.class.php` uniquement — ce sont des
  constantes et deux lignes de construction de charge utile.
- Specs à corriger : `…-01-decouverte-lan-et-session-tech.md` § Contrats externes, et
  `.memory/analyse/smartclim-transport-broadlink-lan.md` § 4 et § 10 (dont les cases « à confirmer »
  sont fermées par cette décision).
- Migration de l'existant : **aucune** — rien n'est persisté qui dépende de ces valeurs. Une session
  LAN déjà en cache serait au pire invalidée, ce que le TTL de 30 min fait de toute façon.
- i18n : **aucune**.
- Réversibilité : **facile** — trois constantes et une boucle de remplissage.

**Traçabilité**
- Spec fonctionnelle : `…-01-decouverte-lan-et-session.md` § À confirmer (les deux points)
- Spec technique : `…-01-decouverte-lan-et-session-tech.md` § Contrats externes
- Analyse à mettre à jour : `.memory/analyse/smartclim-transport-broadlink-lan.md` § 1, § 4, § 6, § 10

---

### D-POSTMVP0101-03 — Diffusion via ext-sockets, dégradation documentée si absente

- **Statut** : appliqué
- **Date** : 2026-08-27
- **Gate** : étape 3 de `/feature` (contradiction advisor / planner) — finding `blocker` F1
- **Principes** : P3, P6, P5

**Question**
Le plan technique proposait de diffuser la requête de découverte UDP avec
`stream_socket_server('udp://0.0.0.0:0', ..., STREAM_SERVER_BIND, $ctx)` et une option de contexte
`socket.so_broadcast`, en présentant ce chemin comme « PHP natif, aucune extension ». L'advisor a
contesté l'existence même de cette option de contexte, et rappelé que `python-broadlink` positionne
`SO_BROADCAST` par un `setsockopt()` explicite — un `sendto()` vers une adresse de diffusion échouant
en `EACCES` sans lui. Si l'advisor a raison, l'extension PHP `sockets` (qui n'est **pas** dans le
cœur du langage) devient le seul chemin possible, et AC1 — le critère phare de l'UC — échoue sur tout
hôte où elle est absente. Ni `WebFetch` (désactivé pour la session **et** les sous-agents) ni un test
empirique (`php` n'est pas installé sur la machine de développement) ne permettaient de trancher le
fait lui-même.

**Décision**
Trois volets.

1. **Chemin de diffusion principal** : `socket_create(AF_INET, SOCK_DGRAM, SOL_UDP)` +
   `socket_set_option($s, SOL_SOCKET, SO_BROADCAST, 1)` + `socket_sendto()` + `socket_select()`,
   sous garde `function_exists('socket_create')`. C'est **exactement** ce que
   `.memory/analyse/smartclim-transport-broadlink-lan.md` § 9 (lignes 200-201) affecte déjà à la
   diffusion depuis le cadrage du 2026-08-24.
2. **Chemin de diffusion secondaire**, tenté uniquement si `socket_create` est indisponible :
   `stream_socket_server('udp://0.0.0.0:0', $errno, $errstr, STREAM_SERVER_BIND, $contexte)` avec
   l'option de contexte `array('socket' => array('so_broadcast' => true))`. S'il échoue, on ne
   réessaie pas.
3. **Dégradation documentée si les deux échouent** — et c'est le cœur de la décision : la découverte
   automatique devient indisponible, **mais rien d'autre**. `interroger()` (sonde unicast d'une IP
   connue ou saisie) utilise `stream_socket_client('udp://<ip>:80')`, qui appartient au **cœur de
   PHP** et ne dépend d'aucune extension. Donc **AC1 seul tombe** ; AC3 (saisie manuelle IP/MAC),
   AC4, AC6 et AC7 restent tenus.
   Message utilisateur, en français, poussé dans le **résumé du scan** (pas seulement dans les logs) :
   « Découverte automatique indisponible sur cet hôte (extension PHP « sockets » absente) —
   renseignez l'adresse IP locale de chaque climatiseur. »
   Journalisation associée : `warning`, jamais `error` (AC4).

**Aucune déclaration de dépendance.** `hasDependency` reste `false` et `plugin_info/packages.json`
reste vide : `packages.json` ne gère officiellement que `pip3` (cf. `CLAUDE.md` § Démon &
dépendances), et une extension PHP n'est pas un paquet pip. `plugin_info/info.json` → `"require": "4.2"`
est la version minimale du **core Jeedom**, pas une liste d'extensions. L'exigence se traite donc
**exclusivement** par détection au runtime avec dégradation, ce qui est de toute façon le bon
comportement : un plugin ne doit pas devenir non installable pour une fonction facultative.

**Pourquoi**
P3 est décisif : l'analyse interne avait **déjà** tranché ce point au cadrage, et le plan s'en écartait
sans le dire. On ne crée pas une seconde convention quand la première est écrite et cohérente avec la
référence canonique. P6 explique pourquoi on garde malgré tout le second chemin : AC1 n'étant pas
recettable (D-POSTMVP0101-01), on ne peut pas se permettre un unique chemin non vérifié — deux chemins
coûtent une méthode privée et doublent les chances que la découverte fonctionne réellement chez un
utilisateur. P5 impose la dégradation plutôt que la dépendance : aucune extension ne devient
obligatoire.

**Alternatives écartées**
1. *Chemin unique `stream_socket_server` + `so_broadcast` (le plan initial)* — écartée parce qu'elle
   contredit l'analyse interne § 9 et repose sur une option de contexte dont l'existence n'a pu être
   confirmée par aucune source accessible ; redeviendrait le meilleur choix s'il était établi que
   cette option existe **et** que `ext-sockets` est fréquemment absente des installations Jeedom.
2. *Chemin unique `socket_*`, sans repli* — écartée au titre de P6 : c'est le choix le plus simple,
   mais il fait dépendre le critère phare d'une extension unique sans filet, sur du code non
   recettable ; redeviendrait le meilleur choix si la recette montrait que `ext-sockets` est
   systématiquement présente, auquel cas le second chemin serait du code mort à supprimer.
3. *Exiger `ext-sockets` et refuser d'installer/activer le plugin sans elle* — écartée : le transport
   LAN est **facultatif** (le socle MVP est cloud), rendre tout le plugin indisponible pour une
   fonction post-MVP serait une régression pour tous les utilisateurs cloud.

**Portée dans le code**
- `core/class/smartclimBroadlinkLan.class.php` → `decouvrir()`, `diffuserParExtensionSockets()`,
  `diffuserParFluxNatif()`, `diffusionDisponible()`, et `interroger()` (qui reste sur
  `stream_socket_client` et n'est **pas** concerné)
- `core/class/smartclim.class.php` → `scannerReseauLocal()` (branche « diffusion indisponible ») et
  `resumeScanLan()` (le message utilisateur ci-dessus)
- `plugin_info/info.json`, `plugin_info/packages.json` → **volontairement non modifiés**

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclimBroadlinkLan.class.php` (supprimer l'un des deux chemins,
  ou en changer l'ordre) et `core/class/smartclim.class.php` pour le message.
- Specs à corriger : `…-01-decouverte-lan-et-session-tech.md` § Dépendances et § Architecture ; et
  `.memory/analyse/smartclim-transport-broadlink-lan.md` § 9 si le fait sur `so_broadcast` est un jour
  établi dans un sens ou dans l'autre.
- Migration de l'existant : **aucune**.
- i18n : 1 clé française à retirer si la dégradation disparaît (« Découverte automatique indisponible
  sur cet hôte… »).
- Réversibilité : **facile** — deux méthodes privées et un ordre d'appel ; aucune donnée persistée
  n'en dépend.

**Traçabilité**
- Spec fonctionnelle : `…-01-decouverte-lan-et-session.md` § Critères d'acceptation (AC1, AC3)
- Spec technique : `…-01-decouverte-lan-et-session-tech.md` § Dépendances
- Analyse : `.memory/analyse/smartclim-transport-broadlink-lan.md` § 9

---

### D-POSTMVP0101-04 — Budget LAN à arrêt dur, et coût par appareil borné

- **Statut** : appliqué
- **Date** : 2026-08-27
- **Gate** : étape 3 de `/feature` (findings advisor F2 et F3)
- **Principes** : P6, P2

**Question**
Le plan bornait la phase LAN du scan par un budget global `BUDGET_LAN = 18 s`, en donnant à chaque
échange `max(1, min(TIMEOUT_ECHANGE, budget_restant))`. L'advisor a montré que ce n'est **pas** une
borne : le plancher à 1 s fait que la boucle continue même budget épuisé, et l'attente de verrou
(`ATTENTE_VERROU`, jusqu'à 2 s par appareil) s'y **ajoute** sans être déduite. Sur un parc de plusieurs
équipements, le dépassement se cumule appareil par appareil. Le « pire cas de 43 s » qui justifiait de
porter le délai jQuery à 60 s n'était donc qu'une espérance. Fallait-il accepter un budget indicatif,
ou imposer un arrêt dur ?

**Décision**
Budget **strict**, par trois mesures cumulatives.

1. **Arrêt dur évalué avant chaque appareil**, dans les deux phases de
   `smartclim::scannerReseauLocal()` : sortie de boucle dès que le temps écoulé depuis l'entrée
   atteint `BUDGET_LAN`. Les appareils non sondés sont comptés dans un compteur `non_sondes` et
   **apparaissent dans le résumé** du scan — jamais d'omission silencieuse.
2. **`ATTENTE_VERROU` borné par le budget restant** et non constant : l'attente effective est le
   minimum des deux. Un verrou qu'on n'a pas le temps d'attendre donne immédiatement
   `STATUT_OCCUPE`, ce qui est le comportement correct — l'appareil est sollicité par ailleurs, ce
   n'est pas une panne.
3. **Le budget est passé en paramètre**, jamais relu depuis une constante au fond de la pile : les
   signatures `ouvrirSession(array $_appareil, $_budget)` et `interroger($_ip, $_budget)` reçoivent le
   **reste** du budget global, exactement comme `smartclimAuxHomeApi::login($_budget)` le fait déjà.

**Correctif joint (finding F3)** : `jeedom::getTmpFolder('smartclim')` est résolu **une seule fois par
processus**, mémoïsé dans une propriété statique privée `$dossierVerrous` de `smartclimBroadlinkLan`,
au lieu d'être rappelé pour chaque appareil. Motif : le plan lui-même signalait que cet appel exécute
un `chown` via `com_shell::execute` au premier appel ; s'appuyer sur le fait que le core mémoïse est un
détail d'implémentation non contractuel, susceptible de changer d'une version de Jeedom à l'autre.

**Pourquoi**
P6 : un budget exprimé en total qui ne borne pas le total n'est pas un budget, c'est un commentaire. Le
précédent est documenté dans le dépôt même — `.memory/analyse/smartclim-transport-aux-home.md` § 8.3
explique que les délais par requête ne suffisent pas à tenir une exigence exprimée en budget global,
et c'est exactement la même erreur qui se reproduisait ici sur les sockets. P2 : la robustesse des
handlers AJAX est un invariant du projet.

**Alternatives écartées**
1. *Accepter un budget indicatif et porter le délai jQuery à 90 s* — écartée parce qu'elle déplace le
   symptôme sans corriger la cause, et parce qu'un délai jQuery n'interrompt pas le PHP : le processus
   continuerait à tourner après que le navigateur a renoncé ; redeviendrait le meilleur choix si
   l'arrêt dur coupait des scans légitimes sur de très grands parcs, ce qui se corrigerait plutôt en
   augmentant `BUDGET_LAN`.
2. *Supprimer le plancher à 1 s au lieu d'ajouter un arrêt dur* — écartée parce qu'un délai de 0 s sur
   un socket UDP n'a pas de sémantique utile et produirait des non-réponses artificielles imputées à
   l'appareil.

**Portée dans le code**
- `core/class/smartclim.class.php` → `scannerReseauLocal()` (les deux sorties de boucle),
  `resumeScanLan()` (compteur `non_sondes`), `const BUDGET_LAN`
- `core/class/smartclimBroadlinkLan.class.php` → `ouvrirSession()`, `interroger()`, `verrou()`,
  `$dossierVerrous` (statique), `const ATTENTE_VERROU`
- `desktop/js/smartclim.js` → `timeout: 60000`

**Coût d'un revirement**
- Fichiers à modifier : les deux classes ci-dessus ; le revirement le plus probable n'est pas de
  retirer l'arrêt dur mais d'ajuster `BUDGET_LAN` — une constante.
- Specs à corriger : `…-01-decouverte-lan-et-session-tech.md` § Budgets.
- Migration de l'existant : **aucune**.
- i18n : 1 clé si le compteur `non_sondes` est retiré du résumé.
- Réversibilité : **facile** — une constante et deux conditions.

**Traçabilité**
- Spec technique : `…-01-decouverte-lan-et-session-tech.md` § Budgets et § Concurrence
- Précédent : `.memory/analyse/smartclim-transport-aux-home.md` § 8.3

---

### D-POSTMVP0101-05 — MAC divergente : statut dédié, jamais d'adoption silencieuse

- **Statut** : appliqué
- **Date** : 2026-08-27
- **Gate** : étape 3 de `/feature` (finding advisor F4)
- **Principes** : P6, P1

**Question**
Quand la sonde unicast d'une IP **saisie à la main** obtient une réponse dont la MAC ne correspond pas
à celle de l'équipement visé — ni en ordre direct, ni en ordre inversé — que faire ? Cas réel et banal :
l'administrateur s'est trompé d'adresse et pointe un **autre** appareil Broadlink de son réseau. Le
plan prévoyait un simple avertissement dans les logs mais ne disait pas sous quelle clé la mémoire de
sonde était écrite, ce qui laissait deux comportements possibles et tous deux mauvais : écrire sous la
MAC de l'appareil réellement joint (entrée **orpheline**, l'équipement visé restant affiché « Jamais
détecté » alors qu'une réponse est bien arrivée à son IP), ou écrire sous la MAC de l'équipement visé
(**rapprochement erroné et silencieux** : le plugin adopte un appareil qui n'est pas le bon).

**Décision**
Un **septième statut**, `smartclimBroadlinkLan::STATUT_MAC_DIVERGENTE`, et une règle explicite :

- l'appareil répondant n'est **jamais** adopté — aucune session n'est ouverte avec lui, aucun de ses
  champs (IP, nom, `type_appareil`) n'est retenu ;
- `smartclim::memoriserSondeLan()` écrit **sous la MAC de l'équipement visé**, avec
  `statut = STATUT_MAC_DIVERGENTE`, `ip` vide (donc `adresseLan()` ne pourra jamais en dériver une
  adresse « détectée ») et `echec_le` horodaté. Écrire sous la MAC visée est ce qui rend le problème
  **visible là où l'utilisateur le cherchera** : sur la fiche de son équipement ;
- `etatConnexionAffichable()` restitue le libellé français « Adresse locale incohérente : l'appareil
  joint n'est pas celui attendu » ;
- la MAC de l'appareil réellement joint est journalisée en `warning` (ce n'est pas un secret), mais
  **aucune** entrée de mémoire de sonde n'est créée pour lui : il n'a pas été découvert par diffusion,
  il n'a donc aucune raison d'apparaître dans le tableau de scan.

**Pourquoi**
P6 : entre une entrée invisible et une adoption fausse, la bonne réponse n'est ni l'une ni l'autre —
c'est un état explicite et affiché. L'adoption erronée serait le pire des trois : le plugin piloterait
un appareil qui n'est pas le bon sans que rien ne le signale, et les UC02/UC03 construiraient dessus.
P1 : AC4 exige qu'une anomalie de joignabilité se traduise par un **statut** et non par une erreur —
`STATUT_MAC_DIVERGENTE` est précisément une valeur de plus dans une énumération qui existe déjà, pas un
mécanisme nouveau.

**Alternatives écartées**
1. *Adopter l'appareil répondant (faire primer la MAC annoncée par l'appareil)* — écartée parce que
   c'est un rapprochement d'équipement, donc UC04, et parce qu'elle transforme une faute de frappe en
   pilotage silencieux du mauvais matériel ; redeviendrait le meilleur choix si la MAC saisie devenait
   purement informative, ce que la spec ne demande pas.
2. *Écrire l'entrée sous la MAC de l'appareil joint et laisser l'équipement visé « Jamais détecté »* —
   écartée parce que l'utilisateur ne dispose alors d'aucun indice reliant sa saisie au silence de
   l'équipement.
3. *Se contenter de l'avertissement du plan initial* — écartée parce qu'un `warning` dans les logs d'un
   plugin Jeedom n'est, en pratique, jamais lu par l'utilisateur qui vient de faire la faute de frappe.

**Portée dans le code**
- `core/class/smartclimBroadlinkLan.class.php` → `const STATUT_MAC_DIVERGENTE`, `interroger()`
  (comparaison de MAC), `ouvrirSession()` (refus d'ouvrir)
- `core/class/smartclim.class.php` → `libelleStatutLan()` (libellé français), `memoriserSondeLan()`
  (clé = MAC visée, `ip` vide), `scannerReseauLocal()` (phase 2), `etatConnexionAffichable()`

**Coût d'un revirement**
- Fichiers à modifier : les deux classes ci-dessus.
- Specs à corriger : `…-01-decouverte-lan-et-session-tech.md` § Validation.
- Migration de l'existant : purger les entrées de cache `smartclim::lan_appareil::*` portant ce statut,
  ou attendre l'expiration `DUREE_MEMOIRE_LAN` — le cache étant périssable, aucune action obligatoire.
- i18n : 1 clé française (« Adresse locale incohérente : l'appareil joint n'est pas celui attendu »).
- Réversibilité : **facile** — une valeur d'énumération et une branche.

**Traçabilité**
- Spec fonctionnelle : `…-01-decouverte-lan-et-session.md` § Critères d'acceptation (AC3, AC4)
- Spec technique : `…-01-decouverte-lan-et-session-tech.md` § Validation

---

### D-POSTMVP0101-06 — AC5 validable en deux temps, réserve inscrite dans la spec fonctionnelle

- **Statut** : appliqué
- **Date** : 2026-08-27
- **Gate** : étape 3 de `/feature` (finding advisor F5) + étape 2 (question ouverte du planner sur `requete()`)
- **Principes** : P1, P4

**Question**
AC5 exige que, après une coupure prolongée de session, « **la commande suivante aboutit** ». Or aucune
commande n'existe avant UC02 (lecture) et UC03 (écriture) de ce même domaine : la méthode qui portera
la réauthentification réactive, `smartclimBroadlinkLan::requete()`, n'a **aucun appelant** en UC01 —
l'écrire ici produirait du code mort. AC2 porte explicitement la réserve « couvertes par les UC
suivantes », mais AC5 **ne la porte pas**. Un recetteur qui suit la spec au pied de la lettre cochera
donc AC5 « non satisfait » sur une UC qui n'a jamais eu les moyens de le satisfaire.

**Décision**
Deux volets.

1. **`requete()` n'est pas implémentée en UC01** ; en revanche son **contrat** est figé dans la spec
   technique § « Réservé à UC02 » : point unique portant la réauthentification, **un seul rejeu** par
   appel (booléen local, jamais de récursion — même convention que le re-login réactif d'UC02 du MVP),
   déclenchée par le silence ou par les codes appareil `-7` (*control key is expired*), `-4012`
   (*device control ID error*), `-1` ; réinitialisation clé par défaut + identifiant nul + compteur à
   zéro avant de ré-authentifier. UC01 livre les briques que ce rejeu consommera :
   `purgerSession()`, l'empreinte d'invalidation, et le passage du budget en paramètre.
2. **La spec fonctionnelle est amendée** : AC5 reçoit la même réserve qu'AC2, sous la forme d'une
   mention explicite « *(la réauthentification est **posée** par l'UC01 — contrat, déclencheurs et
   purge de session — et devient **observable** en UC02/UC03, quand une commande existe)* ». Le
   critère n'est ni supprimé ni affaibli : il est rendu **vérifiable au bon moment**.

**Pourquoi**
P1 : la spec fonctionnelle fait loi, donc un critère qu'on ne sait pas couvrir « se journalise comme
tel, il ne se réinterprète pas ». Le corriger dans la spec est plus honnête que de le laisser
faussement ouvert, et bien plus honnête que de le cocher. P4 : écrire `requete()` sans appelant serait
du périmètre pris à UC02, avec le risque que son contrat soit figé par une intuition d'UC01 plutôt que
par le besoin réel de la lecture d'état.

**Alternatives écartées**
1. *Implémenter `requete()` en UC01 pour « couvrir » AC5* — écartée : code mort, non testable, et
   contrat fixé sans son appelant ; redeviendrait le meilleur choix si UC02 était fusionnée dans cette
   UC, ce que le découpage du domaine exclut.
2. *Laisser la spec fonctionnelle inchangée et ne consigner la réserve que dans la spec technique* —
   écartée parce que le recetteur lit la spec **fonctionnelle** : une réserve invisible là où le
   critère est écrit ne sert à rien ; redeviendrait acceptable si le projet interdisait d'amender une
   spec fonctionnelle après coup, ce qui n'est pas le cas.
3. *Supprimer AC5 de l'UC01 et le déplacer dans UC02* — écartée parce qu'AC5 porte aussi sur des
   éléments réellement livrés ici (purge, empreinte, déclencheurs) : le déplacer entièrement ferait
   perdre la trace de ce qui a été posé.

**Portée dans le code**
- `core/class/smartclimBroadlinkLan.class.php` → `purgerSession()`, l'empreinte
  `sha1(mac|ip|port|devtype)` dans `authentifier()`, et le bloc de commentaire figeant le contrat de
  `requete()` (aucun corps)
- `.memory/specs/post-mvp/01-transport-broadlink-lan/01-decouverte-lan-et-session.md` → AC5 amendé
- `.memory/specs/post-mvp/01-transport-broadlink-lan/01-decouverte-lan-et-session-tech.md` → § « Réservé à UC02 »

**Coût d'un revirement**
- Fichiers à modifier : la spec fonctionnelle (retirer la réserve) et, si l'on voulait couvrir AC5 ici,
  `smartclimBroadlinkLan` pour y écrire `requete()` **et** au moins un appelant.
- Specs à corriger : les deux specs de cette UC, et la spec fonctionnelle d'UC02 si le contrat de
  `requete()` y était déplacé.
- Migration de l'existant : **aucune**.
- i18n : **aucune**.
- Réversibilité : **facile** côté spec ; **moyenne** côté code, parce qu'implémenter `requete()`
  suppose de savoir ce qu'elle transporte — donc d'avoir UC02.

**Traçabilité**
- Spec fonctionnelle : `…-01-decouverte-lan-et-session.md` § Critères d'acceptation (AC2, AC5)
- Spec technique : `…-01-decouverte-lan-et-session-tech.md` § Réservé à UC02

---

### D-POSTMVP0101-07 — Session LAN en cache Jeedom chiffré, sérialisée par flock

- **Statut** : appliqué
- **Date** : 2026-08-27
- **Gate** : étape 4 de `/feature` (validation du plan)
- **Principes** : P6, P3, P5

**Question**
Où vivent l'identifiant et la clé de session LAN, et comment garantir qu'une seule session est active à
la fois par appareil (AC6) alors que deux processus PHP distincts peuvent solliciter le même
climatiseur (deux handlers AJAX, ou un handler et un tick de cron) ?

**Décision**
- **Stockage** : classe `cache` de Jeedom, clé `smartclim::session_lan::<mac>`
  (`smartclimBroadlinkLan::CLE_CACHE_SESSION`), durée `DUREE_SESSION = 1800` s, contenu
  `utils::encrypt(json_encode(array(...)))` portant `id`, `cle`, `compteur`, `ip`, `port`, `devtype`,
  `cree_le` et une **empreinte `sha1(mac|ip|port|devtype)`**. Chiffré parce qu'il contient la clé de
  session. Empreinte divergente à la relecture ⇒ entrée ignorée et ré-authentification : c'est ce qui
  fait qu'un **changement d'IP par DHCP invalide la session tout seul**, sans hook de purge sur
  `lan_ip`. Même schéma que la session AUX Home (`CLE_CACHE_SESSION`, 30 min, `utils::encrypt`,
  empreinte) — P3.
- **Sérialisation** : `flock(LOCK_EX|LOCK_NB)` sur un fichier par appareil dans
  `jeedom::getTmpFolder('smartclim')`, nommé `lan-<sha1(mac)>.lock`, en boucle `usleep(50 ms)` bornée
  (cf. D-POSTMVP0101-04). Le verrou est pris **avant** la lecture du cache et couvre tout le cycle
  « lire session → authentifier si besoin → écrire session ».
- **Aucune sortie de secret** : `ouvrirSession()` renvoie un `STATUT_*`, jamais l'identifiant ni la clé.
  Ces deux valeurs ne sont jamais journalisées (au plus la longueur de la clé).

**Pourquoi**
Le cache est le **seul magasin partagé** disponible sans démon : une session « en mémoire du process »
rendrait AC6 structurellement inatteignable (deux processus ne partagent aucune mémoire) et
re-négocierait une session à chaque appel — or authentifier **invalide la session précédente** sur
l'appareil, donc deux processus se décrocheraient mutuellement en boucle. `flock()` plutôt qu'un verrou
en cache parce que `cache::byKey()` + `cache::set()` ne sont **pas atomiques** — le dépôt le documente
déjà pour `CLE_CACHE_VERROU_SCAN`, présenté comme « une atténuation, jamais un mutex » — et parce que
l'OS relâche un `flock` à la mort du processus : aucun verrou orphelin, aucune durée de vie à choisir.
L'ordre verrou-puis-cache est ce qui évite le TOCTOU entre deux authentifications concurrentes ; il a
été explicitement confirmé par l'advisor.

**Alternatives écartées**
1. *Session en mémoire du process (propriété statique)* — écartée : AC6 inatteignable, et
   décrochages mutuels entre processus ; redeviendrait le meilleur choix le jour où le plugin
   acquiert un démon persistant (domaine post-MVP 05), qui est précisément le seul gain que
   `.memory/analyse/smartclim-daemon-choix.md` reconnaît au démon pour ce transport.
2. *Verrou en cache Jeedom au lieu de `flock`* — écartée pour non-atomicité ; redeviendrait le meilleur
   choix si Jeedom exposait un jour un verrou atomique, ou si le dossier temporaire s'avérait
   indisponible.
3. *Cache de session non chiffré* — écartée d'office : la clé de session est un secret (P2/P6).

**Portée dans le code**
- `core/class/smartclimBroadlinkLan.class.php` → `const CLE_CACHE_SESSION`, `const DUREE_SESSION`,
  `const ATTENTE_VERROU`, `ouvrirSession()`, `authentifier()`, `purgerSession()`, `verrou()`,
  `libererVerrou()`, `$dossierVerrous`

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclimBroadlinkLan.class.php` seulement.
- Specs à corriger : `…-01-decouverte-lan-et-session-tech.md` § Concurrence et § Session.
- Migration de l'existant : purger les clés de cache `smartclim::session_lan::*` (ou attendre 30 min).
  Un changement de **format** du tableau mis en cache doit ajouter un contrôle de forme à la relecture,
  sans quoi une entrée de l'ancien format serait relue de travers — l'empreinte ne couvre pas le format.
- i18n : **aucune**.
- Réversibilité : **moyenne** — le stockage est facile à changer, mais le passage à un verrou non
  atomique reprendrait le risque de double session que cette décision élimine.

**Traçabilité**
- Spec fonctionnelle : `…-01-decouverte-lan-et-session.md` § Comportement attendu (« une seule session locale à la fois »), AC6
- Spec technique : `…-01-decouverte-lan-et-session-tech.md` § Concurrence
- Précédent : `.memory/specs/MVP/02-client-aux-home-authentification-tech.md` § 207-211 (empreinte de session)

---

### D-POSTMVP0101-08 — Adresse détectée en cache, adresse saisie en configuration d'équipement

- **Statut** : appliqué
- **Date** : 2026-08-27
- **Gate** : étape 4 de `/feature` (validation du plan)
- **Principes** : P2, P7

**Question**
`CLAUDE.md` impose que les espaces de nommage « détecté » et « personnalisé par l'utilisateur » soient
disjoints, pour qu'une redétection n'écrase jamais une personnalisation (précédent : `capacites` vs
`temp_min`/`temp_max`/`temp_pas`). Où ranger alors l'IP et la MAC LAN, qui existent dans les **deux**
variantes — détectée par diffusion, ou saisie à la main dans le cas d'un réseau segmenté ?

**Décision**
Séparation **par support**, et non par convention de nommage :

- **personnalisé** → configuration d'équipement, clés `lan_ip` et `lan_mac`
  (`smartclim::CLE_CONF_LAN_IP` / `CLE_CONF_LAN_MAC`), chaîne vide = « non personnalisé » ;
- **détecté** → cache, clé `smartclim::lan_appareil::<mac>` (`smartclim::CLE_CACHE_LAN`), durée
  `DUREE_MEMOIRE_LAN`.

Règle de lecture unique portée par `smartclim::adresseLan()`, calquée sur `bornesTemperature()` :
`lan_ip` personnalisée (revalidée à la lecture) → sinon IP mémorisée en cache pour la MAC de
l'équipement, **essayée aussi en MAC inversée** → sinon vide. Retour
`{ip, mac, port, source: 'manuel'|'detecte'|'aucun'}`. Aucun code n'écrit jamais une valeur détectée
dans `lan_ip`/`lan_mac`, ni une valeur saisie dans le cache.

**Pourquoi**
La séparation de support est **plus forte** que la séparation de nommage : il n'existe
matériellement aucun chemin par lequel un scan pourrait écraser une saisie, alors qu'avec deux clés de
configuration voisines (`lan_ip` / `lan_ip_detectee`) l'erreur reste possible d'une ligne de code.
Elle est aussi sémantiquement juste : une IP détectée est **périssable et re-dérivable** par une
nouvelle diffusion (bail DHCP), une IP saisie est **durable et non re-dérivable** (VLAN où la diffusion
n'arrive jamais). P7 enfin : le cache n'a aucune migration, une clé de configuration en aurait une.
Le test de la MAC inversée est indispensable et non cosmétique — sans lui, AC4 échouerait silencieusement
sur les appareils dont le cloud et le LAN annoncent la MAC en ordre d'octets opposé, cas explicitement
documenté par `.memory/analyse/smartclim-transport-broadlink-lan.md` § 6.

**Limite assumée, transmise à UC02** : le cache est périssable (bouton « vider le cache », redémarrage,
éviction). Contrairement à la session cloud dont un défaut de cache se répare seul via `login()`, une
purge laisse l'équipement en « Jamais détecté sur le réseau local » jusqu'au prochain clic sur
« Scanner » — il n'y a **volontairement** aucun cron LAN en UC01. UC02 devra donc retenter une
découverte à la volée quand `adresseLan()` renvoie `source: 'aucun'`, sans quoi AC2 (« sans que
l'utilisateur ait saisi la moindre information ») ne tiendra pas. Ce point est inscrit en « Risques
transmis à UC02 » dans la spec technique.

**Alternatives écartées**
1. *`lan_ip_detectee` en configuration d'équipement* — écartée parce qu'elle exigerait le
   rapprochement par MAC (donc UC04) pour savoir sur quel équipement écrire, imposerait un `save()` par
   scan, et n'aurait de toute façon nulle part où ranger l'adresse d'un appareil détecté **sans**
   équipement Jeedom ; redeviendrait le meilleur choix si la durabilité de l'adresse détectée devenait
   un besoin, c'est-à-dire précisément si la limite ci-dessus se révélait gênante en recette.
2. *Une seule clé `lan_ip`, écrite par le scan et par l'utilisateur* — écartée d'office : viole
   l'invariant de disjonction de `CLAUDE.md` (P2), une redétection écraserait la saisie VLAN qui est
   le mode de secours de la spec.

**Portée dans le code**
- `core/class/smartclim.class.php` → `const CLE_CONF_LAN_IP`, `CLE_CONF_LAN_MAC`, `CLE_CACHE_LAN`,
  `DUREE_MEMOIRE_LAN` ; `adresseLan()`, `macEquipement()`, `memoriserSondeLan()`,
  `sondeLanMemorisee()`, `normaliserIpV4()`, `preSave()`
- `desktop/php/smartclim.php` → les 2 champs `data-l2key="lan_ip"` / `"lan_mac"`
- `desktop/js/smartclim.js` → normalisation d'aide à la saisie dans `saveEqLogic()`

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclim.class.php` (surtout `adresseLan()`), plus le formulaire
  si les clés changent de nom.
- Specs à corriger : `…-01-decouverte-lan-et-session-tech.md` § Où vivent l'IP et la MAC.
- Migration de l'existant : **réelle si les clés de configuration sont renommées** — `lan_ip` et
  `lan_mac` sont saisies par l'utilisateur, donc déjà en base chez lui : un renommage exige un script
  de reprise dans `smartclim_update()`. Le passage du cache vers la configuration exigerait en plus
  d'attendre un scan pour repeupler.
- i18n : les libellés « Adresse IP locale » / « Adresse MAC locale » si les champs changent.
- Réversibilité : **moyenne** — le cache est jetable, mais `lan_ip`/`lan_mac` sont de la donnée
  utilisateur : tout renommage se paie en migration.

**Traçabilité**
- Spec fonctionnelle : `…-01-decouverte-lan-et-session.md` § Comportement attendu (déclaration manuelle), AC3, AC4
- Spec technique : `…-01-decouverte-lan-et-session-tech.md` § Où vivent l'IP et la MAC
- Invariant : `CLAUDE.md` § Configuration & secrets (disjonction détecté / personnalisé)

---

### D-POSTMVP0101-09 — Statut LAN : colonne de scan pour AC1, mémoire de sonde pour AC4

- **Statut** : appliqué
- **Date** : 2026-08-27
- **Gate** : étape 4 de `/feature` (validation du plan)
- **Principes** : P1, P4, P7

**Question**
Sous quelle forme exposer « LAN disponible / LAN indisponible » ? Quatre supports possibles : une
commande info Jeedom, une clé de configuration d'équipement, une valeur de cache, ou une simple colonne
du résultat de scan.

**Décision**
AC1 et AC4 ne demandent **pas** la même chose, et reçoivent donc deux supports distincts :

- **AC1** parle d'un **appareil détecté**, qui peut n'avoir aucun équipement Jeedom (le rapprochement
  est UC04) ⇒ **colonne du tableau de résultat de scan**, transitoire, indexée par MAC, produite par
  `smartclim::ligneResultatLan()` et rendue dans la table `#table_scanLan` ;
- **AC4** parle d'un **équipement** ⇒ **valeur de cache** (la mémoire de sonde,
  `smartclim::CLE_CACHE_LAN`), restituée par `etatConnexionAffichable()` sous deux clés additives `lan`
  et `lanAdresse`, dans le bloc « État de connexion » qui existe déjà.

Ni commande info, ni clé de configuration.

**Pourquoi**
Une **commande info** serait le pire choix : elle apparaîtrait au dashboard et serait automatiquement
enrôlée par les scénarios et les intégrations tierces, alors qu'elle n'a aucun sens tant que le domaine
post-MVP 02 n'a pas décidé **quand** utiliser le transport LAN — on exposerait un état interne comme un
état fonctionnel (P4). Une **clé de configuration** serait fausse sémantiquement (un statut observé
n'est pas de la configuration), déclencherait un `save()` par équipement à chaque scan, et violerait la
disjonction détecté/personnalisé de D-POSTMVP0101-08. Le cache est jetable, donc P7 : un revirement ne
laisse rien derrière lui. Enfin P1 : lire AC1 et AC4 comme une seule exigence aurait conduit à créer un
support unique inadapté à l'un des deux.

**Alternatives écartées**
1. *Commande info `lan_available` par équipement* — écartée pour l'enrôlement automatique décrit
   ci-dessus ; redeviendrait le meilleur choix au domaine post-MVP 02, quand le transport actif devient
   une information fonctionnelle que l'utilisateur a de bonnes raisons de scénariser.
2. *Clé de configuration `lan_statut`* — écartée : `save()` par scan et confusion avec l'espace
   personnalisé.
3. *Colonne de scan seule, sans mémoire de sonde* — écartée parce qu'AC4 exige un statut **par
   équipement**, consultable en dehors d'un scan ; un tableau transitoire disparaît au rechargement.
4. *Mémoire de sonde seule, sans colonne de scan* — écartée parce qu'AC1 exige de voir un appareil
   détecté **qui n'a pas encore d'équipement**.

**Portée dans le code**
- `core/class/smartclim.class.php` → `ligneResultatLan()`, `libelleStatutLan()`, `memoriserSondeLan()`,
  `sondeLanMemorisee()`, `etatConnexionAffichable()` (clés `lan` et `lanAdresse`),
  `etatsConnexionAffichables()` (**les mêmes 2 clés dans le repli du `catch`**), `resumeScanLan()`
- `desktop/php/smartclim.php` → table `#table_scanLan`, `#span_scanResumeLan`, ligne « Réseau local »
  du bloc `#div_etatConnexion`
- `desktop/js/smartclim.js` → rendu de la table LAN, vidage de son `tbody` au clic, affichage des
  2 clés avec repli chaîne vide

**Piège à ne pas rouvrir** : les 2 clés doivent être présentes **dans les deux branches** de
`etatsConnexionAffichables()`, y compris le repli du `catch`, et le JS doit forcer une chaîne vide.
`.text(undefined)` de jQuery est un **accesseur** : une clé absente laisse affiché le texte de
l'équipement précédemment consulté au lieu de vider le champ.

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclim.class.php`, `desktop/php/smartclim.php`,
  `desktop/js/smartclim.js`.
- Specs à corriger : `…-01-decouverte-lan-et-session-tech.md` § Statut LAN.
- Migration de l'existant : **aucune** vers une commande info (elle serait créée à la volée), mais
  l'inverse — retirer une commande info déjà créée chez l'utilisateur — serait **coûteux** : un
  `logicalId` posé ne se supprime pas sans casser les scénarios qui le référencent. Raison
  supplémentaire de ne pas créer cette commande maintenant.
- i18n : les libellés de `libelleStatutLan()` (7 statuts) et les en-têtes de la table LAN.
- Réversibilité : **facile** dans le sens retenu ; **coûteuse** dans l'autre sens si une commande info
  avait été créée — asymétrie qui justifie à elle seule le choix.

**Traçabilité**
- Spec fonctionnelle : `…-01-decouverte-lan-et-session.md` § Critères d'acceptation (AC1, AC4)
- Spec technique : `…-01-decouverte-lan-et-session-tech.md` § Statut LAN

---

### D-POSTMVP0101-10 — Tableau de scan LAN séparé du tableau cloud, et échec cloud non bloquant

- **Statut** : appliqué
- **Date** : 2026-08-27
- **Gate** : étape 4 de `/feature` — question ouverte n°2 du planner, et finding advisor F6/F7
- **Principes** : P4, P1

**Question**
Deux questions liées au rendu du scan. (a) Le scan produit désormais deux origines de résultats — les
appareils du cloud AUX Home (UC03 du MVP) et ceux détectés sur le réseau local : faut-il un tableau
unique fusionné avec des colonnes « LAN oui/non · cloud oui/non · transport actif », ou deux tableaux
distincts ? (b) `smartclim::scannerAuxHome()` **lève** quand le compte cloud n'est pas configuré. Si la
nouvelle méthode de composition `scannerClimatiseurs()` laisse cette exception se propager, un
utilisateur purement LAN ne verra **jamais** ses résultats de découverte locale.

**Décision**
(a) **Deux tableaux distincts** : la table existante des appareils cloud reste inchangée, et une
nouvelle table `#table_scanLan` (« Climatiseurs détectés sur le réseau local ») lui est ajoutée, avec
son propre résumé `#span_scanResumeLan`. Aucun rapprochement entre les deux listes en UC01. Le `tbody`
de cette table est **vidé au clic**, comme les deux tables existantes (sans quoi un second scan sans
détection laisserait affichées les lignes du précédent — finding F7).

(b) `scannerClimatiseurs()` appelle `scannerReseauLocal()` **puis** `scannerAuxHome()` dans un
`try/catch (smartclimException)`, et place le message **déjà curaté** dans une clé `cloudErreur` du
retour au lieu de le propager. La réponse AJAX reste donc `ajax::success` : les résultats LAN
s'affichent, et le JS rend `cloudErreur` comme un bandeau d'alerte **de niveau `warning`** — pas
`danger`. Le retour conserve les 6 clés d'UC03/UC04/UC08 **inchangées**, plus `lan` et `cloudErreur`.

**Pourquoi**
(a) P4 : le rapprochement LAN/cloud et les colonnes de transport sont **explicitement** l'objet de
l'UC04 de ce domaine (`04-fusion-lan-cloud.md`). Les produire ici, c'est écrire UC04 par anticipation,
avec le risque de figer un format de fusion avant qu'UC02 et UC03 n'en révèlent les besoins réels.
P1 : AC1 demande que l'appareil « apparaisse dans les résultats de scan avec une indication LAN
disponible et son adresse IP détectée » — un tableau dédié le satisfait littéralement.

(b) Le niveau `warning` plutôt que `danger` est le point qui ne va pas de soi : le scan a
**partiellement réussi** (la phase LAN a abouti), donc le signaler comme une panne serait faux. Le cas
qui décide est celui de l'utilisateur sans compte cloud : avec `danger`, il verrait un bandeau rouge à
chaque scan alors que tout fonctionne pour lui. Le résumé du scan porte de son côté le compte réel
d'appareils trouvés, donc l'information n'est pas perdue.

**Alternatives écartées**
1. *Tableau unique fusionné dès maintenant* — écartée comme avancement d'UC04 ; redeviendrait le
   meilleur choix si UC04 était fusionnée ici, ou si deux tableaux s'avéraient déroutants en recette.
2. *Ajouter deux colonnes « LAN » à la table cloud existante* — écartée parce qu'elle ne peut pas
   représenter un appareil détecté en LAN **absent** du cloud (le cas le plus intéressant : un appareil
   purement local), et parce qu'elle modifierait un tableau déjà livré et recetté.
3. *Laisser l'exception cloud se propager (comportement actuel)* — écartée : un utilisateur sans compte
   cloud ne pourrait jamais utiliser la découverte LAN, ce qui contredit AC1 et l'indépendance des
   transports posée par le brief.
4. *Rendre `cloudErreur` en niveau `danger`* — écartée pour le motif ci-dessus ; redeviendrait le
   meilleur choix si la recette montrait que des utilisateurs cloud ratent un vrai échec
   d'authentification faute d'un signal assez fort.
5. *Distinguer « compte non configuré » des autres erreurs cloud pour ne rien afficher dans ce cas* —
   écartée au titre de P8 (le plus simple à qualité égale) : cela demanderait de faire remonter le type
   d'exception jusqu'au JS pour un gain purement cosmétique ; redeviendrait pertinent si le bandeau
   `warning` s'avérait gênant pour les utilisateurs purement LAN.

**Portée dans le code**
- `core/class/smartclim.class.php` → `scannerClimatiseurs()` (composition, `try/catch`, clés `lan` et
  `cloudErreur`), `ligneResultatLan()`, `resumeScanLan()`
- `core/ajax/smartclim.ajax.php` → l'action `scannerClimatiseurs` appelle `scannerClimatiseurs()` au
  lieu de `scannerAuxHome()` (une ligne ; fichier en **4 espaces**)
- `desktop/php/smartclim.php` → bloc de résultat LAN (titre, table à 5 colonnes, résumé)
- `desktop/js/smartclim.js` → rendu de la table LAN, vidage de son `tbody`, lecture de `cloudErreur`
  en niveau `warning`

**Coût d'un revirement**
- Fichiers à modifier : `desktop/php/smartclim.php`, `desktop/js/smartclim.js`,
  `core/class/smartclim.class.php`.
- Specs à corriger : `…-01-decouverte-lan-et-session-tech.md` § Architecture, et la spec technique
  d'UC04 le jour où elle sera écrite.
- Migration de l'existant : **aucune** (rendu et structure de retour uniquement, rien de persisté).
  ⚠️ En revanche `scannerAuxHome()` reste publique et inchangée : tout appelant tiers éventuel
  continue de recevoir l'exception, le changement de contrat est **local** à `scannerClimatiseurs()`.
- i18n : en-têtes et titre de la table LAN ; aucune nouvelle clé pour `cloudErreur` (le message vient
  de `messageErreurAuxHome()`, déjà traduit).
- Réversibilité : **facile** — rendu et une clé de retour.

**Traçabilité**
- Spec fonctionnelle : `…-01-decouverte-lan-et-session.md` AC1 et § Hors périmètre
- Spec fonctionnelle d'UC04 : `.memory/specs/post-mvp/01-transport-broadlink-lan/04-fusion-lan-cloud.md`
- Spec technique : `…-01-decouverte-lan-et-session-tech.md` § Architecture

### D-POSTMVP0101-11 — Reprise à `impl` par complétion, sans rejouer plan ni code écrit

- **Statut** : appliqué
- **Date** : 2026-08-28
- **Gate** : reprise après coupure (règle 4 de l'agent `auto-dev-runner`)
- **Principes** : P2 (préférer le réversible), P6 (ne pas jeter du travail correct)

**Question**
Deux sessions de ce cycle avaient déjà été coupées par la limite d'usage. Au troisième démarrage, l'état
sur le disque ne correspondait à **aucune** ligne de la grille de reprise : la spec technique
`01-decouverte-lan-et-session-tech.md` existait, l'arbre de travail portait bien des modifications du
plugin — ce que la grille classe en « reprendre à `verif` » —, mais l'implémentation était en réalité
**incomplète**. Fallait-il faire confiance à la grille (aller directement aux reviews), reprendre
l'implémentation à zéro depuis la spec technique, ou compléter ce qui existait ?

**Décision**
Reprise à la phase `impl`, en **complétion** : le code déjà écrit est conservé tel quel, sans
réécriture ni reformatage, et un agent `php-jeedom-dev` **neuf** reçoit l'inventaire précis de ce qui
existe et de ce qui manque. Manquaient : les deux méthodes d'instance `smartclim::adresseLan()` et
`smartclim::macEquipement()`, l'ajout de `lan_ip`/`lan_mac` à `smartclim::preSave()`, les deux clés
`lan` et `lanAdresse` dans `smartclim::etatConnexionAffichable()` **et** dans le repli
`catch (Throwable)` de `smartclim::etatsConnexionAffichables()`, et l'intégralité du § 5.6 de la spec
technique (`desktop/js/smartclim.js`, jamais touché). Les phases `plan` et `spec-tech`, ainsi que les
dix arbitrages `D-POSTMVP0101-01` à `-10`, ne sont **pas** rejoués.

**Pourquoi**
Le constat décisif est que `smartclim::scannerReseauLocal()` **appelait** `$eqLogic->adresseLan()`, une
méthode jamais définie : un « Call to undefined method » fatal au runtime, que ni
`.claude/scripts/verif-plugin.py` (vert sur tout sauf l'i18n, attendu à ce stade) ni `php -l` ne
détectent. Croire la grille aurait envoyé en review, puis au commit, un chemin de code mort au premier
clic sur « Scanner les climatiseurs ». À l'inverse, réimplémenter aurait jeté une classe de 43 ko déjà
conforme au § 5.1, et refacturé un plan déjà arbitré (P6).

**Alternatives écartées**
1. *Reprendre à `verif` comme le prescrit la grille* — écartée parce que la grille suppose une
   implémentation terminée, hypothèse démentie par la méthode appelée et non définie ; redeviendrait le
   bon choix si l'inventaire des membres de la spec technique avait été complet.
2. *Rejouer l'implémentation entière depuis la spec technique* — écartée parce que le code existant est
   conforme au plan et que la coupure était une limite d'usage, pas une erreur technique ;
   redeviendrait le bon choix si le code écrit s'était révélé incohérent avec la spec technique plutôt
   que simplement incomplet.
3. *Reprendre l'agent développeur d'origine* — impossible : son contexte est mort avec la session
   coupée. D'où l'agent neuf, briefé par un inventaire écrit.

**Portée dans le code**
- `core/class/smartclim.class.php` → `adresseLan()`, `macEquipement()`, `preSave()`,
  `etatConnexionAffichable()`, `etatsConnexionAffichables()`
- `desktop/js/smartclim.js` → rendu de `#table_scanLan`, vidage du `tbody`, `cloudErreur` en
  `showAlert({ level: 'warning' })`, `afficherEtatConnexion()`, aide de saisie dans `saveEqLogic()`,
  `timeout` de scan porté de 30000 à 60000
- Conservés sans retouche : `core/class/smartclimBroadlinkLan.class.php`,
  `core/php/smartclim.inc.php`, `core/class/smartclimCapabilities.class.php`,
  `core/ajax/smartclim.ajax.php`, `desktop/php/smartclim.php`

**Coût d'un revirement**
- Fichiers à modifier : aucun — cette décision porte sur la **conduite** du cycle, pas sur un choix de
  conception. Elle n'a laissé aucune trace propre dans le code.
- Specs à corriger : aucune
- Migration de l'existant : **aucune**
- i18n : **aucune**
- Réversibilité : sans objet (décision de procédure, déjà consommée)

**Traçabilité**
- Journal du run : `.memory/auto-dev/run-20260827-1008/journal.jsonl`, événements `impl/reprise` puis
  `impl/debut` du 2026-08-28
- Spec technique : `…-01-decouverte-lan-et-session-tech.md` §§ 5.1, 5.2, 5.6
- Capitalisé en mémoire d'agent :
  `.claude/agent-memory/auto-dev-runner/feedback-reprise-impl-methode-appelee-non-definie.md`

### D-POSTMVP0101-12 — Mémoire de sonde LAN sans clé `motif`, le statut suffit

- **Statut** : appliqué
- **Date** : 2026-08-28
- **Gate** : étape 9 de `/feature` (traitement des findings de review, tour 1)
- **Principes** : P4 (pas de donnée morte), P7 (une seule source de vérité)

**Question**
La review qualité du tour 1 a relevé que le § 5.2 de la spec technique
`01-decouverte-lan-et-session-tech.md` documentait, pour l'entrée de cache écrite par
`smartclim::memoriserSondeLan()`, une clé `motif` qui n'est **jamais écrite ni lue** par le code. Le
reviewer laissait explicitement deux issues : écrire la clé `motif` (en y mettant la raison de
l'échec), ou la retirer du plan. Il fallait trancher laquelle des deux — le code et la spec ne
pouvaient pas rester en désaccord.

**Décision**
Retrait de la clé `motif` du plan technique. L'entrée de cache
`smartclim::lan_appareil::<mac>` (constante `smartclim::CLE_CACHE_LAN`, durée
`DUREE_MEMOIRE_LAN` = 86400 s) sérialise donc exactement huit clés :
`ip`, `port`, `type_appareil`, `nom`, `verrouille`, `statut`, `vu_le`, `echec_le`. Le code n'est pas
modifié — c'est la **spec** qui est corrigée pour décrire ce que le code fait.

**Pourquoi**
La raison d'un échec est déjà entièrement portée par `statut`, qui prend l'une des constantes
`smartclimBroadlinkLan::STATUT_*` (`injoignable`, `refusee`, `verrouille`, `occupe`,
`mac_divergente`) et dont le libellé français est rendu par `smartclim::libelleStatutLan()`. Une clé
`motif` en plus serait soit vide, soit une paraphrase du statut — donc une seconde source de vérité
sur la même information, exactement ce que ce cycle a refusé ailleurs (pas d'entrée vide dans
`smartclimCapabilities::tables()` pour le transport LAN, § 5.3).

**Alternatives écartées**
1. *Écrire `motif` avec un texte libre* — écartée parce que le seul texte disponible au moment de
   l'échec est le message technique d'une `smartclimException`, qui n'est **pas** curaté pour
   affichage (`CLAUDE.md` : seul `smartclim::` produit des messages affichables) ; le stocker
   inviterait à l'afficher un jour. Redeviendrait le bon choix si l'IHM devait un jour distinguer
   deux causes partageant le même statut — par exemple « injoignable parce que silence » contre
   « injoignable parce que réponse illisible ».
2. *Laisser la spec et le code en désaccord* — écartée : la spec technique est la référence des UC02
   à UC04 de ce domaine, une clé fantôme y serait recopiée.

**Portée dans le code**
- `core/class/smartclim.class.php` → `memoriserSondeLan()` et `sondeLanMemorisee()` (aucune
  modification : le code était déjà conforme à la décision)
- `.memory/specs/post-mvp/01-transport-broadlink-lan/01-decouverte-lan-et-session-tech.md` § 5.2 →
  ligne de contrat de `memoriserSondeLan()`

**Coût d'un revirement**
- Fichiers à modifier : `core/class/smartclim.class.php` (`memoriserSondeLan()` pour écrire la clé,
  `sondeLanMemorisee()` pour la relire), plus le point d'affichage si elle doit être montrée
- Specs à corriger : `…-01-decouverte-lan-et-session-tech.md` § 5.2
- Migration de l'existant : **aucune** — une entrée de cache écrite sans `motif` reste lisible, la
  clé serait simplement absente ; et le cache expire seul en 24 h
- i18n : aucune si `motif` reste un champ technique ; sinon autant de clés françaises que de motifs
- Réversibilité : **facile** — une clé dans un `json_encode()` et sa lecture

**Traçabilité**
- Spec technique : `…-01-decouverte-lan-et-session-tech.md` § 5.2 (`memoriserSondeLan()`)
- Spec fonctionnelle : `…-01-decouverte-lan-et-session.md` AC4 (mémoire du dernier contact)
- Origine : finding `minor` n°8 de la review qualité du tour 1

### D-POSTMVP0101-13 — Bug 32 bits corrigé en passe de finition, pas reporté en dette

- **Statut** : appliqué
- **Date** : 2026-08-28
- **Gate** : étape 9 de `/feature` (fin de boucle après le plafond de deux tours de review)
- **Principes** : P1 (ne pas livrer un défaut connu), P5 (le coût d'un correctif croît avec le report)

**Question**
Après le tour 2 de reviews — les deux ayant passé la gate — j'ai constaté moi-même un défaut que la
review sécurité avait explicitement **écarté** : dans `smartclim::normaliserIpV4()` et
`smartclimBroadlinkLan::normaliserIpV4()`, le test `$long >= ip2long('224.0.0.0')` est faux sur un
build PHP **32 bits**. Le plafond de deux tours étant atteint, la règle du mode autonome envoie
normalement en `dette` ce qui reste au-dessus de la gate. Fallait-il donc reporter, ou corriger dans
l'unique passe de finition autorisée avant la traduction ?

**Décision**
Correction immédiate, dans la passe de finition, sur **les deux** copies de `normaliserIpV4()`, en
supprimant tout recours à `ip2long()` : la méthode raisonne désormais sur les **octets** de l'adresse
(premier octet `=== 0` pour `0.0.0.0/8` ; premier octet `>= 224` pour multicast et réservé haut ;
premier octet `=== 100` et deuxième entre `64` et `127` pour le CGNAT). Aucun tour de review
supplémentaire n'a été déclenché — conformément au plafond. Le second point de la même passe est
l'élargissement de la portée du `catch (Throwable)` de
`smartclimBroadlinkLan::ouvrirSession()` (finding `low` du tour 2).

**Pourquoi**
`ip2long()` renvoie un entier **signé** : sur PHP 32 bits, `ip2long('224.0.0.0')` vaut
**-536870912**, si bien que toute adresse dont le premier octet est inférieur à 128 est positive,
donc « supérieure » au seuil, donc rejetée. Vérifié par calcul : `10.0.0.1` (167772161) est
**rejetée**, alors que `192.168.1.50` (-1062731470) passe. Or Raspberry Pi OS **armhf** est une
plateforme Jeedom très répandue, et le champ concerné est « Adresse IP locale » — précisément le mode
de secours d'AC3 pour les réseaux segmentés. Reporter en dette aurait signifié livrer une panne
**silencieuse** (champ vidé sans message) sur toute installation en `10.x.x.x`, pour une correction
de six lignes déjà entièrement spécifiée. Le plafond de deux tours existe pour couper le
**ping-pong de review**, pas pour interdire de corriger un défaut connu et compris.
Défaut connexe fermé au passage : le littéral `0xFF000000` (4278190080) dépasse `PHP_INT_MAX` en
32 bits et devient un **flottant**, rendant le `&` non fiable.

**Alternatives écartées**
1. *Reporter en dette avec `Statut : dette` et mention « à trancher par `/change` »* — écartée : la
   dette se justifie quand le correctif est incertain, coûteux ou conceptuel ; ici il est trivial,
   sûr, et son absence casse un critère d'acceptation sur une plateforme entière. Redeviendrait le bon
   choix si la correction avait exigé de rouvrir la conception.
2. *Corriger seulement le test `>= 224.0.0.0` en gardant `ip2long()`* (par exemple via
   `sprintf('%u', ...)`) — écartée parce qu'elle laisse la méthode dépendante de la largeur des
   entiers : le prochain contributeur qui ajoute une plage réintroduit le même bug. Le raisonnement
   par octets ferme la classe entière.
3. *Faire confiance à la review sécurité, qui avait jugé le point non fondé* — écartée après
   vérification : son argument (« les deux opérandes subissent le même décalage pour toute adresse
   ≥ 128.0.0.0 ») se réfute lui-même, puisque `10.0.0.1` est justement **en dessous** de
   `128.0.0.0` et ne subit donc pas ce décalage. Un verdict de reviewer se vérifie comme un finding
   de reviewer.

**Portée dans le code**
- `core/class/smartclimBroadlinkLan.class.php` → `normaliserIpV4()`, `ouvrirSession()`
- `core/class/smartclim.class.php` → `normaliserIpV4()`
- ⚠️ Les deux `normaliserIpV4()` doivent rester **identiques entre elles** (duplication voulue,
  inscrite en dette n°2 du § 12 de la spec technique) : un revirement doit toucher les deux.

**Coût d'un revirement**
- Fichiers à modifier : les deux fichiers ci-dessus, méthode `normaliserIpV4()` dans chacun
- Specs à corriger : `…-01-decouverte-lan-et-session-tech.md` §§ 4.1 et 12
- Migration de l'existant : **aucune** en base. Mais attention à un effet de bord réel : sur un
  Jeedom 32 bits en `10.x.x.x`, une adresse saisie **avant** ce correctif a pu être vidée par
  `preSave()` ; l'utilisateur devra la ressaisir. Aucun script ne peut la restituer, elle n'a jamais
  été stockée.
- i18n : **aucune** (les messages concernés sont des logs)
- Réversibilité : **facile** — une condition arithmétique dans deux méthodes

**Traçabilité**
- Spec fonctionnelle : `…-01-decouverte-lan-et-session.md` AC3 (saisie manuelle IP/MAC, secours VLAN)
  et AC4
- Spec technique : `…-01-decouverte-lan-et-session-tech.md` § 4.1 (validation des entrées
  utilisateur), § 12 (dette)
- Analyse capitalisée : `.memory/analyse/smartclim-transport-broadlink-lan.md` § 9
- Origine : constat de l'orchestrateur au tour 2, contre un verdict de review qui l'écartait
