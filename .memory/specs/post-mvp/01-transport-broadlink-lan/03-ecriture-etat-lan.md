# UC03 — Envoi de commandes en LAN

> **Domaine** : post-mvp/01-transport-broadlink-lan · **Statut** : à implémenter · **Dépend de** : UC02 de
> ce domaine (lecture de l'état et de la température ambiante en LAN)

## Objectif

Permettre de piloter en local (marche/arrêt, mode, consigne, vitesse, oscillations, options de confort) un
climatiseur joignable en LAN, avec exactement la même surface de commandes que le pilotage cloud, sans
effet de bord destructeur sur l'état de l'appareil.

## Comportement attendu

- Toute commande action générique (marche/arrêt, changement de mode, de consigne, de vitesse,
  d'oscillation, d'option) envoyée à un équipement piloté en LAN produit sur l'appareil exactement l'effet
  attendu, et seulement celui-ci — pas de changement involontaire des autres réglages.
- ⚠️ Avant d'envoyer une commande, le plugin s'appuie sur le **dernier état complet connu** de l'appareil
  (lu via UC02) pour construire la trame envoyée, en n'y modifiant que le ou les champs visés. Si aucun
  état n'a jamais été lu pour cet équipement, le plugin lit d'abord l'état avant d'émettre la commande.
  Sans cette fusion, l'appareil s'éteint tout seul (l'écriture porte un état complet, jamais un delta).
- Une commande envoyée n'est jamais silencieusement perdue côté plugin : soit l'utilisateur constate
  l'effet attendu sur l'appareil, soit le plugin remonte un échec exploitable — jamais un silence qui
  laisse croire à tort que la commande a réussi.
- Après une commande réussie, l'état affiché par les commandes info reflète rapidement le changement
  demandé (cohérent avec le mécanisme d'état optimiste du socle).

## Critères d'acceptation

- [ ] **AC1** — Envoyer la commande « Mode Froid » sur un climatiseur en marche ne modifie ni sa consigne,
      ni sa vitesse de ventilation, ni ses oscillations, ni ses options actives — seul le mode change
      (vérifiable en relisant l'état juste après).
- [ ] **AC2** — Envoyer une commande de changement de consigne ne coupe jamais l'appareil et ne change ni
      son mode ni sa vitesse en cours.
- [ ] **AC3** — Sur un équipement dont l'état n'a encore jamais été lu (par ex. juste après un passage au
      transport LAN), la première commande envoyée aboutit correctement — le plugin lit l'état avant
      d'écrire plutôt que d'éteindre ou de dérégler l'appareil.
- [ ] **AC4** — Chaque commande action disponible sur l'équipement (marche, arrêt, chaque mode supporté,
      chaque vitesse supportée, chaque oscillation, chaque option de confort supportée), testée
      individuellement, produit l'effet attendu et uniquement celui-ci, constaté sur l'appareil physique.
- [ ] **AC5** — Une séquence de plusieurs commandes rapprochées (par ex. changer le mode puis la consigne
      juste après) aboutit à un état final cohérent avec la dernière intention de l'utilisateur sur chaque
      champ, sans qu'une commande n'en annule silencieusement une autre.
- [ ] **AC6** — Sur une recette d'au moins une dizaine de commandes variées consécutives, aucune ne
      provoque l'extinction inattendue de l'appareil.

## Impact i18n

- Réutilisation des libellés de commandes déjà définis au socle MVP. Éventuelle nouvelle chaîne pour un
  message d'échec dédié : « Commande LAN non confirmée ».

## À confirmer

- Aucun contrat externe nouveau à trancher au niveau fonctionnel. Point de vigilance pour la recette :
  cf. `.memory/analyse/smartclim-transport-broadlink-lan.md` § 5.4, une implémentation de référence
  publique contient une double encapsulation de trame manifestement erronée — la recette doit constater
  que les commandes produisent l'effet correct plutôt que présumer fiable une implémentation de référence
  telle quelle.

## Hors périmètre

- Le repli automatique vers le cloud en cas d'échec répété de l'écriture LAN est traité par
  `post-mvp/02-strategies-de-transport`. Ici, une commande qui échoue en LAN échoue — elle ne bascule pas
  d'elle-même.
