# UC01 — Interrupteurs de confort (éco, sommeil, afficheur, santé/ioniseur, anti-moisissure, nettoyage, silence)

> **Domaine** : post-mvp/04-fonctions-avancees · **Statut** : à implémenter · **Dépend de** : UC06 du MVP
> (commandes action)

## Objectif

Au-delà du pilotage de base (marche/arrêt, mode, consigne, vitesse, oscillations) livré au socle MVP,
l'utilisateur veut retrouver dans Jeedom les fonctions de confort secondaires de son climatiseur — éco,
sommeil, afficheur, santé/ioniseur, anti-moisissure, nettoyage automatique, silence — exactement comme il
les retrouve dans l'application constructeur ou sur la télécommande. Cette UC étend la surface fonctionnelle
du plugin à ces fonctions, en respectant strictement la règle d'or du projet : **une fonction n'est exposée
que si elle est réellement détectée sur l'appareil**, jamais par supposition.

## Comportement attendu

- Pour chaque fonction de confort dont le profil de capacités de l'équipement (établi par UC04 du MVP)
  confirme le support, une commande **action** (bascule marche/arrêt de la fonction) et une commande
  **info** (état courant de la fonction) apparaissent automatiquement sous l'équipement, avec un libellé
  français explicite.
- Actionner une commande de confort produit un effet réel et constatable sur le climatiseur physique (visible
  sur son afficheur, son comportement, ou dans l'application constructeur), pas seulement un changement
  d'état côté Jeedom.
- La commande info correspondante reflète l'état réellement lu sur l'appareil au rafraîchissement suivant —
  elle ne se contente pas d'afficher le dernier ordre envoyé par Jeedom sans jamais le vérifier.
- Le contrat de chaque commande (libellé, comportement à l'activation, sémantique de l'état lu) est le même
  quel que soit le transport qui alimente l'équipement : rien dans cette UC ne dépend du transport actif ni
  de l'arbitrage entre transports (hors périmètre, cf. plus bas). Au MVP, un seul transport (AUX Home) existe
  réellement ; l'UC est écrite pour rester valable telle quelle quand d'autres transports livreront ces
  mêmes fonctions.
- Cas dégradé — fonction non supportée : aucune commande n'apparaît pour cette fonction (ni visible ni
  masquée) ; rien à constater, ce qui est le comportement attendu.
- Cas dégradé — fonction dont le nom exact ou la valeur de commande n'est pas confirmé (cf. « À confirmer ») :
  la fonction n'est **pas livrée** tant qu'elle n'a pas été validée sur un appareil réel. Elle ne doit jamais
  apparaître comme une commande qui n'aurait aucun effet ou un effet erroné.

## Critères d'acceptation

- [ ] **AC1** — Sur un équipement dont le profil de capacités confirme le support d'une fonction de confort
      donnée (éco, sommeil, afficheur, santé/ioniseur, anti-moisissure, nettoyage ou silence), une commande
      action de bascule et une commande info d'état, toutes deux en français, sont visibles sous
      l'équipement dans Jeedom.
- [ ] **AC2** — Activer la commande action d'une fonction de confort déclenche un changement constatable sur
      le climatiseur physique (ex. voyant/icône sur l'afficheur, comportement sonore ou de ventilation propre
      à la fonction, état visible dans l'application constructeur).
- [ ] **AC3** — Après un rafraîchissement de l'équipement suivant l'activation, la commande info correspondante
      affiche l'état réellement lu sur l'appareil (et non uniquement le dernier ordre envoyé).
- [ ] **AC4** — Désactiver la fonction via sa commande action produit l'effet inverse constatable, et la
      commande info repasse à l'état « inactif » au rafraîchissement suivant.
- [ ] **AC5** — Sur un équipement dont le profil de capacités ne confirme PAS une fonction donnée, aucune
      commande (action ou info) relative à cette fonction n'apparaît sous l'équipement.
- [ ] **AC6** — Si le climatiseur est piloté en dehors de Jeedom (télécommande IR, application constructeur)
      pour activer/désactiver une fonction de confort exposée, un rafraîchissement de l'équipement dans
      Jeedom met à jour la commande info correspondante en conséquence.
- [ ] **AC7** — Aucune commande de confort livrée par cette UC ne correspond à une fonction dont le nom ou
      la valeur de commande n'a pas été validé sur un appareil réel : toute fonction encore listée en « À
      confirmer » au moment de la recette est retirée de la livraison plutôt que livrée en best-effort.

## Impact i18n

- Nouvelles chaînes UI (français) anticipées : « Éco », « Mode éco », « Sommeil », « Mode sommeil »,
  « Afficheur », « Santé / Ioniseur », « Anti-moisissure », « Nettoyage automatique », « Silence »,
  « Ultra-silence », « Activer », « Désactiver », « Actif », « Inactif ». Le libellé retenu pour chaque
  fonction dépendra du nom finalement confirmé (cf. « À confirmer »).

## À confirmer

- ⚠️ **Risque principal de cette UC** : les noms et valeurs exacts des intentions de confort côté transport
  AUX Home (`screen`/`screen_on_off`, `sleep_mode`, `eco`, `clean`, `healthy`, `anti_fungus`) proviennent
  d'une table générique (`deviceMutex`, cf. `.memory/brief.md` § 2) et de sources à statut ⚠️ (source unique,
  non vérifiée sur le matériel) dans `.memory/analyse/smartclim-transport-aux-home.md` § 4.2 et
  `.memory/analyse/smartclim-modele-abstrait-capacites.md` § 3.4. **Chacune doit être validée
  individuellement en recette** (l'action produit bien l'effet attendu, dans le bon sens) avant d'être
  considérée livrée — pas de livraison groupée « en bloc » de la table.
- ⚠️ **Fonction « silence »/« ultra-silence »** : le modèle générique du plugin (`smartclim-modele-abstrait-
  capacites.md` § 2) modélise déjà `SILENT`/`MUTE` comme une **valeur de la vitesse de ventilation**
  (`fanSpeed`), pas comme un interrupteur séparé — cette valeur est déjà couverte par la commande de vitesse
  livrée en UC06 du MVP. La table `deviceMutex` d'AUX Home mentionne en outre une clé distincte
  `ultra_silence` (`.memory/analyse/smartclim-transport-aux-home.md` § 4.2). **Il n'est pas établi que ces
  deux éléments soient des fonctions différentes** (interrupteur additionnel réel) ou une redondance/alias du
  même réglage vu par deux voies. Cette UC ne couvre que le cas où `ultra_silence` s'avère être une fonction
  **distincte** et confirmée de la vitesse « silencieuse » déjà pilotable ; si l'investigation conclut à une
  redondance, cette fonction est retirée du périmètre de l'UC sans que cela constitue un manque.
- Le sens exact (`0`/`1`) de chaque bascule côté AUX Home n'est pas confirmé (marqué ❓ dans l'analyse) :
  une commande qui produirait l'effet inverse de son libellé constitue un échec de recette pour cette
  fonction précise, pas pour l'UC entière.
- Le schéma complet de `GET /app/getConfig?id=deviceMutex` et la façon d'en dériver, appareil par appareil,
  la liste des fonctions réellement supportées restent à établir (`smartclim-transport-aux-home.md` § 9) ;
  en attendant, le profil de capacités (UC04 MVP) peut s'appuyer sur un profil par défaut prudent.

## Hors périmètre

- La détection du profil de capacités elle-même (quelles fonctions cet appareil supporte) → UC04 du MVP.
- L'affichage synthétique en tuile dashboard des fonctions de confort → `post-mvp/06-ergonomie-jeedom`.
- Le pilotage de la vitesse de ventilation, y compris ses valeurs `SILENT`/`MUTE` et `TURBO` déjà couvertes
  comme valeurs de `fanSpeed` → UC06 du MVP (aucune duplication de commande n'est attendue de cette UC).
- L'arbitrage entre transports et le choix de celui utilisé à un instant donné → `post-mvp/02-strategies-de-
  transport`.
- Les oscillations verticale/horizontale distinctes → UC02 de ce domaine.
- Les codes d'erreur, la sécurité enfant et la limitation de puissance → UC03 de ce domaine.
