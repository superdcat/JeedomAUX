# UC02 — Oscillations verticale et horizontale distinctes

> **Domaine** : post-mvp/04-fonctions-avancees · **Statut** : à implémenter · **Dépend de** : UC01 de ce
> domaine

## Objectif

> ⚠️ **Amendement du 2026-09-06 (cycle d'implémentation d'UC02)** — la rédaction d'origine affirmait que
> « le socle MVP (UC06) pilote l'oscillation des volets ». **C'est faux** : aucune commande de swing
> n'existe dans le plugin (les commandes action livrées sont `on`/`off`, `mode_*`, `fan_*`,
> `set_target_temp`, `refresh`, plus les fonctions de confort d'UC01). Cette UC n'ajoute donc pas un
> pilotage fin à un pilotage global : **elle introduit le pilotage d'oscillation tout court**. Le périmètre
> technique est inchangé ; seuls l'objectif ci-dessous et AC6 sont réécrits.

Le plugin ne pilote aujourd'hui **aucune** oscillation de volets. Certains appareils distinguent deux
axes indépendants — oscillation verticale (haut/bas) et horizontale (gauche/droite) — que l'utilisateur veut
pouvoir commander séparément (ex. orienter le flux horizontalement en fixe tout en laissant osciller
verticalement). Cette UC ajoute ce pilotage quand l'appareil le permet réellement, sans rien exposer sur
les appareils dont le support des deux axes n'est pas confirmé.

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
- Sur un équipement dont le support des deux axes n'est pas confirmé, aucune commande d'oscillation n'est
  créée par cette UC, et les commandes héritées du MVP et d'UC01 restent inchangées.

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
- [ ] **AC6** — Sur un équipement dont le profil de capacités ne confirme pas les axes, aucune commande
      « Oscillation verticale » / « Oscillation horizontale » n'apparaît — ni visible, ni masquée — et le
      comportement des commandes héritées du MVP et d'UC01 reste inchangé.
      ⚠️ *Amendé le 2026-09-06* : la rédaction d'origine parlait de « la commande de swing globale héritée
      du MVP », qui **n'existe pas**. Il n'y a donc rien à régresser ; ce que cet AC protège réellement,
      c'est qu'aucune commande n'apparaisse sur un appareil non confirmé — garanti par le marqueur
      `'confirme' => false` **et** par la garde de longueur de trame de `conceptsOscillables()`.
- [ ] **AC7** — L'écriture (commande action) sur un axe fonctionne de façon identique, que la relecture
      séparée de cet axe soit disponible ou non sur le transport actif de l'équipement.

## Impact i18n

- Nouvelles chaînes UI (français) anticipées : « Oscillation verticale », « Oscillation horizontale »,
  « État approximatif : relecture séparée non disponible sur ce transport » (ou libellé équivalent
  d'avertissement affiché à proximité de la commande info concernée).

## À confirmer

- ✅ **AMENDÉ le 2026-09-06 — la relecture séparée EST possible ; la prémisse ci-dessous était fausse.**
  La rédaction d'origine s'appuyait sur `octet[11] != 0x20` (§ 6.1 et § 9 de
  `.memory/analyse/smartclim-transport-aux-home.md`) pour conclure qu'aucune séparation n'existait. Or ce
  test est l'heuristique d'**une implémentation tierce** (`com.zwegersit.auxairco`), **pas** une limite du
  protocole : sur la trame réelle versionnée du 2026-08-26, il rend « oscillation active » sur un appareil
  **éteint** dont le champ déclare « arrêt ». Les deux axes sont en réalité **deux champs distincts** —
  vertical = octet 10 bits 2-0, horizontal = octet 11 bits 7-5. **AC4 est donc atteignable.**
  ⚠️ Il n'est pas pour autant **acquis** : le champ horizontal repose sur **un seul échantillon**, sur un
  appareil éteint, et n'a **jamais été vu varier**. UC02 le livre donc décodé mais **non exposé**
  (marqueur `'lecture' => false`), et c'est AC5 qui porte le comportement tant que la mesure sur matériel
  n'a pas confirmé qu'il bascule dans les deux sens.
- Le sens « oscille »/« fixe » de chaque axe (valeurs `0`/`7` côté AUX Home et fil HVAC, contradiction
  `ac_vdir`/`ac_hdir` `0` vs `1` côté cloud legacy) n'est pas déterminant pour cette spec fonctionnelle — il
  conditionne l'implémentation technique, pas le contrat observable par l'utilisateur (une bascule doit
  produire le bon effet, quel que soit le code interne utilisé). Cf.
  `.memory/analyse/smartclim-modele-abstrait-capacites.md` § 3.3 et
  `.memory/analyse/smartclim-transport-aux-cloud-legacy.md` § 5.1.
- ⚠️ *Amendé le 2026-09-06* : l'affirmation d'origine — « aucune source analysée ne documente un moyen de
  relecture séparée fiable, sur aucun transport » — est **caduque** et contredisait le point ci-dessus. Le
  champ existe et se décode ; ce qui manque n'est plus une **méthode**, c'est une **mesure sur matériel**
  (protocole au § 11 de la spec technique). La distinction compte : livrer AC4 ne demande plus une
  recherche, seulement une validation.

## Hors périmètre

- La détection du profil de capacités (support ou non des deux axes séparés) → UC04 du MVP.
- Le pilotage d'un swing **global** (un seul interrupteur pour les deux axes) : sans objet — il n'en existe
  aucun dans le plugin, et cette UC n'en introduit pas.
- L'affichage en tuile dashboard des oscillations → `post-mvp/06-ergonomie-jeedom`.
- La recherche d'une méthode de relecture séparée sur un transport donné (fil HVAC, futur endpoint AUX
  Home…) relève de l'analyse technique de ce transport, pas de cette spec fonctionnelle.
