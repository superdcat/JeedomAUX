# UC01 — Widget de commande « climatiseur »

> **Domaine** : post-mvp/06-ergonomie-jeedom · **Statut** : à implémenter · **Dépend de** : UC06 du MVP
> (commandes action)

## Objectif

Remplacer, sur le dashboard, la liste brute des commandes d'un climatiseur par une **tuile unique**
regroupant l'état, la consigne, le mode et la vitesse de ventilation. L'utilisateur pilote son climatiseur
d'un coup d'œil plutôt que de chercher la bonne ligne de commande parmi une dizaine, et voit immédiatement
d'où vient la donnée affichée (transport actif, fraîcheur) sans ouvrir la page d'administration.

## Comportement attendu

- Quand un climatiseur a ses commandes créées (socle MVP), le plugin pose automatiquement le widget
  « climatiseur » sur les commandes concernées, à la fois pour le dashboard et pour l'application mobile.
- La tuile regroupe au minimum : marche/arrêt, température ambiante, consigne (avec possibilité de la
  modifier), mode (avec possibilité de le changer) et vitesse de ventilation (avec possibilité de la
  changer). Elle affiche aussi le transport actif et la fraîcheur de la dernière donnée reçue.
- La tuile ne montre que les contrôles correspondant à des capacités réellement présentes sur
  l'équipement : un mode ou une vitesse non supportés par ce climatiseur ne proposent pas de bouton
  correspondant sur la tuile. Un équipement ne possédant pas la lecture de température ambiante n'affiche
  pas ce champ.
- Le comportement et les informations disponibles sont les mêmes sur le dashboard desktop et sur
  l'application mobile ; seule la présentation s'adapte au format d'écran.
- La tuile fonctionne pour tout utilisateur connecté disposant du droit d'usage sur l'équipement, pas
  seulement pour un administrateur.
- Si l'utilisateur a déjà personnalisé l'affichage d'une commande (choisi un autre widget à la main), ce
  choix n'est jamais écrasé par une resynchronisation ultérieure du plugin.
- La tuile ne charge et n'affiche aucune ressource externe (image, police, icône distante) : tout ce
  qu'elle montre est natif au navigateur ou embarqué dans le plugin.

## Critères d'acceptation

- [ ] **AC1** — Sur le dashboard, un climatiseur nouvellement créé (ou resynchronisé) affiche une tuile
      unique regroupant état marche/arrêt, température ambiante, consigne, mode et vitesse de
      ventilation — au lieu d'une liste de commandes séparées.
- [ ] **AC2** — Un clic sur le contrôle marche/arrêt de la tuile déclenche réellement la commande
      correspondante sur l'équipement, et l'état affiché change sans quitter la tuile ni recharger la
      page.
- [ ] **AC3** — Sur un équipement ne supportant pas tous les modes possibles (ex. pas de mode « Sec »),
      seuls les modes réellement supportés apparaissent comme choix sur la tuile ; aucun bouton de mode
      absent n'est visible, même désactivé.
- [ ] **AC4** — Le même constat s'applique aux vitesses de ventilation : seules les vitesses supportées par
      l'équipement sont proposées sur la tuile.
- [ ] **AC5** — La consigne de température est modifiable directement depuis la tuile, dans les bornes
      (minimum/maximum/pas) propres à cet équipement ; une tentative de dépasser ces bornes est refusée ou
      ramenée à la limite, jamais silencieusement acceptée au-delà.
- [ ] **AC6** — La tuile affiche lisiblement le transport actuellement utilisé (ex. « AUX Home ») et une
      indication de fraîcheur de la donnée (horodatage de la dernière mise à jour ou équivalent du type
      « il y a 3 min »).
- [ ] **AC7** — Sur un équipement ne disposant pas d'une capacité optionnelle (ex. pas de balayage), aucun
      contrôle correspondant n'apparaît sur la tuile, actif ou non — la tuile s'adapte réellement au
      profil de capacités de l'équipement.
- [ ] **AC8** — Sur l'application mobile, la même tuile propose les mêmes informations et les mêmes actions
      qu'au dashboard desktop (adaptées à l'écran mobile), sans fonctionnalité manquante par rapport au
      desktop.
- [ ] **AC9** — Après qu'un utilisateur a choisi manuellement un autre widget que celui du plugin sur une
      commande de climatiseur, une resynchronisation ultérieure (nouveau scan, mise à jour de capacités)
      ne réapplique pas le widget par défaut : le choix de l'utilisateur est conservé. À l'inverse, un
      équipement dont aucun widget n'a jamais été choisi obtient bien la tuile à sa création.
- [ ] **AC10** — Un utilisateur connecté non-administrateur, disposant du droit d'usage sur l'équipement,
      peut piloter entièrement la tuile (marche/arrêt, consigne, mode, vitesse) depuis son propre
      dashboard.
- [ ] **AC11** — Au chargement du dashboard affichant la tuile, la console du navigateur ne signale aucune
      erreur de violation de politique de sécurité de contenu (CSP) et aucune ressource externe cassée
      n'apparaît sur la tuile.

## Impact i18n

- Nouvelles chaînes UI (français) anticipées : « Consigne », « Transport », « Dernière mise à jour »,
  libellés de mode et de vitesse si non déjà couverts par le socle MVP (ex. « Auto », « Frais », « Sec »,
  « Chaud », « Ventilation », « Silencieux », « Turbo »). Les libellés déjà introduits au MVP pour les
  commandes elles-mêmes sont réutilisés tels quels sur la tuile.

## À confirmer

- Présentation exacte de la tuile (disposition des contrôles, icônes utilisées) : détail visuel sans
  impact sur les critères d'acceptation, à trancher à l'implémentation en respectant l'absence de
  ressource externe (cf. `.memory/analyse/jeedom-widgets-commandes.md` § 7).
- Sort des capacités secondaires non listées ici (éco, veille, affichage, verrouillage enfant, anti-
  moisissure…) : restent-elles sur des commandes standard à côté de la tuile, ou intégrées à une tuile
  étendue ? Cf. section « Hors périmètre ».
- Mécanisme précis de résolution des commandes sœurs et d'application « si vide » du widget : déjà
  documenté et à réutiliser tel quel, cf. `.memory/analyse/jeedom-widgets-commandes.md` §§ 3 et 6 (aucune
  invention à faire ici).

## Hors périmètre

- La **création** des commandes elles-mêmes (logicalId, subType, bornes de consigne) appartient au socle
  MVP (UC06) ; cette UC habille des commandes déjà existantes, elle n'en crée aucune.
- L'intégration des capacités secondaires (balayage, éco, veille, affichage, anti-moisissure, verrouillage
  enfant, etc.) dans la tuile n'est pas couverte ici : elles restent accessibles via les commandes
  standard du dashboard tant qu'une UC ultérieure ne les intègre pas explicitement à la tuile.
- L'arbitrage entre transports (AUTO/LOCAL/CLOUD) n'est pas traité ici : la tuile affiche le
  transport actif tel que déterminé ailleurs (`post-mvp/02-strategies-de-transport`), elle ne le choisit
  pas.
- La page-panneau multi-climatiseurs (vue d'ensemble pour utilisateur non-admin) est une UC distincte
  (UC03 de ce domaine), qui réutilise cette tuile mais n'est pas définie ici.
