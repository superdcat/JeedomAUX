# UC02 — Oscillations verticale et horizontale distinctes

> **Domaine** : post-mvp/04-fonctions-avancees · **Statut** : à implémenter · **Dépend de** : UC01 de ce
> domaine

## Objectif

Le socle MVP (UC06) pilote l'oscillation des volets du climatiseur. Certains appareils distinguent deux
axes indépendants — oscillation verticale (haut/bas) et horizontale (gauche/droite) — que l'utilisateur veut
pouvoir commander séparément (ex. orienter le flux horizontalement en fixe tout en laissant osciller
verticalement). Cette UC ajoute ce pilotage fin quand l'appareil le permet réellement, sans dégrader le
comportement des appareils qui n'ont qu'un swing global.

## Comportement attendu

- Sur un équipement dont le profil de capacités confirme le support des deux axes d'oscillation
  indépendamment, deux commandes action distinctes apparaissent : une pour l'oscillation verticale, une pour
  l'oscillation horizontale.
- Actionner la commande d'un axe modifie uniquement le mouvement de cet axe, sans changer l'état de l'autre
  axe.
- Quand le transport actif de l'équipement permet de relire séparément l'état de chaque axe, la commande info
  de chaque axe reflète, après rafraîchissement, l'état réellement constaté sur l'appareil pour cet axe
  précis.
- ⚠️ Quand le transport actif ne permet **pas** de distinguer les deux axes en lecture, la commande info de
  chaque axe affiche le dernier état commandé par Jeedom pour cet axe (état optimiste), et cette limitation
  est rendue visible à l'utilisateur (ex. texte d'aide sur la commande ou dans la documentation de
  l'équipement) plutôt que présentée comme une lecture fiable. Cette limitation n'empêche jamais l'écriture :
  la commande action reste pleinement fonctionnelle.
- Sur un équipement qui ne supporte qu'un swing global (pas d'axes séparés), le comportement reste celui du
  socle MVP (UC06) : pas de régression, pas de commandes supplémentaires créées par cette UC.

## Critères d'acceptation

- [ ] **AC1** — Sur un équipement dont le profil de capacités confirme les deux axes, deux commandes action
      distinctes (« Oscillation verticale », « Oscillation horizontale ») sont visibles sous l'équipement.
- [ ] **AC2** — Activer ou désactiver la commande d'oscillation verticale modifie visiblement le mouvement
      vertical des volets du climatiseur sans changer le mouvement horizontal en cours.
- [ ] **AC3** — Réciproquement, activer ou désactiver la commande d'oscillation horizontale ne modifie pas
      le mouvement vertical en cours.
- [ ] **AC4** — Sur un transport où la relecture séparée des deux axes est confirmée possible, la commande
      info de chaque axe affiche, après rafraîchissement, l'état réellement observé pour cet axe.
- [ ] **AC5** — Sur un transport où la relecture séparée n'est pas possible, la commande info de chaque axe
      continue d'afficher le dernier état commandé par Jeedom pour cet axe (pas d'erreur, pas de valeur
      vide), et une indication visible pour l'utilisateur signale que cette valeur peut ne pas refléter un
      changement fait hors Jeedom (télécommande, application constructeur).
- [ ] **AC6** — Sur un équipement ne supportant qu'un swing global, aucune commande « Oscillation verticale »
      / « Oscillation horizontale » distincte n'apparaît, et le comportement de la commande de swing globale
      héritée du MVP reste inchangé.
- [ ] **AC7** — L'écriture (commande action) sur un axe fonctionne de façon identique, que la relecture
      séparée de cet axe soit disponible ou non sur le transport actif de l'équipement.

## Impact i18n

- Nouvelles chaînes UI (français) anticipées : « Oscillation verticale », « Oscillation horizontale »,
  « État approximatif : relecture séparée non disponible sur ce transport » (ou libellé équivalent
  d'avertissement affiché à proximité de la commande info concernée).

## À confirmer

- ⚠️ **La relecture séparée des deux axes reste un objectif de recherche, pas un acquis**, en particulier
  sur le transport AUX Home du MVP : `.memory/analyse/smartclim-transport-aux-home.md` § 6.1 et § 9
  documentent que le champ `status.control` connu (octet 11 `!= 0x20`) indique seulement qu'« une
  oscillation est active », **sans distinguer** verticale et horizontale. Tant qu'un moyen fiable de
  séparation en lecture n'est pas identifié pour ce transport, AC4 ne s'applique qu'aux transports qui le
  permettent effectivement (à date, aucun n'est confirmé) ; AC5 documente le repli assumé.
- Le sens « oscille »/« fixe » de chaque axe (valeurs `0`/`7` côté AUX Home et fil HVAC, contradiction
  `ac_vdir`/`ac_hdir` `0` vs `1` côté cloud legacy) n'est pas déterminant pour cette spec fonctionnelle — il
  conditionne l'implémentation technique, pas le contrat observable par l'utilisateur (une bascule doit
  produire le bon effet, quel que soit le code interne utilisé). Cf.
  `.memory/analyse/smartclim-modele-abstrait-capacites.md` § 3.3 et
  `.memory/analyse/smartclim-transport-aux-cloud-legacy.md` § 5.1.
- Aucune source analysée ne documente à ce jour un moyen de relecture séparée fiable, sur aucun transport de
  l'écosystème ; il n'est donc pas garanti qu'AC4 soit un jour satisfait sur un transport donné — c'est un
  axe de recherche continu, pas une limite à lever au moment de livrer cette UC.

## Hors périmètre

- La détection du profil de capacités (support ou non des deux axes séparés) → UC04 du MVP.
- Le pilotage du swing global sur les appareils sans axes séparés → UC06 du MVP (comportement inchangé).
- L'affichage en tuile dashboard des oscillations → `post-mvp/06-ergonomie-jeedom`.
- La recherche d'une méthode de relecture séparée sur un transport donné (fil HVAC, futur endpoint AUX
  Home…) relève de l'analyse technique de ce transport, pas de cette spec fonctionnelle.
