---
name: feedback-reprise-impl-methode-appelee-non-definie
description: Reprendre une implémentation coupée en vol exige de chercher les méthodes appelées mais jamais définies — ni verif-plugin.py ni php -l ne les voient.
metadata:
  type: feedback
---

Quand tu reprends une UC dont la phase `impl` a été **coupée en vol**, ne conclus jamais « l'impl est
faite » sur la base de `verif-plugin.py` seul. Vérifie d'abord, méthode par méthode, que **tout membre
listé dans la spec technique existe réellement**, et surtout qu'aucune méthode **appelée** dans le code
neuf n'est **jamais définie**.

**Why** : un développeur interrompu s'arrête au milieu d'une section de la spec. Vécu le 2026-08-28 sur
l'UC01 du domaine `post-mvp/01` (run `run-20260827-1008`) : `smartclim::scannerReseauLocal()` appelait
`$eqLogic->adresseLan()`, méthode que la coupure avait laissée non écrite. `verif-plugin.py` rendait
**vert** sur tout sauf l'i18n (attendu à ce stade), et `php -l` aurait aussi passé : un « Call to
undefined method » n'est visible **qu'au runtime**, sur le seul chemin de code concerné — exactement le
même angle mort que le « Class not found » d'autoload documenté dans `CLAUDE.md`. Dans le même état,
tout le fichier `desktop/js/smartclim.js` prévu par la spec était vierge alors que `desktop/php/*.php`
posait déjà les ids DOM à alimenter.

**How to apply** : à la reprise, en plus des indices de `CLAUDE.md` (commit existant ? arbre sale ?
clés i18n manquantes ?), fais **deux passes bon marché** avant de déclarer `impl` franchie —
1. `grep` chaque membre du tableau de contrats de la spec technique (§ « Server Actions / API ») dans
   les fichiers concernés, et note les absents ;
2. compare la **liste de fichiers** du tableau « Architecture — fichiers » de la spec technique à
   `git status` : un fichier prévu mais absent du diff est le signe le plus net d'une coupure (le JS
   et les fichiers de fin de chaîne sont les premiers sacrifiés).

Puis délègue la **complétion** — pas la réécriture — à un agent neuf, en lui donnant l'inventaire de
ce qui existe et de ce qui manque, avec la consigne explicite de ne pas retoucher le code déjà écrit.
Voir aussi [[project-execcmd-action-effet-de-bord]].
