# Spec technique — UC04 « Spike puis transport local alternatif AUXLink »

> **Domaine** : post-mvp/05-temps-reel-et-demon · **Spec fonctionnelle** : `04-spike-auxlink-local.md`
> **Statut à la livraison** : ⚠️ **sonde livrée — verdict en attente**. Cette UC **ne se clôt pas au
> commit** : elle se clôt quand le verdict est consigné (AC6). Voir § 9.

## 0. Position — ce que ce cycle livre, et pourquoi

Ce cycle livre **la sonde de découverte AUXLink et rien d'autre** : un module de démon en **lecture
seule**, un instrument de lecture du résultat (CLI), et les ancrages documentaires du verdict. Le code de
session, de lecture d'état et de pilotage **n'est pas écrit dans ce cycle**.

⚠️ **Le motif est factuel avant d'être juridique, et c'est cet ordre qui compte** (arbitré en revue de
plan) :

1. **Motif principal — il n'y a rien à coder.** La spec fonctionnelle pose elle-même, en « À confirmer » :
   « le format exact du secret d'appairage local dérivé des métadonnées cloud reste à établir à
   l'implémentation ». Et le contrat externe est **contradictoire avec lui-même** sur ce point (§ 2.3,
   écart C3). Écrire la session maintenant serait écrire à l'aveugle un protocole non confirmé, sur un
   appareil dont on ignore s'il le parle.
2. **Motif secondaire — l'AC1 le confirme.** « Aucune fonction d'écriture ou d'établissement de session
   n'y figure », vérifiable par relecture du code produit *à ce stade*. Livrer la session « prête mais non
   appelée » rendrait cette phrase fausse à la lettre, et rendrait l'AC5 coûteuse (« aucun code
   d'exploitation ne reste ») : on écrirait pour supprimer.

⚠️ **Ne pas inverser ces deux motifs dans une UC future.** Présenter le découpage comme une contrainte
purement rédactionnelle d'AC1 est un raisonnement fragile : il s'effondre dès qu'on relit AC1 comme une
clause d'ordonnancement. Le motif qui tient seul est le premier.

**Décision de clôture retenue** (validée avec l'utilisateur) : si le verdict est positif, ouvrir
`post-mvp/05-temps-reel-et-demon/06-transport-auxlink-local.md`. L'UC04 porte alors la sonde et le
verdict, l'UC06 le transport.

## 1. Couverture des critères d'acceptation

| AC | Couverture |
|---|---|
| **AC1** | **Couvert par construction.** `sonde_auxlink.py` ne contient ni `_encrypt`, ni `_authenticate`, ni `PASSCODE_QUERY`, ni aucun envoi sur TCP 12416. **Contrôle prescrit** : `grep -nE "encrypt\|passcode\|sendall\|0x0007\|a5a50a000500" resources/smartclimd/sonde_auxlink.py` → **zéro occurrence**. |
| **AC2** | **Hors de ce cycle** — conditionné au verdict. Aucune session, aucune lecture d'état. |
| **AC3** | **Hors de ce cycle** — conditionné au verdict. |
| **AC4** | **Vacuement satisfait, et il faut le dire ainsi** : aucun secret d'appairage n'est obtenu ni stocké, parce que son obtention (`PASSCODE_QUERY`) *est* le début de l'établissement de session. Rien à chiffrer, donc rien à protéger. ⚠️ Voir **R9** : le contrat externe rend ce secret obtenable **sans authentification** par quiconque est sur le LAN — fait à consigner **avant** de bâtir l'AC4 d'une UC future. |
| **AC5** | **Couvert en deux temps.** Le mécanisme (sonde + trois paliers, § 4) est livré ici ; la **conclusion négative écrite** est produite à l'expiration de la fenêtre (§ 9). Le second volet (« aucun code d'exploitation ne reste ») est **vrai par construction** puisqu'aucun n'est écrit. |
| **AC6** | **Trace construite ici, verdict consigné à l'échéance.** Le squelette des deux branches rédactionnelles est figé au § 9 — l'auteur n'a aucune rédaction à inventer. |

⚠️ **Trois AC sur six restent ouverts à la fin du cycle** (R7). Le statut de la spec fonctionnelle doit le
refléter, sans quoi un `/auto-dev` futur la lira comme « livrée » — piège déjà rencontré sur l'UC06 du MVP.

## 2. Contrats externes

Tout ce qui suit porte sur des **appareils autres que celui de validation** : c'est l'objet même du spike.
Chaque ligne est marquée **[LU]** (lu dans du code source) ou **[HYPOTHÈSE]**.

### 2.1 C1 — Découverte AUXLink

Source : `latentharbor/ha-aux-a-plus`, **MIT**, `custom_components/aux_a_plus/lan.py`.

| Élément | Valeur | Statut |
|---|---|---|
| Port de requête | UDP **12414** (`DISCOVERY_PORT`) | [LU] |
| Port de réponse | UDP **2415** (`DISCOVERY_REPLY_PORT`) — le récepteur fait `bind(("", 2415))` | [LU] |
| Trame de requête | `a5a50a000200000028ab` | [LU] |
| Émission | `SO_BROADCAST`, vers l'hôte connu **et** vers `255.255.255.255` | [LU] |
| Cadrage | magic `A5 A5` (2 o.) + longueur totale LE (2 o.) + type LE (2 o.) + séquence LE (2 o.) + charge + **CRC-16 CCITT big-endian** (2 o.) | [LU] |
| CRC | CCITT, polynôme `0x1021`, init `0xFFFF`, décalage à gauche bit à bit | [LU] |
| Réponse de découverte | type **`0x0003`** ; `payload[0]` = `secure_type` ; `payload[7]` = longueur MAC (**6**) ; MAC en `payload[8:14]` ; puis `did_length` et `device_id` **ASCII** | [LU] |
| Charge utile max | 4086 octets | [LU] |

Décodage de la requête : `a5a5` · `0a00` (longueur = 10) · `0200` (type `0x0002`) · `0000` (séquence) ·
`28ab` (CRC). Elle est donc **sans charge utile** — une pure requête de découverte.

### 2.2 C2 — Corroboration indépendante des ports

Sources : `Apollon77/node-ph803w` (`PROTOCOL.md`) et `gizwits/Gizwits-GAgent` (`gagent/include/gagent.h`).

Les trois ports **12414 / 2415 / 12416** sont les ports standard du **LAN Gizwits GAgent**, documentés
pour des appareils sans aucun rapport avec AUX (capteur pH PH-803W, saunas, pompes). [LU]

| Trame | Octets | Commande | Statut |
|---|---|---|---|
| Requête de découverte GAgent | `00 00 00 03 03 00 00 03` | **`0x03`** | [LU] |
| Réponse de découverte GAgent | `00 00 00 03 68 00 00 04 …` (puis DID, MAC, product key, adresse serveur) | **`0x68`** | [LU] |

⚠️⚠️ **Ce que cette corroboration change, et c'est structurant pour tout le dispositif** : les **ports**
sont solidement établis par une source indépendante, mais le **cadrage `a5a5` ne l'est pas** — il n'est
attesté que par `latentharbor`. Le module AUX est très probablement un module **Gizwits** ; `a5a5` est
alors un cadrage propriétaire posé par AUX *par-dessus* le transport GAgent, ou l'observation d'un
firmware particulier.

**Corollaire opérationnel : un négatif obtenu sur la seule trame `a5a5` ne prouverait rien.** D'où deux
décisions non négociables — la sonde émet **deux variantes** (C1 et C2), et elle **écoute passivement**
les deux ports (§ 4).

### 2.3 C3 — Ce que la sonde n'implémente PAS (frontière AC1, listée pour être vérifiable)

| Élément | Valeur lue | Statut dans ce cycle |
|---|---|---|
| `PASSCODE_QUERY` | `a5a50a00050000007986` (type `0x0005`), envoyée **sur TCP 12416**, réponse type `0x1005` | **INTERDIT** |
| Dérivation de clé | `auth_key = md5(passcode + mac)` ; `session_key = secrets.token_bytes(16)` | **INTERDIT** |
| Authentification | trame `0x0007` = `AES-CBC(auth_key, session_key) + md5(session_key)` ; réponse `0x1007` déchiffrée = `b"ok"` ; ⚠️ **le timeout est accepté comme succès** par la référence | **INTERDIT** |
| Chiffrement | AES-CBC, padding PKCS7, **IV = 16 octets nuls** | **INTERDIT** |
| Maintien / état | `HEARTBEAT = 4.0 s` (type `0x0009`), `STATUS_REFRESH = 10.0 s` ; état court `0x100B` cmd `0x11`, long `0x21`/`0x2C`, ACK `0x01` | **INTERDIT** |

⚠️ **Écart de sources signalé, délibérément non tranché ici.** `docs/RESEARCH_PLAYBOOK.md` du même dépôt
dit « Obtain/configure the MAC and local passcode from cloud device metadata », alors que `lan.py`
**interroge l'appareil** quand aucun passcode n'est configuré (`if passcode is None: sock.sendall(
PASSCODE_QUERY)`). Les deux chemins coexistent dans le code ; le playbook ne nomme **aucun champ** de
métadonnée cloud, et notre `smartclimAuxHomeApi::normaliserAppareil()` ne récolte aujourd'hui aucun champ
ressemblant à un passcode. **À trancher à l'ouverture de l'UC de transport, pas ici** ; le code fait foi
sur le contrat, donc le chemin « interrogation de l'appareil » est le chemin réputé suffisant.

### 2.4 C4 — Licences

`latentharbor/ha-aux-a-plus` : **MIT** → portage autorisé, **notice à conserver en en-tête** de
`sonde_auxlink.py` pour la fonction de CRC. `Apollon77/node-ph803w` et `gizwits/Gizwits-GAgent` : utilisés
comme **sources factuelles** (ports, cadrage GAgent) — un fait de protocole n'est pas protégeable, aucune
ligne n'en est recopiée.

## 3. Architecture

| Chemin | État | Ce qui y entre | Indentation / EOL |
|---|---|---|---|
| `resources/smartclimd/sonde_auxlink.py` | **créé** | Classe `SondeAuxlink`. **Tout le code AUXLink du cycle vit ici.** | 4 espaces, **LF** |
| `resources/smartclimd/smartclimd.py` | modifié | `try/except ImportError` sur l'import ; instanciation `_sonde` ; **un `elif commande == 'auxlink_sonde'`** dans `read_socket()` ; arrêt dans `shutdown()` ; paragraphe d'en-tête FR | 4 espaces, **LF** |
| `core/class/smartclimDemon.class.php` | modifié | 7 constantes + 4 méthodes publiques + 1 privée de validation. **Aucune méthode existante touchée.** | 2 espaces, **CRLF** |
| `core/php/jeeSmartclim.php` | modifié | **5ᵉ bloc `if` INDÉPENDANT** `auxlink.sonde` | 2 espaces, **CRLF** |
| `core/class/smartclim.class.php` | modifié | 3 méthodes publiques + 1 privée. **Aucun appel depuis le socle.** | 2 espaces, **CRLF** |
| `core/class/smartclimDiagnostic.class.php` | modifié | `jeton()` passe **`private` → `public`** (§ 7) | 2 espaces, **CRLF** |
| `core/php/pont-demon.php` | modifié | 3 options. Reste un **aiguillage sans logique métier** | 2 espaces, **CRLF** |
| `.memory/analyse/smartclim-ecosysteme-aux-broadlink.md` | modifié | § 4, § 6, § 7 + **§ 8 nouveau** (§ 9) | — |
| `.memory/analyse/INDEX.md` | modifié | 1 ligne § 0 + bandeau de date | — |
| `core/php/smartclim.inc.php` | **NON touché** | ⚠️ Vérifié, pas supposé : **aucune classe PHP nouvelle** → aucun `require_once` à ajouter. Le piège d'autoload ne s'applique pas. | — |
| `plugin_info/packages.json` | **NON touché** | ⚠️ **Aucune dépendance pip nouvelle** : `socket`, `struct`, `threading`, `time`, `binascii`, `re` sont en stdlib. Donc **aucun re-déclenchement du cycle d'installation sur tout le parc**, et aucun arbitrage `os.min` à rejouer. Voir **R5** pour la phase 2. | — |
| `core/php/.htaccess` | **NON touché** | `pont-demon.php` est une CLI : elle **doit** rester derrière `Deny from all`. | — |
| `plugin_info/configuration.txt` / `.php` | **NON touchés** | **aucun champ de formulaire, aucune clé de config** → aucun `cp` de miroir | — |
| `desktop/**`, `core/i18n/*.json` | **NON touchés** | aucune chaîne UI (§ 8) | — |

### 3.1 Décision — la sonde vit dans le démon, pas dans une 6ᵉ CLI PHP

L'alternative (CLI PHP autonome sur le moule de `diagnostic-auxhome.php` : bind 2415, diffuse, attend
30 s, affiche) est plus petite — mais elle ne tient **ni** la fenêtre d'observation de 24 h sans terminal
attaché, **ni** l'écoute passive, qui est ce qui rend un verdict négatif défendable (§ 4). Elle serait de
surcroît intégralement jetée à la phase 2, dont la session TCP à battement de 4 s **exige** un processus
long. La spec fonctionnelle dit d'ailleurs explicitement « le démon […] tente une découverte AUXLink ».

### 3.2 Décision — le rapport remonte par le PONT, pas par un fichier sur disque

Arbitré avec l'utilisateur contre la variante « JSON dans `jeedom::getTmpFolder('smartclim')` lu
directement par la CLI », qui économisait deux fichiers touchés. Motifs : homogénéité avec le relais de
l'UC03 (même canal, même patron de validation), aucun état sur disque, aucune question de droits entre le
démon et Apache — et surtout ⚠️ **`systemd-tmpfiles-clean` purge le dossier temporaire des fichiers non
touchés depuis 10 jours**, piège déjà payé sur ce projet (cf. `smartclimDemon::etat()` et son repli par
sonde `ps`).

### 3.3 Décision — aucune clé de configuration, aucun bloc de `cron()`

La sonde est armée **exclusivement** par la CLI, pour trois raisons :

1. diffuser une trame propriétaire inconnue sur le LAN de **tous** les utilisateurs du plugin, en
   permanence, n'est pas acceptable pour un spike → l'**opt-in est obligatoire** ;
2. une case à cocher coûterait un champ de formulaire, une clé de config, une chaîne i18n et sa traduction
   — tous à retirer sur le chemin négatif ;
3. sans clé de config ni bloc de cron, **aucune méthode du socle MVP ne touche la sonde**, ce qui rend
   l'invariant de latéralité vrai **par absence de code**, et non par relecture.

⚠️ **Cet invariant est ici plus fort que pour l'UC03** : `rafraichirAuxCloud()` lit le battement du relais
(exception documentée dans `CLAUDE.md`). La sonde, elle, n'a **aucun lecteur dans le socle** — ni
`cron()`, ni `rafraichirAuxHome()`, ni `executerCommandeAction()`, ni aucune brique de transport.

## 4. Stratégie d'observation — ce que « observation raisonnable » veut dire (AC5)

Trois paliers. ⚠️ **Un verdict négatif n'est recevable que si les trois sont passés à blanc.**

**Palier 1 — amorce, ~30 s, à l'armement.** 3 émissions de la variante `a5a5` + 3 de la variante GAgent,
alternées, à 10 s d'intervalle (`INTERVALLE_AMORCE = 10`, `AMORCES = 3`), vers `255.255.255.255:12414`
**et** vers chaque hôte connu. Plus, si un hôte est connu, **un** test d'ouverture de TCP 12416. Un
appareil AUXLink répond en quelques millisecondes : si le palier 1 rend quelque chose, le verdict est
**positif immédiatement** et les paliers suivants sont sans objet.

**Palier 2 — veille, 24 h par défaut** (`DUREE_OBSERVATION_DEFAUT = 86400`, réglable par `--duree=`,
bornée à 7 jours). Émission alternée toutes les **300 s** (`INTERVALLE_VEILLE` — 288 diffusions sur 24 h,
pas 1440 : la valeur ajoutée du temps n'est pas le débit), **écoute passive continue** sur UDP 2415 *et*
UDP 12414, test d'ouverture de TCP 12416 toutes les **900 s** si un hôte est connu, rapport au pont toutes
les **60 s** (`INTERVALLE_RAPPORT`) **et** immédiatement à chaque trame nouvelle.

⚠️ **L'écoute passive sur 12414 est le point non évident, et elle justifie à elle seule le choix du
démon** : c'est le port sur lequel **l'application du constructeur** diffuse ses propres requêtes de
découverte. Une trame `a5a5` ou `00000003` observée **en provenance du téléphone** prouve que ce LAN parle
le protocole, **indépendamment de la justesse de notre trame** — et en donne l'octet exact. Symétriquement,
C2 indique que la réponse de l'appareil est elle-même **diffusée depuis 2415**, ce qui rend observables des
réponses déclenchées par le téléphone.

**Palier 3 — corroboration humaine, obligatoire avant tout verdict négatif, coût en code : zéro.**
Pendant la fenêtre :

1. ouvrir **AUX Home** sur un téléphone **connecté au même Wi-Fi**, ouvrir la fiche du climatiseur,
   changer la consigne ;
2. **couper l'accès Internet du téléphone** (Wi-Fi conservé, **données mobiles coupées**) et réessayer de
   piloter.

Deux issues, toutes deux décisives :

- l'application pilote encore l'appareil **sans Internet** ⇒ un protocole LAN existe ⇒ **c'est notre trame
  qui est fausse, pas l'hypothèse** ⇒ ⚠️ **interdiction de conclure négatif** ; rouvrir avec une capture
  réseau (le rapport de la sonde contient alors les trames vues sur 12414, qui donnent la réponse) ;
- l'application **ne pilote plus** sans Internet ⇒ **négatif décisif**, et il ne dépend d'aucun format de
  trame ni d'aucune source tierce. C'est le seul test du dispositif dont la valeur probante ne repose pas
  sur `latentharbor`.

⚠️ **Leçon de méthode déjà payée sur ce projet** (§ 7.4 de `smartclim-transport-aux-home.md`) : « rien n'a
répondu » ne vaut preuve d'absence **que si l'instrument pouvait voir la chose**. Le palier 3 est
l'instrument qui ne peut pas être aveugle.

## 5. Server vs Client

**Aucun client.** Aucune page, aucune modale, aucun endpoint AJAX, aucun JavaScript. Trois surfaces
serveur seulement :

| Surface | Rôle | Authentification |
|---|---|---|
| `resources/smartclimd/sonde_auxlink.py` | Processus long : émission bornée, écoute passive, rapport | — (processus local) |
| `core/php/jeeSmartclim.php` | Réception du rapport démon → Jeedom | `jeedom::apiAccess(init('apikey'), 'smartclim')` (déjà en place) |
| `core/php/pont-demon.php` | Armement, désarmement, lecture du verdict | `php_sapi_name() === 'cli'` **avant tout `require_once`** (déjà en place) |

⚠️ **Aucune surface web nouvelle n'est ouverte.** L'armement d'une diffusion réseau reste accessible à
l'admin SSH seul — c'est délibéré (§ 3.3).

## 6. Server Actions / API — signatures

### 6.1 `resources/smartclimd/sonde_auxlink.py` (créé) — aucune écriture, aucune session

Patron repris **verbatim** de `RelaisAuxCloud` : compteur de **génération** (invalide une campagne
antérieure sans interrompre un socket bloquant), verrou `threading.Lock`, threads `daemon=True`,
`except Exception` journalisant **`type(erreur).__name__`** et jamais `str(erreur)`.

**Constantes**

```
SONDE_AUXLINK        = bytes.fromhex('a5a50a000200000028ab')
SONDE_GAGENT         = bytes.fromhex('0000000303000003')
PORT_DECOUVERTE      = 12414
PORT_REPONSE         = 2415
PORT_SESSION         = 12416
CMD_GAGENT_REQUETE   = 0x03        # cf. M1 ci-dessous
CMD_GAGENT_REPONSE   = 0x68
TYPE_AUXLINK_REQUETE = 0x0002
TYPE_AUXLINK_REPONSE = 0x0003
INTERVALLE_AMORCE    = 10
AMORCES              = 3
INTERVALLE_VEILLE    = 300
INTERVALLE_PORT      = 900
INTERVALLE_RAPPORT   = 60
TAILLE_MAX_DATAGRAMME = 2048
TRAMES_MAX           = 20
HEX_MAX              = 128
REGEX_DID            = re.compile(r'\A[A-Za-z0-9_-]{1,64}\Z')
```

| Membre | Contrat |
|---|---|
| `SondeAuxlink.__init__(jeedom_com)` | **Aucun socket ouvert ici.** |
| `configurer(paquet)` | Valide la **forme** du paquet reçu du pont (`actif`, `duree`, `hotes`, `empreinte`) ; `actif == False` ⇒ `arreter()`. Incrémente la génération et démarre les threads. **No-op** si empreinte identique et threads vivants. **Ne lève jamais.** |
| `arreter()` | Invalide la génération, ferme les sockets. Appelée par `shutdown()` de `smartclimd.py`. |
| `_ouvrir_sockets()` | **S1** `bind(('', 2415))` + `SO_REUSEADDR` + `SO_BROADCAST` — ⚠️ **c'est depuis S1 que les sondes sont émises**, de sorte qu'une réponse adressée *au port* 2415 comme une réponse adressée *au port source* (= 2415) arrivent toutes deux ; **S2** `bind(('', 12414))` + `SO_REUSEADDR`, **strictement passif**. ⚠️ Échec de `bind` (port déjà pris par un autre logiciel de l'hôte) ⇒ **un** `logging.error` nommant le port, la sonde se désactive, **le démon continue de servir le pont et le relais** — même doctrine que l'`import websocket` sous garde. |
| `_boucle_emission(generation, hotes)` | Paliers 1 puis 2. `sendto` vers `255.255.255.255:12414` puis chaque hôte. S'arrête à `expire_le`. |
| `_boucle_ecoute(generation, sock, port_local)` | `recvfrom(TAILLE_MAX_DATAGRAMME)`, `settimeout(1)`. ⚠️ **S'arrête aussi à `expire_le`** et ferme son socket (cf. m1) — pas seulement au désarmement. Délègue à `_analyser_trame()`. |
| `_tester_port(ip) -> str` | `socket.create_connection((ip, PORT_SESSION), timeout=2)` puis `close()` **immédiat, sans aucun `send`/`sendall`**. Renvoie `'ouvert'` / `'refuse'` / `'timeout'`. ⚠️ **Seule fonction du fichier qui ouvre un TCP — commentaire d'ancrage AC1 obligatoire au-dessus.** |
| `_analyser_trame(octets, ip_source) -> dict\|None` | **Fonction pure.** Règles au § 6.2. |
| `_crc16_ccitt(octets) -> int` | **Fonction pure**, polynôme `0x1021`, init `0xFFFF`. Portée de `lan.py` (MIT) — **notice à citer en en-tête du fichier**. |
| `_adresses_locales() -> set` | Détermine les IP locales pour le filtre de défense en profondeur. ⚠️ **Limites à écrire en commentaire, pas à laisser implicites** : `socket.gethostbyname(gethostname())` est peu fiable sur Debian ; le repli est `socket.socket(AF_INET, SOCK_DGRAM)` + `connect(('8.8.8.8', 80))` + `getsockname()[0]` — **qui n'émet aucun paquet** (UDP non connecté) mais ne voit qu'**une** interface. En multi-interfaces et en conteneur, ce filtre est **incomplet par construction** : c'est exactement pourquoi il n'est pas le critère primaire (§ 6.2). |
| `_noter_trame(...)` | Stocke sous verrou, **borné à `TRAMES_MAX = 20`** (les plus récentes), hex tronqué à `HEX_MAX`, **âge relatif en secondes** et non horodatage absolu. |
| `_boucle_rapport(generation)` | `add_changes('auxlink::sonde', rapport)` toutes les `INTERVALLE_RAPPORT` s **et** immédiatement sur trame nouvelle. Le rapport porte **son empreinte de campagne** (cf. M4, § 6.3). |

### 6.2 ⚠️⚠️ Classement d'une trame — le point qui décide de la justesse du verdict

C'est le correctif **M1** de la revue de plan, et il ne doit pas être relâché.

**Critère PRIMAIRE — l'octet de commande, jamais l'adresse source.**

| Famille | Condition | Classement |
|---|---|---|
| `auxlink` | préfixe `a5a5` **et** longueur déclarée cohérente **et** **CRC valide** | `type` lu ; si `type == 0x0003` → extraction MAC (6 o.) et `device_id` |
| `gagent` | préfixe `00000003` **et** octet de commande (5ᵉ octet) | `sens = 'requete'` si `0x03`, `'reponse'` si `0x68`, `'autre'` sinon |
| `autre` | tout le reste | conservé brut (tronqué), aucun sens attribué |

⚠️ **Une trame `auxlink` de type `0x0002` ou une trame `gagent` de sens `'requete'` NE PEUVENT PAS armer
un verdict** : ce sont des **requêtes**, donc soit les nôtres, soit celles d'un autre client (le téléphone
— cas intéressant, mais qui ne prouve pas qu'un *appareil* a répondu). Seules les **réponses** (`auxlink`
type `0x0003`, `gagent` commande `0x68`) sont probantes.

**Critère SECONDAIRE — filtre d'adresse source locale, défense en profondeur uniquement.** Notre diffusion
vers `255.255.255.255:12414` **revient sur notre propre socket S2** (boucle de diffusion locale). Le filtre
l'écarte. ⚠️ **Mais il ne doit jamais être le seul rempart** : `_adresses_locales()` est incomplète par
construction (§ 6.1), et si elle échouait silencieusement, un classement fondé sur elle produirait un
verdict **faux** — pas indécis. Avec le critère primaire, notre propre requête est de toute façon classée
`sens = 'requete'` et ne peut armer aucun verdict.

⚠️ **Leçon générale, à ne pas perdre** : un filtre dont la fiabilité dépend de l'environnement
(interfaces, conteneur) ne peut pas porter seul une conclusion. Il faut un critère **intrinsèque à la
donnée** — ici, l'octet que l'émetteur a lui-même posé.

### 6.3 `core/class/smartclimDemon.class.php` (ajouts seuls)

```
const CLE_CACHE_SONDE_AUXLINK     = 'smartclim::sonde_auxlink';
const DUREE_MEMOIRE_SONDE_AUXLINK = 604800;   // 7 j
const DUREE_OBSERVATION_DEFAUT    = 86400;    // 24 h
const DUREE_OBSERVATION_MIN       = 60;
const DUREE_OBSERVATION_MAX       = 604800;
const TRAMES_MAX_SONDE            = 20;
const FRAICHEUR_SONDE             = 180;
```

⚠️ **`DUREE_MEMOIRE_SONDE_AUXLINK` (7 j) est délibérément plus longue que `DUREE_MEMOIRE_RELAIS` (300 s)**,
et ce n'est pas une incohérence : un battement de relais ne vaut que frais, un rapport de campagne doit
**survivre à sa propre fenêtre** pour être lisible après coup — c'est lui qui porte la preuve du verdict.

| Membre | Contrat |
|---|---|
| `public static envoyerSondeAuxlink(array $_sonde): bool` | Délégation à `envoyer(array('cmd' => 'auxlink_sonde', 'sonde' => $_sonde))`. Hérite du contrat : **ne lève jamais**, journalise `cmd` + nombre d'octets seulement. |
| `public static enregistrerSondeAuxlink($_rapport): void` | **Appelée UNIQUEMENT par `jeeSmartclim.php`.** Valide intégralement la forme **avant** toute écriture en cache (§ 7). ⚠️ **Horodatage SERVEUR** (`time()`), jamais celui du démon — invariant déjà posé par `enregistrerBattement()`. Cache **non chiffré** : le rapport ne contient aucun secret (aucun passcode n'existe dans ce cycle). |
| `public static rapportSondeAuxlink(): ?array` | Relit, **revalide la forme**, renvoie `null` plutôt qu'un contenu forgé — patron `battementRelais()` / `incidentMemorise()`. |
| `public static oublierSondeAuxlink(): void` | `cache::delete()`. ⚠️ **Appelée sous condition seulement** (M4, ci-dessous). |
| `private static tramesValidees($_valeur): array` | Borne à `TRAMES_MAX_SONDE`, liste blanche **fermée** de `famille` et de `sens`, regex sur `hex`/`mac`/`device_id`/`ip_source`, entiers bornés. |
| `private static portsValides($_valeur): array` | **Symétrique de la précédente, pour le champ `ports`** : liste blanche **fermée** de `statut` (`ouvert`/`refuse`/`timeout`), borne à 4. Elle existe parce que `ports` est consommé par `diagnosticSondeAuxlink()` (verdict `piste_port`) **et** affiché par `pont-demon.php --auxlink` : le laisser hors validation ouvrirait dans le rapport une seconde voie d'entrée, non filtrée, pour une donnée d'origine réseau. ⚠️ Ne pas la fusionner avec `tramesValidees()` : deux listes blanches distinctes, deux vocabulaires fermés distincts. |

⚠️⚠️ **M4 — la purge du rapport est CONDITIONNELLE, et c'est un correctif de perte de preuve.** Une purge
inconditionnelle à chaque `--auxlink-armer` effacerait les trames déjà collectées si la commande est
relancée à l'heure 5 d'une fenêtre de 24 h (relance manuelle, script de reprise, erreur) — alors même que
le démon, lui, fait **no-op** sur empreinte identique et ne réémettrait aucun rapport avant 60 s. Le
rapport resterait vide dans l'intervalle : **perte silencieuse de preuve, sur un dispositif dont la valeur
est précisément de préserver la preuve.** Règle : `armerSondeAuxlink()` lit le rapport en cache, compare
son `empreinte` à celle qu'elle vient de calculer, et **ne purge que si elles diffèrent**. Le rapport écrit
par le démon **doit donc porter son empreinte de campagne** — c'est ce qui rend la comparaison possible.

### 6.4 `core/class/smartclim.class.php` (ajouts seuls)

| Membre | Contrat |
|---|---|
| `public static armerSondeAuxlink($_duree = null, array $_hotes = array()): array` | Normalise la durée (bornes § 6.3), complète `$_hotes` par `hotesSondeAuxlink()` si vide, calcule l'empreinte de campagne, **purge conditionnellement** (M4), appelle `envoyerSondeAuxlink()`. Renvoie `array{lance:bool, duree:int, hotes:string[], motif:string}`. **Ne lève pas** (appelée par une CLI). |
| `public static desarmerSondeAuxlink(): bool` | `envoyerSondeAuxlink(array('actif' => false))`. |
| `public static diagnosticSondeAuxlink(): array` | ⚠️ **LECTURE SEULE, aucune émission réseau** (patron exact de `diagnosticTransport()` / `diagnosticRelaisAuxCloud()`). Renvoie `array{rapport:?array, age:?int, verdict:string, correspondances:array}` — `correspondances` rapproche chaque MAC observée d'un équipement par `macEquipement()`, ⚠️ **et la MAC inversée** (règle du plugin : les implémentations Broadlink de référence lisent des ordres d'octets opposés). |
| `private static hotesSondeAuxlink(): array` | Parcourt `eqLogic::byType('smartclim')`, collecte `adresseLan()['ip']`, valide par **`self::normaliserIpV4()`**, **borne à 4**. |

⚠️⚠️ **M2 — réutiliser `normaliserIpV4()`, ne PAS écrire une `estIpPriveeV4()`.** La méthode existe déjà
(`smartclim.class.php:2389`, `private static`, **même classe** donc aucun changement de visibilité), elle
valide **par octets** (jamais `ip2long()` — signé, et PHP est 32 bits sur armhf) et elle exclut en outre
le CGNAT `100.64.0.0/10`, le multicast et les plages réservées. Une troisième implémentation du même
problème dans le même fichier serait plus pauvre que celle déjà éprouvée.

### 6.5 Règle de verdict (`diagnosticSondeAuxlink()`) — mécanique et sans jugement

| Verdict | Condition |
|---|---|
| `non_arme` | aucun rapport en cache |
| `perime` | fenêtre non expirée mais `age > 3 × FRAICHEUR_SONDE` → le démon a redémarré, **réarmer** |
| `positif_confirme` | ≥ 1 **réponse** `auxlink` type `0x0003`, CRC valide, **MAC correspondant à un équipement** |
| `positif` | idem, MAC sans correspondance (un autre appareil du LAN parle AUXLink) |
| `piste_gagent` | 0 réponse `auxlink` mais ≥ 1 trame `gagent` **de sens `'reponse'`** (commande `0x68`) → **notre trame est probablement fausse** : ne pas conclure |
| `piste_requete` | 0 réponse, mais ≥ 1 **requête** (`auxlink` `0x0002` ou `gagent` `0x03`) d'une source **non locale** → un autre client sonde ce LAN (le téléphone ?) : signal faible, ne pas conclure |
| `piste_port` | 0 trame mais TCP 12416 `ouvert` → idem |
| `en_cours` | fenêtre non expirée, rien vu |
| `negatif_provisoire` | fenêtre expirée, 0 trame probante, port fermé ou non testé |

⚠️ **`negatif_provisoire` n'est jamais écrit nulle part par le programme.** La CLI imprime alors le
**rappel du palier 3** et s'arrête : le verdict définitif est une **décision humaine**, pas une sortie de
programme. C'est ce qui empêche le dispositif de conclure au-delà de ce qu'il a mesuré.

### 6.6 `core/php/pont-demon.php` (3 options ajoutées)

| Option | Émet ? | Rôle |
|---|---|---|
| `--auxlink` | **non** | Rapport d'état interne : verdict, âge, compteurs, trames (masquées), correspondances. Patron de `--transport` de `commande-lan.php`. `--brut` lève le masquage. |
| `--auxlink-armer [--duree=<s>] [--hote=<ip>]` | **oui** | Arme la campagne. ⚠️ **Imprime l'avertissement Docker** (m4) : si aucune interface non-loopback n'est détectable, ou si `/.dockerenv` existe, rappeler que sans `network_mode: host` la sonde est **structurellement aveugle** — 24 h de fenêtre perdue à mesurer un pare-feu de conteneur. |
| `--auxlink-desarmer` | **oui** | Arrête la campagne. |

⚠️ **Reste un aiguillage sans logique métier** : aucune construction de trame, aucun socket. Comme
`commande-lan.php` et `commande-auxcloud.php`.

## 7. Validation

| Entrée | Où | Barrière | Message |
|---|---|---|---|
| `--duree=<s>` | CLI **et** `armerSondeAuxlink()` — **double barrière assumée** (message utilisable côté CLI, barrière côté métier), même doctrine que `--intent` / `--parametre` | entier, `[60, 604800]`, sinon défaut | « Durée hors bornes, 86400 s retenu. » |
| `--hote=<ip>` | idem | `normaliserIpV4()` ; ≤ 4 hôtes ; ⚠️ **jamais de nom d'hôte** (aucune résolution DNS) | « Hôte ignoré : adresse non valide. » |
| Paquet de configuration reçu par le démon | `SondeAuxlink.configurer()` | `isinstance` sur chaque champ ; `duree` **re-bornée côté Python aussi** ; `hotes` re-filtrés. Forme invalide ⇒ `logging.warning` **sans recopier la charge**, et retour | — |
| Datagramme UDP reçu | `_boucle_ecoute` / `_analyser_trame` | ≤ 2048 o. ; longueur déclarée cohérente ; **CRC vérifié** ; ⚠️ `device_id` décodé en ASCII **strict** puis passé à `REGEX_DID` **avant toute journalisation** (injection de journal — leçon UC02/UC03) ; IP source validée en quadruplet pointé | — |
| Rapport reçu du démon | `enregistrerSondeAuxlink()` | `is_array` ; `famille` et `sens` ∈ listes blanches **fermées** ; `mac` `\A[0-9a-f]{12}\z` ; `hex` `\A[0-9a-f]{0,128}\z` ; `device_id` `\A[A-Za-z0-9_-]{1,64}\z` ; compteurs entiers bornés ; ≤ 20 trames ; horodatage **serveur** | `log::add('smartclim','warning','Sonde AUXLink : rapport de forme invalide, ignoré')` |

**Typage des exceptions : aucune `smartclimException` n'est levée dans ce cycle**, et c'est le même
arbitrage que l'UC02 — le contrat est **« statut + journal »**, pas « exception ». Les trois surfaces
d'appel l'exigent : une CLI a besoin d'un code de sortie, `jeeSmartclim.php` doit rester silencieux, et le
démon ne doit **jamais** mourir d'une sonde. Chaque méthode publique PHP ajoutée se clôt par un
`try/catch (Throwable)` dont le message passe par `smartclim::neutraliserPourLog()`, **jamais la trace**.
Côté Python, chaque boucle journalise `type(erreur).__name__` — **jamais `str(erreur)`**.

**Secrets : il n'y en a aucun dans ce cycle.** Aucun passcode n'est obtenu, aucune clé de session n'existe.
Le seul résiduel est que MAC et `device_id` observés sont des **identifiants** : `--auxlink` les masque par
**jetons stables** par défaut. ⚠️ **m2 — réutiliser `smartclimDiagnostic::jeton()`**, passée `private` →
`public` pour l'occasion, plutôt que d'écrire un **3ᵉ** mécanisme de jetons stables dans le plugin (leçon
« masquage par clé insuffisant » : un point d'implémentation unique). Élargir une visibilité n'introduit
aucune régression ; dupliquer un masquage, si.

⚠️ **Ne jamais committer une sortie `--brut`**, ni dans `.memory/` : `.htaccess` ne protège que l'accès web
d'une installation Jeedom, pas GitHub.

## 8. Dépendances & i18n

**Dépendances : aucune.** `socket`, `struct`, `threading`, `time`, `binascii`, `re` sont dans la
bibliothèque standard Python. `plugin_info/packages.json` n'est **pas** touché — donc **aucun
re-déclenchement du cycle d'installation sur tout le parc**, et aucun arbitrage `os.min` à rejouer.

**i18n : aucune chaîne.** Aucun champ de formulaire, aucune modale, aucun bouton, aucun
`launchable_message` nouveau, aucun libellé de commande. Les sorties de `pont-demon.php` sont en français
**sans `__()`** (convention des 5 CLI existantes) et les journaux du démon en français sans `__()`. La spec
fonctionnelle n'anticipe de chaînes (« Local (AUXLink) », « Session locale établie », « Appairage local »)
que sur le **chemin positif**, c'est-à-dire dans l'UC de transport — hors de ce cycle. **Le `translator`
n'a rien à faire sur ce cycle.**

## 9. Consignation du verdict (AC5 / AC6) — rédaction figée d'avance

À l'expiration de la fenêtre, **une seule** des deux branches est écrite dans un **§ 8 nouveau** de
`.memory/analyse/smartclim-ecosysteme-aux-broadlink.md`, intitulé « Verdict AUXLink sur l'appareil de
validation » :

- **Branche positive** — date, compteurs (réponses `auxlink` valides, MAC et `device_id` observés,
  correspondance d'équipement), **trame exacte qui a répondu**, et décision : « piste confirmée, UC de
  transport ouverte ». Puis § 7 : case `[x]` avec renvoi au § 8 ; § 4 : ligne de matrice « À confirmer sur
  le matériel » → **confirmé**. Ouvrir `06-transport-auxlink-local.md`.
- **Branche négative** — date, durée réelle d'observation, compteurs **à zéro**, **les trois paliers
  listés avec leur résultat**, dont celui du palier 3 (l'application pilote-t-elle sans Internet ?), et
  décision : « piste abandonnée ; l'appareil de validation reste durablement dépendant d'un cloud pour être
  piloté ». Puis § 7 : case `[x]` « **NON** » ; § 4 : ligne de matrice barrée pour cet appareil ; § 5
  point 3 amendé (« post-MVP à fort potentiel » → « écarté, cf. § 8 »).

⚠️ **Formulation à ne pas relâcher dans la branche négative** : le négatif porte sur **cet appareil**,
mesuré **par cet instrument**, pas sur « AUXLink n'existe pas ». Le plugin est multimarque ; un autre
module G3 peut parfaitement répondre.

Dans les deux branches, mettre à jour `.memory/analyse/INDEX.md` (§ 0 : « **Mon appareil G3 parle-t-il un
protocole LAN ?** → § 8 ») et le statut de la spec fonctionnelle.

## 10. Procédure de retrait (chemin négatif) — conçue pour être minimale

**Recommandation : conserver la sonde.** L'AC5 l'autorise explicitement (« au-delà de la sonde de
découverte elle-même ») ; elle est en lecture seule, opt-in par CLI, sans dépendance et sans chaîne UI, et
la doctrine multimarque du plugin (« le critère est le protocole joignable, jamais une liste de
références ») la rend utile à tout futur utilisateur d'un module G3.

La procédure est néanmoins figée d'avance, et **le code est conçu pour elle** : toute ligne AUXLink est
soit dans `sonde_auxlink.py`, soit dans un **bloc contigu encadré d'un commentaire d'ancrage**
`=== SONDE AUXLINK (UC04 post-mvp/05) — début/fin ===`.

⚠️ **Une exception, constatée en revue et assumée : `core/php/pont-demon.php`.** Le parsing des options
(`--auxlink*`, `--brut`, `--duree=`, `--hote=`) et la garde d'usage vivent dans des structures de contrôle
**partagées avec les options préexistantes** — on ne peut pas les encadrer sans encadrer aussi du code qui
n'a rien à voir avec AUXLink. Les deux blocs ancrés de ce fichier ne couvrent donc que les déclarations de
variables et le corps `armer`/`desarmer`/`diagnostic`. **Le retrait y demande un `grep -n auxlink
core/php/pont-demon.php`**, pas une simple suppression de blocs. C'est la seule entorse au « aucun
raisonnement » ci-dessous, et elle est bornée à un fichier d'aiguillage CLI.

Retrait = 7 gestes :

1. `git rm resources/smartclimd/sonde_auxlink.py` (l'`import` sous garde fait que le démon démarre encore
   sans lui) ;
2. retirer bloc d'import, `_sonde`, l'`elif`, la ligne de `shutdown()` et le paragraphe d'en-tête de
   `smartclimd.py` ;
3. retirer le 5ᵉ `if` de `jeeSmartclim.php` ;
4. retirer le bloc ancré de `smartclimDemon.class.php` ;
5. retirer le bloc ancré de `smartclim.class.php` ;
6. retirer les 3 options de `pont-demon.php` — **par `grep`, pas par blocs** (exception ci-dessus) ;
7. repasser `smartclimDiagnostic::jeton()` en `private` **si et seulement si** aucun autre appelant n'est
   apparu entre-temps.

Aucune migration de données : la clé de cache expire seule (7 j) et n'est lue nulle part ailleurs.

## 11. Risques

- **R1 — source unique pour le cadrage.** Le framing `a5a5` n'est attesté que par
  `latentharbor/ha-aux-a-plus` (dépôt récent, aucune corroboration indépendante). Les **ports**, eux, sont
  corroborés par Gizwits GAgent. Un négatif obtenu sur la seule variante `a5a5` **ne prouverait rien** —
  c'est la raison d'être de la seconde variante et de l'écoute passive. À rappeler dans le § 8 négatif.
- **R2 — Jeedom en conteneur Docker ⇒ le spike est structurellement aveugle.** Sans `network_mode: host`,
  le démon vit dans un réseau bridge : il ne recevra **jamais** une diffusion du LAN, et sa propre
  diffusion n'en sortira pas. ⚠️ **À vérifier AVANT d'armer** — sinon on produirait un négatif de 24 h qui
  ne mesure que le pare-feu du conteneur. Avertissement imprimé par `--auxlink-armer` (m4).
- **R3 — `255.255.255.255` sur hôte multi-interfaces.** La diffusion limitée peut sortir sur la mauvaise
  interface. Contournement : `--hote=<ip>` (unicast), qui ne dépend d'aucune diffusion.
- **R4 — auto-faux-positif.** Traité **au niveau du critère primaire** (§ 6.2), pas par le seul filtre
  d'adresse. À vérifier explicitement en revue de code.
- **R5 — la phase 2 introduira une dépendance pip, la phase 1 non.** Toute la session AUXLink est en
  **AES-CBC** et Python n'a pas d'AES en bibliothèque standard ⇒ `pycryptodome` ou `cryptography` dans
  `packages.json`. ⚠️ Le plancher se choisit contre **`os.min: 10`** (Debian 10 = **Python 3.7**),
  **jamais** contre la dernière version publiée — sinon `pip` échoue, le script généré par le core **n'a
  pas de `set -e`**, et l'installation repart **toutes les 5 minutes sans borne**. Coût à chiffrer
  **avant** d'ouvrir l'UC de transport, pas pendant.
- **R6 — la fenêtre ne survit pas à un redémarrage du démon** (`plugin::checkDeamon`, mise à jour market,
  `deamon_start` manuel). Détecté par le verdict `perime` ; l'opérateur réarme. Aucune persistance côté
  démon n'est ajoutée : elle exigerait un état sur disque pour un instrument temporaire.
- **R7 — trois AC sur six restent ouverts à la fin du cycle.** C'est la nature de l'UC, pas un défaut du
  plan ; mais le suivi de spec doit le refléter (§ 1).
- **R8 — le rapport contient MAC et `device_id`.** Masqué par défaut ; ne jamais coller la sortie `--brut`
  dans `.memory/` ni dans une issue.
- **R9 — ⚠️ fait de sécurité à consigner dès maintenant, même sur le chemin négatif.** Le contrat externe
  montre que le passcode d'appairage est **servi par l'appareil sur TCP 12416 sans aucune
  authentification** (`if passcode is None: sendall(PASSCODE_QUERY)`), et que la référence **accepte le
  timeout du `0x1007` comme un succès**. Corollaire : si la phase 2 est un jour livrée, **quiconque est sur
  le LAN pilote le climatiseur**, et l'AC4 (« secret chiffré par équipement ») protège le **stockage**,
  **pas l'accès**. À écrire dans l'analyse d'écosystème **quel que soit le verdict** — c'est un fait acquis
  sur le protocole, pas sur l'appareil.
- **R10 — aucune contrainte future n'est prise par ce cycle.** Le rapport est un format interne, la clé de
  cache n'est lue nulle part ailleurs, et aucun `logicalId`, transport, ni entrée de
  `smartclimCapabilities::tables()` n'est créé. Si l'UC de transport arrive, elle choisira librement son
  code de transport et son arbitrage dans `smartclimTransport` — domaine 02, hors périmètre ici.

## 12. Dette

*(section alimentée en fin de cycle par les findings de review n'atteignant pas la gate)*

## Sources

- [latentharbor/ha-aux-a-plus — `custom_components/aux_a_plus/lan.py`](https://github.com/latentharbor/ha-aux-a-plus) (MIT)
- latentharbor/ha-aux-a-plus — `docs/RESEARCH_PLAYBOOK.md`, `const.py`
- [Apollon77/node-ph803w — `PROTOCOL.md`](https://github.com/Apollon77/node-ph803w/blob/main/PROTOCOL.md) (Gizwits GAgent WLAN)
- [gizwits/Gizwits-GAgent — `gagent/include/gagent.h`](https://github.com/gizwits/Gizwits-GAgent)
