# Spec technique — UC02 post-MVP/01 « Lecture de l'état et de la température ambiante en LAN »

> **Spec fonctionnelle** : `.memory/specs/post-mvp/01-transport-broadlink-lan/02-lecture-etat-lan.md`
> **Dépend de** : UC01 de ce domaine (`01-decouverte-lan-et-session-tech.md`) — dont le **§ 7 fige le
> contrat de `requete()`**, implémenté ici pour la première fois.
> **Date du plan** : 2026-09-01 · **Statut de recette** : ⚠️ **non recettable** (l'appareil de validation
> de l'utilisateur ignore le protocole Broadlink — cf. § 10).

---

## 0. Ce que fait cette UC, en une phrase

Ajouter au transport Broadlink LAN la **lecture d'état** (commande `0x6A`) et pousser cet état dans
l'équipement Jeedom, en **extrayant le décodeur de trame HVAC** de `smartclimAuxHomeApi` vers une classe
partagée **`smartclimFrame`** — ce qui rend l'AC3 (« état identique LAN et cloud ») vrai **par
construction** plutôt que par coïncidence.

C'est le déclenchement de la condition posée par `CLAUDE.md` : *« `smartclimFrame` se crée le jour où un
second transport décode la même trame »*. Ce jour est arrivé.

**Ce que cette UC ne fait pas** : elle n'arbitre **jamais** entre LAN et cloud (domaine
`post-mvp/02-strategies-de-transport`), n'envoie **aucun** ordre (UC03 de ce domaine), et ne crée
**aucun** `eqLogic` par la voie LAN.

---

## 1. Architecture

### 1.1 Fichiers

| Fichier | État | Contenu | Indentation |
|---|---|---|---|
| `core/class/smartclimFrame.class.php` | **créé** | Décodeur **partagé** de la trame HVAC | 2 espaces, CRLF |
| `core/php/smartclim.inc.php` | modifié (1 ligne) | `require_once` de la nouvelle classe | 2 espaces, CRLF |
| `core/class/smartclimAuxHomeApi.class.php` | modifié | Le décodeur en sort ; `etatAppareil()` / `capacitesAppareil()` deviennent des délégations | 2 espaces, CRLF |
| `core/class/smartclimBroadlinkLan.class.php` | modifié | `requete()`, `lireEtat()`, `etatAppareil()`, `capacitesAppareil()`, `sessionEnCache()` | 2 espaces, CRLF |
| `core/class/smartclimCapabilities.class.php` | modifié | Section `TRANSPORT_BROADLINK_LAN` dans `tables()` | 2 espaces, CRLF |
| `core/class/smartclim.class.php` | modifié | Branchement dans le scan, `appliquerLectureLan()`, statuts, `source` | 2 espaces, CRLF |

**Non touchés — et c'est un résultat vérifié, pas un oubli** :

- `core/ajax/smartclim.ajax.php` — l'action `scannerClimatiseurs` existe déjà, avec `isConnect('admin')`,
  `ajax::init()`, `session_write_close()` et le `catch (Throwable)` final. **Aucun nouveau paramètre
  n'entre par le navigateur.**
- `desktop/php/smartclim.php` — la colonne « Résultat » du tableau LAN rend `statutLibelle`, calculé
  côté serveur : les nouveaux statuts s'y affichent sans toucher au HTML.
- `desktop/js/smartclim.js` — `resultat.profils` / `resultat.etatsConnexion` sont déjà fusionnés par id
  côté client ; le `timeout: 60000` couvre le pire cas (§ 6).
- `core/config/smartclim.config.ini`, `plugin_info/*` — **aucune clé de config plugin** n'est introduite,
  donc aucun piège `preConfig_` ni défaut INI.
- `core/i18n/*.json` — traduction en fin de cycle par le sous-agent `translator`.

### 1.2 ⚠️ Autoload — la ligne sans laquelle rien ne marche

`smartclimFrame` **doit** être ajoutée à la liste de `require_once` de `core/php/smartclim.inc.php`,
**entre** `smartclimCapabilities` et `smartclimAuxHomeApi` (les deux transports en dépendent) :

```php
require_once __DIR__ . '/../class/smartclimFrame.class.php';
```

Sans cette ligne : `Class 'smartclimFrame' not found` **au runtime uniquement** — invisible à `php -l`,
à la CI **et** à `verif-plugin.py`. Cf. `CLAUDE.md` → Conventions → Autoload Jeedom.

`smartclimFrame.class.php` porte en outre **ses propres `require_once` en tête** (core Jeedom +
`smartclimCapabilities.class.php`), comme les autres classes annexes du plugin : c'est la convention en
place, elle garde chaque classe chargeable isolément.

---

## 2. `smartclimFrame` — le décodeur mutualisé

**Statut de la classe** : table de données pure, **exactement le même que `smartclimCapabilities`** —
aucune E/S, aucun `cache::`, aucun `config::`, aucun `eqLogic`, aucun accès réseau. Elle ne connaît ni
AUX Home, ni Broadlink : elle reçoit deux trames en hexadécimal et un identifiant de transport.

```php
const TRAME_CONTROLE = 'controle';
const TRAME_LONGUE   = 'longue';
const AMBIANTE_MIN_PLAUSIBLE = -20;
const AMBIANTE_MAX_PLAUSIBLE = 60;

private static function champs()                    // ex-smartclimAuxHomeApi::champsEtatAuxHome()
                                                    // concept => array('trame' => …, 'octets' => array(…))
private static function longueursMinimales()        // ex-offsetsAuxHome() : max(octets) + 1 par trame
private static function octet($_trame, $_index)     // ex-octetTrame() : int|null, ne lève jamais

public static function conceptsLisibles($_trameControle, $_trameLongue)
  // → array<int, string> : les concepts DÉCODABLES depuis ces trames.
  //   N'inclut JAMAIS CONCEPT_ONLINE — la joignabilité est l'affaire du transport, pas de la trame.

public static function decoderEtat($_transport, $_trameControle, $_trameLongue)
  // → array<concept, mixed> : SEULES les clés effectivement déterminables.
  //   Ne pose NI 'online' NI 'source' : les deux sont ajoutés par le transport appelant.
```

### 2.1 ⚠️ Le corps de `decoderEtat()` est une copie VERBATIM

La logique déplacée depuis `smartclimAuxHomeApi::etatAppareil()` se transporte **à l'identique** :
décalages de bits, cast `(float)` explicite sur la consigne, appel à `depuisTransport()`, garde de
plausibilité de l'ambiante. **Aucune valeur d'octet, aucun décalage, aucune borne ne change.**

Deux seuls écarts autorisés :
1. `depuisTransport()` reçoit le transport **en paramètre** au lieu de la constante `TRANSPORT_AUX_HOME` ;
2. les messages de `log::add` deviennent **neutres de transport** (« Trame HVAC : code mode inconnu (…) »
   au lieu de « AUX Home : … dans `status.control` »).

**Motif** : c'est la condition pour qu'AC3 soit *démontrable* et pour que le chemin cloud **déjà recetté**
ne bouge pas d'un pixel. Toute « amélioration » glissée dans cette extraction transforme une refactorisation
neutre en modification fonctionnelle non recettée sur le seul transport qu'on peut réellement tester.

### 2.2 Ce qui ne migre pas

`nettoyerTrame()` **reste** dans `smartclimAuxHomeApi` : elle assainit un champ **du cloud**, c'est de
l'hygiène de transport, pas du décodage. Le LAN, lui, produit son hexadécimal par `bin2hex()` — il n'a
rien à assainir.

### 2.3 Vérification préalable — appelants

Confirmé par `grep` sur tout le dépôt (`core/`, `desktop/`, `plugin_info/`, y compris
`smartclimDiagnostic` et `core/php/diagnostic-auxhome.php`) : `champsEtatAuxHome()`, `offsetsAuxHome()`,
`octetTrame()`, `AMBIANTE_MIN_PLAUSIBLE` et `AMBIANTE_MAX_PLAUSIBLE` **n'ont aucun appelant hors de
`smartclimAuxHomeApi`**. L'extraction est donc sans effet de bord.

---

## 3. Contrat externe — la lecture Broadlink `0x6A`

Transport, en-tête `0x38`, chiffrement AES-128-CBC, compteur, codes d'erreur `0x22` : **tout est déjà
livré en UC01** (§ 1.3/1.4 de sa spec technique). Rien n'y change.

### 3.1 Sources, par ordre d'autorité

1. **`mjg59/python-broadlink`** (MIT) — en-tête, compteur, codes d'erreur, chiffrement. Déjà cité en tête
   de `smartclimBroadlinkLan.class.php` : **ne pas dupliquer l'attribution**.
2. **`azadaydinli/ac_freedom`** (`custom_components/ac_freedom/broadlink_ac_api.py`) —
   ⚠️ **AUCUNE LICENCE**. Consulté en **lecture seule le 2026-09-01**, aucune ligne recopiée. Les charges
   et offsets ci-dessous sont des **constantes de protocole**, re-vérifiées indépendamment (§ 3.3), pas
   une transcription de code. **L'attribution MIT en tête du fichier ne doit pas être étendue à ce dépôt.**
3. **`fparrav/homebridge-aux-cloud`** (MIT), `Protocol.ts::buildCommandPayload` — recoupement.
4. `.memory/analyse/smartclim-transport-broadlink-lan.md` § 5.1-5.3 et § 10.

### 3.2 Les deux requêtes

Charge utile de **16 octets** (un bloc AES exactement), chiffrée avec la **clé de session**, transportée
dans l'en-tête `0x38` que `construirePaquet()` sait déjà produire :

| Usage | Charge en clair (hex) | Charge déchiffrée attendue |
|---|---|---|
| État HVAC | `0c00` `bb00 0680 0000 0200 1101` `2b7e` `0000` | **32 octets** |
| Mesures / ambiante | `0c00` `bb00 0680 0000 0200 2101` `1b7e` `0000` | **48 octets** |

Structure — **de la requête comme de la réponse** :

```
[longueur uint16 LE][charge HVAC][somme 16 bits][remplissage nul]
```

où `longueur = strlen(charge HVAC) + 2`. Ici `0x000C = 12 = 10 + 2`.

**Somme de contrôle de la charge HVAC** — complément à un « type Internet » sur mots de 16 bits,
**distinct** de `sommeControle()` (qui, elle, couvre le paquet `0x38`) :

```
somme = Σ mots de 16 bits BIG-endian de la charge HVAC (avec repli des retenues)
crc   = (~somme) & 0xFFFF, écrit en BIG-endian [hi][lo]
```

Vérifié arithmétiquement : `bb00 0680 0000 0200 1101` → `0x2B7E` ✅ ; `…2101` → `0x1B7E` ✅.

⚠️ **Cette UC n'a pas à la générer** — les deux charges sont des constantes figées. L'algorithme sert
uniquement de **vérification non bloquante** de la réponse, journalisée en `debug`. Sa génération est le
problème d'UC03 (envoi d'un ordre).

### 3.3 ⚠️ Le fait structurant — décalage de 2 octets entre les deux espaces d'offsets

Les offsets de l'analyse (§ 5.2) sont **absolus dans la charge déchiffrée**, laquelle **commence par le
préfixe `[longueur]` de 2 octets**. Les offsets de la trame cloud `status.control` — en production depuis
UC05 du MVP — sont dans la **charge HVAC nue** (elle commence par `bb00…`). Donc :

```
offset charge HVAC = offset réponse LAN − 2
```

Recoupement sur les six concepts déjà en production — **exact sur les six** :

| Concept | Réponse LAN (`ac_freedom`) | − 2 | Trame cloud, en production |
|---|---|---|---|
| consigne | `(o[12] >> 3) + 8` | **10** | `(o[10] >> 3) + 8` ✅ |
| demi-degré | `o[14] & 0x80` | **12** | `o[12] & 0x80` ✅ |
| vitesse | `o[15] >> 5` | **13** | `o[13] >> 5` ✅ |
| mode | `o[17] >> 5` | **15** | `o[15] >> 5` ✅ |
| marche/arrêt | `(o[20] >> 5) & 1` | **18** | `(o[18] >> 5) & 1` ✅ |
| ambiante (trame longue) | `o[17] & 0x1F` | **15** | `o[15] − 32` ✅ (équivalents sur `[32, 95]`) |

**Conséquence 1 — le premier « À confirmer » de la spec fonctionnelle se referme analytiquement.** La
« divergence 12 contre 14 » du demi-degré n'existe pas : `fparrav` compte en espace charge HVAC,
`ac_freedom` en espace réponse. `12 + 2 = 14` — **les deux références désignent le même bit**, celui que
le plugin lit déjà côté cloud. Reste à confirmer **contre le matériel** (aucune mesure n'existe), mais
**aucune branche alternative ne doit être codée**.

**Conséquence 2 — ce décalage n'est PAS une transformation à coder.** `requete()` retire le préfixe de
longueur (`substr($clair, 2, $longueur - 2)`) : ce qu'elle rend est déjà de la charge HVAC nue, dans le
**même espace d'offsets** que la trame cloud. Le décalage de 2 est un **outil d'analyse** ayant servi à
valider la cohérence des sources externes, rien de plus. C'est précisément ce qui rend AC3 vrai par
construction.

**Conséquence 3 — le second « À confirmer » (oscillation horizontale) est sans objet ici.** Il n'existe
aucun `CONCEPT_*` d'oscillation (domaine `post-mvp/04`) : rien n'est décodé pour cet axe, donc « aucune
valeur inventée » est tenu **par absence**. Pour mémoire, la contradiction reste ouverte pour le domaine
04 : écriture = `o[11]` bits 7-5 ; lecture `ac_freedom` = `o[11]` bits 2-0 ; lecture `fparrav` = `o[10]`
bits 2-0, qui est la case de l'oscillation **verticale** — recopie manifestement fautive.

### 3.4 Codes d'erreur appareil

Inchangés : la table `classerCodeAppareil()` d'UC01 est **réutilisée, jamais dupliquée**. `-7`, `-4012`,
`-1` → `TYPE_AUTH` → déclencheurs du rejeu réactif.

⚠️ **`-4` (*Command not supported*) → `TYPE_PROTOCOLE`** : c'est **la** réponse attendue d'un appareil
Broadlink qui n'est pas un climatiseur AUX (une prise, une télécommande IR). Elle doit produire le statut
« état non décodable », **pas** une erreur — sinon tout scan sur un réseau contenant un autre appareil
Broadlink se met à journaliser des erreurs.

---

## 4. Server vs Client

**Tout est serveur.** Décision sans alternative sérieuse :

- Le navigateur n'envoie que `{ action: 'scannerClimatiseurs' }` — **aucun offset, aucune adresse, aucun
  chemin réseau, aucune charge** ne transite par le client. C'est le même principe que la sonde de
  diagnostic : *le catalogue est une donnée serveur*, sans quoi on ouvrirait un SSRF.
- Les libellés de statut sont **calculés côté serveur** (`libelleStatutLan()`) et rendus tels quels : le
  JS n'a aucune table de correspondance à tenir à jour.
- Le décodage de trame exige `openssl_*` et des opérations de bits sur des octets bruts : côté client,
  cela signifierait exposer la clé de session au navigateur — **exclu**.

Le seul rôle du client reste celui posé en UC01 : déclencher l'appel AJAX et rendre le tableau de
résultats.

---

## 5. Signatures

### 5.1 `smartclimAuxHomeApi` — délégations, **contrat de sortie inchangé**

```php
public static function etatAppareil(array $_appareil)
  // Signature ET clés de retour STRICTEMENT INCHANGÉES :
  //   array('online' => …, 'source' => TRANSPORT_AUX_HOME)
  //   + smartclimFrame::decoderEtat(TRANSPORT_AUX_HOME, trame_controle, trame_running)

public static function capacitesAppareil(array $_appareil)
  // Signature ET clés de retour STRICTEMENT INCHANGÉES :
  //   concepts = array(CONCEPT_ONLINE) + smartclimFrame::conceptsLisibles(…)
  //   modes / vitesses / modes_exclus / temperature / source : logique actuelle INTACTE
```

### 5.2 `smartclimBroadlinkLan` — ajouts

```php
const COMMANDE_REQUETE      = 0x6A;
const CHARGE_ETAT           = '0c00bb0006800000020011012b7e0000';  // 16 octets, cf. § 3.2
const CHARGE_INFO           = '0c00bb0006800000020021011b7e0000';
const STATUT_ETAT_LU        = 'etat_lu';
const STATUT_ETAT_ILLISIBLE = 'etat_illisible';

private static $compteurPaquet = null;   // compteur de paquet, PAR PROCESSUS (cf. § 5.3)
```

```php
public static function lireEtat(array $_appareil, $_budget)
  // → array('session' => STATUT_*, 'statut' => STATUT_*,
  //         'trame_controle' => string, 'trame_longue' => string)
  // ⚠️ NE LÈVE JAMAIS. Séquence :
  //  1. $statutSession = ouvrirSession($_appareil, $_budget)   ← réutilisée telle quelle
  //     (elle prend ET relâche son propre verrou avant de rendre la main)
  //  2. hors ETABLIE / REUTILISEE → retour immédiat, 'statut' = $statutSession
  //  3. si ETABLIE → usleep(DELAI_APRES_AUTH * 1e6)   ← la constante d'UC01 trouve enfin son usage
  //  4. verrou($mac, …) + finally libererVerrou()     ← couvre la lecture ET un éventuel rejeu
  //  5. requete(CHARGE_ETAT), puis — si le budget le permet encore — requete(CHARGE_INFO)
  //  6. statut = ETAT_LU si conceptsLisibles(…) est non vide, sinon ETAT_ILLISIBLE

public static function etatAppareil(array $_lecture)
  // → array('online' => true, 'source' => TRANSPORT_BROADLINK_LAN)
  //   + smartclimFrame::decoderEtat(TRANSPORT_BROADLINK_LAN, …)
  // ⚠️ 'online' n'est JAMAIS false ici : un LAN muet ne prouve pas qu'un appareil est hors ligne
  //   (VLAN, pare-feu, diffusion filtrée). Seul le cloud sait le dire.

public static function capacitesAppareil(array $_lecture)
  // → concepts     = array(CONCEPT_ONLINE) + smartclimFrame::conceptsLisibles(…)
  //   modes        = array()   ← VIDE, volontairement (§ 9, R1)
  //   vitesses     = array()   ← VIDE, volontairement (§ 9, R1)
  //   modes_exclus = array()
  //   temperature  = bornesParDefaut()
  //   source       = TRANSPORT_BROADLINK_LAN

private static function requete($_appareil, $_commande, $_charge, $_budget)
  // SIGNATURE FIGÉE par le § 7 de la spec technique UC01 — implémentée ici pour la première fois.
  // → charge HVAC nue, en hexadécimal minuscule. Lève une smartclimException typée.
  // 1 rejeu MAXIMUM par appel, par booléen local — jamais de récursion.
  //   Déclencheurs : silence dans le budget, ou code -7 / -4012 / -1.
  //   Avant rejeu : purgerSession($mac), puis authentifier() (qui repose INIT_KEY, id 0, compteur 0),
  //   puis usleep(DELAI_APRES_AUTH).
  // ⚠️ Appelle authentifier() et JAMAIS ouvrirSession() : le verrou est déjà tenu par lireEtat(),
  //   et flock() N'EST PAS RÉENTRANT entre deux descripteurs du même processus.

private static function sessionEnCache(array $_appareil)
  // → array|null. Extraction SANS VERROU du bloc de relecture/validation déjà présent dans
  //   ouvrirSession() : déchiffrement, forme du tableau, 'cle' en 32 caractères hex, empreinte.
  //   ouvrirSession() est refactorée pour l'appeler : comportement strictement identique.
  // 🚫 private, et sa valeur de retour NE SORT JAMAIS de la classe — elle porte la clé de session.

private static function authentifier(array $_appareil, $_budget)
  // Renvoie DÉSORMAIS le tableau de session qu'elle vient d'écrire (au lieu de void).
  // ouvrirSession() ignore ce retour ; requete() l'utilise après un rejeu. Corps inchangé.
```

**Décodage d'une réponse `0x6A` dans `requete()`, dans cet ordre** :

1. `echanger()` — longueur ≥ `0x38`, somme de paquet **bloquante**, écho de MAC **non transmis** (§ 7) ;
2. `codeErreur()` → si ≠ 0, `classerCodeAppareil()` ;
3. `strlen(substr($reponse, 0x38)) % 16 === 0`, sinon `TYPE_PROTOCOLE` ;
4. `dechiffrer(…, hex2bin($session['cle']))` — ⚠️ `hex2bin` obligatoire, la clé est stockée en hexa ;
5. `$longueur = uint16LE($clair[0..1])`, contrôlée (`>= 4` **et** `2 + $longueur <= strlen($clair)`) ;
6. charge HVAC = `substr($clair, 2, $longueur - 2)` → `bin2hex()`.

### 5.3 Compteur de paquet — **par processus, jamais persisté**

`self::$compteurPaquet` est initialisé au `compteur` de la session (à défaut `random_int(0, 0xFFFF)`),
incrémenté à chaque `requete()`, remis à 0 après une ré-authentification, et **jamais réécrit en cache**.

**Motif** : `python-broadlink` initialise `count` **aléatoirement** (`Device.__init__`) — l'appareil ne
contrôle donc aucune monotonie. Persister le compteur imposerait une **écriture de cache par requête**,
et `cache::set()` **réarmerait la TTL de 30 minutes** de la session à chaque lecture, ce qui fausserait
sa durée de vie réelle.

### 5.4 `smartclimCapabilities`

Ajout d'une section `TRANSPORT_BROADLINK_LAN` dans `tables()`, colonne **`fil` identique à `AUX_HOME`**
(mêmes octets de la même trame — démontré au § 3.3) : modes `AUTO 0 / COOL 1 / DRY 2 / HEAT 4 / FAN 6`,
vitesses `AUTO 5 / LOW 3 / MEDIUM 2 / HIGH 1 / TURBO 4`, et **`null` pour `SILENT` / `MEDIUM_LOW` /
`MEDIUM_HIGH`** — la règle « `'fil' => null` exclut au niveau du transport » s'applique à l'identique.

Colonne **`'intent' => null`** et `'intent_confirme' => false` : l'écriture est le sujet d'UC03. La valeur
sera très probablement égale à `fil` (même champ de la même trame), mais **rien ne l'établit** tant
qu'aucun ordre n'a été émis — `versTransport()` renverra donc `null`, ce qui est le comportement voulu.

### 5.5 `smartclim` — branchement

```php
const BUDGET_LECTURE_LAN = 8;   // budget PAR APPAREIL, sous le BUDGET_LAN global de 18 s

private static function appliquerLectureLan(smartclim $_eqLogic, array $_lecture)
  // try/catch (Throwable) interne — ne lève jamais. Dans l'ordre :
  //  1. appliquerCapacites(smartclimBroadlinkLan::capacitesAppareil($_lecture)) → save() si true
  //  2. appliquerEtat(smartclimBroadlinkLan::etatAppareil($_lecture))
  //     ⚠️ SANS second argument : $_optimiste reste false, donc filtrerEtatSelonOrdres() s'applique.
  //        La période de grâce de 60 s protège le LAN exactement comme elle protège le cron cloud —
  //        gratuitement, sans une ligne de plus.
```

`scannerReseauLocal()` — **structure de boucle et budgets inchangés** :

- construire l'index d'équipements **une seule fois** en tête via `indexerEquipements()`, et **l'utiliser
  aussi en phase 2** (qui refait aujourd'hui un `eqLogic::byType()`) → une requête SQL de **moins** ;
- remplacer `ouvrirSession($appareil, $budgetRestant)` par
  `lireEtat($appareil, max(1, min(self::BUDGET_LECTURE_LAN, $budgetRestant)))`, **dans les deux phases** ;
- compter le statut de session par `compterStatutLan()` (compteurs existants **intacts**) **et**
  incrémenter deux nouveaux compteurs `etats_lus` / `etats_illisibles` ;
- statut retenu pour la ligne du tableau **et** pour `memoriserSondeLan()` : le statut de **lecture**
  s'il existe, sinon celui de session ;
- phase 1 : rapprocher l'appareil par MAC via `chercherEquipementExistant($mac, '', $index)`, puis
  `appliquerLectureLan()` **si un équipement existe** (cf. § 8.2). Phase 2 : l'équipement est déjà en
  main. ⚠️ Rendre **neutre de transport** le littéral de log de MAC inversée de
  `chercherEquipementExistant()` — il dit « AUX Home », et il sert désormais aussi au LAN ;
- renvoyer en plus `profils` et `etatsConnexion` des équipements touchés, via les helpers existants.

`scannerClimatiseurs()` : `'profils' => array_replace($lan['profils'], $resultatCloud['profils'])`, idem
pour `etatsConnexion`. Le cloud passe **en dernier** et gagne en cas de collision — ce qui reflète l'ordre
réel d'exécution.

`appliquerEtat()` — **une ligne**. Le littéral figé

```php
checkAndUpdateCmd(self::CMD_TRANSPORT, smartclimCapabilities::libelleTransport(TRANSPORT_AUX_HOME))
```

devient

```php
… libelleTransport(isset($_etat['source']) ? $_etat['source'] : smartclimCapabilities::TRANSPORT_AUX_HOME)
```

Le repli préserve **exactement** le comportement des deux appelants qui ne fournissent pas `source`
(`basculerHorsLigne()` et l'état optimiste d'UC06). Vérifié : `source` **n'est pas un concept** — la
boucle de `conceptsConnus()` l'ignore, et `filtrerEtatSelonOrdres()` ne lit que les clés présentes dans
`memoireOrdres()`, jamais `source`.

---

## 6. Budgets de temps

| Niveau | Valeur | Portée |
|---|---|---|
| `BUDGET_LAN` | 18 s | **global**, arrêt dur du scan LAN — **inchangé** |
| `BUDGET_LECTURE_LAN` | 8 s | **par appareil** : session + `CHARGE_ETAT` + `CHARGE_INFO` + rejeu éventuel |

Le budget par appareil est **borné par le restant global** (`min(BUDGET_LECTURE_LAN, $budgetRestant)`),
avec un plancher de 1 s : un appareil lent ne peut donc pas consommer le budget des suivants, et le pire
cas total reste `BUDGET_LAN`.

Côté navigateur, le `timeout: 60000` posé en UC01 couvre le pire cas inchangé (18 s de LAN + 25 s de
cloud = 43 s) : **aucun ajustement JS nécessaire**.

---

## 7. Validation & erreurs

**Aucune entrée utilisateur nouvelle.** La double barrière `lan_ip` / `lan_mac` d'UC01 est inchangée.

Entrées **réseau** — même principe qu'en UC01 : *une réponse n'est ni authentifiée ni fiable*.

| Contrôle | Comportement | Motif |
|---|---|---|
| longueur ≥ `0x38`, somme de paquet `0x20-0x21` | **bloquant** (`echanger()`, existant) | déjà en place, la source de référence la vérifie |
| écho de MAC `0x2A-0x2F` sur `0x6A` | **non bloquant**, `debug` si divergent → `requete()` appelle `echanger()` **sans** `$_octetsMacAttendus` | `python-broadlink` ne le vérifie pas sur `0x6A`. Un contrôle bloquant **invérifiable** sur un chemin non recettable est un déni de service auto-infligé. L'anti-mélange est déjà assuré par le socket UDP **connecté** (filtrage noyau par adresse source) |
| somme de la charge HVAC (`0x34-0x35`, CRC interne) | **non bloquant**, `debug` | même raison : algorithme prouvé arithmétiquement, mais jamais observé sur du matériel |
| charge chiffrée non multiple de 16 | **bloquant**, `TYPE_PROTOCOLE` | `openssl_decrypt` + `OPENSSL_ZERO_PADDING` renverrait `false` sans diagnostic |
| champ `longueur` incohérent avec la charge reçue | **bloquant**, `TYPE_PROTOCOLE` | évite un `substr()` piloté par des données arbitraires |
| trame trop courte pour un concept | **concept absent** de l'état | mécanisme existant, **seul garant** de « aucune valeur inventée » |

Classement des exceptions — table `classerCodeAppareil()` d'UC01, **réutilisée, jamais dupliquée** :

| Cas | Type | Rejeu |
|---|---|---|
| silence dans le budget, socket impossible, codes `-3` / `-4000` | `TYPE_RESEAU` | 1 |
| codes `-1`, `-2`, `-7`, `-4012` | `TYPE_AUTH` | 1 |
| réponse tronquée, somme de paquet fausse, déchiffrement illisible, longueur incohérente, codes `-4` / `-2040` / `-4007`…`-4011` | `TYPE_PROTOCOLE` | **non** |
| aucune session en cache, verrou impossible, `openssl_*` en échec | `TYPE_INTERNE` | **non** |

`lireEtat()` **convertit tout cela en statut** : elle ne lève jamais, et n'émet **aucun `error`** pour une
non-réponse d'appareil — `error` reste réservé aux `Throwable` internes (§ 4.4 d'UC01).

**Aucun message d'erreur LAN curaté n'est introduit** : `messageErreurLan()` reste réservée à UC03.
L'utilisateur ne voit que des **statuts**.

### 7.1 Secrets

La clé de session **ne quitte jamais** `smartclimBroadlinkLan` : `sessionEnCache()` et `authentifier()`
sont `private`, leur retour ne sort pas de la classe. Elle n'est jamais journalisée (au plus sa longueur),
n'entre ni dans le DOM, ni dans une réponse AJAX, ni dans un statut. Les trames HVAC ne sont **ni
persistées ni journalisées** telles quelles — seuls des entiers de code, des longueurs et le premier octet
peuvent apparaître en `debug`, exactement comme côté cloud.

### 7.2 Ce qu'un échec de lecture **ne fait pas**

Il **ne touche aucune commande** de l'équipement — ni `online`, ni aucune autre. L'équipement conserve sa
dernière valeur connue, et « non rafraîchi » se lit dans le statut LAN de la ligne de scan **et** dans la
ligne « Réseau local » de l'état de connexion. C'est la lecture littérale de la spec fonctionnelle
(« l'équipement conserve alors sa dernière valeur connue »).

---

## 8. Périmètre — trois précisions issues de la revue du plan

### 8.1 Déclencheur : le scan manuel, **exclusivement** — arbitré le 2026-09-01

La spec fonctionnelle a été **amendée** en conséquence (§ Hors périmètre) : sa formulation initiale
(« au rafraîchissement périodique du socle MVP ou à la demande ») promettait un branchement sur `cron()`
que le § 9 de la spec technique UC01 **interdit** — une diffusion par minute, et une bagarre permanente
pour la **session unique** de l'appareil, dont l'authentification décroche le logiciel qui la détenait.
Brancher le LAN sur le cycle automatique reviendrait de plus à **arbitrer LAN contre cloud**, ce qui est
l'objet même du domaine `post-mvp/02`.

⚠️ `cron()` et `rafraichirMaintenant()` restent **cloud pur, strictement inchangés**. La part
« périodique » d'AC1 se recettera au domaine 02.

### 8.2 Rapprochement par MAC : ici oui, fusion des doublons : toujours UC04

Le § 0 d'UC01 renvoie « rapprocher un appareil LAN d'un équipement Jeedom, fusionner les doublons
LAN/cloud » à **UC04 de ce domaine**. Cette UC utilise pourtant `chercherEquipementExistant()` — il n'y a
pas d'autre moyen de savoir à quel `eqLogic` appliquer un état, donc pas d'autre moyen de satisfaire AC1.

La frontière, pour lever l'ambiguïté au moment d'UC04 :

| Ici (UC02) | Reste à UC04 |
|---|---|
| **Rapprochement** par MAC (et MAC inversée) d'un appareil LAN vers un `eqLogic` **déjà existant** | **Fusion des doublons** : deux `eqLogic` distincts (`lan:<mac>` et `mac:<mac>`, ou `auxhome:<deviceId>`) décrivant le même appareil |
| Aucune **création** d'`eqLogic` par la voie LAN | La création, la migration de `logicalId` et la déduplication |

### 8.3 Cas « LAN-only jamais scanné par le cloud » — zone d'ombre connue, pas un bug

Si un équipement n'a **jamais** été scanné côté cloud, son profil stocké porte `modes` et `vitesses`
vides. Le LAN rendant `mode` et `fan_speed` **lisibles**, ces concepts apparaîtront dans `concepts` — donc
des **commandes info** existeront — sans qu'aucune **commande d'action** de mode ou de vitesse ne soit
créée (`definitionsCommandesAction()` dérive du profil **stocké**, jamais du détecté-du-jour).

Ce n'est **pas** une violation d'AC5, qui interdit la sur-création (« aucune commande créée sans être
couverte par ce profil »), pas l'inverse. Et c'est cohérent avec UC02 : **lecture seule**. La situation se
résoudra d'elle-même au premier scan cloud, ou au domaine 02/03 selon la stratégie retenue. Consigné ici
pour ne pas être confondu plus tard avec une régression.

---

## 9. Risques

- **R1 — `modes` / `vitesses` vides dans le profil LAN : un garde-fou, pas une paresse.** Le LAN n'a
  **aucun équivalent de `feature.coolType`** : il ne peut rien exclure. S'il publiait le catalogue complet
  de son transport, l'**union** de `appliquerCapacites()` **réintroduirait `HEAT`** sur les unités
  froid-seul — exactement la régression corrigée le 2026-08-26 — dès qu'un scan LAN tourne sans qu'un scan
  cloud repasse derrière (compte non configuré, cloud en panne). Vérifié contre le code réel
  (`array_diff(array_merge(…), $modesExclus_de_CE_scan)`). UC02 étant en lecture seule, aucun catalogue
  d'action n'y est nécessaire.
  ⚠️ **Risque transmis à UC03** : le jour où le LAN publiera des modes, l'exclusion devra devenir
  **persistante dans le profil** (une preuve n'expire pas) plutôt que recalculée par transport.
- **R2 — décimale de l'ambiante non décodée** (réponse « info » octet 33 = charge HVAC octet 31, `/10`).
  **Décision : non.** Le décodeur étant partagé, l'activer modifierait aussi le chemin cloud **recetté**,
  sur une formule **jamais observée** dans une trame `running` réelle (l'appareil de recette renvoie
  `running = null`) : bénéfice nul sur la seule installation testable, régression possible ailleurs. La
  résolution reste 1 °C, ce qu'AC2 admet (« à la résolution annoncée près »). Réouverture = **une ligne**
  dans `smartclimFrame::champs()`, le jour où une trame longue réelle est observée.
- **R3 — un contrôle de conformité invérifiable est un risque, pas une sécurité.** D'où l'écho de MAC et
  les sommes de charge journalisés et non bloquants sur `0x6A`. Si la recette montre un appareil qui
  échoue **toutes** ses lectures, c'est le premier levier à regarder dans les logs `debug`.
- **R4 — deux prises de verrou par appareil** (`ouvrirSession()` puis `lireEtat()`), avec une fenêtre où
  un autre processus peut ré-authentifier et invalider notre session. **Assumé et bénin** : `lireEtat()`
  relit la session depuis le cache **sous son propre verrou** (`sessionEnCache()`), donc obtient toujours
  la version la plus fraîche, jamais une session périmée figée en mémoire locale ; et le rejeu réactif
  (`-7` / `-4012`) referme le cas de lui-même. Un verrou tenu à travers `ouvrirSession()` exigerait de
  démonter une méthode déjà livrée.
- **R5 — AC1 mentionne « oscillations, options de confort » que le modèle générique ne porte pas**
  (domaine `post-mvp/04`). La trame les contient pourtant — charge HVAC : oscillations `o[10]` / `o[11]`,
  silence et turbo `o[14]` bits 7/6, veille `o[15]` bit 2, santé et nettoyage `o[18]`, afficheur et
  anti-moisissure `o[20]`. **Rien à coder ici** ; offsets à consigner dans l'analyse pour le domaine 04.
  Corollaire **hérité d'UC05 du MVP, non aggravé** : un appareil en vitesse « Silencieux » affichera la
  vitesse portée par le code `fil`, le bit `mute` n'étant pas lu — même comportement que le cloud
  aujourd'hui.
- **R6 — `source` du profil et commande `transport` alternent** sur un appareil vu par les deux voies : le
  scan LAN pose « Broadlink LAN », le scan cloud qui suit repose « AUX Home ». Un `save()` de plus par
  équipement et par scan **manuel** (jamais en cron). C'est honnête — `source` signifie « qui a détecté en
  dernier » — et le domaine 02 est le bon endroit pour en faire « transport actif ».
- **R7 — auto-guérison de la mémoire de sonde** (dette 4 d'UC01, `adresseLan()` renvoyant
  `source: 'aucun'`) non traitée ici : en UC02, la phase 1 (diffusion) réalimente la mémoire à chaque
  scan, ce qui suffit au chemin manuel retenu. À rouvrir au domaine 02, où la lecture LAN devient
  automatique.
- **R8 — licence.** `ac_freedom` est **sans licence** : charges magiques et offsets doivent être **écrits
  depuis le contrat documenté au § 3**, jamais recopiés depuis ce dépôt. L'attribution MIT en tête de
  `smartclimBroadlinkLan.class.php` couvre `python-broadlink` et **ne doit pas** être étendue.
- **R9 — rappel mécanique.** `smartclimFrame` est inutilisable sans sa ligne dans
  `core/php/smartclim.inc.php`, et l'oubli ne casse **ni `php -l`, ni la CI, ni `verif-plugin.py`**.
  Lancer `python .claude/scripts/verif-plugin.py` avant commit : les nouveaux docblocks parlent de trames
  et d'offsets, terrain propice à une séquence de fermeture de commentaire collée au texte.

---

## 10. Recette — ce qui est vérifiable, et ce qui ne l'est pas

⚠️ **AC1 à AC5 ne sont pas recettables** : l'appareil de validation de l'utilisateur ignore le protocole
Broadlink. Le code est vérifié **contre `mjg59/python-broadlink`**, jamais contre du matériel.

**Ce qui est réellement observable sur l'installation actuelle — et qui est le vrai test de cette UC** :

1. Un scan sans appareil Broadlink produit un **tableau LAN vide** et **aucun log `error`**.
2. Les **résultats cloud sont strictement identiques à avant** : mêmes états, mêmes profils, mêmes
   commandes, même `transport` affiché. C'est le test de non-régression de l'extraction de
   `smartclimFrame` — le seul enjeu réel de cette UC sur du matériel disponible.
3. Aucune apparition de `Class 'smartclimFrame' not found` dans les logs (garde § 1.2).

**Instrumentation** — suite directe de `D-POSTMVP0101-01` : chaque première réponse `0x6A` journalise en
`debug` la commande écho `0x26-0x27`, la longueur de charge déchiffrée, le premier octet de la charge HVAC
(attendu `0xBB` — **log seulement, jamais un rejet**), la divergence éventuelle de la somme `0x34-0x35` et
l'écho de MAC. C'est ce qui rendra la recette possible le jour où un appareil Broadlink sera disponible.

---

## 11. Dépendances

**Aucune.** PHP pur : `openssl_*` et opérations de bits, déjà utilisés en UC01. Aucun démon, aucun paquet
`pip`, `plugin_info/packages.json` reste vide, `hasDependency` et `hasOwnDeamon` restent à `false`.

---

## 12. Impact i18n (français, langue source)

Trois chaînes littérales, toutes dans `core/class/smartclim.class.php` :

| Chaîne | Emplacement |
|---|---|
| `État lu sur le réseau local` | `libelleStatutLan()` — `STATUT_ETAT_LU` |
| `LAN disponible — état non décodable par cet appareil` | `libelleStatutLan()` — `STATUT_ETAT_ILLISIBLE` |
| `%d état(s) lu(s) sur le réseau local` | `resumeScanLan()` — `sprintf` **après** `__()` |

`libelleTransport(TRANSPORT_BROADLINK_LAN)` → `'Broadlink LAN'` existe déjà et reste **sans `__()`** (nom
de marque). **Aucun libellé n'est introduit dans `smartclimFrame`** : la classe est muette côté UI, ses
seuls textes sont des logs, qui ne se traduisent pas. Aucun nouveau libellé de concept, de mode ou de
vitesse — la table `BROADLINK_LAN` réutilise les clés françaises littérales déjà présentes dans `tables()`.

Traduction `en_US` / `de_DE` / `es_ES` : **en fin de cycle**, par le sous-agent `translator`, sur le code
figé.

---

## 13. Dette

Reviews croisées du 2026-09-02 — **`security-reviewer` : aucun `critical`/`high`/`medium`** ;
**`code-reviewer` : `pass`, aucun `blocker`/`major`/`minor`**. Le point le plus risqué du cycle a été
vérifié explicitement : l'extraction de `smartclimFrame` est **verbatim**, contrôlée offset par offset
contre le tableau du § 3.3 (`10/12`, `13`, `15`, `18`, ambiante `15`, bornes `-20/+60`, cast `(float)`),
et `smartclimAuxHomeApi` est devenue une pure délégation — le chemin cloud recetté ne bouge pas.

Deux findings `low` ont été **corrigés en passe de finition**, sans review de contrôle : la garde
explicite « longueur multiple de 16 » ajoutée dans `authentifier()` par cohérence avec `requete()`
(l'incohérence aurait été recopiée par UC03), et le log de code d'erreur appareil restreint au cas non
nul dans `requete()` (il s'émettait à chaque requête, y compris en succès).

**Reste en dette — rien de bloquant** :

| # | Dette | Où | Quand la traiter |
|---|---|---|---|
| **D-1** | Le plancher `max(1, …)` du budget par appareil peut faire **légèrement dépasser** `BUDGET_LECTURE_LAN` dans le pire cas. Effet cumulé borné par la garde globale de `scannerReseauLocal()`, vérifiée avant chaque appareil dans les deux phases. Mécanisme **explicitement prescrit** au § 6, hérité d'UC01. | `smartclimBroadlinkLan::requete()` | domaine `post-mvp/02`, quand la lecture LAN devient automatique et que le budget cesse d'être celui d'un scan manuel |
| **D-2** | Les deux « À confirmer » de la spec fonctionnelle restent **non mesurés sur matériel** (bit de demi-degré, décimale de l'ambiante) — la contradiction *documentaire* est levée (§ 3.3), pas la vérification physique. | `smartclimFrame::champs()` | à la première recette sur un appareil Broadlink réel |
| **D-3** | Cas « LAN-only jamais scanné par le cloud » : commandes info de mode/vitesse sans commande d'action correspondante (§ 8.3). Non-violation d'AC5, consigné pour ne pas être pris pour une régression. | profil de capacités | domaine `post-mvp/02` ou `03` selon la stratégie retenue |
| **D-4** | Offsets des **oscillations et options de confort** identifiés mais non décodés (§ 9, R5) — le modèle générique ne porte aucun concept correspondant. | `smartclimFrame` | domaine `post-mvp/04-fonctions-avancees` |
| **D-5** | Auto-guérison de la mémoire de sonde LAN (dette 4 d'UC01) toujours non traitée (§ 9, R7). | `smartclim::adresseLan()` | domaine `post-mvp/02` |
