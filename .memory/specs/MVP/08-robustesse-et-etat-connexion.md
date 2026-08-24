# UC08 — Robustesse, expiration de session et diagnostic

> **Domaine** : MVP · **Statut** : à implémenter · **Dépend de** : UC07

## Objectif

Garantir que le plugin se rétablit seul face aux incidents inévitables — jeton de session expiré, cloud
AUX Home indisponible, coupure Internet, redémarrage de Jeedom ou du climatiseur — sans intervention
manuelle de l'utilisateur, et lui donner une vue claire de l'état de connexion pour comprendre ce qui se
passe sans avoir à lire les journaux bruts.

## Comportement attendu

Si un appel authentifié au cloud échoue pour une cause probable d'expiration de session, le plugin retente
une seule fois une reconnexion puis rejoue une seule fois la requête initiale, avec une garde qui limite
cette tentative à une seule par cycle (pas de boucle de re-connexion en rafale). Si le cloud est
indisponible ou si Internet est coupé, les équipements passent hors ligne proprement — valeurs conservées —
et reprennent automatiquement dès que la connectivité est rétablie, sans que l'utilisateur ait à intervenir.
Un redémarrage de Jeedom n'exige aucune reconfiguration : le plugin reprend son fonctionnement normal au
cycle suivant. Un redémarrage du climatiseur lui-même est détecté dès qu'il redevient joignable côté cloud.

Aucun secret (mot de passe, champ de compte chiffré, jeton complet) n'apparaît jamais dans un journal du
plugin, quel que soit son niveau, y compris dans le message d'une exception inattendue. La page de
configuration affiche, pour chaque équipement, un état de connexion explicite, le transport actif et la
fraîcheur de la dernière donnée reçue.

## Critères d'acceptation

- [ ] **AC1** — Après une invalidation du jeton de session (ex. délai suffisant écoulé, ou changement du
      mot de passe côté AUX Home), le cycle de rafraîchissement suivant reconnecte automatiquement le
      plugin sans action de l'utilisateur, et les équipements repassent « en ligne » avec un état à jour.
- [ ] **AC2** — Pendant un incident d'expiration de session, les journaux ne montrent qu'un nombre borné de
      tentatives de reconnexion par cycle — jamais une rafale de tentatives répétées.
- [ ] **AC3** — En coupant l'accès Internet du serveur Jeedom pendant un cycle, les équipements smartclim
      passent hors ligne sans provoquer d'erreur bloquante ailleurs dans Jeedom (le reste du système continue
      de fonctionner normalement), et un avertissement est visible dans les journaux.
- [ ] **AC4** — En rétablissant l'accès Internet, le cycle de rafraîchissement suivant fait automatiquement
      repasser les équipements en ligne avec un état à jour, sans action manuelle.
- [ ] **AC5** — Après un redémarrage du service Jeedom, le plugin reprend son fonctionnement normal
      (rafraîchissement, envoi de commandes) au cycle suivant, sans reconfiguration.
- [ ] **AC6** — Après extinction puis rallumage physique du climatiseur, le plugin le détecte de nouveau
      « en ligne » avec un état cohérent, dans un délai raisonnable (au plus quelques cycles de
      rafraîchissement).
- [ ] **AC7** — Après un scénario combiné (coupure réseau, puis expiration de session, puis redémarrage de
      Jeedom), l'examen complet des journaux du plugin (tous niveaux) ne révèle aucun mot de passe, jeton
      complet ou champ chiffré en clair, y compris dans un message d'exception.
- [ ] **AC8** — La page de configuration affiche, pour chaque équipement, un état de connexion explicite
      (en ligne / hors ligne / erreur), le transport actif, et l'âge de la dernière donnée reçue.
- [ ] **AC9** — Si le mot de passe du compte est changé côté AUX Home sans être mis à jour dans la
      configuration Jeedom, le plugin affiche un état d'erreur de connexion explicite et compréhensible
      (pas un simple « hors ligne » silencieux et inexpliqué).

## Impact i18n

- Nouvelles chaînes UI (français) anticipées : « État de connexion », « En ligne », « Hors ligne », « Erreur
  de connexion — vérifiez vos identifiants », « Transport actif », « Dernière donnée reçue il y a… ».

## À confirmer

- ⚠️ Durée de vie exacte du jeton de session AUX Home et code d'erreur précis signalant son expiration :
  non documentés, aucun refresh token n'existe côté AUX Home — `smartclim-transport-aux-home.md` § 2.3 et
  § 9. À défaut de valeur confirmée, traiter tout `code` d'erreur applicatif sur un appel authentifié comme
  une expiration possible (comportement déjà retenu par UC02/UC07).

## Hors périmètre

- Reprise après incident sur les transports LAN Broadlink et cloud legacy → post-mvp/01 et post-mvp/03.
- Notifications proactives (e-mail, push) en cas d'incident prolongé — non prévues au MVP.
- Reconnexion WebSocket / démon de communication persistante → post-mvp/05.
