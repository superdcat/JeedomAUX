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
*   sudo -u www-data php core/php/pont-demon.php --auxlink [--brut]
*   sudo -u www-data php core/php/pont-demon.php --auxlink-armer [--duree=<secondes>] [--hote=<ip>]
*   sudo -u www-data php core/php/pont-demon.php --auxlink-desarmer
*
*   --etat        : affiche l'etat du demon (aucune emission reseau).
*   --ping        : envoie un ping au demon et attend le pong (defaut 5 s).
*   --relais      : rapport de l'etat du relais WebSocket AUX Cloud legacy (UC03 du
*                   domaine post-mvp/05-temps-reel-et-demon) - AUCUNE emission reseau,
*                   lecture seule du battement et de l'index des equipements legacy.
*   --relais-sync : force une resynchronisation IMMEDIATE de l'abonnement au relais
*                   (ignore l'echeance normale de 600 s) - EMET un message au demon.
*
* === SONDE AUXLINK (UC04 post-mvp/05) - debut ===
*   --auxlink         : rapport d'etat interne de la sonde de decouverte AUXLink
*                       (UC04 du domaine post-mvp/05-temps-reel-et-demon) - AUCUNE
*                       emission reseau, lecture seule du rapport en cache. MAC et
*                       device_id MASQUES par defaut (--brut leve le masquage).
*   --auxlink-armer   : arme la campagne d'observation (defaut 24 h, hotes connus du
*                       parc si --hote absent) - EMET une diffusion reseau.
*   --auxlink-desarmer: arrete la campagne en cours.
* === SONDE AUXLINK (UC04 post-mvp/05) - fin ===
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
// === SONDE AUXLINK (UC04 post-mvp/05) - debut ===
$modeAuxlink = false;
$modeAuxlinkArmer = false;
$modeAuxlinkDesarmer = false;
$auxlinkBrut = false;
$auxlinkDuree = null;
$auxlinkHotes = array();
// === SONDE AUXLINK (UC04 post-mvp/05) - fin ===

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
  } elseif ($argument === '--auxlink') {
    $modeAuxlink = true;
  } elseif ($argument === '--auxlink-armer') {
    $modeAuxlinkArmer = true;
  } elseif ($argument === '--auxlink-desarmer') {
    $modeAuxlinkDesarmer = true;
  } elseif ($argument === '--brut') {
    $auxlinkBrut = true;
  } elseif (strpos($argument, '--duree=') === 0) {
    $auxlinkDuree = substr($argument, strlen('--duree='));
  } elseif (strpos($argument, '--hote=') === 0) {
    $auxlinkHotes[] = substr($argument, strlen('--hote='));
  } else {
    die('Option inconnue : ' . $argument . "\n");
  }
}

if (!$modeEtat && !$modePing && !$modeRelais && !$modeRelaisSync && !$modeAuxlink && !$modeAuxlinkArmer && !$modeAuxlinkDesarmer) {
  die("Usage :\n  php core/php/pont-demon.php --etat\n  php core/php/pont-demon.php --ping [--attente=<secondes>]\n  php core/php/pont-demon.php --relais\n  php core/php/pont-demon.php --relais-sync\n  php core/php/pont-demon.php --auxlink [--brut]\n  php core/php/pont-demon.php --auxlink-armer [--duree=<secondes>] [--hote=<ip>]\n  php core/php/pont-demon.php --auxlink-desarmer\n");
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

// === SONDE AUXLINK (UC04 post-mvp/05) - debut ===
// Detection (best effort, sans socket) d'un environnement Docker probablement sans
// "network_mode: host" : sans lui, la sonde AUXLink ne recevra ni n'emettra jamais
// aucune diffusion, et une fenetre de 24 h ne mesurerait alors que le pare-feu du
// conteneur (R2 de la spec technique).
function auxlinkAvertissementDocker() {
  if (file_exists('/.dockerenv')) {
    return true;
  }
  $procNetDev = @file('/proc/net/dev');
  if (!is_array($procNetDev)) {
    return false;
  }
  foreach ($procNetDev as $ligne) {
    if (strpos($ligne, ':') === false) {
      continue;
    }
    $morceaux = explode(':', $ligne);
    $nom = trim($morceaux[0]);
    if ($nom !== '' && $nom !== 'lo') {
      return false;
    }
  }
  return true;
}

// --auxlink-armer : EMET une diffusion reseau (arme la campagne d'observation).
if ($modeAuxlinkArmer) {
  // Barriere CLI (message utilisable) - la barriere metier reste
  // smartclim::normaliserIpV4() dans armerSondeAuxlink(), meme doctrine que
  // --intent/--parametre des autres CLI de ce plugin.
  if ($auxlinkDuree !== null && !is_numeric($auxlinkDuree)) {
    die("La duree doit etre numerique.\n");
  }
  foreach ($auxlinkHotes as $hote) {
    if (filter_var($hote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
      echo 'Hote ignore : adresse non valide (' . $hote . ").\n";
    }
  }
  if (auxlinkAvertissementDocker()) {
    echo "ATTENTION : aucune interface reseau non-loopback detectee (ou conteneur Docker).\n";
    echo "Sans 'network_mode: host', la sonde AUXLink est structurellement aveugle : elle ne recevra ni n'emettra aucune diffusion, et la fenetre d'observation ne mesurerait que le pare-feu du conteneur.\n";
  }
  $resultat = smartclim::armerSondeAuxlink($auxlinkDuree, $auxlinkHotes);
  echo 'Sonde AUXLink : lance=' . ($resultat['lance'] ? 'oui' : 'non') . ' duree=' . $resultat['duree'] . 's hotes=' . (empty($resultat['hotes']) ? '(aucun)' : implode(',', $resultat['hotes'])) . "\n";
  if ($resultat['motif'] !== '') {
    echo '  ' . $resultat['motif'] . "\n";
  }
  exit(0);
}

// --auxlink-desarmer : EMET un message au demon (arrete la campagne en cours).
if ($modeAuxlinkDesarmer) {
  $ok = smartclim::desarmerSondeAuxlink();
  echo 'Sonde AUXLink desarmee : ' . ($ok ? 'oui' : 'non') . "\n";
  exit(0);
}

// --auxlink : LECTURE SEULE (aucune emission reseau) - patron diagnosticTransport().
// MAC et device_id MASQUES par defaut (--brut leve le masquage, cf. smartclimDiagnostic::jeton()).
if ($modeAuxlink) {
  $diagnostic = smartclim::diagnosticSondeAuxlink();
  $correspondancesMasque = array();
  echo "Sonde de decouverte AUXLink :\n";
  echo '  verdict     : ' . $diagnostic['verdict'] . "\n";
  echo '  age rapport : ' . ($diagnostic['age'] === null ? '(aucun)' : $diagnostic['age'] . ' s') . "\n";
  if ($diagnostic['rapport'] === null) {
    exit(0);
  }
  echo "Correspondances d'equipement :\n";
  if (empty($diagnostic['correspondances'])) {
    echo "  (aucune)\n";
  }
  foreach ($diagnostic['correspondances'] as $ligne) {
    $mac = $auxlinkBrut ? $ligne['mac'] : smartclimDiagnostic::jeton($ligne['mac'], $correspondancesMasque);
    $deviceId = ($ligne['device_id'] !== '') ? ($auxlinkBrut ? $ligne['device_id'] : smartclimDiagnostic::jeton($ligne['device_id'], $correspondancesMasque)) : '-';
    echo '  - mac=' . $mac . ' device_id=' . $deviceId . ' equipement=' . ($ligne['equipement'] !== null ? $ligne['equipement'] : '(aucun)') . "\n";
  }
  echo "Trames observees :\n";
  if (empty($diagnostic['rapport']['trames'])) {
    echo "  (aucune)\n";
  }
  foreach ($diagnostic['rapport']['trames'] as $trame) {
    $mac = ($trame['mac'] !== '') ? ($auxlinkBrut ? $trame['mac'] : smartclimDiagnostic::jeton($trame['mac'], $correspondancesMasque)) : '-';
    $deviceId = ($trame['device_id'] !== '') ? ($auxlinkBrut ? $trame['device_id'] : smartclimDiagnostic::jeton($trame['device_id'], $correspondancesMasque)) : '-';
    echo '  ' . $trame['famille'] . '/' . $trame['sens'] . ' mac=' . $mac . ' device_id=' . $deviceId . ' locale=' . ($trame['locale'] ? 'oui' : 'non') . "\n";
  }
  echo "Ports TCP 12416 testes :\n";
  if (empty($diagnostic['rapport']['ports'])) {
    echo "  (aucun)\n";
  }
  foreach ($diagnostic['rapport']['ports'] as $ip => $statut) {
    $ipAffichee = $auxlinkBrut ? $ip : smartclimDiagnostic::jeton($ip, $correspondancesMasque);
    echo '  ' . $ipAffichee . ' : ' . $statut . "\n";
  }
  exit(0);
}
// === SONDE AUXLINK (UC04 post-mvp/05) - fin ===

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
