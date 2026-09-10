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
* PONT DEMON — 4e CLI du plugin (UC02 du domaine post-mvp/05-temps-reel-et-demon),
* calquee sur diagnostic-auxhome.php / commande-lan.php / sonde-intent-auxhome.php.
* Aucun POST, aucune ecriture en base ni sur disque.
*
* USAGE (sur le Jeedom, en SSH, sous www-data - le pong est ecrit par Apache dans
* le cache, releve ici) :
*   cd /var/www/html/plugins/smartclim
*   sudo -u www-data php core/php/pont-demon.php --etat
*   sudo -u www-data php core/php/pont-demon.php --ping [--attente=<secondes>]
*   sudo -u www-data php core/php/pont-demon.php --relais
*   sudo -u www-data php core/php/pont-demon.php --relais-sync
*
*   --etat        : affiche l'etat du demon (aucune emission reseau).
*   --ping        : envoie un ping au demon et attend le pong (defaut 5 s).
*   --relais      : rapport de l'etat du relais WebSocket AUX Cloud legacy (UC03 du
*                   domaine post-mvp/05-temps-reel-et-demon) - AUCUNE emission reseau,
*                   lecture seule du battement et de l'index des equipements legacy.
*   --relais-sync : force une resynchronisation IMMEDIATE de l'abonnement au relais
*                   (ignore l'echeance normale de 600 s) - EMET un message au demon.
*/

if (php_sapi_name() !== 'cli') {
  http_response_code(403);
  die("Ce script est reserve a la ligne de commande.\n");
}

require_once __DIR__ . '/../class/smartclim.class.php';

$modeEtat = false;
$modePing = false;
$modeRelais = false;
$modeRelaisSync = false;
$attente = 5;

foreach (array_slice($argv, 1) as $argument) {
  if ($argument === '--etat') {
    $modeEtat = true;
  } elseif ($argument === '--ping') {
    $modePing = true;
  } elseif ($argument === '--relais') {
    $modeRelais = true;
  } elseif ($argument === '--relais-sync') {
    $modeRelaisSync = true;
  } elseif (strpos($argument, '--attente=') === 0) {
    $attente = (float) substr($argument, strlen('--attente='));
  } else {
    die('Option inconnue : ' . $argument . "\n");
  }
}

if (!$modeEtat && !$modePing && !$modeRelais && !$modeRelaisSync) {
  die("Usage :\n  php core/php/pont-demon.php --etat\n  php core/php/pont-demon.php --ping [--attente=<secondes>]\n  php core/php/pont-demon.php --relais\n  php core/php/pont-demon.php --relais-sync\n");
}

if ($modeEtat) {
  $etat = smartclimDemon::etat();
  echo "Etat du demon :\n";
  echo '  state       : ' . (isset($etat['state']) ? $etat['state'] : '?') . "\n";
  echo '  launchable  : ' . (isset($etat['launchable']) ? $etat['launchable'] : '?') . "\n";
  if (!empty($etat['launchable_message'])) {
    echo '  message     : ' . $etat['launchable_message'] . "\n";
  }
  echo '  port        : ' . smartclimDemon::port() . "\n";
  $pidFichier = smartclimDemon::fichierPid();
  $pid = is_file($pidFichier) ? trim((string) @file_get_contents($pidFichier)) : '';
  echo '  pid         : ' . ($pid !== '' ? $pid : '(aucun)') . "\n";
  exit(0);
}

// UC03 du domaine post-mvp/05-temps-reel-et-demon (§ 5.4 de sa spec technique) :
// rapport LECTURE SEULE (aucune emission reseau) - patron diagnosticTransport().
if ($modeRelais) {
  $diagnostic = smartclim::diagnosticRelaisAuxCloud();
  echo "Relais WebSocket AUX Cloud legacy :\n";
  if ($diagnostic['battement'] === null) {
    echo "  battement   : (aucun)\n";
  } else {
    echo '  etat        : ' . $diagnostic['battement']['etat'] . "\n";
    echo '  age         : ' . $diagnostic['battement']['age'] . " s\n";
    echo '  abonnes     : ' . $diagnostic['battement']['abonnes'] . "\n";
    echo '  confirmes   : ' . $diagnostic['battement']['confirmes'] . "\n";
  }
  echo "Equipements legacy :\n";
  if (empty($diagnostic['equipements'])) {
    echo "  (aucun)\n";
  }
  foreach ($diagnostic['equipements'] as $ligne) {
    echo '  - ' . $ligne['nom'] . ' (endpointId=' . $ligne['endpointId'] . ') : push_actif='
      . ($ligne['pushActif'] ? 'oui' : 'non') . ' cadence=' . $ligne['cadence'] . "s\n";
  }
  exit(0);
}

// --relais-sync : EMET un message au demon (force la resynchro, ignore l'echeance).
if ($modeRelaisSync) {
  $resultat = smartclim::synchroniserPushAuxCloud(true);
  echo 'Synchro relais : lance=' . ($resultat['lance'] ? 'oui' : 'non') . ' appareils=' . $resultat['appareils'] . "\n";
  exit(0);
}

// --ping : purger AVANT d'emettre, pour ne pas confirmer un aller-retour anterieur.
smartclimDemon::oublierPong();

$jeton = bin2hex(random_bytes(4));
$debut = microtime(true);

if (!smartclimDemon::ping($jeton)) {
  echo "Echec : demon non joignable (verifiez qu'il est demarre et le port configure).\n";
  exit(1);
}

$borneAttente = $debut + $attente;
$pongObserve = null;
while (microtime(true) < $borneAttente) {
  $pongObserve = smartclimDemon::pongMemorise();
  if ($pongObserve !== null) {
    break;
  }
  usleep(200000);
}

$dureeMs = (int) round((microtime(true) - $debut) * 1000);

if ($pongObserve === null) {
  echo 'Echec : aucun pong recu apres ' . $attente . " s (demon injoignable ou en erreur - consultez log/smartclim_demon).\n";
  exit(1);
}

if ($pongObserve !== $jeton) {
  echo "Echec : pong recu avec un jeton different (aller-retour non fiable).\n";
  exit(1);
}

echo 'Aller-retour OK (jeton ' . $jeton . ', ' . $dureeMs . " ms)\n";
exit(0);
