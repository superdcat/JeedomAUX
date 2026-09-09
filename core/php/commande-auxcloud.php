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
* COMMANDE AUX CLOUD LEGACY — 5e CLI du plugin (UC03 du domaine
* post-mvp/03-cloud-aux-legacy, § 8 de sa spec technique). MEME MOULE que les 4 CLI
* existantes (commande-lan.php, diagnostic-auxhome.php, sonde-intent-auxhome.php) :
* garde php_sapi_name() === 'cli' AVANT tout require_once, aucun POST, aucune ecriture
* en base ni sur disque, sorties FR SANS __() (convention des CLI de ce plugin).
*
* USAGE (sur le Jeedom, en SSH, sous www-data comme les 4 autres CLI) :
*   cd /var/www/html/plugins/smartclim
*   sudo -u www-data php core/php/commande-auxcloud.php --equipement=<id> --lister
*   sudo -u www-data php core/php/commande-auxcloud.php --equipement=<id> --etat
*   sudo -u www-data php core/php/commande-auxcloud.php --equipement=<id> --commande=<logicalId> [--valeur=<consigne>]
*   sudo -u www-data php core/php/commande-auxcloud.php --equipement=<id> --parametre=<cle> --valeur=<n>
*   sudo -u www-data php core/php/commande-auxcloud.php --equipement=<id> --special
*
* Aiguillage SANS logique metier (§ 8 de la spec technique) : --commande passe par
* envoyerCommandeActionAuxCloud(), qui appelle la MEME ordreDeCommandeAction() que le
* pilotage cloud/LAN interactif — jamais de map de concepts construite ici a la main.
* --etat et --parametre exposent la lecture BRUTE (diagnosticAuxCloud()/
* sonderParametreAuxCloud()), sans filtrage de grace ni deduplication : un instrument
* de mesure doit rendre ce que l'appareil a reellement fait. --special emet le second
* `get` conditionne (params: ["mode"]), volontairement absent du cycle periodique (R2
* de la spec technique) : seul moyen de trancher factuellement son role.
*/

if (php_sapi_name() !== 'cli') {
  http_response_code(403);
  die("Ce script est reserve a la ligne de commande.\n");
}

require_once __DIR__ . '/../class/smartclim.class.php';

$idEquipement = null;
$lister = false;
$etat = false;
$commande = null;
$valeur = null;
$parametre = null;
$special = false;

foreach (array_slice($argv, 1) as $argument) {
  if (strpos($argument, '--equipement=') === 0) {
    $idEquipement = substr($argument, strlen('--equipement='));
  } elseif ($argument === '--lister') {
    $lister = true;
  } elseif ($argument === '--etat') {
    $etat = true;
  } elseif (strpos($argument, '--commande=') === 0) {
    $commande = substr($argument, strlen('--commande='));
  } elseif (strpos($argument, '--valeur=') === 0) {
    $valeur = substr($argument, strlen('--valeur='));
  } elseif (strpos($argument, '--parametre=') === 0) {
    $parametre = substr($argument, strlen('--parametre='));
  } elseif ($argument === '--special') {
    $special = true;
  } else {
    die('Option inconnue : ' . $argument . "\n");
  }
}

$usage = "Usage :\n" .
  "  php core/php/commande-auxcloud.php --equipement=<id> --lister\n" .
  "  php core/php/commande-auxcloud.php --equipement=<id> --etat\n" .
  "  php core/php/commande-auxcloud.php --equipement=<id> --commande=<logicalId> [--valeur=<consigne>]\n" .
  "  php core/php/commande-auxcloud.php --equipement=<id> --parametre=<cle> --valeur=<n>\n" .
  "  php core/php/commande-auxcloud.php --equipement=<id> --special\n";

if ($idEquipement === null || !ctype_digit((string) $idEquipement)) {
  die($usage);
}

$eqLogic = eqLogic::byId((int) $idEquipement);
if (!($eqLogic instanceof smartclim)) {
  die('Equipement introuvable ou non SmartClim (id=' . $idEquipement . ").\n");
}

if ($lister) {
  foreach ($eqLogic->getCmd('action') as $cmdAction) {
    echo $cmdAction->getLogicalId() . ' - ' . $cmdAction->getName() . "\n";
  }
  exit(0);
}

if ($etat) {
  try {
    $diagnostic = $eqLogic->diagnosticAuxCloud();
  } catch (smartclimException $e) {
    // Message DEJA CURATE en francais (contrat de diagnosticAuxCloud()).
    die('Echec : ' . $e->getMessage() . "\n");
  } catch (Throwable $e) {
    log::add('smartclim', 'error', 'commande-auxcloud.php --etat : erreur interne (equipement id=' . $idEquipement . ') : ' . get_class($e) . ' : ' . smartclim::neutraliserPourLog($e->getMessage()));
    die("Echec : erreur interne, consultez les logs du plugin.\n");
  }
  echo "Valeurs brutes :\n";
  foreach ($diagnostic['valeurs'] as $nom => $valeurBrute) {
    echo '  ' . $nom . ' = ' . (is_scalar($valeurBrute) ? $valeurBrute : json_encode($valeurBrute)) . "\n";
  }
  echo "Etat decode :\n";
  foreach ($diagnostic['etat'] as $concept => $valeurDecodee) {
    echo '  ' . $concept . ' = ' . (is_scalar($valeurDecodee) ? var_export($valeurDecodee, true) : json_encode($valeurDecodee)) . "\n";
  }
  exit(0);
}

if ($special) {
  try {
    $valeurs = $eqLogic->sonderSpecialAuxCloud();
  } catch (smartclimException $e) {
    die('Echec : ' . $e->getMessage() . "\n");
  } catch (Throwable $e) {
    log::add('smartclim', 'error', 'commande-auxcloud.php --special : erreur interne (equipement id=' . $idEquipement . ') : ' . get_class($e) . ' : ' . smartclim::neutraliserPourLog($e->getMessage()));
    die("Echec : erreur interne, consultez les logs du plugin.\n");
  }
  echo "Reponse du second get (params: [\"mode\"]) :\n";
  if (empty($valeurs)) {
    echo "  (aucune valeur)\n";
  }
  foreach ($valeurs as $nom => $valeurBrute) {
    echo '  ' . $nom . ' = ' . (is_scalar($valeurBrute) ? $valeurBrute : json_encode($valeurBrute)) . "\n";
  }
  exit(0);
}

if ($parametre !== null) {
  // Correctif post-review UC03 (point 5) : validation MIROIR EXACTE de la regex du
  // transport (smartclimAuxCloudApi::sonderParametre()), AVANT toute requete - le
  // docblock de sonderParametre() affirme "validee DEUX FOIS (script + ici)" : cette
  // moitie "script" doit reellement exister, aucune requete n'est emise en cas d'echec.
  if (preg_match('/\A[a-z_][a-z0-9_]{0,31}\z/', (string) $parametre) !== 1) {
    die("--parametre doit etre un nom de forme [a-z_][a-z0-9_]{0,31} (ex. pwr, ac_mode).\n");
  }
  if ($valeur === null || !is_numeric($valeur)) {
    die("--parametre exige --valeur=<n> (entier).\n");
  }
  try {
    $eqLogic->sonderParametreAuxCloud($parametre, (int) $valeur);
  } catch (smartclimException $e) {
    die('Echec : ' . $e->getMessage() . "\n");
  } catch (Throwable $e) {
    log::add('smartclim', 'error', 'commande-auxcloud.php --parametre : erreur interne (equipement id=' . $idEquipement . ') : ' . get_class($e) . ' : ' . smartclim::neutraliserPourLog($e->getMessage()));
    die("Echec : erreur interne, consultez les logs du plugin.\n");
  }
  echo "Parametre envoye : " . $parametre . ' = ' . $valeur . "\n";
  exit(0);
}

if ($commande === null) {
  die($usage);
}
if ($valeur !== null && !is_numeric($valeur)) {
  die("La valeur doit etre numerique.\n");
}

$options = ($valeur !== null) ? array('slider' => (float) $valeur) : array();

try {
  $applique = $eqLogic->envoyerCommandeActionAuxCloud($commande, $options);
} catch (smartclimException $e) {
  // Message DEJA CURATE en francais (contrat de envoyerCommandeActionAuxCloud()) :
  // affichable tel quel, aucun secret ni detail technique dedans.
  die('Echec : ' . $e->getMessage() . "\n");
} catch (Throwable $e) {
  log::add('smartclim', 'error', 'commande-auxcloud.php : erreur interne (equipement id=' . $idEquipement . ') : ' . get_class($e) . ' : ' . smartclim::neutraliserPourLog($e->getMessage()));
  die("Echec : erreur interne, consultez les logs du plugin.\n");
}

echo "Ordre applique :\n";
foreach ($applique as $concept => $valeurAppliquee) {
  echo '  ' . $concept . ' = ' . (is_scalar($valeurAppliquee) ? $valeurAppliquee : json_encode($valeurAppliquee)) . "\n";
}
