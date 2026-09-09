# Spec technique — UC02 du domaine post-mvp/03 : familles, appareils et capacités legacy

> **Spec fonctionnelle** : `02-decouverte-legacy.md` (même dossier) · **Dépend de** : UC01 de ce domaine
> (livrée) · **Statut** : plan validé le 2026-09-09, arbitrages utilisateur ci-dessous.

## 0. Périmètre et non-objectifs

Étendre `smartclimAuxCloudApi` — livrée à l'UC01 avec sa seule authentification — de la **découverte** du
parc : familles → appareils propres **et** partagés → jeu de paramètres réellement annoncé par chaque
appareil → profil de capacités générique ; puis création/rapprochement des équipements Jeedom par
`smartclim::scannerAuxCloud()`, composée dans le scan existant.

**Non-objectifs, nommés** :

- **Aucune lecture de valeur d'état, aucun pilotage** — c'est l'UC03. La seule valeur consommée est le
  `state` en ligne/hors ligne d'AC5. Le second `get` (`params: ["mode"]`, `AC_SPECIAL_PARAMS` des
  références) n'est **pas** émis : c'est de la lecture d'état, et il coûterait N requêtes.
- **Aucun 3ᵉ cycle cron** — arbitré avec l'utilisateur le 2026-09-09 (cf. § 3.2).
- **Aucun catalogue de modes/vitesses legacy** — arbitré le 2026-09-09 (cf. § 3.1).
- **`smartclimTransport` n'est pas touchée** — l'arbitrage à trois transports est le domaine 02 (cf. § 3.4).
- Aucun démon, aucune dépendance nouvelle : ce transport reste 100 % PHP (`curl` + `openssl`).

⚠️ **Livré non recetté**, comme le transport Broadlink LAN et comme l'UC01 : aucun compte legacy n'est
disponible. Le code est vérifié contre deux références MIT recoupées, **jamais** contre le backend réel.
Le § 9 dit, critère par critère, ce qui est vérifiable par lecture et ce qui attend une recette.

## 1. Contrats externes

**Sources relues verbatim** (les mêmes que l'UC01, aux mêmes commits) :

- `maeek/ha-aux-cloud` (MIT) — `custom_components/aux_cloud/api/aux_cloud.py` : `_get_headers` l.122-135,
  `_make_request` l.137-173, `get_families` l.230-257, `get_rooms` l.259-279, `get_devices` l.281-423,
  `_get_directive_header` l.425-436, `bulk_query_device_state` l.474-511, `_act_device_params` l.513-631 ;
  `const.py` : `AuxProducts` l.125-243.
- `GijsZwegers/com.zwegersit.auxairco` (MIT) — `lib/auxcloud/legacyClient.ts` : `baseHeaders` l.61-75,
  `directiveHeader` l.77-86, `listFamilies` l.150-160, `fetchDevicesForFamily` l.162-189,
  `actOnDeviceParams` l.191-260, `queryOnlineStates` l.262-289, `listDevices` l.291-332.

Les deux sont **concordantes** sauf aux trois écarts du § 1.4.

### 1.1 ⚠️⚠️ Le piège de copier-coller n° 1 de cette UC

**Seule la route `/account/login` a un corps chiffré et les en-têtes `timestamp`/`token`.** Toutes les
routes de découverte envoient un **corps JSON en clair** (ou aucun corps) et **n'émettent ni `timestamp`
ni `token`** — `_get_headers()` appelée sans argument ne les contient pas (`aux_cloud.py:122`,
`legacyClient.ts:61`).

Recopier l'enveloppe de `login()` ici ferait échouer **toutes** les requêtes de découverte. C'est le
symétrique exact du piège `status == 0` (et non `code == 200`) documenté au § 1.3 de la spec technique
d'UC01 : une brique de transport se recopie mal parce que ses invariants ne sont pas uniformes d'une route
à l'autre.

### 1.2 Les six routes

Toutes en `POST`, sur l'hôte de la région résolu par `hoteRegion()` (UC01).

| Route | Corps | En-têtes en plus de la base | Réponse exploitée |
|---|---|---|---|
| `/appsync/group/member/getfamilylist` | **aucun** | — | `status == 0`, `data.familyList[].familyid` |
| `/appsync/group/room/query` | aucun | `familyid` | **non appelée**, cf. Écart 1 |
| `/appsync/group/dev/query?action=select` | `{"pids":[]}` | `familyid` | `status == 0`, `data.endpoints[]` |
| `/appsync/group/sharedev/querylist?querytype=shared` | `{"endpointId":""}` | `familyid` | `status == 0`, `data.shareFromOther[].devinfo` |
| `/device/control/v2/querystate` | directive `DNA.QueryState` / `queryState`, `payload.studata = [{did, devSession}, …]`, `payload.msgtype = "batch"` | — (**pas** de `familyid`) | `event.payload.status == 0`, puis `event.payload.data[]` `{did, state}` — `state == 1` = en ligne ; repli `event.payload.studata[]` (Écart 3) |
| `/device/control/v2/sdkcontrol?license=<LEGACY_LICENSE>` | directive `DNA.KeyValueControl` / `KeyValueControl`, `endpoint.devicePairedInfo{did,pid,mac,devicetypeflag,cookie}`, `endpoint.endpointId`, `endpoint.cookie = {}`, `endpoint.devSession`, `payload{act:"get", params:[], vals:[], did}` | — | `event.header.name === "Response"` (sinon `"ErrorResponse"`), puis `event.payload.data` = **chaîne JSON à re-parser** → `{params:[…], vals:[[{val}], …]}` parallèles |

**En-tête de directive** (`_get_directive_header`, `directiveHeader`) :
`{namespace, name, interfaceVersion: "2", senderId: "sdk", messageId: "<prefixe>-<epoch s>"}` + extras.
Le préfixe de `messageId` est **`userid`** pour `querystate`, **`endpointId`** pour `sdkcontrol`.

⚠️ **Correction apportée à l'implémentation (2026-09-09)** : la faute de frappe `timstamp` est présente
dans les **DEUX** directives (`querystate` **et** `sdkcontrol`), et non dans `querystate` seule comme
l'écrivait initialement ce paragraphe — constat direct sur les deux sources de référence.

⚠️ `querystate` ajoute `messageType: "controlgw.batch"` et **`timstamp`** — faute de frappe **présente
dans les deux références** (`aux_cloud.py:452`, `legacyClient.ts:275`) : **à reproduire verbatim**. Aucune
source ne dit si le backend la lit ; la corriger serait une invention.

**Base d'en-têtes post-login** : `licenseId`, `lid`, `language: en`, `appVersion`, `User-Agent`, `system`,
`appPlatform`, `loginsession`, `userid` — toutes **déjà** présentes dans `smartclimAuxCloudApi::requete()`.

### 1.3 Champs d'appareil et types de produits

`endpointId`, `friendlyName`, `productId`, `devSession`, `devicetypeFlag`, `cookie`, `mac`
(⚠️ **typé optionnel** dans `legacyClient.ts:37-45`). Le `cookie` est un base64 d'un JSON
`{terminalid, aeskey, …}` — **secret d'appairage** (cf. § 7.3).

Types de produits (`const.py:127-132`) : climatiseur `000000000000000000000000c0620000` et
`0000000000000000000000002a4e0000` ; **pompe à chaleur** `000000000000000000000000c3aa0000`.

### 1.4 Trois écarts entre les références, et leur arbitrage

**Écart 1 — les pièces ne sont PAS un niveau de parcours.** La spec fonctionnelle décrit
« familles → pièces de chaque famille → appareils ». Le contrat réel est **familles → appareils** :
`dev/query` est indexé par `familyid`, jamais par `roomid` ; `room/query` ne rend qu'un libellé de
regroupement, et **aucune** des deux références ne s'en sert pour trouver un appareil.
→ `room/query` **n'est pas appelée** : une requête par famille économisée, aucun AC impacté (aucun critère
d'acceptation ne mentionne les pièces). À amender dans la spec fonctionnelle (§ 11).

**Écart 2 — `Content-Type` post-login.** `maeek` conserve `application/x-java-serialized-object` ;
`GijsZwegers` envoie `application/json`. → **On garde `application/x-java-serialized-object`** : c'est la
valeur de la source réellement en production sur ces routes (intégration Home Assistant), et c'est déjà ce
qu'émet `requete()` — donc zéro modification. Repli documenté si le backend refuse (§ 10, R2).

**Écart 3 — clé de la liste d'états.** `maeek` lit `payload["data"]` ; `GijsZwegers` lit
`payload.data ?? payload.studata`. → **lire `data`, repli `studata`** : coût nul, la forme n'est pas prouvée.

### 1.5 ⚠️ `LEGACY_LICENSE` est embarquée DÈS cette UC — correction du § 1.5 d'UC01

Le § 1.5 de `01-client-legacy-authentification-tech.md` écrit « ne pas l'embarquer, elle relève de
l'UC03 » : **c'est faux**, et la spec d'UC01 est à amender (§ 11). La détection de capacités d'AC2 passe
par `sdkcontrol`, qui exige `?license=`.

Constante base64 (~172 caractères) recopiée **verbatim** depuis `legacyConstants.ts`, avec le **même bloc
d'attribution MIT** que les autres constantes de protocole déjà embarquées à l'UC01.

### 1.6 Ce qui reste non prouvé

Aucune source ne documente : le comportement du `get` `params: []` sur un `productId` inconnu (cf. § 3.1) ;
l'existence d'un code de `status` signifiant « session expirée » ; la forme réelle de `mac` (séparateurs ou
non) ; l'acceptation d'un `studata` **inter-familles** — plausible puisque cette route n'envoie pas de
`familyid`, mais **aucune référence ne le fait**, donc **non tenté**.

## 2. Architecture — fichiers

| Chemin | Action | Contenu | Indentation / EOL |
|---|---|---|---|
| `core/class/smartclimAuxCloudApi.class.php` | modifié | Toute la découverte : `LEGACY_LICENSE`, `BUDGET_DECOUVERTE`, `listerAppareils()`, les 4 requêtes privées, la normalisation, `parametresAuxCloud()`, `capacitesAppareil()`, `etatAppareil()`, la 3ᵉ frontière d'assainissement ; extension de `requete()` et de `journaliserErreurLegacy()` | **2 espaces**, CRLF |
| `core/class/smartclim.class.php` | modifié | `scannerAuxCloud()`, `resumeScanAuxCloud()`, `appliquerDecouverteAuxCloud()`, `memoriserAppareilAuxCloud()`, 7 constantes, +1 index dans `indexerEquipements()`, +1 paramètre / +1 étape dans `chercherEquipementExistant()`, +1 paramètre / +1 colonne dans `lignesFusionScan()`, composition dans `scannerClimatiseurs()`, 1 garde dans `executerCommandeAction()` | 2 espaces, CRLF |
| `core/class/smartclimCapabilities.class.php` | modifié | `const TRANSPORT_AUX_CLOUD_LEGACY = 'AUX_CLOUD_LEGACY';` + branche dans `libelleTransport()`. ⚠️ **Aucune entrée dans `tables()`** — c'est l'UC03 | 2 espaces, CRLF |
| `desktop/php/smartclim.php` | modifié | 1 colonne `{{Disponible dans le cloud historique}}` dans `#table_scanClimatiseurs`, 1 bloc `#div_scanAuxCloudWrapper` + `#table_scanAuxCloud` (mêmes colonnes que `#table_scanTrouves`) | ⚠️ **tabulations**, CRLF |
| `desktop/js/smartclim.js` | modifié | Rendu des 2 tables, `resultat.legacyErreur` en `warning`, `legacyCrees` pour le bouton de rechargement, `timeout` porté de 60000 à 80000 | 2 espaces, CRLF |

**Sans objet, explicitement** — pour éviter qu'un implémenteur les cherche :

- `core/php/smartclim.inc.php` : **aucune classe nouvelle**. Tout vit dans `smartclimAuxCloudApi`, déjà en
  `require_once`. L'autoload n'est pas un sujet de cette UC.
- `core/ajax/smartclim.ajax.php` : l'action `scannerClimatiseurs` est **inchangée**, `session_write_close()`
  déjà en place.
- `plugin_info/configuration.txt` / `.php` : **aucune clé de config plugin nouvelle** — donc **aucune
  synchronisation du miroir à faire**. `core/config/smartclim.config.ini` idem.
- `packages.json` / `info.json` / `.htaccess` : aucun point d'entrée web nouveau, aucune dépendance.
- `smartclimFrame` : **aucune trame HVAC dans ce transport** — le legacy expose des paramètres JSON
  nommés, pas des octets. Aucun offset à ajouter.
- `smartclimTransport` (§ 3.4), `smartclimBroadlinkLan`, `smartclimDiagnostic`, `smartclimDemon`, aucune
  CLI, `docs/`.

## 3. Décisions — les cinq questions ouvertes de la spec, et deux de plus

### 3.1 D1 — Le `get` à liste de paramètres vide sur un `productId` inconnu

Les deux références appellent `sdkcontrol get params: []` **sans consulter le `productId`**
(`aux_cloud.py:355`, `legacyClient.ts:311`) ; seul le **second** `get` (`params: ["mode"]`) y est
conditionné. Le client n'a donc **rien** à connaître du produit — c'est un fait, pas une hypothèse. Ce qui
reste non prouvé, c'est la **réponse du backend**. Repli, sans rien deviner :

- **réponse exploitable** → profil dérivé des **clés réellement renvoyées**. C'est une inclusion **sur
  preuve positive** (présence de clé), pas une inclusion devinée : plus solide que la détection AUX Home ;
- **réponse en échec / `ErrorResponse` / jeu vide** → **aucun socle n'est inventé**. Sur un appareil **déjà
  rapproché**, on pose seulement les clés `auxcloud_*` et `online` ; sur un appareil **inconnu**, aucune
  création, ligne « Ignoré — appareil non reconnu comme climatiseur » et `log warning`. Le scan suivant
  réessaie.

⚠️⚠️ **Où cette décision est prise — correction du 2026-09-09, et la leçon vaut au-delà de cette UC.**
Les deux paragraphes ci-dessus opposent « appareil **déjà rapproché** » et « appareil **inconnu** ». C'est
le bon critère, mais il décrit un **état côté Jeedom** que la brique de transport ne peut structurellement
pas connaître : le rapprochement est établi par `chercherEquipementExistant()`, dans
`smartclim::scannerAuxCloud()`, **après** que `listerAppareils()` a rendu la main.

La première implémentation y a substitué le seul critère disponible dans le transport —
« le `productId` est-il au catalogue ? » — en citant cette phrase pour se justifier. Conséquence : un
appareil **jamais vu par Jeedom**, dont le `productId` était au catalogue mais dont `sdkcontrol` échouait,
était créé avec un profil de concepts **vide**, donc **sans aucune commande** — strictement pire que le cas
qu'AC3 vise à protéger.

**Le partage retenu, qui est aussi le bon découpage de couches** :

| Couche | Décide |
|---|---|
| `smartclimAuxCloudApi` (transport) | des **faits** : `preuve_climatiseur` (bool, `estClimatiseur()` satisfaite ou non) et `motif_exclusion` (`''`, `'pompe_a_chaleur'`, `'budget_epuise'`) — des exclusions **inconditionnelles**, indépendantes de Jeedom. Elle ne filtre **plus rien** : elle renvoie **tous** les appareils rencontrés |
| `smartclim::scannerAuxCloud()` (orchestration) | de la **création** : elle relaie d'abord les motifs transport, puis appelle `chercherEquipementExistant()` et n'exige `preuve_climatiseur` **que si `$eqLogic` est nul**. Un appareil déjà rapproché pose ses clés `auxcloud_*` et `online` **même sans preuve** — c'est ce que dit ce § 3.1 |

⚠️ Le motif `'non_climatiseur'` n'est donc **jamais** posé par le transport : c'est une **décision**
d'orchestration, pas un fait de protocole. Et `produitsClimatiseurConnus()` ne gouverne plus la création —
son unique rôle restant est la ligne `log info` d'AC4.

⚠️ **Leçon réutilisable** : un critère écrit dans une spec doit être **disponible dans la couche à qui elle
le confie**. Sinon l'implémentation y substituera le proxy le plus proche — et le commentaire du code
citera la spec, ce qui rend la substitution invisible en relecture.

C'est le même garde-fou que la création LAN conditionnée à `STATUT_ETAT_LU` (UC04 du domaine 01), et il
traite au passage un risque que la spec fonctionnelle ne nomme pas : **`dev/query` renvoie TOUS les
endpoints d'une famille**, or le cloud Broadlink héberge aussi des prises, des télécommandes IR et des
capteurs — sans preuve, on créerait des « climatiseurs » pour eux (§ 10, R4).

AC3 reste satisfait pour le cas qu'il vise — un `productId` inconnu **qui répond**.

### 3.2 D3 — Statut en ligne groupé, et pas de cycle cron

Un `querystate` **par famille**, `studata` portant tous les appareils de la famille : conforme aux deux
références, **O(familles)** et jamais O(appareils) — c'est ce qu'exige AC5 (« quel que soit le nombre
d'appareils »). L'appel unique inter-familles est plausible mais non étayé : non tenté (§ 1.6).

Le résultat atterrit dans la **commande info `online` existante**, poussée par
`appliquerEtat(['online' => …, 'source' => …])` — exactement le mécanisme de `basculerHorsLigne()`.

⚠️ **Aucun 3ᵉ cycle n'est ajouté à `cron()`** — *arbitré avec l'utilisateur le 2026-09-09*. Un cycle
périodique serait de la **lecture d'état** (UC03), et son arbitrage face aux autres transports serait le
domaine 02. Précédent exact : le LAN n'est entré dans `cron()` qu'à l'UC01 du domaine 02, **un domaine
après** sa découverte.
**Conséquence assumée** : entre deux scans, `online` d'un équipement legacy n'est pas rafraîchi. AC5 dit
« après une découverte » — il est tenu.
**Non-régression vérifiée** : `equipementsParIdentifiant()` n'indexe que `auxhome_device_id`, donc un
équipement legacy pur n'est **jamais** basculé hors ligne par le cycle AUX Home auquel il n'appartient pas.

### 3.3 D5 — Capacités par appareil sans amputer un profil

*Arbitré avec l'utilisateur le 2026-09-09 : report à l'UC03, contre une publication spéculative.*

- **Concepts** : union sûre — chaque concept est une **preuve de présence de clé**.
- **`modes` et `vitesses` : publiés VIDES**, exactement comme `smartclimBroadlinkLan::capacitesAppareil()`,
  et pour la **raison identique** : le jeu de paramètres dit que `ac_mode` **existe**, jamais **quelles
  valeurs** l'appareil accepte — le legacy n'a aucun équivalent de `feature.coolType`, donc il ne peut
  **rien exclure**. Publier le catalogue ferait réintroduire `HEAT`, par l'union de `appliquerCapacites()`,
  sur une unité froid-seul dont AUX Home vient de prouver qu'elle ne chauffe pas : c'est exactement la
  régression du 2026-08-26, et elle serait **irréversible** (`modes_exclus` du legacy resterait vide).
- **`modes_exclus` : toujours vide** — aucune preuve disponible ; un tableau vide laisse l'union pure.
- Aucun concept de confort / oscillation / protection : leurs `confirme => false` les bloquent de toute
  façon, et les activer sans mesure violerait la règle d'entrée **irréversible** au profil.

**Coût explicite, accepté** : un climatiseur découvert **uniquement** en legacy n'aura ni `mode_*` ni
`fan_*`. Limite d'AC3, levée à l'UC03 quand la table `TRANSPORT_AUX_CLOUD_LEGACY` de
`smartclimCapabilities::tables()` existera.

### 3.4 D4 — `smartclimTransport` n'est pas touchée

`transportRetenu()` reste binaire LAN/AUX_HOME et `cloudDisponible()` reste adossée à
`auxhome_device_id` : ajouter un 3ᵉ terme à l'arbitrage **est** le domaine 02, et le faire ici — sans
pilotage legacy, qui est l'UC03 — produirait un routage vers un transport **incapable d'exécuter**.

Conséquence directe : un équipement legacy pur est routé vers le LAN. D'où § 3.6.

### 3.5 D6 — Ordre de composition : LAN → legacy → AUX Home

`array_replace` fait gagner le **dernier**, et `profilAffichable()['source']` / `CMD_TRANSPORT` en
dérivent. Mettre le legacy en dernier ferait afficher « AUX Cloud (AC Freedom) » comme transport actif d'un
équipement piloté par AUX Home. En l'**intercalant**, l'affichage existant est strictement inchangé.

### 3.6 D7 — Message honnête sur une commande d'un équipement legacy pur

`appliquerEtat()` appelle `creerCommandesAction()` : dès qu'`online` est poussé, les boutons `on` / `off` /
`set_target_temp` apparaissent. Sans garde, un clic remonterait « Adresse réseau locale inconnue […]
lancez un scan ou renseignez l'adresse IP » — message **affirmatif, faux et inactionnable** pour un
appareil qui ne parle pas Broadlink, exactement ce que l'arbitrage du 2026-09-08 (§ 7.1 d'UC01) a proscrit.

Garde de quelques lignes dans `executerCommandeAction()`, **juste après `transportRetenu()`** et avant
`ordreDeCommandeAction()` : transport retenu = LAN, aucune adresse LAN, pas de `cloudDisponible()`, mais
`auxcloud_endpoint_id` non vide → message dédié. C'est le **seul point de cette UC hors découverte pure**,
et il est assumé pour cette raison.

## 4. Persistance — D2, et ce que l'UC03 devra en faire

Séparation par **nature de la donnée**, pas par commodité :

| Donnée | Où | Pourquoi |
|---|---|---|
| `auxcloud_endpoint_id`, `auxcloud_product_id`, `auxcloud_devicetype_flag`, `auxcloud_family_id`, `auxcloud_partage` | **configuration** d'équipement, en clair | identité stable, non sensible ; `family_id` évite un `getfamilylist` de plus à l'UC03 |
| `cookie` (base64 de `{terminalid, aeskey}`) **et** `devSession` | **cache chiffré** `smartclim::appareil_auxcloud::<getId()>`, TTL 1800 s | `cookie` porte une **clé AES d'appairage** (secret d'équipement) et `devSession` est un **jeton périssable** — les deux périment ensemble, un seul chemin de rafraîchissement à l'UC03 |

⚠️ **Alternative écartée** : `encrypt()` du `cookie` en configuration d'équipement. Elle grave un secret en
base pour une valeur que `dev/query` re-livre gratuitement, et laisse `devSession` sans domicile cohérent.

⚠️ **7ᵉ mémoire de cache du plugin** — à ajouter à la liste de `CLAUDE.md` avec son rôle distinct des six
autres. Les `postConfig_auxcloud_*` ne la purgent **pas** : il faudrait itérer sur tout le parc, la TTL de
30 min suffit, et elle ne contient aucun secret **de compte**.

### 4.1 ⚠️ Contrat imposé à l'UC03 — la mémoire sera le plus souvent EXPIRÉE

Cette mémoire est **écrite sans lecteur** dans cette UC. Le rapprochement tenté avec
`smartclimAuxCloudApi::session()` (livrée sans appelant à l'UC01) **ne tient pas**, et la différence est
dirimante : une session cloud **se régénère seule** par `login()`, alors que `cookie` et `devSession` ne
s'obtiennent que par un **`dev/query` complet**.

Or l'usage réel est « scan le matin, commande le soir » : à 30 minutes de TTL, la mémoire aura expiré
**presque à chaque fois**. Elle ne fait donc gagner qu'une réutilisation de courte fenêtre (enchaînement
scan → commande), jamais une réutilisation durable.

**Contrat pour l'UC03, à écrire dès maintenant pour ne pas le redécouvrir** : le chemin de pilotage legacy
doit traiter l'absence de cette mémoire comme le **cas nominal**, et savoir re-obtenir `cookie` /
`devSession` par un `dev/query` ciblé sur `auxcloud_family_id` — c'est précisément pourquoi cette clé est
persistée en configuration. Un pilotage qui *supposerait* la mémoire présente échouerait la plupart du
temps.

## 5. Server vs Client

**Tout côté serveur.** Le navigateur n'envoie **aucun** paramètre : l'action AJAX `scannerClimatiseurs` est
inchangée et sans entrée. Le JS ne fait que **rendre** des lignes déjà construites, libellées et traduites
côté PHP — aucune chaîne d'erreur, aucun libellé de statut, aucune décision n'est construite en JS.

C'est la même doctrine que les scans existants, et c'est ce qui garantit qu'aucune entrée utilisateur
n'atteint `CURLOPT_URL` (§ 7.1).

## 6. Signatures

### 6.1 `smartclimAuxCloudApi`

Constantes : `BUDGET_DECOUVERTE = 25` (parité `smartclimAuxHomeApi::BUDGET_SCAN`), `LEGACY_LICENSE`,
`PRODUIT_INCONNU_MAX = 64`.

| Méthode | Rôle | Lève |
|---|---|---|
| `public static listerAppareils($_budget = BUDGET_DECOUVERTE)` | Orchestration : `session()` → familles → (propres + partagés) par famille → `querystate` par famille → `get` par appareil. **Budget GLOBAL** mesuré depuis l'entrée, arrêt **dur** évalué avant chaque requête et avant chaque appareil ; chaque requête reçoit `max(3, min(TIMEOUT_REQUETE, restant))`. **Re-login réactif borné à UN rejeu**, uniquement sur `TYPE_AUTH` et si `restant >= BUDGET_LOGIN + 3` (patron de `smartclimAuxHomeApi::listerAppareils()` : booléen local, **jamais** de récursion ; le `try` n'entoure que la requête métier, jamais `session()`). Renvoie des lignes **normalisées à clés génériques françaises**. `catch` final qui **recrée** l'exception à ce point d'appel — la frame de `requete()` porte `loginsession` | `TYPE_*`, message **technique** |
| `private static requeteFamilles(array $_session, $_temps)` | `getfamilylist`, aucun corps → liste de `familyid` **validés par `valeurEnteteConforme()`** : ils repartent en **en-tête HTTP** | `TYPE_*` |
| `private static requeteAppareilsFamille(array $_session, $_familyId, $_partages, $_temps)` | Une des deux routes selon `$_partages` ; extrait `data.endpoints` ou `data.shareFromOther[].devinfo` | `TYPE_*` |
| `private static requeteEtatsGroupe(array $_session, array $_bruts, $_temps)` | `querystate` pour une famille → `did => state`. ⚠️ **Ne lève JAMAIS** : tout échec = `log warning` + tableau vide → `online` restera **absent** de l'état, donc la commande ne sera pas touchée (invariant « une clé absente ne touche pas sa commande ») | — |
| `private static requeteParametres(array $_session, array $_brut, $_temps)` | `sdkcontrol` `act: "get"`, `params: []` → **NOMS** des paramètres renvoyés. ⚠️ **Les VALEURS sont ignorées** (périmètre UC02). Rejette `event.header.name !== 'Response'` | `TYPE_*` |
| `private static enveloppeDirective($_namespace, $_nom, $_prefixeMessage, array $_extra)` | Gabarit d'en-tête de directive (5 clés + extras) — **un seul endroit** pour `interfaceVersion` / `senderId` / `messageId` | — |
| `private static normaliserAppareilLegacy($_brut, $_familyId, $_partage)` | Ligne normalisée, ou `null`. Clés : `mac`, `identifiant`, `nom`, `type_produit`, `type_produit_connu`, `devicetype_flag`, `famille`, `partage`, `enLigne`, `parametres` (**noms** seuls), `cookie`, `dev_session`. ⚠️ **Aucun nom de champ propriétaire ne sort** ; `cookie` / `dev_session` ont une **destination exclusive** (la mémoire chiffrée), même statut que `capacites_brutes` chez AUX Home | — |
| `private static parametresAuxCloud()` | **Seul endroit du plugin** où vivent `pwr` / `ac_mode` / `temp` / `envtemp` / `ac_mark` → `CONCEPT_*`. Pendant exact d'`intentionsAuxHome()`. ⚠️ Ne pas la figer en simple liste plate : elle grandira à l'UC03 (§ 10, R9) | — |
| `private static produitsPompeAChaleur()` | `productId` de pompes à chaleur documentés — **exclusion sur preuve positive**, jamais une inclusion | — |
| `private static estClimatiseur(array $_noms)` | **Preuve** : `pwr` présent **et** (`temp` **ou** `ac_mode`). ⚠️ `pwr` nu discrimine climatiseur de PAC : `HP_PARAMS` porte `ac_pwr` / `ac_temp`, jamais `pwr` / `temp` | — |
| `public static capacitesAppareil(array $_appareil)` | `{concepts, modes: [], vitesses: [], modes_exclus: [], temperature: bornesParDefaut(), source: TRANSPORT_AUX_CLOUD_LEGACY}`. **Ne lève jamais** | — |
| `public static etatAppareil(array $_appareil)` | `{online, source}` **UNIQUEMENT** — aucune valeur de paramètre. **Ne lève jamais** ; `online` **absent** si l'état groupé n'a pas couvert ce `did` | — |
| `private static nettoyerTexteExterne($_valeur, $_max)` | **3ᵉ frontière d'assainissement du plugin**, **strictement symétrique** de `smartclimAuxHomeApi::nettoyerTexteExterne()` et `smartclimBroadlinkLan::nettoyerNomExterne()` : caractères de contrôle → garde UTF-8 → retrait `<` et `>` → `trim` → troncature UTF-8-safe. ⚠️ La symétrie est un **invariant** : un même appareil vu par deux transports doit porter le même nom | — |
| `private static jetonAppareilConforme($_valeur, $_max)` | Forme de `cookie` / `devSession` avant mémorisation. **Jamais journalisés, jamais rendus** | — |
| `private static classerStatutLegacy($_contexte, $_donnees, $_typeParDefaut)` | `status != 0` sur route authentifiée → `journaliserErreurLegacy()` puis lève, **`TYPE_AUTH` par défaut** (hypothèse « session expirée » — c'est ce qui **arme le rejeu unique**) | `TYPE_*` |
| `private static journaliserErreurLegacy($_contexte, $_donnees)` | ⚠️ **Voir § 6.1.1 — ce n'est PAS une extension additive.** Les 5 étapes de neutralisation restent **inchangées et dans l'ordre** | — |
| `private static requete(…, $_session = null, array $_options = array())` | Extension **additive** (un seul appelant aujourd'hui, `login()`) : `$_options{familyid, query, corps_json}` ; `timestamp` / `token` émis **seulement si non vides** — même traitement conditionnel que celui **déjà appliqué à `loginsession` / `userid`**, alors qu'ils sont aujourd'hui émis inconditionnellement ; `query` construite depuis des **littéraux serveur** et `LEGACY_LICENSE` ; `familyid` passé par `valeurEnteteConforme()` avant concaténation. Classement d'erreurs (étapes 1-5) **inchangé et partagé** | `TYPE_RESEAU`, `TYPE_PROTOCOLE` |

#### 6.1.1 ⚠️ `journaliserErreurLegacy()` — l'appel existant DOIT être mis à jour

Signature actuelle : `journaliserErreurLegacy($_donnees)`, **un seul appelant**, dans `login()`.

Insérer `$_contexte` en **premier** paramètre **change l'ordre des arguments**. Si l'appel de `login()`
n'est pas mis à jour dans le même geste, `$donnees` (un tableau) atterrit dans `$_contexte` : le log
d'erreur de login est silencieusement cassé — **invisible à `php -l`, invisible à la CI**, exactement la
classe de régression que ce projet paie cher.

→ **L'implémentation met à jour cet appel.** Alternative acceptable si elle est jugée plus sûre : ajouter
`$_contexte` en **dernier** paramètre avec une valeur par défaut, ce qui rend l'extension réellement
additive. Le choix est laissé à l'implémentation, mais **l'un des deux doit être fait explicitement**.

### 6.2 `smartclim`

Constantes : `CLE_CONF_AUXCLOUD_ENDPOINT_ID = 'auxcloud_endpoint_id'`, `..._PRODUCT_ID`,
`..._DEVICETYPE_FLAG`, `..._FAMILY_ID`, `..._PARTAGE` ;
`CLE_CACHE_APPAREIL_AUXCLOUD = 'smartclim::appareil_auxcloud::'`,
`DUREE_MEMOIRE_APPAREIL_AUXCLOUD = 1800`.

| Méthode | Rôle | Lève |
|---|---|---|
| `public static scannerAuxCloud()` | Jumeau de `scannerAuxHome()` : verrou `CLE_CACHE_VERROU_SCAN` réutilisé, `indexerEquipements()`, boucle avec **`try/catch` par appareil** (`Exception` puis `Throwable`), rapprochement / création / mise à jour **conditionnée**, `appliquerCapacites()`, `memoriserMacEquipement()`, `memoriserAppareilAuxCloud()`, `appliquerEtat(etatAppareil())`. ⚠️ **Garde SILENCIEUSE** : `!compteAuxCloudConfigure()` → résultat vide + `log debug`, **jamais** d'exception — divergence assumée avec `scannerAuxHome()`, parce qu'un compte legacy non configuré est le cas **nominal** d'un utilisateur AUX Home. ⚠️ **Aucun calcul de « disparus »** : `appareilsDisparus()` est indexée sur `auxhome_device_id` et reste **intacte** | `smartclimException` **curatée** |
| `private static resumeScanAuxCloud(array $_compteurs)` | Phrase française ; réutilise les fragments existants `%d créé(s)`, `%d déjà connu(s)`, `%d ignoré(s)`, `%d en erreur` | — |
| `private static appliquerDecouverteAuxCloud(smartclim $_eq, array $_appareil)` | Pose les 5 clés `auxcloud_*` **en comparant avant d'écrire** ; renvoie `bool` modifié — **jamais** de `save()` ici | — |
| `private static memoriserAppareilAuxCloud(smartclim $_eq, array $_appareil)` | Cache **chiffré** `smartclim::appareil_auxcloud::<getId()>` = `utils::encrypt(json_encode({cookie, dev_session, cree_le}))`, TTL 1800 s. ⚠️ **Clé par `getId()`, jamais par MAC ni par `endpointId`** — même leçon que `CLE_CACHE_ECHECS_TRANSPORT` | — |
| `chercherEquipementExistant($_mac, $_deviceId, $_index, $_transport = '', $_endpointAuxCloud = '')` | **+1 paramètre en fin de signature** — ⚠️ **2 appelants réels** (et non 4 : vérifié, `smartclim.class.php` l.699 et l.885), tous deux intacts. **+1 étape 8** `parEndpointAuxCloud`, **gardée par `$_transport === TRANSPORT_AUX_CLOUD_LEGACY`** — même doctrine que la garde `lan_mac` (« ne rapproche que pour son transport »). ⚠️ Le scan legacy passe `$_deviceId = ''` : l'étape 7 (`auxhome_device_id`) reste réservée à AUX Home, **sans la modifier** | — |
| `indexerEquipements()` | **+1 index** `parEndpointAuxCloud`, **filtré « non vide »** (même piège que `parLanMac`), +1 log `debug` de collision | — |
| `lignesFusionScan($_lan, $_cloud, $_etats, array $_auxCloud = array())` | **+1 paramètre** et **+1 clé** `cloudHistorique` (via `libelleDisponibilite()` — **zéro chaîne nouvelle**) ; `equipementId > 0` alimente aussi la liste des `$ids` | jamais |
| `scannerClimatiseurs()` | Composition **LAN → legacy → AUX Home** (§ 3.5). Capture séparée dans `'legacyErreur'` | — |
| `executerCommandeAction($_logicalId, $_options)` | **+1 garde** juste après `transportRetenu()` (§ 3.6) | `smartclimException` |

### 6.3 `smartclimCapabilities`

`const TRANSPORT_AUX_CLOUD_LEGACY = 'AUX_CLOUD_LEGACY';` et, dans `libelleTransport()`,
`return 'AUX Cloud (AC Freedom)';` — **nom de marque, sans `__()`**, doctrine identique à `AUX Home` et
`Broadlink LAN`.

⚠️ **Aucune entrée dans `tables()`** : la numérotation legacy des modes et vitesses est l'UC03 (§ 10, R9).

## 7. Validation & classement des erreurs

**Côté client : rien.** Le navigateur n'envoie aucun paramètre ; aucune chaîne d'erreur n'est construite
en JS.

**Côté serveur, dans l'ordre** :

| Point | Contrôle | Conséquence |
|---|---|---|
| Entrée de `scannerAuxCloud()` | `compteAuxCloudConfigure()` | résultat vide + `log debug`, **aucune exception** |
| Verrou | `CLE_CACHE_VERROU_SCAN` | `TYPE_INTERNE` « Un scan est déjà en cours » (littéral **existant**) |
| Réseau / TLS / HTTP / JSON | classement de `requete()` (étapes 1-4 **inchangées**, étape 5 **relâchée** — cf. § 7.4) | `TYPE_RESEAU` (+ `CONTEXTE_CERTIFICAT` / `CONTEXTE_MAGASIN_LOCAL`), `TYPE_PROTOCOLE` |
| `status != 0` sur route authentifiée | `classerStatutLegacy()` | `TYPE_AUTH` → **arme le rejeu unique** ; au 2ᵉ échec, remonte |
| `event.header.name !== 'Response'` | `requeteParametres()` | `TYPE_PROTOCOLE`, appareil ignoré, boucle poursuivie |
| `event.payload.data` non re-parsable | `requeteParametres()` | idem |
| `familyid` avant en-tête HTTP | `valeurEnteteConforme()` (ancre **`\z`**, jamais `$`) | famille ignorée + `log warning` — **donnée backend dans un en-tête** |
| `mac` | `preg_replace('/[^0-9a-f]/', '', strtolower())`, longueur 12 stricte | vide → `logicalId = auxcloud:<endpointId>` (repli documenté dans `CLAUDE.md`) |
| `friendlyName`, `endpointId`, `productId` | `nettoyerTexteExterne()` **avant** tout log, tout DOM, toute construction de `logicalId` | valeur assainie ou vide |
| `cookie` / `devSession` | `jetonAppareilConforme()` ; le `cookie` est en plus contrôlé décodable (`base64_decode` strict → `json_decode` → présence de `terminalid` et `aeskey`) | `log warning` **sans aucun contenu**, mémorisation du brut quand même |
| `productId` dans `produitsPompeAChaleur()` | avant tout `sdkcontrol` | ligne « Ignoré — pompe à chaleur (hors périmètre du plugin) », **aucune requête** |
| Preuve de climatiseur | `estClimatiseur()` | pas de création si absente (§ 3.1) |
| Budget | arrêt dur avant chaque requête et chaque appareil | ligne « Ignoré — budget de temps épuisé », scan suivant réessaie |
| Boucle par appareil | `catch (Exception)` puis `catch (Throwable)`, message neutralisé | compteur `erreurs`, **boucle poursuivie** (AC3) |
| Frontière technique → curaté | `messageErreurAuxCloud()` (existante, **inchangée**) dans `scannerAuxCloud()` | jamais de `status` brut dans le DOM |

### 7.1 Aucune entrée utilisateur n'atteint `CURLOPT_URL`

La `query` (`?action=select`, `?querytype=shared`, `?license=<LEGACY_LICENSE>`) est construite depuis des
**littéraux serveur** et une constante embarquée. Le `familyid`, seule valeur d'origine backend qui repart
dans un en-tête HTTP, passe `valeurEnteteConforme()` — ancre **`\z`** et non `$`, qui accepterait un saut
de ligne final et donc une injection d'en-tête.

### 7.2 Secrets

`cookie` et `devSession` ne sont **jamais** journalisés, **jamais** renvoyés dans une ligne de résultat
AJAX, **jamais** dans un message d'exception. `listerAppareils()` **recrée** son exception au point
d'appel, parce que la frame de `requete()` porte `loginsession`. Aucun `CURLOPT_VERBOSE`. `endpointId`
tronqué en `debug`.

### 7.3 ⚠️ Le `productId` d'AC4 ne doit traverser AUCUNE neutralisation

Il fait exactement 32 caractères hexadécimaux. Passé par `journaliserErreurLegacy()`, il serait remplacé
par `[b64]` **dès l'étape 3** — le charset hexadécimal est un sous-ensemble de base64. **AC4 deviendrait
invérifiable sans aucune erreur visible.**

→ Le log d'AC4 est une ligne `info` **séparée**, construite après `nettoyerTexteExterne()` **seul**.

### 7.4 ⚠️ L'étape 5 de `requete()` est RELÂCHÉE — correction du § 7 initial

Ce document écrivait « classement d'erreurs (étapes 1-5) **inchangé et partagé** ». **C'était faux**, et
l'implémentation l'a corrigé après relecture directe des sources de référence.

L'étape 5 héritée de l'UC01 exigeait un champ `status` **au premier niveau** de l'enveloppe JSON. Or seules
`account/login`, `getfamilylist`, `dev/query` et `sharedev/querylist` le portent là : `querystate` le porte
sous `event.payload.status`, et `sdkcontrol` n'en a **aucun** — il s'identifie par `event.header.name`.
Garder l'exigence aurait fait échouer ces deux routes en `TYPE_PROTOCOLE` **à chaque appel**, et donc rendu
la détection de capacités (AC2) et l'état groupé (AC5) inopérants — **silencieusement**, puisqu'un échec de
protocole est un cas d'erreur légitime.

`requete()` ne valide donc plus que la **présence d'une enveloppe JSON**. La sentinelle de statut redescend
à **chaque appelant**, et c'est là l'invariant à préserver :

| Route | Qui valide, et quoi |
|---|---|
| `account/login` | `login()` — `status == 0`, **strictement inchangé** (piège n° 1 du transport, § 1.3 d'UC01) |
| `getfamilylist`, `dev/query`, `sharedev/querylist` | `classerStatutLegacy()` — `status == 0` |
| `querystate` | `requeteEtatsGroupe()` — `event.payload.status`, et **ne lève jamais** (§ 6.1) |
| `sdkcontrol` | `requeteParametres()` — `event.header.name === 'Response'` |

⚠️ **Aucune route ne doit rester sans validation** : `requete()` ne protège plus personne à ce niveau. Une
7ᵉ route ajoutée un jour sans sa sentinelle transformerait une erreur backend en **succès silencieux**.
C'est la même doctrine qu'AUX Home, où le classement des codes métier appartient à `classerCodeMetier()` et
jamais à la fonction de requête.

## 8. Dépendances

**Aucune.** `curl` et `openssl` (déjà requis par l'UC01 et par AUX Home) suffisent. Pas de démon, pas de
paquet pip, `packages.json` inchangé.

## 9. Couverture des critères d'acceptation

| AC | Réalisé par | Vérifiable par lecture | À valider en recette |
|---|---|---|---|
| **AC1** | `getfamilylist` puis, par famille, `dev/query` **et** `sharedev/querylist` | le parcours des deux routes, la fusion, l'absence de filtrage préalable | que les deux routes répondent, et que les partagés arrivent bien sous `shareFromOther[].devinfo` |
| **AC2** | concepts dérivés de la **présence des clés** du `get params: []` | qu'aucun catalogue figé n'est consulté ; que deux appareils aux jeux de clés différents donnent deux profils différents | la forme réelle de la réponse `sdkcontrol` |
| **AC3** | aucun filtrage par `productId` ; `try/catch` par appareil | que la boucle n'est jamais interrompue ; que les commandes de base sont créées | le comportement du `get` sur un `productId` inconnu (§ 1.6) |
| **AC4** | ligne `info` **séparée**, hors neutralisation (§ 7.3) | oui, entièrement | — |
| **AC5** | un `querystate` **par famille** — O(familles) | que le nombre de requêtes ne dépend pas du nombre d'appareils | la forme de la réponse (`data` vs `studata`, Écart 3) |
| **AC6** | `chercherEquipementExistant()` réutilisée + `memoriserMacEquipement()` | oui, entièrement (chemin identique aux deux autres transports) | la forme réelle de `mac` (§ 1.6) |
| **AC7** | `$consommes`, écriture conditionnée, `appliquerCapacites()` qui ne renvoie `true` que sur divergence | oui, entièrement | — |

⚠️ **AC3 est partiellement couvert, et c'est un arbitrage utilisateur du 2026-09-09** : un climatiseur
découvert **uniquement** en legacy reçoit ses commandes info (`power`, `mode`, `target_temp`…) et ses
actions `on` / `off` / `set_target_temp`, mais **aucun bouton de mode ni de vitesse** — conséquence directe
de § 3.3. Le complément arrive à l'UC03.

## 10. Risques

- **R1 — Livré non recetté.** Vérifiable par lecture : autoload, symétrie d'assainissement, absence de
  secret en log, idempotence du rapprochement, budget global, classement d'erreurs. À valider en recette :
  chacune des six routes, le corps en clair post-login, la forme de `mac` / `cookie` / `devSession`, le
  `get` sur `productId` inconnu, le code de `status` d'une session expirée, la faute de frappe `timstamp`.
- **R2 — Le corps en clair post-login est le point de rupture le plus probable.** Si le backend attend en
  réalité un corps chiffré ou les en-têtes `timestamp` / `token` sur ces routes, **toute** la découverte
  échoue d'un bloc — mais **proprement**, en `TYPE_AUTH` / `TYPE_PROTOCOLE` avec le `status` journalisé.
  Les deux références concordent : c'est le meilleur étai disponible.
- **R3 — Le `productId` d'AC4 et la neutralisation** : cf. § 7.3. Piège silencieux.
- **R4 — `dev/query` ne renvoie pas que des climatiseurs.** Prises, télécommandes IR et capteurs du même
  compte Broadlink y figurent. Garde-fou : `estClimatiseur()` (§ 3.1), dont la robustesse dépend d'une
  hypothèse **non mesurée** (`pwr` absent des appareils non-AC). Un faux positif crée un équipement de
  trop, **supprimable** — même borne de conséquence que le garde-fou LAN de l'UC04 du domaine 01.
- **R5 — Durée du scan.** 18 s (LAN) + 25 s (AUX Home) + 25 s (legacy) ≈ 68 s ; le `timeout` jQuery passe
  de 60000 à 80000 ms. ⚠️ Un `max_execution_time` PHP-FPM inférieur couperait le handler **côté serveur** —
  à vérifier avant de conclure à une panne de transport.
- **R6 — Un `save()` par scan sur un équipement multi-transport.** `appliquerCapacites()` compare
  `json_encode` du profil, `source` **incluse** : sur un équipement vu par LAN + legacy + AUX Home,
  `source` oscille et un `save()` est émis à chaque phase. Comportement **préexistant** (LAN puis cloud),
  aggravé d'un cran. Sans effet sur un scan manuel ; deviendrait un vrai sujet si le legacy entrait dans un
  cycle cron.
- **R7 — Incohérence d'affichage sur un équipement legacy pur.** La commande info `transport` affichera
  « AUX Cloud (AC Freedom) » alors que `transportRetenu()` renvoie LAN. Cosmétique, disparaît quand le
  domaine 02 intégrera le 3ᵉ transport.
- **R8 — Warning « Compte AUX Home non configuré » pour un utilisateur 100 % legacy.** Préexistant, mais
  qui devient gênant maintenant qu'un compte legacy seul est un cas nominal. **Non corrigé ici** → § 12.
- **R9 — Extension future contrainte.** `parametresAuxCloud()` devra grandir à l'UC03 (oscillations,
  confort, `err_flag` / `ac_errcode1` — **seul canal de code d'erreur d'appareil de tout l'écosystème**) et
  la table `TRANSPORT_AUX_CLOUD_LEGACY` de `smartclimCapabilities::tables()` devra naître avec sa
  **numérotation propre** (`ac_mode` : 0 COOL, 1 HEAT, 2 DRY, 3 FAN, 4 AUTO — **différente** d'AUX Home et
  du LAN). Ne pas les créer aujourd'hui ; ne pas figer `parametresAuxCloud()` en simple liste plate.
- **R10 — Vérifications mécaniques.** Docblocks denses portant des regex commentées et des fragments de
  protocole : lancer `python .claude/scripts/verif-plugin.py` **avant commit** (colonne `meta=`) — `php`
  n'est pas installé sur la machine de dev et la CI ne se déclenche pas sur push `master`.

## 11. Documentation à amender en fin de cycle

- **`CLAUDE.md`** : 7ᵉ mémoire de cache (§ 4) ; découverte legacy livrée (la ligne
  `smartclimAuxCloudApi` dit encore « Aucune découverte d'appareil (UC02) ») ; 3ᵉ frontière
  d'assainissement ; clés de configuration `auxcloud_*` par équipement.
- **`01-client-legacy-authentification-tech.md` § 1.5** : `LEGACY_LICENSE` est désormais embarquée
  (§ 1.5 ci-dessus) — l'affirmation « elle relève de l'UC03 » est fausse.
- **Docblock de tête de `smartclimAuxCloudApi`** : il dit encore que la découverte est hors périmètre de
  cette classe.
- **`.memory/analyse/smartclim-transport-aux-cloud-legacy.md`** §§ 3, 7, 8 : corps en clair post-login,
  pièces non parcourues, points « À confirmer » restants.
- **`02-decouverte-legacy.md`** (spec fonctionnelle) : Écart 1 — les pièces ne sont pas un niveau de
  parcours.
- **`.memory/analyse/INDEX.md`** : ligne + déclencheurs + date.

## 12. Dette (hors périmètre de ce cycle)

- **D-PM0302-01** — R8 : le warning « Compte AUX Home non configuré » remonté à un utilisateur qui n'a
  qu'un compte legacy. Le message reste utile à qui n'a rien configuré ; le traiter proprement suppose de
  savoir quels transports l'utilisateur a réellement voulu activer.
- **D-PM0302-02** — R6 : le `save()` par scan sur un équipement multi-transport, dû à `source` incluse dans
  la comparaison de profil. À traiter si le legacy entre un jour dans un cycle cron.
- **D-PM0302-03** — R7 : la commande info `transport` d'un équipement legacy pur affiche un transport que
  `transportRetenu()` ne retient pas. Se referme au domaine 02.
