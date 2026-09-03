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
> `makleso6/broadlink-aircon-api` (**Apache-2.0**). Depuis le 2026-08-28, s'y ajoute
> `mjg59/python-broadlink` (**MIT**) — `broadlink/device.py`, `broadlink/protocol.py` —, retenue comme
> **source de vérité unique** pour tout ce qui touche la découverte, l'en-tête et l'authentification :
> c'est l'implémentation de référence dont les deux autres dérivent.
>
> **Date** : 2026-08-24, révisé le **2026-08-28** — l'UC01 du domaine `post-mvp/01` a tranché contre
> `python-broadlink` les divergences signalées ⚠️ ci-dessous et fermé 3 des 5 cases du § 10 (cf. § 11).

---

## 1. Paramètres réseau

| Élément | Valeur | Statut |
|---|---|---|
| Port de communication | **UDP 80** | ✅ (les deux références) |
| Ports de découverte (broadcast) | **80 seul** | ✅ **tranché UC01** — `DEFAULT_PORT` de `python-broadlink`. Les ports 15001 et 2415 de `ac_freedom` ne concernent pas les climatiseurs |
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
| `0x28`–`0x29` | compteur de paquet (little-endian, incrémenté par session), **bit 15 forcé à 1** ✅ tranché UC01 |
| `0x2A`–`0x2F` | **MAC** de l'appareil, écrite dans l'**ordre inverse** de la MAC imprimable ✅ tranché UC01 |
| `0x30`–`0x33` | identifiant de session (nul avant auth) |
| `0x34`–`0x35` | somme de contrôle de la charge utile **en clair** |
| `0x38`… | charge utile **chiffrée** |

Deux sommes de contrôle **différentes**, à ne pas confondre ✅ :

- **En-tête** : somme d'octets initialisée à `0xBEAF`, tronquée à 16 bits (`calculateChecksum`).
- **Charge utile HVAC** : complément à un « type Internet » sur mots de 16 bits (`commandPayloadChecksum` /
  `_payload_checksum`).

## 4. Authentification `0x65`

1. Charge utile de `0x50` octets, majoritairement nulle ✅ :
   - octets `0x04`–`0x13` — **16 octets** — remplis d'ASCII `'1'` (`0x31`) ✅ **tranché UC01** : ni `0x0F`
     ni `0x12`, les deux références se trompaient sur la borne haute ;
   - `0x1E` = `0x01`, `0x2D` = `0x01` ;
   - `0x30`… = nom de terminal ASCII **`"Test 1"`** — 6 caractères, **un seul** espace ✅ tranché UC01.
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

> ⚠️⚠️ **LIRE D'ABORD — deux espaces d'offsets, et c'est la source de toutes les fausses divergences.**
> Les offsets de ce tableau sont **absolus dans la charge déchiffrée**, laquelle commence par un
> **préfixe de longueur de 2 octets**. Les offsets de la trame cloud `status.control` (et donc ceux
> codés dans `smartclimFrame`) sont dans la **charge HVAC nue**, qui commence par `bb00…`. Donc :
> **`offset charge HVAC = offset de ce tableau − 2`**. Vérifié exact sur les six concepts en production
> (UC02, 2026-09-02). ⚠️ `smartclimBroadlinkLan::requete()` **retire ce préfixe** : ce qui entre dans le
> décodeur est **toujours** de la charge HVAC nue. Avant de conclure que deux références se
> contredisent, **vérifier dans quel espace chacune compte**.

| Champ | Extraction |
|---|---|
| consigne | `8 + (octet[12] >> 3)`, `+0,5` si `octet[14] & 0x80` ✅ *(la « divergence » 14 chez `ac_freedom` / 12 chez `fparrav` est **résolue** : `12 + 2 = 14`, les deux désignent le même bit — cf. l'encadré ci-dessus)* |
| oscillation verticale | `octet[12] & 0x07` |
| oscillation horizontale | `octet[13] & 0x07` ⚠️ *(`fparrav` lit `octet[12] & 0x07` — cette fois ce n'est PAS un décalage d'espace : `12` y désignerait l'oscillation verticale. Bug de recopie confirmé. **Reste à trancher contre du matériel** au domaine `post-mvp/04`)* |
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

Puis encapsulation : `[longueur uint16 LE][charge utile 23 o.][CRC hi][CRC lo]` dans un tampon de 32
octets — `longueur = 23 + 2 = 25 = 0x19`, donc les deux premiers octets sont `19 00`. CRC = complément à
un 16 bits de la charge utile, écrit en **big-endian** ✅. **Algorithme exact, cas impair et vecteurs de
contrôle : § 13** (établi en UC03).

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
>
> ✅ **Tranché UC01** : l'ordre **inversé** de `ac_freedom` est le bon. `fparrav` est l'exception, et
> pour une raison mécanique — il ne lit pas une réponse de découverte, il relit l'**écho de son propre
> paquet `0x65`**, dans lequel c'est lui qui a écrit la MAC. D'où la distinction portée par la ligne
> normalisée du transport : `mac` (ordre imprimable, comparable au cloud) et `octets_mac` (ordre de
> l'en-tête, à réécrire en `0x2A`–`0x2F`).

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

- UDP **unicast** : `stream_socket_client('udp://<ip>:80')` + `stream_set_timeout()` — **cœur de PHP,
  aucune extension**, donc toujours disponible.
- UDP **diffusion** : `socket_create(AF_INET, SOCK_DGRAM, SOL_UDP)` +
  `socket_set_option(..., SO_BROADCAST, 1)` — exige l'extension `sockets`, qui n'est **pas** garantie
  sur une installation Jeedom. Repli sans extension : `stream_socket_server('udp://0.0.0.0:0', …,
  STREAM_SERVER_BIND, $contexte)` avec `array('socket' => array('so_broadcast' => true))`.
  ⚠️ **Deux pièges dissymétriques, établis en UC01** :
  1. **Ne jamais diffuser depuis un socket UDP *connecté*** (`stream_socket_client('udp://255.255.255.255:80')`).
     Un socket connecté fait filtrer par le noyau tout datagramme dont la source n'est pas l'adresse
     connectée — or les appareils répondent depuis leur adresse **unicast**. Le code « marche » (l'envoi
     réussit) et la découverte ne voit **rien** : panne muette. La diffusion exige donc un socket **non
     connecté** (`socket_sendto()` ou `stream_socket_sendto()`), l'unicast pouvant rester connecté.
  2. L'absence d'extension `sockets` est une **dégradation, pas une panne** : seule la découverte
     automatique tombe. La saisie manuelle IP/MAC reste opérationnelle, puisque l'auth et les requêtes
     n'ont jamais besoin de diffusion. À dire à l'utilisateur **dans l'interface**, pas seulement au log.
- AES-128-CBC zero padding : `openssl_encrypt`/`openssl_decrypt` avec
  `OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING`.
- Manipulation binaire : `pack`/`unpack`, opérateurs de bits.
- ⚠️ **Valider une adresse IP sans jamais passer par `ip2long()`** (piège trouvé en UC01, 2026-08-28).
  `ip2long()` renvoie un entier **signé**, et PHP tourne en **32 bits** sur Raspberry Pi OS armhf —
  une plateforme Jeedom très répandue. Conséquences, toutes silencieuses :
  - `ip2long('224.0.0.0')` vaut **-536870912**, pas 3758096384. Un test « l'adresse est-elle
    multicast ou au-delà ? » écrit `$long >= ip2long('224.0.0.0')` est donc **vrai pour toute adresse
    dont le premier octet est < 128** : `10.0.0.1` (167772161) est rejetée, tandis que `192.168.1.50`
    (-1062731470) passe. Un plan d'adressage en `10.x` cesse de fonctionner, l'autre non.
  - un masque littéral comme `0xFF000000` (4278190080) **dépasse `PHP_INT_MAX` en 32 bits** : PHP en
    fait un **flottant**, et l'opérateur `&` appliqué à un flottant hors plage n'est pas fiable.
  **La règle** : raisonner sur les **octets** (`explode('.', $ip)` après un
  `filter_var(..., FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)` réussi, puis comparer des entiers 0-255).
  C'est exact sur toute architecture, et lisible sans connaître la représentation interne. Ne pas se
  contenter de corriger une comparaison : tant que `ip2long()` reste dans la méthode, le prochain
  ajout de plage réintroduit le défaut.

Le seul gain d'un démon serait de **maintenir la session ouverte** entre deux commandes (~200 ms
économisés par re-auth). Ce n'est pas décisif → cf. `smartclim-daemon-choix.md`.

## 10. À confirmer

- [x] ~~Offset réel du bit de demi-degré en **lecture** (12 ou 14) — divergence entre références.~~ →
      **fausse divergence** : décalage d'espace d'offsets, `12 + 2 = 14`, même bit (UC02, § 5.2).
      Le plugin le lit déjà côté cloud. ⚠️ Reste non **mesuré** sur matériel — mais **aucune branche
      alternative ne doit être codée**.
- [ ] Offset réel de l'oscillation horizontale en **lecture** (12 ou 13) — **vraie** divergence celle-là
      (cf. § 5.2), sans objet tant qu'aucun concept d'oscillation n'existe (domaine `post-mvp/04`).
- [x] ~~Bornes exactes du remplissage ASCII `'1'` de la charge utile d'auth~~ → **`0x04`–`0x13`** (UC01, § 4).
- [x] ~~Utilité réelle des ports de découverte 15001 et 2415~~ → **aucune** pour un climatiseur, port
      **80 seul** (UC01, § 1).
- [ ] Signification exploitable de `devtype` pour identifier le modèle.

## 11. Établi en UC01 du domaine `post-mvp/01` (2026-08-28) ✅

Faits tranchés contre `mjg59/python-broadlink` pendant l'implémentation de la découverte, de l'auth et
de la session. Ils n'étaient dans **aucune** des deux références d'origine.

| Fait | Détail |
|---|---|
| **Compteur de paquet** | `0x28`–`0x29`, little-endian, **bit 15 forcé à 1** (ou binaire avec `0x8000`) |
| **Code d'erreur appareil** | entier **signé** little-endian sur 2 octets en `0x22` de la **réponse**. `0` = pas d'erreur. Ne pas le lire ailleurs, ne pas le supposer non signé |
| **Codes vus et leur sens** | `-7` et `-4012` = **session invalidée** (déclencheurs de la ré-authentification réactive) ; `-1` = échec générique, également traité en perte de session ; les autres sont classés en `TYPE_PROTOCOLE` |
| **Appareil verrouillé** | drapeau en `0x7F` de la réponse de découverte : l'appareil a été « verrouillé » depuis l'application constructeur et **refusera l'authentification**. À remonter comme un statut explicite, pas comme un échec réseau — sinon l'utilisateur cherche un problème de réseau qui n'existe pas |
| **Une seule session par appareil** | authentifier **invalide la session précédente sur l'appareil**. Conséquence à ne pas perdre de vue : deux processus PHP concurrents (handler AJAX + tick de cron) se décrochent mutuellement en boucle si la session n'est pas **partagée et sérialisée**. D'où session en cache Jeedom chiffré + `flock` par MAC, jamais de session « en mémoire du process ». Et l'inverse est vrai : le plugin peut couper la session d'un autre logiciel (Home Assistant, application constructeur) |
| **Empreinte de session** | doit inclure l'**IP** : c'est ce qui fait qu'un changement de bail DHCP invalide la session tout seul, sans cron ni détection dédiée |

⚠️ **Ce transport n'est toujours pas recetté** : l'appareil de validation de l'utilisateur ignore le
protocole Broadlink (cf. en-tête). Tout ce qui précède est **vérifié dans du code lu**, jamais observé
sur du matériel. Le premier contact avec un appareil réellement compatible doit produire en `debug` les
valeurs `0x26`-`0x27`, le `devtype` et tout code d'erreur — instrumentation posée exprès pour ça.

## 12. Établi en UC02 du domaine `post-mvp/01` (2026-09-02) ✅

Lecture d'état en LAN. **Non recetté** : l'appareil de validation de l'utilisateur ignore le protocole
Broadlink — tout ci-dessous est vérifié contre `mjg59/python-broadlink` (MIT) et par recoupement
arithmétique, jamais contre du matériel.

- **Les deux espaces d'offsets** (§ 5.2, encadré) — le résultat le plus réutilisable de cette UC : il
  referme une « divergence » qui aurait autrement conduit à coder deux branches concurrentes.
- **Somme de contrôle de la charge HVAC** — complément à un « type Internet » sur mots de 16 bits
  **BIG-endian**, avec repli des retenues, écrite en big-endian. **Distincte** de la somme du paquet
  `0x38` (`sommeControle()`). Vérifiée arithmétiquement contre les deux magics : `bb00 0680 0000 0200
  1101` → `0x2B7E`, `…2101` → `0x1B7E`. ⚠️ Non nécessaire en lecture (les charges sont constantes) —
  elle le devient à l'**écriture**, UC03.
- **Structure de la charge, requête comme réponse** : `[longueur uint16 LE][charge HVAC][somme 16
  bits][remplissage nul]`, où `longueur = strlen(charge HVAC) + 2`.
- **Un décodeur unique pour les deux transports** : `smartclimFrame`, extraite de `smartclimAuxHomeApi`
  (le second appelant est arrivé). C'est ce qui rend « l'état LAN est identique à l'état cloud » vrai
  **par construction** plutôt que par surveillance.
- **Ce qui n'est PAS vérifié sur `0x6A`, et pourquoi** : `python-broadlink` ne contrôle **ni** l'écho de
  MAC **ni** la somme de charge sur cette commande. SmartClim les **journalise sans bloquer** — un
  contrôle invérifiable sur un chemin non recettable est un déni de service auto-infligé. Restent
  bloquants : longueur de charge non multiple de 16, et champ `longueur` incohérent avec la charge reçue.
- **Le compteur de paquet ne se persiste pas** : `python-broadlink` l'initialise **aléatoirement**, donc
  l'appareil n'en contrôle aucune monotonie — et le persister réarmerait la TTL de session à chaque
  lecture. Il vit **par processus**.
- **Le profil de capacités LAN ne publie ni modes ni vitesses** : le LAN n'a aucun équivalent de
  `feature.coolType`, donc il ne peut **rien exclure**, et l'union des profils réintroduirait un mode
  précédemment exclu sur preuve. Corollaire pour UC03 : le jour où le LAN publiera des modes, l'exclusion
  devra devenir **persistante dans le profil** — une preuve n'expire pas.
- **Offsets déjà identifiés pour le domaine `post-mvp/04`** (à convertir depuis l'espace du § 5.2) :
  oscillations, silence/turbo, veille, santé/nettoyage, afficheur/anti-moisissure. Rien n'est décodé
  aujourd'hui, faute de concept générique correspondant.

## 13. Établi en UC03 du domaine `post-mvp/01` (2026-09-03) ✅

Écriture d'état en LAN. **Non recetté** : l'appareil de validation de l'utilisateur ignore le protocole
Broadlink. Vérifié contre `fparrav/homebridge-aux-cloud` (MIT) et par recoupement arithmétique.

### 13.1 Somme de contrôle de la charge HVAC — algorithme complet

```
somme = Σ, i par pas de 2 : (octet[i] << 8) + (octet[i+1] si présent, sinon 0)
tant que (somme >> 16) : somme = (somme & 0xFFFF) + (somme >> 16)
crc = 0xFFFF ^ somme        écrit BIG-endian [hi][lo]
```

⚠️ **La charge d'écriture fait 23 octets — longueur IMPAIRE**, et ce cas n'est pas une affaire de
convention : la boucle de référence lit `data[i+1]` hors borne, qui vaut 0 après masquage, donc le
**dernier octet est poids fort d'un mot complété par `0x00`**. La convention Internet standard dit la
même chose.

⚠️ **Cette somme est DISTINCTE de celle du paquet `0x38`** (`0xBEAF`, § 3) — deux fonctions, jamais
fusionnées.

**Vecteurs de contrôle** (les deux premiers sont les magics de lecture) :

| Entrée | Longueur | Somme |
|---|---|---|
| `bb00 0680 0000 0200 1101` | 10 (paire) | `0x2B7E` |
| `bb00 0680 0000 0200 2101` | 10 (paire) | `0x1B7E` |
| `bb00 0680 0000 0f00 0101 8000 0fa0 0020 0000 2000 0000 00` | **23 (impaire)** | `0x7EBD` |
| idem, **dernier octet à `0x01`** | 23 (impaire) | `0x7DBD` |

⚠️ **Les deux premiers vecteurs ne suffisent PAS à valider une implémentation** : de longueur paire, ils
n'exercent jamais la branche du dernier octet. Et le troisième seul ne discrimine pas non plus (son
dernier octet vaut `0x00`) — c'est le **quatrième** qui départage : une implémentation plaçant l'octet
impair en **poids faible** rendrait `0x7EBC` au lieu de `0x7DBD`.

### 13.2 En-tête d'écriture contre en-tête de lecture — deux octets d'écart

| | Octets 0-9 |
|---|---|
| lecture | `bb 00 06 80 00 00 `**`02`**` 00 `**`11`**` 01` |
| écriture | `bb 00 06 80 00 00 `**`0f`**` 00 `**`01`**` 01` |

Seuls les octets **6** et **8** diffèrent. ⚠️ Ne pas confondre l'octet 6 de l'en-tête (`0x0f`) avec le
**marqueur `0x0F` de l'octet 12** (§ 5.4) : deux choses distinctes qui portent la même valeur.

### 13.3 Ce que la stratégie d'écriture doit être — et pourquoi la référence a tort

- **Fusionner au niveau des OCTETS, jamais des paramètres décodés.** On recopie la trame que l'appareil
  vient de renvoyer et on ne patche que les bits visés : les champs que le décodeur ne connaît pas
  (oscillations, veille, santé, afficheur, octets 21-22 non documentés) traversent **intacts**. Une
  fusion par paramètres perd tout ce qu'elle ne décode pas.
- **Relire l'appareil avant chaque écriture**, sous le **même verrou** que l'écriture. ⚠️
  `AuxDeviceControl` fait l'inverse — il fusionne un `device.params` en cache alimenté par un polling
  découplé : c'est la cause directe des retours en arrière observés avec cette référence.
- ⚠️ **Corollaire heureux du « état complet » : l'écriture est IDEMPOTENTE.** Réémettre le même ordre est
  sans danger, puisque la trame porte un état absolu. Un rejeu réseau sur l'écriture est donc légitime —
  ne pas le désactiver « par prudence ».
- ⚠️ **La plage de consigne encodable est `[8, 39] °C`** : `(T − 8) << 3` doit tenir sur 5 bits. Plus
  étroite que l'enveloppe personnalisable du plugin (5-35 °C) — divergence de comportement entre
  transports, à traiter au domaine 02.
- **Le bit turbo (octet 14 bit 6) doit être DÉRIVÉ de la vitesse commandée**, pas recopié : `TURBO` est
  modélisée à la fois par un code de vitesse et par ce bit. Le recopier laisserait « vitesse = Faible
  **et** turbo = 1 » après un passage de TURBO à Faible. Le bit `mute` voisin (bit 7), lui, se recopie —
  aucun concept générique ne le porte.

### 13.4 Ce qui n'est toujours pas vérifiable

- **La réponse à une écriture n'est parsée par aucune référence.** La seule confirmation disponible est
  le **code d'erreur `0x22` nul**. Si un appareil accusait `0` sans appliquer, seule la recette le
  montrerait.
- **L'oscillation horizontale reste une VRAIE divergence** (§ 10) : octet 11 bits 7-5 en écriture, bits
  2-0 en lecture selon `ac_freedom`. Contrairement au demi-degré (fausse divergence d'espace, refermée en
  UC02), celle-ci ne se referme pas par l'analyse. En attendant, **l'octet 11 se recopie tel quel** —
  toute transformation reposerait sur le choix arbitraire d'une des deux lectures.
