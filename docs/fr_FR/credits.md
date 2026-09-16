# Crédits et licences

## 1. Pourquoi cette page

SmartClim pilote des climatiseurs par des protocoles que leur fabricant n'a jamais documentés
publiquement. Cette connaissance provient d'un travail communautaire de reverse engineering mené par
plusieurs développeurs indépendants, dont certains ont publié leur code sous licence libre. Cette page
liste ces projets, honore les obligations de leurs licences, et distingue précisément ce qui a été
**repris** de ce qui a seulement servi de **source d'information**.

## 2. Sous quelle licence SmartClim est publié

SmartClim est publié sous licence **GNU General Public License version 3, ou (à votre choix) toute
version ultérieure** (GPL-3.0-or-later). Le texte complet de cette licence figure dans le fichier
`LICENSE` à la racine du dépôt du plugin.

## 3. Projets dont du code a été repris

Ces projets sont cités avec leur notice de copyright et de licence, conformément à leurs conditions
(« inclure la notice de copyright et d'autorisation dans toutes les copies ou parties substantielles du
logiciel », pour les projets sous licence MIT).

| Projet | Auteur | Licence | Ce qui est repris |
|---|---|---|---|
| [`mjg59/python-broadlink`](https://github.com/mjg59/python-broadlink) | Mike Ryan (2014), Matthew Garrett (2016) et contributeurs | MIT | Tout le contrat protocolaire du transport Broadlink LAN (découverte, authentification, envoi de paquets, horodatage) |
| [`GijsZwegers/com.zwegersit.auxairco`](https://github.com/GijsZwegers/com.zwegersit.auxairco) | Gijs Zwegers | MIT | Constantes de protocole AUX Home et du cloud historique, recopiées verbatim |
| [`maeek/ha-aux-cloud`](https://github.com/maeek/ha-aux-cloud) | maeek | MIT | Cloud historique (AC Freedom) ; séquence du relais temps réel (initialisation, abonnement, battement) |
| [`fparrav/homebridge-aux-cloud`](https://github.com/fparrav/homebridge-aux-cloud) | Felipe Parra (`fparrav`) | MIT | Contrat d'écriture en réseau local re-vérifié indépendamment ; cloud historique ; stratégies de transport |
| [`latentharbor/ha-aux-a-plus`](https://github.com/latentharbor/ha-aux-a-plus) | latentharbor et contributeurs | MIT | Algorithme de contrôle CRC-16 CCITT porté dans le module de sonde de découverte AUXLink ; découverte AUXLink sur le réseau local |
| [`jeedom/plugin-template`](https://github.com/jeedom/plugin-template) | Jeedom SAS | GPL-3.0-or-later | Squelette complet du plugin ; bibliothèque de communication avec Jeedom utilisée par le démon (seule dépendance sous licence copyleft) |

`jeedom/plugin-template` est sous licence **GPL-3.0-or-later**, c'est-à-dire la même licence que
SmartClim lui-même (§ 2) : son texte intégral figure dans le fichier `LICENSE` à la racine du dépôt, et
n'est donc pas reproduit une seconde fois ci-dessous.

### 3.1 Notices de copyright et de licence (MIT)

Conformément aux conditions de la licence MIT, les notices de copyright des cinq projets MIT du tableau
ci-dessus sont reproduites verbatim :

```
mjg59/python-broadlink :
Copyright (c) 2014 Mike Ryan
Copyright (c) 2016 Matthew Garrett

GijsZwegers/com.zwegersit.auxairco :
Copyright (c) 2026 Gijs Zwegers

maeek/ha-aux-cloud :
Copyright (c) 2026 maeek

fparrav/homebridge-aux-cloud :
Copyright (c) 2025 Felipe Parra

latentharbor/ha-aux-a-plus :
Copyright (c) 2026
```

Le corps de la licence MIT ci-dessous s'applique **identiquement aux cinq notices de copyright
ci-dessus** (il ne dépend pas du titulaire ni de l'année) :

```
Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
```

## 4. Projets ayant servi de source factuelle

Ces projets ont été consultés pour comprendre le protocole, sans qu'aucune ligne de leur code ne soit
reprise dans SmartClim. Leur mention de licence est reproduite par courtoisie, même quand leur licence
n'impose pas de notice de reprise :

- [`makleso6/homebridge-broadlink-heater-cooler`](https://github.com/makleso6/homebridge-broadlink-heater-cooler) (Apache-2.0)
- [`makleso6/broadlink-aircon-api`](https://github.com/makleso6/broadlink-aircon-api) (Apache-2.0)
- [`maxmirazh33/aircore`](https://github.com/maxmirazh33/aircore) (MIT)
- [`Apollon77/node-ph803w`](https://github.com/Apollon77/node-ph803w)
- [`gizwits/Gizwits-GAgent`](https://github.com/gizwits/Gizwits-GAgent)
- L'article technique de Gijs Zwegers sur zwegersit.nl — c'est la **source unique** du constat de retard
  de la température ambiante mentionné dans la documentation principale (§ 8.2 de
  [l'index](index.md)).

## 5. Projets consultés sans licence ouverte

Ces projets ont été consultés à titre de référence factuelle uniquement, **sans qu'aucun code n'en soit
copié** — ils ne publient aucune licence ouverte (droits réservés par défaut, ou statut de licence non
déterminé) :

- [`azadaydinli/ac_freedom`](https://github.com/azadaydinli/ac_freedom) — aucun fichier de licence, tous
  droits réservés par défaut ;
- [`azadaydinli/homebridge-ac-freedom`](https://github.com/azadaydinli/homebridge-ac-freedom) — idem ;
- [`GrKoR/esphome_aux_ac_component`](https://github.com/GrKoR/esphome_aux_ac_component) — statut de
  licence non déterminé (« NOASSERTION ») ; documente un protocole AUX série/filaire qui n'est **aucun**
  des trois transports mis en œuvre par SmartClim.

## 6. Le squelette et les bibliothèques Jeedom

Le plugin est construit à partir du squelette officiel `jeedom/plugin-template` (GPL-3.0-or-later, cf.
tableau du § 3), qui fournit l'architecture de base attendue par Jeedom (page de configuration,
installation, gestion des équipements) ainsi que la bibliothèque de communication utilisée par le
programme auxiliaire du plugin (le « démon », cf. § 2.3 de [l'index](index.md)).

## 7. Les dépendances installées à l'exécution

Deux bibliothèques Python sont installées automatiquement par Jeedom au démarrage du plugin (écran
« Dépendances »), sans être redistribuées dans ce dépôt : aucune obligation de licence n'est donc
déclenchée pour SmartClim, mais elles sont citées par courtoisie.

| Bibliothèque | Rôle |
|---|---|
| `requests` | Requêtes HTTP du programme auxiliaire |
| `websocket-client` | Relais temps réel avec le cloud historique |

## 8. Marques citées — aucune affiliation, aucun agrément

Les noms AUX, Broadlink, AC Freedom, Ballu, Centek, Dunham Bush, Kenwood, Rinnai, Rcool, Tornado, Akai,
Hyundai, Hisense, Royal Clima et toute autre marque commerciale mentionnée dans cette documentation sont
la propriété de leurs titulaires respectifs. SmartClim n'est affilié à, agréé par, ni sponsorisé par
aucune de ces marques : il s'agit d'un projet communautaire indépendant.
