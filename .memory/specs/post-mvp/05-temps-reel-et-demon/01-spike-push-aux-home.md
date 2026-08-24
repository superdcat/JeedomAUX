# UC01 — Spike : existence d'un canal de push sur le cloud AUX Home européen

> **Domaine** : post-mvp/05-temps-reel-et-demon · **Statut** : à implémenter · **Dépend de** : UC07 du MVP

## Objectif

Le socle MVP scrute le cloud AUX Home toutes les 5 minutes (UC07 du MVP) parce qu'aucun canal de push n'a
jamais été confirmé côté backend européen — seule une **hypothèse** d'analogie avec le broker MQTT du
backend cousin chinois existe (`smthomem2m.aux-home.com:8883`). Cette UC est un **spike** : elle vérifie
cette hypothèse par observation réelle du trafic de l'application AUX Home, **sans écrire de code de
production**, afin de trancher si l'introduction d'un démon temps réel pour ce transport est justifiée.

⚠️ Le résultat de ce spike **n'est pas bloquant pour le reste du domaine** : le cloud historique
(`AUX_CLOUD_LEGACY`) possède déjà un relais WebSocket **confirmé** (cf. UC03 de ce domaine), qui justifie à
lui seul la construction du démon. Cette UC ne fait que déterminer si le transport du **MVP** (AUX Home)
peut, en plus, bénéficier d'un push — pas si un démon doit exister.

## Comportement attendu

- Observation du trafic réseau de l'application AUX Home officielle pendant une session d'usage normal :
  ouverture, maintien en arrière-plan pendant une durée raisonnable, déclenchement d'un changement d'état
  depuis un **autre** canal que l'app elle-même (télécommande infrarouge, ou un second appareil connecté au
  même compte) pendant que l'app reste ouverte.
- Recherche spécifique d'une **connexion persistante** distincte des appels REST déjà connus vers
  `eu-smthome-api.aux-global.com` : connexion TLS maintenue ouverte vers un autre hôte, trafic périodique de
  type keep-alive, ou notification reçue par l'app sans requête REST visible immédiatement avant.
- Si un canal est identifié : consignation de l'hôte/domaine, du port, du ou des sujets/topics observés
  s'il s'agit de MQTT, et du mode d'authentification employé (jeton réutilisé du login REST, ou identifiants
  distincts).
- Si rien n'est identifié après une observation couvrant au moins un changement d'état déclenché par un
  canal externe : conclusion négative, argumentée par ce qui a été observé et pendant quelle durée.
- Le livrable est la **mise à jour des notes d'analyse existantes** (pas un nouveau composant du plugin) et
  une **décision explicite** consignée : on retient l'introduction d'un canal de push pour ce transport, ou
  on documente que ce transport reste en scrutation pour le temps réel.
- ⚠️ Aucun jeton de session, mot de passe, ni capture réseau brute n'est versionné dans le dépôt à aucune
  étape de ce spike.

## Critères d'acceptation

- [ ] **AC1** — À l'issue d'une session d'observation du trafic réseau de l'application AUX Home couvrant
      une action déclenchée depuis un canal externe (télécommande ou second appareil) pendant que l'app
      reste ouverte, la note d'analyse concernée n'est plus marquée comme hypothèse non vérifiée : elle
      affirme explicitement soit l'existence d'un canal persistant, soit son absence.
- [ ] **AC2** — Si un canal persistant est confirmé, la note documente au minimum l'hôte/domaine, le port,
      et le mode d'authentification observé — sans que cette UC ne produise de code exploitant ce canal.
- [ ] **AC3** — Si aucun canal n'est trouvé, la conclusion négative consignée est explicite et justifiée
      (nature de l'observation, durée, action déclenchante testée) — pas une simple absence de mention.
- [ ] **AC4** — Une décision explicite est consignée dans les notes d'analyse du domaine : introduire un
      canal de push pour le transport AUX Home, ou documenter le maintien en scrutation pour ce transport.
- [ ] **AC5** — À l'issue de ce spike, aucun fichier versionné du dépôt ne contient de jeton de session, de
      mot de passe, ni de capture réseau brute.

## Impact i18n

- Aucune chaîne UI n'est livrée par cette UC (spike de recherche, aucun composant fonctionnel produit).

## À confirmer

- Le protocole exact d'un éventuel canal (MQTT ou WebSocket) n'est pas connu à l'avance : c'est précisément
  l'objet de ce spike.
- L'existence même d'un équivalent européen du broker MQTT confirmé côté backend chinois reste, avant ce
  spike, une hypothèse forte non vérifiée — cf. `.memory/analyse/smartclim-transport-aux-home.md` § 7 et
  `.memory/analyse/smartclim-ecosysteme-aux-broadlink.md` § 7.
- Même en cas de canal confirmé, la donnée de température ambiante remontée par le backend AUX Home semble
  rafraîchie côté serveur avec un retard propre (`smartclim-transport-aux-home.md` § 6.4, jusqu'à ~30 min,
  y compris dans l'app officielle) : un push ne garantirait donc pas nécessairement un gain de fraîcheur sur
  ce champ précis, seulement sur les changements d'état commandés. À réévaluer avec les observations réelles
  de ce spike, pas supposé a priori.

## Hors périmètre

- L'exploitation effective d'un canal confirmé (code de production, intégration au démon) : si ce spike
  conclut positivement, l'exploitation du canal fait l'objet d'une UC future, non décrite dans ce domaine en
  l'état.
- Le socle du démon Python et le pont de communication PHP↔démon → UC02 de ce domaine (dont la construction
  ne dépend pas du résultat de ce spike, cf. « Objectif »).
- Le temps réel du cloud historique, déjà confirmé indépendamment → UC03 de ce domaine.
- Le transport local alternatif AUXLink → UC04 de ce domaine.
