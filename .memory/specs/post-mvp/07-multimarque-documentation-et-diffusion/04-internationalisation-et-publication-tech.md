# Spec technique — UC04 du domaine post-MVP 07 : traductions complètes et préparation à la publication

> Spec fonctionnelle : `04-internationalisation-et-publication.md` (AC1..AC9) · **UC de clôture** : elle ne
> crée aucune fonctionnalité et **aucune chaîne UI nouvelle**. Elle mesure, verrouille et corrige.

## 0. Décisions de cadrage

| # | Question | Décision |
|---|---|---|
| **D1** | Outillage d'audit : script dédié ou extension de l'existant ? | **Étendre `.claude/scripts/verif-plugin.py`** — cf. § 1.1 |
| **D2** | Cible des liens `documentation`/`changelog` d'`info.json` | **GitHub Pages**, `https://superdcat.github.io/JeedomAUX/fr_FR/…` — cf. § 2.2 |
| **D3** | `#language#` ou langue en dur dans ces URL ? | **`fr_FR` en dur** — cf. § 2.3 |
| **D4** | Catégorie `wellness` | **Conservée** — cf. § 2.4 |
| **D5** | Champ `licence` d'`info.json` | **NON touché** (reste `GPL`) — cf. § 2.5 |
| **D6** | `plugin_info/helperConfiguration.php` / `.py` | **Supprimés** — cf. § 3 |
| **D7** | `author` | **`dcat` → `superdcat`** (arbitrage utilisateur du 2026-09-16) |
| **D8** | Périmètre `README.md` | **Les 6 zones périmées** (arbitrage utilisateur du 2026-09-16) — cf. § 4 |
| **D9** | AC8 (CI verte) | **Aucun push, aucune branche créée** → dette D-07UC04-01 (arbitrage utilisateur) |
| **D10** | AC6 (recette IHM en anglais) | **UC committée avec AC6 non coché** → dette D-07UC04-02 (arbitrage utilisateur) |
| **D11** | `link.forum` | **Laissé sur la racine** `community.jeedom.com` → dette D-07UC04-03 |

## 1. État mesuré avant travaux

⚠️ **Tout ce tableau est une MESURE, pas une présomption.** 7 des 9 critères étaient déjà satisfaits sur le
fond : l'essentiel du travail de cette UC est de **verrouiller mécaniquement** ces acquis pour qu'ils ne se
dégradent plus, pas de les produire.

| AC | État mesuré | Traitement |
|---|---|---|
| AC1 | ✅ **353 clés UI** sur 13 fichiers ; **353 clés / 13 sections** dans chacun des 3 JSON ; 0 manquante, 0 orpheline | verrou A1 + A3 |
| AC2 | ✅ `core/i18n/` ne contient que `de_DE.json`, `en_US.json`, `es_ES.json` | verrou A2 |
| AC3 | ✅ `description` objet 4 langues : 255 / 245 / 246 / 259 caractères | verrou A6 |
| AC4 | ⚠️ **1 défaut réel** : `desktop/js/smartclim.js:60` | correctif § 5 + verrou A4 + revue § 6.2 |
| AC5 | ✅ **254 appels `__()`**, 254 avec 1ᵉʳ argument littéral **et** 2ᵉ argument `__FILE__` | verrou A5 |
| AC6 | ❌ non vérifiable hors Jeedom réel | recette § 8 → **dette D-07UC04-02** |
| AC7 | ⚠️ **4 URL en HTTP 404** héritées du squelette + `author` | § 2 |
| AC8 | ❌ CI **jamais exécutée** (dépôt mono-branche) | **dette D-07UC04-01** |
| AC9 | ✅ `generer-icone.py --verifier` : 7 contrôles verts | — |

### 1.1 D1 — étendre `verif-plugin.py` plutôt qu'écrire un second script

`verif-plugin.py` porte déjà `fichiers_tout()`, `_cles_source()` (l'extracteur de clés UI) et une section
« Transverse » où les nouveaux contrôles se branchent sans réaménagement.

⚠️ **Le motif dirimant est la duplication de l'extracteur** : un second script devrait ré-extraire les clés
`{{…}}` / `__()`, et deux extracteurs « identiques » **divergent au premier correctif** — piège déjà payé
dans ce dépôt (cf. `categorieLigneLan()`, source unique de vérité du tri des lignes de scan).

⚠️ **Pas de nouveau drapeau `--publication`** : tous les contrôles ajoutés sont des **invariants
permanents**, pas des exigences de publication. Un mode dédié créerait un second chemin d'exécution qui ne
serait jamais joué, donc jamais vérifié.

⚠️ **Le script reste dans `.claude/scripts/`, JAMAIS sous `core/`, `desktop/` ou `resources/`** — même
doctrine que `generer-icone.py` : il n'est pas livré sur l'installation Jeedom et ne doit pas suggérer une
dépendance runtime qui n'existe pas.

## 2. `plugin_info/info.json`

**Convention du fichier** : **LF pur** (mesuré : CR=0, LF=44), indentation **tabulations**, et les URL y
sont écrites avec l'échappement `https:\/\/` — **le conserver**, c'est le style du fichier.

### 2.1 Les 4 URL sont le résidu principal d'AC7

Valeurs actuelles, toutes en **HTTP 404 vérifié** :

```
"changelog_beta":     "https://doc.jeedom.com/#language#/plugins/wellness/smartclim/beta/changelog"
"changelog":          "https://doc.jeedom.com/#language#/plugins/wellness/smartclim/changelog"
"documentation_beta": "https://doc.jeedom.com/#language#/plugins/wellness/smartclim/beta"
"documentation":      "https://doc.jeedom.com/#language#/plugins/wellness/smartclim"
```

Ce sont les URL du squelette avec `template` → `smartclim` : la **lignée directe** du gabarit. Le namespace
`doc.jeedom.com/#language#/plugins/<catégorie>/<id>` est **réservé aux plugins hébergés par Jeedom** — il ne
répondra **jamais** pour un plugin tiers, quelle que soit la qualité de `docs/`.

### 2.2 D2 — contrat externe : où doit pointer la doc d'un plugin tiers

Établi par lecture de la doc officielle (`doc.jeedom.com/fr_FR/dev/documentation_plugin`), pas supposé :

> « il suffit d'aller sur votre dépôt github puis "Settings" et dans la partie "GitHub Pages" d'activer
> celle sur "master branch /docs folder" » … « ajouter `#language#/` pour le lien vers la documentation »

Vérifications faites : `https://jeedom.github.io/plugin-template/fr_FR/` → **200** ;
`…/fr_FR/changelog` → **200** (GitHub Pages sert bien l'URL **sans extension `.md`**).

**Valeurs cibles** (dépôt `github.com/superdcat/JeedomAUX`, **public**, vérifié par `git remote -v`) :

```
"changelog_beta":     "https:\/\/superdcat.github.io\/JeedomAUX\/fr_FR\/changelog_beta",
"changelog":          "https:\/\/superdcat.github.io\/JeedomAUX\/fr_FR\/changelog",
"documentation_beta": "https:\/\/superdcat.github.io\/JeedomAUX\/fr_FR\/",
"documentation":      "https:\/\/superdcat.github.io\/JeedomAUX\/fr_FR\/"
```

Les 4 fichiers cibles existent déjà : `docs/fr_FR/index.md`, `changelog.md`, `changelog_beta.md`.

⚠️ **Ces URL sont correctes mais pas encore SERVIES** — cf. dette **D-07UC04-01**. AC7 porte sur l'absence
de trace du squelette, **pas** sur l'accessibilité de la doc : ne pas le cocher comme « documentation
accessible ».

### 2.3 D3 — `fr_FR` en dur, et pourquoi ce n'est pas une régression

**Contrat externe, lu dans la source du core** (`jeedom/core`, `core/class/plugin.class.php` l. 107-110) :

```php
$plugin->documentation = (isset($data['documentation']))
  ? str_replace('#language#', config::byKey('language', 'core', 'fr_FR'), $data['documentation']) : '';
```

La substitution est **aveugle** : elle prend la langue **de l'instance Jeedom**, ne consulte **pas** le
tableau `language[]` du plugin, et **ne retombe pas** sur `fr_FR` si la page cible n'existe pas. Le `fr_FR`
qu'on y lit n'est que le *défaut de la clé de config*.

Or `docs/` ne contient que `fr_FR/` (la traduction de la documentation est **hors périmètre**, cf. UC02 et
la spec fonctionnelle de cette UC). Garder `#language#` produirait donc `/en_US/` → **404** pour tout
utilisateur non francophone — strictement pire qu'une documentation française servie à un anglophone.

⚠️ **Réversible en une ligne** le jour où `docs/en_US/` existera. Inscrit en **dette D-07UC04-04**.

⚠️⚠️ **Écart documentaire à corriger dans le même commit** : `CLAUDE.md` § docs affirme « substitution
`#language#`, **repli FR** ». C'est **inexact** au sens « repli quand la page manque » — la source ci-dessus
fait foi. La phrase est à corriger, sans quoi une UC future reprendra la déduction fausse.

### 2.4 D4 — `wellness` conservée

`doc.jeedom.com/fr_FR/dev/structure_info_json`, tableau « Market → info.json » : **Confort = `wellness`**.
C'est donc une valeur **documentée et valide**. Le point ouvert de la spec fonctionnelle portait sur
l'acceptation par la **modération** du market, pas sur la validité technique — il reste ouvert, et un repli
vers `devicecommunication` resterait l'édition d'un seul token.

### 2.5 D5 — ⚠️ le champ `licence` n'est PAS touché

**Piège évité, à consigner** : la valeur actuelle est `"licence": "GPL"`, et la réécrire en
`GPL-3.0-or-later` **défairait une décision déjà livrée**. `02-documentation-utilisateur-tech.md` § 1.2,
décision **D1**, porte explicitement : « `plugin_info/info.json` | **une valeur** : `"licence": "AGPL"` →
`"GPL"` ». La forme courte **est** l'implémentation de cette décision.

Contrat externe vérifié (`jeedom/core`, `core/class/plugin.class.php` l. 90-91) :

```php
$plugin->license = (isset($data['licence'])) ? $data['licence'] : '';
$plugin->license = (isset($data['license']))  ? $data['license']  : $plugin->license;
```

→ **chaîne libre**, aucune énumération, aucune validation, aucune normalisation ; le core accepte même les
deux orthographes. Consommation unique : `desktop/php/plugin.php` l. 141, **affichage** accolé au nom de
l'auteur. La doc marque `licence` obligatoire mais **n'énumère aucune valeur**, contrairement à `category`.
Usage de fait : 6 plugins officiels échantillonnés portent tous la **forme courte**.

La déclaration **normative** de licence vit dans `LICENSE`, les en-têtes de tous les sources, `README.md`
§ Licence et `docs/fr_FR/credits.md` § 2 — **les quatre disent déjà `GPL-3.0-or-later`** et sont cohérents.

⚠️ **Et aucun contrôle dédié n'est créé pour ce champ** — c'est un **refus motivé, pas un oubli** : un
contrôle supposerait une nomenclature documentée, et il n'en existe aucune. Fabriquer une liste blanche à
partir de 6 échantillons inventerait une contrainte que ni le core ni la doc n'imposent. ⚠️ Le contrôle A6
« aucune valeur de chaîne ne contient `template` » reste **strictement** un test de sous-chaîne au service
d'AC7 : il ne se prononce sur la validité d'**aucun** champ.

### 2.6 D7 — `author`

`"dcat"` → `"superdcat"` (arbitrage utilisateur). ⚠️ **Ce n'est PAS un résidu de squelette** : le gabarit
portait `"author": "Jeedom SAS"` (`git show ceed01b:plugin_info/info.json`), et `helperConfiguration` ne
touchait jamais ce champ (il ne réécrit que l'`id` et les 4 URL). C'était donc une valeur choisie au
cadrage, sans aucun risque d'attribution à un tiers ; l'alignement sur le compte propriétaire du dépôt est
un choix d'identité publiée, tranché par l'utilisateur.

## 3. D6 — suppression de `plugin_info/helperConfiguration.php` et `.py`

Les deux fichiers sont supprimés. Motifs **cumulés** :

1. **Outillage à usage unique déjà joué** (`template` → `smartclim`), sans aucun appelant dans le plugin.
2. Il porte littéralement `$oldId = 'template';` dans un fichier **livré sur chaque installation**.
3. ⚠️ **Le `.php` n'a AUCUNE garde CLI ni `isConnect()`** : il réécrit récursivement tous les fichiers du
   plugin et termine par un `file_put_contents($pathInfoJson, …)` **inconditionnel**. Il n'est protégé que
   par le `Deny from all` de `plugin_info/.htaccess` — une seule barrière, et de configuration serveur.
4. C'est du PHP **hérité, jamais audité pour PHP 7.4**, que la CI lintera (cf. § 7 R7).
5. C'est exactement la doctrine déjà écrite dans `CLAUDE.md` pour `generer-icone.py` : l'outillage de dev
   vit dans `.claude/scripts/`, **jamais** sous un dossier livré.

Récupérable à tout moment depuis l'historique git ou depuis `jeedom/plugin-template` amont.

⚠️ **5 lignes de référence à mettre à jour DANS LE MÊME COMMIT** (sans quoi la doc interne cite un fichier
disparu) :

| Référence | Action |
|---|---|
| `CLAUDE.md` (entrée d'architecture `helperConfiguration`) | mettre à jour |
| `.claude/commands/init-plugin.md` l. 84, 88, 159 | mettre à jour |
| `.claude/agents/jeedom-plugin-architect.md` l. 119 | mettre à jour |
| `.memory/analyse/smartclim-daemon-choix.md` l. 95 | ⚠️ **NE PAS TOUCHER** — note **historique** légitime (décrit ce qui a été fait au moment du renommage) |

## 4. D8 — `README.md` : 6 zones périmées

**Convention** : **LF pur** (mesuré : CR=0, LF=129) — ⚠️ contrairement aux `.md` de `docs/fr_FR/` qui sont
en CRLF. Ne pas normaliser, ne pas passer le fichier dans un outil qui uniformiserait.

| Lignes | Affirmation actuelle | Correction |
|---|---|---|
| **7-8** | « Statut : en développement […] **n'est pas encore publiable** sur le market » | statut de publication, **en conservant** la mention honnête des deux transports non recettés |
| **37** | Broadlink LAN — `⚪ prévu` | **livré**, non recetté (aucun matériel Broadlink chez l'utilisateur) |
| **38** | AUX Cloud legacy — `⚪ prévu` | **livré**, non recetté (aucun compte AC Freedom) |
| **40-43** | « **Une fois plusieurs transports disponibles**, trois stratégies **seront proposées** » | présent de l'indicatif (`smartclimTransport` + clé `transport_mode` livrés au domaine 02 UC01) |
| **53-54** | fonctions de confort annoncées : « **éco** […] **silence** » | ⚠️ **retirer ces deux termes** — cf. ci-dessous |
| **63** ⚠️ | « Aucune dépendance système au MVP : le plugin est **100 % PHP**, sans démon » | démon Python **latéral et optionnel**, 2 paquets pip, et **le plugin reste pleinement opérant démon arrêté** |
| **60-62** | « Un **compte AUX Home** (ou AC Freedom…) » | incise : avec le LAN livré, un appareil Broadlink joignable suffit |
| **93-100** | bloc « Structure du dépôt » | ajouter `resources/` (démon Python), absent alors qu'il est peuplé et livré |

⚠️ **La l. 63 passe devant le tableau des transports en gravité** : elle est en **« Prérequis »**, la
section qu'un utilisateur lit *avant* d'installer ; elle contredit **directement** deux drapeaux du
manifeste (`hasDependency: true`, `hasOwnDeamon: true`) et `packages.json` (2 paquets) ; et elle annonce
l'absence d'un écran « Dépendances » que Jeedom **va** afficher. C'est une information **actionnable et
fausse**, là où « ⚪ prévu » n'est qu'une roadmap en retard.

⚠️ **Les mentions « éco » et « silence » sont le seul endroit du README qui promet ce qui n'arrivera pas** :
`CLAUDE.md` établit qu'il n'existe **ni `CONCEPT_ECO` ni `CONCEPT_ULTRA_SILENCE`**, et que leur omission est
**délibérée et structurelle** (« sans bit de lecture connu, leur état ne pourrait pas être relu — les
omettre les rend inatteignables par construction »). Les cinq autres fonctions citées (sommeil, afficheur,
ioniseur/santé, anti-moisissure, nettoyage) sont livrées avec `'confirme' => false`, donc légitimement
« visées » — les conserver.

⚠️ **« codes d'erreur » est CONSERVÉ, et c'est une décision tracée, pas un oubli** (point soulevé en revue
qualité). C'est en effet la **seule** mention de cette énumération pour laquelle **aucune ligne de code
n'existe** — les six autres sont implémentées et seulement désactivées (`'confirme' => false`) : la spec
technique de l'UC03 du domaine post-MVP 04 acte « volet erreur reporté, **rien n'est codé** », et
`smartclimAuxCloudApi` place explicitement `err_flag` / `ac_errcode1` hors périmètre. **Le critère de tri
n'est cependant pas « du code existe-t-il ? » mais « est-ce atteignable ? »** : « éco » et « silence » sont
exclus **par construction** (aucun bit de lecture, donc jamais relisibles), tandis que les codes d'erreur
sont seulement **non encore implémentés** — le canal existe côté protocole. Sous un intitulé
« Fonctionnalités **visées** », une fonction non faite reste légitime ; une fonction **impossible** ne l'est
pas. Ne pas « harmoniser » les deux cas au prochain passage.

⚠️ **L'intitulé « Fonctionnalités visées » (l. 45) reste au futur** : il assume son temps, ne pas le
réécrire. Seul le contenu factuellement faux est corrigé.

**Vérification collatérale faite** : `docs/fr_FR/index.md` est **à jour** (§ 2.2 décrit les deux paquets,
§ 2.3 le démon et son caractère non bloquant). `README.md` est la **dernière surface publique périmée** du
dépôt.

## 5. `desktop/js/smartclim.js` — le seul défaut de code de l'UC

**Ligne 60**, `placeholder="Unité"` n'est **pas** enveloppé alors que le `title="{{Unité}}"` de la **même
ligne** l'est — et que les deux lignes voisines (`Min` l. 58, `Max` l. 59) enveloppent **les deux**
attributs. L'incohérence est interne au fichier.

**Origine établie** : `git show ceed01b:desktop/js/template.js` ligne **60** porte la chaîne **identique au
caractère près** — le squelette avait déjà oublié ce seul attribut.

**Correctif** : `placeholder="Unité"` → `placeholder="{{Unité}}"`.

**Convention** : le fichier est en **CRLF** (mesuré : CR=603, LF=603) et en 2 espaces. ⚠️ L'édition d'une
ligne ne doit pas y introduire de LF nu — `verif-plugin.py` le détecterait en `MIXTE`.

⚠️⚠️ **Point de contrôle non intuitif** : ce correctif **n'ajoute AUCUNE clé i18n**. `Unité` est déjà
extraite du `title="{{Unité}}"` de la même ligne (`trouvees` est un `set`) et déjà traduite dans les trois
fichiers (`en_US`: *Unit*, `de_DE`: *Einheit*, `es_ES`: *Unidad*). **Le compteur doit rester à 353.** S'il
monte, c'est que la chaîne a été recopiée avec une variation (accent, espace) : corriger la **source**,
jamais ajouter une clé.

## 6. Contrôles ajoutés à `.claude/scripts/verif-plugin.py`

**Convention** : **LF pur**, 4 espaces (PEP 8). Tous les contrôles s'ajoutent à la section « Transverse »
et sont appelés depuis `main()`. Contrat existant inchangé : code de retour **1** s'il reste un PROBLÈME,
**0** sinon ; les AVIS n'échouent pas.

### 6.1 A0 — ⚠️ défaut réel à effet rétroactif : le drapeau n'est pas lu

`CLAUDE.md` documente `python .claude/scripts/verif-plugin.py --tous`, mais le script teste
`'--tout' in sys.argv`. **Mesuré** : `--tout` → **38 fichiers analysés** ; `--tous` → **0**.

Exécuté tel qu'il est documenté, le script affiche « === Fichiers (0) === », **saute silencieusement tous
les contrôles par fichier** (fins de ligne, octets de contrôle, équilibrage structurel, méta-séquences), et
**sort en code 0 — vert**. Toute session ayant suivi `CLAUDE.md` à la lettre a donc sauté ces contrôles.

⚠️ **La cause n'est pas l'orthographe, c'est que le script accepte silencieusement n'importe quel drapeau** :
`args = [a for a in sys.argv[1:] if not a.startswith('--')]` jette tous les `--*` sans les lire. Un simple
alias `--tous` ferait disparaître **ce** symptôme et laisserait la classe de bug entière (`--all`,
`--toute`, `--tout=1` échoueraient demain de la même façon).

**Correctif retenu** : **une seule forme documentée, `--tout`**, **plus un rejet explicite de tout drapeau
inconnu** (message sur `stderr`, code de retour **2**). Et `CLAUDE.md` corrigé (`--tous` → `--tout`, seule
occurrence du dépôt).

### 6.2 A1 — durcissement de `check_i18n()` : retrait de `hors_perimetre`

Aujourd'hui, un fichier source dont **aucune** clé n'est traduite est classé « hors périmètre » et ne
produit qu'un **AVIS** ; le commentaire du script dit lui-même « *dont le balayage i18n complet relève de
post-mvp/07* » — **c'est cette UC**. Un tel fichier devient un **PROBLÈME**, au même titre qu'un fichier
partiellement traduit.

⚠️ **Motif de fond** : exempter une catégorie **sur le signal même que le contrôle cherche** rend ce
contrôle inatteignable en silence.

**Effet aujourd'hui : nul** — les 13 sections du code ont leurs 13 sections dans chaque JSON, la branche
n'est jamais empruntée. C'est un **verrou pour l'avenir**, et il renforce le « tell » de reprise
`/auto-dev` décrit dans `CLAUDE.md` (une UC dont le code est en place mais l'i18n incomplète est une UC
non terminée).

### 6.3 A2 — `check_fr_fr_absent()` *(AC2)*

PROBLÈME si `core/i18n/fr_FR.json` existe. Une ligne, mais c'est le seul moyen d'empêcher un `translator`
futur de le recréer « par symétrie » — la clé française **est** le texte source.

### 6.4 A3 — `check_template_sans_i18n()` *(soutien AC1/AC6)*

PROBLÈME si la séquence double-accolade ouvrante apparaît dans un fichier de `core/template/**`,
**commentaire compris**. État actuel : **0 occurrence**.

⚠️ **Justification par lecture de source — ferme un « à confirmer » de `CLAUDE.md`** :
`cmd.class.php:2268` fait `translate::exec($template, 'core/template/'.$_version.'/'.$widget['widgetName'].'.html')`,
et `getWidgetTemplateCode()` (l. 2003) renvoie `isCoreWidget => true` **y compris** pour un template trouvé
dans un plugin ; `translate::getPluginFromName('core/template/…')` ne trouve pas `plugins/` et renvoie
**`core`**. Donc une entrée `plugins/smartclim/core/template/…` d'un `core/i18n/*.json` **ne peut jamais
être lue**. La règle « aucune double accolade dans un template de widget » est **structurellement
obligatoire**, pas prudentielle — d'où un PROBLÈME plutôt qu'une exigence de traduction inatteignable.

### 6.5 A4 — `check_chaines_non_enveloppees()` *(AC4 — portée honnête)*

**Périmètre** : `desktop/`, `plugin_info/configuration.`, `core/template/`, `desktop/js/`.

**Procédé** : neutraliser `<?php … ?>` / `<?= … ?>`, `<script>…</script>` (sauf pour un `.js`),
`<!-- … -->` et les `{{…}}` déjà enveloppés, puis :

- **attributs d'une liste fermée** (`title`, `placeholder`, `alt`, `data-original-title`, `aria-label`,
  `data-confirm`) portant du texte → **PROBLÈME** ;
- **nœuds de texte** `>texte<` d'au moins 3 lettres → **AVIS** (décidabilité moindre).

**Filtres anti-bruit obligatoires** : ignorer une valeur contenant `<?php` / `<?=` / une concaténation
(`' .`, `. '`, `" +`), un jeton de widget `#…#`, ou correspondant à `^[a-z0-9_\-\. ]+$` (classes et ids
techniques).

**Confiance mesurée** : après neutralisation, le contrôle rend **exactement 1 résultat** sur l'arbre actuel
— le vrai défaut du § 5 — et **0 faux positif** (les 5 autres candidats bruts sont des
`value="<?php echo htmlspecialchars(…) ?>"`, écartés par les filtres).

⚠️ **Commentaire de maintenance OBLIGATOIRE sur la liste d'attributs**, sur le modèle de celui d'`EXT_BINAIRE`
dans le même script : *liste **FERMÉE**, fondée sur l'attribut, à **compléter** dès qu'un attribut porteur
de texte utilisateur apparaît (`data-content` d'un popover, `data-title`, `summary`…). Un attribut absent de
cette liste n'est pas « toléré » : il est **invisible au contrôle**, donc AC4 devient faux sans aucun
signal.* Sans ce commentaire, c'est à nouveau le patron « critère d'exemption qui aveugle le contrôle ».

⚠️ **Limite connue, assumée** : le filtre `^[a-z0-9_\-\. ]+$` écarterait aussi un futur libellé français
**tout en minuscules et sans accent** (`placeholder="nom"`) — **faux négatif silencieux**. Improbable (la
convention du dépôt capitalise les libellés). **Aucun durcissement proposé** : toute tentative (longueur
maximale, détection d'accent) échangerait ce faux négatif contre des faux positifs, et un contrôle qui crie
au loup est désactivé au deuxième passage.

⚠️⚠️ **Ce que ce contrôle NE PEUT PAS faire, et ne prétend pas faire** : décider si un littéral de
`core/class/**` atteint l'utilisateur. Population mesurée : **347 chaînes françaises littérales** hors
`__()` et hors `log::add`, dont ~95 % **légitimes par décision de projet** — sorties des **6 CLI**
(« FR sans `__()` » par convention explicite de `CLAUDE.md` : 153 chaînes à elles seules), messages
d'exception **techniques** de transport, motifs de `log::add`, classes CSS. Les candidats les plus
crédibles ont été vérifiés **en remontant la chaîne d'appel** et sont des faux positifs :
`basculerHorsLigne($cibles, 'appareil AUX Home injoignable…')` finit en `log::add` ; `'Broadlink LAN : …'`
est **re-enveloppé** par `messageErreurLan()` avant de sortir ; `'Durée hors bornes…'` est consommé par une
CLI. Un scan statique ne peut pas trancher cela sans analyse de flot ; une heuristique produirait 300+ faux
positifs et serait désactivée dès la deuxième exécution.

**AC4 se signe donc sur DEUX JAMBES** : le contrôle machine (périmètre rendu) **+** une revue humaine
**bornée** de la liste fermée des 9 sinks utilisateur — `ajax::success/error` de
`core/ajax/smartclim.ajax.php` ; les `throw new smartclimException` levées **par `smartclim::`** (message
« déjà curaté ») ; `messageErreurAuxHome()` / `messageErreurLan()` / `messageErreurLegacy()` ;
`setName()`/`setUnite()` de `creerCommandesInfo()` et `creerCommandesAction()` ;
`smartclimCapabilities::libelle*()` et `nomSuffixe()` ; `smartclimWidget::chargeTuile()` ;
`lignesFusionScan()` / `lignesResiduellesScan()` / `diagnosticTransport()` ; les titres de groupe de
`desktop/php/panel.php`.

⚠️ **Exception légitime à ne pas « corriger »** : `smartclimCapabilities::libelleTransport()` renvoie
`'AUX Home'`, `'Broadlink LAN'`, `'AUX Cloud (AC Freedom)'` **sans `__()`** — ce sont des **noms de
marque**, décision déjà inscrite dans `CLAUDE.md`.

### 6.6 A5 — `check_appels_traduction()` *(AC5)*

Lexeur minimal sautant commentaires et chaînes, puis pour chaque `(?<![\w$])__\(` :

- 1ᵉʳ argument **non littéral** → PROBLÈME ;
- 2ᵉ argument ≠ `__FILE__` → PROBLÈME.

⚠️ **Sauter les lignes dont le contenu nu commence par `*`, `//` ou `#`** : les commentaires **qui
interdisent le motif contiennent le motif** — même précaution que `check_interdits()`.

⚠️ **Correction d'une estimation fausse de cette spec** (relevée en review, mesurée le 2026-09-16) : le
dépôt ne contient pas « 3 occurrences » de `__(` en commentaire mais **~29** — surtout des docblocks
PHPDoc (`smartclimCapabilities.class.php` l. 363-364, 457-458, 560-561…). Aucune ne produit de faux
positif : elles sont **toutes déjà neutralisées par le saut de commentaires du lexeur**, en amont. La
garde `startswith(('*', '//', '#'))` est donc **redondante et normalement inatteignable** ; elle est
**conservée volontairement** comme filet au cas où le lexeur serait retouché, et porte un commentaire le
disant. ⚠️ Ne pas la prendre pour la protection principale : la vraie est le lexeur.

⚠️ **Premier argument concaténé — cas tranché** : `__('Texte ' . $x, __FILE__)` est un **PROBLÈME**, avec un
message **distinct** de celui du `__FILE__` manquant. Mécanique : après lecture du littéral, sauter les
blancs ; si le caractère suivant n'est **pas** une virgule, le diagnostic est *« 1ᵉʳ argument non littéral
(concaténation) — la clé construite à l'exécution n'existera dans aucun `core/i18n/*.json` »*. Motif : c'est
le cas le plus pernicieux du lot — `translate::sentence()` reçoit une chaîne bâtie au runtime, aucune clé ne
correspond, et la chaîne sort en français **sans aucune trace**, exactement comme un `__($variable)`.
**Aucune occurrence aujourd'hui** (254 appels classés 254 conformes / 0 / 0).

⚠️ **Ce contrôle ferme un trou réel de `_cles_source()`** : aujourd'hui un `__($var)` n'est pas seulement
toléré, il est **invisible** — la regex d'extraction ne le capture pas, donc aucune clé n'est jamais
réclamée et le rapport reste à « 0 manquante ».

### 6.7 A6 — `check_manifeste()` *(AC3 + AC7)*

Charge `plugin_info/info.json` (JSON invalide → PROBLÈME) et exige :

- `description` est un **objet**, contenant **exactement** les codes de `language[]`, chaque valeur
  ≥ **80** caractères ;
- `category` appartient à la nomenclature documentée (constante reprenant le tableau de
  `structure_info_json`) ;
- **aucune valeur de chaîne ne contient la sous-chaîne `template`** ;
- les 4 clés `documentation`, `documentation_beta`, `changelog`, `changelog_beta` sont présentes, en
  `https://`, et **ne contiennent pas `doc.jeedom.com`** (namespace réservé aux plugins hébergés par
  Jeedom → 404 garanti pour un plugin tiers).

⚠️ **Aucun contrôle sur `licence`** — refus motivé, cf. § 2.5.

## 7. Inventaire `template` — ce qui se purge et ce qui est INTOUCHABLE

⚠️⚠️ **C'est le risque n°1 de cette UC.** Un `sed` global sur `template` casserait, dans l'ordre de
gravité : le chargement du JS de la page d'administration, les en-têtes de provenance GPL des `.py` du
démon, et les clés de section i18n. **La purge se fait fichier par fichier, depuis ce tableau, jamais par
motif.**

| Occurrence | Verdict |
|---|---|
| `desktop/php/smartclim.php:459` — `include_file('core', 'plugin.template', 'js')` | ⚠️ **LÉGITIME — INTOUCHABLE**. Asset JS générique **du core**. Le supprimer casse toute la page d'administration. |
| `desktop/js/smartclim.js:181` — commentaire citant `core/js/plugin.template.js` | **LÉGITIME** (documente un comportement du core) |
| `resources/smartclimd/smartclimd.py:17-18` et `jeedom/jeedom.py:17-18` — en-têtes de provenance | ⚠️ **LÉGITIME — À CONSERVER** : `CLAUDE.md` impose de maintenir ces blocs (sans eux, une resynchronisation amont réintroduit les écarts en silence), et `credits.md` § 3 en dépend pour l'obligation GPL |
| `core/i18n/*.json` — clés de section | **AUCUNE occurrence de `plugins/template/`** : les 13 sections sont en `plugins/smartclim/…`, ce que `translate::exec()` calcule exactement. **Rien à corriger.** |
| `3rdparty/readme` | **CONSERVÉ** — aucune occurrence de `template`, et `./3rdparty/*` est un chemin **référencé par la CI** comme exclusion |
| `compatibility: [smart, luna, atlas, rpi, docker, diy, mobile]` | **CONSERVÉ** — identique au squelette, mais ce **n'est pas un résidu** : c'est la liste matérielle courante maintenue par Jeedom SAS |
| `plugin_info/info.json` — 4 URL | **RÉSIDU → réécrit** (§ 2.2) |
| `desktop/js/smartclim.js:60` — `placeholder` | **RÉSIDU → corrigé** (§ 5) |
| `plugin_info/helperConfiguration.php` / `.py` | **RÉSIDU → supprimés** (§ 3) |

## 8. Validation

**Mécanique, avant commit** :

1. `python .claude/scripts/verif-plugin.py --tout` → attendu **« ✓ Aucun problème mécanique détecté »**, et
   **toujours 353 clés / 13 sections** dans les 3 langues (cf. l'avertissement du § 5).
2. `python .claude/scripts/verif-plugin.py --tous` → doit désormais **échouer explicitement** (code 2), et
   non plus afficher un faux vert.
3. `python .claude/scripts/generer-icone.py --verifier` → 7 contrôles verts *(AC9, part automatisable)*.
4. `python -c "import json; json.load(open('plugin_info/info.json', encoding='utf-8'))"`.
5. `git diff --stat` → **uniquement** les fichiers du § 9. Toute apparition de
   `plugin_info/configuration.php`, `core/template/**` ou `resources/**` signale une purge partie trop loin.
6. ⚠️ **Anti-régression, à EXÉCUTER (et non à prescrire)** :
   `grep -rn "plugin.template" desktop/` → **2** lignes ; `grep -rn "plugin-template" resources/` → **4**
   lignes.

**AC6 — recette manuelle (seule voie possible)** : Réglages → Système → Configuration → **Langue =
English**, puis parcourir (a) la page de configuration **plugin** (comptes AUX Home et legacy, pays,
région, intervalle), (b) la page d'administration (scan, tableau « Sources interrogées », les 3 sections
résiduelles, sonde de diagnostic), (c) la fiche d'un équipement (capacités, bornes, mode de transport),
(d) le **dashboard** (tuile + bouton d'état), (e) la **page-panneau**.

⚠️ **Deux pièges de banc, à connaître sous peine de conclure à tort** :
- `translate::exec()` **rend la chaîne française telle quelle** quand la clé manque — **aucune** erreur,
  **aucune** trace. Il faut la **lire**.
- La page-panneau **ne peut pas** être recettée avec un profil `user` : `eqLogic::hasRight()` rend `true`
  **inconditionnellement** pour `admin` **et** `user`. Créer un utilisateur **`restricted`** est l'étape 0.

**AC8 — hors machine de dev.** Compensation faite : contrôle statique de **ce que la CI mesure réellement**
(`jeedom/workflows/.github/workflows/plugin.yml` = **3 jobs de lint PHP** 7.4 / 8.0 / 8.2 sur `**.php`, hors
`./3rdparty/*` et `./vendor/*` ; **aucune** validation i18n, `info.json` ou prettier). Vérifié : **aucune**
syntaxe PHP 8+ (`match(`, `?->`, `readonly`, `enum`, `#[Attr]`, types union, `catch` sans variable, virgule
terminale de signature) ni fonction PHP 8 (`str_contains`, `str_starts_with`, `str_ends_with`,
`array_is_list`) sur `core/`, `desktop/`, `plugin_info/`. ⚠️ **C'est une mesure par `grep`, pas une garantie
de parseur**, et elle ne couvre pas `plugin_info/configuration.php` (illisible par les outils — sa
conformité est adossée au miroir `.txt`, qui passe `check_structure`). Bénéfice collatéral de la purge :
`helperConfiguration.php` **sort du périmètre de lint**.

## 9. Fichiers touchés

| Chemin | Action | Convention |
|---|---|---|
| `plugin_info/info.json` | modifié — **4 URL + `author`** (⚠️ **pas** `licence`) | **LF pur**, tabulations, échappement `https:\/\/` conservé |
| `desktop/js/smartclim.js` | modifié — 1 ligne (l. 60) | **CRLF**, 2 espaces |
| `README.md` | modifié — 6 zones (§ 4) | **LF pur** |
| `plugin_info/helperConfiguration.php` | **supprimé** | — |
| `plugin_info/helperConfiguration.py` | **supprimé** | — |
| `.claude/scripts/verif-plugin.py` | modifié — A0 · A1 · A2 · A3 · A4 · A5 · A6 | LF pur, 4 espaces |
| `CLAUDE.md` | modifié — entrée `helperConfiguration` · `--tous`→`--tout` · « repli FR » corrigé · § docs | LF |
| `.claude/commands/init-plugin.md` | modifié — l. 84, 88, 159 | LF |
| `.claude/agents/jeedom-plugin-architect.md` | modifié — l. 119 | LF |

**Explicitement NON touchés** (à ne pas chercher) : `core/php/smartclim.inc.php` — **sans objet**, aucune
classe nouvelle, aucune ligne de `require_once` à ajouter ; `plugin_info/configuration.txt` / `.php` —
aucun changement, donc **aucun `cp` de miroir à jouer** (`verif-plugin.py` émettra son avis habituel
« aucun des deux fichiers n'est modifié » : normal) ; `core/template/**` ; `resources/**` ; `docs/**` ;
`core/config/smartclim.config.ini` ; `packages.json` ; `install.php` ; `pre_install.php`.

## 10. Server vs Client

**Sans objet** — aucun code exécuté par Jeedom n'est ajouté ni modifié, hormis une chaîne d'attribut dans
`desktop/js/smartclim.js`. Les 6 contrôles vivent dans `.claude/scripts/verif-plugin.py`, outillage de
développement **jamais livré** sur l'installation.

## 11. Dépendances

**Aucune.** Aucun paquet système ou pip n'est ajouté ; `plugin_info/packages.json` n'est pas touché.

⚠️ **Bump de version** : le hook `pre-commit` incrémente `pluginVersion` dès qu'un commit touche
`plugin_info/` — ce qui sera le cas via `info.json`. Normal et souhaitable. **Ne pas le faire à la main.**

## 12. Impact i18n

⚠️ **ZÉRO chaîne française nouvelle.** Le seul changement est l'**enveloppement d'une chaîne existante**
(`Unité`), dont la clé est **déjà traduite** dans les trois fichiers cibles.

→ **L'étape `translator` du cycle `/feature` est SANS OBJET pour cette UC** : il n'y a rien à traduire, et
la faire tourner ne pourrait qu'introduire des clés orphelines.

## 13. Risques

- **R1 — La purge** : traitée au § 7, tableau exhaustif, fichier par fichier, jamais par motif.
- **R2 — `fr_FR` en dur** : un utilisateur non francophone reçoit la doc **française**. Assumé (mieux qu'un
  404), réversible en une ligne → dette **D-07UC04-04**.
- **R3 — Les 4 nouvelles URL restent en 404** tant que GitHub Pages n'est pas activé **et** que `master`
  n'est pas poussé (le remote est à `1134b24` : la doc d'UC02 et l'icône d'UC03 n'y sont pas encore). Le
  plan livre des liens **corrects mais pas encore servis** → dette **D-07UC04-01**.
- **R4 — Le durcissement A1 change le gate pour tous les cycles suivants** : un fichier UI neuf non traduit
  deviendra un **PROBLÈME** (code 1) au lieu d'un avis. Voulu — mais un `/feature` en cours verra le script
  rouge **entre l'implémentation et l'étape `translator`** : comportement normal, à ne pas prendre pour une
  régression.
- **R5 — Suppression d'`helperConfiguration`** : `/init-plugin` (déjà joué, et refusant de se rejouer)
  cesse d'être exécutable. 5 lignes de référence à mettre à jour dans le même commit (§ 3). Recouvrable via
  git ou depuis l'amont.
- **R6 — Fins de ligne** : `info.json` et `README.md` sont **LF pur** (`verif-plugin.py` exclut
  délibérément `.json` de la règle CRLF) ; `desktop/js/smartclim.js` est **CRLF**. Un éditeur
  « normalisant » produirait un diff intégral et masquerait le vrai changement.
- **R7 — AC8 non pré-validable localement** : `php` est absent de la machine de dev. Compensation au § 8,
  avec ses limites explicites.
- **R8 — Un échec de traduction est silencieux** (`translate::exec()` renvoie la source). ⚠️ Sous-cas
  piégeux à ne pas « corriger » à l'aveugle : le core accepte aussi une clé **à apostrophe échappée**
  (`str_replace("'", "\'", $text)`). Nos JSON stockent l'apostrophe **non échappée** et `_cles_source()`
  dés-échappe symétriquement — c'est **cohérent**, et c'est pourquoi le rapport est à 0 manquante. **Ne pas
  « harmoniser » un seul des deux côtés.**
- **R9 — `wellness`** : validité technique établie (§ 2.4) ; l'acceptation par la **modération** reste hors
  de notre contrôle. Impact d'un repli : un token.
- **R10 — `link.forum`** pointe sur la racine `community.jeedom.com`, pas sur un sujet dédié → dette
  **D-07UC04-03**.

## 14. Dette

| # | Objet | Motif |
|---|---|---|
| **D-07UC04-01** | **AC8 non coché** — CI jamais déclenchée (dépôt mono-branche `master`), et **GitHub Pages non activé** + `master` non poussé, donc les 4 URL restent en 404 | Arbitrage utilisateur du 2026-09-16 : aucun push ni création de branche dans ce cycle. **Actions hors dépôt** : `Settings → Pages → Deploy from a branch → master → /docs`, pousser `master`, puis déclencher la CI (branche `beta` ou PR vers `master`) |
| **D-07UC04-02** | **AC6 non coché** — parcours IHM en `en_US` non joué | Exige un Jeedom réel en anglais **et** un utilisateur de profil `restricted`. Protocole au § 8 |
| **D-07UC04-03** | `link.forum` sur la racine `community.jeedom.com` | Créer un sujet dédié au « Salon des développeurs » est un prérequis de publication au market, mais aucun AC ne le porte |
| **D-07UC04-04** | `fr_FR` en dur dans les 4 URL | Réversible en une ligne le jour où `docs/en_US/` existera (traduction de la doc : hors périmètre, cf. UC02) |
| **D-07UC04-05** | ⚠️ **AC4 sur `core/class/**` est un instantané daté, pas un gate reconductible** | La fermeture est obtenue par **revue manuelle** des 9 sinks du § 6.5. `verif-plugin.py` ne se prononce que sur le périmètre rendu (`desktop/**`, `plugin_info/configuration.*`, `core/template/**`, `desktop/js/**`). Toute feature ultérieure introduisant une chaîne utilisateur dans `core/class/**` dépend de la vigilance du `code-reviewer`, **d'aucun contrôle automatique** |
| **D-07UC04-06** | A4 : faux négatif silencieux du filtre `^[a-z0-9_\-\. ]+$` | Un futur libellé tout en minuscules sans accent échapperait au contrôle. Aucun durcissement proposé (§ 6.5) |
| **D-07UC04-07** | ⚠️ `CATEGORIES_MARKET` (A6) n'a **qu'une seule source**, et elle est distante | Corrigée en fin de cycle (cf. § 15) : la liste initiale, **reconstituée de mémoire**, était fausse dans les deux sens. La version en place est recopiée de la doc et **datée**. Il n'existe **aucun moyen de la revalider hors ligne** : toute alerte de ce contrôle se recoupe contre `doc.jeedom.com/fr_FR/dev/structure_info_json` avant de conclure, jamais l'inverse |
| **D-07UC04-08** | Trois specs d'UC **terminées** prescrivent encore `verif-plugin.py --tous` — `MVP/08-…-tech.md` l. 49, `post-mvp/02-…/03-…-tech.md` l. 513, `post-mvp/05-…/05-…-tech.md` l. 431 | ⚠️ **Délibérément NON corrigées** : ces documents attestent ce qui a été vérifié **à l'époque**, et la commande qu'ils citent produisait un **faux vert** (0 fichier analysé, code 0). Les réécrire ferait croire que la vérification avait été faite correctement — cela **masquerait l'incident** au lieu de le documenter. Le correctif A0 suffit à protéger les futurs lecteurs : la commande **échoue désormais bruyamment** (code 2), elle ne trompe plus personne. ⚠️ Les deux dernières s'appuyaient dessus comme **preuve** d'absence de clé i18n manquante : cette preuve était **sans valeur**. ✅ **Mais le fait est rétabli par ce cycle** : `--tout` a été exécuté sur tout le dépôt et rend **353 clés / 13 sections dans les 3 langues, 0 manquante, 0 orpheline** — la conclusion de ces UC était donc **juste, pour une mauvaise raison**. Aucune action corrective n'est due |

## 15. Incident de cycle — une liste reconstituée de mémoire

⚠️ **À lire avant d'ajouter un contrôle qui valide contre une nomenclature externe.**

Le contrôle A6 vérifie `category` contre une liste de valeurs autorisées. L'agent d'implémentation, **sans
accès réseau**, l'a reconstituée **de mémoire** — en le signalant honnêtement dans son rapport *et* dans un
commentaire du code. Vérification faite contre la source (`doc.jeedom.com/fr_FR/dev/structure_info_json`,
tableau « NOMENCLATURE CATEGORIES », le 2026-09-16) : la liste était **fausse dans les deux sens** —
une vingtaine de valeurs **inventées** (`iot`, `lighting`, `dashboard`, `device`, `sensor`, `system`,
`utility`, `meteo`, `other`…) et **six valeurs réelles omises** (`weather`, `monitoring`, `nature`,
`home automation protocol`, `automation protocol`, `automatisation`).

⚠️ **Ce qui rend le défaut pernicieux, c'est qu'il était INVISIBLE** : `wellness` figurant dans les deux
listes, le contrôle restait **vert** et aucune exécution n'aurait pu le révéler. Il n'aurait mordu que le
jour d'un repli vers `devicecommunication` (valeur réelle, qui passait par chance) ou d'un passage à
`weather`/`monitoring` (valeurs réelles qui auraient été **rejetées à tort**) — c'est-à-dire au pire
moment, en préparation de publication.

**Règle retenue** : un contrôle qui valide contre une **nomenclature externe** n'est acceptable que si sa
liste est **recopiée d'une source citée et datée**. À défaut de pouvoir la vérifier, il vaut mieux **ne pas
écrire le contrôle** que d'en écrire un qui affirme valider ce qu'il ne valide pas. ⚠️ Et la liste des
attributs d'A4 **n'est pas dans ce cas** : elle est issue d'un **audit du dépôt lui-même**, donc
re-mesurable hors ligne à tout moment — ne pas confondre les deux natures de liste.
