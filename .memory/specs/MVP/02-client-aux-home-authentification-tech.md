# Spec technique — UC02 « Brique d'accès AUX Home : authentification et test de connexion »

> **Domaine** : MVP · **Spec fonctionnelle** : `02-client-aux-home-authentification.md` (AC1→AC7)
> **Dépend de** : UC01 (livrée) · **Date** : 2026-08-25
> Plan validé par l'utilisateur après revue advisor (1 blocker + 9 majors traités).

## 0. Contrats externes

### 0.1 Cloud AUX Home — enveloppe et en-têtes

Source : `.memory/analyse/smartclim-transport-aux-home.md` §§ 1-2, **recoupé le 2026-08-25** contre
`GijsZwegers/com.zwegersit.auxairco` (MIT), `lib/auxcloud/constants.ts`.

- Hôte : `https://eu-smthome-api.aux-global.com`
- Réponse : `{"code": <int>, "message": "<string>", "data": <any>}`
  ⚠️ **Le succès est `code == 200`, PAS le code HTTP.** Un `HTTP 200` avec `code != 200` est une erreur
  métier. → **c'est le cœur d'AC2**. Toujours tester **les deux**.
- En-têtes : `Accept: */*` · `Accept-Language: en-US` · `aid: 1` · `os: 2` · `country: <ISO-3>` ·
  `User-Agent: AUXAC/2.3.2 (iPhone; iOS 18.6.2; Scale/3.00)` (**valeur exacte confirmée**) ·
  `Authorization: bearer <jeton>` (**`bearer` en minuscules**) · `Content-Type: application/json` si corps.
- **TLS toujours vérifié** : jamais de `CURLOPT_SSL_VERIFYPEER = false`.

### 0.2 Séquence d'authentification

```text
GET  /app/auth/getPubkey   → data = clé publique RSA-1024 (DER base64 NU)
POST /app/auth/login/pwd   → data = { token: { token }, appUser: { uid, nickName } }
```

⚠️ **Le jeton est imbriqué : `data.token.token`**, pas `data.token`.

Corps du login :
```json
{ "password": "<base64>", "account": "<base64>", "ts": "<epoch ms, string>", "publicKeyBase64": "<clé du GET>" }
```

| Champ | Traitement |
|---|---|
| `password` | **RSA/ECB/PKCS1** avec la clé **fraîchement obtenue** ; blocs de **117 octets** (128 − 11 pour RSA-1024), blocs chiffrés concaténés, puis base64 |
| `account` | **AES-128-ECB** + padding PKCS7 (= PKCS5), clé fixe, base64 |
| `ts` | epoch **millisecondes**, en **chaîne** |
| `publicKeyBase64` | la valeur **exacte** renvoyée par `getPubkey`, telle quelle |

⚠️ **La clé publique arrive en DER base64 nu** → reconstituer un PEM :
`"-----BEGIN PUBLIC KEY-----\n" . chunk_split($der, 64, "\n") . "-----END PUBLIC KEY-----\n"`.

⚠️ **Redemander une clé publique avant CHAQUE login** — une clé réutilisée est rejetée (`code 64033`) sur
le backend cousin CN ⚠️, comportement supposé identique en EU ❓. → **aucune mise en cache de la clé
publique**. Sert aussi AC6.

### 0.3 Constantes de protocole à embarquer

Décision utilisateur actée (spec fonctionnelle § « Décisions actées ») : elles vivent **dans le code de
`smartclimAuxHomeApi`**, avec un commentaire citant **source et licence (MIT)**. Elles sont
**volontairement absentes de cette spec** — les lire dans
`GijsZwegers/com.zwegersit.auxairco`, `lib/auxcloud/constants.ts` (branche `main`) :

| Constante | Format **confirmé** le 2026-08-25 |
|---|---|
| `STATIC_APP_TOKEN` | base64, ~88 caractères. Jeton **applicatif**, pas utilisateur |
| `ACCOUNT_AES_KEY` | ⚠️ **texte ASCII brut de 16 caractères** → **utilisable directement** comme clé AES-128 en PHP, **sans** décodage hex ni base64 |
| `AUX_USER_AGENT` | `AUXAC/2.3.2 (iPhone; iOS 18.6.2; Scale/3.00)` |

*Écart assumé : la référence retombe sur `NLD` quand le fuseau est inconnu ; UC01 a choisi le champ vide.*

### 0.4 Contrats du core Jeedom

Déjà établis et consignés dans `.memory/analyse/jeedom-config-plugin-et-cycle-de-vie.md` — **les relire,
ne pas les redécouvrir**. Ceux qui pèsent sur UC02 :

- `postConfig_<clé>` d'une clé chiffrée reçoit **le chiffré** → on ignore l'argument, on purge, c'est tout.
- `postConfig_<clé>` est appelé **aussi** dans la branche « valeur == défaut INI » de `config::save`.
- ⚠️ **`config::remove()`** ne déclenche **pas** `postConfig_*` → la purge de session doit être
  **explicite** dans l'action d'effacement (§ 1.7), pas déléguée au hook.
- ❓ **À confirmer à l'implémentation** : `cache::byKey()` renvoie-t-il un objet même pour une entrée
  expirée ? Si oui, tester explicitement la validité à la relecture (§ 1.5).
- ❓ **À confirmer à l'implémentation** : `ajax::init()` ferme-t-il déjà la session PHP ? Sinon → § 1.6.

## 1. Architecture

| Fichier | État | Rôle |
|---|---|---|
| `core/class/smartclimException.class.php` | **nouveau** | exception **typée** (4 types) |
| `core/class/smartclimAuxHomeApi.class.php` | modifié | cURL centralisé, crypto, `login()`, `session()` |
| `core/class/smartclim.class.php` | modifié | `testerConnexionAuxHome()`, `effacerIdentifiantsAuxHome()`, 3 `postConfig_*` |
| `core/ajax/smartclim.ajax.php` | modifié | actions `testerConnexion` et `effacerIdentifiants` |
| `plugin_info/configuration.txt` (→ `.php`) | modifié | 2 boutons, zone de résultat, aide, JS inline |

**Non touchés** : `desktop/`, `core/template/`, `core/config/`, `plugin_info/install.php`, `info.json`,
`packages.json`, `core/i18n/` (traduction différée à l'étape 10).

⚠️ **Indentation** : `core/ajax/smartclim.ajax.php` existant est en **4 espaces** (et non 2 comme
l'annonce `CLAUDE.md`) — **respecter l'existant**, la règle du projet étant « respecter l'existant fichier
par fichier ». Ne pas normaliser le fichier dans cette UC (diff bruité).

### 1.1 `smartclimException` — 4 types

Fichier **propre** obligatoire : `core/ajax/smartclim.ajax.php` est un **point d'entrée externe** qui fera
`catch (smartclimException $e)` (règle d'autoload de `CLAUDE.md`).

| Type | Déclencheur | Message utilisateur |
|---|---|---|
| `TYPE_RESEAU` | échec cURL (DNS, timeout, TLS, connexion refusée) **ou** HTTP `>= 500` **ou** HTTP `429` | « Service AUX Home injoignable, réessayez plus tard » |
| `TYPE_AUTH` | HTTP `< 500` **et** `code != 200` sur **`/app/auth/login/pwd`**, hors codes connus | « Échec — vérifiez vos identifiants **et le pays** » |
| `TYPE_PROTOCOLE` | corps non-JSON, enveloppe absente, `data.token.token` manquant, `code != 200` sur `getPubkey`, ou codes **`9023`** / **`64033`** | « Réponse inattendue du service AUX Home… » |
| `TYPE_INTERNE` | chiffrement **local** impossible (PEM inexploitable, OpenSSL en échec) | « Erreur interne lors de la préparation de la connexion… » |

**Ordre de classement — impératif, c'est là que se jouent AC2/AC3/AC5** :

1. erreur cURL → `TYPE_RESEAU` ;
2. **sinon HTTP `>= 500` ou `429` → `TYPE_RESEAU`** (une panne du frontal ou un rate-limit **n'est pas**
   un problème d'identifiants — sans cette règle, AC5 est faux dès que le serveur répond une erreur avec
   une enveloppe JSON) ;
3. sinon corps non-JSON / enveloppe absente → `TYPE_PROTOCOLE` ;
4. sinon `code == 200` → succès ;
5. sinon `code ∈ {9023, 64033}` → **`TYPE_PROTOCOLE`** ⚠️ — `9023` = *notre* chiffrement est faux,
   `64033` = *notre* clé publique est périmée. Ce ne sont **pas** des erreurs d'identifiants, et ce sont
   les deux échecs **les plus probables au premier test réel**. Les annoncer « vérifiez vos identifiants »
   enverrait l'utilisateur **et** le développeur chercher au mauvais endroit ;
6. sinon, sur le **login** → `TYPE_AUTH` ; sur `getPubkey` → `TYPE_PROTOCOLE`.

⚠️ **`getPubkey` envoie déjà l'en-tête `country`** : son message d'échec doit donc **aussi** inviter à
vérifier le code pays, sans quoi un pays invalide échouerait dès la première requête avec un message
muet sur la cause — et AC3 serait manqué.

Le `code` et le `message` du backend sont **journalisés en `error`** (indispensables au diagnostic, et
invisibles par défaut si journalisés en `debug`), mais **jamais affichés bruts** (la spec interdit
« un code d'erreur nu »).

### 1.2 `smartclimAuxHomeApi` — surface publique

```php
smartclimAuxHomeApi::login()          // array('jeton','uid','pseudo') — TOUJOURS frais : getPubkey + login
                                      //   ET écrit la session en cache (§ 1.5)
smartclimAuxHomeApi::session()        // array('jeton','uid') — LIT le cache si valide, sinon login()  [UC03+]
smartclimAuxHomeApi::purgerSession()  // void
```

⚠️ **`session()` doit renvoyer la MÊME forme de tableau sur ses deux branches** — exactement
`array('jeton', 'uid')`, y compris sur le repli `login()` (qui renvoie en plus `pseudo`). Sinon
`$session['pseudo']` fonctionnerait au premier appel (cache vide) puis lèverait « undefined array key »
**pendant 30 minutes** : panne intermittente, très difficile à rattacher à sa cause.

⚠️ **Répartition des rôles vis-à-vis du cache — à ne pas inverser** : `login()` **écrit**, `session()`
**lit** (et retombe sur `login()` si l'entrée est absente, expirée ou d'empreinte divergente).
C'est ce qui rend le **chemin d'écriture et la purge testables dès UC02** (points 9-10 de la recette),
alors que seul le chemin de **relecture** attend UC03. Une implémentation où seul `session()` écrirait
rendrait ces deux points de recette systématiquement faux et ferait conclure à un bug.
✅ **Compatible AC6** : l'exigence est que le test ne **réutilise** pas une session obtenue avec
d'anciens identifiants — rien ne lui interdit d'en **écrire** une fraîche après un login réussi.

Les deux méthodes publiques **recréent l'exception** avant de la propager (`catch (smartclimException)`
→ `throw new smartclimException(même message, même type, même contexte)`). Motif : `requete()` porte le
corps chiffré et le jeton en **paramètres**, et ses `throw` naissent dans cette frame — leurs traces les
embarquent. Recréer l'exception fait **mourir la trace d'origine à l'intérieur de la brique**, et la
garantie vaut pour tout appelant futur sans discipline à maintenir. Cf. § 3.1.

`session()` **pose la garde `smartclim::compteConfigure()`** avant de retomber sur `login()` : sans elle,
un appelant qui l'oublierait (le cron d'UC07) verrait « Erreur interne » (`TYPE_INTERNE` sur mot de passe
vide) au lieu de « Compte non configuré ».
`login()` **vérifie la non-vacuité du mot de passe avant tout appel réseau** (en ligne, sans variable
nommée) : sinon un appelant contournant la garde émettrait un `getPubkey` avec un mot de passe vide,
contraire au contrat « zéro requête si compte non configuré » d'UC01.

`session()` renvoie un **tableau**, pas une chaîne : UC03 aura besoin du `jeton`, et le domaine
post-mvp/05 (MQTT, souscription `dev2app/<uid>/#`) du `uid`. Coût nul aujourd'hui, évite de rouvrir la
classe dès la première ligne d'UC03.

Privées : `requete()` (**le seul point cURL du plugin**), `clePublique()`, `chiffrerMotDePasse()`,
`chiffrerCompte()`, `derVersPem()`.

### 1.3 Budget de temps — contrainte dimensionnante

AC1 : succès **< ~15 s**. AC5 : échec réseau **< 20 s**. Or un login = **2 requêtes séquentielles**.

```php
const TIMEOUT_CONNEXION = 5;   // CURLOPT_CONNECTTIMEOUT
const TIMEOUT_REQUETE   = 10;  // CURLOPT_TIMEOUT par requête
const BUDGET_LOGIN      = 18;  // plafond global des 2 requêtes
```

⚠️ **Ne pas se reposer sur le timeout par requête pour tenir un AC exprimé en budget global** :
`CURLOPT_TIMEOUT` peut être inopérant pendant `getaddrinfo()` selon le build de libcurl (absence de
`AsynchDNS` + `CURLOPT_NOSIGNAL`). Un DNS injoignable bloquerait alors bien au-delà.

→ **Avant la 2ᵉ requête, calculer le temps restant** : `CURLOPT_TIMEOUT = max(3, BUDGET_LOGIN - écoulé)`.
Pire cas borné à ~18 s < 20 s ✅, cas nominal très en deçà des 15 s d'AC1 ✅.
Poser aussi `CURLOPT_DNS_CACHE_TIMEOUT`. Journaliser `CURLINFO_TOTAL_TIME` et `CURLINFO_NAMELOOKUP_TIME`
en `debug` — seule donnée permettant de calibrer sur le terrain, et sans aucun secret.

### 1.4 ⚠️ Le verrou de session PHP — sinon « sans blocage de la page » est faux

Jeedom utilise des sessions **fichier** : tant que la session n'est pas fermée, **toute autre requête de
la même session est sérialisée** derrière ce handler qui tient jusqu'à 18 s. La page de configuration, le
menu et les autres AJAX se figent — exactement ce que la spec fonctionnelle interdit.

→ **`session_write_close()` immédiatement après `isConnect('admin')` / `ajax::init()`, et AVANT tout
appel réseau.** Vérifier d'abord si `ajax::init()` le fait déjà (§ 0.4) ; l'appel est de toute façon
inoffensif s'il est redondant.

### 1.5 Session en cache — exigée par AC6

`cache::set('smartclim::session_auxhome', utils::encrypt(json_encode($session)), 1800)` — **chiffré**
avant mise en cache (`CLAUDE.md`). **Durée : 30 minutes** (décision utilisateur), la durée de vie réelle
du jeton étant inconnue jusqu'à UC08 qui portera la reprise réactive.

Le tableau mis en cache contient `jeton`, `uid` **et une empreinte** `sha1($email . '|' . $pays)`.
À la relecture, **si l'empreinte diffère → purge et re-login** : couvre les changements d'identifiants
qui ne passent pas par `config::save` (restauration de sauvegarde, écriture SQL, migration), que les
hooks `postConfig_*` ne voient pas.
🚫 **Ne jamais inclure le mot de passe dans l'empreinte** — cela le remettrait sur la pile (§ 3.1).

- ⚠️ **`testerConnexionAuxHome()` n'utilise JAMAIS le cache** : il appelle `login()` → AC6.
- **Purge sur changement d'identifiants** : `postConfig_auxhome_password`, `postConfig_auxhome_email`,
  `postConfig_auxhome_country` → `smartclimAuxHomeApi::purgerSession()`. Explicitement autorisé par
  UC01 § 4 (le hook ne **lit** rien, il purge).

### 1.6 `core/ajax/smartclim.ajax.php` — deux actions

Structure existante conservée (`isConnect('admin')`, `ajax::init()`, branches `if (init('action') == …)`).
Les deux actions appellent **`smartclim::`**, jamais `smartclimAuxHomeApi` directement : `CLAUDE.md`
impose que le reste du plugin parle à l'API générique, pas à un transport.

- succès → `ajax::success(array('message' => <texte français>))`
- échec → `ajax::error(<texte français>, <code FIXE>)`

⚠️ **Le code passé à `ajax::error()` est figé** (`0`, ou le type d'exception 1..4) — **jamais** le `code`
métier AUX ni le statut HTTP : la spec interdit d'afficher un code d'erreur nu, et le toast Jeedom
affiche ce code.

⚠️ **Ne JAMAIS appeler `displayException()` sur une `smartclimException`** — uniquement
`$e->getMessage()` (message curaté). `displayException()` reste réservé au `catch (Exception)` générique
de secours (§ 3.1).

### 1.7 Action `effacerIdentifiants` — contrat hérité d'UC01

UC01 avait retiré la purge de `smartclim_remove()` (elle détruisait les identifiants à **chaque
désactivation** du plugin) en la déléguant à une **action volontaire** de l'utilisateur. Elle est honorée
ici (décision utilisateur).

- `config::remove('auxhome_email', 'smartclim')` et `config::remove('auxhome_password', 'smartclim')`.
- **Puis `smartclimAuxHomeApi::purgerSession()` explicitement** — `config::remove()` ne déclenche pas
  `postConfig_*` (§ 0.4).
- **On ne touche ni au pays ni à l'intervalle** : ce ne sont pas des identifiants, et vider le pays serait
  de toute façon vain — `preConfig_auxhome_country` le re-déduirait du fuseau (UC01 § 1.4).
- Côté client : **confirmation `bootbox.confirm` obligatoire** avant l'appel (action destructive).
- ⚠️⚠️ **Puis RECHARGEMENT de la page** après affichage du message de succès
  (`setTimeout(function () { window.location.reload(); }, 1200)`).
  **Sans cela, l'effacement est annulable silencieusement** : la modale reste ouverte avec les champs
  `.configKey` **toujours peuplés dans le DOM** (le core y a mis l'e-mail et le mot de passe en clair au
  chargement — carve-out UC01), et comme la modale réenvoie **toutes** les clés à chaque « Sauvegarder »,
  l'utilisateur qui efface, voit le succès, puis corrige l'intervalle et sauvegarde **réécrit ses deux
  secrets en base**, convaincu du contraire. C'est précisément la garantie qu'UC01 § 1.6 avait retirée de
  `smartclim_remove()` pour la déléguer à cette action : sans le rechargement, elle n'est portée par rien.
  🚫 **Ne JAMAIS toucher un champ `.configKey` en JS** pour « refléter » l'effacement — geste interdit par
  UC01, qui écraserait le secret au prochain enregistrement. Le rechargement repart de l'état serveur.
- ⚠️ **Le rechargement doit aussi avoir lieu sur le chemin d'ERREUR**, et les boutons être désactivés
  **immédiatement**. Trois trous ramènent sinon exactement le scénario ci-dessus :
  - effacement **réussi côté serveur** mais échec côté client (coupure réseau après traitement,
    redémarrage d'Apache) → callback `error:`, DOM toujours peuplé, un « Sauvegarder » ressuscite tout ;
  - échec **partiel** côté serveur (le 1ᵉʳ `config::remove()` passe, le 2ᵈ ou la purge lève) → idem, avec
    en plus une session non purgée ;
  - la **fenêtre du `setTimeout`** : le bouton « Sauvegarder » du core y reste actif.
  → sur le chemin d'erreur, l'état serveur est **indéterminé** : le rechargement est la seule
  resynchronisation fiable du DOM.

### 1.8 `plugin_info/configuration.txt` — boutons et JS

Nouveau bloc en pied du fieldset « Compte AUX Home » : bouton **« Tester la connexion »**, bouton
**« Effacer les identifiants »** (style `btn-danger`), `<span>` de résultat, et un **`help-block`
visible** (décision utilisateur) :
`{{Le test utilise les identifiants enregistrés : enregistrez vos modifications avant de tester.}}`

Motif : le JS n'envoie **aucun secret** au serveur (§ 2), le test porte donc sur les identifiants
**enregistrés**. Sans cet avertissement, un utilisateur qui corrige son mot de passe puis teste sans
enregistrer obtient un faux échec parfaitement crédible — le scénario nominal d'AC6.

JS **inline** dans ce fichier : la modale de config plugin ne charge **pas** `desktop/js/smartclim.js`
(celui-ci est inclus par `desktop/php/smartclim.php`).
Comportement : bouton désactivé pendant l'appel, libellé « Test de connexion en cours… », résultat en
vert/rouge, `timeout` client **22 s** (budget serveur 18 s + marge).
⚠️ Le timeout client **n'interrompt pas le PHP** — il évite seulement que l'UI attende indéfiniment. La
seule borne réelle est **serveur** (§ 1.3).
⚠️ **Rappel critique UC01** : ne **jamais** vider ni réécrire en JS la valeur d'un champ `configKey` — la
modale réenvoie toutes les clés à l'enregistrement, un champ vidé écraserait le secret stocké. Le JS de
cette UC **ne touche à aucun champ**.

⚠️ Procédure : éditer **uniquement** le `.txt`, puis `cp plugin_info/configuration.txt
plugin_info/configuration.php`, vérifier par `git status --short plugin_info/configuration.php`
(`git diff --numstat` sur les deux chemins est un contrôle plus fort, également disponible).

## 2. Server vs Client

**Toute la logique est serveur.** Le client déclenche l'appel et affiche une chaîne **déjà traduite**.

Justification : la crypto, le contrat d'enveloppe et le classement des erreurs ne doivent exister qu'à un
seul endroit, et **aucun secret ne doit transiter vers le navigateur** — le JS n'envoie ni e-mail, ni mot
de passe, ni pays ; le serveur lit tout depuis la configuration. Conséquence assumée et compensée par le
`help-block` du § 1.8.

## 3. Sécurité — AC4 est le point dur

### 3.1 ⚠️⚠️ Le piège de la trace d'exception PHP — protection en trois couches

Une trace PHP inclut **les arguments** de chaque frame. Une exception née pendant que le mot de passe en
clair est un **argument** de la frame courante l'exposerait — et `displayException()` peut faire remonter
cette trace jusqu'à la réponse AJAX, donc jusqu'au DOM. **C'est le scénario qui viole AC4.**

UC01 § 4 l'avait déjà interdit : « **aucune** fonction du plugin ne prend le mot de passe en paramètre ».
Cette UC doit chiffrer le mot de passe — d'où trois couches, **toutes obligatoires** :

1. **Aucun paramètre porteur du clair.** `chiffrerMotDePasse($pem)` prend la **clé publique**, jamais le
   mot de passe : elle lit `config::byKey('auxhome_password', 'smartclim')` **elle-même**, au plus près de
   l'usage — même pattern que `compteConfigure()` en UC01.
2. **La crypto est enveloppée dans un `try { … } catch (Throwable $t) { return false; }`** interne. Même
   si un `set_error_handler` du core convertissait un warning `openssl_*` en `ErrorException` — y compris
   avec un **fragment de 117 octets du mot de passe** en argument de `openssl_public_encrypt()` —
   l'exception est **capturée et jetée sur place**, jamais propagée.
   🚫 Ne **jamais** journaliser `$t->getMessage()` ni `$t->getTraceAsString()` ici : message **fixe** +
   `openssl_error_string()` uniquement (la pile OpenSSL ne contient jamais de données).
3. **L'AJAX n'appelle jamais `displayException()` sur une `smartclimException`** (§ 1.6).

4. **Les méthodes publiques de la brique (`login()`, `session()`) recréent l'exception** avant de la
   propager. Motif : `requete()` porte le **corps chiffré** (mot de passe RSA + `account` AES) et le
   **jeton** en *paramètres*, et ses `throw` naissent dans cette frame — leurs traces les embarquent.
   `testerConnexionAuxHome()` neutralisait déjà cela par effet de bord, mais `session()` propageait
   l'originale — et `session()` est le point d'entrée d'UC03. Recréer l'exception fait mourir la trace
   d'origine **dans** la brique.
5. **L'AJAX rattrape aussi `Throwable`**, en 3ᵉ bloc après `smartclimException` et `Exception`. Sans lui,
   une `Error` PHP 8 (`TypeError` sur `curl_setopt(false, …)` si `curl_init()` échoue) traverse tout,
   la réponse cesse d'être du JSON et peut exposer chemins et trace. Message fixe, code `0`, **jamais**
   `displayException()`.

*(Une 6ᵉ couche existe — `zend.exception_ignore_args=On` par défaut depuis PHP 7.4 — mais elle dépend du
`php.ini` de l'hôte : **on ne s'y fie pas**.)*

⚠️ **Le jeton est validé avant toute concaténation d'en-tête** (`/^[A-Za-z0-9._~+\/=-]{8,4096}$/`), à la
réception **et** à la relecture du cache. C'est la seule valeur d'en-tête d'origine externe : un CRLF y
injecterait des en-têtes arbitraires dans toutes les requêtes authentifiées, empoisonnés 30 min via le
cache. Le contrat UC01 § 9 exigeait des en-têtes bâtis sur des valeurs **déjà filtrées**.

### 3.2 Journalisation (AC4)

### 3.2bis ⚠️ Journaliser en `error` — cinq messages y renvoient l'utilisateur

Cinq des messages utilisateur disent « consultez les logs du plugin ». **Ils doivent y trouver quelque
chose.** Deux chemins étaient muets et sont désormais obligatoires :

1. **Avant chaque re-throw curaté** (`testerConnexionAuxHome()`) : journaliser en `error` le type **et le
   message technique** de l'exception d'origine. Ce message est contractuellement exempt de secret
   (§ 1.1), donc AC4 est préservé. Sans cela, un échec réseau ou protocolaire ne laisse **aucune trace**.
2. **Sur chaque `return false` de la crypto** : message **fixe** + `openssl_error_string()`.
   ⚠️⚠️ **`openssl_public_encrypt()` sur un PEM inexploitable renvoie `false` en émettant un *warning*, il
   ne lève PAS d'exception** → le `try/catch (Throwable)` du § 3.1 **ne couvre pas ce chemin**. Or c'est le
   scénario **le plus probable au premier test contre le vrai backend**, et sans journalisation il produit
   « Erreur interne » avec zéro ligne de log.
   ⚠️ `openssl_error_string()` ne dépile qu'**une** erreur : **boucler** pour vider la file, sinon les
   suivantes seront attribuées à un appel ultérieur sans rapport.
   🚫 Jamais `$t->getMessage()` ni `$t->getTraceAsString()` ici (§ 3.1 couche 2).

### 3.2ter ⚠️ Toute donnée externe journalisée doit être neutralisée — dans les DEUX sens

Le piège s'est présenté deux fois, symétriquement : on neutralise scrupuleusement ce qui vient du
**backend** et on journalise crûment ce qui vient du **client** (ou l'inverse). **Règle unique** : toute
valeur d'origine externe journalisée passe par `is_scalar` → filtre → troncature.

- **Ordre de traitement d'un `message` backend** — les trois étapes répondent à trois problèmes
  **distincts**, ne pas les confondre (une première version de cette spec le faisait, à tort) :
  1. **filtrer les caractères de CONTRÔLE** (`[\x00-\x1F\x7F]`) → ferme l'**injection de log** ;
  2. **garantir la validité UTF-8** — si `preg_match('//u', $m) !== 1`, alors seulement retomber sur un
     filtre imprimable → évite qu'un UTF-8 invalide casse le `json_encode` de la visionneuse de logs de
     Jeedom (déni de diagnostic) ;
  3. **neutraliser les suites base64** (`/[A-Za-z0-9+\/]{16,}={0,2}/` → `[b64]`) → **c'est CELA, et rien
     d'autre, qui borne l'écho d'un champ chiffré** ;
  puis tronquer (~120 pour un `message` backend, ~40 pour une entrée client).

  ⚠️ **Deux erreurs de raisonnement à ne pas refaire** :
  - **Un filtre « imprimables » ne bloque PAS le base64** — le base64 *est* intégralement imprimable.
  - **Aucune troncature ne protège d'un écho de champ chiffré en ECB.** L'AES-128-**ECB** chiffre bloc par
    bloc : 24 caractères de base64 livrent déjà les 16 premiers caractères du courriel, et 120 en livrent
    79. Et la clé `ACCOUNT_AES_KEY` étant **en dur et publique**, quiconque lit le log déchiffre.
  - ⚠️ **Coût caché du filtre imprimable s'il est appliqué seul** : tout message backend non-ASCII —
    accentué, ou **chinois, fréquent sur les backends AUX** — devient une suite d'espaces. On perd
    l'information exactement dans le cas d'échec de login qu'on cherche à diagnostiquer.
- **Caster ce qui doit être numérique** : un `is_scalar()` accepte n'importe quelle **chaîne**. Le `code`
  de l'enveloppe doit être casté en `(int)` **partout**, y compris au point de journalisation — sinon
  `{"code":"9023\n[faux log]"}` forge des lignes.
- **Vider la file d'erreurs OpenSSL AVANT l'opération, pas seulement après** : elle est **globale au
  processus** et PHP ne la remet pas à zéro entre les appels. Sans purge en entrée, une erreur laissée
  par `utils::decrypt`, par la poignée TLS de cURL ou par un autre plugin de la même requête est
  attribuée à notre chiffrement.
- ⚠️ **Une regex de validation d'en-tête doit finir par `\z`, pas par `$`** : en PCRE, sans modificateur
  `D`, `$` matche **aussi juste avant un `\n` final**. Un jeton `"AAAA\n"` passerait, et le `\n` suivi du
  `\r\n` de cURL **clôt le bloc d'en-têtes** — les en-têtes suivants basculent dans le corps.

### 3.3 Journalisation — périmètre

- **Autorisé** : chemin appelé, code HTTP, `code` métier, `message` du backend, durées cURL.
  ⚠️ Le `message` du backend est une chaîne **contrôlée par le serveur distant** : la **neutraliser et la
  borner** avant journalisation (`preg_replace('/[\x00-\x1F\x7F]/', ' ', …)` puis `substr(…, 0, 200)`,
  filtre en octets sans `/u`). Sans cela : injection de journal (un `\n` forge des lignes ressemblant à
  des entrées Jeedom légitimes), et risque AC4 indirect si le backend écho un champ de la requête — dont
  `account` chiffré, nommément interdit. Contrôler aussi le **type** de `code` (un tableau passerait un
  simple `isset`).
- **Interdit** : corps de requête (il contient `password` et `account` chiffrés), mot de passe sous toute
  forme, `account` chiffré, jeton complet. Jeton en `debug` : **6 premiers caractères maximum**.
- 🚫 **`CURLOPT_VERBOSE`, `CURLOPT_STDERR` et `CURLOPT_DEBUGFUNCTION` sont INTERDITS** : le mode verbose
  écrit les **en-têtes** sur stderr, donc `Authorization: bearer <jeton complet>` dans le log du serveur
  web — violation directe d'AC4 par un flag qu'on active « cinq minutes pour debug » et qu'on oublie.
- Rien de tout cela dans la réponse AJAX ni dans le DOM.

### 3.3 Autres

- TLS vérifié (§ 0.1). Garde `smartclim::compteConfigure()` **avant tout appel réseau** (contrat UC01).
- ⚠️ Contrat UC01 § 9 : le mot de passe part **exclusivement dans le corps JSON**, jamais dans un en-tête
  ni une query string (c'est la seule clé sans normalisation, la double barrière ne le couvre pas).

## 4. Validation — cartographie complète des cas

| Situation | Type | Message |
|---|---|---|
| Compte non configuré (e-mail **ou** mot de passe vide) | — | « Compte non configuré : renseignez l'e-mail et le mot de passe » · **zéro requête réseau** |
| **Pays indéductible** (vide après normalisation) | — | **message dédié** invitant à saisir le code ISO-3 · **zéro requête** |
| Échec cURL (DNS, timeout, TLS, refus) | `RESEAU` | « injoignable » (**AC5**) |
| HTTP `>= 500` ou `429` | `RESEAU` | « injoignable » (**AC5**) |
| Corps non-JSON / enveloppe absente | `PROTOCOLE` | « réponse inattendue » |
| `code != 200` sur `getPubkey` | `PROTOCOLE` | « requête initiale refusée — **vérifiez le code pays** » |
| `code ∈ {9023, 64033}` | `PROTOCOLE` | « réponse inattendue — consultez les logs » |
| Autre `code != 200` sur le login | `AUTH` | « vérifiez vos identifiants **et le pays** » (**AC2, AC3**) |
| `data.token.token` absent | `PROTOCOLE` | « réponse inattendue » |
| Chiffrement **local** impossible | `INTERNE` | « erreur interne — consultez les logs » |
| `code == 200` + jeton présent | — | « Connexion réussie » (**AC1**) |

⚠️ **Le pays indéductible a son propre message** : c'est un contrat explicite d'UC01 § 9 — un utilisateur
hors table Europe enregistre son compte sans erreur, puis doit **savoir que c'est le pays qui bloque**.
`compteConfigure()` renvoyant un simple `bool`, tester les conditions **séparément** avant de l'appeler.

## 5. Server Actions / API

```php
// core/class/smartclim.class.php — point d'accès neutre (le reste du plugin ne parle pas au transport)
smartclim::testerConnexionAuxHome()      // string message succès — lève smartclimException sinon
smartclim::effacerIdentifiantsAuxHome()  // void
smartclim::postConfig_auxhome_password($v) // purge la session ; IGNORE $v (c'est le chiffré)
smartclim::postConfig_auxhome_email($v)    // idem
smartclim::postConfig_auxhome_country($v)  // idem

// core/class/smartclimAuxHomeApi.class.php — brique de transport
smartclimAuxHomeApi::login()             // array('jeton','uid','pseudo') — toujours frais
smartclimAuxHomeApi::session()           // array('jeton','uid') — cache 30 min sinon login()
smartclimAuxHomeApi::purgerSession()     // void

// core/ajax/smartclim.ajax.php
action=testerConnexion       → ajax::success(array('message'=>…)) | ajax::error(<fr>, <code figé>)
action=effacerIdentifiants   → idem
```

⚠️ **Tous les `__()` de messages destinés à l'utilisateur vivent dans `smartclim.class.php`**, pas dans
`smartclimAuxHomeApi` ni dans l'AJAX. Motif i18n : une clé est indexée **sous le fichier où vit l'appel
`__()`** — les éparpiller produirait plusieurs entrées pour une même intention. La brique de transport ne
porte qu'un **type** et un contexte technique.

## 6. Dépendances

**Aucune.** `openssl_*` (RSA + AES) et cURL sont natifs et déjà utilisés par le core Jeedom.
`packages.json` reste vide, `hasDependency: false`, `hasOwnDeamon: false`. Pas de démon.

## 7. Impact i18n — **français uniquement** (traduction différée à l'étape 10)

Dans `plugin_info/configuration.txt` → `.php` (`{{…}}`) :
`{{Tester la connexion}}` · `{{Effacer les identifiants}}` · `{{Test de connexion en cours…}}` ·
`{{Le test utilise les identifiants enregistrés : enregistrez vos modifications avant de tester.}}` ·
`{{Le test n'a pas répondu à temps}}` ·
`{{Effacer l'e-mail et le mot de passe du compte AUX Home ?}}` (confirmation)

Dans `core/class/smartclim.class.php` (`__(…, __FILE__)`) :
`Connexion réussie au compte AUX Home` · `Échec de la connexion — vérifiez vos identifiants et le pays
sélectionné` · `Service AUX Home injoignable, réessayez plus tard` · `Réponse inattendue du service AUX
Home — consultez les logs du plugin` · `Le service AUX Home a refusé la requête initiale — vérifiez le
code pays (FRA, BEL…)` · `Erreur interne lors de la préparation de la connexion — consultez les logs du
plugin` · `Compte AUX Home non configuré : renseignez l'e-mail et le mot de passe` · `Pays du compte AUX
Home introuvable : saisissez le code ISO à 3 lettres (FRA, BEL…) dans le champ Pays` · `Identifiants
effacés`

Clés ajoutées après les reviews : `{{L'effacement des identifiants a échoué}}` (la chaîne de timeout du
test ne doit **pas** être réutilisée ici — message faux sur une action destructive, l'utilisateur ne
saurait pas si l'effacement a eu lieu) · `Erreur interne du plugin — consultez les logs` (dans
`core/ajax/smartclim.ajax.php`, pour le `catch (Throwable)`) · éventuellement une chaîne distincte pour un
échec de transport non lié au timeout.

⚠️⚠️ **Les chaînes injectées dans le JS doivent être délimitées par des GUILLEMETS DOUBLES.** Une
traduction contenant une apostrophe — courante en `es_ES`, `fr_FR`, `de_DE` — casserait le script de la
modale de configuration : **panne silencieuse, invisible à `php -l` comme à la CI**. À vérifier sur les
**cinq** chaînes JS (le handler `error` discriminant et la chaîne d'effacement dédiée en ont ajouté deux
après la rédaction initiale), et à rappeler au sous-agent de traduction : **pas de guillemet double ni
d'antislash** dans ces cinq traductions.
⚠️ Le commentaire de garde placé dans `configuration.txt` **énumère les lignes concernées** : c'est le
seul rappel que verra le sous-agent de traduction, il doit rester exact quand une chaîne JS est ajoutée.

⚠️ **Une seule chaîne pour « test en cours »** — ne pas en créer deux variantes.
⚠️ **Consigne étape 10** : indexer les `{{…}}` sous `plugins/smartclim/plugin_info/configuration.php`
(**jamais** sous le miroir `.txt`) et les `__()` sous `plugins/smartclim/core/class/smartclim.class.php`.
Le périmètre de traduction reste **strictement** les clés d'UC02 ; les chaînes préexistantes du squelette
relèvent de `post-mvp/07`, UC04.

## 8. Checklist de recette — sur le Jeedom réel

1. Identifiants valides → « Connexion réussie » en **< 15 s**. *(AC1)*
2. Mot de passe erroné → message d'échec explicite, **jamais** un succès. *(AC2)*
3. Pays valide remplacé par un code faux (`ZZZ`) → le message **mentionne le pays**. *(AC3)*
4. Après un test réussi **et** un test échoué : `grep -ri '<motdepasse>' /var/www/html/log/` → **aucun
   résultat** ; vérifier aussi qu'aucun jeton complet ni `account` chiffré n'apparaît. *(AC4)*
5. Couper **la passerelle** du serveur Jeedom (route noire, pas le câble — c'est le seul scénario
   discriminant pour le DNS) → message « injoignable » en **< 20 s**, page non figée. *(AC5)*
6. Pendant un test lent, cliquer ailleurs dans Jeedom (menu, autre page) → **l'interface répond**
   (non-régression du verrou de session, § 1.4).
7. Changer le mot de passe puis tester **immédiatement** → le résultat reflète le **nouveau** mot de
   passe. *(AC6)*
8. Tous les messages affichés sont en français. *(AC7)*
9. Après un test réussi : la clé de cache `smartclim::session_auxhome` **existe** et son contenu est
   **chiffré** (illisible en clair).
10. Enregistrer un nouveau mot de passe → la clé de cache **disparaît** (hook `postConfig_*`).
11. Bouton « Effacer les identifiants » → confirmation demandée, puis e-mail et mot de passe vidés,
    cache purgé, pays et intervalle **inchangés**, **et la page se recharge**.
11bis. ⚠️ **Non-régression de l'effacement** : après un effacement, **sans recharger manuellement**,
    cliquer « Sauvegarder » → vérifier que `auxhome_email` et `auxhome_password` restent **absentes** de
    la table `config`. C'est le test qui prouve que la garantie d'effacement existe réellement.
14. Provoquer un échec de chiffrement (ex. faire renvoyer une clé publique tronquée) → le message est
    « erreur interne » **et** le log `error` contient une ligne avec la pile OpenSSL. Motif : c'est le
    chemin où `openssl_public_encrypt()` renvoie `false` **sans** lever d'exception, donc celui que le
    `catch (Throwable)` ne couvre pas.
15. Après un échec réseau (point 5) → vérifier que le log niveau `error` contient bien le message
    technique (« Could not resolve host », « HTTP 502 »…). Cinq messages utilisateur renvoient aux logs :
    ils doivent y trouver quelque chose.
12. Compte non configuré (e-mail vide) → message dédié **sans aucune requête réseau** (vérifier l'absence
    de trace d'appel dans les logs).
13. Pays vidé sur un Jeedom hors table Europe → **message dédié au pays**, distinct du précédent.

## 9. Contrats transmis aux UC suivantes

- **UC03** : consommer `smartclimAuxHomeApi::session()` (et non `login()`) — c'est son premier
  consommateur réel, donc la **première recette du chemin de relecture du cache**. La garde
  `compteConfigure()` est désormais **posée dans `session()`**, plus besoin de la répéter. Le `uid` est
  déjà disponible dans la session.
  ⚠️⚠️ **Le piège le plus probable de la première heure d'UC03** : `messageErreurAuxHome()` est
  **privée** et n'est appelée que par `testerConnexionAuxHome()`. Si UC03 laisse remonter une
  `smartclimException` sans passer par un point d'accès `smartclim::` qui **curate** le message, c'est le
  **message technique brut** (« AUX Home login : code métier 9023 ») qui atterrira dans le DOM — la spec
  fonctionnelle interdit d'exposer un code d'erreur nu. Tout nouveau point d'accès public de `smartclim`
  doit reprendre le motif de `testerConnexionAuxHome()` : journaliser le technique en `error`, puis
  re-lever avec le message curaté.
- **Écart assumé** : les deux gardes « zéro requête » (compte non configuré / pays introuvable) sont
  typées `TYPE_AUTH` là où le § 4 note « — ». Leur message est déjà curaté et ne repasse pas par
  `messageErreurAuxHome()`, donc sans conséquence. Si un appelant futur devait **discriminer** sur
  `getType()`, introduire alors une 5ᵉ constante `TYPE_CONFIGURATION` plutôt que de surcharger
  `TYPE_AUTH`.
- **Ne pas se fier à `CURLOPT_DNS_CACHE_TIMEOUT`** : le cache DNS de libcurl est porté par le **handle**,
  créé et détruit à chaque appel. L'option est donc sans effet, et le budget absorbe deux résolutions DNS
  au lieu d'une. Un `curl_share_init()` (`CURL_LOCK_DATA_DNS`) au niveau de la classe serait la vraie
  correction, volontairement non faite en UC02 (gain marginal, surface nouvelle en fin de cycle).
- **UC08** : la durée de cache de **30 min est un pari** — la durée de vie réelle du jeton et le code
  d'erreur exact d'expiration restent **inconnus**. UC08 porte la reprise réactive (un re-login + un seul
  rejeu, avec anti-boucle) et devra calibrer cette durée sur des observations réelles.
- **`.memory/analyse/smartclim-transport-aux-home.md` § 8.3** annonce des timeouts de 5 s / 15 s,
  **contredits** par le budget global d'UC02 (§ 1.3). À mettre à jour à la capitalisation, sinon UC03
  recopiera la mauvaise valeur.
- **post-mvp/05** : le `uid` mis en session est ce dont la souscription MQTT `dev2app/<uid>/#` aura besoin.
