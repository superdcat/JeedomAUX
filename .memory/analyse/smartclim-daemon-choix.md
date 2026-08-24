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
| `paho-mqtt` | push MQTT ❓ (si confirmé) |

⚠️ **Les numéros de version exacts doivent être relevés sur PyPI au moment d'écrire l'UC** (une version
figée ici serait périmée et le format n'admet pas d'opérateur de comparaison). Vérifier également la
compatibilité `paho-mqtt` 1.x vs 2.x (API cliente incompatible entre les deux majeures).

## 6. Ce qui déclencherait une révision de ce verdict

- [ ] Découverte d'un **push confirmé** sur `eu-smthome-api.aux-global.com` (MQTT ou WebSocket) → le démon
      remonterait au MVP+1, car il changerait radicalement la fraîcheur perçue.
- [ ] Confirmation que l'appareil de validation parle **AUXLink TCP 12416** → session persistante +
      heartbeat 4 s ⇒ démon nécessaire pour le pilotage local de CET appareil (fort intérêt utilisateur).
- [ ] Constat de quotas/limitation de débit sur `/app/user_device` imposant un canal évènementiel.
