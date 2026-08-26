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

/**
 * Table de correspondance UNIQUE entre les codes propriétaires d'un transport et les
 * concepts génériques du plugin (CLAUDE.md § Modèle de données : "table de données
 * unique, jamais dupliquée ni codée en switch"). Aucune E/S, aucun config::, aucun
 * eqLogic : uniquement des tableaux et des accesseurs de lecture.
 *
 * Sémantique des colonnes de tables(), par valeur générique :
 * - "intent" : valeur du champ envoyé en ÉCRITURE (air_con_func, wind_speed).
 *   NON consommée par UC04 (aucune méthode de ce fichier ne l'expose) — elle existe
 *   pour qu'UC06 dispose de la même table sans en redéfinir une deuxième.
 * - "fil" : valeur LUE dans la trame HVAC (status.control, octet décalé de 5 bits).
 *   null = aucune correspondance en LECTURE : la valeur n'entre alors JAMAIS dans un
 *   profil de capacités. C'est l'unique mécanisme garantissant AC5 (UC04) — aucun
 *   drapeau supplémentaire n'est nécessaire.
 * - "intent_confirme" : marqueur de RECETTE pour UC06 (jamais lu par UC04). false =
 *   code d'écriture issu d'une source unique et contestée (cf. § "À confirmer" de la
 *   spec fonctionnelle UC04) ; true = confirmé par recoupement de sources concordantes.
 *
 * Non couvert ICI, délibérément (emplacement prévu, pas de code mort) :
 * - oscillations : aucune lecture par axe possible depuis la trame HVAC connue à ce
 *   jour (cf. .memory/analyse/smartclim-modele-abstrait-capacites.md § 4.3) ;
 * - fonctions de confort (afficheur, veille, éco, santé…) : post-mvp/04 ;
 * - transports BROADLINK_LAN et AUX_CLOUD_LEGACY : post-mvp/01 et post-mvp/03 ;
 * - échelles de température PAR TRANSPORT : UC06.
 */
class smartclimCapabilities {
  /*     * *************************Attributs****************************** */

  const TRANSPORT_AUX_HOME = 'AUX_HOME';

  const CONCEPT_ONLINE = 'online';
  const CONCEPT_POWER = 'power';
  const CONCEPT_MODE = 'mode';
  const CONCEPT_TARGET_TEMP = 'target_temp';
  const CONCEPT_AMBIENT_TEMP = 'ambient_temp';
  const CONCEPT_FAN_SPEED = 'fan_speed';

  const MODE_AUTO = 'AUTO';
  const MODE_COOL = 'COOL';
  const MODE_DRY = 'DRY';
  const MODE_HEAT = 'HEAT';
  const MODE_FAN = 'FAN';

  const VITESSE_AUTO = 'AUTO';
  const VITESSE_SILENT = 'SILENT';
  const VITESSE_LOW = 'LOW';
  const VITESSE_MEDIUM_LOW = 'MEDIUM_LOW';
  const VITESSE_MEDIUM = 'MEDIUM';
  const VITESSE_MEDIUM_HIGH = 'MEDIUM_HIGH';
  const VITESSE_HIGH = 'HIGH';
  const VITESSE_TURBO = 'TURBO';

  // Bornes de température PAR DÉFAUT du transport (AC2 de la spec fonctionnelle UC04).
  const TEMP_MIN_DEFAUT = 16;
  const TEMP_MAX_DEFAUT = 32;
  const TEMP_PAS_DEFAUT = 0.5;

  // Enveloppe des bornes PERSONNALISÉES admissibles par équipement (cf. smartclim::preSave()).
  const TEMP_ENVELOPPE_MIN = 5;
  const TEMP_ENVELOPPE_MAX = 35;

  /*     * ***********************Methode static*************************** */

  /**
   * LA table de correspondance : TRANSPORT -> concept -> valeur générique -> colonnes
   * ('intent', 'fil', 'intent_confirme', 'libelle'). Libellés en chaînes LITTÉRALES
   * dans __() — jamais __($variable) : l'extraction i18n est un scan statique (cf.
   * CLAUDE.md § Internationalisation).
   *
   * ⚠️ "Silencieux", "Moyen-faible" et "Moyen-fort" sont volontairement définis alors
   * que ces vitesses n'entrent pas dans le profil au MVP ('fil' => null) : l'extraction
   * i18n est statique, donc les clés seront déjà traduites le jour où une confirmation
   * en recette ne demandera qu'un passage de 'fil' => null à une valeur.
   *
   * @return array
   */
  private static function tables() {
    return array(
      self::TRANSPORT_AUX_HOME => array(
        self::CONCEPT_MODE => array(
          self::MODE_AUTO => array('intent' => 0, 'fil' => 0, 'intent_confirme' => true, 'libelle' => __('Automatique', __FILE__)),
          self::MODE_COOL => array('intent' => 1, 'fil' => 1, 'intent_confirme' => true, 'libelle' => __('Refroidissement', __FILE__)),
          self::MODE_DRY => array('intent' => 2, 'fil' => 2, 'intent_confirme' => true, 'libelle' => __('Déshumidification', __FILE__)),
          self::MODE_HEAT => array('intent' => 4, 'fil' => 4, 'intent_confirme' => true, 'libelle' => __('Chauffage', __FILE__)),
          self::MODE_FAN => array('intent' => 6, 'fil' => 6, 'intent_confirme' => true, 'libelle' => __('Ventilation', __FILE__)),
        ),
        self::CONCEPT_FAN_SPEED => array(
          self::VITESSE_AUTO => array('intent' => 4, 'fil' => 5, 'intent_confirme' => false, 'libelle' => __('Automatique', __FILE__)),
          self::VITESSE_SILENT => array('intent' => 3, 'fil' => null, 'intent_confirme' => false, 'libelle' => __('Silencieux', __FILE__)),
          self::VITESSE_LOW => array('intent' => 0, 'fil' => 3, 'intent_confirme' => false, 'libelle' => __('Faible', __FILE__)),
          self::VITESSE_MEDIUM_LOW => array('intent' => 6, 'fil' => null, 'intent_confirme' => false, 'libelle' => __('Moyen-faible', __FILE__)),
          self::VITESSE_MEDIUM => array('intent' => 1, 'fil' => 2, 'intent_confirme' => false, 'libelle' => __('Moyen', __FILE__)),
          self::VITESSE_MEDIUM_HIGH => array('intent' => 7, 'fil' => null, 'intent_confirme' => false, 'libelle' => __('Moyen-fort', __FILE__)),
          self::VITESSE_HIGH => array('intent' => 2, 'fil' => 1, 'intent_confirme' => false, 'libelle' => __('Fort', __FILE__)),
          self::VITESSE_TURBO => array('intent' => 5, 'fil' => 4, 'intent_confirme' => true, 'libelle' => __('Turbo', __FILE__)),
        ),
      ),
    );
  }

  /**
   * Valeurs génériques dont la correspondance en LECTURE ('fil') n'est pas null, dans
   * l'ordre de déclaration de la table (= ordre CANONIQUE d'affichage/de fusion). Seul
   * mécanisme d'AC5 : une valeur sans 'fil' n'apparaît JAMAIS ici.
   *
   * @param string $_transport
   * @param string $_concept
   * @return array<int,string>
   */
  public static function valeursLisibles($_transport, $_concept) {
    $table = self::tables();
    if (!isset($table[$_transport][$_concept]) || !is_array($table[$_transport][$_concept])) {
      return array();
    }
    $valeurs = array();
    foreach ($table[$_transport][$_concept] as $valeurGenerique => $colonnes) {
      if (is_array($colonnes) && array_key_exists('fil', $colonnes) && $colonnes['fil'] !== null) {
        $valeurs[] = $valeurGenerique;
      }
    }
    return $valeurs;
  }

  /**
   * Code à envoyer en écriture pour une valeur générique donnée (colonne 'intent'),
   * ou null si aucune correspondance — jamais de repli silencieux. NON consommée par
   * UC04 : existe pour qu'UC05/UC06 n'aillent jamais lire le tableau brut de tables().
   *
   * @param string $_transport
   * @param string $_concept
   * @param string $_valeurGenerique
   * @return int|null
   */
  public static function versTransport($_transport, $_concept, $_valeurGenerique) {
    $table = self::tables();
    if (!isset($table[$_transport][$_concept][$_valeurGenerique]['intent'])) {
      return null;
    }
    return $table[$_transport][$_concept][$_valeurGenerique]['intent'];
  }

  /**
   * Valeur générique correspondant à un code lu dans la trame (colonne 'fil'), ou null
   * si aucune correspondance en lecture — jamais de repli silencieux.
   *
   * @param string $_transport
   * @param string $_concept
   * @param int $_codeFil
   * @return string|null
   */
  public static function depuisTransport($_transport, $_concept, $_codeFil) {
    $table = self::tables();
    if (!isset($table[$_transport][$_concept]) || !is_array($table[$_transport][$_concept])) {
      return null;
    }
    foreach ($table[$_transport][$_concept] as $valeurGenerique => $colonnes) {
      if (is_array($colonnes) && array_key_exists('fil', $colonnes) && $colonnes['fil'] === $_codeFil) {
        return $valeurGenerique;
      }
    }
    return null;
  }

  /**
   * Libellé français déjà traduit d'une valeur générique de mode ou de vitesse. Chaîne
   * vide si le concept ou la valeur est inconnu(e) — jamais de code brut affiché (AC4).
   * Cherche dans TOUS les transports connus de la table (un même concept/valeur porte
   * le même libellé quel que soit le transport, cf. contrat transmis à UC06 dans la
   * spec technique § Impact i18n).
   *
   * @param string $_concept
   * @param string $_valeurGenerique
   * @return string
   */
  public static function libelle($_concept, $_valeurGenerique) {
    foreach (self::tables() as $tableTransport) {
      if (isset($tableTransport[$_concept][$_valeurGenerique]['libelle'])) {
        return $tableTransport[$_concept][$_valeurGenerique]['libelle'];
      }
    }
    return '';
  }

  /**
   * Libellé français d'un concept générique (AC1/AC4). Chaîne vide si inconnu.
   *
   * @param string $_concept
   * @return string
   */
  public static function libelleConcept($_concept) {
    $libelles = array(
      self::CONCEPT_ONLINE => __('Disponibilité', __FILE__),
      self::CONCEPT_POWER => __('Marche/Arrêt', __FILE__),
      self::CONCEPT_MODE => __('Mode', __FILE__),
      self::CONCEPT_TARGET_TEMP => __('Consigne de température', __FILE__),
      self::CONCEPT_AMBIENT_TEMP => __('Température ambiante', __FILE__),
      self::CONCEPT_FAN_SPEED => __('Vitesse de ventilation', __FILE__),
    );
    return isset($libelles[$_concept]) ? $libelles[$_concept] : '';
  }

  /**
   * Nom de la commande info d'un concept, destiné à cmd::setName() (spec technique UC05
   * § Signatures). Aucun des caractères supprimés par cleanComponanteName() du core
   * (`& # ] [ % \ / ' " *`) : « Marche/Arrêt » y deviendrait « MarcheArrêt » — d'où
   * « Marche-Arrêt » ici, alors que libelleConcept() (destiné à une PHRASE, pas à un nom
   * de composant) garde « Marche/Arrêt ». Chaîne vide si le concept est inconnu :
   * l'appelant (creerCommandesInfo()) ne crée alors AUCUNE commande.
   *
   * @param string $_concept
   * @return string
   */
  public static function libelleCommande($_concept) {
    $libelles = array(
      self::CONCEPT_ONLINE => __('Disponibilité', __FILE__),
      self::CONCEPT_POWER => __('Marche-Arrêt', __FILE__),
      self::CONCEPT_MODE => __('Mode', __FILE__),
      self::CONCEPT_TARGET_TEMP => __('Consigne', __FILE__),
      self::CONCEPT_AMBIENT_TEMP => __('Température ambiante', __FILE__),
      self::CONCEPT_FAN_SPEED => __('Vitesse de ventilation', __FILE__),
    );
    return isset($libelles[$_concept]) ? $libelles[$_concept] : '';
  }

  /**
   * Libellé du transport (nom de marque, SANS __() — cf. spec technique § Impact i18n).
   * Chaîne vide si inconnu.
   *
   * @param string $_transport
   * @return string
   */
  public static function libelleTransport($_transport) {
    if ($_transport === self::TRANSPORT_AUX_HOME) {
      return 'AUX Home';
    }
    return '';
  }

  /**
   * Ordre CANONIQUE d'affichage/de fusion des concepts génériques (indépendant d'un
   * transport particulier) : identique aux futurs logicalId de commandes info (UC05).
   *
   * @return array<int,string>
   */
  public static function conceptsConnus() {
    return array(
      self::CONCEPT_ONLINE,
      self::CONCEPT_POWER,
      self::CONCEPT_MODE,
      self::CONCEPT_TARGET_TEMP,
      self::CONCEPT_AMBIENT_TEMP,
      self::CONCEPT_FAN_SPEED,
    );
  }

  /**
   * Bornes de température PAR DÉFAUT du transport (AC2). Toujours les mêmes constantes,
   * peu importe l'appareil : ce n'est pas une détection, c'est le défaut documenté.
   *
   * @return array{min:int,max:int,pas:float}
   */
  public static function bornesParDefaut() {
    return array(
      'min' => self::TEMP_MIN_DEFAUT,
      'max' => self::TEMP_MAX_DEFAUT,
      'pas' => self::TEMP_PAS_DEFAUT,
    );
  }

  /**
   * Enveloppe des bornes PERSONNALISÉES admissibles (validation par smartclim::preSave()
   * et desktop/js/smartclim.js::saveEqLogic()).
   *
   * @return array{min:int,max:int,pasAutorises:array<int,string>}
   */
  public static function enveloppeBornes() {
    return array(
      'min' => self::TEMP_ENVELOPPE_MIN,
      'max' => self::TEMP_ENVELOPPE_MAX,
      'pasAutorises' => array('0.5', '1'),
    );
  }
}
