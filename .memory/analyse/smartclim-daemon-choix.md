# SmartClim — Démon ou pas ? Arbitrage PHP pur / Python / Node.js

> **Verdict** : **MVP sans démon** (100 % PHP, `hasOwnDeamon: false`, `hasDependency: false`).
> **Démon Python** introduit **plus tard**, uniquement quand on ajoute un canal **persistant réel**
> (WebSocket legacy, MQTT AUX, session AUXLink TCP).
>
> **Date** : 2026-08-24.

---

## 1. Ce que le `.memory/brief.md` demande (§ 13)

> « *Le choix Python vs Node doit être fait après analyse du code existant et du volume de code
> réutilisable. Ne pas réimplémenter en PHP un protocole complexe uniquement pour avoir du PHP.* »

L'arbitrage se fait donc sur trois axes : **besoin réel de persistance**, **volume de code réutilisable**,
**coût d'intégration Jeedom**.

## 2. Axe 1 — Y a-t-il un besoin réel de canal persistant ?

| Transport | Nature | Canal persistant nécessaire ? |
|---|---|---|
| **`AUX_HOME`** (MVP) | REST + scrutation ; **aucun push confirmé** ; donnée d'ambiance rafraîchie en **minutes à 30 min** (`smartclim-transport-aux-home.md` § 6.4/§ 7) | **NON** — un démon n'améliorerait strictement rien : la latence vient du backend, pas du transport |
| `BROADLINK_LAN` | requête/réponse UDP ; session ré-authentifiable en ~200 ms | **NON** (gain marginal : éviter une ré-auth) |
| `AUX_CLOUD_LEGACY` (REST) | requête/réponse | **NON** |
| `AUX_CLOUD_LEGACY` (**WebSocket relay**) | `wss://app-relay-*/appsync/apprelay/relayconnect`, keep-alive **toutes les 10 s**, reconnexion automatique ✅ | **OUI** |
| `AUXLINK_LAN` ❓ | session **TCP** authentifiée, heartbeat **toutes les ~4 s** ✅ (`ha-aux-a-plus/lan.py`) | **OUI** |
| MQTT AUX ❓ | broker TLS, souscription permanente ✅ (backend CN) | **OUI** |

**Conclusion de l'axe 1** : le besoin de démon est **entièrement post-MVP**. Introduire un démon dès le
MVP reviendrait à ajouter un processus, une dépendance pip, un indicateur d'état, un pont socket et une
surface de panne — **pour zéro gain fonctionnel**. C'est exactement le cas où `CLAUDE.md` recommande
« REST + polling cron, sans démon ».

## 3. Axe 2 — Volume de code réutilisable, par langage

### Ce qu'il faut écrire pour le MVP (`AUX_HOME`)

| Brique | Difficulté en PHP | Fonction native |
|---|---|---|
| Requêtes HTTP + en-têtes + enveloppe `{code,data}` | triviale | cURL |
| DER base64 → PEM | triviale | concaténation |
| **RSA/ECB/PKCS1Padding** par blocs de 117 octets | facile | `openssl_public_encrypt(..., OPENSSL_PKCS1_PADDING)` |
| **AES-128-ECB / PKCS5Padding** | facile | `openssl_encrypt($s, 'aes-128-ecb', $k, OPENSSL_RAW_DATA)` |
| Décodage de trame hexadécimale + opérations de bits | facile | `hexdec`/`str_split`/`unpack`, opérateurs `>>`, `&` |

→ **~200 lignes de PHP.** Aucune de ces briques ne justifie un démon.

### Ce qu'il faudrait pour les transports post-MVP

| Brique | PHP | Python |
|---|---|---|
| UDP broadcast + unicast | `socket_*` / `stream_socket_*` — verbeux mais standard | `asyncio.DatagramProtocol` |
| AES-128-CBC zero padding | `OPENSSL_ZERO_PADDING` | `pycryptodome` |
| WebSocket client + keep-alive + reconnexion | ❌ **pas de client WebSocket natif** ; dépendance Composer et pas de processus long en PHP-FPM | `websocket-client` / `aiohttp` — trivial |
| MQTT TLS | ❌ | `paho-mqtt` |
| Session TCP persistante + heartbeat 4 s | ❌ | trivial |

### Volume réellement réutilisable (code lu, licences vérifiées)

| Langage | Sources exploitables | Ce qu'on y prend |
|---|---|---|
| **Python (MIT)** | `maeek/ha-aux-cloud` (~950 l. : legacy complet **+ WebSocket**), `latentharbor/ha-aux-a-plus` (~2 050 l. : **AUXLink TCP + MQTT**), `maxmirazh33/aircore` | **transposable quasi tel quel** dans un démon Jeedom |
| **TypeScript (MIT)** | `fparrav/homebridge-aux-cloud` (~2 750 l. : LAN Broadlink + legacy + stratégies), `GijsZwegers/com.zwegersit.auxairco` (~500 l. : **AUX Home**) | ⚠️ **spécification excellente, mais à réécrire** : dépend de l'écosystème Homebridge/Homey (`Platform`, `Accessory`, `Device`, `dgram-as-promised`), rien n'est directement exécutable |
| Python **sans licence** | `azadaydinli/ac_freedom` | ❌ lecture seule |

→ **Le code directement réutilisable est massivement Python**, et il couvre **précisément** les briques
qui exigeront un démon (WebSocket, MQTT, TCP persistant). Le TypeScript couvre AUX Home et le LAN
Broadlink — c'est-à-dire ce qu'on écrit en PHP de toute façon.

## 4. Axe 3 — Coût d'intégration Jeedom

| Critère | Python | Node.js |
|---|---|---|
| Squelette fourni | ✅ `resources/demond/demond.py` + lib `jeedom/` (`jeedom_socket` PHP→démon, `jeedom_com` démon→Jeedom, gestion PID/signaux/log) | ❌ à écrire intégralement |
| Déclaration des dépendances | ✅ `packages.json` → `pip3`, mécanisme officiel piloté par `system::checkAndInstall` | ⚠️ Le `packages.json` du template contient bien des sections `npm`/`yarn`, mais **pointant vers un chemin fautif** (`ressources/demond` — double `s`) ; mécanisme moins documenté, install plus lourde (`node_modules`), et pas de retour d'état aussi fiable dans l'indicateur de dépendances |
| Runtime présent sur toutes les cibles Jeedom (Smart, Luna, Atlas, RPi, Docker) | ✅ Python 3 systématiquement présent | ⚠️ Node non garanti / versions hétérogènes |
| Hooks plugin | `deamon_info` / `deamon_start` / `deamon_stop` + callback `core/php/jeeSmartclim.php` | identiques, mais tout l'outillage à recréer |

⚠️ **Pièges `packages.json` à respecter** (règles génériques `CLAUDE.md`, coûteuses à redécouvrir) :
la **version va dans la VALEUR** (`"paho-mqtt": {"version": "2.1.0"}`, jamais `"paho-mqtt==2.1.0": {}`) ;
**aucun opérateur** `<`/`>` dans `version` (redirection shell → paquet jamais installé) ; **ne pas définir**
`smartclim::dependancy_info()` (code mort dès que `packages.json` existe — le hook officiel est
`additionnalDependancyCheck()`).

## 5. Verdict

| Phase | Décision |
|---|---|
| **MVP** (`AUX_HOME`) | **PHP pur, sans démon, sans dépendance.** `hasOwnDeamon: false`, `hasDependency: false`, `resources/` supprimé par le renommage (`--daemon no`). |
| **Post-MVP `BROADLINK_LAN`** | **PHP pur** également (UDP + AES sont natifs ; le gain d'un démon est marginal). |
| **Post-MVP `AUX_CLOUD_LEGACY` REST** | **PHP pur**. |
| **Post-MVP temps réel** (WebSocket legacy, MQTT ❓, AUXLink TCP ❓) | **Démon Python**, réintroduit à ce moment-là. |

> ⚠️ **Conséquence sur le renommage du squelette** : `helperConfiguration.py --daemon no` **supprime
> `resources/`**. Réintroduire un démon plus tard supposera de récupérer `resources/demond/` (démon +
> lib `jeedom/`) depuis le dépôt `jeedom/plugin-template` d'origine. **À signaler explicitement dans la
> spec de l'UC « démon »** pour ne pas le redécouvrir.
>
> Alternative si l'on veut se garder la porte ouverte sans coût : conserver `resources/` (renommage avec
> `--daemon yes` puis remettre `hasOwnDeamon: false` à la main). **Décision à trancher avec l'utilisateur**
> au moment du renommage.

### Dépendances prévues **le jour où** le démon arrive

Format `packages.json` (rappel : version dans la valeur, exacte, sans opérateur) :

| Paquet pip3 | Rôle |
|---|---|
| `requests` | HTTP synchrone côté démon |
| `pycryptodome` | AES-128-CBC zero padding (LAN, legacy) |
| `websocket-client` | WebSocket relay du cloud legacy |
| `paho-mqtt` | push MQTT ❓ (si confirmé) — ⚠️ **nécessaire seulement pour la marche 3** (souscription permanente), cf. § 6 |

⚠️ **Les numéros de version exacts doivent être relevés sur PyPI au moment d'écrire l'UC** (une version
figée ici serait périmée et le format n'admet pas d'opérateur de comparaison). Vérifier également la
compatibilité `paho-mqtt` 1.x vs 2.x (API cliente incompatible entre les deux majeures).

## 6. Ce qui déclencherait une révision de ce verdict

- [~] ~~Découverte d'un **push confirmé** sur `eu-smthome-api.aux-global.com` (MQTT ou WebSocket) → le
      démon remonterait au MVP+1, car il changerait radicalement la fraîcheur perçue.~~
      → **Déclencheur PARTIELLEMENT ARMÉ** le 2026-09-07 (spike UC01 du domaine post-mvp/05) : un broker
      MQTT **existe** côté EU (`eu-smthome-m2m.aux-global.com`), mais l'acceptation de nos identifiants
      n'est pas établie et son certificat TLS est invalide. Voir
      `smartclim-transport-aux-home.md` § 7.
      ⚠️⚠️ **Et la CONSÉQUENCE écrite ici était fondée sur une prémisse FAUSSE** : « MQTT ⇒ démon » ne
      tient pas. `ha-aux-a-plus/mqtt.py` n'utilise **pas** `paho` — il construit ses paquets à la main sur
      un socket brut (`_mqtt_packet()`, `_mqtt_utf8()`) — et il s'en sert de **deux** façons qui n'ont pas
      le même coût pour nous :

      | Usage du canal | Nature | Faisable en **PHP pur** ? | Démon ? |
      |---|---|---|---|
      | `query_temperatures()` / `control_device()` — connexion **ponctuelle** | requête/réponse, tient dans un cycle de cron | **Oui** — CONNECT/PUBLISH/SUBSCRIBE et lecture du CONNACK tiennent en quelques dizaines de lignes ; `openssl_*` et les sockets sont **déjà** utilisés par le plugin | **Non** |
      | souscription **permanente** `dev2app/<uid>/#` — vrai push | processus long | Non (ni PHP-FPM ni le cron n'ont de processus long) | **Oui** |

      D'où **trois marches**, et non une seule :
      1. **Validation d'accès** — un CONNECT authentifié, lecture du code retour. Zéro dépendance, zéro
         démon. C'est le seul geste manquant.
         ⚠️ **INSTRUMENTÉE le 2026-09-12** (UC05 du domaine post-mvp/05,
         `core/php/sonde-mqtt-auxhome.php`) — **pas « jouée »** : la machine de développement n'a ni PHP,
         ni Jeedom, ni compte AUX Home, donc le CONNECT réel n'a **pas** pu être envoyé pendant ce cycle.
         Elle ne sera **jouée** qu'après exécution du protocole de recette par l'utilisateur (§ 10 de la
         spec technique de l'UC05) — voir le résultat au § 7.2 et la décision au § 7.7 de
         `smartclim-transport-aux-home.md`.
      2. **MQTT ponctuel en PHP** dans le cycle de cron existant : si `query_temperatures()` se transpose,
         on gagne potentiellement la **fraîcheur d'ambiante** — le vrai point faible du transport
         (`smartclim-transport-aux-home.md` § 6.4) — **sans démon, sans `packages.json`, sans indicateur
         de dépendances**. ⚠️ Cette marche **n'existait pas** dans l'arbitrage d'origine.
         ⚠️⚠️ **La dérogation TLS de l'UC05 ne vaut pas précédent** — toute UC ultérieure qui ouvrirait
         cette marche doit **revérifier le certificat au moment où elle s'écrit** (protocole de re-mesure :
         `core/php/sonde-mqtt-auxhome.php --certificat`) plutôt que de recopier la dérogation bornée et
         datée de la sonde de validation. Le vecteur de propagation d'une dérogation TLS n'est jamais
         l'autoload, c'est la **recopie humaine** d'une fonction « qui marchait déjà ».
      3. **Démon Python + souscription permanente** : justifié seulement si la marche 2 réussit **et** que
         l'évènement non sollicité (changement depuis la télécommande) est retenu comme besoin. Le démon
         reste de toute façon justifié **indépendamment** par le WebSocket du cloud legacy (UC03 de ce
         domaine) — ce spike ne conditionne donc pas son existence.
- [ ] Confirmation que l'appareil de validation parle **AUXLink TCP 12416** → session persistante +
      heartbeat 4 s ⇒ démon nécessaire pour le pilotage local de CET appareil (fort intérêt utilisateur).
- [ ] Constat de quotas/limitation de débit sur `/app/user_device` imposant un canal évènementiel.

## 7. Ce que l'implémentation du socle a réellement appris (UC02, 2026-09-07)

Le démon **existe** depuis UC02 du domaine post-mvp/05. Cette section consigne ce qui n'était pas
prévisible depuis l'arbitrage ci-dessus, et qui vaut pour **tout** plugin Jeedom à démon — pas seulement
celui-ci. Détail complet et sources dans `.memory/specs/post-mvp/05-temps-reel-et-demon/02-socle-demon-python-tech.md`.

### 7.1 ⚠️⚠️ Sur Debian ≥ 12, les paquets pip d'un plugin vivent dans un venv PAR PLUGIN

`system::getPython3VenvDir()` renvoie `plugins/<id>/resources/python_venv`, et `system::checkAndInstall()`
le crée (`python3 -m venv --upgrade-deps`) **uniquement s'il y a au moins un paquet à installer** ;
`pip list` est ensuite lu **dans ce venv**. Trois conséquences, toutes des pièges silencieux :

1. **Le démon doit être lancé par `system::getCmdPython3('<id>')`, jamais par `python3` en dur** — sinon
   les paquets installés dans le venv sont **invisibles** au processus. ⚠️ La valeur renvoyée **finit par
   une espace** : ne pas en ajouter une seconde en concaténant.
2. **Une section `pip3` vide ne crée aucun venv** alors que `getCmdPython3()` pointerait dedans → démon
   **inlançable, sans aucun message**. Déclarer au moins un paquet réellement utilisé n'est donc pas
   cosmétique. (C'est une des raisons pour lesquelles `requests` est déclaré ici.)
3. `resources/python_venv/` doit entrer dans `.gitignore`, et `resources/` recevoir un `.htaccess` — le
   venv est créé **hors** du sous-dossier du démon.

Debian 10 et 11 n'ont **pas** ce venv : c'est le pip **système**, avec le Python **système**. Cette
asymétrie est ce qui rend le point 7.2 dangereux.

### 7.2 ⚠️⚠️ La version déclarée dans `packages.json` se choisit contre `os.min`, PAS contre la dernière version publiée

Rappel du mécanisme (`system::checkAndInstall`) : la version déclarée est comparée en **plancher**
(`version_compare(installé, déclaré) < 0` ⇒ à réinstaller), et l'installation se fait en `==<version>`,
donc **exactement** cette version.

Le piège : déclarer la **dernière** version publiée d'un paquet maximise la chance que son
`Requires-Python` dépasse le Python de la plus vieille distribution supportée. Cas réel de cette UC —
`requests` 2.34.2 exige Python ≥ 3.10, alors que `info.json` porte `os.min: 10` (Debian 10 = Python 3.7,
Debian 11 = 3.9) :

| version | `Requires-Python` | Debian 10 (3.7) | Debian 11 (3.9) | Debian 12 (3.11) |
|---|---|---|---|---|
| 2.31.0 ✅ | `>=3.7` | ✅ | ✅ | ✅ |
| 2.32.5 | `>=3.9` | ❌ | ✅ | ✅ |
| 2.34.2 | `>=3.10` | ❌ | ❌ | ✅ |

⚠️ **Et le mode de défaillance est le pire possible** : le script d'installation généré par le core
**n'a pas de `set -e`** (il commence par `set -x`). Sur échec de `pip`, il déroule jusqu'à sa dernière
ligne, **supprime son fichier de progression**, l'état repasse `nok` — et `plugin::checkDeamon` relance
l'installation **toutes les 5 minutes, sans borne de nombre**. Donc : déclarer une version trop récente ne
« dégrade » pas les vieilles distributions, elle y **casse le plugin en boucle**.

**Règle** : la version déclarée est la plus **basse** qui satisfait le besoin fonctionnel *et* dont le
`Requires-Python` couvre le Python de `os.min`. Comment vérifier une candidate, sans rien installer :

```bash
python -m pip download <paquet>==<v> -d /tmp/whl --no-deps
unzip -p /tmp/whl/<paquet>-<v>-*.whl '*/METADATA' | grep '^Requires-Python'
```

⚠️ Corollaire à ne pas rater : **relever un plancher plus tard re-déclenche un cycle d'installation sur
tout le parc**. Et une contrainte `os.min` basse peut **enfermer sur une version portant un CVE** — c'est
le cas ici (cf. dette D-05-02-06 de la spec technique d'UC02 : `requests` 2.31.0 / CVE-2024-35195, non
exploitable dans ce motif d'appel, mais infermable tant que `os.min` vaut 10).

### 7.3 ⚠️ Le squelette de démon officiel est CASSÉ à la sortie de la boîte

Vérifié sur `jeedom/plugin-template@master` (identique au commit `ceed01b` de ce dépôt, comparaison octet
à octet — la question « ce dépôt ou l'amont ? » est donc sans enjeu, on restaure depuis `ceed01b`) :

1. **`jeedom_utils.stripped()` tue le démon au premier message reçu**, et il est sur le chemin de
   `read_socket()`. `"".join([i for i in s if i in range(32,127)])` lève un `TypeError` sur des `bytes`
   (`i` est un `int`), et renvoie `''` sur une `str` (`'a' in range(...)` est toujours `False`) → dans les
   deux cas l'exception naît **hors** du `try` interne, traverse `listen()` (qui n'attrape que
   `KeyboardInterrupt`) et atteint le `except Exception` du module → `shutdown()`.
2. **`import serial` / `import pyudev` inconditionnels** en tête de `jeedom/jeedom.py`, alors que le
   `packages.json` du gabarit ne déclare **pas** `pyudev` → `ImportError` au démarrage dans un venv neuf.
   Le gabarit est donc incohérent avec lui-même.
3. `shutdown()` fait `os.remove()`/`close()` **sans garde** → warnings parasites sur un arrêt précoce.
4. ⚠️ **`jeedom_socket_handler.handle()` journalise la charge brute reçue sur le socket** — et si le côté
   PHP y ajoute une `apikey` (ce que fait le patron officiel), **c'est le secret en clair dans le journal**,
   à chaque message. Il fait aussi `readline()` **sans timeout ni taille max** sur un `TCPServer` **non
   threadé** : une connexion locale qui n'envoie jamais de `\n` immobilise tout le pont, **sans que le
   démon meure ni journalise**, PID toujours vivant — donc invisible depuis le panneau « Démon ».

Tout portage de ce squelette doit corriger ces quatre points, et **consigner chaque écart dans un bloc
d'en-tête** du fichier : sans cette trace, une resynchronisation amont future les réintroduit en silence.

### 7.4 Un seul hook de cycle de vie, et deux crons du core à ne pas confondre

`plugin::cron` est en `* * * * *` (`timeout` 2) ; **`plugin::checkDeamon` est en `*/5 * * * *`**
(`timeout` 5) — deux lignes distinctes de la table `cron`, chacune exécutée dans **son propre processus
détaché** par `cron::run()`. Une installation de dépendances ne peut donc **pas** retarder le cron d'un
autre plugin : elle est elle-même détachée (`exec(… &)` ou `at now`). Le partage résiduel est **matériel**
(CPU/disque, verrou apt), pas logiciel.

⚠️ Deux gardes du core à connaître **avant de conclure qu'une recette échoue** : `deamon_start()` refuse
deux lancements à moins de **45 s** d'intervalle, et `checkDeamon` **relance le démon tout seul** dans les
5 minutes — tester un plugin « démon arrêté » exige de basculer « Gestion automatique » à 0.
