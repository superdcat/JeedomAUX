# Spec technique — UC02 « Socle du démon Python et pont de communication avec Jeedom »

> **Domaine** : post-mvp/05-temps-reel-et-demon · **Spec fonctionnelle** : `02-socle-demon-python.md`
> **Statut** : plan validé le 2026-09-07 · **Dépend de** : UC01 de ce domaine (spike, conclu)
>
> Toutes les affirmations sur le comportement du **core Jeedom** de cette spec ont été lues dans la
> source (`jeedom/core`, branche `master`, recoupée sur `V4-stable` = 4.4.12 pour les points sensibles).
> Les numéros de ligne cités sont ceux de `master` au 2026-09-07 : ils dérivent, le **nom de méthode** et
> le **comportement** sont ce qui fait foi.

## 0. Ce que fait cette UC, en une phrase

Elle fait passer le plugin du modèle « 100 % PHP sans aucune dépendance » (socle MVP) à un modèle
« démon Python + pont bidirectionnel », **sans implémenter aucun protocole métier** : le démon restauré ne
sait faire qu'un aller-retour de vérification `ping` → `pong`.

⚠️ **Le démon est LATÉRAL, jamais un passage obligé.** C'est l'invariant de conception de cette UC, et il
se lit dans le code par une propriété simple, à préserver : **aucune** méthode du socle MVP
(`cron()`, `cycleEchu()`, `rafraichirAuxHome()`, `executerCommandeAction()`, `envoyerOrdreLan()`) ni aucune
des quatre briques (`smartclimAuxHomeApi`, `smartclimBroadlinkLan`, `smartclimFrame`,
`smartclimCapabilities`) n'appelle `smartclimDemon`. Le seul couplage introduit est **descendant** — le
pont ne remonte rien vers le métier. Le jour où une UC de ce domaine branchera un protocole réel, ce sera
en ajoutant un appelant, jamais en insérant le démon sur le chemin de la scrutation.

## 1. Contrats externes

Aucun service tiers : **cette UC ne sort pas du plugin**, ni vers AUX Home, ni vers le LAN. Les seuls
contrats sont ceux du core Jeedom.

### 1.1 Cycle de vie du démon — `core/class/plugin.class.php`

- `$plugin_id::deamon_info()` est appelée **en statique sur la classe principale** (l. 844). Le core exige
  au moins `state` et `launchable` (il indexe `$return['launchable']`, l. 884-892) et complète lui-même
  `auto`, `last_launch`, `launchable_message`/`log` s'ils manquent (l. 862-873).
- `deamon_start()` n'est appelée que si `launchable == 'ok' && state == 'nok'` (l. 892). ⚠️ Le core résout
  sa signature par **`ReflectionMethod`** : `$_auto` n'est transmis que si la méthode déclare **au moins un
  paramètre obligatoire** (l. 912-917) → on la déclare **sans paramètre**.
- `deamon_stop()` n'est appelée que si `state == 'ok'` (l. 929-932). ⚠️ **C'est de cette condition que naît
  le risque d'orphelin traité au § 6.2** — pas de l'ordre des opérations du core.
- ⚠️⚠️ `plugin::deamon_start()` et `deamon_stop()` sont entourées d'un `try/catch (\Throwable)` **par le
  core** ; **`plugin::deamon_info()` ne l'est pas** (l. 839-877). D'où le contrat « ne lève jamais » du
  § 5.
- **Auto-démarrage (AC3), zéro ligne de code de notre part** : `plugin::start()` appelle
  `deamon_start(false, true)` sur chaque plugin actif (l. 548-550), et la tâche cron du core
  `plugin::checkDeamon` sert de filet.
- ⚠️ **`plugin::checkDeamon` tourne toutes les 5 MINUTES, pas chaque minute** :
  `install/consistency.php` l. 335-345 la crée avec `setSchedule('*/5 * * * *')`, `setTimeout(5)` — à
  distinguer de `plugin::cron` (l. 263-273), `setSchedule('* * * * *')`, `setTimeout(2)`. **Deux lignes
  distinctes de la table `cron`.**
- `setIsEnable(1)` — donc **toute mise à jour depuis le market** — enchaîne : `deamon_stop()` →
  `deamon_info()` **journalisé en clair via `json_encode()`** (l. 993-998) → `callInstallFunction('update')`
  → `dependancy_info(true)` → `dependancy_install()` si `nok`. C'est le point de fuite n° 5 du § 6.1.

### 1.2 Dépendances — `plugin.class.php` + `core/class/system.class.php`

- `dependancy_info()` : **dès que `plugin_info/packages.json` existe**, l'état vient exclusivement de
  `system::checkAndInstall(..., $_fix = false)`, et la méthode statique du plugin **n'est jamais appelée**
  (l. 679-687). État `ok` mis en cache (`dependancy<id>`).
- ⚠️ **Nuance au périmètre de la règle de `CLAUDE.md`, à connaître** : le core lit *aussi*
  `method_exists($plugin_id, 'dependancy_info')` comme **interrupteur** (l. 845-856), pour bloquer
  `launchable` et afficher « Dépendances non installées ». La règle « ne pas définir `dependancy_info()` »
  reste appliquée (elle est juste, et la définir rendrait le calcul d'état incohérent) ; **conséquence
  assumée** : c'est notre `etat()` qui doit porter le message d'indisponibilité — c'est l'origine de
  l'écart i18n du § 7.
- **AC7, mécanisme de la stabilité** : `checkAndInstall` compare la **clé nue** du paquet à la liste
  installée, `isset($installPackage[mb_strtolower($package)])` (l. 558), où `getInstallPackage('pip3')`
  provient de `python3 -m pip list --format=json` (l. 385-395). Une clé `"requests==2.31.0"` ne
  correspondrait **jamais** → `status = 0` **en permanence** → indicateur NOK **et réinstallation à chaque
  passe**. C'est exactement le piège de `CLAUDE.md`, vérifié ligne à ligne.
- La version est comparée ensuite :
  `if (isset($info['version']) && version_compare($version, $info['version']) < 0) { $found = 0; }`
  (l. 576-579). Une version exacte se comporte donc en **plancher** : dès que l'installé est ≥ au déclaré,
  `status = 1` et rien n'est refait → **indicateur stable d'un cycle à l'autre**.
- `installPackage('pip3', ...)` (l. 839-848) : avec un `<` ou `>` dans `version`, le core fait
  `$_package .= $_version` **non quoté** → redirection shell. Toujours une version **exacte, sans
  opérateur**.
- ⚠️⚠️ **Fait décisif, absent de `.memory/` avant cette UC — le venv par plugin.** Sur **Debian ≥ 12**,
  les paquets pip d'un plugin vivent dans `plugins/smartclim/resources/python_venv`
  (`system::getPython3VenvDir`, l. 318-331), créé par `checkAndInstall` **uniquement s'il y a au moins un
  paquet à installer** (l. 661-694 : `python3 -m venv --upgrade-deps`), et `pip list` est lu **dans ce
  venv**. Trois conséquences directes :
  1. le démon **doit** être lancé avec `system::getCmdPython3('smartclim')`, jamais `python3` en dur —
     sinon `requests`, installé dans le venv, est invisible. ⚠️ La valeur renvoyée **finit par une
     espace** : ne pas en ajouter une seconde ;
  2. une section `pip3` **vide** ne crée aucun venv alors que `getCmdPython3()` pointerait dedans → démon
     **inlançable en silence**. Déclarer `requests` n'est donc pas cosmétique ;
  3. `resources/python_venv/` doit entrer dans `.gitignore`, et `resources/` recevoir un `.htaccess`
     (le venv est créé **hors** de `resources/smartclimd/`).

### 1.3 Le pont — deux sens, aucun utilitaire du core pour le sens descendant

- **PHP → démon** : le core ne fournit **rien** (vérifié : aucune classe `com_socket`/`com_*` dans
  `core/class/`). Le patron officiel (`jeedom/plugin-blea`, `blea.class.php` l. 361-364, 375-381) utilise
  `socket_create()`, donc l'extension **`sockets`** — que ce plugin sait *non garantie*
  (`.memory/analyse/smartclim-transport-broadlink-lan.md` § 9). On émet donc en
  **`stream_socket_client('tcp://127.0.0.1:<port>')`** + `fwrite($fp, $json . "\n")`. ⚠️ Le `"\n"` n'est pas
  décoratif : côté démon, `jeedom_socket_handler.handle()` fait `self.rfile.readline()` — **une ligne,
  terminée par `\n`**. Le patron blea s'en passe et se repose sur la fermeture du socket ; on est explicite.
- **Démon → PHP** : `jeedom_com.__post_change()` fait `requests.post(url + '?apikey=' + apikey, json=change)`.
  L'URL est un **fichier du plugin** — patron `plugins/<id>/core/php/jee<Id>.php`, vérifié dans
  `jeeBlea.php`, `jeeBroadlink.php`, `jeeMqtt2.php` — gardé par
  `jeedom::apiAccess(init('apikey'), '<id>')`, **pas** par `isConnect`. `jeedom::getApiKey('smartclim')`
  génère la clé à la demande (`jeedom.class.php` l. 564-575).
- **URL de rappel** : `network::getNetworkAccess('internal', 'http:127.0.0.1:port:comp')`
  (`network.class.php` l. 94-99, présent en V4-stable) → **HTTP nu sur 127.0.0.1**, avec repli automatique
  sur `internalAddr` en Docker. ⚠️ Retenu **contre** le `proto:127.0.0.1:port:comp` de blea, et le motif
  est de fond : il garantit qu'**aucun TLS n'entre en jeu**, ce qui rend le `verify=False` de la lib
  fournie *sans objet* plutôt que *toléré*. La règle projet « TLS toujours vérifié » n'est ni contournée
  ni assouplie — elle est mise hors sujet.
- ⚠️ **Voie écartée, et pourquoi** : `core/api/jeeApi.php?type=event&plugin=smartclim` →
  `smartclim::event()` existe bel et bien (`jeeApi.php` l. 76-80) et éviterait d'exposer un fichier.
  **Rejetée** : ce chemin fait `log::add('api', 'debug', ... json_encode($_GET))` (l. 75) — il écrirait la
  **clé d'API en clair** dans le journal `api` à **chaque** notification. AC6 tranche.

### 1.4 Le squelette de démon — question « À confirmer » de la spec, tranchée

`git show ceed01b --stat -- resources/` → **5 fichiers, 666 lignes** : `.htaccess`, `demond.py` (137 l.),
`jeedom/__init__.py` (15 l.), `jeedom/jeedom.js` (192 l.), `jeedom/jeedom.py` (320 l.).

**Comparaison octet à octet avec `jeedom/plugin-template@master`** (`diff -u` sur `demond.py` et
`jeedom/jeedom.py`) : **identiques**. La question « ce dépôt ou l'amont ? » **n'a donc aucun enjeu** : on
restaure depuis `ceed01b`, qui *est* l'amont courant — sans requête réseau et sans risque de dérive de
version. `grep -i template` sur les fichiers restaurés : **zéro occurrence** — l'id du plugin n'apparaît
**nulle part** dans le squelette Python (les seules occurrences vivaient dans l'ancien `packages.json`,
sections `npm`/`yarn`/`composer`, déjà vidées au renommage).

### 1.5 ⚠️⚠️ Trois défauts ÉTABLIS du squelette officiel — ils conditionnent AC5 et AC2

Vérifiés, pas supposés. Sans les deux premiers, **AC5 est inatteignable**.

1. **`jeedom_utils.stripped()` est cassé, et il est sur le chemin de `read_socket()`.** `handle()` empile
   des **bytes** ; `stripped()` fait `"".join([i for i in s if i in range(32,127)])` :
   - sur des `bytes`, `i` est un `int` → **`TypeError: sequence item 0: expected str instance, int found`**
     (exécuté et confirmé localement) ;
   - sur une `str`, `'a' in range(...)` vaut **`False`** pour tout caractère → renvoie **`''`** →
     `json.loads('')` lève.

   Dans les deux cas l'exception naît **hors** du `try` interne de `read_socket()`, traverse `listen()`
   (qui n'attrape que `KeyboardInterrupt`), et tombe dans le `except Exception` du corps de module →
   `shutdown()` → **le démon meurt au premier message reçu**.
2. **`import serial` / `import pyudev` inconditionnels** en tête de `jeedom/jeedom.py`, alors que le
   `packages.json` du gabarit ne déclare que `pyserial` et `requests` (**pas** `pyudev`) → dans un venv
   neuf, `ImportError` au démarrage. Le gabarit amont est **lui-même incohérent** dans cette configuration.
3. **`shutdown()` fait `os.remove(_pidfile)` et `my_jeedom_socket.close()` sans garde** → deux
   `logging.warning` parasites sur un arrêt précoce. Nuit directement à AC2 (« sans erreur dans les
   journaux »).

## 2. Architecture — fichiers

Convention d'indentation / fins de ligne relevée **fichier par fichier**, par **comptage d'octets**
`\r`/`\n` (jamais `grep -c $'\r'`, cf. `CLAUDE.md`).

| Chemin | État | Ce qui y entre | Indentation / fins de ligne |
|---|---|---|---|
| `plugin_info/info.json` | modifié | `"hasDependency": true`, `"hasOwnDeamon": true` — **2 valeurs, rien d'autre**. ⚠️ **ne pas toucher `pluginVersion`** (hook `pre-commit`) | tabulations, **LF** (existant : 0 CR / 43 LF) |
| `plugin_info/packages.json` | modifié | section `pip3` : `"requests": {"version": "<plancher>"}`. Rien dans `apt`/`npm`/`yarn`/`composer`/`plugin`/`pre-install`/`post-install` | 2 espaces, **LF** (0 CR / 18 LF ; `.json` est exclu de la règle CRLF par `verif-plugin.py`) |
| `resources/smartclimd/smartclimd.py` | **restauré, renommé, amendé** | point d'entrée du démon : `ping` seul, apikey non journalisée, `read_socket()` robuste | 4 espaces, **LF dans l'index** |
| `resources/smartclimd/jeedom/jeedom.py` | **restauré, amendé** (3 divergences) | `jeedom_com`, `jeedom_utils`, `jeedom_socket` | 4 espaces, **LF dans l'index** (le blob d'origine est CRLF — cf. § 2.2) |
| `resources/smartclimd/jeedom/__init__.py` | restauré **verbatim** | en-tête de licence seul | **LF dans l'index** |
| `resources/smartclimd/.htaccess` | restauré verbatim | `Deny from all` | intact |
| `resources/.htaccess` | **créé** | `Order allow,deny` / `Deny from all` — couvre `python_venv/`, créé par le core **hors** de `smartclimd/` (patron : `jeedom/plugin-blea` en a un) | 2 lignes, CRLF |
| `core/php/jeeSmartclim.php` | **créé** | rappel du démon : `apiAccess`, décodage JSON, aiguillage `pont.pong` → `smartclimDemon::enregistrerPong()`. **Aucune** autre action | 2 espaces, **CRLF** (aligné sur `core/php/*.php`) |
| `core/php/.htaccess` | modifié | mise en liste blanche du **seul** `jeeSmartclim.php` (cf. § 9 R2) | 4-5 lignes, CRLF |
| `core/class/smartclimDemon.class.php` | **créé** | toute la mécanique : port, chemins, état, lancement/arrêt, **unique point socket**, ping/pong | 2 espaces, **CRLF** |
| `core/class/smartclim.class.php` | modifié | **3 délégations statiques** `deamon_info`/`deamon_start`/`deamon_stop` + bloc de commentaire d'usage. **Rien d'autre** — aucune méthode existante touchée | 2 espaces, **CRLF** |
| `core/php/smartclim.inc.php` | modifié | ⚠️ **ligne à ajouter, en dernier** : `require_once __DIR__ . '/../class/smartclimDemon.class.php';` (après `smartclimDiagnostic`). **Sans elle : « Class not found » au runtime, invisible à `php -l` ET à la CI** | 2 espaces, **CRLF** |
| `core/config/smartclim.config.ini` | modifié | `demon_port = 55112` + commentaire « doit rester identique à `smartclimDemon::PORT_DEMON` » | **CRLF** |
| `core/php/pont-demon.php` | **créé** | **4ᵉ CLI** : `--etat` (lecture seule) et `--ping [--attente=<s>]`. Garde `php_sapi_name() !== 'cli'` **avant tout `require_once`** | 2 espaces, **CRLF** |
| `.gitignore` | modifié | `resources/python_venv/` (`__pycache__/` et `*.pyc` sont **déjà présents**) | **CRLF** |
| `resources/smartclimd/jeedom/jeedom.js` | **non restauré** (`git rm`) | lib Node du gabarit, sans emploi ici | — |
| `plugin_info/configuration.txt` / `.php` | **NON touchés** | aucun champ de formulaire ajouté → **aucun `cp` de miroir**, `check_miroir()` reste vert | — |
| `plugin_info/install.php` | **NON touché** | reste volontairement vide — cf. § 6.2, l'arrêt défensif est **délibérément écarté** | — |
| `.gitattributes` | **NON touché** | cf. § 2.2 | — |
| `desktop/**`, `core/ajax/smartclim.ajax.php`, `core/i18n/*.json` | **NON touchés** | l'i18n est l'étape finale du cycle (sous-agent `translator`) | — |

### 2.1 Restauration — commandes exactes, dans cet ordre

```bash
git checkout ceed01b -- resources/
git mv resources/demond resources/smartclimd
git mv resources/smartclimd/demond.py resources/smartclimd/smartclimd.py
git rm resources/smartclimd/jeedom/jeedom.js
git add --renormalize resources/smartclimd/     # réapplique le filtre clean -> blobs en LF
```

⚠️ **Pourquoi renommer** (et non garder `demond/demond.py`) : `system::kill()` et `system::ps()`
travaillent par **motif `grep` sur la ligne de commande**. `demond.py` est le nom que produira **tout**
plugin dérivé du même gabarit — un `system::kill('demond.py')` d'un autre plugin tuerait **notre** démon,
et réciproquement. Le renommage est la **seule** protection possible.

Rien d'autre n'est à renommer : `from jeedom.jeedom import …` reste valide (Python ajoute le répertoire du
script à `sys.path`), et l'id du plugin n'apparaît nulle part dans le squelette (§ 1.4).

### 2.2 ⚠️ Fins de ligne des `.py` — LF, et la vérification porte sur l'INDEX

Les trois `.py` sont normalisés en **LF**. Motif : LF est le standard Python, c'est déjà le blob de
`demond.py` **et** celui des trois `.py` existants du dépôt (`.claude/scripts/*.py` : 0 CR pour 534, 668 et
106 LF) — `jeedom/jeedom.py` (blob CRLF, 320/320) est le seul intrus, et « respecter l'existant fichier par
fichier » **ne s'applique pas** ici puisque les deux fichiers sont **activement réécrits** (en-tête FR,
suppression d'imports, refonte de `read_socket()`/`shutdown()`). `CLAUDE.md` ne fixe d'ailleurs aucune
règle CRLF pour `.py` (sa liste est PHP/JS/ini/txt/html).

**Pas de ligne ajoutée à `.gitattributes`** : le motif qui justifie `.githooks/** text eol=lf` est un motif
d'**exécution** (`/bin/sh` refuse un shebang CRLF) et il ne s'applique pas à Python, qui lit les fins de
ligne universelles. L'uniformité recherchée ici est cosmétique — elle ne justifie pas d'élargir un fichier
que `CLAUDE.md` veut minimal.

⚠️⚠️ **Piège de vérification** : `git show ceed01b:<path>` lit le **blob brut**, indépendamment des filtres
de checkout. Ce clone est en **`core.autocrlf=true`** : après `git checkout ceed01b -- resources/`, les
fichiers sont **CRLF sur disque** et l'index porte encore les blobs d'origine. La vérification doit donc
porter sur ce qui sera **réellement livré**, c'est-à-dire l'**index** :

```bash
for f in resources/smartclimd/smartclimd.py resources/smartclimd/jeedom/jeedom.py \
         resources/smartclimd/jeedom/__init__.py; do
  printf '%s CR=%s\n' "$f" "$(git show ":$f" | tr -cd '\r' | wc -c)"
done                                          # doit afficher CR=0 partout
```

Sur disque, **`LF-pur` comme `CRLF` sont acceptables** (aucune règle projet pour `.py`) ; **`MIXTE` est
interdit** — c'est le seul défaut que `verif-plugin.py` bloque sur cette extension, et c'est exactement ce
qu'une édition inattentive d'un fichier CRLF depuis cette machine produit.

### 2.3 Divergences à appliquer au squelette

Chacune est consignée dans un **bloc d'en-tête FR** du fichier concerné (origine, commit, liste des
écarts), pour qu'une resynchronisation amont future sache quoi rejouer.

**`smartclimd.py`**
- (a) en-tête FR : origine `jeedom/plugin-template` via `ceed01b`, liste des écarts ;
- (b) `_socket_host = '127.0.0.1'` **au lieu de `'localhost'`** — pas de résolution DNS, aucun risque de
  `::1` ni d'interface externe ; défauts `_socket_port = 55112` et `_pidfile = '/tmp/smartclim_demon.pid'` ;
- (c) **suppression** de `logging.info('Apikey: %s', _apikey)` et de l'argument `--device` (aucun matériel
  série) ;
- (d) **`read_socket()` réécrit** : `try/except Exception` **englobant**, `bytes.decode('utf-8', 'ignore')`
  au lieu de `jeedom_utils.stripped()`, comparaison de clé par **`hmac.compare_digest`**, aiguillage sur
  `message.get('cmd')` avec **`ping` comme seul ordre reconnu**, et journalisation d'un ordre inconnu
  **sans recopier la charge reçue** ;
- (e) `shutdown()` : `os.path.exists()` avant `os.remove()`, et test de présence de `my_jeedom_socket` dans
  `globals()` ;
- (f) messages de journal **en français**.

**`jeedom/jeedom.py`**
- (a) suppression de `import serial`, `import pyudev`, de la classe `jeedom_serial` et de
  `jeedom_utils.find_tty_usb()` — c'est ce qui **évite `pyserial` et `pyudev` dans `packages.json`** ;
- (b) dans `jeedom_com.__post_change()` **et** `.test()`, neutralisation du message d'exception journalisé :
  `str(error).replace(self._apikey, '***')` ;
- (c) `stripped()` **conservée mais annotée** d'un avertissement — conservée pour rester au plus près de
  l'amont, annotée pour qu'on ne la réemploie pas (§ 1.5) ;
- **(d) ⚠️ `jeedom_socket_handler.handle()` ne journalise plus la charge brute**, seulement sa **longueur**
  — c'est le **6ᵉ point de fuite** de la clé d'API (§ 6.3), ajouté après review. La ligne d'origine
  (`logging.info("Message read from socket: %s", str(lg.strip()))`) écrivait l'`apikey` en clair dans
  `log/smartclim_demon` à chaque message du pont ;
- **(e) `handle()` borne sa lecture** : timeout de socket par requête **et** taille maximale de ligne.
  Sans cela, une connexion locale qui n'envoie jamais de `\n` bloque **indéfiniment** le thread unique du
  `TCPServer` non threadé — le pont cesse de répondre **sans que le démon meure ni journalise**, et
  `etat()` continue d'afficher un démon en pleine forme (§ 9 R13).

⚠️ **Les divergences (d) et (e) portent toutes deux sur `handle()`, une méthode que le plan initial avait
classée « non retouchée ».** C'est précisément là que se cachaient les deux défauts. Une ligne du squelette
laissée intacte n'est pas une ligne auditée.

### 2.4 Arbitrage « classe annexe ou pas »

Le core appelle `deamon_info`/`deamon_start`/`deamon_stop` **en statique sur `smartclim`** (§ 1.1) : ces
trois méthodes **n'ont pas le choix de leur domicile**. Tout le reste va dans `smartclimDemon`, pour trois
raisons : (1) c'est la règle du plugin — « tout le LAN par `smartclimBroadlinkLan`, jamais de socket
épars » ; un second point socket dans `smartclim.class.php` la contredirait ; (2) `smartclim.class.php`
fait déjà **4 298 lignes** ; (3) le coût est d'**une** ligne de `require_once`.

## 3. Server vs Client

**Tout est serveur. Il n'y a aucun client.** Aucune ligne de JS, aucun champ de formulaire, aucune modale :
`desktop/**` et `plugin_info/configuration.txt`/`.php` sont intacts. Les deux surfaces visibles sont
fournies par le **core** — le panneau « Dépendances » et le panneau « Démon » de la page du plugin — et
alimentées, l'une par `packages.json` seul, l'autre par notre `deamon_info()`.

Corollaire : **toute** validation est côté serveur (§ 4), et l'unique instrument de démonstration est une
CLI (§ 5.4), retenue contre un bouton de page pour ne pas ajouter de chaîne UI ni de surface web à un
instrument d'infrastructure.

## 4. Validation

| Entrée | Barrière |
|---|---|
| Port de config (`demon_port`) | entier, **1024..65535**, normalisé **à la lecture** ; sinon la constante. Barrière **unique** : sans champ de formulaire il n'existe aucun chemin d'écriture par `config::save` depuis l'IHM, donc **pas** de `preConfig_demon_port` — qui serait du code mort |
| Jeton de pong (venu du réseau) | forme `^[0-9a-f]{8,32}$` **avant** toute écriture en cache — jamais une valeur reçue non validée en clé ou en valeur de cache |
| Corps du rappel HTTP | `is_array(json_decode(...))` avant tout accès ; toute autre forme → **sortie silencieuse** |
| Clé d'API | `jeedom::apiAccess()` côté PHP ; **`hmac.compare_digest`** côté Python (comparaison à temps constant) |

## 5. Signatures

### 5.1 `smartclimDemon` (`core/class/smartclimDemon.class.php`)

Aucun `eqLogic`, aucune commande, aucun accès AUX/LAN. **Unique point socket** du plugin vers le démon.

| Membre | Rôle / contrat |
|---|---|
| `const PORT_DEMON = 55112` | port d'écoute par défaut. Volontairement **hors** de la bande 5500x du gabarit (55009) et de blea (55008), où tout plugin dérivé du même squelette se bousculerait |
| `const CLE_CONF_PORT = 'demon_port'` | clé de config **sans champ de formulaire** : échappatoire en cas de collision, réglable par l'API JSON-RPC du core. Défaut **dupliqué en littéral** dans `smartclim.config.ini` — seul défaut vu par `config::byKeys()` ; les deux doivent rester identiques |
| `const NOM_LOG = 'smartclim_demon'` | ⚠️ `log::getLogLevel('smartclim_demon')` retombe **par regex** sur `log::level::smartclim` (`log.class.php` l. 70-77) : le niveau du démon suit celui du plugin, **sans réglage supplémentaire** |
| `const NOM_SCRIPT = 'smartclimd.py'`, `CLE_CACHE_PONG = 'smartclim::pont_pong'`, `DUREE_MEMOIRE_PONG = 300`, `TIMEOUT_SOCKET = 2`, `ATTENTE_DEMARRAGE = 5` | — |
| `public static function port(): int` | `config::byKey('demon_port', 'smartclim', PORT_DEMON)`, normalisé à la lecture (§ 4) |
| `public static function cheminScript(): string` | `realpath(__DIR__ . '/../../resources/smartclimd/smartclimd.py')`, ou `''` |
| `public static function fichierPid(): string` | `jeedom::getTmpFolder('smartclim') . '/demon.pid'` |
| `public static function etat(): array` | **Le corps de `deamon_info()`.** Renvoie `array('log' => NOM_LOG, 'state' => 'ok'\|'nok', 'launchable' => 'ok'\|'nok'[, 'launchable_message' => …])`. Détection : fichier PID présent **et** PID vivant (`posix_getsid`, sous garde `function_exists`) → `ok`, le PID périmé étant supprimé au passage. **Sinon, et seulement sinon**, repli par sonde de processus `system::ps(NOM_SCRIPT)` : non vide → `ok`. Sinon `nok`. `launchable` = script présent **et** interpréteur atteignable (`system::getCmdPython3('smartclim')` : chemin absolu → `file_exists`, sinon `system::checkHasExec('python3')`). ⚠️ **Coût : zéro appel shell sur le chemin nominal** ; la sonde `ps` (~10-30 ms) ne paie que dans le cas dégradé — précisément celui où un `nok` erroné ferait démarrer un **second** démon (§ 6.2). ⚠️⚠️ **Ne lève JAMAIS** (`try/catch (Throwable)` → `state`/`launchable` à `nok`) : `plugin::deamon_info()` n'a **aucun** `try/catch`, et un jet ici casserait la tâche `plugin::checkDeamon` **de tous les plugins**. Appelée toutes les 5 minutes par ce cron **et** à chaque chargement/rafraîchissement du panneau Démon → **aucun** `shell_exec`, `pip list` ou `fuser` sur le chemin nominal |
| `public static function lancer(): bool` | `exec($cmd . ' >> ' . log::getPathToLog(NOM_LOG) . ' 2>&1 &')`, **sans `sudo`** (le démon n'a besoin ni de root ni d'un port < 1024), puis attente de `state == 'ok'` par pas de 500 ms, `ATTENTE_DEMARRAGE` s au plus. Journalise la commande **masquée**. `false` + `log::add(error)` sur échec ; **ne lève pas** |
| `private static function commandeLancement(): string` | `<getCmdPython3('smartclim')><script> --loglevel <convertLogLevel(getLogLevel('smartclim'))> --socketport <port> --callback <getNetworkAccess('internal','http:127.0.0.1:port:comp')>/plugins/smartclim/core/php/jeeSmartclim.php --apikey <getApiKey('smartclim')> --pid <fichierPid()> --cycle 0.5`. ⚠️ `getCmdPython3()` **renvoie déjà une espace finale** |
| `public static function masquerCle(string $_commande): string` | `preg_replace('/(--apikey\s+)\S+/', '$1***', …)`. **Seule** forme journalisable de la commande |
| `public static function arreter(): bool` | **SIGTERM** par PID quand il est disponible **et que `posix` est là** (`system::kill((int) $pid)` — branche numérique = `posix_kill(…, 15)`, `system.class.php` l. 146-160 : le gestionnaire Python s'exécute, PID retiré, socket fermé), attente bornée, puis **repli inconditionnel** `system::kill(NOM_SCRIPT)` (branche chaîne : `ps \| grep \| awk \| xargs kill -9`, **sans `posix`**) et `system::fuserk((string) port())`. C'est ce repli qui referme le cas orphelin du § 6.2 : la sonde `ps` de `etat()` rend `state = ok`, donc le core délègue, et `arreter()` sait tuer un processus dont le PID n'est plus sur disque. **Idempotente et silencieuse** quand il n'y a rien à arrêter (`log::add(info)` **seulement** si un signal a été effectivement envoyé) — exigence AC2. **Ne lève pas** |
| `public static function envoyer(array $_message): bool` | **UNIQUE point socket** vers le démon. Ajoute `apikey`, encode en JSON, `stream_socket_client('tcp://127.0.0.1:' . port(), $errno, $errstr, TIMEOUT_SOCKET)` + `stream_set_timeout` + `fwrite($json . "\n")` + `fclose`. Journalise l'ordre **sans la clé**. `false` si le démon n'écoute pas ; **ne lève pas** |
| `public static function ping(string $_jeton): bool` | `envoyer(array('cmd' => 'ping', 'jeton' => $_jeton))` |
| `public static function enregistrerPong($_jeton): void` | Appelée **uniquement** par `jeeSmartclim.php`. Valide la **forme** du jeton (§ 4) puis `cache::set(CLE_CACHE_PONG, $jeton, DUREE_MEMOIRE_PONG)` |
| `public static function pongMemorise(): ?string` / `oublierPong(): void` | Lecture / purge, pour la CLI. ⚠️ La CLI purge **avant** d'émettre, pour ne pas confirmer un aller-retour antérieur |

⚠️ **Garde `posix` — conséquence non évidente à ne pas rater.** `posix` est de facto obligatoire sous
Jeedom : le core appelle lui-même `posix_getsid()` **sans garde** dans `cron::running()`
(`cron.class.php` l. 316), donc un Jeedom sans `posix` est déjà cassé. Les gardes ci-dessus sont de la
ceinture-et-bretelles **gratuite**, et surtout elles **réutilisent le chemin `ps` dont le § 6.2 a besoin de
toute façon** — un seul mécanisme, deux motifs. Détail : dans `etat()`, `posix` absent → on **saute**
l'étape PID et la sonde `ps` devient le détecteur principal, `launchable` restant `'ok'` (l'absence de
`posix` n'empêche pas de lancer un processus) ; dans `arreter()`, la branche numérique est conditionnée à
`function_exists('posix_kill')`, **sans quoi `system::kill((int) $pid)` du core lèverait une `Error` fatale
dans le code du core**.

### 5.2 `smartclim` — ajouts (et rien d'autre)

- `public static function deamon_info()` → `smartclimDemon::etat()`
- `public static function deamon_start()` → `smartclimDemon::lancer()` — ⚠️ **aucun paramètre
  obligatoire**, cf. la `ReflectionMethod` du core (§ 1.1)
- `public static function deamon_stop()` → `smartclimDemon::arreter()`

**Aucune** autre modification, et surtout **pas** de `dependancy_info()` ni de `dependancy_install()`
(§ 1.2).

### 5.3 `core/php/jeeSmartclim.php`

`require_once` du core →
`if (!jeedom::apiAccess(init('apikey'), 'smartclim')) { http_response_code(401); die(); }` →
`if (init('test') != '') { echo 'OK'; die(); }` →
`json_decode(file_get_contents('php://input'), true)` → si `is_array` et `isset($corps['pont']['pong'])` →
`smartclimDemon::enregistrerPong(...)` → `log::add('smartclim', 'info', …)`. Puis fin silencieuse.

- ⚠️ **Pas de `session_write_close()` ici**, contrairement à `core/ajax/smartclim.ajax.php` : ce point
  d'entrée n'inclut pas `authentification`, **aucune session n'est ouverte**.
- ⚠️ Le **401** (là où `jeeBlea.php` renvoie 200 avec un texte) est **délibéré** : `jeedom_com.test()`
  traite tout code ≠ 200 comme fatal, donc une clé fausse ou un `.htaccess` mal réglé se voit **au
  démarrage**, dans le journal du démon, au lieu de dégénérer en **pont muet**.

### 5.4 `core/php/pont-demon.php` — 4ᵉ CLI

Calquée sur les trois existantes : garde `php_sapi_name() === 'cli'` **avant tout `require_once`**, aucun
POST, aucune écriture en base ni sur disque, sorties **FR sans `__()`**.

- `--etat` : affiche `smartclimDemon::etat()` + port + PID. **Aucune émission réseau.**
- `--ping [--attente=<s>]` : `oublierPong()` → `bin2hex(random_bytes(4))` → `ping()` → sondage de
  `pongMemorise()` toutes les 200 ms pendant `--attente` (défaut 5 s) → « Aller-retour OK (jeton …, N ms) »
  et **code de sortie 0** ; sinon un diagnostic distinguant les **trois** échecs possibles (démon non
  joignable / pas de pong / jeton différent) et **code 1**.

## 6. Sécurité — classement des erreurs et secrets

### 6.1 Typage des exceptions — cette UC ne lève AUCUNE `smartclimException`

C'est un **choix**, pas un oubli. Les quatre types (`TYPE_RESEAU`/`AUTH`/`PROTOCOLE`/`INTERNE`) décrivent
un échange avec un **tiers** ; ici tout est local et, surtout, **les trois surfaces d'appel exigent le
contraire d'une exception** : le core lit `deamon_info()` **sans `try/catch`**, `deamon_start`/`stop`
doivent renvoyer un booléen exploitable, et la CLI doit rendre un code de sortie.

Le contrat est donc **« statut + journal »** — celui de `smartclimBroadlinkLan::ouvrirSession()` /
`lireEtat()`, **pas** celui d'`appliquerOrdre()`. Un `try/catch (Throwable)` clôt chacune des trois
méthodes publiques ; le message d'un `Throwable` passe par `smartclim::neutraliserPourLog()` avant
journalisation, **jamais la trace**.

### 6.2 ⚠️⚠️ Orphelin de démon — la cause est la détection par PID, pas l'ordre des opérations du core

**Vérifié dans `plugin::setIsEnable(0)` (`plugin.class.php` l. 1012-1019)**, l'ordre est :

```php
} else {
    $this->deamon_stop();                              // 1. arrêt
    if ($alreadyActive == 1) {
        $out = $this->callInstallFunction('remove');    // 2. smartclim_remove()
    }
    rrmdir(jeedom::getTmpFolder($this->getId()));       // 3. purge du dossier tmp (donc du PID)
}
```

L'arrêt **précède** la purge du dossier qui contient le fichier PID, et `config::save('deamonAutoMode', 0)`
est posé **avant** tout le bloc (l. 954-955) puis restauré (l. 1031-1033) : aucun `checkDeamon` ne peut
relancer le démon pendant l'opération.

⚠️ **Aucun arrêt défensif n'est donc à ajouter dans `smartclim_remove()`, et il ne faut pas en ajouter par
précaution** : il serait redondant sur le chemin nominal, et il ne couvrirait **pas** le cas dégradé (si
`state != 'ok'`, c'est le **core** qui saute l'appel, pas `remove()` qui manque). `install.php` reste vide.
⚠️ Pour lever d'avance l'objection symétrique : arrêter un processus n'aurait **pas** été une violation de
la règle « ne jamais mettre de purge de configuration dans `<id>_remove()` » — cette règle interdit de
**détruire une donnée utilisateur** sur un cycle désactiver/réactiver, pas d'arrêter un processus, qui est
réversible par nature. C'est l'argument de **redondance**, et lui seul, qui écarte l'arrêt défensif ici.

**Le vrai risque d'orphelin est ailleurs** : `plugin::deamon_stop()` ne délègue **que si `state == 'ok'`**
(l. 929-932), alors que `state` dérivait du seul fichier PID. Tout événement qui **efface le PID sans tuer
le processus** produit l'orphelin, et il en existe un qui n'a rien d'exotique :
**`systemd-tmpfiles-clean` purge `/tmp` des fichiers non touchés depuis 10 jours** (défaut Debian). Notre
PID est écrit une fois et jamais retouché → un démon en marche depuis plus de 10 jours peut perdre son
fichier PID, passer `state = nok`, se faire doubler par un second démon qui échouera à se lier au port
(§ 9 R5), le premier continuant de tourner et l'état restant `nok` **indéfiniment**, avec une tentative de
lancement toutes les 5 minutes. S'y ajoutent le `PrivateTmp` d'Apache (le core livre
`install/fix_apache_private_tmp.sh` — ce n'est pas théorique) et un `/tmp` nettoyé à la main.

**C'est pour cela — et non pour un confort de diagnostic — que `etat()` sonde `system::ps(NOM_SCRIPT)` en
repli, et qu'`arreter()` a un repli inconditionnel** (§ 5.1).

### 6.3 AC6 — les SIX points de fuite et leur traitement

⚠️⚠️ **Cette liste a compté cinq points jusqu'à la review du 2026-09-07 — elle en compte six, et l'erreur
est instructive.** Le plan avait énuméré les fuites présentes dans le code qu'il prévoyait d'**écrire** ou
de **retoucher**, et manqué celle qui vivait dans la partie du squelette qu'il avait décidé de laisser
**intacte** (`jeedom_socket_handler.handle()`). La review sécurité a elle aussi conclu « pas de sixième
point de fuite » ; c'est la review **qualité** qui l'a trouvée, en remontant du `envoyer()` PHP vers ce que
le Python en fait.
**Leçon à retenir pour toute UC future de ce domaine** : la surface d'audit d'un secret n'est pas
« le code que j'écris », c'est **tout le trajet de la valeur**. Un point de fuite se cherche en suivant la
donnée, jamais en relisant le diff.

| # | Où | Ce qui fuirait | Traitement |
|---|---|---|---|
| 1 | `lancer()`, journal `smartclim` | `--apikey <clé>` dans la commande journalisée — **c'est ce que fait le patron officiel** (`blea.class.php` l. 490) | `masquerCle()` **obligatoire** ; la commande brute n'est **jamais** passée à `log::add` |
| 2 | `smartclimd.py`, journal `smartclim_demon` | `logging.info('Apikey: %s', _apikey)` du gabarit | ligne **supprimée** |
| 3 | `read_socket()`, journal du démon | `logging.error("Invalid apikey from socket: %s", message)` recopie la charge, **donc la clé présentée** | message **sans charge** |
| 4 | `jeedom_com`, journal du démon | un `str(exception)` de `requests` contient l'**URL complète**, donc `?apikey=…`, à **chaque** échec de rappel | `.replace(self._apikey, '***')` dans `__post_change()` **et** `test()` |
| 5 | journal `smartclim`, écrit **par le core** | `setIsEnable(1)` journalise `json_encode(deamon_info())` (l. 993-998) | **contrainte de conception** : le tableau rendu par `etat()` ne contient **que** `log`/`state`/`launchable`/`launchable_message` — jamais de commande, de clé, ni de chemin de venv |
| **6** | ⚠️ `jeedom_socket_handler.handle()` (`jeedom.py`), journal du démon | `logging.info("Message read from socket: %s", str(lg.strip()))` journalise la **ligne brute reçue sur le socket** — or `envoyer()` y ajoute **inconditionnellement** `apikey`. Donc la clé, en clair, en `INFO`, à **chaque** message du pont | **ne journaliser que la LONGUEUR** de la charge, jamais son contenu (le contenu utile est déjà journalisé par `read_socket()`, après authentification). Consigné en **divergence** de `jeedom.py` (§ 2.3) |

⚠️ **Ce point 6 n'était pas un cas limite** : l'étape 7 de la recette (§ 11) prescrit un `grep` de l'apikey
dans `log/smartclim_demon` en attendant **zéro occurrence** — il faisait échouer AC6 dans la procédure de
recette prescrite par cette spec même.
⚠️ **Symétrie à préserver** : côté PHP, `envoyer()` journalise `$ordreSansCle`, c'est-à-dire le message
**avant** ajout de la clé. Les deux extrémités du socle doivent rester ainsi — chacune journalise ce
qu'elle sait sûr, jamais la trame qui transite.

**Résiduel assumé et nommé** : la clé d'API apparaît dans la **ligne de commande** du processus (visible par
`ps` à tout compte local) et dans la **query string** du rappel loopback. C'est le mécanisme **natif du
core**, identique dans `plugin-blea`, `plugin-broadlink` et `plugin-mqtt2`, et **il n'existe aucune voie qui
l'évite** (`init()` ne lit pas les en-têtes HTTP, et le corps posté est du JSON donc `$_POST` est vide).
Même classe d'arbitrage que l'exception `configKey` déjà tranchée dans `CLAUDE.md` : surface
admin/localhost, mono-tenant. **Ce qui n'est pas accepté**, c'est qu'elle atteigne un **fichier de
journal** (points 1 à 4) — parce qu'un journal part dans une archive de support.

## 7. Impact i18n (français uniquement à l'implémentation)

⚠️ **Écart à la spec fonctionnelle, ratifié par l'utilisateur le 2026-09-07.** La spec annonçait « aucune
nouvelle chaîne UI **anticipée à ce stade** ». Deux chaînes sont introduites, dans
`core/class/smartclimDemon.class.php`, toutes deux comme valeur de `launchable_message` — champ **affiché**
par le panneau « Démon » du core :

- `__('Dépendances non installées ou interpréteur Python introuvable', __FILE__)`
- `__('Fichier du démon introuvable — réinstallez le plugin', __FILE__)`

**Motif de l'écart** : la règle « ne pas définir `dependancy_info()` » prive le plugin du message que le
core aurait fourni traduit (« Dépendances non installées », l. 850) ; l'alternative sans chaîne serait un
indicateur rouge **sans aucune explication** au moment précis — première mise à jour, venv pas encore créé
— où l'utilisateur en a le plus besoin.

Chaînes **littérales**, dans la table de retour, **jamais `__($variable)`** (l'extraction i18n est un scan
statique).

**Aucune autre chaîne** : pas de bouton, pas de champ de formulaire, pas de modale (`configuration.txt`
intact), sorties CLI en FR **sans `__()`** (convention des trois CLI existantes), journaux en FR sans
`__()`.

## 8. Périmètre

### 8.1 Couverture des critères d'acceptation

| AC | Couverture |
|---|---|
| **AC1** | `hasDependency: true` **et** `hasOwnDeamon: true` : `desktop/js/plugin.js` du core (l. 148, 158) **masque purement le panneau** si le drapeau vaut 0 — un panneau caché ne peut pas être « à l'état correct ». Dépendances calculées par le core depuis `packages.json` seul ; état du démon par `etat()`. **À valider en recette** |
| **AC2** | Panneau « Démon » → `core/ajax/plugin.ajax.php`, actions `deamonStop` / `deamonStart&forceRestart=1`. `arreter()` : SIGTERM → attente → repli `kill -9` + `fuserk`, **idempotente et silencieuse** si rien à arrêter. ⚠️ Recette : **≥ 45 s** entre Stop et Start (§ 9 R7a). **À valider en recette** |
| **AC3** | **Zéro ligne de code** : `plugin::start()` (l. 548-550) + filet `plugin::checkDeamon` **toutes les 5 minutes**. **À valider en recette** |
| **AC4** | **Par absence de diff** (§ 0), et vérifié en outre qu'il n'existe **aucun partage de processus** : cf. § 9 R1. ⚠️ Recette : « Gestion automatique » à **0** (§ 9 R7b), sinon le démon est relancé sous le test |
| **AC5** | CLI `core/php/pont-demon.php --ping` : PHP → socket loopback → démon → `jeedom_com` → POST sur `jeeSmartclim.php` → cache → relecture du jeton, code 0/1. Observable en **trois** endroits : stdout, `log/smartclim`, `log/smartclim_demon`. ⚠️ C'est aussi le test de § 9 R2 |
| **AC6** | Cinq points de fuite identifiés et traités un par un (§ 6.3), résiduel nommé |
| **AC7** | `requests` seul, **version dans la valeur**, sans opérateur, pas de `reinstall`, **pas** de `dependancy_info()`. Mécanisme de stabilité démontré au § 1.2 (comparaison par **clé** puis `version_compare` en **plancher**) |

Aucun critère non couvert.

### 8.2 Écart avec la spec fonctionnelle

Un seul, celui du § 7 (deux chaînes UI). **Ratifié.** La spec fonctionnelle n'est **pas** amendée : sa
formule « aucune nouvelle chaîne UI anticipée à ce stade » n'était pas une interdiction, et l'écart est
tracé ici.

### 8.3 Hors périmètre

Tout protocole métier : WebSocket du cloud historique → UC03 ; AUXLink → UC04 ; push AUX Home → verdict
d'UC01. **Aucune donnée climatiseur réelle ne transite** dans cette UC.

## 9. Risques

- **R1 — l'installation automatique des dépendances : coût ponctuel *si le réseau répond*, récurrent
  sinon.** Au premier `plugin::checkDeamon` suivant la mise à jour (donc dans les **5 minutes**), l'état
  est `nok` et, `dependancyAutoMode` valant 1 par défaut, le core lance en **tâche détachée** :
  `apt update`, `apt install python3 python3-pip python3-dev python3-venv`,
  `python3 -m venv --upgrade-deps resources/python_venv`, `pip install --upgrade pip wheel`,
  `pip install requests==<v>`. Plusieurs minutes, un fichier `/tmp/jeedom_install_in_progress_smartclim`,
  l'indicateur qui passe par « en cours », la sortie complète dans `log/smartclim_packages`. Pour un plugin
  qui n'avait **jamais** eu de dépendance, c'est un changement d'expérience notable.
  ⚠️ **Ce qui a été vérifié, et qui infirme la crainte d'un cron bloqué** : `plugin::cron` (`* * * * *`) et
  `plugin::checkDeamon` (`*/5 * * * *`) sont deux tâches **distinctes** ; `cron::run()`
  (`cron.class.php` l. 293-308) lance chaque tâche dans **son propre processus détaché**
  (`system::php(jeeCron.php "cron_id=<id>" … &)`), et `running()` (l. 314-324) n'empêche qu'une seconde
  instance **de la même** tâche ; enfin l'installation elle-même est **détachée** — avec
  `$_foreground = false`, `checkAndInstall` **n'exécute rien** : elle concatène les commandes
  (`system.class.php` l. 660-694, 723-729), écrit `/tmp/jeedom_fix_package` (l. 771) et appelle
  `launchScriptPackage()` (l. 801-825), qui fait `exec(sudo /bin/bash … &)` ou `echo … | sudo at now`.
  **Le partage résiduel est donc matériel, pas logiciel** : CPU/disque et **verrou apt** (`checkAndInstall`
  fait même `killall apt apt-get unattended-upgr` et `rm /var/lib/dpkg/lock*`, l. 645-655) → un cycle de
  scrutation peut être plus **lent**, jamais bloqué ni sauté (la garde d'échéance d'UC07 le reporte
  simplement).
  ⚠️ **En cas d'échec durable** (pas d'accès apt/PyPI, disque plein, machine coupée d'Internet), le script
  généré n'a **pas** de `set -e` — il commence par `set -x` : il déroule jusqu'à sa dernière ligne, supprime
  son fichier de progression, l'état repasse `nok`, et **une nouvelle tentative part toutes les 5 minutes,
  sans borne de nombre**. Il n'y a ni **empilement** (garde `installPackageInProgress`, l. 775-799 :
  péremption 30 min, plus verrou de 60 s de `dependancy_install`, l. 757-762) ni **silence** (message core
  après 3 relances de démon manquées, l. 904-906), mais c'est bien une charge **récurrente** : ~1
  `apt update` toutes les 5 min sur une machine air-gapped. Ce n'est pas propre à ce plugin — c'est le
  mécanisme du core pour tout plugin à dépendances. **Deux interrupteurs** existent dans la page du plugin :
  « Gestion automatique » des **dépendances** (`dependancyAutoMode = 0`, testé l. 584) et celle du **démon**
  (`deamonAutoMode = 0`), qui fait `continue` **en tête de boucle** (l. 578-580) et coupe donc *aussi*
  l'installation automatique. **À dire dans la doc utilisateur.**
- **R2 — `core/php/.htaccess` bloquerait le pont, et rien ne le dirait clairement.** Le rappel est un
  GET/POST **HTTP** sur `plugins/smartclim/core/php/jeeSmartclim.php`, alors que ce répertoire porte
  `Deny from all` → **403** → `jeedom_com.test()` échoue → **le démon s'arrête au démarrage**. Les trois
  plugins officiels à démon (`blea`, `broadlink`, `mqtt2`) n'ont **pas** de `.htaccess` dans `core/php` —
  on ne supprime pas le nôtre pour autant (il protège `smartclim.inc.php` et les CLI) : on met **un seul
  fichier** en liste blanche, sur le modèle **déjà éprouvé dans ce dépôt** par `plugin_info/.htaccess`
  (bloc `<Files>` encadré de `Order allow,deny` / `Deny from all`, qui sert effectivement l'icône).
  Si un 403 subsiste en recette, l'alternative Apache 2.4 est
  `<Files "jeeSmartclim.php">Require all granted</Files>` (`mod_access_compat` contre autorisation
  native). **AC5 est le test, et c'est le premier point à vérifier s'il échoue.**
- **R3 — version de `requests` : la contrainte qui commande n'est PAS « la plus récente », c'est
  `os.min`.** ⚠️⚠️ **La formulation initiale de ce risque était fausse, et elle a directement produit un
  défaut en implémentation** (corrigé le 2026-09-07) : elle disait « relever la version au moment de
  rédiger `packages.json` » par `python3 -m pip index versions requests`, sans dire **contre quoi** la
  croiser. L'implémenteur a donc retenu la **dernière** version publiée (2.34.2) — le pire choix possible.
  Matrice réelle, établie en lisant `Requires-Python` dans le `METADATA` des roues :

  | version | `Requires-Python` | Debian 10 (Python 3.7) | Debian 11 (3.9) | Debian 12 (3.11) |
  |---|---|---|---|---|
  | **2.31.0** ✅ retenue | `>=3.7` | ✅ | ✅ | ✅ |
  | 2.32.5 | `>=3.9` | ❌ | ✅ | ✅ |
  | 2.33.1 / 2.34.2 | `>=3.10` | ❌ | ❌ | ✅ |

  `info.json` porte **`os.min: 10`**, et — point décisif — **Debian 10/11 n'ont pas le venv par plugin**
  (réservé à Debian ≥ 12, § 1.2) : c'est le pip **système**, avec le Python **système**. Une version
  exigeant Python ≥ 3.10 y échoue donc à l'installation, et le mode de défaillance est celui décrit en R1 :
  script sans `set -e` → fichier de progression supprimé → état `nok` → **relance toutes les 5 minutes,
  indéfiniment**. Autrement dit : déclarer la dernière version ne « dégrade » pas les vieilles
  distributions, elle y **casse le plugin en boucle**.
  ⚠️ **Règle à appliquer désormais** : la version déclarée doit être la plus **basse** qui satisfait le
  besoin fonctionnel **et** dont le `Requires-Python` couvre le Python de `os.min`. Ce n'est pas un
  compromis : le core installe avec `==<version>`, donc la version déclarée est **installée exactement**,
  et Debian 12 ne perd rien — `requests` 2.31.0 suffit très largement pour un `requests.post` sur
  loopback. Comment vérifier une candidate :

  ```bash
  python -m pip download requests==<v> -d /tmp/whl --no-deps
  unzip -p /tmp/whl/requests-<v>-py3-none-any.whl '*/METADATA' | grep '^Requires-Python'
  ```

  ⚠️ Et **ne pas relever ce plancher plus tard sans raison fonctionnelle** : le faire **re-déclenche un
  cycle d'installation sur tout le parc**.
- **R4 — `resources/python_venv` sur une installation clonée en git.** Le core crée ce répertoire **dans**
  l'arborescence du plugin ; non ignoré, il salit l'arbre et peut faire échouer une mise à jour par `git`.
  D'où la ligne de `.gitignore`. Il n'est **pas** couvert par `resources/smartclimd/.htaccess` (autre
  répertoire) → d'où `resources/.htaccess`.
- **R5 — port en dur, sans détection de collision.** Si 55112 est déjà pris, `TCPServer` lève au
  démarrage : le démon meurt avec « Fatal error » dans son journal, `state` reste `nok`, et le core
  réessaie jusqu'au message « relancé plus de 3 fois consécutivement ». Diagnosticable, **non silencieux** ;
  l'échappatoire est la clé `demon_port`. Détecter la collision dans `etat()` coûterait un `fuser`/test de
  socket **toutes les 5 minutes** et une troisième chaîne UI : **non retenu**.
- **R6 — PID réutilisé.** `posix_getsid()` sur un PID périmé recyclé par un autre processus rendrait
  `state = ok` à tort. Comportement de **toutes** les implémentations de référence. Conséquence bornée : le
  démon n'est pas relancé jusqu'au prochain `deamon_start(true)`.
- **R7 — deux gardes du core à connaître pour la recette, sans quoi AC2 et AC4 *paraissent* en échec.**
  (a) `deamon_start()` **lève** « Vous devez attendre au moins 45 secondes entre deux lancements du démon »
  (l. 896-902) : après un Stop, attendre **≥ 45 s** avant Start. (b) `plugin::checkDeamon` **relance le
  démon tout seul dans les 5 minutes** : pour tenir AC4 (« démon volontairement arrêté »), basculer
  « Gestion automatique » à **0** dans le panneau Démon (`deamonChangeAutoMode`) — sinon la scrutation est
  testée avec un démon… redémarré.
- **R8 — la CLI doit tourner sous `www-data`.** Le pong est écrit par le processus Apache dans le cache
  (`FileCache`, moteur par défaut), relu par la CLI :
  `sudo -u www-data php core/php/pont-demon.php --ping`. Lancée en root, elle créerait des fichiers de
  cache/journal root-owned. Remarque valable aussi pour les **trois CLI existantes** — défaut préexistant,
  pas introduit ici.
- **R9 — `require: 4.2` et `http:127.0.0.1:port:comp`.** Ce gabarit d'URL est présent en V4-stable (4.4.12)
  et master (4.6.1) ; **non vérifié sur un core 4.2**. Dégradation gracieuse s'il manquait : repli sur
  `internalProtocol + internalAddr`, éventuellement en `https://` — donc un rappel TLS où le
  `verify=False` de la lib **redeviendrait porteur**. Ne pas monter `require` pour autant (décision
  produit) ; à revoir seulement si un utilisateur de 4.2 se manifeste.
- **R10 — les fichiers Python échappent au filet automatique, mais pas à toute vérification.**
  `verif-plugin.py` ne parcourt que `core/`, `desktop/`, `plugin_info/` : `resources/**.py` n'est ni balayé
  pour l'i18n, ni contrôlé pour les octets de contrôle (il l'est en revanche pour les **fins de ligne**
  quand on le lui passe **explicitement** en argument, cf. § 2.2 — et il refuse le **MIXTE**). `php -l` et
  la CI sont aveugles par nature. Le filet effectif est
  `python -m py_compile resources/smartclimd/smartclimd.py resources/smartclimd/jeedom/jeedom.py`,
  disponible sur la machine de dev (**Python 3.14.5**, vérifié) : **syntaxe seulement** — ni la présence de
  `requests` (absent de la machine de dev, présent dans le venv de la cible), ni le comportement
  d'exécution. Effet de bord `__pycache__/`, **déjà couvert** par `.gitignore`. C'est **précisément
  pourquoi** le `read_socket()` réécrit est englobé dans un `try/except Exception` : le défaut le plus
  probable de ce fichier ne doit pas se traduire par un démon qui meurt (§ 1.5).
- **R11 — contrainte léguée à UC03.** `smartclimDemon::envoyer()` est un **aller simple**, sans corrélation
  requête/réponse : le retour passe par le rappel HTTP et le cache. UC03 aura besoin d'ordres **corrélés** ;
  c'est `envoyer()` qui devra évoluer (ajout d'un identifiant d'échange), **pas** le pont. Signalé, **pas**
  implémenté ici.
- **R12 — orphelin de démon** : traité au § 6.2, à lire là.
- **R13 — le pont est mono-thread, et sa panne est SILENCIEUSE.** Le `TCPServer` du squelette n'est **pas**
  threadé : `serve_forever()` traite les connexions **séquentiellement**. Une seule connexion qui n'envoie
  jamais de `\n` immobilise donc tout le pont — et le symptôme est le pire possible : le démon ne meurt
  pas, ne journalise rien, son PID reste vivant, donc `etat()` renvoie `state = ok` et le panneau
  « Démon » affiche un démon **en pleine forme** qui ne répond plus. Bridé par le timeout et la borne de
  taille de la divergence (e) du § 2.3, et borné à un accès **local** (le port n'écoute que sur loopback).
  ⚠️ **À rouvrir au moment d'UC03**, qui fera passer du trafic réel sur ce pont : si plusieurs pairs
  doivent dialoguer concurremment, c'est `socketserver.ThreadingMixIn` qu'il faudra — et alors la file
  `JEEDOM_SOCKET_MESSAGE` devra être réexaminée, pas seulement le serveur.

## 10. Dépendances

**Un seul paquet pip3 : `requests`, version `2.31.0`** — **dans la valeur**, sans opérateur.

⚠️ **`2.31.0` n'est pas un choix conservateur par frilosité, c'est la seule valeur qui couvre la matrice
déclarée** (`os.min: 10` → Python 3.7) : cf. § 9 R3 pour la matrice `Requires-Python` et la règle de
sélection. Ne pas la « moderniser » sans relire ce risque.

Ce qui est **délibérément exclu** de cette UC :
- `websocket-client` → UC03 (WebSocket du cloud historique) ;
- `paho-mqtt` → selon le verdict d'UC01 ; ⚠️ rappel de `CLAUDE.md` : « MQTT ⇒ démon » est **faux**, une
  connexion ponctuelle tient en PHP pur — seul un **processus long** exige un démon ;
- `pycryptodome` → selon UC01/UC04 (la crypto actuelle passe par `openssl_*` en PHP) ;
- `pyserial` / `pyudev` → **évités par construction**, en retirant `jeedom_serial` et
  `find_tty_usb()` du portage (§ 2.3). Aucun matériel série n'est en jeu.

Aucune dépendance `apt`, `npm`, `yarn`, `composer`.

## 11. Recette — ordre d'exécution

1. **Avant tout**, relever le plancher de `requests` (R3) et l'inscrire dans `packages.json`.
2. Mettre à jour le plugin depuis le market → observer l'installation détachée (R1) dans
   `log/smartclim_packages`, et **AC1** dans les deux panneaux.
3. **AC5** : `sudo -u www-data php core/php/pont-demon.php --etat` puis `--ping`. En cas d'échec, R2
   d'abord.
4. **AC2** : Stop, **attendre ≥ 45 s** (R7a), Start. Vérifier `log/smartclim` **et**
   `log/smartclim_demon` : aucune erreur.
5. **AC3** : `sudo systemctl restart jeedom` (ou redémarrage complet) → l'indicateur redevient actif sans
   intervention.
6. **AC4** : « Gestion automatique » du démon à **0** (R7b), Stop, puis vérifier qu'un cycle cron et une
   commande d'action fonctionnent inchangés.
7. **AC6** : `grep` des journaux `smartclim`, `smartclim_demon` et `smartclim_packages` sur l'`apikey` du
   plugin — **zéro occurrence** attendue.
8. **AC7** : deux passages de vérification de dépendances consécutifs → indicateur **stable**, aucune
   réinstallation.

## 12. Dette

Arrêtée après le tour 1 de reviews croisées (2026-09-07). Tous les findings atteignant la gate
(`blocker`/`major`/`critical`/`high`) ont été **corrigés dans ce cycle** — ce qui suit est ce qui reste,
délibérément.

- **D-05-02-04 — le pont reste mono-thread** (R13) : bridé par un timeout et une borne de taille, pas
  rendu concurrent. `ThreadingMixIn` n'est pas justifié tant qu'un seul appelant (la CLI) émet sur le
  pont ; **à rouvrir à UC03**, avec la file `JEEDOM_SOCKET_MESSAGE`.
- **D-05-02-05 — fenêtre de DoS local résiduelle : « trickle lent ».** Le `timeout = 5` de
  `jeedom_socket_handler` est appliqué par `StreamRequestHandler.setup()`
  (`self.connection.settimeout(5)`), mais il borne **chaque appel bloquant individuel** (chaque `recv()`
  interne à `readline()`), **pas la durée totale de la connexion** : un client local qui envoie 1 octet
  toutes les 4 s ne déclenche jamais le timeout et retient l'unique thread bien au-delà de 5 s, jusqu'à
  `TAILLE_MAX_MESSAGE`. La fenêtre passe donc d'**illimitée** à *bornée par octet lu* — fortement réduite,
  pas fermée. Accepté : le socket est lié à `127.0.0.1`, l'attaquant doit **déjà** avoir un accès local.
  Fermeture éventuelle avec D-05-02-04 (modèle threadé ou deadline absolue), pas avant.
- ⚠️ **D-05-02-06 — `requests` 2.31.0 porte CVE-2024-35195, et c'est une contrainte, pas un oubli.**
  Le pin `2.31.0` imposé par `os.min: 10` (Python 3.7, cf. § 9 R3) réintroduit
  CVE-2024-35195 / GHSA-9wx4-h78v-vm56 : un objet `Session` ayant fait une requête avec `verify=False`
  continue d'ignorer la vérification TLS pour les requêtes **suivantes**. Corrigé en **2.32.0** — qui exige
  Python ≥ 3.8, donc **inatteignable tant que `os.min` vaut 10**. Il n'existe aucune version qui satisfasse
  *à la fois* Python 3.7 et ce correctif.
  **Non exploitable dans le code livré** : `jeedom_com.__post_change()` et `.test()` appellent
  `requests.post()` / `requests.get()` au **niveau module** (`jeedom.py` l. 109 et 135), qui créent et
  referment une `Session` **éphémère par appel** — aucun état `verify=False` ne persiste d'un appel au
  suivant. S'y ajoute que le rappel est en **HTTP nu sur loopback** (§ 1.3), donc hors du champ de ce CVE.
  ⚠️⚠️ **Garde-fou à respecter** : **ne jamais introduire une `requests.Session()` persistante dans
  `jeedom_com`** sans revisiter cette version — ce serait réactiver le CVE en silence, dans un plugin dont
  l'invariant est « TLS toujours vérifié ». UC03, qui fera passer du trafic réel, est le premier candidat à
  cette tentation.
  **Décision produit ouverte, à porter à l'utilisateur** : monter `os.min` à **11** (Debian 11, Python 3.9)
  permettrait `requests` 2.32.5 et refermerait le CVE, au prix de l'abandon du support de Debian 10 (déjà
  en fin de vie). Ce n'est **pas** une décision de cette UC — la matrice de support préexiste — mais c'est
  cette UC qui la rend visible, puisqu'elle est la première à déclarer une dépendance.
- **D-05-02-01 — pas de détection de collision de port** (R5) : l'échappatoire `demon_port` n'a **aucun
  champ de formulaire** ; elle n'est réglable que par l'API JSON-RPC du core. Acceptable tant que le port
  retenu est hors des bandes des plugins officiels, à reprendre si un utilisateur se heurte au cas.
- **D-05-02-02 — aucun backoff sur échec d'installation de dépendances** (R1) : c'est le mécanisme du
  core, non contournable depuis un plugin. Seuls les deux interrupteurs `dependancyAutoMode` /
  `deamonAutoMode` en tiennent lieu. À documenter côté utilisateur plutôt qu'à coder.
- **D-05-02-03 — `resources/**.py` hors du filet `verif-plugin.py`** (R10) : le script pourrait étendre sa
  couverture à `resources/` et à l'extension `.py` (fins de ligne + octets de contrôle). Non fait dans ce
  cycle pour ne pas modifier l'outillage en même temps que la feature.
