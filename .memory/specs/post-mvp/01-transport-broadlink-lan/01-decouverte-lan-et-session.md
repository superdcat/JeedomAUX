# UC01 — Découverte broadcast, authentification et session locale

> **Domaine** : post-mvp/01-transport-broadlink-lan · **Statut** : à implémenter · **Dépend de** : UC03 du
> MVP (découverte et création des équipements)

## Objectif

Permettre à SmartClim de détecter, sur le réseau local, les climatiseurs qui répondent au protocole
Broadlink (UDP port 80), ou d'utiliser un appareil dont l'adresse a été saisie à la main, puis d'établir
avec lui une session authentifiée — préalable indispensable à toute lecture ou écriture ultérieure en LAN.
Sans cette UC, le transport local reste totalement inutilisable, que le réseau autorise ou non la
diffusion.

## Comportement attendu

- Lors d'un scan (bouton « Scanner les climatiseurs » du socle MVP), le plugin diffuse une requête de
  découverte sur le réseau local et écoute les réponses pendant un court délai.
- Chaque réponse valide déclenche une tentative d'authentification auprès de l'appareil correspondant : le
  plugin obtient un identifiant de session et une clé de session propres à cet échange, puis respecte un
  court délai avant d'émettre la première requête.
- Si la session est perdue en cours d'usage (délai dépassé, appareil redémarré…), le plugin la
  réétablit automatiquement, de façon transparente pour l'utilisateur — aucune action manuelle n'est
  nécessaire.
- Indépendamment du scan, l'utilisateur peut déclarer manuellement l'IP et la MAC d'un climatiseur (cas
  d'un réseau segmenté / VLAN où la diffusion n'atteint pas l'appareil) ; l'authentification et tout le
  reste du transport LAN fonctionnent alors exactement comme pour un appareil découvert automatiquement.
- Un appareil qui ne répond ni à la diffusion ni à une tentative d'authentification en IP connue est
  signalé « LAN indisponible » pour cet équipement. ⚠️ Ce n'est **jamais** traité comme une panne du
  plugin ni comme une erreur bloquante : une MAC Broadlink ne garantit pas que l'appareil répond au
  protocole (certains firmwares récents l'ignorent totalement).
- Une seule session locale est active à la fois par appareil : deux sollicitations ne se marchent jamais
  dessus.

## Critères d'acceptation

- [ ] **AC1** — Après un scan sur un réseau où au moins un climatiseur répond au protocole Broadlink,
      cet appareil apparaît dans les résultats de scan avec une indication « LAN disponible » et son
      adresse IP détectée.
- [ ] **AC2** — Sur ce même appareil, une lecture ou une commande (couvertes par les UC suivantes) réussit
      sans que l'utilisateur ait saisi la moindre information de connexion.
- [ ] **AC3** — Après saisie manuelle de l'IP et de la MAC d'un climatiseur non trouvé par le scan
      (diffusion bloquée), ce climatiseur devient utilisable en LAN avec le même comportement observé
      qu'un appareil découvert automatiquement.
- [ ] **AC4** — Un climatiseur qui ne répond ni à la diffusion ni à l'IP/MAC saisie manuellement obtient le
      statut « LAN indisponible » pour cet équipement, sans qu'aucune erreur ne remonte comme un incident
      du plugin (pas de journal de niveau erreur, pas de mise en défaut visible autrement que par ce
      statut).
- [ ] **AC5** — Après une coupure prolongée de la session (par ex. redémarrage du climatiseur ou coupure
      réseau simulée), la commande suivante aboutit malgré la perte de session, sans intervention de
      l'utilisateur — la réauthentification automatique est constatée par le succès de cette commande,
      éventuellement après un délai perceptible.
      *(Validable en deux temps — arbitrage `D-POSTMVP0101-06` du run `run-20260827-1008` : l'UC01
      **pose** la réauthentification — contrat de `smartclimBroadlinkLan::requete()`, déclencheurs
      (silence, codes appareil `-7`, `-4012`, `-1`), purge de session et empreinte d'invalidation —
      mais aucune commande n'existe avant l'UC02 (lecture) et l'UC03 (écriture) de ce domaine. Ce
      critère devient donc **observable en UC02/UC03**, comme AC2.)*
- [ ] **AC6** — Deux sollicitations rapprochées du même équipement (par ex. deux commandes quasi
      simultanées) n'entrent jamais en conflit visible : pas d'erreur de session, pas de réponse mélangée
      entre deux appareils.
- [ ] **AC7** — Un second scan sur le même réseau ne crée ni doublon ni nouvelle demande d'authentification
      perturbatrice pour un appareil déjà reconnu.

## Impact i18n

- Nouvelles chaînes UI (français) anticipées : « LAN disponible », « LAN indisponible », « Adresse IP »,
  « Adresse MAC », « Session locale réétablie » (ou équivalent). « Scanner les climatiseurs » existe déjà
  au socle MVP et est réutilisé tel quel.

## À confirmer

- Bornes exactes de la charge utile d'authentification (remplissage ASCII `'1'` jusqu'à `0x0F` ou `0x12`
  selon la référence) et nom de terminal envoyé — détail d'implémentation sans impact observable pour
  l'utilisateur ; cf. `.memory/analyse/smartclim-transport-broadlink-lan.md` § 4 et § 10.
- Utilité réelle des ports de diffusion secondaires (`15001`, `2415`) pour des climatiseurs par rapport à
  d'autres appareils Broadlink — cf. même fichier § 1 et § 10.

## Hors périmètre

- Le **choix** d'utiliser le transport LAN plutôt que le cloud, et le repli automatique en cas d'échecs
  répétés, appartiennent au domaine `post-mvp/02-strategies-de-transport`. Cette UC rend le transport LAN
  **disponible** et session-able ; elle ne décide pas quand l'utiliser.
