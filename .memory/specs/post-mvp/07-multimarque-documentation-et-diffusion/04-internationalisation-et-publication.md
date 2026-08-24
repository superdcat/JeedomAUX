# UC04 — Traductions complètes et préparation à la publication

> **Domaine** : post-mvp/07-multimarque-documentation-et-diffusion · **Statut** : à implémenter ·
> **Dépend de** : UC02 de ce domaine (documentation utilisateur et crédits de licences), UC03 de ce domaine
> (icône du plugin)

## Objectif

SmartClim est nativement multilingue (`CLAUDE.md`, langue source française, cibles `en_US`/`de_DE`/`es_ES`)
et destiné à être publié sur le market Jeedom. Cette UC est la **passe de clôture** : elle vérifie que
toutes les chaînes UI livrées au fil des features précédentes sont effectivement traduites et correctement
enveloppées, que le manifeste respecte les règles du market, et que le dépôt est prêt à être publié —
sans refaire le travail de traduction feature par feature, qui a déjà été produit en continu.

## Comportement attendu

- Chaque fichier `core/i18n/en_US.json`, `core/i18n/de_DE.json`, `core/i18n/es_ES.json` contient une entrée
  pour **chaque** chaîne UI (`{{...}}`) et **chaque** appel `__(...)` présents dans le code du plugin,
  sans clé manquante ; aucun fichier `core/i18n/fr_FR.json` n'existe (la clé française est le texte
  source).
- Le manifeste `plugin_info/info.json` porte une `description` sous forme d'objet à clés de langue
  (`fr_FR`, `en_US`, `de_DE`, `es_ES`), chaque valeur faisant au moins 80 caractères — ⚠️ ce mécanisme est
  **distinct** des fichiers `core/i18n/*.json` et ne doit jamais y être dupliqué.
- Aucune chaîne affichée à l'utilisateur n'est laissée sans enveloppe de traduction (`{{...}}` en HTML/JS,
  `__(..., __FILE__)` en PHP). Aucun appel de traduction n'est fait sur une variable — l'extraction i18n est
  un scan statique, un libellé stocké en variable puis passé à `__()` échapperait silencieusement à la
  traduction.
- L'icône du plugin, sa catégorie (`wellness`) et les liens de documentation déclarés dans `info.json`
  pointent vers des ressources réelles du plugin — plus aucun placeholder hérité du template.
- Le manifeste et les fichiers livrés ne contiennent plus aucune trace du plugin `template` d'origine (id,
  nom, textes ou liens de démonstration du squelette).
- La CI du dépôt (workflows Jeedom réutilisables, `.github/workflows/work.yml`) passe au vert sur la
  branche de publication.

## Critères d'acceptation

- [ ] **AC1** — Un audit des fichiers `core/i18n/en_US.json`, `de_DE.json`, `es_ES.json` montre une entrée
      pour chaque chaîne `{{...}}` et chaque appel `__(...)` présents dans le code du plugin, sans clé
      manquante dans aucun des trois fichiers.
- [ ] **AC2** — Aucun fichier `core/i18n/fr_FR.json` n'existe dans le dépôt.
- [ ] **AC3** — `plugin_info/info.json` contient un champ `description` sous forme d'objet multilingue
      (`fr_FR`, `en_US`, `de_DE`, `es_ES`) dont chaque valeur fait au moins 80 caractères.
- [ ] **AC4** — Un audit du code ne révèle aucune chaîne UI affichée à l'utilisateur qui ne soit pas
      enveloppée par `{{...}}` (HTML/JS) ou `__(..., __FILE__)` (PHP).
- [ ] **AC5** — Un audit du code ne révèle aucun appel `__($variable)` à argument non littéral — uniquement
      des appels à texte français littéral.
- [ ] **AC6** — L'interface Jeedom affichée dans une langue cible (`en_US` au minimum) ne fait apparaître
      aucune chaîne restée en français par défaut de traduction manquante, sur un parcours de recette
      couvrant la configuration du plugin, le scan et le pilotage d'un équipement.
- [ ] **AC7** — `plugin_info/info.json` ne contient plus aucune référence au plugin `template` (id, nom,
      catégorie ou liens de démonstration du squelette).
- [ ] **AC8** — Le pipeline `.github/workflows/work.yml` est vert sur la branche/PR de publication.
- [ ] **AC9** — Contrôle de conformité (le livrable lui-même relève de l'UC03 de ce domaine) :
      `plugin_info/smartclim_icon.png` existe, s'affiche correctement dans la liste des plugins Jeedom, et
      les critères d'acceptation de l'UC03 sont satisfaits.

## Impact i18n

- Cette UC est elle-même la passe de complétude i18n : elle ne crée pas de nouvelles chaînes, elle vérifie
  que toutes celles introduites par les UC précédentes (MVP et post-MVP) sont traduites dans les trois
  langues cibles.

## À confirmer

- L'outillage exact utilisé pour l'audit statique (détection de chaînes non enveloppées, détection d'appels
  `__()` sur variable) — script ou revue manuelle — est un choix d'implémentation, à trancher dans la spec
  technique de cette UC.
- La cible des liens de documentation déclarés dans `info.json` (dépôt GitHub public, pages `docs/fr_FR/`
  locales, ou les deux) n'est pas tranchée — à confirmer contre la convention market Jeedom.
- L'acceptation de la catégorie `wellness` par la modération du market reste, comme déjà noté dans l'analyse
  architecture (§ 10), un point ouvert ; un repli vers `devicecommunication` resterait possible sans impact
  sur cette UC.

## Hors périmètre

- La production des traductions elle-même, UC par UC, au fil du développement : c'est le rôle de l'agent
  `translator` en fin de cycle `/feature` pour chaque feature ; cette UC vérifie la **complétude finale**,
  elle ne retraduit rien qui serait déjà correctement traduit.
- La traduction de la documentation utilisateur (`docs/fr_FR/`) vers d'autres langues → hors périmètre,
  cf. UC02 de ce domaine, « Hors périmètre ».
- Toute démarche de soumission administrative au market Jeedom (compte développeur, processus de
  modération) n'est pas couverte : cette UC prépare le dépôt à être publiable, elle ne pilote pas la
  publication elle-même.
