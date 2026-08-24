# UC03 — Icône du plugin

> **Domaine** : post-mvp/07-multimarque-documentation-et-diffusion · **Statut** : à implémenter ·
> **Dépend de** : — (aucune dépendance technique ; réalisable dès que l'identité du plugin est fixée, donc
> à tout moment avant la publication)

## Objectif

SmartClim est publié sur le market Jeedom sous son identité propre, et non sous celle du template dont il
est issu. Le fichier `plugin_info/smartclim_icon.png` **existe** dans le dépôt mais n'est, à ce stade,
qu'une copie renommée de l'icône générique du template : le plugin n'a **pas encore** d'identité visuelle.
Cette UC livre une icône propre à SmartClim, conforme aux exigences du market Jeedom, qui le rend
reconnaissable dans la liste des plugins et sur le market — et fait disparaître visuellement toute trace du
template.

## Comportement attendu

- L'icône évoque la climatisation, l'air ou le confort thermique — le domaine fonctionnel du plugin — sans
  reprendre le visuel du template ni celui d'un autre plugin.
- Elle reste **lisible en petite taille** : elle est affichée en vignette dans la liste des plugins Jeedom
  et dans le market, jamais en grand format à l'usage courant.
- ⚠️ SmartClim est **multimarque** (AUX, Broadlink, AC Freedom et tout appareil compatible avec cet
  écosystème, `.memory/brief.md`) : l'icône ne reprend **aucun logo ni marque déposée** d'un constructeur
  de cette liste, ni d'aucun autre. Ce n'est pas qu'une question de cohérence avec la promesse
  multimarque du plugin — c'est aussi un risque juridique réel (contrefaçon de marque) si un logo tiers
  était repris.
- Le moyen de production du visuel (génération par IA, dessin vectoriel, banque d'icônes libre de droits…)
  n'est pas tranché par cette UC : c'est un choix d'implémentation. En revanche, quel que soit le moyen
  retenu, la **provenance et la licence** de tout élément réutilisé (icône de banque, modèle génératif,
  police, forme) doivent être traçables — cohérent avec la logique de crédits de licences déjà posée par
  l'UC02 de ce domaine.
- Une fois l'icône livrée, `plugin_info/smartclim_icon.png` n'est plus une copie renommée de l'icône du
  template : son contenu visuel a réellement changé.

## Critères d'acceptation

- [ ] **AC1** — Le fichier `plugin_info/smartclim_icon.png` est un PNG de dimensions 309 × 348 pixels.
- [ ] **AC2** — L'image présente des bords arrondis, un fond coloré, et une zone transparente autour du
      sujet (vérifiable en ouvrant le fichier sur un fond contrasté : la transparence est visible autour du
      dessin, pas de rectangle plein bord à bord).
- [ ] **AC3** — Aucun texte (nom du plugin ou autre) n'apparaît sous ou sur le dessin de l'icône.
- [ ] **AC4** — Affichée en vignette (taille réduite, telle qu'elle apparaît dans la liste des plugins
      Jeedom), l'icône reste identifiable : le sujet ne se brouille pas en un amas de détails illisibles.
- [ ] **AC5** — Dans la liste des plugins de l'interface d'administration Jeedom, SmartClim affiche cette
      icône, visuellement **différente** de l'icône du template (comparaison directe possible avec
      l'icône d'un plugin resté sur le template, ou avec l'ancienne version du fichier).
- [ ] **AC6** — Une revue visuelle de l'icône ne fait apparaître aucun logo, aucun nom de marque, aucun
      élément graphique reconnaissable comme appartenant à AUX, Broadlink, AC Freedom ou tout autre
      constructeur de climatiseurs.
- [ ] **AC7** — La provenance de l'icône (source, auteur/outil, licence) est documentée et retrouvable
      (par exemple dans la section « Crédits » de l'UC02 de ce domaine, si un élément tiers a été réutilisé
      pour la produire).

## Impact i18n

- Sans objet : l'icône est un fichier image, hors périmètre des mécanismes `{{...}}` / `__()` et des
  fichiers `core/i18n/*.json`.

## À confirmer

- Le choix chromatique définitif : la doc développeur Jeedom interdit de reprendre le code couleur des
  icônes de plugins **officiels** Jeedom, sans lister les codes exacts à éviter. Ce point ne peut être
  tranché qu'au moment du rendu réel, par comparaison visuelle avec les icônes des plugins officiels
  existants.
- L'existence éventuelle d'une déclinaison de l'icône attendue par le market Jeedom au-delà de ce seul
  fichier `plugin_info/<id>_icon.png` (par exemple une vignette dédiée au market, distincte de l'icône du
  plugin) n'est pas confirmée par les analyses internes actuelles — à vérifier contre la documentation
  développeur au moment de l'implémentation.

## Hors périmètre

- Les icônes des **commandes** (widgets `core/template/{dashboard,mobile}/cmd.*.html`) et le visuel de la
  tuile de dashboard → `post-mvp/06-ergonomie-jeedom`.
- La publication du plugin sur le market elle-même (soumission, modération) → UC04 de ce domaine.
- La rédaction de la section « Crédits » et la vérification de compatibilité des licences des éléments
  tiers réutilisés → UC02 de ce domaine ; cette UC exige seulement que la provenance de l'icône y soit
  traçable si pertinent.
