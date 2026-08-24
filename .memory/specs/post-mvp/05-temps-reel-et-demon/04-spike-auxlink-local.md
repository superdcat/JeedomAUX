# UC04 — Spike puis transport local alternatif AUXLink pour les modules récents

> **Domaine** : post-mvp/05-temps-reel-et-demon · **Statut** : à implémenter · **Dépend de** : UC02 de ce
> domaine

## Objectif

L'appareil de validation du plugin ignore totalement le protocole Broadlink UDP historique : sa MAC
appartient à Broadlink, mais il ne répond à aucune trame de ce protocole sur le réseau local. Un protocole
local distinct, **AUXLink** (découverte UDP puis session TCP persistante), existe pour les modules récents
mais n'a **jamais été confirmé** sur ce matériel précis. Cette UC vérifie d'abord cette hypothèse **sans
risque** (sonde en lecture seule), puis, si elle se confirme, offre un pilotage local — sans Internet — à
cet appareil.

⚠️ C'est la **seule piste connue** de pilotage local pour ce matériel : si elle échoue, l'appareil de
validation reste durablement dépendant d'un cloud pour être piloté depuis Jeedom.

## Comportement attendu

- **Phase spike (sonde en lecture seule)** : le démon (UC02 de ce domaine) tente une découverte AUXLink non
  intrusive sur le réseau local de l'appareil de validation, sans jamais établir de session authentifiée ni
  envoyer de commande à ce stade.
- Si aucune réponse n'est obtenue après une observation raisonnable : conclusion négative documentée dans
  la note d'analyse d'écosystème, et abandon assumé de cette piste — aucun code d'exploitation (session,
  pilotage) n'est laissé en place au-delà de la sonde de découverte elle-même.
- Si une réponse est obtenue, passage à la **phase transport** : établissement d'une session authentifiée
  avec l'appareil, maintien de session périodique, lecture de l'état, puis pilotage effectif (marche/arrêt,
  mode, consigne, et les autres commandes déjà disponibles sur l'équipement).
- Le secret nécessaire à l'appairage local (dérivé de métadonnées déjà récupérées côté cloud) est stocké
  **chiffré, par équipement** — jamais en clair.
- Une fois ce transport confirmé et fonctionnel, l'appareil de validation devient pilotable **sans
  connexion Internet**.
- Dans les deux cas (confirmation ou abandon), une **décision explicite et traçable** conclut cette UC —
  pas de zone grise laissée ouverte.

## Critères d'acceptation

- [ ] **AC1** — Une phase de sonde de découverte AUXLink est exécutée sur l'appareil de validation sans
      qu'aucune authentification ni commande n'ait été tentée au préalable (vérifiable par relecture du
      code produit à ce stade : aucune fonction d'écriture ou d'établissement de session n'y figure).
- [ ] **AC2** (chemin positif) — Si l'appareil répond à la découverte AUXLink, une session authentifiée
      s'établit avec succès, et une lecture de l'état de l'appareil (au minimum marche/arrêt et température
      ambiante) est obtenue via ce canal, sans transiter par un cloud.
- [ ] **AC3** (chemin positif) — En coupant l'accès Internet du serveur Jeedom, chacune des commandes déjà
      disponibles sur l'équipement (marche/arrêt, mode, consigne) envoyée via ce transport produit l'effet
      attendu sur l'appareil physique de validation — preuve du pilotage sans Internet.
- [ ] **AC4** (chemin positif) — Le secret d'appairage local de l'équipement n'apparaît en clair dans aucun
      journal ni réponse AJAX ; il est chiffré au même titre que les autres secrets d'équipement du plugin.
- [ ] **AC5** (chemin négatif) — Si l'appareil de validation ne répond à aucune tentative de découverte
      AUXLink après une observation raisonnable, la note d'analyse d'écosystème est mise à jour avec une
      conclusion négative explicite et justifiée, et aucun code d'exploitation de ce transport (session,
      pilotage) ne reste dans le dépôt au-delà de la sonde de découverte.
- [ ] **AC6** — Dans les deux cas, la décision finale (pilotage local AUXLink activé pour cet appareil, ou
      abandon documenté de cette piste) est consignée de façon traçable dans les notes d'analyse du projet.

## Impact i18n

- Si le chemin positif est atteint, nouvelles chaînes UI (français) anticipées : « Local (AUXLink) »,
  « Session locale établie », « Appairage local ». Aucune chaîne n'est due si le chemin négatif est retenu.

## À confirmer

- La découverte AUXLink (port UDP, trame magique) et la session TCP qui la suit (port, maintien de session)
  ne sont documentées que par du code tiers portant sur **d'autres appareils** que celui de validation,
  jamais vérifiées sur ce matériel précis — c'est précisément l'objet du spike de cette UC. Cf.
  `.memory/analyse/smartclim-ecosysteme-aux-broadlink.md` § 4 et § 7.
- Si la phase positive est atteinte, le format exact du secret d'appairage local dérivé des métadonnées
  cloud reste à établir à l'implémentation.

## Hors périmètre

- Le socle du démon Python et le pont de communication → UC02 de ce domaine.
- L'arbitrage entre plusieurs transports disponibles pour un même équipement (priorité AUTO/LOCAL/CLOUD) →
  `post-mvp/02-strategies-de-transport`, à réévaluer une fois le verdict de cette UC connu.
- Tout appareil autre que celui de validation : la généralisation de ce transport à d'autres modèles
  AUXLink n'est pas couverte par cette UC, centrée sur la confirmation ou l'infirmation pour l'appareil de
  validation.
- Le temps réel du cloud historique, déjà confirmé indépendamment → UC03 de ce domaine.
