# Spec technique — UC02 du domaine post-MVP 07 : Documentation utilisateur et crédits de licences

> Spec fonctionnelle : `02-documentation-utilisateur.md` (AC1..AC6) · **UC de documentation** : aucun
> fichier de code applicatif n'est modifié, aucune classe, aucune clé de configuration, aucune commande.

## 0. Décisions d'arbitrage (tranchées avec l'utilisateur avant rédaction)

| # | Question | Décision |
|---|---|---|
| **D1** | Licence de publication du plugin (trois déclarations contradictoires dans le dépôt) | **GPL-3.0-or-later**, et **alignement de `LICENSE` + `info.json` dans cette UC** — cf. § 1.2 |
| **D2** | Étendue de la section Crédits | **Citer large**, en distinguant **trois niveaux** (notice obligatoire / source factuelle sans reprise de code / projet voisin non utilisé) |
| **D3** | `changelog.md` et `changelog_beta.md` (encore ceux du template) | **Traités ici**, réécriture minimale |
| **D4** | Captures d'écran | **Aucune.** Description textuelle reprenant les libellés exacts de l'IHM |
| **D5** | Liens `documentation`/`changelog` d'`info.json` en HTTP 404 | **Hors périmètre** (la spec l'impose) → inscrit en **dépendance bloquante** de l'UC04 du même domaine |
| **D6** | Formulation du retard de température ambiante | « de quelques minutes à une demi-heure », **avec attribution explicite de l'observation** — cf. § 4.2 |

## 1. Architecture

### 1.1 Fichiers

| Chemin | Action | Fins de ligne |
|---|---|---|
| `docs/fr_FR/index.md` | **réécrit intégralement** (c'est encore la doc du template : 18 lignes de liens vers la doc dev Jeedom) | **CRLF** |
| `docs/fr_FR/credits.md` | **créé** | **CRLF** |
| `docs/fr_FR/changelog.md` | **réécrit** (minimal) | **CRLF** |
| `docs/fr_FR/changelog_beta.md` | **réécrit** (minimal) | **CRLF** |
| `docs/fr_FR/reseau-local.md` | **conservé, NON modifié** — à lier depuis `index.md` § 5 | inchangé |
| `README.md` (racine) | **1 paragraphe** : le § Crédits promet déjà « la liste complète […] sera publiée avec la documentation utilisateur » → remplacer la liste partielle par un lien vers `docs/fr_FR/credits.md` | **LF pur** |
| `LICENSE` (racine) | **remplacé** par le texte **GNU GPL version 3** (il porte aujourd'hui le texte GPL **v2** de 1991) — cf. § 1.2 | selon le texte officiel |
| `plugin_info/info.json` | **une valeur** : `"licence": "AGPL"` → `"GPL"` — cf. § 1.2. **Aucune autre clé touchée** (les URLs de doc restent en l'état, D5) | inchangé |

> ⚠️ **Piège de fins de ligne vérifié, pas supposé** : les quatre `.md` de `docs/fr_FR/` sont en **CRLF**
> (contrôle par comptage d'octets `\r` vs `\n`), alors que **`README.md` est en LF pur**. La règle du
> projet est « respecter l'existant fichier par fichier » — ne pas uniformiser.

**Aucun fichier sous `core/`, `desktop/`, `resources/`.** Les deux seuls fichiers hors `docs/` sont
`README.md`, `LICENSE` et `plugin_info/info.json`, et uniquement pour la raison énoncée au § 1.2.

**Pas de fichier `NOTICE` ni `CREDITS.md` à la racine** (arbitrage au § 3.2).

### 1.2 Pourquoi `LICENSE` et `info.json` entrent dans le périmètre

Le dépôt donne **trois réponses contradictoires** à la question « sous quelle licence ce plugin est-il
publié ? » :

- `LICENSE` (racine) = texte **GNU GPL version 2, June 1991** — résidu du squelette ;
- en-têtes de **tous** les `.php` et `.py` = « GNU General Public License […] **version 3** of the
  License, or (at your option) any later version » ;
- `plugin_info/info.json` ligne 11 = `"licence": "AGPL"`, et `README.md` dit de même.

`credits.md` **doit** énoncer la licence du plugin : c'est l'objet même de la page. L'écrire sans aligner
le dépôt produirait une affirmation fausse **dans le document qui traite des licences** — le défaut
exactement inverse de celui qu'AC3 cherche à éviter. La décision D1 retient **GPL-3.0-or-later**, qui est
ce que portent déjà tous les fichiers source et ce qu'impose la dérivation du squelette
`jeedom/plugin-template` (GPL-3.0-or-later, seule dépendance **copyleft** du plugin). L'alignement se
limite donc à **deux fichiers**, aucun en-tête de source n'est touché.

⚠️ Le texte de remplacement de `LICENSE` est le texte **officiel et intégral** de la GNU GPL v3, recopié
sans altération. Ne pas le résumer, ne pas le reformuler, ne pas le traduire.

### 1.3 Contrat externe — comment Jeedom sert la documentation d'un plugin

Vérifié dans la doc développeur Jeedom, pas supposé :

1. Structure attendue : un dossier `docs/`, un dossier par langue, contenant **`index.md`** (documentation
   principale) et **`changelog.md`**. Format **Markdown**. Aucun sommaire ni nommage imposé pour les
   sous-pages — `credits.md` et `reseau-local.md` sont donc libres.
2. ⚠️ **Le core ne rend aucun Markdown local.** Les clés `documentation` / `changelog` /
   `documentation_beta` / `changelog_beta` d'`info.json` sont des **liens web** (substitution de
   `#language#` par la langue de l'utilisateur, repli automatique sur le français). Le bouton
   « Documentation » ouvre cette URL : **il ne lit jamais `docs/`**.
3. Hébergement prescrit par Jeedom : **GitHub Pages**, branche `master`, dossier `/docs`.
4. ⚠️ **Constat, sans action ici** : `info.json` déclare
   `https://doc.jeedom.com/#language#/plugins/wellness/smartclim` → **HTTP 404** (vérifié). Ce chemin
   n'existe que pour un plugin publié sur le market officiel. **Écrire la doc ne la rend pas atteignable
   depuis le plugin.** Hors périmètre par décision D5 → dépendance bloquante de l'UC04.
5. **Aucun Jekyll configuré** : `docs/` ne contient ni `_config.yml` ni front matter. Le pipeline de rendu
   final n'est donc pas arrêté (doc.jeedom.com aplatit en page de wiki ; GitHub Pages sans front matter
   sert le `.md` brut). **Conséquence de conception** : le contenu obligatoire est concentré dans
   `index.md`, les liens sont **relatifs et simples**, et rien ne dépend d'un thème ni d'une inclusion.

## 2. Plan de contenu — `docs/fr_FR/index.md`

L'ordre suit le **parcours d'un primo-utilisateur**, pas l'architecture du plugin.

```
# SmartClim — piloter un climatiseur AUX / Broadlink / AC Freedom depuis Jeedom

1. À lire avant d'installer                          → AC4
   1.1 Ce que fait ce plugin
   1.2 Mon climatiseur est-il compatible ?  (critère = protocole joignable, JAMAIS une liste de
       marques : le nom imprimé sur l'appareil ne décide de rien)
   1.3 Ce que ce plugin n'est pas  (encadré : reverse engineering communautaire, aucune
       documentation du fabricant, peut cesser de fonctionner sans préavis)
2. Installation
   2.1 Depuis le market · 2.2 Dépendances (écran « Dépendances », installation pip automatique)
   2.3 Le démon  ⚠ il ACCÉLÈRE, il ne CONDITIONNE jamais : le plugin fonctionne démon arrêté
3. Configurer un compte
   3.1 Compte AUX Home (appareils récents) : Adresse e-mail, Mot de passe, Pays
   3.2 Choisir le pays (défaut FRA, liste déroulante + « Autre pays » en code ISO à 3 lettres)
   3.3 Compte cloud historique (AC Freedom / AUX Cloud) : e-mail, mot de passe, Région EU/USA/CHN/RUS
   3.4 Un seul compte, les deux, ou aucun — ils sont indépendants
   3.5 Tester la connexion  ⚠ « Effacer les identifiants » n'existe QUE pour AUX Home
   3.6 Intervalle de rafraîchissement (1..1440 min, défaut 5) — et pourquoi descendre ne sert à rien
4. Découvrir ses climatiseurs (le scan)
   4.1 ⚠ Il n'y a pas de bouton « Ajouter » — et pourquoi
   4.2 Le bouton « Scanner les climatiseurs »
   4.3 Lire le résultat : « Sources interrogées » (ok / dégradée / échec / non configurée —
       ⚠ « non configurée » n'est PAS une erreur), « Climatiseurs », « Appareils écartés »,
       « Autres appareils détectés », « introuvables au dernier scan »
   4.4 Mon climatiseur n'apparaît pas : que faire
5. Les modes de transport
   5.1 Les trois chemins : AUX Home / Broadlink LAN / AUX Cloud (AC Freedom)
   5.2 Le réglage « Mode de transport » : Automatique / Local / Cloud (défaut Automatique)
   5.3 Ce qu'affiche « Transport actif »                          ← cœur d'AC6
   5.4 Le repli automatique et le retour au local (3 échecs, temporisation 30 s → 300 s)
   5.5 Pilotage en réseau local → lien vers reseau-local.md
6. Utiliser le plugin au quotidien
   6.1 Les commandes créées · 6.2 La tuile sur le dashboard
   6.3 La page « Mes climatiseurs »  ⚠ masquée tant qu'un administrateur n'a pas coché
       « Afficher le panneau desktop » ; droits par équipement UNIQUEMENT pour le profil restricted
   6.4 Le bouton « Rafraîchir » · 6.5 Bornes de température personnalisées
7. Régler un problème
   7.1 Les logs · 7.2 « Sonde de diagnostic »  ⚠ rapport masqué, MAIS à relire avant tout partage
   7.3 Supprimer un équipement en trop (doublon ou fausse détection LAN)
   7.4 Désinstaller ou désactiver le plugin  ⚠ les identifiants ne sont PAS effacés automatiquement
   7.5 Symptômes courants → cause probable
8. Limites connues                                    → AC2 + AC4
   8.1 Ces protocoles ne sont pas documentés par le fabricant (rupture possible sans préavis)
   8.2 ⚠ La température ambiante est en retard — NE PAS l'utiliser pour une régulation fine
   8.3 Broadlink LAN et AUX Cloud legacy : non validés sur matériel réel à ce jour
   8.4 Fonctions non disponibles à ce jour
   8.5 Le rafraîchissement n'est pas du temps réel
9. Crédits et licences (table courte + lien vers credits.md)     → AC3
10. Signaler un problème / contribuer
```

**Règle de rédaction non négociable** (elle sert AC6) : tout terme de protocole — *transport*, *cloud*,
*LAN*, *MAC*, *scan*, *capacités* — est **défini à sa première occurrence**, en une phrase, sans renvoi.
**Aucun lien vers `.memory/`, vers un fichier de code ou vers une classe** depuis ce fichier.

## 3. Crédits et licences

### 3.1 Source de vérité — ⚠️ la source prescrite par la spec est incomplète

La spec fonctionnelle renvoie à `.memory/analyse/smartclim-ecosysteme-aux-broadlink.md` § 6. **Cette
table omet les deux emprunts de code les plus lourds du plugin** (vérifié par l'advisor) :

- **`mjg59/python-broadlink`** (MIT) — que `smartclimBroadlinkLan.class.php` déclare pourtant en docblock
  « contrat protocolaire **entièrement** repris […] **source de vérité unique** », notice MIT déjà
  reproduite dans l'en-tête ;
- **`jeedom/plugin-template`** (GPL-3.0-or-later) — dont viennent tout le squelette et
  `resources/smartclimd/jeedom/*.py`, et qui est la **seule dépendance copyleft** du plugin.

**Rédiger depuis le § 6 seul produirait une section de crédits incomplète sur ses deux entrées les plus
contraignantes.** La source de vérité pour AC3 est donc : **les en-têtes et docblocks des classes
`core/class/smartclim*.class.php` et des modules `resources/smartclimd/*.py`**, le § 6 servant de
complément. Mettre le § 6 à jour est une tâche de **capitalisation de fin de cycle**, pas une préalable.

### 3.2 Emplacement des notices — un seul, `docs/fr_FR/credits.md`

Ni `NOTICE` ni `CREDITS.md` à la racine. Motifs :

1. **L'obligation MIT porte sur la distribution, pas sur un site web** (« include […] in all copies or
   substantial portions of the Software »). `docs/` fait partie du dépôt, donc du paquet installé sur le
   Jeedom de l'utilisateur : l'obligation est satisfaite **indépendamment** de l'activation de GitHub
   Pages — ce qui est décisif compte tenu du 404 du § 1.3.
2. Les notices sont **déjà présentes à la source**, dans les en-têtes des classes concernées ;
   `credits.md` les **agrège** sans en être l'unique porteur, ce qui borne le coût d'une divergence.
3. Un fichier racine **dupliquerait et divergerait** : `README.md` § Crédits porte déjà une liste
   **partielle** de 6 projets. La bonne action est de la remplacer par un **lien**, pas d'ajouter un
   troisième inventaire.
4. ⚠️ **Ne rien placer sous `.memory/`** : c'est le seul emplacement à la fois protégé en web et invisible
   à l'utilisateur — l'exact inverse du but d'une page de crédits.

### 3.3 Table des crédits (D2 : citer large, en trois niveaux)

**Niveau 1 — notice obligatoire, reproduite verbatim** (nom, auteur, licence, URL, ce qui est repris) :

| Projet | Auteur | Licence | Ce qui est repris |
|---|---|---|---|
| `mjg59/python-broadlink` | Mike Ryan (2014), Matthew Garrett (2016) et contributeurs | **MIT** | Tout le contrat protocolaire du transport Broadlink LAN (découverte, authentification, envoi de paquets, horodatage) |
| `GijsZwegers/com.zwegersit.auxairco` | Gijs Zwegers | **MIT** | Constantes de protocole AUX Home **et** legacy, recopiées verbatim |
| `maeek/ha-aux-cloud` | maeek | **MIT** | Cloud legacy ; séquence du relais WebSocket (`init`/`initk`/`sub`/`ping`) |
| `fparrav/homebridge-aux-cloud` | fparrav | **MIT** | Contrat d'écriture LAN re-vérifié indépendamment ; legacy ; stratégies de transport |
| `latentharbor/ha-aux-a-plus` | latentharbor et contributeurs | **MIT** | CRC-16 CCITT **porté** dans `sonde_auxlink.py` ; découverte AUXLink UDP |
| `jeedom/plugin-template` | Jeedom SAS | **GPL-3.0-or-later** | Squelette complet du plugin ; bibliothèque `resources/smartclimd/jeedom/` ; démon porté (**seul copyleft**) |

**Niveau 2 — source factuelle, aucune ligne de code reprise** (citation éditoriale, sans notice) :
`makleso6/homebridge-broadlink-heater-cooler` et `makleso6/broadlink-aircon-api` (Apache-2.0),
`maxmirazh33/aircore` (MIT), `Apollon77/node-ph803w`, `gizwits/Gizwits-GAgent`, et l'**article
zwegersit.nl** de Gijs Zwegers — ⚠️ ce dernier est la **source unique du fait d'AC2** (retard de
température), il doit être cité à ce titre.

> ⚠️ Pour les deux projets **Apache-2.0**, aucun en-tête de classe ne les cite comme source de code, et le
> transport LAN déclare `python-broadlink` comme source **unique**. La notice n'est donc pas obligatoire.
> **Position retenue** : reproduire quand même la mention de licence — le coût est nul, l'omission ne
> l'est pas. (Apache-2.0 § 4(d) n'impose de transmettre un fichier `NOTICE` que si le projet d'origine en
> fournit un.)

**Niveau 3 — projets consultés sans licence ouverte, ou voisins non utilisés** (mention explicite qu'aucun
code n'en provient) : `azadaydinli/ac_freedom` et `azadaydinli/homebridge-ac-freedom` (**aucune licence**,
`LICENSE` en 404 — tous droits réservés par défaut ; aucun code copié, et le transport LAN précise même
« non consulté pour l'écriture ») ; `GrKoR/esphome_aux_ac_component` (**NOASSERTION**, documente le
protocole AUX **série/UART**, qui n'est **aucun** des trois transports du plugin).

**Hors licence du dépôt** : `requests` 2.31.0 et `websocket-client` 1.6.1 — installées par `pip` à
l'exécution, **non redistribuées** dans ce dépôt : aucune obligation déclenchée, citation par courtoisie.

### 3.4 Plan de `credits.md`

```
1. Pourquoi cette page
2. Sous quelle licence SmartClim est publié       → GPL-3.0-or-later (D1)
3. Projets dont du code a été repris               → niveau 1, notices verbatim
4. Projets ayant servi de source factuelle         → niveau 2
5. Projets consultés sans licence ouverte          → niveau 3
6. Le squelette et les bibliothèques Jeedom
7. Les dépendances installées à l'exécution
8. Marques citées — aucune affiliation, aucun agrément
```

Le § 8 n'est exigé par aucun critère mais est le pendant naturel d'une page de licences : le plugin nomme
une douzaine de marques commerciales (AUX, Ballu, Centek, Kenwood, Hisense…) sans lien avec leurs
titulaires.

## 4. Les trois passages à haut risque de faux

### 4.1 « Transport actif » (§ 5.3, cœur d'AC6)

C'est la commande info `transport`. Elle affiche **le chemin réellement emprunté à la dernière lecture ou
commande**, pas le mode réglé : `AUX Home`, `Broadlink LAN` ou `AUX Cloud (AC Freedom)` (noms de marque,
non traduits). Formulation imposée : **« Mode de transport » = ce que vous demandez ; « Transport actif »
= ce qui a effectivement été utilisé** — et les deux peuvent diverger, ce qui est le signe **normal** d'un
repli, pas une anomalie.

### 4.2 Température ambiante (§ 8.2, AC2)

Formulation retenue : **« de quelques minutes à une demi-heure de retard »**, avec mention explicite que
ce constat est une **observation tierce** (article Zwegers), relevée **y compris dans l'application
officielle du fabricant**, et **jamais mesurée sur ce plugin**. Recommandation exigée par la spec : ne
**jamais** brancher une régulation fine (thermostat Jeedom, chauffage d'appoint) sur cette température ;
alternative proposée → une **sonde de température Jeedom dédiée** dans la pièce. Atténuation à signaler :
la commande « Dernière mise à jour » et la fraîcheur affichée par la tuile donnent l'âge de la donnée.

### 4.3 Fonctions non disponibles (§ 8.4) — ⚠️ piège de sourcing majeur

`CLAUDE.md` décrit longuement les fonctions de **confort** (afficheur, veille, santé, nettoyage,
anti-moisissure), d'**oscillation** (verticale, horizontale) et la **sécurité enfant** comme livrées.
Elles le sont — **désactivées**. Vérifié dans `core/class/smartclimCapabilities.class.php` : **11
occurrences de `'confirme' => false`, aucune de `'confirme' => true`**. **Aucune de ces huit fonctions
n'apparaît dans l'interface.**

**La documentation doit dire qu'elles ne sont pas disponibles à ce jour.** Écrire l'inverse à partir de
`CLAUDE.md` serait le faux le plus probable de cette UC.

## 5. Stratégie de sourcing — quel fait se vérifie où

Toute affirmation de la doc doit être vraie **du code livré**. Aucune vérification ne demande d'exécuter
PHP.

| Fait | Où le vérifier | Valeur constatée |
|---|---|---|
| Boutons de la page de configuration | `desktop/php/smartclim.php` | « Scanner les climatiseurs », « Sonde de diagnostic » — **aucun bouton « Ajouter »** |
| Champs AUX Home | `plugin_info/configuration.txt` | e-mail, mot de passe, **liste déroulante** Pays + « Autre pays (code ISO à 3 lettres) » |
| Champs legacy | idem | e-mail, mot de passe, Région (**liste**, pas de saisie libre) |
| ⚠️ « Effacer les identifiants » | idem | **AUX Home uniquement** — aucun équivalent côté legacy. Ne pas décrire un bouton symétrique |
| Intervalle / pays / région par défaut | `configuration.txt`, `core/config/smartclim.config.ini` | 1..1440 min défaut **5** ; `FRA` ; `EU` |
| Modes de transport | `smartclimTransport.class.php` | **Automatique / Local / Cloud**, défaut Automatique |
| Noms des transports affichés | `smartclimCapabilities.class.php` | « AUX Home », « Broadlink LAN », « AUX Cloud (AC Freedom) » |
| Commandes info | `smartclim.class.php`, `smartclimCapabilities.class.php` | Disponibilité, Marche-Arrêt, Mode, Consigne, Température ambiante, Vitesse de ventilation, **Transport actif**, **Dernière mise à jour** |
| Commandes action | `smartclim.class.php` | `on`/`off`, modes, vitesses, consigne (curseur), **« Rafraîchir »** |
| Modes et vitesses (libellés FR) | `smartclimCapabilities.class.php` | Automatique, Froid, Déshumidification, Chauffage, Ventilation / Automatique, Silencieux, Faible, Moyen-faible, Moyen, Moyen-fort, Turbo — **affichés seulement si détectés sur l'appareil** |
| Bornes de température | idem | **16-32 °C**, pas **0,5 °C** ; enveloppe personnalisable **5-35 °C** |
| Cadences réelles | `smartclim.class.php` | Cloud AUX Home = `refresh_interval` (défaut 5 min) ; **LAN = 900 s FIXES** ; **legacy = 900 s FIXES** — ⚠️ les deux derniers **indépendants du réglage utilisateur**, à dire explicitement |
| Grâce après commande / repli | `smartclim.class.php`, `smartclimTransport.class.php` | 60 s / **3 échecs**, temporisation **30 s → 300 s** |
| Page-panneau | `desktop/php/panel.php`, `info.json` | « Mes climatiseurs » ; ⚠️ **masquée** tant que « Afficher le panneau desktop » n'est pas coché ; droits par équipement **uniquement** pour le profil `restricted` |
| Tuile dashboard | `smartclimWidget.class.php` | Posée sur la commande info `power` ; masque `ambient_temp`/`target_temp` pour éviter le doublon |
| Dépendances et démon | `info.json`, `packages.json` | Écran « Dépendances » + panneau « Démon » visibles ; **le démon est latéral, le plugin fonctionne sans lui** |
| Fonctions confort / oscillation / sécurité enfant | `smartclimCapabilities.class.php` | **Toutes `confirme => false` → aucune n'est visible** (cf. § 4.3) |
| Statut non recetté LAN / legacy | docblocks des deux transports | À énoncer honnêtement au § 8.3 |

## 6. Validation

### 6.1 AC5 — anonymisation (D4 : aucune capture)

La machine de développement n'a **ni PHP ni Jeedom** : une capture ne pourrait venir que de
l'installation de l'utilisateur, avec ses identifiants réels à l'écran — **exactement le risque qu'AC5
élimine**. Remplacé par une **description textuelle reprenant les libellés exacts** de l'IHM, plus robuste
qu'une image qui périme au premier changement d'écran.

**Un seul jeu d'exemples fictifs, réutilisé partout**, chaque première occurrence portant la mention
« (exemple fictif) » : `mon.compte@exemple.fr` · `192.168.1.42` (RFC 1918) · `aa:bb:cc:dd:ee:ff` ·
identifiant cloud `XXXXXXXXXXXX`.

⚠️ **`docs/` n'est couvert par AUCUN filet automatique** : `.claude/scripts/verif-plugin.py` ne parcourt
que `core/`, `desktop/` et `plugin_info/`. Les contrôles ci-dessous sont donc le **seul** filet — ils
doivent être **exécutés et leur sortie jointe au rapport**, pas seulement prescrits :

| Contrôle | Attendu |
|---|---|
| `find docs -type f ! -name '*.md'` | vide (aucune image) |
| recherche d'adresses e-mail dans `docs/` | uniquement `@exemple.fr` |
| recherche de MAC (`xx:xx:…` et 12 hexa nus) dans `docs/` | uniquement `aa:bb:cc:dd:ee:ff` |
| recherche de `bearer`/`passcode`/`loginsession`/`dev_session`/`apikey` | aucune occurrence |

### 6.2 AC6 — recette de lecture : ce qui en tient lieu

**Ce critère ne peut pas être coché par un agent** : il exige une personne n'ayant pas participé au
développement, et aucune automatisation ne simule ce jugement. **AC6 est livré explicitement non coché**,
avec la mention « à valider par un lecteur tiers » — jamais coché en silence.

Trois substituts vérifiables, joints au rapport de livraison :

1. **Grille de traçabilité** : chaque étape du parcours d'AC6 (« configurer son compte » → § 3 ; « lancer
   un scan » → § 4 ; « comprendre le transport actif » → § 5.3) rattachée à une section nommée, chaque
   section lisible **sans lire les autres**.
2. **Contrôle de vocabulaire** : liste des termes techniques employés, chacun avec la ligne où il est
   défini. Tout terme non défini est un défaut bloquant.
3. **Contrôle d'autonomie** : aucun renvoi vers `.memory/`, vers un chemin de code ou vers un `.php`
   depuis `index.md`.

**Protocole de recette à remettre à l'utilisateur** : faire lire `index.md` à un tiers, chronométrer les
trois tâches, et relever **les questions qu'il pose** — ce sont elles, et non le succès, qui désignent les
trous.

### 6.3 Autres contrôles

| Contrôle | Attendu |
|---|---|
| Fins de ligne | **comptage d'octets** `\r` vs `\n` (jamais un `grep -c` mal échappé) : CRLF pour `docs/**`, **LF pur** pour `README.md` |
| Méta-séquences (`{{`, `*/`, `?>`) | **sans objet** : `docs/**` n'est ni rendu par PHP ni traité par le moteur i18n |
| `verif-plugin.py` | doit rester **vert** ; il ne couvre pas `docs/`, mais `info.json` et `README.md` sont dans son périmètre ou à proximité |
| Liens internes | `index.md` référence `reseau-local.md` et `credits.md` ; les deux existent — aucun lien mort |
| Validité de `info.json` | le JSON doit parser après le changement de la valeur `licence` |

## 7. Impact i18n

**Aucun.** Zéro chaîne `{{…}}`, zéro `__()`, zéro clé nouvelle, **aucun fichier `core/i18n/*.json`
touché**. `docs/**` est hors du périmètre du scan d'extraction i18n. La traduction de cette documentation
vers `en_US` / `de_DE` / `es_ES` est **explicitement hors périmètre** (spec § Hors périmètre) et relève de
l'UC04 du même domaine.

⚠️ **Le sous-agent `translator` n'a donc rien à faire sur cette UC** — ne pas le lancer « par symétrie »
avec les cycles précédents.

## 8. Risques

- **R1 — Source prescrite pour AC3 incomplète** (le plus grave) : traité au § 3.1 par un sourcing depuis
  les en-têtes de classes.
- **R2 — `CLAUDE.md` décrit comme livrées huit fonctions invisibles** : traité au § 4.3.
- **R3 — La doc écrite reste inatteignable depuis le plugin** (URLs en 404) : hors périmètre par D5, mais
  les critères d'acceptation portent sur le **contenu** de `docs/fr_FR/` et sont donc satisfaits.
  ⚠️ L'**objectif** de la spec (« rendre le plugin utilisable de façon autonome ») ne l'est pas tant que le
  lien n'est pas corrigé → **dépendance bloquante à inscrire dans l'UC04**.
- **R4 — Trois licences contradictoires** : tranché par D1, aligné au § 1.2.
- **R5 — Documenter des transports non recettés** : LAN et legacy sont livrés sans validation matérielle.
  La doc doit le dire — mais une formulation trop alarmante décourage les seuls utilisateurs capables de
  les recetter. **Registre imposé : factuel** (« non validé sur matériel réel à ce jour ; vos retours sont
  utiles »), **jamais** « expérimental » ni « instable ».
- **R6 — Péremption** : une doc qui cite des libellés d'IHM périme au premier changement d'écran.
  Atténuation : citer les libellés **exacts** (donc repérables au `grep` lors d'un futur renommage) et
  **dater** la page (« à jour de la version *N* du plugin »).
- **R7 — `docs/` sans filet automatique** : cf. § 6.1, les contrôles doivent être **exécutés**.
- **R8 — `README.md` racine périmé** : il annonce Broadlink LAN et le cloud legacy comme « prévus » alors
  que les deux sont livrés, et le MVP comme « en cours ». **Hors périmètre de cette UC** — ne corriger que
  le § Crédits. Signalé pour que ce ne soit pas pris pour un oubli.

## 9. Dépendances

Aucune. Aucun paquet système ou pip n'est ajouté ; `plugin_info/packages.json` n'est pas touché.

⚠️ **Bump de version** : le hook `pre-commit` incrémente `pluginVersion` dès qu'un commit touche
`plugin_info/` — ce qui sera le cas ici via `info.json`. C'est **normal et souhaitable** (la valeur
`licence` est servie au market). En revanche un commit qui ne toucherait que `docs/` et `README.md` ne
consommerait **aucune** version : à ne pas « corriger ».

## 10. Dette

*(section alimentée en fin de cycle par les findings de review n'atteignant pas la gate)*
