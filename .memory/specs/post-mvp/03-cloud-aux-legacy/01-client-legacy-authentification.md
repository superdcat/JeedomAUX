# UC01 — Client AUX Cloud legacy : authentification multi-régions

> **Domaine** : post-mvp/03-cloud-aux-legacy · **Statut** : à implémenter · **Dépend de** : UC02 du MVP

## Objectif

Certains climatiseurs de l'écosystème AUX/Broadlink n'ont jamais de compte AUX Home exploitable : générations
plus anciennes, ou comptes créés sur les régions USA/Chine/Russie. Cette UC ouvre une deuxième porte
d'entrée — le cloud historique AC Freedom / AUX Cloud — pour que ces climatiseurs restent pilotables depuis
SmartClim, avec la même promesse multimarque (sans whitelist) que le reste du plugin.

## Comportement attendu

L'utilisateur configure, dans la page de configuration du plugin, un compte cloud historique : e-mail, mot
de passe, et une région parmi celles proposées (Europe, USA, Chine, Russie au minimum). Ce compte est
**indépendant** du compte AUX Home du socle MVP : l'utilisateur peut renseigner l'un, l'autre, les deux, ou
aucun — aucune des deux configurations n'est un préalable à l'autre.

Un test de connexion explicite permet de vérifier les identifiants avant toute découverte. En cas d'échec,
le message affiché distingue les causes courantes (identifiants invalides, région/serveur injoignable,
anomalie de certificat) pour que l'utilisateur sache quoi corriger.

Une fois la connexion établie, la session est réutilisée pour tous les appels suivants (découverte,
lecture, commandes — UC02 et UC03) : l'utilisateur ne resaisit rien tant que le compte reste valide. Si le
cloud rejette une session devenue invalide (expiration), la reconnexion se fait automatiquement et de façon
transparente ; seule une vraie perte de validité des identifiants remonte un message actionnable.

⚠️ **Point de sécurité non négociable** : la validité du certificat TLS des serveurs du cloud historique est
toujours vérifiée. Si un certificat s'avère invalide ou non vérifiable, la connexion échoue explicitement —
elle n'est **jamais** établie en contournant la vérification, même si les implémentations de référence
étudiées le font.

## Critères d'acceptation

- [ ] **AC1** — La page de configuration du plugin propose une section dédiée au compte cloud historique,
      distincte de la section du compte AUX Home ; l'utilisateur peut renseigner et enregistrer l'une sans
      l'autre.
- [ ] **AC2** — Un sélecteur de région propose au moins Europe, USA, Chine et Russie ; choisir une région et
      enregistrer les identifiants suffit à préparer la connexion, sans autre manipulation.
- [ ] **AC3** — Une action « Tester la connexion » déclenche une tentative de connexion réelle et affiche
      sans ambiguïté un succès ou un échec.
- [ ] **AC4** — En cas d'échec du test, le message affiché est actionnable : il indique si la cause probable
      est des identifiants invalides, un serveur/une région injoignable, ou une anomalie de certificat —
      pas un message technique générique unique pour tous les cas.
- [ ] **AC5** — Si le certificat TLS du serveur cloud historique est invalide, le test de connexion échoue
      explicitement avec un message dédié ; il n'y a jamais de connexion établie silencieusement malgré un
      certificat invalide.
- [ ] **AC6** — Après un test de connexion réussi, une opération suivante qui échoue pour cause de session
      expirée aboutit quand même (reconnexion automatique) sans que l'utilisateur n'ait à ressaisir quoi que
      ce soit, sauf si les identifiants eux-mêmes sont devenus invalides.
- [ ] **AC7** — Aucun mot de passe ni identifiant de session n'apparaît en clair dans les logs du plugin, y
      compris en niveau debug.
- [ ] **AC8** — Un utilisateur n'ayant qu'un compte cloud historique (pas de compte AUX Home) peut utiliser
      le plugin normalement ; réciproquement, un utilisateur n'ayant que le compte AUX Home n'est pas gêné
      par la présence de cette nouvelle section.

## Impact i18n

- Nouvelles chaînes UI (français) anticipées : « Compte cloud historique (AC Freedom / AUX Cloud) »,
  « Région », « Adresse e-mail », « Mot de passe », « Tester la connexion », « Connexion réussie »,
  « Identifiants invalides », « Serveur injoignable », « Certificat de sécurité invalide ».

## À confirmer

- Le format exact de l'horodatage servant de graine à la dérivation de la clé de chiffrement du corps de
  requête est un flottant côté implémentations de référence (ex. sortie de `time.time()` en Python) ; une
  implémentation PHP doit reproduire ce format à l'identique, faute de quoi l'authentification échoue
  silencieusement (clé de chiffrement différente). À valider dès cette UC. Cf.
  `.memory/analyse/smartclim-transport-aux-cloud-legacy.md` § 2 et § 8.
- La validité réelle des certificats TLS des serveurs `smarthomecs.*` / `ibroadlink.com` n'est vérifiée par
  aucune des implémentations de référence (elles désactivent toutes la vérification) : leur état réel avec
  vérification active n'est pas prouvé. Cf. même fichier, § 1 et § 8.
- La durée de vie réelle de la session (`loginsession`) n'est pas documentée ; le seuil déclenchant la
  reconnexion automatique est à déterminer en recette. Cf. même fichier, § 7.
- La disponibilité effective de la région Russie n'est confirmée que côté API (pas de temps réel) dans les
  sources étudiées.

## Hors périmètre

- La découverte des familles, pièces et appareils du compte → UC02 de ce domaine.
- La lecture et l'écriture des paramètres d'un climatiseur → UC03 de ce domaine.
- Le temps réel par WebSocket relay de ce cloud → `post-mvp/05-temps-reel-et-demon`.
- L'arbitrage entre ce transport et les autres transports disponibles pour un même équipement →
  `post-mvp/02-strategies-de-transport`.
