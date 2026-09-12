# Spec technique — UC05 « Validation de l'accès au broker MQTT AUX Home européen »

> **Domaine** : post-mvp/05-temps-reel-et-demon · **Spec fonctionnelle** :
> `05-validation-acces-mqtt-aux-home.md`
> **Statut à la livraison** : ⚠️ **instrument livré — fait en attente**. Cette UC **ne se clôt pas au
> commit**, exactement comme l'UC04 de ce domaine : elle se clôt quand le code retour du CONNACK est
> consigné au § 7.2 de `smartclim-transport-aux-home.md` (AC2) et que la décision de marche est écrite
> (AC5). Voir § 9 et § 10.

## 0. Position — ce que ce cycle livre, et pourquoi

Cette UC ne livre **aucune fonctionnalité**. Elle produit **un fait** (le broker EU accepte-t-il nos
identifiants ?) et prépare **une décision** (ouvrir une marche du § 6 de `smartclim-daemon-choix.md`, ou
clore le dossier). Le code livré est un **instrument de mesure**, du même statut que
`core/php/sonde-intent-auxhome.php` et `resources/smartclimd/sonde_auxlink.py` : il n'est appelé par
**aucun** chemin d'exécution du plugin, ni maintenant ni jamais.

⚠️ **Le fait ne peut pas être produit pendant ce cycle.** La machine de développement n'a ni PHP, ni
Jeedom, ni compte AUX Home. Le livrable du cycle est donc : **l'outil + l'arbitrage TLS daté + le
protocole de recette**. Le fait arrive après exécution par l'utilisateur, en SSH sur son Jeedom.

### 0.1 Décisions d'arbitrage prises avec l'utilisateur (2026-09-12)

| # | Question | Décision |
|---|---|---|
| **D-1** | Certificat EU expiré **et** SAN ne couvrant pas son nom d'hôte, contre la règle « TLS toujours vérifié » | **Dérogation cadrée**, bornée à cette CLI, par un drapeau **jamais implicite**. Motif : l'information est inobtenable autrement et c'est le **dernier** inconnu du dossier ; le gain principal n'est pas d'ouvrir le push (§ 11, R8) mais de pouvoir **clore** avec un fait. |
| **D-2** | Le `configId` par défaut de la référence MIT est-il versionnable ? | **Oui, en dur dans le script**, source et licence citées — même statut que `STATIC_APP_TOKEN` et `ACCOUNT_AES_KEY` déjà versionnées dans `smartclimAuxHomeApi`. AC6 vise les secrets **du compte de l'utilisateur** ; ce UUID est une constante publique d'un projet tiers. C'est aussi ce qui rend l'exigence d'AC3 vérifiable **par le programme** (§ 6.4). |

⚠️ **D-1 ne vaut pas précédent.** Toute UC ultérieure qui voudrait exploiter ce canal doit **revérifier le
certificat au moment où elle s'écrit** — cf. § 11, R8, et la phrase ajoutée au texte de la marche 2 du § 6
de `smartclim-daemon-choix.md`. Le vecteur de propagation d'une dérogation n'est pas l'autoload, c'est la
**recopie humaine** d'une fonction « qui marchait déjà dans la sonde ».

## 1. Couverture des critères d'acceptation

| AC | Couverture |
|---|---|
| **AC1** | **Couvert par ce document et par le § 7.3 amendé**, écrits **avant** toute connexion. La décision est D-1 ci-dessus : dérogation cadrée, bornée, datée, jamais silencieuse. |
| **AC2** | **Couvert par l'instrument, conditionné à la recette.** `--connack` lit le code retour et le rend en hexadécimal brut. Le report au § 7.2 est l'étape 7 du protocole (§ 10). |
| **AC3** | **Rendu structurel par `conclusion()`** (§ 6.4) par **deux mécanismes indépendants** : une garde qui refuse un verdict négatif tant que la valeur de référence n'a pas été essayée, que deux `configId` distincts n'ont pas produit de CONNACK et que les deux niveaux de protocole n'ont pas été couverts ; **et**, évaluée **en amont de cette garde**, la discrimination d'une série homogène de `0x02`. ⚠️ Ce n'est **pas** un simple comptage, et ⚠️ **les deux ne doivent pas être imbriqués** — cf. § 6.4. |
| **AC4** | **Zéro code.** `core/php/diagnostic-auxhome.php` accepte déjà des chemins libres en CLI (§ 2.2). Trois pièges à intégrer au protocole, sans lesquels AC4 serait **mal conclu** — § 2.2 et § 10 étape 3. |
| **AC5** | **Rédaction figée d'avance au § 9**, à reporter au § 7.7 **nouveau** de `smartclim-transport-aux-home.md`, au § 6 de `smartclim-daemon-choix.md` et à `INDEX.md`. Décision **humaine**, pas produite par le code. |
| **AC6** | **Couvert par construction** : le jeton n'est **jamais** affiché, même tronqué ; le `uid` passe par `smartclimDiagnostic::jeton()` ; le UserName complet n'est jamais rendu (il **contient** le `uid`). Tableau exhaustif au § 7.3. |

## 2. Contrats externes

### 2.1 CONNECT / CONNACK MQTT 3.1.1

**Source normative** : OASIS *MQTT Version 3.1.1*, §§ 3.1 (CONNECT), 3.2 (CONNACK), 3.14 (DISCONNECT).
**Source d'usage** : `latentharbor/ha-aux-a-plus` (MIT), `custom_components/aux_a_plus/mqtt.py` et
`const.py` — la référence construit elle aussi son CONNECT **à la main sur un socket brut**, sans `paho`.

Paquet **CONNECT** : octet de type `0x10`, puis *Remaining Length* (varint), puis :

| Bloc | Octets | Valeur retenue |
|---|---|---|
| Protocol Name | chaîne préfixée 2 octets BE | `00 04 MQTT` (niveau 4) **ou** `00 06 MQIsdp` (niveau 3) |
| Protocol Level | 1 | `04` ou `03` |
| Connect Flags | 1 | **`0xC2`** = user name (bit 7) + password (bit 6) + clean session (bit 1). Aucun will, bit 0 réservé à 0. Contrôle arithmétique : 128 + 64 + 2 = 194 = `0xC2`. |
| Keep Alive | 2 BE | `00 78` (120 s, valeur de la référence) |
| Payload — **ordre imposé** | — | ClientId, [WillTopic, WillMsg], UserName, Password — chacun préfixé de sa longueur sur **2 octets BE** |

- **ClientId** : `usr<uid>`.
  ⚠️ **Écart à consigner au § 7.1 de l'analyse** : la référence construit en réalité
  `usr<uid>_ha_<6 derniers caractères du device_id>`, `usr<uid>` n'étant que son **repli**. Sans
  `device_id` côté EU, nous utilisons le repli — c'est un **confondant possible d'un `0x02`**, et c'est
  pour cela que `conclusion()` traite ce code à part (§ 6.4).
- **UserName** : `2$<configId>$<uid>` — conforme au § 7.1.
- **Password** : le jeton REST **brut**, **sans** préfixe `bearer`.
- ⚠️ **Second écart à consigner, non contradictoire** : le § 7.1 ne dit **rien du niveau de protocole**, et
  la référence parle **MQTT 3.1** (`MQIsdp`, niveau **3**), pas 3.1.1. Le spike a prouvé que le broker EU
  **accepte** le niveau 4 (il a répondu `0x04`, pas `0x01`), mais rien ne dit que sa politique
  d'**authentification** est identique dans les deux dialectes. D'où `--protocole=3|4` et l'interdiction
  de conclure négatif sans avoir essayé les deux.

Paquet **CONNACK** : exactement 4 octets, `20 02 <flags> <code>`, `flags` bit 0 = *session present*.

| Code | Signification | Lecture pour cette UC |
|---|---|---|
| `0x00` | Connection accepted | **Le compte EU est connu du broker** → AC2 tranché positivement |
| `0x01` | Unacceptable protocol version | **Bug de construction du paquet**, jamais un fait sur le compte |
| `0x02` | Identifier rejected | Le **ClientId** est en cause, pas les identifiants — cf. § 6.4 |
| `0x03` | Server unavailable | Indécis, à rejouer |
| `0x04` | Bad user name or password | ⚠️ **N'autorise aucune conclusion seul** : `configId`, niveau de protocole et ClientId sont des confondants |
| `0x05` | Not authorized | Identifiants **reconnus** mais compte non habilité — information **forte**, à distinguer nettement de `0x04` |

Paquet **DISCONNECT** : `E0 00`.
⚠️⚠️ **Il n'est émis QUE si le code du CONNACK vaut `0x00`.** MQTT § 3.2.2.3 : *« If a server sends a
CONNACK packet containing a non-zero return code it MUST then close the network connection »* — le pair a
**déjà fermé** le socket. Émettre un DISCONNECT après un `0x02`/`0x04`/`0x05` écrit sur un socket clos et
produit un `fwrite()` partiel ou un warning « Broken pipe » dans un outil qui doit être irréprochable.
Dans tous les cas l'erreur d'écriture du DISCONNECT est ignorée : un `fclose()` suit immédiatement.

**Candidats `configId`** (AC3) :

1. `B7E65BB2-F02E-4EAD-B7BA-1C50FCE62882` — `const.py::DEFAULT_CONFIG_ID` de `latentharbor/ha-aux-a-plus`.
   **C'est la valeur exigée nommément par AC3** (« celle par défaut de l'implémentation de référence »),
   d'où D-2.
2. Un UUID v4 **généré à l'exécution** — teste l'hypothèse « identifiant d'instance faiblement contrôlé »
   du § « À confirmer » de la spec fonctionnelle.
3. La **chaîne vide**, donc `2$$<uid>` — teste si le champ est seulement lu.
4. Le `uid` lui-même.

### 2.2 `/app/getConfig?id=<X>` (AC4) — vérifié dans le code, aucune ligne à écrire

- **Garde CLI** : `smartclimAuxHomeApi::sondeDiagnostic()` lève `TYPE_INTERNE` si des chemins
  supplémentaires sont fournis hors CLI. ✅
- **Liste blanche de forme** (`executerSonde`) : motif ancré sur `/app/`, classe `[A-Za-z0-9._/-]` pour le
  chemin et `[A-Za-z0-9._=&%-]` pour la partie requête, plus interdiction de `..`. Les **9** valeurs de
  `id` de la spec passent, `=` et la casse mixte comprises. ✅
- **Méthode** : `GET`, en-têtes `Authorization: bearer <jeton de session>` et `country`, **TLS vérifié**
  (`CURLOPT_SSL_VERIFYPEER`/`VERIFYHOST`). ✅ **Cette voie ne demande aucun compromis TLS.**
- **Sortie** : `http`, code métier, `donnees` (corps JSON complet), `erreur`.

⚠️ **Trois points sans lesquels AC4 serait mal conclu** :

1. **`id=all` est DÉJÀ dans `routesDiagnostic()`** — le repasser en argument le sonderait deux fois. Ne
   passer que les **8 autres**.
2. ⚠️⚠️ **`BUDGET_SONDE = 40 s` est la vraie contrainte, pas `DIAG_MAX_ROUTES = 20`.** Le plafond de
   routes tient (10 du catalogue + 8 = 18 ≤ 20, et le compteur est local à chaque appel). Mais avec
   `TIMEOUT_SONDE = 6 s` par route et un budget global **démarré avant le login**, les dernières routes
   sortiront en « non sondée : budget de temps épuisé ». → **Sonder en 2 ou 3 passes de 3-4 valeurs**, et
   **relire chaque ligne** : « budget épuisé » n'est **pas** un résultat négatif. C'est exactement la
   confusion que l'AC4 interdit (« y compris négatif, quels `id` essayés, quel code de retour »).
3. **Masquage** : la liste de clés sensibles de `smartclimDiagnostic` contient `ip` et `address`, mais
   **pas** `host`, `url`, `domain`. Un broker annoncé sous `mqttUrl`/`host` serait **lisible** dans le
   rapport masqué ; annoncé sous `address`, il sortirait en `masque:xxxxxx` et il faudrait relancer avec
   `--brut` **en local**. 🚫 **Ne jamais committer ce rapport.**
   ⚠️ Nuance : un hôte de broker n'est pas un secret au sens d'AC6 — c'est précisément l'information que
   AC4 cherche à révéler. Ce point est de l'hygiène de lecture, pas un risque d'AC6.

### 2.3 Certificat TLS du broker (§ 7.3 de l'analyse)

| Hôte | Sujet / SAN | Validité |
|---|---|---|
| `eu-smthome-m2m.aux-global.com:8883` | CN=`*.aux-home.com`, SAN `*.aux-home.com, aux-home.com` | 2024-10-08 → **2025-11-06 (expiré)** |
| `smthomem2m.aux-home.com:8883` (broker CN) | même identité, autre émetteur | 2025-10-30 → **2026-11-30 (valide)** |

**Double échec** côté EU : expiration **et** nom d'hôte non couvert (le SAN est en `aux-home.com`, l'hôte
en `aux-global.com`) — chacun suffit seul à faire échouer la vérification.

⚠️ **Mesure datée du 2026-09-07, soit 5 jours.** Le mode `--certificat` est la **toute première étape** du
protocole : si AUX a renouvelé depuis, l'arbitrage D-1 devient **sans objet** et le test se fait sans
aucune dérogation. Meilleur scénario possible, et il coûte une commande.

⚠️ **Pas d'épinglage automatique.** Une revue a proposé d'épingler sur « l'empreinte déjà versionnée au
§ 7.3 » : **elle n'y est pas** (aucune empreinte n'a jamais été consignée). Épingler depuis notre propre
première connexion serait du **TOFU**, pas une preuve. Ce qui est retenu à la place, à coût nul :
`--certificat` **affiche** l'empreinte SHA-256, et `--connack` accepte un `--empreinte=<sha256>`
**optionnel** que l'utilisateur colle depuis la première commande. L'outil n'affirme **jamais** que c'est
un contrôle de sécurité ; c'est une corroboration entre deux exécutions, et elle est facultative.

## 3. Architecture — fichiers

| Chemin | Action | Contenu |
|---|---|---|
| `core/php/sonde-mqtt-auxhome.php` | **créé** | **6ᵉ CLI** du plugin. Tout le code MQTT y vit, en **fonctions locales** (pas de classe). Garde `php_sapi_name() === 'cli'` **avant tout `require_once`**. Aucun POST, aucune écriture en base ni sur disque. **2 espaces, CRLF**, sorties FR **sans `__()`**. |
| `.memory/analyse/smartclim-transport-aux-home.md` | modifié | § 7.1 (les **deux écarts** du § 2.1), § 7.2 (le `❓` devient un fait), § 7.3 (arbitrage D-1 **daté** + protocole de re-mesure + la nuance d'épinglage ci-dessus), **§ 7.7 nouveau** (décision — § 9), § 9 (case d'avancement) |
| `.memory/analyse/smartclim-daemon-choix.md` | modifié | § 6 : marche 1 **jouée**, résultat, statut des marches 2 et 3 — **et la phrase « D-1 ne vaut pas précédent » dans le texte de la marche 2 elle-même** (§ 0.1) |
| `.memory/analyse/INDEX.md` | modifié | ligne du § 0 « Y a-t-il un push sur AUX Home ? » + bloc « dernière mise à jour » |
| **`core/php/smartclim.inc.php`** | ⚠️ **NON MODIFIÉ, et c'est le cœur du cadrage** | Aucune classe annexe n'est créée, donc rien à y ajouter. C'est **précisément** ce qui garantit que la dérogation TLS n'est atteignable depuis **aucun** point d'entrée du plugin. |
| `core/php/.htaccess` | **non modifié** | Le `Deny from all` couvre le nouveau fichier automatiquement. 🚫 **Ne jamais l'ajouter au bloc `<Files>`** — réservé à `jeeSmartclim.php`. |
| `plugin_info/packages.json` | **non modifié** | Aucune dépendance. PHP + `openssl` (déjà requis par le login RSA/AES). |
| `resources/`, `smartclimDemon`, `jeeSmartclim.php`, `cron()` | **non touchés** | Invariant « le démon est **latéral** » : rien de cette UC n'approche la scrutation. |

### 3.1 Pourquoi une CLI auto-portante, et pas une classe

Trois options pesées, une retenue.

1. **Classe `smartclimMqttSonde` dans `core/class/`** — ❌ **rejetée**. Elle devrait être ajoutée à
   `core/php/smartclim.inc.php` (règle d'autoload : c'est le seul chemin de chargement), donc **chargée à
   chaque requête PHP du plugin** — AJAX, cron, pages desktop. Une classe dont l'unique raison d'être est
   d'ouvrir un socket TLS à vérification relâchée, **résoluble depuis n'importe quel chemin de code**, est
   l'exact contraire d'« exception strictement cadrée et bornée à un outil de test hors production ».
2. **Script jetable hors dépôt** — ❌ **rejetée**. Il doit tourner **sur le Jeedom**, avec la session du
   plugin : il faudrait de toute façon le déposer dans le dossier du plugin. Et il perdrait ce qui fait la
   valeur des 5 instruments existants — versionné, relu, rejouable.
3. **Code dans le démon Python** — ❌ **rejetée**. Il faudrait un mécanisme d'armement/désarmement (comme
   `sonde_auxlink`) pour une mesure **ponctuelle**, et cela ferait transiter le jeton AUX Home par un
   composant qui ne voit aujourd'hui **aucun** identifiant AUX Home. L'UC01 a établi qu'un CONNECT
   ponctuel tient en **PHP pur**.

⚠️ **Deux tensions nommées, à redire dans l'en-tête du fichier** :

- **Avec « centraliser les accès externes, jamais de socket épars »** : la règle protège contre
  l'éparpillement du savoir protocolaire **dans le runtime du plugin**. Ici le fichier n'est requis par
  rien, joignable par rien (`Deny from all` + garde CLI), et n'est **pas un transport**. S'y ajoute une
  garantie que le cadrage par l'autoload ne donnait pas : **chaque invocation CLI est un processus PHP
  isolé** — même une constante ou une globale définie dans ce script ne peut atteindre un process
  AJAX/cron ultérieur.
- **Avec le patron des CLI d'aiguillage** : contrairement à `commande-lan.php` ou
  `sonde-intent-auxhome.php`, qui ne portent **aucune** logique métier et délèguent à `smartclim::`, ce
  script **porte sa propre logique protocolaire**. C'est légitime — il n'y a rien à déléguer, puisque
  cette UC produit précisément le **premier** fait MQTT du projet (même situation que `sonde_auxlink.py`,
  sonde écrite avant que le transport n'existe). ⚠️ **À écrire noir sur blanc dans l'en-tête**, pour
  qu'un lecteur futur ne croie pas que ce fichier suit le patron « zéro logique métier » des autres.

## 4. Server vs Client

**Sans objet — 100 % serveur, et même 100 % CLI.** Aucune page, aucun endpoint AJAX, aucun JS, aucun
widget. Le seul point d'entrée est une ligne de commande lancée en SSH par un administrateur. Aucune
surface web n'est ajoutée : c'est un des motifs du choix d'une CLI plutôt que d'un bouton de page
(même arbitrage qu'à l'UC02 de ce domaine pour `pont-demon.php`).

## 5. Dépendances

**Aucune.** Ni paquet système, ni `pip3`, ni bibliothèque MQTT. `plugin_info/packages.json` **n'est pas
touché** — rappel : chaque paquet ajouté re-déclenche un cycle d'installation sur **tout le parc**.

Extensions PHP utilisées, toutes déjà requises par le plugin : `openssl` (déjà indispensable au login
RSA/AES d'AUX Home) pour le transport `ssl://` et pour `openssl_x509_parse()` /
`openssl_x509_fingerprint()`. ⚠️ Une garde d'environnement explicite le vérifie quand même
(`in_array('ssl', stream_get_transports(), true)`), avec un message clair — un `stream_socket_client()`
sur un transport absent échoue autrement sur un message opaque.

## 6. Signatures — tout est local au script

Pas de classe, pas d'autoload, pas de `require_once` vers un fichier nouveau. Le script fait, comme les 5
autres CLI, un `require_once` **vers** `core.inc.php` puis `smartclim.class.php` — la direction de
chargement est donc **entrante**, jamais sortante : rien dans le plugin ne peut atteindre ce fichier.

### 6.1 Construction du protocole — fonctions pures, aucune E/S

```php
mqttChaine(string $texte): string
  // pack('n', strlen($texte)) . $texte. Arrêt net si strlen > 65535.

mqttLongueurRestante(int $n): string
  // Varint 7 bits par octet, bit de continuation 0x80, 1 à 4 octets.
  // PIÈGE N°1 : le corps CONNECT dépasse presque toujours 127 octets (jetonConforme()
  // autorise jusqu'à 4096) -> 2 octets obligatoires. Un varint mal encodé produit un
  // paquet malformé, donc une connexion FERMÉE, qui serait lue comme un refus
  // d'authentification. C'est le pire faux négatif possible de cette UC.

mqttPaquet(int $type, string $corps): string
  // chr($type) . mqttLongueurRestante(strlen($corps)) . $corps

mqttCorpsConnect(string $clientId, string $user, string $password,
                 int $niveau, int $keepAlive): string
  // niveau 4 => 'MQTT' ; niveau 3 => 'MQIsdp'. Drapeaux figés à 0xC2.
```

### 6.2 E/S réseau

```php
ouvrirFlux(string $hote, int $port, bool $verifier, ?string $empreinte,
           array &$infosCert)
  // stream_socket_client('ssl://<hote>:<port>') avec contexte ssl :
  //   verify_peer / verify_peer_name = $verifier, peer_name = $hote,
  //   capture_peer_cert = true, SNI_enabled = true, disable_compression = true,
  //   peer_fingerprint = $empreinte quand elle est fournie (optionnelle, cf. § 2.3).
  // Renseigne $infosCert (sujet, émetteur, SAN, notBefore/notAfter, empreinte SHA-256)
  // via stream_context_get_params() + openssl_x509_parse() / openssl_x509_fingerprint().

ecrireTout(resource $flux, string $donnees): bool
  // PIÈGE N°2 : fwrite() n'est pas plus atomique que fread(). Boucler jusqu'à
  // épuisement du tampon, ou comparer le retour à strlen(). Symétrique de lireConnack().

lireConnack(resource $flux, float $echeance): array
  // {etat: 'connack'|'ferme'|'timeout'|'malforme', brut_hex, session_present, code}
  // PIÈGE N°3 : TCP est un FLUX -- boucler sur fread() jusqu'à 4 octets ou échéance,
  //   en relisant stream_get_meta_data()['timed_out'] à chaque tour (sans quoi un
  //   stream_set_timeout() seul peut boucler indéfiniment).
  // VALIDE les deux premiers octets (0x20, 0x02) AVANT de faire confiance au 4e :
  //   une page d'erreur HTTP sur le port 443 doit sortir en 'malforme', jamais en un
  //   faux code de retour.
  // 'ferme' (fermé sans réponse) est un RÉSULTAT DISTINCT -- le spike l'a observé sur
  //   des octets non-MQTT -- et n'est JAMAIS assimilé à un refus d'authentification.

tenterCandidat(array $params): array
  // Une connexion complète : ouvrirFlux -> CONNECT -> lireConnack -> DISCONNECT (E0 00,
  // UNIQUEMENT si code === 0x00, cf. § 2.1) -> fclose.
  // Ne SOUSCRIT ni ne PUBLIE jamais.
```

### 6.3 Rendu

```php
texteCertificat(array $infosCert): string
ligneResultat(array $resultat, array &$correspondancesMasque, bool $brut): string
```

### 6.4 `conclusion()` — c'est elle qui porte l'AC3

```php
conclusion(array $resultats, array $protocolesEssayes): string
```

⚠️⚠️ **L'ordre d'évaluation est le contrat, pas un détail d'écriture** (corrigé en revue, cf. § 12,
D-05-05-04). Les deux mécanismes ci-dessous sont **indépendants** et le second s'évalue **en premier** :

1. **La discrimination du code** (ci-dessous, « série homogène de `0x02` ») — valable **dès un seul run et
   un seul niveau de protocole**.
2. **La garde des trois conditions** — qui ne gouverne que les **autres** verdicts négatifs.

Imbriquer le premier dans le second le rend **inatteignable par construction** : `--protocole` n'accepte
qu'une valeur par invocation, donc la condition « les deux niveaux ont été essayés » n'est **jamais**
vraie au sein d'une exécution. C'est le défaut qu'a trouvé la revue — et il frappait précisément le
scénario **le plus probable** (D-05-05-01).

Elle **refuse d'imprimer une conclusion négative** — et dit alors ce qui manque — tant que les **trois**
conditions ne sont pas réunies :

1. **La valeur de référence a été essayée.** ⚠️ Vérification par **identité**, pas par comptage :
   `B7E65BB2-F02E-4EAD-B7BA-1C50FCE62882` doit figurer parmi les candidats ayant produit un CONNACK. AC3
   exige nommément « celle par défaut de l'implémentation de référence » — un utilisateur passant
   `--config-id=A --config-id=B` satisferait un compteur, pas l'AC. **C'est le premier trou fermé ici.**
2. **Au moins deux `configId` distincts** ont produit un CONNACK.
3. **Les deux niveaux de protocole** (3 et 4) ont été essayés — extension au-delà de la lettre d'AC3,
   justifiée par le second écart du § 2.1.

⚠️ **Et elle discrimine le code, pas seulement le nombre.** Une série **homogène de `0x02`** n'écarte
**rien** : le coupable désigné est alors le **ClientId** (repli `usr<uid>` sans suffixe
`_ha_<device_id>`), pas le `configId`. Dans ce cas `conclusion()` émet un message explicite orientant vers
le ClientId et **ne conclut pas** sur le `configId`. **C'est le second trou fermé** — sans lui, AC3 serait
respecté à la lettre et violé dans son esprit.

⚠️ Ce test s'évalue **avant** la garde des trois conditions et **ne dépend d'aucune d'elles** : sa seule
condition est qu'au moins un CONNACK ait été lu et que **tous** les codes obtenus vaillent `0x02`.

### 6.5 Appels au plugin existant — et seulement ceux-là

`smartclim::compteConfigure()` (garde, comme `diagnostic-auxhome.php`) ·
`smartclimAuxHomeApi::session()` (**déjà `public static`**, rend `jeton`/`uid`/`cree_le` — **rien à
ajouter**) · `smartclimAuxHomeApi::purgerSession()` en fin de test ·
`smartclimDiagnostic::jeton()` (masquage) · `smartclim::neutraliserPourLog()` (journalisation).

🚫 **Ne PAS ajouter d'accesseur du type `smartclim::jetonAuxHome()`** : ce serait un nouveau point
d'exposition d'un secret dans une classe chargée partout, pour rien — `session()` est déjà publique.

### 6.6 Usages

```
sudo -u www-data php core/php/sonde-mqtt-auxhome.php --certificat [--port=8883|443]
sudo -u www-data php core/php/sonde-mqtt-auxhome.php --controle [--port=8883]
sudo -u www-data php core/php/sonde-mqtt-auxhome.php --connack [--protocole=3|4]
      [--config-id=<v> …] [--port=…] [--empreinte=<sha256>]
      [--accepter-certificat-invalide] [--brut]
```

- **`--certificat`** — **n'envoie AUCUN identifiant.** Tente d'abord une poignée de main **entièrement
  vérifiante** : si elle réussit, le blocage est **levé** et plus aucune dérogation n'est nécessaire.
  Sinon, ré-ouvre en capture et **affiche l'anomalie** (sujet, émetteur, SAN, dates, empreinte SHA-256 —
  toutes données publiques). ⚠️ **Ce mode n'est pas une dérogation : c'est l'implémentation de « l'anomalie
  est remontée »** — on ne peut pas remonter une anomalie qu'on s'interdit d'observer, et aucun secret ne
  traverse le canal.
- **`--controle`** — **test de contrôle sans aucune dérogation et sans aucun secret.** Envoie un CONNECT
  portant des identifiants **manifestement bidons** vers le broker **CN** `smthomem2m.aux-home.com:8883`,
  dont le certificat est **valide** et dont le CN couvre le nom d'hôte (§ 2.3), en **vérification TLS
  pleine**. Attendu : `20 02 00 04`, déjà observé par le spike. Toute autre réponse (`0x01`, `ferme`,
  `malforme`) prouve que **notre paquet est mal construit**, et non que le broker refuse quoi que ce soit.
  ⚠️ C'est ce qui neutralise le pire risque de l'UC (§ 11, R6) **avant** de toucher au canal EU, et donc
  ce qui empêche un **faux négatif d'être consigné dans l'analyse**. Recommandé en étape 2 du protocole,
  non bloquant.
- **`--connack`** — le test réel. **Refuse de démarrer** sans `--accepter-certificat-invalide` si la
  vérification échoue, en renvoyant explicitement vers `--certificat`.
- **`--port=1883` est refusé nommément**, avec message : le jeton y transiterait **en clair** (§ 7.3 de
  l'analyse).
- **Constantes locales** : `TIMEOUT_CONNEXION = 5`, `TIMEOUT_LECTURE = 6`, `BUDGET_SERIE = 30`,
  `PAUSE_TENTATIVE = 1`, `MAX_CANDIDATS = 6`.
- **Code de sortie** : **0 si un CONNACK a été lu** (le fait est produit, quel qu'en soit le code), **1
  sinon**. ⚠️ « accepté » contre « refusé » **n'est pas** le code de sortie — la mesure réussit dans les
  deux cas.

### 6.7 Invariant vérifiable — à écrire en en-tête et à contrôler en revue

Même doctrine que l'AC1 de `sonde_auxlink.py` : **aucune souscription, aucune publication, aucun topic**.

```
grep -nE "0x82|0x30|\bsubscribe\b|\bpublish\b|dev2app|app2dev" core/php/sonde-mqtt-auxhome.php
```

→ **zéro occurrence** (hors le présent invariant cité en commentaire, qui doit donc être formulé sans ces
littéraux).

⚠️ **Les frontières de mot `\b` ne sont pas cosmétiques** (corrigé en revue) : sans elles, `publish`
matche `published` du **bloc de licence GPL** présent en tête de **tous** les fichiers PHP du plugin
(« *General Public License as published by* »). La commande rendait donc une occurrence, et l'invariant
était annoncé comme vérifié alors que sa commande de contrôle ne l'était pas. **Leçon générale** : un
contrôle prescrit dans une spec doit être **exécuté** avant d'être écrit, sinon c'est le contrôle qui
devient la dette.

## 7. Validation

### 7.1 Arguments

`--protocole` ∈ {3, 4} · `--port` ∈ {8883, 443}, **1883 refusé nommément** · `--config-id` limité à
`[A-Za-z0-9-]` sur 64 caractères au plus (il entre dans une chaîne envoyée à un tiers) · `--empreinte`
limitée à 64 caractères hexadécimaux · nombre de candidats plafonné à `MAX_CANDIDATS`.

### 7.2 Gardes métier et d'environnement

- **`uid` vide → arrêt net**, avec message utilisable : « le login a réussi mais le backend n'a pas
  renvoyé d'`uid` — voir le `warning` dans les logs du plugin ». Sans `uid`, **ni** le ClientId **ni** le
  UserName ne sont constructibles. Piège réel : `login()` ne fait qu'un `log::add(warning)` dans ce cas.
- **Transport `ssl` absent** → message explicite (§ 5).
- **Compte non configuré** → `smartclim::compteConfigure()` en garde d'ouverture, comme
  `diagnostic-auxhome.php`.

### 7.3 Erreurs

Aucun type d'exception nouveau. `catch (smartclimException $e)` → message **déjà curaté en français** par
`session()`, affiché tel quel. `catch (Throwable $e)` en **dernier bloc** → `log::add('smartclim',
'error', …)` avec `get_class($e)` et `smartclim::neutraliserPourLog($e->getMessage())`, puis message
générique.

⚠️ **Le code MQTT local ne lève jamais** : il rend des **statuts** (`connack` / `ferme` / `timeout` /
`malforme`), exactement comme `smartclimBroadlinkLan::lireEtat()`. Un échec réseau est un **résultat de
mesure**, pas une erreur de programme.

### 7.4 Ce qui est affiché, ce qui ne l'est JAMAIS (AC6)

| Affiché | Jamais affiché |
|---|---|
| Hôte, port, niveau de protocole, drapeaux `0xC2`, keep-alive | **Le jeton, même tronqué** — ce rapport est fait pour être collé ailleurs ; la règle « 6 premiers caractères » vaut pour un log de debug, pas ici |
| CONNACK **brut en hexadécimal** (`20 02 00 04`) et son code — aucun secret | Le `uid` en clair → `smartclimDiagnostic::jeton()` |
| **Longueurs** de ClientId / UserName / Password | Le **UserName complet** — il **contient** le `uid` |
| Étiquette du candidat (`reference`, `aleatoire`, `vide`, `uid`) | Le `configId` **du compte de l'utilisateur**, s'il venait à être connu |
| Certificat : sujet, émetteur, SAN, dates, empreinte SHA-256 — **données publiques** | — |

`--brut` lève le masquage, avec le même avertissement « à garder pour soi » que les autres CLI.

**Pour les fichiers d'analyse** : seule la **forme** est consignée (`2$<configId>$<uid>`, `usr<uid>`,
codes de retour), **jamais** une valeur de compte — règle déjà posée au § 7.1 de l'analyse. 🚫 **Aucune
capture brute d'échange MQTT** : le CONNACK est reporté comme une **valeur de 4 octets**, ce qu'il est.

## 8. i18n

**Aucune chaîne introduite.** Le fichier est une CLI : ses sorties sont en **français sans `__()`**, comme
les 5 CLI existantes. Aucun marqueur de traduction, aucun `core/i18n/*.json` touché. **L'étape
`translator` du cycle est sans objet**, et `python .claude/scripts/verif-plugin.py --tous` doit rester
sans clé manquante.

⚠️ **Vigilance méta-séquences** : l'en-tête de ce script parlera de paquets, d'octets et de protocoles.
Aucune séquence `*/`, `?>` ni double accolade ouvrante **littérale** dans un commentaire — contrôle par la
colonne `meta=` de `verif-plugin.py`.

## 9. Consignation du résultat (AC2 / AC5) — rédaction figée d'avance

Pour que le report ne dépende pas d'une session qui aura tout oublié, la forme est fixée ici.

**§ 7.2 de `smartclim-transport-aux-home.md`** — remplacer la puce « ❓ Nos identifiants EU sont-ils
acceptés par ce broker ? » par le fait, sous cette forme :

> ✅/❌ **Nos identifiants EU (ne) sont (pas) acceptés par le broker** — CONNACK `20 02 00 0X` observé le
> `<date>` sur `eu-smthome-m2m.aux-global.com:<port>`, protocole MQTT `<3|4>`, `configId` `<étiquette du
> candidat>`. Candidats essayés : `<n>`, dont la valeur de référence. Réponses obtenues : `<liste des
> codes>`.

**§ 7.7 nouveau — « Décision d'exploitation »** (le § 7.6 reste intact comme historique) :

> **Décision du `<date>`** : `<ouvrir la marche N du § 6 de smartclim-daemon-choix.md | maintenir la
> scrutation>`.
> **Motif** : `<le fait mesuré>`.
> **Ce que cette décision ne change pas** : les marches 2 et 3 restent fermées tant que le certificat du
> broker EU est invalide (R8) ; et la dette D-05-01-03 conditionne toujours le **gain** attendu (R9).

**§ 6 de `smartclim-daemon-choix.md`** : marche 1 marquée **jouée**, avec son résultat, **et** la phrase
« la dérogation TLS de l'UC05 ne vaut pas précédent — toute UC ultérieure doit revérifier le certificat au
moment où elle s'écrit » **dans le texte de la marche 2 elle-même**, pas seulement en renvoi.

**`INDEX.md`** : ligne du § 0 et bloc « dernière mise à jour ».

## 10. Protocole de recette — à exécuter par l'utilisateur, en SSH

⚠️ Toutes les commandes sous `www-data` (`sudo -u www-data php …`) : la session du plugin est écrite par
le processus Apache, et la CLI la relit. Vaut pour les 5 CLI existantes.

0. *(fait dans ce cycle)* Arbitrage TLS écrit et **daté** au § 7.3 (AC1) — **avant** toute connexion.
1. `--certificat`, puis `--certificat --port=443`. Comparer au tableau du § 2.3.
   **Si le certificat vérifie → aller directement à 4, sans `--accepter-certificat-invalide`**, et noter
   que D-1 est devenu sans objet.
2. `--controle` — **recommandé.** Sans secret, sans dérogation, il prouve que notre paquet est bien
   construit. Un résultat autre que `20 02 00 04` **interdit de passer à l'étape 4** : ce serait mesurer
   notre propre bug.
3. **AC4, indépendant de tout le reste et sans compromis TLS**, en 2-3 passes :
   `php core/php/diagnostic-auxhome.php '/app/getConfig?id=mqtt' '/app/getConfig?id=m2m'
   '/app/getConfig?id=server'`, puis `'…?id=serverConfig' '…?id=appConfig' '…?id=domain'`, puis
   `'…?id=host' '…?id=push'`. **Relire chaque ligne** : « budget de temps épuisé » impose de **rejouer**,
   ce n'est pas un négatif (§ 2.2). `id=all` est déjà couvert par le catalogue.
4. *(gate)* Si le certificat est toujours invalide → la dérogation D-1 s'applique. Si l'utilisateur
   revient sur sa décision : sauter 5 et 6, aller en 7 avec « blocage assumé ».
5. `--connack --accepter-certificat-invalide` (série des 4 candidats, protocole 4), puis
   **`--protocole=3`** si tout rend `0x04`. Optionnellement `--empreinte=<valeur lue à l'étape 1>`.
   ⚠️ **Avant de lancer** : voir R11 — fermer l'application AUX officielle sur le téléphone.
6. **Changer le mot de passe AUX Home** dans l'application officielle, puis le re-saisir dans la
   configuration du plugin (ce qui purge le cache de session via `postConfig_auxhome_password`).
   ⚠️ **C'est une étape, pas une recommandation** — cf. R2.
7. Reporter : § 7.2, § 7.3, **§ 7.7**, § 9, `daemon-choix.md` § 6, `INDEX.md` (§ 9 ci-dessus). Cocher AC1
   à AC6 dans la spec fonctionnelle.

## 11. Risques

- **R1 — La mesure ne peut pas être faite dans ce cycle.** Ni PHP, ni Jeedom, ni compte AUX sur la machine
  de développement. Le cycle livre l'instrument ; le fait arrive ensuite. Précédent identique : UC04 de ce
  domaine. **Ce n'est pas un échec du plan, c'est sa forme.**
- **R2 — Exposition du jeton : le vrai coût de D-1.** Le test envoie un jeton de session **réel** sur un
  canal dont l'identité du pair n'est pas vérifiable ; un attaquant actif sur le trajet Jeedom → Alibaba
  Cloud `eu-central-1` le capturerait. ⚠️ **`purgerSession()` ne révoque RIEN côté serveur** — c'est
  cosmétique, il faut le dire. Seule atténuation réelle : **changer le mot de passe** juste après (étape 6
  du protocole). La fenêtre d'exposition résiduelle du jeton déjà émis reste **inconnue** (durée de vie du
  jeton non établie, déjà en « À confirmer »).
- **R3 — Pas d'épinglage automatique.** Justification complète au § 2.3 : aucune empreinte de référence
  hors bande n'existe, l'épinglage serait du TOFU. L'outil **affiche** l'empreinte et accepte un
  `--empreinte` **optionnel** ; il ne prétend jamais que ce soit un contrôle de sécurité.
- **R4 — Le certificat a pu être renouvelé** (mesure vieille de 5 jours). `--certificat` est la **première**
  étape ; s'il vérifie, D-1 devient sans objet. Meilleur scénario, coût d'une commande.
- **R5 — Un `0x04` sur tous les candidats ne clôt rien tout seul.** Confondants identifiés : le
  `configId` (AC3), le **niveau de protocole** 3 contre 4, le **ClientId** sans suffixe
  `_ha_<device_id>` (un `0x02` le signalerait), et l'hypothèse d'un **cloisonnement régional** des
  comptes. `conclusion()` refuse d'imprimer un verdict tant que les deux premiers ne sont pas balayés
  (§ 6.4).
- **R6 — Pièges d'implémentation, tous silencieux** : varint mal encodé au-delà de 127 octets → paquet
  malformé → connexion fermée → **lue comme un refus d'authentification** ; `fread()` supposé atomique sur
  4 octets ; `fwrite()` supposé atomique ; `stream_set_timeout()` sans relecture de
  `stream_get_meta_data()['timed_out']` → boucle sans fin ; en-tête CONNACK non validé → une réponse
  non-MQTT lue comme un code de retour. **`--controle` (§ 6.6) est la parade** : il exerce toute la chaîne
  de construction contre un broker au certificat **valide**, sans envoyer le moindre secret.
- **R7 — Martèlement d'un tiers.** Plafond `MAX_CANDIDATS = 6`, pause de 1 s entre tentatives, budget
  global de 30 s. Un backend qui compterait les échecs d'authentification par IP ne doit pas être
  provoqué.
- **R8 — Contrainte héritée par toute UC future.** ⚠️ **Même avec un `0x00`, les marches 2 et 3 restent
  fermées tant que le certificat est invalide** : du code de **production** devrait alors déroger à « TLS
  toujours vérifié », ce que `CLAUDE.md` interdit sans ambiguïté. Le fait produit par cette UC a donc une
  **valeur prospective** (« le jour où AUX renouvelle, est-ce immédiatement exploitable ? ») et une
  **valeur de clôture** (« si c'est non, le dossier se ferme définitivement »), **pas** une valeur
  d'ouverture immédiate. À écrire tel quel au § 7.7.
- **R9 — Dette héritée D-05-01-03** (origine du retard d'ambiante indéterminée) : elle conditionne le
  **gain** de la marche 2. Un `0x00` ne suffirait donc **toujours pas** à justifier une exploitation — à
  redire au § 7.7 pour que personne ne s'en dispense.
- **R10 — Dette D-05-01-01** (nature MQTT du broker établie sur source unique) : **fermée par
  construction** si l'outil lit un CONNACK bien formé. Bénéfice collatéral du cycle.
- **R11 — Collision de `ClientId` avec l'application officielle.** ⚠️ MQTT § 3.1.3.1 : un CONNECT portant
  un `ClientId` déjà en session force le broker à **couper** la session existante. Si l'application AUX
  officielle utilise ce broker avec un `ClientId` dérivé du même `uid`, lancer le test **pendant** qu'elle
  est ouverte peut déconnecter momentanément son affichage temps réel. C'est le **même mécanisme** que la
  session Broadlink unique déjà documentée (« s'authentifier invalide celle du logiciel qui l'avait
  avant »). Non bloquant, mais **à annoncer avant l'exécution** : étape 5 du protocole.

## 12. Dette

- **D-05-05-01** — Le suffixe `_ha_<device_id>` du ClientId de la référence n'est pas exercé. Si la
  campagne rend une série homogène de `0x02`, il faudra ajouter une option pour le construire — ce que
  `conclusion()` signalera explicitement (§ 6.4), mais que cette UC ne livre pas.
- **D-05-05-02** — Les clés `host`, `url`, `domain` restent absentes de la liste de clés sensibles de
  `smartclimDiagnostic`. Non corrigé ici (ce n'est pas un risque AC6, cf. § 2.2), mais un rapport de sonde
  de l'étape 3 ne doit pas être committé pour autant.
- **D-05-05-03** — La durée de vie du jeton en usage MQTT reste inconnue, ce qui laisse la fenêtre
  d'exposition de R2 non bornée. Hors périmètre : l'UC s'arrête au CONNACK.
- **D-05-05-04** *(fermée dans ce cycle — conservée comme leçon)* — La première rédaction de ce document
  décrivait les deux mécanismes d'AC3 (§ 6.4) comme une seule garde à conditions cumulées. Implémentée à
  la lettre, cette formulation rendait la discrimination du `0x02` **inatteignable par construction**,
  puisque la condition « les deux niveaux de protocole » ne peut jamais être vraie au sein d'une
  invocation. ⚠️ **Leçon transverse** : deux tests qui répondent à des questions différentes ne
  s'imbriquent pas parce qu'ils vivent dans la même fonction — écrire leur **ordre d'évaluation** et leur
  **indépendance** dans la spec, pas seulement leur contenu. Même famille de piège que « un prédicat
  réutilisé comme garde » : ce n'est pas ce que le test répond qui compte, c'est **quand il est
  atteint**.

## Sources

- OASIS, *MQTT Version 3.1.1* — §§ 3.1 (CONNECT), 3.1.3.1 (ClientId et prise de session), 3.2 (CONNACK),
  3.2.2.3 (fermeture après code non nul), 3.14 (DISCONNECT).
- `latentharbor/ha-aux-a-plus` (MIT) — `custom_components/aux_a_plus/mqtt.py`, `const.py`, `api.py`.
- `.memory/analyse/smartclim-transport-aux-home.md` § 7 (spike UC01 du 2026-09-07).
- `.memory/analyse/smartclim-daemon-choix.md` § 6 (les trois marches).
