# Spec technique — UC01 post-MVP/01 « Découverte broadcast, authentification et session locale »

> **Spec fonctionnelle** : `01-decouverte-lan-et-session.md` (AC1..AC7) · **Domaine** :
> `post-mvp/01-transport-broadlink-lan` · **Dépend de** : UC03 du MVP (découverte et création des
> équipements)
> **Décisions automatiques de ce cycle** :
> `.memory/auto-dev/run-20260827-1008/post-mvp-01-transport-broadlink-lan-01/decisions.md`
> (`D-POSTMVP0101-01` à `D-POSTMVP0101-10`)
> **Date** : 2026-08-27

## 0. Périmètre — ce que cette UC fait et ne fait PAS

**Fait** : une brique de transport LAN qui (a) découvre par diffusion UDP les appareils parlant le
protocole Broadlink, (b) sonde en unicast une adresse connue ou saisie à la main, (c) ouvre et mémorise
une **session authentifiée par appareil**, sérialisée.

**Ne fait pas**, et ce n'est pas un oubli :

| Hors périmètre | Où |
|---|---|
| Décoder la trame HVAC / lire un état | UC02 de ce domaine |
| Envoyer un ordre | UC03 de ce domaine |
| Rapprocher un appareil LAN d'un équipement Jeedom, fusionner les doublons LAN/cloud | **UC04** de ce domaine |
| Choisir quel transport utiliser, repli automatique | domaine `post-mvp/02-strategies-de-transport` |
| Extraire `smartclimFrame` | UC02 (son AC3 « état identique par les deux voies » **est** la condition « second appelant » de `CLAUDE.md`) |
| Sonder le LAN depuis le cron | volontairement **rien** — cf. § 9 |

⚠️ **Aucun `eqLogic` n'est créé, modifié ou supprimé par la voie LAN. Aucune commande Jeedom n'est
créée. Aucune clé de configuration d'équipement n'est écrite par le scan.** C'est ce qui rend AC7
(idempotence du re-scan) vrai par construction, et ce qui borne le risque de
`D-POSTMVP0101-01` (code non recettable).

## 1. Contrats externes

**Source de vérité unique** : `mjg59/python-broadlink`, branche `master` — `broadlink/device.py`
(`scan()`, `auth()`, `send_packet()`), `broadlink/protocol.py` (`Datetime.pack`),
`broadlink/exceptions.py` (`check_error`, `BROADLINK_EXCEPTIONS`), `broadlink/const.py`.
**Licence MIT** — « Copyright (c) 2014 Mike Ryan / Copyright (c) 2016 Matthew Garrett ». Retenue par
`D-POSTMVP0101-02` contre les deux sources qui divergeaient (`fparrav/homebridge-aux-cloud` MIT, et
`azadaydinli/ac_freedom` **sans licence — lecture seule, aucune copie**).

⚠️ **L'attribution MIT doit être citée en commentaire en tête de
`core/class/smartclimBroadlinkLan.class.php`**, comme le plugin le fait déjà pour la crypto AUX Home.

`const.py` : `DEFAULT_PORT = 80`, `DEFAULT_BCAST_ADDR = "255.255.255.255"`.

### 1.1 Paquet « hello » de découverte — `0x30` octets, UDP vers `<cible>:80`

Tout à zéro **sauf** — ⚠️ **aucun magic `5aa5aa55` dans ce paquet**, ne pas en ajouter « par
cohérence » avec l'en-tête de requête :

| Offset | Contenu |
|---|---|
| `0x08`–`0x13` | `Datetime.pack()`, cf. ci-dessous |
| `0x18`–`0x1B` | IP locale, `inet_aton` **inversé** — **zéros acceptés** |
| `0x1C`–`0x1D` | port local, uint16 LE — **zéro accepté** |
| `0x26` | `0x06` |
| `0x20`–`0x21` | somme de contrôle, uint16 LE, calculée **en dernier** |

`Datetime.pack()`, relatif au début de la zone (`0x08`) :

| Offset relatif | Contenu |
|---|---|
| `0x00`–`0x03` | décalage UTC en **heures**, int32 **signé** LE |
| `0x04`–`0x05` | année, uint16 LE |
| `0x06` | minute |
| `0x07` | heure |
| `0x08` | année sur **2 chiffres décimaux** (année % 100) |
| `0x09` | jour **ISO** de la semaine, 1 (lundi) à 7 (dimanche) |
| `0x0A` | jour du mois |
| `0x0B` | mois |

⚠️ **Fait décisif, et il simplifie beaucoup l'implémentation** : la voie par défaut de `scan()` envoie
`local_ip_address = "0.0.0.0"` et `port = 0`, et **fonctionne** — l'appareil répond à l'adresse
**source UDP** du datagramme. Le plugin n'a donc **jamais** besoin de connaître son IP locale : aucune
énumération d'interface, aucune dépendance, aucune ruse de `connect()` vers une adresse publique.
On laisse donc `0x18`-`0x1D` à zéro.

### 1.2 Réponse de découverte

| Champ | Extraction |
|---|---|
| `devtype` | `octet[0x34] | (octet[0x35] << 8)` |
| octets de MAC | `octets[0x3A..0x3F]` — **tels quels** |
| nom | `octets[0x40..]` jusqu'au premier octet nul |
| verrouillé | `(bool) octet[0x7F]` — lu **seulement** si la réponse fait ≥ `0x80` octets |

⚠️ **Ordre des octets de la MAC — le point qui fait perdre le plus de temps si on le rate.**
`scan()` fait `mac = resp[0x3A:0x40][::-1]` et `send_packet()` fait
`packet[0x2A:0x30] = self.mac[::-1]`. Donc :

- les 6 octets à écrire en `0x2A`-`0x2F` d'une **requête** sont **exactement** `resp[0x3A..0x3F]`,
  **inchangés** → c'est le champ `octets_mac` de la ligne normalisée ;
- la MAC **imprimable / normalisée** (celle qui se compare à la MAC du cloud et au `logicalId`
  `mac:<…>`) en est l'**inverse octet à octet** → c'est le champ `mac`.

**Conséquence de conception** : puisqu'on fait **toujours** un hello avant d'authentifier — y compris
sur une IP saisie à la main — la question de l'ordre des octets **ne se pose jamais** à
l'authentification. Pas d'heuristique, pas de rejeu « avec l'autre ordre ».

### 1.3 En-tête d'une requête — `0x38` octets

| Offset | Contenu |
|---|---|
| `0x00`–`0x07` | magic `5A A5 AA 55 5A A5 AA 55` |
| `0x20`–`0x21` | somme de contrôle du **paquet complet**, uint16 LE, calculée **en dernier** |
| `0x24`–`0x25` | `devtype`, uint16 LE (issu de la découverte ; repli `DEVTYPE_REPLI = 0x272A`) |
| `0x26`–`0x27` | type de paquet, uint16 LE : `0x65` auth, `0x6A` requête |
| `0x28`–`0x29` | compteur, uint16 LE |
| `0x2A`–`0x2F` | `octets_mac` (cf. § 1.2) |
| `0x30`–`0x33` | identifiant de session, uint32 LE — **nul avant authentification** |
| `0x34`–`0x35` | somme de contrôle de la charge utile **en clair, AVANT remplissage**, uint16 LE |
| `0x38`… | charge utile **chiffrée** |

⚠️ **Compteur** : `compteur = ((compteur + 1) | 0x8000) & 0xFFFF` — le **bit 15 est forcé à 1**. Ce
détail est **absent des deux sources internes** et vient de `send_packet()`.

**Sommes de contrôle** — `sum(octets, initial = 0xBEAF) & 0xFFFF` pour **les deux** (charge utile et
paquet complet). Une seule fonction, `sommeControle()`.

**Vérification d'une réponse** :
`attendu = (sum(reponse, 0xBEAF) - reponse[0x20] - reponse[0x21]) & 0xFFFF`, comparé à
`reponse[0x20] | (reponse[0x21] << 8)`.

### 1.4 Cryptographie

- `INIT_KEY = 097628343fe99e23765c1513accf8b02`
- `INIT_VECT = 562e17996d093d28ddb3ba695a2e6f58`
- **AES-128-CBC**, **remplissage nul** (`padding = (16 - len % 16) % 16`), **jamais PKCS#7**.
- PHP : `openssl_encrypt($donnees, 'aes-128-cbc', $cle, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv)`
  après avoir complété soi-même la charge utile à un multiple de 16 par des octets nuls.

⚠️ L'octet d'index **3** de l'IV vaut `0x99`, **pas** `0x09` — l'avertissement de l'analyse interne
§ 2 est **confirmé** par la source canonique. Une transcription hâtive casse tout silencieusement.

### 1.5 Authentification `0x65` — charge utile `0x50` octets

Tout à zéro **sauf** (verbatim `auth()`) :

| Offset | Contenu |
|---|---|
| `0x04`–`0x13` | `0x31` (ASCII `'1'`) × **16 octets** |
| `0x1E` | `0x01` |
| `0x2D` | `0x01` |
| `0x30`–`0x35` | `"Test 1"` en ASCII — **6 caractères, UN seul espace** |

Chiffrée avec la **clé par défaut** (`INIT_KEY`). Réponse : contrôler le code d'erreur (§ 1.6), puis
`charge = dechiffrer(reponse[0x38..])` avec la **clé par défaut** :

- **identifiant de session** = `charge[0x00..0x03]`, uint32 LE ;
- **clé de session** = `charge[0x04..0x13]`, **16 octets**, qui remplace `INIT_KEY` pour la suite.

⚠️ Si la charge fait moins de `0x14` octets, ou si la clé extraite ne fait pas exactement 16 octets →
`TYPE_PROTOCOLE`. Ne **jamais** compléter une clé courte.

⚠️ `DELAI_APRES_AUTH = 0.2` s (200 ms) est une constante **définie en UC01 mais non appliquée ici** :
UC01 n'émet aucune requête après le `0x65`. Elle est posée pour qu'UC02 n'invente pas une autre valeur.

### 1.6 Codes d'erreur appareil

Lus sur **2 octets** en `0x22`-`0x23`, entier **signé** 16 bits **LE**. `0` = pas d'erreur.

| Code | Sens | Type `smartclimException` |
|---|---|---|
| `-1` | Authentication failed | `TYPE_AUTH` |
| `-2` | You have been logged out | `TYPE_AUTH` |
| `-3` | The device is offline | `TYPE_RESEAU` |
| `-4` | Command not supported | `TYPE_PROTOCOLE` |
| **`-7`** | **Control key is expired** | `TYPE_AUTH` |
| `-2040`, `-4007`…`-4011` | données invalides | `TYPE_PROTOCOLE` |
| `-4000` | Network timeout | `TYPE_RESEAU` |
| **`-4012`** | **Device control ID error** | `TYPE_AUTH` |

⚠️ **`-7` et `-4012` sont le signal d'expiration de session** — c'est le déclencheur
**non-devinatoire** d'AC5, absent de l'analyse interne qui ne connaissait que le délai dépassé.

### 1.7 Écarts avec `.memory/analyse/smartclim-transport-broadlink-lan.md` — à répercuter

Ces points ferment des cases « à confirmer » de l'analyse (§ 10) ; **mettre l'analyse à jour** en fin
de cycle.

| § de l'analyse | Ce qu'elle dit | Ce qui est établi |
|---|---|---|
| § 4 | remplissage ASCII `'1'` jusqu'à `0x0F` **ou** `0x12` | **ni l'un ni l'autre** : `0x04`–`0x13`, 16 octets |
| § 4 | nom de terminal `"Test  1"` / `"Tes  1"` | **`"Test 1"`**, 6 caractères, un espace |
| § 1, § 10 | ports de diffusion `80`, `15001`, `2415` | **port 80 seul** (`DEFAULT_PORT`) |
| § 3 | « `0x2A`–`0x2F` = MAC de l'appareil » (sans ordre) | **ordre inverse** de la MAC imprimable |
| § 6 | MAC en `0x3A`-`0x40` **inversée** (`ac_freedom`) | **confirmé** ; `fparrav` est l'exception (il relit l'écho de son propre `0x65`) |
| — | *(nouveau)* | compteur avec **bit 15 forcé** ; `verrouille` en `0x7F` ; code d'erreur en `0x22` ; codes `-7`/`-4012` |

## 2. Architecture — fichiers

| Fichier | État | Indentation / fins de ligne |
|---|---|---|
| `core/class/smartclimBroadlinkLan.class.php` | **créé** | **2 espaces**, **CRLF** |
| `core/php/smartclim.inc.php` | modifié | 2 espaces, CRLF |
| `core/class/smartclim.class.php` | modifié | 2 espaces, CRLF |
| `core/class/smartclimCapabilities.class.php` | modifié | 2 espaces, CRLF |
| `core/ajax/smartclim.ajax.php` | modifié (**1 ligne**) | ⚠️ **4 espaces** (exception héritée), CRLF |
| `desktop/php/smartclim.php` | modifié | ⚠️ **TABULATIONS**, CRLF |
| `desktop/js/smartclim.js` | modifié | 2 espaces, CRLF |

**Non touchés, volontairement** : `core/config/smartclim.config.ini` (aucune clé de config **plugin**,
donc aucun piège `preConfig_` / défaut INI), `plugin_info/packages.json` et `info.json`
(cf. § 8 Dépendances), `plugin_info/configuration.txt` / `.php` (l'IP/MAC est **par équipement**, pas
globale — donc le formulaire de config plugin n'est **pas** concerné, et son miroir n'a pas à être
resynchronisé), `core/i18n/*.json` (traduction en fin de cycle par le sous-agent `translator`).

### 2.1 ⚠️ Autoload — la ligne sans laquelle rien ne fonctionne au runtime

Ajouter dans `core/php/smartclim.inc.php`, **entre** la ligne `smartclimAuxHomeApi` et la ligne
`smartclimDiagnostic` (ordre = dépendances croissantes) :

```php
require_once __DIR__ . '/../class/smartclimBroadlinkLan.class.php';
```

Ni `php -l`, ni la CI, ni `verif-plugin.py` ne détectent l'oubli : la panne est un
« Class not found » au **runtime**, uniquement sur le chemin LAN.

## 3. Server vs Client

**Tout côté serveur.** Le navigateur n'envoie **aucun** paramètre au scan (comme UC03 : `data: { action:
'scannerClimatiseurs' }` et rien d'autre) et ne reçoit que des **libellés déjà traduits** et des
statuts. Aucune adresse de diffusion, aucun port, aucun offset, aucun chemin réseau ne transite par le
client — même garde que la sonde de diagnostic, dont le catalogue de routes est une donnée serveur.

Le client porte uniquement : le rendu des tableaux, le vidage de leur `tbody`, l'affichage de l'état de
connexion, et une **aide** à la saisie IP/MAC (jamais autoritaire — cf. § 4).

## 4. Validation

### 4.1 Entrées utilisateur (formulaire d'équipement) — double barrière

| Barrière | Où | Comportement |
|---|---|---|
| **Aide** à la saisie | `desktop/js/smartclim.js` → `saveEqLogic()` | normalise, **ne lève jamais**, `return _eqLogic` **obligatoire**, un seul message : « Adresses réseau local corrigées : vérifiez les valeurs saisies » |
| **Autoritaire** | `smartclim::preSave()` | normalise `lan_ip` via `normaliserIpV4()` et `lan_mac` via `normaliserMac()` (existante). **Silencieux — ne lève JAMAIS** : `preSave()` est aussi traversé par le `save()` d'autres chemins. Une correction se journalise en `warning` |

Valeur invalide ⇒ **chaîne vide** (= « non personnalisé »), **jamais** un refus d'enregistrement.
Même discipline que les bornes de température (`normaliserBorneTemperature()`).

`normaliserIpV4($_valeur)` : `filter_var(FILTER_VALIDATE_IP | FILTER_FLAG_IPV4)`, **puis refus des
adresses publiquement routables** — si le même `filter_var` avec
`FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` renvoie une valeur, c'est une adresse publique
⇒ rejet. Motif : le plugin envoie de l'UDP vers cette adresse ; la restreindre aux plages privées et
réservées empêche qu'une faute de frappe (ou une saisie malveillante sur une surface admin) ne
transforme le plugin en émetteur vers Internet. Exclure **explicitement** `100.64.0.0/10` (CGNAT),
`0.0.0.0/8` et `224.0.0.0` et au-delà (multicast, réservé haut) plutôt que de dépendre du
comportement de `filter_var` selon la version de PHP.

⚠️ **Ces exclusions se font sur les OCTETS de l'adresse, jamais avec `ip2long()`** (corrigé en passe
de finition, `D-POSTMVP0101-13`) : `ip2long()` renvoie un entier **signé** et PHP est **32 bits** sur
Raspberry Pi OS armhf, plateforme Jeedom courante. `ip2long('224.0.0.0')` y vaut **-536870912**, donc
`$long >= ip2long('224.0.0.0')` est vrai pour toute adresse de premier octet < 128 : `10.0.0.1` était
rejetée à tort — et un plan d'adressage en `10.x` est exactement le cas d'usage d'AC3. Un masque
littéral `0xFF000000` pose le même problème : il dépasse `PHP_INT_MAX` en 32 bits et devient un
**flottant**. La méthode compare donc des entiers 0-255 issus d'`explode('.', $ip)`, après un
`filter_var` réussi. Détail : `.memory/analyse/smartclim-transport-broadlink-lan.md` § 9.

### 4.2 Entrées réseau — une réponse de diffusion n'est ni authentifiée ni fiable

⚠️ **N'importe quelle machine du LAN peut répondre à la diffusion.** Donc :

- longueur ≥ `0x40` exigée, sinon réponse ignorée (`debug`) ;
- `verrouille` lu **seulement** si longueur ≥ `0x80` ;
- `nom` passé par `nettoyerNomExterne()` et borné à **63** caractères ;
- MAC exigée sur 12 caractères hexadécimaux après normalisation ;
- IP source validée par `normaliserIpV4()` ;
- plafond `MAX_APPAREILS = 32` par découverte ;
- **aucun `logicalId` n'est dérivé d'une donnée LAN en UC01** (c'est UC04) ;
- somme de contrôle d'une réponse de **découverte** : calculée, journalisée en `debug` si elle diverge,
  **sans rejeter l'appareil** — la source canonique ne la vérifie pas sur ce paquet, et rejeter sur une
  hypothèse coûterait potentiellement tous les appareils. En revanche sur une réponse
  d'**authentification**, somme **et** écho de MAC sont **bloquants** (la source les vérifie).

`nettoyerNomExterne($_valeur, $_max = 63)` : frontière d'assainissement du transport — retirer les
octets de contrôle, replier sur une chaîne imprimable si le contenu n'est pas de l'UTF-8 valide, couper
proprement, borner. **Volontairement dupliqué** de `smartclimAuxHomeApi::nettoyerTexteExterne()`
(qui est `private`) : un transport ne doit pas dépendre d'un autre transport, et on ne refactore pas du
code livré et recetté. ⚠️ Mettre un **commentaire croisé** dans les deux fichiers pour éviter une
dérive silencieuse si l'un des deux algorithmes évolue.

### 4.3 Classement des exceptions

`smartclimException` levée par le transport porte un message **technique** (jamais affiché tel quel).

| Cas | Type |
|---|---|
| silence dans le budget, envoi/réception en échec, codes `-3` / `-4000` | `TYPE_RESEAU` |
| codes `-1`, `-2`, `-7`, `-4012` | `TYPE_AUTH` |
| réponse trop courte, somme invalide, MAC réémise différente, clé de session ≠ 16 octets, déchiffrement illisible, codes `-4` / `-2040` / `-4007`…`-4011` | `TYPE_PROTOCOLE` |
| `openssl_*` en échec, socket impossible sur l'hôte, verrou impossible, aucune adresse pour l'appareil | `TYPE_INTERNE` |

**Aucun message d'erreur LAN curaté n'est introduit en UC01.** `messageErreurLan()` est **réservée à
UC03** (premier chemin réellement interactif). L'utilisateur ne voit que des **statuts**
(`libelleStatutLan()`) et le résumé du scan.

### 4.4 Niveaux de log — AC4 est un critère SUR LES LOGS, pas seulement sur l'UI

| Niveau | Cas |
|---|---|
| `debug` | non-réponse, appareil non sondé faute de budget, session réutilisée, code d'appareil reçu, valeurs `0x26`-`0x27` et `devtype` observées (**instrumentation de recette**, cf. `D-POSTMVP0101-01`) |
| `warning` | diffusion impossible sur l'hôte, réponse malformée, MAC saisie ≠ MAC de l'appareil, correction de saisie |
| `error` | ⚠️ **réservé aux `Throwable` internes** — **jamais** une non-réponse d'appareil |

🚫 **L'identifiant et la clé de session ne sont JAMAIS journalisés** (au plus la longueur de la clé),
jamais renvoyés par une méthode publique, jamais mis dans le DOM ou une réponse AJAX. IP, MAC,
`devtype` et nom assaini sont journalisables.

## 5. Server Actions / API

### 5.1 `smartclimBroadlinkLan` — aucune E/S base, aucun `eqLogic`, aucun `config::`

```php
const PORT = 80;
const ADRESSE_DIFFUSION = '255.255.255.255';
const DEVTYPE_REPLI = 0x272A;
const FENETRE_DECOUVERTE = 4;      // secondes d'écoute de la diffusion
const INTERVALLE_RENVOI = 2;       // second envoi du hello
const TIMEOUT_ECHANGE = 2;         // secondes, un aller-retour unicast
const MAX_APPAREILS = 32;         // borne le RÉSULTAT de la découverte
const MAX_REPONSES_BRUTES = 128;  // borne la MÉMOIRE pendant la collecte (review tour 1)
const ATTENTE_VERROU = 2;          // borné par le budget restant (D-POSTMVP0101-04)
const DELAI_APRES_AUTH = 0.2;      // contrat pour UC02, NON appliqué ici
const CLE_CACHE_SESSION = 'smartclim::session_lan::';
const DUREE_SESSION = 1800;
const STATUT_ETABLIE = 'etablie';
const STATUT_REUTILISEE = 'reutilisee';
const STATUT_REFUSEE = 'refusee';
const STATUT_INJOIGNABLE = 'injoignable';
const STATUT_VERROUILLE = 'verrouille';
const STATUT_OCCUPE = 'occupe';
const STATUT_MAC_DIVERGENTE = 'mac_divergente';   // D-POSTMVP0101-05
private static $dossierVerrous = null;            // mémoïsé (D-POSTMVP0101-04)
```

| Membre | Contrat |
|---|---|
| `public static diffusionDisponible()` | `true` si `function_exists('socket_create')` **ou** si le repli flux est envisageable. Sert au message de dégradation (§ 8) |
| `public static decouvrir($_budget = self::FENETRE_DECOUVERTE)` | Diffuse le hello sur `255.255.255.255:80`, envois à `t=0` et `t≈INTERVALLE_RENVOI`, écoute jusqu'au budget. Dédoublonne **par MAC**, plafonne à `MAX_APPAREILS`. → `array<int, ligne normalisée>`. ⚠️ **Un tableau vide est un SUCCÈS**, pas une erreur. Lève `smartclimException(TYPE_INTERNE)` **uniquement** si aucun chemin de diffusion n'est disponible sur l'hôte |
| `public static interroger($_ip, $_budget = self::TIMEOUT_ECHANGE)` | Hello **unicast** vers `$_ip:80` via `stream_socket_client`, 2 envois max. → ligne normalisée, ou **`null`** (silence / réponse inexploitable) + log `debug`. Voie d'AC3 **et** de la sonde par IP connue d'AC4 |
| `public static ouvrirSession(array $_appareil, $_budget)` | **Point d'entrée unique de la session.** (1) prend le verrou `flock` par MAC — sinon `STATUT_OCCUPE` ; (2) si session en cache **valide** (empreinte identique) → `STATUT_REUTILISEE`, **zéro paquet réseau** (c'est AC7) ; (3) sinon `authentifier()` → `STATUT_ETABLIE` / `REFUSEE` / `VERROUILLE` / `INJOIGNABLE`. ⚠️ **Ne lève JAMAIS** (c'est AC4) : tout échec devient un statut + log `debug`/`warning`. **Ne renvoie jamais l'identifiant ni la clé de session.** `finally` → `libererVerrou()` |
| `public static purgerSession($_macNorm)` | `cache::delete(CLE_CACHE_SESSION . $macNorm)`. Réservé au rejeu réactif d'UC02 et à un usage explicite |
| `private static authentifier(array $_appareil, $_budget)` | Charge `0x50` (§ 1.5), envoi `0x65` chiffré avec `INIT_KEY`, validation, extraction identifiant + clé, écriture du cache **chiffré**. Lève `smartclimException` typée, rattrapée par `ouvrirSession()` |
| `private static echanger($_ip, $_paquet, $_timeout)` | Socket UDP **connecté** (`stream_socket_client('udp://<ip>:80')`), 2 envois max, `stream_select()`. → réponse brute ou `null`. Vérifie longueur minimale, somme de contrôle **et l'écho de la MAC en `0x2A`-`0x2F`** (anti-mélange entre deux appareils, AC6) |
| `private static diffuserParExtensionSockets($_paquet, $_budget)` | **Chemin principal** : `socket_create(AF_INET, SOCK_DGRAM, SOL_UDP)` + `socket_set_option(SOL_SOCKET, SO_BROADCAST, 1)` + `socket_sendto()` + `socket_select()`. Sous `function_exists('socket_create')` |
| `private static diffuserParFluxNatif($_paquet, $_budget)` | **Chemin secondaire** (`D-POSTMVP0101-03`) : `stream_socket_server('udp://0.0.0.0:0', $errno, $errstr, STREAM_SERVER_BIND, $contexte)` avec `array('socket' => array('so_broadcast' => true))`. ⚠️ **Ne PAS** utiliser `stream_socket_client('udp://255.255.255.255:80')` : un socket UDP *connecté* fait filtrer par le noyau les réponses venant de l'adresse **unicast** des appareils — la découverte ne verrait **rien** |
| `private static construirePaquet($_commande, $_charge, array $_session)` | En-tête `0x38` (§ 1.3) + charge complétée de zéros à un multiple de 16 puis chiffrée. **Ordre imposé** : somme de la charge en clair (`0x34`) **d'abord**, somme du paquet complet (`0x20`) **en dernier** |
| `private static construireHello()` | Paquet `0x30` de découverte (§ 1.1) |
| `private static normaliserReponseDecouverte($_reponse, $_ip)` | Réponse brute → ligne normalisée ou `null`. Porte toutes les validations de § 4.2 |
| `private static sommeControle($_octets)` | `sum(octets, 0xBEAF) & 0xFFFF`. Une seule implémentation pour les deux usages |
| `private static chiffrer($_donnees, $_cle)` / `dechiffrer($_donnees, $_cle)` | AES-128-CBC, `OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING`. ⚠️ Vider la file d'erreurs OpenSSL **en entrée** et journaliser sur chaque `false` — même discipline que `chiffrerMotDePasse()` existante |
| `private static codeErreur($_reponse)` | Entier **signé** LE sur 2 octets en `0x22`, `0` si la réponse est trop courte |
| `private static classerCodeAppareil($_code)` | Table code → `TYPE_*` (§ 1.6). **Un seul endroit**, jamais un `switch` dupliqué |
| `private static nettoyerNomExterne($_valeur, $_max = 63)` | Cf. § 4.2 |
| `private static empreinteSession(array $_appareil)` | `sha1($mac . '|' . $ip . '|' . $port . '|' . $devtype)`. ⚠️ **L'IP en fait partie** : c'est ce qui invalide la session tout seul sur un changement de bail DHCP |
| `private static dossierVerrous()` | Mémoïse `jeedom::getTmpFolder('smartclim')` dans `$dossierVerrous` (`D-POSTMVP0101-04`) |
| `private static verrou($_macNorm, $_budget)` / `libererVerrou($_ressource)` | `fopen(dossierVerrous() . '/lan-' . sha1($mac) . '.lock', 'c')` + `flock(LOCK_EX | LOCK_NB)` en boucle `usleep(50000)` bornée par `min(ATTENTE_VERROU, $_budget)` |
| **`requete()`** | ⚠️ **NON implémentée en UC01** — aucun appelant, ce serait du code mort. Cf. § 7 pour son contrat figé |

**Ligne normalisée** — clés **génériques françaises**, aucun nom ni offset de protocole n'en sort :

```
array(
  'mac'           => string,  // 12 hex minuscules, ordre IMPRIMABLE (comparable au cloud)
  'octets_mac'    => string,  // 12 hex, ordre de l'en-tête (à écrire en 0x2A-0x2F)
  'ip'            => string,
  'port'          => int,
  'type_appareil' => string,   // devtype en hexadécimal
  'nom'           => string,   // assaini, <= 63 caractères
  'verrouille'    => bool,
  'vu_le'         => int,      // horodatage
)
```

### 5.2 `smartclim` — façade, seul point qui parle au transport

```php
const CLE_CACHE_LAN = 'smartclim::lan_appareil::';
const DUREE_MEMOIRE_LAN = 86400;   // 24 h
const BUDGET_LAN = 18;             // budget GLOBAL de la phase LAN du scan
const CLE_CONF_LAN_IP = 'lan_ip';
const CLE_CONF_LAN_MAC = 'lan_mac';
```

| Membre | Contrat |
|---|---|
| `public static scannerClimatiseurs()` | **Composition, aucune logique métier.** (1) `scannerReseauLocal()` — ne lève jamais ; (2) `scannerAuxHome()` dans un `try/catch (smartclimException)` → message **déjà curaté** placé dans `cloudErreur`. Retour = les **6 clés existantes d'UC03/UC04/UC08 inchangées** + `lan` + `cloudErreur`. ⚠️ `scannerAuxHome()` **reste publique et inchangée** : le changement de contrat est **local** à cette nouvelle méthode |
| `private static scannerReseauLocal()` | Budget **global** `BUDGET_LAN`, chronométré depuis l'entrée. **Phase 1** : `decouvrir()`, puis pour chaque appareil, `try/catch` **individuel** → `ouvrirSession()` + `memoriserSondeLan()`. **Phase 2** : **une seule** `eqLogic::byType('smartclim')` ; pour chaque équipement porteur d'une adresse (`adresseLan()`) **non déjà rencontré en phase 1** → `interroger()` + `ouvrirSession()` + mémorisation (succès **ou** échec daté). ⚠️ **Arrêt dur** `if ((microtime(true) - $debut) >= self::BUDGET_LAN) break;` évalué **avant chaque appareil, dans les deux phases**. Rend `array('resume' => string, 'compteurs' => array, 'appareils' => array)`. **Ne lève jamais** |
| `private static ligneResultatLan(...)` | Ligne du tableau LAN : liste **blanche** de champs (jamais de clé de session). `array('nom', 'mac', 'ip', 'typeAppareil', 'statut', 'statutLibelle')` |
| `private static resumeScanLan(array $_compteurs)` | Résumé français. Doit mentionner les appareils **non sondés** faute de budget, et porter le message de dégradation si la diffusion est indisponible (§ 8) |
| `private static libelleStatutLan($_statut)` | `STATUT_*` → libellé français `__()`. **SEUL** endroit où vivent ces `__()` (même règle que `messageErreurAuxHome()`) |
| `private static memoriserSondeLan($_macNorm, array $_resultat)` | `cache::set(CLE_CACHE_LAN . $mac, json_encode(array('ip','port','type_appareil','nom','verrouille','statut','vu_le','echec_le')), DUREE_MEMOIRE_LAN)`. ⚠️ **Non chiffrée** : aucun secret dedans (IP et MAC n'en sont pas) — la clé de session vit dans une entrée **distincte et chiffrée**. ⚠️ **Pas de clé `motif`** : la raison d'un échec est déjà portée par `statut` (`D-POSTMVP0101-12`) |
| `private static sondeLanMemorisee($_macNorm)` | Relit l'entrée. ⚠️ **Teste la MAC ET la MAC inversée** (`macInversee()`, existante) — sans quoi AC4 échouerait silencieusement sur les appareils dont cloud et LAN annoncent la MAC en ordre opposé |
| `private static normaliserIpV4($_valeur)` | Cf. § 4.1 |
| `public adresseLan()` | **Règle de lecture unique**, analogue de `bornesTemperature()` : `ip` = `lan_ip` personnalisée (**revalidée à la lecture**) → sinon IP mémorisée en cache pour la MAC de l'équipement (**MAC inversée essayée aussi**) → sinon `''`. `mac` = `lan_mac` personnalisée → sinon `macEquipement()`. → `array('ip','mac','port','source' => 'manuel'|'detecte'|'aucun')` |
| `public macEquipement()` | MAC normalisée : `configuration.mac` (posée par `creerEquipement()` d'UC03) sinon préfixe `mac:` du `logicalId`. **Lecture seule** — UC01 n'écrit **jamais** `configuration.mac` depuis le LAN (c'est UC04) |
| `preSave()` — **ajout** | Normalise `lan_ip` et `lan_mac`. Silencieux, ne lève jamais (§ 4.1) |
| `etatConnexionAffichable()` — **ajout** | **2 clés additives** : `lan` (libellé de statut) et `lanAdresse` (IP + `' (' . dureeHumaine(...) . ')'`, ou `''`). Absence d'entrée de sonde ⇒ « Jamais détecté sur le réseau local » |
| `etatsConnexionAffichables()` — **ajout** | ⚠️ **Les 2 mêmes clés doivent figurer dans le repli du `catch (Throwable)`** — cf. le piège jQuery ci-dessous |

⚠️ **Piège jQuery à ne pas rouvrir** : `.text(undefined)` est un **accesseur**, pas un mutateur. Si le
serveur omet `lan` ou `lanAdresse`, le champ **conserve le texte de l'équipement précédemment
consulté** au lieu de se vider. Double ceinture : clés **toujours** présentes côté serveur (les deux
branches) **et** repli chaîne vide côté JS.

### 5.3 `smartclimCapabilities`

```php
const TRANSPORT_BROADLINK_LAN = 'BROADLINK_LAN';
```

plus une branche dans `libelleTransport()` → `'Broadlink LAN'` — **nom de marque, sans `__()`**,
conformément au docblock existant de la méthode.

⚠️ **Aucune entrée dans `tables()`** : les correspondances mode/vitesse du transport LAN relèvent
d'UC02. Une entrée vide serait de la donnée morte.

### 5.4 `core/ajax/smartclim.ajax.php` — une seule ligne

L'action `scannerClimatiseurs` appelle `smartclim::scannerClimatiseurs()` au lieu de
`smartclim::scannerAuxHome()`. `isConnect('admin')`, `ajax::init()`, `session_write_close()` et le
`catch (Throwable)` final sont **déjà en place** — ne rien y toucher. ⚠️ Fichier en **4 espaces**.

### 5.5 `desktop/php/smartclim.php` (⚠️ TABULATIONS + CRLF)

1. **Bloc résultat LAN** : titre « Climatiseurs détectés sur le réseau local », `#span_scanResumeLan`,
   table `#table_scanLan` à **5 colonnes** (Nom · Adresse MAC · Adresse IP · Type d'appareil · Résultat).
2. **Légende « Réseau local »** dans « Paramètres spécifiques », 2 champs :
   `<input ... class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="lan_ip">` et
   idem `data-l2key="lan_mac"`, avec placeholder « Adresse détectée » et l'infobulle
   « Renseignez l'adresse locale si la diffusion réseau n'atteint pas l'appareil (VLAN, réseau
   segmenté) ».
3. **Une ligne « Réseau local »** dans `#div_etatConnexion` : `#span_etatConnexionLan` et
   `#span_etatConnexionLanAdresse`.

⚠️ **Aucune double accolade ouvrante littérale** dans ce fichier (ni ailleurs dans un fichier rendu),
pas même en commentaire.

### 5.6 `desktop/js/smartclim.js`

- rendu de `#table_scanLan` en réutilisant `ajouterLigneScan()` telle quelle ;
- ⚠️ **vider `#table_scanLan tbody`** au clic, comme les deux tables existantes ;
- `cloudErreur` → `showAlert({ level: 'warning' })` — **`warning`, pas `danger`** : le scan a
  partiellement réussi (`D-POSTMVP0101-10`) ;
- `afficherEtatConnexion()` : afficher `lan` et `lanAdresse` avec un **repli chaîne vide** ;
- `saveEqLogic()` : normalisation d'aide de `lan_ip` / `lan_mac`, **jamais** de blocage,
  `return _eqLogic` conservé ;
- **`timeout: 30000` → `60000`** : pire cas 18 s (LAN) + 25 s (`BUDGET_SCAN` cloud) ≈ 43 s. Un
  `timeout` jQuery **n'interrompt pas** le PHP ; précédent dans le dépôt : la sonde de diagnostic est
  à 75 s.
- ⚠️ Chaînes JS en **apostrophes simples** ou `"{{…}}"` selon l'existant du fichier.

## 6. Concurrence, budgets, session

**Verrou** — `flock(LOCK_EX | LOCK_NB)` par MAC, pris **avant** la lecture du cache, couvrant tout le
cycle « lire session → authentifier si besoin → écrire session ». C'est cet ordre qui évite le TOCTOU
entre deux authentifications concurrentes. Motif du choix de `flock` plutôt qu'un verrou en cache :
`cache::byKey()` + `cache::set()` ne sont **pas atomiques** (le dépôt le documente déjà pour
`CLE_CACHE_VERROU_SCAN`, « une atténuation, jamais un mutex »), et l'OS relâche un `flock` à la mort du
processus — aucun verrou orphelin, aucune durée de vie à choisir.

**Session** — `cache::set(CLE_CACHE_SESSION . $mac, utils::encrypt(json_encode(array('id', 'cle',
'compteur', 'ip', 'port', 'devtype', 'cree_le', 'empreinte'))), DUREE_SESSION)`. **Chiffrée** parce
qu'elle contient la clé de session. Empreinte divergente à la relecture ⇒ entrée ignorée et
ré-authentification. ⚠️ **Contrôler la forme du tableau relu** (clés présentes, types) : l'empreinte ne
couvre pas un changement de **format** entre deux versions du plugin.

**Pourquoi pas « en mémoire du process »** : chaque sollicitation Jeedom est un **processus distinct**
(handler AJAX, tick de cron) — AC6 serait structurellement inatteignable, et comme authentifier
**invalide la session précédente** sur l'appareil, deux processus se décrocheraient mutuellement en
boucle.

**Budgets** — `BUDGET_LAN = 18 s` **strict** (cf. `D-POSTMVP0101-04`) : arrêt dur avant chaque
appareil, `ATTENTE_VERROU` borné par le budget restant, budget passé **en paramètre** à
`ouvrirSession()` / `interroger()` (jamais relu d'une constante au fond de la pile), chaque échange
recevant `max(1, min(TIMEOUT_ECHANGE, budget_restant))`.

## 7. Réservé à UC02 — contrat de `requete()`, à ne pas réinventer

`requete()` n'est **pas** écrite en UC01. Son contrat est figé ici pour qu'UC02 n'invente pas une
seconde convention :

- **point unique** portant la réauthentification réactive ;
- **un seul rejeu** par appel — booléen local, **jamais** de récursion (même convention que le
  re-login réactif d'UC02 du MVP) ;
- **déclencheurs** : silence dans le budget, ou code appareil `-7` / `-4012` / `-1` ;
- avant de ré-authentifier : **réinitialiser** clé par défaut + identifiant nul + **compteur à zéro** ;
- appliquer `DELAI_APRES_AUTH` (200 ms) **après** une authentification réussie, avant la première
  requête ;
- ⚠️ **la clé de session est stockée en HEXADÉCIMAL** dans l'entrée de cache (32 caractères
  `[0-9a-f]`), pas en octets bruts : `requete()` doit la repasser par `hex2bin()` avant de chiffrer
  quoi que ce soit. Raison, trouvée en review du tour 1 : `json_encode()` renvoie **`false`** dès
  qu'une chaîne du tableau n'est pas de l'UTF-8 valide, ce qui est quasi certain pour 16 octets
  aléatoires — la session était donc écrite corrompue et jamais réutilisée, `STATUT_REUTILISEE`
  restant inatteignable. `ouvrirSession()` valide la **forme** hexadécimale à la relecture, mais ne
  décode pas : elle ne renvoie jamais la clé.

## 8. Dépendances

**Aucune dépendance déclarée.** `hasDependency` reste `false`, `plugin_info/packages.json` reste
**vide**, `plugin_info/info.json` n'est pas modifié (son `"require": "4.2"` est la version minimale du
**core Jeedom**, pas une liste d'extensions PHP).

L'extension PHP `sockets` est **souhaitable mais non requise** (`D-POSTMVP0101-03`) :

| Chemin | Disponibilité | Conséquence |
|---|---|---|
| **Diffusion** — `socket_create()` + `SO_BROADCAST` | extension `sockets` | chemin **principal** (conforme à l'analyse interne § 9) |
| **Diffusion** — `stream_socket_server` + option de contexte `so_broadcast` | cœur de PHP, **non vérifié** | chemin **secondaire**, tenté seulement si le premier est indisponible |
| **Unicast** — `stream_socket_client('udp://<ip>:80')` | **cœur de PHP**, aucune extension | **toujours disponible** |

⚠️ **Dégradation, pas panne** : si aucun chemin de diffusion n'est disponible, **AC1 seul tombe**.
AC3 (saisie manuelle IP/MAC — le mode de secours VLAN que la spec fonctionnelle décrit déjà), AC4, AC6
et AC7 restent tenus, parce que l'unicast ne dépend d'aucune extension.

Message utilisateur, poussé dans le **résumé du scan** (pas seulement dans les logs), en `warning` :

> « Découverte automatique indisponible sur cet hôte (extension PHP « sockets » absente) — renseignez
> l'adresse IP locale de chaque climatiseur. »

## 9. Ce qui n'est délibérément PAS fait

- **Aucune sonde LAN dans `cron()`** : une diffusion par minute, et une bagarre récurrente pour la
  session unique avec l'application du constructeur sur le téléphone de l'utilisateur. Le scan est
  **manuel**.
- **Aucune clé `lan_enabled`** : le domaine `post-mvp/02` est propriétaire du choix de transport ; un
  interrupteur ici créerait un second commutateur concurrent.
- **Aucune extraction de `smartclimFrame`** : condition « second appelant » non remplie (cf. § 0).

## 10. Recette

⚠️ **AC1 à AC7 ne sont PAS recettés** — `D-POSTMVP0101-01`. L'analyse interne
`.memory/analyse/smartclim-transport-broadlink-lan.md` porte en en-tête, depuis le 2026-08-24, la
mention « **Ne fonctionne PAS sur l'appareil de validation** » : le climatiseur de référence de
l'utilisateur ignore le protocole Broadlink. Le code est livré **théoriquement correct et
instrumenté**, non vérifié contre du matériel.

Ce qui est **néanmoins observable** sur l'installation actuelle :

| Vérification | Attendu |
|---|---|
| Clic sur « Scanner les climatiseurs » sans appareil Broadlink | table LAN vide, résumé « Aucun climatiseur détecté sur le réseau local », **aucun log `error`**, résultats cloud inchangés |
| Idem sans compte cloud configuré | résultats LAN affichés, bandeau **`warning`** (pas rouge) sur le cloud |
| Saisie d'une IP invalide dans « Adresse IP locale » | champ vidé à l'enregistrement, `warning` dans les logs, **aucun refus** d'enregistrement |
| Saisie d'une IP publique (ex. `8.8.8.8`) | **refusée** (champ vidé) — § 4.1 |
| Équipement sans adresse LAN | « Jamais détecté sur le réseau local » dans « État de connexion » |
| Consultation successive de deux équipements | le champ « Réseau local » **se vide** au lieu de garder la valeur du précédent (piège jQuery, § 5.2) |
| Durée d'un scan | bornée, et le résumé mentionne les appareils non sondés si le budget a été atteint |

Le premier contact avec un appareil réellement compatible doit produire, en niveau `debug` : les
valeurs `0x26`-`0x27` observées, le `devtype`, et tout code d'erreur appareil — c'est l'instrumentation
prévue par `D-POSTMVP0101-01`.

## 11. Risques transmis à UC02

1. ⚠️ **Aucun self-healing de la mémoire de sonde.** Contrairement à la session cloud, dont un défaut
   de cache se répare seul via `login()`, une purge du cache Jeedom (bouton « vider le cache »,
   redémarrage, éviction) laisse l'équipement en « Jamais détecté » **jusqu'au prochain clic manuel sur
   Scanner** — il n'y a volontairement aucun cron LAN. **UC02 devra retenter une découverte à la volée
   quand `adresseLan()` renvoie `source: 'aucun'`**, sans quoi AC2 (« sans que l'utilisateur ait saisi
   la moindre information ») ne tiendra pas.
2. **Le succès de l'authentification ne prouve pas que les requêtes passeront** : UC01 n'émet rien
   après le `0x65`. Un appareil qui authentifie puis refuse les requêtes ne sera démasqué qu'en UC02.
3. **Concurrence avec un autre contrôleur** (Home Assistant, Homebridge, application du
   constructeur) : une seule session par appareil ⇒ notre session sera invalidée sans préavis — absorbé
   par le rejeu réactif — **mais l'inverse est vrai aussi**, le plugin peut couper la session d'un
   autre logiciel. Intrinsèque au protocole, à documenter côté `docs/`.
4. **Hôte multi-interfaces** : `255.255.255.255` ne sort que par la route par défaut (bridge Docker,
   second NIC, VLAN). Contournement = la saisie manuelle, déjà prévue. Levier futur :
   `net_get_interfaces()` (PHP ≥ 7.3, natif) pour des diffusions dirigées par sous-réseau.
5. **Changement d'IP par DHCP** : géré par l'empreinte de session, mais l'adresse mémorisée devient
   fausse jusqu'au prochain scan. Recommander une réservation DHCP dans `docs/`.
6. **Faiblesse théorique du `flock` par chemin** : si le fichier de verrou est supprimé pendant qu'il
   est tenu, un second processus le recrée avec un nouvel inode et verrouille en parallèle.
   Probabilité négligeable (verrou tenu quelques secondes, dossier géré par Jeedom) — mentionné pour
   mémoire.

## 12. Dette

Alimentée après les deux tours de reviews croisées. **Tous les findings au-dessus de la gate
(`high` / `major`) ont été corrigés dans le cycle** — ce qui suit est ce qui reste ouvert, assumé.

| # | Point | Pourquoi c'est assumé | Ce qui le refermerait |
|---|---|---|---|
| 1 | **AC1 à AC7 non recettés** | `D-POSTMVP0101-01` : l'appareil de validation de l'utilisateur ignore le protocole Broadlink (cf. § 10 et l'en-tête de `.memory/analyse/smartclim-transport-broadlink-lan.md`). Le code est vérifié contre `python-broadlink`, jamais contre du matériel | un appareil compatible (G1/G2) sur le réseau de l'utilisateur, ou d'un contributeur |
| 2 | **`normaliserIpV4()` dupliquée** entre `smartclimBroadlinkLan` et `smartclim` | duplication prévue par ce plan (§§ 5.1 et 5.2) : la brique de transport ne doit pas dépendre de la classe principale. Mais c'est une **règle de validation** en double, donc deux endroits à corriger — la review du tour 1 a effectivement dû faire corriger `0.0.0.0/8` et `224.0.0.0/4` aux deux endroits, puis la passe de finition y remplacer `ip2long()` par une comparaison d'octets (`D-POSTMVP0101-13`) — deux fois la même correction, deux fois le risque de n'en faire qu'une | l'arrivée d'un utilitaire partagé sans dépendance circulaire, probablement au domaine `post-mvp/02` quand `smartclimTransport` existera |
| 3 | **`MAX_REPONSES_BRUTES = 128` n'est pas une valeur mesurée** | borne de mémoire posée par prudence pendant la collecte de diffusion (review tour 1) ; aucun réseau réel n'a été observé pour la calibrer. Trop basse, elle tronquerait une découverte sur un LAN très bavard | une observation sur un réseau réel comptant plusieurs appareils Broadlink |
| 4 | **Aucun self-healing de la mémoire de sonde** | il n'y a volontairement aucun cron LAN (§ 9) : une purge du cache Jeedom laisse l'équipement en « Jamais détecté » jusqu'au prochain scan manuel | **UC02 doit** retenter une découverte à la volée quand `adresseLan()` renvoie `source: 'aucun'` — cf. § 11.1, c'est une condition d'AC2 |
| 5 | **Hôte multi-interfaces** | `255.255.255.255` ne sort que par la route par défaut (bridge Docker, second NIC, VLAN). Contournement déjà livré : la saisie manuelle IP/MAC | `net_get_interfaces()` (PHP ≥ 7.3, natif) pour des diffusions dirigées par sous-réseau |
