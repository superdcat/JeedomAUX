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
* SONDE DE DIAGNOSTIC « capacites reelles d'un appareil » — transport AUX Home.
*
* POURQUOI CE FICHIER EXISTE
* Le plugin sait aujourd'hui detecter QUELS CONCEPTS sont lisibles (marche/arret, mode,
* consigne, vitesse, ambiante) parce que cela se deduit de la LONGUEUR des trames HVAC.
* Il ne sait PAS detecter quels MODES cet appareil-la accepte : capacitesAppareil()
* renvoie le catalogue du transport (auto, froid, sec, chaud, ventilation), d'ou un
* « Chauffage » affiche sur une unite qui ne chauffe pas. Le brief l'avait anticipe
* (.memory/brief.md : « Cette table est generique et ne signifie pas que chaque appareil
* supporte toutes ces fonctions »), sans que la source par appareil soit identifiee.
*
* L'application AUX Home, elle, sait le faire : l'information EXISTE cote backend. Ce
* script sonde les routes candidates avec le compte configure dans le plugin, et rend
* leur reponse BRUTE — c'est le seul moyen de trancher sans deviner.
*
* USAGE (sur le Jeedom de test, en SSH) :
*   cd /var/www/html/plugins/smartclim
*   php core/php/diagnostic-auxhome.php
*
*   php core/php/diagnostic-auxhome.php '/app/getConfig?id=autreId' '/app/route/a/tester'
*       -> sonde EN PLUS les chemins passes en argument (utile au 2e passage, une fois
*          qu'un premier rapport a montre un identifiant ou une route a suivre).
*
*   --brut     : n'applique AUCUN masquage (deviceId/mac en clair) — a garder pour soi.
*   --sortie=/chemin/rapport.json : fichier de sortie explicite.
*
* CE QU'IL NE FAIT PAS
* - aucun POST, aucune commande envoyee a un climatiseur : GET seulement ;
* - aucun acces au jeton de session (il reste dans smartclimAuxHomeApi) ;
* - aucune ecriture en base, aucune modification d'equipement.
*
* Le rapport est masque par defaut : les identifiants (deviceId, mac, cookie, uid...)
* sont remplaces par un jeton stable « masque:xxxxxx » — deux occurrences du meme
* identifiant portent le meme jeton, donc les recoupements restent lisibles sans que le
* rapport identifie le materiel. Il est fait pour etre relu et partage tel quel.
*/

if (php_sapi_name() !== 'cli') {
  http_response_code(403);
  die("Ce script est reserve a la ligne de commande.\n");
}

require_once __DIR__ . '/../class/smartclim.class.php';

/* * *************************** Parametres ****************************** */

$cheminsSupplementaires = array();
$masquer = true;
$fichierSortie = sys_get_temp_dir() . '/smartclim-diagnostic-' . date('Ymd-His') . '.json';

foreach (array_slice($argv, 1) as $argument) {
  if ($argument === '--brut') {
    $masquer = false;
  } elseif (strpos($argument, '--sortie=') === 0) {
    $fichierSortie = substr($argument, strlen('--sortie='));
  } elseif (strpos($argument, '--') === 0) {
    die('Option inconnue : ' . $argument . "\n");
  } else {
    $cheminsSupplementaires[] = $argument;
  }
}

/*
* Routes sondees. Deux categories, volontairement etiquetees :
* - « connue »    : route deja utilisee par le plugin, ou deja verifiee en direct (cf.
*                   .memory/analyse/smartclim-transport-aux-home.md § 3) ;
* - « candidate » : HYPOTHESE de nommage. Une 404 ou un corps non-JSON est un resultat
*                   utile, pas un echec du script.
* Les jetons deviceId et productId entre accolades sont remplaces au 2e passage par les
* valeurs lues dans la reponse de user_device (premier appareil de la liste).
*/
$routesReference = array(
  '/app/user_device?getStatus=1' => 'connue : liste des appareils + trames HVAC (source actuelle du plugin)',
);
$routesCandidates = array(
  '/app/getConfig?id=deviceMutex' => 'connue : table GENERIQUE des concepts (verifiee HTTP 200) — y chercher un lien vers un modele/produit',
  '/app/getConfig?id=deviceFunction' => 'candidate : fonctions par type d appareil ?',
  '/app/getConfig?id=deviceType' => 'candidate : types d appareil ?',
  '/app/getConfig?id=product' => 'candidate : catalogue produits ?',
  '/app/getConfig?id=all' => 'candidate : configuration complete ?',
  '/app/device/config?deviceId={deviceId}' => 'candidate : configuration DE CET appareil ?',
  '/app/device/function?deviceId={deviceId}' => 'candidate : fonctions DE CET appareil ?',
  '/app/device/v2/config?deviceId={deviceId}' => 'candidate : variante v2 ?',
  '/app/user_device/config?deviceId={deviceId}' => 'candidate : variante sous user_device ?',
  '/app/product?productId={productId}' => 'candidate : fiche produit ?',
);

/* * *************************** Masquage ******************************** */

/*
* Cles dont la VALEUR est masquee (comparaison en minuscules, sur le nom de cle exact).
* Volontairement SANS modelId, productId, deviceType ni alias : ce sont justement les
* champs susceptibles de porter les capacites, les masquer viderait le rapport de son
* interet. alias est le nom donne par l'utilisateur a la piece — il aide a reconnaitre
* l'appareil dans le rapport et ne designe pas le materiel.
*/
$clesSensibles = array(
  'deviceid', 'mac', 'cookie', 'token', 'uid', 'sn', 'sncode', 'familyid', 'homeid',
  'userid', 'username', 'nickname', 'account', 'email', 'phone', 'mobile', 'password',
  'ssid', 'bssid', 'ip', 'ipaddress', 'latitude', 'longitude', 'address', 'secret',
);

$correspondances = array();

function jetonMasque($_valeur, &$_correspondances) {
  $cle = (string) $_valeur;
  if (!isset($_correspondances[$cle])) {
    $_correspondances[$cle] = 'masque:' . substr(sha1($cle), 0, 6);
  }
  return $_correspondances[$cle];
}

function masquerRecursif($_valeur, array $_clesSensibles, &$_correspondances, $_masquer, $_profondeur = 0) {
  if ($_profondeur > 12) {
    return '(profondeur maximale atteinte)';
  }
  if (!is_array($_valeur)) {
    return $_valeur;
  }
  $sortie = array();
  foreach ($_valeur as $cle => $sousValeur) {
    if ($_masquer && is_string($cle) && in_array(strtolower($cle), $_clesSensibles, true) && is_scalar($sousValeur) && (string) $sousValeur !== '') {
      $sortie[$cle] = jetonMasque($sousValeur, $_correspondances);
      continue;
    }
    $sortie[$cle] = masquerRecursif($sousValeur, $_clesSensibles, $_correspondances, $_masquer, $_profondeur + 1);
  }
  return $sortie;
}

/*
* Remplace, dans un texte (typiquement un chemin sonde contenant un deviceId), toute
* valeur deja masquee ailleurs par son jeton : sans cela le rapport reafficherait en
* clair, dans l'URL, l'identifiant masque dans la charge utile.
*/
function masquerTexte($_texte, array $_correspondances) {
  foreach ($_correspondances as $valeur => $jeton) {
    if ((string) $valeur !== '' && strlen((string) $valeur) >= 4) {
      $_texte = str_replace((string) $valeur, $jeton, $_texte);
      $_texte = str_replace(rawurlencode((string) $valeur), $jeton, $_texte);
    }
  }
  return $_texte;
}

/*
* « Pistes » : les couples cle/valeur dont le NOM evoque une capacite, un modele ou un
* type. C'est la partie courte du rapport, celle a relire en premier — et celle qui dira
* si la reponse porte, oui ou non, de quoi restreindre les modes par appareil.
*/
function pistesCapacites($_valeur, $_chemin = '', $_profondeur = 0) {
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
    $pistes = array_merge($pistes, pistesCapacites($sousValeur, $chemin, $_profondeur + 1));
  }
  return $pistes;
}

/*
* Forme d'une reponse, resumee sur 2 niveaux : de quoi voir si une route candidate a
* renvoye une vraie charge utile ou une enveloppe vide, sans imprimer 300 lignes.
*/
function formeReponse($_valeur, $_profondeur = 0) {
  if (!is_array($_valeur)) {
    return gettype($_valeur);
  }
  if ($_profondeur >= 2) {
    return 'array(' . count($_valeur) . ')';
  }
  $forme = array();
  foreach ($_valeur as $cle => $sousValeur) {
    $forme[$cle] = formeReponse($sousValeur, $_profondeur + 1);
  }
  return $forme;
}

/* * *************************** Execution ******************************* */

echo "== Sonde AUX Home — capacites par appareil ==\n";
echo 'Masquage des identifiants : ' . ($masquer ? 'ACTIF' : 'DESACTIVE (--brut)') . "\n\n";

if (!smartclim::compteConfigure()) {
  die("Compte AUX Home non configure (email / mot de passe / pays) : rien a sonder.\n");
}

$rapport = array(
  'genere_le' => date('c'),
  'hote' => 'AUX Home',
  'masquage' => $masquer,
  'routes' => array(),
);

/*
* Passage 1 — la route de reference, dont on extrait les identifiants qui parametrent
* les routes candidates. Volontairement separee : une route candidate parametree par
* deviceId n'a aucun sens tant qu'on n'a pas lu la liste des appareils.
*/
try {
  $reference = smartclimAuxHomeApi::diagnostic(array_keys($routesReference));
} catch (Throwable $e) {
  die('Echec de la sonde de reference : ' . $e->getMessage() . "\n");
}

$identifiants = array('deviceId' => '', 'productId' => '');
foreach ($reference as $resultat) {
  if (!is_array($resultat['donnees']) || !isset($resultat['donnees']['data']) || !is_array($resultat['donnees']['data'])) {
    continue;
  }
  foreach ($resultat['donnees']['data'] as $appareil) {
    if (!is_array($appareil)) {
      continue;
    }
    if ($identifiants['deviceId'] === '' && isset($appareil['deviceId']) && is_scalar($appareil['deviceId'])) {
      $identifiants['deviceId'] = (string) $appareil['deviceId'];
    }
    if ($identifiants['productId'] === '' && isset($appareil['productId']) && is_scalar($appareil['productId'])) {
      $identifiants['productId'] = (string) $appareil['productId'];
    }
  }
}

$cheminsCandidats = array();
foreach ($routesCandidates as $modele => $commentaire) {
  $chemin = $modele;
  foreach ($identifiants as $jeton => $valeur) {
    if (strpos($chemin, '{' . $jeton . '}') !== false) {
      if ($valeur === '') {
        $chemin = '';
        break;
      }
      $chemin = str_replace('{' . $jeton . '}', rawurlencode($valeur), $chemin);
    }
  }
  if ($chemin === '') {
    echo '-- ignoree (identifiant absent de user_device) : ' . $modele . "\n";
    continue;
  }
  $cheminsCandidats[$chemin] = $commentaire;
}
foreach ($cheminsSupplementaires as $chemin) {
  $cheminsCandidats[$chemin] = 'passee en argument';
}

try {
  $candidats = smartclimAuxHomeApi::diagnostic(array_keys($cheminsCandidats));
} catch (Throwable $e) {
  echo 'Echec de la sonde des routes candidates : ' . $e->getMessage() . "\n";
  $candidats = array();
}

$tous = array_merge($reference, $candidats);
$commentaires = array_merge($routesReference, $cheminsCandidats);

/*
* Masquage AVANT toute impression, et dans cet ordre : les charges utiles d'abord (elles
* alimentent la table de correspondance), les chemins ensuite (ils contiennent parfois un
* deviceId, qui doit porter le MEME jeton que dans la charge utile).
*/
foreach ($tous as $resultat) {
  $donneesMasquees = masquerRecursif($resultat['donnees'], $clesSensibles, $correspondances, $masquer);
  $rapport['routes'][] = array(
    'chemin' => $resultat['chemin'],
    'commentaire' => isset($commentaires[$resultat['chemin']]) ? $commentaires[$resultat['chemin']] : '',
    'http' => $resultat['http'],
    'code_metier' => $resultat['code'],
    'erreur' => $resultat['erreur'],
    'forme' => formeReponse($donneesMasquees),
    'pistes' => pistesCapacites($donneesMasquees),
    'donnees' => $donneesMasquees,
  );
}
foreach ($rapport['routes'] as $index => $route) {
  if ($masquer) {
    $rapport['routes'][$index]['chemin'] = masquerTexte($route['chemin'], $correspondances);
    // Le message d'erreur aussi : un message cURL peut recopier l'URL sondee, donc un
    // deviceId — deja masque dans la charge utile, il doit l'etre ici aussi.
    $rapport['routes'][$index]['erreur'] = masquerTexte($route['erreur'], $correspondances);
  }
}

/* * *************************** Restitution ***************************** */

echo "\n-- Resume --\n";
foreach ($rapport['routes'] as $route) {
  printf(
    "%-56s http=%-4s code=%-6s %s\n",
    substr($route['chemin'], 0, 56),
    $route['http'] === 0 ? '-' : $route['http'],
    $route['code_metier'] === null ? '-' : $route['code_metier'],
    $route['erreur'] === '' ? 'OK' : $route['erreur']
  );
}

echo "\n-- Pistes « capacites » (cle => valeur) --\n";
foreach ($rapport['routes'] as $route) {
  if (empty($route['pistes'])) {
    continue;
  }
  echo "\n[" . $route['chemin'] . "]\n";
  foreach ($route['pistes'] as $cle => $valeur) {
    echo '  ' . $cle . ' = ' . (is_scalar($valeur) ? $valeur : json_encode($valeur)) . "\n";
  }
}

$json = json_encode($rapport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (@file_put_contents($fichierSortie, $json) === false) {
  echo "\n!! Impossible d ecrire " . $fichierSortie . " — rapport complet ci-dessous.\n";
  echo $json . "\n";
} else {
  echo "\nRapport complet (masque) : " . $fichierSortie . "\n";
  echo "A relire, puis a coller dans la conversation : la section « pistes » d abord, et\n";
  echo "la route dont la charge utile differe d un appareil a l autre.\n";
}
