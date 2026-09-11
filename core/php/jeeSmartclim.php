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

/*
* RAPPEL du démon SmartClim (UC02 du domaine post-mvp/05-temps-reel-et-demon) —
* patron officiel plugins/<id>/core/php/jee<Id>.php (jeeBlea.php, jeeBroadlink.php,
* jeeMqtt2.php). Gardé par jeedom::apiAccess(), PAS par isConnect() : ce point
* d'entrée n'inclut pas authentification, aucune session n'est ouverte, donc pas de
* session_write_close() ici (contrairement à core/ajax/smartclim.ajax.php).
*
* Le 401 (là où certains plugins renvoient 200 avec un texte) est DÉLIBÉRÉ :
* jeedom_com.test() traite tout code != 200 comme fatal, donc une clé fausse ou un
* .htaccess mal réglé se voit AU DÉMARRAGE, dans le journal du démon, au lieu de
* dégénérer en pont muet (spec technique UC02 § 5.3).
*/

require_once __DIR__ . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/../class/smartclim.class.php';

if (!jeedom::apiAccess(init('apikey'), 'smartclim')) {
  http_response_code(401);
  die();
}

if (init('test') != '') {
  echo 'OK';
  die();
}

$corps = json_decode((string) file_get_contents('php://input'), true);
if (is_array($corps) && isset($corps['pont']['pong'])) {
  smartclimDemon::enregistrerPong($corps['pont']['pong']);
  log::add('smartclim', 'info', 'Pont : pong reçu du démon');
}

/*
* UC03 du domaine post-mvp/05-temps-reel-et-demon (§ 4.2 de sa spec technique) :
* TROIS branches if INDÉPENDANTES ci-dessous — JAMAIS elseif. Le thread de battement
* (60 s) et le thread de push du démon écrivent tous deux dans self._changes, fusionnés
* par merge_dict() avant le POST du cycle de 0,5 s (resources/smartclimd/jeedom/
* jeedom.py) : un même corps HTTP peut donc légitimement porter à la fois
* auxcloud.relais ET auxcloud.push.<endpointId>. Un elseif en perdrait un SANS AUCUNE
* TRACE, violation ponctuelle de l'AC2.
*/
if (is_array($corps) && isset($corps['auxcloud']['push']) && is_array($corps['auxcloud']['push'])) {
  smartclim::appliquerPushAuxCloud($corps['auxcloud']['push']);
}

if (is_array($corps) && isset($corps['auxcloud']['relais'])) {
  smartclimDemon::enregistrerBattement($corps['auxcloud']['relais']);
}

if (is_array($corps) && isset($corps['pont']['demarre'])) {
  smartclim::invaliderSyncRelais();
}

/*
* === SONDE AUXLINK (UC04 post-mvp/05) — début ===
* 5e bloc if INDÉPENDANT, comme les quatre précédents — un même corps HTTP peut
* légitimement porter plusieurs clés de premier niveau (§ 4.2 de la spec technique
* de l'UC03, doctrine reconduite ici) : un elseif en perdrait une SANS AUCUNE TRACE.
*/
if (is_array($corps) && isset($corps['auxlink']['sonde'])) {
  smartclimDemon::enregistrerSondeAuxlink($corps['auxlink']['sonde']);
}
// === SONDE AUXLINK (UC04 post-mvp/05) — fin ===
