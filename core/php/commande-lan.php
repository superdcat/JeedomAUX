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
* COMMANDE LAN — declencheur CLI unique de l'ecriture Broadlink (UC03 du domaine
* post-mvp/01-transport-broadlink-lan). Aucune surface web n'est ajoutee :
* executerCommandeAction() reste CLOUD (arbitrage du 2026-09-03, cf. § 8.1 de la spec
* technique) — ce script est, en attendant le domaine post-mvp/02, le SEUL declencheur
* du pilotage local.
*
* USAGE (sur le Jeedom, en SSH) :
*   cd /var/www/html/plugins/smartclim
*   php core/php/commande-lan.php --equipement=<id> --lister
*   php core/php/commande-lan.php --equipement=<id> --commande=<logicalId> [--valeur=<consigne>]
*
* Aiguillage SANS logique metier : toute la validation de fond (existence du
* logicalId, bornes/quantification de la consigne, correspondance d'ecriture pour ce
* transport) vit dans smartclim/smartclimFrame, aux memes endroits que pour le cloud.
* Aucun rapport ecrit sur disque, aucune donnee d'equipement persistee.
*/

if (php_sapi_name() !== 'cli') {
  http_response_code(403);
  die("Ce script est reserve a la ligne de commande.\n");
}

require_once __DIR__ . '/../class/smartclim.class.php';

$idEquipement = null;
$lister = false;
$commande = null;
$valeur = null;

foreach (array_slice($argv, 1) as $argument) {
  if (strpos($argument, '--equipement=') === 0) {
    $idEquipement = substr($argument, strlen('--equipement='));
  } elseif ($argument === '--lister') {
    $lister = true;
  } elseif (strpos($argument, '--commande=') === 0) {
    $commande = substr($argument, strlen('--commande='));
  } elseif (strpos($argument, '--valeur=') === 0) {
    $valeur = substr($argument, strlen('--valeur='));
  } else {
    die('Option inconnue : ' . $argument . "\n");
  }
}

if ($idEquipement === null || !ctype_digit((string) $idEquipement)) {
  die("Usage :\n  php core/php/commande-lan.php --equipement=<id> --lister\n  php core/php/commande-lan.php --equipement=<id> --commande=<logicalId> [--valeur=<consigne>]\n");
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

if ($commande === null) {
  die("Precisez --lister ou --commande=<logicalId>.\n");
}
if ($valeur !== null && !is_numeric($valeur)) {
  die("La valeur doit etre numerique.\n");
}

$options = ($valeur !== null) ? array('slider' => (float) $valeur) : array();

try {
  $applique = $eqLogic->envoyerCommandeActionLan($commande, $options);
} catch (smartclimException $e) {
  // Message DEJA CURATE en francais (contrat de envoyerCommandeActionLan()) : affichable
  // tel quel, aucun secret ni detail technique dedans.
  die('Echec : ' . $e->getMessage() . "\n");
} catch (Throwable $e) {
  // Revue croisee (finding low) : jamais un message TECHNIQUE brut affiche, meme sans
  // secret atteignable dans la pile actuelle - meme discipline qu'envoyerOrdreLan().
  log::add('smartclim', 'error', 'commande-lan.php : erreur interne (equipement id=' . $idEquipement . ') : ' . get_class($e) . ' : ' . smartclim::neutraliserPourLog($e->getMessage()));
  die("Echec : erreur interne, consultez les logs du plugin.\n");
}

echo "Ordre applique :\n";
foreach ($applique as $concept => $valeurAppliquee) {
  echo '  ' . $concept . ' = ' . $valeurAppliquee . "\n";
}
