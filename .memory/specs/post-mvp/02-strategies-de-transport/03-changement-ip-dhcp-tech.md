# Spec technique — UC03 · Résilience au changement d'adresse IP

> **Domaine** : post-mvp/02-strategies-de-transport · **Spec fonctionnelle** :
> [`03-changement-ip-dhcp.md`](03-changement-ip-dhcp.md) · **Dépend de** : UC01 et UC02 de ce domaine,
> UC01/UC02/UC03 du domaine post-mvp/01.

---

## 0. Ce que cette UC change, en une phrase

Elle insère **une redécouverte par diffusion entre l'échec de la sonde unicast et la conclusion
« injoignable »**, adopte l'appareil retrouvé par sa MAC (directe ou inversée) et enregistre la nouvelle
adresse dans la **mémoire de sonde** — jamais en configuration d'équipement, jamais par un `save()`.

### 0.1 Propriété directrice — strictement derrière la branche d'échec

Tout le code de cette UC vit **derrière un échec de sonde unicast déjà constaté**. Sur le chemin nominal
(l'appareil répond à son adresse connue), il n'exécute **aucune branche nouvelle** : zéro paquet, zéro
lecture de cache supplémentaire, zéro milliseconde. C'est ce qui rend l'**AC4** vrai par construction et non
par mesure.

Corollaire pour la recette : sur l'installation de l'utilisateur, **aucun équipement ne possède de mémoire de
sonde LAN** — le cycle LAN y est donc exactement aussi inerte qu'avant cette UC, et **aucune diffusion ne
part jamais** hors de la CLI `--redecouvrir`. Cf. § 9.

### 0.2 Ce que cette UC n'est PAS

Elle **n'ajoute aucune capacité de découverte** : `scannerReseauLocal()` savait déjà diffuser, rapprocher par
MAC directe et inversée, et écrire la nouvelle adresse. Ce que l'UC03 apporte est que **cela s'exerce sans
action de l'utilisateur** (AC2/AC3). Ne pas la lire comme un second moteur de découverte.

---

## 1. Décisions arbitrées avec l'utilisateur (2026-09-08)

| # | Question | Décision | Alternative écartée et pourquoi |
|---|---|---|---|
| **A1** | Fréquence de la redécouverte vis-à-vis du compteur d'échecs de l'UC02 (« À confirmer » n°1 de la spec) | **À chaque échec de sonde unicast**, mais la diffusion est faite **au plus une fois par cycle** (et une fois par opération interactive), et elle est placée **avant** l'incrément du compteur | *« Après N échecs »* : un changement d'IP **n'est pas** un échec LAN. Lui laisser consommer 2 ou 3 crans arme le repli cloud pour la cause exacte que cette UC existe pour éviter, et en LOCAL (aucun cloud) c'est **45 minutes** (3 × 15 min) d'appareil déclaré indisponible alors qu'il répond. La spec écrit « **avant** de déclarer injoignable ». — *« À chaque échec, par équipement »* : N diffusions par cycle sur un parc de N appareils morts ; la diffusion étant **globale**, une seule suffit pour tout le parc |
| **A2** | Budget de latence ajouté sur un chemin interactif (`brief.md` § 15, « À confirmer » n°2) | La redécouverte se paie **dans** `BUDGET_ORDRE_LAN` = 12 s, **non relevé d'une seconde**. Fenêtre `BUDGET_REDECOUVERTE_LAN` = **3 s**, réserve `RESERVE_APRES_REDECOUVERTE_LAN` = **6 s**, abandon sous 1 s | *Relever le budget global* : le pire cas ressenti par l'utilisateur augmenterait pour un cas rare. *Fenêtre de 2 s* : **vérifié en source**, la retransmission du hello ne partirait jamais (§ 2.2) |
| **A3** | Ambiguïté de rapprochement (« À confirmer » n°3) | Ordre de candidats figé, **refus explicite** si une MAC candidate directe **et** son inversée sont portées par **deux appareils distincts**. Jamais d'adoption « au mieux » | *Adopter le premier trouvé* : envoyer un ordre HVAC à la machine d'un tiers est **physiquement observable et irréversible** (bip + changement d'état). Un cycle d'indisponibilité coûte 15 minutes ; une adoption fausse pilote l'appareil du voisin. Même doctrine que `STATUT_MAC_DIVERGENTE` (« insister serait marteler la machine d'un tiers ») |
| **A4** | Forme de l'**AC6** (documentation), sachant que `docs/fr_FR/index.md` est encore l'index brut du squelette `jeedom/plugin-template` | **Page seule** : créer `docs/fr_FR/reseau-local.md`, **ne pas toucher `index.md`** | *Ajouter une puce dans l'index* : y insérer une entrée SmartClim au milieu d'un sommaire qui parle du « template de plugin » et pointe vers doc.jeedom.com serait incohérent. *Refondre l'index* : empiète sur **post-mvp/07 UC02 « documentation utilisateur »**, qui possède `docs/fr_FR/`. ⚠️ **Reprise à faire au domaine 07** : poser le lien vers `reseau-local.md` lors de la refonte |
| **A5** | 4ᵉ usage CLI `--redecouvrir` + `diagnosticRedecouverteLan()` (§ 9), déclinable sans toucher aux AC | **Conservé** | Il est le **seul** moyen d'exercer réellement ce code sur l'installation de l'utilisateur : le `brief.md` § 19 établit que **deux RM4 Pro répondent à la diffusion**. Sans lui, l'UC est intégralement vérifiée par lecture seule, comme UC01 et UC02 de ce domaine |

### 1.1 Les 4 mineurs de la revue de plan, intégrés

- **M1** (§ 4.1) — `decouverteParMac()` journalise un `warning` dans **les deux** branches de rattrapage, pas
  seulement sur `smartclimException` : le patron existant de `scannerReseauLocal()` (l. 754-757) le fait, et
  un bug interne muet se répéterait toutes les 15 minutes sans laisser de trace.
- **M2** (§ 2.3) — commentaire croisé obligatoire sur `RESERVE_APRES_REDECOUVERTE_LAN` : **trois**
  constantes « réserve » coexistent désormais, dont une purement documentaire.
- **M3** (§ 8) — le statut « raccroc temporaire » du lien de documentation est écrit noir sur blanc (A4).
- **M4** (§ 6.3) — la nuance **AC3 en LOCAL contre AC3 en AUTO** est explicitée : une redécouverte
  interactive ne rouvre pas `lanJoignable()`.

---

## 2. Architecture

### 2.1 Les deux seuls sites de déclenchement

| Site | Chemin | Garde de mode (**existante**, aucune garde nouvelle) |
|---|---|---|
| `smartclim::rafraichirLan()` (l. 1059) | cycle LAN, 15 min | `smartclimTransport::sondeLanAutorisee()` |
| `smartclim::sonderAppareilLan()` (l. 4724) | interactif : `envoyerOrdreLan()` (l. 4510) **et** `rafraichirLanEquipement()` (l. 4426) | `smartclimTransport::transportRetenu()` en amont |

Les deux couvrent l'**AC1** dans ses deux branches — « repli cloud de l'UC02 en AUTO » et « signaler un échec
en LOCAL ».

### 2.2 Où la redécouverte n'est **pas** déclenchée — et pourquoi

- **Pas dans `smartclimTransport`.** `lanJoignable()` est appelée par `transportRetenu()`, donc sur **toutes**
  les commandes de **tous** les équipements, y compris ceux qui partent au cloud. Y glisser une sonde
  mettrait 3 s d'UDP sur un chemin cloud qui n'aboutira jamais sur un parc sans Broadlink, et ferait perdre à
  cette classe sa propriété structurante — *aucune E/S, vérifiable par lecture*. **Fichier non modifié.**
- **Pas dans `sonderAdresseLan()`** (l. 984). Elle a **deux** appelants : `rafraichirLan()` *et* la phase 2 de
  `scannerReseauLocal()`. Or le scan a **déjà diffusé** en phase 1 — une diffusion ici en ferait une seconde
  par scan. **Signature et corps inchangés** ; la redécouverte vit chez les appelants.
- **Pas dans `scannerReseauLocal()`** (cf. § 0.2).
- **Pas sur la branche « aucune adresse connue »** (`adresseLan()['ip'] === ''`, exception
  `CONTEXTE_LAN_ADRESSE_INCONNUE`). Un équipement sans adresse n'est pas « injoignable », il est « jamais
  découvert » : le **scan** reste son vecteur (UC01 du domaine 01, § 6). Exception unique et bornée : la
  règle **(b)** du § 5.2.

### 2.3 Constantes

```php
const BUDGET_REDECOUVERTE_LAN        = 3;  // s
const RESERVE_APRES_REDECOUVERTE_LAN = 6;  // s
```

**`BUDGET_REDECOUVERTE_LAN` = 3 s, et 3 s exactement** — vérifié dans
`smartclimBroadlinkLan::diffuserParExtensionSockets()` (l. 1009-1045) et son jumeau `diffuserParFluxNatif()` :
la boucle est `while ((microtime(true) - $debut) < $_budget)` et le second envoi du hello exige
`$ecoule >= INTERVALLE_RENVOI` (2 s). **Avec un budget de 2 s, la retransmission ne part jamais** — la
condition de boucle et celle de l'envoi sont mutuellement exclusives à cet instant. 3 s délivrent les deux
émissions (t=0, t≈2 s) et laissent ≈ 1 s d'écoute après la seconde. C'est la **retransmission** qui compte
contre la perte UDP, pas la queue d'écoute — d'où le choix de 3 s plutôt que du défaut `FENETRE_DECOUVERTE`
= 4 s, qui coûterait une seconde de plus sur un chemin interactif sans rien délivrer de neuf.

⚠️ **M2 — commentaire croisé obligatoire.** Trois constantes de « réserve » coexistent, et deux seulement
sont arithmétiques :

| Constante | Valeur | Nature |
|---|---|---|
| `smartclimBroadlinkLan::RESERVE_ECRITURE` | 3 s | **arithmétique** — refus d'émettre un ordre sous ce reliquat |
| `smartclim::RESERVE_ECRITURE_LAN` | 3 s | **purement documentaire** — jumelle de la précédente, jamais lue |
| `smartclim::RESERVE_APRES_REDECOUVERTE_LAN` | 6 s | **arithmétique** — introduite ici |

La nouvelle constante porte un commentaire nommant les deux autres, sur le modèle de celui déjà en place aux
lignes 152-157.

### 2.4 Fichiers

| Chemin | État | Contenu | Format |
|---|---|---|---|
| `core/class/smartclim.class.php` | **modifié** | 2 constantes ; 5 méthodes privées nouvelles (§ 4) + 1 publique de diagnostic (§ 9) ; `sonderAppareilLan()` et `rafraichirLan()` modifiées ; `sondeLanEquipement()` refactorisée **sans changement de comportement** | **2 espaces, CRLF** |
| `core/php/commande-lan.php` | **modifié** | 4ᵉ usage `--redecouvrir` + en-tête d'usage | **2 espaces, CRLF, sorties et commentaires FR SANS ACCENTS** (convention observée dans le fichier : « reserve », « Echecs », « Derniere ») |
| `docs/fr_FR/reseau-local.md` | **créé** | ~25 lignes, **AC6** | UTF-8 accentué |
| `docs/fr_FR/index.md` | **NON touché** | décision **A4** — reprise au domaine 07 | — |
| `core/php/smartclim.inc.php` | **NON touché** | ⚠️ **aucune classe nouvelle**, donc aucun `require_once`. *Si l'implémentation devait malgré tout extraire une classe, la ligne y devient obligatoire : l'oubli ne casse ni `php -l`, ni la CI, ni les reviews — seulement le runtime* | — |
| `smartclimTransport`, `smartclimBroadlinkLan`, `smartclimFrame`, `smartclimCapabilities`, `smartclimAuxHomeApi` | **NON touchés** | `decouvrir()` / `interroger()` utilisées telles quelles, avec leur paramètre `$_budget` existant | — |
| `desktop/php/smartclim.php`, `desktop/js/smartclim.js` | **NON touchés** | ⚠️ **vérifié** : `etatConnexionAffichable()` (l. 3400-3420) recalcule `lan` / `lanAdresse` **au rendu**, depuis `sondeLanEquipement()`. La nouvelle adresse apparaît donc seule dans la ligne « Réseau local », **sans une ligne de JS ni de HTML** — et sans rouvrir le piège `.text(undefined)` des 8 points d'écriture | — |
| `core/ajax/smartclim.ajax.php` | **NON touché** | **aucune surface web** | — |
| `core/config/smartclim.config.ini` | **NON touché** | les deux budgets sont des **constantes**, pas des clés de config : une clé imposerait un champ de formulaire, un `preConfig_`, la duplication du défaut dans l'INI et la double barrière écriture/lecture, pour un réglage qu'aucun scénario utilisateur ne justifie | — |
| `plugin_info/configuration.txt` + `.php` | **NON touchés** | rien au formulaire **plugin** → **pas de `cp` de synchronisation** | — |
| `plugin_info/install.php` | **NON touché** | **aucune migration** : tout l'état nouveau est un **cache existant**, dont l'absence vaut « adresse inconnue » | — |
| `packages.json`, `resources/`, démon | **NON touchés** | invariant « le démon est latéral » préservé : aucune méthode de cette UC ne référence `smartclimDemon` | — |

---

## 3. Server vs Client

**Tout est serveur, sans exception, et il n'y a aucun client.** La redécouverte est un mécanisme d'infra
déclenché par `plugin::cron` et par le chemin de commande ; l'IHM ne l'expose pas, ne la déclenche pas et
n'en connaît pas l'existence. Aucune action AJAX nouvelle, aucun endpoint, aucune surface web — donc **aucune
entrée utilisateur à valider** sur ce chemin.

Le seul « affichage » est un effet de bord gratuit : l'IHM relit la mémoire de sonde au rendu et montre donc
la nouvelle adresse d'elle-même (§ 2.4).

---

## 4. Signatures — `core/class/smartclim.class.php`

### 4.1 `private static function decouverteParMac($_budget)`

Renvoie `array<string,array>` : MAC imprimable ⇒ ligne normalisée. Appelle
`smartclimBroadlinkLan::decouvrir($_budget)` et indexe le résultat par `mac`.

**Ne lève JAMAIS.** Deux rattrapages, **journalisant tous les deux** (M1) :

```
catch (smartclimException $e)  → warning « aucun chemin de diffusion / diffusion impossible »  → array()
catch (Throwable $e)           → warning « erreur interne pendant la redécouverte »            → array()
```

N'écrit **rien** : ni cache, ni base, ni disque.

⚠️ `decouvrir()` ne lève que `TYPE_INTERNE`, et seulement quand **ni** l'extension `sockets` **ni**
`stream_socket_server` n'est disponible (vérifié l. 170-182). Une diffusion filtrée par un VLAN ne lève pas :
elle renvoie un tableau vide, ce qui est le comportement attendu par l'**AC5**.

### 4.2 `private static function appareilRedecouvert(smartclim $_eqLogic, array $_parMac, array &$_adoptes)`

Renvoie `array|null`. **Fonction de rapprochement pure** — elle ne fait aucune E/S, n'écrit aucun cache et
**ne touche JAMAIS la 6ᵉ mémoire**.

1. Parcourt `$_eqLogic->macsCandidatesLan()` (§ 4.5) dans **son ordre réel**, qui est **interfolé** —
   `lan_mac`, inverse(`lan_mac`), `macEquipement()`, inverse(`macEquipement()`) — et **non** « tous les
   directs avant tous les inversés ». ⚠️ **Correction du plan, établie contre le code à l'étape de
   vérification du livrable** : cette formulation avait été reprise par analogie de
   `chercherEquipementExistant()`, qui est un **autre** algorithme. Conséquence à assumer, et elle est
   voulue : une `lan_mac` **saisie par l'utilisateur** prime sur `macEquipement()` **y compris par son
   inverse**, ce qui est cohérent avec la priorité « personnalisé avant détecté » qui gouverne tout le
   plugin. ⚠️ Corollaire pour toute évolution : la détection « adopté via une MAC inversée » ne peut donc
   **pas** se faire en comparant au seul premier candidat — elle se fait par **position relative** dans la
   liste (le membre direct est toujours inséré avant son inverse par la boucle de construction), seul
   critère robuste quel que soit l'ordre effectif et quels que soient les doublons sautés.
2. Retient le **premier** candidat présent dans `$_parMac` (`decouvrir()` dédoublonne déjà par MAC : au plus
   un appareil par MAC).
3. **Garde d'ambiguïté (A3)** : si `$_parMac` contient **à la fois** une MAC candidate directe et sa
   candidate inversée, **portées par deux appareils distincts** → `null` + `warning` nommant les **deux** MAC
   et les **deux** IP. L'échec est confirmé comme si rien n'avait été trouvé.
4. **Garde de doublon** : un appareil déjà adopté par un autre équipement dans la même passe
   (`$_adoptes[$mac]`) → `null` + `warning`. Calquée sur `$rapprochesLan` de `scannerReseauLocal()`.
5. Adoption via une MAC **inversée** → `warning`, comme `chercherEquipementExistant()`.
6. Adoption → `$_adoptes[$mac] = true`, retour de la ligne.

⚠️ Les deux gardes vivent **ici, ensemble** : elles répondent à la même question (« puis-je adopter cette
ligne pour cet équipement ? ») et ne doivent pas diverger. L'appelant interactif passe un `$adoptes` **local
vide** — la garde y est un no-op assumé, pas un oubli.

⚠️ **Ne pas réutiliser `chercherEquipementExistant()`** : elle va **appareil → équipement** (avec un `$index`,
et peut aboutir à une **création**). Ici le sens est **équipement → appareil**.

### 4.3 `private function sonderAppareilLan($_budget)` — MODIFIÉE, signature inchangée

Ordre impératif :

1. `adresseLan()` ; `ip === ''` → `throw` `CONTEXTE_LAN_ADRESSE_INCONNUE` — **inchangé, aucune diffusion** ;
2. `interroger()` ; succès **et** MAC concordante → `return` — **chemin nominal strictement inchangé** ;
3. les deux branches d'échec (réponse `null`, puis MAC divergente) **mémorisent leur message, type et
   contexte d'origine** et tombent dans une tentative **unique** de redécouverte ;
4. fenêtre = `min(BUDGET_REDECOUVERTE_LAN, restant − RESERVE_APRES_REDECOUVERTE_LAN)` ; **si < 1 s, aucune
   diffusion** et l'exception d'origine part immédiatement ;
5. `decouverteParMac()` → `appareilRedecouvert()` ; `null` → `throw` **l'exception d'origine** (mêmes
   message, type et contexte : `messageErreurLan()` n'est **pas** touchée, **aucune chaîne nouvelle**) ;
6. succès → `memoriserAdresseLan($this, $trouve)` (**AC2**), journalisation (§ 7), `return $trouve`.

⚠️ La ligne rendue porte `octets_mac` et `type_appareil`, exigés par `appliquerOrdre()` : **rien en aval ne
change**.

⚠️ Le compteur d'échecs reste écrit par les `catch` des **appelants** (`envoyerOrdreLan()`,
`rafraichirLanEquipement()`) : **aucun** des 16 points de l'UC02 n'est déplacé sur ce chemin.

### 4.4 `private static function rafraichirLan()` — MODIFIÉE, signature inchangée

Le tableau de retour gagne une clé `redecouverts:int`. ⚠️ **Vérifié** : son seul appelant est `cron()`
(l. 2421), qui **ignore la valeur de retour** ; seul le `log::add('debug')` interne (l. 1140) la consomme.

Deux variables locales **en tête de boucle** :

```php
$decouverts = null;   // null = diffusion PAS ENCORE TENTÉE dans ce cycle
$adoptes    = array();
```

⚠️ **`$decouverts === null` est le seul mécanisme qui garantit « au plus une diffusion par cycle ».** Un
tableau vide signifie « diffusion tentée, rien trouvé » et ne doit **jamais** relancer une diffusion.

Modifications, dans l'ordre du corps actuel :

- **l. ~1084**, `adresse['ip'] === ''` → `continue` **sauf** règle **(b)** (§ 5.2) ;
- **l. ~1095**, `appareil === null` : **avant toute conclusion**, remplir `$decouverts` s'il vaut `null`
  (fenêtre = `min(BUDGET_REDECOUVERTE_LAN, restant)`, `< 1` → tableau vide), puis `appareilRedecouvert()`.
  - **Trouvé** → `$resultat['redecouverts']++`, on **reconstruit**
    `$resultatSonde = array('appareil' => $trouve, 'statut' => '', 'mac' => $trouve['mac'])` et **on poursuit
    le corps existant** (donc `lireEtat()`, donc `noterOperationLan()`, donc la 6ᵉ mémoire — par le chemin
    normal, cf. § 6.1) ;
  - **Non trouvé** → `$resultat['injoignables']++`, puis **et seulement là**
    `memoriserEchecLan(STATUT_INJOIGNABLE)`, conditionné au statut comme aujourd'hui ;
- **l. ~1109** : `memoriserSondeLan($resultatSonde['mac'], …)` devient
  `memoriserSondeLanEquipement($eqLogic, $resultatSonde['mac'], …)` (§ 4.6). **Contenu du tableau
  inchangé.**

⚠️ Tout le code nouveau est **dans le `try/catch (Throwable)` par équipement existant** : `rafraichirLan()`
continue de **ne jamais lever**. ⚠️ Aucun `appliquerCapacites()`, aucun `save()`, aucun `basculerHorsLigne()`
n'est ajouté.

### 4.5 `private function macsCandidatesLan()` → `string[]`

**Extraction stricte** des lignes 3159-3179 de `sondeLanEquipement()` : pour **chaque** candidat
(`lan_mac` saisie, puis `macEquipement()`), la MAC **puis son inverse** — d'où un ordre **interfolé**, et non
« tous les directs puis tous les inversés » (cf. § 4.2, point 1). Dédoublonné, **palindrome sauté**.
`sondeLanEquipement()` devient une boucle sur cette liste.

⚠️ **Refactor à comportement rigoureusement identique**, sur un chemin recetté par lecture seulement — à
vérifier ligne à ligne en review. **Vérifié en revue de plan** : la plage 3159-3179 est de la construction
pure, sans effet de bord ni retour anticipé (le `return` vit dans la boucle **suivante**, l. 3181-3187, hors
extraction).

Motif : **un seul endroit connaît l'ordre des candidats**, désormais consommé par **trois** lecteurs — la
sonde, le rapprochement de redécouverte (§ 4.2) et la résolution de clé d'écriture (§ 4.6).

### 4.6 `private static function memoriserSondeLanEquipement(smartclim $_eqLogic, $_macTrouvee, array $_resultat)` → `void`

Résout la **clé d'écriture** : première MAC de `macsCandidatesLan()` possédant **déjà** une entrée de cache ;
à défaut `$_macTrouvee` (comportement actuel). Puis délègue à `memoriserSondeLan()`.

⚠️ **Ce n'est pas cosmétique — c'est la correction d'un défaut pré-existant, confirmé en revue de plan.**
Tracé sur les appelants réels de `memoriserSondeLan()` :

| Écriture | Clé utilisée aujourd'hui |
|---|---|
| **Échecs** — `sonderAdresseLan()` l. 1001 et 1022 | `$macAttendue` = `adresseLan()['mac']`, donc **toujours le candidat direct** |
| **Succès** — `scannerReseauLocal()` l. 784/893, `rafraichirLan()` l. 1109 | MAC **réellement rapportée par l'appareil**, qui peut être l'**inversée** |

Or `sondeLanEquipement()` essaie les directs **avant** les inversés et **retourne à la première entrée
trouvée**. Sur un appareil concerné par le quirk d'inversion Broadlink, deux entrées coexistent et **c'est
toujours celle des échecs qui est relue** : la nouvelle adresse serait écrite là où **personne ne la lit**, et
**AC2/AC4 seraient faux en silence** — le LAN n'étant recettable sur aucun matériel climatiseur.

La résolution converge les deux entrées vers une seule et **ne peut jamais en créer une nouvelle quand une
existe**.

⚠️ **Portée volontairement limitée à `rafraichirLan()` et au chemin interactif.** Les écritures de
`scannerReseauLocal()` **ne sont pas touchées** : l'UC02 § 12.1 (M4) a explicitement refusé le point
d'écriture unique, et un scan est de toute façon immédiatement suivi d'une lecture complète. Résidu en
dette (§ 12, D-2).

### 4.7 `private static function memoriserAdresseLan(smartclim $_eqLogic, array $_appareil)` → `void`

Cas « adresse redécouverte, **pas encore lue** » — le chemin **interactif**, où aucun `lireEtat()` ne suivra.
Écrit via `memoriserSondeLanEquipement()`.

| Champ | Origine |
|---|---|
| `ip`, `port`, `type_appareil`, `nom`, `verrouille`, `vu_le` | la ligne **fraîchement découverte** |
| `statut`, `echec_le` | **reportés de l'entrée précédente** |

⚠️ **Règle en une phrase, symétrique exacte du correctif d'UC02 sur `sonderAdresseLan()`** (« seuls `statut`
et `echec_le` sont neufs ») : *ici, tout est neuf SAUF `statut` et `echec_le`.* **Une redécouverte prouve une
adresse, jamais une capacité.** Écrire un statut inventé casserait `lanJoignable()`, qui exige
`STATUT_ETAT_LU` : un statut de complaisance ferait router des ordres vers un appareil non vérifié, un statut
d'échec dégraderait un équipement joignable.

⚠️⚠️ **Nuance relevée en revue de sécurité, à ne pas lire trop vite** : « ne pose jamais de statut » signifie
**n'invente jamais** un statut — **pas** que le statut écrit est neutre. Le statut **reporté** peut être un
`STATUT_ETAT_LU` hérité de l'ancienne adresse, qui se retrouve donc momentanément porté par une adresse dont
**seule la MAC** a été vérifiée, jamais la capacité HVAC. Cette fenêtre est **fermée de façon synchrone par
tous les appelants actuels** — `rafraichirLan()` enchaîne `lireEtat()` + `noterOperationLan()` dans la même
passe, `envoyerOrdreLan()` et `rafraichirLanEquipement()` concluent par `memoriserSuccesLan()` /
`memoriserEchecLan()` — et se limite donc à quelques secondes à l'intérieur d'un seul processus PHP. ⚠️ **Ce
n'est pas une propriété de `memoriserAdresseLan()`, c'est une obligation de ses appelants** : tout futur
appelant de `sonderAppareilLan()` qui ne conclurait pas par une opération LAN réelle laisserait un
`STATUT_ETAT_LU` non vérifié en cache, et `lanJoignable()` routerait des ordres sur cette seule foi. Risque
résiduel accepté (il suppose un attaquant déjà capable de répondre à la diffusion avec la bonne MAC, cf. R3),
mais **à re-vérifier à chaque nouvel appelant**.

⚠️ **Unique exception** : si le statut précédent est `STATUT_MAC_DIVERGENTE`, écrire `''`. Un verdict de
divergence porte sur l'**ancienne** adresse ; le reporter sur une adresse dont on vient de vérifier la MAC
serait factuellement faux.

---

## 5. Éligibilité à la redécouverte dans le cycle

### 5.1 Cas nominal

Un équipement est candidat quand **il a une adresse connue** et que la sonde unicast a échoué. C'est le seul
cas courant.

### 5.2 Règle (b) — l'équipement éjecté par `STATUT_MAC_DIVERGENTE`

Un équipement **sans adresse** (`adresseLan()['ip'] === ''`) est normalement `continue`é. **Exception unique**
: si sa mémoire de sonde existe et porte `statut === smartclimBroadlinkLan::STATUT_MAC_DIVERGENTE`, il ne
sort pas — il va directement à la redécouverte.

⚠️ **Vérifié atteignable en revue de plan** : `sonderAdresseLan()` (l. 1022-1026) écrit bien
`STATUT_MAC_DIVERGENTE` **avec `ip => ''`** sous `$macAttendue`, qui est **exactement** le premier candidat
testé par `sondeLanEquipement()`. L'entrée est donc relisible sous sa forme actuelle, sans même attendre
§ 4.6.

**Aucune autre situation ne lève ce `continue`.** Corollaire de recette : sur l'installation de
l'utilisateur, **aucun** équipement n'a de mémoire de sonde, donc aucune diffusion n'est jamais déclenchée par
le cycle (§ 0.1).

---

## 6. Interaction avec l'UC02 — ce qui bouge, ce qui ne bouge pas

### 6.1 La 6ᵉ mémoire n'est jamais écrite par la redécouverte

`decouverteParMac()` et `appareilRedecouvert()` n'appellent **ni** `noterOperationLan()`, **ni**
`memoriserEchecLan()`, **ni** `memoriserSuccesLan()`. La redécouverte établit une **adresse**, pas une
**joignabilité** — seul le `lireEtat()` qui suit, par `noterOperationLan()`, fait varier le compteur.

**L'invariant tient** : `0 < lan < SEUIL_ECHECS_LAN` implique toujours qu'un `STATUT_ETAT_LU` a été constaté
dans les 24 h, puisque **aucun `memoriserSuccesLan()` n'est ajouté hors de `noterOperationLan()`**.

### 6.2 Le décompte à 16 points est préservé

⚠️ **Vérifié** : `memoriserEchecLan(STATUT_INJOIGNABLE)` n'a **qu'un seul site** dans tout le fichier
(l. 1102). Ce site **change de place** (il passe après la tentative de redécouverte et n'est exécuté que si
elle a échoué) — il n'est **ni ajouté ni retiré**. Le décompte de l'UC02 § 5.3 reste à **16 points**.

### 6.3 M4 — AC3 ne se lit pas de la même façon en LOCAL et en AUTO

Après une redécouverte **interactive** réussie, `memoriserAdresseLan()` **ne pose jamais** `STATUT_ETAT_LU`
(§ 4.7). Or `smartclimTransport::lanJoignable()` l'exige pour redevenir vrai hors du cas `echecs > 0`.

Conséquence, à écrire noir sur blanc pour tout lecteur futur :

- **En mode LOCAL** — le routage ne passe pas par `lanJoignable()` : la commande suivante utilise directement
  la nouvelle adresse. La branche « ou à la prochaine commande » de l'**AC3** est vraie.
- **En mode AUTO** — c'est le **cycle** `rafraichirLan()` qui referme réellement `lanJoignable()`, parce qu'il
  enchaîne redécouverte **puis** une vraie `lireEtat()` + écriture de sonde dans la **même passe**. Le retour
  au LAN s'y fait donc dans les **≤ 15 min** prévus par l'AC3, pas à la première commande.

**Aucun AC n'est enfreint.** Ne pas « corriger » cela en posant un statut à la redécouverte : ce serait
exactement l'erreur que le § 4.7 interdit.

### 6.4 La R8 de l'UC02 — traitée

> « Une adresse IP périmée est désormais martelée indéfiniment (une trame UDP toutes les 15 min)…
> Contrainte transmise à l'UC03. »

**Le martèlement n'est pas supprimé — il est rendu convergent, et son coût borné à O(1) par cycle.**

1. Le hello unicast de 2 s toutes les 15 min **est le détecteur** du changement : le supprimer supprimerait
   le déclencheur.
2. Dès le **premier** cycle en échec, une diffusion tranche : soit l'appareil est retrouvé (l'adresse périmée
   cesse immédiatement d'être martelée), soit il est démontrablement absent. Le martèlement n'est plus
   indéfini *sur une adresse fausse*.
3. Coût résiduel du cas « appareil physiquement retiré » : 2 s d'unicast **par équipement** + **3 s de
   diffusion pour tout le parc**, toutes les 900 s. Sur 9 appareils morts : ≈ 21 s / 900 s = **2,3 %** de
   rapport cyclique dans `plugin::cron`, contre 2 % avant — la diffusion est **mutualisée**, elle ne monte
   pas avec N.
4. **Aucune règle d'abandon n'est ajoutée**, délibérément : abandonner rendrait le retour automatique au LAN
   impossible et contredirait l'UC02 § 7.3. Le remède d'un appareil définitivement retiré est utilisateur —
   supprimer l'équipement, ou le passer en CLOUD.

### 6.5 La dette D-1 de l'UC02 se referme

`STATUT_MAC_DIVERGENTE` **efface l'IP** (invariant conservé, cf. `CLAUDE.md`), ce qui éjectait définitivement
l'équipement du cycle jusqu'à un scan manuel. Or **la divergence de MAC est la signature même d'un bail DHCP
réattribué** : l'adresse héberge désormais un autre appareil.

Deux mécanismes la referment : **(a)** la divergence déclenche la redécouverte **dans la même passe**, avant
que l'effacement n'ait de conséquence ; **(b)** si cette passe échoue (diffusion bloquée), la règle (b) du
§ 5.2 garde l'équipement éligible aux cycles suivants. L'entrée de sonde survivant 24 h et étant réécrite à
chaque passe, la fenêtre de réarmement ne se referme pas.

⚠️ La revue de plan a relevé que **§ 4.6 est aussi nécessaire à cette fermeture** : sans la résolution de
clé, une entrée `STATUT_MAC_DIVERGENTE` écrite sous la clé directe ne serait jamais convergée avec le succès
de redécouverte qui pourrait suivre sous une autre clé.

---

## 7. Journalisation (français, hors `__()` — ce sont des logs)

Une **condition unique** gouverne le bruit : `ip retrouvée !== ip enregistrée dans la mémoire de sonde`.

| Niveau | Message | Condition |
|---|---|---|
| `info` | `Équipement "X" : adresse IP locale mise à jour automatiquement (a.b.c.d -> e.f.g.h)` | adresse changée |
| `warning` | `l'adresse IP locale SAISIE (a.b.c.d) ne répond plus, appareil retrouvé en e.f.g.h — mettez à jour la configuration de l'équipement (l'adresse saisie reste prioritaire)` | adresse changée **et** `adresseLan()['source'] === 'manuel'` |
| `warning` | ambiguïté (les deux MAC, les deux IP), doublon, adoption par MAC inversée | § 4.2 |
| `warning` | échec de diffusion, **dans les deux branches de rattrapage** (M1) | § 4.1 |
| `debug` | aucune correspondance, budget insuffisant, résumé de cycle enrichi de `redecouverts` | — |

⚠️ « Aucune correspondance » est en **`debug` et jamais en `warning`** : sur un appareil réellement retiré,
cela se répéterait toutes les 15 minutes.

Noms d'équipement toujours passés à `neutraliserPourLog()`. Aucune IP ni MAC n'est un secret (elles sont déjà
journalisées par le scan et la sonde) ; **aucun jeton ni clé de session n'est atteignable sur ce chemin**.

---

## 8. Documentation — AC6

**`docs/fr_FR/reseau-local.md`**, page courte (~25 lignes), en français accentué, couvrant :

1. comment l'adresse locale du climatiseur est trouvée (scan, puis mémorisée) ;
2. ce qui se passe si elle change (redécouverte automatique, aucune action requise) ;
3. **la recommandation de réserver l'adresse IP du climatiseur dans la box ou le serveur DHCP** — c'est le
   point que l'**AC6** exige explicitement ;
4. le secours en réseau segmenté (VLAN, diffusion bloquée) : saisir l'IP à la main dans la configuration de
   l'équipement, **et sa contrepartie** — une adresse saisie est prioritaire et n'est jamais corrigée
   automatiquement, donc à mettre à jour soi-même si elle change.

⚠️ **M3 — raccroc temporaire assumé.** `docs/fr_FR/index.md` n'est **pas** modifié (décision A4) : il est
encore l'index brut du squelette `jeedom/plugin-template`. **Reprise à faire au domaine post-mvp/07 UC02**
« documentation utilisateur » : y poser le lien vers `reseau-local.md` lors de la refonte du sommaire.

---

## 9. Instrument de constat — `core/php/commande-lan.php --redecouvrir`

**4ᵉ usage** de la CLI, sur le patron exact des trois autres : garde `php_sapi_name() === 'cli'` **avant tout
`require_once`**, aucun POST, **aucune écriture en base, en cache ni sur disque**, sorties FR **sans `__()`
et sans accents**.

Implémentation : un `elseif` dans la boucle d'arguments existante, un bloc d'affichage **avant** la garde
`--equipement`, l'en-tête d'usage complété.

Il appelle **`smartclim::diagnosticRedecouverteLan()`** :

```php
public static function diagnosticRedecouverteLan()
// → array{diffusion:bool, decouverts:int, lignes:array}
```

Une diffusion, puis pour chaque équipement de mode ≠ CLOUD : nom neutralisé, mode, MAC candidates, adresse
connue et sa source, MAC/IP retrouvées, et un **code de verdict** parmi
`aucune_correspondance | identique | changement | ambigu | deja_adopte` — **la CLI faisant seule la mise en
français**. Même patron que `diagnosticTransport()`, `lireTrameAuxHome()` et `sonderIntentAuxHome()` : **une**
méthode publique plutôt que l'ouverture de six accesseurs privés.

⚠️ **Le commentaire d'en-tête doit distinguer les deux usages de diagnostic** : `--transport` n'émet **aucun**
paquet réseau, `--redecouvrir` émet **une** diffusion. Ne pas laisser croire que ce fichier est resté
entièrement passif.

⚠️ À lancer sous `www-data` (`sudo -u www-data php …`), comme les trois autres.

---

## 10. Validation & erreurs

| Situation | Où | Comportement |
|---|---|---|
| Aucun chemin de diffusion sur l'hôte (`TYPE_INTERNE`) | `decouverteParMac()` | Rattrapé, `warning`, vaut « 0 appareil » → l'échec d'origine est confirmé. **AC5** |
| Diffusion filtrée par le réseau (VLAN) | idem | 0 appareil, aucun bruit `error`. La `lan_ip` saisie reste opérante et **prioritaire**. **AC5** |
| Budget insuffisant pour diffuser | `sonderAppareilLan()` étape 4 / `rafraichirLan()` | Aucune diffusion ; `throw` d'origine ou `continue`, comme aujourd'hui. `debug` |
| Aucune MAC candidate ne correspond | `appareilRedecouvert()` | `null` + `debug` |
| Ambiguïté directe/inversée | `appareilRedecouvert()` | **Refus**, `warning` nommant les deux MAC et les deux IP |
| Appareil déjà adopté dans ce cycle | `appareilRedecouvert()` | **Refus**, `warning` |
| Adoption via MAC inversée | `appareilRedecouvert()` | Adopté + `warning` |
| Commande LAN après une redécouverte échouée | `envoyerOrdreLan()` | **Messages existants** de `messageErreurLan()`, types et contextes **inchangés**. Le compteur est écrit par le `catch` existant, `CONTEXTE_LAN_MAC_DIVERGENTE` restant un **no-op** (UC02 § 12.1 M2) |
| Erreur PHP interne pendant la redécouverte du cycle | `catch (Throwable)` par équipement **existant** | Comptée dans `$resultat['erreurs']`, **jamais** dans le compteur d'échecs |
| Mémoire de sonde absente ou corrompue | `sondeLanMemorisee()` (existant) | `null` → aucune clé résolue → écriture sous `$_macTrouvee`, comportement actuel |

**Aucun type d'exception nouveau, aucun contexte nouveau, aucun statut LAN nouveau** : `libelleStatutLan()`
n'est pas touchée. `catch (Throwable)` reste en **dernier bloc** partout.

---

## 11. Server Actions / API, dépendances et i18n

**Aucune action AJAX, aucun endpoint, aucune dépendance.** `packages.json` n'est pas touché ; aucun paquet
système ou pip n'est requis ; le démon n'est ni appelé ni référencé.

**Impact i18n : aucune clé nouvelle. Zéro.** Vérifiable — `python .claude/scripts/verif-plugin.py --tous` ne
doit remonter **aucune** clé manquante après ce cycle.

- Les messages d'erreur utilisateur sont **ceux déjà en place** dans `messageErreurLan()` : la redécouverte ne
  change ni le type, ni le contexte, ni le message levés.
- Les textes nouveaux sont des **logs** (français, sans `__()`) et des **sorties CLI** (français sans `__()`
  ni accents).
- `docs/fr_FR/*.md` est hors du périmètre du scan d'extraction i18n.
- L'IHM n'est pas modifiée : aucun `{{…}}` nouveau.

⚠️ **Aucune méta-séquence littérale** dans les nouveaux docblocks (`*/` collé à du texte, balise fermante
PHP) : ils parlent de MAC, de trames et de budgets — terrain propice. Lancer `verif-plugin.py` (colonne
`meta=`) **avant** commit.

---

## 12. Recette

**Partiellement observable sur l'installation de l'utilisateur** — c'est nouveau dans ce domaine : le
`brief.md` § 19 établit que **deux RM4 Pro répondent à la diffusion** sur son réseau.

1. `sudo -u www-data php core/php/commande-lan.php --redecouvrir` → la diffusion fonctionne, les deux RM4
   apparaissent, **aucun équipement n'est rapproché** (verdict `aucune_correspondance`) : le chemin négatif et
   le refus d'adopter un appareil étranger sont démontrés.
2. Renseigner **temporairement** `lan_mac` d'un équipement de test avec la MAC d'un RM4, relancer
   `--redecouvrir` → verdict `changement`, **sans aucune écriture** (la commande est en lecture seule) ; puis
   avec la MAC **inversée**, vérifier l'avertissement « MAC inversée ». **Retirer `lan_mac` après essai.**
3. **Non-régression — le point le plus important** : en AUTO sans matériel Broadlink, `--transport` doit
   rendre exactement le même rapport qu'avant, le cron cloud tourner à l'identique, et
   `grep 'Redécouverte\|diffusion' log/smartclim` rester **vide** — aucune diffusion ne doit jamais partir
   sur une installation sans mémoire de sonde (§ 0.1).

**Non vérifiable ici** : AC1 à AC5 dans leur partie « appareil retrouvé **et piloté** » — le climatiseur de
validation ignore le protocole Broadlink. Vérifiés **par lecture** contre `mjg59/python-broadlink` (MIT) et
les specs techniques du domaine 01. Statut hérité de la R1 de l'UC01 de ce domaine.

---

## 13. Risques

- **R1 — Chemin non recettable sur du matériel climatiseur.** Hérité. Atténué, pour la première fois de ce
  domaine, par le § 12 qui exerce réellement la diffusion et le rapprochement.
- **R2 — La clé d'écriture de la mémoire de sonde est un défaut PRÉ-EXISTANT, révélé ici.** Sur un appareil
  dont la MAC Broadlink est l'inverse de `configuration.mac`, les entrées d'échec masquent celles de succès —
  donc `lanJoignable()` ne peut **jamais** devenir vrai pour cet appareil, **indépendamment de cette UC**.
  C'est la dette D-2 de l'UC02, avec une conséquence plus dure que décrite. Le § 4.6 la corrige sur les
  chemins touchés ici ; les écritures du scan restent en l'état (§ 12, D-2). ⚠️ Le refactor du § 4.5 doit
  être à comportement **rigoureusement** identique — à re-vérifier ligne à ligne en review.
- **R3 — Le rayon d'impact de la dette D-1 de l'UC01 (domaine 01) grandit d'un cran.** L'adoption repose sur
  la **MAC seule** — critère déjà en place (le scan crée même un équipement sur cette base) — mais elle
  devient **automatique, en tâche de fond**. Un attaquant présent sur le LAN et usurpant la MAC reçoit nos
  ordres HVAC et peut forger l'état affiché. Aucun secret n'est en jeu (la trame ne porte aucun identifiant),
  le périmètre reste celui, déjà accepté, du LAN local. Durcissement : `smartclimFrame::estTrameHvac()`
  **bloquant sur le ROUTAGE**, jamais sur le décodage — exige du matériel (§ 14, D-1).
- **R4 — Contrôle de `type_appareil` écarté, sciemment.** Il semblait durcir l'adoption ; il serait
  **inopérant dans le cas visé** — `STATUT_MAC_DIVERGENTE` efface justement `type_appareil` de la mémoire,
  donc la garde serait sautée exactement là où elle servirait. *Un prédicat ne vaut que par les entrées sur
  lesquelles il se prononce.*
- **R5 — `lan_ip` saisie et périmée : ça marche, mais ça coûte à chaque passage.** `adresseLan()` renvoyant la
  valeur saisie de façon **inconditionnelle** (l'AC5 en dépend), la redécouverte est rejouée à **chaque**
  cycle et à **chaque** commande pour cet équipement : ≈ 5 s par opération. Le pilotage local continue, un
  `warning` actionnable est émis (§ 7), et l'IHM affiche l'adresse **détectée** à côté d'une configuration
  obsolète. *Écarté* : effacer ou réécrire automatiquement `lan_ip` — cela détruirait silencieusement un
  réglage utilisateur et imposerait un `save()` dans le cron (donc `postSave()`, donc `creerCommandes*()` à
  chaque cycle), l'anti-patron explicitement refusé par l'UC02 § 2.1 (c1).
- **R6 — Dépassement de budget hérité, légèrement aggravé.** Le plancher `max(1, …)` par échange (dettes D-1
  d'UC02 et D-1 d'UC03 du domaine 01) fait qu'`envoyerOrdreLan()` peut dépasser `BUDGET_ORDRE_LAN` dans le
  pire cas ; la redécouverte consomme désormais une part du budget en amont. Garde-fou : la fenêtre est
  calculée **contre le restant**, réserve de 6 s déduite, et **abandonnée sous 1 s**.
- **R7 — Appareil définitivement retiré : coût cyclique indéfini.** 2 s d'unicast par équipement + 3 s de
  diffusion pour tout le parc, toutes les 15 min. Borné et **O(1) en diffusion** (§ 6.4). À rouvrir si une
  recette matérielle montre une gêne.
- **R8 — Trou connu de la CLI, étendu.** `commande-lan.php --commande=…` force le LAN, y compris sur un
  équipement en mode CLOUD (R4 de l'UC01, assumée) : il déclenchera désormais aussi une diffusion. Outil
  admin/SSH nommé pour ce qu'il fait ; conservé.
- **R9 — Deux écritures de cache successives sur la même passe** (issue terminale de `sonderAdresseLan()`,
  puis écriture post-lecture après redécouverte). **Voulu** : la seconde gagne, et l'ordre garantit qu'un
  `MAC_DIVERGENTE` efface bien l'IP **avant** que la bonne ne soit posée. **Ne pas inverser**, et ne pas
  « optimiser » en supprimant la première — c'est elle qui tient le cas où la redécouverte échoue.
- **R10 — Traçabilité à mettre à jour après livraison** : `.memory/analyse/smartclim-transport-broadlink-lan.md`
  § 8 (la mitigation « ré-exécuter une découverte broadcast » cesse d'être une recommandation pour devenir un
  fait), la spec technique de l'UC02 (§ 5.3 : un point d'appel déplacé ; D-1 fermée ; R8 traitée), et la puce
  `smartclim` de `CLAUDE.md`.

---

## 14. Dette (non traitée dans ce cycle)

- **D-1 — Durcissement du critère d'adoption** : `estTrameHvac()` bloquant sur le **routage**, plus un
  contrôle de `type_appareil` **une fois** que `STATUT_MAC_DIVERGENTE` cessera d'effacer ce champ. Exige du
  matériel Broadlink réel, sous peine de troquer un faux positif contre un faux négatif silencieux (R3, R4).
- **D-2 — Clé unique de la mémoire de sonde** : le § 4.6 converge les chemins de rafraîchissement, **pas ceux
  du scan**. Prolonge la D-2 de l'UC02, dont il durcit le diagnostic (R2). Détectable par
  `commande-lan.php --transport` (`preuve = 0` alors que le statut LAN est `ETAT_LU`).
- **D-3 — Équipement AUTO sans aucune adresse connue** (Jeedom arrêté > 24 h, mémoire de sonde expirée) : il
  n'est **pas** réarmé par cette UC — seul le scan le redécouvre. Seule la règle (b), réservée à
  `STATUT_MAC_DIVERGENTE`, est livrée. Extension éventuelle : rendre éligible tout équipement en mode LOCAL
  sans adresse (~4 lignes) — non fait, aucun AC ne l'exige, et cela ferait diffuser pour un parc entier de
  mode LOCAL jamais scanné.
- **D-4 — Race non atomique sur la mémoire de sonde** entre `plugin::cron` et une commande interactive :
  inchangée, même classe que la D-3 de l'UC02. ⚠️ C'est cette race, et elle seule, qui rend observable par un
  **autre processus** la fenêtre de statut reporté décrite au § 4.7.
- **D-6 — Imbrication de `rafraichirLan()`** : la boucle par équipement atteint ~5-6 niveaux
  (`foreach` → `try` → adresse → appareil `null` → trouvé → source manuelle). Dictée par l'algorithme et
  abondamment commentée, mais candidate à l'extraction d'un helper privé (« tenter la redécouverte et
  conclure ») **si ce fichier est retouché pour une autre raison**. Non fait ici : ce chemin n'est recettable
  sur aucun matériel, un refactor de confort y coûterait plus qu'il ne rapporte.
- **D-5 — Lien de documentation** : poser le lien vers `docs/fr_FR/reseau-local.md` dans le sommaire lors de
  la refonte de `docs/fr_FR/` au **domaine post-mvp/07 UC02** (décision A4, M3).
