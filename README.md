# SmartClim — climatiseurs AUX / Broadlink / AC Freedom pour Jeedom

Plugin [Jeedom](https://jeedom.com) pour piloter les **climatiseurs Wi-Fi de l'écosystème AUX / Broadlink /
AC Freedom**, *quelle que soit la marque commerciale* : AUX, Ballu, Centek, Dunham Bush, Kenwood, Rinnai,
Rcool, Tornado, Akai, Hyundai, Hisense, Royal Clima… **et toute autre marque utilisant le même protocole**.

> 🚧 **Statut : en développement.** Le socle MVP est spécifié et en cours d'implémentation, une UC à la
> fois. Le plugin n'est pas encore publiable sur le market.

## Pourquoi ce plugin

Les intégrations existantes ciblent soit le cloud historique **AC Freedom**, soit le **LAN Broadlink**,
soit l'une des générations d'appareils. SmartClim vise une **couche générique** qui ne dépend pas du
protocole :

```text
Device → Capabilities → Generic AC API → Transport
                                            ├── Broadlink Local (UDP)
                                            ├── AUX Cloud legacy (AC Freedom)
                                            └── AUX Home Cloud (appareils récents)
```

Conséquences concrètes :

- **Pas de whitelist de modèles.** Un appareil est pris en charge s'il est **joignable** par un des
  transports ; ses commandes Jeedom sont créées d'après ses **capacités réellement détectées**, pas d'après
  une liste de références.
- **Les commandes ne bougent pas quand le transport change.** Un scénario écrit pour un climatiseur piloté
  en LAN continue de fonctionner s'il bascule sur le cloud.
- **Ajouter une génération de firmware = enrichir une table de données**, pas réécrire la logique.

## Transports

| Transport | Statut | Ce qu'il apporte |
|---|---|---|
| **AUX Home** (cloud récent) | 🟢 socle MVP | Les appareils récents, qui ne répondent plus au Broadlink UDP |
| **Broadlink LAN** (UDP port 80) | ⚪ prévu | Pilotage **local**, sans Internet, latence divisée |
| **AUX Cloud legacy / AC Freedom** | ⚪ prévu | Le parc historique et les régions USA / Chine / Russie |

Une fois plusieurs transports disponibles, trois stratégies seront proposées **par équipement** :
**AUTO** (défaut — LAN prioritaire, repli cloud automatique en cas d'échecs répétés, et retour au LAN
dès qu'il redevient joignable), **LOCAL** (jamais de cloud) et **CLOUD** (jamais de LAN). Le transport
réellement utilisé reste **visible** dans l'interface.

## Fonctionnalités visées

- **Découverte** des climatiseurs (« Scanner les climatiseurs »), avec **fusion** d'un même appareil trouvé
  à la fois en LAN et dans le cloud — un équipement, pas deux.
- **Pilotage** : marche/arrêt, mode (auto, froid, déshumidification, chaud, ventilation), température de
  consigne, vitesse de ventilation, oscillations verticale et horizontale.
- **Lecture d'état** : température ambiante, état en ligne, transport actif, fraîcheur de la donnée — y
  compris après un changement fait **à la télécommande** ou **dans l'application constructeur**.
- **Fonctions de confort** selon l'appareil : éco, sommeil, afficheur, ioniseur/santé, anti-moisissure,
  nettoyage, silence, sécurité enfant, codes d'erreur.
- **Ergonomie Jeedom** : widget « climatiseur » (dashboard + mobile) et page-panneau multi-climatiseurs
  accessible aux utilisateurs non-admin.

## Prérequis

- Une instance **Jeedom** (4.2 minimum) — le plugin s'installe sous `<jeedom>/plugins/smartclim/` et dépend
  du core Jeedom : il ne fonctionne pas isolément.
- Un **compte AUX Home** (ou AC Freedom pour le transport historique) et au moins un climatiseur compatible.
- Aucune dépendance système au MVP : le plugin est **100 % PHP**, sans démon.

## Limites connues

- ⚠️ **La température ambiante remontée par le cloud AUX Home n'est pas temps réel** : elle peut avoir
  plusieurs dizaines de minutes de retard, y compris dans l'application officielle. Ne l'utilisez pas comme
  sonde de régulation fine dans un scénario.
- ⚠️ Un appareil dont la MAC appartient à Broadlink **n'est pas forcément pilotable en UDP local** :
  plusieurs firmwares récents ignorent complètement le protocole Broadlink LAN. Le plugin bascule alors sur
  le cloud, ce n'est pas une erreur.
- ⚠️ Les protocoles sont issus de **reverse engineering** de sources tierces : une mise à jour de firmware
  ou de backend peut casser un transport. C'est exactement ce que le multi-transport cherche à amortir.

## Développement

Le dépôt embarque un outillage [Claude Code](https://claude.com/claude-code) :

- **`/feature <spec>`** implémente une UC de bout en bout : spec technique → code → reviews croisées
  (qualité + sécurité) → traduction i18n → capitalisation mémoire.
- **`/init-plugin`** a déjà servi au cadrage initial (analyses, roadmap, specs, renommage du squelette) et
  n'est plus à relancer.

La feuille de route vit dans **`.memory/specs/`** : `MVP/` (8 UC ordonnées) puis `post-mvp/` (7 domaines).
La connaissance protocolaire et les décisions d'architecture vivent dans **`.memory/analyse/`**, découvrable
via son `INDEX.md`. Le brief d'origine est dans **`.memory/brief.md`**. Les conventions de code sont dans
**`CLAUDE.md`**.

### Structure du dépôt

```
core/            Cœur PHP (classes eqLogic/cmd, ajax, includes, widgets)
desktop/         UI desktop (page de config PHP, JS, modales)
plugin_info/     Manifeste (info.json), install, configuration, packages.json
docs/            Documentation utilisateur (par langue)
.claude/         Outillage Claude Code (commandes, agents, skills, mémoire)
.memory/         Brief, specs, analyses et index de doc (connaissance interne, versionnée)
CLAUDE.md        Guide projet lu par Claude Code
```

> ⚠️ **`plugin_info/configuration.php`** s'édite via son miroir **`configuration.txt`** (source de vérité
> éditable), resynchronisé par `cp plugin_info/configuration.txt plugin_info/configuration.php`. Voir
> `CLAUDE.md`.

### Intégration continue & formatage

- La CI s'appuie sur les workflows réutilisables de Jeedom (`.github/workflows/work.yml`) : check du plugin
  sur push/PR vers `beta` et PR vers `master`.
- Pousser sur une branche nommée **`prettier`** déclenche un bot qui reformate le code et commite.

### Internationalisation

Plugin **nativement multilingue** : langue source **français** (la clé *est* le texte français), chaînes UI
enveloppées (`{{...}}` en HTML/JS, `__('...', __FILE__)` en PHP), traductions dans
`core/i18n/<langue>.json` pour `en_US`, `de_DE` et `es_ES`.

## Crédits

L'implémentation s'appuie sur l'analyse de projets open source ayant documenté ces protocoles, notamment
[`maeek/ha-aux-cloud`](https://github.com/maeek/ha-aux-cloud),
[`fparrav/homebridge-aux-cloud`](https://github.com/fparrav/homebridge-aux-cloud),
[`azadaydinli/ac_freedom`](https://github.com/azadaydinli/ac_freedom),
[`azadaydinli/homebridge-ac-freedom`](https://github.com/azadaydinli/homebridge-ac-freedom),
[`latentharbor/ha-aux-a-plus`](https://github.com/latentharbor/ha-aux-a-plus) et
[`GijsZwegers/com.zwegersit.auxairco`](https://github.com/GijsZwegers/com.zwegersit.auxairco).
La liste complète, avec les licences et notices, sera publiée avec la documentation utilisateur.

## Licence

AGPL — voir la doc [développeur Jeedom](https://doc.jeedom.com/fr_FR/dev/) pour le cadre de publication des
plugins.
