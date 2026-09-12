# UC05 — Validation de l'accès au broker MQTT AUX Home européen

> **Domaine** : post-mvp/05-temps-reel-et-demon · **Statut** : à implémenter · **Dépend de** : UC01 de ce
> domaine (spike, **livré le 2026-09-07**)

## Objectif

Le spike de l'UC01 a établi qu'un **broker MQTT existe** sur l'infrastructure AUX Home européenne —
`eu-smthome-m2m.aux-global.com`, ports 8883/443 en TLS et 1883 en clair — et que la recette
d'authentification du backend cousin chinois réutilise **le jeton du login REST** en mot de passe, avec
l'`uid` en identifiant de client : deux valeurs que le plugin extrait et met **déjà** en cache de session
(cf. `.memory/analyse/smartclim-transport-aux-home.md` § 7).

Ce que le spike n'a **pas** établi, et qui bloque toute décision de push : **le broker accepte-t-il nos
identifiants européens ?** Un CONNECT anonyme reçoit un refus d'authentification — ce qui prouve qu'un
broker écoute, pas qu'un compte européen y soit connu. Cette UC ne fait **qu'une** chose : produire ce
fait manquant, qui coûte une seule connexion.

⚠️ Cette UC est un **préalable de décision**, pas une exploitation. Elle ne livre aucun canal temps réel,
aucune commande, aucun démon : elle rend la décision « push ou scrutation » informée, là où l'UC01 a dû la
laisser ouverte.

⚠️ **Deux obstacles connus d'avance, à ne pas découvrir en cours de route** :

1. **Le certificat TLS du broker européen est expiré depuis le 2025-11-06 et son SAN (`*.aux-home.com`) ne
   couvre pas son propre nom d'hôte en `aux-global.com`** — double échec de validation. Le projet pose
   « TLS toujours vérifié, l'anomalie est remontée, jamais contournée » : **une connexion conforme est donc
   impossible en l'état**, et c'est cet arbitrage-là qui conditionne l'UC, avant même la question des
   identifiants. Le port 1883 en clair est **exclu** : le mot de passe MQTT étant le jeton de session, il
   transiterait en clair.
2. **Le `configId` européen est inconnu** et entre dans le nom d'utilisateur (`2$<configId>$<uid>`). Un
   refus d'authentification peut donc venir de ce seul paramètre, sans rien dire du canal.

## Comportement attendu

- Décision explicite, **prise avant toute connexion**, sur le traitement du certificat invalide : soit
  l'anomalie est remontée et l'UC s'arrête là en documentant le blocage, soit une exception **strictement
  cadrée et temporaire** est actée pour ce test de validation seul — jamais pour du code de production, et
  jamais silencieusement.
- Si le test est mené : une connexion TLS vers le broker, un CONNECT portant les identifiants issus d'un
  login réel, la lecture du **code retour du CONNACK**, puis fermeture immédiate. Aucune souscription
  durable, aucune publication d'ordre vers un appareil.
- Le code retour est interprété **sans surinterprétation** : un succès établit que le compte est connu du
  broker ; un refus d'authentification **ne permet pas** de conclure que le canal est fermé à nos comptes,
  le `configId` étant une inconnue indépendante. Plusieurs valeurs de `configId` sont essayées avant toute
  conclusion négative.
- Voie complémentaire, **sans aucun certificat invalide à accepter** et sans écrire de code : sonder
  `/app/getConfig?id=<X>` sur le backend REST via la CLI existante `core/php/diagnostic-auxhome.php`
  (chemins libres en CLI), avec `X` parmi `mqtt`, `m2m`, `server`, `serverConfig`, `appConfig`, `domain`,
  `host`, `push`, `all` — pour savoir si le backend **annonce** lui-même un broker, ce que l'implémentation
  de référence ne fait pas (hôte codé en dur).
- Le livrable est la **mise à jour du § 7** de `smartclim-transport-aux-home.md` — le `❓` « nos
  identifiants sont-ils acceptés ? » remplacé par un fait — et une **décision consignée** : ouvrir
  l'exploitation du canal (et à quelle marche, cf. `smartclim-daemon-choix.md` § 6), ou clore le dossier
  en maintenant la scrutation.
- ⚠️ Aucun jeton, `uid`, `configId` de compte réel ni capture brute n'est versionné, à aucune étape. Seule
  la **forme** des paramètres est un fait de protocole documentable.

## Critères d'acceptation

- [x] **AC1** — La décision sur le certificat TLS invalide est consignée **explicitement** dans les notes
      d'analyse avant toute connexion : blocage assumé, ou exception cadrée et bornée à ce test.
      (D-1, `.memory/analyse/smartclim-transport-aux-home.md` § 7.3, datée du 2026-09-12.)
- [ ] **AC2** — Si le test est mené, le § 7.2 de `smartclim-transport-aux-home.md` ne porte plus le `❓`
      « nos identifiants EU sont-ils acceptés ? » : il affirme le résultat, avec le code retour observé.
- [ ] **AC3** — Une conclusion négative n'est consignée qu'après avoir écarté le `configId` comme cause :
      au moins deux valeurs distinctes essayées, dont celle par défaut de l'implémentation de référence.
- [ ] **AC4** — La voie `/app/getConfig` est sondée et son résultat consigné, y compris négatif (quels
      `id` essayés, quel code de retour) — c'est la seule voie sans compromis TLS.
- [ ] **AC5** — Une décision explicite est consignée : à quelle marche du § 6 de
      `smartclim-daemon-choix.md` on s'engage, ou maintien de la scrutation avec le motif.
- [x] **AC6** — Aucun fichier versionné ne contient de jeton, d'`uid`, de `configId` réel ni de capture
      brute d'échange MQTT. (Couvert par construction : `core/php/sonde-mqtt-auxhome.php` n'écrit rien sur
      disque, et les fichiers d'analyse modifiés ne portent que de la forme, § 7.1/7.2/7.3/7.7.)

## Impact i18n

- Aucune chaîne UI n'est livrée par cette UC (validation de contrat externe, aucun composant fonctionnel).

## À confirmer

- Le `configId` européen — valeur, et même son rôle réel : côté chinois c'est un UUID **côté client**,
  jamais envoyé au login, et notre transport appelle `/app/user_device` **sans** ce paramètre et
  fonctionne. L'hypothèse d'un identifiant d'instance faiblement contrôlé est la plus économique, elle
  n'est pas établie.
- Le format des messages de `dev2app/<uid>/#`, et si un changement déclenché par la télécommande y produit
  un évènement **non sollicité** — c'est ce qui distingue un vrai push d'un simple transport de requêtes.
  Hors périmètre de cette UC, qui s'arrête au CONNACK.
- La durée de vie du jeton en usage MQTT, déjà inconnue en REST.

## Hors périmètre

- Toute exploitation du canal : souscription durable, lecture d'état, envoi d'ordre, intégration au cycle
  de rafraîchissement. Ce sont les marches 2 et 3 du § 6 de `smartclim-daemon-choix.md`, et elles
  dépendent du résultat de cette UC.
- Le socle du démon Python → UC02 de ce domaine, dont la construction **ne dépend pas** de cette UC (le
  WebSocket du cloud historique la justifie indépendamment).
- Toute capture réseau de l'application officielle : écartée par construction, le CONNACK la remplaçant.
