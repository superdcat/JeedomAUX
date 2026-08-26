---
name: execcmd-action-effet-de-bord
description: Dans Jeedom, execCmd() lit sans effet de bord une commande info mais EXÉCUTE un ordre réel sur une commande action — filtrer getType() === 'info' avant toute lecture de valeur.
metadata:
  type: project
---

Dans Jeedom, `$cmd->execCmd()` est **asymétrique** : sur une commande **info** elle sort la valeur du
cache sans effet de bord ; sur une commande **action** elle dispatche vers `cmd::execute()` et **envoie un
vrai ordre à l'appareil**. Toute lecture de valeurs qui parcourt un ensemble de commandes non trié
(`getCmd(null, null)`) doit donc filtrer `getType() === 'info'` **avant** d'appeler `execCmd()`.

**Why:** identifié en conception au cycle UC08 du MVP de SmartClim (2026-08-27). Sans ce filtre, la
méthode d'affichage de l'état de connexion aurait **allumé le climatiseur à l'ouverture de la page de
configuration** — symptôme incompréhensible côté utilisateur et invisible en relecture, puisque rien ne
distingue visuellement l'appel fautif de l'appel légitime. Le risque est aggravé quand les `logicalId`
sont appariés (info `power` / action `on`) : un index par `logicalId` sans filtre laisse l'action écraser
l'info.

**How to apply:** traiter ce filtre comme une garde **fonctionnelle**, à vérifier explicitement en review
de code, jamais comme un raffinement « simplifiable ». Analyse détaillée et exemple de code :
`.memory/analyse/jeedom-widgets-commandes.md` § 8.7 (référencé au § 0 de
`.memory/analyse/INDEX.md`). Voir aussi [[feedback-verifier-la-premisse-d-un-finding]] : ici la prémisse
« lire une valeur est inoffensif » est précisément celle qui est fausse.
