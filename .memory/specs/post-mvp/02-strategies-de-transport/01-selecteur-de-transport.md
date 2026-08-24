# UC01 — Choix du mode de transport par équipement

> **Domaine** : post-mvp/02-strategies-de-transport · **Statut** : à implémenter · **Dépend de** : UC03 de
> `post-mvp/01` (écriture LAN disponible)

## Objectif

Certains utilisateurs veulent un climatiseur qui « fonctionne tout seul » sans se soucier du réseau ;
d'autres veulent la garantie qu'aucune donnée ne sorte de leur réseau local, ou au contraire qu'aucune
requête locale ne perturbe un appareil déjà géré ailleurs. Cette UC offre, **par équipement**, le choix
entre trois stratégies de transport (AUTO / LOCAL / CLOUD), avec un mode **AUTO par défaut** qui détecte
lui-même la meilleure option sans configuration. C'est le socle sur lequel s'appuient le repli automatique
(UC02) et la résilience au changement d'IP (UC03).

## Comportement attendu

- Chaque équipement `smartclim` porte un réglage « Mode de transport » à 3 valeurs. Un équipement
  nouvellement créé par la découverte reçoit **AUTO** par défaut.
- **AUTO** — le mode « ça marche tout seul », qui **priorise toujours le LAN quand il est disponible** :
  - au premier pilotage puis périodiquement, l'équipement sonde sa joignabilité en LAN ; s'il répond en LAN,
    il est piloté en LAN ;
  - s'il ne répond pas en LAN, il est piloté via le cloud auquel il est rattaché (AUX Home au MVP) ;
  - en cas d'**échecs LAN répétés** sur un équipement jusque-là joignable localement, il **bascule
    automatiquement sur le cloud** puis **revient au LAN** dès que celui-ci redevient joignable — seuils,
    temporisation et mécanique de retour sont spécifiés par l'UC02 ;
  - ⚠️ une MAC d'origine Broadlink qui ne répond pas en LAN (firmwares récents, cf.
    `smartclim-ecosysteme-aux-broadlink.md` § 1) est un **résultat de sonde nominal**, pas une panne : aucune
    erreur ne doit être remontée à l'utilisateur pour ce cas, l'équipement bascule simplement sur son cloud ;
  - un équipement **sans identifiant cloud** configuré ne tente jamais le cloud en AUTO : il se comporte
    alors comme LOCAL (échec propre plutôt qu'erreur de configuration cloud).
- **LOCAL** : communication exclusivement en LAN Broadlink. Aucune requête cloud n'est jamais émise, y
  compris en cas d'échec LAN répété. Doit fonctionner **intégralement sans accès Internet**.
- **CLOUD** : communication exclusivement via le cloud rattaché à l'équipement. Aucun paquet LAN n'est
  jamais émis, même si l'équipement est par ailleurs joignable localement.
- Quel que soit le mode, le transport **réellement utilisé** pour la dernière opération réussie est exposé
  à l'utilisateur (commande info + IHM d'administration) — cf. `.memory/brief.md` § 4 « le plugin doit afficher
  clairement quel transport est actuellement utilisé ».
- Changer de mode sur un équipement est une opération **non destructive** : aucune commande n'est
  recréée/renommée/supprimée, aucun scénario existant n'est affecté par le changement.

## Critères d'acceptation

- [ ] **AC1** — Sur la fiche de chaque équipement, un réglage « Mode de transport » propose exactement
      trois valeurs (AUTO, LOCAL, CLOUD) ; un équipement fraîchement créé par le scan est en AUTO.
- [ ] **AC2** — Un équipement en AUTO dont le LAN a été détecté joignable est piloté en LAN sans
      configuration supplémentaire ; un équipement en AUTO dont le LAN a été détecté injoignable est piloté
      via son cloud, **sans qu'aucun message d'erreur** ne soit affiché pour ce fonctionnement nominal.
- [ ] **AC3** — Un équipement en AUTO sans identifiant cloud configuré ne déclenche jamais de tentative
      cloud (aucune erreur « cloud injoignable/non configuré » ne doit apparaître) ; ses commandes échouent
      proprement si le LAN est indisponible, comme en mode LOCAL.
- [ ] **AC4** — En mode LOCAL, débrancher la connexion Internet (WAN) du Jeedom n'empêche pas de piloter
      l'équipement (marche, arrêt, changement de consigne) tant que le LAN reste opérationnel — à vérifier
      en recette en coupant physiquement/logiquement l'accès WAN.
- [ ] **AC5** — En mode CLOUD, aucune requête LAN n'est émise vers l'équipement, y compris s'il est
      joignable localement — vérifiable par une observation du trafic réseau local (absence de paquets
      vers l'IP/le port de l'équipement) pendant une commande.
- [ ] **AC6** — Le transport actif (celui de la dernière lecture/commande réussie) est visible via une
      commande info dédiée sur l'équipement et affiché dans l'IHM d'administration du plugin.
- [ ] **AC7** — Changer le mode de transport d'un équipement (dans n'importe quel sens) ne modifie aucun
      `logicalId` de commande existant ; un scénario Jeedom référençant une commande de cet équipement
      continue de fonctionner sans modification après le changement de mode.
- [ ] **AC8** — En AUTO, dès lors que le LAN est joignable, il est utilisé **en priorité sur le cloud**
      pour toute opération, y compris la première commande suivant la découverte de l'équipement ; le
      comportement en cas d'échecs LAN répétés est celui décrit par l'UC02.

## Impact i18n

- Nouvelles chaînes UI (français) anticipées : « Mode de transport », « Automatique », « Local », « Cloud »,
  « Transport actif », « Non déterminé ».

## Décisions actées

- ✅ **Trois modes, pas quatre : le mode HYBRID est abandonné** (arbitrage utilisateur du 2026-08-24). Le
  `.memory/brief.md` § 4 décrivait AUTO et HYBRID en des termes très proches (LAN prioritaire, repli cloud
  éventuel) ; les distinguer aurait imposé à l'utilisateur un choix sans bénéfice observable. **AUTO absorbe
  intégralement le comportement HYBRID** : priorité LAN, repli cloud sur échecs répétés, retour au LAN dès
  qu'il redevient joignable. LOCAL et CLOUD restent les deux modes d'exclusion explicite, pour qui veut
  garantir qu'aucune donnée ne sort du réseau local, ou qu'aucun paquet LAN n'est émis.

## À confirmer

- La cadence de re-sonde périodique du LAN en mode AUTO (à chaque cycle de rafraîchissement, une fois par
  jour, à la demande…) est une décision d'implémentation, cf. `smartclim-architecture-jeedom.md` § 6
  (crons) — non tranchée ici.
- Libellés exacts affichés pour le transport actif (« AUX Home », « LAN », « AUX Cloud legacy »…) — laissés
  à la spec technique / i18n.

## Hors périmètre

- L'implémentation des protocoles LAN (`post-mvp/01`) et cloud legacy (`post-mvp/03`) eux-mêmes.
- L'algorithme précis de bascule sur échecs répétés, ses compteurs et sa temporisation → UC02.
- La résilience au changement d'adresse IP DHCP → UC03.
- L'affichage riche du transport dans une tuile de dashboard → `post-mvp/06-ergonomie-jeedom`.
