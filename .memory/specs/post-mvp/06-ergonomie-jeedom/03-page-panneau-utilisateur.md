# UC03 — Page-panneau multi-climatiseurs au menu Jeedom

> **Domaine** : post-mvp/06-ergonomie-jeedom · **Statut** : à implémenter · **Dépend de** : UC01 de ce
> domaine (widget de commande « climatiseur »)

## Objectif

Donner aux utilisateurs **non-administrateurs** du foyer une vue d'ensemble de tous les climatiseurs
auxquels ils ont accès, directement depuis le menu d'accueil Jeedom, avec un pilotage rapide — sans avoir
à ouvrir un dashboard construit à la main ni à posséder les droits d'administration du plugin.

## Comportement attendu

- Une page dédiée apparaît dans le menu d'accueil de Jeedom (mécanisme natif du core, déclenché par la
  déclaration du plugin — rien à activer côté utilisateur au-delà des cases natives d'affichage du
  panneau, qui existent déjà indépendamment de cette UC).
- La page est utilisable par un utilisateur connecté **non-administrateur** : elle ne requiert pas de
  droits d'administration Jeedom pour s'afficher ni pour piloter les climatiseurs visibles.
- Pour chaque climatiseur, la page ne l'affiche que si l'utilisateur connecté dispose du **droit de
  lecture** sur cet équipement précis. Un climatiseur sur lequel l'utilisateur n'a aucun droit
  n'apparaît nulle part dans la page (ni en liste, ni en grisé, ni en aperçu partiel).
- Pour chaque climatiseur affiché, la page montre au minimum : l'état marche/arrêt, la température
  ambiante, la consigne, le mode, la vitesse de ventilation, le transport actif et la fraîcheur de la
  dernière donnée reçue — les mêmes informations que la tuile de dashboard (UC01), réutilisées pour cette
  vue d'ensemble.
- Depuis la page, l'utilisateur peut agir directement sur un climatiseur (marche/arrêt, consigne, mode,
  vitesse) sans être renvoyé vers une autre page.
- Un utilisateur disposant seulement du droit de **lecture** (pas d'écriture) sur un climatiseur voit son
  état dans la page, mais ne peut pas agir dessus depuis cette page.
- Un climatiseur hors-ligne ou en échec de communication reste visible dans la page (avec sa dernière
  donnée connue et une indication d'indisponibilité) : il ne fait jamais échouer l'affichage de la page
  entière.
- Aucune information sensible (jeton, mot de passe, identifiant de compte) n'apparaît jamais dans la page,
  quel que soit le nombre ou l'état des climatiseurs affichés.

## Critères d'acceptation

- [ ] **AC1** — Un utilisateur connecté avec une session **non-administrateur** accède à la page depuis le
      menu d'accueil de Jeedom et la page s'affiche normalement, sans redirection vers un écran
      d'authentification admin ni message de droits insuffisants.
- [ ] **AC2** — Pour un utilisateur disposant du droit de lecture sur 2 climatiseurs parmi 3 déclarés dans
      Jeedom, seuls ces 2 climatiseurs apparaissent dans la page ; le troisième n'apparaît sous aucune
      forme (pas de ligne vide, pas de nom masqué).
- [ ] **AC3** — Pour chaque climatiseur visible, la page affiche au minimum : marche/arrêt, température
      ambiante, consigne, mode, vitesse de ventilation, transport actif, et fraîcheur de la donnée.
- [ ] **AC4** — Depuis la page, un clic sur marche/arrêt (ou une modification de consigne/mode/vitesse)
      déclenche réellement la commande sur l'équipement concerné, visible sans quitter la page.
- [ ] **AC5** — Un utilisateur disposant du droit de lecture mais pas d'écriture sur un climatiseur voit son
      état affiché dans la page mais ne dispose d'aucun contrôle actionnable pour cet équipement (les
      commandes sont absentes ou sans effet).
- [ ] **AC6** — Le code HTML de la page et toute requête réseau qu'elle déclenche (résultat d'une action,
      rafraîchissement de l'état) ne contiennent à aucun moment un jeton de session cloud, un mot de
      passe, ni un identifiant de compte AUX.
- [ ] **AC7** — Un climatiseur hors-ligne au moment de l'affichage reste présent dans la page (avec sa
      dernière valeur connue et une indication qu'il est hors-ligne) ; il n'empêche pas l'affichage des
      autres climatiseurs ni ne provoque d'erreur de chargement de la page entière.
- [ ] **AC8** — Au chargement de la page, la console du navigateur ne signale aucune violation de politique
      de sécurité de contenu (CSP) et aucune ressource externe cassée.

## Impact i18n

- Nouvelles chaînes UI (français) anticipées : titre de la page (ex. « Mes climatiseurs »), « Aucun
  climatiseur accessible » (cas d'un utilisateur sans droit de lecture sur aucun équipement), « Hors
  ligne » ou équivalent pour l'indication d'indisponibilité. Les libellés déjà introduits pour la tuile
  (UC01) et les commandes (socle MVP) sont réutilisés tels quels.

## À confirmer

- Faut-il permettre à l'utilisateur de restreindre l'affichage de la page à un sous-ensemble des
  climatiseurs auxquels il a droit de lecture (ex. masquer un climatiseur qui ne l'intéresse pas dans cette
  vue), en plus du contrôle de droits déjà couvert par AC2 ? Piste non demandée explicitement dans le
  cadrage de cette UC ; à arbitrer — cf. `.memory/analyse/jeedom-panel-page-menu.md` § 4 (mécanisme déjà
  documenté côté Jeedom si la réponse est oui).
- Disposition exacte de la page quand le nombre de climatiseurs visibles est élevé (pagination,
  regroupement par pièce/objet parent…) : détail sans impact sur les AC ci-dessus.

## Hors périmètre

- ⚠️ Les cases natives **« Afficher le panneau desktop »** / **« Afficher le panneau mobile »** sont
  générées et gérées entièrement par le core Jeedom dès que la page est déclarée : elles ne sont **pas**
  spécifiées ni codées par cette UC.
- Le contenu et le comportement détaillé de la tuile agrégée (UC01) ne sont pas redéfinis ici : cette page
  la réutilise telle quelle.
- Le scan/la découverte des climatiseurs (UC02 de ce domaine) n'est pas accessible depuis cette page : le
  scan reste une opération d'administration, réservée à la page de configuration du plugin.
- L'arbitrage entre transports (AUTO/LOCAL/CLOUD) n'est pas traité ici : la page affiche le
  transport actif tel que déterminé ailleurs (`post-mvp/02-strategies-de-transport`).
