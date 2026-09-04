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
require_once __DIR__ . '/smartclimException.class.php';
// UC02 du domaine post-mvp/01-transport-broadlink-lan : etatAppareil()/capacitesAppareil()
// ci-dessous consomment smartclimCapabilities::TRANSPORT_BROADLINK_LAN/CONCEPT_ONLINE, et
// lireEtat() consomme le décodeur mutualisé smartclimFrame. require_once idempotent.
require_once __DIR__ . '/smartclimCapabilities.class.php';
require_once __DIR__ . '/smartclimFrame.class.php';

/**
 * Brique de transport "Broadlink LAN" (UC01 du domaine post-mvp/01-transport-broadlink-lan) :
 * découverte par diffusion UDP (port 80), sonde unicast d'une adresse connue/saisie, et
 * ouverture/mémorisation d'une session authentifiée par appareil. Aucune E/S base, aucun
 * eqLogic, aucun config:: ici (CLAUDE.md § Conventions — "aucun code propriétaire hors des
 * adaptateurs de transport") : cette classe ne connaît que le protocole Broadlink.
 *
 * Contrat protocolaire ENTIÈREMENT repris de mjg59/python-broadlink (branche "master",
 * consulté le 2026-08-27) — broadlink/device.py (scan(), auth(), send_packet()),
 * broadlink/protocol.py (Datetime.pack()), broadlink/const.py — retenu comme SOURCE DE
 * VÉRITÉ UNIQUE (D-POSTMVP0101-02) après contradiction entre deux sources dérivées.
 * Licence MIT — Copyright (c) 2014 Mike Ryan / Copyright (c) 2016 Matthew Garrett.
 *
 * ⚠️ Cette UC n'a PAS pu être recettée contre un appareil réel (le climatiseur de
 * l'utilisateur ignore le protocole Broadlink, D-POSTMVP0101-01) : le code est
 * théoriquement conforme à la source ci-dessus, instrumenté en 'debug' pour le premier
 * contact avec un appareil compatible, mais non vérifié en conditions réelles.
 *
 * Depuis l'UC02 de ce domaine, porte la LECTURE d'état (0x6A, § 5.2 de sa spec
 * technique), et depuis l'UC03 l'ÉCRITURE (appliquerOrdre(), même commande 0x6A) : la
 * charge d'écriture est OBTENUE PAR FUSION de la trame lue (recopie + patch des seuls
 * concepts visés, jamais un delta) — le piège central documenté au § 2 de la spec
 * technique UC03. Charges/offsets d'écriture RE-VÉRIFIÉS indépendamment contre le
 * contrat documenté (fparrav/homebridge-aux-cloud, MIT ; azadaydinli/ac_freedom SANS
 * LICENCE, non consulté pour l'écriture), jamais recopiés depuis une source dérivée.
 */
class smartclimBroadlinkLan {
  /*     * *************************Attributs****************************** */

  // Port et adresse de diffusion Broadlink (const.py : DEFAULT_PORT, DEFAULT_BCAST_ADDR).
  // Port 80 UNIQUEMENT : les ports secondaires 15001/2415 évoqués par des sources dérivées
  // ne sont pas utilisés par python-broadlink (D-POSTMVP0101-02).
  const PORT = 80;
  const ADRESSE_DIFFUSION = '255.255.255.255';

  // Repli quand la découverte n'a pas encore fourni de devtype (ex. IP saisie à la main
  // jamais vue en diffusion) : valeur arbitraire mais stable, cf. analyse interne.
  const DEVTYPE_REPLI = 0x272A;

  // Budgets et cadencement (secondes). FENETRE_DECOUVERTE = durée d'écoute de la diffusion ;
  // INTERVALLE_RENVOI = second envoi du hello, DIFFUSION UNIQUEMENT (diffuserParExtensionSockets()/
  // diffuserParFluxNatif()) — le délai de réémission du chemin UNICAST est calculé localement
  // par envoyerEtAttendre() (budget/3) ; TIMEOUT_ECHANGE = un aller-retour unicast (auth ou
  // sonde par IP connue) ; ATTENTE_VERROU = borné par le budget RESTANT de l'appelant
  // (D-POSTMVP0101-04), jamais une constante fixe appliquée telle quelle.
  const FENETRE_DECOUVERTE = 4;
  const INTERVALLE_RENVOI = 2;
  const TIMEOUT_ECHANGE = 2;
  const MAX_APPAREILS = 32;
  // Borne la MÉMOIRE pendant la collecte (avant dédoublonnage par MAC), là où
  // MAX_APPAREILS borne le RÉSULTAT normalisé : une machine du LAN qui inonde le socket
  // pendant FENETRE_DECOUVERTE ne doit pas faire grossir $reponses jusqu'à épuiser la
  // mémoire PHP.
  const MAX_REPONSES_BRUTES = 128;
  const ATTENTE_VERROU = 2;

  // Contrat pour UC02 (requete(), § 7 de la spec technique) : NON appliqué ici, UC01
  // n'émet aucune requête après le 0x65. Posée pour qu'UC02 n'invente pas une autre valeur.
  const DELAI_APRES_AUTH = 0.2;

  // Session LOCALE par appareil, chiffrée (contient la clé de session) — D-POSTMVP0101-07.
  const CLE_CACHE_SESSION = 'smartclim::session_lan::';
  const DUREE_SESSION = 1800;

  // Statuts renvoyés par ouvrirSession()/interroger() — jamais une exception (AC4).
  const STATUT_ETABLIE = 'etablie';
  const STATUT_REUTILISEE = 'reutilisee';
  const STATUT_REFUSEE = 'refusee';
  const STATUT_INJOIGNABLE = 'injoignable';
  const STATUT_VERROUILLE = 'verrouille';
  const STATUT_OCCUPE = 'occupe';
  // D-POSTMVP0101-05 : l'appareil qui répond à une IP saisie n'est PAS celui attendu —
  // jamais adopté, jamais de session ouverte avec lui.
  const STATUT_MAC_DIVERGENTE = 'mac_divergente';

  // UC02 de ce domaine (§ 3.2/5.2 de sa spec technique) : commande "requête" du protocole
  // et les DEUX charges magiques (16 octets = 1 bloc AES, chiffrées avec la clé de
  // session), constantes de PROTOCOLE re-vérifiées indépendamment contre le contrat
  // documenté (§ 3 de la spec technique), JAMAIS recopiées depuis un dépôt sans licence
  // (R8) — cf. l'attribution MIT en tête de fichier, qui ne couvre QUE python-broadlink.
  const COMMANDE_REQUETE = 0x6A;
  const CHARGE_ETAT = '0c00bb0006800000020011012b7e0000';
  const CHARGE_INFO = '0c00bb0006800000020021011b7e0000';
  const STATUT_ETAT_LU = 'etat_lu';
  const STATUT_ETAT_ILLISIBLE = 'etat_illisible';

  // UC03 du domaine post-mvp/01-transport-broadlink-lan (§ 5.2/7 de sa spec technique) :
  // secondes MINIMALES exigées AVANT d'émettre l'ordre d'écriture — un ordre non envoyé
  // vaut mieux qu'un ordre dont on ignore le sort (garde vérifiée APRÈS la lecture de
  // base, AVANT le requete() d'écriture).
  const RESERVE_ECRITURE = 3;

  // Contexte technique dédié (§ 4.2 de la spec technique UC03) : distingue, au sein
  // d'un même TYPE_RESEAU, un ordre EFFECTIVEMENT ÉMIS mais non confirmé (silence après
  // émission, rejeu compris) d'un échec survenu AVANT l'émission (session/adresse
  // indisponible) — smartclim::messageErreurLan() est l'UNIQUE endroit qui le traduit.
  const CONTEXTE_ECRITURE_NON_CONFIRMEE = 'ecriture_lan_non_confirmee';

  // Constantes de crypto (mjg59/python-broadlink, const.py) — ⚠️ l'octet d'index 3 de l'IV
  // vaut 0x99, PAS 0x09 (piège confirmé par la source canonique, D-POSTMVP0101-02).
  const INIT_KEY = '097628343fe99e23765c1513accf8b02';
  const INIT_VECT = '562e17996d093d28ddb3ba695a2e6f58';

  // Dossier des verrous flock() par appareil, mémoïsé une seule fois par processus
  // (D-POSTMVP0101-04, finding F3) : jeedom::getTmpFolder() exécute un chown au premier
  // appel, s'appuyer sur une mémoïsation interne du core serait un détail non contractuel.
  private static $dossierVerrous = null;

  // Compteur de paquet PAR PROCESSUS (UC02, § 5.3 de sa spec technique), jamais persisté
  // en cache : python-broadlink initialise ce compteur ALÉATOIREMENT (Device.__init__),
  // l'appareil ne contrôle donc aucune monotonie. Persister imposerait une écriture de
  // cache::set() par requête, qui RÉARMERAIT la TTL de 30 min de la session à chaque
  // lecture — faussant sa durée de vie réelle. Initialisé au compteur de la session (à
  // défaut aléatoire) au premier appel de requete(), incrémenté à chaque appel, remis à 0
  // après une ré-authentification.
  private static $compteurPaquet = null;

  /*     * ***********************Methode static*************************** */

  /**
   * Indique si au moins un chemin de DIFFUSION est envisageable sur cet hôte (sert au
   * message de dégradation d'AC1, § 8 de la spec technique — D-POSTMVP0101-03). La sonde
   * UNICAST (interroger()) ne dépend elle d'aucune extension et reste toujours possible.
   *
   * @return bool
   */
  public static function diffusionDisponible() {
    return function_exists('socket_create') || function_exists('stream_socket_server');
  }

  /**
   * Diffuse une requête de découverte Broadlink sur ADRESSE_DIFFUSION:PORT et écoute les
   * réponses pendant $_budget secondes (2 envois maxi, à t=0 et t≈INTERVALLE_RENVOI).
   * Dédoublonne PAR MAC, plafonne à MAX_APPAREILS. Un tableau VIDE est un SUCCÈS (AC1 —
   * aucun appareil Broadlink sur ce réseau n'est pas une erreur).
   *
   * @param int $_budget
   * @return array<int, array{mac:string, octets_mac:string, ip:string, port:int, type_appareil:string, nom:string, verrouille:bool, vu_le:int}>
   * @throws smartclimException TYPE_INTERNE si AUCUN chemin de diffusion n'est disponible
   *   sur cet hôte (ni ext-sockets, ni le repli flux natif) — le seul cas où cette méthode
   *   lève : l'appelant (smartclim::scannerReseauLocal()) décide alors du message de
   *   dégradation, jamais un niveau 'error' (AC4).
   */
  public static function decouvrir($_budget = self::FENETRE_DECOUVERTE) {
    $paquet = self::construireHello();

    $reponses = null;
    if (function_exists('socket_create')) {
      $reponses = self::diffuserParExtensionSockets($paquet, $_budget);
    }
    if ($reponses === null && function_exists('stream_socket_server')) {
      $reponses = self::diffuserParFluxNatif($paquet, $_budget);
    }
    if ($reponses === null) {
      throw new smartclimException('Broadlink LAN : aucun chemin de diffusion UDP disponible sur cet hôte', smartclimException::TYPE_INTERNE);
    }

    $appareils = array();
    $parMac = array();
    foreach ($reponses as $item) {
      $ligne = self::normaliserReponseDecouverte($item['reponse'], $item['ip']);
      if ($ligne === null || isset($parMac[$ligne['mac']])) {
        continue;
      }
      $parMac[$ligne['mac']] = true;
      $appareils[] = $ligne;
      if (count($appareils) >= self::MAX_APPAREILS) {
        break;
      }
    }
    return $appareils;
  }

  /**
   * Sonde une adresse UNICAST connue (adresse mémorisée d'un scan précédent) ou saisie à
   * la main (AC3, réseau segmenté où la diffusion n'arrive pas). Utilise le MÊME paquet
   * "hello" que la découverte, via stream_socket_client() : AUCUNE extension requise,
   * toujours disponible même si diffusionDisponible() est faux (D-POSTMVP0101-03).
   *
   * @param string $_ip
   * @param int $_budget
   * @return array{mac:string, octets_mac:string, ip:string, port:int, type_appareil:string, nom:string, verrouille:bool, vu_le:int}|null
   *   null = silence ou réponse inexploitable (jamais une exception, § 4.2).
   */
  public static function interroger($_ip, $_budget = self::TIMEOUT_ECHANGE) {
    $ip = self::normaliserIpV4($_ip);
    if ($ip === '') {
      log::add('smartclim', 'debug', 'Broadlink LAN : adresse non exploitable pour la sonde unicast');
      return null;
    }

    $paquet = self::construireHello();
    $errno = 0;
    $errstr = '';
    $flux = @stream_socket_client('udp://' . $ip . ':' . self::PORT, $errno, $errstr, $_budget, STREAM_CLIENT_CONNECT);
    if ($flux === false) {
      log::add('smartclim', 'debug', 'Broadlink LAN : connexion UDP impossible vers ' . $ip . ' (' . $errstr . ')');
      return null;
    }
    stream_set_blocking($flux, false);

    $reponse = self::envoyerEtAttendre($flux, $paquet, $_budget);
    fclose($flux);

    if ($reponse === null) {
      log::add('smartclim', 'debug', 'Broadlink LAN : aucune réponse de ' . $ip . ' à la sonde unicast');
      return null;
    }

    return self::normaliserReponseDecouverte($reponse, $ip);
  }

  /**
   * Point d'entrée UNIQUE de la session locale (AC2/AC5/AC6/AC7) : (1) refuse D'ABORD tout
   * appareil signalé "verrouillé" par sa propre réponse de découverte (STATUT_VERROUILLE) —
   * avant même de prendre un verrou local, pour ne pas en poser un inutilement ; (2) prend
   * ensuite un verrou flock() par MAC — sinon STATUT_OCCUPE, jamais d'attente illimitée ;
   * (3) session en cache VALIDE (empreinte identique) -> STATUT_REUTILISEE, ZÉRO paquet
   * réseau (c'est AC7) ; (4) sinon authentifier() -> STATUT_ETABLIE / REFUSEE / INJOIGNABLE.
   * Ne LÈVE JAMAIS (AC4) : tout échec devient un statut + un log 'debug'/'warning'.
   * Ne renvoie JAMAIS l'identifiant ni la clé de session — au plus leur longueur en log.
   *
   * @param array $_appareil Ligne normalisée (decouvrir()/interroger()).
   * @param float $_budget Budget de temps RESTANT à cette tentative, en secondes.
   * @return string Une des constantes STATUT_*.
   */
  public static function ouvrirSession(array $_appareil, $_budget) {
    $macNorm = isset($_appareil['mac']) ? $_appareil['mac'] : '';
    if (strlen($macNorm) !== 12) {
      return self::STATUT_INJOIGNABLE;
    }
    if (!empty($_appareil['verrouille'])) {
      log::add('smartclim', 'debug', 'Broadlink LAN : appareil verrouillé (' . $macNorm . '), authentification non tentée');
      return self::STATUT_VERROUILLE;
    }

    $debut = microtime(true);
    $attenteVerrou = max(0, min(self::ATTENTE_VERROU, $_budget));
    $ressource = self::verrou($macNorm, $attenteVerrou);
    if ($ressource === null) {
      return self::STATUT_OCCUPE;
    }

    try {
      $budgetRestant = max(1, $_budget - (microtime(true) - $debut));

      $session = self::sessionEnCache($_appareil);
      if ($session !== null) {
        log::add('smartclim', 'debug', 'Broadlink LAN : session en cache valide, réutilisée (' . $macNorm . ')');
        return self::STATUT_REUTILISEE;
      }
      // Absente OU invalide (empreinte divergente, forme inattendue) : purgerSession()
      // est un no-op idempotent si rien n'était en cache (§ 5.2 de la spec technique UC02).
      log::add('smartclim', 'debug', 'Broadlink LAN : aucune session en cache valide (' . $macNorm . '), nouvelle authentification');
      self::purgerSession($macNorm);

      self::authentifier($_appareil, $budgetRestant);
      return self::STATUT_ETABLIE;
    } catch (smartclimException $e) {
      if ($e->getType() === smartclimException::TYPE_AUTH) {
        log::add('smartclim', 'debug', 'Broadlink LAN : authentification refusée (' . $macNorm . ')');
        return self::STATUT_REFUSEE;
      }
      $niveau = ($e->getType() === smartclimException::TYPE_PROTOCOLE) ? 'warning' : 'debug';
      log::add('smartclim', $niveau, 'Broadlink LAN : session impossible (' . $macNorm . ') : ' . $e->getMessage());
      return self::STATUT_INJOIGNABLE;
    } catch (Throwable $t) {
      // Contrat "ne lève JAMAIS" (AC4, § 5.1) porté ici sur TOUT le corps de la méthode
      // (lecture cache/utils::decrypt/json_decode inclus, pas seulement l'appel à
      // authentifier()) : une anomalie interne inattendue (TypeError, échec
      // d'utils::decrypt...) ne doit pas s'échapper de ouvrirSession() — l'appelant
      // d'UC02 (requete()) doit pouvoir compter dessus sans le redoubler.
      log::add('smartclim', 'warning', 'Broadlink LAN : anomalie interne pendant l\'ouverture de session (' . $macNorm . ') : ' . $t->getMessage());
      return self::STATUT_INJOIGNABLE;
    } finally {
      self::libererVerrou($ressource);
    }
  }

  /**
   * Purge la session LAN en cache d'un appareil. Réservée au rejeu réactif d'UC02
   * (requete()) et à un usage explicite (aucun appelant dans UC01 hors ouvrirSession()).
   *
   * @param string $_macNorm
   */
  public static function purgerSession($_macNorm) {
    if ($_macNorm === '') {
      return;
    }
    cache::delete(self::CLE_CACHE_SESSION . $_macNorm);
  }

  /**
   * Lit l'état HVAC COMPLET d'un appareil (UC02, § 5.2 de la spec technique) — commandes
   * 0x6A "état" (CHARGE_ETAT) puis, si le budget le permet encore, "mesures/ambiante"
   * (CHARGE_INFO). NE LÈVE JAMAIS : toute anomalie devient un statut. Séquence imposée :
   * (1) ouvrirSession() — prend ET relâche son PROPRE verrou avant de rendre la main ;
   * (2) hors ETABLIE/REUTILISEE → retour immédiat, 'statut' = statut de session ;
   * (3) si ETABLIE → DELAI_APRES_AUTH (le contrat posé par UC01, non appliqué jusqu'ici,
   *     trouve enfin son usage) ; (4) verrou() + finally libererVerrou(), couvrant la
   *     lecture ET un éventuel rejeu ; (5) requete(CHARGE_ETAT) puis, si le budget le
   *     permet encore, requete(CHARGE_INFO) ; (6) statut = ETAT_LU si au moins un concept
   *     est décodable, sinon ETAT_ILLISIBLE (ex. appareil Broadlink non-climatiseur, code
   *     -4 "Command not supported", § 3.4).
   *
   * @param array $_appareil Ligne normalisée (decouvrir()/interroger()).
   * @param float $_budget Budget de temps RESTANT à cette tentative, en secondes.
   * @return array{session:string, statut:string, trame_controle:string, trame_longue:string}
   */
  public static function lireEtat(array $_appareil, $_budget) {
    $macNorm = isset($_appareil['mac']) ? $_appareil['mac'] : '';
    $debut = microtime(true);

    $statutSession = self::ouvrirSession($_appareil, $_budget);
    if ($statutSession !== self::STATUT_ETABLIE && $statutSession !== self::STATUT_REUTILISEE) {
      return array(
        'session' => $statutSession,
        'statut' => $statutSession,
        'trame_controle' => '',
        'trame_longue' => '',
      );
    }

    if ($statutSession === self::STATUT_ETABLIE) {
      usleep((int) (self::DELAI_APRES_AUTH * 1000000));
    }

    $trameControle = '';
    $trameLongue = '';
    $budgetRestant = max(1, $_budget - (microtime(true) - $debut));
    $ressource = self::verrou($macNorm, max(0, min(self::ATTENTE_VERROU, $budgetRestant)));
    if ($ressource === null) {
      return array(
        'session' => $statutSession,
        'statut' => self::STATUT_OCCUPE,
        'trame_controle' => '',
        'trame_longue' => '',
      );
    }

    try {
      try {
        $budgetRestant = max(1, $_budget - (microtime(true) - $debut));
        $trameControle = self::requete($_appareil, self::COMMANDE_REQUETE, hex2bin(self::CHARGE_ETAT), $budgetRestant);
      } catch (smartclimException $e) {
        $niveau = ($e->getType() === smartclimException::TYPE_PROTOCOLE) ? 'warning' : 'debug';
        log::add('smartclim', $niveau, 'Broadlink LAN : lecture d\'état HVAC en échec (' . $macNorm . ') : ' . $e->getMessage());
      }

      $budgetRestant = $_budget - (microtime(true) - $debut);
      if ($budgetRestant >= 1) {
        try {
          $trameLongue = self::requete($_appareil, self::COMMANDE_REQUETE, hex2bin(self::CHARGE_INFO), $budgetRestant);
        } catch (smartclimException $e) {
          $niveau = ($e->getType() === smartclimException::TYPE_PROTOCOLE) ? 'warning' : 'debug';
          log::add('smartclim', $niveau, 'Broadlink LAN : lecture de mesures HVAC en échec (' . $macNorm . ') : ' . $e->getMessage());
        }
      }
    } catch (Throwable $t) {
      // Même discipline que ouvrirSession() (§ 5.1) : une anomalie interne INATTENDUE ne
      // doit jamais s'échapper de lireEtat() — l'appelant (smartclim::scannerReseauLocal())
      // doit pouvoir compter dessus sans le redoubler.
      log::add('smartclim', 'warning', 'Broadlink LAN : anomalie interne pendant la lecture d\'état (' . $macNorm . ') : ' . $t->getMessage());
    } finally {
      self::libererVerrou($ressource);
    }

    // UC04 du domaine post-mvp/01-transport-broadlink-lan (§ 5.6 de sa spec technique) :
    // journalisation NON BLOQUANTE — statut et retour restent déterminés par
    // conceptsLisibles() ci-dessous, jamais par ce préfixe. Instrumentation de recette
    // du seul acte irréversible d'UC04 (création d'équipement depuis le LAN), en
    // l'absence de matériel Broadlink pour confirmer R3.
    if ($trameControle !== '' && !smartclimFrame::estTrameHvac($trameControle)) {
      log::add('smartclim', 'warning', 'Broadlink LAN : trame de contrôle sans le préfixe HVAC attendu (' . $macNorm . ') : ' . strtoupper(substr($trameControle, 0, 8)));
    }

    $lisibles = smartclimFrame::conceptsLisibles($trameControle, $trameLongue);
    $statut = !empty($lisibles) ? self::STATUT_ETAT_LU : self::STATUT_ETAT_ILLISIBLE;

    return array(
      'session' => $statutSession,
      'statut' => $statut,
      'trame_controle' => $trameControle,
      'trame_longue' => $trameLongue,
    );
  }

  /**
   * État GÉNÉRIQUE décodé par CE transport, à partir d'une lecture lireEtat() (UC02, §
   * 5.2 de la spec technique) : DÉLÈGUE au décodeur MUTUALISÉ smartclimFrame::decoderEtat()
   * — c'est ce qui rend AC3 (« état identique LAN et cloud ») vrai PAR CONSTRUCTION.
   * ⚠️ 'online' n'est JAMAIS false ici : un LAN muet ne prouve pas qu'un appareil est hors
   * ligne (VLAN, pare-feu, diffusion filtrée) — seul le cloud sait le dire.
   *
   * @param array $_lecture Renvoyé par lireEtat() ci-dessus.
   * @return array{online:bool, power?:int, mode?:string, target_temp?:float, ambient_temp?:int, fan_speed?:string, source:string}
   */
  public static function etatAppareil(array $_lecture) {
    $trameControle = isset($_lecture['trame_controle']) && is_string($_lecture['trame_controle']) ? $_lecture['trame_controle'] : '';
    $trameLongue = isset($_lecture['trame_longue']) && is_string($_lecture['trame_longue']) ? $_lecture['trame_longue'] : '';

    $etat = array(
      'online' => true,
      'source' => smartclimCapabilities::TRANSPORT_BROADLINK_LAN,
    );

    return $etat + smartclimFrame::decoderEtat(smartclimCapabilities::TRANSPORT_BROADLINK_LAN, $trameControle, $trameLongue);
  }

  /**
   * Profil de capacités GÉNÉRIQUE de CE transport, à partir d'une lecture lireEtat() (UC02,
   * § 5.2 de la spec technique). 'modes' et 'vitesses' VIDES à dessein (R1, § 9 de la spec
   * technique) : le LAN n'a AUCUN équivalent de feature.coolType (le champ déclaré du
   * cloud qui permet d'EXCLURE un mode) — il ne peut donc rien exclure. Publier ici le
   * catalogue COMPLET du transport réintroduirait, via l'UNION de
   * smartclim::appliquerCapacites(), un mode déjà écarté par le cloud (ex. Chauffage sur
   * une unité froid-seul) dès qu'un scan LAN tourne sans qu'un scan cloud repasse
   * derrière. UC02 étant en LECTURE SEULE, aucun catalogue d'action n'est nécessaire ici.
   *
   * @param array $_lecture Renvoyé par lireEtat() ci-dessus.
   * @return array{concepts:array<int,string>, modes:array<int,string>, vitesses:array<int,string>, modes_exclus:array<int,string>, temperature:array{min:int,max:int,pas:float}, source:string}
   */
  public static function capacitesAppareil(array $_lecture) {
    $trameControle = isset($_lecture['trame_controle']) && is_string($_lecture['trame_controle']) ? $_lecture['trame_controle'] : '';
    $trameLongue = isset($_lecture['trame_longue']) && is_string($_lecture['trame_longue']) ? $_lecture['trame_longue'] : '';

    $concepts = array_merge(array(smartclimCapabilities::CONCEPT_ONLINE), smartclimFrame::conceptsLisibles($trameControle, $trameLongue));

    return array(
      'concepts' => $concepts,
      'modes' => array(),
      'vitesses' => array(),
      'modes_exclus' => array(),
      'temperature' => smartclimCapabilities::bornesParDefaut(),
      'source' => smartclimCapabilities::TRANSPORT_BROADLINK_LAN,
    );
  }

  /**
   * Écrit un ordre GÉNÉRIQUE sur cet appareil (UC03 du domaine
   * post-mvp/01-transport-broadlink-lan, § 5.2 de sa spec technique) : relit la trame de
   * CONTRÔLE courante, la fait fusionner par smartclimFrame::encoderOrdre() (recopie +
   * patch des SEULS concepts visés — le piège central de cette UC, § 2.2), puis émet la
   * charge encapsulée. ⚠️ LÈVE, contrairement à ouvrirSession()/lireEtat() : chemin
   * INTERACTIF, un ordre perdu en silence est précisément ce que la spec fonctionnelle
   * interdit.
   *
   * Séquence : (1) ouvrirSession() — prend ET relâche son PROPRE verrou ; (2) hors
   * ETABLIE/REUTILISEE → smartclimException typée (AUTH ou RESEAU selon le statut) ;
   * (3) si ETABLIE → DELAI_APRES_AUTH ; (4) verrou() + finally libererVerrou(), COUVRANT
   * LA LECTURE DE BASE ET L'ÉCRITURE — sinon un autre processus s'intercale entre les
   * deux et notre écriture réécrit un état déjà périmé ; (5) requete(CHARGE_ETAT) →
   * trame de base ; (6) smartclimFrame::encoderOrdre() ; (7) garde RESERVE_ECRITURE
   * AVANT d'émettre ; (8) requete() d'écriture, réponse journalisée en debug.
   *
   * ⚠️ Un rejeu de requete() sur l'ÉCRITURE est SANS DANGER (contrairement à un ordre
   * dupliqué sur une API stateful) : la trame porte un état ABSOLU et complet, réémettre
   * le même ordre est idempotent. Ne PAS « corriger » ce rejeu (§ 5.2/R6).
   *
   * @param array $_appareil Ligne normalisée (decouvrir()/interroger()).
   * @param array $_ordre Map GÉNÉRIQUE concept => valeur générique (aucun code
   *   propriétaire en entrée).
   * @param float $_budget Budget de temps RESTANT à cette tentative, en secondes.
   * @return array Ordre RÉELLEMENT appliqué (consigne après quantification) — MÊME
   *   contrat de sortie que smartclimAuxHomeApi::appliquerOrdre() : c'est cette valeur,
   *   jamais celle demandée, que l'appelant doit pousser en état optimiste.
   * @throws smartclimException Message TECHNIQUE, jamais affiché tel quel.
   */
  public static function appliquerOrdre(array $_appareil, array $_ordre, $_budget) {
    $macNorm = isset($_appareil['mac']) ? $_appareil['mac'] : '';
    $debut = microtime(true);

    $statutSession = self::ouvrirSession($_appareil, $_budget);
    if ($statutSession !== self::STATUT_ETABLIE && $statutSession !== self::STATUT_REUTILISEE) {
      $type = ($statutSession === self::STATUT_REFUSEE) ? smartclimException::TYPE_AUTH : smartclimException::TYPE_RESEAU;
      throw new smartclimException('Broadlink LAN : session indisponible pour l\'écriture (' . $macNorm . '), statut ' . $statutSession, $type);
    }

    if ($statutSession === self::STATUT_ETABLIE) {
      usleep((int) (self::DELAI_APRES_AUTH * 1000000));
    }

    $budgetRestant = max(1, $_budget - (microtime(true) - $debut));
    $ressource = self::verrou($macNorm, max(0, min(self::ATTENTE_VERROU, $budgetRestant)));
    if ($ressource === null) {
      throw new smartclimException('Broadlink LAN : verrou indisponible pour l\'écriture (' . $macNorm . ')', smartclimException::TYPE_RESEAU);
    }

    try {
      $budgetRestant = max(1, $_budget - (microtime(true) - $debut));
      $trameBase = self::requete($_appareil, self::COMMANDE_REQUETE, hex2bin(self::CHARGE_ETAT), $budgetRestant);

      $chargeHvac = smartclimFrame::encoderOrdre(smartclimCapabilities::TRANSPORT_BROADLINK_LAN, $trameBase, $_ordre);

      $budgetRestant = $_budget - (microtime(true) - $debut);
      if ($budgetRestant < self::RESERVE_ECRITURE) {
        throw new smartclimException('Broadlink LAN : budget insuffisant pour émettre l\'ordre (' . $macNorm . ')', smartclimException::TYPE_RESEAU);
      }

      $chargeEncapsulee = self::encapsulerChargeHvac($chargeHvac);
      try {
        $reponse = self::requete($_appareil, self::COMMANDE_REQUETE, $chargeEncapsulee, $budgetRestant);
      } catch (smartclimException $e) {
        // Contexte DÉDIÉ (§ 4.2) : l'ordre a été EFFECTIVEMENT émis, seule sa
        // confirmation manque — distinct d'un échec survenu AVANT l'émission.
        if ($e->getType() === smartclimException::TYPE_RESEAU) {
          throw new smartclimException($e->getMessage(), $e->getType(), self::CONTEXTE_ECRITURE_NON_CONFIRMEE);
        }
        throw $e;
      }
      log::add('smartclim', 'debug', 'Broadlink LAN : ordre écrit (' . $macNorm . '), réponse 1er octet=0x' . (strlen($reponse) >= 2 ? substr($reponse, 0, 2) : '--') . ', longueur=' . (strlen($reponse) / 2));
    } finally {
      self::libererVerrou($ressource);
    }

    return self::ordreAppliqueGenerique($_ordre);
  }

  /**
   * Ordre GÉNÉRIQUE RÉELLEMENT appliqué, après quantification de la consigne (UC03, §
   * 5.2 de la spec technique) : même échelle d'écriture que
   * smartclimFrame::encoderConsigne() (arrondi au 0,5 °C le plus proche), lue via
   * smartclimCapabilities::echelleTemperature() — jamais une seconde constante. Mode et
   * vitesse ne sont PAS quantifiés : encoderOrdre() les a déjà validés (sinon levé),
   * la valeur générique demandée EST la valeur appliquée.
   *
   * @param array $_ordre Map GÉNÉRIQUE, DÉJÀ validée par un encoderOrdre() réussi.
   * @return array
   */
  private static function ordreAppliqueGenerique(array $_ordre) {
    $echelle = smartclimCapabilities::echelleTemperature(smartclimCapabilities::TRANSPORT_BROADLINK_LAN);
    $pas = (isset($echelle['pas_ecriture']) && $echelle['pas_ecriture'] > 0) ? $echelle['pas_ecriture'] : 0.5;

    $applique = array();
    foreach ($_ordre as $concept => $valeur) {
      if ($concept === smartclimCapabilities::CONCEPT_TARGET_TEMP && is_numeric($valeur)) {
        $applique[$concept] = round(((float) $valeur) / $pas) * $pas;
      } else {
        $applique[$concept] = $valeur;
      }
    }
    return $applique;
  }

  /**
   * Encapsule une charge HVAC d'ÉCRITURE (23 octets hexadécimaux) dans le format
   * transporté par 0x6A (§ 2.3 de la spec technique UC03) : [longueur uint16
   * LE][charge][somme 16 bits BE][remplissage nul], dans un tampon de 32 octets.
   * `longueur = strlen(charge) + 2`. ⚠️ Somme calculée par sommeChargeHvac() (§ 2.4),
   * DISTINCTE de sommeControle() (0xBEAF, paquet 0x38) — jamais fusionnées.
   *
   * @param string $_chargeHvacHex 23 octets, hexadécimal.
   * @return string 32 octets bruts.
   */
  private static function encapsulerChargeHvac($_chargeHvacHex) {
    $chargeHvac = hex2bin($_chargeHvacHex);
    $longueur = strlen($chargeHvac) + 2;
    $somme = self::sommeChargeHvac($chargeHvac);
    $tampon = self::packerUint16LE($longueur) . $chargeHvac . chr(($somme >> 8) & 0xFF) . chr($somme & 0xFF);
    return str_pad($tampon, 32, "\x00");
  }

  /**
   * Somme de contrôle « type Internet » de la charge HVAC (§ 2.4 de la spec technique
   * UC03), lue dans fparrav/homebridge-aux-cloud (MIT) `commandPayloadChecksum` :
   * mots BIG-endian de 16 bits, repli des retenues, complément à un. La longueur de la
   * charge HVAC (23) est IMPAIRE : le dernier octet est traité comme poids FORT d'un
   * mot complété par 0x00 (vérifié par les 4 vecteurs de la spec technique, § 2.4).
   * ⚠️ DISTINCTE de sommeControle() (0xBEAF, paquet 0x38) — ne JAMAIS fusionner les deux.
   *
   * @param string $_octets
   * @return int
   */
  private static function sommeChargeHvac($_octets) {
    $longueur = strlen($_octets);
    $somme = 0;
    for ($i = 0; $i < $longueur; $i += 2) {
      $hi = ord($_octets[$i]);
      $lo = ($i + 1 < $longueur) ? ord($_octets[$i + 1]) : 0;
      $somme += ($hi << 8) + $lo;
    }
    while (($somme >> 16) !== 0) {
      $somme = ($somme & 0xFFFF) + ($somme >> 16);
    }
    return (0xFFFF ^ $somme) & 0xFFFF;
  }

  /**
   * Émet UNE requête 0x6A (§ 3.2/5.2 de la spec technique UC02) et renvoie la charge HVAC
   * NUE (hexadécimal minuscule) contenue dans la réponse déchiffrée. SIGNATURE FIGÉE par
   * le § 7 de la spec technique UC01. Point UNIQUE portant la réauthentification
   * RÉACTIVE : UN SEUL rejeu par appel (booléen local, JAMAIS de récursion — même
   * convention que le re-login réactif d'UC02 du MVP,
   * smartclimAuxHomeApi::listerAppareils()). Déclencheurs de rejeu : silence dans le
   * budget, ou code appareil -7 / -4012 / -1 (§ 3.4/7). Avant rejeu : purgerSession() puis
   * authentifier() (qui repose INIT_KEY, identifiant nul et compteur à zéro), puis
   * DELAI_APRES_AUTH.
   *
   * ⚠️ Appelle authentifier() et JAMAIS ouvrirSession() : le verrou est déjà tenu par
   * lireEtat(), et flock() N'EST PAS RÉENTRANT entre deux descripteurs du même processus.
   *
   * @param array $_appareil
   * @param int $_commande
   * @param string $_charge Un ou plusieurs blocs AES (multiple de 16 octets), NON
   *   chiffrés — lireEtat() y passe TOUJOURS 16 octets (CHARGE_ETAT/CHARGE_INFO),
   *   appliquerOrdre() (UC03 de ce domaine) y passe 32 octets (charge HVAC d'écriture
   *   encapsulée, § 2.3 de sa spec technique) : construirePaquet() complète déjà à un
   *   multiple de 16, aucune contrainte de longueur FIXE ici.
   * @param float $_budget
   * @return string Charge HVAC nue, en hexadécimal minuscule.
   * @throws smartclimException
   */
  private static function requete(array $_appareil, $_commande, $_charge, $_budget) {
    $macNorm = isset($_appareil['mac']) ? $_appareil['mac'] : '';
    $debut = microtime(true);
    $rejoue = false;

    while (true) {
      $session = self::sessionEnCache($_appareil);
      if ($session === null) {
        throw new smartclimException('Broadlink LAN : aucune session en cache disponible pour la requête', smartclimException::TYPE_INTERNE);
      }
      if (!is_string($session['cle']) || preg_match('/\A[0-9a-f]{32}\z/', $session['cle']) !== 1) {
        throw new smartclimException('Broadlink LAN : clé de session illisible', smartclimException::TYPE_INTERNE);
      }
      $cle = hex2bin($session['cle']);

      if (self::$compteurPaquet === null) {
        self::$compteurPaquet = (isset($session['compteur']) && is_numeric($session['compteur'])) ? (int) $session['compteur'] : random_int(0, 0xFFFF);
      }
      $octetsMac = (isset($session['octets_mac']) && is_string($session['octets_mac'])) ? self::hexVersOctets($session['octets_mac']) : self::hexVersOctets(isset($_appareil['octets_mac']) ? $_appareil['octets_mac'] : '');

      $sessionPourPaquet = array(
        'compteur' => self::$compteurPaquet,
        'octets_mac' => $octetsMac,
        'id' => isset($session['id']) ? (int) $session['id'] : 0,
        'cle' => $cle,
        'devtype' => isset($session['devtype']) ? (int) $session['devtype'] : self::devtypeDepuisAppareil($_appareil),
      );
      $paquet = self::construirePaquet($_commande, $_charge, $sessionPourPaquet);
      // Même formule que construirePaquet() (§ 1.3) : compteur = ((base+1)|0x8000)&0xFFFF.
      // Duplication ASSUMÉE d'une identité arithmétique (pas de la construction du
      // paquet) : c'est le seul moyen de faire progresser l'état PAR PROCESSUS sans que
      // construirePaquet() ait à connaître ce compteur (§ 5.3 de la spec technique).
      self::$compteurPaquet = ((self::$compteurPaquet + 1) | 0x8000) & 0xFFFF;

      $budgetRestant = max(1, $_budget - (microtime(true) - $debut));
      $timeout = max(1, min(self::TIMEOUT_ECHANGE, $budgetRestant));
      $ip = (isset($session['ip']) && is_string($session['ip']) && $session['ip'] !== '') ? $session['ip'] : (isset($_appareil['ip']) ? $_appareil['ip'] : '');

      // ⚠️ SANS $_octetsMacAttendus (§ 7.1 de la spec technique) : python-broadlink ne
      // vérifie pas l'écho de MAC sur 0x6A, un contrôle BLOQUANT ici serait un déni de
      // service auto-infligé sur un chemin non recettable. L'anti-mélange est déjà assuré
      // par le socket UDP CONNECTÉ (filtrage noyau par adresse source).
      $reponse = self::echanger($ip, $paquet, $timeout);

      if ($reponse === null) {
        if ($rejoue) {
          throw new smartclimException('Broadlink LAN : aucune réponse à la requête (après rejeu)', smartclimException::TYPE_RESEAU);
        }
        $rejoue = true;
        self::rejouerAuthentification($_appareil, $_budget - (microtime(true) - $debut));
        continue;
      }

      $code = self::codeErreur($reponse);
      if ($code !== 0) {
        log::add('smartclim', 'debug', 'Broadlink LAN : code d\'erreur appareil observé sur la requête (' . $macNorm . ') : ' . $code);
        if (!$rejoue && in_array($code, array(-7, -4012, -1), true)) {
          $rejoue = true;
          self::rejouerAuthentification($_appareil, $_budget - (microtime(true) - $debut));
          continue;
        }
        // classerCodeAppareil() lève TOUJOURS (§ classement) : propage TYPE_RESEAU pour
        // -3/-4000, TYPE_AUTH pour -1/-2/-7/-4012 hors rejeu, TYPE_PROTOCOLE sinon (dont
        // -4 "Command not supported" : réponse attendue d'un appareil Broadlink qui n'est
        // pas un climatiseur AUX, § 3.4).
        self::classerCodeAppareil($code);
      }

      // Instrumentation § 10 (non bloquante) : commande écho, longueur déchiffrée, 1er
      // octet de charge HVAC (attendu 0xBB), écho de MAC — accès garanti par la longueur
      // minimale déjà vérifiée par echanger() (>= 0x38).
      $commandeEcho = ord($reponse[0x26]) | (ord($reponse[0x27]) << 8);
      $macEcho = substr($reponse, 0x2A, 6);
      if ($macEcho !== $octetsMac) {
        log::add('smartclim', 'debug', 'Broadlink LAN : écho de MAC divergent sur la requête (' . $macNorm . '), non bloquant');
      }
      log::add('smartclim', 'debug', 'Broadlink LAN : commande écho observée sur la requête (' . $macNorm . ') : 0x' . dechex($commandeEcho));

      $chargeChiffree = substr($reponse, 0x38);
      if (strlen($chargeChiffree) === 0 || strlen($chargeChiffree) % 16 !== 0) {
        throw new smartclimException('Broadlink LAN : charge chiffrée de la réponse de longueur non multiple de 16', smartclimException::TYPE_PROTOCOLE);
      }

      $chargeClaire = self::dechiffrer($chargeChiffree, $cle);
      if ($chargeClaire === false || strlen($chargeClaire) < 4) {
        throw new smartclimException('Broadlink LAN : réponse de requête illisible ou tronquée', smartclimException::TYPE_PROTOCOLE);
      }

      // Somme de la charge (0x34-0x35, § 3.2/7) : NON bloquante, algorithme prouvé
      // arithmétiquement mais jamais observé sur du matériel réel.
      $sommeRecue = ord($reponse[0x34]) | (ord($reponse[0x35]) << 8);
      $sommeCalculee = self::sommeControle($chargeClaire);
      if ($sommeCalculee !== $sommeRecue) {
        log::add('smartclim', 'debug', 'Broadlink LAN : somme de charge divergente sur la requête (' . $macNorm . '), non bloquant');
      }

      $longueur = ord($chargeClaire[0]) | (ord($chargeClaire[1]) << 8);
      if ($longueur < 4 || (2 + $longueur) > strlen($chargeClaire)) {
        throw new smartclimException('Broadlink LAN : longueur de charge HVAC incohérente dans la réponse', smartclimException::TYPE_PROTOCOLE);
      }

      $chargeHvac = substr($chargeClaire, 2, $longueur - 2);
      log::add('smartclim', 'debug', 'Broadlink LAN : charge HVAC reçue (' . $macNorm . '), 1er octet=0x' . (strlen($chargeHvac) > 0 ? dechex(ord($chargeHvac[0])) : '--') . ', longueur=' . strlen($chargeHvac));

      return strtolower(bin2hex($chargeHvac));
    }
  }

  /**
   * Ré-authentifie un appareil AVANT un rejeu (requete()) : purge la session en cache,
   * authentifie de nouveau (authentifier() repose INIT_KEY, identifiant nul et compteur à
   * zéro), remet le compteur de paquet PAR PROCESSUS à zéro (§ 5.3 de la spec technique),
   * puis applique DELAI_APRES_AUTH. Propage toute smartclimException d'authentifier() —
   * requete() ne la rattrape pas, elle fait partie de son propre contrat "lève".
   *
   * @param array $_appareil
   * @param float $_budget
   * @throws smartclimException
   */
  private static function rejouerAuthentification(array $_appareil, $_budget) {
    $macNorm = isset($_appareil['mac']) ? $_appareil['mac'] : '';
    self::purgerSession($macNorm);
    self::authentifier($_appareil, max(1, $_budget));
    self::$compteurPaquet = 0;
    usleep((int) (self::DELAI_APRES_AUTH * 1000000));
  }

  /**
   * Relit et VALIDE la session LAN en cache d'un appareil, SANS PRENDRE DE VERROU
   * (extraction du bloc de relecture/validation historiquement dans ouvrirSession(), §
   * 5.2 de la spec technique UC02) : déchiffrement, forme du tableau, 'cle' en 32
   * caractères hexadécimaux, empreinte. ouvrirSession() l'appelle SOUS SON PROPRE
   * VERROU ; requete() l'appelle SOUS LE VERROU pris par lireEtat() (jamais son propre
   * verrou, flock() n'étant pas réentrant, § 5.2).
   *
   * 🚫 PRIVATE, et sa valeur de retour NE SORT JAMAIS de cette classe — elle porte la clé
   * de session (au plus sa longueur peut être journalisée ailleurs, jamais son contenu).
   *
   * @param array $_appareil
   * @return array|null
   */
  private static function sessionEnCache(array $_appareil) {
    $macNorm = isset($_appareil['mac']) ? $_appareil['mac'] : '';
    if ($macNorm === '') {
      return null;
    }
    $brut = cache::byKey(self::CLE_CACHE_SESSION . $macNorm)->getValue(null);
    if ($brut === null) {
      return null;
    }
    $dechiffre = utils::decrypt($brut);
    if (!is_string($dechiffre) || $dechiffre === '') {
      return null;
    }
    $session = json_decode($dechiffre, true);
    if (
      !is_array($session)
      || !isset($session['id'], $session['cle'], $session['empreinte'])
      || !is_string($session['cle']) || preg_match('/\A[0-9a-f]{32}\z/', $session['cle']) !== 1
      || $session['empreinte'] !== self::empreinteSession($_appareil)
    ) {
      return null;
    }
    return $session;
  }

  /**
   * Authentifie l'appareil (paquet 0x65, § 1.5) et écrit la session en cache, chiffrée
   * (D-POSTMVP0101-07). Lève une smartclimException typée sur tout échec — rattrapée par
   * ouvrirSession(), jamais propagée telle quelle plus haut (mais PROPAGÉE telle quelle
   * par rejouerAuthentification()/requete(), UC02, § 5.2 de sa spec technique).
   *
   * @param array $_appareil
   * @param float $_budget
   * @return array Le tableau de session VENANT D'ÊTRE écrit en cache (UC02 : requete(),
   *   via rejouerAuthentification(), en a besoin APRÈS un rejeu ; ouvrirSession() ignore
   *   ce retour, comportement inchangé).
   * @throws smartclimException
   */
  private static function authentifier(array $_appareil, $_budget) {
    // Charge utile 0x50 octets, verbatim auth() de la source canonique (§ 1.5).
    $charge = str_repeat("\x00", 0x50);
    $charge = substr_replace($charge, str_repeat("\x31", 16), 0x04, 16);
    $charge[0x1E] = "\x01";
    $charge[0x2D] = "\x01";
    $charge = substr_replace($charge, 'Test 1', 0x30, 6);

    $octetsMac = self::hexVersOctets(isset($_appareil['octets_mac']) ? $_appareil['octets_mac'] : '');
    $devtype = self::devtypeDepuisAppareil($_appareil);

    $session = array(
      'compteur' => 0,
      'octets_mac' => $octetsMac,
      'id' => 0,
      'cle' => hex2bin(self::INIT_KEY),
      'devtype' => $devtype,
    );

    $paquet = self::construirePaquet(0x65, $charge, $session);

    // Plafonné par TIMEOUT_ECHANGE (§ 6 de la spec technique) : $_budget peut porter le
    // budget global RESTANT du scan (jusqu'à ~18 s, transmis par ouvrirSession()) — sans ce
    // plafond, un appareil muet sur ce seul échange monopoliserait tout BUDGET_LAN et les
    // appareils suivants basculeraient en "non sondés".
    $timeout = max(1, min(self::TIMEOUT_ECHANGE, $_budget));

    $ip = isset($_appareil['ip']) ? $_appareil['ip'] : '';
    $reponse = self::echanger($ip, $paquet, $timeout, $octetsMac);
    if ($reponse === null) {
      throw new smartclimException('Broadlink LAN : aucune réponse à la requête d\'authentification', smartclimException::TYPE_RESEAU);
    }

    $code = self::codeErreur($reponse);
    log::add('smartclim', 'debug', 'Broadlink LAN : code d\'erreur appareil observé sur l\'authentification : ' . $code);
    // § 10 / D-POSTMVP0101-01 : au premier contact avec du matériel réellement
    // compatible, ce sont les valeurs 0x26-0x27 de la réponse (commande écho) qui
    // serviront de levier de diagnostic — accès garanti par la vérification de longueur
    // minimale déjà faite par echanger() (>= 0x38).
    $commandeEcho = ord($reponse[0x26]) | (ord($reponse[0x27]) << 8);
    log::add('smartclim', 'debug', 'Broadlink LAN : commande écho observée en réponse d\'authentification : 0x' . dechex($commandeEcho));
    if ($code !== 0) {
      self::classerCodeAppareil($code);
    }

    $chargeChiffree = substr($reponse, 0x38);
    if (strlen($chargeChiffree) === 0 || strlen($chargeChiffree) % 16 !== 0) {
      throw new smartclimException('Broadlink LAN : charge chiffrée de la réponse d\'authentification de longueur non multiple de 16', smartclimException::TYPE_PROTOCOLE);
    }

    $chargeClaire = self::dechiffrer($chargeChiffree, hex2bin(self::INIT_KEY));
    if ($chargeClaire === false || strlen($chargeClaire) < 0x14) {
      throw new smartclimException('Broadlink LAN : réponse d\'authentification illisible ou tronquée', smartclimException::TYPE_PROTOCOLE);
    }

    $identifiantSession = self::octetsVersUint32LE(substr($chargeClaire, 0x00, 4));
    $cleSession = substr($chargeClaire, 0x04, 16);
    if (strlen($cleSession) !== 16) {
      throw new smartclimException('Broadlink LAN : clé de session de longueur inattendue', smartclimException::TYPE_PROTOCOLE);
    }

    // Jamais l'identifiant ni la clé de session en log — au plus leur longueur (§ 4.4).
    log::add('smartclim', 'debug', 'Broadlink LAN : authentification réussie (longueur clé=' . strlen($cleSession) . ')');

    $macNorm = self::macImprimableDepuisOctets($octetsMac);
    // 'cle' est stockée en HEXADÉCIMAL (32 caractères) : json_encode() renvoie false sur
    // une chaîne binaire non-UTF8, ce qui casserait silencieusement le cache de session
    // (cf. ouvrirSession(), qui valide désormais la FORME hexadécimale). L'appelant qui a
    // besoin de la clé brute (requete() d'UC02, § 7 de la spec technique) doit la repasser
    // par hex2bin().
    $donneesSession = array(
      'id' => $identifiantSession,
      'cle' => bin2hex($cleSession),
      'compteur' => 0,
      'ip' => $ip,
      'port' => isset($_appareil['port']) ? (int) $_appareil['port'] : self::PORT,
      'devtype' => $devtype,
      'octets_mac' => bin2hex($octetsMac),
      'cree_le' => time(),
      'empreinte' => self::empreinteSession($_appareil),
    );
    cache::set(self::CLE_CACHE_SESSION . $macNorm, utils::encrypt(json_encode($donneesSession)), self::DUREE_SESSION);

    return $donneesSession;
  }

  /**
   * Envoie un paquet UDP sur un flux déjà connecté (non bloquant), jusqu'à 2 envois
   * (t=0 et t≈budget/3), et attend une réponse jusqu'au budget imparti.
   *
   * @param resource $_flux
   * @param string $_paquet
   * @param float $_budget
   * @return string|null
   */
  private static function envoyerEtAttendre($_flux, $_paquet, $_budget) {
    $reponse = null;
    $debut = microtime(true);
    $numeroEnvoi = 0;
    $prochainEnvoi = 0.0;
    while ((microtime(true) - $debut) < $_budget && $reponse === null) {
      $ecoule = microtime(true) - $debut;
      if ($numeroEnvoi < 2 && $ecoule >= $prochainEnvoi) {
        @fwrite($_flux, $_paquet);
        $numeroEnvoi++;
        $prochainEnvoi = $ecoule + max(0.3, $_budget / 3);
      }
      $lecture = array($_flux);
      $ecriture = null;
      $exception = null;
      $selection = @stream_select($lecture, $ecriture, $exception, 0, 100000);
      if ($selection > 0) {
        $tampon = @fread($_flux, 4096);
        if ($tampon !== false && $tampon !== '') {
          $reponse = $tampon;
        }
      }
    }
    return $reponse;
  }

  /**
   * Échange unicast connecté (utilisé pour la requête d'authentification, § 1.3) :
   * vérifie longueur minimale, somme de contrôle du paquet ET écho de la MAC en
   * 0x2A-0x2F — ces deux dernières vérifications sont BLOQUANTES sur une réponse
   * d'authentification (§ 4.2/4.3, anti-mélange entre deux appareils, AC6).
   *
   * @param string $_ip
   * @param string $_paquet
   * @param float $_timeout
   * @param string $_octetsMacAttendus 6 octets bruts.
   * @return string|null null = silence réseau (jamais d'exception pour ce seul cas).
   * @throws smartclimException TYPE_PROTOCOLE si la réponse est reçue mais non conforme.
   */
  private static function echanger($_ip, $_paquet, $_timeout, $_octetsMacAttendus = '') {
    $errno = 0;
    $errstr = '';
    $flux = @stream_socket_client('udp://' . $_ip . ':' . self::PORT, $errno, $errstr, $_timeout, STREAM_CLIENT_CONNECT);
    if ($flux === false) {
      log::add('smartclim', 'debug', 'Broadlink LAN : connexion UDP impossible vers ' . $_ip . ' (' . $errstr . ')');
      return null;
    }
    stream_set_blocking($flux, false);
    $reponse = self::envoyerEtAttendre($flux, $_paquet, $_timeout);
    fclose($flux);

    if ($reponse === null) {
      return null;
    }
    if (strlen($reponse) < 0x38) {
      throw new smartclimException('Broadlink LAN : réponse trop courte', smartclimException::TYPE_PROTOCOLE);
    }

    $sommeRecue = ord($reponse[0x20]) | (ord($reponse[0x21]) << 8);
    $reponseVerif = $reponse;
    $reponseVerif[0x20] = "\x00";
    $reponseVerif[0x21] = "\x00";
    $sommeCalculee = self::sommeControle($reponseVerif);
    if ($sommeCalculee !== $sommeRecue) {
      throw new smartclimException('Broadlink LAN : somme de contrôle de réponse invalide', smartclimException::TYPE_PROTOCOLE);
    }

    if ($_octetsMacAttendus !== '') {
      $macEcho = substr($reponse, 0x2A, 6);
      if ($macEcho !== $_octetsMacAttendus) {
        throw new smartclimException('Broadlink LAN : écho de MAC divergent dans la réponse', smartclimException::TYPE_PROTOCOLE);
      }
    }

    return $reponse;
  }

  /**
   * Chemin de diffusion PRINCIPAL (D-POSTMVP0101-03) : extension PHP "sockets".
   * Renvoie null si l'extension est indisponible ou si la création du socket échoue —
   * l'appelant (decouvrir()) tente alors le chemin secondaire.
   *
   * @param string $_paquet
   * @param float $_budget
   * @return array<int, array{reponse:string, ip:string}>|null
   */
  private static function diffuserParExtensionSockets($_paquet, $_budget) {
    $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if ($socket === false) {
      return null;
    }
    @socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);

    $reponses = array();
    $debut = microtime(true);
    $numeroEnvoi = 0;
    $prochainEnvoi = 0.0;
    while ((microtime(true) - $debut) < $_budget) {
      $ecoule = microtime(true) - $debut;
      if ($numeroEnvoi < 2 && $ecoule >= $prochainEnvoi) {
        @socket_sendto($socket, $_paquet, strlen($_paquet), 0, self::ADRESSE_DIFFUSION, self::PORT);
        $numeroEnvoi++;
        $prochainEnvoi = self::INTERVALLE_RENVOI;
      }
      $lecture = array($socket);
      $ecriture = null;
      $exception = null;
      $selection = @socket_select($lecture, $ecriture, $exception, 0, 200000);
      if ($selection > 0) {
        $tampon = '';
        $depuisIp = '';
        $depuisPort = 0;
        $recu = @socket_recvfrom($socket, $tampon, 2048, 0, $depuisIp, $depuisPort);
        if ($recu !== false && $tampon !== '') {
          $reponses[] = array('reponse' => $tampon, 'ip' => $depuisIp);
          if (count($reponses) >= self::MAX_REPONSES_BRUTES) {
            log::add('smartclim', 'warning', 'Broadlink LAN : nombre maximal de réponses brutes atteint pendant la découverte (extension sockets)');
            break;
          }
        }
      }
    }
    @socket_close($socket);
    return $reponses;
  }

  /**
   * Chemin de diffusion SECONDAIRE (D-POSTMVP0101-03), tenté SEULEMENT si le chemin
   * principal (extension "sockets") est indisponible. ⚠️ stream_socket_server(), JAMAIS
   * stream_socket_client('udp://255.255.255.255:80') : un socket UDP CONNECTÉ fait
   * filtrer par le noyau les réponses venant de l'adresse UNICAST des appareils — la
   * découverte ne verrait RIEN.
   *
   * @param string $_paquet
   * @param float $_budget
   * @return array<int, array{reponse:string, ip:string}>|null
   */
  private static function diffuserParFluxNatif($_paquet, $_budget) {
    $contexte = stream_context_create(array('socket' => array('so_broadcast' => true)));
    $errno = 0;
    $errstr = '';
    $flux = @stream_socket_server('udp://0.0.0.0:0', $errno, $errstr, STREAM_SERVER_BIND, $contexte);
    if ($flux === false) {
      return null;
    }
    stream_set_blocking($flux, false);

    $reponses = array();
    $debut = microtime(true);
    $numeroEnvoi = 0;
    $prochainEnvoi = 0.0;
    while ((microtime(true) - $debut) < $_budget) {
      $ecoule = microtime(true) - $debut;
      if ($numeroEnvoi < 2 && $ecoule >= $prochainEnvoi) {
        @stream_socket_sendto($flux, $_paquet, 0, 'udp://' . self::ADRESSE_DIFFUSION . ':' . self::PORT);
        $numeroEnvoi++;
        $prochainEnvoi = self::INTERVALLE_RENVOI;
      }
      $lecture = array($flux);
      $ecriture = null;
      $exception = null;
      $selection = @stream_select($lecture, $ecriture, $exception, 0, 200000);
      if ($selection > 0) {
        $depuis = '';
        $tampon = @stream_socket_recvfrom($flux, 2048, 0, $depuis);
        if ($tampon !== false && $tampon !== '') {
          $ip = $depuis;
          $pos = strrpos($depuis, ':');
          if ($pos !== false) {
            $ip = substr($depuis, 0, $pos);
          }
          $reponses[] = array('reponse' => $tampon, 'ip' => $ip);
          if (count($reponses) >= self::MAX_REPONSES_BRUTES) {
            log::add('smartclim', 'warning', 'Broadlink LAN : nombre maximal de réponses brutes atteint pendant la découverte (flux natif)');
            break;
          }
        }
      }
    }
    fclose($flux);
    return $reponses;
  }

  /**
   * Construit le paquet "hello" de découverte (0x30 octets, § 1.1). Utilisé à la fois par
   * decouvrir() (diffusion) et interroger() (unicast) : c'est le MÊME paquet, seule la
   * destination change. AUCUN magic 0x5AA5AA55 dans ce paquet (contrairement à l'en-tête
   * d'une requête, § 1.3).
   *
   * @return string
   */
  private static function construireHello() {
    $paquet = str_repeat("\x00", 0x30);

    // Datetime.pack(), relatif à 0x08 (§ 1.1).
    $maintenant = time();
    $decalageHeures = (int) floor(((int) date('Z', $maintenant)) / 3600);
    $annee = (int) date('Y', $maintenant);
    $minute = (int) date('i', $maintenant);
    $heure = (int) date('G', $maintenant);
    $anneeCourte = $annee % 100;
    $jourSemaineIso = (int) date('N', $maintenant);
    $jourMois = (int) date('j', $maintenant);
    $mois = (int) date('n', $maintenant);

    $zone = self::packerInt32LE($decalageHeures)
      . self::packerUint16LE($annee)
      . chr($minute & 0xFF)
      . chr($heure & 0xFF)
      . chr($anneeCourte & 0xFF)
      . chr($jourSemaineIso & 0xFF)
      . chr($jourMois & 0xFF)
      . chr($mois & 0xFF);
    $paquet = substr_replace($paquet, $zone, 0x08, strlen($zone));

    $paquet[0x26] = "\x06";
    // 0x18-0x1D (IP locale + port local) restent à zéro : local_ip_address="0.0.0.0" et
    // port=0 fonctionnent, l'appareil répond à l'adresse SOURCE du datagramme (§ 1.1).

    $paquet[0x20] = "\x00";
    $paquet[0x21] = "\x00";
    $somme = self::sommeControle($paquet);
    $paquet[0x20] = chr($somme & 0xFF);
    $paquet[0x21] = chr(($somme >> 8) & 0xFF);

    return $paquet;
  }

  /**
   * Construit l'en-tête d'une requête (0x38 octets, § 1.3) + la charge utile complétée de
   * zéros à un multiple de 16 puis chiffrée. Compteur : ((compteur + 1) | 0x8000) & 0xFFFF
   * — le bit 15 est FORCÉ à 1 (détail absent des sources dérivées, présent dans
   * send_packet()). Ordre impératif des 2 sommes de contrôle : charge (0x34) D'ABORD,
   * paquet complet (0x20) EN DERNIER.
   *
   * @param int $_commande 0x65 (auth) ou 0x6A (requête, réservé à UC02).
   * @param string $_charge Charge utile en clair, NON complétée.
   * @param array $_session 'compteur', 'octets_mac' (6 octets bruts), 'id' (int), 'cle'
   *   (16 octets bruts), 'devtype' (int).
   * @return string
   * @throws smartclimException TYPE_INTERNE si le chiffrement échoue.
   */
  private static function construirePaquet($_commande, $_charge, array $_session) {
    $paquet = str_repeat("\x00", 0x38);
    $paquet = substr_replace($paquet, "\x5A\xA5\xAA\x55\x5A\xA5\xAA\x55", 0x00, 8);

    $devtype = isset($_session['devtype']) ? (int) $_session['devtype'] : self::DEVTYPE_REPLI;
    $paquet = substr_replace($paquet, self::packerUint16LE($devtype), 0x24, 2);
    $paquet = substr_replace($paquet, self::packerUint16LE($_commande), 0x26, 2);

    $compteurBase = isset($_session['compteur']) ? (int) $_session['compteur'] : 0;
    $compteur = (($compteurBase + 1) | 0x8000) & 0xFFFF;
    $paquet = substr_replace($paquet, self::packerUint16LE($compteur), 0x28, 2);

    $octetsMac = isset($_session['octets_mac']) ? $_session['octets_mac'] : '';
    if (strlen($octetsMac) === 6) {
      $paquet = substr_replace($paquet, $octetsMac, 0x2A, 6);
    }

    $identifiant = isset($_session['id']) ? (int) $_session['id'] : 0;
    $paquet = substr_replace($paquet, self::packerInt32LE($identifiant), 0x30, 4);

    $charge = $_charge;
    $reste = strlen($charge) % 16;
    if ($reste !== 0) {
      $charge .= str_repeat("\x00", 16 - $reste);
    }

    $sommeCharge = self::sommeControle($charge);
    $paquet = substr_replace($paquet, self::packerUint16LE($sommeCharge), 0x34, 2);

    $cle = isset($_session['cle']) && strlen($_session['cle']) === 16 ? $_session['cle'] : hex2bin(self::INIT_KEY);
    $chiffre = self::chiffrer($charge, $cle);
    if ($chiffre === false) {
      throw new smartclimException('Broadlink LAN : échec du chiffrement de la charge utile', smartclimException::TYPE_INTERNE);
    }
    $paquet .= $chiffre;

    $paquet[0x20] = "\x00";
    $paquet[0x21] = "\x00";
    $somme = self::sommeControle($paquet);
    $paquet[0x20] = chr($somme & 0xFF);
    $paquet[0x21] = chr(($somme >> 8) & 0xFF);

    return $paquet;
  }

  /**
   * Normalise une réponse BRUTE (diffusion ou unicast) en ligne générique française, ou
   * null si inexploitable (§ 4.2 : n'importe quelle machine du LAN peut répondre à la
   * diffusion, une réponse n'est ni authentifiée ni fiable). La somme de contrôle d'une
   * réponse de DÉCOUVERTE est journalisée si elle diverge mais ne REJETTE jamais
   * l'appareil (contrairement à une réponse d'authentification, § echanger() ci-dessus) :
   * la source canonique ne la vérifie pas sur ce paquet.
   *
   * @param string $_reponse
   * @param string $_ip Adresse SOURCE du datagramme (déjà connue de l'appelant).
   * @return array{mac:string, octets_mac:string, ip:string, port:int, type_appareil:string, nom:string, verrouille:bool, vu_le:int}|null
   */
  private static function normaliserReponseDecouverte($_reponse, $_ip) {
    if (!is_string($_reponse) || strlen($_reponse) < 0x40) {
      log::add('smartclim', 'debug', 'Broadlink LAN : réponse de découverte ignorée (longueur insuffisante)');
      return null;
    }

    $ip = self::normaliserIpV4($_ip);
    if ($ip === '') {
      log::add('smartclim', 'debug', 'Broadlink LAN : réponse de découverte ignorée (adresse source non exploitable)');
      return null;
    }

    $sommeRecue = ord($_reponse[0x20]) | (ord($_reponse[0x21]) << 8);
    $sommeCalculee = (self::sommeControle($_reponse) - ord($_reponse[0x20]) - ord($_reponse[0x21])) & 0xFFFF;
    if ($sommeCalculee !== $sommeRecue) {
      log::add('smartclim', 'debug', 'Broadlink LAN : somme de contrôle de découverte divergente (non bloquant, ip=' . $ip . ')');
    }

    $devtype = ord($_reponse[0x34]) | (ord($_reponse[0x35]) << 8);
    $octetsMac = substr($_reponse, 0x3A, 6);
    if (strlen($octetsMac) !== 6) {
      return null;
    }
    $macImprimable = self::macImprimableDepuisOctets($octetsMac);
    if (strlen($macImprimable) !== 12) {
      return null;
    }
    $octetsMacHex = strtolower(bin2hex($octetsMac));

    $nom = '';
    if (strlen($_reponse) > 0x40) {
      $finNom = strpos($_reponse, "\x00", 0x40);
      $brutNom = ($finNom !== false) ? substr($_reponse, 0x40, $finNom - 0x40) : substr($_reponse, 0x40);
      $nom = self::nettoyerNomExterne($brutNom);
    }

    $verrouille = false;
    if (strlen($_reponse) >= 0x80) {
      $verrouille = (bool) ord($_reponse[0x7F]);
    }

    log::add('smartclim', 'debug', 'Broadlink LAN : devtype observé 0x' . dechex($devtype) . ' (ip=' . $ip . ')');

    return array(
      'mac' => $macImprimable,
      'octets_mac' => $octetsMacHex,
      'ip' => $ip,
      'port' => self::PORT,
      'type_appareil' => sprintf('0x%04X', $devtype),
      'nom' => $nom,
      'verrouille' => $verrouille,
      'vu_le' => time(),
    );
  }

  /**
   * Somme de contrôle du protocole Broadlink : sum(octets, 0xBEAF) & 0xFFFF. UNE seule
   * implémentation pour les 2 usages (charge utile et paquet complet, § 1.3) — l'appelant
   * doit avoir mis à zéro les octets 0x20-0x21 AVANT l'appel s'il calcule la somme du
   * paquet complet (cf. construirePaquet()/construireHello()).
   *
   * @param string $_octets
   * @return int
   */
  private static function sommeControle($_octets) {
    $somme = 0xBEAF;
    $longueur = strlen($_octets);
    for ($i = 0; $i < $longueur; $i++) {
      $somme += ord($_octets[$i]);
    }
    return $somme & 0xFFFF;
  }

  /**
   * Chiffre une charge utile (déjà complétée à un multiple de 16) en AES-128-CBC,
   * remplissage NUL (jamais PKCS#7), avec l'IV FIXE INIT_VECT (§ 1.4 — seule la clé
   * change après authentification, pas l'IV). Vide la file d'erreurs OpenSSL en entrée et
   * journalise sur chaque `false` (même discipline que
   * smartclimAuxHomeApi::chiffrerMotDePasse()).
   *
   * @param string $_donnees
   * @param string $_cle 16 octets bruts.
   * @return string|false
   */
  private static function chiffrer($_donnees, $_cle) {
    self::purgerErreursOpenssl();
    $iv = hex2bin(self::INIT_VECT);
    $sortie = openssl_encrypt($_donnees, 'aes-128-cbc', $_cle, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
    if ($sortie === false) {
      log::add('smartclim', 'error', 'Broadlink LAN : échec du chiffrement AES (' . self::purgerErreursOpenssl() . ')');
    }
    return $sortie;
  }

  /**
   * Déchiffre une charge utile en AES-128-CBC, IV FIXE INIT_VECT. Même discipline que
   * chiffrer() ci-dessus.
   *
   * @param string $_donnees
   * @param string $_cle 16 octets bruts.
   * @return string|false
   */
  private static function dechiffrer($_donnees, $_cle) {
    self::purgerErreursOpenssl();
    $iv = hex2bin(self::INIT_VECT);
    $sortie = openssl_decrypt($_donnees, 'aes-128-cbc', $_cle, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
    if ($sortie === false) {
      log::add('smartclim', 'error', 'Broadlink LAN : échec du déchiffrement AES (' . self::purgerErreursOpenssl() . ')');
    }
    return $sortie;
  }

  /**
   * Vide entièrement la file d'erreurs OpenSSL et la renvoie concaténée (même motif que
   * smartclimAuxHomeApi::purgerErreursOpenssl() — dupliqué à dessein, un transport ne doit
   * pas dépendre d'un autre transport).
   *
   * @return string
   */
  private static function purgerErreursOpenssl() {
    $messages = array();
    while (($erreur = openssl_error_string()) !== false) {
      $messages[] = $erreur;
    }
    return $messages ? implode(' | ', $messages) : 'aucune erreur OpenSSL rapportée';
  }

  /**
   * Code d'erreur appareil, entier SIGNÉ 16 bits LE lu en 0x22-0x23 (§ 1.6). 0 = pas
   * d'erreur. Renvoie 0 si la réponse est trop courte (défensif, echanger() a déjà
   * vérifié la longueur minimale sur le seul appelant actuel).
   *
   * @param string $_reponse
   * @return int
   */
  private static function codeErreur($_reponse) {
    if (!is_string($_reponse) || strlen($_reponse) < 0x24) {
      return 0;
    }
    $valeur = ord($_reponse[0x22]) | (ord($_reponse[0x23]) << 8);
    if ($valeur >= 0x8000) {
      $valeur -= 0x10000;
    }
    return $valeur;
  }

  /**
   * Classe un code d'erreur appareil (§ 1.6) et lève TOUJOURS la smartclimException
   * correspondante (jamais de retour normal) — table UNIQUE, jamais un switch dupliqué.
   * ⚠️ -7 (Control key is expired) et -4012 (Device control ID error) sont le signal
   * D'EXPIRATION DE SESSION, non-devinatoire : c'est le déclencheur du rejeu réactif
   * réservé à UC02 (requete()).
   *
   * @param int $_code
   * @throws smartclimException Toujours.
   */
  private static function classerCodeAppareil($_code) {
    $type = smartclimException::TYPE_PROTOCOLE;
    if ($_code === -3 || $_code === -4000) {
      $type = smartclimException::TYPE_RESEAU;
    } elseif ($_code === -1 || $_code === -2 || $_code === -7 || $_code === -4012) {
      $type = smartclimException::TYPE_AUTH;
    }
    throw new smartclimException('Broadlink LAN : code d\'erreur appareil ' . $_code, $type);
  }

  /**
   * Nettoie le nom d'un appareil issu d'une réponse EXTERNE (§ 4.2) : frontière
   * d'assainissement du transport, au même titre que
   * smartclimAuxHomeApi::nettoyerTexteExterne() — VOLONTAIREMENT DUPLIQUÉ (un transport ne
   * doit pas dépendre d'un autre transport, et on ne refactore pas du code déjà livré et
   * recetté). ⚠️ Mettre un commentaire croisé dans les deux fichiers si l'un évolue.
   * ⚠️ Retire aussi `<` et `>` (correctif sécurité, review post-mvp 01-04) : cette réponse de
   * diffusion UDP n'est pas authentifiée, donc forgeable par toute machine du réseau local ;
   * le nom traverse ensuite cleanComponanteName() du core, qui ne filtre PAS `<`/`>`, puis
   * finit dans du HTML rendu sans échappement systématique — XSS stocké sinon possible.
   *
   * @param mixed $_valeur
   * @param int $_max
   * @return string
   */
  private static function nettoyerNomExterne($_valeur, $_max = 63) {
    if (!is_scalar($_valeur)) {
      return '';
    }
    $valeur = preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) $_valeur);
    if (preg_match('//u', $valeur) !== 1) {
      $valeur = preg_replace('/[^\x20-\x7E]/', ' ', $valeur);
    }
    $valeur = str_replace(array('<', '>'), '', $valeur);
    $valeur = trim($valeur);
    $valeur = substr($valeur, 0, $_max);
    while ($valeur !== '' && preg_match('//u', $valeur) !== 1) {
      $valeur = substr($valeur, 0, -1);
    }
    return $valeur;
  }

  /**
   * Valide/normalise une IPv4 avant tout usage réseau (destination d'une sonde unicast,
   * adresse source d'une réponse de découverte, § 4.1/4.2) : rejette les adresses
   * PUBLIQUEMENT ROUTABLES (dont le CGNAT 100.64.0.0/10, exclu explicitement plutôt que de
   * dépendre du comportement de filter_var() selon la version de PHP) — le plugin envoie
   * de l'UDP vers cette adresse, il ne doit jamais devenir un émetteur vers Internet.
   * VOLONTAIREMENT DUPLIQUÉ de smartclim::normaliserIpV4() (même motif que
   * nettoyerNomExterne() ci-dessus : un transport ne dépend pas d'une autre classe pour
   * une règle de validation générique).
   *
   * @param mixed $_valeur
   * @return string '' si non exploitable ou publiquement routable.
   */
  private static function normaliserIpV4($_valeur) {
    if (!is_scalar($_valeur)) {
      return '';
    }
    $ip = filter_var((string) $_valeur, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    if ($ip === false) {
      return '';
    }
    $publique = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    if ($publique !== false) {
      return '';
    }
    $octets = array_map('intval', explode('.', $ip));
    if ($octets[0] === 100 && $octets[1] >= 64 && $octets[1] <= 127) {
      return '';
    }
    // FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE ne couvre ni 0.0.0.0/8 ni la
    // plage multicast/réservée haute (224.0.0.0 et au-delà) : rejet explicite par
    // comparaison d'OCTETS, PAS ip2long() — ip2long() renvoie un entier SIGNÉ, et sur un
    // build PHP 32 bits (ex. Raspberry Pi OS armhf) le seuil 224.0.0.0 devient négatif,
    // ce qui fait passer à tort toute adresse en 10.x.x.x pour "supérieure" à ce seuil et
    // la rejette (bug vécu, cf. revue). Raisonner sur les octets est exact quelle que
    // soit la largeur des entiers.
    if ($octets[0] === 0 || $octets[0] >= 224) {
      return '';
    }
    return $ip;
  }

  /**
   * Empreinte de session (§ 6) : sha1(mac|ip|port|devtype). L'IP en fait partie —
   * c'est ce qui invalide la session TOUTE SEULE sur un changement de bail DHCP, sans
   * hook de purge dédié.
   *
   * @param array $_appareil
   * @return string
   */
  private static function empreinteSession(array $_appareil) {
    $mac = isset($_appareil['mac']) ? $_appareil['mac'] : '';
    $ip = isset($_appareil['ip']) ? $_appareil['ip'] : '';
    $port = isset($_appareil['port']) ? $_appareil['port'] : self::PORT;
    $devtype = isset($_appareil['type_appareil']) ? $_appareil['type_appareil'] : '';
    return sha1($mac . '|' . $ip . '|' . $port . '|' . $devtype);
  }

  /**
   * Dossier des verrous flock(), mémoïsé UNE SEULE FOIS par processus
   * (D-POSTMVP0101-04, finding F3).
   *
   * @return string
   */
  private static function dossierVerrous() {
    if (self::$dossierVerrous === null) {
      self::$dossierVerrous = jeedom::getTmpFolder('smartclim');
    }
    return self::$dossierVerrous;
  }

  /**
   * Prend un verrou EXCLUSIF non bloquant sur un fichier par MAC, en boucle courte
   * (usleep 50 ms) bornée par $_budget (D-POSTMVP0101-04/07). Renvoie null si le budget
   * est épuisé avant l'obtention du verrou (STATUT_OCCUPE côté appelant) ou si le fichier
   * ne peut pas être ouvert (défensif, log 'warning').
   *
   * @param string $_macNorm
   * @param float $_budget
   * @return resource|null
   */
  private static function verrou($_macNorm, $_budget) {
    $chemin = self::dossierVerrous() . '/lan-' . sha1($_macNorm) . '.lock';
    $ressource = @fopen($chemin, 'c');
    if ($ressource === false) {
      log::add('smartclim', 'warning', 'Broadlink LAN : verrou impossible à créer pour ' . $_macNorm);
      return null;
    }
    $debut = microtime(true);
    while (!flock($ressource, LOCK_EX | LOCK_NB)) {
      if ((microtime(true) - $debut) >= $_budget) {
        fclose($ressource);
        return null;
      }
      usleep(50000);
    }
    return $ressource;
  }

  /**
   * Libère un verrou pris par verrou() ci-dessus. L'OS relâche de toute façon le flock à
   * la mort du processus : aucun verrou orphelin possible.
   *
   * @param resource|null $_ressource
   */
  private static function libererVerrou($_ressource) {
    if (is_resource($_ressource)) {
      flock($_ressource, LOCK_UN);
      fclose($_ressource);
    }
  }

  /**
   * Convertit une chaîne hexadécimale de 12 caractères ("octets_mac" d'une ligne
   * normalisée) en 6 octets bruts. Repli à 6 octets nuls si la forme est inattendue —
   * ne doit normalement jamais se produire, les lignes normalisées étant déjà validées.
   *
   * @param string $_hex
   * @return string
   */
  private static function hexVersOctets($_hex) {
    if (!is_string($_hex) || strlen($_hex) !== 12 || preg_match('/^[0-9a-f]{12}\z/', $_hex) !== 1) {
      return str_repeat("\x00", 6);
    }
    return hex2bin($_hex);
  }

  /**
   * MAC IMPRIMABLE (ordre inverse, § 1.2) à partir de 6 octets bruts dans l'ordre de
   * l'en-tête. JAMAIS strrev() sur une chaîne hexadécimale (inverserait aussi les
   * quartets) : on inverse les OCTETS binaires avant l'encodage hexadécimal.
   *
   * @param string $_octets 6 octets bruts.
   * @return string 12 caractères hexadécimaux minuscules, ou '' si non conforme.
   */
  private static function macImprimableDepuisOctets($_octets) {
    if (!is_string($_octets) || strlen($_octets) !== 6) {
      return '';
    }
    return strtolower(bin2hex(strrev($_octets)));
  }

  /**
   * Détermine le devtype à utiliser pour une requête à partir d'une ligne normalisée
   * ("type_appareil", forme "0x1234"). Repli DEVTYPE_REPLI si absent/inexploitable (ex.
   * IP saisie à la main jamais vue en diffusion).
   *
   * @param array $_appareil
   * @return int
   */
  private static function devtypeDepuisAppareil(array $_appareil) {
    $type = isset($_appareil['type_appareil']) ? $_appareil['type_appareil'] : '';
    if (is_string($type) && preg_match('/^0x[0-9A-Fa-f]{1,4}\z/', $type) === 1) {
      return (int) hexdec($type);
    }
    return self::DEVTYPE_REPLI;
  }

  /**
   * Décode 4 octets bruts en entier non signé 32 bits, LITTLE-ENDIAN, via le format pack()
   * PORTABLE 'V' (indépendant de l'endianness de l'hôte, garanti par PHP >= 5.2.1).
   *
   * @param string $_octets
   * @return int
   */
  private static function octetsVersUint32LE($_octets) {
    if (!is_string($_octets) || strlen($_octets) !== 4) {
      return 0;
    }
    $valeurs = unpack('V', $_octets);
    return isset($valeurs[1]) ? (int) $valeurs[1] : 0;
  }

  /**
   * Encode un entier en 2 octets non signés, LITTLE-ENDIAN, via le format pack() PORTABLE
   * 'v' (indépendant de l'endianness de l'hôte).
   *
   * @param int $_valeur
   * @return string
   */
  private static function packerUint16LE($_valeur) {
    return pack('v', $_valeur & 0xFFFF);
  }

  /**
   * Encode un entier (positif ou négatif) en 4 octets, LITTLE-ENDIAN, via le format
   * pack() PORTABLE 'V' — le masquage & 0xFFFFFFFF ramène un entier négatif à sa forme
   * complément à deux sur 32 bits AVANT l'encodage (PHP gère nativement des entiers 64
   * bits, ce masquage est donc nécessaire).
   *
   * @param int $_valeur
   * @return string
   */
  private static function packerInt32LE($_valeur) {
    return pack('V', $_valeur & 0xFFFFFFFF);
  }
}
