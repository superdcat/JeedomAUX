# Spec technique — UC07 du MVP : rafraîchissement périodique et rafraîchissement manuel

> Spec fonctionnelle : `.memory/specs/MVP/07-rafraichissement-cron.md`
> Dépend de : UC01 (clé `refresh_interval`), UC03 (`listerAppareils()`, `auxhome_device_id`),
> UC04 (`smartclimCapabilities`), UC05 (`appliquerEtat()`, commandes info), UC06 (période de grâce,
> `definitionsCommandesAction()`, `executerCommandeAction()`).
> Arbitrages automatiques du cycle : `.memory/auto-dev/run-20260826-1904/MVP-07/decisions.md`
> (D-MVP07-01 à D-MVP07-04).

## 1. Périmètre

UC07 **cadence** un appel réseau qui existe déjà et **distribue** son résultat. Elle n'ajoute :

- **aucune classe** (donc **aucun** `require_once` à ajouter dans `core/php/smartclim.inc.php`) ;
- **aucun endpoint** (ni côté cloud, ni côté `core/ajax/smartclim.ajax.php`) ;
- **aucune dépendance**, **aucun démon** ;
- **aucune clé de configuration** nouvelle.

Elle ajoute une commande d'action générique (`refresh`), quatre constantes et six méthodes dans
`smartclim`, et retire un champ mort de la page équipement.

Explicitement **hors périmètre** (renvoyés à UC08 par la spec fonctionnelle) : stratégie de reprise après
incident prolongé, backoff après échecs répétés, expiration de session, commande info détaillée d'état de
connexion. Également hors périmètre : la découverte de nouveaux appareils en cours de cycle (le scan reste
manuel, UC03) et toute modification du profil de capacités (cf. § 6).

## 2. Ce qui est déjà acquis et ne doit PAS être réécrit

| Besoin d'UC07 | Déjà fourni par | Conséquence |
|---|---|---|
| **AC5** — anti-rollback après commande | UC06 : cache `smartclim::ordres::<id>` (`CLE_CACHE_ORDRES`, `DUREE_GRACE` = 60 s), consommé par `filtrerEtatSelonOrdres()` **à l'intérieur** de `appliquerEtat()` sous la garde `if (!$_optimiste)` | Le cycle appelle `appliquerEtat($etat)` **sans second argument** → `$_optimiste = false` → la grâce s'applique. **Zéro ligne à écrire pour AC5.** |
| **AC3** — un seul appel réseau par cycle | UC03 : `smartclimAuxHomeApi::listerAppareils()` (`GET /app/user_device?getStatus=1`) renvoie **tous** les appareils du compte avec leur état, normalisés en clés génériques françaises | Un seul appel, puis distribution. `smartclimAuxHomeApi::etatAppareil(array $_appareil)` est du **décodage pur, sans E/S** : elle ne lit que les clés `trame_controle`, `trame_running`, `enLigne` de la ligne qu'on lui passe. |
| **AC7** — conservation des dernières valeurs | UC05 : `appliquerEtat()` ne pousse que les **clés présentes** dans le tableau d'état | Pousser `array(CONCEPT_ONLINE => false)` ne touche **aucune** autre commande. |
| Re-login réactif si la session a expiré | UC03 : `listerAppareils()` borne le rejeu à **une** tentative, dans le budget global `BUDGET_SCAN` | UC07 n'écrit **aucune** stratégie de session. |
| Intervalle réglable | UC01 : `refresh_interval` (1..1440, défaut 5), double barrière `preConfig_refresh_interval()` / `smartclim::intervalleRafraichissement()` | Lecture par l'accesseur **uniquement**, jamais `config::byKey` en direct. |

⚠️ **Vérification faite sur le code réel pendant la revue de plan** : `filtrerEtatSelonOrdres()` n'agit que
sur les concepts présents **à la fois** dans l'état reçu et dans la mémoire des ordres. Or cette mémoire
n'est alimentée que par `enregistrerOrdre()`, appelée uniquement pour des concepts **commandables**
(`power`, `target_temp`, `mode`, `fan_speed`) — **jamais** `online`. Un passage hors ligne ne peut donc pas
atteindre le `cache::delete(CLE_CACHE_ORDRES...)` de `filtrerEtatSelonOrdres()` : la période de grâce
survit à une coupure réseau.

## 3. Architecture — fichiers touchés

| Fichier | État | Contenu | Indentation |
|---|---|---|---|
| `core/class/smartclim.class.php` | **modifié** | 4 constantes ; corps de `cron()` ; `cycleEchu()`, `marquerCycle()`, `rafraichirAuxHome()`, `equipementsParIdentifiant()`, `basculerHorsLigne()`, `rafraichirMaintenant()` ; entrée `refresh` dans `definitionsCommandesAction()` ; branche `refresh` dans `executerCommandeAction()` ; micro-correctif du log `infoLiee` vide dans `creerCommandesAction()` | **2 espaces**, **CRLF** |
| `desktop/php/smartclim.php` | **modifié** | suppression du bloc « Auto-actualisation » (case à cocher + expression cron liées à `configuration.autorefresh`, commentaires inclus). **Rien d'autre.** Le champ `param1` n'est pas touché | **tabulations**, **CRLF** |
| `core/php/smartclim.inc.php` | inchangé | aucune classe nouvelle. `smartclimFrame` reste **ajournée** (arbitrage UC05) — ne pas la (re)proposer | — |
| `core/class/smartclimAuxHomeApi.class.php` | inchangé | `listerAppareils()` et `etatAppareil()` couvrent le besoin tels quels | — |
| `core/config/smartclim.config.ini`, `plugin_info/*`, `core/ajax/*`, `desktop/js/*`, `core/template/*` | inchangés | — | — |
| `core/i18n/*.json` | inchangés à ce stade | traduction déléguée à l'étape `translator` en fin de cycle | — |

## 4. Server vs Client

**100 % serveur.** Aucune ligne de JavaScript n'est écrite.

- Le **cycle automatique** est déclenché par le cron du core (processus PHP serveur, aucun navigateur).
- Le **rafraîchissement manuel** est une commande d'action Jeedom : le navigateur appelle
  `core/ajax/cmd.ajax.php` **du core**, qui vérifie les droits, puis `cmd::execCmd()` →
  `smartclimCmd::execute()` → `smartclim::executerCommandeAction()`. Aucun endpoint AJAX de plugin n'est
  ajouté : ce serait une surface d'attaque de plus pour un gain nul.
- Le widget `smartclim::etat` (UC06) est posé sur les commandes `action`/`other`. Il déduit sa valeur
  attendue du `logicalId` ; pour `refresh` il n'en trouve aucune et sort immédiatement → bouton neutre,
  jamais en surbrillance. Comportement attendu et sans risque.

## 5. Cadencement du cycle (D-MVP07-01)

### Constantes ajoutées (après `DUREE_GRACE`)

```php
const CLE_CACHE_DERNIER_CYCLE = 'smartclim::dernier_cycle';
const DUREE_MEMOIRE_CYCLE = self::INTERVALLE_MAX * 60 * 2;  // 48 h, > intervalle max
const MARGE_ECHEANCE_CYCLE = 30;                            // secondes
const CMD_RAFRAICHIR = 'refresh';                           // logicalId générique
```

### Hook

`smartclim::cron()` **seul** (appelé chaque minute par le core). `cron5()`, `cron10()`, `cron15()`,
`cron30()`, `cronHourly()`, `cronDaily()` restent **commentées**, donc **inexistantes** — et non pas
« vides ». La nuance compte : le core ne crée un interrupteur `functionality::cron<N>::enable` que pour les
hooks qu'il trouve par introspection, une méthode absente n'expose donc aucun réglage désynchronisable
(cf. D-MVP07-01).

```php
public static function cron() {
  try {
    if (!self::cycleEchu()) { return; }
    self::rafraichirAuxHome();
  } catch (Throwable $t) {
    log::add('smartclim', 'error', ...);
  }
}
```

⚠️ **Ce `try/catch` n'est pas redondant avec celui de `plugin::cron()`.** Le core, lui, journalise via
`log::exception()`, qui imprime la **trace de pile** — or une trace née dans la brique de transport peut
porter le jeton de session en argument de frame. C'est une garde de **sécurité**, pas de confort. Ne pas
la retirer, ne pas y appeler `getTraceAsString()`.

### Garde d'échéance

`cycleEchu()` → `bool`, lit `cache::byKey(CLE_CACHE_DERNIER_CYCLE)->getValue(null)` :

| Cas | Retour |
|---|---|
| valeur non numérique (jamais tourné, cache purgé) | `true` |
| `time() - $dernier < 0` (**horloge reculée** : Jeedom sans RTC, resynchro NTP au démarrage) | `true` |
| sinon | `(time() - $dernier) >= self::intervalleRafraichissement() * 60 - self::MARGE_ECHEANCE_CYCLE` |

`marquerCycle()` écrit `cache::set(CLE_CACHE_DERNIER_CYCLE, (string) time(), DUREE_MEMOIRE_CYCLE)` — valeur
stockée en **chaîne** et relue avec `is_numeric`, même prudence que `memoireOrdres()` vis-à-vis du moteur
de cache.

**Marge de 30 s** : les ticks de cron ne sont pas espacés d'exactement 60 s. Sans marge, un intervalle de
N minutes dégénère en N+1 dès qu'un tick arrive 59,4 s après le précédent, et AC8 tombe une fois sur deux.
Comme `cron()` ne peut pas s'exécuter deux fois dans la même minute, une marge de 30 s ne peut pas produire
de double cycle.

**Changement d'intervalle en cours de route** : l'intervalle est **relu à chaque tick**. Une baisse comme
une hausse s'appliquent dès le tick suivant. **Aucun hook `postConfig_refresh_interval` n'est écrit** : il
serait inutile et forcerait un cycle parasite à chaque enregistrement du formulaire de configuration.

## 6. Le cycle — `rafraichirAuxHome()`

```php
private static function rafraichirAuxHome()
```

→ `array{lance: bool, appareils: int, rafraichis: int, horsLigne: int, erreurs: int,
echecType: int|null, echecContexte: string}`. **Ne lève JAMAIS.**

⚠️ **`private`, pas `public`** : les deux seuls appelants (`cron()` et `rafraichirMaintenant()`) sont dans
la classe. Aucun point d'entrée externe ne doit pouvoir déclencher un cycle sans passer par l'un des deux.

⚠️ **Le contrat « ne lève jamais » est tenu par un `try/catch (Throwable)` GLOBAL** enveloppant tout le
corps de la méthode, l'initialisation de `$resultat` restant en dehors (correctif de fin de cycle, finding
relevé indépendamment par les deux reviewers). Les `try/catch` internes des étapes 5 et 6 ci-dessous ne
couvrent que l'appel réseau et la distribution : sans le filet global, un incident de la couche ORM
(`eqLogic::byType()`, `equipementsParIdentifiant()`) traversait la méthode — absorbé par `cron()` sur le
chemin automatique, mais remontant **brut** au core sur le chemin manuel, hors du chemin de curation de
message. Le `catch` global journalise, pose `echecType = TYPE_INTERNE` et renvoie `$resultat` ; il ne
bascule **aucun** équipement hors ligne (une erreur interne ne dit rien de la joignabilité des appareils,
et l'index des cibles peut ne même pas avoir été construit). Poser `echecType` n'est pas cosmétique :
sans lui, `rafraichirMaintenant()` ne lèverait pas et l'utilisateur ayant cliqué « Rafraîchir » recevrait
un **succès silencieux** alors que rien ne s'est produit.

Séquence, dans cet ordre exact :

1. `if (!self::compteConfigure()) { log debug ; return ; }` — **zéro requête réseau**, et **aucun marqueur
   posé** : dès que l'utilisateur configure son compte, le tick suivant lance un cycle sans attendre un
   intervalle complet.
2. `self::marquerCycle();` — **avant** l'appel réseau (D-MVP07-02). Sinon un cloud en panne serait
   re-sollicité **chaque minute**, jusqu'à 25 s de budget brûlées dans le processus `plugin::cron`, qui
   exécute séquentiellement les crons de *tous* les plugins.
3. `$cibles = self::equipementsParIdentifiant(eqLogic::byType('smartclim', true));` — le `true` filtre les
   équipements **activés** en SQL.
4. `if (empty($cibles)) { return ; }` — **zéro requête réseau**.
5. `try { $appareils = smartclimAuxHomeApi::listerAppareils(); }`
   `catch (smartclimException $e) { ... }` `catch (Throwable $t) { ... }` — en cas d'échec **global** :
   journalisation, mémorisation de `echecType`/`echecContexte`, puis
   `basculerHorsLigne($cibles, ...)` sur **tous** les équipements ciblés, et sortie (AC7).
6. **Distribution** : pour chaque ligne d'appareil, `$cibles[$identifiant]` ; absent → **ignoré
   silencieusement** (la découverte reste manuelle). Pour chaque équipement du groupe :
   `try { $eqLogic->appliquerEtat(smartclimAuxHomeApi::etatAppareil($appareil)); $rafraichis++; }`
   `catch (Throwable $t) { $erreurs++; log error; }` — **la boucle continue** (AC4). Puis
   `unset($cibles[$identifiant])`.
7. `basculerHorsLigne($cibles, 'appareil absent de la réponse du compte AUX Home')` sur ce qui **reste**
   dans l'index : les équipements Jeedom dont l'appareil n'est plus renvoyé par le compte (AC4). **Jamais
   de suppression, jamais de désactivation d'équipement.**
8. `log debug` de résumé (compteurs).

⚠️ **Le cycle ne touche JAMAIS les capacités.** La réponse contient pourtant les trames et
`capacites_brutes` : la tentation est là, elle est refusée. `appliquerCapacites()` impliquerait un `save()`
→ `postSave()` → écriture SQL potentielle **à chaque cycle**, et `CLAUDE.md` fixe le **scan** comme vecteur
de migration du parc. Le cycle est **lecture d'état seulement**.

### Rapprochement appareil ↔ équipement

`equipementsParIdentifiant(array $_equipements)` → `array<string, smartclim[]>`.

- Clé : `configuration.auxhome_device_id` — **et uniquement elle**. `chercherEquipementExistant()` (MAC /
  MAC inversée) n'est **pas** réutilisée : elle journalise un `warning` à chaque rapprochement par MAC
  inversée, acceptable une fois par scan, insupportable 288 fois par jour.
- Un équipement sans `auxhome_device_id` est **ignoré par le cycle** : ni rafraîchi, ni basculé hors ligne.
  Il n'est pas un appareil AUX Home.
- La valeur est une **liste** (`smartclim[]`), pas un objet unique : un équipement dupliqué dans Jeedom
  (bouton « Dupliquer ») partage le même `auxhome_device_id` ; un index scalaire en ferait disparaître un
  des deux, silencieusement.
- Filtre `instanceof smartclim` en plus du type SQL.

### Bascule hors ligne

`basculerHorsLigne(array $_groupes, $_motif)` → `int`. Par équipement, dans un `try/catch (Throwable)` :

```php
if ($eqLogic->appliquerEtat(array(smartclimCapabilities::CONCEPT_ONLINE => false))) {
  log::add('smartclim', 'warning', ... . $_motif);
}
```

- Le booléen de retour d'`appliquerEtat()` (« au moins un concept a changé ») fait que le warning n'est
  écrit **qu'à la transition** : une panne longue ne produit pas un warning par cycle.
- `$_motif` est un fragment de log **français non traduit** (les `log::add` du plugin ne passent pas par
  `__()` — convention du dépôt).
- **AC7 est tenu par le mécanisme d'UC05** : seule la clé `online` étant présente dans le tableau, aucune
  autre commande n'est écrite, aucune `valueDate` n'est touchée.

⚠️ **`setStatus()` n'est PAS utilisé**, et ce n'est pas un oubli. Trois raisons : (1) `cmd::event()` force
`timeout => 0` sur l'équipement **à chaque** poussée de valeur — le badge « timeout » du core ne peut donc
structurellement pas signaler notre état hors ligne, puisqu'on pousse `online = 0` ; (2) `checkAlive()` du
core est propriétaire de `timeout`, le recalcule depuis `lastCommunication` et publie ses propres messages
core — un écrit plugin serait écrasé et polluerait la boîte à messages ; (3) `warning`/`danger`
appartiennent au calcul de niveau d'alerte des commandes. La commande info `online` (subType `binary`,
`generic_type` `ONLINE`, créée depuis UC05) est le **seul** porteur de l'état de joignabilité.
Ces trois affirmations portent sur des internes du core non vérifiables depuis ce dépôt : **à confirmer en
recette**, sans que l'architecture retenue en dépende.

## 7. Rafraîchissement manuel (AC6) — D-MVP07-03

### Définition de la commande

Dans `definitionsCommandesAction()`, **en fin de méthode** (après la boucle des vitesses, avant le
`return`), entrée **inconditionnelle** — hors profil de capacités, comme les commandes méta d'UC05
(rafraîchir a du sens même sur un équipement au profil vide) :

```php
$definitions[self::CMD_RAFRAICHIR] = array(
  'name'     => __('Rafraîchir', __FILE__),
  'subType'  => 'other',
  'infoLiee' => '',
  'ordre'    => array(),
  'ordreCmd' => 40,
);
```

`ordreCmd = 40` est supérieur au maximum atteignable par les boucles existantes (13 + 5 modes + 8 vitesses
= 26) : le bouton se place **après** les autres actions. Cette table étant **aussi** la table
d'autorisation d'`executerCommandeAction()`, l'ajout rend la commande exécutable sans autre garde.

### Chemin d'exécution

Dans `executerCommandeAction()`, **juste après** `$definition = $definitions[$_logicalId];` et **avant** la
construction de l'ordre :

```php
if ($_logicalId === self::CMD_RAFRAICHIR) {
  $this->rafraichirMaintenant();
  return;
}
```

Ce placement est structurant : il sort **avant** le calcul d'empreinte de déduplication, **avant** le
`cache::set` du marqueur de dédup et **avant** `appliquerOrdre()`.

⚠️ **Pas de déduplication sur « Rafraîchir »** : la dédup d'UC06 (10 s) existe pour éviter le double **bip**
d'un ordre matériel. Un rafraîchissement est en lecture seule et AC6 exige une mise à jour **immédiate** ;
avaler silencieusement un second clic contredirait le critère.

Les trois gardes amont sont **conservées** et pertinentes telles quelles :

- `session_write_close()` — l'appel réseau peut durer jusqu'à 25 s ; sans elle, la session **fichier** de
  Jeedom fige toute l'interface ;
- `compteConfigure()` ;
- `auxhome_device_id` non vide — « relancez un scan » est le bon message si l'on clique « Rafraîchir » sur
  un équipement non relié.

### Méthode d'instance

```php
public function rafraichirMaintenant()
```

Appelle `self::rafraichirAuxHome()` ; si `echecType !== null`, lève
`new smartclimException(self::messageErreurAuxHome($echecType, $echecContexte), $echecType)`.

⚠️ **C'est le seul point de bascule « message technique → message curaté » de cette UC.** Le message d'une
exception née dans la brique de transport n'est **jamais** affiché ; `messageErreurAuxHome()` produit le
message français destiné à l'utilisateur (mêmes littéraux qu'UC02/UC03/UC06, **aucune clé i18n nouvelle**).
L'exception curatée remonte ensuite à `cmd::execCmd()`.

**Portée** : le rafraîchissement manuel exécute le **cycle global** et rafraîchit donc **tous** les
équipements, pas seulement celui sur lequel on a cliqué. Il **ré-ancre aussi l'échéance** du cron (il passe
par `marquerCycle()`), donc le cycle automatique suivant est repoussé d'un intervalle complet. Ce n'est pas
une anomalie de recette : c'est la conséquence mécanique du fait que le cloud ne sait renvoyer que la liste
complète.

## 8. Concurrence et verrous (D-MVP07-02)

**Aucun verrou n'est posé.** Le cycle n'écrit aucun `eqLogic` (contrairement au scan d'UC03, dont le verrou
`CLE_CACHE_VERROU_SCAN` existe parce qu'il **crée** des équipements). Deux lectures concurrentes sont
idempotentes : mêmes valeurs poussées, donc aucun événement émis.

La sérialisation utile est déjà obtenue par l'ordre de la séquence : `marquerCycle()` étant appelé **avant**
l'appel réseau, un rafraîchissement manuel repousse l'échéance du cron dès son démarrage — le sens « manuel
démarré, puis tick de cron » est donc fermé. Le sens inverse (cron en cours, l'utilisateur clique) reste
possible et est assumé : c'est une action volontaire.

Un verrou global aurait fait échouer ou avaler un « Rafraîchir » cliqué pendant un cycle cron, ce qui
contredit AC6 — le critère prime sur l'économie d'une requête GET redondante.

## 9. Validation et erreurs

| Situation | Où c'est traité | Comportement |
|---|---|---|
| Compte non configuré | `rafraichirAuxHome()` étape 1, **avant tout réseau** | sortie silencieuse + `log debug` ; aucun marqueur posé |
| Aucun équipement activé / aucun avec `auxhome_device_id` | étape 4, **avant tout réseau** | sortie ; marqueur déjà posé, la vérification (1 SELECT) n'est repayée qu'au prochain intervalle |
| Équipement désactivé | `eqLogic::byType('smartclim', true)` + double filet du core (`checkAndUpdateCmd()` renvoie `false` si l'équipement est désactivé) | jamais rafraîchi, jamais basculé hors ligne |
| Échec **global** de l'appel réseau | étape 5 | `warning` si `TYPE_RESEAU` (transitoire, attendu — évite d'inonder le journal pendant une coupure), `error` sinon (`AUTH`/`PROTOCOLE`/`INTERNE`, actionnable). Message **technique**, jamais affiché. Puis bascule hors ligne de tous les équipements ciblés |
| Échec **par équipement** | étape 6 | `catch (Throwable)` (une `Error` PHP 8 ne doit pas traverser), compteur, `log error`, **la boucle continue** (AC4) |
| Nom d'équipement en log | partout | `self::neutraliserPourLog($eqLogic->getHumanName())` — le nom est une entrée utilisateur renommable (anti-injection de log) |
| Message d'exception en log | étape 6 | `get_class($t)` + `neutraliserPourLog($t->getMessage())` |
| Appareil du cloud inconnu de Jeedom | étape 6 | ignoré silencieusement (découverte hors périmètre) |
| Commande info absente (équipement au profil vide) | `checkAndUpdateCmd()` | renvoie `false` sans lever : aucune erreur, aucune valeur affichée |
| Rafraîchissement manuel en échec | `rafraichirMaintenant()` | `smartclimException` **curatée** par `messageErreurAuxHome()` |
| Secrets | tout le cycle | aucun jeton, e-mail ou mot de passe dans un log, un compteur ou une exception ; les trames HVAC ne sont ni journalisées ni persistées. Le `catch (Throwable)` de `cron()` empêche le `log::exception()` du core d'imprimer une trace de pile issue de la brique de transport |

## 10. Contrats externes

**Aucun nouvel appel réseau.** UC07 ne fait que cadencer `GET /app/user_device?getStatus=1`, déjà
implémenté par `smartclimAuxHomeApi::listerAppareils()` (UC03) : query string littérale constante, jeton
en en-tête, vérification `code == 200` puis `data` tableau, budget global `BUDGET_SCAN` login compris,
re-login réactif borné à **un** rejeu.

## 11. Dépendances

**Aucune.** `plugin_info/packages.json` reste vide, `hasDependency` et `hasOwnDeamon` restent à `false`.
PHP natif uniquement.

## 12. Impact i18n (français, langue source)

| Chaîne | Fichier | Contexte |
|---|---|---|
| `Rafraîchir` | `core/class/smartclim.class.php` | `__('Rafraîchir', __FILE__)` dans `definitionsCommandesAction()` — littérale, **jamais** une variable |

C'est la **seule** chaîne nouvelle. « En ligne » / « Hors ligne », anticipées par la spec fonctionnelle,
existent déjà (`smartclim::libelleEnLigne()`) ; l'état hors ligne d'UC07 est porté par la commande binaire
`online`, rendue par le core. Les messages `log::add` sont en français **non traduits** (convention du
dépôt).

La suppression du bloc « Auto-actualisation » rend **orphelines** 3 clés existantes de
`core/i18n/*.json` : « Auto-actualisation », « Fréquence de rafraîchissement des commandes infos de
l'équipement », « Cliquer sur ? pour afficher l'assistant cron ». Sans effet fonctionnel.

## 13. Couverture des critères d'acceptation

| AC | Réalisation | Statut |
|---|---|---|
| AC1 — action à la télécommande IR répercutée au cycle suivant | `rafraichirAuxHome()` → `etatAppareil()` → `appliquerEtat()` | mécanisme en place — **à valider en recette** (cf. R1) |
| AC2 — changement fait dans l'application AUX Home répercuté | même chemin | mécanisme en place — **à valider en recette** |
| AC3 — un seul appel réseau par cycle | **un** `listerAppareils()` puis distribution ; `etatAppareil()` sans E/S | couvert par construction |
| AC4 — un équipement en erreur / retiré n'empêche pas les autres | `try/catch` par équipement + `basculerHorsLigne()` sur les absents | couvert |
| AC5 — anti-rollback dans la minute suivant une commande | **UC06**, sans une ligne nouvelle | couvert par l'existant |
| AC6 — commande « Rafraîchir » par équipement | `refresh` + `rafraichirMaintenant()` | couvert (portée élargie, cf. D-MVP07-03) |
| AC7 — cloud indisponible : tous hors ligne, valeurs conservées | `basculerHorsLigne()` ne pousse que `online` | couvert |
| AC8 — intervalle à 1 min, rafraîchissement ~ chaque minute | `cron()` + garde d'échéance avec marge de 30 s | couvert — **à valider en recette** |

## 14. Risques

1. **R1 — AC1/AC2 dépendent d'un contrat tiers non garanti.** La trame `control` est documentée comme le
   dernier état **commandé** ; si le cloud ne l'actualise pas après une action à la télécommande IR, AC1
   échoue **sans que le cycle soit en cause**. Correctif connu et localisé : déplacer le concept concerné
   vers `'running'` dans `smartclimAuxHomeApi::champsEtatAuxHome()` — les longueurs minimales en sont
   dérivées automatiquement, c'est **une ligne**. À trancher en recette, puis mettre à jour
   `.memory/analyse/smartclim-transport-aux-home.md`.
2. **R2 — Occupation du cron du core.** `plugin::cron()` exécute les `cron()` de **tous** les plugins
   séquentiellement dans un même processus. Pire cas borné à `BUDGET_SCAN` (25 s) ; cas nominal, session
   en cache : 1 à 3 s.
3. **R3 — Pas de backoff en cas de panne prolongée.** Avec `refresh_interval = 1`, un cloud qui pend coûte
   jusqu'à 25 s par minute. **Volontairement non traité** : renvoyé à UC08 par la spec fonctionnelle.
4. **R4 — Coût SQL par cycle.** `appliquerEtat()` appelle `creerCommandesInfo()` + `creerCommandesAction()`
   (2 lectures `getCmd(null, null)`, non mémoïsées) puis `checkAndUpdateCmd()` par concept : ~10 SELECT par
   équipement et par cycle. Hérité d'UC05/UC06, négligeable à l'échelle d'un Jeedom. Optimisation
   disponible sans changer de conception : `checkAndUpdateCmd()` accepte un **objet cmd** en premier
   argument.
5. **R5 — `last_update` bouge lors d'un passage hors ligne.** `online` fait partie des concepts connus et
   compte donc comme un changement réel (décision UC05, assumée). Pendant une panne, « Dernière mise à
   jour » affiche l'heure du **constat de panne**. Ne pas re-litiger UC05 pour cela.
6. **R6 — Ré-appairage d'un appareil.** Si le cloud change l'identifiant d'un appareil, le cycle le voit
   « absent » → hors ligne jusqu'à un **scan** manuel (qui le rerapproche par MAC). Visible et réparable en
   un clic ; aucune suppression n'a lieu.
7. **R7 — Perte du marqueur de cycle** (purge de cache admin, changement de moteur de cache) → un cycle
   supplémentaire immédiat, sans conséquence.
8. **R8 — Horloge système.** Un recul est neutralisé (`$ecoule < 0` → échu) ; une avance brutale provoque
   un cycle immédiat, comportement souhaité.
9. **R9 — Le rafraîchissement manuel est un appel global.** Cliquer « Rafraîchir » sur 5 équipements = 5
   appels. Aucune limitation de débit n'est documentée côté cloud (même constat qu'UC06).
10. **R10 — Extension future.** Le jour où un second transport existera (Broadlink LAN),
    `rafraichirAuxHome()` devra devenir « un cycle par transport » : le nom porte déjà `AuxHome`, et le
    rapprochement par `auxhome_device_id` est explicitement propre à ce transport. Rien à anticiper
    aujourd'hui — juste **ne pas** renommer la méthode en `rafraichir()` générique.
11. **R11 — Équipement au profil vide** (créé avant UC04, ou scanné appareil hors ligne) : aucune commande
    info n'existe, le cycle ne pousse rien et n'affiche rien, sans erreur. Un scan le corrige.

## 15. Recette manuelle (au-delà des AC)

1. Régler l'intervalle sur 1 min et vérifier dans « Analyse > Log > smartclim » (niveau debug) une ligne de
   résumé environ chaque minute ; repasser à 60 min et vérifier que le cycle suivant est bien différé.
2. Couper le réseau : vérifier **un seul** warning par transition (pas un par cycle) et que consigne, mode
   et vitesse **restent affichés**.
3. Envoyer une consigne puis attendre le cycle suivant dans les 60 s : la valeur commandée ne doit pas
   « revenir » (AC5 — c'est le calibrage de `DUREE_GRACE`, le point « À confirmer » de la spec
   fonctionnelle).
4. Désactiver le cron depuis l'onglet « Fonctionnalités » du plugin (`functionality::cron::enable`) et
   vérifier l'arrêt des cycles : c'est l'interrupteur natif, aucune clé de configuration plugin n'a été
   ajoutée pour cela.
5. Vérifier qu'un clic sur « Rafraîchir » met à jour **tous** les équipements (comportement attendu,
   D-MVP07-03).

## 16. Dette

Findings de review **sous la gate** (aucun `critical`/`high`/`blocker`/`major` au tour 1), non corrigés dans
ce cycle :

1. **Libellé du message d'échec interne du rafraîchissement manuel** (`low`, cosmétique). Un échec de type
   `TYPE_INTERNE` pendant un clic sur « Rafraîchir » affiche « Erreur interne lors de la **préparation de la
   connexion** — consultez les logs du plugin », libellé de `messageErreurAuxHome()` hérité d'UC02 où le
   seul chemin interne était effectivement la préparation de la connexion. Le message reste exact sur le
   fond (erreur interne, logs à consulter) mais désigne une étape qui n'est pas celle en cause. À traiter
   le jour où ce message aura un second appelant à contexte différent — pas avant, une chaîne UI de plus
   coûte trois traductions.
2. **Widget `smartclim::etat` posé sur la commande `refresh`** (`minor`, sans effet). `creerCommandesAction()`
   pose le widget sur toute commande action non-consigne dont le template est vide, donc aussi sur
   « Rafraîchir », qui n'a **aucune** commande info liée. Vérifié dans
   `core/template/dashboard/cmd.action.other.etat.html` : `valeurAttendue` reste `null` pour ce
   `logicalId`, `majEtat()` sort immédiatement, le bouton reste `btn-default`. Le script est donc injecté
   pour rien, sans erreur ni effet visible. Exclure `refresh` de la pose demanderait un cas particulier de
   plus dans une méthode qui en a déjà deux : le coût dépasse le gain.
3. **Absence de garde-fou sur les clics répétés de « Rafraîchir »** (`low`) — voir § 14 R9 et D-MVP07-02 :
   ce n'est pas une dette involontaire mais un **arbitrage assumé** (AC6 exige une lecture immédiate). À
   rouvrir si la recette révèle un rate-limit côté cloud (HTTP 429 ou blocage temporaire du compte).
