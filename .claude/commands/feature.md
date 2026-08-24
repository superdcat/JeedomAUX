---
description: Prépare et valide le plan technique complet d'une feature à partir de sa spec fonctionnelle, puis délègue l'implémentation à l'agent php-jeedom-dev, exécute les reviews croisées et la traduction.
argument-hint: [nom-de-la-spec]
model: opus
effort: xhigh
---

# Workflow agentic complet — orchestrateur

Tu vas dérouler le workflow complet pour la feature `$ARGUMENTS`.

Tu es l'**orchestrateur/architecte** (Opus, `effort: xhigh`). Ton travail : **préparer et faire valider
le plan technique**, puis **déléguer l'implémentation** à l'agent `php-jeedom-dev` (Sonnet), et enfin
piloter reviews croisées, traduction et capitalisation. **Tu ne codes pas toi-même** la feature :
l'écriture du code est confiée à l'agent développeur (étape 6). Tu restes responsable de la qualité du
plan, de la validation utilisateur, des gates de review et de la synthèse finale.

## Consultation doc & connaissance — À LA DEMANDE seulement (lazy)

Tu élabores le **plan** à partir de la spec et de `CLAUDE.md` (déjà en contexte). **Ne charge RIEN « par
sécurité ».** Ne consulte une source externe/interne **que si une incertitude concrète te bloque**
(typiquement étapes 2 et 7 — le plan et sa vérification ; la consultation en cours de code est gérée par
l'agent `php-jeedom-dev` via la skill `dev`) ; sinon, avance.

Quand c'est le cas, dans cet ordre et en t'arrêtant dès que tu as la réponse — chaque INDEX porte son
propre mode d'emploi en en-tête, ne le redécris pas ici :

1. **Connaissance interne d'abord** (local, gratuit, propre au projet) : `.memory/analyse/INDEX.md`
   (§ 0 = incertitude → fichier), puis ouvre **uniquement** le fichier pointé. `.memory/specs/README.md`
   décrit la convention des specs.
2. **Doc de l'API / service tiers** que pilote le plugin (le cas échéant) : contrat des endpoints,
   payloads, codes d'erreur. Si le plugin cible une API sans doc officielle propre, la source de vérité
   est le **code de référence** (implémentation existante, SDK) — fais **un seul `WebFetch`** ciblé.
3. **Doc Jeedom** (contrat du core) : `.memory/external/doc/jeedom/INDEX.md`. Pour une signature de classe
   core (`cache::`, `config::`, hooks `eqLogic`/`cmd`…), lis la **source du core**, pas le wiki.

Astuce tokens : **`grep` l'INDEX** pour la ligne utile plutôt que de le `Read` en entier. **Cite** l'info
retenue (endpoint, champ, code d'erreur) et sa source. Si une source **contredit** une spec/analyse
interne, **signale l'écart** — ne tranche pas en silence (la doc officielle fait foi sur le contrat,
l'analyse interne sur les décisions projet).

## Étape 1 — Charger la spec fonctionnelle

Lis la spec fonctionnelle `$ARGUMENTS` sous `.memory/specs/` (ex. `.memory/specs/**/$ARGUMENTS.md`).
Confirme en 1-2 phrases ce que tu as chargé. Si aucune spec fonctionnelle n'existe encore pour cette
feature, **demande à l'utilisateur** de la fournir/valider avant de continuer (le plan technique dérive
de ses critères d'acceptation).

## Étape 2 — Générer le plan technique

Sur la base de la spec, propose un plan concis :

- **Contrats externes** : pour chaque appel réseau (API HTTP, commande via démon…), l'endpoint/topic, les
  paramètres/payload et le format de réponse. En cas de doute, applique *Consultation à la demande*
  (interne d'abord, puis code de référence/doc de l'API) **avant** de figer le plan — pas en cours
  d'implémentation.
- Type de composant ; architecture (fichiers à créer/modifier) ; logique de validation ; appels AJAX /
  actions nécessaires ; dépendances éventuelles (`packages.json`).
- **Impact i18n** : lister les nouvelles chaînes UI **en français uniquement** (clés `{{...}}` / `__()`).
  La traduction est différée (étape 10) — ici on anticipe juste la liste FR.

## Étape 3 — Challenge par advisor

Invoque le sous-agent `code-reviewer` en mode advisor (revue critique du **plan**, pas du code) : risques
d'architecture, points de convention, suggestions. Présente la synthèse.

## Étape 4 — Validation utilisateur du plan

**ARRÊTE-TOI ICI** et demande :

> "Le plan technique te convient-il ? Veux-tu ajuster avant l'implémentation ? (oui / propose des ajustements)"

Attends sa réponse.

## Étape 5 — Écriture de la spec technique

Plan validé, écris la spec technique dans `.memory/specs/[même dossier]/$ARGUMENTS-tech.md` :

```markdown
# Spec technique — $ARGUMENTS

## Architecture
[composants, fichiers, structure]

## Server vs Client
[décision et justification]

## Validation
[stratégie côté client et serveur]

## Server Actions / API
[signatures et logique]

## Dépendances
[paquets à installer si nécessaires]
```

## Étape 6 — Délégation de l'implémentation à l'agent `php-jeedom-dev`

**Tu ne codes pas.** Invoque le sous-agent **`php-jeedom-dev`** (Sonnet, `effort: xhigh`) pour écrire le
code à partir de la spec technique. Il tourne en contexte neuf : passe-lui **explicitement** dans son
prompt de lancement :

- le **nom de la feature** `$ARGUMENTS` ;
- le **chemin de la spec fonctionnelle** (celle de l'étape 1) et le **chemin de la spec technique**
  `…-tech.md` (celle de l'étape 5) — ce sont ses entrées de travail ;
- la consigne : *« Lis `CLAUDE.md`, la spec technique (plan) et la spec fonctionnelle (critères
  d'acceptation), puis implémente via la skill `dev` en bouclant jusqu'à convergence. i18n : français
  uniquement, ne touche pas aux `core/i18n/*.json`. Ne commite pas. Rends le rapport structuré. »*

L'agent boucle en interne (skill `dev` : cadrer → implémenter → vérifier → auto-revue → itérer) jusqu'à
ce que les critères d'acceptation et sa checklist qualité soient verts, puis **te rend un rapport
structuré** (fichiers modifiés, état des critères, chaînes UI FR introduites, points « à confirmer »).

**Conserve l'`agentId` renvoyé** : tu réutiliseras le **même agent** (via `SendMessage`) pour lui faire
corriger les findings de review à l'étape 9, ce qui préserve son contexte d'implémentation.

**i18n : le code est écrit en français, point.** L'agent enveloppe chaque chaîne UI
(`{{Texte français}}` / `__('Texte français', __FILE__)`) mais **ne touche PAS** aux
`core/i18n/{en_US,de_DE,es_ES}.json` : la traduction est déléguée au sous-agent `translator` en étape 10,
sur le code figé.

## Étape 7 — Réception du livrable & vérification

À partir du **rapport de l'agent** (étape 6), fais une **vérification indépendante** (l'agent a déjà
auto-vérifié ; toi tu contrôles, tu ne re-codes pas) :

- **Couverture** : chaque critère d'acceptation est couvert ou explicitement « à valider en recette ».
- **Fidélité spec technique** : les fichiers touchés et le chemin d'appel correspondent au plan de
  l'étape 5 ; tout écart signalé par l'agent est acceptable/justifié.
- **Contrats externes** : endpoints/topics, paramètres/payloads et parsing correspondent au contrat réel
  (recoupe interne + doc/code de référence au moindre doute résiduel ; signale tout écart
  code/spec/analyse/doc). Applique *Consultation à la demande* si un doute concret subsiste.
- **i18n** : vérifie **uniquement l'enveloppage français** (aucune chaîne en dur). La couverture des
  langues cibles n'est **pas** attendue ici (étape 10) — ne la compte pas comme un défaut.

Si le livrable est manifestement incomplet ou hors plan, **renvoie-le à l'agent** (`SendMessage`) avant
de lancer les reviews.

## Étape 8 — Reviews croisées

C'est **ta gate de review indépendante** (l'auto-revue de l'agent ne la remplace pas). Sur les fichiers
créés/modifiés **listés dans le rapport de l'agent** (étape 6), lance **en parallèle** :
- sous-agent `security-reviewer` ;
- sous-agent `code-reviewer` (passe-lui la spec technique en contexte pour la review « cohérence spec »).

Précise-leur que la traduction est **volontairement différée** : ils évaluent le code FR et l'enveloppage
(`{{...}}` / `__()`), mais l'absence de traduction `en_US`/`de_DE`/`es_ES` n'est **pas** un finding ici.
Présente une synthèse.

## Étape 9 — Décision finale utilisateur

Si findings `critical`/`high` (ou `blocker`/`major`), demande :

> "Reviews terminées. [N findings critiques/high]. Je propose des fix maintenant ou je continue ? (fix / continue)"

Attends la réponse. Si fix demandés : **ne corrige pas toi-même** — renvoie les findings à l'agent
`php-jeedom-dev` via `SendMessage` (même `agentId` qu'à l'étape 6, pour réutiliser son contexte
d'implémentation), en lui listant précisément les findings à traiter. Quand il rend la main, **relance les
reviews croisées** (étape 8) jusqu'à ce qu'il ne reste plus de `critical`/`high`/`blocker`/`major`.

## Étape 10 — Traduction (sous-agent `translator`) — **une fois tout validé**

Le code FR est figé et validé. Invoque le sous-agent `translator` sur les fichiers créés/modifiés. Il doit :
- extraire toutes les clés UI françaises (`{{...}}` / `__()`) introduites/modifiées ;
- remplir/mettre à jour `core/i18n/{en_US,de_DE,es_ES}.json` sous `plugins/template/<fichier>` ;
- garantir la **couverture complète des langues cibles**, signaler toute **clé orpheline** ;
- **valider lui-même que les JSON parsent** avant de rendre la main (il dispose de `Bash` et lance la
  validation Python ; il ne rend `pass` que si les fichiers parsent). **Tu n'as donc PAS à re-valider les
  JSON au retour** — c'est garanti par le sous-agent ;
- si la `description` du plugin a changé : mettre à jour `info.json` (toutes langues) et
  `docs/{fr_FR,en_US,de_DE,es_ES}/`.

Si verdict `needs_changes` (clés manquantes / JSON invalide non corrigé), relance jusqu'à couverture
complète et JSON valides. Présente la synthèse (clés ajoutées par langue, orphelines éventuelles).

## Étape 11 — Présentation finale

```
✅ Feature : $ARGUMENTS

📋 Spec fonctionnelle : .memory/specs/*/$ARGUMENTS.md
📐 Spec technique : .memory/specs/*/$ARGUMENTS-tech.md
💻 Feature : [fichiers créés/modifiés]
🔒 Review sécurité : [verdict]
🎯 Review qualité : [verdict]
🌍 Traduction (en/de/es) : [verdict translator — clés ajoutées par langue]
```

## Étape 12 — Capitalisation mémoire (apprentissages durables)

**Avant de clore**, capture ce que ce cycle a révélé et qui **servira aux features suivantes** —
**uniquement si c'est réellement nouveau**. Si tout est déjà couvert (specs, `CLAUDE.md`, code, doc
locale, git), **n'écris rien** (ni fichier, ni ligne, ni note « rien de neuf ») et clôture.

**Que retenir** (typique) : contrat d'une API tierce non évident confirmé contre le code de référence
(nom/type réel d'un champ, payload exact d'une commande, schéma d'auth) ; code d'erreur et son sens réel ;
comportement empirique d'un quota/limite (rate-limit, expiration token) ; piège du core Jeedom (hook,
récursion `save`, autoload, démon/socket) ; décision d'archi prise pendant le dev.

**Où écrire** (selon la nature) :
1. **Mémoire persistante inter-sessions** (`MEMORY.md` + fichier sous le dossier mémoire de l'agent,
   chargée auto chaque session) : pour un apprentissage transverse utile dès le prochain démarrage.
   Format : 1 fichier = 1 fait (frontmatter `name`/`description`/`metadata.type`, corps FR, liens
   `[[autre]]`), **puis** une ligne de pointeur dans `MEMORY.md`.
2. **`.memory/analyse/`** (**versionné, partagé équipe**) : pour une analyse/décision/limite **propre au
   projet ou générique Jeedom**. ⚠️ N'est utile que s'il reste **découvrable** → écris dans le fichier
   thématique existant (ou crée-en un) **ET mets `.memory/analyse/INDEX.md` à jour** (ligne + déclencheurs
   § 0 + date) — sinon un futur `/feature` ne le relira jamais. Alternative : la spec technique `…-tech.md`
   si l'info est strictement locale à la feature.

> Un apprentissage transverse important mérite souvent **(1) + (2)** : en (1) le fait condensé, en (2)
> l'analyse détaillée — qui se référencent, sans doublon littéral.

**Règles** : avant d'écrire, **vérifie l'existant** (`MEMORY.md`, `.memory/analyse/INDEX.md` + fichier) et
mets à jour plutôt que dupliquer ; supprime une note devenue fausse. N'enregistre **pas** ce que
code/git/`CLAUDE.md`/specs disent déjà. Dates absolues ; **jamais** de secret/token.

Si tu as mémorisé : présente-le en 1-3 lignes. Sinon, ne dis rien de spécial et clôture.

## Étape 13 — Mise à jour de `CLAUDE.md` (fin de cycle)

`CLAUDE.md` est **lu par toute future session** : une affirmation qui devient fausse après cette feature
(architecture non actualisée, mention « à créer »/« reste à faire » visant un fichier/classe qui existe
désormais) transmet une fausse information à chaque `/feature` suivant. Avant de clore, vérifie et corrige
**uniquement ce que cette feature a rendu faux** :

- **Section Architecture** : si un composant décrit comme futur (« à créer », « prévu ») vient d'être
  implémenté, reformule au présent — ou l'inverse si une feature retire/déplace un composant documenté.
- **Autres sections** (Configuration & secrets, Conventions, i18n…) : seulement si cette feature a
  introduit une clé de config, un fichier, ou une convention qui n'y figure pas encore.

**Ne réécris pas** ce qui reste vrai. Pas de refonte ni de reformulation cosmétique — uniquement les
phrases concrètement rendues obsolètes ou incomplètes par `$ARGUMENTS`. Si rien n'a changé dans
`CLAUDE.md`, ne dis rien de spécial et clôture.
