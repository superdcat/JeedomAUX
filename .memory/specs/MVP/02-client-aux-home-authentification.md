# UC02 — Brique d'accès AUX Home : authentification et test de connexion

> **Domaine** : MVP · **Statut** : à implémenter · **Dépend de** : UC01

## Objectif

Le plugin doit savoir se connecter lui-même au cloud AUX Home avec les identifiants configurés (UC01), sans
jamais demander à l'utilisateur de récupérer manuellement un jeton depuis une capture réseau ou l'application
mobile — cette dernière pratique n'étant tolérée qu'en développement, jamais en usage normal. L'utilisateur
doit pouvoir vérifier en un clic que ses identifiants fonctionnent avant de lancer une découverte
d'appareils (UC03).

## Comportement attendu

Un bouton « Tester la connexion », disponible sur la page de configuration, déclenche une tentative de
connexion complète au cloud AUX Home avec les identifiants actuellement enregistrés. En cas de succès, un
message clair de confirmation apparaît. En cas d'échec, un message explicite en français apparaît, sans
jargon technique brut (pas de code d'erreur nu ni de trace de pile) ; si l'échec correspond à un refus
d'authentification, le message invite explicitement à vérifier les identifiants et en particulier le champ
pays. Un cloud injoignable (panne, coupure réseau) produit un message distinct indiquant l'indisponibilité
du service, en quelques secondes, sans jamais bloquer l'interface Jeedom.

Chaque tentative de connexion est indépendante et à jour : elle utilise les identifiants actuellement
enregistrés (pas une session ou une clé mise en cache d'une tentative précédente), afin qu'un changement de
mot de passe soit immédiatement pris en compte au test suivant.

## Critères d'acceptation

- [ ] **AC1** — Avec des identifiants AUX Home valides enregistrés, cliquer sur « Tester la connexion »
      affiche un message de succès en moins d'une quinzaine de secondes.
- [ ] **AC2** — Avec un mot de passe erroné, cliquer sur « Tester la connexion » affiche un message
      d'échec explicite — jamais un message de succès, même si la requête réseau sous-jacente a répondu en
      HTTP 200.
- [ ] **AC3** — Avec un compte valide mais un pays mal renseigné, le message d'échec affiché suggère
      explicitement de vérifier le champ pays.
- [ ] **AC4** — Après un test de connexion (réussi ou échoué), aucune trace du mot de passe, du champ
      compte chiffré ou du jeton complet n'apparaît dans les journaux du plugin, quel que soit le niveau de
      log activé ; au plus un préfixe tronqué du jeton peut apparaître en debug.
- [ ] **AC5** — Simuler l'indisponibilité du service AUX Home (ex. coupure réseau du serveur Jeedom) puis
      cliquer sur « Tester la connexion » aboutit à un message d'échec clair et distinct en moins de 20
      secondes, sans blocage de la page.
- [ ] **AC6** — Modifier le mot de passe enregistré (UC01) puis relancer immédiatement un test de
      connexion : le résultat reflète le nouveau mot de passe (succès s'il est correct, échec sinon) — le
      plugin ne réutilise pas une session ou une clé obtenue avec l'ancien mot de passe.
- [ ] **AC7** — Le résultat du test de connexion (succès comme échec) est intégralement affiché en
      français.

## Impact i18n

- Nouvelles chaînes UI (français) anticipées : « Tester la connexion », « Connexion réussie », « Échec de
  la connexion — vérifiez vos identifiants et le pays sélectionné », « Service AUX Home injoignable,
  réessayez plus tard ».

## Décisions actées

- ✅ **Clé AES du champ `account` : embarquée dans le code du plugin** (arbitrage utilisateur du
  2026-08-24). Le chiffrement du champ `account` (e-mail) repose sur une clé AES fixe extraite de
  l'application AUX Home, publiée dans un dépôt tiers sous licence MIT. Sans elle, **aucun login n'est
  possible** : c'est une constante de protocole, pas un secret utilisateur. Conséquences à respecter à
  l'implémentation :
  - la valeur vit dans la brique de transport `smartclimAuxHomeApi`, **jamais** dans un fichier de
    configuration, dans un log, dans le DOM ou dans une réponse AJAX ;
  - elle est accompagnée d'un commentaire citant sa **source et sa licence** (obligation reprise par
    l'UC02 du domaine post-mvp/07 — crédits de licences) ;
  - elle ne chiffre **aucune donnée utilisateur au repos** : elle ne sert qu'à reproduire le format
    d'échange attendu par le backend AUX. Le mot de passe, lui, reste chiffré par la clé publique RSA
    redemandée à chaque login.

## À confirmer

- Durée de validité d'une clé publique fraîchement obtenue et code d'erreur exact en cas de réutilisation
  d'une clé expirée (observé sur le backend cousin chinois, non confirmé côté EU) —
  `smartclim-transport-aux-home.md` § 2.2 et § 9.
- Durée de vie du jeton de session et code d'erreur exact d'expiration — aucun refresh token n'existe côté
  AUX Home ; approfondi en UC08.

## Hors périmètre

- Découverte des appareils du compte → UC03.
- Re-connexion automatique en cours de fonctionnement (anti-boucle, reprise après expiration en usage
  courant) → UC08.
- Authentification aux transports LAN Broadlink et cloud legacy → post-mvp/01 et post-mvp/03.
