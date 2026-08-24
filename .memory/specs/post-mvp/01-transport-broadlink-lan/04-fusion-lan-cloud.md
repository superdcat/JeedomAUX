# UC04 — Fusion d'un même climatiseur découvert en LAN et dans le cloud

> **Domaine** : post-mvp/01-transport-broadlink-lan · **Statut** : à implémenter · **Dépend de** : UC01 de
> ce domaine (découverte broadcast, authentification et session locale)

## Objectif

Éviter qu'un même climatiseur, détecté à la fois par la diffusion LAN et par le cloud AUX Home, n'apparaisse
comme deux équipements distincts dans Jeedom, et permettre à un seul équipement de porter tous les
identifiants de transport nécessaires au mode combiné à venir (AUTO).

## Comportement attendu

- Lors d'un scan, chaque appareil détecté en LAN est rapproché des équipements déjà connus (créés depuis
  le cloud ou un scan LAN précédent) par comparaison de sa MAC — y compris lorsque l'ordre des octets de
  la MAC diffère d'une source à l'autre.
- Si aucune correspondance par MAC (directe ou inversée) n'est trouvée, le plugin tente le rapprochement
  par un identifiant de transport déjà mémorisé sur un équipement existant, avant de conclure qu'il s'agit
  d'un nouvel appareil.
- Quand la correspondance est établie, les informations LAN (adresse IP, MAC) sont ajoutées sur
  l'équipement existant plutôt que de créer un doublon ; l'équipement porte alors simultanément son
  identifiant cloud et son adresse locale.
- Le résultat de scan affiche, pour chaque climatiseur, sa disponibilité LAN, sa disponibilité cloud, et
  le transport actuellement retenu.
- Ce comportement est symétrique : que le climatiseur ait d'abord été créé via le cloud puis retrouvé en
  LAN, ou l'inverse, le résultat final est un équipement unique.

## Critères d'acceptation

- [ ] **AC1** — Un climatiseur déjà présent comme équipement (créé via le cloud AUX Home) puis retrouvé
      lors d'un scan LAN n'apparaît toujours qu'une seule fois dans la liste des équipements ; son adresse
      IP locale devient visible sur cet équipement.
- [ ] **AC2** — À l'inverse, un climatiseur d'abord découvert en LAN seul (avant configuration du cloud),
      puis retrouvé ensuite via le cloud lors d'un scan ultérieur, reste un seul et même équipement — pas
      de second équipement créé pour le même appareil physique.
- [ ] **AC3** — Sur la page de résultats de scan, chaque climatiseur détecté affiche distinctement : LAN
      oui/non, cloud oui/non, et le transport actif retenu pour cet équipement.
- [ ] **AC4** — Relancer un scan plusieurs fois de suite (LAN et cloud tous deux disponibles) ne crée
      jamais de nouvel équipement au-delà du premier scan — le nombre total d'équipements reste stable.
- [ ] **AC5** — Un climatiseur dont la MAC est lue dans un ordre d'octets inversé par la découverte LAN
      (par rapport à la MAC connue côté cloud) est correctement rapproché de l'équipement existant — pas
      de doublon dû à cette seule différence de représentation.

## Impact i18n

- Nouvelles chaînes UI (français) anticipées, sur la page de résultats de scan : « Disponible en LAN »,
  « Disponible dans le cloud », « Transport actif » (cette dernière potentiellement déjà introduite par le
  socle MVP pour la seule colonne cloud, à compléter ici).

## À confirmer

- Aucun contrat externe nouveau. Le détail technique du rapprochement (ordres d'octets, offsets de lecture
  de la MAC selon la source) est renvoyé à `.memory/analyse/smartclim-transport-broadlink-lan.md` § 6 et à
  `.memory/analyse/smartclim-architecture-jeedom.md` § 4.
- La fiabilité du rapprochement dans les cas limites réels (plusieurs climatiseurs identiques sur le même
  réseau, absence de MAC exploitable) reste à vérifier en recette avec au moins deux appareils.

## Hors périmètre

- Cette UC ne décide pas quel transport utiliser une fois la fusion faite (`post-mvp/02-strategies-de-
  transport`) ; elle garantit seulement l'unicité de l'équipement et l'exhaustivité de l'affichage des
  transports disponibles.
