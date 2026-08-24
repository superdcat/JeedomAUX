# UC02 — Repli LAN → cloud avec compteurs d'échec et temporisation

> **Domaine** : post-mvp/02-strategies-de-transport · **Statut** : à implémenter · **Dépend de** : UC01 de
> ce domaine

## Objectif

Un réseau local n'est jamais parfait : Wi-Fi instable, appareil qui redémarre, session LAN perdue. En
mode AUTO, l'utilisateur attend que son climatiseur reste pilotable malgré ces aléas, en
basculant vers le cloud **seulement quand c'est justifié** (pas au premier accroc) et en revenant au LAN dès
que possible — sans jamais osciller nerveusement entre les deux transports ni laisser l'équipement dans un
état incohérent.

## Comportement attendu

- Chaque équipement en AUTO maintient un compteur d'échecs LAN **consécutifs**, remis à zéro dès le
  premier succès.
- Sous un seuil de tolérance, un échec LAN se traduit par un **échec propre de la commande/lecture en
  cours** — le plugin ne tente pas automatiquement la même opération sur le cloud. Ce choix est **assumé** :
  éviter qu'une même commande logique parte à la fois en LAN et en cloud (risque d'état incohérent, de
  double exécution perçue par l'utilisateur — le climatiseur bipe à chaque ordre reçu, cf.
  `smartclim-architecture-jeedom.md` § 7) et éviter de masquer trop vite un vrai problème réseau.
- Au seuil atteint, l'équipement bascule sur son cloud pour les opérations suivantes, sans action de
  l'utilisateur. Un équipement sans identifiant cloud configuré ne bascule **jamais** : il continue à
  échouer proprement en LAN (cohérent avec le comportement décrit en UC01 pour AUTO sans cloud).
- Côté cloud, les tentatives suivent une temporisation **croissante** et sont **bornées en nombre** : un
  cloud durablement indisponible ne doit jamais bloquer indéfiniment une commande Jeedom (`.memory/brief.md` § 15).
- Dès que le LAN redevient joignable, l'équipement revient automatiquement au LAN pour les opérations
  suivantes — sans intervention de l'utilisateur.
- L'utilisateur voit, dans l'IHM, qu'un équipement est actuellement en **repli** (transport actif = cloud
  alors que le mode configuré est AUTO) et peut comprendre pourquoi (échecs LAN), pour ne pas
  confondre ce repli automatique avec un choix manuel de mode CLOUD.

## Critères d'acceptation

- [ ] **AC1** — Pour un équipement en AUTO avec identifiant cloud configuré, **3 échecs
      consécutifs** d'opération LAN déclenchent le passage automatique au cloud pour les opérations
      suivantes, sans intervention de l'utilisateur.
- [ ] **AC2** — Un succès LAN remet immédiatement le compteur d'échecs consécutifs à zéro (qu'il survienne
      avant ou après une bascule).
- [ ] **AC3** — Avant que le seuil ne soit atteint, chaque échec LAN se traduit par un échec propre de
      l'opération (pas de tentative automatique sur le cloud pour cette même opération) — observable par le
      fait que l'équipement ne change pas d'état de façon inattendue et n'exécute jamais deux fois la même
      commande sur deux transports différents.
- [ ] **AC4** — Un équipement sans identifiant cloud configuré ne bascule jamais vers le cloud, quel que
      soit le nombre d'échecs LAN consécutifs constatés ; ses opérations continuent d'échouer proprement en
      LAN.
- [ ] **AC5** — Une fois en repli cloud, une indisponibilité prolongée du cloud ne bloque jamais durablement
      une commande Jeedom : le nombre de tentatives est borné et chaque tentative attend plus longtemps que
      la précédente.
- [ ] **AC6** — Dès que le LAN redevient joignable (constaté par une opération LAN réussie), l'équipement
      repasse automatiquement au LAN dès l'opération suivante, sans action de l'utilisateur.
- [ ] **AC7** — Quand un équipement est en repli cloud, l'IHM d'administration l'indique de façon distincte
      d'un mode CLOUD choisi manuellement (l'utilisateur peut voir que c'est un repli, pas un réglage).
- [ ] **AC8** — *(Recette)* Couper la joignabilité LAN d'un équipement en AUTO (ex. désactiver son Wi-Fi
      ou bloquer son IP au pare-feu) pendant l'usage → après 3 opérations en échec, l'équipement continue de
      répondre aux commandes (via le cloud, avec un délai potentiellement plus élevé) ; rétablir le LAN →
      le pilotage local revient automatiquement au cycle suivant.

## Impact i18n

- Nouvelles chaînes UI (français) anticipées : « Repli cloud actif », « Échecs LAN consécutifs : N »,
  « Retour au pilotage local », « Cloud indisponible, nouvelle tentative dans … ».

## À confirmer

- Le nombre exact de tentatives cloud avant abandon définitif d'une opération, et les valeurs de
  temporisation — l'analyse `smartclim-transport-broadlink-lan.md` § 7 documente `500 ms · 2^n` plafonné à
  3 s côté référence `fparrav`, à valider comme cible et à voir si elle s'applique telle quelle au cloud AUX
  Home (MVP) ou seulement au cloud legacy.
- Le compteur d'échecs consécutifs porte-t-il sur les commandes d'écriture, les lectures d'état (cron), ou
  les deux confondues ? Non tranché par l'analyse.
- Le mécanisme précis de détection « LAN redevenu joignable » (nouvelle tentative systématique à chaque
  cycle vs sonde dédiée) — recoupe l'UC03 pour le cas particulier d'un changement d'IP.
- Libellé exact affiché à l'utilisateur pour signaler un repli en cours — laissé à la spec technique / i18n.

## Hors périmètre

- Le protocole LAN et le protocole cloud eux-mêmes (`post-mvp/01`, `post-mvp/03`).
- La sélection initiale du mode de transport → UC01.
- Le cas où l'échec LAN est dû à un changement d'adresse IP plutôt qu'à une simple indisponibilité — traité
  en profondeur par l'UC03 (ici, l'IP est supposée stable).
- L'affichage riche du repli dans une tuile de dashboard → `post-mvp/06-ergonomie-jeedom`.
