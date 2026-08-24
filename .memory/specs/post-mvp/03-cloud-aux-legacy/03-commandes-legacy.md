# UC03 — Lecture et écriture des paramètres legacy

> **Domaine** : post-mvp/03-cloud-aux-legacy · **Statut** : à implémenter · **Dépend de** : UC02 de ce
> domaine

## Objectif

Un climatiseur découvert via le cloud historique (UC02) doit se piloter depuis Jeedom exactement comme un
climatiseur découvert via n'importe quel autre transport : mêmes commandes génériques, même expérience
utilisateur, mêmes scénarios réutilisables. Cette UC couvre la traduction entre ces commandes génériques et
le contrat propre à ce cloud.

## Comportement attendu

Chaque commande générique disponible sur l'équipement (déterminée par ses capacités détectées en UC02) lit
ou écrit un paramètre du climatiseur via le cloud historique. Une lecture rafraîchit l'état affiché dans
Jeedom ; une écriture change effectivement le réglage sur l'appareil physique.

⚠️ **Règle impérative de ce transport** : une commande d'écriture inclut toujours l'état de marche/arrêt
courant de l'appareil, même quand elle ne porte que sur un autre réglage (mode, température, vitesse…). Ne
pas le faire expose au risque réel que le cloud éteigne l'appareil en construisant la commande à partir d'un
état de marche mis en cache et périmé.

Le succès d'une commande n'est jamais déduit du seul code de retour réseau : la réponse du cloud est
vérifiée sur son contenu applicatif (statut fonctionnel **et** nature de la réponse) avant d'être considérée
comme un succès. Les échelles de valeurs propres à ce cloud (température encodée, numérotation des modes et
des vitesses) sont converties vers le modèle générique du plugin ; en particulier, la correspondance des
modes de ce transport est **spécifique** — sa numérotation diffère de celle des autres transports — et ne
doit jamais être confondue avec elle.

## Critères d'acceptation

- [ ] **AC1** — Envoyer depuis Jeedom la commande générique de chaque mode proposé (refroidissement,
      chauffage, déshumidification, ventilation, auto) fait passer le climatiseur physique dans le mode
      correspondant, sans confusion entre deux modes.
- [ ] **AC2** — Changer uniquement la température de consigne, la vitesse de ventilation ou une option de
      confort (ex. mode nuit) n'éteint jamais l'appareil et ne modifie aucun autre réglage que celui
      demandé.
- [ ] **AC3** — Après l'envoi d'une commande, une lecture de l'état de l'équipement dans Jeedom reflète le
      changement demandé (pas de commande affichée en succès alors que le cloud l'a en réalité refusée).
- [ ] **AC4** — Une réponse du cloud indiquant un échec applicatif (même transportée par un code réseau de
      succès) est traitée comme un échec dans Jeedom, jamais comme un succès.
- [ ] **AC5** — La température de consigne et la température ambiante affichées dans Jeedom sont en degrés
      Celsius avec la bonne valeur (ex. 24,0 °C reste 24,0 °C, jamais 240 ou 2,4).
- [ ] **AC6** — La commande d'oscillation verticale et la commande d'oscillation horizontale produisent
      l'effet annoncé par leur libellé : activer l'oscillation fait osciller les volets correspondants,
      désactiver les immobilise. Le sens correct est confirmé en recette avant livraison (cf. « À
      confirmer »).
- [ ] **AC7** — Chaque commande générique disponible sur un équipement de ce transport a été testée
      individuellement en recette et produit exactement l'effet attendu sur l'appareil physique, sans
      effet de bord observable sur un autre réglage.
- [ ] **AC8** — Un scénario Jeedom déjà écrit pour un climatiseur piloté par un autre transport (AUX Home,
      Broadlink LAN) fonctionne à l'identique sur un climatiseur piloté par ce transport, sans adaptation :
      mêmes commandes, même comportement.

## Impact i18n

- Pas de nouvelle chaîne de commande attendue (les libellés génériques sont déjà couverts par le socle MVP
  et les tables de correspondance communes) ; messages d'erreur spécifiques éventuels, ex. « Réponse cloud
  invalide », « Commande refusée par le cloud ».

## À confirmer

- Le jeton d'appairage de l'appareil récupéré à la découverte (UC02) doit être décodé puis recomposé dans un
  format précis pour être utilisable dans une commande de pilotage ; ce format n'est pas trivial et doit
  être validé à l'implémentation. Cf. `.memory/analyse/smartclim-transport-aux-cloud-legacy.md` § 3.
- Le sens de l'oscillation verticale/horizontale : deux sources de référence se contredisent sur la valeur
  qui signifie « oscille » et celle qui signifie « fixe ». À trancher en recette (AC6), avec un mécanisme
  d'ajustement sans modification de code si le sens s'avère inversé sur un modèle. Cf. même fichier, § 5.1
  et `.memory/analyse/smartclim-modele-abstrait-capacites.md` § 3.3.
- La nécessité de relister l'appareil (rafraîchir son jeton de session) avant une série de commandes si la
  découverte date, la session pouvant expirer. Cf. `.memory/analyse/smartclim-transport-aux-cloud-legacy.md`
  § 7.
- Le comportement d'un paramètre nécessitant une requête séparée pour être lu (ex. température ambiante sur
  certains modèles) et son impact sur la fraîcheur de la lecture affichée dans Jeedom. Cf. même fichier § 4.

## Hors périmètre

- La découverte des appareils et la détection de leurs capacités → UC02 de ce domaine.
- Le temps réel par WebSocket relay (pousser les changements sans polling) → `post-mvp/05-temps-reel-et-demon`.
- Le pilotage des pompes à chaleur (jeu de paramètres distinct) → hors périmètre du plugin.
- L'arbitrage entre plusieurs transports disponibles pour un même équipement → `post-mvp/02-strategies-de-transport`.
