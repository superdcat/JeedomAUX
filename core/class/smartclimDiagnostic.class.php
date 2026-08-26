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

  // Plafond, en caractères, d'UN extrait rendu dans le rapport texte. Le rapport texte
  // est fait pour être copié-collé : un sous-arbre de 80 ko le rendrait inutilisable.
  // Le rapport JSON complet, lui, n'est pas tronqué.
  const EXTRAIT_MAX = 6000;

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
        'extraits' => self::extraits($donnees),
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

    /*
    * Extraits : les sous-arbres de cheminsExtraits(), rendus EN ENTIER. C'est la section
    * qui permet de decoder, la ou 'pistes' ne fait que reperer. Tronquee par extrait
    * (EXTRAIT_MAX), et la troncature est DITE — un extrait coupe en silence se lit comme
    * un sous-arbre complet, et on en tirerait une correspondance fausse.
    */
    $lignes[] = '';
    $lignes[] = '-- Extraits cibles (sous-arbres complets) --';
    $aucun = true;
    foreach ($routes as $route) {
      if (empty($route['extraits'])) {
        continue;
      }
      $aucun = false;
      foreach ($route['extraits'] as $chemin => $sousArbre) {
        $rendu = json_encode($sousArbre, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $rendu = ($rendu === false) ? '(non encodable en JSON)' : $rendu;
        $lignes[] = '';
        $lignes[] = '[' . $route['chemin'] . '] ' . $chemin;
        if (strlen($rendu) > self::EXTRAIT_MAX) {
          $lignes[] = substr($rendu, 0, self::EXTRAIT_MAX);
          $lignes[] = '... TRONQUE a ' . self::EXTRAIT_MAX . ' caracteres sur ' . strlen($rendu) . ' (rapport JSON complet non tronque)';
        } else {
          $lignes[] = $rendu;
        }
      }
    }
    if ($aucun) {
      $lignes[] = '(aucun des sous-arbres recherches n est present)';
    }

    return implode("\n", $lignes) . "\n";
  }

  /**
   * Sous-arbres rendus EN ENTIER dans le rapport, en notation pointée ; l'étoile
   * correspond à n'importe quelle clé de ce niveau (une ligne d'appareil, typiquement).
   *
   * Pourquoi une liste explicite : la section « pistes » ne donne que le NOM et la
   * TAILLE d'un sous-arbre, ce qui suffit à repérer un champ intéressant mais jamais à
   * le décoder. Sonde du 2026-08-26 : 'feature' a livré 'mode' = "0,1,2,3,4" sur une
   * unité froid-seul, valeurs qui ne peuvent PAS être les codes air_con_func
   * (0/1/2/4/6) — donc des index, dont la table de référence est
   * configContent.air_con_func.specs. Sans ces deux sous-arbres côte à côte dans le même
   * rapport, la correspondance ne peut qu'être devinée, et une correspondance de mode
   * devinée produit exactement le défaut que smartclimCapabilities existe pour empêcher.
   *
   * Cette liste se modifie au fil des questions ouvertes de
   * .memory/analyse/smartclim-transport-aux-home.md § 9 — ce n'est pas un contrat figé.
   *
   * @return array<int,string>
   */
  private static function cheminsExtraits() {
    return array(
      // /app/user_device : le profil de capacités PAR APPAREIL (§ 3.2 de l'analyse).
      'data.*.feature',
      // deviceMutex : les tables de référence dans lesquelles feature indexe.
      'data.configContent.air_con_func',
      'data.configContent.wind_speed',
      'data.configContent.temperature',
      'data.configContent.on_off',
    );
  }

  /**
   * Résout les chemins de cheminsExtraits() dans une charge utile et renvoie les
   * sous-arbres trouvés, indexés par leur chemin RÉSOLU (étoile remplacée par la clé
   * réelle). Un chemin absent est simplement omis : une charge utile de route candidate
   * n'a aucune raison de porter les mêmes branches que la route de référence.
   *
   * @param mixed $_donnees
   * @return array<string,mixed>
   */
  private static function extraits($_donnees) {
    $extraits = array();
    foreach (self::cheminsExtraits() as $chemin) {
      foreach (self::resoudreChemin($_donnees, explode('.', $chemin)) as $cheminResolu => $sousArbre) {
        /*
        * JSON IMBRIQUE DANS UNE CHAINE : le backend AUX Home livre 'feature' comme une
        * chaine contenant elle-meme du JSON, pas comme un objet. Rendue telle quelle,
        * elle arrive dans le rapport avec tous ses guillemets echappes, illisible la ou
        * c'est justement le sous-arbre le plus important. On decode donc UNE fois, et le
        * chemin le dit.
        */
        if (is_string($sousArbre) && $sousArbre !== '') {
          $decode = json_decode($sousArbre, true);
          if (is_array($decode)) {
            $extraits[$cheminResolu . ' (JSON imbrique, decode)'] = $decode;
            continue;
          }
        }
        $extraits[$cheminResolu] = $sousArbre;
      }
    }
    return $extraits;
  }

  /**
   * Descend une suite de segments dans un tableau ; le segment '*' se développe en
   * TOUTES les clés de ce niveau. Renvoie chemin résolu => valeur.
   *
   * @param mixed $_valeur
   * @param array<int,string> $_segments
   * @param string $_prefixe
   * @return array<string,mixed>
   */
  private static function resoudreChemin($_valeur, array $_segments, $_prefixe = '') {
    if (empty($_segments)) {
      return ($_prefixe === '') ? array() : array($_prefixe => $_valeur);
    }
    if (!is_array($_valeur)) {
      return array();
    }
    $segment = array_shift($_segments);
    $trouves = array();
    if ($segment === '*') {
      foreach ($_valeur as $cle => $sousValeur) {
        $prefixe = ($_prefixe === '') ? (string) $cle : $_prefixe . '.' . $cle;
        $trouves = array_merge($trouves, self::resoudreChemin($sousValeur, $_segments, $prefixe));
      }
      return $trouves;
    }
    if (!array_key_exists($segment, $_valeur)) {
      return array();
    }
    $prefixe = ($_prefixe === '') ? $segment : $_prefixe . '.' . $segment;
    return self::resoudreChemin($_valeur[$segment], $_segments, $prefixe);
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
