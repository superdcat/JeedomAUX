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

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

// Fonction exécutée automatiquement après l'installation du plugin
function smartclim_install() {
  // Volontairement vide : le pays du compte AUX Home a un défaut constant
  // (smartclim::PAYS_DEFAUT, repris dans core/config/smartclim.config.ini), plus aucune
  // valeur n'est donc à amorcer en base à l'installation. Le core retombe seul sur ce
  // défaut tant que l'utilisateur n'a rien choisi dans la liste déroulante.
}

// Fonction exécutée automatiquement après la mise à jour du plugin
function smartclim_update() {
  // Volontairement vide, même motif que smartclim_install().
}

// Fonction exécutée automatiquement après la suppression du plugin
function smartclim_remove() {
  // Volontairement vide : le core appelle cette fonction à chaque DÉSACTIVATION du
  // plugin (plugin::setIsEnable(0) -> callInstallFunction('remove')), pas seulement à
  // la désinstallation. Y purger les clés détruirait les identifiants cloud lors d'un
  // simple cycle désactiver/réactiver. L'effacement volontaire passera par un bouton
  // dédié en UC02.
}
