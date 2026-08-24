# UC01 — Tolérance aux modèles et générations inconnus

> **Domaine** : post-mvp/07-multimarque-documentation-et-diffusion · **Statut** : à implémenter ·
> **Dépend de** : UC02 de post-mvp/03-cloud-aux-legacy (découverte des appareils du compte legacy — le
> dernier transport de découverte livré)

## Objectif

SmartClim promet de supporter **tout appareil compatible avec l'écosystème AUX / Broadlink / AC Freedom**,
quelle que soit la marque imprimée dessus (`.memory/brief.md` § 10 et § 20) — sans whitelist de références
commerciales. Cette promesse n'est vraie que si un appareil **jamais vu par les auteurs du plugin**, avec
un identifiant de produit ou une génération inconnus de toutes les tables internes, reste malgré tout
découvert et pilotable au minimum. Cette UC garantit ce filet de sécurité et démontre que l'ajout d'une
nouvelle référence commerciale ne demande jamais de toucher à la logique du plugin.

## Comportement attendu

- Lors d'un scan, un appareil dont l'identifiant de modèle, de type produit ou de génération ne correspond
  à **aucune entrée connue** des tables de correspondance n'est **jamais écarté** des résultats : il
  apparaît comme les autres, avec les informations qu'on a pu en tirer (nom, marque si connue, MAC, IP,
  transport), et peut être créé en équipement normalement.
- Le profil de capacités d'un tel appareil se réduit à un **socle minimal** garanti par ce qui est commun
  aux trois générations du protocole (la trame HVAC mutualisée, cf. analyse écosystème) : au minimum
  marche/arrêt, mode, et consigne de température. Les fonctions non reconnues sont simplement **absentes**
  du profil — pas de plantage, pas de commande qui apparaîtrait sans jamais produire d'effet.
- En niveau de log **debug**, le plugin journalise la charge utile brute reçue pour un appareil non
  reconnu (trame hexadécimale ou JSON selon le transport), afin de permettre un diagnostic communautaire et
  l'enrichissement futur des tables. ⚠️ Ce journal ne contient **jamais** d'identifiants de compte, de
  jeton ou de mot de passe.
- Ajouter la prise en charge d'une nouvelle référence commerciale (nouveau modèle, nouvelle marque
  utilisant le même protocole) se traduit uniquement par l'ajout d'entrées dans une **table de données**
  existante — jamais par une nouvelle branche logique (`switch`/`if`) dans le code du plugin.

## Critères d'acceptation

- [ ] **AC1** — Un appareil dont l'identifiant de modèle/produit renvoyé par le transport ne correspond à
      aucune entrée connue apparaît quand même dans les résultats du scan et peut être créé comme
      équipement, sans message d'erreur bloquant ni interruption du scan pour les autres appareils.
- [ ] **AC2** — Un équipement créé à partir d'un tel appareil non reconnu expose au minimum les commandes
      marche/arrêt, mode et consigne de température, et ces commandes produisent l'effet attendu sur
      l'appareil.
- [ ] **AC3** — Aucune commande correspondant à une fonction non confirmée par les capacités détectées
      (option de confort, vitesse de ventilation spécifique…) n'apparaît sur cet équipement — pas de
      commande présente mais sans effet.
- [ ] **AC4** — Avec le niveau de log debug activé, la charge utile brute reçue pour l'appareil non reconnu
      est retrouvable dans les logs du plugin ; une relecture de ces logs ne révèle aucun identifiant de
      compte, jeton ou mot de passe.
- [ ] **AC5** — Recette de démonstration : en ajoutant un jeu de données fictif (référence commerciale
      imaginaire et ses codes propriétaires) dans la table de correspondance, **sans modifier aucune autre
      partie du plugin**, un appareil simulé portant cette référence obtient automatiquement le profil de
      capacités correspondant — preuve que l'extension du support est une opération de données, pas de
      code.
- [ ] **AC6** — Recette finale : un appareil dont la référence a été volontairement omise de toutes les
      tables (test avec une marque/un modèle fictifs) apparaît au scan, se crée comme équipement, et se
      pilote a minima (marche/arrêt, mode, consigne), conformément à AC1 et AC2.

## Impact i18n

- Nouvelles chaînes UI (français) anticipées : « Modèle non reconnu », « Profil de capacités minimal »,
  « Capacités détectées automatiquement ».

## À confirmer

- Le socle minimal garanti (marche/arrêt, mode, consigne) suppose que la trame HVAC commune (`bb00…`,
  cf. `.memory/analyse/smartclim-ecosysteme-aux-broadlink.md` § 3) est décodable même pour un appareil
  totalement inconnu. Si un fabricant s'écarte de cette disposition de bits, le socle minimal réel pourrait
  être plus réduit (par ex. lecture seule) — à valider dès qu'un appareil non catalogué est testé en
  recette.
- Le contenu exact du « socle minimal » (faut-il garantir la lecture de l'état ambiant, ou seulement
  l'écriture de commandes ?) n'est pas tranché par l'analyse actuelle ; cette UC retient marche/arrêt, mode
  et consigne comme plancher, à ajuster si la recette sur un vrai appareil non répertorié montre un cas
  plus restrictif.

## Hors périmètre

- La découverte elle-même via le cloud legacy → `post-mvp/03-cloud-aux-legacy` UC02.
- L'enrichissement effectif des tables avec de vraies références commerciales (Ballu, Centek, Tornado…) :
  cette UC démontre que le mécanisme fonctionne pour n'importe quelle référence, elle ne livre pas une
  liste de marques cataloguées.
- Une heuristique de rapprochement automatique d'un modèle inconnu vers un profil existant proche (par
  similarité de trame) n'est pas couverte ici ; le profil dégradé reste la seule réponse à l'inconnu.
