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
 *   ✅ Les 8 vitesses sont passées à true le 2026-08-26 sur une source qui n'est plus un
 *   recoupement mais le BACKEND LUI-MÊME : la sonde de diagnostic a rendu
 *   getConfig?id=deviceMutex -> configContent.wind_speed.specs, liste ORDONNÉE dont
 *   l'index EST le code d'écriture, avec les libellés constructeur (0 低档 faible,
 *   1 中档 moyen, 2 高档 fort, 3 静音 silencieux, 4 自动 auto, 5 强力 turbo, 6 中低
 *   moyen-faible, 7 中高 moyen-fort). La colonne 'intent' ci-dessous était donc juste sur
 *   les 8 valeurs, et la table CN concurrente (ha-aux-a-plus) est écartée pour ce backend
 *   (cf. .memory/analyse/smartclim-transport-aux-home.md § 4.3, contradiction CLOSE).
 *   ⚠️ Cela ne dit RIEN de la colonne 'fil' : le codage LU dans la trame HVAC est une
 *   numérotation DIFFÉRENTE, qui reste non confirmée là où elle vaut null. Ne pas
 *   « compléter » 'fil' depuis cette table d'écriture — c'est exactement la confusion
 *   que ce fichier existe pour empêcher.
 *
 * Non couvert ICI, délibérément (emplacement prévu, pas de code mort) :
 * - oscillations : aucune lecture par axe possible depuis la trame HVAC connue à ce
 *   jour (cf. .memory/analyse/smartclim-modele-abstrait-capacites.md § 4.3) ;
 * - transports BROADLINK_LAN et AUX_CLOUD_LEGACY : post-mvp/01 et post-mvp/03 ;
 * - échelles de température PAR TRANSPORT : UC06.
 *
 * ⚠️ Depuis l'UC01 du domaine post-mvp/04-fonctions-avancees, ce fichier porte AUSSI les
 * fonctions de confort (afficheur, sommeil, ioniseur, nettoyage, anti-moisissure) via
 * fonctionsConfort() ci-dessous. Cette table introduit un SECOND marqueur de recette,
 * nommé 'confirme' — à ne pas confondre avec 'intent_confirme' ci-dessus, qui reste,
 * lui, purement DÉCLARATIF (jamais lu). 'confirme' est EFFECTIVEMENT lu et GOUVERNE
 * l'exposition (profil, commandes info, commandes action). Voir le docblock de
 * fonctionsConfort() pour le détail des TROIS familles de marqueurs du plugin.
 */
class smartclimCapabilities {
  /*     * *************************Attributs****************************** */

  const TRANSPORT_AUX_HOME = 'AUX_HOME';
  // Identifiant du transport LAN (UC01 du domaine post-mvp/01-transport-broadlink-lan).
  // Depuis UC02 de ce domaine, tables() porte une entrée BROADLINK_LAN (§ 5.4 de sa spec
  // technique) : mêmes octets de la même trame HVAC que AUX_HOME (démontré § 3.3), colonne
  // 'fil' donc IDENTIQUE ; colonne 'intent' laissée à null (l'écriture est le sujet d'UC03
  // de ce domaine — rien ne l'établit tant qu'aucun ordre n'a été émis).
  const TRANSPORT_BROADLINK_LAN = 'BROADLINK_LAN';

  const CONCEPT_ONLINE = 'online';
  const CONCEPT_POWER = 'power';
  const CONCEPT_MODE = 'mode';
  const CONCEPT_TARGET_TEMP = 'target_temp';
  const CONCEPT_AMBIENT_TEMP = 'ambient_temp';
  const CONCEPT_FAN_SPEED = 'fan_speed';

  // UC01 du domaine post-mvp/04-fonctions-avancees (§ 5.1 de sa spec technique) :
  // concepts booléens de confort. Ces logicalId sont STABLES par contrat (ne changent
  // jamais lors d'une bascule de transport), comme tous les CONCEPT_* ci-dessus.
  const CONCEPT_DISPLAY = 'display';
  const CONCEPT_SLEEP = 'sleep';
  const CONCEPT_HEALTH = 'health';
  const CONCEPT_CLEAN = 'clean';
  const CONCEPT_MILDEW = 'mildew';
  // ⚠️ Pas de CONCEPT_ECO ni de CONCEPT_ULTRA_SILENCE (décision D-UC01-Q1, § 1.1 de la
  // spec technique) : aucun bit de LECTURE connu pour ces deux fonctions, ce qui rendrait
  // AC3/AC4/AC6 impossibles à honorer. Cette absence est le MÉCANISME qui les rend
  // inatteignables par construction (un execCmd() forgé ne peut viser une constante qui
  // n'existe pas), pas un oubli — ne pas les ajouter « pour compléter la table ».

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

  // Échelle de température en ÉCRITURE du transport AUX Home (UC06, § 3.2/5.1 de la
  // spec technique) : intent "temperature" = degré Celsius entier (référence EU,
  // vérifiée en direct sur getPubkey dès UC02) — DISTINCTE du pas de LECTURE (0,5°C,
  // TEMP_PAS_DEFAUT ci-dessus), qui reste inchangé. Ne pas confondre les deux : seul
  // le pas d'ÉCRITURE vaut 1,0°C. Si la recette révèle un backend en ×10 (risque R2),
  // ces deux littéraux suffisent à corriger.
  const FACTEUR_TEMP_AUX_HOME = 1;
  const PAS_ECRITURE_AUX_HOME = 1.0;

  // Échelle de température en ÉCRITURE du transport Broadlink LAN (UC03 du domaine
  // post-mvp/01-transport-broadlink-lan, § 5.4 de sa spec technique) : facteur 1 (même
  // unité, degrés Celsius), pas d'écriture 0,5 °C — la trame code la consigne sur 5 bits
  // de degrés entiers PLUS un bit de demi-degré (smartclimFrame::encoderConsigne()),
  // donc la même granularité qu'en LECTURE (TEMP_PAS_DEFAUT), contrairement au cloud
  // AUX Home dont l'écriture est en degrés entiers (PAS_ECRITURE_AUX_HOME = 1.0).
  const FACTEUR_TEMP_BROADLINK_LAN = 1;
  const PAS_ECRITURE_BROADLINK_LAN = 0.5;

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
          self::VITESSE_AUTO => array('intent' => 4, 'fil' => 5, 'intent_confirme' => true, 'libelle' => __('Automatique', __FILE__)),
          self::VITESSE_SILENT => array('intent' => 3, 'fil' => null, 'intent_confirme' => true, 'libelle' => __('Silencieux', __FILE__)),
          self::VITESSE_LOW => array('intent' => 0, 'fil' => 3, 'intent_confirme' => true, 'libelle' => __('Faible', __FILE__)),
          self::VITESSE_MEDIUM_LOW => array('intent' => 6, 'fil' => null, 'intent_confirme' => true, 'libelle' => __('Moyen-faible', __FILE__)),
          self::VITESSE_MEDIUM => array('intent' => 1, 'fil' => 2, 'intent_confirme' => true, 'libelle' => __('Moyen', __FILE__)),
          self::VITESSE_MEDIUM_HIGH => array('intent' => 7, 'fil' => null, 'intent_confirme' => true, 'libelle' => __('Moyen-fort', __FILE__)),
          self::VITESSE_HIGH => array('intent' => 2, 'fil' => 1, 'intent_confirme' => true, 'libelle' => __('Fort', __FILE__)),
          self::VITESSE_TURBO => array('intent' => 5, 'fil' => 4, 'intent_confirme' => true, 'libelle' => __('Turbo', __FILE__)),
        ),
      ),
      // UC02 du domaine post-mvp/01-transport-broadlink-lan (§ 5.4 de sa spec technique) :
      // mêmes octets de la MÊME trame HVAC que AUX_HOME (démontré § 3.3 — décalage de 2
      // octets entre espace "réponse LAN" et espace "charge HVAC nue", vérifié EXACT sur
      // les 6 concepts déjà en production), donc colonne 'fil' IDENTIQUE. Colonne 'libelle'
      // délibérément ABSENTE ici : libelle()/libelleCommande() parcourent TOUTES les
      // tables et trouvent déjà le libellé français via l'entrée AUX_HOME ci-dessus (même
      // concept/valeur générique = même libellé, quel que soit le transport) — la dupliquer
      // ici créerait un second __() pour un texte identique, sans utilité.
      // ⚠️ Colonne 'intent' remplie depuis l'UC03 de ce domaine (§ 5.4 de sa spec
      // technique) AVEC LA VALEUR DE 'fil' : ce n'est PAS une supposition, c'est une
      // conséquence du protocole — mode et vitesse s'écrivent dans le MÊME octet, au
      // MÊME décalage que celui où ils se lisent (smartclimFrame::champsEcriture()), et
      // l'écriture procède par RECOPIE de la trame lue. Si les deux numérotations
      // différaient, chaque commande réécrirait les champs non modifiés avec des codes
      // faux. 'intent_confirme' reste false : établi par COHÉRENCE INTERNE, jamais
      // mesuré sur matériel — à basculer à true au premier ordre réussi en recette.
      self::TRANSPORT_BROADLINK_LAN => array(
        self::CONCEPT_MODE => array(
          self::MODE_AUTO => array('intent' => 0, 'fil' => 0, 'intent_confirme' => false),
          self::MODE_COOL => array('intent' => 1, 'fil' => 1, 'intent_confirme' => false),
          self::MODE_DRY => array('intent' => 2, 'fil' => 2, 'intent_confirme' => false),
          self::MODE_HEAT => array('intent' => 4, 'fil' => 4, 'intent_confirme' => false),
          self::MODE_FAN => array('intent' => 6, 'fil' => 6, 'intent_confirme' => false),
        ),
        self::CONCEPT_FAN_SPEED => array(
          self::VITESSE_AUTO => array('intent' => 5, 'fil' => 5, 'intent_confirme' => false),
          self::VITESSE_SILENT => array('intent' => null, 'fil' => null, 'intent_confirme' => false),
          self::VITESSE_LOW => array('intent' => 3, 'fil' => 3, 'intent_confirme' => false),
          self::VITESSE_MEDIUM_LOW => array('intent' => null, 'fil' => null, 'intent_confirme' => false),
          self::VITESSE_MEDIUM => array('intent' => 2, 'fil' => 2, 'intent_confirme' => false),
          self::VITESSE_MEDIUM_HIGH => array('intent' => null, 'fil' => null, 'intent_confirme' => false),
          self::VITESSE_HIGH => array('intent' => 1, 'fil' => 1, 'intent_confirme' => false),
          self::VITESSE_TURBO => array('intent' => 4, 'fil' => 4, 'intent_confirme' => false),
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
   * LA table des fonctions de confort (UC01 du domaine post-mvp/04-fonctions-avancees,
   * § 5.1 de sa spec technique). Une fonction n'y figure QUE si elle a un bit de LECTURE
   * identifié dans smartclimFrame::champsBinaires() — sans quoi AC3/AC4/AC6 seraient
   * impossibles à honorer (cf. décision D-UC01-Q1 pour Éco et Ultra-silence, exclues à la
   * racine : aucune constante CONCEPT_* ne les représente).
   *
   * ⚠️ TROIS familles de marqueurs de recette dans ce plugin, à ne JAMAIS confondre :
   * 1. 'intent_confirme' (table tables() ci-dessus) : DÉCLARATIF, jamais lu par du code,
   *    simple note de traçabilité sur la solidité d'un code d'écriture.
   * 2. 'fil' => null (table tables() ci-dessus) : un FAIT DE PROTOCOLE — aucune
   *    correspondance de lecture n'EXISTE (pas seulement « pas encore vérifiée »).
   * 3. 'confirme' ICI : un ÉTAT DE RECETTE actif — une correspondance est SUPPOSÉE
   *    exister (bit identifié par recoupement de sources), mais n'a pas encore été
   *    validée sur un appareil réel (§ 11 de la spec technique). C'est le PREMIER
   *    marqueur de ce plugin qui soit à la fois lu ET décisionnel : 'confirme' => false
   *    signifie concrètement « aucune commande, aucune entrée de profil » — c'est
   *    conceptsConfortLivres() ci-dessous qui applique ce filtre, seul point d'édition
   *    pour faire passer une fonction en production après recette (§ 11 : au minimum
   *    l'ordre accepté, le bit qui bascule dans les deux sens, un effet constatable).
   *
   * - 'libelle' : chaîne LITTÉRALE dans __() (scan i18n statique) — sert À LA FOIS de
   *   libellé de concept et de base du nom des deux commandes action (un seul __() par
   *   fonction, jamais deux pour un texte identique).
   * - 'allumer' : l'ordre ON de cette fonction porte-t-il power => 1 ? Dérogation
   *   EXPLICITE à la règle générale de CLAUDE.md (scopée « mode ou consigne ») pour
   *   'clean' et 'mildew' : ce sont des fonctions de l'état ARRÊT côté backend AUX Home
   *   (clean/anti_fungus sont masquées quand on_off=1, et allumer FORCE clean=0) — leur
   *   ordre ON ne doit JAMAIS porter power => 1, sous peine d'éteindre la fonction qu'on
   *   vient d'activer. 'sleep' et 'health' exigent au contraire l'appareil allumé.
   * - 'ordre' : base d'affichage Jeedom (ON = ordre, OFF = ordre + 1).
   *
   * @return array<string, array{libelle:string, confirme:bool, allumer:bool, ordre:int}>
   */
  private static function fonctionsConfort() {
    return array(
      self::CONCEPT_DISPLAY => array('libelle' => __('Afficheur', __FILE__), 'confirme' => false, 'allumer' => false, 'ordre' => 30),
      self::CONCEPT_SLEEP => array('libelle' => __('Mode sommeil', __FILE__), 'confirme' => false, 'allumer' => true, 'ordre' => 32),
      self::CONCEPT_HEALTH => array('libelle' => __('Ioniseur', __FILE__), 'confirme' => false, 'allumer' => true, 'ordre' => 34),
      self::CONCEPT_CLEAN => array('libelle' => __('Nettoyage automatique', __FILE__), 'confirme' => false, 'allumer' => false, 'ordre' => 36),
      self::CONCEPT_MILDEW => array('libelle' => __('Anti-moisissure', __FILE__), 'confirme' => false, 'allumer' => false, 'ordre' => 38),
    );
  }

  /**
   * Les 5 concepts de confort, TOUJOURS (recette ou non) — dans l'ordre de la table.
   *
   * @return array<int,string>
   */
  public static function conceptsConfort() {
    return array_keys(self::fonctionsConfort());
  }

  /**
   * Les concepts de confort dont 'confirme' === true (§ 11 de la spec technique UC01) :
   * UNIQUE point de filtrage de l'UC, consommé par conceptsConnus(), et par
   * smartclimFrame::conceptsLisibles()/decoderEtat(). Éditer UNE ligne dans
   * fonctionsConfort() ci-dessus suffit à faire circuler une fonction validée dans tout
   * le cycle info/action existant.
   *
   * @return array<int,string>
   */
  public static function conceptsConfortLivres() {
    $livres = array();
    foreach (self::fonctionsConfort() as $concept => $colonnes) {
      if (!empty($colonnes['confirme'])) {
        $livres[] = $concept;
      }
    }
    return $livres;
  }

  /**
   * Colonnes de fonctionsConfort() pour un concept de confort donné, ou array() si le
   * concept est inconnu — jamais de repli silencieux. Consommée par smartclim::
   * definitionsCommandesAction() (libellé, 'allumer', 'ordre').
   *
   * @param string $_concept
   * @return array{libelle:string, confirme:bool, allumer:bool, ordre:int}|array
   */
  public static function fonctionConfort($_concept) {
    $fonctions = self::fonctionsConfort();
    return isset($fonctions[$_concept]) ? $fonctions[$_concept] : array();
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
   * ⚠️ Depuis l'UC01 du domaine post-mvp/04-fonctions-avancees : REPLI sur
   * fonctionsConfort()[$_concept]['libelle'] quand le concept n'est pas dans la liste
   * statique ci-dessous (un concept de confort NON livré, 'confirme' => false, n'a de
   * toute façon jamais de commande créée — ce repli ne relâche donc rien).
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
    if (isset($libelles[$_concept])) {
      return $libelles[$_concept];
    }
    $confort = self::fonctionConfort($_concept);
    return isset($confort['libelle']) ? $confort['libelle'] : '';
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
    if (isset($libelles[$_concept])) {
      return $libelles[$_concept];
    }
    // Aucun des libellés de fonctionsConfort() ne porte de caractère supprimé par
    // cleanComponanteName() (`& # ] [ % \ / ' " *`) : pas de transformation nécessaire
    // ici, contrairement à CONCEPT_POWER ci-dessus (cf. § Impact i18n de la spec
    // technique UC01 post-mvp/04 — « Santé / Ioniseur » a d'ailleurs été raccourci en
    // « Ioniseur » pour cette même raison, en amont, dans fonctionsConfort()).
    $confort = self::fonctionConfort($_concept);
    return isset($confort['libelle']) ? $confort['libelle'] : '';
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
    if ($_transport === self::TRANSPORT_BROADLINK_LAN) {
      return 'Broadlink LAN';
    }
    return '';
  }

  /**
   * Ordre CANONIQUE d'affichage/de fusion des concepts génériques (indépendant d'un
   * transport particulier) : identique aux futurs logicalId de commandes info (UC05).
   *
   * ⚠️ Depuis l'UC01 du domaine post-mvp/04-fonctions-avancees : les 6 concepts
   * historiques PUIS conceptsConfortLivres() (dans l'ordre de fonctionsConfort()) — donc
   * RIEN tant qu'aucune fonction de confort n'est 'confirme' => true. C'est cet ordre que
   * consomme la boucle de smartclim::appliquerEtat() (elle itère sur conceptsConnus(),
   * PAS sur les clés de l'état) : étendre cette seule fonction suffit à faire circuler
   * les nouveaux concepts dans le cycle info existant, sans une ligne dans
   * appliquerEtat().
   *
   * @return array<int,string>
   */
  public static function conceptsConnus() {
    return array_merge(
      array(
        self::CONCEPT_ONLINE,
        self::CONCEPT_POWER,
        self::CONCEPT_MODE,
        self::CONCEPT_TARGET_TEMP,
        self::CONCEPT_AMBIENT_TEMP,
        self::CONCEPT_FAN_SPEED,
      ),
      self::conceptsConfortLivres()
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

  /**
   * Échelle de température d'ÉCRITURE d'un transport (UC06, § 5.1 de la spec
   * technique) : facteur d'échelle de l'intent + pas d'écriture. Renvoie array() si le
   * transport est inconnu — jamais de repli silencieux (même contrat que
   * versTransport()/depuisTransport() ci-dessus).
   *
   * @param string $_transport
   * @return array{facteur:int,pas_ecriture:float}|array
   */
  public static function echelleTemperature($_transport) {
    if ($_transport === self::TRANSPORT_AUX_HOME) {
      return array(
        'facteur' => self::FACTEUR_TEMP_AUX_HOME,
        'pas_ecriture' => self::PAS_ECRITURE_AUX_HOME,
      );
    }
    if ($_transport === self::TRANSPORT_BROADLINK_LAN) {
      return array(
        'facteur' => self::FACTEUR_TEMP_BROADLINK_LAN,
        'pas_ecriture' => self::PAS_ECRITURE_BROADLINK_LAN,
      );
    }
    return array();
  }
}
