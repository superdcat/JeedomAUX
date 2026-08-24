# UC02 — Lecture de l'état et de la température ambiante en LAN

> **Domaine** : post-mvp/01-transport-broadlink-lan · **Statut** : à implémenter · **Dépend de** : UC01 de
> ce domaine (découverte broadcast, authentification et session locale)

## Objectif

Offrir, pour un climatiseur joignable en LAN, une lecture de son état complet et de sa température
ambiante directement depuis l'appareil, sans transiter par le cloud — condition nécessaire à l'usage
« pilotage sans Internet » demandé par le projet.

## Comportement attendu

- Une fois une session établie (UC01), le plugin interroge l'appareil — au rafraîchissement périodique du
  socle MVP ou à la demande (« Rafraîchir ») — pour obtenir son état (marche/arrêt, mode, consigne,
  vitesse, oscillations, options de confort) et sa température ambiante.
- L'état lu est traduit dans le vocabulaire générique du plugin en réutilisant le **même décodeur de
  trame** que celui du transport cloud AUX Home — pas un second décodeur propre au LAN.
- Un champ non décodable par ce transport ne remonte jamais de valeur inventée : il reste absent/inconnu
  plutôt que faussé.
- En cas de non-réponse (timeout), le plugin retente une fois, après ré-authentification, avant de
  considérer la lecture en échec pour ce cycle ; l'équipement conserve alors sa dernière valeur connue et
  est signalé comme non rafraîchi, sans interrompre le cycle des autres équipements.
- Le profil de capacités de l'équipement est renseigné pour le transport LAN (quelles informations sont
  disponibles par ce chemin).

## Critères d'acceptation

- [ ] **AC1** — Pour un climatiseur en LAN disponible, après un rafraîchissement, les commandes d'info
      (marche/arrêt, mode, consigne, vitesse, oscillations, options visibles) affichent des valeurs
      cohérentes avec l'état réel constaté sur l'appareil ou à sa télécommande.
- [ ] **AC2** — La température ambiante affichée correspond, à la résolution annoncée près, à la
      température réellement ressentie/mesurée à proximité de l'appareil (pas de valeur aberrante ou
      tronquée).
- [ ] **AC3** — Pour un même climatiseur joignable à la fois en LAN et en cloud AUX Home, l'état affiché
      par les deux voies est identique à un instant donné (aux délais de rafraîchissement respectifs
      près) — preuve que le décodeur est bien mutualisé entre les deux transports.
- [ ] **AC4** — Une coupure temporaire de l'appareil (éteint/rallumé, ou Wi-Fi coupé un instant) n'empêche
      pas le rafraîchissement des autres équipements du plugin ; au retour de l'appareil, la lecture
      reprend normalement au cycle suivant.
- [ ] **AC5** — La page de configuration de l'équipement affiche, pour un appareil utilisant le LAN, un
      profil de capacités cohérent avec les commandes effectivement créées (aucune commande créée sans
      être couverte par ce profil).

## Impact i18n

- Aucune chaîne UI majeure au-delà de celles déjà couvertes par les commandes d'info génériques du socle
  MVP. Éventuellement un statut « Dernière lecture LAN » s'il est affiché distinctement du cloud.

## À confirmer

- ⚠️ **Position exacte du bit de demi-degré de la consigne en lecture** (octet 12 ou 14 selon la
  référence) — divergence entre implémentations, à trancher par mesure sur l'appareil avant de figer le
  décodeur. Cf. `.memory/analyse/smartclim-transport-broadlink-lan.md` § 5.2 et § 10.
- ⚠️ **Position exacte du bit d'oscillation horizontale en lecture** (octet 12 ou 13 selon la référence) —
  même remarque, même fichier.
- Ces deux points n'empêchent pas de livrer l'UC mais conditionnent l'exactitude de la valeur affichée ;
  à vérifier en recette sur un appareil réel avant de considérer l'UC totalement close.

## Hors périmètre

- Le choix du transport utilisé pour afficher l'état quand plusieurs sont disponibles (LAN vs cloud) est
  du ressort de `post-mvp/02-strategies-de-transport`. Cette UC lit un état ; elle n'arbitre pas entre
  transports.
