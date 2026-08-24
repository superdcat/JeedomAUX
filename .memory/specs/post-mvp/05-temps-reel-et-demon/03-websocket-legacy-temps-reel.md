# UC03 — Temps réel du cloud historique via le relais WebSocket

> **Domaine** : post-mvp/05-temps-reel-et-demon · **Statut** : à implémenter · **Dépend de** : UC02 de ce
> domaine et UC03 du domaine post-mvp/03-cloud-aux-legacy (lecture et écriture des paramètres legacy)

## Objectif

Le cloud historique AC Freedom / AUX Cloud est le **seul transport de tout l'écosystème SmartClim à
exposer un canal de push confirmé** : le relais WebSocket `apprelay/relayconnect`. Cette UC l'exploite pour
répercuter dans Jeedom, en quelques secondes, tout changement d'état d'un climatiseur legacy — qu'il vienne
de la télécommande infrarouge, de l'application officielle, ou d'un autre système — au lieu d'attendre le
prochain cycle de scrutation. Elle réduit la latence perçue sans remettre en cause le pilotage par
commandes déjà livré (UC03 du domaine post-mvp/03-cloud-aux-legacy), qui continue de fonctionner à
l'identique, avec ou sans push actif.

## Comportement attendu

- Le démon (UC02 de ce domaine) établit et maintient une connexion au relais temps réel du cloud historique
  pour chaque équipement/compte de ce transport configuré, avec un **maintien de session périodique**
  conforme au protocole du relais.
- Toute anomalie de la session (réponse d'erreur du relais, absence de réponse au maintien de session,
  coupure de la connexion) déclenche une **fermeture propre** de la connexion puis une **reconnexion
  temporisée**, sans jamais provoquer de boucle de reconnexion effrénée.
- La reconnexion se produit aussi bien après une coupure réseau temporaire qu'après un redémarrage complet
  de Jeedom, sans action de l'utilisateur.
- Un changement d'état poussé par le relais pour un équipement connu de Jeedom met à jour l'état affiché de
  cet équipement dans un délai de l'ordre de quelques secondes après l'action réelle sur le climatiseur —
  au lieu du délai du cycle de scrutation habituel.
- Un état poussé par le relais, mais daté d'avant une commande optimiste récente (période de grâce déjà
  retenue au socle MVP, UC07 du MVP), n'écrase jamais cette valeur plus récente.
- Quand le push fonctionne effectivement pour un équipement, la fréquence de scrutation cron appliquée à cet
  équipement est automatiquement réduite : le push devient la source principale de fraîcheur, la scrutation
  espacée restant un filet de sécurité.
- Cas dégradé — démon arrêté ou relais injoignable : les équipements de ce transport continuent d'être
  rafraîchis par la scrutation cron standard, sans dégradation au-delà de la perte du push.

## Critères d'acceptation

- [ ] **AC1** — Une fois le démon démarré et un équipement de ce transport configuré, la connexion au
      relais temps réel est établie et confirmée par le relais ; les journaux du plugin ou du démon en
      attestent.
- [ ] **AC2** — Un changement d'état effectué depuis la télécommande infrarouge ou depuis l'application
      officielle sur un climatiseur legacy connu de Jeedom apparaît dans Jeedom en quelques secondes, sans
      attendre le cycle de scrutation cron suivant.
- [ ] **AC3** — En coupant temporairement l'accès réseau du serveur Jeedom puis en le rétablissant, la
      connexion au relais se rétablit automatiquement, sans action manuelle.
- [ ] **AC4** — Après un redémarrage complet de Jeedom, la connexion au relais est automatiquement
      rétablie au redémarrage du démon, sans reconfiguration de la part de l'utilisateur.
- [ ] **AC5** — Juste après l'envoi d'une commande sur un équipement de ce transport, un événement poussé
      par le relais relayant encore l'état antérieur à cette commande n'écrase pas la valeur commandée
      affichée dans Jeedom, tant que la période de grâce n'est pas écoulée.
- [ ] **AC6** — Avec le push actif et stable sur un équipement, l'intervalle de scrutation cron effectif
      appliqué à cet équipement est constatablement plus espacé qu'en l'absence de push.
- [ ] **AC7** — En arrêtant le démon, les équipements de ce transport continuent d'être rafraîchis par le
      cycle de scrutation cron standard (retour au comportement d'avant cette UC), sans blocage ni erreur
      visible ailleurs dans Jeedom.

## Impact i18n

- Nouvelles chaînes UI (français) anticipées : « Temps réel actif », « Connexion au relais perdue »,
  « Reconnexion en cours » — venant compléter l'affichage de l'état de connexion déjà introduit au socle
  MVP (UC08 du MVP) et pour le transport legacy.

## À confirmer

- Le schéma exact des messages poussés par le relais, au-delà du message de maintien de session, n'est pas
  documenté dans les sources analysées : la structure d'un événement de changement d'état réel reste à
  établir par observation directe au moment de l'implémentation. Cf.
  `.memory/analyse/smartclim-transport-aux-cloud-legacy.md` § 6 et § 8.
- La périodicité exacte du maintien de session et le délai de temporisation avant reconnexion observés dans
  les implémentations de référence sont des valeurs indicatives, pas des contraintes fonctionnelles figées
  par cette spec — à valider/ajuster en recette.
- Le nouvel intervalle de scrutation appliqué quand le push est actif n'a pas de valeur cible fixée ici (relève
  du plan technique).

## Hors périmètre

- Le pilotage par commandes du transport legacy (lecture/écriture des paramètres) : déjà couvert par UC03 du
  domaine post-mvp/03-cloud-aux-legacy, inchangé par cette UC.
- Un éventuel canal de push sur le transport AUX Home du socle MVP → conditionné par le verdict de UC01 de
  ce domaine, non traité ici.
- Le transport local alternatif AUXLink → UC04 de ce domaine.
- Le socle du démon lui-même (dépendances, cycle de vie, pont de communication) → UC02 de ce domaine.
