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
// UC03 du domaine post-mvp/01-transport-broadlink-lan (§ 5.1 de sa spec technique) :
// encoderOrdre() ci-dessous lève des smartclimException typées (TYPE_PROTOCOLE,
// TYPE_INTERNE).
require_once __DIR__ . '/smartclimException.class.php';

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
 *
 * ⚠️ Depuis l'UC03 de ce domaine (« Envoi de commandes en LAN »), cette classe porte AUSSI
 * l'ENCODAGE de l'écriture (`encoderOrdre()`, § 5.1 de sa spec technique) : « ne lève
 * jamais » ne vaut donc plus que pour le DÉCODAGE ci-dessus. `encoderOrdre()` lève une
 * `smartclimException` typée — une base illisible ou un concept sans correspondance
 * d'écriture ne doivent JAMAIS produire un octet approximatif.
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

  // UC03 du domaine post-mvp/01-transport-broadlink-lan (§ 2.2/5.1 de sa spec technique) :
  // ÉCRITURE de la charge HVAC — 23 octets, en-tête FIXE distinct de celui de la LECTURE
  // sur seulement 2 octets (l'octet 6 = 0x0f au lieu de 0x02, l'octet 8 = 0x01 au lieu de
  // 0x11), et un marqueur FORCÉ (octet 12, bits 3-0) sans lequel l'appareil ignore
  // silencieusement l'ordre. Consigne encodable sur [8, 39] °C (5 bits, (T-8) << 3).
  const LONGUEUR_CHARGE_ECRITURE = 23;
  const ENTETE_ECRITURE_HEX = 'bb00068000000f000101';
  const MARQUEUR_ECRITURE = 0x0F;
  const CONSIGNE_MIN_ENCODABLE = 8;
  const CONSIGNE_MAX_ENCODABLE = 39;

  // Contextes techniques DÉDIÉS (revue croisée UC03, § 4.2/4.3 de la spec technique) :
  // smartclim::messageErreurLan() est l'UNIQUE endroit qui les traduit en français,
  // même patron que smartclimBroadlinkLan::CONTEXTE_ECRITURE_NON_CONFIRMEE — un ordre
  // NON envoyé (base illisible) doit se distinguer d'un ordre envoyé mais NON confirmé.
  const CONTEXTE_BASE_ILLISIBLE = 'trame_base_illisible';
  const CONTEXTE_CONSIGNE_HORS_PLAGE = 'consigne_hors_plage';

  // UC04 du domaine post-mvp/01-transport-broadlink-lan (§ 2.2/5.6 de sa spec
  // technique) : préfixe des trames de CONTRÔLE, établi côté cloud (recetté) et par les
  // magics de lecture — JAMAIS observé sur une réponse LAN réelle (R3). Utilisé
  // UNIQUEMENT comme signal de journalisation par le transport appelant, jamais comme
  // critère bloquant.
  const MAGIC_TRAME_HVAC = 'bb00';

  /*     * ***********************Methode static*************************** */

  /**
   * Emplacement de CHAQUE concept dans les trames HVAC : 'trame' (TRAME_CONTROLE ou
   * TRAME_LONGUE) + 'octets' (indices 0-based lus pour ce concept). SOURCE UNIQUE de
   * l'emplacement — longueursMinimales() ci-dessous en DÉRIVE ses longueurs minimales, et
   * decoderEtat()/conceptsLisibles() ci-dessous lisent directement cette table. Copie
   * VERBATIM de l'ex-smartclimAuxHomeApi::champsEtatAuxHome().
   *
   * ⚠️ Octets 13/15/18 (vitesse/mode/marche) IDENTIQUES à ceux de champsEcriture()
   * ci-dessous (ÉCRITURE, UC03 de ce domaine, § 2.2 de sa spec technique) : mode et
   * vitesse s'écrivent au MÊME décalage qu'ils se lisent. Commentaire croisé : toute
   * évolution de l'une de ces deux tables doit être vérifiée contre l'autre.
   *
   * ⚠️ Depuis l'UC01 du domaine post-mvp/04-fonctions-avancees : les octets 15 et 18
   * sont EN PLUS PARTAGÉS avec champsBinaires() ci-dessous (sommeil sur l'octet 15,
   * ioniseur/nettoyage sur l'octet 18) — TROISIÈME table à vérifier avant toute
   * modification de ces deux octets, en plus de champsEcriture().
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
   * Emplacement des concepts BOOLÉENS de confort (UC01 du domaine
   * post-mvp/04-fonctions-avancees, § 5.2 de sa spec technique) : un simple (octet, bit)
   * — jamais 'octets' (pluriel) comme champs() ci-dessus, dont le SCHÉMA est différent
   * (une liste d'indices, pas un octet unique + un bit). Table NON filtrée par
   * 'confirme' : des offsets restent des offsets, quel que soit l'état de recette —
   * c'est conceptsLisibles()/decoderEtat() ci-dessous qui appliquent le filtre.
   *
   * ⚠️ Commentaire croisé OBLIGATOIRE avec champs() et champsEcriture() : l'octet 15 est
   * PARTAGÉ avec le mode (champs()/champsEcriture() : bits 7-5), l'octet 18 avec la
   * marche (bits 7-5). Toute modification de l'une de ces trois tables se vérifie contre
   * les deux autres — un octet écrit en entier au lieu d'être masqué casserait deux
   * concepts d'un coup.
   *
   * PUBLIQUE (contrairement à champs()) : second consommateur hors de cette classe,
   * smartclimDiagnostic::texteTrameHvac() (§ 5.7 de la spec technique UC01) — mise en
   * forme du rapport de mesure, sans jamais coder un offset en dur de son côté.
   *
   * @return array<string, array{trame:string, octet:int, bit:int}>
   */
  public static function champsBinaires() {
    return array(
      smartclimCapabilities::CONCEPT_SLEEP => array('trame' => self::TRAME_CONTROLE, 'octet' => 15, 'bit' => 2),
      smartclimCapabilities::CONCEPT_HEALTH => array('trame' => self::TRAME_CONTROLE, 'octet' => 18, 'bit' => 1),
      smartclimCapabilities::CONCEPT_CLEAN => array('trame' => self::TRAME_CONTROLE, 'octet' => 18, 'bit' => 2),
      smartclimCapabilities::CONCEPT_DISPLAY => array('trame' => self::TRAME_CONTROLE, 'octet' => 20, 'bit' => 4),
      smartclimCapabilities::CONCEPT_MILDEW => array('trame' => self::TRAME_CONTROLE, 'octet' => 20, 'bit' => 3),
    );
  }

  /**
   * Longueur MINIMALE (en octets) de chaque trame requise par concept, avant d'en tirer
   * une correspondance générique : offsets 0-based, donc une trame de longueur N couvre
   * l'octet d'indice N-1. DÉRIVÉE de champs() UNION champsBinaires() (longueur minimale
   * = max(octets) + 1 pour champs(), octet + 1 pour champsBinaires()) — SOURCE UNIQUE
   * des longueurs, ne pas coder de seuil ailleurs. Copie VERBATIM de l'ex-
   * smartclimAuxHomeApi::offsetsAuxHome() (même forme de retour) pour la partie champs().
   *
   * ⚠️ Deux boucles DISTINCTES : champs() porte 'octets' (pluriel, liste d'indices),
   * champsBinaires() porte 'octet' (singulier, un entier) — ne jamais confondre les deux
   * schémas dans une même boucle.
   *
   * @return array{controle:array<string,int>, longue:array<string,int>}
   */
  private static function longueursMinimales() {
    $offsets = array(self::TRAME_CONTROLE => array(), self::TRAME_LONGUE => array());
    foreach (self::champs() as $concept => $champ) {
      $offsets[$champ['trame']][$concept] = max($champ['octets']) + 1;
    }
    foreach (self::champsBinaires() as $concept => $champ) {
      $offsets[$champ['trame']][$concept] = $champ['octet'] + 1;
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

    // UC01 du domaine post-mvp/04-fonctions-avancees (§ 5.2 de sa spec technique) :
    // n'ajoute un concept de confort QUE s'il figure dans conceptsConfortLivres() — seul
    // point de filtrage de l'UC (mécanisme d'AC5 côté lecture).
    $livres = smartclimCapabilities::conceptsConfortLivres();
    foreach (self::champsBinaires() as $concept => $champ) {
      if (!in_array($concept, $livres, true)) {
        continue;
      }
      $octetsDisponibles = ($champ['trame'] === self::TRAME_CONTROLE) ? $octetsControle : $octetsLongue;
      if ($octetsDisponibles >= $offsets[$champ['trame']][$concept]) {
        $concepts[] = $concept;
      }
    }
    return $concepts;
  }

  /**
   * true si $_trame commence par le magic MAGIC_TRAME_HVAC (UC04 du domaine
   * post-mvp/01-transport-broadlink-lan, § 5.6 de sa spec technique). PRÉDICAT PUR,
   * AUCUNE E/S — la classe reste une table de données. Ne sert QUE de signal de
   * journalisation à l'appelant (smartclimBroadlinkLan::lireEtat()) : jamais un critère
   * bloquant, cf. le docblock de la constante ci-dessus.
   *
   * @param mixed $_trame
   * @return bool
   */
  public static function estTrameHvac($_trame) {
    return is_string($_trame) && strpos($_trame, self::MAGIC_TRAME_HVAC) === 0;
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

    // UC01 du domaine post-mvp/04-fonctions-avancees (§ 5.2 de sa spec technique) :
    // fonctions de confort, filtrées par conceptsConfortLivres() — une clé absente reste
    // absente (trame trop courte, octet illisible) : ne JAMAIS substituer 0 à une
    // lecture impossible, ce serait afficher « inactif » pour « inconnu » et casserait
    // l'invariant « une clé absente de l'état ne touche pas sa commande ».
    $livres = smartclimCapabilities::conceptsConfortLivres();
    foreach (self::champsBinaires() as $concept => $champ) {
      if (!in_array($concept, $livres, true)) {
        continue;
      }
      $trame = ($champ['trame'] === self::TRAME_CONTROLE) ? $trameControle : $trameLongue;
      $octetsDisponibles = ($champ['trame'] === self::TRAME_CONTROLE) ? $octetsControle : $octetsLongue;
      if ($octetsDisponibles < $offsets[$champ['trame']][$concept]) {
        continue;
      }
      $valeurOctet = self::octet($trame, $champ['octet']);
      if ($valeurOctet !== null) {
        $etat[$concept] = ($valeurOctet >> $champ['bit']) & 1;
      }
    }

    return $etat;
  }

  /**
   * En-tête FIXE d'une charge HVAC d'ÉCRITURE (10 octets, § 2.2 de la spec technique
   * UC03) : DEUX octets seulement la distinguent de l'en-tête de LECTURE (l'octet 6 —
   * 0x0f au lieu de 0x02 — et l'octet 8 — 0x01 au lieu de 0x11). Écrit depuis le contrat
   * documenté, jamais recopié depuis une source sans licence (§ 2.1/R8).
   *
   * @return string 10 octets bruts.
   */
  private static function enteteEcriture() {
    return hex2bin(self::ENTETE_ECRITURE_HEX);
  }

  /**
   * Emplacement des concepts qui s'écrivent par un simple couple (masque, décalage)
   * dans UN SEUL octet — vitesse, mode, marche (§ 2.2/5.1 de la spec technique UC03).
   * La consigne (2 octets, encodage dédié) n'est PAS ici : cf. encoderConsigne().
   *
   * ⚠️ Octets 13/15/18 IDENTIQUES à ceux de champs() (LECTURE, ci-dessus) : le mode et
   * la vitesse s'écrivent au MÊME décalage qu'ils se lisent — c'est ce qui rend la
   * fusion par recopie de la trame lue cohérente (§ 5.4 de la spec technique : « intent
   * = fil n'est pas une supposition, c'est une conséquence du protocole »). Commentaire
   * croisé avec champs() : toute évolution de l'un doit être vérifiée contre l'autre.
   *
   * ⚠️ Depuis l'UC01 du domaine post-mvp/04-fonctions-avancees (§ 5.2 de sa spec
   * technique) : 5 lignes supplémentaires portant 'binaire' => true (concepts de
   * confort), masques VÉRIFIÉS DISJOINTS de ceux des concepts qui partagent leur octet
   * (sleep 0x04 contre mode 0xE0 sur l'octet 15 ; health 0x02 / clean 0x04 contre power
   * 0x20 sur l'octet 18). encoderOrdre() ci-dessous COURT-CIRCUITE
   * smartclimCapabilities::versTransport() pour ces lignes : ces concepts sont ABSENTS
   * de smartclimCapabilities::tables() (ce sont des booléens, pas des valeurs génériques
   * énumérées), donc versTransport() y renverrait systématiquement null et lèverait
   * TYPE_INTERNE à chaque commande LAN.
   *
   * @return array<string, array{octet:int, masque:int, decalage:int, binaire?:bool}>
   */
  private static function champsEcriture() {
    return array(
      smartclimCapabilities::CONCEPT_FAN_SPEED => array('octet' => 13, 'masque' => 0xE0, 'decalage' => 5),
      smartclimCapabilities::CONCEPT_MODE => array('octet' => 15, 'masque' => 0xE0, 'decalage' => 5),
      smartclimCapabilities::CONCEPT_POWER => array('octet' => 18, 'masque' => 0x20, 'decalage' => 5),
      smartclimCapabilities::CONCEPT_SLEEP => array('octet' => 15, 'masque' => 0x04, 'decalage' => 2, 'binaire' => true),
      smartclimCapabilities::CONCEPT_HEALTH => array('octet' => 18, 'masque' => 0x02, 'decalage' => 1, 'binaire' => true),
      smartclimCapabilities::CONCEPT_CLEAN => array('octet' => 18, 'masque' => 0x04, 'decalage' => 2, 'binaire' => true),
      smartclimCapabilities::CONCEPT_DISPLAY => array('octet' => 20, 'masque' => 0x10, 'decalage' => 4, 'binaire' => true),
      smartclimCapabilities::CONCEPT_MILDEW => array('octet' => 20, 'masque' => 0x08, 'decalage' => 3, 'binaire' => true),
    );
  }

  /**
   * Concepts que encoderOrdre() sait ÉCRIRE (§ 5.1 de la spec technique UC03) :
   * champsEcriture() + la consigne (encodage dédié sur 2 octets). Consommée en LISTE
   * BLANCHE par smartclim::valeursCommandees() — une entrée de mémoire d'ordres portant
   * un concept futur (oscillation, domaine post-mvp/04) ne doit JAMAIS atteindre
   * encoderOrdre(), sous peine de TYPE_INTERNE sur TOUTE commande LAN pendant la
   * fenêtre de grâce (§ 6.2/R14 de la spec technique).
   *
   * @return array<int, string>
   */
  public static function conceptsEncodables() {
    return array_merge(array_keys(self::champsEcriture()), array(smartclimCapabilities::CONCEPT_TARGET_TEMP));
  }

  /**
   * Encode la consigne GÉNÉRIQUE (float, degrés Celsius) sur les octets 10 (bits 7-3)
   * et 12 (bit 7, demi-degré) — § 2.2/2.4 de la spec technique UC03. Arrondi à 0,5 °C
   * le plus proche AVANT de séparer partie entière et demi-degré (mêmes conventions que
   * le DÉCODAGE, smartclimCapabilities::echelleTemperature(BROADLINK_LAN)).
   *
   * @param mixed $_valeurGenerique
   * @param int $_octet10 Octet 10 COURANT (recopié, bits 2-0 = oscillation verticale).
   * @param int $_octet12 Octet 12 COURANT (déjà porteur du marqueur d'écriture forcé).
   * @return array{0:int, 1:int} [octet10, octet12] patchés.
   * @throws smartclimException TYPE_INTERNE (non numérique, ou hors [CONSIGNE_MIN_ENCODABLE, CONSIGNE_MAX_ENCODABLE]).
   */
  private static function encoderConsigne($_valeurGenerique, $_octet10, $_octet12) {
    if (!is_scalar($_valeurGenerique) || !is_numeric($_valeurGenerique)) {
      throw new smartclimException('Trame HVAC : consigne non numérique pour l\'écriture', smartclimException::TYPE_INTERNE);
    }
    $arrondi = round(((float) $_valeurGenerique) * 2) / 2;
    $entier = (int) floor($arrondi);
    $demiDegre = (($arrondi - $entier) >= 0.5);
    if ($entier < self::CONSIGNE_MIN_ENCODABLE || $entier > self::CONSIGNE_MAX_ENCODABLE) {
      throw new smartclimException('Trame HVAC : consigne hors plage encodable [' . self::CONSIGNE_MIN_ENCODABLE . ', ' . self::CONSIGNE_MAX_ENCODABLE . ']', smartclimException::TYPE_INTERNE, self::CONTEXTE_CONSIGNE_HORS_PLAGE);
    }
    $octet10 = ($_octet10 & 0x07) | ((($entier - self::CONSIGNE_MIN_ENCODABLE) & 0x1F) << 3);
    $octet12 = $demiDegre ? ($_octet12 | 0x80) : ($_octet12 & 0x7F);
    return array($octet10, $octet12);
  }

  /**
   * Construit la charge HVAC d'ÉCRITURE (23 octets, § 2/5.1 de la spec technique UC03) :
   * recopie la trame de CONTRÔLE lue (le PIÈGE CENTRAL de cette UC — l'écriture porte un
   * état COMPLET, jamais un delta, sous peine d'éteindre l'appareil), écrase l'en-tête
   * d'ÉCRITURE (octets 0-9, JAMAIS celui de la réponse lue), force le marqueur (octet
   * 12, bits 3-0), puis ne patche QUE les concepts effectivement présents dans
   * $_ordre — tout le reste traverse INTACT.
   *
   * ⚠️ Bit turbo (octet 14, bit 6) DÉRIVÉ à CHAQUE commande de vitesse : posé pour
   * VITESSE_TURBO, EFFACÉ pour toute autre — jamais un recopiage partiel (§ 5.1). Le
   * bit mute voisin (bit 7), lui, N'EST PAS touché ici : il traverse avec le reste de
   * l'octet, recopié comme tout ce que ce concept ne couvre pas.
   *
   * @param string $_transport smartclimCapabilities::TRANSPORT_* — résout les codes
   *   propriétaires via smartclimCapabilities::versTransport().
   * @param string $_trameControleLue Trame de CONTRÔLE hexadécimale, déjà nettoyée par
   *   le transport appelant (>= 21 octets, couvre l'octet 20).
   * @param array $_ordre Map GÉNÉRIQUE concept => valeur générique (aucun code
   *   propriétaire en entrée).
   * @return string Charge HVAC d'écriture, 23 octets, hexadécimal minuscule.
   * @throws smartclimException TYPE_PROTOCOLE (base illisible ou trop courte)
   *                           | TYPE_INTERNE (concept sans entrée d'écriture, valeur
   *                             sans correspondance pour ce transport, consigne hors
   *                             plage encodable).
   */
  public static function encoderOrdre($_transport, $_trameControleLue, array $_ordre) {
    if (!is_string($_trameControleLue) || preg_match('/\A[0-9a-fA-F]*\z/', $_trameControleLue) !== 1 || strlen($_trameControleLue) < 42) {
      throw new smartclimException('Trame HVAC : base illisible ou trop courte pour construire un ordre d\'écriture', smartclimException::TYPE_PROTOCOLE, self::CONTEXTE_BASE_ILLISIBLE);
    }
    $base = hex2bin($_trameControleLue);
    if ($base === false) {
      throw new smartclimException('Trame HVAC : base illisible pour construire un ordre d\'écriture', smartclimException::TYPE_PROTOCOLE, self::CONTEXTE_BASE_ILLISIBLE);
    }

    $octets = array();
    for ($i = 0; $i < self::LONGUEUR_CHARGE_ECRITURE; $i++) {
      $octets[$i] = ($i < strlen($base)) ? ord($base[$i]) : 0;
    }

    $entete = self::enteteEcriture();
    for ($i = 0; $i < strlen($entete); $i++) {
      $octets[$i] = ord($entete[$i]);
    }

    // Marqueur FORCÉ (§ 2.2) : sans lui, l'appareil ignore silencieusement l'ordre.
    $octets[12] |= self::MARQUEUR_ECRITURE;

    $champs = self::champsEcriture();
    foreach ($_ordre as $concept => $valeurGenerique) {
      if ($concept === smartclimCapabilities::CONCEPT_TARGET_TEMP) {
        list($octets[10], $octets[12]) = self::encoderConsigne($valeurGenerique, $octets[10], $octets[12]);
        continue;
      }
      if (!isset($champs[$concept])) {
        throw new smartclimException('Trame HVAC : concept sans entrée d\'écriture (' . $concept . ')', smartclimException::TYPE_INTERNE);
      }
      $definition = $champs[$concept];
      if (!empty($definition['binaire'])) {
        // UC01 du domaine post-mvp/04-fonctions-avancees (§ 5.2 de sa spec technique) :
        // concept booléen de confort, ABSENT de smartclimCapabilities::tables() —
        // versTransport() y renverrait null systématiquement. Le code est simplement le
        // booléen générique, 0 ou 1.
        $code = $valeurGenerique ? 1 : 0;
      } else {
        $code = smartclimCapabilities::versTransport($_transport, $concept, $valeurGenerique);
        if ($code === null) {
          throw new smartclimException('Trame HVAC : valeur sans correspondance d\'écriture pour ce transport (' . $concept . '=' . $valeurGenerique . ')', smartclimException::TYPE_INTERNE);
        }
      }
      $octets[$definition['octet']] = ($octets[$definition['octet']] & ~$definition['masque']) | (($code << $definition['decalage']) & $definition['masque']);

      if ($concept === smartclimCapabilities::CONCEPT_FAN_SPEED) {
        if ($valeurGenerique === smartclimCapabilities::VITESSE_TURBO) {
          $octets[14] |= 0x40;
        } else {
          $octets[14] &= ~0x40;
        }
      }
    }

    $charge = '';
    for ($i = 0; $i < self::LONGUEUR_CHARGE_ECRITURE; $i++) {
      $charge .= chr($octets[$i] & 0xFF);
    }
    return strtolower(bin2hex($charge));
  }
}
