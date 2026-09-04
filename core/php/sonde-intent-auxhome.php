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
* SONDE D'INTENT AUX Home — instrument de MESURE des fonctions de confort (UC01 du
* domaine post-mvp/04-fonctions-avancees, § 5.6 de sa spec technique).
*
* Protocole de recette complet : § 11 de la spec technique de cette UC. Resume :
* 1. --etat seul, pour avoir une REFERENCE (25 octets, bits documentes, etat decode).
* 2. Pour chaque fonction, dans l'etat PERMIS par le backend (allume pour sleep/health,
*    eteint pour clean/mildew, indifferent pour display) : --concept=<code> --valeur=1
*    puis --valeur=0, en notant le code metier renvoye, l'octet/bit qui bascule et
*    l'effet physique constate.
* 3. --intent est l'echappatoire d'investigation : une cle AUX BRUTE, non traduite, pour
*    essayer une variante ("screen_on_off") ou chercher un bit "eco" par diff d'octets.
*
* USAGE (sur le Jeedom, en SSH) :
*   cd /var/www/html/plugins/smartclim
*   php core/php/sonde-intent-auxhome.php --equipement=<id> --etat
*   php core/php/sonde-intent-auxhome.php --equipement=<id> --concept=<code> --valeur=<n> [--allumer] [--attente=<s>]
*   php core/php/sonde-intent-auxhome.php --equipement=<id> --intent=<cle> --valeur=<n> [--allumer] [--attente=<s>]
*
* Aiguillage SANS logique metier : ce script ne construit JAMAIS de map de concepts a la
* main (meme discipline que core/php/commande-lan.php) — il appelle
* smartclim::sonderIntentAuxHome(), qui passe par la MEME construction d'ordre que le
* reste du plugin. Aucun POST hors cet ordre explicite, aucune ecriture en base ni sur
* disque : sortie sur la sortie standard uniquement.
*/

if (php_sapi_name() !== 'cli') {
  http_response_code(403);
  die("Ce script est reserve a la ligne de commande.\n");
}

require_once __DIR__ . '/../class/smartclim.class.php';

$idEquipement = null;
$etat = false;
$concept = null;
$intent = null;
$valeur = null;
$allumer = false;
$attente = 15;

foreach (array_slice($argv, 1) as $argument) {
  if (strpos($argument, '--equipement=') === 0) {
    $idEquipement = substr($argument, strlen('--equipement='));
  } elseif ($argument === '--etat') {
    $etat = true;
  } elseif (strpos($argument, '--concept=') === 0) {
    $concept = substr($argument, strlen('--concept='));
  } elseif (strpos($argument, '--intent=') === 0) {
    $intent = substr($argument, strlen('--intent='));
  } elseif (strpos($argument, '--valeur=') === 0) {
    $valeur = substr($argument, strlen('--valeur='));
  } elseif ($argument === '--allumer') {
    $allumer = true;
  } elseif (strpos($argument, '--attente=') === 0) {
    $attente = substr($argument, strlen('--attente='));
  } else {
    die('Option inconnue : ' . $argument . "\n");
  }
}

$usage = "Usage :\n"
  . "  php core/php/sonde-intent-auxhome.php --equipement=<id> --etat\n"
  . "  php core/php/sonde-intent-auxhome.php --equipement=<id> --concept=<code> --valeur=<n> [--allumer] [--attente=<s>]\n"
  . "  php core/php/sonde-intent-auxhome.php --equipement=<id> --intent=<cle> --valeur=<n> [--allumer] [--attente=<s>]\n";

if ($idEquipement === null || !ctype_digit((string) $idEquipement)) {
  die($usage);
}
if ($concept !== null && $intent !== null) {
  die("Precisez --concept OU --intent, jamais les deux.\n" . $usage);
}
if (!$etat && $concept === null && $intent === null) {
  die($usage);
}
if (($concept !== null || $intent !== null) && ($valeur === null || preg_match('/\A-?\d+\z/', (string) $valeur) !== 1)) {
  die("--concept/--intent exige --valeur=<entier signe>.\n" . $usage);
}
if ($intent !== null && preg_match('/\A[a-z][a-z0-9_]{1,30}\z/', $intent) !== 1) {
  // Controle de forme redondant avec smartclimAuxHomeApi::sonderIntent() (MEME expression),
  // et ne le remplace pas : ce script est un point d'entree CLI, sonderIntent() reste la
  // garde autoritaire. But ICI purement ergonomique — donner a l'operateur un message de
  // forme explicite plutot que le message generique curate par le transport.
  die("--intent doit respecter [a-z][a-z0-9_]{1,30} (cle AUX brute en minuscules).\n" . $usage);
}
if (!ctype_digit((string) $attente) || (int) $attente < 0 || (int) $attente > 180) {
  die("--attente doit etre un entier entre 0 et 180.\n" . $usage);
}

$eqLogic = eqLogic::byId((int) $idEquipement);
if (!($eqLogic instanceof smartclim)) {
  die('Equipement introuvable ou non SmartClim (id=' . $idEquipement . ").\n");
}

$ordre = array();
$brut = false;
if ($concept !== null) {
  $ordre[$concept] = ((int) $valeur !== 0);
  if ($allumer) {
    $ordre[smartclimCapabilities::CONCEPT_POWER] = 1;
  }
} elseif ($intent !== null) {
  $brut = true;
  $ordre[$intent] = (int) $valeur;
  if ($allumer) {
    // L'echappatoire d'investigation N'HERITE JAMAIS de fonctionsConfort() (§ 5.6 de
    // la spec technique) : une mesure dont la commande depend d'une table qu'on
    // cherche justement a valider est une mesure ambigue — power est ajoute ICI,
    // explicitement, en clair AUX ("on_off").
    $ordre['on_off'] = 1;
  }
}

try {
  if ($etat && empty($ordre)) {
    $mesure = $eqLogic->lireTrameAuxHome();
    echo smartclimDiagnostic::texteTrameHvac($mesure['trame'], $mesure['trame'], $mesure['etat'], $mesure['etat']);
  } else {
    $resultat = $eqLogic->sonderIntentAuxHome($ordre, (int) $attente, $brut);
    echo 'Ordre envoye : ' . ($resultat['ecrit'] ? 'oui' : 'non (lecture seule)') . "\n\n";
    echo smartclimDiagnostic::texteTrameHvac($resultat['avant'], $resultat['apres'], $resultat['etat_avant'], $resultat['etat_apres']);
  }
} catch (smartclimException $e) {
  // Message DEJA CURATE en francais (contrat de smartclim::lireTrameAuxHome()/
  // sonderIntentAuxHome()) : affichable tel quel, aucun secret ni detail technique.
  die('Echec : ' . $e->getMessage() . "\n");
} catch (Throwable $e) {
  log::add('smartclim', 'error', 'sonde-intent-auxhome.php : erreur interne (equipement id=' . $idEquipement . ') : ' . get_class($e) . ' : ' . smartclim::neutraliserPourLog($e->getMessage()));
  die("Echec : erreur interne, consultez les logs du plugin.\n");
}
