# UC02 — Familles, pièces, appareils et capacités legacy

> **Domaine** : post-mvp/03-cloud-aux-legacy · **Statut** : à implémenter · **Dépend de** : UC01 de ce
> domaine

## Objectif

Une fois le compte cloud historique connecté (UC01), l'utilisateur doit pouvoir retrouver dans Jeedom tous
les climatiseurs de ce compte — y compris ceux qui lui sont seulement partagés — avec, pour chacun, un jeu
de commandes qui correspond exactement à ce que l'appareil sait réellement faire, jamais à un modèle
générique figé. C'est la même exigence anti-whitelist que pour les autres transports, appliquée à ce cloud.

## Comportement attendu

La découverte parcourt l'ensemble des familles du compte, puis les pièces de chaque famille, puis les
appareils — **y compris les appareils partagés** avec le compte par un autre utilisateur (un climatiseur
peut être visible sans appartenir en propre à la famille). Chaque appareil trouvé est interrogé pour
déterminer les paramètres qu'il accepte réellement de renvoyer : ce sont ces paramètres, et eux seuls, qui
définissent les commandes proposées ensuite dans Jeedom (une fonction absente de la réponse de l'appareil
n'a pas de commande).

Un appareil dont l'identifiant de produit n'est reconnu par aucune table connue n'est **jamais rejeté** : il
est découvert et créé comme climatiseur générique, avec un socle de commandes minimal, et le fait qu'il soit
inconnu est journalisé pour permettre un enrichissement ultérieur du plugin — jamais silencieusement ignoré.

L'état en ligne/hors ligne de tous les appareils du compte est récupéré en une seule opération groupée, pas
appareil par appareil, pour rester rapide même avec un compte comportant de nombreux climatiseurs.

Si un climatiseur retrouvé sur le cloud historique correspond, par son adresse MAC, à un équipement déjà
présent dans Jeedom (créé via un autre transport lors d'une découverte précédente), **aucun nouvel
équipement n'est créé** : le cloud historique devient une source supplémentaire pour l'équipement existant.

## Critères d'acceptation

- [ ] **AC1** — Après une découverte réussie, chaque climatiseur du compte cloud historique apparaît comme
      équipement dans Jeedom — y compris ceux uniquement partagés avec le compte (pas seulement ceux d'une
      famille possédée en propre).
- [ ] **AC2** — Les commandes proposées pour un équipement découvert via ce transport correspondent
      uniquement aux fonctions que cet appareil précis a effectivement annoncées ; deux modèles différents
      découverts sur le même compte peuvent avoir des jeux de commandes différents.
- [ ] **AC3** — Un appareil dont l'identifiant de produit n'est pas reconnu est tout de même découvert et
      créé comme climatiseur, avec au minimum les commandes de base (marche/arrêt, mode, température) ; la
      découverte des autres appareils du compte n'est pas interrompue par sa présence.
- [ ] **AC4** — L'identifiant de produit inconnu d'un tel appareil est retrouvable dans les logs/le journal
      du plugin après la découverte, pour permettre un signalement ou un enrichissement futur.
- [ ] **AC5** — Après une découverte, l'état en ligne/hors ligne de chaque appareil du compte est visible
      dans Jeedom à l'issue d'un seul cycle de rafraîchissement, quel que soit le nombre d'appareils du
      compte.
- [ ] **AC6** — Un climatiseur déjà présent dans Jeedom (créé via un autre transport) et retrouvé lors d'une
      découverte sur le cloud historique ne crée pas de second équipement : c'est le même équipement,
      reconnu par sa MAC, qui gagne cette source supplémentaire — visible dans sa configuration.
- [ ] **AC7** — Relancer la découverte plusieurs fois de suite sur le même compte ne crée aucun doublon,
      quel que soit le nombre de relances.

## Impact i18n

- Nouvelles chaînes UI (français) anticipées : libellé du transport « Cloud historique (AC Freedom / AUX
  Cloud) » dans l'affichage des sources d'un équipement, « Appareil partagé ».

## À confirmer

- Le comportement exact d'une interrogation à vide (`get` avec liste de paramètres vide) sur un appareil au
  `productId` inconnu n'est pas prouvé : renvoie-t-il un jeu de paramètres exploitable comme pour un
  appareil connu, ou une erreur ? À valider dès l'implémentation. Cf.
  `.memory/analyse/smartclim-transport-aux-cloud-legacy.md` § 3 et § 8.
- La durée de vie du jeton de session par appareil (`devSession`) et la nécessité de relister les appareils
  avant une série de commandes si la découverte date — non documentées. Cf. même fichier, § 7.
- Le jeton d'appairage par appareil récupéré à la découverte doit être décodé puis recomposé dans un format
  différent pour permettre le pilotage (UC03) ; sa structure précise n'est pas traitée ici (relève du
  contrat technique) mais son existence conditionne ce que la découverte doit conserver pour l'appareil. Cf.
  même fichier, § 3.

## Hors périmètre

- La lecture et l'écriture des paramètres d'un climatiseur découvert → UC03 de ce domaine.
- Le support des pompes à chaleur (jeu de paramètres et types de produits distincts, identifiés dans
  l'analyse) → hors périmètre du plugin, qui cible les climatiseurs.
- Le temps réel par WebSocket relay → `post-mvp/05-temps-reel-et-demon`.
- L'arbitrage sur le transport à utiliser en priorité pour un équipement retrouvé via plusieurs sources →
  `post-mvp/02-strategies-de-transport`.
