# Spec technique — UC02 « Scan unifié LAN + AUX Home + legacy avec fusion »

> **Domaine** : post-mvp/06-ergonomie-jeedom · **Spec fonctionnelle** : `02-scan-unifie-multi-sources.md`
> **Statut** : plan validé le 2026-09-15 · **Dépend de** : UC04 du domaine post-mvp/01 (fusion LAN/cloud),
> UC02 du domaine post-mvp/03 (découverte legacy)
>
> Toutes les affirmations de cette spec sur le **code existant** (signatures, nombre d'appelants, budgets,
> comportement du JS) ont été vérifiées par lecture directe et par `grep` le 2026-09-15, deux fois : par
> `jeedom-tech-planner` à la rédaction du plan, puis **indépendamment** par la revue advisor. Les écarts
> relevés entre le code et la documentation interne sont consignés au § 2 — **le code fait foi**.

## 0. Ce que fait cette UC, en une phrase

Elle **présente** ce que le socle sait déjà découvrir : `scannerClimatiseurs()` enchaîne déjà les trois
scans et `lignesFusionScan()` produit déjà une ligne par climatiseur. Cette UC (a) **enrichit** la ligne
fusionnée des colonnes manquantes, (b) **remplace les cinq tableaux de résultat par un tableau unique**
plus des sections résiduelles masquées quand elles sont vides, (c) **distingue « source non configurée »
de « source en échec »**, et (d) **durcit l'isolation par source**, aujourd'hui incomplète.

⚠️ **Invariants de conception de cette UC, à ne pas relâcher en implémentation** :
- **Aucun protocole de découverte n'est réimplémenté ni modifié.** Les trois briques de transport ne sont
  pas touchées. Aucune requête HTTP ni aucun paquet UDP nouveau n'est émis.
- **Aucune écriture nouvelle.** Toutes les méthodes ajoutées sont en **lecture seule** : ni `save()`, ni
  `setConfiguration()`, ni `cache::set()`. C'est ce qui tient AC5 par construction, et non par vigilance.
- **Aucune surface web nouvelle** : pas d'action AJAX nouvelle, pas de paramètre client, pas de classe
  nouvelle (donc **rien à ajouter à `core/php/smartclim.inc.php`**), `.htaccess` inchangés.
- **Aucune clé de configuration nouvelle**, ni plugin ni équipement. `plugin_info/configuration.txt/.php`
  n'est pas touché (la spec fonctionnelle exclut explicitement le formulaire de configuration).

## 1. Contrats externes

**Aucun.** Les trois découvertes (`smartclimBroadlinkLan::decouvrir()` / `lireEtat()`,
`smartclimAuxCloudApi::listerAppareils()`, `smartclimAuxHomeApi::listerAppareils()`) sont appelées
**exactement comme aujourd'hui**, à travers les méthodes `scanner*()` existantes.

Budgets de temps — **inchangés, et déjà bornés** (constantes vérifiées dans le code) :

| Phase | Budget | Nature |
|---|---|---|
| LAN | `smartclim::BUDGET_LAN` = 18 s | arrêt dur |
| Cloud historique | `smartclimAuxCloudApi::BUDGET_DECOUVERTE` = 25 s | global, O(N) déjà borné, émet `budget_epuise` |
| AUX Home | `smartclimAuxHomeApi::BUDGET_SCAN` = 25 s | arrêt dur |

**Pire cas tri-source : 68 s, inchangé.** La pré-garde du § 3 (D2) le ramène à **18 s** pour un parc
purement LAN. Aucun mécanisme de budget nouveau n'est introduit — il ferait double emploi.

## 2. Contrats internes réutilisés & écarts documentaires

### 2.1 Signatures réellement en place (vérifiées)

```php
smartclim::scannerClimatiseurs()   // public static ; retour array_merge($resultatCloud, {profils,
                                   //   etatsConnexion, lan, legacy, legacyErreur, cloudErreur, climatiseurs})
smartclim::scannerAuxHome()        // public static ; LÈVE une smartclimException déjà curatée,
                                   //   y compris sur !compteConfigure()
smartclim::scannerAuxCloud()       // public static ; garde SILENCIEUSE si compte legacy non configuré
smartclim::scannerReseauLocal()    // private static ; NE LÈVE JAMAIS
smartclim::indexerEquipements()    // private static -> {parLogicalId, parMac, parLanMac, parDeviceId,
                                   //   parEndpointAuxCloud, tous, noms}
smartclim::chercherEquipementExistant($_macNorm, $_deviceId, array $_index, $_transport = '',
                                      $_endpointAuxCloud = '')
smartclim::lignesFusionScan(array $_lignesLan, array $_lignesCloud, array $_etatsConnexion,
                            array $_auxCloud = array())
smartclim::ligneResultatScan($_nom, $_modele, $_mac, $_identifiant, $_enLigne, $_statut, $_equipementId = 0)
smartclim::ligneResultatLan($_nom, $_mac, $_ip, $_typeAppareil, $_statut, $_statutLibelle, $_equipementId = 0)
smartclim::categorieLigneLan($_statut, $_equipementId)   // 'climatiseur' | 'autre'
smartclim::libelleDisponibilite($_disponible)            // private ; UN SEUL appelant : lignesFusionScan()
smartclim::libelleEnLigne($_enLigne)                     // PUBLIC
smartclim::statutEnEchec($_statut)
smartclim::compteConfigure()                             // présence email + mot de passe + pays, PAS validité
smartclim->macEquipement() / adresseLan() / profilAffichable() / etatConnexionAffichable()
smartclimTransport::mode(smartclim $_eqLogic)            // 'auto' | 'local' | 'cloud'
smartclimCapabilities::libelleTransport($_transport)     // noms de marque, SANS __()
```

Faits vérifiés qui commandent des choix de cette spec :

- **`scannerAuxHome()` n'a qu'un seul appelant en production** : `scannerClimatiseurs()`. La pré-garde
  de D2 ne casse donc aucun autre chemin — et `scannerAuxHome()` reste **publique et strictement
  inchangée** (elle doit continuer de lever quand elle est appelée seule).
- **`lignesFusionScan()` fait aujourd'hui un `eqLogic::byType('smartclim')`**. Le remplacer par l'index
  calculé une fois dans `tableauxScanUnifie()` ne change **pas** le nombre de requêtes SQL.
- **`libelleDisponibilite()` a 3 sites d'appel, tous dans `lignesFusionScan()`** : l'ajout d'un
  2ᵉ paramètre par défaut ne peut pas régresser ailleurs.
- **`resultat.legacy.resume` est calculé puis jamais lu par le JS** (seuls `.appareils` et
  `.compteurs.crees` le sont) : cette UC le récupère comme `detail` de sa source.
- **`ligneResultatScan()` a 15 sites d'appel** (lignes 824, 848, 882, 888, 908, 915, 1013, 1024, 1029,
  1042, 1055, 1075, 1088, 1108, 1112). **Aucun n'est touché** : le classement `ecartes`/`autres` vit
  dans une méthode dédiée, pas dans une colonne ajoutée à la ligne.
- **`categorieLigneLan()` renvoie `climatiseur` dès que `equipementId > 0`, quel que soit le statut.**
  C'est ce qui garantit que le classement du § 5.6 ne peut pas diverger d'elle.
- **Aucun autre fichier du dépôt (JS, PHP, CSS) ne référence** les trois tables ni les deux `span_`
  supprimés au § 6 : la suppression ne casse rien en silence.

### 2.2 Écarts entre la documentation interne et le code réel

⚠️ Ces écarts **n'invalident pas le plan** — ils invalident des documents. À corriger au § 12.

1. `CLAUDE.md` décrit `chercherEquipementExistant($mac, $deviceId, $index, $transport = '')` et
   « 7 clés » ; la signature réelle porte un **5ᵉ paramètre** `$_endpointAuxCloud = ''` et **8 étapes**
   (la 8ᵉ = `auxcloud_endpoint_id`, gardée par transport).
2. `CLAUDE.md` décrit `lignesFusionScan()` comme « LAN oui/non, cloud oui/non, transport actif » : le
   code porte **déjà** une 4ᵉ colonne `cloudHistorique`.
3. ⚠️ **`.memory/analyse/smartclim-architecture-jeedom.md` § 3.2 est périmé** — et c'est le paragraphe
   que la spec fonctionnelle cite pour trancher le format de la colonne « identifiant cloud ». Il **ne
   traite pas cette question**, et sa table de clés de configuration ne correspond à **rien** dans le
   code : il liste `capabilities`, `temp_step`, `lan_mac_source`, `transport_actif`, `etat_optimiste`,
   `marque`, `auxcloud_region` par équipement — **zéro occurrence de chacune** (`grep`). Les clés réelles
   sont `capacites`, `temp_min`/`temp_max`/`temp_pas`, `lan_ip`/`lan_mac`, `transport_mode`,
   `auxcloud_endpoint_id`/`_product_id`/`_devicetype_flag`/`_family_id`/`_partage`. Le transport actif
   est une **commande info** (`smartclim::CMD_TRANSPORT`), pas une clé de config ; l'état optimiste vit
   en **cache** (`smartclim::ordres::<id>`) ; `auxcloud_region` est une clé **plugin**, pas équipement.
   Le § 3.3 du même fichier est périmé de même (`auxhome_login` → réel `auxhome_email` ; « pays déduit du
   fuseau Jeedom » → **révoqué en recette**, remplacé par `PAYS_DEFAUT`).
4. § 4 du même fichier décrit le rapprochement en **4 étapes** ; le code en a **8**, avec la correction
   « tous les ordres directs avant tous les ordres inversés » apportée à l'UC04 du domaine post-mvp/01.

## 3. Décisions d'architecture

**D1 — Colonne « identifiant cloud » quand l'appareil possède deux identifiants.**
→ **Une seule colonne**, valeur construite **côté serveur** par `identifiantCloudAffichable()` :
- un seul identifiant ⇒ **la valeur nue** — l'affichage actuel est strictement préservé, cas de la quasi
  totalité du parc ;
- deux identifiants ⇒ `AUX Home : <id> · AC Freedom : <id>`, préfixes issus de
  `smartclimCapabilities::libelleTransport()` (**noms de marque, aucune clé i18n**).

*Deux colonnes ont été écartées* : le tableau en porte déjà 11, et deux colonnes d'identifiant presque
toujours à moitié vides coûtent de la largeur sans lever d'ambiguïté que le préfixe ne lève déjà.
⚠️ La spec fonctionnelle renvoyait au § 3.2 de l'analyse pour trancher ce point : **ce paragraphe ne
répond pas à la question et il est périmé** (cf. § 2.2). La décision est donc prise ici.

**D2 — « Source non configurée » vs « source en échec » : états distincts, rendus distincts.**
Quatre codes serveur — `ok` / `degradee` / `echec` / `non_configuree` — rendus dans un bloc **persistant**
« Sources interrogées » de trois lignes. Conséquences :
- **Pré-garde `compteConfigure()` avant `scannerAuxHome()`** — *seul changement de comportement
  fonctionnel de cette UC*, **arbitré avec l'utilisateur le 2026-09-15**. Aujourd'hui, un parc purement
  LAN déclenche à **chaque** clic une alerte modale « Compte AUX Home non configuré », faux positif
  visible. Elle devient un état persistant « Non configurée », et le scan passe de 68 s à **18 s**.
  ⚠️ Ce n'est **pas** une perte de signal : `compteConfigure()` teste la **présence** des identifiants,
  jamais leur validité — des identifiants renseignés mais **faux** traversent le chemin normal et
  produisent une vraie erreur `cloudErreur`, donc une vraie alerte.
- `showAlert` n'est plus émis que pour `echec`, jamais pour `non_configuree`.
- Le 3ᵉ état se propage **jusqu'aux cellules** du tableau (« Non interrogé »). Sans cela, la colonne
  « Disponible dans le cloud historique » afficherait « Non » sur tout un parc sans compte legacy —
  c'est-à-dire un échec là où il n'y a rien à échouer.

**D3 — Sort des tableaux existants : un tableau unifié, sections résiduelles masquées quand vides.**
Arbitré avec l'utilisateur le 2026-09-15.
- Les **trois tables par source** (`table_scanTrouves`, `table_scanAuxCloud`, `table_scanLan`) sont
  **supprimées**. La seule information qu'elles portaient et que le tableau unifié ne reprend pas est
  l'ensemble des lignes **sans équipement** (ignoré, en erreur, injoignable…) — que les revues croisées
  de l'UC02 legacy ont explicitement rendues visibles (« jamais passé sous silence »). Elles sont
  **regroupées** dans `table_scanEcartes`, avec une colonne **Source**.
- La section **« Autres appareils »** est **conservée séparément** et **généralisée aux trois sources**.
  ⚠️ Motif dirimant, à ne pas défaire : elle répond à une **preuve négative** (ce n'est pas un
  climatiseur : RM4 Pro, pompe à chaleur), tandis que « écartés » répond à une **absence de preuve**
  (climatiseur possible, non rattaché). Les fondre reviendrait à annuler la distinction introduite par la
  recette du 2026-09-12.
- `table_scanDisparus` **conservée** : ce n'est pas un résultat de scan mais un équipement connu absent
  du compte (AC6 de l'UC03 du MVP). Simplement masquée quand vide.
- **Couverture d'AC1** : les trois sections résiduelles étant masquées quand vides, le cas nominal
  affiche **un seul tableau de climatiseurs**. ⚠️ Formulation exacte : le bloc « Sources interrogées »
  reste visible en plus — il y a donc deux tableaux à l'écran, dont **un seul de résultats**. AC1
  interdit « trois listes séparées » par source, pas un compte-rendu.

**D4 — Budget global & comportement AJAX : une seule action, inchangée.**
L'action `scannerClimatiseurs` reste unique. Trois raisons, dans l'ordre :
1. **La spec impose la fusion côté serveur.** Trois appels AJAX enchaînés obligeraient soit à renvoyer
   les lignes de chaque phase au serveur pour une 4ᵉ requête de fusion (le serveur fusionnerait alors des
   données venues du client), soit à créer une **8ᵉ mémoire de cache** inter-requêtes. Les deux sont plus
   coûteux et plus fragiles que le statu quo.
2. **Le budget global est déjà tenu par construction** (cf. § 1) : chaque phase porte son propre budget à
   arrêt dur.
3. La pré-garde de D2 **réduit** le pire cas pour les parcs mono-source.

`session_write_close()` est **déjà** appelé juste après `ajax::init()` dans `core/ajax/smartclim.ajax.php`
— l'invariant « un handler long ne fige pas l'interface » est tenu, rien à ajouter. `timeout: 80000` côté
jQuery **inchangé** (et un timeout jQuery n'interrompt pas le PHP). Aucun `set_time_limit()`.

**D5 — Colonne « Marque » : non livrée.** Arbitré avec l'utilisateur le 2026-09-15.
Aucune des trois sources ne publie de marque (AUX Home → `modelId` ; legacy → `productId`, un GUID de
32 hexa ; LAN → un `devtype` numérique). La déduire supposerait une table « modèle → marque »,
c'est-à-dire exactement la **liste de références commerciales** que le `brief.md` et `CLAUDE.md`
proscrivent comme critère de prise en charge. **Aucun des huit AC ne l'exige** — elle n'apparaît que dans
« Comportement attendu ». La colonne « Modèle » porte la seule information réellement disponible.
⚠️ **Écart assumé à la spec fonctionnelle**, consigné ici et au § 11.

**D6 — Où vit la fusion** : intégralement côté serveur (`tableauxScanUnifie()` / `lignesFusionScan()`).
Le JS n'assemble **aucun** libellé et n'arbitre **aucune** catégorie — il injecte en `.text()` des chaînes
déjà traduites. Doctrine inchangée.

## 4. Architecture — fichiers

| Fichier | État | Contenu | Indentation |
|---|---|---|---|
| `core/class/smartclim.class.php` | **modifié** | `scannerClimatiseurs()` remaniée ; `lignesFusionScan()` enrichie ; `libelleDisponibilite()` à 3 états ; `scannerReseauLocal()` + 1 clé de retour ; **7 méthodes privées nouvelles** (les 5 du § 5, plus les deux helpers de libellé `libelleEtatSource()` / `niveauEtatSource()` extraits de `sourcesScan()` à l'implémentation) | **2 espaces**, CRLF |
| `desktop/php/smartclim.php` | **modifié** | bloc « Sources interrogées » ; `table_scanClimatiseurs` à 11 colonnes ; suppression de 3 wrappers + 2 `span_` ; nouveau `div_scanEcartesWrapper` ; paragraphe de « Autres appareils » généralisé | ⚠️ **TABULATIONS**, CRLF |
| `desktop/js/smartclim.js` | **modifié** | remplissage des nouveaux tableaux, aiguillage par catégorie, alertes sur échec réel uniquement ; `ajouterLigneScan()` réutilisée telle quelle | **2 espaces**, CRLF |
| `core/ajax/smartclim.ajax.php` | **inchangé** | même action, même `isConnect('admin')`, `session_write_close()` déjà présent | (4 espaces) |
| `core/php/smartclim.inc.php` | **inchangé** | aucune classe nouvelle | — |
| `core/config/smartclim.config.ini` | **inchangé** | aucune clé nouvelle | — |
| `plugin_info/*` | **inchangé** | aucune dépendance, aucun démon, formulaire hors périmètre | — |
| `core/i18n/*.json` | **non touchés dans ce cycle** | traduction par `translator` en fin de workflow | — |

## 5. Signatures

### 5.1 `scannerClimatiseurs()` — modifiée (public static, contrat de retour **additif**)

Trois phases, **trois blocs `try` indépendants**, chacun avec `catch (smartclimException)` **puis**
`catch (Throwable)` :

1. **Phase LAN** — `scannerReseauLocal()` continue de ne jamais lever ; le `try` est une **ceinture**,
   pas un déplacement de responsabilité. Sans lui, un `Error` PHP 8 levé hors de ses `try` internes
   ferait perdre **tout** le scan.
2. **Phase legacy** — appel inchangé, `catch (Throwable)` ajouté.
3. **Phase AUX Home** — **pré-garde `self::compteConfigure()`** (cf. D2) ; `scannerAuxHome()` elle-même
   **strictement inchangée**.

⚠️ **Jamais de `return` dans un de ces blocs** — même piège que les quatre cycles de `cron()` : un
`return` dans la phase LAN court-circuiterait les deux phases cloud et contredirait AC3 à la lettre.

⚠️ **Correctif réel, pas une précaution théorique** : aujourd'hui la phase LAN n'est protégée par aucun
`try`, et les deux phases cloud ne rattrapent que `smartclimException`. Un `TypeError` y fait perdre les
résultats des phases **déjà terminées**.

Retour : toutes les clés existantes **conservées** (`resume`, `compteurs`, `appareils`, `disparus`,
`profils`, `etatsConnexion`, `lan`, `legacy`, `legacyErreur`, `cloudErreur`) **plus** `sources`,
`climatiseurs` (enrichie), `ecartes`, `autres`, `lanErreur`.

### 5.2 `sourcesScan()` — nouvelle, private static, **fonction pure**

```php
private static function sourcesScan(array $_lan, $_lanErreur, array $_legacy, $_legacyErreur,
                                    array $_cloud, $_cloudErreur, $_cloudConfigure, $_legacyConfigure)
// @return array<int, array{code:string, libelle:string, etat:string, etatLibelle:string,
//                          detail:string, niveau:string}>
```

Trois entrées, dans l'**ordre d'exécution** : LAN, cloud historique, AUX Home.
- `libelle` ← `smartclimCapabilities::libelleTransport()` (**noms de marque, zéro clé i18n**).
- `etat` ∈ `{'ok', 'degradee', 'echec', 'non_configuree'}` — codes serveur, jamais assemblés côté client.
- `niveau` ∈ `{'ok', 'warning', 'neutre'}` — la **seule** chose dont le JS dérive une classe CSS.
- `detail` : le `resume` de la source, ou le message d'erreur **déjà curaté**, ou — pour
  `non_configuree` — les littéraux **déjà existants** `Compte AUX Home non configuré : renseignez
  l'e-mail et le mot de passe` et `Compte cloud historique non configuré : renseignez l'e-mail et le mot
  de passe` (zéro clé i18n nouvelle).
- LAN : `degradee` si `$_lan['diffusionIndisponible']`, `echec` si `$_lanErreur`, sinon `ok`.
  ⚠️ **Jamais `non_configuree` pour le LAN** : il n'y a rien à y configurer, il est toujours tenté.

### 5.3 `tableauxScanUnifie()` — nouvelle, private static, orchestrateur d'affichage

```php
private static function tableauxScanUnifie(array $_lan, array $_legacy, array $_cloud,
                                           array $_etatsConnexion, array $_profils, array $_etatsSource)
// @return array{climatiseurs:array, ecartes:array, autres:array}
```

1. `$index = self::indexerEquipements();` — **une seule requête SQL**, qui *remplace* le
   `eqLogic::byType('smartclim')` fait aujourd'hui par `lignesFusionScan()`.
2. 2ᵉ passe : `resoudreEquipementLignes()` sur chacun des trois tableaux.
3. `climatiseurs` ← `lignesFusionScan($lanResolu, $cloudResolu, $_etatsConnexion, $legacyResolu, $contexte)`.
4. `ecartes` / `autres` ← `lignesResiduellesScan()`.
5. `try/catch (Throwable)` **global** → tableaux vides en dernier recours : elle tourne après jusqu'à
   68 s de travail réseau, elle ne doit jamais le faire perdre.

### 5.4 `resoudreEquipementLignes()` — nouvelle, private static, **LECTURE SEULE**

```php
private static function resoudreEquipementLignes(array $_lignes, $_transport, array $_index)
// @return array  Mêmes lignes, 'equipementId' renseigné quand un rapprochement tardif est trouvé.
```

Pour chaque ligne de `equipementId === 0` et de MAC non vide, rappelle `chercherEquipementExistant()`
avec les arguments propres au transport :

| Transport | Appel |
|---|---|
| LAN | `($mac, '', $_index, TRANSPORT_BROADLINK_LAN)` |
| AUX Home | `($mac, $identifiant, $_index)` |
| Legacy | `($mac, '', $_index, TRANSPORT_AUX_CLOUD_LEGACY, $identifiant)` |

⚠️⚠️ **Pourquoi cette 2ᵉ passe existe — la vraie raison, à tester en recette.** Le symptôme évident est
une ligne fantôme dans « écartés ». Le défaut réel est plus sérieux : une ligne LAN émise **avant** que le
cloud ne crée l'équipement garde `equipementId === 0`, donc le rapprochement de `lignesFusionScan()` ne la
retrouve jamais — et la ligne fusionnée affiche **« Disponible en LAN : Non » pour un appareil que le LAN
vient de voir dans CE scan**. C'est un défaut d'exactitude d'**AC6**, pas seulement de présentation.
**Cas de recette à exercer explicitement** : appareil vu d'abord par le LAN sans y être créé (preuve
`STATUT_ETAT_LU` absente), puis créé par un cloud dans le même scan.

⚠️ **Deux gardes non négociables** :
- **N'écrit jamais** (ni `save()`, ni `setConfiguration()`, ni cache). C'est un rattrapage d'**affichage** ;
  en faire un chemin d'écriture contournerait les gardes de preuve (`STATUT_ETAT_LU`,
  `preuve_climatiseur`) qui conditionnent la création d'équipement.
- **Saute les lignes de statut `ignore_doublon`** : une telle ligne désigne par construction un
  équipement qu'une autre ligne a déjà consommé ; la résoudre produirait deux lignes pour le même
  appareil et un faux « vu par cette source ».

### 5.5 `lignesFusionScan()` — modifiée (reste **le** lieu unique de la fusion)

```php
private static function lignesFusionScan(array $_lignesLan, array $_lignesCloud,
                                         array $_etatsConnexion, array $_auxCloud = array(),
                                         array $_contexte = array())
// $_contexte : {'index' => array, 'profils' => array<int,array>, 'sources' => array}
// @return array<int, array{nom, mac, ip, modele, identifiantCloud, lan, cloud, cloudHistorique,
//                          enLigne, capacites, transport}>   (11 chaînes, toutes déjà traduites)
```

5ᵉ paramètre **optionnel** (comme l'a été `$_auxCloud`), **groupé en un tableau** plutôt qu'en trois
positionnels supplémentaires. Composition d'une ligne :

| Clé | Source de la valeur |
|---|---|
| `nom` | `$eqLogic->getName()` (inchangé) |
| `mac` | `$eqLogic->macEquipement()` (inchangé) |
| `ip` | `ip` de la ligne LAN de ce scan → `$eqLogic->adresseLan()['ip']` → `''` |
| `modele` | `modele` ligne AUX Home → `modele` ligne legacy → `typeAppareil` ligne LAN → `''` |
| `identifiantCloud` | `identifiantCloudAffichable()` (cf. D1) |
| `lan` / `cloud` / `cloudHistorique` | `libelleDisponibilite($vu, $interrogee)` — **3 états** |
| `enLigne` | `libelleEnLigne()` de la ligne AUX Home, sinon du legacy, sinon `État inconnu` (clé **existante**) |
| `capacites` | `$_contexte['profils'][$id]['modes']` → `['concepts']` → `$eqLogic->profilAffichable()` |
| `transport` | `$_etatsConnexion[$id]['transport']`, sinon `Inconnu` (inchangé) |

⚠️ **`enLigne` n'est JAMAIS déduit du LAN** : `etatAppareil()` du LAN ne pose jamais `online` — un LAN
muet ne prouve pas qu'un appareil est hors ligne. Ne pas « compléter » ce contrat (cf. § 9, risque 4).

⚠️ Le repli de `capacites` sur `profilAffichable()` n'est pas décoratif : les lignes `ignore_doublon` et
`ignore_mode_cloud` portent un `equipementId` **sans figurer dans `eqLogicsTouches`**, donc sans entrée
dans `$_profils`.

Règles des trois états de disponibilité :
- **`cloud`** : `interrogee = ($_contexte['sources'][AUX_HOME]['etat'] !== 'non_configuree')`.
- **`cloudHistorique`** : idem sur l'entrée legacy.
- **`lan`** : `interrogee = false` si la ligne LAN de cet équipement porte le statut `ignore_mode_cloud`
  (sonde délibérément non émise, `smartclimTransport::sondeLanAutorisee()` faux), `true` sinon.
  Le prédicat « vu » reste `!statutEnEchec($statut)`, inchangé.

### 5.6 `lignesResiduellesScan()` — nouvelle, private static

```php
private static function lignesResiduellesScan(array $_lan, array $_legacy, array $_cloud)
// @return array{ecartes:array, autres:array}
// ligne : array{source:string, nom:string, mac:string, adresse:string, statutLibelle:string}
```

Ne traite que les lignes de `equipementId === 0` **après** la 2ᵉ passe. Classement **unique**, dans cette
seule méthode — **aucune colonne `categorie` n'est ajoutée à `ligneResultatScan()`**, donc aucun de ses
15 sites d'appel n'est touché :

- famille **`autres`** — *preuve négative, ce n'est pas un climatiseur* : LAN
  `smartclimBroadlinkLan::STATUT_ETAT_ILLISIBLE`, legacy `ignore_pompe_chaleur`, `ignore_non_climatiseur` ;
- famille **`ecartes`** — tout le reste : `ignore_identifiant`, `ignore_doublon`, `ignore_budget`,
  `erreur`, LAN injoignable / refusé / verrouillé / MAC divergente / non sondé.

`source` ← `smartclimCapabilities::libelleTransport()` (zéro clé i18n). `adresse` ← `ip` (LAN) ou
`identifiant` (clouds).

⚠️ Le critère de la famille `autres` côté LAN est **exactement** celui de `categorieLigneLan()`
(`STATUT_ETAT_ILLISIBLE`) : les deux ne peuvent pas diverger, puisque c'est déjà la preuve qui conditionne
la création d'équipement. **Ne jamais trancher sur `type_appareil` (devtype)** — une liste blanche de
codes exclurait tout firmware inconnu, contre le principe directeur du brief.

### 5.7 `identifiantCloudAffichable()` — nouvelle, private static, fonction pure

```php
private static function identifiantCloudAffichable(array $_identifiants)  // code transport => identifiant
```

Cf. D1 : valeur nue si un seul identifiant, préfixée et jointe par ` · ` si deux.

### 5.8 `libelleDisponibilite()` — modifiée

```php
private static function libelleDisponibilite($_disponible, $_interrogee = true)
```

`!$_interrogee` → `Non interrogé`. Le paramètre par défaut reproduit le comportement actuel ; son unique
appelant est `lignesFusionScan()`.

### 5.9 `scannerReseauLocal()` — modifiée d'une ligne

`'diffusionIndisponible' => $diffusionIndisponible` ajouté au tableau de retour : la variable existe
déjà, elle n'était transmise qu'à `resumeScanLan()`. **Aucun autre changement.**

## 6. Server vs Client

**Tout est serveur.** Le JS ne fait que du rendu : il reçoit des lignes dont **chaque cellule est déjà une
chaîne traduite**, les injecte en `.text()`, et masque les sections vides. Il n'assemble aucun libellé,
ne dérive aucune catégorie, et ne fait qu'une seule chose à partir d'un code serveur : mapper
`niveau` ∈ `{ok, warning, neutre}` sur une classe CSS.

Structure cible de `desktop/php/smartclim.php` :

```
#div_scanResultat
  ├─ #div_scanSourcesWrapper      → table_scanSources        [Source | État | Détail]        (toujours visible)
  ├─ #div_scanClimatiseursWrapper → table_scanClimatiseurs
  │     [Nom | Adresse MAC | Adresse IP | Modèle | Identifiant cloud | Disponible en LAN |
  │      Disponible dans le cloud | Disponible dans le cloud historique | État en ligne |
  │      Capacités détectées | Transport actif]
  ├─ #div_scanEcartesWrapper      → table_scanEcartes    [Source | Nom | Adresse MAC | Adresse | Résultat]  (masqué si vide)
  ├─ #div_scanLanAutresWrapper    → table_scanLanAutres  (mêmes colonnes, paragraphe conservé)              (masqué si vide)
  ├─ #div_scanDisparusWrapper     → table_scanDisparus   (INCHANGÉ)                                          (masqué si vide)
  └─ #bt_scanRecharger            (INCHANGÉ)
```

**Supprimés** : `div_scanTrouvesWrapper`, `div_scanAuxCloudWrapper`, `div_scanLanWrapper`,
`span_scanResume`, `span_scanResumeLan`.

⚠️ **`div_scanLanAutresWrapper` et `table_scanLanAutres` GARDENT leur identifiant** (décision prise à la
revue advisor, qui avait relevé l'ambiguïté du plan) : seuls le titre et le paragraphe explicatif sont
généralisés. Renommer l'id n'apporterait rien et obligerait à toucher le sélecteur JS.

⚠️ Le paragraphe explicatif de cette section **doit être reformulé sans « Broadlink »** : il accueille
désormais aussi les pompes à chaleur du cloud legacy — sinon il devient faux pour une partie de ses lignes.

## 7. Validation & erreurs

- **Isolation par source (AC3)** : cf. § 5.1 — trois `try` indépendants, `catch (smartclimException)`
  puis `catch (Throwable)`, jamais de `return` dans un bloc.
- **Aucune `smartclimException` nouvelle.** Les messages de `cloudErreur` / `legacyErreur` sont **déjà
  curatés** par `messageErreurAuxHome()` / `messageErreurAuxCloud()` : ne jamais les re-traduire ni les
  concaténer à un code technique. Pour le `catch (Throwable)` ajouté : chaîne curatée nouvelle (§ 8) +
  `log::add('smartclim', 'error', …)` passant par `neutraliserPourLog()`.
- **Validation d'entrée** : **aucune donnée client n'est reçue** par cette action — le JS n'envoie que
  `action=scannerClimatiseurs`. Il n'y a rien à valider au-delà des gardes existantes.
- **Validation côté client** : aucune ; le JS ne fait que du rendu.
- **Ne lèvent jamais** : `tableauxScanUnifie()`, `lignesFusionScan()`, `lignesResiduellesScan()` — la
  première porte le `try/catch (Throwable)` qui couvre les trois.

## 8. Sécurité

- **AC4 — liste blanche, jamais un `array_merge` d'une ligne brute de transport.** La ligne fusionnée
  (11 clés, § 5.5) et la ligne résiduelle (5 clés, § 5.6) sont des listes blanches **explicites**.
  ⚠️ Les lignes **brutes** du transport legacy portent `cookie` (base64 d'une **clé AES d'appairage**) et
  `dev_session` ; celles d'AUX Home portent `trame_controle`, `trame_running`, `capacites_brutes`.
  **Aucun tableau brut ne doit jamais remonter au front.**
- **Jamais dans la réponse AJAX** : e-mail de compte, mot de passe, `loginsession`, `userid`, `uid`,
  jeton bearer, clé de session LAN, empreinte de session.
- `identifiantCloud` = `deviceId` / `endpointId` : identifiant **d'appareil**, pas de compte — AC4 vise
  l'« identifiant de compte complet ». Déjà affiché aujourd'hui, sur une surface **admin authentifiée**
  (`isConnect('admin')`). Conservé.
- **Rendu** : tout passe par `ajouterLigneScan()` → `$('<td></td>').text(valeur)`.
  ⚠️ **Jamais `.html()` ni `innerHTML`** : `nom`, `modele` et `identifiantCloud` sont des données
  d'origine externe, dont certaines viennent d'une **découverte LAN non authentifiée**, et
  `cleanComponanteName()` du core **ne retire ni `<` ni `>`**.
- **Aucun `echo` de donnée externe ajouté** dans `desktop/php/smartclim.php` : les tableaux sont des
  squelettes vides remplis par le JS. Le seul `echo` de donnée externe du fichier (nom d'équipement, déjà
  corrigé en `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')` à l'UC04 du domaine post-mvp/01) n'est pas touché.
- **Logs** : les seuls ajouts sont dans les `catch (Throwable)` — `get_class($t)` +
  `neutraliserPourLog($t->getMessage())`, **jamais `getTraceAsString()`**.

## 9. Risques

1. **Tableau à 11 colonnes** — `table-responsive` est déjà en place (scroll horizontal), mais la
   lisibilité sur écran étroit se dégrade. À juger en recette ; si nécessaire, « Modèle » est la première
   candidate au retrait (elle porte un GUID inexploitable côté legacy).
2. **Timeout de reverse-proxy** — le pire cas tri-source (68 s) dépasse le `fastcgi_read_timeout` de 60 s
   par défaut de nginx. **Risque préexistant, non introduit par cette UC**, et **atténué** pour les parcs
   mono-source par la pré-garde de D2. À surveiller sur une installation servie par nginx.
3. **Changement d'emplacement à annoncer en recette** — un appareil LAN injoignable et sans équipement
   quittait le tableau « Climatiseurs détectés sur le réseau local » ; il apparaît désormais dans
   « Appareils écartés », avec le même libellé de statut. Rien n'est perdu, l'emplacement change.
4. **Colonne « État en ligne » systématiquement « État inconnu » sur un parc purement LAN** —
   `etatAppareil()` du LAN ne pose jamais `online`. ⚠️ **Ne pas « corriger »** en déduisant `online = true`
   d'une lecture LAN réussie : ce serait inventer un fait que le transport refuse d'établir.
5. **2ᵉ passe de rapprochement** — si `chercherEquipementExistant()` se trompait (collision d'index MAC),
   une ligne serait rattachée au mauvais équipement **à l'affichage seul**. Conséquence bornée à une ligne
   mal placée — **à condition que l'invariant « n'écrit jamais » soit tenu**. C'est le point de revue n° 1
   de cette UC.
6. **Ordre d'exécution et `source` du profil** — LAN → legacy → AUX Home, et `array_replace` fait gagner
   le dernier. Un équipement vu par les trois voit « Transport actif » et la `source` de son profil
   refléter AUX Home. Voulu. Cela **ne signifie pas** que les capacités LAN sont perdues :
   `appliquerCapacites()` **unionne**, et le profil LAN publie `modes`/`vitesses` vides précisément pour
   ne rien réintroduire. Ne pas « améliorer » ce point sans relire l'exception `modes_exclus`.
7. **Verrou de scan pris par phase, pas globalement** (`CLE_CACHE_VERROU_SCAN`, 60 s) — deux admins
   scannant simultanément verront une phase de l'un échouer par « Un scan est déjà en cours ».
   Comportement **inchangé** ; il devient simplement **visible** dans le bloc « Sources » au lieu d'une
   alerte fugace. Un verrou global exigerait de refactorer `scannerAuxHome()` / `scannerAuxCloud()` — hors
   périmètre.

## 10. Critères d'acceptation — couverture

| AC | Porté par | Vérification |
|---|---|---|
| **AC1** | Tableau unifié `table_scanClimatiseurs` ; trois tables par source supprimées ; sections résiduelles masquées quand vides | recette |
| **AC2** | Fusion par `equipementId` dans `lignesFusionScan()` **+ 2ᵉ passe** `resoudreEquipementLignes()` (§ 5.4) | recette |
| **AC3** | Trois `try/catch (Throwable)` indépendants (§ 5.1) + `sourcesScan()` (§ 5.2) | recette |
| **AC4** | Listes blanches explicites (§ 8) ; rendu `.text()` | recette + review sécurité |
| **AC5** | **Aucune écriture nouvelle** — toutes les méthodes ajoutées sont en lecture seule | recette (cf. § 11) |
| **AC6** | Colonnes `lan` / `cloud` / `cloudHistorique` à **3 états** (§ 5.5) | recette |
| **AC7** | `chercherEquipementExistant()` réutilisée telle quelle, 8 étapes, garde palindrome | ⚠️ **lecture de code uniquement** (cf. § 11) |
| **AC8** | Colonne `capacites` alimentée par le profil unioné, repli `modes` → `concepts` → `profilAffichable()` | recette |

## 11. Points à valider en recette / limites assumées

- **AC5 — idempotence**, à exercer explicitement : second scan ⇒ **même nombre d'équipements** ; un
  équipement **renommé** garde son nom (`setName()` n'est appelé que dans `creerEquipement()`) ;
  `transport_mode` forcé en LOCAL **reste** LOCAL (aucun chemin du scan n'écrit cette clé) ; une commande
  réaffichée à la main reste visible (`masquerCommandesTuile()` porte le marqueur versionné
  `CLE_MASQUAGE_TUILE`).
- **AC6, cas particulier** : un équipement en MODE_CLOUD ne doit émettre **aucun** paquet LAN et doit
  afficher **« Non interrogé »**, pas « Non ».
- **AC2, cas à monter** : appareil vu d'abord par le LAN **sans y être créé**, puis créé par un cloud dans
  le même scan (cf. § 5.4) — c'est le seul montage qui exerce réellement la 2ᵉ passe.
- ⚠️ **AC7 restera vérifié par lecture de code, pas par mesure.** Le montage qui l'exerce (« équipement
  créé par le LAN, puis revu par le cloud historique avec la MAC inversée ») exige un appareil Broadlink
  **et** un compte legacy : le matériel de validation de l'utilisateur ne parle pas Broadlink, et aucun
  compte legacy n'est disponible. **Hypothèse à confirmer contre du matériel réel.**
- ⚠️ **Écart assumé à la spec fonctionnelle** : la colonne « Marque » du § « Comportement attendu » n'est
  **pas livrée** (D5), arbitré avec l'utilisateur le 2026-09-15. Aucun AC ne l'exige.

## 12. Dette

*(section alimentée à l'issue des reviews croisées — tour 1, 2026-09-16 : aucun `blocker`, `major`,
`critical` ni `high` ; un `minor` traité en passe de finition, deux observations consignées ci-dessous)*

- **D-UC02-06-02 — `lignesFusionScan()` cherche en O(N x M).** Elle balaie linéairement `$_lignesLan`,
  `$_lignesCloud` et `$_auxCloud` pour **chaque** équipement, au lieu d'indexer ces trois tableaux par
  `equipementId` une fois en amont. Sans impact réel à l'échelle d'un parc domestique de climatiseurs, et
  **non corrigé volontairement** dans ce cycle : le gain est nul et la réécriture toucherait le coeur de
  la fusion, seul endroit du plugin où les trois sources se rejoignent. ⚠️ À ne pas recopier comme motif
  si une boucle de ce genre est ajoutée ailleurs sur un ensemble non borné.

- **D-UC02-06-01 — `.memory/analyse/smartclim-architecture-jeedom.md` §§ 3.2, 3.3 et 4 sont périmés**
  (cf. § 2.2). Le § 3.2 est cité par la spec fonctionnelle comme référence pour trancher un point qu'il
  ne traite pas, et sa table de clés de configuration ne correspond à aucune clé réelle. À corriger à
  l'étape de capitalisation de ce cycle — c'est le cas le plus rentable : une analyse fausse est recopiée
  par les UC suivantes.
