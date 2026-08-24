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
include_file('core', 'authentification', 'php');
if (!isConnect('admin')) {
  include_file('desktop', '404', 'php');
  die();
}

// Amorçage paresseux du pays AUX Home depuis le fuseau horaire de Jeedom : couvre le cas
// d'un plugin posé à la main ou cloné en git, où smartclim_install() n'est pas garanti
// d'avoir été exécuté (ceinture + bretelles avec plugin_info/install.php).
smartclim::amorcerPaysAuxHome();
?>
<form class="form-horizontal">
  <fieldset>
    <legend>{{Compte AUX Home}}</legend>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Adresse e-mail}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Adresse e-mail du compte AUX Home (celle de l'application mobile)}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input type="email" class="configKey form-control" data-l1key="auxhome_email"/>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Mot de passe}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Mot de passe du compte AUX Home. Il est stocké chiffré et n'est jamais journalisé.}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input type="password" class="configKey form-control" data-l1key="auxhome_password" autocomplete="new-password"/>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Pays}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Code pays ISO à 3 lettres (FRA, BEL, CHE, DEU…). Pré-rempli depuis le fuseau horaire de Jeedom ; hors Europe, saisissez-le manuellement. Un pays erroné fait échouer la connexion au cloud AUX Home.}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input type="text" maxlength="3" style="text-transform:uppercase" class="configKey form-control" data-l1key="auxhome_country"/>
        <span class="help-block">{{Sans pays valide — ni saisi, ni déduit du fuseau horaire de Jeedom — aucune connexion au cloud n'est tentée.}}</span>
      </div>
    </div>
  </fieldset>
  <fieldset>
    <legend>{{Rafraîchissement}}</legend>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Intervalle de rafraîchissement (minutes)}}
        <!-- bornes et défaut doivent rester identiques à smartclim::INTERVALLE_MIN/INTERVALLE_MAX/INTERVALLE_DEFAUT (core/class/smartclim.class.php) -->
        <sup><i class="fas fa-question-circle tooltips" title="{{Entre 1 et 1440 minutes, 5 minutes par défaut.}}"></i></sup>
      </label>
      <div class="col-md-4">
        <!-- min/max doivent rester identiques à smartclim::INTERVALLE_MIN/INTERVALLE_MAX (core/class/smartclim.class.php) -->
        <input type="number" min="1" max="1440" step="1" class="configKey form-control" data-l1key="refresh_interval"/>
        <span class="help-block">{{La température ambiante remontée par AUX Home se rafraîchit lentement (jusqu'à environ 30 minutes) ; réduire cet intervalle n'accélère pas la donnée.}}</span>
      </div>
    </div>
  </fieldset>
</form>
