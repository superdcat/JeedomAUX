# SmartClim — Transport `BROADLINK_LAN` (UDP port 80, protocole Broadlink AC)

> **Transport post-MVP prioritaire.** Concerne les générations G1/G2 (cf.
> `smartclim-ecosysteme-aux-broadlink.md`). **Ne fonctionne PAS sur l'appareil de validation.**
>
> **Statut** : ✅ = vérifié dans du code lu · ⚠️ = source unique / divergence entre sources · ❓ = à confirmer.
>
> **Sources de vérité** : `fparrav/homebridge-aux-cloud` (**MIT**) —
> `src/api/broadlink/{Protocol,DeviceControl,DeviceDiscovery}.ts`, `src/api/AuxDeviceControl.ts` ; et
> `azadaydinli/ac_freedom` (**SANS LICENCE — lecture seule, aucune copie de code**) —
> `custom_components/ac_freedom/broadlink_ac_api.py`. Origine commune :
> `makleso6/broadlink-aircon-api` (**Apache-2.0**).
>
> **Date** : 2026-08-24.

---

## 1. Paramètres réseau

| Élément | Valeur | Statut |
|---|---|---|
| Port de communication | **UDP 80** | ✅ (les deux références) |
| Ports de découverte (broadcast) | **80**, **15001**, **2415** | ⚠️ `ac_freedom` diffuse sur les trois ; `fparrav` uniquement sur 80 |
| Adresse de broadcast | `255.255.255.255` | ✅ |
| Délai d'écoute de découverte | 3 s (`fparrav`) à 5 s (`ac_freedom`) | ✅ |
| Timeout d'une requête | 3 s (état), 1,5 s (info) | ✅ `AuxDeviceControl.ts::pollLocalState` |
| Sessions concurrentes | **une seule session UDP par appareil** | ⚠️ `fparrav/README.md` — imposer un verrou par équipement |

**VLAN / broadcast bloqué** : si la découverte échoue, l'IP et la MAC saisies **manuellement** suffisent
(aucun broadcast requis pour l'auth ni les commandes) ✅. C'est le mode de secours documenté par les deux
références et une exigence explicite du `.memory/brief.md` § 5.

## 2. Cryptographie

- **AES-128-CBC**, **padding nul** (zero padding, jamais PKCS#7) ✅.
- **Clé par défaut** (avant authentification) et **IV fixe** : constantes de 16 octets, identiques dans les
  deux références (`DEFAULT_KEY` / `DEFAULT_IV`). ✅
  → à reprendre depuis `fparrav/src/api/broadlink/Protocol.ts` (MIT) au moment de l'implémentation.
- ⚠️ **Piège vérifié** : l'octet 3 de l'IV est `0x99` et non `0x09` (commentaire explicite dans
  `Protocol.ts`) — une transcription hâtive casse tout silencieusement.
- En PHP : `openssl_encrypt($data, 'aes-128-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv)`
  après avoir complété soi-même la charge utile à un multiple de 16 octets par des octets nuls.

## 3. Structure de paquet

En-tête de `0x38` octets suivi de la charge utile chiffrée ✅ (`Protocol.ts::buildPacket`) :

| Offset | Contenu |
|---|---|
| `0x00`–`0x07` | magic `5A A5 AA 55 5A A5 AA 55` |
| `0x20`–`0x21` | **somme de contrôle du paquet complet** (calculée en dernier) |
| `0x24`–`0x25` | `0x2A`, `0x27` (type d'appareil) |
| `0x26` | **commande** : `0x65` auth, `0x6A` requête · réponses `0xE9`, `0xEE` |
| `0x28`–`0x29` | compteur de paquet (little-endian, incrémenté par session) |
| `0x2A`–`0x2F` | **MAC** de l'appareil |
| `0x30`–`0x33` | identifiant de session (nul avant auth) |
| `0x34`–`0x35` | somme de contrôle de la charge utile **en clair** |
| `0x38`… | charge utile **chiffrée** |

Deux sommes de contrôle **différentes**, à ne pas confondre ✅ :

- **En-tête** : somme d'octets initialisée à `0xBEAF`, tronquée à 16 bits (`calculateChecksum`).
- **Charge utile HVAC** : complément à un « type Internet » sur mots de 16 bits (`commandPayloadChecksum` /
  `_payload_checksum`).

## 4. Authentification `0x65`

1. Charge utile de `0x50` octets, majoritairement nulle ✅ :
   - octets `0x04`–`0x0F` (ou `0x12`) remplis d'ASCII `'1'` (`0x31`) ⚠️ *les deux références diffèrent
     légèrement sur la borne haute* ;
   - `0x1E` = `0x01`, `0x2D` = `0x01` ;
   - `0x30`… = nom de terminal ASCII (`"Test  1"` / `"Tes  1"` selon la référence ⚠️).
2. Envoi en commande `0x65`, chiffré avec la **clé par défaut**.
3. Réponse déchiffrée avec la **clé par défaut** :
   - `payload[0x00:0x04]` → **identifiant de session** ;
   - `payload[0x04:0x14]` → **clé de session** (16 octets) qui remplace la clé par défaut pour la suite.
   ✅ (`Protocol.ts::parseAuthResponse`, `broadlink_ac_api.py::_authenticate`)
4. ⚠️ **Attendre ~200 ms après l'auth** avant la première commande : l'appareil a besoin de ce délai
   (`AuxDeviceControl.ts::LAN_AUTH_DELAY_MS = 200`).
5. En cas de perte de session (timeout) : **réinitialiser clé par défaut + identifiant nul + compteur à 0**
   puis ré-authentifier ✅ (`broadlink_ac_api.py::_reauthenticate`). Jusqu'à **2 tentatives**
   (`LAN_RECONNECT_RETRY`).

## 5. Lecture et écriture de l'état

### 5.1 Requêtes magiques (commande `0x6A`) ✅

| Usage | Charge utile (hex) | Réponse |
|---|---|---|
| `getState` | `0C00 BB00 0680 0000 0200 1101 2B7E 0000` | **32 octets** |
| `getInfo` (température ambiante) | `0C00 BB00 0680 0000 0200 2101 1B7E 0000` | **48 octets** |

> Ces mêmes magics se retrouvent côté cloud AUX Home / AUX A+ (cf.
> `smartclim-ecosysteme-aux-broadlink.md` § 3) : le décodeur est **mutualisable**.

### 5.2 Décodage de la réponse d'état (32 octets) ✅

| Champ | Extraction |
|---|---|
| consigne | `8 + (octet[12] >> 3)`, `+0,5` si `octet[14] & 0x80` ⚠️ *(l'offset du demi-degré diffère entre références : 14 chez `ac_freedom`, 12 chez `fparrav` en émission)* |
| oscillation verticale | `octet[12] & 0x07` |
| oscillation horizontale | `octet[13] & 0x07` ⚠️ *(`fparrav` lit `octet[12] & 0x07`, très probablement un bug de recopie)* |
| vitesse (fil) | `(octet[15] >> 5) & 0x07` |
| silence (`mute`) | `(octet[16] >> 7) & 1` |
| turbo | `(octet[16] >> 6) & 1` |
| mode | `(octet[17] >> 5) & 0x0F` |
| sommeil | `(octet[17] >> 2) & 1` |
| marche/arrêt | `(octet[20] >> 5) & 1` |
| santé/ioniseur | `(octet[20] >> 1) & 1` |
| auto-nettoyage | `(octet[20] >> 2) & 1` |
| afficheur | `(octet[22] >> 4) & 1` |
| anti-moisissure | `(octet[22] >> 3) & 1` |

### 5.3 Décodage de la réponse d'info (48 octets) ✅

```text
partie entière   = octet[17] & 0x1F   (+ 32 si octet[17] > 63)
partie décimale  = octet[33] / 10
ambiante         = entière + décimale
```

### 5.4 Écriture de l'état ✅ (`Protocol.ts::buildCommandPayload`)

Charge utile de **23 octets** `BB 00 06 80 00 00 0F 00 01 01 …` puis champs empaquetés :

| Octet | Contenu |
|---|---|
| 10 | `(consigne - 8) << 3` &#124; oscillation verticale (3 bits) |
| 11 | oscillation horizontale `<< 5` |
| 12 | **`0x0F` obligatoire** &#124; demi-degré `<< 7` |
| 13 | vitesse `<< 5` |
| 14 | turbo `<< 6` &#124; silence `<< 7` |
| 15 | mode `<< 5` &#124; sommeil `<< 2` |
| 18 | marche `<< 5` &#124; nettoyage `<< 2` &#124; santé `<< 1` |
| 20 | afficheur `<< 4` &#124; anti-moisissure `<< 3` |

> ⚠️ **Piège vérifié et coûteux** : sans le marqueur `0x0F` sur l'octet 12, *« the device silently discards
> the command »* (commentaire de `Protocol.ts`).

Puis encapsulation : `[longueur+2][0x00][charge utile 23 o.][CRC hi][CRC lo]` dans un tampon de 32 octets,
CRC = complément à un 16 bits de la charge utile ✅.

> ⚠️ **Piège vérifié n°2** : dans `fparrav`, `DeviceControl.ts::sendCommand` **double** l'encapsulation
> (elle est déjà faite par `buildCommandPayload`) et y écrit `0xBEAF` en guise de CRC — code manifestement
> mort/erroné. **Ne pas s'en inspirer** : le chemin correct est celui de
> `AuxDeviceControl.ts::sendLocalCommand`.

### 5.5 ⚠️ L'écriture est un état COMPLET, pas un delta

La trame de commande contient **tous** les champs. Envoyer `{mode: cool}` sans les autres champs remet les
absents à `0` — donc **éteint le climatiseur**. Correction appliquée par la référence : fusionner l'état
courant connu avec les paramètres modifiés avant de construire la trame ✅
(`AuxDeviceControl.ts::sendCommand`, commentaire « *buildCommandPayload defaults missing fields to 0
(pwr=0), which would turn the device off* »).

→ **Conséquence Jeedom** : ce transport exige un **cache d'état courant par équipement** valide avant toute
commande. Si l'état n'a jamais été lu, il faut lire avant d'écrire.

## 6. Découverte broadcast

Deux approches vérifiées, à combiner :

- **`fparrav`** : diffuse un **paquet d'auth** (`0x65`) et retient toute réponse dont l'octet `0x26` vaut
  `0xE9` ou `0xEE` ; MAC lue en `0x2A`–`0x2F`, **ordre direct**. ✅
- **`ac_freedom`** : diffuse un **paquet de découverte** de `0x30` octets (fuseau horaire, date/heure, IP
  et port locaux, commande `0x06` en `0x26`, somme en `0x20`–`0x21`) sur les 3 ports ; MAC lue en
  `0x3A`–`0x40` **en ordre inversé**, nom en `0x40`…, type d'appareil en `0x34`. ✅

> ⚠️ **Piège de rapprochement** : les deux références lisent la MAC à des **offsets différents** et dans des
> **ordres d'octets opposés**. La normalisation de MAC de SmartClim doit donc : minuscules, sans
> séparateur, **et tester les deux ordres** lors du rapprochement avec la MAC fournie par le cloud
> (cf. `smartclim-architecture-jeedom.md` § 4).

## 7. Stratégie de repli LAN → cloud (référence `fparrav`) ✅

- Compteur d'échecs consécutifs **par appareil** ; seuil `LAN_FAILURE_THRESHOLD = 3`.
- Sous le seuil : la commande **échoue** (pas de bascule) → on privilégie la cohérence.
- Au seuil : bascule cloud, puis remise à zéro du compteur au premier succès.
- Un appareil marqué « local uniquement » (sans identifiant cloud) **ne bascule jamais**.
- Côté cloud : jusqu'à 3 tentatives avec **backoff exponentiel** `500 ms · 2^n`, plafonné à 3 s.

Ce comportement est directement transposable au mode `AUTO` du plugin (le `.memory/brief.md` § 4 évoquait aussi
un mode HYBRID, abandonné le 2026-08-24 : `AUTO` l'absorbe).

## 8. Limites connues

- Certains firmwares récents **ignorent totalement** le protocole (cf.
  `smartclim-ecosysteme-aux-broadlink.md` § 1). Une MAC Broadlink ne prouve rien.
- Une seule session UDP par appareil : conflit possible avec un autre logiciel (Home Assistant, Homebridge)
  qui parlerait au même climatiseur.
- Pas de notion d'« en ligne » : seul le timeout fait foi.
- Changement d'IP DHCP → invalide l'IP mémorisée. Mitigation : ré-exécuter une découverte broadcast avant
  de déclarer l'appareil injoignable, et recommander une réservation DHCP dans la documentation.
- Aucune information de marque/modèle exploitable : seul `devtype` (2 octets) est lisible ❓.

## 9. Faisabilité en PHP pur (sans démon)

Tout ce qui précède est **réalisable en PHP** :

- UDP : `stream_socket_client('udp://<ip>:80')` + `stream_set_timeout()` ; broadcast via
  `socket_create(AF_INET, SOCK_DGRAM, SOL_UDP)` + `socket_set_option(..., SO_BROADCAST, 1)`.
- AES-128-CBC zero padding : `openssl_encrypt`/`openssl_decrypt` avec
  `OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING`.
- Manipulation binaire : `pack`/`unpack`, opérateurs de bits.

Le seul gain d'un démon serait de **maintenir la session ouverte** entre deux commandes (~200 ms
économisés par re-auth). Ce n'est pas décisif → cf. `smartclim-daemon-choix.md`.

## 10. À confirmer

- [ ] Offset réel du bit de demi-degré en **lecture** (12 ou 14) — divergence entre références.
- [ ] Offset réel de l'oscillation horizontale en **lecture** (12 ou 13).
- [ ] Bornes exactes du remplissage ASCII `'1'` de la charge utile d'auth (0x0F ou 0x12).
- [ ] Utilité réelle des ports de découverte 15001 et 2415 pour les climatiseurs (vs autres appareils
      Broadlink).
- [ ] Signification exploitable de `devtype` pour identifier le modèle.
