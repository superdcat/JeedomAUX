# Spec technique — UC08 du MVP : robustesse, expiration de session et diagnostic

> **Spec fonctionnelle** : `.memory/specs/MVP/08-robustesse-et-etat-connexion.md`
> **Dépend de** : UC07 (cycle de rafraîchissement) — livrée, commit `186b2da`
> **Arbitrages** : `.memory/auto-dev/run-20260826-2232/MVP-08/decisions.md`

Dernière UC du MVP. Deux natures de travail, à ne pas confondre :

- **la moitié des critères est déjà tenue par UC02/UC03/UC07** — ils ne demandent pas une ligne de code,
  seulement une recette ;
- **ce qui est réellement nouveau** : rendre l'incident **lisible dans l'interface** (AC8/AC9), étendre le
  rejeu re-login au chemin d'**écriture** (AC1), et 4 micro-correctifs de cohérence de journalisation (AC7).

**Aucune classe nouvelle. Aucun endpoint nouveau. Aucune clé de configuration nouvelle. Aucune
dépendance.** `core/php/smartclim.inc.php` reste inchangé.

---

## 1. Couverture des critères d'acceptation

| AC | Statut | Où |
|---|---|---|
| **AC1** — reconnexion auto après invalidation du jeton | **couvert en lecture / complété en écriture** | Lecture : `smartclimAuxHomeApi::listerAppareils()` (rejeu unique borné `$rejoue`, gate `TYPE_AUTH` + budget) — **aucune ligne à écrire**. Écriture : § 6 ajoute le même mécanisme à `appliquerOrdre()` |
| **AC2** — nombre borné de tentatives par cycle | **couvert, à préserver** | 3 gardes déjà en place : (a) `$rejoue` booléen local, jamais de récursion ; (b) `marquerCycle()` posé **avant** l'appel réseau dans `rafraichirAuxHome()` — un cycle en échec ne re-sollicite rien avant l'intervalle ; (c) `login()` n'écrit le cache qu'en cas de succès. Le rejeu du § 6 respecte la même borne : **2 logins au maximum par action** |
| **AC3** — hors ligne propre sur coupure Internet | **couvert — recette seule** | `cron()` (`catch (Throwable)` sans trace), `rafraichirAuxHome()` qui ne lève jamais, `basculerHorsLigne()` (`online = false`, warning **à la transition**), niveau `warning` si `TYPE_RESEAU` |
| **AC4** — retour en ligne automatique | **couvert — recette seule** | Cycle suivant : `listerAppareils()` puis `appliquerEtat()` pousse `online = true`, `last_update` repart |
| **AC5** — reprise après redémarrage Jeedom | **couvert — recette seule** | Configuration en base, session et marqueurs dans le cache Jeedom (fichier ou MariaDB : persistants). Corollaire assumé : `CLE_CACHE_DERNIER_CYCLE` survit aussi, le premier cycle après redémarrage peut donc attendre jusqu'à un intervalle complet — conforme au « au cycle suivant » de l'AC |
| **AC6** — redétection après coupure secteur du climatiseur | **couvert — recette seule** | Champ `enLigne` du cloud vers `etatAppareil()['online']`. La latence propre au cloud justifie le « quelques cycles » de l'AC |
| **AC7** — aucun secret dans les journaux | **audit fait (§ 8) + 4 micro-correctifs** | Aucun secret réellement exposé trouvé ; 4 écarts de cohérence corrigés |
| **AC8** — état / transport / fraîcheur par équipement dans la page de config | **à implémenter (§ 4)** | `smartclim::etatsConnexionAffichables()` / `etatConnexionAffichable()`, bloc HTML dans `desktop/php/smartclim.php`, injection `.text()` dans `printEqLogic()` |
| **AC9** — erreur d'authentification explicite | **à implémenter (§ 5)** | Mémoire d'incident globale en cache + promotion de l'état à « Erreur de connexion » + message français déjà curaté |

---

## 2. Architecture — fichiers touchés

| Fichier | État | Ce qui y entre | Indentation / fins de ligne |
|---|---|---|---|
| `core/class/smartclim.class.php` | modifié | 1 constante (`CLE_CACHE_DERNIER_INCIDENT`) ; 3 méthodes privées d'incident ; `etatsConnexionAffichables()` + `etatConnexionAffichable()` + `dureeHumaine()` ; branchements dans `rafraichirAuxHome()`, `scannerAuxHome()`, `testerConnexionAuxHome()`, `effacerIdentifiantsAuxHome()` et les 3 `postConfig_auxhome_*` ; `neutraliserPourLog()` passe `private` en `public` ; 3 micro-correctifs de log | **2 espaces**, **CRLF** |
| `core/class/smartclimAuxHomeApi.class.php` | modifié | 1 constante (`BUDGET_REJEU_ORDRE`) ; boucle de rejeu unique dans `appliquerOrdre()` ; clé additive `cree_le` dans la charge de session ; 2 lignes de log `info` de télémétrie ; commentaire de `DUREE_CACHE_SESSION` réécrit (**valeur inchangée**) | **2 espaces**, **CRLF** |
| `core/ajax/smartclim.ajax.php` | modifié | 2 micro-correctifs AC7 (§ 8.2, A7-1 et A7-4) | ⚠️ **4 espaces**, **CRLF** |
| `desktop/php/smartclim.php` | modifié | 1 `sendVarToJS('smartclimEtatsConnexion', …)` ; bloc `div_etatConnexion` au-dessus de « Profil de capacités détecté » ; badge d'état sur chaque carte d'équipement dans la boucle existante | ⚠️ **tabulations**, **CRLF** |
| `desktop/js/smartclim.js` | modifié | extension de `printEqLogic()` (injection `.text()` + classe CSS) ; rafraîchissement de la variable après un scan | **2 espaces**, **CRLF** |
| `plugin_info/configuration.txt` | modifié | 1 micro-correctif AC7 (§ 8.2, A7-2). ⚠️ puis `cp plugin_info/configuration.txt plugin_info/configuration.php`, contrôle par `git status --short` — **jamais** de relecture du `.php` | **2 espaces**, **CRLF** |
| `core/php/smartclim.inc.php` | **inchangé** | aucune classe nouvelle, donc aucun `require_once`. `smartclimFrame` reste **ajournée** (arbitrage UC05), ne pas la proposer | — |
| `core/config/smartclim.config.ini`, `plugin_info/info.json`, `plugin_info/install.php`, `plugin_info/packages.json`, `core/template/*` | **inchangés** | aucune clé de config, aucune dépendance, aucun widget | — |
| `core/i18n/*.json` | **inchangés à ce stade** | traduction déléguée au sous-agent `translator` en fin de cycle | — |

Avant commit : `python .claude/scripts/verif-plugin.py --tous` — deux fichiers **rendus** sont touchés
(`desktop/php/smartclim.php`, `plugin_info/configuration.txt`), donc la colonne `meta=` compte.

---

## 3. Server vs Client

**Tout le rendu de texte est SERVEUR**, sans exception — reprise à l'identique de la décision d'UC04 pour
`profilsAffichables()`, et la raison en est concrète : le JS n'a aucun moyen d'appeler `__()`, et un
libellé assemblé côté navigateur échapperait au scan statique d'extraction i18n.

- `desktop/php/smartclim.php` appelle
  `sendVarToJS('smartclimEtatsConnexion', smartclim::etatsConnexionAffichables($eqLogics))`.
- Le tableau transmis ne contient que des **chaînes déjà traduites** : aucun code, aucun identifiant
  cloud, aucune donnée d'origine externe non assainie.
- `desktop/js/smartclim.js` **n'assemble aucun libellé et ne valide rien** : il injecte en `.text()` et
  dérive une classe CSS du seul champ `niveau`.

---

## 4. AC8 — état de connexion affichable

### 4.1 Où vit la donnée affichée

**Décision : on lit les commandes info déjà en place. Aucun état nouveau n'est stocké.**
(cf. `decisions.md` D-MVP08-02)

| Donnée d'AC8 | Source retenue | Pourquoi pas autrement |
|---|---|---|
| état de connexion | valeur de la commande info `online` (`smartclimCapabilities::CONCEPT_ONLINE`) — seul porteur de la joignabilité depuis UC07 | un état dédié en `configuration` d'équipement violerait l'invariant des espaces de nommage disjoints (`capacites` détecté / `temp_*` personnalisé) et imposerait un `save()` par cycle ; un cache par équipement serait une **seconde** source de vérité pour un fait déjà porté |
| transport actif | valeur de la commande info `transport` (`smartclim::CMD_TRANSPORT`) | afficher `libelleTransport(TRANSPORT_AUX_HOME)` en dur mentirait : « actif » alors que rien ne fonctionne |
| âge de la dernière donnée | `$cmd->getValueDate()` de la commande `last_update` (`smartclim::CMD_DERNIERE_MAJ`), puis `time() - strtotime(...)` | cf. § 4.2 |

### 4.2 `last_update` : format INCHANGÉ, âge calculé sur la date de la commande

La valeur de `last_update` reste la chaîne `date('d/m/Y H:i:s')`. **Aucune migration.** Deux raisons, dont
une décisive :

1. **Parser la chaîne affichée serait FAUX.** `strtotime()` désambiguïse une date à séparateur `/` en
   supposant le format américain `m/d/Y` : `01/02/2026` serait lu « 2 janvier » au lieu du « 1er février ».
   Le format `d/m/Y H:i:s` est *lisible* mais *non parsable de façon fiable*.
2. **La date existe déjà, en format machine.** `last_update` n'est poussée que quand `appliquerEtat()` a
   constaté un changement, et sa valeur change à chaque poussée (les secondes) : le `$repeat` de
   `cmd::event()` est donc toujours faux, et `valueDate == collectDate == instant de la poussée`.
   `getValueDate()` renvoie exactement l'horodatage encodé dans la chaîne, au format `Y-m-d H:i:s`.

⚠️ **`getStatus('lastCommunication')` du core ne doit PAS servir de fraîcheur** — bien qu'il soit maintenu
gratuitement. Vérifié dans la source du core (`eqLogic::checkAndUpdateCmd()`) : dans la branche « valeur
inchangée », il exécute quand même `setCache('collectDate', now)` **et**
`setStatus(array('lastCommunication' => now, 'timeout' => 0))` avant de renvoyer `false`. Il serait donc
rafraîchi même quand le seul champ poussé est le `online = false` forcé par `basculerHorsLigne()` pendant
une coupure : il dirait « donnée reçue il y a 30 s » alors que **rien** n'a été reçu.

**Sémantique assumée** : « dernière donnée reçue » = **dernier changement constaté**. C'est la convention
déjà posée par UC05 (son AC6 : « deux cycles sans changement laissent `last_update` figé : l'utilisateur
lit l'âge RÉEL de la donnée »), et c'est ce que le dashboard affiche — une seule vérité.

### 4.3 Signatures

```php
/** Miroir exact de profilsAffichables() : try/catch PAR équipement, ne lève jamais.
 *  @param smartclim[] $_eqLogics
 *  @return array<int,array> indexé par id d'équipement, chaînes DÉJÀ traduites */
public static function etatsConnexionAffichables(array $_eqLogics)

/** État de connexion de CET équipement, prêt à l'affichage : que des chaînes déjà
 *  traduites, aucun code, aucune donnée externe, aucun identifiant cloud.
 *  UNE seule lecture getCmd(null, null), indexée par logicalId ET filtrée sur
 *  getType() === 'info'.
 *  @return array{niveau:string, etat:string, detail:string, transport:string,
 *                fraicheur:string, derniereDonnee:string, incidentLe:string} */
public function etatConnexionAffichable()

/** Durée écoulée en français : « à l'instant » / « il y a %d min » /
 *  « il y a %d h %d min » / « il y a %d jour(s) ». __() enveloppé AVANT sprintf(),
 *  arguments POSITIONNELS dès qu'il y en a plusieurs.
 *  @param int $_secondes @return string */
private static function dureeHumaine($_secondes)
```

`niveau` appartient à `{ok, warning, danger, neutre}` — **seule** valeur consommée par le JS pour choisir
une classe CSS. Le JS ne doit jamais raisonner sur `etat`.

### 4.4 ⚠️ Piège fatal — `execCmd()` sur une commande ACTION l'EXÉCUTE

Lire la valeur d'une commande passe par `execCmd()`. Sur une commande **info**, `execCmd()` sort du cache
avant tout contrôle et n'a aucun effet de bord. Sur une commande **action**, elle **envoie un vrai ordre au
climatiseur**.

L'index construit depuis `getCmd(null, null)` **doit** donc retenir uniquement les commandes dont
`getType() === 'info'`. Ce n'est pas une précaution de style : sans ce filtre, ouvrir la page d'un
équipement pourrait allumer l'appareil. **À revoir explicitement en review de code.**

### 4.5 Table de résolution de l'état (ordre impératif, premier cas gagnant)

| Condition | `etat` | `niveau` | `detail` |
|---|---|---|---|
| `getIsEnable() == 0` | « Équipement désactivé » | `neutre` | vide |
| `!smartclim::compteConfigure()` | littéral **existant** « Compte AUX Home non configuré : renseignez l'e-mail et le mot de passe » | `danger` | vide |
| `online` vaut `1` | `self::libelleEnLigne(true)` → « En ligne » | `ok` | vide |
| incident mémorisé, type **différent de** `TYPE_RESEAU` | « Erreur de connexion » | `danger` | `self::messageErreurAuxHome($type, $contexte)` |
| incident mémorisé, type `TYPE_RESEAU` | `self::libelleEnLigne(false)` → « Hors ligne » | `warning` | `self::messageErreurAuxHome(TYPE_RESEAU, '')` |
| `online` vaut `0` | « Hors ligne » | `warning` | vide (l'appareil est injoignable côté cloud, cf. AC6) |
| `online` vaut la chaîne vide (jamais poussée) | « État inconnu » | `warning` | « Aucun cycle de rafraîchissement n'a encore eu lieu » |

Points tranchés dans cette table :

- **`online == 1` gagne sur l'incident** : un cycle réussi efface l'incident (§ 5), les deux ne peuvent pas
  coexister ; la priorité rend l'affichage déterministe même si le cache est incohérent.
- **`TYPE_PROTOCOLE` et `TYPE_INTERNE` promeuvent aussi à « Erreur de connexion »**, pas seulement
  `TYPE_AUTH` : un code pays erroné arrive en `TYPE_PROTOCOLE` + contexte « requête initiale », et
  `messageErreurAuxHome()` produit alors déjà « …vérifiez le code pays (FRA, BEL…) » — exactement l'esprit
  d'AC9. Seul `TYPE_RESEAU` (transitoire, rien à corriger côté utilisateur) reste un « Hors ligne ».
- **`libelleEnLigne()` et `messageErreurAuxHome()` sont réutilisées telles quelles** (privées, même
  classe) : **zéro clé i18n nouvelle** pour les 4 messages d'erreur et pour « En ligne » / « Hors ligne ».
- L'état est **borné à 5 valeurs** : le vocabulaire d'AC8 (en ligne / hors ligne / erreur) plus deux cas
  honnêtes que l'AC ne nomme pas (« désactivé », « inconnu »). Afficher « Hors ligne » sur un équipement
  jamais interrogé serait faux (P6).

`getValueDate()` sur une chaîne vide donne `strtotime('') === false`, donc repli « Jamais ». Un âge
**négatif** (horloge reculée, Jeedom sans RTC resynchronisé au démarrage) est ramené à 0 → « à l'instant » :
même prudence que `cycleEchu()`.

---

## 5. AC9 — mémoire de l'incident de connexion

### 5.1 Constante et signatures

```php
const CLE_CACHE_DERNIER_INCIDENT = 'smartclim::dernier_incident';  // TTL : self::DUREE_MEMOIRE_CYCLE

/** Mémorise le dernier échec de CONNEXION du cycle automatique. Cache NON chiffré :
 *  aucun secret dedans (un type 1..4, une constante de contexte neutre, un timestamp).
 *  Appelée depuis les catch INTERNES de rafraichirAuxHome(), donc déjà couverte par son
 *  catch(Throwable) global : pas de try/catch propre nécessaire. */
private static function memoriserIncident($_type, $_contexte)

/** Efface la mémoire d'incident : dès qu'une connexion RÉUSSIT, et à tout changement
 *  d'identifiants. */
private static function oublierIncident()

/** @return array{type:int, contexte:string, ts:int}|null — null si absent, illisible, ou si
 *  'type' n'est pas une des 4 constantes smartclimException::TYPE_*. */
private static function incidentMemorise()
```

**Invariant en une phrase** : *seul le cycle automatique écrit l'incident ; toute connexion réussie
l'efface.*

### 5.2 Points de branchement

| Emplacement | Action |
|---|---|
| `rafraichirAuxHome()`, `catch (smartclimException)` de `listerAppareils()` | `memoriserIncident($e->getType(), $e->getContexte())` |
| `rafraichirAuxHome()`, `catch (Throwable)` de `listerAppareils()` | `memoriserIncident(TYPE_INTERNE, '')` — c'est la branche qui bascule déjà hors ligne |
| `rafraichirAuxHome()`, juste **après** un `listerAppareils()` réussi (avant distribution) | `oublierIncident()` |
| `scannerAuxHome()`, juste après un `listerAppareils()` réussi | `oublierIncident()` |
| `testerConnexionAuxHome()`, après `login()` réussi | `oublierIncident()` |
| `effacerIdentifiantsAuxHome()` et `postConfig_auxhome_{password,email,country}` | `oublierIncident()`, à côté du `purgerSession()` existant |
| `rafraichirAuxHome()`, `catch (Throwable)` **externe** (filet interne) | **rien** — cohérent avec son commentaire existant : une erreur interne hors réseau ne dit rien de la connectivité, et elle ne bascule pas non plus les équipements hors ligne |

**Non écrits volontairement** : un **scan** échoué et un **test de connexion** échoué ne mémorisent pas. Ce
sont des chemins interactifs, l'erreur est déjà affichée à l'utilisateur, et l'incident doit refléter la
santé du **cycle automatique**. Alternative journalisée pour `/change` (D-MVP08-02) : les faire mémoriser
aussi, pour que la page se colore sans attendre un cycle.

### 5.3 Durée de vie et portée

- **Durée** : `DUREE_MEMOIRE_CYCLE` (48 h = 2 fois l'intervalle maximal), **réutilisée sans constante
  nouvelle** — deux noms pour une même valeur est un piège de maintenance. Au-delà, l'information n'a plus
  de valeur de diagnostic, et un cron arrêté ne laisse pas une erreur affichée indéfiniment.
- **Portée** : **une seule** entrée globale, sans suffixe d'équipement — l'incident porte sur le **compte**
  cloud, pas sur un appareil. Un appareil individuellement injoignable est déjà décrit par son
  `online = false` (AC6).

---

## 6. AC1 — rejeu re-login sur le chemin d'ÉCRITURE

`listerAppareils()` (lecture) a son rejeu depuis UC03. `appliquerOrdre()` (écriture) n'en avait pas : un
jeton expiré faisait échouer le premier appui sur un bouton, l'utilisateur devait réessayer. Le
« Comportement attendu » de la spec fonctionnelle parle d'« un appel authentifié », ce qui inclut
l'écriture (P1), et le commentaire de `requeteControle()` renvoyait déjà explicitement à UC08 (P3).

```php
// Temps minimal (secondes) requis dans BUDGET_COMMANDE pour tenter re-login + rejeu.
// Arithmétique du pire cas : login réduit (3 + 3, ses deux planchers) + RESERVE_ORDRE (4).
const BUDGET_REJEU_ORDRE = 10;
```

Dans `appliquerOrdre()`, la construction de l'`intent` est **inchangée** ; seul l'appel final passe dans
une boucle calquée sur `listerAppareils()` :

```
$rejoue = false;
while (true) {
  $tempsRequete = (int) max(3, min(TIMEOUT_REQUETE, BUDGET_COMMANDE - ecoule));
  try { requeteControle($session['jeton'], $intent, $id, $tempsRequete); break; }
  catch (smartclimException $e) {
    $budgetRestant = BUDGET_COMMANDE - ecoule;
    if (!$rejoue && $e->getType() === TYPE_AUTH && $budgetRestant >= BUDGET_REJEU_ORDRE) {
      $rejoue = true;
      log::add('smartclim', 'info', 'rejeu apres re-login, age de la session refusee');
      $session = self::login((int) max(6, $budgetRestant - RESERVE_ORDRE));
      continue;
    }
    throw $e;
  }
}
return $ordreApplique;   // inchangé, construit AVANT la boucle
```

**Pourquoi un seuil DÉDIÉ et non celui de `listerAppareils()`** — c'est le point qui se paie cher si on le
rate : `BUDGET_COMMANDE = 18` et `BUDGET_LOGIN = 18`, donc la garde de `listerAppareils()`
(`budgetRestant >= BUDGET_LOGIN + 3`) serait **du code mort** ici (jamais vraie). Avec le seuil à 10 s :
premier essai au plus 8 s, login réduit au plus 6 s (ses deux planchers de 3 s), rejeu de la requête de
contrôle `max(3, min(10, 18 - 14)) = 4` s, soit **un total au plus égal à 18 s** : l'exigence interactive
d'UC06 tient sans être relâchée. Si le premier essai a été plus lent : aucun rejeu —
`requeteControle()` a déjà purgé la session, la tentative suivante de l'utilisateur repart sur un login
frais (comportement actuel, dégradation gracieuse).

Détails à ne pas rater :

- **Aucun `purgerSession()`** dans la branche de rejeu : `requeteControle()` l'a déjà fait pour tout code
  classé `TYPE_AUTH`, et `login()` réécrit le cache de toute façon.
- Le `try` entoure **uniquement** `requeteControle()`. Un `TYPE_AUTH` levé par `session()` (compte non
  configuré, login en échec) est **hors** boucle et ne doit **jamais** déclencher de rejeu — c'est
  précisément la rafale qu'AC2 interdit.
- Mettre à jour les commentaires de `requeteControle()` qui annoncent « hors périmètre UC08 » : ce n'est
  plus vrai.

---

## 7. Calibration de `DUREE_CACHE_SESSION`

**Décision : la valeur reste 1800 s (30 min). Ce qui change, c'est son statut.** (D-MVP08-04)

Avec le rejeu réactif présent **sur les deux chemins** (lecture depuis UC03, écriture depuis cette UC), le
TTL cesse d'être un paramètre de **correction** pour devenir un simple réglage d'**économie de requêtes**.
Le baisser ne gagne rien (le rejeu couvre déjà l'expiration) et multiplie les logins RSA contre un backend
tiers **sans quota documenté**. Le monter n'est pas validable : la durée réelle reste inconnue, et si elle
est courte, un TTL long transformerait l'échec en cas nominal — une requête perdue **par cycle**. 30 min
coûte environ 2 logins par heure sur l'intervalle par défaut de 5 min.

**Télémétrie ajoutée** — c'est elle qui permettra de trancher factuellement le « À confirmer » de la spec
fonctionnelle :

- `login()` ajoute `'cree_le' => time()` dans la charge chiffrée mise en cache, et la renvoie.
- `session()` renvoie `cree_le` : valeur du cache si présente et numérique, sinon `0` (inconnu). Une entrée
  de cache d'avant cette UC reste **valide**, aucune migration.
- ⚠️ Les **2 clés du contrat de `session()` (`jeton`, `uid`) sont inchangées**, et `cree_le` est **toujours
  présent dans les deux branches** (cache et login frais). Une clé présente une fois sur deux
  reproduirait la panne intermittente déjà signalée en revue UC02 (le cas `pseudo`).
- Les deux branches de rejeu journalisent en `info` : « AUX Home : rejeu après re-login (âge de la session
  refusée : N s) », avec `N = 0` rendu « inconnu ».
- Le **code backend** qui a déclenché le rejeu est déjà journalisé en `error` par
  `journaliserErreurBackend()` : les deux lignes ensemble ferment l'incertitude « durée de vie + code
  d'expiration » de `.memory/analyse/smartclim-transport-aux-home.md` § 2.3 et § 9 — **à reporter dans
  l'analyse après recette**.

Le commentaire « 30 minutes — pari documenté, à calibrer en UC08 » est réécrit : plus de TODO pointant vers
une UC terminée.

---

## 8. AC7 — audit de journalisation

### 8.1 Ce qui a été vérifié et est CONFORME (aucune action)

- **Aucun** `getTraceAsString()`, `log::exception()`, `var_dump`, `print_r`, `error_log` dans le code
  (uniquement dans des commentaires).
- Jeton : journalisé **tronqué à 6 caractères** à un seul endroit, jamais ailleurs ; session en cache via
  `utils::encrypt()`.
- Mot de passe et champ de compte chiffré : jamais journalisés. `chiffrerMotDePasse()` /
  `chiffrerCompte()` ne journalisent que la pile OpenSSL et `basename(file):line`.
- `journaliserErreurBackend()` : 4 étapes dans l'ordre, dont la neutralisation base64 qui borne un écho du
  champ de compte.
- **Le chemin d'exception le plus dangereux est fermé par construction** : toutes les méthodes publiques du
  transport **recréent** leur exception à leur point d'appel, donc une exception qui atteint
  `core/ajax/cmd.ajax.php` (lequel appelle `displayException()`, qui met la trace dans le DOM si
  `log::level <= 100`) ne peut pas porter la frame `executerRequete(..., $_jeton)`. **C'est cet invariant
  qui tient AC7** — il vivait dans les docblocks, il est désormais aussi écrit ici.

### 8.2 Les 4 écarts corrigés

| # | Constat | Correctif |
|---|---|---|
| **A7-1** | `core/ajax/smartclim.ajax.php` journalise `$t->getMessage()` **non neutralisé** — seul endroit du plugin où un message de `Throwable` échappe à `neutraliserPourLog()` (injection de log par retour à la ligne forgé) | passer `smartclim::neutraliserPourLog()` de `private` à **`public`** et l'appeler ici. Ce n'est pas une fonction top-niveau : aucun risque de redéclaration dans ce fichier procédural |
| **A7-2** | `plugin_info/configuration.txt` : même motif | idem. ⚠️ éditer le **`.txt`** puis `cp` vers le `.php` |
| **A7-3** | 3 `log::add` de `smartclim.class.php` (dans `testerConnexionAuxHome()`, `sonderDiagnostic()`, `scannerAuxHome()`) journalisent `$e->getMessage()` d'une `smartclimException` **sans** neutralisation, alors que `rafraichirAuxHome()` la neutralise. Un seul message de transport embarque du texte non contrôlé par nous : celui qui concatène l'erreur cURL | envelopper les 3 par `self::neutraliserPourLog(...)` — cohérence, coût nul |
| **A7-4** | `core/ajax/smartclim.ajax.php` : `ajax::error(displayException($e), …)` sur une `Exception` **générique** met la **trace dans le DOM** dès que `log::level <= 100`. Une `Exception` née *pendant* `executerRequete()` (ex. un `log::add()` en échec) aurait dans sa trace la frame `executerRequete($m, $c, $corps, $t, $jeton)`, donc **le jeton complet et le corps chiffré** | remplacer par `ajax::error($e->getMessage(), $e->getCode())`. On perd le lien « Show traces » en mode debug ; on garde le message, donc la diagnosticabilité (y compris pour l'exception « Aucune méthode correspondante » levée par ce fichier). Arbitré **P2 sur P4** : « jamais de secret dans le DOM » n'est pas négociable, même sur une surface admin |

### 8.3 Écart connu et volontairement NON corrigé

L'identifiant cloud (`auxhome_device_id`) est journalisé en `error` sur 3 lignes du chemin de scan, et la
MAC en `warning` sur une quatrième. Ce n'est ni un mot de passe, ni un jeton, ni un champ chiffré — donc
hors de la liste explicite d'AC7 (P1) — mais `smartclimDiagnostic` **masque** ces mêmes champs dans un
rapport partageable. Un utilisateur qui colle ses logs sur le forum les expose.

**Non modifié** : le changer maintenant rendrait le diagnostic du scan aveugle sur le seul champ qui permet
de rapprocher un appareil. Journalisé en dette (D-MVP08-06), candidat `/change`.

---

## 9. Validation et erreurs

| Quoi | Où | Comportement |
|---|---|---|
| forme de l'entrée de cache d'incident | `incidentMemorise()`, **serveur** | `is_array`, `is_numeric('ts')`, `type` dans {1,2,3,4} ; sinon `null` — aucun état d'erreur affiché plutôt qu'un état forgé |
| valeur de `online` | `etatConnexionAffichable()`, **serveur** | chaîne vide ou `null` → « État inconnu » ; sinon `(int) $valeur === 1` |
| type des commandes lues | `etatConnexionAffichable()`, **serveur** | `getType() === 'info'` **obligatoire** avant tout `execCmd()` (§ 4.4) |
| dates du core | `etatConnexionAffichable()`, **serveur** | `strtotime('')` donne `false` → « Jamais » ; âge négatif ramené à 0 |
| robustesse de l'affichage | `etatsConnexionAffichables()`, **serveur** | `try/catch (Throwable)` **par équipement** → entrée dégradée `etat` = « État indisponible — consultez les logs du plugin », `niveau` = `neutre`. Une lecture de cache en échec ne doit pas casser toute la page admin |
| budget du rejeu d'ordre | `appliquerOrdre()`, **serveur** | gate `budgetRestant >= BUDGET_REJEU_ORDRE` ; pas de rejeu sinon |
| client | `desktop/js/smartclim.js` | **aucune validation, aucun assemblage de libellé** : `.text()` de chaînes déjà traduites, classe CSS dérivée du seul champ `niveau` |

**Typage des exceptions** : aucune exception nouvelle. `smartclimException` reste la seule ; les messages
affichés proviennent exclusivement de `messageErreurAuxHome()`. `etatConnexionAffichable()`,
`etatsConnexionAffichables()` et les 3 méthodes d'incident **ne lèvent jamais**.

---

## 10. Dépendances

**Aucune.** Pas de paquet, pas de démon, pas de classe nouvelle, pas de clé de configuration, pas de
`require_once` supplémentaire dans `core/php/smartclim.inc.php`.

---

## 11. Impact i18n (français, langue source)

`core/class/smartclim.class.php` — 11 littérales nouvelles (`__('…', __FILE__)`, **toujours** littérales,
`__()` enveloppé **avant** `sprintf()`) :
« Erreur de connexion », « État inconnu », « Aucun cycle de rafraîchissement n'a encore eu lieu »,
« Équipement désactivé », « État indisponible — consultez les logs du plugin », « Inconnu », « Jamais »,
« à l'instant », « il y a %d min », « il y a %d h %d min », « il y a %d jour(s) ».

`desktop/php/smartclim.php` — 4 clés nouvelles (enveloppées en double accolade) : « État de connexion »,
« État », « Transport actif », « Dernière donnée reçue ».

⚠️ « Transport actif » existe déjà comme littérale **PHP** dans `smartclim.class.php` : les clés i18n étant
indexées **par fichier**, c'est bien une **entrée nouvelle** pour `desktop/php/smartclim.php`. Ne pas
« optimiser » en la supprimant.

**Réutilisées sans clé nouvelle** (grâce à `libelleEnLigne()`, `messageErreurAuxHome()` et aux gardes
existantes) : « En ligne », « Hors ligne », « Compte AUX Home non configuré : renseignez l'e-mail et le mot
de passe », « Échec de la connexion — vérifiez vos identifiants et le pays sélectionné », « Service AUX
Home injoignable, réessayez plus tard », « Le service AUX Home a refusé la requête initiale — vérifiez le
code pays (FRA, BEL…) », « Réponse inattendue du service AUX Home — consultez les logs du plugin »,
« Erreur interne lors de la préparation de la connexion — consultez les logs du plugin ».

`desktop/js/smartclim.js`, `core/ajax/smartclim.ajax.php`, `plugin_info/configuration.txt` : **aucune**
chaîne nouvelle.

---

## 12. Risques

1. **`execCmd()` sur une commande action = ordre réel au climatiseur** (§ 4.4). Le filtre
   `getType() === 'info'` est une garde fonctionnelle, pas cosmétique.
2. **Rejeu d'ordre et codes métier** : `classerCodeMetier('control', …, TYPE_AUTH)` classe **tout** code
   hors 9023/64033 en `TYPE_AUTH`. Un ordre voué à l'échec (appareil hors ligne, identifiant d'appareil
   obsolète) déclenchera donc un login et un rejeu inutiles — borné à **un**, conforme à la politique
   documentée, mais à confirmer en recette (durée totale, une seule ligne « rejeu après re-login »).
3. **Pas de backoff après échecs répétés — non-objectif assumé** (D-MVP08-05). Aucun AC ne le demande
   (AC2 borne les tentatives **par cycle**, ce qui est tenu). Résiduel : mot de passe durablement faux plus
   intervalle réglé à 1 min, soit jusqu'à 2 tentatives de login par minute contre un backend tiers sans
   quota documenté. **Candidat `/change` de premier rang.** Piste écartée : sauter le cycle quand
   l'incident mémorisé est `TYPE_AUTH` et récent — elle sacrifierait AC1/AC4 (une expiration de jeton mal
   classée gèlerait le rafraîchissement).
4. **`last_update` et `repeatEventManagement = 'always'`** : si l'utilisateur règle une commande info sur
   « toujours notifier », `last_update` repart à chaque cycle et la fraîcheur affichée devient toujours
   « à l'instant ». Comportement Jeedom natif, hors du contrôle du plugin, déjà documenté en UC05 ; ne pas
   tenter de le contourner (cela écraserait un réglage utilisateur).
5. **Purge du cache Jeedom** (`cache::flush`, changement de moteur) : les valeurs de commandes vivent dans
   le cache, donc tous les équipements affichent « État inconnu » jusqu'au cycle suivant. Auto-réparant en
   un intervalle au plus, mais déroutant si cela arrive juste avant une recette.
6. **Coût de la page admin** : AC8 ajoute une lecture `getCmd(null, null)` par équipement au rendu de la
   page, plus une lecture de cache d'incident. Négligeable sur un parc domestique — **à ne pas reproduire
   dans un chemin de cron**.
7. **Identifiant cloud en journal** (§ 8.3) : hors du périmètre littéral d'AC7, mais exposé si
   l'utilisateur colle ses logs sur un forum, alors que le rapport de sonde le masque.
8. **`session()` / `login()` sont des contrats déjà passés en revue** : `cree_le` est le **seul** ajout, et
   il doit être présent dans les **deux** branches. Une présence intermittente reproduirait la panne
   signalée en revue UC02.
9. **Contrats du core non garantis dans le temps** : la lecture de `getValueDate()` s'appuie sur le
   comportement de `cmd::event()` / `checkAndUpdateCmd()` vérifié sur `jeedom/core` en août 2026. Un
   changement du core (par exemple `valueDate` alimenté aussi sur répétition) ferait dériver l'âge affiché,
   sans casser le plugin.
10. **Extension future** : le jour où un second transport existe, `etatConnexionAffichable()` lira toujours
    la commande `transport` (donc restera juste), mais la mémoire d'incident, **globale au compte AUX
    Home**, devra devenir par transport. Une ligne à prévoir au domaine post-MVP 02, rien à anticiper
    aujourd'hui.

---

## 13. Recette manuelle (mappée AC par AC)

- **AC1/AC2 (lecture)** — purger le cache de session (ou changer puis rétablir le mot de passe) ; le cycle
  suivant doit relogger et repasser les équipements en ligne. Journal : **au plus** 2 logins, une seule
  ligne « rejeu après re-login ».
- **AC1 (écriture, nouveau)** — purger la session, puis appuyer sur un bouton d'action : l'ordre doit
  **aboutir du premier coup**. Vérifier la ligne `info` d'âge de session et la durée totale (moins de 18 s).
- **AC2** — pendant un incident prolongé, compter les tentatives sur 10 minutes : elles doivent suivre
  l'intervalle configuré, sans rafale.
- **AC3** — couper Internet ; les équipements passent hors ligne, un `warning` apparaît **une seule fois**
  (transition), le reste de Jeedom continue de fonctionner.
- **AC4** — rétablir ; le cycle suivant remonte les équipements en ligne, `last_update` repart.
- **AC5** — redémarrer le service Jeedom : aucun reconfigurage, le cycle suivant rafraîchit **et** un ordre
  passe.
- **AC6** — couper puis rallumer le climatiseur au disjoncteur ; noter le nombre de cycles nécessaires
  (alimente `.memory/analyse/smartclim-transport-aux-home.md`).
- **AC7** — scénario combiné, puis recherche insensible à la casse dans le journal (tous niveaux, **debug
  inclus**) sur : le mot de passe réel, 8 caractères ou plus du jeton, un fragment base64 long,
  `auxhome_password`. Vérifier aussi le **DOM** (mode debug activé) sur une erreur AJAX.
- **AC8** — page du plugin : badge d'état sur chaque carte ; page d'un équipement : état, transport et
  fraîcheur cohérents avec le dashboard. Vérifier les **5** états (désactivé, non configuré, en ligne, hors
  ligne, jamais interrogé).
- **AC9** — changer le mot de passe côté AUX Home sans le mettre à jour dans Jeedom ; après un cycle, la
  page doit afficher « Erreur de connexion » plus « Échec de la connexion — vérifiez vos identifiants et le
  pays sélectionné » et la date de l'incident. Corriger le mot de passe : l'erreur disparaît
  **immédiatement** (purge par `postConfig_auxhome_password`).
- **Calibration** — relever, sur plusieurs jours, les lignes « âge de la session refusée » : si aucune
  n'apparaît jamais, la durée de vie du jeton est supérieure ou égale à 30 min ; sinon la valeur relevée en
  est une borne supérieure. **Reporter le résultat dans
  `.memory/analyse/smartclim-transport-aux-home.md` § 2.3 et § 9.**

---

## 14. Dette

Findings de review **sous la gate**, non corrigés dans ce cycle (tours de review : 1 ; sécurité
`pass` sans `critical`/`high`, qualité `pass` sans `blocker`/`major`).

| # | Sévérité | Où | Constat | Correction attendue le jour où on y revient |
|---|---|---|---|---|
| **DT-1** | `minor` | `desktop/php/smartclim.php` (tableau PHP `$sc_classesNiveau`) et `desktop/js/smartclim.js` (objet `smartclimClassesNiveau`) | La correspondance `niveau` → classe CSS (`label-success` / `label-warning` / `label-danger` / `label-default`) est **dupliquée à l'identique** dans les deux fichiers, **sans référence croisée**. Fonctionnellement correct : les deux copies sont aujourd'hui synchrones, et `niveau` est borné à 4 valeurs par `etatConnexionAffichable()`. Le risque est de maintenance — un 5ᵉ niveau ajouté d'un seul côté retomberait silencieusement sur la classe par défaut. | Ajouter dans **chacun** des deux fichiers un commentaire croisé pointant l'autre (« même correspondance que … , à maintenir synchronisée »), sur le modèle du commentaire déjà employé pour l'enveloppe de température dupliquée PHP/JS (`preSave()` / `saveEqLogic`). Ne **pas** tenter de dédupliquer en faisant calculer la classe côté serveur : `niveau` est précisément le seul champ que le JS a le droit d'interpréter (§ 3), et lui envoyer une classe CSS toute faite ferait entrer de la présentation dans le contrat de données. |

**Arbitrages reportés** (journalisés, candidats `/change`, cf.
`.memory/auto-dev/run-20260826-2232/MVP-08/decisions.md`) :

- **D-MVP08-05** — aucun backoff après échecs répétés. Résiduel : mot de passe durablement faux plus
  intervalle réglé à 1 min, soit jusqu'à 2 tentatives de login par minute contre un backend tiers sans
  quota documenté. **Candidat `/change` de premier rang.**
- **D-MVP08-06** — identifiant cloud (`auxhome_device_id`) laissé en clair sur 3 lignes de log du chemin
  de scan, et MAC sur une quatrième, alors que `smartclimDiagnostic` masque ces mêmes champs dans un
  rapport partageable.
