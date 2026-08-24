# UC05 — Commandes info : lecture de l'état du climatiseur

> **Domaine** : MVP · **Statut** : à implémenter · **Dépend de** : UC04

## Objectif

Donner à l'utilisateur une vue à jour de l'état réel de chaque climatiseur directement dans Jeedom (marche/
arrêt, mode, consigne, vitesse, oscillations, température ambiante), sous forme de commandes standard
exploitables dans des scénarios et des dashboards, sans avoir à ouvrir l'application AUX Home.

## Comportement attendu

À la suite de la détection de capacités (UC04), Jeedom crée pour chaque équipement les commandes
d'information correspondant aux fonctions détectées comme supportées : marche/arrêt, mode, consigne de
température, vitesse de ventilation, température ambiante, et oscillations verticale/horizontale lorsqu'elles
sont détectées. Deux commandes méta accompagnent ces commandes : le transport ayant fourni la dernière
donnée, et l'horodatage de cette donnée.

La création de ces commandes est idempotente : un second scan ou une redétection de capacités ne recrée pas
les commandes existantes et ne réinitialise jamais les réglages que l'utilisateur y a appliqués (visibilité,
historisation, widget de dashboard). Une fonction qui disparaît d'une redétection ultérieure ne supprime pas
la commande info déjà créée.

⚠️ Avertissement à porter à la connaissance de l'utilisateur : la température ambiante remontée par AUX
Home n'est pas une mesure temps réel. Son rafraîchissement côté cloud peut prendre de quelques minutes à
environ 30 minutes, y compris dans l'application officielle. Elle ne doit pas servir de sonde pour une
régulation fine (thermostat).

## Critères d'acceptation

- [ ] **AC1** — Pour un équipement dont le profil détecte marche/arrêt, mode, consigne, vitesse et
      température ambiante, ces cinq commandes info apparaissent dans la liste des commandes de
      l'équipement.
- [ ] **AC2** — Pour un équipement dont le profil détecte une ou deux oscillations, la ou les commandes
      info d'oscillation correspondantes apparaissent également.
- [ ] **AC3** — Modifier l'état du climatiseur depuis sa télécommande infrarouge, puis attendre un cycle de
      rafraîchissement : les commandes info de marche/arrêt, mode et vitesse reflètent le nouvel état
      constaté sur l'appareil physique.
- [ ] **AC4** — La commande info de consigne affiche une valeur avec une précision au demi-degré lorsque le
      climatiseur est réglé sur une valeur à 0,5 °C près.
- [ ] **AC5** — La commande « transport actif » affiche en toutes lettres le nom du transport ayant fourni
      la dernière donnée (ex. « AUX Home »).
- [ ] **AC6** — La commande « dernière mise à jour » affiche un horodatage qui ne change pas entre deux
      cycles où le cloud n'a renvoyé aucun changement, permettant de constater l'âge réel de la donnée.
- [ ] **AC7** — Modifier manuellement la visibilité ou l'historisation d'une commande info, puis provoquer
      un nouveau scan ou une redétection de capacités : ce réglage personnalisé n'est pas réinitialisé.
- [ ] **AC8** — Aucune commande info n'apparaît pour une fonction que le profil de capacités de l'équipement
      ne détecte pas comme supportée.
- [ ] **AC9** — Une fonction précédemment détectée qui disparaît lors d'une redétection ultérieure ne fait
      pas disparaître la commande info déjà créée (elle reste présente dans la liste des commandes).
- [ ] **AC10** — Pour une vitesse de ventilation préalablement commandée dont l'état relu depuis le cloud ne
      permet pas de confirmer la valeur exacte, la commande info de vitesse continue d'afficher la dernière
      vitesse effectivement commandée plutôt qu'une valeur incohérente ou par défaut.
- [ ] **AC11** — La page de configuration du plugin (ou une aide associée) indique explicitement à
      l'utilisateur que la température ambiante AUX Home n'est pas temps réel et ne doit pas être utilisée
      pour une régulation fine.

## Impact i18n

- Nouvelles chaînes UI (français) anticipées : « Marche/Arrêt », « Mode », « Consigne », « Température
  ambiante », « Vitesse de ventilation », « Oscillation verticale », « Oscillation horizontale »,
  « Transport actif », « Dernière mise à jour », texte d'avertissement sur la fraîcheur de la température
  ambiante.

## À confirmer

- Renvoi à UC04 pour les points de contrat non tranchés sur les tables de correspondance (vitesses,
  schéma des capacités par appareil).

## Hors périmètre

- Pilotage effectif du climatiseur (commandes action) → UC06.
- Déclenchement du cycle de rafraîchissement automatique et gestion des échecs de scrutation → UC07.
- Fonctions de confort avancées (afficheur, veille, éco, santé, anti-moisissure, verrouillage enfant, vent
  confort, chauffage d'appoint) → post-mvp/04.
- Widget de dashboard dédié et page-panneau → post-mvp/06.
