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

/* * ***************************Includes********************************* */
require_once __DIR__ . '/../../../../core/php/core.inc.php';

/**
 * Brique de transport "AUX Home" (cloud eu-smthome-api.aux-global.com).
 *
 * Conformément à CLAUDE.md (« les noms de champs d'API… restent confinés dans la
 * brique du transport »), c'est ici et nulle part ailleurs que vit la connaissance de
 * protocole liée au pays. UC01 n'en a besoin que pour déduire un code pays ISO-3 par
 * défaut ; UC02 enrichira cette même classe (getPubkey(), login/pwd, en-tête "country",
 * correspondance auxhome_email -> champ "account").
 */
class smartclimAuxHomeApi {
  /*     * *************************Attributs****************************** */

  // Table de correspondance fuseau horaire IANA -> code pays ISO-3166 alpha-3.
  // Couverture Europe uniquement (cf. .memory/specs/MVP/01-configuration-plugin-tech.md § 1.3).
  // Ne pas étendre hors Europe sans code ISO-3 confirmé : un pays faux mais plausible
  // provoquerait un échec de login au message trompeur côté cloud AUX Home.
  private static $_fuseauVersPays = array(
    'Europe/Paris' => 'FRA',
    'Europe/Brussels' => 'BEL',
    'Europe/Luxembourg' => 'LUX',
    'Europe/Zurich' => 'CHE',
    'Europe/Vaduz' => 'LIE',
    'Europe/Amsterdam' => 'NLD',
    'Europe/Berlin' => 'DEU',
    'Europe/Busingen' => 'DEU',
    'Europe/Vienna' => 'AUT',
    'Europe/Madrid' => 'ESP',
    'Atlantic/Canary' => 'ESP',
    'Africa/Ceuta' => 'ESP',
    'Europe/Lisbon' => 'PRT',
    'Atlantic/Madeira' => 'PRT',
    'Atlantic/Azores' => 'PRT',
    'Europe/Rome' => 'ITA',
    'Europe/Vatican' => 'VAT',
    'Europe/San_Marino' => 'SMR',
    'Europe/Malta' => 'MLT',
    'Europe/London' => 'GBR',
    'Europe/Belfast' => 'GBR',
    'Europe/Dublin' => 'IRL',
    'Europe/Copenhagen' => 'DNK',
    'Europe/Oslo' => 'NOR',
    'Europe/Stockholm' => 'SWE',
    'Europe/Helsinki' => 'FIN',
    'Europe/Mariehamn' => 'FIN',
    'Europe/Tallinn' => 'EST',
    'Europe/Riga' => 'LVA',
    'Europe/Vilnius' => 'LTU',
    'Europe/Warsaw' => 'POL',
    'Europe/Prague' => 'CZE',
    'Europe/Bratislava' => 'SVK',
    'Europe/Budapest' => 'HUN',
    'Europe/Ljubljana' => 'SVN',
    'Europe/Zagreb' => 'HRV',
    'Europe/Sarajevo' => 'BIH',
    'Europe/Belgrade' => 'SRB',
    'Europe/Podgorica' => 'MNE',
    'Europe/Skopje' => 'MKD',
    'Europe/Tirane' => 'ALB',
    'Europe/Athens' => 'GRC',
    'Europe/Bucharest' => 'ROU',
    'Europe/Sofia' => 'BGR',
    'Europe/Chisinau' => 'MDA',
    'Europe/Tiraspol' => 'MDA',
    'Europe/Kyiv' => 'UKR',
    'Europe/Kiev' => 'UKR', // alias historique de Europe/Kyiv
    'Europe/Simferopol' => 'UKR',
    'Europe/Uzhgorod' => 'UKR',
    'Europe/Zaporozhye' => 'UKR',
    'Europe/Minsk' => 'BLR',
    'Europe/Moscow' => 'RUS',
    'Europe/Kaliningrad' => 'RUS',
    'Europe/Volgograd' => 'RUS',
    'Europe/Samara' => 'RUS',
    'Europe/Saratov' => 'RUS',
    'Europe/Astrakhan' => 'RUS',
    'Europe/Ulyanovsk' => 'RUS',
    'Europe/Kirov' => 'RUS',
    'Europe/Istanbul' => 'TUR',
    'Europe/Nicosia' => 'CYP',
    'Asia/Nicosia' => 'CYP',
    'Asia/Famagusta' => 'CYP',
    'Atlantic/Reykjavik' => 'ISL',
    'Europe/Andorra' => 'AND',
    'Europe/Monaco' => 'MCO',
    'Europe/Gibraltar' => 'GIB',
    'Atlantic/Faroe' => 'FRO',
  );

  /*     * ***********************Methode static*************************** */

  /**
   * Déduit le code pays ISO-3 par défaut à partir du fuseau horaire configuré dans
   * Jeedom (repli sur le fuseau horaire PHP si Jeedom n'en a pas). Aucune tentative de
   * deviner un pays hors de la table Europe : un champ vide est plus honnête qu'un pays
   * faux mais plausible, qui provoquerait un échec de login trompeur.
   *
   * @return string Code pays ISO-3166 alpha-3 en majuscules, ou '' si indéductible.
   */
  public static function paysParDefaut() {
    $fuseau = config::byKey('timezone');
    if ($fuseau == '') {
      $fuseau = date_default_timezone_get();
    }
    if (isset(self::$_fuseauVersPays[$fuseau])) {
      return self::$_fuseauVersPays[$fuseau];
    }
    return '';
  }
}
