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
  *
  * ⚠️ INCIDENT DU 2026-08-26, à ne pas reproduire : cette liste seule est INSUFFISANTE, et
  * un rapport publié l'a démontré. 'mac' était bien masquée, mais la MÊME adresse MAC
  * ressortait en clair sous 'thirdDid' (forme 1C:D1:...) et à l'intérieur de 'did'
  * (concaténée à un préfixe) ; 'passcode' — un secret d'appairage — n'était dans aucune
  * liste. Une liste de noms de clés ne peut pas suivre un backend tiers qui republie le
  * même identifiant sous d'autres noms et d'autres formats. D'où la SECONDE passe,
  * masquerParRessemblance(), qui ne raisonne plus sur les noms mais sur les valeurs. Les
  * deux passes sont nécessaires : ne jamais en retirer une.
  */
  private static $clesSensibles = array(
    'deviceid', 'did', 'thirddid', 'mac', 'cookie', 'token', 'accesstoken', 'refreshtoken',
    'uid', 'sn', 'sncode', 'familyid', 'homeid', 'userid', 'username', 'nickname',
    'account', 'email', 'phone', 'mobile', 'password', 'passcode', 'passwd', 'pwd',
    'ssid', 'bssid', 'ip', 'ipaddress', 'latitude', 'longitude', 'address', 'secret',
    'devicesecret', 'privatekey', 'licence', 'license',
  );

  // Longueur minimale d'une valeur sensible pour servir de motif à la seconde passe :
  // en dessous, un identifiant court provoquerait des remplacements parasites partout
  // dans le rapport.
  const RESSEMBLANCE_MIN = 8;

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
      // SECONDE PASSE, sur la même charge utile : rattrape les valeurs qui échappent aux
      // noms de clés (même identifiant republié sous un autre nom, ou dans un autre
      // format). Elle tourne APRÈS la première, qui vient d'alimenter la table des motifs.
      if ($_masquer) {
        $donnees = self::masquerParRessemblance($donnees, $correspondances);
      }
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
        $sortie[$cle] = self::jeton((string) $sousValeur, $_correspondances);
        continue;
      }
      $sortie[$cle] = self::masquerValeur($sousValeur, $_correspondances, $_masquer, $_profondeur + 1);
    }
    return $sortie;
  }

  /**
   * SECONDE PASSE de masquage, par RESSEMBLANCE de valeur et non par nom de clé. Deux
   * règles, toutes deux nées de l'incident du 2026-08-26 (cf. $clesSensibles) :
   *
   * 1. Toute chaîne qui CONTIENT une valeur déjà masquée, comparaison faite sur une forme
   *    normalisée (minuscules, séparateurs retirés). C'est ce qui attrape une MAC republiée
   *    concaténée à un préfixe, ou ponctuée autrement.
   * 2. Toute chaîne de FORME MAC PONCTUÉE (xx:xx:xx:xx:xx:xx ou avec des tirets), quel que
   *    soit le nom de sa clé — un identifiant matériel n'a aucune raison de sortir en clair.
   *
   * ⚠️ La règle 2 ne couvre VOLONTAIREMENT pas la forme hexadécimale nue de 12 caractères :
   * elle collisionnerait avec les trames HVAC (status.control / status.running), qui sont
   * de l'hexadécimal nu et constituent la donnée la plus utile du rapport. Une MAC nue est
   * déjà attrapée par la règle 1, puisque le champ 'mac' l'a enregistrée à la passe
   * précédente. Ne pas « durcir » en anchorant du 12-hex nu : cela viderait le rapport.
   *
   * @param mixed $_valeur
   * @param array $_correspondances Table valeur -> jeton, alimentée par la 1re passe et enrichie ici.
   * @param int $_profondeur
   * @return mixed
   */
  private static function masquerParRessemblance($_valeur, array &$_correspondances, $_profondeur = 0) {
    if ($_profondeur > self::PROFONDEUR_MAX) {
      return $_valeur;
    }
    if (is_array($_valeur)) {
      $sortie = array();
      foreach ($_valeur as $cle => $sousValeur) {
        $sortie[$cle] = self::masquerParRessemblance($sousValeur, $_correspondances, $_profondeur + 1);
      }
      return $sortie;
    }
    if (!is_string($_valeur) || $_valeur === '' || strpos($_valeur, 'masque:') === 0) {
      return $_valeur;
    }

    if (preg_match('/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}\z/', $_valeur) === 1) {
      return self::jeton($_valeur, $_correspondances);
    }

    $normalisee = strtolower(preg_replace('/[^0-9A-Za-z]/', '', $_valeur));
    if ($normalisee === '') {
      return $_valeur;
    }
    foreach (array_keys($_correspondances) as $sensible) {
      $motif = strtolower(preg_replace('/[^0-9A-Za-z]/', '', (string) $sensible));
      if (strlen($motif) < self::RESSEMBLANCE_MIN) {
        continue;
      }
      if (strpos($normalisee, $motif) !== false) {
        return self::jeton($_valeur, $_correspondances);
      }
    }
    return $_valeur;
  }

  /**
   * Jeton stable d'une valeur, enregistré dans la table des correspondances : deux
   * occurrences de la même valeur portent le même jeton, donc les recoupements d'un
   * rapport restent lisibles sans que le rapport désigne le matériel.
   *
   * @param string $_valeur
   * @param array $_correspondances
   * @return string
   */
  private static function jeton($_valeur, array &$_correspondances) {
    $brute = (string) $_valeur;
    if (!isset($_correspondances[$brute])) {
      $_correspondances[$brute] = 'masque:' . substr(sha1($brute), 0, 6);
    }
    return $_correspondances[$brute];
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
   * Mise en forme PURE (UC01 du domaine post-mvp/04-fonctions-avancees, § 5.7 de sa
   * spec technique) d'une mesure AVANT/APRÈS de trame HVAC produite par
   * smartclim::sonderIntentAuxHome() : une ligne par octet (index, hexadécimal,
   * binaire, marqueur de différence), puis un bloc « bits documentés » (concept,
   * octet, bit, avant -> après) construit depuis smartclimFrame::champsBinaires(),
   * puis les deux états génériques déjà décodés.
   *
   * ⚠️ AUCUN offset en dur ici (lit champsBinaires()), AUCUNE E/S, AUCUN masquage :
   * une trame HVAC n'est PAS un secret, et CLAUDE.md interdit explicitement de
   * masquer du 12-hex nu (ce sont les trames, la donnée la plus utile d'un rapport).
   * Affiche TOUS les octets, pas seulement ceux qu'on suppose porteurs : si le bit
   * qui bascule n'est pas celui attendu, c'est la SEULE façon de le voir (leçon de la
   * température ambiante, cf. .memory/analyse/smartclim-transport-aux-home.md § 6.2).
   *
   * @param string $_avant Trame de contrôle hexadécimale AVANT écriture.
   * @param string $_apres Trame de contrôle hexadécimale APRÈS écriture.
   * @param array $_etatAvant État générique décodé AVANT (smartclimFrame::decoderEtat()).
   * @param array $_etatApres État générique décodé APRÈS.
   * @return string
   */
  public static function texteTrameHvac($_avant, $_apres, array $_etatAvant, array $_etatApres) {
    $avant = is_string($_avant) ? $_avant : '';
    $apres = is_string($_apres) ? $_apres : '';
    $octetsAvant = (int) (strlen($avant) / 2);
    $octetsApres = (int) (strlen($apres) / 2);
    $nombreOctets = max($octetsAvant, $octetsApres);

    $lignes = array();
    $lignes[] = '== Trame HVAC : avant / apres ==';
    $lignes[] = sprintf('%-4s %-6s %-10s %-6s %-10s %s', 'oct', 'avant', 'avant(bin)', 'apres', 'apres(bin)', '');
    for ($i = 0; $i < $nombreOctets; $i++) {
      $octetAvant = ($i < $octetsAvant) ? hexdec(substr($avant, $i * 2, 2)) : null;
      $octetApres = ($i < $octetsApres) ? hexdec(substr($apres, $i * 2, 2)) : null;
      $marqueur = ($octetAvant !== $octetApres) ? '<< DIFFERENT' : '';
      $lignes[] = sprintf(
        '%-4d %-6s %-10s %-6s %-10s %s',
        $i,
        ($octetAvant === null) ? '-' : sprintf('0x%02x', $octetAvant),
        ($octetAvant === null) ? '-' : sprintf('%08b', $octetAvant),
        ($octetApres === null) ? '-' : sprintf('0x%02x', $octetApres),
        ($octetApres === null) ? '-' : sprintf('%08b', $octetApres),
        $marqueur
      );
    }

    $lignes[] = '';
    $lignes[] = '-- Bits documentes (concept : octet.bit avant -> apres) --';
    foreach (smartclimFrame::champsBinaires() as $concept => $champ) {
      $octetAvant = ($champ['octet'] < $octetsAvant) ? hexdec(substr($avant, $champ['octet'] * 2, 2)) : null;
      $octetApres = ($champ['octet'] < $octetsApres) ? hexdec(substr($apres, $champ['octet'] * 2, 2)) : null;
      $bitAvant = ($octetAvant === null) ? '-' : (($octetAvant >> $champ['bit']) & 1);
      $bitApres = ($octetApres === null) ? '-' : (($octetApres >> $champ['bit']) & 1);
      $lignes[] = '  ' . $concept . ' : octet ' . $champ['octet'] . ' bit ' . $champ['bit'] . ' : ' . $bitAvant . ' -> ' . $bitApres;
    }

    $lignes[] = '';
    $lignes[] = '-- Etat generique decode --';
    $lignes[] = 'avant : ' . json_encode($_etatAvant, JSON_UNESCAPED_UNICODE);
    $lignes[] = 'apres : ' . json_encode($_etatApres, JSON_UNESCAPED_UNICODE);

    return implode("\n", $lignes) . "\n";
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
