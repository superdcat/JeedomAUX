# UC03 — Résilience au changement d'adresse IP

> **Domaine** : post-mvp/02-strategies-de-transport · **Statut** : à implémenter · **Dépend de** : UC02 de
> ce domaine

## Objectif

Une box qui redémarre ou un bail DHCP qui se renouvelle peut changer l'adresse IP du climatiseur du jour au
lendemain. Sans traitement, ce cas se confond avec une vraie panne LAN et déclenche le repli cloud (UC02)
ou une perte de pilotage local en mode LOCAL, alors que l'appareil est parfaitement joignable — juste à une
autre adresse. Cette UC évite cette fausse alerte en relançant une découverte avant de conclure à
l'indisponibilité, et en mémorisant la nouvelle IP sans intervention de l'utilisateur.

## Comportement attendu

- Avant de déclarer un équipement injoignable en LAN (que ce soit pour déclencher le repli cloud de l'UC02
  en AUTO, ou pour signaler un échec en LOCAL), le plugin relance une **découverte par diffusion**
  et tente de rapprocher un appareil trouvé avec l'équipement concerné via sa **MAC** (identique ou
  inversée, cf. `smartclim-architecture-jeedom.md` § 4).
- Si un appareil correspondant est retrouvé à une IP différente de celle mémorisée, cette nouvelle IP est
  enregistrée **automatiquement** sur l'équipement, sans que l'utilisateur ait à intervenir.
- Le pilotage local reprend dès l'opération suivante avec la nouvelle IP, sans configuration manuelle.
- Si le réseau bloque la diffusion (VLAN) et qu'aucune correspondance n'est trouvée, l'équipement reste en
  secours pilotable en saisissant l'IP manuellement (comportement déjà prévu par la configuration
  d'équipement, cf. `smartclim-architecture-jeedom.md` § 3.2) — cette UC ne dégrade pas ce cas de secours.
- La documentation utilisateur recommande la réservation DHCP pour ce climatiseur, afin de limiter la
  fréquence de ce cas.

## Critères d'acceptation

- [ ] **AC1** — Avant de considérer un équipement (mode LOCAL ou AUTO) injoignable en LAN, une
      nouvelle découverte par diffusion est relancée.
- [ ] **AC2** — Si cette découverte retrouve un appareil de même MAC (ou MAC inversée) que l'équipement,
      mais sous une IP différente de celle mémorisée, l'équipement Jeedom correspondant voit sa nouvelle IP
      enregistrée automatiquement, sans action de l'utilisateur.
- [ ] **AC3** — *(Recette)* Changer l'adresse IP du climatiseur (renouvellement forcé du bail DHCP ou
      redémarrage de la box) → au cycle de rafraîchissement suivant (ou à la prochaine commande), le
      pilotage local reprend automatiquement, sans que l'utilisateur ait modifié la configuration de
      l'équipement.
- [ ] **AC4** — Une fois la nouvelle IP retrouvée et enregistrée, les opérations suivantes l'utilisent
      directement (pas de nouvelle découverte systématique tant qu'elle répond) — pas de dégradation de
      latence perceptible en fonctionnement normal.
- [ ] **AC5** — Si la diffusion est bloquée sur le réseau et qu'aucune correspondance MAC n'est trouvée,
      l'utilisateur peut toujours ressaisir manuellement l'IP de l'équipement sans perdre celui-ci ni ses
      commandes.
- [ ] **AC6** — La documentation utilisateur (`docs/fr_FR/`) mentionne explicitement la recommandation de
      réserver l'adresse IP du climatiseur dans la box/le serveur DHCP.

## Impact i18n

- Nouvelles chaînes UI (français) anticipées : aucune attendue côté commandes (le mécanisme est silencieux
  pour l'utilisateur en cas de succès) ; éventuel message de journal « Adresse IP mise à jour automatiquement
  pour <équipement> ».

## À confirmer

- Faut-il relancer la découverte à **chaque** échec LAN ou seulement après un nombre d'échecs donné,
  potentiellement pour ne pas interférer avec le compteur de l'UC02 ? L'analyse
  `smartclim-transport-broadlink-lan.md` § 8 recommande de « ré-exécuter une découverte broadcast avant de
  déclarer l'appareil injoignable », sans préciser la fréquence exacte.
- Le délai ajouté par une redécouverte broadcast (jusqu'à quelques secondes, cf.
  `smartclim-transport-broadlink-lan.md` § 1) avant de conclure à l'indisponibilité, à mettre en regard de
  l'exigence `.memory/brief.md` § 15 « ne jamais laisser une commande Jeedom bloquée longtemps ».
- Comportement en cas d'ambiguïté de rapprochement (plusieurs appareils candidats après inversion de MAC) —
  cf. l'algorithme de rapprochement en 4 étapes de `smartclim-architecture-jeedom.md` § 4, non détaillé côté
  gestion d'erreur.

## Hors périmètre

- Le protocole de découverte par diffusion lui-même → `post-mvp/01`.
- Le déclenchement du repli cloud en cas d'échec confirmé (non lié à un changement d'IP) → UC02.
- Le choix initial du mode de transport → UC01.
