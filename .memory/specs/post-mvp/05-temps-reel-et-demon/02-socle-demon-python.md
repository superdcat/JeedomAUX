# UC02 — Socle du démon Python et pont de communication avec Jeedom

> **Domaine** : post-mvp/05-temps-reel-et-demon · **Statut** : à implémenter · **Dépend de** : UC01 de ce
> domaine

## Objectif

Mettre en place l'infrastructure minimale d'un démon Python pour SmartClim : le processus lui-même, ses
dépendances déclarées, et le pont de communication bidirectionnel entre le plugin PHP et le démon — sans
encore implémenter aucun protocole métier (WebSocket legacy, AUXLink). Cette UC fait passer le plugin d'un
modèle 100 % PHP sans dépendance (MVP) à un modèle avec démon, condition préalable à toute UC de ce domaine
qui exploite un canal réellement persistant (UC03, et potentiellement UC04 selon son verdict). Elle garantit
aussi que ce changement de modèle ne dégrade jamais le socle existant : le pilotage par scrutation cron
reste pleinement opérant, démon arrêté ou non.

⚠️ Cette UC est construite dès lors qu'**au moins un** canal persistant réel est à exploiter dans ce domaine
— ce qui est déjà le cas indépendamment du verdict de UC01, grâce au relais WebSocket du cloud historique
(confirmé, exploité par UC03).

## Comportement attendu

- **Prérequis** : le squelette de démon (`resources/demond/` : `demond.py` + la lib `jeedom/`) a été
  supprimé lors du renommage du gabarit effectué avec l'option « pas de démon ». Il est restauré avant toute
  autre étape, depuis le commit initial versionné de ce dépôt ou depuis le gabarit officiel amont.
- Le manifeste du plugin déclare désormais un démon propre et des dépendances (là où le MVP n'en déclarait
  aucun).
- Les dépendances Python nécessaires sont déclarées dans le fichier de dépendances du plugin, dans le
  respect strict des règles du format attendu par Jeedom (version dans la valeur associée au paquet, jamais
  dans son nom ; version exacte, sans opérateur de comparaison ; aucune méthode de vérification
  supplémentaire redondante avec ce fichier).
- Depuis la page de configuration Jeedom, l'indicateur de dépendances et l'indicateur d'état du démon
  affichent tous deux un état positif une fois les dépendances installées et le démon démarré.
- Le démon démarre à l'activation du plugin ou au démarrage de Jeedom, s'arrête proprement sur demande,
  redémarre sur action explicite, et reprend automatiquement son fonctionnement après un redémarrage complet
  du serveur Jeedom, sans intervention manuelle.
- Un pont de communication bidirectionnel est opérationnel : le plugin PHP peut transmettre une instruction
  au démon, et le démon peut notifier Jeedom d'un événement, sans qu'aucun secret ne transite en clair sur
  ce canal.
- Cas dégradé — démon arrêté, en erreur, ou dépendances non installées : le plugin continue de fonctionner
  exactement comme au socle MVP (scrutation cron, envoi de commandes). Le démon **accélère**, il ne
  **conditionne** jamais le fonctionnement de base.

## Critères d'acceptation

- [ ] **AC1** — Après installation complète du plugin (dépendances installées), la page de configuration
      Jeedom affiche l'indicateur de dépendances à l'état correct et l'indicateur d'état du démon à l'état
      actif.
- [ ] **AC2** — Arrêter le démon depuis l'interface Jeedom, puis le redémarrer, produit l'effet attendu (le
      processus est effectivement arrêté puis relancé), sans erreur dans les journaux du plugin ni du démon.
- [ ] **AC3** — Après un redémarrage complet du service Jeedom, le démon redémarre automatiquement sans
      intervention manuelle et son indicateur d'état redevient actif.
- [ ] **AC4** — Avec le démon volontairement arrêté, le pilotage existant du socle MVP (rafraîchissement
      cron, envoi de commandes) continue de fonctionner sans aucune régression constatable.
- [ ] **AC5** — Une instruction envoyée depuis Jeedom vers le démon, puis une notification envoyée par le
      démon vers Jeedom, sont toutes deux effectivement transmises et observables (par exemple dans les
      journaux), démontrant que le pont fonctionne dans les deux sens.
- [ ] **AC6** — L'examen des journaux du plugin et du démon, après plusieurs échanges via le pont, ne révèle
      aucun secret (mot de passe, jeton) en clair.
- [ ] **AC7** — Le fichier de dépendances du plugin déclare chaque paquet avec sa version dans la valeur
      (jamais concaténée au nom du paquet) et sans opérateur de comparaison ; une fois les dépendances
      installées, l'indicateur correspondant reste stable à l'état correct d'un cycle de vérification à
      l'autre (pas de réinstallation répétée en boucle).

## Impact i18n

- Aucune nouvelle chaîne UI anticipée à ce stade : les indicateurs de dépendances et d'état du démon sont un
  mécanisme standard fourni par le core Jeedom, pas par le plugin. D'éventuels messages de journal
  spécifiques au pont ne sont pas destinés à l'utilisateur final.

## À confirmer

- La liste exacte des paquets pip3 nécessaires dépend des protocoles retenus par les UC suivantes de ce
  domaine (websocket-client pour UC03 ; pycryptodome et éventuellement paho-mqtt selon le verdict de UC01 et
  de UC04) — cf. `.memory/analyse/smartclim-daemon-choix.md` § 5. Les numéros de version exacts doivent être
  relevés au moment de l'implémentation (une version figée aujourd'hui serait périmée).
- L'origine exacte du squelette de démon à restaurer (ce dépôt à son commit initial, ou le gabarit officiel
  `jeedom/plugin-template` amont) et sa compatibilité avec la version du core Jeedom ciblée sont à vérifier
  à l'implémentation.

## Hors périmètre

- Tout protocole métier exploité par le démon : le relais WebSocket du cloud historique → UC03 de ce
  domaine ; le transport local AUXLink → UC04 de ce domaine. Cette UC ne fait transiter aucune donnée
  climatiseur réelle, seulement un aller-retour de vérification du pont.
- L'exploitation d'un éventuel canal de push sur le transport AUX Home (dépend du verdict de UC01).
- Le pilotage nominal du plugin, déjà pleinement assuré par le socle MVP en scrutation — ce domaine accélère
  la fraîcheur perçue, il ne remplace pas ce socle.
