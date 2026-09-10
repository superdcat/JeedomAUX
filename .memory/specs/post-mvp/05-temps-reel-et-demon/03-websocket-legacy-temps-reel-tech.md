# Spec technique — UC03 « Temps réel du cloud historique via le relais WebSocket »

> **Domaine** : post-mvp/05-temps-reel-et-demon · **Spec fonctionnelle** :
> `03-websocket-legacy-temps-reel.md` · **Dépend de** : UC02 de ce domaine (socle démon) et UC03 du
> domaine post-mvp/03-cloud-aux-legacy (lecture/écriture des paramètres legacy).
>
> ⚠️ **Livrée NON RECETTÉE**, comme les trois UC du domaine 03 : aucun compte AC Freedom n'est
> disponible, et le climatiseur de validation ne parle pas ce protocole. Ce qui est vérifiable par
> lecture est distingué AC par AC au § 11 ; ce qui ne l'est pas est adossé aux deux instruments CLI
> `pont-demon.php --relais` et `--relais-sync`.

## 0. Ce que fait cette UC, en une phrase

Le démon Python tient **une** session WebSocket par compte legacy vers `apprelay/relayconnect`, s'abonne
aux appareils que le cycle legacy dessert déjà, et **relaie les événements bruts** vers Jeedom par le pont
HTTP livré à l'UC02 — tout le sens (décodage des paramètres, période de grâce, routage, affichage)
restant **en PHP**.

⚠️ **Le démon ne parle pas HVAC.** Il tient une socket, valide une forme, et transmet. Un décodeur Python
aurait été une divergence garantie avec `decoderParametres()` ; un second décodeur PHP aussi.

## 1. Contrats externes

**Sources** : `maeek/ha-aux-cloud` (**MIT**) — `custom_components/aux_cloud/api/aux_cloud_ws.py`,
`api/aux_cloud.py`, et surtout **`demo_ws.py`**, qui porte des **échantillons réels** de messages ; plus
une **sonde directe du relais EU exécutée le 2026-09-09** depuis le poste de développement.

### 1.1 Points de terminaison

| Région | Hôte du relais |
|---|---|
| `EU` | `wss://app-relay-deu-f0e9ebbb.smarthomecs.de/appsync/apprelay/relayconnect` |
| `USA` | `wss://app-relay-usa-fd7cc04c.smarthomecs.com/appsync/apprelay/relayconnect` |
| `CHN` | `wss://app-relay-chn-31a93883.ibroadlink.com/appsync/apprelay/relayconnect` |
| `RUS` | **aucun hôte relais connu** — `urlRelais()` renvoie `''` |

⚠️ **La région RUS n'est pas un oubli** : aucune des deux références ne publie d'hôte relais pour elle.
La synchro est alors sautée avec un log d'information, et **tout le reste du plugin est inchangé** — un
compte RUS continue de fonctionner en scrutation, exactement comme avant cette UC.

**TLS** : `ssl_verify_result=0` **mesuré** sur EU et USA (`curl` sans `-k`). CHN est injoignable depuis
le poste de dev (TCP refusé) — non concluant, ni dans un sens ni dans l'autre. La règle projet « TLS
toujours vérifié » ne coûte donc **rien** ici, contrairement au broker MQTT AUX Home du spike UC01, dont
le certificat était expiré et ne couvrait pas son propre nom d'hôte. Les trois références désactivent la
vérification (`ssl=False`) : **écart délibéré et gratuit**.

### 1.2 Handshake — mesuré

La sonde a émis **exactement** : `Host`, `Upgrade`, `Connection`, `Sec-WebSocket-Key`,
`Sec-WebSocket-Version`, `Origin`. Aucun en-tête applicatif, **aucun `loginsession` ni `userid`**.
Réponse : `101 Switching Protocols`, puis
`{"msgtype":"initk","status":-66001,"msg":"initialize request parameters invaild","scope":{"loginsession":"","userid":""}}`,
puis une close 1000 émise par le serveur.

**Ce que cela prouve** : l'upgrade n'exige aucun en-tête applicatif ; le backend parse bien l'`init`,
**relit le `scope` et le renvoie en écho** ; le libellé du refus désigne les *paramètres de l'init*, pas
un en-tête manquant.
**Ce que cela ne prouve pas** : `-66001` est un code générique — on ne peut pas établir qu'un `scope`
**valide** seul suffit, faute de compte. Et l'absence d'`Origin` n'a **pas** été testée.

### 1.3 Séquence applicative

| Étape | Message |
|---|---|
| 1. `init` | `{"msgtype":"init","data":{"relayrule":"share"},"scope":{loginsession, userid},"messageid":"<epoch>000"}` |
| 2. `initk` | `{"msgtype":"initk","messageid":<echo>,"topic":"","status":0 ou négatif,"msg":"…","scope":{…}}` |
| 3. **`sub`** | `{"msgtype":"sub","topic":"devpush","messageid":…,"data":{"devList":[{endpointId, devSession, pid, gatewayId:""}]}}` |
| 4. maintien | `{"msgtype":"ping","messageid":…}` toutes les **10 s** → `pingk` porteur d'un `status` |
| 5. événement | `push` / `devpush` (cf. 1.4) |

⚠️⚠️ **Le message `sub` est ABSENT de `.memory/analyse/smartclim-transport-aux-cloud-legacy.md` § 6, et
il est dirimant** : sans lui, la session s'établit, le relais la confirme, et **aucun événement n'arrive
jamais**. C'est le pivot de toute l'UC (cf. R1).

⚠️ **`sub.pid` = `productId` = le champ déjà exposé par `appareilAuxCloud()` sous le nom
`type_produit`.** Concordance vérifiée dans le code : `cookieMappe()` fait `'pid' => $_brut['productId']`
(l. ~1210) et `appareilAuxCloud()` expose ce même champ sous `type_produit` (l. ~5684-5705). Écrit noir
sur blanc parce qu'une erreur ici rend l'abonnement **muet, sans aucun signal**.

⚠️ **Effet du `sub`, et c'est la brique sur laquelle repose tout l'AC6** : commentaire de la référence —
« *Subscribe to device updates and fetch the history of the device (last state of parameters)* » — donc
un abonnement réussi produit **au moins un push immédiat**. Source unique, à confirmer en recette.

⚠️ **La référence ne réémet PAS les `sub` après une reconnexion** (elle ne rejoue que l'`init`), et ne
traite **aucun `subk`** : aucun accusé de réception n'est documenté. **Divergence assumée** : notre démon
réémet systématiquement le `sub` après chaque `initk` réussi — sans quoi une reconnexion silencieuse
laisserait la session vivante et muette.

### 1.4 Événement poussé — échantillon réel

```json
{"msgtype":"push","topic":"devpush","messageid":…,"scope":{},
 "data":{"endpointId":"…","data":"<base64>",
         "payload":{"change":{"cause":{"msgtype":48}},"data":"<hex>"}}}
```

Les deux champs portent le **même JSON** :
`{"ac_mode":0,"ac_mark":1,"temp":250,"pwr":0,"childlock":0,"scrdisp":0,"ac_vdir":0,"ac_hdir":0,…}` —
soit **exactement les clés de `parametresAuxCloud()`**. Le « À confirmer » n° 1 de la spec fonctionnelle
est donc **largement refermé** : la structure d'un événement de changement d'état réel est établie par
un échantillon, pas déduite.

⚠️ **Piège du champ base64** : il décode en `{…}` **suivi de `"` et `\n`** (l'échantillon se termine par
`…fSIK`), donc `json_decode` échoue tel quel. Le champ **hex** de `payload.data`, lui, est propre — d'où
la priorité donnée à l'hexadécimal, le base64 n'étant qu'un repli tolérant.

⚠️ **Absents du push observé** : **pas d'`envtemp`**, pas de statut en ligne/hors ligne. Conséquence
directe sur le choix de cadence (§ 3) et sur R4. Échantillon unique — ne pas en conclure qu'ils
n'existent jamais.

### 1.5 En-têtes du handshake — arbitrage

La référence envoie **11 en-têtes** : `aux_cloud_ws.py` fait
`await session.ws_connect(url, headers=self.headers, ssl=False)`, et `initialize_websocket()` construit
`headers=self._get_headers(CompanyId=COMPANY_ID, Origin=self.url)`, où `_get_headers()` rend
`Content-Type, licenseId, lid, language, appVersion, User-Agent, system, appPlatform, loginsession,
userid`. Ce n'est donc **pas une extrapolation** : la référence réemploie son helper REST tel quel pour
le WebSocket, plus deux kwargs. `CompanyId` et `Origin` n'existent **que** sur l'appel WS — d'où leur
absence légitime de notre `requete()`.

**Décision (arbitrée avec l'utilisateur, 2026-09-09) : `loginsession` et `userid` sont RETIRÉS des
en-têtes ; les 10 autres sont conservés** (`Content-Type: application/x-java-serialized-object`,
`licenseId`, `lid`, `language`, `appVersion`, `User-Agent`, `system`, `appPlatform`, `CompanyId`,
`Origin`).

**Motif décisif — les deux modes de défaillance ne sont pas symétriques** : un secret dupliqué en
en-tête HTTP fuit **en silence** dans des journaux qu'on ne contrôle pas (reverse-proxy, load-balancer,
et le message d'exception de `websocket-client`, qui peut porter la liste d'en-têtes) ; un refus du
relais, lui, est **bruyant** — `initk status != 0` est journalisé à chaque tentative et remonte en
`etat: 'refus'` dans le battement, donc dans `--relais` et dans le bandeau d'état de la page.
L'authentification est in-band (`init.scope`), **mesurée suffisante pour atteindre le parsing de
l'init**.

⚠️ **Réserve honnête, à ne pas gommer** : ce raisonnement ne couvre **pas** le cas où l'`init` passerait
et où le **`sub`** serait refusé faute de ces en-têtes — refus dont la référence prouve qu'il est
**silencieux**. La conséquence reste sûre (aucun push ⇒ aucun endpoint « confirmé » ⇒ la scrutation reste
à 900 s, rien ne se dégrade au-delà de l'absence de temps réel), mais elle est **muette**.

**Deux exigences d'implémentation qui rendent l'écart réversible en une ligne** :
1. les deux entrées figurent dans la table de `enTetesRelais()` **en commentaire**, avec le symptôme qui
   justifierait de les rétablir — jamais supprimées sans trace ;
2. `relais_auxcloud.py` ne journalise **jamais** la liste d'en-têtes, y compris dans un message
   d'exception (`str(e)` neutralisé sur le modèle de `jeedom_com`).

### 1.6 Bibliothèque

`websocket-client` **1.6.1** : `Requires-Python >=3.7`, licence Apache-2.0, **aucune dépendance
d'exécution**. Vérifié sur l'API PyPI (1.6.1 → 1.7.0) : **1.6.2 et au-delà exigent >= 3.8**.

⚠️ **1.6.1 est le plafond imposé par `os.min: 10`** (Debian 10 = Python 3.7), exactement la même
contrainte que `requests` 2.31.0. Ne pas « moderniser » sans relire le § 9 R3 de la spec technique
d'UC02 : le script généré par le core n'a **pas** de `set -e`, donc un pip qui échoue relance
l'installation **toutes les 5 minutes, sans borne**.

**Option « frames à la main » écartée** : elle a été exercée pour de vrai lors de la sonde et fonctionne
sur le chemin nominal, mais fragmentation, frames de contrôle entrelacées et handshake de fermeture sur
une connexion **permanente** et **non recettable** ne valent pas l'économie d'une ligne de
`packages.json`. Le précédent MQTT du spike UC01 ne s'applique pas : il s'agissait là d'une connexion
**ponctuelle** dans un cycle de cron.

## 2. Architecture — fichiers

| Chemin | Action | Ce qui y entre | Indentation / EOL |
|---|---|---|---|
| `plugin_info/packages.json` | modifié | section `pip3` : `"websocket-client": {"version": "1.6.1"}` — version dans la **valeur**, exacte, sans opérateur | 2 espaces, LF |
| `resources/smartclimd/relais_auxcloud.py` | **créé** | toute la session relais : import sous garde, thread de session, init/ping/sub, backoff, battement, transfert des push | 4 espaces, LF dans l'index |
| `resources/smartclimd/smartclimd.py` | modifié | import sous garde, branche `cmd == 'auxcloud_relais'`, signal `pont::demarre`, arrêt du relais dans `shutdown()`, bloc d'en-tête FR complété | 4 espaces, LF (index) |
| `core/class/smartclimDemon.class.php` | modifié | `envoyerRelais()`, `enregistrerBattement()`, `battementRelais()`, constantes — **et retouche de `envoyer()`** (§ 6.1) | 2 espaces, CRLF |
| `core/php/jeeSmartclim.php` | modifié | **3 branches `if` indépendantes** (§ 4.2) | 2 espaces, CRLF |
| `core/class/smartclimAuxCloudApi.class.php` | modifié | colonne `relais` dans `regions()`, `urlRelais()`, `enTetesRelais()`, `valeursDepuisPush()` | 2 espaces, CRLF |
| `core/class/smartclim.class.php` | modifié | constantes, 4 méthodes publiques, 4 privées de cadence, `diagnosticRelaisAuxCloud()`, +1 clé dans les **7 branches** de `etatConnexionAffichable()`, +1 garde dans `rafraichirAuxCloud()`, **4e bloc** dans `cron()`, appel dans `preRemove()` | 2 espaces, CRLF |
| `core/php/pont-demon.php` | modifié | usages `--relais` (rapport, aucune émission) et `--relais-sync` (émet) | 2 espaces, CRLF |
| `desktop/php/smartclim.php` | modifié | 1 ligne : libellé `{{Temps réel}}` + `<span id="span_etatConnexionTempsReel">` | ⚠️ **tabulations**, CRLF |
| `desktop/js/smartclim.js` | modifié | 2 lignes : affectation + reset dans la branche « pas d'état » | 2 espaces, CRLF |

**Sans objet, explicitement** — vérifié, pour qu'on ne les « complète » pas par précaution :
`core/php/smartclim.inc.php` (**aucune classe PHP nouvelle**) ; `core/php/.htaccess`
(`jeeSmartclim.php` déjà en liste blanche, `pont-demon.php` déjà protégé par le `Deny from all`) ;
`resources/.htaccess` et `resources/smartclimd/.htaccess` (couvrent déjà le nouveau `.py`) ;
`plugin_info/configuration.txt`/`.php` (**aucune clé de config plugin nouvelle**, donc **aucun `cp` de
miroir**) ; `core/config/smartclim.config.ini` ; `plugin_info/info.json` (`hasOwnDeamon` et
`hasDependency` déjà à `true` ; ⚠️ ne pas toucher `pluginVersion`, le hook `pre-commit` s'en charge) ;
`smartclimFrame`, `smartclimCapabilities`, `smartclimTransport`, `smartclimBroadlinkLan`,
`smartclimAuxHomeApi`, `smartclimDiagnostic` ; `core/ajax/smartclim.ajax.php` ; `core/i18n/*.json`
(traduction différée).

## 3. Server vs Client — où vit quoi

| Responsabilité | Où | Pourquoi |
|---|---|---|
| Socket WebSocket, keepalive, reconnexion | **démon Python** | c'est un processus long ; PHP ne peut pas le tenir |
| Authentification legacy, jetons d'appairage | **PHP** | `session()` et `appareilAuxCloud()` existent déjà, avec leur cache chiffré. Le démon ne reçoit que des jetons, jamais des identifiants |
| Décodage des paramètres HVAC | **PHP** | `decoderParametres()` existe et est recetté sur le chemin de scrutation. Le dupliquer en Python garantirait la divergence |
| Période de grâce (AC5) | **PHP** | `filtrerEtatSelonOrdres()`, déjà livrée à l'UC07 du MVP |
| Routage vers l'équipement | **PHP** | `indexerEquipements()['parEndpointAuxCloud']` existe déjà |
| Décision de cadence (AC6) | **PHP** | lecture d'un cache, jamais un appel au démon (§ 5.4) |

**Cadence du filet quand le push est actif : `INTERVALLE_FILET_AUXCLOUD` = 3600 s** (×4 par rapport aux
900 s du cycle legacy). Arbitré avec l'utilisateur le 2026-09-09, contre l'option 1800 s.
**Motif** : `envtemp` est déjà, sur ce transport, une donnée dont l'existence même n'est pas garantie
(cf. R2 d'UC03 du domaine 03 : le second `get` n'est pas émis) ; et la fenêtre d'aveuglement après une
mort **silencieuse** du démon reste bornée à `FRAICHEUR_RELAIS + INTERVALLE_CYCLE_AUXCLOUD` ≈ **18 min**,
indépendamment de ce réglage — c'est le battement qui borne le risque, pas la cadence du filet.

## 4. Validation

### 4.1 Table des barrières

| Entrée | Barrière | Conséquence si invalide |
|---|---|---|
| Corps du rappel HTTP | `is_array(json_decode(...))` puis `isset()` par branche | **sortie silencieuse** (patron du pong d'UC02) |
| `endpointId` venu du relais | `/\A[A-Za-z0-9_-]{1,64}\z/` **côté Python avant `add_changes`** *et* **côté PHP avant tout usage** | rejeté + log. ⚠️ Défense en profondeur : **ne supprimer ni l'une ni l'autre** comme redondante |
| `endpointId` inconnu du parc | recherche dans `indexerEquipements()['parEndpointAuxCloud']` | ignoré, log `debug` **sans l'identifiant complet** |
| Charge du push | hex prioritaire, base64 en repli tolérant (trim des `"`/blancs finaux), ≤ 64 clés, noms `/\A[a-z_][a-z0-9_]{0,31}\z/` | `array()` + `warning` portant **msgtype/topic/longueur seulement**. ⚠️ **La session n'est PAS fermée** et la commande info n'est pas touchée |
| Valeur de paramètre | `decoderParametres()` **existante** : `null` ⇒ **clé absente** ; `temp`/`envtemp` sous garde de plausibilité 50..500 | inchangé — un push aberrant ne peut produire ni « 2,4 » ni « 240 » |
| Battement | `etat` dans une liste blanche **fermée** `{connecte, reconnexion, refus}` ; listes d'endpoints validées et **bornées à 200** ; horodatage **serveur** | rejeté. ⚠️ Le démon ne dicte **jamais** une chaîne affichée : PHP mappe un code vers un littéral |
| Taille du message socket | `readline(TAILLE_MAX_MESSAGE)` = 65536, déjà en place | ⇒ ~200 appareils max par paquet ; au-delà la ligne est rejetée et journalisée |
| Trame WebSocket entrante | taille max applicative 64 Kio **avant** parsing | ignorée + `warning` |

⚠️ **L'`endpointId` doit refuser `::`** : la clé passée à `add_changes()` est arborescente
(`auxcloud::push::<id>`), un `::` dans l'identifiant casserait l'arborescence côté `merge_dict()`.

### 4.2 Dispatch dans `jeeSmartclim.php` — trois `if` INDÉPENDANTS

```
auxcloud.push    -> smartclim::appliquerPushAuxCloud()
auxcloud.relais  -> smartclimDemon::enregistrerBattement()
pont.demarre     -> smartclim::invaliderSyncRelais()
```

⚠️⚠️ **Jamais `elseif`.** Le fichier ne porte aujourd'hui qu'une seule branche (`pont.pong`), donc le
style naturel de continuation serait un `elseif` — et ce serait un **défaut silencieux** : le thread de
battement (60 s) et le thread de push écrivent tous deux dans `self._changes`, fusionnés par
`merge_dict()` avant le POST du cycle de 0,5 s. Un même corps peut donc légitimement porter
`auxcloud::relais` **et** `auxcloud::push::<id>` ; un `elseif` en perdrait un **sans aucune trace**,
violant ponctuellement l'AC2.

⚠️ Ce point d'entrée reste **sans aucun appel réseau et sans écriture de configuration** : il valide,
écrit un cache ou appelle `appliquerEtat()`, et rend la main.

## 5. Signatures

### 5.1 `resources/smartclimd/relais_auxcloud.py` (créé)

Identifiants et journaux **en français**, comme le reste du portage.

| Membre | Contrat |
|---|---|
| `RelaisAuxCloud(jeedom_com)` | détient l'unique session. Aucun décodage HVAC, aucune connaissance des concepts génériques |
| `configurer(paquet: dict) -> None` | appelée depuis `read_socket()`. Valide la forme ; **empreinte identique et thread vivant ⇒ no-op** (ne pas se déconnecter toutes les 10 min) ; `appareils` vide ⇒ ferme la session ; sinon (re)démarre le thread |
| `arreter() -> None` | fermeture propre (close 1000), appelée par `shutdown()` |
| `_boucle_session()` (thread) | `create_connection(url, header=…, timeout=10)` → `init` → attente `initk` (`status == 0`, sinon fermeture + backoff) → **`sub`** → boucle : `recv` avec timeout 1 s, `ping` applicatif toutes les **10 s**, `pingk` attendu sous `TIMEOUT_PONG` = **30 s**. Backoff **10 → 20 → … → 300 s**, remis à 10 s après une session stable > 60 s |
| `_traiter(message: dict)` | `msgtype == 'push'` **et** `topic == 'devpush'` **et** `endpointId` conforme ⇒ `add_changes('auxcloud::push::<endpointId>', enveloppe)` + inscription de l'endpoint dans `confirmes`. Tout autre `msgtype` ⇒ `logging.info` du **seul couple** `msgtype`/`topic`, **jamais la charge** |
| `_battement()` | toutes les 60 s tant que le thread vit : `add_changes('auxcloud::relais', {'etat', 'abonnes', 'confirmes', 'empreinte'})` |

⚠️ **`import websocket` est sous `try/except ImportError`** : dépendance pas encore installée ⇒ relais
désactivé + un `logging.error` **une seule fois**, et le démon (donc `ping`/`pong`, donc l'AC7) continue
de fonctionner normalement. C'est la leçon d'`import pyudev` du gabarit officiel, qui n'était pas déclaré
dans son propre `packages.json`.

⚠️ **Jamais `sslopt={'cert_reqs': CERT_NONE}`, jamais `enableTrace(True)`** — le trace écrirait le
contenu des messages, donc le `loginsession`, dans le journal.

⚠️ **Le journal doit dire explicitement « abonnement envoyé pour N appareil(s) »**, puis nommer chaque
`msgtype` reçu. Sans cela, un `sub` refusé serait totalement silencieux (cf. R1).

### 5.2 `smartclimDemon`

Constantes : `CLE_CACHE_RELAIS = 'smartclim::relais_auxcloud'`, `DUREE_MEMOIRE_RELAIS = 300`,
`FRAICHEUR_RELAIS = 180`.

| Méthode | Contrat |
|---|---|
| `public static envoyerRelais(array $_relais): bool` | `envoyer(array('cmd' => 'auxcloud_relais', 'relais' => $_relais))`. **Ne lève pas** |
| `public static enregistrerBattement($_battement): void` | appelée **uniquement** par `jeeSmartclim.php` ; valide la forme (§ 4.1) **avant** `cache::set` ; ajoute `ts` **serveur**, jamais l'horodatage du démon |
| `public static battementRelais(): ?array` | lecture **validée** ; renvoie `null` plutôt qu'un contenu forgé — patron `incidentMemorise()` |
| `public static envoyer(...)` **modifiée** | ⚠️ cf. § 6.1 |

### 5.3 `smartclimAuxCloudApi`

| Méthode | Contrat |
|---|---|
| `regions()` **étendue** | +1 colonne `'relais'` (URL `wss://…` complète, `''` pour RUS). L'invariant « ajouter une région = ajouter une entrée » est préservé |
| `public static urlRelais(): string` | URL du relais de la région configurée, `''` si aucune. ⚠️ **Entièrement issue de la table serveur** — aucune entrée utilisateur n'atteint jamais le démon |
| `public static enTetesRelais(array $_session): array` | les **10** en-têtes retenus au § 1.5. Les deux entrées retirées y figurent **en commentaire**, avec leur symptôme |
| `public static valeursDepuisPush(array $_enveloppe): array` | enveloppe → `payload.data` (hex, **prioritaire**) sinon `data.data` (base64, trim) → JSON → map `nom => scalaire` filtrée. **Ne lève JAMAIS** |

⚠️ **`decoderParametres()` et `etatAppareil()` ne sont PAS touchées.** `smartclim` appelle
`etatAppareil(array('valeurs' => …, 'swing_inverse' => …))`, **déjà publique**, qui délègue au décodeur
existant.

⚠️ **Le push ne pose JAMAIS `online`** : le `sub` rejoue le dernier état connu, on ne sait donc pas
distinguer un événement vif d'un rejeu d'historique. La joignabilité reste au `querystate` du cycle.

### 5.4 `smartclim`

Constantes : `CLE_CACHE_DERNIER_SYNC_RELAIS = 'smartclim::dernier_sync_relais'`,
`INTERVALLE_SYNC_RELAIS = 600`, `BUDGET_SYNC_RELAIS = 10`,
`CLE_CACHE_DERNIER_FILET_AUXCLOUD = 'smartclim::dernier_filet_auxcloud'`,
`INTERVALLE_FILET_AUXCLOUD = 3600`.

| Méthode | Contrat |
|---|---|
| `public static synchroniserPushAuxCloud($_force = false): array` | **Le 4e bloc de `cron()`.** Gardes dans cet ordre : `compteAuxCloudConfigure()` → `urlRelais() !== ''` → `smartclimDemon::etat()['state'] === 'ok'` → **marqueur d'échéance posé APRÈS les gardes et AVANT tout appel réseau**. Cible = équipements activés vérifiant `smartclimTransport::lectureLegacyAutorisee()` — **le même filtre que le cycle**. Cible vide ⇒ paquet vide envoyé (le démon ferme), **sans login**. Sinon `session()` + `appareilAuxCloud()` par équipement, `empreinte = sha1(json du paquet)`, `smartclimDemon::envoyerRelais()`. **Ne lève JAMAIS** |
| `public static appliquerPushAuxCloud(array $_pushs): int` | routage par l'index ; équipement désactivé ou `!lectureLegacyAutorisee()` ⇒ **ignoré** (log debug) ; sinon `valeursDepuisPush()` → `etatAppareil()` → **`appliquerEtat($etat)` sans second argument**. `try/catch (Throwable)` **par push** |
| `public static invaliderSyncRelais(): void` | `cache::delete()` du marqueur de synchro. **Aucun réseau** — c'est tout l'effet du signal `pont.demarre`, et c'est aussi ce qu'appelle `preRemove()` |
| `public function pushAuxCloudActif(array $_battement = null): bool` | prédicat **pur** : battement non nul, âge < `FRAICHEUR_RELAIS`, `etat === 'connecte'`, et `auxcloud_endpoint_id` présent dans `confirmes` |
| `private static syncRelaisEchu()` / `marquerSyncRelais()` / `filetAuxCloudEchu()` / `marquerFiletAuxCloud()` | jumeaux **exacts** de `cycleLanEchu()`/`marquerCycleLan()` : marqueur dédié, horloge reculée neutralisée |
| `public static diagnosticRelaisAuxCloud(): array` | surface unique de la CLI (patron `diagnosticTransport()`) : état et âge du battement, abonnés, confirmés, et par équipement legacy : endpoint, push actif, cadence effective. **Aucune émission réseau** |

⚠️ **AC5 est tenu ici, et nulle part ailleurs** : `appliquerEtat($etat)` appelée **sans** `$_optimiste`
⇒ `$_optimiste = false` ⇒ `filtrerEtatSelonOrdres()` s'applique, exactement comme pour le cron legacy.
**Aucune ligne neuve** n'est écrite pour cet AC ; ne pas « renforcer » ce chemin.

**`rafraichirAuxCloud()` modifiée** — le battement est lu **UNE SEULE FOIS** en tête de fonction, à côté
du calcul de `$filetEchu` :

```
$filetEchu  = self::filetAuxCloudEchu();   // marqué s'il l'est
$battement  = smartclimDemon::battementRelais();
…
if (!$filetEchu && $eqLogic->pushAuxCloudActif($battement)) { log debug; continue; }
```

⚠️ **Une lecture, pas N.** `CLE_CACHE_RELAIS` est **globale au compte** (une seule session WS pour tout
le parc legacy) : la relire par équipement dans la boucle serait N lectures de la même ligne de cache par
cycle. L'idiome à suivre est déjà dans cette fonction, avec `$filetEchu`.

**`cron()` modifiée** — **4e bloc**, avec sa **propre** garde d'échéance et son **propre**
`try/catch (Throwable)`. ⚠️ **Jamais de `return`** dans l'un des quatre blocs : il court-circuiterait les
suivants.

**`etatConnexionAffichable()` modifiée** — +1 clé `tempsReel`, **calculée une fois** et reportée dans les
**7** branches de retour (désactivé / compte non configuré / `online = true` / incident non-réseau /
incident réseau / `online = false` / `online = null`). ⚠️ Une branche oubliée produit `undefined` côté
JS, d'où le garde-fou côté client.

**`preRemove()` modifiée** — appelle `invaliderSyncRelais()`. Motif : un équipement supprimé resterait
sinon abonné côté démon jusqu'au prochain tick de synchro (≤ 600 s). Le chemin « endpointId inconnu du
parc ⇒ ignoré » couvre déjà la **sûreté** ; cet appel ferme la **fenêtre**, pour le coût d'un
`cache::delete()`.

## 6. Sécurité — secrets et classement des erreurs

### 6.1 Fuite préexistante corrigée par cette UC

⚠️⚠️ **`smartclimDemon::envoyer()` journalise aujourd'hui `json_encode($ordreSansCle)` en `debug`** —
c'est-à-dire le message complet, seule l'`apikey` étant retirée. Vérifié sur le code actuel. Dès que le
paquet relais transitera par cette méthode, elle écrirait le **`loginsession`** et **tous les
`devSession`** en clair dans `log/smartclim`.

**Correction** : journaliser `cmd` + le **nombre d'octets**, jamais le contenu. C'est une correction de
sécurité sur du code livré à l'UC02, pas une nouveauté de celle-ci.

### 6.2 Ce que le démon détient

`loginsession`, `userid`, et les `devSession` — **jamais** l'e-mail, **jamais** le mot de passe,
**jamais le `cookie`** (base64 portant la clé AES d'appairage). Le message `sub` n'en a pas besoin :
c'est un **fait de protocole**, pas une précaution prise en plus.

Points de fuite passés en revue : `read_socket()` ne recopie pas la charge (déjà le cas),
`handle()` ne journalise que la longueur (déjà le cas), `relais_auxcloud.py` ne journalise **ni l'URL
avec ses en-têtes, ni le contenu d'un message, ni la liste d'en-têtes dans une exception**, et
`enableTrace` reste désactivé. Le battement remonté par le démon ne porte **aucun** secret : un code
d'état, deux listes d'identifiants d'endpoint et une empreinte.

### 6.3 Classement des erreurs

**Cette UC ne lève AUCUNE `smartclimException` nouvelle.** `synchroniserPushAuxCloud()` et
`appliquerPushAuxCloud()` suivent le contrat « **statut + journal** » d'UC02 : `try/catch (Throwable)`
clôturant, message passé par `neutraliserPourLog()`, **jamais la trace**. Les exceptions de `session()`
et `appareilAuxCloud()` sont **rattrapées** dans le bloc de synchro.

Conséquence voulue : un relais en panne ne casse **ni** le cron, **ni** une commande, **ni** une page.

## 7. Impact i18n (français uniquement à l'implémentation)

`core/class/smartclim.class.php`, littéraux dans `etatConnexionAffichable()` :
`__('Temps réel actif', __FILE__)`, `__('Temps réel en attente de confirmation', __FILE__)`,
`__('Reconnexion au relais en cours', __FILE__)`, `__('Connexion au relais perdue', __FILE__)`.

`desktop/php/smartclim.php` : `{{Temps réel}}`.

Rien d'autre : sorties CLI en FR **sans `__()`** (convention des 5 CLI existantes), journaux FR sans
`__()`, journaux du démon FR sans i18n, **aucune** clé `configKey`. Les `core/i18n/*.json` sont remplis
en fin de cycle par le sous-agent `translator`, sur le code figé.

## 8. Périmètre

**Dans** : session WebSocket et son maintien, abonnement, reconnexion temporisée, transfert des
événements, application de l'état avec période de grâce, espacement du cycle legacy sur preuve de push,
affichage de l'état temps réel, deux usages CLI de diagnostic.

**Hors** : le pilotage par commandes du transport legacy (UC03 du domaine 03, **inchangé**) ; un canal
de push sur AUX Home (conditionné par UC01 de ce domaine, dont le verdict est négatif à ce jour) ;
AUXLink (UC04) ; le socle du démon lui-même (UC02).

## 9. Risques

- **R1 — le contrat du `sub` repose sur une SOURCE UNIQUE, et c'est le pivot de l'UC.** Sans lui :
  session établie, relais qui confirme, **zéro événement**. Et son refus est silencieux (aucun `subk`
  traité par la référence, aucun ack documenté). D'où l'exigence de journalisation du § 5.1. **Premier
  point de recette communautaire.**
- **R2 — budget cumulé de `plugin::cron`** : 25 (`BUDGET_SCAN`) + 18 (`BUDGET_LAN`) + 20
  (`BUDGET_CYCLE_AUXCLOUD`) + 10 (`BUDGET_SYNC_RELAIS`) = **73 s**, sous les 120 s du `setTimeout(2)`
  de la tâche core — qui exécute les crons de **tous** les plugins. En régime établi la synchro coûte
  **zéro requête** (session en cache 1800 s et jetons en cache 1800 s, tous deux > la cadence de 600 s) :
  le coût réel n'existe qu'au démarrage à froid.
- **R3 — la reconnaissance « push confirmé » dépend du rejeu d'état au `sub`.** Si le relais ne rejoue
  pas, un équipement stable n'est **jamais** confirmé et **AC6 ne s'active pas**. Dégradation *sûre* (on
  reste à 900 s), jamais dangereuse. C'est le sens du choix : on n'espace que sur **preuve reçue**, pas
  sur une intention d'abonnement.
- **R4 — le push observé ne porte pas `envtemp` ni `online`.** Espacer à 3600 s espace donc aussi leur
  fraîcheur. Arbitré au § 3.
- **R5 — session legacy refusée par le relais : récupération en ~40 min, pas immédiate.** Aucune purge de
  `smartclim::session_auxcloud` n'est déclenchée depuis le battement — elle serait pilotée par une donnée
  venue du réseau. La sortie se fait par l'expiration naturelle (1800 s) + le tick de synchro (600 s).
  ⚠️ **Écart assumé** vis-à-vis de la leçon « une TTL absolue n'est pas une invalidation » : ici la
  conséquence est **bornée et non bloquante** (le cycle HTTP continue de tout servir), là où le cas
  d'UC03 du domaine 03 rendait lecture *et* commande impossibles. À reprendre en dette si la recette
  montre des refus fréquents.
- **R6 — un 2e paquet pip re-déclenche un cycle d'installation sur tout le parc** (cf. UC02 R1/R3), et
  `1.6.1` est le plafond imposé par `os.min: 10`. La question `os.min` 10 → 11 (dette D-05-02-06) en
  devient plus saillante.
- **R7 — le pont reste mono-thread** (dette D-05-02-04, explicitement « à rouvrir à UC03 »). **Réexaminé
  ici et NON rouvert** : le seul émetteur vers le socket reste PHP (≤ 1 message / 600 s), et le trafic
  massif circule dans l'**autre** sens (démon → HTTP), qui ne passe pas par le `TCPServer`. Aucune
  concurrence nouvelle sur la file `JEEDOM_SOCKET_MESSAGE`.
- **R8 — collision de session avec l'application constructeur : inconnue.** Le relais est un canal de
  diffusion (`relayrule: "share"`), pas une session exclusive comme le LAN Broadlink — rien n'indique
  une éviction, rien ne la réfute. À observer en recette.
- **R9 — CHN injoignable depuis le poste de dev ; RUS sans hôte relais connu.** Traité au § 1.1.
- **R10 — livré non recetté.** Cf. § 11.

## 10. Dépendances

`plugin_info/packages.json`, section `pip3` : **`"websocket-client": {"version": "1.6.1"}`**.

⚠️ Rappels des pièges du format, coûteux à redécouvrir : la version va dans la **valeur**, jamais dans la
clé (le core compare la clé nue à `pip list`) ; **jamais** de `<`/`>` dans le champ `version`
(redirection shell) ; toujours une version **exacte**.

## 11. Recette

**Vérifiable par lecture, sans compte ni matériel** : AC5 (le mécanisme de grâce est inchangé et
démontrable ligne à ligne), AC7 (supprimer le 4e bloc de `cron()` rend exactement le comportement
d'avant l'UC), et la structure d'AC3/AC4.

**Exerçable sans compte legacy** :
- démon démarré sans `websocket-client` installé ⇒ relais désactivé, un seul `logging.error`, le
  `ping`/`pong` du pont continue de répondre (`pont-demon.php --ping` sort en 0) ;
- réseau coupé ⇒ le démon boucle sur l'échec d'`init` avec un backoff visible dans le journal ;
- `pont-demon.php --relais` ⇒ rapport cohérent avec un battement absent ou en `refus` ;
- compte legacy non configuré ⇒ le 4e bloc de `cron()` sort sur sa première garde, sans login.

**Exige un compte AC Freedom (recette communautaire)** : AC1, AC2, AC6 réels.

⚠️ Lancer les CLI sous `www-data` (`sudo -u www-data php …`), comme les cinq autres.

### Rapport de recette communautaire — ordre de suspicion si le temps réel ne marche pas

1. **La ligne `initk` verbatim** (`status`, `msg`, `scope` avec le `loginsession` masqué). Un
   `status != 0` avec une session valide ⇒ **rétablir `loginsession`/`userid` en en-têtes est le premier
   essai** (§ 1.5, entrées laissées en commentaire pour cela).
2. **`initk status == 0` mais zéro push dans les 5 min suivant un changement réel à la télécommande** ⇒
   le `sub` est refusé en silence : même premier essai (les deux en-têtes), puis fraîcheur du
   `devSession` (jetons de plus de 30 min), puis `pid` / `gatewayId` du `devList`.
3. **Seulement ensuite**, la casse de `devicetypeflag` — premier suspect du transport en général, mais
   qui ne joue **pas** dans le `sub` (il n'y figure pas).

## 12. Dette

*(section alimentée en fin de cycle par les findings de review sous la gate)*

- **D-05-03-01** — R5 : pas de purge de `smartclim::session_auxcloud` sur refus du relais ; récupération
  en ~40 min. À rouvrir si la recette montre des refus fréquents.
- **D-05-03-02** — R8 : comportement en cas de session concurrente avec l'application constructeur non
  établi.
