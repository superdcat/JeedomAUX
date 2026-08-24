# Specs — Roadmap du plugin SmartClim

Ce dossier contient les **specs des features** du plugin `smartclim`, découpées en **UC implémentables une
par une** par la commande `/feature`. Chaque UC est un incrément **livrable et testable** par l'utilisateur
sur son matériel.

- **`MVP/`** — le socle : 8 UC ordonnées menant au **pilotage complet d'un climatiseur AUX Home**
  (le transport du premier appareil de validation).
- **`post-mvp/<NN-domaine>/`** — 7 domaines qui étendent le plugin : transports supplémentaires, stratégies
  de bascule, fonctions de confort, temps réel, ergonomie, publication.

## Convention

Une feature = deux fichiers dans le **même dossier** :

- **Spec fonctionnelle** `NN-nom.md` — le **quoi** et le **pourquoi** : contexte, comportement attendu et
  surtout les **critères d'acceptation** (la *definition of done*, vérifiables). C'est l'entrée du
  workflow ; elle est rédigée/validée avec l'utilisateur.
- **Spec technique** `NN-nom-tech.md` — le **comment** : architecture, fichiers à créer/modifier, décision
  server/client, signatures d'actions AJAX, validation, dépendances. Elle est **produite par
  l'orchestrateur `/feature`**, après validation du plan par l'utilisateur, puis consommée par l'agent
  `php-jeedom-dev`.

> ⚠️ **Numérotation locale à chaque dossier** : le MVP a ses UC01→UC08, et *chaque* domaine post-MVP
> repart à `01`. Une référence croisée se cite donc **en toutes lettres** — « UC06 du MVP », « UC03 du
> domaine post-mvp/01 » — jamais par un numéro nu.

## Socle MVP — ordre de développement

Ordre **strict** : chaque UC dépend de la précédente.

| # | Titre | Dépend de |
|---|---|---|
| 01 | Configuration du plugin et stockage sécurisé des identifiants | — |
| 02 | Brique d'accès AUX Home : authentification et test de connexion | 01 |
| 03 | Découverte des climatiseurs AUX Home et création des équipements | 02 |
| 04 | Modèle générique, tables de correspondance et profil de capacités | 03 |
| 05 | Commandes info : lecture de l'état du climatiseur | 04 |
| 06 | Commandes action : pilotage complet du climatiseur | 05 |
| 07 | Rafraîchissement périodique et rafraîchissement manuel | 06 |
| 08 | Robustesse, expiration de session et diagnostic | 07 |

À l'issue de l'UC08, un climatiseur AUX Home est **entièrement pilotable depuis Jeedom**, en PHP pur, sans
démon ni dépendance.

## Domaines post-MVP

### `01-transport-broadlink-lan/` — Transport local Broadlink (UDP port 80)
Ouvre le plugin au parc installé et permet le pilotage **sans Internet**.

| # | Titre | Dépend de |
|---|---|---|
| 01 | Découverte broadcast, authentification et session locale | UC03 du MVP |
| 02 | Lecture de l'état et de la température ambiante en LAN | 01 de ce domaine |
| 03 | Envoi de commandes en LAN | 02 de ce domaine |
| 04 | Fusion d'un même climatiseur découvert en LAN et dans le cloud | 01 de ce domaine |

### `02-strategies-de-transport/` — Stratégies AUTO / LOCAL / CLOUD
Arbitre entre transports disponibles et rend visible celui qui est actif.

| # | Titre | Dépend de |
|---|---|---|
| 01 | Choix du mode de transport par équipement | UC03 du domaine 01 |
| 02 | Repli LAN → cloud avec compteurs d'échec et temporisation | 01 de ce domaine |
| 03 | Résilience au changement d'adresse IP | 02 de ce domaine |

### `03-cloud-aux-legacy/` — Cloud historique AC Freedom / AUX Cloud
Couvre les comptes et générations absents d'AUX Home (régions USA / Chine / Russie incluses).

| # | Titre | Dépend de |
|---|---|---|
| 01 | Client AUX Cloud legacy : authentification multi-régions | UC02 du MVP |
| 02 | Familles, pièces, appareils et capacités legacy | 01 de ce domaine |
| 03 | Lecture et écriture des paramètres legacy | 02 de ce domaine |

### `04-fonctions-avancees/` — Fonctions de confort et diagnostic
Étend la surface fonctionnelle au-delà du pilotage de base.

| # | Titre | Dépend de |
|---|---|---|
| 01 | Fonctions éco, sommeil, afficheur, santé, anti-moisissure, nettoyage, silence | UC06 du MVP |
| 02 | Oscillations verticale et horizontale distinctes | 01 de ce domaine |
| 03 | Codes d'erreur, sécurité enfant et limitation de puissance | 01 de ce domaine |

### `05-temps-reel-et-demon/` — Temps réel : démon Python et canaux persistants
⚠️ **Domaine conditionnel** : il n'existe que si un canal réellement persistant est confirmé. C'est le seul
endroit où un démon devient justifié — et il sera **Python**, jamais Node.

| # | Titre | Dépend de |
|---|---|---|
| 01 | Spike : existe-t-il un push sur le cloud AUX Home européen ? | UC07 du MVP |
| 02 | Socle de démon Python et pont avec Jeedom | 01 de ce domaine |
| 03 | Temps réel du cloud historique (relais WebSocket) | 02 de ce domaine + UC03 du domaine 03 |
| 04 | Spike puis transport local alternatif (AUXLink) pour les modules récents | 02 de ce domaine |

### `06-ergonomie-jeedom/` — Widget, scan unifié, page-panneau
Rend le plugin agréable au quotidien.

| # | Titre | Dépend de |
|---|---|---|
| 01 | Widget de commande « climatiseur » (dashboard + mobile) | UC06 du MVP |
| 02 | Scan unifié LAN + AUX Home + legacy avec fusion | UC04 du domaine 01 + UC02 du domaine 03 |
| 03 | Page-panneau multi-climatiseurs au menu Jeedom | 01 de ce domaine |

### `07-multimarque-documentation-et-diffusion/` — Multimarque, doc et publication
Transforme le plugin en produit diffusable.

| # | Titre | Dépend de |
|---|---|---|
| 01 | Tolérance aux modèles et générations inconnus | UC02 du domaine 03 |
| 02 | Documentation utilisateur et crédits de licences | UC08 du MVP |
| 03 | Icône du plugin | — |
| 04 | Traductions complètes et préparation à la publication | 02 et 03 de ce domaine |

## Conventions transverses (rappel)

- **Langue FR** ; i18n `{{...}}` (HTML/JS) et `__('...', __FILE__)` (PHP), clé = texte français.
  ⚠️ Toujours une chaîne **littérale** dans `__()`, jamais `__($variable)`.
- **Autoload 1 classe ↔ 1 fichier** ; tout appel externe centralisé (brique de transport, jamais de cURL
  ou de socket épars).
- **`logicalId` de commande générique et stable** : une bascule de transport ne casse aucun scénario.
- Logs via `log::add('smartclim', …)` ; **jamais** de secret/token en clair, y compris dans une trace
  d'exception.
- **Robustesse** : try/catch **par équipement** dans les crons ; période de grâce après commande
  (anti-rollback) ; backoff/cooldown sur les erreurs réseau.
- **TLS toujours vérifié**, contrairement aux implémentations publiques de référence.
- Une feature = un commit/PR, vérifications vertes entre chaque.

> Chaque spec porte une section **« À confirmer »** : ce sont les contrats externes **non prouvés**
> (échelles de valeurs, noms de champs, codes d'erreur). Ils se tranchent **en recette sur le matériel**, au
> moment de coder — et le résultat est reporté dans le fichier d'analyse concerné.

> Détail des conventions et de l'architecture : `CLAUDE.md` (racine).
> Connaissance protocolaire et décisions : `.memory/analyse/` (via son `INDEX.md`).
> Intention d'origine : `.memory/brief.md` (le brief utilisateur, fondateur de toute la roadmap).
