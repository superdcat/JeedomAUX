# UC03 — Codes d'erreur, sécurité enfant et limitation de puissance

> **Domaine** : post-mvp/04-fonctions-avancees · **Statut** : à implémenter · **Dépend de** : UC01 de ce
> domaine

## Objectif

Au-delà du pilotage de confort, l'utilisateur veut pouvoir diagnostiquer son climatiseur depuis Jeedom :
savoir qu'il est en défaut, connaître la nature du défaut quand elle est identifiable, et disposer des
fonctions de protection (sécurité enfant, limitation de puissance) quand l'appareil les propose. L'objectif
est aussi de permettre à l'utilisateur de réagir automatiquement (scénario Jeedom) dès qu'une anomalie
apparaît, sans devoir surveiller l'application constructeur.

## Comportement attendu

- Sur un équipement dont le profil de capacités confirme la remontée d'un indicateur d'erreur, une commande
  info « Erreur » (état binaire : en défaut / normal) apparaît, ainsi qu'une commande info « Code erreur »
  quand un code est disponible.
- Quand un code d'erreur est reçu et que ce code est **connu** du plugin (table de correspondance
  construite au fil des retours), la commande « Code erreur » affiche un libellé lisible en français (ex.
  « Défaut capteur de température »). Quand le code est **inconnu**, la valeur brute renvoyée par l'appareil
  est affichée telle quelle — jamais masquée, jamais remplacée par un message générique qui ferait perdre
  l'information technique nécessaire à un diagnostic ou une recherche ultérieure.
- Un code d'erreur inconnu est également journalisé par le plugin (avec sa valeur brute), pour permettre
  d'enrichir la table de correspondance dans une version ultérieure — sans jamais bloquer la remontée de
  l'information à l'utilisateur ni faire échouer le rafraîchissement de l'équipement.
- Sur un équipement dont le profil de capacités confirme le support de la sécurité enfant, une commande
  action (activer/désactiver) et une commande info (état courant) apparaissent, avec un effet constatable
  sur l'appareil (verrouillage effectif du panneau de commande physique).
- Sur un équipement dont le profil de capacités confirme le support de la limitation de puissance, une
  commande action et une commande info apparaissent, avec un effet constatable.
- Ces commandes suivent le fonctionnement standard des commandes Jeedom : un changement d'état déclenche
  normalement les scénarios de l'utilisateur, sans mécanisme supplémentaire à mettre en place.
- Cas dégradé — pas d'erreur en cours : la commande « Erreur » reste à l'état « normal », sans code affiché
  (ou un code neutre signifiant explicitement l'absence d'erreur).
- Cas dégradé — fonction (erreur, sécurité enfant, limitation de puissance) non supportée par l'appareil ou
  par le transport actif : aucune commande correspondante n'est créée.

## Critères d'acceptation

- [ ] **AC1** — Sur un climatiseur fonctionnant normalement, la commande info « Erreur » indique l'absence
      de défaut, et aucun code d'erreur actif n'est affiché (ou un code neutre signifiant explicitement
      « aucune erreur »).
- [ ] **AC2** — Lorsqu'une erreur survient sur le climatiseur (constatée sur l'appareil ou son application
      constructeur), la commande info « Erreur » bascule à l'état « en défaut » au rafraîchissement suivant.
- [ ] **AC3** — Quand le code d'erreur remonté est reconnu par le plugin, la commande « Code erreur » affiche
      un libellé en français compréhensible sans avoir à consulter la documentation technique.
- [ ] **AC4** — Quand le code d'erreur remonté n'est pas reconnu par le plugin, la commande « Code erreur »
      affiche la valeur brute renvoyée par l'appareil (jamais un texte générique qui masquerait cette
      valeur).
- [ ] **AC5** — Un code d'erreur non reconnu apparaît dans les journaux du plugin avec sa valeur brute, sans
      provoquer d'échec du rafraîchissement de l'équipement ni des autres équipements du plugin.
- [ ] **AC6** — Sur un équipement dont le profil de capacités confirme la sécurité enfant, actionner la
      commande correspondante verrouille effectivement le panneau de commande physique du climatiseur
      (vérifiable en constatant que les boutons de l'appareil deviennent inopérants), et la commande info
      associée reflète cet état après rafraîchissement.
- [ ] **AC7** — Sur un équipement dont le profil de capacités confirme la limitation de puissance, actionner
      la commande correspondante produit un effet constatable sur la consommation ou le comportement de
      l'appareil, et la commande info associée reflète cet état après rafraîchissement.
- [ ] **AC8** — Sur un équipement ne supportant pas la sécurité enfant et/ou la limitation de puissance,
      aucune commande correspondante n'apparaît sous l'équipement.
- [ ] **AC9** — Un scénario Jeedom standard, déclenché sur le changement de la commande « Erreur » (ou
      « Code erreur »), se déclenche effectivement lors d'un changement d'état réel de cette commande.

## Impact i18n

- Nouvelles chaînes UI (français) anticipées : « Erreur », « Code erreur », « Aucune erreur », « Sécurité
  enfant », « Limitation de puissance », « Activer », « Désactiver ». Les libellés des codes d'erreur connus
  seront ajoutés au fil de leur confirmation (table ouverte, pas figée à la livraison de cette UC).

## À confirmer

- ⚠️ Aucune table libellé ↔ code d'erreur n'existe à ce jour dans les sources analysées pour le transport
  cloud legacy (champs `err_flag`, `ac_errcode1` repérés mais non documentés,
  `.memory/analyse/smartclim-transport-aux-cloud-legacy.md` § 5) : cette table sera construite
  **incrémentalement**, code par code, au fil des retours d'installation réels — elle n'est pas un
  prérequis bloquant pour livrer AC4 (comportement « code brut affiché » pour tout code encore inconnu).
- ⚠️ Aucun champ d'erreur applicative (au-delà du simple online/offline déjà couvert au MVP) n'a été
  identifié dans les sources analysées pour le transport AUX Home
  (`.memory/analyse/smartclim-transport-aux-home.md`, `.memory/analyse/smartclim-modele-abstrait-
  capacites.md` § 2 et § 3.4). Comme le MVP repose sur AUX Home, il est possible qu'aucune commande
  d'erreur détaillée n'apparaisse tant que ce transport n'expose pas ce concept — conforme à la règle
  d'or (pas une anomalie de cette UC), mais à vérifier en priorité avant de considérer l'UC totalement
  close pour cet appareil.
- La sécurité enfant (`childLock`) et la limitation de puissance (`pwrlimit`/`pwrlimitswitch`) sont
  confirmées uniquement côté transport cloud legacy (`.memory/analyse/smartclim-transport-aux-cloud-
  legacy.md` § 5) ; leur existence côté AUX Home est marquée non confirmée (❓) dans
  `.memory/analyse/smartclim-modele-abstrait-capacites.md` § 3.4. Sur le MVP (AUX Home), ces commandes
  pourraient donc ne pas être livrables tant que ce transport ne les confirme pas — cohérent avec AC8.
- Le format exact et la plage de valeurs des codes d'erreur (`ac_errcode1` et équivalents) ne sont pas
  documentés dans les sources analysées ; à établir au moment de l'implémentation technique, sans que cela
  ne change le contrat fonctionnel (code brut affiché par défaut).

## Hors périmètre

- La détection du profil de capacités (l'appareil expose-t-il un indicateur d'erreur, la sécurité enfant, la
  limitation de puissance) → UC04 du MVP.
- L'affichage synthétique en tuile dashboard de l'état d'erreur → `post-mvp/06-ergonomie-jeedom`.
- Un mécanisme de notification dédié (au-delà du déclenchement standard de scénario Jeedom sur changement de
  commande) : notification push, e-mail, historique d'incidents — non couvert par cette UC.
- Les codes et fonctions spécifiques aux pompes à chaleur (`ac_pwr`, `hp_*`…) : hors périmètre général de
  SmartClim (le plugin cible les climatiseurs), cf. `.memory/analyse/smartclim-transport-aux-cloud-
  legacy.md` § 5.
- Les interrupteurs de confort (éco, sommeil, afficheur…) → UC01 de ce domaine ; les oscillations fines →
  UC02 de ce domaine.
