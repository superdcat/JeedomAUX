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
// Même motif que les autres classes annexes (autoload du core limité à smartclim.class.php,
// cf. core/php/smartclim.inc.php) : rend ce fichier autonome. require_once idempotent.
require_once __DIR__ . '/smartclimCapabilities.class.php';

/**
 * Décodeur MUTUALISÉ de la trame HVAC (UC02 du domaine
 * post-mvp/01-transport-broadlink-lan, § 2 de la spec technique) : table de données pure,
 * exactement au même titre que smartclimCapabilities — aucune E/S, aucun cache::, aucun
 * config::, aucun eqLogic, aucun accès réseau. Ne connaît ni AUX Home, ni Broadlink : elle
 * reçoit deux trames en hexadécimal et un identifiant de transport.
 *
 * Extraite le 2026-09-01 de smartclimAuxHomeApi::etatAppareil() (ex-champsEtatAuxHome() /
 * offsetsAuxHome() / octetTrame()) : condition « second appelant » posée par CLAUDE.md
 * désormais remplie (le transport Broadlink LAN décode la MÊME trame). Le corps de
 * decoderEtat() est une copie VERBATIM de la logique déplacée — décalages de bits, cast
 * (float) explicite sur la consigne, garde de plausibilité de l'ambiante : AUCUNE valeur
 * d'octet, AUCUN décalage, AUCUNE borne ne change. Seuls écarts autorisés : le transport
 * reçu en PARAMÈTRE (au lieu de la constante TRANSPORT_AUX_HOME) et des messages de log
 * NEUTRES de transport. C'est ce qui rend le chemin cloud, déjà recetté, inchangé d'un
 * pixel, et AC3 d'UC02 (« état identique par les deux voies ») vrai PAR CONSTRUCTION.
 */
class smartclimFrame {
  /*     * *************************Attributs****************************** */

  // Identifiants GÉNÉRIQUES des deux trames HVAC consommées ici — jamais un nom de champ
  // propriétaire (status.control / status.running côté AUX Home, réponses 0x6A côté
  // Broadlink LAN) : c'est le rôle du TRANSPORT appelant de fournir la bonne trame dans le
  // bon paramètre, cette classe ne connaît que "contrôle" et "longue".
  const TRAME_CONTROLE = 'controle';
  const TRAME_LONGUE = 'longue';

  // Plausibilité de la température ambiante décodée (octet[15] de la trame longue - 32 est
  // mathématiquement borné à [-32, 223]). Une trame à zéros (appareil éteint ?) donne -32 :
  // hors de cette borne, le concept est OMIS (jamais une valeur par défaut, jamais null
  // poussé). Valeurs INCHANGÉES depuis smartclimAuxHomeApi (ex-AMBIANTE_MIN/MAX_PLAUSIBLE).
  const AMBIANTE_MIN_PLAUSIBLE = -20;
  const AMBIANTE_MAX_PLAUSIBLE = 60;

  /*     * ***********************Methode static*************************** */

  /**
   * Emplacement de CHAQUE concept dans les trames HVAC : 'trame' (TRAME_CONTROLE ou
   * TRAME_LONGUE) + 'octets' (indices 0-based lus pour ce concept). SOURCE UNIQUE de
   * l'emplacement — longueursMinimales() ci-dessous en DÉRIVE ses longueurs minimales, et
   * decoderEtat()/conceptsLisibles() ci-dessous lisent directement cette table. Copie
   * VERBATIM de l'ex-smartclimAuxHomeApi::champsEtatAuxHome().
   *
   * @return array<string, array{trame:string, octets:array<int,int>}>
   */
  private static function champs() {
    return array(
      smartclimCapabilities::CONCEPT_TARGET_TEMP => array('trame' => self::TRAME_CONTROLE, 'octets' => array(10, 12)),
      smartclimCapabilities::CONCEPT_FAN_SPEED => array('trame' => self::TRAME_CONTROLE, 'octets' => array(13)),
      smartclimCapabilities::CONCEPT_MODE => array('trame' => self::TRAME_CONTROLE, 'octets' => array(15)),
      smartclimCapabilities::CONCEPT_POWER => array('trame' => self::TRAME_CONTROLE, 'octets' => array(18)),
      smartclimCapabilities::CONCEPT_AMBIENT_TEMP => array('trame' => self::TRAME_LONGUE, 'octets' => array(15)),
    );
  }

  /**
   * Longueur MINIMALE (en octets) de chaque trame requise par concept, avant d'en tirer
   * une correspondance générique : offsets 0-based, donc une trame de longueur N couvre
   * l'octet d'indice N-1. DÉRIVÉE de champs() (longueur minimale = max(octets) + 1). Copie
   * VERBATIM de l'ex-smartclimAuxHomeApi::offsetsAuxHome() (même forme de retour).
   *
   * @return array{controle:array<string,int>, longue:array<string,int>}
   */
  private static function longueursMinimales() {
    $offsets = array(self::TRAME_CONTROLE => array(), self::TRAME_LONGUE => array());
    foreach (self::champs() as $concept => $champ) {
      $offsets[$champ['trame']][$concept] = max($champ['octets']) + 1;
    }
    return $offsets;
  }

  /**
   * Octet d'indice $_index (0-based) d'une trame hexadécimale déjà nettoyée par le
   * transport appelant, ou null si la trame est trop courte / non exploitable. Ne lève
   * jamais. Copie VERBATIM de l'ex-smartclimAuxHomeApi::octetTrame().
   *
   * @param string $_trame
   * @param int $_index
   * @return int|null
   */
  private static function octet($_trame, $_index) {
    if (!is_string($_trame) || $_index < 0) {
      return null;
    }
    $hex = substr($_trame, $_index * 2, 2);
    if (strlen($hex) !== 2) {
      return null;
    }
    return hexdec($hex);
  }

  /**
   * Concepts DÉCODABLES depuis ces deux trames (longueur suffisante pour l'offset requis,
   * cf. champs()) — n'INCLUT JAMAIS CONCEPT_ONLINE : la joignabilité est l'affaire du
   * transport (cloud : champ 'online' de la réponse ; LAN : succès de la session), jamais
   * de la trame elle-même.
   *
   * @param string $_trameControle
   * @param string $_trameLongue
   * @return array<int,string>
   */
  public static function conceptsLisibles($_trameControle, $_trameLongue) {
    $trameControle = is_string($_trameControle) ? $_trameControle : '';
    $trameLongue = is_string($_trameLongue) ? $_trameLongue : '';
    $octetsControle = strlen($trameControle) / 2;
    $octetsLongue = strlen($trameLongue) / 2;
    $offsets = self::longueursMinimales();

    $concepts = array();
    foreach (array(
      smartclimCapabilities::CONCEPT_TARGET_TEMP,
      smartclimCapabilities::CONCEPT_FAN_SPEED,
      smartclimCapabilities::CONCEPT_MODE,
      smartclimCapabilities::CONCEPT_POWER,
    ) as $concept) {
      if ($octetsControle >= $offsets[self::TRAME_CONTROLE][$concept]) {
        $concepts[] = $concept;
      }
    }
    if ($octetsLongue >= $offsets[self::TRAME_LONGUE][smartclimCapabilities::CONCEPT_AMBIENT_TEMP]) {
      $concepts[] = smartclimCapabilities::CONCEPT_AMBIENT_TEMP;
    }
    return $concepts;
  }

  /**
   * Décode l'état GÉNÉRIQUE porté par ces deux trames, pour LE TRANSPORT $_transport (sert
   * uniquement à résoudre les codes propriétaires via smartclimCapabilities::depuisTransport(),
   * § 2.1 de la spec technique UC02). SEULES les clés effectivement déterminables sont
   * renvoyées — jamais de valeur par défaut, jamais null poussé (mécanisme d'AC10 du MVP).
   * Ne pose NI 'online' NI 'source' : les deux sont ajoutés par le transport appelant. Ne
   * lève JAMAIS (contrôles is_string/longueur).
   *
   * ⚠️ Corps COPIÉ VERBATIM depuis l'ex-smartclimAuxHomeApi::etatAppareil() (§ 2.1 de la
   * spec technique) : aucune valeur d'octet, aucun décalage, aucune borne ne change. Les
   * trames ne sont NI journalisées NI persistées telles quelles ; seuls les codes fil
   * (entiers) et les longueurs peuvent apparaître en 'debug'.
   *
   * @param string $_transport smartclimCapabilities::TRANSPORT_*.
   * @param string $_trameControle Trame hexadécimale déjà nettoyée par le transport appelant.
   * @param string $_trameLongue Trame hexadécimale déjà nettoyée par le transport appelant.
   * @return array<string, mixed>
   */
  public static function decoderEtat($_transport, $_trameControle, $_trameLongue) {
    $trameControle = is_string($_trameControle) ? $_trameControle : '';
    $trameLongue = is_string($_trameLongue) ? $_trameLongue : '';
    $offsets = self::longueursMinimales();
    $octetsControle = strlen($trameControle) / 2;
    $octetsLongue = strlen($trameLongue) / 2;

    $etat = array();

    if ($octetsControle >= $offsets[self::TRAME_CONTROLE][smartclimCapabilities::CONCEPT_POWER]) {
      $octet18 = self::octet($trameControle, 18);
      if ($octet18 !== null) {
        $etat[smartclimCapabilities::CONCEPT_POWER] = ($octet18 >> 5) & 1;
      }
    }

    if ($octetsControle >= $offsets[self::TRAME_CONTROLE][smartclimCapabilities::CONCEPT_MODE]) {
      $octet15 = self::octet($trameControle, 15);
      if ($octet15 !== null) {
        $codeMode = $octet15 >> 5;
        $mode = smartclimCapabilities::depuisTransport($_transport, smartclimCapabilities::CONCEPT_MODE, $codeMode);
        if ($mode !== null) {
          $etat[smartclimCapabilities::CONCEPT_MODE] = $mode;
        } else {
          log::add('smartclim', 'debug', 'Trame HVAC : code mode inconnu (' . $codeMode . ')');
        }
      }
    }

    if ($octetsControle >= $offsets[self::TRAME_CONTROLE][smartclimCapabilities::CONCEPT_TARGET_TEMP]) {
      $octet10 = self::octet($trameControle, 10);
      $octet12 = self::octet($trameControle, 12);
      if ($octet10 !== null && $octet12 !== null) {
        // Cast explicite : sans lui, la consigne est un int aux degrés pleins et un
        // float aux demi-degrés. Le type doit rester STABLE d'un cycle à l'autre, sinon
        // l'aller-retour par le cache du core peut faire varier execCmd() et déclencher
        // un faux changement, donc un 'last_update' qui repart sans raison.
        $temp = (float) (($octet10 >> 3) + 8);
        if (($octet12 & 0x80) !== 0) {
          $temp += 0.5;
        }
        $etat[smartclimCapabilities::CONCEPT_TARGET_TEMP] = $temp;
      }
    }

    if ($octetsControle >= $offsets[self::TRAME_CONTROLE][smartclimCapabilities::CONCEPT_FAN_SPEED]) {
      $octet13 = self::octet($trameControle, 13);
      if ($octet13 !== null) {
        $codeVitesse = $octet13 >> 5;
        $vitesse = smartclimCapabilities::depuisTransport($_transport, smartclimCapabilities::CONCEPT_FAN_SPEED, $codeVitesse);
        if ($vitesse !== null) {
          $etat[smartclimCapabilities::CONCEPT_FAN_SPEED] = $vitesse;
        } else {
          log::add('smartclim', 'debug', 'Trame HVAC : code vitesse inconnu (' . $codeVitesse . ')');
        }
      }
    }

    if ($octetsLongue >= $offsets[self::TRAME_LONGUE][smartclimCapabilities::CONCEPT_AMBIENT_TEMP]) {
      $octet15Longue = self::octet($trameLongue, 15);
      if ($octet15Longue !== null) {
        $ambiante = $octet15Longue - 32;
        if ($ambiante >= self::AMBIANTE_MIN_PLAUSIBLE && $ambiante <= self::AMBIANTE_MAX_PLAUSIBLE) {
          $etat[smartclimCapabilities::CONCEPT_AMBIENT_TEMP] = $ambiante;
        } else {
          log::add('smartclim', 'debug', 'Trame HVAC : température ambiante implausible (' . $ambiante . ')');
        }
      }
    }

    return $etat;
  }
}
