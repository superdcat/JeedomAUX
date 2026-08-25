# Jeedom — Config plugin, hooks `preConfig`/`postConfig` et cycle de vie install/remove

> **Analyse générique Jeedom** (réutilisable par tout plugin, pas propre à SmartClim).
>
> **Statut des informations** : ✅ = **lu dans la source du core** (`jeedom/core@master`,
> `core/class/config.class.php`, `core/ajax/config.ajax.php`, `core/class/plugin.class.php`,
> `core/class/cache.class.php`, `core/class/ajax.class.php`, lus les 2026-08-24 et 2026-08-25).
> Tout ce fichier est en ✅ — rien n'y est supposé.
>
> **Pourquoi ce fichier existe** : ces onze contrats sont **invisibles à `php -l`**, ne se devinent pas à
> la lecture d'un plugin existant, et chacun a un mode de défaillance **silencieux** (perte de clés, perte
> d'identifiants, validation court-circuitée, secret exposé, interface figée, log muet ou forgé).
> §§ 1-7 établis au cycle UC01 du MVP, §§ 8-11 au cycle UC02.
>
> **Date** : 2026-08-25.

---

## 1. `config::save($clé, $valeur, $plugin)` — ordre réel des opérations

```text
1. json_encode si tableau/objet
2. purge du cache statique
3. SI la valeur == le défaut INI du plugin :
      config::remove()  →  postConfig_<clé>()  →  RETURN     ⚠️ preConfig_<clé> N'EST PAS APPELÉ
4. preConfig_<clé>($valeur)            → $valeur = retour
5. chiffrement si $clé ∈ $_encryptConfigKey
6. REPLACE INTO config
7. postConfig_<clé>($valeur)           ⚠️ reçoit la valeur CHIFFRÉE pour une clé chiffrée
```

**Trois conséquences pratiques** :

- ⚠️ **La validation ne peut pas reposer uniquement sur `preConfig_<clé>`** : il est court-circuité dès
  que la valeur enregistrée égale le défaut INI. → appliquer chaque règle **à l'écriture ET à la
  lecture** (« double barrière »), via un normaliseur privé partagé par le hook et par l'accesseur.
- `postConfig_<clé>` d'une clé chiffrée ne voit **jamais** le clair : utile pour *réagir* à un changement
  (purger un cache de jeton), inutilisable pour *lire* la valeur.
- Le nom de méthode est calculé par `str_replace(...)` sur la clé — mais **pas avec le même jeu de
  caractères** : `preConfig_` remplace `::`, `:` **et `-`** ; `postConfig_` seulement `::` et `:`.
  → **ne jamais mettre de tiret dans un nom de clé de configuration** (les deux hooks divergeraient).

## 2. Valeurs par défaut : `plugins/<id>/core/config/<id>.config.ini`

`config::getDefaultConfiguration($plugin)` lit ce fichier via `parse_ini_file($f, true)` puis l'indexe en
`$defaut[$plugin][$clé]` → **la section doit porter le nom du plugin** :

```ini
[monplugin]
ma_cle = 5
```

**`config::byKey()` ET `config::byKeys()` retombent tous deux sur ce défaut** quand la valeur est absente,
vide ou `null` en base. C'est donc le mécanisme propre pour un défaut **statique** — y compris pour
l'affichage initial d'un formulaire, `byKeys()` alimentant le chargement de la modale.

⚠️ **Corollaire contre-intuitif** : amorcer en base une valeur **égale** au défaut INI est un no-op —
`config::save()` supprime aussitôt la ligne (§ 1, étape 3). Inutile, et trompeur à la relecture.
⚠️ Un défaut **dynamique** (calculé : fuseau horaire, matériel…) n'est **pas** exprimable en INI : il faut
l'écrire en base (à l'install **et** au chargement de la page, cf. § 5) ou le résoudre dans un accesseur.

## 3. `$_encryptConfigKey` — chiffré au repos, **mais renvoyé en clair au navigateur**

`config::byKey()` **et** `config::byKeys()` déchiffrent automatiquement les clés listées dans
`public static $_encryptConfigKey` de la classe principale (branche
`property_exists($class, '_encryptConfigKey')`). Le chiffrement à l'écriture est symétrique (§ 1, étape 5).

⚠️ **`core/ajax/config.ajax.php`, `action=getKey`** — la liste de clés vient **du client**
(`init('key')`, JSON) puis `config::byKeys()` **déchiffre et renvoie le clair**, sans masquage ni
filtrage. Le secret transite donc dans la réponse AJAX et atterrit dans l'attribut `value` du champ.
C'est le comportement natif de **tout** plugin Jeedom et du core lui-même (mots de passe SMTP, clés
d'API), sur une surface admin authentifiée.

- Pour l'assumer : champ `type="password"` (**masqué**), secret chiffré au repos.
- Pour l'éviter : **sortir le champ de `.configKey`** — il disparaît alors de la liste envoyée à `getKey`,
  donc le clair ne redescend jamais — et l'enregistrer via un endpoint AJAX dédié *write-only*.
- ⚠️ **Ne JAMAIS vider en JS un champ mot de passe porteur de `configKey`** : la modale réenvoie
  **toutes** les clés à chaque sauvegarde → un champ vidé **écrase le secret stocké par une chaîne
  vide**, y compris lors d'un enregistrement visant un tout autre champ. Perte de données silencieuse.

## 4. `config.ajax.php`, `action=addKey` — **boucle sans transaction**

```php
foreach ($values as $key => $value) {
    config::save($key, jeedom::fromHumanReadable($value), init('plugin', 'core'));
}
```

⚠️ **Une exception levée dans un `preConfig_<clé>` abandonne la boucle** : les clés déjà traitées sont
écrites, **les suivantes sont perdues**, et l'utilisateur voit une erreur laissant croire que rien n'a
été enregistré.

→ **Règle** : un hook `preConfig_*` **normalise en silence**, il ne lève **jamais** d'exception. Il doit
aussi être blindé contre les entrées non-chaîne (`$v = is_scalar($v) ? (string) $v : '';`) : ces méthodes
sont `public static` et `config::save()` est appelable depuis un scénario ou l'API JSON-RPC — sur PHP ≥ 8,
un tableau passé à `trim()`/`strtoupper()` lève une **`TypeError` non rattrapée**.

## 5. `plugin_info/configuration.php` — point d'entrée PHP à part entière

Ce n'est pas un simple gabarit : le fichier est **exécuté** à chaque ouverture de la page de config,
**avant** le `getKey` de la modale. C'est donc le bon endroit pour un **amorçage paresseux** (écrire un
défaut dynamique, § 2) — garanti par construction, contrairement à `<id>_install()` dont le moment
d'appel dépend du mode d'installation.

⚠️ Le squelette Jeedom le livre gardé par `isConnect()` (tout utilisateur authentifié). **Dès qu'on y met
le moindre effet de bord**, passer à `isConnect('admin')`, comme les autres points d'entrée du plugin.

## 6. ⚠️⚠️ `<id>_remove()` est appelée à chaque **DÉSACTIVATION**, pas seulement à la désinstallation

`plugin::setIsEnable()` — branche `$_state == 0` :

```php
if ($alreadyActive == 1) {
    $out = $this->callInstallFunction('remove');
}
```

Symétriquement, `<id>_install()` est rejouée à chaque **activation** (et `<id>_update()` si le plugin
était déjà actif). **Jeedom n'expose aucun hook distinguant désactivation et suppression.**

→ **Ne jamais rien mettre de destructif dans `<id>_remove()`** — en particulier pas de purge
d'identifiants. Un simple cycle désactiver/réactiver (opération admin banale, que le core déclenche
lui-même sur échec de dépendances ou pendant une mise à jour) détruirait les données, silencieusement et
sans confirmation. Un effacement de secrets doit être une **action volontaire** de l'utilisateur
(bouton dédié), jamais un effet de bord du cycle de vie.

## 7. `plugin_info/.htaccess` — whiteliste des extensions, dont `txt`

Le fichier livré par le squelette :

```apache
Order allow,deny
<Files ~ "\.(jpg|jpeg|png|gif|pdf|txt|bmp)$">
   allow from all
</Files>
Deny from all
```

La section `<Files>` **neutralise** le `Deny from all` du dossier pour ces extensions — c'est ce qui rend
l'icône du plugin accessible. ⚠️ **Tout fichier `.txt` déposé dans `plugin_info/` est donc téléchargeable
sans aucune authentification** (Apache sert un statique, aucune session PHP n'est évaluée).

⚠️ Plus généralement, Apache/Debian ne bloque par défaut que `^\.ht` : les **répertoires commençant par un
point** (`.memory/`, `.claude/`, `.github/`, **`.git/`**) sont servis s'ils sont livrés. `.git/config`
expose l'URL du dépôt distant — et un **jeton** si le clone utilisait `https://user:token@…`. Aucun
`.htaccess` interne ne peut fermer `.git/` : seule une règle à la racine le pourrait, ou une installation
**par archive** plutôt que par `git clone`.

## 8. `cache::byKey()` et les entrées expirées — pas de piège

Vérifié sur la source (`core/class/cache.class.php`, `FileCache::fetch()`, `MariadbCache::fetch()`) :
**chaque moteur de cache purge lui-même l'entrée expirée et renvoie `null`** avant que `cache::byKey()`
ne la voie ; celle-ci construit alors un objet `cache` **frais** (valeur `null`, horodatage courant),
jamais l'ancien objet avec sa valeur périmée.

→ `cache::byKey($cle)->getValue(null) !== null` suffit à distinguer « présent et valide » de
« absent **ou** expiré ». **Aucun test de fraîcheur supplémentaire n'est nécessaire.**

⚠️ Ce qui reste à la charge du plugin, en revanche, c'est l'**invalidation métier** : une entrée non
expirée mais devenue **fausse** (identifiants changés hors `config::save`, donc sans passer par les hooks
`postConfig_*` — restauration de sauvegarde, écriture SQL, migration). Motif retenu : stocker dans
l'entrée une **empreinte** des identifiants (`sha1(email . '|' . pays)`) et la comparer à la relecture.
🚫 **Ne jamais inclure un mot de passe dans une telle empreinte** — cela le remettrait sur la pile
d'appel (cf. § 10).

## 9. ⚠️ `ajax::init()` NE ferme PAS la session PHP — et Jeedom utilise des sessions fichier

Vérifié sur la source (`core/class/ajax.class.php`) : `ajax::init()` ne fait que poser l'en-tête
`Content-Type` et valider l'allow-list des actions autorisées en GET.

Or Jeedom utilise des **sessions fichier** : tant que la session n'est pas fermée, **toute autre requête
de la même session est sérialisée** derrière le handler en cours. Un endpoint AJAX qui fait un appel
réseau de plusieurs secondes **fige donc toute l'interface** — menu, page courante, autres appels AJAX.
Le symptôme est spectaculaire et ne ressemble pas à sa cause.

→ **`session_write_close()` juste après le contrôle d'accès (`isConnect(...)`) et `ajax::init()`, et
AVANT tout appel réseau.** L'appel est inoffensif s'il s'avérait redondant.
⚠️ Corollaire : un `timeout` posé côté client (jQuery) **n'interrompt pas le PHP** — il évite seulement
que l'interface attende. La seule borne réelle est **serveur**.

## 10. ⚠️ Une trace d'exception PHP expose les ARGUMENTS de chaque frame

Ce n'est pas propre à Jeedom, mais c'est le piège central de tout plugin qui manipule un secret : une
exception née pendant qu'un secret est **argument** de la frame courante l'embarque dans sa trace — et
`displayException()` peut faire remonter cette trace jusqu'à une réponse AJAX, donc jusqu'au DOM.

Défense retenue, en couches, **toutes nécessaires** :

1. **aucune fonction ne prend le secret en paramètre** : elle lit `config::byKey(...)` elle-même, au plus
   près de l'usage ;
2. la crypto est enveloppée dans un `try { … } catch (Throwable $t) { return false; }` qui **capture et
   jette sur place** — même si un `set_error_handler` du core convertissait un *warning* `openssl_*` en
   `ErrorException` avec un fragment du secret en argument.
   ⚠️ **`openssl_public_encrypt()` sur une clé inexploitable renvoie `false` en émettant un WARNING, il
   ne lève PAS d'exception** : ce `catch` ne couvre donc pas le chemin le plus probable → journaliser
   **aussi** sur chaque `return false`, avec un message **fixe** + `openssl_error_string()`.
   ⚠️ `openssl_error_string()` ne dépile qu'**une** erreur : boucler pour vider la file, **et la vider
   aussi en entrée** (elle est globale au processus — sinon on attribue à son propre code une erreur
   laissée par `utils::decrypt`, par la poignée TLS de cURL ou par un autre plugin) ;
3. les méthodes **publiques** d'une brique qui lève depuis une frame porteuse de données sensibles
   **recréent l'exception** avant de la propager : la trace d'origine meurt dans la brique, et la
   garantie vaut pour tout appelant futur sans discipline à maintenir ;
4. le point d'entrée AJAX rattrape **`Throwable`** en dernier bloc (une `Error` PHP 8 traverse sinon
   `catch (Exception)`), avec un message curaté et un code **figé** — **jamais** `displayException()`.
   Journaliser `get_class($t)` et `basename($t->getFile()) . ':' . $t->getLine()` est **sûr** ; jamais
   `getTraceAsString()`. `$t->getMessage()` d'une `TypeError` cite le **type** de l'argument, pas sa
   valeur ; les `ValueError` qui écho la valeur ne concernent que des arguments d'algorithme/encodage.

*(`zend.exception_ignore_args=On` est le défaut depuis PHP 7.4, mais dépend du `php.ini` de l'hôte : ne
pas s'y fier.)*

## 11. Journaliser une donnée d'origine externe — trois problèmes distincts

Vaut pour une valeur venue d'une API tierce **comme** d'une requête client (`init('...')`) :

1. **filtrer les caractères de CONTRÔLE** (`[\x00-\x1F\x7F]`) → ferme l'**injection de log** (un `\n`
   forge des lignes ressemblant à des entrées Jeedom légitimes) ;
2. **garantir la validité UTF-8** — sinon le `json_encode` de la visionneuse de logs de Jeedom échoue,
   et on perd l'accès au diagnostic. Ne retomber sur un filtre « imprimables » **que** dans ce cas ;
3. **neutraliser les suites base64** (`/[A-Za-z0-9+\/]{16,}={0,2}/`) puis **tronquer**.

⚠️ **Deux erreurs de raisonnement à ne pas refaire** :
- un filtre « imprimables » **ne bloque pas** le base64 — le base64 *est* imprimable ;
- **aucune troncature ne protège** d'un écho de champ chiffré en **ECB** : le mode chiffre bloc par bloc,
  24 caractères de base64 livrent déjà 16 caractères du clair. Et si la clé est une constante de
  protocole publique, quiconque lit le log déchiffre.
- ⚠️ Coût caché d'un filtre « imprimables » appliqué **seul** : tout message non-ASCII (accentué, ou
  chinois — fréquent sur les backends asiatiques) devient une suite d'espaces, et l'on perd
  l'information exactement dans le cas d'échec qu'on cherchait à diagnostiquer.

⚠️ **Caster ce qui doit être numérique** : `is_scalar()` accepte n'importe quelle **chaîne**.
⚠️ **Une regex validant une valeur destinée à un en-tête HTTP doit finir par `\z`, pas par `$`** : en
PCRE, sans modificateur `D`, `$` matche **aussi juste avant un `\n` final** — et ce `\n`, suivi du `\r\n`
de cURL, **clôt le bloc d'en-têtes**, faisant basculer les en-têtes suivants dans le corps.
