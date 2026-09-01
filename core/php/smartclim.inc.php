<?php
/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

require_once __DIR__  . '/../../../../core/php/core.inc.php';

/*
* Chargement des classes ANNEXES du plugin — OBLIGATOIRE, ce n'est pas une précaution.
*
* L'autoloader du core (jeedomAutoload(), core/php/core.inc.php) ne sait charger qu'UN
* SEUL fichier de classe par plugin : plugins/<id>/core/class/<id>.class.php. Son code :
*     $classname = str_replace(array('Real', 'Cmd'), '', $_classname);
*     $plugin_active = config::byKey('active', $classname, null);      // null ici
*     if ((... null ...) && strpos($classname, '_') !== false) { ... } // sans '_' : ignore
*     if ($plugin_active == 1) { include_file('core', $classname, 'class', $classname); }
* -> pour "smartclimAuxHomeApi" (aucun '_'), la branche de repli n'est jamais prise,
* $plugin_active reste null, et l'autoloader NE FAIT RIEN — sans erreur, sans log, sans
* warning. La classe est simplement declaree introuvable au premier usage.
* Panne effectivement observee en recette UC02, sur un Jeedom a jour :
*   « Error : Class 'smartclimAuxHomeApi' not found (smartclim.class.php:76) »
*   « Error : Class 'smartclimException' not found (smartclim.class.php:131) »
* Et meme AVEC un '_' dans le nom, l'autoloader n'inclurait que <id>.class.php : un
* fichier <Classe>.class.php separe n'est JAMAIS charge tout seul. Le str_replace('Cmd')
* du core en est la preuve — smartclimCmd est trouvee parce qu'elle vit DANS
* smartclim.class.php, pas dans un fichier portant son nom.
*
* D'ou la regle du plugin : toute classe annexe s'ajoute ICI, et ce fichier est inclus en
* tete de core/class/smartclim.class.php — le seul fichier que l'autoloader charge. Les
* classes annexes sont donc disponibles des que `smartclim` ou `smartclimCmd` est resolue,
* c'est-a-dire depuis TOUS les points d'entree (AJAX, crons, pages desktop, install.php).
* Les classes a venir (smartclimTransport, smartclimAuxCloudApi) viennent s'ajouter a
* cette liste.
*
* ⚠️ Ni `php -l` ni la CI ne detectent l'oubli : la panne n'existe qu'au runtime, et
* seulement sur le chemin de code qui touche la classe manquante.
*
* Ordre : smartclimException d'abord (aucune dependance), puis les briques qui la levent.
*/
require_once __DIR__ . '/../class/smartclimException.class.php';
require_once __DIR__ . '/../class/smartclimCapabilities.class.php';
require_once __DIR__ . '/../class/smartclimFrame.class.php';
require_once __DIR__ . '/../class/smartclimAuxHomeApi.class.php';
require_once __DIR__ . '/../class/smartclimBroadlinkLan.class.php';
require_once __DIR__ . '/../class/smartclimDiagnostic.class.php';
