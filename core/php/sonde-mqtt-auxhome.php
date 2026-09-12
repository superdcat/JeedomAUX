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
* SONDE MQTT AUX Home — instrument de MESURE de l'UC05 du domaine
* post-mvp/05-temps-reel-et-demon (« Validation de l'accès au broker MQTT AUX Home
* europeen »). Meme statut que core/php/sonde-intent-auxhome.php et
* resources/smartclimd/sonde_auxlink.py : ce script n'est appele par AUCUN chemin
* d'execution du plugin, ni maintenant ni jamais. Il produit UN fait — le broker
* europeen accepte-t-il nos identifiants ? — et rien d'autre.
*
* ⚠️ CE SCRIPT PORTE SA PROPRE LOGIQUE PROTOCOLAIRE, contrairement au patron des autres
* CLI (commande-lan.php, sonde-intent-auxhome.php) qui ne font QUE deleguer a smartclim::
* et ne contiennent aucune logique metier. Ici il n'y a rien a deleguer : cette UC produit
* le PREMIER fait MQTT du projet, et aucune classe du plugin ne parle ce protocole (meme
* situation que sonde_auxlink.py, ecrite avant que son transport n'existe). Un lecteur
* futur ne doit pas prendre ce fichier pour un aiguillage sans logique.
*
* ⚠️ TENSION ASSUMEE avec « centraliser les acces externes, jamais de socket epars » :
* la regle protege le RUNTIME du plugin contre l'eparpillement du savoir protocolaire.
* Ce fichier n'est requis par rien, joignable par rien (Deny from all + garde CLI
* ci-dessous), et n'est PAS un transport du plugin — il n'entrera jamais dans
* core/php/smartclim.inc.php (§ 3 de la spec technique). Chaque invocation CLI est en
* outre un PROCESSUS PHP ISOLE : aucune constante ni globale definie ici ne peut
* atteindre un futur process AJAX/cron.
*
* ⚠️ ARBITRAGE TLS (D-1, decide avec l'utilisateur le 2026-09-12, cf. § 0.1 de la spec
* technique et § 7.3 de .memory/analyse/smartclim-transport-aux-home.md) : le certificat
* du broker europeen est EXPIRE et son SAN ne couvre pas son propre nom d'hote — une
* connexion pleinement verifiee y est IMPOSSIBLE. Le mode --connack refuse de deroger a
* « TLS toujours verifie » SANS que l'operateur ne le demande explicitement via
* --accepter-certificat-invalide (jamais implicite). Cette derogation est cadree, bornee
* a CETTE CLI, et ne vaut PAS precedent : toute UC future qui voudrait exploiter ce canal
* doit revalider le certificat au moment ou elle s'ecrit.
*
* ⚠️ INVARIANT VERIFIABLE (meme doctrine que l'AC1 de sonde_auxlink.py) : ce fichier ne
* doit contenir NI code d abonnement a un canal d evenements, NI code d envoi d un
* message applicatif vers un appareil, NI le nom d un topic de messagerie. Le controle
* de revue est donne au § 6.7 de la spec technique de cette UC (une commande grep sur
* des motifs precis, deux d entre eux pris entre frontieres de mot pour ne pas matcher
* le mot « published » du bloc de licence GPL en tete de ce meme fichier — un faux
* positif sur le texte de licence, pas sur du code) : par construction cette
* formulation-ci ne reprend AUCUN de ces motifs a la lettre, afin que ce controle rende
* bien zero occurrence SANS avoir besoin d exclure ce commentaire lui-meme.
*
* USAGE (sur le Jeedom, en SSH, sous www-data — la session du plugin est ecrite par
* Apache et relue par la CLI) :
*   cd /var/www/html/plugins/smartclim
*   sudo -u www-data php core/php/sonde-mqtt-auxhome.php --certificat [--port=8883|443]
*   sudo -u www-data php core/php/sonde-mqtt-auxhome.php --controle [--port=8883]
*   sudo -u www-data php core/php/sonde-mqtt-auxhome.php --connack [--protocole=3|4]
*         [--config-id=<v> …] [--port=8883|443] [--empreinte=<sha256>]
*         [--accepter-certificat-invalide] [--brut]
*
* Protocole de recette complet : § 10 de la spec technique de cette UC.
*
* Ne fait AUCUN POST vers le backend REST, n'ecrit rien en base ni sur disque : sortie
* sur la sortie standard uniquement. Purge la session AUX Home en cache en fin de test
* (§ 6.5 de la spec technique).
*/

if (php_sapi_name() !== 'cli') {
  http_response_code(403);
  die("Ce script est reserve a la ligne de commande.\n");
}

require_once __DIR__ . '/../class/smartclim.class.php';

// --- Constantes locales (§ 6.6 de la spec technique) -----------------------------------

const MQTT_HOTE_EU = 'eu-smthome-m2m.aux-global.com';
const MQTT_HOTE_CONTROLE = 'smthomem2m.aux-home.com';
const MQTT_TIMEOUT_CONNEXION = 5;
const MQTT_TIMEOUT_LECTURE = 6;
const MQTT_BUDGET_SERIE = 30;
const MQTT_PAUSE_TENTATIVE = 1;
const MQTT_MAX_CANDIDATS = 6;

// Constante PUBLIQUE d'un projet tiers (pas un secret d'un compte) — D-2 de la spec
// technique § 0.1 : versionnee au meme titre que STATIC_APP_TOKEN/ACCOUNT_AES_KEY deja
// presentes dans smartclimAuxHomeApi. Source : latentharbor/ha-aux-a-plus (MIT),
// custom_components/aux_a_plus/const.py::DEFAULT_CONFIG_ID.
const MQTT_CONFIG_ID_REFERENCE = 'B7E65BB2-F02E-4EAD-B7BA-1C50FCE62882';

// --- 6.1 Construction du protocole — fonctions pures, aucune E/S -----------------------

/**
 * Chaine prefixee de sa longueur sur 2 octets big-endian (format MQTT standard).
 *
 * @param string $_texte
 * @return string
 */
function mqttChaine($_texte) {
  $longueur = strlen($_texte);
  if ($longueur > 65535) {
    // Arret net : aucune valeur legitime de ce script (jeton, uid, configId borne a 64
    // caracteres) ne peut jamais depasser cette taille — un depassement signale une
    // erreur de programmation, pas un resultat de mesure.
    throw new RuntimeException('Champ MQTT trop long (' . $longueur . ' octets)');
  }
  return pack('n', $longueur) . $_texte;
}

/**
 * Encode la Remaining Length MQTT (varint 7 bits par octet, bit de continuation 0x80,
 * de 1 a 4 octets).
 *
 * PIEGE N°1 (§ 6.1 de la spec technique) : le corps CONNECT de ce script depasse
 * presque toujours 127 octets (le jeton seul peut atteindre 4096 caracteres) — donc 2
 * octets sont quasi systematiquement necessaires. Un varint mal encode produit un
 * paquet malforme, donc une connexion FERMEE par le broker, qui serait alors lue comme
 * un refus d'authentification : c'est le pire faux negatif possible de cette UC.
 *
 * @param int $_n
 * @return string
 */
function mqttLongueurRestante($_n) {
  $n = $_n;
  $octets = '';
  do {
    $octet = $n % 128;
    $n = intdiv($n, 128);
    if ($n > 0) {
      $octet |= 0x80;
    }
    $octets .= chr($octet);
  } while ($n > 0);
  return $octets;
}

/**
 * Assemble un paquet MQTT complet : type + Remaining Length + corps.
 *
 * @param int $_type
 * @param string $_corps
 * @return string
 */
function mqttPaquet($_type, $_corps) {
  return chr($_type) . mqttLongueurRestante(strlen($_corps)) . $_corps;
}

/**
 * Corps du paquet CONNECT (§ 2.1 de la spec technique). Drapeaux figes a 0xC2 (user
 * name + password + clean session, aucun will) : 128 + 64 + 2 = 194 = 0xC2.
 *
 * @param string $_clientId
 * @param string $_user
 * @param string $_password
 * @param int $_niveau 3 (MQIsdp) ou 4 (MQTT)
 * @param int $_keepAlive
 * @return string
 */
function mqttCorpsConnect($_clientId, $_user, $_password, $_niveau, $_keepAlive) {
  $nomProtocole = ($_niveau === 3) ? 'MQIsdp' : 'MQTT';
  $corps = mqttChaine($nomProtocole);
  $corps .= chr($_niveau);
  $corps .= chr(0xC2);
  $corps .= pack('n', $_keepAlive);
  $corps .= mqttChaine($_clientId);
  $corps .= mqttChaine($_user);
  $corps .= mqttChaine($_password);
  return $corps;
}

// --- 6.2 E/S reseau ---------------------------------------------------------------------

/**
 * Ouvre un flux TLS vers le broker et renseigne $_infosCert (donnees PUBLIQUES du
 * certificat : sujet, emetteur, SAN, dates, empreinte SHA-256 — jamais un secret).
 *
 * @param string $_hote
 * @param int $_port
 * @param bool $_verifier Verification TLS pleine (peer + nom d'hote)
 * @param string|null $_empreinte Empreinte SHA-256 optionnelle a corroborer (§ 2.3)
 * @param array $_infosCert Rempli par reference
 * @return resource|false
 */
function ouvrirFlux($_hote, $_port, $_verifier, $_empreinte, array &$_infosCert) {
  $_infosCert = array();
  $options = array(
    'ssl' => array(
      'verify_peer' => $_verifier,
      'verify_peer_name' => $_verifier,
      'peer_name' => $_hote,
      'capture_peer_cert' => true,
      'SNI_enabled' => true,
      'disable_compression' => true,
    ),
  );
  if ($_empreinte !== null && $_empreinte !== '') {
    $options['ssl']['peer_fingerprint'] = strtolower($_empreinte);
  }
  $contexte = stream_context_create($options);
  $erreurNumero = 0;
  $erreurMessage = '';
  $flux = @stream_socket_client(
    'ssl://' . $_hote . ':' . $_port,
    $erreurNumero,
    $erreurMessage,
    MQTT_TIMEOUT_CONNEXION,
    STREAM_CLIENT_CONNECT,
    $contexte
  );
  if ($flux === false) {
    log::add('smartclim', 'debug', 'sonde-mqtt-auxhome : connexion TLS echouee vers ' . $_hote . ':' . $_port . ' : ' . smartclim::neutraliserPourLog($erreurMessage));
    return false;
  }
  $parametres = stream_context_get_params($flux);
  if (isset($parametres['options']['ssl']['peer_certificate'])) {
    $ressourceCert = $parametres['options']['ssl']['peer_certificate'];
    $donnees = openssl_x509_parse($ressourceCert);
    if (is_array($donnees)) {
      $_infosCert['sujet'] = isset($donnees['subject']['CN']) ? $donnees['subject']['CN'] : '';
      $_infosCert['emetteur'] = isset($donnees['issuer']['CN']) ? $donnees['issuer']['CN'] : '';
      $_infosCert['san'] = isset($donnees['extensions']['subjectAltName']) ? $donnees['extensions']['subjectAltName'] : '';
      $_infosCert['valide_du'] = isset($donnees['validFrom_time_t']) ? date('Y-m-d', $donnees['validFrom_time_t']) : '';
      $_infosCert['valide_au'] = isset($donnees['validTo_time_t']) ? date('Y-m-d', $donnees['validTo_time_t']) : '';
      $_infosCert['expire'] = isset($donnees['validTo_time_t']) && $donnees['validTo_time_t'] < time();
    }
    $empreinte = openssl_x509_fingerprint($ressourceCert, 'sha256');
    $_infosCert['empreinte_sha256'] = is_string($empreinte) ? $empreinte : '';
  }
  return $flux;
}

/**
 * Ecrit integralement une chaine sur un flux.
 *
 * PIEGE N°2 (§ 6.2 de la spec technique) : fwrite() n'est pas plus atomique que
 * fread() — il peut renvoyer moins d'octets qu'on ne lui en a donne. Boucle jusqu'a
 * epuisement du tampon.
 *
 * @param resource $_flux
 * @param string $_donnees
 * @return bool
 */
function ecrireTout($_flux, $_donnees) {
  $restant = $_donnees;
  while (strlen($restant) > 0) {
    $ecrit = @fwrite($_flux, $restant);
    if ($ecrit === false || $ecrit === 0) {
      return false;
    }
    $restant = substr($restant, $ecrit);
  }
  return true;
}

/**
 * Lit un paquet CONNACK (4 octets exacts, § 2.1). Ne leve JAMAIS : un echec reseau est
 * un RESULTAT DE MESURE, pas une erreur de programme (meme doctrine que
 * smartclimBroadlinkLan::lireEtat()).
 *
 * PIEGE N°3 (§ 6.2 de la spec technique) : TCP est un FLUX, pas un message — fread()
 * peut renvoyer moins de 4 octets. Boucle sur fread() jusqu'a 4 octets ou echeance, en
 * relisant stream_get_meta_data()['timed_out'] a CHAQUE tour : un simple
 * stream_set_timeout() sans cette relecture peut boucler indefiniment sur un flux qui
 * ne renvoie plus rien sans se fermer.
 *
 * Valide les deux premiers octets (0x20, 0x02) AVANT de faire confiance au 4e : une
 * page d'erreur HTTP renvoyee par erreur sur le port 443 doit sortir en 'malforme',
 * jamais en un faux code de retour.
 *
 * @param resource $_flux
 * @param float $_echeance Horodatage Unix limite (microtime(true))
 * @return array{etat:string,brut_hex:string,session_present:bool,code:int|null}
 */
function lireConnack($_flux, $_echeance) {
  stream_set_timeout($_flux, MQTT_TIMEOUT_LECTURE);
  $tampon = '';
  while (strlen($tampon) < 4) {
    if (microtime(true) >= $_echeance) {
      return array('etat' => 'timeout', 'brut_hex' => bin2hex($tampon), 'session_present' => false, 'code' => null);
    }
    $lu = @fread($_flux, 4 - strlen($tampon));
    $meta = stream_get_meta_data($_flux);
    if ($lu === false || $lu === '') {
      if (!empty($meta['timed_out'])) {
        return array('etat' => 'timeout', 'brut_hex' => bin2hex($tampon), 'session_present' => false, 'code' => null);
      }
      if (feof($_flux)) {
        return array('etat' => 'ferme', 'brut_hex' => bin2hex($tampon), 'session_present' => false, 'code' => null);
      }
      // Rien lu, pas timeout, pas EOF : on retente sans consommer le budget en rafale.
      usleep(50000);
      continue;
    }
    $tampon .= $lu;
  }
  $brutHex = bin2hex($tampon);
  $octet0 = ord($tampon[0]);
  $octet1 = ord($tampon[1]);
  if ($octet0 !== 0x20 || $octet1 !== 0x02) {
    // Pas un CONNACK MQTT valide : une page d'erreur HTTP, un flux TLS d'un autre
    // service, ou tout octet inattendu. Ne JAMAIS interpreter le 4e octet dans ce cas.
    return array('etat' => 'malforme', 'brut_hex' => $brutHex, 'session_present' => false, 'code' => null);
  }
  $drapeaux = ord($tampon[2]);
  $code = ord($tampon[3]);
  return array(
    'etat' => 'connack',
    'brut_hex' => $brutHex,
    'session_present' => ($drapeaux & 0x01) === 0x01,
    'code' => $code,
  );
}

/**
 * Mene une connexion complete a un candidat : ouvrirFlux -> CONNECT -> lireConnack ->
 * DISCONNECT (E0 00, UNIQUEMENT si le code du CONNACK vaut 0x00, § 2.1 : MQTT § 3.2.2.3
 * impose au pair de fermer la connexion sur tout code non nul, le socket est donc DEJA
 * ferme cote broker) -> fclose. Ne SOUSCRIT ni ne PUBLIE jamais.
 *
 * @param array $_params {hote,port,verifier,empreinte,clientId,user,password,niveau,keepAlive}
 * @return array{etat:string,brut_hex:string,session_present:bool,code:int|null,cert:array}
 */
function tenterCandidat(array $_params) {
  $infosCert = array();
  $flux = ouvrirFlux($_params['hote'], $_params['port'], $_params['verifier'], $_params['empreinte'], $infosCert);
  if ($flux === false) {
    return array('etat' => 'echec_tls', 'brut_hex' => '', 'session_present' => false, 'code' => null, 'cert' => $infosCert);
  }
  try {
    $corps = mqttCorpsConnect($_params['clientId'], $_params['user'], $_params['password'], $_params['niveau'], $_params['keepAlive']);
    $paquet = mqttPaquet(0x10, $corps);
    if (!ecrireTout($flux, $paquet)) {
      return array('etat' => 'ecriture_echouee', 'brut_hex' => '', 'session_present' => false, 'code' => null, 'cert' => $infosCert);
    }
    $echeance = microtime(true) + MQTT_TIMEOUT_LECTURE;
    $resultat = lireConnack($flux, $echeance);
    if ($resultat['etat'] === 'connack' && $resultat['code'] === 0x00) {
      // DISCONNECT (E0 00) UNIQUEMENT sur code 0x00 (§ 2.1) : dans tous les autres cas
      // le pair a deja ferme le socket, et ecrire dessus produirait un fwrite() partiel
      // ou un warning "Broken pipe". L'erreur d'ecriture eventuelle est ignoree : un
      // fclose() suit immediatement dans tous les cas.
      @fwrite($flux, "\xE0\x00");
    }
    $resultat['cert'] = $infosCert;
    return $resultat;
  } finally {
    @fclose($flux);
  }
}

// --- 6.3 Rendu ---------------------------------------------------------------------------

/**
 * @param array $_infosCert
 * @return string
 */
function texteCertificat(array $_infosCert) {
  if (empty($_infosCert)) {
    return "  (certificat non capture — echec de connexion TLS)\n";
  }
  $texte = '  Sujet          : ' . (isset($_infosCert['sujet']) ? $_infosCert['sujet'] : '?') . "\n";
  $texte .= '  Emetteur       : ' . (isset($_infosCert['emetteur']) ? $_infosCert['emetteur'] : '?') . "\n";
  $texte .= '  SAN            : ' . (isset($_infosCert['san']) ? $_infosCert['san'] : '?') . "\n";
  $texte .= '  Validite       : ' . (isset($_infosCert['valide_du']) ? $_infosCert['valide_du'] : '?') . ' -> ' . (isset($_infosCert['valide_au']) ? $_infosCert['valide_au'] : '?');
  $texte .= !empty($_infosCert['expire']) ? " (EXPIRE)\n" : "\n";
  $texte .= '  Empreinte SHA-256 : ' . (isset($_infosCert['empreinte_sha256']) ? $_infosCert['empreinte_sha256'] : '?') . "\n";
  return $texte;
}

/**
 * Libelle FR d'un etat de resultat.
 *
 * @param string $_etat
 * @return string
 */
function libelleEtatResultat($_etat) {
  switch ($_etat) {
    case 'connack':
      return 'CONNACK recu';
    case 'ferme':
      return 'connexion fermee sans reponse';
    case 'timeout':
      return 'delai de lecture depasse';
    case 'malforme':
      return 'reponse non-MQTT (en-tete invalide)';
    case 'echec_tls':
      return 'echec de connexion TLS';
    case 'ecriture_echouee':
      return 'echec d ecriture du paquet CONNECT';
    default:
      return $_etat;
  }
}

/**
 * Libelle FR d'un code de retour CONNACK (§ 2.1 de la spec technique).
 *
 * @param int|null $_code
 * @return string
 */
function libelleCodeConnack($_code) {
  switch ($_code) {
    case 0x00:
      return 'accepte — le compte est connu du broker';
    case 0x01:
      return 'version de protocole inacceptable (bug de construction du paquet, pas un fait sur le compte)';
    case 0x02:
      return 'identifiant de client rejete (le ClientId est en cause, pas les identifiants)';
    case 0x03:
      return 'serveur indisponible (indecis, a rejouer)';
    case 0x04:
      return 'nom d utilisateur ou mot de passe invalide (configId/niveau de protocole/ClientId sont des confondants — n autorise AUCUNE conclusion seul)';
    case 0x05:
      return 'non autorise (identifiants reconnus mais compte non habilite — information forte)';
    default:
      return 'code inconnu';
  }
}

/**
 * Une ligne de resultat pour un candidat donne. Le candidat 'uid' EST le uid du
 * compte : masque par defaut via smartclimDiagnostic::jeton() (§ 7.4 de la spec
 * technique, AC6), leve seulement avec --brut. Les trois autres etiquettes
 * (reference/aleatoire/vide) ne portent aucune valeur de compte — la valeur publique de
 * reference, une valeur generee par ce script, ou une chaine vide — et s'affichent donc
 * toujours en clair. Le jeton, lui, n'est JAMAIS affiche, meme avec --brut, meme
 * tronque : seules ses LONGUEURS (ClientId/UserName) le sont.
 *
 * @param array $_resultat
 * @param array $_correspondancesMasque
 * @param bool $_brut
 * @return string
 */
function ligneResultat(array $_resultat, array &$_correspondancesMasque, $_brut) {
  if ($_resultat['etiquette'] === 'uid' && !$_brut) {
    $configIdAffiche = smartclimDiagnostic::jeton($_resultat['configId'], $_correspondancesMasque);
  } else {
    $configIdAffiche = ($_resultat['configId'] === '') ? '(vide)' : $_resultat['configId'];
  }
  $ligne = '  [' . $_resultat['etiquette'] . ', configId=' . $configIdAffiche . '] protocole=' . $_resultat['niveau'] . ' port=' . $_resultat['port'] . ' -> ' . libelleEtatResultat($_resultat['etat']);
  if ($_resultat['etat'] === 'connack') {
    $ligne .= ' (brut ' . $_resultat['brut_hex'] . ', code 0x' . str_pad(dechex($_resultat['code']), 2, '0', STR_PAD_LEFT) . ' : ' . libelleCodeConnack($_resultat['code']) . ')';
  }
  $ligne .= ' — ClientId ' . strlen($_resultat['clientId']) . ' caracteres, UserName ' . strlen($_resultat['user']) . ' caracteres';
  return $ligne . "\n";
}

/**
 * ⚠️ C'est ELLE qui porte l'AC3 (§ 6.4 de la spec technique).
 *
 * Elle DISCRIMINE le code EN PREMIER, INDEPENDAMMENT des trois conditions ci-dessous :
 * une serie HOMOGENE de 0x02 parmi les CONNACK obtenus designe le ClientId (repli
 * usr<uid> sans le suffixe _ha_<device_id> de la reference, § 2.1), jamais le configId —
 * et la fonction ne conclut PAS sur le configId dans ce cas. Ce diagnostic est evalue
 * AVANT le calcul de $manque et sans dependre de lui : une invocation ne teste toujours
 * qu'un seul niveau de protocole (§ 6.6), donc la condition 3 ci-dessous n'est jamais
 * remplie au sein d'un seul run — sans cette independance, le diagnostic 0x02 serait
 * inatteignable.
 *
 * Sinon, elle refuse d'imprimer une conclusion NEGATIVE — et dit alors ce qui manque —
 * tant que les TROIS conditions suivantes ne sont pas reunies :
 *   1. La valeur de REFERENCE (identite exacte, pas un simple comptage) a produit un
 *      CONNACK.
 *   2. Au moins DEUX configId distincts ont produit un CONNACK.
 *   3. Les DEUX niveaux de protocole (3 et 4) ont ete essayes.
 *
 * @param array $_resultats Liste de resultats de tenterCandidat() + 'etiquette','configId','niveau'
 * @param array $_protocolesEssayes Niveaux (3/4) effectivement essayes dans cette execution
 * @return string
 */
function conclusion(array $_resultats, array $_protocolesEssayes) {
  $avecConnack = array_filter($_resultats, function ($r) {
    return $r['etat'] === 'connack';
  });

  $succes = array_filter($avecConnack, function ($r) {
    return $r['code'] === 0x00;
  });
  if (!empty($succes)) {
    $premier = reset($succes);
    return "CONCLUSION : le compte europeen EST connu du broker (CONNACK 0x00 obtenu avec le candidat [" . $premier['etiquette'] . "], protocole " . $premier['niveau'] . ").\n";
  }

  // Discrimination du code AVANT le calcul de $manque, et evaluee INDEPENDAMMENT des
  // trois conditions ci-dessous : une serie HOMOGENE de 0x02 designe le ClientId, pas le
  // configId, et cette information vaut meme avec un seul niveau de protocole essaye et
  // un seul run — $_protocolesEssayes ne contenant structurellement qu un seul element
  // (une invocation = un seul --protocole), la condition 3 ci-dessous n est jamais
  // remplie et $manque n est donc jamais vide : ce diagnostic serait sinon du code mort
  // (§ 6.4 de la spec technique).
  $codes = array();
  foreach ($avecConnack as $r) {
    $codes[$r['code']] = true;
  }
  if (!empty($avecConnack) && count($codes) === 1 && isset($codes[0x02])) {
    return "CONCLUSION : tous les candidats renvoient 0x02 (identifiant de client rejete). Ceci designe le ClientId, PAS le configId — la reference construit en realite usr<uid>_ha_<device_id> (§ 2.1), non exerce par ce script (dette D-05-05-01). Aucune conclusion n est tiree sur l acceptation du compte.\n";
  }

  $referenceEssayee = false;
  foreach ($avecConnack as $r) {
    if ($r['configId'] === MQTT_CONFIG_ID_REFERENCE) {
      $referenceEssayee = true;
      break;
    }
  }
  $configIdsDistincts = array();
  foreach ($avecConnack as $r) {
    $configIdsDistincts[$r['configId']] = true;
  }
  $protocolesOk = in_array(3, $_protocolesEssayes, true) && in_array(4, $_protocolesEssayes, true);

  $manque = array();
  if (!$referenceEssayee) {
    $manque[] = 'la valeur de reference (' . MQTT_CONFIG_ID_REFERENCE . ') n a produit aucun CONNACK';
  }
  if (count($configIdsDistincts) < 2) {
    $manque[] = 'moins de deux configId distincts ont produit un CONNACK';
  }
  if (!$protocolesOk) {
    $manque[] = 'les deux niveaux de protocole (3 et 4) n ont pas ete essayes (essayes : ' . implode(',', $_protocolesEssayes) . ')';
  }
  if (!empty($manque)) {
    return "CONCLUSION REFUSEE (pas encore assez d elements pour ecarter le configId comme cause) :\n  - " . implode("\n  - ", $manque) . "\n";
  }

  // Les trois conditions sont reunies et le code n est pas une serie homogene de 0x02
  // (deja ecarte plus haut).
  return "CONCLUSION : aucun candidat n a ete accepte (0x00) apres avoir ecarte le configId et le niveau de protocole comme causes uniques. Codes obtenus : " . implode(',', array_map(function ($c) {
    return '0x' . str_pad(dechex($c), 2, '0', STR_PAD_LEFT);
  }, array_keys($codes))) . ". Voir le detail ligne par ligne ci-dessus pour orienter la suite (§ 11, R5 de la spec technique).\n";
}

// --- UUID v4 (candidat 'aleatoire') -------------------------------------------------------

/**
 * @return string
 */
function genererUuidV4() {
  $donnees = random_bytes(16);
  $donnees[6] = chr((ord($donnees[6]) & 0x0F) | 0x40);
  $donnees[8] = chr((ord($donnees[8]) & 0x3F) | 0x80);
  $hex = bin2hex($donnees);
  return strtoupper(substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12));
}

// --- Arguments -----------------------------------------------------------------------------

$usage = "Usage :\n"
  . "  php core/php/sonde-mqtt-auxhome.php --certificat [--port=8883|443]\n"
  . "  php core/php/sonde-mqtt-auxhome.php --controle [--port=8883]\n"
  . "  php core/php/sonde-mqtt-auxhome.php --connack [--protocole=3|4] [--config-id=<v> ...]\n"
  . "        [--port=8883|443] [--empreinte=<sha256>] [--accepter-certificat-invalide] [--brut]\n";

$modeCertificat = false;
$modeControle = false;
$modeConnack = false;
$port = null;
$protocole = 4;
$configIdsSupplementaires = array();
$empreinte = null;
$accepterCertificatInvalide = false;
$brut = false;

foreach (array_slice($argv, 1) as $argument) {
  if ($argument === '--certificat') {
    $modeCertificat = true;
  } elseif ($argument === '--controle') {
    $modeControle = true;
  } elseif ($argument === '--connack') {
    $modeConnack = true;
  } elseif (strpos($argument, '--port=') === 0) {
    $port = substr($argument, strlen('--port='));
  } elseif (strpos($argument, '--protocole=') === 0) {
    $protocole = substr($argument, strlen('--protocole='));
  } elseif (strpos($argument, '--config-id=') === 0) {
    $configIdsSupplementaires[] = substr($argument, strlen('--config-id='));
  } elseif (strpos($argument, '--empreinte=') === 0) {
    $empreinte = substr($argument, strlen('--empreinte='));
  } elseif ($argument === '--accepter-certificat-invalide') {
    $accepterCertificatInvalide = true;
  } elseif ($argument === '--brut') {
    $brut = true;
  } else {
    die('Option inconnue : ' . $argument . "\n" . $usage);
  }
}

if ((int) $modeCertificat + (int) $modeControle + (int) $modeConnack !== 1) {
  die("Precisez exactement un mode : --certificat, --controle ou --connack.\n" . $usage);
}

// --- 7.1 Validation des arguments -----------------------------------------------------

if ($port === null) {
  $port = 8883;
} else {
  if (!ctype_digit((string) $port)) {
    die("--port doit etre un entier.\n" . $usage);
  }
  $port = (int) $port;
  if ($port === 1883) {
    die("Le port 1883 (MQTT en clair) est refuse : le jeton de session y transiterait en clair.\n");
  }
  if ($port !== 8883 && $port !== 443) {
    die("--port doit valoir 8883 ou 443.\n" . $usage);
  }
}

if (!ctype_digit((string) $protocole) || ((int) $protocole !== 3 && (int) $protocole !== 4)) {
  die("--protocole doit valoir 3 ou 4.\n" . $usage);
}
$protocole = (int) $protocole;

foreach ($configIdsSupplementaires as $candidatSupplementaire) {
  if (preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $candidatSupplementaire) !== 1) {
    die("--config-id doit respecter [A-Za-z0-9-] sur 64 caracteres au plus (il entre dans une chaine envoyee a un tiers).\n");
  }
}
// 4 candidats par defaut (reference/aleatoire/vide/uid, § 6.6) sont TOUJOURS presents,
// --config-id ne fait qu'en AJOUTER — jamais les remplacer, sans quoi la valeur de
// reference pourrait manquer et fausser conclusion() (§ 6.4).
if (count($configIdsSupplementaires) > MQTT_MAX_CANDIDATS - 4) {
  die('--config-id : ' . (MQTT_MAX_CANDIDATS - 4) . ' candidats supplementaires au plus (4 candidats par defaut + ' . (MQTT_MAX_CANDIDATS - 4) . ' = ' . MQTT_MAX_CANDIDATS . ' maximum).' . "\n");
}

if ($empreinte !== null && preg_match('/\A[A-Fa-f0-9]{1,64}\z/', $empreinte) !== 1) {
  die("--empreinte doit etre une chaine hexadecimale de 64 caracteres au plus.\n");
}

// --- 7.2 Gardes metier et d'environnement ----------------------------------------------

if (!in_array('ssl', stream_get_transports(), true)) {
  die("L extension openssl (transport ssl://) n est pas disponible sur ce PHP — impossible de sonder le broker.\n");
}

if ($modeConnack && !smartclim::compteConfigure()) {
  die("Compte AUX Home non configure (e-mail / mot de passe / pays) : rien a sonder.\n");
}

// --- Execution ---------------------------------------------------------------------------

try {
  if ($modeCertificat) {
    echo "Verification TLS pleine (peer + nom d hote)...\n";
    $infosCertVerifie = array();
    $fluxVerifie = ouvrirFlux(MQTT_HOTE_EU, $port, true, null, $infosCertVerifie);
    if ($fluxVerifie !== false) {
      @fclose($fluxVerifie);
      echo "SUCCES : le certificat de " . MQTT_HOTE_EU . ':' . $port . " verifie integralement. Aucune derogation n est necessaire (D-1 devient sans objet) — passer directement a --connack sans --accepter-certificat-invalide.\n";
      exit(0);
    }
    echo "ECHEC de la verification pleine — nouvelle tentative en capture, sans verification, pour afficher l anomalie (donnees PUBLIQUES uniquement, aucun secret n est envoye) :\n\n";
    $infosCertCapture = array();
    $fluxCapture = ouvrirFlux(MQTT_HOTE_EU, $port, false, null, $infosCertCapture);
    if ($fluxCapture === false) {
      echo "Impossible d ouvrir meme une connexion TLS sans verification vers " . MQTT_HOTE_EU . ':' . $port . " — l hote est peut-etre injoignable.\n";
      exit(1);
    }
    @fclose($fluxCapture);
    echo texteCertificat($infosCertCapture);
    exit(0);
  }

  if ($modeControle) {
    // Test de CONTROLE : aucun secret, aucune derogation. Broker au CN, certificat
    // VALIDE et couvrant son nom d hote (§ 2.3) — prouve que notre construction de
    // paquet est correcte AVANT de toucher au canal EU (§ 11, R6).
    echo "Test de controle vers " . MQTT_HOTE_CONTROLE . ':' . $port . " (identifiants bidons, aucune derogation TLS)...\n";
    $params = array(
      'hote' => MQTT_HOTE_CONTROLE,
      'port' => $port,
      'verifier' => true,
      'empreinte' => null,
      'clientId' => 'usr0000000000',
      'user' => '2$controle-sonde$0000000000',
      'password' => 'controle-sonde-valeur-bidon',
      'niveau' => 4,
      'keepAlive' => 120,
    );
    $resultat = tenterCandidat($params);
    echo '  -> ' . libelleEtatResultat($resultat['etat']);
    if ($resultat['etat'] === 'connack') {
      echo ' (brut ' . $resultat['brut_hex'] . ')';
    }
    echo "\n\n";
    if ($resultat['etat'] === 'connack' && $resultat['brut_hex'] === '20020004') {
      echo "ATTENDU (20 02 00 04) : la construction du paquet CONNECT est correcte. Passer a --connack peut se faire en confiance dans la construction du paquet.\n";
      exit(0);
    }
    echo "INATTENDU : ce n est PAS le resultat connu (20 02 00 04). NE PAS passer a --connack sans corriger : cela mesurerait notre propre bug, pas le broker EU.\n";
    exit(1);
  }

  // $modeConnack
  if (!$accepterCertificatInvalide) {
    echo "Verification TLS pleine requise par defaut. Lancez d abord --certificat pour savoir si elle passe.\n";
    echo "Si elle echoue et que vous acceptez la derogation D-1 (cadree, temporaire, jamais implicite — cf. l en-tete de ce fichier), relancez avec --accepter-certificat-invalide.\n";
    exit(1);
  }

  echo "⚠️  DEROGATION TLS ACTIVE (D-1) : la verification du certificat est DESACTIVEE pour ce test. Un jeton de session REEL va transiter sur un canal dont l identite du pair n est PAS verifiee (§ 11, R2 de la spec technique).\n";
  echo "⚠️  Pensez a changer le mot de passe AUX Home juste apres ce test (etape 6 du protocole de recette).\n";
  echo "⚠️  Si l application AUX officielle est ouverte sur un telephone, ce test peut la deconnecter momentanement (§ 11, R11).\n\n";

  $session = smartclimAuxHomeApi::session();
  $uid = isset($session['uid']) ? (string) $session['uid'] : '';
  if ($uid === '') {
    die("Le login a reussi mais le backend n a pas renvoye d uid — voir le warning dans les logs du plugin.\n");
  }
  $jeton = $session['jeton'];

  $candidats = array(
    array('etiquette' => 'reference', 'configId' => MQTT_CONFIG_ID_REFERENCE),
    array('etiquette' => 'aleatoire', 'configId' => genererUuidV4()),
    array('etiquette' => 'vide', 'configId' => ''),
    array('etiquette' => 'uid', 'configId' => $uid),
  );
  foreach ($configIdsSupplementaires as $indice => $candidatSupplementaire) {
    if (count($candidats) >= MQTT_MAX_CANDIDATS) {
      break;
    }
    $candidats[] = array('etiquette' => 'supplementaire#' . ($indice + 1), 'configId' => $candidatSupplementaire);
  }

  $clientId = 'usr' . $uid;
  // Ecart consigne au § 7.1 de smartclim-transport-aux-home.md : la reference construit
  // en realite usr<uid>_ha_<6 derniers caracteres du device_id> ; sans device_id cote
  // EU, ce script utilise le repli usr<uid> — confondant possible d un 0x02 homogene,
  // traite explicitement par conclusion() ci-dessus.

  // Une execution ne teste QU UN SEUL niveau de protocole (celui de --protocole, 4 par
  // defaut) — conforme au § 6.6 de la spec technique et au protocole de recette § 10,
  // qui prevoit deux invocations SEPAREES (protocole 4 par defaut, puis --protocole=3
  // "si tout rend 0x04"). Consequence assumee et voulue : conclusion() ci-dessous, dont
  // la 3e condition exige les DEUX niveaux essayes, ne peut donc JAMAIS afficher de
  // conclusion negative au sein d une seule execution — elle affiche ce qui manque, et
  // c est a l operateur d agreger les deux executions dans le rapport ecrit (§ 7.2/7.7).
  // Une conclusion POSITIVE (0x00), elle, est immediate des le premier niveau essaye.
  $resultats = array();
  $protocolesEssayes = array($protocole);
  $echeanceSerie = microtime(true) + MQTT_BUDGET_SERIE;
  $budgetEpuise = false;

  foreach ($candidats as $candidat) {
    if (microtime(true) >= $echeanceSerie) {
      $budgetEpuise = true;
      break;
    }
    $user = '2$' . $candidat['configId'] . '$' . $uid;
    $params = array(
      'hote' => MQTT_HOTE_EU,
      'port' => $port,
      'verifier' => false,
      'empreinte' => $empreinte,
      'clientId' => $clientId,
      'user' => $user,
      'password' => $jeton,
      'niveau' => $protocole,
      'keepAlive' => 120,
    );
    $resultat = tenterCandidat($params);
    $resultat['etiquette'] = $candidat['etiquette'];
    $resultat['configId'] = $candidat['configId'];
    $resultat['niveau'] = $protocole;
    $resultat['port'] = $port;
    $resultat['clientId'] = $clientId;
    $resultat['user'] = $user;
    $resultats[] = $resultat;
    if ($resultat['etat'] === 'connack' && $resultat['code'] === 0x00) {
      break;
    }
    sleep(MQTT_PAUSE_TENTATIVE);
  }

  $correspondancesMasque = array();
  if ($brut) {
    echo "⚠️  --brut : le uid du compte sera affiche EN CLAIR ci-dessous — a garder pour soi.\n";
  }

  echo "Resultats (le candidat [uid] est masque sauf --brut ; le jeton n est JAMAIS affiche) :\n";
  foreach ($resultats as $resultat) {
    echo ligneResultat($resultat, $correspondancesMasque, $brut);
  }
  if ($budgetEpuise) {
    echo "\n⚠️  Budget de serie (" . MQTT_BUDGET_SERIE . " s) epuise avant la fin des candidats — ceci n est PAS un resultat negatif, relancer pour completer la mesure.\n";
  }
  echo "\n" . conclusion($resultats, $protocolesEssayes);

  // Contrat de sortie (§ 6.6 de la spec technique) : 0 si un CONNACK a ete lu (le fait
  // est produit, quel qu'en soit le code), 1 sinon — « accepte » contre « refuse » n est
  // PAS le code de sortie, la mesure reussit dans les deux cas.
  $unConnackLu = false;
  foreach ($resultats as $resultat) {
    if ($resultat['etat'] === 'connack') {
      $unConnackLu = true;
      break;
    }
  }

  smartclimAuxHomeApi::purgerSession();
  echo "\nSession AUX Home purgee du cache local (rappel : cela ne revoque RIEN cote serveur, § 11 R2 — changez le mot de passe).\n";
  exit($unConnackLu ? 0 : 1);
} catch (smartclimException $e) {
  // Message DEJA CURATE en francais (contrat de smartclimAuxHomeApi::session()) :
  // affichable tel quel, aucun secret ni detail technique.
  die('Echec : ' . $e->getMessage() . "\n");
} catch (Throwable $e) {
  log::add('smartclim', 'error', 'sonde-mqtt-auxhome.php : erreur interne : ' . get_class($e) . ' : ' . smartclim::neutraliserPourLog($e->getMessage()));
  die("Echec : erreur interne, consultez les logs du plugin.\n");
}
