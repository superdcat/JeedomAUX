# Jeedom — Config plugin, hooks `preConfig`/`postConfig` et cycle de vie install/remove

> **Analyse générique Jeedom** (réutilisable par tout plugin, pas propre à SmartClim).
>
> **Statut des informations** : ✅ = **lu dans la source du core** (`jeedom/core@master`,
> `core/class/config.class.php`, `core/ajax/config.ajax.php`, `core/class/plugin.class.php`, lus le
> 2026-08-24/25). Tout ce fichier est en ✅ — rien n'y est supposé.
>
> **Pourquoi ce fichier existe** : ces six contrats sont **invisibles à `php -l`**, ne se devinent pas à
> la lecture d'un plugin existant, et chacun a un mode de défaillance silencieux (perte de clés, perte
> d'identifiants, validation court-circuitée, secret exposé). Établis au cycle UC01 du MVP.
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
