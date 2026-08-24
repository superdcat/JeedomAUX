# UC04 — Modèle générique, tables de correspondance et profil de capacités

> **Domaine** : MVP · **Statut** : à implémenter · **Dépend de** : UC03

## Objectif

Établir la couche d'abstraction qui traduit les codes propriétaires AUX Home en concepts génériques
indépendants du transport (mode, vitesse, oscillation…), et détecter automatiquement, pour chaque
climatiseur découvert, l'ensemble des fonctions qu'il supporte réellement. C'est ce profil de capacités qui
pilotera la création des commandes Jeedom (UC05, UC06) : l'utilisateur ne doit jamais se voir proposer une
commande que son appareil ne sait pas exécuter.

## Comportement attendu

Après un scan (UC03), la page de configuration de chaque équipement affiche un profil de capacités détecté :
les modes disponibles, les vitesses de ventilation disponibles, la plage de température pilotable, la date
de détection et le transport ayant servi à l'établir. Tous les libellés affichés sont compréhensibles sans
connaître l'API AUX Home (aucun code interne brut visible).

Les bornes de température affichées par défaut sont 16-32 °C avec un pas de 0,5 °C ; l'utilisateur peut les
modifier pour un équipement particulier, et cette personnalisation n'est pas silencieusement réécrite par
une redétection ultérieure.

Une fonction pour laquelle aucune correspondance générique n'est établie n'apparaît jamais dans le profil de
capacités avec une valeur par défaut ou approximative : elle est simplement absente, traitée comme non
supportée.

⚠️ Point de vigilance (documentation interne, pas un détail d'implémentation à ignorer côté recette) : les
concepts « mode » et « vitesse de ventilation » ne partagent pas la même numérotation selon la source de la
donnée à l'intérieur même du transport AUX Home (valeur envoyée en commande vs valeur relue depuis l'état).
Une confusion entre ces numérotations produit un climatiseur qui « marche presque » (ex. chauffe au lieu de
refroidir) — c'est précisément ce que ce profil de capacités et ses tables de correspondance doivent
empêcher.

## Critères d'acceptation

- [ ] **AC1** — La page de configuration de chaque équipement affiche un profil de capacités listant les
      modes et les vitesses détectés comme supportés, avec la date de détection et le nom du transport
      source affichés en toutes lettres et en français.
- [ ] **AC2** — La plage de température affichée par défaut pour un équipement nouvellement découvert est
      16-32 °C avec un pas de 0,5 °C.
- [ ] **AC3** — Modifier manuellement les bornes de température (min, max ou pas) d'un équipement, puis
      relancer un scan/une redétection de capacités : les valeurs personnalisées restent en vigueur (non
      réécrasées automatiquement).
- [ ] **AC4** — Aucun libellé de mode ou de vitesse affiché dans l'interface ne consiste en un code brut
      (nombre, nom de champ API) : tous sont des libellés français lisibles (« Refroidissement »,
      « Automatique »…).
- [ ] **AC5** — Un mode ou une vitesse pour lesquels le plugin ne dispose pas de correspondance vérifiée
      n'apparaît jamais dans le profil de capacités affiché, plutôt que d'y figurer avec une valeur erronée
      ou approximative.
- [ ] **AC6** — Si deux modèles de climatiseurs différents sont disponibles en recette, leurs profils de
      capacités affichés diffèrent conformément à leurs fonctions réelles respectives (pas un profil
      identique imposé aux deux) ; à défaut d'un second modèle disponible, ce critère est constaté comme
      non testable en recette et reporté.

## Impact i18n

- Nouvelles chaînes UI (français) anticipées : « Profil de capacités détecté », « Détecté le », « Transport
  source », « Température minimale », « Température maximale », « Pas de réglage », libellés des modes
  (« Automatique », « Refroidissement », « Déshumidification », « Chauffage », « Ventilation ») et des
  vitesses (« Automatique », « Silencieux », « Faible », « Moyen-faible », « Moyen », « Moyen-fort »,
  « Fort », « Turbo »).

## À confirmer

- ⚠️ Table exacte de correspondance des vitesses de ventilation côté AUX Home : deux sources publiques se
  contredisent (`smartclim-modele-abstrait-capacites.md` § 3.2, `smartclim-transport-aux-home.md` § 4.3) —
  à valider vitesse par vitesse sur l'appareil de recette avant de la considérer fiable.
- Schéma exact de l'endpoint de configuration exposant les fonctions supportées par appareil
  (`GET /app/getConfig?id=deviceMutex`) et façon d'en dériver un profil de capacités propre à chaque appareil
  plutôt qu'un profil générique par défaut — `smartclim-modele-abstrait-capacites.md` § 4.1.
- Bornes de température différenciées par mode (ex. plage de chauffage plus basse sur certains modèles) —
  non tranché, non bloquant pour le MVP.

## Hors périmètre

- Création effective des commandes Jeedom à partir du profil → UC05 (info) et UC06 (action).
- Fonctions de confort avancées (afficheur, veille, éco, santé, anti-moisissure, verrouillage enfant, vent
  confort, chauffage d'appoint) → post-mvp/04.
- Tables de correspondance propres aux transports LAN Broadlink et cloud legacy → post-mvp/01 et
  post-mvp/03.
