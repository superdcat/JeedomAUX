# UC07 — Rafraîchissement périodique et rafraîchissement manuel

> **Domaine** : MVP · **Statut** : à implémenter · **Dépend de** : UC06

## Objectif

Synchroniser automatiquement Jeedom avec l'état réel des climatiseurs, y compris lorsque le changement
provient d'ailleurs que de Jeedom (télécommande infrarouge, application AUX Home), sans multiplier les
appels réseau et sans jamais laisser un rafraîchissement écraser une commande qui vient tout juste d'être
envoyée.

## Comportement attendu

Un cycle automatique interroge périodiquement le cloud AUX Home (5 minutes par défaut, réglable jusqu'à 1
minute selon la configuration de UC01) pour l'ensemble des équipements en une seule fois, puis distribue les
états reçus vers chaque équipement concerné. Un équipement en erreur pendant cette distribution n'empêche
jamais les autres équipements de se rafraîchir correctement au même cycle.

Une protection anti-rebond s'applique juste après l'envoi d'une commande (UC06) : un état lu par le cycle de
rafraîchissement mais daté d'avant cette commande ne vient pas écraser la valeur commandée pendant une
courte période de grâce. Une commande de rafraîchissement manuel, disponible par équipement, permet de
forcer une lecture immédiate sans attendre le prochain cycle. En cas d'échec global de l'appel réseau, tous
les équipements passent hors ligne mais conservent leurs dernières valeurs connues (aucune remise à zéro ni
effacement).

## Critères d'acceptation

- [ ] **AC1** — Après une action effectuée directement sur la télécommande infrarouge du climatiseur,
      attendre un cycle de rafraîchissement (5 minutes par défaut) : l'état affiché dans Jeedom (marche/
      arrêt, mode, consigne, vitesse) correspond au nouvel état réel constaté.
- [ ] **AC2** — Après un changement effectué depuis l'application AUX Home officielle (et non depuis
      Jeedom), le prochain cycle de rafraîchissement Jeedom répercute ce changement.
- [ ] **AC3** — Avec plusieurs équipements smartclim configurés, un seul cycle de rafraîchissement ne
      déclenche qu'un seul appel réseau de lecture d'état vers AUX Home, quel que soit le nombre
      d'équipements (constatable par observation du volume ou de la latence des appels).
- [ ] **AC4** — Un équipement devenu injoignable ou en erreur (ex. retiré du compte AUX Home) n'empêche pas
      les autres équipements smartclim de continuer à se rafraîchir normalement au cycle suivant.
- [ ] **AC5** — Juste après l'envoi d'une commande (ex. nouvelle consigne), un cycle de rafraîchissement
      survenant dans la minute qui suit n'annule pas visuellement la valeur commandée, même si le cloud
      renvoie encore l'ancien état à ce moment-là.
- [ ] **AC6** — Une commande « Rafraîchir » disponible sur chaque équipement force une mise à jour
      immédiate de son état, sans attendre le cycle automatique suivant.
- [ ] **AC7** — En simulant une indisponibilité du cloud AUX Home pendant un cycle (ex. coupure réseau),
      tous les équipements smartclim passent visuellement « hors ligne », mais leurs dernières valeurs
      connues (mode, consigne, vitesse…) restent affichées, sans être effacées ni remises à une valeur par
      défaut.
- [ ] **AC8** — En réglant l'intervalle de rafraîchissement sur 1 minute (UC01), un rafraîchissement
      effectif est constaté à peu près toutes les minutes.

## Impact i18n

- Nouvelles chaînes UI (français) anticipées : « Rafraîchir », « En ligne », « Hors ligne ».

## À confirmer

- Durée exacte de la période de grâce anti-rebond (valeur de départ ~60 s) à calibrer en recette selon le
  comportement observé sur l'appareil de validation — `smartclim-architecture-jeedom.md` § 7.

## Hors périmètre

- Mise à jour temps réel via push/WebSocket (aucun canal de ce type n'étant confirmé côté AUX Home EU au
  moment de la rédaction) → post-mvp/05 (nécessite un démon).
- Découverte de nouveaux appareils en cours de cycle (le scan reste une action manuelle, UC03).
- Reprise après incident prolongé (expiration de session, coupure Internet, redémarrage de Jeedom) →
  UC08.
