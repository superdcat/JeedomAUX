# Spec technique — UC03 post-MVP/01 « Envoi de commandes en LAN »

> **Spec fonctionnelle** : `.memory/specs/post-mvp/01-transport-broadlink-lan/03-ecriture-etat-lan.md`
> (amendée le 2026-09-03 : AC4 recentré, deux arbitrages journalisés en « Hors périmètre »)
> **Dépend de** : UC02 de ce domaine (`02-lecture-etat-lan-tech.md`) — dont le § 3.2 renvoyait
> explicitement ici la **génération** de la somme de contrôle de la charge HVAC, et le § 5.4 la colonne
> `intent` du transport LAN.
> **Date du plan** : 2026-09-03 · **Statut de recette** : ⚠️ **non recettable** (l'appareil de validation
> ignore le protocole Broadlink — cf. § 11).

---

## 0. Ce que fait cette UC, en une phrase

Ajouter au transport Broadlink LAN l'**écriture d'état** : construire une charge HVAC de 23 octets **par
recopie de la trame que l'appareil vient de renvoyer**, n'y patcher que les bits des concepts visés,
l'encapsuler (préfixe de longueur + somme de contrôle) et l'émettre en `0x6A` via la `requete()` figée
d'UC01.

**Le piège central, qui commande toute l'architecture** : l'écriture Broadlink porte un **état complet**,
jamais un delta — un champ absent vaut 0, donc l'appareil s'éteint tout seul. C'est ce qui impose la
fusion, et c'est ce qu'AC1, AC2, AC3 et AC6 vérifient chacun sous un angle différent.

**Ce que cette UC ne fait pas** : elle n'arbitre **jamais** entre LAN et cloud. Elle livre la
**capacité** d'écrire, exercée par une **commande en ligne** dédiée ; `executerCommandeAction()` reste
cloud (cf. § 8.1).

---

## 1. Architecture

### 1.1 Fichiers

| Fichier | État | Contenu | Indentation |
|---|---|---|---|
| `core/class/smartclimFrame.class.php` | modifié | `encoderOrdre()`, `champsEcriture()`, `enteteEcriture()`, `conceptsEncodables()`, constantes d'encodage ; `require_once` de `smartclimException` ; docblock de classe amendé | 2 espaces, CRLF |
| `core/class/smartclimBroadlinkLan.class.php` | modifié | `appliquerOrdre()`, `sommeChargeHvac()`, `encapsulerChargeHvac()`, constante `RESERVE_ECRITURE` | 2 espaces, CRLF |
| `core/class/smartclimCapabilities.class.php` | modifié | colonne `intent` de la table `BROADLINK_LAN` remplie, branche `BROADLINK_LAN` dans `echelleTemperature()` | 2 espaces, CRLF |
| `core/class/smartclim.class.php` | modifié | `envoyerCommandeActionLan()`, `envoyerOrdreLan()`, `valeursCommandees()`, `sonderAppareilLan()`, `messageErreurLan()`, `ordreDeCommandeAction()` (extraction), constantes de budget | 2 espaces, CRLF |
| `core/php/commande-lan.php` | **créé** | Déclencheur **CLI** unique du chemin d'écriture LAN | 2 espaces, CRLF |

**Non touchés — résultat vérifié, pas oubli** :

- ⚠️ **`core/php/smartclim.inc.php` — aucune ligne à ajouter, et c'est la SEULE raison** : cette UC ne
  crée **aucune classe**. Si la revue en faisait naître une (elle ne doit pas), la ligne `require_once`
  redeviendrait **obligatoire** — l'oubli ne casse ni `php -l`, ni la CI, ni `verif-plugin.py`.
- `core/ajax/smartclim.ajax.php`, `desktop/php/smartclim.php`, `desktop/js/smartclim.js` — **inchangés**.
  Aucune surface web n'est ajoutée.
- `cron()`, `rafraichirMaintenant()`, `scannerReseauLocal()`, `scannerAuxHome()` — **strictement
  inchangés**. `cron()` reste cloud pur et n'émet **aucun** ordre.
- `core/config/smartclim.config.ini`, `plugin_info/configuration.txt`/`.php`, `plugin_info/info.json` —
  **aucune clé de configuration**, ni plugin ni équipement : donc aucun piège `preConfig_`, aucun défaut
  INI à dupliquer, aucun miroir à resynchroniser. En particulier **aucune clé `lan_enabled`** — le
  domaine `post-mvp/02` est propriétaire du choix de transport.
- `plugin_info/packages.json` — reste **vide**.

---

## 2. Contrat externe — la charge HVAC d'écriture

Transport (`0x38`, AES-128-CBC, compteur, codes d'erreur `0x22`, rejeu) : **entièrement livré en
UC01/UC02, rien n'y change**. Ce qui suit ne concerne que la charge.

### 2.1 Sources, par ordre d'autorité

Toutes consultées le 2026-09-02.

1. **`fparrav/homebridge-aux-cloud`** (**MIT**), `src/api/broadlink/Protocol.ts` —
   `buildCommandPayload`, `commandPayloadChecksum`. Lu ; **aucune ligne recopiée**.
2. `fparrav/.../AuxDeviceControl.ts` (MIT) — stratégie de fusion état + ordre.
3. `mjg59/python-broadlink` (MIT) — en-tête, codes d'erreur. Déjà cité en tête du fichier de transport.
4. `.memory/analyse/smartclim-transport-broadlink-lan.md` § 5.4/5.5 ; spec technique UC02 § 3.2/3.3.
5. ⚠️ `azadaydinli/ac_freedom` — **sans licence**, **non consulté** pour cette UC. L'attribution MIT en
   tête de `smartclimBroadlinkLan.class.php` couvre `python-broadlink` et **ne s'étend pas**.

### 2.2 Structure — 23 octets

En-tête fixe, octets 0-9 : `bb 00 06 80 00 00 0f 00 01 01`. Deux octets seulement la distinguent de
l'en-tête de **lecture** (`bb 00 06 80 00 00 02 00 11 01`) : **l'octet 6** (`0x0f` au lieu de `0x02`) et
**l'octet 8** (`0x01` au lieu de `0x11`).

Offsets dans l'**espace charge HVAC nue** — le même que `smartclimFrame`, cf. UC02 § 3.3 : `requete()` a
déjà retiré le préfixe de longueur.

| Octet | Bits | Champ | Traitement |
|---|---|---|---|
| 10 | 7-3 | consigne | `(entier − 8) << 3` **si** `target_temp` dans l'ordre, sinon recopié |
| 10 | 2-0 | oscillation verticale | **recopié** |
| 11 | 7-5 | oscillation horizontale | **octet recopié tel quel** — cf. § 9, R2 |
| 12 | 3-0 | **marqueur `0x0F`** | **forcé** (`|= 0x0F`) — sans lui l'appareil ignore silencieusement l'ordre |
| 12 | 7 | demi-degré | posé/effacé **si** `target_temp` dans l'ordre, sinon recopié |
| 13 | 7-5 | vitesse | `code << 5` **si** `fan_speed` dans l'ordre |
| 14 | 6 | turbo | **dérivé** de `fan_speed` — cf. § 5.1 |
| 14 | 7 | silence (`mute`) | **recopié** (aucun concept générique) |
| 15 | 7-5 | mode | `code << 5` **si** `mode` dans l'ordre |
| 15 | 2 | veille | **recopié** |
| 18 | 5 | marche | `power << 5` **si** `power` dans l'ordre |
| 18 | 2 / 1 | nettoyage / santé | **recopiés** |
| 20 | 4 / 3 | afficheur / anti-moisissure | **recopiés** |
| 21-22 | — | non documentés | **recopiés** s'ils existent dans la trame lue, sinon `0x00` |

⚠️ Les octets **13, 15 et 18** sont **exactement ceux de la table de lecture** `smartclimFrame::champs()`
(vitesse, mode, marche). Un commentaire croisé est obligatoire dans les deux tables : elles décrivent le
même champ, vu en lecture et en écriture.

### 2.3 Encapsulation

Identique à la lecture (UC02 § 3.2) :
`[longueur uint16 LE][charge HVAC 23][somme 16 bits BE][remplissage nul]`, dans un tampon de **32
octets**. `longueur = 23 + 2 = 25 = 0x19` → `19 00`. Somme en offsets 25-26, remplissage en 27-31.

⚠️ Une des deux extractions de `Protocol.ts` décrit la longueur sur **1 octet** et décale tout de 1 :
c'est une imprécision de lecture. La forme **uint16 LE** est celle vérifiée arithmétiquement sur les
magics de lecture d'UC02 — elle fait foi.

⚠️ **Piège confirmé de visu** : `fparrav/DeviceControl.ts::sendCommand` ré-encapsule une charge **déjà
encapsulée** et y écrit `0xBEAF` **en dur** comme somme. Code mort ou erroné — **ne pas le reproduire**
(l'analyse § 5.4 le signalait, la lecture du 2026-09-02 le confirme).

### 2.4 Somme de contrôle de la charge HVAC

C'est la génération qu'UC02 avait explicitement renvoyée ici. Algorithme (lu dans
`commandPayloadChecksum`) :

```
somme = Σ, i par pas de 2 : (octet[i] << 8) + (octet[i+1] si présent, sinon 0)
tant que (somme >> 16) : somme = (somme & 0xFFFF) + (somme >> 16)
crc = 0xFFFF ^ somme        écrit BIG-endian [hi][lo]
```

⚠️ **La longueur 23 est impaire, et le cas est tranché par le code source, pas par convention** : la
boucle de référence lit `data[i+1]` hors borne, qui vaut 0 après masquage — le dernier octet est donc
traité comme **poids fort d'un mot complété par `0x00`**. La convention Internet standard dit la même
chose : les deux concordent.

⚠️ Cette somme est **distincte** de `sommeControle()` (`0xBEAF`, paquet `0x38`). **Deux fonctions,
jamais fusionnées.**

#### Contrôles obligatoires à l'implémentation — les deux premiers ne suffisent pas

Les magics de lecture font **10 octets, longueur paire** : ils n'exercent jamais la branche « dernier
octet complété par `0x00` », qui est la **seule partie réellement nouvelle** de la fonction.

| # | Entrée (charge HVAC) | Longueur | Somme attendue |
|---|---|---|---|
| 1 | `bb00 0680 0000 0200 1101` | 10 (paire) | `0x2B7E` |
| 2 | `bb00 0680 0000 0200 2101` | 10 (paire) | `0x1B7E` |
| **3** | vecteur impair ci-dessous | **23 (impaire)** | **`0x7EBD`** |
| **4** | vecteur 3 avec **octet 22 = `0x01`** | 23 (impaire) | **`0x7DBD`** |

**Vecteur 3** — charge d'écriture réaliste : consigne 24 °C sans demi-degré, vitesse `AUTO` (code 5),
mode `COOL` (code 1), marche, tous les autres bits à zéro.

```
bb 00 06 80 00 00 0f 00 01 01 80 00 0f a0 00 20 00 00 20 00 00 00 00
```

(octet 10 = `(24−8)<<3` = `0x80` · octet 12 = marqueur `0x0F` · octet 13 = `5<<5` = `0xA0` ·
octet 15 = `1<<5` = `0x20` · octet 18 = `1<<5` = `0x20`.)

Mots de 16 bits big-endian, le douzième étant l'octet 22 complété par `0x00` :

```
0xBB00 + 0x0680 + 0x0000 + 0x0F00 + 0x0101 + 0x8000
      + 0x0FA0 + 0x0020 + 0x0000 + 0x2000 + 0x0000 + 0x0000
= 0x18141
repli des retenues : 0x8141 + 0x1 = 0x8142
crc = 0xFFFF ^ 0x8142 = 0x7EBD          écrit BIG-endian : 7e bd
```

Charge encapsulée correspondante, à contrôler telle quelle (32 octets) :

```
19 00 | bb 00 06 80 00 00 0f 00 01 01 80 00 0f a0 00 20 00 00 20 00 00 00 00 | 7e bd | 00 00 00 00 00
```

⚠️ **Le vecteur 3 seul ne discrimine PAS** poids fort et poids faible : son octet 22 vaut `0x00`, les
deux lectures donnent le même résultat. D'où le **vecteur 4**, obligatoire : octet 22 = `0x01` → mot
final `0x0100`, somme `0x18241`, repli `0x8242`, **crc `0x7DBD`**. Une implémentation plaçant l'octet
impair en **poids faible** obtiendrait mot `0x0001`, somme `0x18142`, repli `0x8143`, crc **`0x7EBC`** —
les deux se distinguent.

### 2.5 Réponse à l'écriture

Ni `fparrav` ni `python-broadlink` ne la parsent comme trame d'état. Contrat retenu :
**confirmation = code d'erreur `0x22` nul**, plus la charge rendue par `requete()`, journalisée en
`debug` (premier octet attendu `0xBB`, longueur). **Aucun état n'est poussé depuis cette réponse** :
l'état affiché reste l'**état optimiste** construit depuis l'ordre réellement envoyé, comme en UC06.

### 2.6 Écart assumé avec l'implémentation de référence

`AuxDeviceControl` **ne relit pas** l'appareil avant d'écrire — il fusionne un `device.params` en cache
alimenté par un polling découplé — et fusionne au niveau des **paramètres décodés**, donc **perd tout
champ qu'il ne décode pas**.

SmartClim fait les deux différemment, et délibérément : **relecture systématique** et **fusion par
octets**. Le cache de la référence est la cause directe des retours en arrière que la spec fonctionnelle
décrit, et la fusion par paramètres ne peut structurellement pas tenir AC1 sur les oscillations.

---

## 3. Server vs Client

**Tout est serveur**, comme UC01 et UC02 — et cette fois de façon absolue : **aucun octet ne transite par
un navigateur**, puisque le seul déclencheur est une commande en ligne.

- Le déclencheur est un script **CLI**, gardé par `php_sapi_name() === 'cli'`. Aucune action AJAX,
  aucune page, aucun JS.
- Le décodage et l'encodage de trame exigent `openssl_*` et des opérations de bits sur des octets
  bruts : côté client, cela signifierait exposer la clé de session au navigateur — **exclu**.
- La CLI ne valide que la **forme** de ses arguments. Toute la validation **de fond** vit côté
  `smartclim` / `smartclimFrame`, aux mêmes endroits que pour le cloud (§ 7).

---

## 4. Validation

### 4.1 Ce que la CLI valide, et ce qu'elle ne valide pas

⚠️ **Aucune validation métier dans la CLI, et aucune construction d'ordre à la main.** Elle contrôle la
**forme** (`--equipement` entier, `--commande` chaîne, `--valeur` numérique) et **rejette tout argument
inconnu** — aucune valeur libre n'atteint le réseau.

La validation **de fond** vit entièrement côté classes : existence du `logicalId` dans
`definitionsCommandesAction()`, refus de `CMD_RAFRAICHIR`, bornes et quantification de la consigne via
`ordreEffectifConsigne()`, correspondance `intent` du transport. **C'est la condition d'AC4** : le LAN
reçoit exactement l'ordre que le cloud aurait reçu, parce qu'il passe par le même code.

La double barrière `lan_ip` / `lan_mac` d'UC01 est inchangée.

### 4.2 Entrées réseau

| Contrôle | Où | Comportement |
|---|---|---|
| `adresseLan()['ip']` vide | `sonderAppareilLan()` | exception curatée « lancez un scan ou renseignez l'adresse IP » — **pas** de diffusion à la volée (§ 9, R8) |
| MAC répondante ≠ MAC attendue (les **deux** ordres d'octets testés) | `sonderAppareilLan()` | exception curatée, **aucune session ouverte** avec cet appareil |
| statut de session hors `ETABLIE` / `REUTILISEE` | `appliquerOrdre()` | `TYPE_AUTH` ou `TYPE_RESEAU` selon le statut |
| **trame de base absente, < 21 octets, ou non décodable** | `encoderOrdre()` | **`TYPE_PROTOCOLE`, écriture NON tentée** — la garde qui tient AC6 |
| concept sans entrée d'écriture, ou valeur sans `intent` pour ce transport | `encoderOrdre()` | `TYPE_INTERNE` — un `execCmd()` de vieux scénario échoue proprement, jamais un octet approximatif |
| consigne hors `[8, 39]` après quantification | `encoderOrdre()` | `TYPE_INTERNE` — **jamais de clamp silencieux** |
| budget restant < `RESERVE_ECRITURE` | `appliquerOrdre()` | `TYPE_RESEAU` **avant** d'émettre : un ordre non envoyé vaut mieux qu'un ordre dont on ignore le sort |
| silence après émission (rejeu compris) | `requete()` → `appliquerOrdre()` | `TYPE_RESEAU` + contexte dédié → « **Commande LAN non confirmée** » |
| code appareil ≠ 0 | `requete()` (existant) | `classerCodeAppareil()` — table unique, jamais dupliquée |

### 4.3 Typage et curation des exceptions

La brique lève des messages **techniques** ; `smartclim::messageErreurLan()` est l'**unique** point de
bascule vers le français affichable — même règle que `messageErreurAuxHome()`. Elle était réservée à
cette UC par UC01 § 4.3 et UC02 § 7 : elle s'écrit enfin ici.

`envoyerOrdreLan()` rattrape `smartclimException` **puis `Throwable` en dernier bloc** (une `Error`
PHP 8 traverse `catch (Exception)`). Idem dans la CLI.

`session_write_close()` : sans objet en CLI.

### 4.4 Secrets

Identifiant et clé de session **ne sortent pas** de `smartclimBroadlinkLan` (`sessionEnCache()` et
`authentifier()` restent `private`). Les trames HVAC ne sont **ni persistées ni journalisées en clair** —
au plus le premier octet, la longueur et des codes entiers en `debug`, comme en lecture.

⚠️ **Aucun cache nouveau n'est créé, et aucune trame n'est stockée nulle part.** C'est aussi la réponse à
« d'où vient l'état de base » : de l'appareil, à chaque ordre.

---

## 5. Signatures

### 5.1 `smartclimFrame` — reste une table de données pure

Aucune E/S, aucun `cache::`, aucun `config::`, aucun `eqLogic`, aucun réseau. ⚠️ Son docblock de classe
doit être amendé : « ne lève jamais » ne vaut désormais que pour le **décodage**.

```php
const LONGUEUR_CHARGE_ECRITURE = 23;
const MARQUEUR_ECRITURE        = 0x0F;   // octet 12, bits 3-0
const CONSIGNE_MIN_ENCODABLE   = 8;      // (T-8) << 3 tient sur 5 bits
const CONSIGNE_MAX_ENCODABLE   = 39;

private static function enteteEcriture()   // 10 octets : bb 00 06 80 00 00 0f 00 01 01
private static function champsEcriture()   // concept => array('octet', 'masque', 'decalage')
                                           // ⚠️ octets 13/15/18 IDENTIQUES à ceux de champs() :
                                           // commentaire croisé obligatoire dans les DEUX tables.

public static function conceptsEncodables()
  // → array<int, string> : les concepts que encoderOrdre() sait écrire (champsEcriture()
  //   + CONCEPT_TARGET_TEMP). Consommée par smartclim::valeursCommandees() comme LISTE
  //   BLANCHE — cf. § 6.2, c'est une garde, pas une commodité.

public static function encoderOrdre($_transport, $_trameControleLue, array $_ordre)
  // → string : charge HVAC d'écriture, 23 octets, hexadécimal minuscule.
  // 1. exige >= 21 octets de trame de base (couvre l'octet 20), sinon TYPE_PROTOCOLE
  // 2. recopie min(23, len) octets, complète à 23 par des zéros
  // 3. ÉCRASE les octets 0-9 par enteteEcriture()  ← jamais l'en-tête de la réponse lue
  // 4. octet 12 |= MARQUEUR_ECRITURE
  // 5. patche les SEULS concepts présents dans $_ordre (+ consigne sur 2 octets, + turbo)
  // @throws smartclimException TYPE_PROTOCOLE (base illisible)
  //                          | TYPE_INTERNE (concept inconnu, valeur sans 'intent',
  //                            consigne hors [8, 39])
```

⚠️ **Bit turbo (octet 14, bit 6) — dérivé de `fan_speed` à CHAQUE commande de vitesse** : posé à 1 pour
`VITESSE_TURBO`, **effacé** pour toute autre vitesse commandée. Ce n'est **pas** un recopiage partiel
« uniquement en entrant en turbo » : sans l'effacement, commander `LOW` sur un appareil en turbo
enverrait « fil = 3 **et** turbo = 1 », deux informations contradictoires et un comportement
indéterminé. **Ne pas « corriger » cette dérivation en relecture.**
Le bit `mute` voisin (bit 7), lui, reste **recopié** : il n'appartient à aucun concept générique, et
l'effacer violerait AC1.

### 5.2 `smartclimBroadlinkLan`

```php
const RESERVE_ECRITURE = 3;   // secondes minimales exigées AVANT d'émettre l'ordre

public static function appliquerOrdre(array $_appareil, array $_ordre, $_budget)
  // → array : ordre RÉELLEMENT appliqué (consigne après quantification) — même contrat de
  //   sortie que smartclimAuxHomeApi::appliquerOrdre(). C'est ce que l'appelant pousse en
  //   état optimiste.
  // ⚠️ LÈVE, contrairement à ouvrirSession() et lireEtat() : chemin INTERACTIF, et un ordre
  //   perdu en silence est précisément ce que la spec fonctionnelle interdit.
  // Séquence :
  //  1. ouvrirSession($_appareil, budget)  — prend ET relâche son propre verrou
  //     hors ETABLIE/REUTILISEE → smartclimException typée (AUTH ou RESEAU selon le statut)
  //  2. si ETABLIE → usleep(DELAI_APRES_AUTH * 1e6)
  //  3. verrou($mac, …) + finally libererVerrou()
  //     ⚠️ LECTURE DE BASE ET ÉCRITURE SOUS LE MÊME VERROU : sinon un autre processus
  //        s'intercale entre les deux et notre écriture réécrit un état déjà périmé.
  //  4. requete(COMMANDE_REQUETE, hex2bin(CHARGE_ETAT), …)   → trame de base
  //  5. smartclimFrame::encoderOrdre(TRANSPORT_BROADLINK_LAN, $base, $_ordre)
  //  6. garde : budget restant >= RESERVE_ECRITURE, sinon TYPE_RESEAU AVANT d'écrire
  //  7. requete(COMMANDE_REQUETE, encapsulerChargeHvac($charge), …)
  //  8. journalise la réponse en debug ; renvoie l'ordre appliqué
  // @throws smartclimException (message TECHNIQUE, jamais affiché tel quel)

private static function encapsulerChargeHvac($_chargeHvac)
  // [longueur uint16 LE][charge][somme BE][zéros] → 32 octets bruts.

private static function sommeChargeHvac($_octets)
  // Complément à un « type Internet », mots BE, repli des retenues, dernier octet d'une
  // longueur impaire complété par 0x00 (§ 2.4).
  // ⚠️ DISTINCTE de sommeControle() (0xBEAF) — ne JAMAIS fusionner les deux.
```

⚠️ **`requete()` n'est pas touchée** (contrat figé en UC01). Son rejeu unique s'applique donc aussi à
l'écriture : **réémettre le même ordre est sans danger, parce que la trame porte un état absolu et
complet** — l'écriture est idempotente. Corollaire heureux du piège central, à écrire dans le code pour
que personne ne « corrige » le rejeu sur ce chemin.

### 5.3 `smartclim` — chemin d'appel complet

```php
const BUDGET_ORDRE_LAN     = 12;  // budget GLOBAL (hello + session + lecture + écriture)
const RESERVE_ECRITURE_LAN = 3;

private function ordreDeCommandeAction($_logicalId, array $_options)
  // Reste PRIVATE. Extraction VERBATIM des lignes de construction d'ordre de
  // executerCommandeAction() (consigne via ordreEffectifConsigne(), sinon
  // $definition['ordre']), PLUS la garde d'existence qu'elle porte désormais elle-même.
  // @throws smartclimException CURATÉE, littéral DÉJÀ EXISTANT :
  //   'Commande inconnue pour cet équipement' → aucune clé i18n nouvelle.
  // ⚠️ executerCommandeAction() CONSERVE sa propre garde et sa branche CMD_RAFRAICHIR
  //   AVANT l'appel : la séquence de contrôles d'un chemin RECETTÉ ne bouge pas. Le double
  //   appel à definitionsCommandesAction() qui en résulte est ASSUMÉ — elle ne fait aucune
  //   E/S, et préserver l'ordre des gardes prime sur une micro-optimisation.

public function envoyerCommandeActionLan($_logicalId, array $_options = array())
  // ← POINT D'ENTRÉE de la CLI. PUBLIQUE : un script CLI ne peut pas appeler une privée.
  //   C'est elle qui rend « AC4 par construction » vrai — elle passe par la MÊME
  //   ordreDeCommandeAction() que le chemin cloud, donc la même injection power => 1, la
  //   même quantification de consigne, la même liste blanche de logicalId.
  // 1. REVALIDE la présence de $_logicalId dans definitionsCommandesAction() : ce contrôle
  //    n'est plus porté par executerCommandeAction(), absente de ce chemin.
  //    Absent → TYPE_INTERNE, 'Commande inconnue pour cet équipement'
  // 2. REFUSE self::CMD_RAFRAICHIR — c'est une lecture, pas un ordre → TYPE_INTERNE
  // 3. $ordre = $this->ordreDeCommandeAction($_logicalId, $_options)
  // 4. return $this->envoyerOrdreLan($ordre)
  // @return array Ordre réellement appliqué (affiché par la CLI).
  // @throws smartclimException Message DÉJÀ CURATÉ en français.

public function envoyerOrdreLan(array $_ordreGenerique)
  // Façade du pilotage local, et POINT DE BRANCHEMENT du futur domaine post-mvp/02 : il
  // l'appellera depuis executerCommandeAction() sans rien réécrire ici.
  //  1. sonderAppareilLan(budget restant)
  //  2. $ordre = array_merge($this->valeursCommandees(), $_ordreGenerique)   ← cf. § 6
  //  3. smartclimBroadlinkLan::appliquerOrdre($appareil, $ordre, budget restant)
  //  4. enregistrerOrdre($applique)
  //  5. appliquerEtat($applique + array('source' => TRANSPORT_BROADLINK_LAN), true)
  //     ← pose au passage la commande info « transport » (mécanisme d'UC02 § 5.5)
  //  6. catch smartclimException → messageErreurLan() ; catch Throwable EN DERNIER
  // @return array Ordre réellement appliqué.
  // @throws smartclimException Message DÉJÀ CURATÉ en français.

private function valeursCommandees()            // § 6.2
private function sonderAppareilLan($_budget)
  // adresseLan() → interroger(ip) → vérification MAC (attendue vs trouvée ET inversée).
  // → ligne d'appareil normalisée. @throws smartclimException CURATÉE.
  // ⚠️ Le hello préalable est OBLIGATOIRE : il est la seule source de 'octets_mac' et de
  //   'type_appareil' (UC01 § 1.2), que ni adresseLan() ni la mémoire de sonde ne portent.

private static function messageErreurLan($_type, $_contexte)
  // SEUL endroit où vivent les __() d'erreur LAN.
```

### 5.4 `smartclimCapabilities`

`tables()[BROADLINK_LAN]` : colonne `intent` remplie **avec la valeur de `fil`** (5 modes ; vitesses
`AUTO 5 / LOW 3 / MEDIUM 2 / HIGH 1 / TURBO 4`, et `intent => null` là où `fil` est `null`).
`intent_confirme` **reste `false`** — marqueur documentaire, jamais lu par le code.
`echelleTemperature(BROADLINK_LAN)` → `array('facteur' => 1, 'pas_ecriture' => 0.5)`.

⚠️ **`intent = fil` n'est pas une supposition, c'est une conséquence du protocole.** Le mode et la
vitesse s'écrivent dans **le même octet, au même décalage** que celui où ils se lisent (15 bits 7-5 et
13 bits 7-5). Surtout, l'écriture procède par **recopie de la trame lue** : si les deux numérotations
différaient, chaque commande réécrirait les champs *non modifiés* avec des codes faux, et l'appareil
changerait de mode à chaque ordre — AC1 serait **structurellement infaisable**, y compris pour
l'implémentation de référence dont c'est la stratégie.
Statut de confiance : établie **par cohérence interne**, jamais mesurée sur matériel → `intent_confirme`
à `false`, à basculer à `true` au premier ordre réussi sur un appareil réel.
✅ Effet de bord nul sur le cloud : `versTransport()` n'est appelée avec `TRANSPORT_AUX_HOME` que dans
`definitionsCommandesAction()` et `smartclimAuxHomeApi::appliquerOrdre()`.

### 5.5 `core/php/commande-lan.php`

⚠️ **La CLI prend des `logicalId` de commandes action, PAS des concepts génériques.** C'est la
formulation littérale d'AC4 (« chaque commande action disponible sur l'équipement, testée
individuellement ») et la seule façon de ne pas réimplémenter à la main `power => 1`, la quantification
et la liste blanche — donc de ne pas diverger du cloud.

```
php core/php/commande-lan.php --equipement=<id> --lister
php core/php/commande-lan.php --equipement=<id> --commande=<logicalId> [--valeur=<consigne>]
```

Séquence — le script est un **aiguillage sans logique métier**, comme `diagnostic-auxhome.php` :

1. garde `php_sapi_name() === 'cli'` → sinon `http_response_code(403)` + `die` ;
2. `require_once __DIR__ . '/../class/smartclim.class.php'` ;
3. parse des arguments ; **tout argument inconnu tue le script** ;
4. `eqLogic::byId((int) $id)` + contrôle `instanceof smartclim`, sinon `die` ;
5. `--lister` → boucle sur `$eqLogic->getCmd('action')` (méthode **du core**, publique : aucune méthode
   nouvelle à exposer) et affiche `logicalId — nom`, puis sort ;
6. sinon `$eqLogic->envoyerCommandeActionLan($commande, $valeur !== null ? array('slider' => $valeur) : array())`,
   dans un `try { } catch (smartclimException) { } catch (Throwable) { }` — **`Throwable` en dernier** ;
7. affiche l'ordre appliqué en cas de succès, le message **déjà curaté** en cas d'échec.

**Aucun rapport écrit sur disque, aucune donnée d'appareil persistée.** ⚠️ Ne jamais écrire de fichier
dans le dossier du plugin : sa racine n'a pas de `.htaccess` (même piège que `configuration.txt`).

---

## 6. Le mécanisme d'AC5 — mémoire d'ordres en entrée

### 6.1 Pourquoi il faut ce mécanisme

La **relecture systématique** avant écriture règle AC1 et AC3 — mais elle **crée** un risque sur AC5 :
une relecture faite 2 s après un changement de mode peut encore renvoyer l'**ancien** mode (l'appareil
n'a pas fini d'appliquer), et l'écriture suivante le réécrirait, annulant silencieusement la commande
précédente. La mémoire d'ordres referme exactement ce trou.

Les trois mémoires du socle, et ce qu'on en fait :

- `CLE_CACHE_DEDUP` (10 s, empreinte du **contenu**) — **réutilisé tel quel, non dupliqué**, là où il
  est : dans `executerCommandeAction()`. Il ne gêne pas AC5 (deux ordres *différents* rapprochés passent
  tous les deux, la clé étant le contenu), et le jour où le domaine 02 aiguille vers le LAN il couvrira
  les deux transports sans une ligne de plus. Le chemin CLI ne le traverse pas — **voulu** : il protège
  du double-clic d'IHM, pas d'une commande tapée.
- `CLE_CACHE_ORDRES` (`DUREE_GRACE` = 60 s) — **réutilisé en sortie** (`enregistrerOrdre()` +
  `appliquerEtat(…, true)`, comme UC06) **et en entrée** (usage nouveau, ci-dessous).
- La grâce **en lecture** (`filtrerEtatSelonOrdres()` via `appliquerLectureLan()`) est déjà acquise
  depuis UC02 : rien à faire.

### 6.2 ⚠️ Format réel de `memoireOrdres()` — vérifié dans le code, à ne pas se tromper

`core/class/smartclim.class.php`, lignes ~3122-3143 :

```php
// retour : array<concept, array{valeur: mixed, ts: int}>   ← SOUS-TABLEAU, jamais la valeur nue
```

Deux faits en découlent, et ils figent l'étape 2 d'`envoyerOrdreLan()` :

1. **Le filtrage temporel est DÉJÀ fait par `memoireOrdres()`** : elle purge à la lecture toute entrée
   dont `($maintenant - (int) $entree['ts']) > self::DUREE_GRACE`, où `ts` est le `time()` posé par
   `enregistrerOrdre()` **au moment de l'envoi**. Une entrée rendue est donc, **par construction**,
   encore sous grâce. ⚠️ **Ne pas refiltrer** — ce serait dupliquer la règle des 60 s à un second
   endroit, exactement ce que le dépôt évite partout ailleurs.
2. **Seule l'extraction de `['valeur']` manque**, et aucun helper existant ne la fait :
   `filtrerEtatSelonOrdres()` lit `$entree['valeur']` **inline** et ne rend jamais une map
   `concept => valeur`.

```php
private function valeursCommandees()
  // memoireOrdres() rend concept => array('valeur' => …, 'ts' => …) et a DÉJÀ purgé les
  // concepts hors grâce : ici, EXTRACTION SEULE, aucun refiltrage temporel.
  // Ne retient que les concepts de smartclimFrame::conceptsEncodables() (LISTE BLANCHE) :
  // une entrée de cache portant un concept futur (oscillation, domaine post-mvp/04) ferait
  // sinon lever TYPE_INTERNE à encoderOrdre() et casserait TOUTES les commandes LAN
  // pendant 60 s — l'inverse exact de ce que ce mécanisme est là pour faire.
  // → array<concept, mixed> : valeurs GÉNÉRIQUES scalaires.
```

⚠️ **`array_column($memoire, 'valeur')` n'est PAS une alternative valide** : elle **perd les clés de
concept** (tableau indexé quand `index_key` n'est pas fourni, et la clé de concept n'est pas une colonne
du sous-tableau). La boucle `foreach` explicite est **obligatoire**.

Fusion :

```php
$ordre = array_merge($this->valeursCommandees(), $_ordreGenerique);
// clés identiques : l'ordre DEMANDÉ écrase toujours la valeur sous grâce.
```

**Ce qui entre donc dans `encoderOrdre()`** : uniquement des couples `concept => valeur générique
scalaire` (`power` 0/1, `mode`/`fan_speed` codes génériques, `target_temp` float) — **jamais un
sous-tableau**. `enregistrerOrdre()` et l'état optimiste reçoivent ensuite l'ordre appliqué **complet**,
valeurs héritées de la grâce incluses : elles ont réellement été réémises à l'appareil, réarmer leur
grâce est donc exact.

---

## 7. Budget de temps

| Constante | Valeur | Portée |
|---|---|---|
| `BUDGET_ORDRE_LAN` | 12 s | **global** à un ordre : hello + session + lecture + écriture |
| `RESERVE_ECRITURE` / `RESERVE_ECRITURE_LAN` | 3 s | minimum exigé **avant** d'émettre l'ordre |

Chronométré depuis l'entrée d'`envoyerOrdreLan()` ; chaque étape reçoit
`max(1, min(TIMEOUT_ECHANGE, restant))`. Aucune constante n'est relue au fond de la pile.

⚠️ **Le budget est dépassable, et c'est consigné en dette plutôt que masqué** (§ 12, D-1). Pire cas
théorique : hello (2 s) + session (verrou 2 s + auth 2 s + 0,2 s) + verrou (2 s) + lecture avec rejeu
(2 + 2 + 0,2 s) + écriture avec rejeu (2 + 2 + 0,2 s) ≈ **20,6 s**, parce que **chacun des deux
`requete()` porte son propre rejeu** et que le plancher `max(1, …)` garantit à chaque échange au moins
1 s même budget épuisé. **Desserrer la constante ne supprimerait pas ce plancher** : le dépassement
resterait, simplement plus long. La garde `RESERVE_ECRITURE` empêche en revanche le seul cas réellement
mauvais — émettre un ordre sans pouvoir en lire l'accusé.

Un ordre CLI n'a **pas** de timeout HTTP : le budget est sa seule borne.

---

## 8. Périmètre

### 8.1 Le déclencheur — arbitré le 2026-09-03

La spec fonctionnelle présupposait « un équipement piloté en LAN », notion qui n'existe pas encore : le
domaine `post-mvp/02` en est propriétaire, et **son UC01 déclare dépendre de celle-ci** — dépendance
circulaire de fait.

Retenu : **une commande en ligne dédiée**. `executerCommandeAction()` reste **cloud**, aucune surface
web n'est ajoutée, aucune clé de configuration n'est créée. Le jour du domaine 02, l'aiguillage se
réduit à un appel à `envoyerOrdreLan()` — la façade est dimensionnée pour ça.

Écarté : brancher directement `executerCommandeAction()` sur le LAN quand une adresse locale est connue.
Cela **préempterait le domaine 02** (c'est le mode AUTO codé en dur) et rendrait faux par avance son
critère « en mode CLOUD, aucun paquet LAN n'est émis ».

La spec fonctionnelle a été **amendée** en conséquence (§ Hors périmètre).

### 8.2 Oscillations et options de confort — AC4 amendé

**Couvrable ici** : leur **préservation**, garantie par la fusion au niveau des octets (AC1) — pour tous
les champs sauf l'oscillation horizontale (§ 9, R2).
**Non couvrable** : leur **pilotage**. Le modèle générique ne porte aucun `CONCEPT_*` correspondant,
donc `definitionsCommandesAction()` ne crée aucune commande d'oscillation ni d'option : il n'y a rien à
tester individuellement. C'est le domaine `post-mvp/04-fonctions-avancees`.

---

## 9. Risques

- **R1 — rien de tout cela ne sera recetté.** Le code est vérifié contre `python-broadlink` et `fparrav`
  (MIT). La somme de contrôle, elle, est vérifiable **arithmétiquement** (§ 2.4) : c'est le seul test
  réellement exécutable de cette UC, **il doit être fait à l'implémentation**.
- **R2 — l'oscillation horizontale est le seul champ dont la préservation n'est pas démontrable.**
  L'octet 11 la porte en **bits 7-5 en écriture** et en **bits 2-0 en lecture** selon `ac_freedom` :
  contrairement au demi-degré (fausse divergence d'espace, refermée en UC02), **celle-ci reste ouverte**.
  Décision : **recopier l'octet tel quel** — toute transformation reposerait sur le choix arbitraire
  d'une des deux lectures contradictoires. À trancher au domaine `post-mvp/04`, sur matériel.
- **R3 — le bit turbo est dérivé, et c'est une hypothèse.** `VITESSE_TURBO` est modélisée à la fois par
  un code `fil` (4) et par un bit dédié. Cf. § 5.1 pour la règle et son motif.
- **R4 — la relecture systématique coûte un aller-retour et crée le risque qu'AC5 referme.** Choix
  assumé contre la référence : en LAN le RTT est négligeable, et un état de base issu d'un cache est
  exactement la cause des retours en arrière que la spec décrit.
- **R5 — la réponse d'écriture n'est validée par personne.** Aucune référence ne la parse ; la
  confirmation repose sur le seul code d'erreur `0x22`. Si un appareil réel accusait `0` sans appliquer,
  seule la recette le montrerait — d'où l'instrumentation `debug`.
- **R6 — un rejeu de `requete()` réémet l'ordre.** Sans danger (état absolu, écriture idempotente), mais
  **à ne pas « corriger »** en désactivant le rejeu sur l'écriture : le contrat de `requete()` est figé.
- **R7 — la consigne encodable est `[8, 39] °C`, l'enveloppe personnalisable du plugin descend à 5 °C.**
  Un utilisateur ayant réglé `temp_min = 5` verra ses consignes < 8 acceptées par le cloud et refusées
  en LAN, avec un message explicite. Divergence de comportement entre transports → dette D-3.
- **R8 — dette 4 d'UC01 / D-5 d'UC02 toujours ouverte** : si le cache de sonde a été purgé,
  `adresseLan()` rend `source: 'aucun'` et la commande échoue avec un message invitant à scanner.
  **Aucune diffusion à la volée** : 4 s de broadcast dans un chemin interactif est un mauvais compromis,
  et le message est actionnable.
- **R9 — concurrence hors SmartClim.** S'authentifier **invalide** la session de l'application
  constructeur ou de Home Assistant : un ordre LAN peut décrocher un autre logiciel. Intrinsèque au
  protocole, à documenter côté `docs/`. Le `flock` par MAC sérialise en revanche parfaitement deux
  ordres SmartClim simultanés.
- **R10 — cas « LAN-only jamais scanné par le cloud » (D-3 d'UC02) inchangé** :
  `definitionsCommandesAction()` filtre toujours sur `TRANSPORT_AUX_HOME`, donc un équipement sans
  profil cloud n'a aucune commande d'action à envoyer. Non aggravé, non traité ici.
- **R11 — mécanique.** Aucune classe nouvelle ⇒ aucune ligne d'autoload… **à condition que la revue n'en
  crée pas une**. Et les nouveaux docblocks parlent d'octets, de masques et de `0x0F` : terrain propice
  à une séquence de fermeture de commentaire collée au texte. **Lancer
  `python .claude/scripts/verif-plugin.py` (colonne `meta=`) avant commit.**
- **R12 — duplication assumée** : la vérification « MAC attendue vs MAC trouvée » existera à deux
  endroits (`scannerReseauLocal()` phase 2 et `sonderAppareilLan()`). Le scan **n'est pas refactorisé** —
  chemin livré, budgets et compteurs entremêlés. Même statut que la dette 2 d'UC01, à refermer quand
  `smartclimTransport` existera.
- **R13 — `BUDGET_ORDRE_LAN` est dépassable** (~20,6 s contre 12 s annoncées) : dette assumée, cf. § 7.
- **R14 — le format de `memoireOrdres()` est un sous-tableau, et l'erreur serait silencieuse à
  l'écriture, fatale à l'exécution.** Fusionner ses entrées sans extraire `['valeur']` fait entrer
  `array('valeur' => …, 'ts' => …)` dans `encoderOrdre()`, où `versTransport()` ne le reconnaît jamais →
  `TYPE_INTERNE` **à chaque commande émise pendant qu'un autre concept est dans sa fenêtre de 60 s**,
  c'est-à-dire précisément le scénario d'AC5. Ni `php -l`, ni la CI, ni `verif-plugin.py` ne le voient.
  `valeursCommandees()` est le **seul** endroit autorisé à lire ce format.

---

## 10. Dépendances

**Aucune.** PHP pur : `openssl_*` et opérations de bits, déjà utilisés en UC01/UC02. Aucun démon, aucun
paquet `pip`. `plugin_info/packages.json` reste vide, `hasDependency` et `hasOwnDeamon` restent à
`false`.

---

## 11. Recette — ce qui est vérifiable, et ce qui ne l'est pas

⚠️ **AC1 à AC6 ne sont pas recettables** : l'appareil de validation ignore le protocole Broadlink.

**Ce qui est réellement vérifiable, et qui doit l'être** :

1. **Les quatre vecteurs de somme de contrôle du § 2.4** — dont le vecteur impair et son discriminant
   poids fort / poids faible. C'est le seul test exécutable de cette UC.
2. **Non-régression du cloud** : les commandes d'action Jeedom continuent de passer par le cloud, à
   l'identique. L'extraction d'`ordreDeCommandeAction()` ne doit rien changer à leur comportement ni à
   l'ordre de leurs gardes.
3. Un `php core/php/commande-lan.php --equipement=<id> --lister` doit afficher les commandes d'action de
   l'équipement sans rien émettre sur le réseau.
4. Le script doit refuser de s'exécuter depuis un navigateur (garde `php_sapi_name()`).

---

## 12. Dette

Reviews croisées, **deux tours** (2026-09-03). Tour 2 : `code-reviewer` **`pass`, aucun `blocker` ni
`major`, aucune régression** ; `security-reviewer` **aucun `critical` ni `high`**.

**Ce que les reviews ont vérifié et confirmé** — utile à savoir avant de rouvrir ce code :

- `encoderOrdre()` est fidèle **octet par octet** au § 2.2 : en-tête posé et jamais recopié, marqueur
  `0x0F` forcé inconditionnellement, masques ne touchant que les bits documentés (`0xE0`, `0xE0`,
  `0x20`), et **tout octet non explicitement patché traverse intact** — c'est AC1.
- Les **quatre vecteurs de somme du § 2.4 ont été retracés à la main** par le reviewer, `0x7EBD` et
  `0x7DBD` inclus : la discrimination poids fort / poids faible du dernier octet impair tombe juste.
- La boucle `enregistrerOrdre()` → `valeursCommandees()` → `encoderOrdre()` est cohérente de bout en
  bout : ce que le cache contient est exactement ce que l'encodeur sait relire. **Le risque R14 est
  refermé.**
- Chemin cloud **non régressé** : `executerCommandeAction()` conserve sa garde d'existence, sa branche
  `CMD_RAFRAICHIR`, sa déduplication et ses `catch`, dans le même ordre.
- Verrou unique couvrant lecture **et** écriture, un seul `finally`, chemin de fichier en `sha1()` — pas
  de traversée de répertoire depuis une donnée réseau.

**Corrigé au tour 1** (un lot unique consolidé) : contextes d'erreur dédiés
(`CONTEXTE_BASE_ILLISIBLE`, `CONTEXTE_CONSIGNE_HORS_PLAGE`) threadés jusqu'à `messageErreurLan()`, qui
restitue les deux messages **actionnables** du § 12 au lieu d'un générique « consultez les logs » ;
refus de `CMD_RAFRAICHIR` séparé de la garde d'existence avec son message propre ; commentaire croisé
`champs()` ↔ `champsEcriture()` ; docblock de `requete()` (32 octets en écriture) ; message technique
brut retiré de la sortie de la CLI.
**Corrigé en passe de finition** : `neutraliserPourLog()` appliqué au `getMessage()` journalisé par la
CLI, par cohérence avec le patron du projet.

⚠️ **Deux causes d'erreur restent volontairement sur le message générique `TYPE_INTERNE`** — consigne
non numérique, et concept/valeur sans correspondance d'écriture. Jugé acceptable par la review : ce sont
des **filets de sécurité derrière des entrées déjà validées en amont** (la consigne passe par
`ordreEffectifConsigne()`, les concepts par `conceptsEncodables()` puis par la liste blanche de
`definitionsCommandesAction()`), donc non atteignables depuis un usage normal. Ne pas y voir un oubli.

---

**Reste en dette — rien de bloquant** :

| # | Dette | Où | Quand la traiter |
|---|---|---|---|
| **D-1** | `BUDGET_ORDRE_LAN` dépassable (~20,6 s) dans un cas à double ré-authentification, à cause du plancher `max(1, …)` de chaque échange. Même nature que la dette D-1 d'UC02. | `smartclim::envoyerOrdreLan()` | domaine `post-mvp/02`, quand l'écriture LAN cessera d'être un chemin de diagnostic |
| **D-2** | Position réelle du bit d'oscillation horizontale (R2) — **vraie** divergence entre références, non refermable par l'analyse | `smartclimFrame` | domaine `post-mvp/04`, sur matériel |
| **D-3** | Consigne encodable `[8, 39] °C` en LAN contre `[5, 35]` d'enveloppe personnalisable : divergence de comportement entre transports (R7) | `smartclimFrame::encoderOrdre()` | domaine `post-mvp/02` |
| **D-4** | Duplication de la vérification de MAC entre `scannerReseauLocal()` et `sonderAppareilLan()` (R12) | `smartclim` | à la création de `smartclimTransport` |
| **D-5** | `enregistrerOrdre()` / `appliquerEtat()` s'exécutent **hors** du `try/catch` propre d'`envoyerOrdreLan()` : une `Error` PHP y remontant n'est rattrapée que par le `catch (Throwable)` de l'appelant. Relevé au tour 2, non introduit par cette UC. | `smartclim::envoyerOrdreLan()` | quand le domaine 02 fera de `envoyerOrdreLan()` un chemin non interactif |
