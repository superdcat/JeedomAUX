# UC02 — Scan unifié LAN + AUX Home + legacy avec fusion

> **Domaine** : post-mvp/06-ergonomie-jeedom · **Statut** : à implémenter · **Dépend de** : UC04 du domaine
> post-mvp/01-transport-broadlink-lan (fusion LAN/cloud) et UC02 du domaine post-mvp/03 (découverte legacy)

## Objectif

Offrir à l'utilisateur un **seul bouton** « Scanner les climatiseurs » qui interroge les trois sources
possibles (réseau local, cloud AUX Home, cloud historique) et lui restitue un tableau récapitulatif
complet et fusionné, sans qu'il ait à lancer trois scans séparés ni à démêler lui-même les doublons entre
un appareil vu en LAN et le même appareil vu dans un cloud.

## Comportement attendu

- Le bouton « Scanner les climatiseurs » (existant au socle MVP pour le cloud AUX Home) interroge
  désormais, dans la même opération, les trois sources : réseau local (Broadlink), cloud AUX Home, cloud
  historique — chacune uniquement si elle est configurée/pertinente (ex. pas de tentative cloud legacy si
  aucun identifiant legacy n'a été renseigné).
- Les résultats des trois sources sont **fusionnés** avant affichage : un climatiseur vu à la fois en LAN
  et dans un cloud n'apparaît qu'**une seule fois** dans le tableau, avec les informations des deux
  origines rassemblées.
- Le tableau de résultats présente, par appareil : nom, marque si connue, modèle, MAC, IP, identifiant
  cloud, cloud d'appartenance, disponibilité LAN, état en ligne, et capacités détectées.
- Si une source échoue (identifiants invalides, service cloud injoignable, réseau local inaccessible),
  les deux autres sources produisent quand même leurs résultats ; l'échec de la source en question est
  affiché clairement dans le compte-rendu du scan, jamais passé sous silence.
- Aucune information sensible (jeton de session, mot de passe, identifiant de compte) n'apparaît dans le
  tableau de résultats ni dans le compte-rendu de scan.
- Relancer le scan à l'identique (aucun changement de parc matériel) ne crée ni doublon ni nouvel
  équipement, et ne réinitialise aucun réglage déjà personnalisé par l'utilisateur sur un équipement
  existant (nom, mode de transport choisi, visibilité des commandes…).
- Pour chaque appareil du tableau, l'utilisateur peut distinguer clairement par quelle(s) voie(s) il est
  joignable (LAN oui/non, AUX Home oui/non, cloud historique oui/non), et non une case unique ambiguë.

## Critères d'acceptation

- [ ] **AC1** — Un clic sur « Scanner les climatiseurs » déclenche une interrogation des trois sources
      disponibles et produit un unique tableau de résultats, pas trois listes séparées.
- [ ] **AC2** — Un climatiseur détecté à la fois en LAN et dans le cloud AUX Home apparaît en **une seule
      ligne** du tableau, portant à la fois son IP/MAC (origine LAN) et son identifiant cloud (origine AUX
      Home) — aucune ligne dupliquée pour ce même appareil.
- [ ] **AC3** — Si les identifiants du cloud historique sont invalides (ou le service injoignable) pendant
      le scan, le tableau affiche quand même les appareils trouvés en LAN et via AUX Home, et une mention
      visible signale l'échec de la source cloud historique (le scan ne s'interrompt pas et n'affiche pas
      un résultat vide).
- [ ] **AC4** — Le tableau de résultats et tout élément affiché à l'écran à l'issue du scan ne contiennent
      à aucun moment un jeton de session, un mot de passe, ni un identifiant de compte complet.
- [ ] **AC5** — Lancer un second scan immédiatement après un premier scan réussi, sans changement de parc,
      produit exactement le même nombre d'équipements Jeedom (aucun doublon créé), et un réglage
      personnalisé au préalable sur un équipement existant (ex. nom renommé, mode de transport forcé en
      LOCAL) reste inchangé après ce second scan.
- [ ] **AC6** — Pour chaque ligne du tableau, il est possible de lire séparément la disponibilité LAN,
      la disponibilité AUX Home et la disponibilité du cloud historique — ces trois informations sont
      distinguables, pas résumées en un unique statut global.
- [ ] **AC7** — Un climatiseur vu par le cloud historique et par le LAN, dont la MAC est rapportée dans un
      ordre d'octets inversé par l'une des deux sources, est reconnu comme le même appareil (une seule
      ligne), pas comme deux appareils distincts.
- [ ] **AC8** — Les capacités affichées pour un appareil dans le tableau reflètent celles réellement
      détectées par au moins une des sources ayant répondu, même si une autre source a échoué pour cet
      appareil.

## Impact i18n

- Nouvelles chaînes UI (français) anticipées : « Marque », « Modèle », « Identifiant cloud », « Cloud »,
  « Disponibilité LAN », « État en ligne », « Capacités détectées », « Échec de la source » (ou équivalent
  par source : « LAN indisponible », « AUX Home indisponible », « Cloud historique indisponible »).
  « Scanner les climatiseurs » existe déjà au socle MVP et est réutilisé tel quel.

## À confirmer

- Format exact de la colonne « identifiant cloud » quand un appareil possède à la fois un identifiant AUX
  Home et un identifiant cloud historique (deux valeurs affichées, ou un seul identifiant « principal » ?)
  — cf. `.memory/analyse/smartclim-architecture-jeedom.md` § 3.2.
- Distinction à l'affichage entre une source « non configurée » (ex. aucun identifiant legacy renseigné,
  scan légitimement non tenté) et une source « en échec » (tentative faite, a échoué) : les deux ne
  devraient pas produire le même message à l'utilisateur, à trancher à l'implémentation.
- Algorithme précis de rapprochement des doublons (MAC normalisée, MAC inversée, identifiant déjà
  mémorisé) : déjà tranché, cf. `.memory/analyse/smartclim-architecture-jeedom.md` § 4 — à réutiliser tel
  quel, pas à redéfinir dans cette UC.

## Hors périmètre

- Les protocoles de découverte eux-mêmes (diffusion Broadlink LAN, appairage au cloud historique) sont
  implémentés par les UC dont dépend celle-ci (`post-mvp/01` UC04, `post-mvp/03` UC02) ; cette UC orchestre
  l'appel conjoint aux trois sources et la présentation fusionnée, elle ne réimplémente aucun protocole de
  découverte.
- Le choix du transport effectivement utilisé pour piloter un climatiseur une fois scanné (LOCAL vs CLOUD
  vs AUTO) relève de `post-mvp/02-strategies-de-transport`, pas de cette UC.
- La configuration des identifiants de comptes (AUX Home, cloud historique) est un réglage de la page de
  configuration du plugin, déjà couvert ailleurs ; cette UC ne modifie pas ce formulaire.
