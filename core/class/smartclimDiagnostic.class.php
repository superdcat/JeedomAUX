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
 * Mise en forme des rapports de SONDE DE DIAGNOSTIC — outillage de reverse engineering,
 * jamais sollicité par le plugin en fonctionnement.
 *
 * Rôle exact : prendre les réponses BRUTES d'un transport (aujourd'hui
 * smartclimAuxHomeApi::sondeDiagnostic()) et en faire un rapport qu'on peut relire, et
 * surtout PARTAGER — donc masqué. Deux appelants, et c'est ce qui justifie une classe
 * plutôt qu'un bout de script : la page admin (core/ajax -> smartclim::sonderDiagnostic())
 * et la ligne de commande (core/php/diagnostic-auxhome.php). Les deux doivent rendre
 * EXACTEMENT le même rapport, sinon un rapport collé dans une discussion ne décrit plus
 * ce que l'autre chemin produit.
 *
 * Ce que la classe NE fait pas : aucun appel réseau (délégué au transport), aucune E/S
 * fichier (l'appelant CLI écrit son fichier), aucun accès eqLogic ou config.
 *
 * Trois sections dans un rapport, du plus court au plus long — c'est délibéré, un
 * rapport de sonde se lit de haut en bas et la réponse est presque toujours dans les
 * deux premières :
 * 1. 'resume'  : quelle route répond quoi (une ligne par route) ;
 * 2. 'pistes'  : les clés dont le NOM évoque une capacité, un modèle ou un type ;
 * 3. 'donnees' : la charge utile complète, masquée.
 */
class smartclimDiagnostic {
  /*     * *************************Attributs****************************** */

  /*
  * Clés dont la VALEUR est masquée (comparaison en minuscules sur le nom de clé exact).
  *
  * Volontairement SANS modelId, productId, deviceType ni alias : ce sont justement les
  * champs susceptibles de porter les capacités — les masquer viderait le rapport de son
  * intérêt. 'alias' est le nom donné par l'utilisateur à la pièce : il aide à reconnaître
  * l'appareil dans le rapport et ne désigne pas le matériel.
  */
  private static $clesSensibles = array(
    'deviceid', 'mac', 'cookie', 'token', 'uid', 'sn', 'sncode', 'familyid', 'homeid',
    'userid', 'username', 'nickname', 'account', 'email', 'phone', 'mobile', 'password',
    'ssid', 'bssid', 'ip', 'ipaddress', 'latitude', 'longitude', 'address', 'secret',
  );

  // Garde-fou de récursion : une charge utile d'origine externe ne dicte pas la
  // profondeur de pile du plugin.
  const PROFONDEUR_MAX = 12;

  /*     * ***********************Methode static*************************** */

  /**
   * Rapport complet de la sonde AUX Home : exécute la sonde puis masque, résume et
   * annote. Le résultat est fait pour être affiché tel quel ET partagé tel quel.
   *
   * @param array<int,string> $_cheminsSupplementaires Chemins ajoutés à la main (CLI uniquement, cf. le transport).
   * @param bool $_masquer false = identifiants EN CLAIR : réservé à la CLI (un rapport
   *        non masqué n'a rien à faire dans une réponse AJAX, donc dans un navigateur,
   *        donc dans un copier-coller).
   * @return array{genere_le:string, transport:string, masquage:bool, routes:array}
   * @throws smartclimException Propagée du transport (session/login en échec).
   */
  public static function rapport(array $_cheminsSupplementaires = array(), $_masquer = true) {
    if (!$_masquer && php_sapi_name() !== 'cli') {
      throw new smartclimException('Rapport de sonde non masque refuse hors ligne de commande', smartclimException::TYPE_INTERNE);
    }

    $resultats = smartclimAuxHomeApi::sondeDiagnostic($_cheminsSupplementaires);

    $rapport = array(
      'genere_le' => date('c'),
      'transport' => smartclimCapabilities::libelleTransport(smartclimCapabilities::TRANSPORT_AUX_HOME),
      'masquage' => (bool) $_masquer,
      'routes' => array(),
    );

    /*
    * Masquage en DEUX temps, et cet ordre compte : les charges utiles d'abord (elles
    * alimentent la table de correspondance valeur -> jeton), les textes ensuite (chemin
    * et message d'erreur portent parfois un deviceId, qui doit alors recevoir le MÊME
    * jeton que dans la charge utile — sans quoi le rapport masquerait d'un côté et
    * publierait de l'autre).
    */
    $correspondances = array();
    foreach ($resultats as $resultat) {
      $donnees = self::masquerValeur($resultat['donnees'], $correspondances, $_masquer);
      $rapport['routes'][] = array(
        'chemin' => $resultat['chemin'],
        'role' => isset($resultat['role']) ? $resultat['role'] : '',
        'http' => $resultat['http'],
        'code' => $resultat['code'],
        'erreur' => $resultat['erreur'],
        'forme' => self::forme($donnees),
        'pistes' => self::pistes($donnees),
        'donnees' => $donnees,
      );
    }
    if ($_masquer) {
      foreach ($rapport['routes'] as $index => $route) {
        $rapport['routes'][$index]['chemin'] = self::masquerTexte($route['chemin'], $correspondances);
        $rapport['routes'][$index]['erreur'] = self::masquerTexte($route['erreur'], $correspondances);
      }
    }
    return $rapport;
  }

  /**
   * Rendu TEXTE du rapport (résumé + pistes), destiné à être lu dans la page admin puis
   * copié-collé. Volontairement sans la charge utile complète : elle part dans le
   * fichier JSON téléchargeable, pas dans un bloc de texte de plusieurs centaines de
   * lignes que personne ne relit.
   *
   * @param array $_rapport Renvoyé par rapport().
   * @return string
   */
  public static function texte(array $_rapport) {
    $lignes = array();
    $lignes[] = '== Sonde AUX Home : ou vivent les capacites d un appareil ==';
    $lignes[] = 'Genere le : ' . (isset($_rapport['genere_le']) ? $_rapport['genere_le'] : '?');
    $lignes[] = 'Masquage des identifiants : ' . (!empty($_rapport['masquage']) ? 'ACTIF' : 'DESACTIVE');
    $routes = isset($_rapport['routes']) && is_array($_rapport['routes']) ? $_rapport['routes'] : array();

    $lignes[] = '';
    $lignes[] = '-- Resume --';
    foreach ($routes as $route) {
      $lignes[] = sprintf(
        '%-58s http=%-4s code=%-6s %s',
        substr($route['chemin'], 0, 58),
        ($route['http'] === 0) ? '-' : $route['http'],
        ($route['code'] === null) ? '-' : $route['code'],
        ($route['erreur'] === '') ? 'OK' : $route['erreur']
      );
    }

    $lignes[] = '';
    $lignes[] = '-- Pistes (cle = valeur) --';
    $aucune = true;
    foreach ($routes as $route) {
      if (empty($route['pistes'])) {
        continue;
      }
      $aucune = false;
      $lignes[] = '';
      $lignes[] = '[' . $route['chemin'] . ']';
      foreach ($route['pistes'] as $cle => $valeur) {
        $lignes[] = '  ' . $cle . ' = ' . (is_scalar($valeur) ? $valeur : json_encode($valeur));
      }
    }
    if ($aucune) {
      $lignes[] = '(aucune cle evoquant une capacite, un modele ou un type)';
    }
    return implode("\n", $lignes) . "\n";
  }

  /**
   * Masque récursivement les valeurs des clés sensibles. Le jeton est un préfixe stable
   * dérivé de la valeur : deux occurrences du même identifiant portent le même jeton,
   * donc les recoupements d'un rapport restent lisibles (« c'est le même appareil ici et
   * là ») sans que le rapport désigne le matériel.
   *
   * @param mixed $_valeur
   * @param array $_correspondances Table valeur -> jeton, enrichie au passage.
   * @param bool $_masquer
   * @param int $_profondeur
   * @return mixed
   */
  private static function masquerValeur($_valeur, array &$_correspondances, $_masquer, $_profondeur = 0) {
    if ($_profondeur > self::PROFONDEUR_MAX) {
      return '(profondeur maximale atteinte)';
    }
    if (!is_array($_valeur)) {
      return $_valeur;
    }
    $sortie = array();
    foreach ($_valeur as $cle => $sousValeur) {
      $sensible = $_masquer
        && is_string($cle)
        && in_array(strtolower($cle), self::$clesSensibles, true)
        && is_scalar($sousValeur)
        && (string) $sousValeur !== '';
      if ($sensible) {
        $brute = (string) $sousValeur;
        if (!isset($_correspondances[$brute])) {
          $_correspondances[$brute] = 'masque:' . substr(sha1($brute), 0, 6);
        }
        $sortie[$cle] = $_correspondances[$brute];
        continue;
      }
      $sortie[$cle] = self::masquerValeur($sousValeur, $_correspondances, $_masquer, $_profondeur + 1);
    }
    return $sortie;
  }

  /**
   * Remplace dans un texte (chemin sondé, message d'erreur) toute valeur déjà masquée
   * ailleurs par son jeton — forme brute ET forme encodée pour URL, puisque le chemin
   * porte un rawurlencode(). Seuil de 4 caractères : en dessous, un identifiant est trop
   * court pour ne pas provoquer de remplacements parasites dans le reste du texte.
   *
   * @param string $_texte
   * @param array $_correspondances
   * @return string
   */
  private static function masquerTexte($_texte, array $_correspondances) {
    $texte = (string) $_texte;
    foreach ($_correspondances as $valeur => $jeton) {
      $brute = (string) $valeur;
      if (strlen($brute) < 4) {
        continue;
      }
      $texte = str_replace($brute, $jeton, $texte);
      $texte = str_replace(rawurlencode($brute), $jeton, $texte);
    }
    return $texte;
  }

  /**
   * Les couples clé/valeur dont le NOM évoque une capacité, un modèle ou un type. C'est
   * la section à relire en premier : elle dit si la réponse porte, oui ou non, de quoi
   * restreindre les modes appareil par appareil.
   *
   * Heuristique volontairement LARGE (mieux vaut trois lignes de bruit qu'un champ
   * manqué) et appliquée aux seuls NOMS de clés : la valeur, elle, est rendue telle
   * quelle, sans interprétation.
   *
   * @param mixed $_valeur
   * @param string $_chemin Chemin de clés courant, en notation pointée.
   * @param int $_profondeur
   * @return array<string,mixed>
   */
  private static function pistes($_valeur, $_chemin = '', $_profondeur = 0) {
    $pistes = array();
    if ($_profondeur > 8 || !is_array($_valeur)) {
      return $pistes;
    }
    foreach ($_valeur as $cle => $sousValeur) {
      $chemin = ($_chemin === '') ? (string) $cle : $_chemin . '.' . $cle;
      if (is_string($cle) && preg_match('/(mode|func|capab|support|feature|type|model|product|heat|cool|dry|fan|kind|series|spec|abilit|option|enable|flag)/i', $cle) === 1) {
        if (is_bool($sousValeur)) {
          $pistes[$chemin] = $sousValeur ? 'true' : 'false';
        } elseif (is_scalar($sousValeur) || $sousValeur === null) {
          $pistes[$chemin] = $sousValeur;
        } else {
          $pistes[$chemin] = '(' . gettype($sousValeur) . ', ' . count((array) $sousValeur) . ' entrees)';
        }
      }
      $pistes = array_merge($pistes, self::pistes($sousValeur, $chemin, $_profondeur + 1));
    }
    return $pistes;
  }

  /**
   * Forme d'une réponse, résumée sur 2 niveaux : de quoi voir si une route candidate a
   * renvoyé une vraie charge utile ou une enveloppe vide, sans imprimer 300 lignes.
   *
   * @param mixed $_valeur
   * @param int $_profondeur
   * @return mixed
   */
  private static function forme($_valeur, $_profondeur = 0) {
    if (!is_array($_valeur)) {
      return gettype($_valeur);
    }
    if ($_profondeur >= 2) {
      return 'array(' . count($_valeur) . ')';
    }
    $forme = array();
    foreach ($_valeur as $cle => $sousValeur) {
      $forme[$cle] = self::forme($sousValeur, $_profondeur + 1);
    }
    return $forme;
  }
}
