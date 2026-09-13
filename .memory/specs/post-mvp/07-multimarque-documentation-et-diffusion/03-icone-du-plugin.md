# UC03 — Icône du plugin

> **Domaine** : post-mvp/07-multimarque-documentation-et-diffusion · **Statut** : à implémenter ·
> **Dépend de** : — (aucune dépendance technique ; réalisable dès que l'identité du plugin est fixée, donc
> à tout moment avant la publication)

## Objectif

SmartClim est installé sous son identité propre, et non sous celle du template dont il est issu. Le fichier
`plugin_info/smartclim_icon.png` **existe** dans le dépôt mais n'est, à ce stade, qu'une copie renommée de
l'icône générique du template : le plugin n'a **pas encore** d'identité visuelle. Cette UC livre une icône
propre à SmartClim, conforme aux exigences de format du market Jeedom, qui le rend reconnaissable dans la
liste des plugins — et fait disparaître visuellement toute trace du template.

⚠️ **Arbitrage de l'utilisateur, 2026-09-13** : l'icône est **dérivée du logo AUX** (fichier source
`logo-aux-Blk--YM1.png` fourni par l'utilisateur), et non d'un visuel neutre comme le prévoyait la version
initiale de cette UC. Motif : l'installation est **locale et privée**, sans diffusion publique. Le risque
de contrefaçon de marque qui motivait l'interdiction d'origine n'est pas nul — il est **assumé et borné à
cet usage**. Corollaire non négociable, reporté en « Hors périmètre » : toute publication du plugin sur le
market Jeedom public (UC04 de ce domaine) exige de **repasser à une icône neutre** avant soumission, le
market étant une diffusion publique où cet arbitrage ne tient plus.

## Comportement attendu

- L'icône reprend le **logo AUX** fourni par l'utilisateur, retravaillé au format attendu par Jeedom — elle
  ne reprend ni le visuel du template, ni celui d'un autre plugin.
- Elle reste **lisible en petite taille** : elle est affichée en vignette dans la liste des plugins Jeedom
  et dans le market, jamais en grand format à l'usage courant.
- ⚠️ SmartClim reste **multimarque** au niveau fonctionnel (AUX, Broadlink, AC Freedom et tout appareil
  compatible avec cet écosystème, `.memory/brief.md`) : le choix d'un logo de constructeur pour l'icône
  **ne restreint en rien** le périmètre de prise en charge, qui reste le protocole joignable et jamais une
  liste de références commerciales. L'icône est une identité visuelle d'installation privée, pas une
  déclaration de compatibilité.
- Le moyen de production du visuel est un choix d'implémentation, mais la **source est imposée ici** : le
  fichier `logo-aux-Blk--YM1.png` déposé à la racine du dépôt par l'utilisateur. Sa **provenance** (logo de
  la marque AUX, réutilisé sur arbitrage explicite de l'utilisateur pour un usage local) doit rester
  traçable — cohérent avec la logique de crédits de licences déjà posée par l'UC02 de ce domaine.
- ⚠️ Le fichier source est un logo **large et horizontal** sur fond blanc, comportant du **texte**
  (« AUX » et « AIR CONDITIONER ») : le passer tel quel dans un cadre presque carré le rendrait illisible
  en vignette et violerait l'exigence « aucun texte ». Le retravail (recadrage sur le monogramme, mise à
  l'échelle, fond, arrondis, transparence) est donc la **substance** de cette UC, pas une conversion de
  format.
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
- [ ] **AC6** — Le sujet de l'icône est le **monogramme AUX** issu du fichier source fourni, reconnaissable
      en vignette. La mention secondaire « AIR CONDITIONER » du logo d'origine **n'y figure pas** : elle
      tombe sous l'exigence AC3 (« aucun texte ») et serait de toute façon illisible à cette taille.
- [ ] **AC7** — La provenance de l'icône est documentée et retrouvable : fichier source, marque d'origine,
      arbitrage de l'utilisateur et restriction d'usage (local, hors market public). La section
      « Crédits » de l'UC02 de ce domaine en est le porteur ; tant qu'elle n'existe pas, la trace vit dans
      la spec technique de cette UC.
- [ ] **AC8** — Le fichier source `logo-aux-Blk--YM1.png` n'est **pas laissé à la racine du dépôt** en fin
      d'UC : la racine du plugin n'a pas de `.htaccess`, un fichier qui y séjourne est téléchargeable sans
      authentification sur une installation Jeedom.

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
- Le traitement chromatique du monogramme : le logo source est **bleu foncé sur blanc**, alors que l'icône
  Jeedom attend un **fond coloré** avec transparence autour du sujet (AC2). Inverser le contraste (fond
  bleu de marque, monogramme clair) ou garder le bleu sur un fond clair ne se tranche qu'au rendu réel,
  par comparaison avec les icônes des plugins officiels (cf. point précédent).

## Hors périmètre

- Les icônes des **commandes** (widgets `core/template/{dashboard,mobile}/cmd.*.html`) et le visuel de la
  tuile de dashboard → `post-mvp/06-ergonomie-jeedom`.
- La publication du plugin sur le market elle-même (soumission, modération) → UC04 de ce domaine.
  ⚠️ **Report explicite** : cette UC04 devra **remplacer l'icône par un visuel neutre** avant toute
  soumission publique — l'arbitrage consigné dans l'objectif ci-dessus ne vaut que pour une installation
  privée.
- La rédaction de la section « Crédits » et la vérification de compatibilité des licences des éléments
  tiers réutilisés → UC02 de ce domaine ; cette UC exige seulement que la provenance de l'icône y soit
  traçable si pertinent.
