# UC06 — Commandes action : pilotage complet du climatiseur

> **Domaine** : MVP · **Statut** : à implémenter · **Dépend de** : UC05

## Objectif

Rendre chaque climatiseur réellement pilotable depuis Jeedom — marche/arrêt, changement de mode, réglage de
la consigne, changement de vitesse, activation/désactivation des oscillations — avec un retour visuel
immédiat, sans bug de « rebond » d'état et sans multiplier les bips du climatiseur à chaque interaction.

## Comportement attendu

Pour chaque fonction détectée comme supportée par le profil de capacités de l'équipement (UC04), une
commande action correspondante est disponible. Actionner une commande envoie un ordre unique au cloud AUX
Home, puis met immédiatement à jour la commande info correspondante avec la valeur commandée et un
horodatage, sans attendre le prochain cycle de rafraîchissement automatique (état optimiste).

Changer le mode ou la consigne d'un climatiseur actuellement éteint l'allume également : l'utilisateur n'a
pas besoin d'envoyer une commande « Marche » séparée au préalable. La consigne de température se règle via
un curseur dont les bornes et le pas proviennent du profil de capacités (ou de ses bornes personnalisées,
UC04). Seules les valeurs de mode et de vitesse effectivement supportées par l'équipement sont proposées.

Deux ordres identiques envoyés à quelques instants d'intervalle (double-clic, scénario mal écrit) ne
doivent produire qu'un seul effet réel sur le climatiseur. Une commande qui échoue (cloud indisponible,
délai dépassé) se termine en échec en quelques secondes, sans jamais bloquer l'interface Jeedom ni le
scénario appelant, et laisse une trace exploitable dans les journaux.

## Critères d'acceptation

- [ ] **AC1** — Actionner la commande « Marche » sur un équipement éteint allume réellement le climatiseur
      (constaté sur l'appareil ou son afficheur) en moins d'une quinzaine de secondes.
- [ ] **AC2** — Actionner une commande de mode (ex. « Froid ») sur un climatiseur éteint l'allume également,
      en plus de basculer le mode — constaté sur l'appareil physique.
- [ ] **AC3** — Déplacer le curseur de consigne de température et valider : la nouvelle consigne est
      appliquée sur le climatiseur physique, et la commande info de consigne affiche la nouvelle valeur
      immédiatement, sans attendre le cycle de rafraîchissement suivant.
- [ ] **AC4** — Le curseur de consigne ne permet pas de sélectionner une valeur hors des bornes ou hors du
      pas définis pour l'équipement (profil détecté ou bornes personnalisées, UC04).
- [ ] **AC5** — Actionner une commande de vitesse de ventilation supportée change réellement la vitesse du
      climatiseur physique et met à jour la commande info de vitesse en conséquence.
- [ ] **AC6** — Aucune commande de mode ou de vitesse n'est proposée pour une valeur non détectée comme
      supportée par le profil de capacités de l'équipement.
- [ ] **AC7** — Actionner deux fois de suite très rapidement la même commande action (rapprochées de
      quelques secondes) ne fait pas biper deux fois le climatiseur et n'a d'effet qu'une seule fois sur
      son état réel.
- [ ] **AC8** — Envoyer une commande action alors que le cloud AUX Home est injoignable se termine en échec
      en moins d'une vingtaine de secondes, sans blocage de l'interface Jeedom ni du scénario appelant, et
      laisse une trace exploitable dans les journaux indiquant l'échec.
- [ ] **AC9** — Chaque commande action de marche/mode/vitesse est visuellement associée dans Jeedom à sa
      commande info correspondante (l'affichage du bouton reflète l'état courant, pas seulement un
      déclenchement à sens unique).
- [ ] **AC10** — Deux commandes actions demandées presque simultanément sur un même équipement (ex. via un
      scénario changeant mode et vitesse à la suite) sont toutes deux effectivement appliquées l'une après
      l'autre, même si l'équipement était éteint au départ — aucune des deux n'est silencieusement perdue.

## Impact i18n

- Nouvelles chaînes UI (français) anticipées : « Marche », « Arrêt », « Automatique », « Froid »,
  « Déshumidification », « Chaud », « Ventilation », libellés de vitesse (identiques à UC04/UC05),
  « Oscillation verticale — Marche/Arrêt », « Oscillation horizontale — Marche/Arrêt ».

## À confirmer

- ⚠️ Échelle exacte du champ de température dans une commande AUX Home (entier en °C vs valeur ×10) : deux
  sources publiques se contredisent — `smartclim-transport-aux-home.md` § 4.2. À valider en recette avant
  de considérer le réglage de consigne fiable sur toute la plage.
- ⚠️ Table exacte des vitesses de ventilation pilotables (même contradiction qu'en UC04) — à valider
  vitesse par vitesse sur l'appareil de recette (`smartclim-modele-abstrait-capacites.md` § 3.2).
- Noms et valeurs exacts des codes d'oscillation (`smartclim-modele-abstrait-capacites.md` § 3.3) — la
  distinction oscille/fixe est établie mais marquée incertaine sur certains transports.

## Hors périmètre

- Rafraîchissement automatique et manuel de l'état → UC07.
- Gestion de l'expiration de session en cours de pilotage et anti-boucle de re-connexion → UC08.
- Fonctions de confort avancées (afficheur, veille, éco, santé, anti-moisissure, verrouillage enfant, vent
  confort, chauffage d'appoint) → post-mvp/04.
- Stratégies de transport AUTO/LOCAL/CLOUD et repli sur le LAN → post-mvp/02.
