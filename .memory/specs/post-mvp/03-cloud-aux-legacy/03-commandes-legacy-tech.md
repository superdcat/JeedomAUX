# Spec technique — UC03 du domaine post-mvp/03 : lecture et écriture des paramètres legacy

> **Spec fonctionnelle** : `03-commandes-legacy.md` · **Dépend de** : UC02 de ce domaine (découverte),
> UC01 (authentification) · **Arbitrages utilisateur** : 2026-09-09 (cf. § 3.0)

## 0. Périmètre et non-objectifs

Cette UC donne au transport `AUX_CLOUD_LEGACY` — livré à l'UC02 en **découverte seule** — sa **lecture
d'état** et son **écriture d'ordres**, derrière l'API générique déjà en place, puis le raccorde aux quatre
points d'entrée du plugin : la commande d'action, le bouton « Rafraîchir », le cron, et une CLI dédiée.

**Ce que cette UC ne fait pas** :

- Aucun temps réel, aucun WebSocket, aucun démon → domaine post-mvp/05.
- Aucun nouveau mode de transport dans l'IHM → `MODE_CLOUD` ne distingue pas les deux clouds (cf. R3).
- Aucun pilotage de pompe à chaleur → hors périmètre du plugin.
- Aucun disjoncteur de repli pour le legacy → `repliCloudActif()` reste adossée à AUX Home (cf. R9).
- **Aucune 7ᵉ route** : tout passe par `sdkcontrol`, déjà appelée par l'UC02.
- **Aucune classe nouvelle** → `core/php/smartclim.inc.php` **n'est pas touchée**.

⚠️ **Livré non recetté**, comme l'UC01 et l'UC02 : aucun compte AC Freedom n'est disponible. Le code est
vérifié contre **trois** références MIT recoupées, **jamais** contre le backend réel. Le § 9 dit, critère
par critère, ce qui est vérifiable par lecture et ce qui attend une recette.

---

## 1. Contrats externes

**Sources relues verbatim** (les mêmes dépôts, aux mêmes commits, que l'UC01 et l'UC02) :

- `maeek/ha-aux-cloud` — `api/aux_cloud.py::_act_device_params`, `::get_device_params`,
  `::set_device_params`, `::_get_directive_header`, `::get_devices` ; `api/const.py`.
- `GijsZwegers/com.zwegersit.auxairco` — `lib/auxcloud/legacyClient.ts::actOnDeviceParams`.
- `fparrav/homebridge-aux-cloud` — `src/api/constants.ts`.

### 1.1 La route, unique

`POST /device/control/v2/sdkcontrol?license=<LEGACY_LICENSE>` — **déjà appelée** par `requeteParametres()`
depuis l'UC02. Cette UC n'en ajoute aucune ; elle en ajoute deux **usages** (`get` à liste pleine, `set`).

⚠️⚠️ **Rappel de l'invariant qui coûte le plus cher sur ce transport** : les routes de découverte et de
contrôle **n'ont ni corps chiffré ni en-têtes `timestamp`/`token`** — seule `/account/login` les porte. Et
la **sentinelle de statut appartient à chaque appelant**, jamais à `requete()` : `sdkcontrol` n'a **aucun
`status`**, à aucun niveau. Il s'identifie par `event.header.name`.

### 1.2 L'enveloppe de directive

| Élément | Contrat vérifié | Sources |
|---|---|---|
| En-tête | `namespace: DNA.KeyValueControl`, `name: KeyValueControl`, `messageId` préfixé par l'`endpointId`, extra **`timstamp`** (faute de frappe de l'amont, **à reproduire telle quelle**) | 2 |
| `endpoint.devicePairedInfo` | `{did, pid, mac, devicetypeflag, cookie: <cookie mappé>}` | 2 |
| `payload` **get** | `{act:"get", params:[…], vals:[…], did}` — `vals = []` si `params` est vide **ou** a ≥ 2 entrées ; **`vals = [[{"val":0,"idx":1}]]` si et seulement si `params` a exactement 1 entrée** | 2, d'accord au caractère près |
| `payload` **set** | `{act:"set", params:[n1,n2,…], vals:[[{"idx":1,"val":v1}],…], did}` — listes **parallèles**, longueurs égales | 2 |
| Réponse (`get` **et** `set`) | `event.header.name === "Response"` (sinon `"ErrorResponse"`, message sous `event.payload.message`) **ET** `event.payload.data` = **chaîne JSON à re-parser** → `{params:[…], vals:[[{val}],…]}` recomposés **par index** | 2 |

### 1.3 ⚠️ Le « À confirmer » n° 1 de la spec fonctionnelle est FERMÉ — sans écrire une ligne

La spec fonctionnelle annonçait le format du jeton d'appairage comme « pas trivial, à valider à
l'implémentation ». Format établi, **concordance stricte des deux références** :

```
cookie mappé = base64( json_compact( {"device":{
    "id":         cookie.terminalid,
    "key":        cookie.aeskey,
    "devSession": device.devSession,
    "aeskey":     cookie.aeskey,
    "did":        endpointId,
    "pid":        productId,
    "mac":        mac
}} ) )
```

…le `cookie` d'origine étant lui-même `base64(json({terminalid, aeskey, …}))`. Côté Python,
`separators=(",",":")` ⇒ un `json_encode` PHP nu convient.

⚠️ **`smartclimAuxCloudApi::cookieMappe()` (livrée à l'UC02) est déjà conforme.** Rien à réécrire. Ce
point « À confirmer » se ferme par **vérification**, pas par implémentation — à répercuter dans
`.memory/analyse/smartclim-transport-aux-cloud-legacy.md` § 3.

### 1.4 Les numérotations — la TROISIÈME du plugin

| Clé | Correspondance | Sources concordantes |
|---|---|---|
| `ac_mode` | **0** COOL · **1** HEAT · **2** DRY · **3** FAN · **4** AUTO | **3** (`const.py`, `constants.ts`, analyse interne § 5) |
| `ac_mark` | **0** AUTO · **1** LOW · **2** MEDIUM · **3** HIGH · **4** TURBO · **5** MUTE | **2** |
| `temp` / `envtemp` | entiers **×10** (`240` = 24,0 °C) ; `envtemp` prouve une résolution native de **0,1 °C** | analyse interne § 5, non contredit |

⚠️ Conforme à ce qu'annonçait `CLAUDE.md`, **aucun écart**. Elle est différente d'AUX Home **et** du LAN :
c'est la raison d'être de `smartclimCapabilities::tables()`, et elle ne se code jamais en `switch`.

### 1.5 ⚠️ Le sens des oscillations reste INDÉTERMINÉ — contradiction confirmée verbatim

| Source | `swing_v` activé | désactivé |
|---|---|---|
| `maeek/ha-aux-cloud` | `{ac_vdir: 1}` | `{ac_vdir: 0}` |
| `fparrav/homebridge-aux-cloud` | `{ac_vdir: 0}` — commentaire : `// 0 = oscilar, 1 = fijo` | `{ac_vdir: 1}` |

**Les deux sont en production.** Aucune des deux ne peut être déclarée fausse par lecture. C'est le
« À confirmer » n° 2 de la spec fonctionnelle, et il **reste ouvert** : d'où le mécanisme d'ajustement du
§ 3.5, exigé par la spec elle-même (« sans modification de code si le sens s'avère inversé »).

### 1.6 ⚠️ Trois écarts avec les specs et analyses internes — à corriger en fin de cycle

1. **`devicetypeFlag` vs `devicetypeflag`** dans `devicePairedInfo` : `maeek` écrit `devicetypeFlag`
   (F majuscule), `GijsZwegers` `devicetypeflag`. **L'UC02 a implémenté la minuscule**, sans que sa spec
   technique signale l'écart. **Décision : conserver la minuscule** — cohérente avec l'Écart 2 d'UC02, et
   changer un champ déjà livré sans preuve serait une invention. ⚠️ **À inscrire en tête des points de
   recette** : si `sdkcontrol` rend systématiquement `ErrorResponse`, c'est le **premier suspect**.
2. **L'analyse interne § 4 affirme que `mode` est « nécessaire pour obtenir `envtemp` sur certains
   modèles ». Ce lien n'est établi par AUCUNE source lue.** Le paramètre demandé s'appelle littéralement
   `mode` (≠ `ac_mode`), et la référence se contente de fusionner sa réponse. **Analyse à amender** — et
   c'est ce qui fonde R2.
3. **L'analyse § 1 écrit** « pour les routes `device/control/*`, succès = `event.payload.status == 0`
   **et** `event.header.name == "Response"` ». **Faux pour `sdkcontrol`** (aucun `status`). Déjà corrigé
   dans le code par l'UC02 (§ 7.4), **pas encore dans l'analyse**.

### 1.7 Ce qui reste non prouvé

Le second `get` « paramètre spécial » (`AC_SPECIAL_PARAMS = ["mode"]`) est, dans la référence,
**conditionné au `productId`** et son résultat fusionné au dictionnaire de paramètres. Son rôle n'est
établi nulle part (cf. écart 2). **Il n'est pas émis sur le chemin périodique** — cf. R2.

---

## 2. Architecture — fichiers

| Chemin | Action | Ce qui y entre | Indentation / EOL |
|---|---|---|---|
| `core/class/smartclimAuxCloudApi.class.php` | modifié | `parametresAuxCloud()` étendue (13 concepts, colonnes `nature`/`lecture_seule`), `requeteSdkControl()`, `recomposerValeurs()`, `lireParametres()`, `appliquerOrdre()`, `decoderParametres()`, `jetonsAppareil()`, `sonderParametre()`, `etatAppareil()` **étendue additivement**, `capacitesAppareil()` (catalogue + `catalogue_par_defaut`), `requeteParametres()` **au contrat interne changé** (§ 6.4), constantes | **2 espaces**, CRLF |
| `core/class/smartclimCapabilities.class.php` | modifié | Entrée `TRANSPORT_AUX_CLOUD_LEGACY` dans `tables()` (mode + vitesse), `FACTEUR_TEMP_AUX_CLOUD_LEGACY` / `PAS_ECRITURE_AUX_CLOUD_LEGACY` + branche dans `echelleTemperature()`, accesseur **additif** `codesOscillation($_concept, $_transport)` | 2 espaces, CRLF |
| `core/class/smartclimTransport.class.php` | modifié | `cloudLegacyDisponible()`, `lectureLegacyAutorisee()`, +2 branches dans `transportRetenu()` | 2 espaces, CRLF |
| `core/class/smartclim.class.php` | modifié | Constantes, `appareilAuxCloud()`, `jetonsAuxCloud()`/`memoriserJetonsAuxCloud()`, `etatMarcheCourant()`, `envoyerOrdreAuxCloud()`, `envoyerCommandeActionAuxCloud()`, `rafraichirAuxCloudEquipement()`, `rafraichirAuxCloud()`, `cycleAuxCloudEchu()`/`marquerCycleAuxCloud()`, `diagnosticAuxCloud()`, `sonderParametreAuxCloud()`, +1 branche dans `executerCommandeAction()` (**remplacement** de la garde D7), +1 branche dans `rafraichirMaintenant()`, **+1 bloc dans `cron()`**, +1 clé dans `preSave()`, **neutralisation du catalogue dans `appliquerCapacites()`** | 2 espaces, CRLF |
| `core/php/commande-auxcloud.php` | **créé** | **5ᵉ CLI** du plugin (§ 8) | 2 espaces, CRLF |
| `desktop/php/smartclim.php` | modifié | 1 `<legend>` « Cloud historique » + 1 case à cocher `auxcloud_swing_inverse` | ⚠️ **tabulations**, CRLF |

### 2.1 Sans objet, explicitement

Pour qu'un implémenteur ne les cherche pas, et ne les « complète » pas par précaution :

- **`core/php/smartclim.inc.php`** : **aucune classe nouvelle**. La 5ᵉ CLI fait
  `require_once __DIR__ . '/../class/smartclim.class.php'`, comme les quatre autres.
- **`core/php/.htaccess`** : le `Deny from all` + whitelist du **seul** `jeeSmartclim.php` couvre
  `commande-auxcloud.php` **sans modification**. Ne rien y ajouter, et surtout jamais une extension.
- **`plugin_info/configuration.txt` / `.php`** : **aucune clé de config plugin nouvelle** ⇒ **aucune copie
  de miroir**. `auxcloud_swing_inverse` est une clé d'**équipement** : le piège du `preConfig_`
  court-circuité par l'INI ne s'y applique pas, et `core/config/smartclim.config.ini` reste intacte.
- `core/ajax/smartclim.ajax.php`, `desktop/js/smartclim.js` (une case `eqLogicAttr` est liée nativement
  par le core), `packages.json`, `info.json`, `core/template/`, `docs/`.
- **`smartclimFrame`** : ce transport n'a **aucune trame HVAC**, aucun offset. Ne pas y toucher.
- `smartclimBroadlinkLan`, `smartclimAuxHomeApi`, `smartclimDiagnostic`, `smartclimDemon` : **intacts**.
  `codesOscillation()` est **additif** — les 3 sites qui lisent `code_actif`/`code_fixe` ne changent pas.

---

## 3. Décisions

### 3.0 Les trois arbitrages utilisateur du 2026-09-09

| # | Question | Décision |
|---|---|---|
| **A** | 3ᵉ cycle cron legacy, alors que l'UC02 § 3.2 l'avait refusé le même jour | **Option A retenue : le cycle est ajouté** (§ 3.4). La décision d'UC02 disait « plus tard, à l'UC03 et au domaine 02 » — l'UC03 est cette UC, le domaine 02 est livré : ne pas le faire ici, c'est ne le faire jamais. ⚠️ **Le § 3.2 d'UC02 est donc RÉVISÉ, pas contredit** — à amender en fin de cycle |
| **B** | Livrer la 5ᵉ CLI `commande-auxcloud.php` | **Oui** (§ 8) — seul instrument capable de fermer les 4 points ouverts par un rapport communautaire |
| **C** | AC6 et AC7 non couvrables faute de matériel | **Accepté** : livraison non recettée, comme l'UC01 et l'UC02. Dette nommée (§ 12) |

### 3.1 D1 — Protection de `HEAT` : un critère d'orchestration, ZÉRO persistance

L'UC02 § 3.3 fixe le mandat : *« Limite d'AC3, levée à l'UC03 quand la table `TRANSPORT_AUX_CLOUD_LEGACY`
existera »*. Le legacy doit donc **publier** ses `modes`/`vitesses`. Le même § nomme le danger : l'union de
`appliquerCapacites()` réintroduirait `HEAT` sur une unité froid-seul dont AUX Home vient de prouver
qu'elle ne chauffe pas — *« exactement la régression du 2026-08-26 »*.

⚠️⚠️ **Une mémoire persistée de `modes_exclus` a été envisagée puis REJETÉE, et il faut savoir pourquoi
pour ne pas la réintroduire.** Elle confondait deux causes derrière un même signal (`modes_exclus = []`) :
« AUX Home n'a pas tourné dans cette passe » et « AUX Home confirme qu'il n'exclut plus rien ». Les traiter
pareil rend une exclusion **fausse indéfectible** autrement qu'en supprimant l'équipement — soit
exactement l'auto-guérison que `CLAUDE.md` décrit comme le **motif d'existence** de cette exception à
l'union (« la migration du parc est automatique au premier scan, sans script »).

**Forme retenue** — le critère manquant n'est pas une mémoire, c'est « **cet équipement est-il AUSSI connu
d'AUX Home ?** », soit la présence d'`auxhome_device_id`. Il n'est pas disponible dans le transport (c'est
un état côté Jeedom) ; il l'est dans `appliquerCapacites()`, point de passage **unique** de toute fusion.

| Couche | Ce qu'elle fait |
|---|---|
| `smartclimAuxCloudApi::capacitesAppareil()` | Publie ce qu'elle **sait** : `modes`/`vitesses` = catalogue `valeursLisibles(TRANSPORT_AUX_CLOUD_LEGACY, …)`, **plus une colonne déclarative `'catalogue_par_defaut' => true`** — « ce que je publie est un catalogue faute de pouvoir rien exclure, ce n'est pas une détection ». Un **fait**, vrai indépendamment de Jeedom |
| `smartclim::appliquerCapacites()` | **Décide** : `!empty($_detecte['catalogue_par_defaut']) && $this->getConfiguration('auxhome_device_id') !== ''` ⇒ `$modesDetectes = array(); $vitessesDetectees = array();` **avant** la fusion |

⚠️ **`concepts` reste unionné sans condition** : un concept est une preuve de présence de clé, pas un
catalogue. **Seuls `modes` et `vitesses`** sont neutralisés.

⚠️ **La colonne est déclarative, jamais un nom de transport en dur** : `appliquerCapacites()` ne teste
**pas** `source === TRANSPORT_AUX_CLOUD_LEGACY`. Un 4ᵉ transport publiant un catalogue le déclarerait de
la même façon, sans toucher l'orchestration. C'est le partage de couches d'UC02 § 3.1 — *le transport
établit des faits, l'orchestration décide* — appliqué au bon endroit cette fois.

**Les sept cas de transition, passés en revue** :

| Cas | Comportement | Verdict |
|---|---|---|
| Legacy pur | `auxhome_device_id` vide ⇒ catalogue admis ⇒ 5 modes + 6 vitesses | ✅ AC1 tenu |
| Legacy pur → mixte | Au **premier** scan mixte, la phase legacy passe **avant** la pose de la clé (ordre LAN → legacy → AUX Home, cf. UC02 § 3.5) : `HEAT` entre, puis la phase AUX Home du **même scan** applique `modes_exclus`, `array_diff` le retire et `masquerCommandesModes()` masque `mode_heat`. Dès le scan suivant, la clé est posée | ✅ transition **en une passe**, aucun aller-retour en régime établi |
| Mixte, scans répétés | Catalogue neutralisé à chaque phase legacy ⇒ profil identique ⇒ `json_encode` identique ⇒ **aucun `save()`** | ✅ |
| Mixte → perte du compte AUX Home | **Vérifié dans le code** : `auxhome_device_id` n'est **jamais** effacé (aucun `setConfiguration(…, '')` n'existe). La clé survit ⇒ le profil garde les modes établis par AUX Home, et le pilotage bascule en legacy **avec les bons modes** | ✅ propriété obtenue **gratuitement** |
| AUX Home cesse d'exclure (`capacites_brutes` tronqué) | Ses `modes` redeviennent le catalogue complet ⇒ `HEAT` revient par l'union | ✅ **auto-guérison préservée** |
| Exclusion initiale erronée, corrigée plus tard | Idem | ✅ **indéfectibilité supprimée** |
| **LAN + legacy sans AUX Home** | Catalogue legacy admis ; le LAN publie vide ⇒ union = catalogue legacy ⇒ les `mode_*` existent et partent en LAN | ⚠️ **résidu assumé, R14** — et c'est aussi un **gain** : un équipement LAN pur n'a aujourd'hui aucun bouton de mode |

**Conséquences à vérifier à la relecture** : aucune clé de configuration nouvelle, aucun script de
migration, **`profilVide()` INCHANGÉE** — `catalogue_par_defaut` n'est **pas** recopiée dans `$fusion`
(construit clé par clé), donc elle n'entre ni dans le profil stocké ni dans la comparaison `json_encode`.
**Aucun `save()` de migration sur le parc existant.**

### 3.2 D2 — `valeurCommandable()` : NON retenu

Un prédicat « au moins un transport déclare un `intent` » avait été proposé pour remplacer le filtre
`versTransport(TRANSPORT_AUX_HOME, …) === null` codé en dur de `definitionsCommandesAction()`.

**Vérification faite** : les **5 modes** et les **8 vitesses** de `tables()` ont **tous** un `intent`
AUX Home non-`null` (`SILENT` = 3, `MEDIUM_LOW` = 6, `MEDIUM_HIGH` = 7). Le prédicat est donc **inerte
aujourd'hui**, et il le reste **après** D1 — le catalogue legacy admis dans un profil ne contient que des
valeurs déjà porteuses d'un `intent` AUX Home.

**Décision : retiré de ce cycle.** Modifier une fonction partagée par les trois transports pour remplacer
un filtre par un autre strictement équivalent sur l'ensemble des valeurs atteignables, c'est de la surface
de maintenance pour zéro bénéfice constaté. Le filtre en dur **reste tel quel**. Consigné en dette (§ 12).

⚠️ **La vraie garde d'autorisation est ailleurs et n'a jamais bougé** : dans l'`appliquerOrdre()` de chaque
brique, qui lève `TYPE_INTERNE` sur une valeur non traduisible **pour son transport**.

### 3.3 D3 — L'état de marche joint à tout ordre (AC2)

La spec fonctionnelle en fait la **règle impérative de ce transport** : *« une commande d'écriture inclut
toujours l'état de marche/arrêt courant, même quand elle ne porte que sur un autre réglage »*.

Elle se combine à l'invariant du socle (« tout ordre de mode ou de consigne porte TOUJOURS `power => 1` »)
sans le contredire : cet invariant couvre les ordres **de mode et de consigne**, où allumer est
l'intention. Il ne couvre **pas** `fan_*` ni les désactivations de confort/oscillation — c'est exactement
le trou que l'AC2 vise, et pour lequel l'état **courant** est nécessaire.

`etatMarcheCourant($_appareil, $_budget)` → `0|1` ou `null`, **quatre sources dans cet ordre** :

| # | Source | Condition |
|---|---|---|
| a | L'ordre lui-même porte déjà `power` | rien à faire |
| b | Mémoire d'ordres `smartclim::ordres::<id>` | dans la période de grâce (`DUREE_GRACE`) |
| c | Commande info `power` | `collectDate` plus récente que `AGE_MAX_POWER_AUXCLOUD` |
| d | **Lecture réseau fraîche** `lireParametres()` | budget disponible (§ 4.2) |
| — | sinon | **`null` ⇒ ordre REFUSÉ** |

⚠️ **Le refus est le comportement voulu, pas un échec à corriger** : `TYPE_INTERNE` +
`CONTEXTE_ETAT_INCONNU`, message dédié. Un ordre non envoyé vaut mieux qu'un appareil éteint — même
doctrine que la réserve d'écriture de `smartclimBroadlinkLan::appliquerOrdre()`.

⚠️ **`AGE_MAX_POWER_AUXCLOUD` s'écrit DÉRIVÉE, jamais en littéral** :
`const AGE_MAX_POWER_AUXCLOUD = self::INTERVALLE_CYCLE_AUXCLOUD + 60;` (= 960 s). Un `power` plus vieux que
le cycle plus un tick n'est plus une observation, c'est une supposition ; les lier est ce qui empêche les
deux de diverger silencieusement le jour où la cadence change.

### 3.4 D4 — Le 3ᵉ cycle cron (arbitrage A)

Cadence **900 s fixes** (`INTERVALLE_CYCLE_AUXCLOUD`), marqueur `smartclim::dernier_cycle_auxcloud`
**séparé** de celui du LAN — les deux dérivent donc et ne coïncident pas systématiquement. Même motif que
le cycle LAN : découplé de `refresh_interval`, car un utilisateur réglé à 1 min transformerait
`plugin::cron` (partagé par **tous** les plugins) en générateur de trafic.

⚠️ **Optimisation non négociable** : `rafraichirAuxCloud()` **groupe les équipements par
`auxcloud_family_id`** et n'émet qu'**un** `dev/query` par famille quand des jetons manquent — **O(familles)**,
jamais O(N). Sans cela le premier cycle à froid ferait 2N requêtes.

**Chiffrage, mesuré dans le code existant** :

| Bloc de `cron()` | Plafond | Requêtes, régime établi |
|---|---|---|
| 1 — `rafraichirAuxHome()` | `BUDGET_SCAN` = 25 s | **O(1)** : 1 HTTPS quel que soit N |
| 2 — `rafraichirLan()` | `BUDGET_LAN` = **18 s**, arrêt dur | O(N) UDP, tronqué |
| 3 — `rafraichirAuxCloud()` | `BUDGET_CYCLE_AUXCLOUD` = **20 s**, arrêt dur | **N** `get` (jetons chauds : TTL 1800 s > cadence 900 s) |
| **Pire cas cumulé** | **63 s** (contre 43 s aujourd'hui) | +N requêtes / 15 min |

⚠️ **Le cycle LAN A un plafond global** (`BUDGET_LAN` = 18 s) — une revue a affirmé le contraire, c'est
faux, et le vérifier évite de sur-dimensionner le budget legacy.

⚠️ **Jamais de `return` dans les blocs 1 et 2** — piège déjà payé sur le cycle LAN : le cycle cloud n'étant
presque jamais échu à un tick donné, un `return` ferait que le bloc suivant ne tournerait **jamais**.
Chaque bloc a sa **propre** garde d'échéance et son **propre** `try/catch (Throwable)`.

⚠️ **Le marqueur d'échéance est posé APRÈS la garde `compteAuxCloudConfigure()` et AVANT tout appel
réseau** — sinon un backend en panne est re-sollicité chaque minute dans un processus partagé. La garde de
compte, elle, ne pose **aucun** marqueur : dès que le compte est renseigné, le tick suivant lance un cycle.

⚠️ **Le cycle est en LECTURE D'ÉTAT SEULE** : aucun `appliquerCapacites()`, aucun `save()` d'équipement.
Le vecteur de migration du parc reste le **scan**. Et il **n'écrit jamais `smartclim::dernier_incident`** :
cette mémoire décrit le cycle **AUX Home**, y écrire depuis ici falsifierait l'état de connexion affiché
d'un *autre* transport — exactement le piège déjà payé sur `postConfig_auxcloud_*`.

### 3.5 D5 — Le sens des oscillations, ajustable sans modifier le code (AC6)

La spec fonctionnelle **exige** un « mécanisme d'ajustement sans modification de code si le sens s'avère
inversé sur un modèle ». Deux moitiés :

1. **Une dimension transport DANS la table**, jamais un second littéral ailleurs — c'est ce que
   prescrivait déjà le docblock de `fonctionsOscillation()` :
   `codesOscillation($_concept, $_transport)` → `{actif, fixe}`, renvoyant la surcharge par transport si
   elle existe, sinon `code_actif`/`code_fixe`. **Additif** : les 3 appelants existants sont **intacts**.
   Surcharge livrée : `AUX_CLOUD_LEGACY => {actif: 1, fixe: 0}` (convention `maeek`).
2. **Une case à cocher par équipement**, `auxcloud_swing_inverse`, qui inverse la convention à l'encodage
   **et** au décodage. C'est elle qui satisfait littéralement l'exigence : si le sens s'avère inversé sur
   un modèle, l'utilisateur coche, sans mise à jour du plugin.

⚠️⚠️ **AC6 est néanmoins livré DÉSACTIVÉ, et le marqueur qui le bloque est GLOBAL** :
`fonctionsOscillation()['confirme']` vaut `false` pour les deux axes et gouverne l'exposition **sur les
trois transports**. Le basculer pour recetter le legacy exposerait **aussi** AUX Home et le LAN, non
mesurés — ce qui est précisément ce que la doctrine « on n'expose que sur mesure » interdit. Le mécanisme
legacy est complet et s'active en éditant **une ligne**, le jour où une recette réelle existe.

⚠️ **Ne pas « corriger » cela en rendant `confirme` dépendant du transport** : cela supposerait que
`definitionsCommandesAction()` connaisse le transport de pilotage — or elle est la **liste blanche
d'autorisation**, et l'indexer sur `transportRetenu()` la rendrait **instable dans le temps** (un
`execCmd()` de scénario passerait ou non selon la joignabilité LAN du moment). Dette nommée (§ 12).

### 3.6 D6 — Aiguillage de transport

| Prédicat | Contenu |
|---|---|
| `cloudLegacyDisponible(smartclim $_eq)` | `smartclim::compteAuxCloudConfigure()` **et** `auxcloud_endpoint_id` non vide — pendant exact de `cloudDisponible()`, deux termes dont un par équipement |
| `lectureLegacyAutorisee(smartclim $_eq)` | `transportRetenu($_eq) === TRANSPORT_AUX_CLOUD_LEGACY` |
| `transportRetenu()`, `MODE_CLOUD` | AUX Home si `cloudDisponible()`, **sinon** legacy si `cloudLegacyDisponible()`, **sinon AUX Home** (repli inchangé ⇒ le message « compte non configuré » est préservé) |
| `transportRetenu()`, `MODE_AUTO` | LAN joignable → AUX Home → **legacy** → LAN |

⚠️ **Non-régression garantie par construction** : sur un parc sans compte legacy,
`cloudLegacyDisponible()` est faux partout, les deux branches ajoutées sont **mortes**, le comportement
est **strictement identique**.

⚠️ **`lectureLegacyAutorisee()` est PLUS STRICTE que `lectureCloudAutorisee()`, délibérément** : le cycle
AUX Home est **une** requête pour tout le parc (exclure un équipement n'économise rien), le cycle legacy
est **O(N)**. Effet voulu : un équipement vu par les deux clouds n'entre **jamais** dans le cycle legacy —
« jamais deux transports pour une même opération » (AC3 de l'UC02 du domaine 02).

⚠️ **`repliCloudActif()` / `delaiTemporisationCloud()` / `memoriserEchecCloud()` NE SONT PAS touchées** :
elles restent adossées à `cloudDisponible()` (AUX Home). La garde de temporisation d'
`executerCommandeAction()` étant déjà conditionnée à `$transport === TRANSPORT_AUX_HOME`, il n'y a **rien à
modifier** — et un équipement legacy n'a donc **pas** de disjoncteur (R9).

### 3.7 D7 — Le cycle d'obtention des jetons d'appairage

Contrat imposé par l'UC02 § 4.1, honoré ici : **l'absence de la 7ᵉ mémoire est le cas NOMINAL** (TTL
1800 s, usage « scan le matin, commande le soir »).

`appareilAuxCloud($_budget)` reconstitue
`{identifiant, type_produit, mac, devicetype_flag, cookie, dev_session, swing_inverse}` :

1. clés `auxcloud_*` de la **configuration** (identité, en clair, stable) ;
2. jetons via `jetonsAuxCloud()` — lecture de `smartclim::appareil_auxcloud::<getId()>`, **chiffrée**, qui
   **valide la forme** et renvoie `array()` plutôt qu'un contenu forgé (patron `incidentMemorise()`) ;
3. **si absents** → `smartclimAuxCloudApi::jetonsAppareil(auxcloud_family_id, …)` puis
   `memoriserJetonsAuxCloud()` ;
4. `auxcloud_family_id` vide → message curaté « relancez un scan ».

⚠️ **Clé par `getId()`, jamais par MAC ni par `endpointId`** — même leçon que `echecs_transport`.
⚠️ **Ne jamais graver un `cookie` en configuration** : `dev/query` le re-livre gratuitement, et c'est un
base64 portant une **clé AES d'appairage**.

### 3.8 D8 — La session, et le « À confirmer » n° 3 de la spec

La spec demandait de statuer sur *« la nécessité de relister l'appareil avant une série de commandes si la
découverte date »*. **Décision : aucun seuil d'âge n'est introduit.** La durée de vie de `devSession`
n'est documentée par aucune source ; un seuil deviné serait précisément le défaut « un fait sans fraîcheur
bornée ne garde rien ».

À la place, **deux rejeux réactifs, indépendants, bornés à un chacun** (booléens locaux, **jamais** de
récursion) :

| Déclencheur | Action | Couche | Garde |
|---|---|---|---|
| `TYPE_AUTH` | `purgerSession()` + `login()` + rejeu | **`smartclimAuxCloudApi`** — tout est disponible sur place | `restant >= BUDGET_REJEU_ORDRE` (10 s) |
| `TYPE_PROTOCOLE` + `CONTEXTE_APPAIRAGE_REFUSE` | purge de la 7ᵉ mémoire + `jetonsAppareil()` + rejeu | **`smartclim`** — cf. l'encadré ci-dessous | idem |

⚠️ **Le `try` n'entoure QUE la requête métier, jamais `session()`** : un `TYPE_AUTH` levé par l'ouverture
de session ne doit jamais déclencher de rafale — c'est l'anti-boucle de l'UC08 du MVP.
⚠️ **Chaque rejeu est conditionné au budget restant EN PLUS du type d'exception**, jamais au seul type.

#### ⚠️⚠️ Correction de cette spec — le rejeu d'appairage ne peut PAS vivre dans le transport

**Première rédaction fautive, corrigée après review.** Le § 5.2 attribuait « les rejeux du § 3.8 » à
`smartclimAuxCloudApi::lireParametres()`, donc **au transport, pour les deux**. C'est impossible pour le
second : re-obtenir les jetons exige `auxcloud_family_id`, une clé de **configuration d'équipement**, et
purger la 7ᵉ mémoire exige `getId()` — **ni l'une ni l'autre n'est disponible dans le transport**, qui ne
connaît pas `eqLogic`. C'est le défaut nommé par `feedback-critere-de-spec-indisponible-dans-sa-couche` :
la couche destinataire y substitue le proxy le plus proche, ou renonce en silence.

**Répartition réelle, à respecter** :

- le rejeu **`TYPE_AUTH`** reste dans le transport (session, `login()` et budget y sont tous accessibles) ;
- le rejeu **d'appairage** vit dans `smartclim`, dans un wrapper privé autour de `lireParametres()`,
  puisque c'est la couche qui porte `appareilAuxCloud()`.

**Qui l'utilise, et qui ne doit surtout pas l'utiliser** :

| Chemin | Rejeu d'appairage | Pourquoi |
|---|---|---|
| `rafraichirAuxCloudEquipement()` (bouton « Rafraîchir ») | **oui** | chemin interactif, un échec répété y est directement visible |
| `rafraichirAuxCloud()` (cycle) | **oui**, par équipement, dans le `try/catch` existant et **sous l'arrêt dur de budget** | sinon l'échec se répète à **chaque tick** |
| `envoyerOrdreAuxCloud()` (écriture) | **oui**, il le porte déjà en propre | — |
| `etatMarcheCourant()` (source d) | ⚠️ **NON — appel direct à `lireParametres()`** | son **appelant** `envoyerOrdreAuxCloud()` porte déjà le rejeu : l'ajouter ici purgerait et re-obtiendrait les jetons **deux fois** pour une seule commande, soit exactement la rafale que ce § interdit |
| `diagnosticAuxCloud()`, `sonderParametreAuxCloud()`, `sonderSpecialAuxCloud()` | ⚠️ **NON, délibérément** | ce sont des **instruments de mesure** : ils rendent le comportement **brut** du backend. Un rejeu automatique masquerait précisément le refus qu'on cherche à observer — même doctrine que « ces méthodes ne rendent jamais la mémoire d'ordres ni le marqueur de déduplication » |

⚠️ **Ce que coûtait l'omission, mesuré en review** : `DUREE_MEMOIRE_APPAREIL_AUXCLOUD` est une TTL
**absolue de 1800 s, jamais raccourcie par un échec**. Sans purge sur le chemin de lecture, un jeton
rejeté par le backend fait donc échouer « Rafraîchir » et le cycle **à l'identique pendant ~30 min** — et
non « jusqu'au cycle suivant ». Un cache dont l'entrée n'est invalidée par aucun échec ne se répare pas
tout seul : seule une purge **sur preuve de refus** le fait.

---

## 4. Server vs Client

**Tout est serveur.** La seule nouveauté côté navigateur est une case à cocher `eqLogicAttr`, liée
nativement par le core : aucun paramètre construit en JS, aucune URL, aucune chaîne d'erreur côté client.
`desktop/js/smartclim.js` n'est pas touché.

L'ordre envoyé est construit **entièrement côté serveur** à partir du `logicalId`, validé contre
`definitionsCommandesAction()` ; `$_options` n'est lu que pour la consigne (`slider`), bornée et
**quantifiée** par `ordreEffectifConsigne()`. C'est ce qui tient l'exigence « aucune valeur non
supportée » **hors** de l'interface — un `execCmd()` de vieux scénario échoue proprement.

### 4.1 Budget — la règle de partage, sans laquelle le pire cas n'est pas borné

⚠️ Sans règle : `login` + `dev/query` + `get` (+2 par rejeu) + `set` (+2 par rejeu) = **jusqu'à 12
allers-retours**, soit **120 s** à `TIMEOUT_REQUETE = 10` s. Inadmissible sur un chemin interactif, et
c'est exactement ce que `CLAUDE.md` nomme : *« une exigence exprimée en temps total ne se tient pas avec
des timeouts par requête »*.

**Règle retenue** : `BUDGET_COMMANDE_AUXCLOUD = 20` s, **porté par `smartclim::envoyerOrdreAuxCloud()`**,
mesuré depuis son entrée et **propagé en secondes restantes** à chaque appel de transport.
`RESERVE_ECRITURE_AUXCLOUD = 6` s.

| Étape | Règle |
|---|---|
| Chaque requête | reçoit `max(3, min(TIMEOUT_REQUETE, restant))` — patron `listerAppareils()` |
| `etatMarcheCourant()` source (d) | ne se déclenche **que** si `restant >= RESERVE_ECRITURE_AUXCLOUD + 3` ; reçoit `restant - RESERVE_ECRITURE_AUXCLOUD` — **l'écriture est servie d'abord** |
| `appliquerOrdre()` | **refuse d'émettre** s'il reste `< RESERVE_ECRITURE_AUXCLOUD` — patron exact de `smartclimBroadlinkLan::appliquerOrdre()` |

**Le pire cas est ainsi borné par 20 s, pas par un nombre de requêtes.** Un budget épuisé produit un refus
**daté et propre**, jamais une requête tronquée.

| Chemin réel | Requêtes | Durée |
|---|---|---|
| `on`/`off`/`mode_*`/`set_target_temp`, session + jetons chauds | **1** (`set`) | ≈ 1 s |
| `fan_*` avec info `power` fraîche (source c) | **1** | ≈ 1 s |
| `fan_*`, `power` inconnu ⇒ source (d) | **2** | ≈ 2 s |
| Démarrage à froid, `fan_*` : session + jetons expirés | **4** | 4-8 s |
| Pire cas avec les deux rejeux | borné | ≤ 20 s |

**Pourquoi 20 s et non 18** (`BUDGET_COMMANDE` d'AUX Home) : le chemin legacy peut enchaîner **4** requêtes
distinctes là où AUX Home en enchaîne 2. 20 s reste dans l'enveloppe d'un `cmd.ajax.php` interactif, et
`executerCommandeAction()` relâche déjà la session (`session_write_close()`) — l'interface n'est pas figée.

---

## 5. Signatures

### 5.1 `smartclimCapabilities`

| Élément | Rôle |
|---|---|
| `FACTEUR_TEMP_AUX_CLOUD_LEGACY = 10` · `PAS_ECRITURE_AUX_CLOUD_LEGACY = 0.5` | Échelle d'écriture. **Deux littéraux suffisent** à corriger si la recette montre un refus des demi-degrés (→ `1.0`) |
| Entrée `tables()[TRANSPORT_AUX_CLOUD_LEGACY]` | `CONCEPT_MODE` : COOL 0 / HEAT 1 / DRY 2 / FAN 3 / AUTO 4 — `'intent'` **et** `'fil'` à la **même** valeur (le legacy lit et écrit la même clé `ac_mode`), `'intent_confirme' => false`, **pas de colonne `libelle`** (les libellés sont déjà portés par l'entrée AUX Home). `CONCEPT_FAN_SPEED` : AUTO 0 / LOW 1 / MEDIUM 2 / HIGH 3 / TURBO 4 / SILENT 5 ; `MEDIUM_LOW` et `MEDIUM_HIGH` → `'intent' => null, 'fil' => null` — **fait de protocole** (aucun code `ac_mark` correspondant), pas une non-confirmation |
| `codesOscillation($_concept, $_transport)` | `{actif, fixe}` ; surcharge par transport, sinon comportement actuel. **Additif** (§ 3.5) |

### 5.2 `smartclimAuxCloudApi`

Constantes : `BUDGET_ETAT = 12`, `RESERVE_ORDRE = 4`, `BUDGET_REJEU_ORDRE = 10`,
`TEMP_MIN_PLAUSIBLE = 50`, `TEMP_MAX_PLAUSIBLE = 500` (dixièmes de °C),
`CONTEXTE_APPAIRAGE_REFUSE = 'appairage_refuse'`, `CONTEXTE_ETAT_INCONNU = 'etat_inconnu'`.

| Méthode | Rôle | Lève |
|---|---|---|
| `private static parametresAuxCloud()` | **Étendue** (l'UC02 R9 interdisait de la figer en liste plate). Colonnes `{cle, nature, lecture_seule?}`, `nature` ∈ `booleen`/`table`/`temperature`/`oscillation`. 13 entrées : `power→pwr`, `mode→ac_mode`, `fan_speed→ac_mark`, `target_temp→temp`, `ambient_temp→envtemp` (**`lecture_seule`**), `display→scrdisp`, `sleep→ac_slp`, `health→ac_health`, `clean→ac_clean`, `mildew→mldprf`, `child_lock→childlock`, `swing_v→ac_vdir`, `swing_h→ac_hdir`. ⚠️ **SEUL endroit du plugin où ces 13 noms existent.** ⚠️ `ecomode`/`comfwind`/`ac_astheat`/`pwrlimit*` **non mappés** : aucun `CONCEPT_*` ne les porte, en créer contournerait la décision D-UC01-Q1 du domaine 04 | — |
| `private static requeteSdkControl($_session, $_appareil, $_act, array $_params, array $_vals, $_temps)` | **Point unique** de la route. Enveloppe de directive, `devicePairedInfo` via `cookieMappe()`, convention `vals` du § 1.2, sentinelle du § 6.2. ⚠️ Sur `ErrorResponse` : `journaliserErreurLegacy('sdkcontrol', $donnees['event']['payload'])` — **le sous-tableau, pas `$donnees`** : la fonction lit `message`/`msg` au **premier niveau**, la passer telle quelle perdrait le message **en silence** | `TYPE_*` |
| `private static recomposerValeurs(array $_reponse)` | Zippe `params[i] ↔ vals[i][0]['val']`. `vals` absent ou mal formé ⇒ **map vide + `log warning`, jamais d'exception** ; index mal formé ⇒ **clé omise**. Nom passé à la **même** regex `/\A[A-Za-z0-9_]{1,40}\z/` | jamais |
| `public static lireParametres(array $_appareil, $_budget = BUDGET_ETAT)` | **UNE** requête `get params: []` → map `nom => valeur`. ⚠️ Porte le **seul** rejeu `TYPE_AUTH` — le rejeu d'appairage vit chez son appelant, dans `smartclim` (§ 3.8, encadré de correction) | `TYPE_*`, message **technique**, exception **recréée** à ce point (la frame de `requete()` porte `loginsession`) |
| `public static appliquerOrdre(array $_appareil, array $_ordre, $_budget)` | Traduit la map **générique** via `parametresAuxCloud()` + `versTransport()` + `echelleTemperature()` + `codesOscillation()`. Refuse un concept `lecture_seule`, absent, ou d'`intent` `null`. **UNE** requête `set`. Renvoie l'**ordre réellement appliqué** (consigne après quantification — c'est lui qui part en état optimiste) | `TYPE_*` |
| `private static decoderParametres(array $_valeurs, $_swingInverse = false)` | Conversions inverses ; **`null` ⇒ clé absente**, jamais de repli. Filtre confort/oscillation/protection par `conceptsConfortLivres()`/`conceptsOscillationRelus()`/`conceptsProtectionLivres()` — **même mécanisme que `smartclimFrame::decoderEtat()`**, pour ne pas le dupliquer là où il divergerait | **jamais** |
| `public static etatAppareil(array $_appareil)` | **Extension additive** : rend `{online?, source}` comme aujourd'hui, **plus** `decoderParametres(...)` si la clé `valeurs` est présente. Signature et clés existantes **inchangées** — l'appel d'UC02 dans `scannerAuxCloud()` continue de fonctionner et **gagne l'état complet au scan** | jamais |
| `public static capacitesAppareil(array $_appareil)` | `modes`/`vitesses` = catalogue + `'catalogue_par_defaut' => true` (§ 3.1) ; `concepts` étendus aux concepts confort/oscillation/protection **filtrés par les `conceptsXxxLivres()`** — donc **rien de neuf n'entre au profil aujourd'hui**, l'entrée étant irréversible ; `modes_exclus` **toujours vide** (aucune preuve disponible) | jamais |
| `public static jetonsAppareil($_familyId, $_endpointId, $_partage, $_budget)` | **UNE** requête `dev/query?action=select` (ou `sharedev/querylist?querytype=shared`) → `{cookie, dev_session, mac, type_produit, devicetype_flag}` | `TYPE_PROTOCOLE` si l'endpoint n'est pas dans la famille |
| `public static sonderParametre(array $_appareil, $_cle, $_valeur, $_budget)` | Instrument CLI : `set` d'**une** clé **brute** validée `/\A[a-z_][a-z0-9_]{0,31}\z/` et d'un entier borné. ⚠️ Forme validée **deux fois** (script + ici) — défense en profondeur, ne supprimer ni l'une ni l'autre | `TYPE_*` |

### 5.3 `smartclim`

Constantes : `CLE_CONF_AUXCLOUD_SWING_INVERSE = 'auxcloud_swing_inverse'`,
`CLE_CACHE_DERNIER_CYCLE_AUXCLOUD = 'smartclim::dernier_cycle_auxcloud'`,
`INTERVALLE_CYCLE_AUXCLOUD = 900`, `BUDGET_CYCLE_AUXCLOUD = 20`, `BUDGET_ETAT_AUXCLOUD = 12`,
`BUDGET_COMMANDE_AUXCLOUD = 20`, `RESERVE_ECRITURE_AUXCLOUD = 6`,
`AGE_MAX_POWER_AUXCLOUD = self::INTERVALLE_CYCLE_AUXCLOUD + 60`.

| Méthode | Rôle | Lève |
|---|---|---|
| `private function appareilAuxCloud($_budget)` | § 3.7 | `smartclimException` **curatée** |
| `private function jetonsAuxCloud()` / `memoriserJetonsAuxCloud(array)` | 7ᵉ mémoire, chiffrée, TTL 1800 s ; la lecture **valide la forme** | — |
| `private function lireParametresAvecRejeuAppairage(array &$_appareil, $_budget)` | Wrapper de `lireParametres()` portant le **rejeu d'appairage** (§ 3.8, encadré) : sur `TYPE_PROTOCOLE` + `CONTEXTE_APPAIRAGE_REFUSE` **uniquement**, purge de la 7ᵉ mémoire + re-obtention des jetons + **un seul** rejeu, borné par booléen local et par le budget. ⚠️ Deux appelants **seulement** : `rafraichirAuxCloudEquipement()` et `rafraichirAuxCloud()` — le tableau du § 3.8 dit qui ne doit **pas** l'utiliser, et pourquoi | comme `lireParametres()` |
| `private function etatMarcheCourant($_appareil, $_budget)` | § 3.3 → `0\|1` ou `null`. ⚠️ Appelle `lireParametres()` **directement**, jamais le wrapper : son appelant porte déjà le rejeu (§ 3.8) | si la lecture de repli échoue |
| `public function envoyerOrdreAuxCloud(array $_ordreGenerique)` | Façade, **jumelle d'`envoyerOrdreLan()`** : fusionne `valeursCommandees()` (grâce), complète `power` via `etatMarcheCourant()`, `appliquerOrdre()`, puis `enregistrerOrdre()` + `appliquerEtat($applique + ['source' => TRANSPORT_AUX_CLOUD_LEGACY], true)`. Message curaté par `messageErreurAuxCloud()`. `catch (Throwable)` en **dernier** bloc | **curatée** |
| `public function envoyerCommandeActionAuxCloud($_logicalId, $_options = array())` | Point d'entrée CLI, jumeau d'`envoyerCommandeActionLan()` : revalide le `logicalId`, refuse `CMD_RAFRAICHIR`, passe par la **même** `ordreDeCommandeAction()` | **curatée** |
| `public function rafraichirAuxCloudEquipement()` | Chemin **interactif** : `lireParametres()` → `appliquerEtat(etatAppareil(...))`. **LÈVE** — un échec silencieux y est interdit | **curatée** |
| `private static rafraichirAuxCloud()` | Cycle du § 3.4. ⚠️ **Ne lève JAMAIS** | — |
| `cycleAuxCloudEchu()` / `marquerCycleAuxCloud()` | Jumeaux **exacts** de `cycleLanEchu()`/`marquerCycleLan()`, marqueur dédié, horloge reculée neutralisée | — |
| `public function diagnosticAuxCloud()` / `sonderParametreAuxCloud($_cle, $_valeur)` | Surfaces de la CLI (patron `diagnosticTransport()` / `sonderIntentAuxHome()` : **une** méthode publique plutôt que l'ouverture de 5 accesseurs privés). ⚠️ Rendent la lecture **BRUTE** — jamais la mémoire d'ordres ni le marqueur de déduplication, sans quoi l'instrument confirmerait ce qu'on vient d'envoyer | **curatée** |
| `executerCommandeAction()` | **+1 branche** `TRANSPORT_AUX_CLOUD_LEGACY`, calquée sur la branche LAN. ⚠️ **La garde D7 d'UC02 est REMPLACÉE, pas supprimée** : inatteignable quand le compte legacy est configuré, son message reste **faux** quand il ne l'est pas → nouveau littéral (§ 7) | idem |
| `rafraichirMaintenant()` | **+1 branche, placée AVANT** le test `mode === MODE_LOCAL` existant | idem |
| `cron()` | **+1 troisième bloc**, § 3.4 | — |
| `preSave()` | +1 barrière **autoritaire et silencieuse** sur `auxcloud_swing_inverse` (`0`/`1`), au même endroit que `transport_mode` | jamais |
| `appliquerCapacites()` | Neutralisation du catalogue, § 3.1. **`profilVide()` inchangée** | — |

---

## 6. `requeteParametres()` — le contrat INTERNE change, et c'est le piège de ce cycle

### 6.1 Le corps JSON émis par la découverte est identique OCTET POUR OCTET

Aujourd'hui : `'payload' => array('act' => 'get', 'params' => array(), 'vals' => array(), 'did' => …)`.

`requeteSdkControl()` calcule
`$vals = ($_act === 'get') ? ((count($_params) === 1) ? array(array(array('val' => 0, 'idx' => 1))) : array()) : $_vals;`

Pour l'appel de découverte, `count(array()) === 0` ⇒ `$vals = array()`. Le `payload` produit est
`{"act":"get","params":[],"vals":[],"did":"…"}` — **même contenu, mêmes clés, même ordre** (l'ordre est
conservé volontairement : parité d'octets, même doctrine que le `JSON_UNESCAPED_SLASHES` de `login()`).
**Risque nul, démontrable en relisant deux lignes.**

### 6.2 La validation partagée est figée à celle d'aujourd'hui, ni plus ni moins

`requeteSdkControl()` valide **exactement** : `event.header.name === 'Response'` → `event.payload.data`
chaîne non vide → `json_decode` rendant un tableau portant `params` **tableau**. Elle rend la réponse
re-parsée brute.

⚠️⚠️ **`vals` n'est PAS exigé à ce niveau, et ne doit pas l'être.** L'exiger **durcirait** la validation de
la découverte déjà livrée : si le backend renvoyait `params` sans `vals`, une découverte qui passe
aujourd'hui échouerait demain, en `TYPE_PROTOCOLE`, sur un chemin que personne ne peut recetter.
L'exigence sur `vals` vit **uniquement** dans `recomposerValeurs()`, appelée par le seul chemin neuf.

### 6.3 ⚠️ Ce qui change : la signature de retour, et son appelant

`requeteParametres()` rend aujourd'hui une **liste de noms** (`string[]`) filtrée par regex, en ignorant
les valeurs. Pour que le scan pose l'état complet **sans émettre une seconde requête**, elle doit rendre
`array{noms: string[], valeurs: array}`.

C'est un changement **local et sûr** — méthode `private`, **un seul appelant** — mais c'est **exactement la
classe de piège du § 6.1.1 d'UC02** (`journaliserErreurLegacy()` : signature changée, appelant à mettre à
jour dans le même geste, **régression invisible à `php -l` et à la CI**).

**L'appelant `executerDecouverte()` est mis à jour dans le MÊME geste** :
`$noms = requeteParametres(...)` devient `$reponseParams = requeteParametres(...)`, puis
`$normalise['parametres'] = $reponseParams['noms']` et `$normalise['valeurs'] = $reponseParams['valeurs']`.

⚠️ **Alternative écartée** : ajouter une méthode sœur en laissant `requeteParametres()` intacte. Rejetée —
la duplication ferait **diverger deux validations**, et le nom `requeteParametres` décrit toujours ce que
fait la fonction.

---

## 7. Validation & classement des erreurs

| Point | Contrôle | Conséquence / message |
|---|---|---|
| Entrée de `rafraichirAuxCloud()` | `compteAuxCloudConfigure()` | `log debug`, **aucune exception**, **aucun marqueur posé** |
| Marqueur d'échéance | **après** la garde de compte, **avant** tout appel réseau | § 3.4 |
| `auxcloud_family_id` vide | `appareilAuxCloud()` | `TYPE_INTERNE` — « Cet équipement n'est pas relié à un appareil du cloud historique — relancez un scan » |
| `jetonsAppareil()` : endpoint absent | — | « Réponse inattendue du cloud historique » (littéral **existant**) |
| **Réponse `sdkcontrol`** | `event.header.name === 'Response'` **ET** `event.payload.data` chaîne re-parsable portant `params` | **AC4** : `TYPE_PROTOCOLE` + `CONTEXTE_APPAIRAGE_REFUSE`. ⚠️ **`ErrorResponse` ne se classe PAS en `TYPE_AUTH`** : cela armerait un re-login inutile |
| Message d'erreur backend | `journaliserErreurLegacy('sdkcontrol', $donnees['event']['payload'])` | ⚠️ le **sous-tableau** — sinon le message est perdu **sans erreur visible** |
| Concept sans correspondance en écriture | absent de `parametresAuxCloud()`, `lecture_seule`, ou `intent` `null` | `TYPE_INTERNE` → « Erreur interne lors de l'envoi de la commande » (littéral **existant**) |
| **`temp` / `envtemp` en lecture** | entier, `TEMP_MIN_PLAUSIBLE <= v <= TEMP_MAX_PLAUSIBLE` | **AC5** : hors bornes ⇒ **clé absente**, la commande n'est pas touchée. ⚠️ C'est cette garde, et elle seule, qui rend « 2,4 » et « 240 » **structurellement inatteignables** — y compris si un firmware renvoyait `temp` déjà en degrés (`24` ⇒ `2,4` ⇒ rejeté) |
| `ac_mode` / `ac_mark` en lecture | `depuisTransport(TRANSPORT_AUX_CLOUD_LEGACY, …)` | `null` ⇒ **clé absente**, jamais un code brut ni un repli |
| État de marche introuvable | `etatMarcheCourant()` → `null` | **AC2** : ordre **refusé**, `TYPE_INTERNE` + `CONTEXTE_ETAT_INCONNU` |
| `--parametre` (CLI) | validée dans le script **et** dans `sonderParametre()` | refus explicite, **aucune requête** |
| Boucle du cycle | `try/catch (Exception)` puis `catch (Throwable)` **par équipement** | boucle **poursuivie** |
| Budget | § 4.1 | refus daté, jamais une requête tronquée |
| Frontière technique → curaté | `messageErreurAuxCloud()` (**existante, inchangée**) | jamais un message technique dans le DOM |

### 7.1 Secrets

`cookie` et `dev_session` ne sont **jamais** journalisés, **jamais** rendus, **jamais** dans un message
d'exception ni dans une sortie CLI. `lireParametres()` / `appliquerOrdre()` **recréent** leur exception au
point d'appel : la frame de `requete()` porte `loginsession`, celles de `requeteSdkControl()` portent le
**cookie mappé**. Aucun `CURLOPT_VERBOSE`. **TLS vérifié en dur**, aucune échappatoire ajoutée.

---

## 8. La 5ᵉ CLI — `core/php/commande-auxcloud.php`

Même moule que les quatre existantes : garde `php_sapi_name() === 'cli'` **avant tout `require_once`**,
aucun POST, aucune écriture en base ni sur disque, sorties **FR sans `__()`**.

| Usage | Effet |
|---|---|
| `--lister` | `logicalId` des commandes d'action, **sans rien émettre** sur le réseau |
| `--etat` | lecture **brute** des paramètres (`diagnosticAuxCloud()`) |
| `--commande=<logicalId> [--valeur=<consigne>]` | passe par `envoyerCommandeActionAuxCloud()` |
| `--parametre=<clé> --valeur=<n>` | sonde d'une clé brute (`sonderParametreAuxCloud()`) |
| `--special` | émet le second `get` (`params: ["mode"]`) — le **seul** moyen de trancher R2 factuellement |

⚠️ **C'est un AIGUILLAGE, sans aucune logique métier** : il ne construit **jamais** de map de concepts à la
main. Réimplémenter la construction d'ordre ici ferait diverger la surface de commandes legacy de celle du
cloud et du LAN — c'est le passage par `ordreDeCommandeAction()` qui garantit l'inverse.

⚠️ **À lancer sous `www-data`** (`sudo -u www-data php …`), comme les quatre autres.

---

## 9. Couverture des critères d'acceptation

| AC | Statut | Vérifiable par |
|---|---|---|
| **AC1** — chaque mode commandable | couvert | **lecture** (3 sources concordantes sur `ac_mode`) + recette |
| **AC2** — un réglage n'éteint jamais | couvert | **lecture** (§ 3.3, refus explicite) |
| **AC3** — la lecture reflète le changement | couvert | lecture + recette |
| **AC4** — échec applicatif ≠ succès | couvert | **entièrement par lecture** (§ 7) |
| **AC5** — °C justes | couvert | **entièrement par lecture** (garde de plausibilité) |
| **AC6** — sens des oscillations | **mécanisme livré, exposition DÉSACTIVÉE** (§ 3.5) | recette requise — dette § 12 |
| **AC7** — chaque commande testée en recette | **non couvert** — aucun compte AC Freedom | recette communautaire via la CLI § 8 |
| **AC8** — un scénario portable | couvert **par construction** | lecture : `logicalId` génériques inchangés, `ordreDeCommandeAction()` partagée, aiguillage **en aval** de la construction de l'ordre |

⚠️ **Nuance sur AC8, à ne pas escamoter** : il est tenu pour les scénarios qui **commandent**. Un scénario
**déclenché par** un changement de commande info dépend du cycle de lecture — c'est précisément ce que
l'arbitrage A (§ 3.0) rend possible.

**Points à valider en recette, par ordre de suspicion** :

1. la casse `devicetypeFlag` vs `devicetypeflag` (§ 1.6) — **premier suspect** si tout `sdkcontrol` rend
   `ErrorResponse` ;
2. le sens réel des oscillations (§ 1.5) ;
3. l'existence de `envtemp` dans le premier `get`, et donc l'utilité du second (R2) ;
4. l'acceptation d'un `temp` **impair** (`245`) — sinon `PAS_ECRITURE_AUX_CLOUD_LEGACY → 1.0` ;
5. le comportement d'un `set` refusé.

---

## 10. Risques

- **R1 — Livré non recetté.** Aucun compte legacy. Vérifiable par lecture : les numérotations, le format
  du jeton d'appairage, la convention `vals`, la sentinelle AC4, l'absence de secret en log, les budgets,
  la non-régression de `transportRetenu()`.
- **R2 — Le second `get` n'est PAS émis sur le chemin périodique.** La référence le conditionne au
  `productId` (contraire à la doctrine anti-whitelist du `brief.md` § 10), son rôle n'est établi par
  aucune source (§ 1.6, écart 2), et il **doublerait** le trafic d'un cycle O(N). Accessible seulement par
  `--special`. **Conséquence assumée** : sur les modèles concernés, `ambient_temp` reste **absente** —
  donc la commande n'est pas touchée, jamais une valeur fausse. ⚠️ Si la recette prouve qu'il livre
  `envtemp`, l'ajouter **conditionné à l'absence de `envtemp`** — un critère **positif et disponible dans
  la couche** —, **jamais** au `productId`.
- **R3 — `MODE_CLOUD` ne distingue pas les deux clouds.** AUX Home prioritaire (seul recetté, seul porteur
  d'un `online` fiable et de `modes_exclus`). Un 4ᵉ mode toucherait l'IHM, `smartclimTransport::modes()` et
  la spec du domaine 02, pour un cas de parc rare. Candidat `/change`.
- **R5 — Fenêtre de course sur `pwr`.** Rien ne verrouille côté cloud : une extinction faite à la
  télécommande entre notre lecture et notre `set` sera écrasée. La chaîne de sources d'AC2 **borne** le
  risque, elle ne l'élimine pas. Aucun protocole ne le permet.
- **R7 — Le cycle legacy est en O(N) requêtes**, contre O(1) pour AUX Home. D'où la cadence fixe de 900 s,
  le filtre strict `lectureLegacyAutorisee()`, le groupement par famille et le budget de 20 s avec arrêt
  dur. Un parc legacy important verra ses derniers équipements rafraîchis au cycle suivant.
- **R9 — Aucun disjoncteur pour le legacy** (§ 3.6). Un legacy en panne est re-sollicité à chaque cycle et
  à chaque commande, sans backoff. Cohérent avec la dette existante D-MVP08-05 ; l'étendre supposerait un
  8ᵉ compteur de cache.
- **R13 — Fenêtre de transition d'un équipement legacy dont le LAN a réellement fonctionné puis est
  tombé.** Une fois `STATUT_ETAT_LU` constaté, `lanJoignable()` renvoie **vrai** tant que
  `0 < echecs < SEUIL_ECHECS_LAN` (3) — comportement voulu, pour ne pas basculer au premier accroc.
  Pendant cette série, `transportRetenu()` rend LAN : l'équipement **sort du cycle legacy** et n'entre pas
  dans le cycle AUX Home (`equipementsParIdentifiant()` n'indexe que `auxhome_device_id`). **Son état
  n'est donc rafraîchi par aucun cycle.**
  **Borne** : au plus 3 échecs, incrémentés par le cycle LAN (900 s) **et** par chaque commande échouée ⇒
  au pire ≈ **45 min**, moins dès que l'utilisateur agit. Aucune commande n'est perdue (elles partent en
  LAN et échouent **visiblement**, ce qui **accélère** la bascule), aucune donnée n'est faussée.
  **Non corrigé délibérément** : élargir `lectureLegacyAutorisee()` ferait lire en legacy des équipements
  pilotés par le LAN ou AUX Home — O(N) requêtes inutiles et violation de « jamais deux transports pour
  une même opération ». Le coût de la correction dépasse celui du défaut.
  ⚠️ **Le cas voisin cité par `CLAUDE.md` — « une `lan_ip` saisie à la main sur un climatiseur non
  Broadlink » — N'EST PAS un angle mort** : `memoriserEchecLan()` refuse d'incrémenter tant qu'aucune
  preuve n'existe (`preuve === 0`), donc `echecs = 0`, donc `lanJoignable()` renvoie **false** et
  l'équipement **est** dans le cycle legacy. Vérifié étape par étape.
- **R14 — LAN + legacy sans AUX Home : le catalogue entre au profil et personne ne peut l'amputer**
  (§ 3.1, cas 7). Une unité froid-seul y affichera un bouton « Mode Chauffage » inopérant.
  **Borne** : un bouton de trop, masquable à la main ; **aucun état faussé** (l'ordre part, l'appareil
  l'ignore). Même borne que le garde-fou de création LAN d'UC04 du domaine 01. Se referme dès qu'un compte
  AUX Home est configuré. **Contrepartie** : c'est le même mécanisme qui donne enfin des boutons de mode à
  un équipement LAN pur, qui n'en a **aucun** aujourd'hui.
- **R15 — Vérifications mécaniques.** Docblocks denses portant des fragments de protocole, des regex et
  des extraits JSON. ⚠️ Lancer `python .claude/scripts/verif-plugin.py` **avant commit** (colonne `meta=`) :
  `php` n'est pas installé sur la machine de dev et la CI ne se déclenche pas sur push `master`.

---

## 11. Documentation à amender en fin de cycle

- **`CLAUDE.md`** : la 7ᵉ mémoire a enfin un lecteur ; `cron()` porte **trois** cycles ; `tables()` porte
  une entrée legacy ; le pilotage legacy est livré ; 5ᵉ CLI ; `smartclimAuxCloudApi` porte lecture et
  écriture.
- **`.memory/analyse/smartclim-transport-aux-cloud-legacy.md`** §§ 1, 3, 4 : les **trois écarts** du § 1.6,
  et le « À confirmer » n° 1 **fermé**.
- **`.memory/specs/post-mvp/03-cloud-aux-legacy/02-decouverte-legacy-tech.md`** : § 3.2 **révisé** (le
  3ᵉ cycle cron est ajouté, arbitrage du 2026-09-09 rouvert et tranché — cf. § 3.0 A) ; § 3.3 (les
  `modes`/`vitesses` ne sont plus vides, et **comment** l'union est protégée) ; § 4.1 (contrat honoré).
- **`.memory/analyse/INDEX.md`** : ligne + déclencheurs § 0 + date.

---

## 12. Dette (hors périmètre de ce cycle)

| # | Dette | Pourquoi elle n'est pas traitée ici |
|---|---|---|
| **D-PM0303-01** | **AC6 non recetté, exposition des oscillations désactivée** — `fonctionsOscillation()['confirme']` est un marqueur **global aux 3 transports** | Le basculer exposerait AUX Home et le LAN, non mesurés. Le rendre dépendant du transport rendrait la liste blanche d'autorisation **instable dans le temps** (§ 3.5) |
| **D-PM0303-02** | **AC7 non recetté** — aucune commande vérifiée sur du matériel | Aucun compte AC Freedom. Instrumenté par la CLI § 8, recette communautaire |
| **D-PM0303-03** | Le filtre de `definitionsCommandesAction()` porte un **nom de transport en dur** (`TRANSPORT_AUX_HOME`) | Inerte aujourd'hui (§ 3.2) ; deviendrait faux le jour où une valeur générique existerait sans `intent` AUX Home |
| **D-PM0303-04** | **Aucun disjoncteur legacy** (R9) | Supposerait un 8ᵉ compteur de cache ; cohérent avec D-MVP08-05 |
| **D-PM0303-05** | **R14** — catalogue non amputable sur un équipement LAN + legacy | Aucun transport de ce couple ne sait exclure ; borne de conséquence faible (un bouton de trop) |
