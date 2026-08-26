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
* SONDE DE DIAGNOSTIC AUX Home — variante LIGNE DE COMMANDE.
*
* La voie normale est le bouton « Sonde de diagnostic » de la page du plugin : rien a
* installer, rien a taper. Ce script existe pour ce que la page ne peut PAS faire, et
* que le transport refuse hors CLI (cf. smartclimAuxHomeApi::sondeDiagnostic) :
* - sonder des chemins LIBRES, pour suivre une piste ouverte par un premier rapport ;
* - retirer le masquage des identifiants.
*
* USAGE (sur le Jeedom, en SSH) :
*   cd /var/www/html/plugins/smartclim
*   php core/php/diagnostic-auxhome.php
*   php core/php/diagnostic-auxhome.php '/app/getConfig?id=autreId' '/app/route/a/tester'
*
*   --brut                        : identifiants EN CLAIR (a garder pour soi).
*   --sortie=/chemin/rapport.json : fichier de sortie explicite.
*
* Ne fait aucun POST : aucune commande n'est envoyee a un climatiseur, rien n'est ecrit
* en base, aucun equipement n'est modifie.
*/

if (php_sapi_name() !== 'cli') {
  http_response_code(403);
  die("Ce script est reserve a la ligne de commande : utilisez le bouton « Sonde de diagnostic » de la page du plugin.\n");
}

require_once __DIR__ . '/../class/smartclim.class.php';

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

if (!smartclim::compteConfigure()) {
  die("Compte AUX Home non configure (e-mail / mot de passe / pays) : rien a sonder.\n");
}

try {
  $rapport = smartclimDiagnostic::rapport($cheminsSupplementaires, $masquer);
} catch (Throwable $e) {
  die('Echec de la sonde : ' . $e->getMessage() . "\n");
}

echo smartclimDiagnostic::texte($rapport);

/*
* Le rapport COMPLET (charges utiles incluses) part dans un fichier, jamais dans le
* terminal : c'est le resume et les pistes qu'on relit, pas 300 lignes de JSON.
* ⚠️ Ne jamais l'ecrire dans le dossier du plugin : la racine plugins/smartclim/ n'a pas
* de .htaccess, le fichier y serait telechargeable sans authentification.
*/
$json = json_encode($rapport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (@file_put_contents($fichierSortie, $json) === false) {
  echo "\n!! Impossible d ecrire " . $fichierSortie . " — rapport complet ci-dessous.\n\n";
  echo $json . "\n";
} else {
  echo "\nRapport complet" . ($masquer ? ' (masque)' : ' (NON MASQUE)') . ' : ' . $fichierSortie . "\n";
}
