# SmartClim — Écosystème AUX / Broadlink / AC Freedom : panorama & matrice de décision

> **But** : savoir, face à un climatiseur donné, **quel protocole il parle** et **quel transport le plugin
> doit utiliser**. Point d'entrée des trois analyses de transport (`smartclim-transport-*.md`).
>
> **Date** : 2026-08-24. Tout ce qui n'est pas vérifié dans du code source lu est explicitement marqué
> **[HYPOTHÈSE]** ou **[À CONFIRMER]**.

---

## 1. Le point clé : « AUX » n'est pas un protocole, c'est trois générations

Le nom commercial (AUX, Ballu, Centek, Tornado, Royal Clima…) ne dit **rien** du protocole. Ce qui compte
est le **module Wi-Fi** embarqué et le **backend** auquel il est appairé. Trois générations coexistent :

| Gén. | Nom usuel | Application mobile | Backend cloud | LAN |
|---|---|---|---|---|
| **G1** | Broadlink AC / « AC Freedom » historique | AC Freedom | *(aucun — LAN pur possible)* | **UDP port 80**, protocole Broadlink (auth `0x65`, AES-128-CBC) |
| **G2** | AUX Cloud « legacy » (infra Broadlink/iBroadlink) | AC Freedom | `app-service-*.smarthomecs.de/.com`, `*.ibroadlink.com` | souvent oui (même module que G1) |
| **G3** | AUX Home / AUX A+ (« smart home » AUX propriétaire) | **AUX Home** (Android/EU), AC Freedom (iOS), AUX A+ (CN) | `eu-smthome-api.aux-global.com` (EU), `smarthome.aux-home.com` (CN) | **AUXLink** : découverte UDP 12414 / réponse 2415, session **TCP 12416**, magic `a5a5` — **pas** Broadlink UDP 80 |

> ⚠️ **Le piège central du projet** : un appareil peut avoir une **MAC appartenant à Broadlink** et
> **ne pas répondre du tout** au protocole Broadlink UDP 80. C'est exactement le cas de l'appareil de
> validation (`.memory/brief.md` § 19 : `hello` → timeout, `auth` → timeout, broadcast → absent, alors que deux
> RM4 Pro du même LAN répondent). Le module est G3 : il parle AUXLink, pas Broadlink.
>
> Corollaire d'architecture : **l'absence de réponse LAN n'est jamais une erreur fatale**, c'est un
> résultat de sonde qui oriente le choix de transport.

Confirmations croisées :

- `fparrav/homebridge-aux-cloud` (README) : « *LAN control may not work on newer devices… Some newer AC
  units have updated firmware that blocks or ignores local UDP commands* ».
- `zwegersit.nl` (article de reverse engineering, § 01) : tentative Broadlink UDP sur une AUX Freedom
  récente → « *Een UDP-handshake naar het IP-adres van de wifi-module liep gewoon in een timeout* ».
- `latentharbor/ha-aux-a-plus` : les modules récents exposent un LAN **différent** (AUXLink TCP 12416).

## 2. Multimarque : le critère est le protocole, pas la marque

Marques revendiquées compatibles « AC Freedom » par `azadaydinli/homebridge-ac-freedom` (README) :
AUX, Ballu, Centek, Dunham Bush, Kenwood, Rinnai, Rcool, Tornado, Akai, Hyundai, Hisense, Royal Clima.
`maxmirazh33/aircore` ajoute Zanussi, Rovex, Electrolux côté Broadlink LAN.

**Décision produit** : aucune whitelist de marques dans le code. Le plugin classe un appareil par
**transport joignable** + **capacités effectivement observées**. La liste de marques n'existe qu'à titre
documentaire (docs utilisateur, description `info.json`). Cf. `.memory/brief.md` § 10.

## 3. Ce qui est réellement commun aux trois générations

Découverte majeure de l'analyse, qui structure toute l'architecture :

> **Les trois transports véhiculent, au fond, la même trame HVAC de type « UART Broadlink » commençant par
> `bb00…`, avec la même disposition de bits.**

- **G1/LAN** : la trame `bb 00 06 80 …` est le payload chiffré AES-128-CBC envoyé en UDP
  (`fparrav/.../api/broadlink/Protocol.ts::buildCommandPayload` ;
  `azadaydinli/ac_freedom/broadlink_ac_api.py::_build_set_state_payload`).
- **G3/AUX Home** : les champs `status.control` / `status.running` renvoyés par le **cloud** sont **cette
  même trame en hexadécimal** (`"running": "bb000700000018000121e4…"`, `"type": "uart"`) — vérifié dans
  `GijsZwegers/com.zwegersit.auxairco/lib/auxcloud/client.ts::parseControlState`, qui décode `bytes[10]`,
  `bytes[12]`, `bytes[15]`, `bytes[18]` **exactement comme** le parseur LAN Broadlink.
- **G3/AUX A+** : la requête de statut MQTT est `bb0006800000020021011b7e`
  (`latentharbor/ha-aux-a-plus/mqtt.py::_BIG_STATUS_QUERY`) — **le même magic** que le `getInfo` Broadlink
  LAN (`0C00 BB00 0680 0000 0200 2101 1B7E 0000`).

**Conséquence pour SmartClim** : le **décodeur de trame HVAC est mutualisable** entre le transport LAN
Broadlink et le transport cloud AUX Home. Gain d'implémentation majeur, et justification directe de la
couche d'abstraction demandée au `.memory/brief.md` § 20 (`Device → Capabilities → Generic AC API → Transport`).

En revanche **les tables de codes ne sont PAS communes** : les valeurs de `mode` et de `fanSpeed` diffèrent
entre le fil (LAN / `status.control`) et l'API JSON du cloud legacy. Voir
`smartclim-modele-abstrait-capacites.md` § 3 — c'est la source d'erreur n°1 dans les projets étudiés.

## 4. Matrice de décision « quel transport pour cet appareil ? »

| Sonde | Résultat | Conclusion |
|---|---|---|
| Login `eu-smthome-api.aux-global.com` OK **et** appareil présent dans `/app/user_device` | oui | **G3 EU** → transport `AUX_HOME` (MVP) |
| Login legacy `app-service-*` OK **et** appareil dans `getfamilylist` + `dev/query` | oui | **G2** → transport `AUX_CLOUD_LEGACY` |
| Broadcast Broadlink UDP 80 / 15001 / 2415 → réponse cmd `0xe9`/`0xee` | oui | **G1/G2** → `BROADLINK_LAN` disponible |
| Auth Broadlink `0x65` sur IP connue → timeout | oui | LAN Broadlink **indisponible** (pas une erreur) |
| Découverte UDP 12414 (magic `a5a5…`) → réponse sur 2415 | **[À CONFIRMER sur le matériel]** | **G3** → `AUXLINK_LAN` potentiellement disponible |

Ordre de préférence recommandé quand plusieurs transports répondent (cf. `.memory/brief.md` § 4) :
`BROADLINK_LAN` / `AUXLINK_LAN` > `AUX_HOME` > `AUX_CLOUD_LEGACY`.

## 5. Impact sur la feuille de route

1. **MVP = `AUX_HOME` seul.** Seul transport dont on sait qu'il fonctionne sur l'appareil de validation,
   et celui dont le contrat est le mieux documenté (implémentation de référence MIT complète, cf.
   `smartclim-transport-aux-home.md`).
2. **Post-MVP prioritaire = `BROADLINK_LAN`** (grand parc G1/G2 installé, pilotage sans Internet).
3. **Post-MVP à fort potentiel = `AUXLINK_LAN`** : seule piste connue pour donner du **pilotage local** à
   l'appareil de validation. À traiter comme un **spike** (sonde read-only avant tout code de production),
   car non confirmé sur ce matériel.
4. **`AUX_CLOUD_LEGACY`** : indispensable à la promesse multimarque/multigénération, mais aucun appareil de
   test disponible → développement contre les implémentations de référence + recette communautaire.

## 6. Sources et licences (cf. `.memory/brief.md` § 17)

| Projet | Langage | Portée | Licence | Réutilisation |
|---|---|---|---|---|
| `GijsZwegers/com.zwegersit.auxairco` | TypeScript | **AUX Home EU** (login RSA+AES, `user_device`, `v2/control`, décodage trame) + legacy | **MIT** | ✅ portage de code autorisé (conserver la notice) |
| `zwegersit.nl/projecten/airco-homey/` | article (NL) | démarche + contrat AUX Home vérifié à la capture réseau | article | ✅ source factuelle, à citer |
| `maeek/ha-aux-cloud` | Python | **AUX Cloud legacy** complet + WebSocket relay | **MIT** | ✅ portage autorisé |
| `fparrav/homebridge-aux-cloud` | TypeScript | **Broadlink LAN** + legacy + stratégies LAN/Cloud/Hybride | **MIT** | ✅ portage autorisé |
| `latentharbor/ha-aux-a-plus` | Python | **AUX A+ / AUXLink** LAN TCP 12416 + MQTT push | **MIT** | ✅ portage autorisé |
| `makleso6/homebridge-broadlink-heater-cooler`, `makleso6/broadlink-aircon-api` | TS | origine du protocole Broadlink AC | **Apache-2.0** | ✅ compatible (conserver `NOTICE`) |
| `maxmirazh33/aircore` | Python | Broadlink LAN, multimarque | **MIT** | ✅ |
| `azadaydinli/ac_freedom` | Python | Broadlink LAN + legacy | **AUCUNE licence** | ❌ **pas de copie de code** — référence factuelle/conceptuelle seulement |
| `azadaydinli/homebridge-ac-freedom` | JS | multimarque, presets | **AUCUNE licence** | ❌ idem |
| `GrKoR/esphome_aux_ac_component` | C++ | protocole AUX série (UART) | `NOASSERTION` | ⚠️ licence à vérifier avant tout emprunt |

> **Compatibilité** : le plugin Jeedom est sous **AGPL-3.0** (`info.json "licence": "AGPL"`). MIT et
> Apache-2.0 sont compatibles *en aval* (intégration dans un projet AGPL) **à condition de conserver les
> notices de copyright et de licence** des sources d'origine. Prévoir une section « Crédits » dans la doc
> utilisateur (`docs/fr_FR/`).
>
> ❌ **Les deux dépôts `azadaydinli` sont sans fichier `LICENSE`** (vérifié :
> `raw.githubusercontent.com/azadaydinli/ac_freedom/main/LICENSE` → HTTP 404) : par défaut « tous droits
> réservés ». On peut s'en servir pour **comprendre** un protocole (un fait technique n'est pas
> protégeable) mais **pas** en recopier le code.

## 7. À confirmer

- [ ] Le module de l'appareil de validation répond-il au LAN **AUXLink** (UDP 12414 / TCP 12416) ?
- [ ] Le backend EU `eu-smthome-api.aux-global.com` a-t-il un pendant MQTT du type
      `smthomem2m.aux-home.com` (le backend CN en a un) ? **[HYPOTHÈSE forte, non vérifiée]**
- [ ] Les comptes AC Freedom (G2) et AUX Home (G3) sont-ils distincts ou unifiés ? Les implémentations de
      référence supposent **distincts** et essaient G3 puis G1/G2 en repli
      (`com.zwegersit.auxairco/drivers/airco/driver.ts::onPair`).
