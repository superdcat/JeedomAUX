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

/**
 * Exception typée du plugin SmartClim (cf.
 * .memory/specs/MVP/02-client-aux-home-authentification-tech.md § 1.1), avec DEUX
 * usages distincts selon qui la construit — corrigé après revue croisée (le docblock
 * initial affirmait à tort que $_message n'était "jamais affiché", ce qui est faux
 * pour le second cas) :
 * 1. Levée par la brique de transport (smartclimAuxHomeApi) : $_message est un
 *    diagnostic TECHNIQUE interne (code HTTP, code métier AUX, texte d'erreur
 *    OpenSSL) — jamais un secret, et jamais affiché tel quel à l'utilisateur.
 * 2. Levée par smartclim:: (testerConnexionAuxHome(), les deux gardes "zéro
 *    requête") : $_message EST le texte curaté et traduit, destiné à l'affichage.
 * Le passage du cas 1 au cas 2 se fait EXCLUSIVEMENT via
 * smartclim::messageErreurAuxHome(), seul endroit du plugin où vivent les __() de
 * message d'erreur AUX Home (cf. spec technique § 5).
 *
 * ⚠️ Fichier PROPRE, obligatoire (règle d'autoload Jeedom, CLAUDE.md § Conventions) :
 * core/ajax/smartclim.ajax.php est un point d'entrée externe qui fait
 * `catch (smartclimException $e)` — l'autoloader mappe 1 classe à 1 fichier
 * <NomClasse>.class.php ; un appel direct à une classe sans son propre fichier depuis
 * un point d'entrée externe provoquerait un "Class not found" au runtime.
 *
 * 🚫 Ni le cas 1 ni le cas 2 ne doivent jamais porter un secret (mot de passe, jeton
 * complet, champ "account" chiffré) : core/ajax/smartclim.ajax.php lit
 * $e->getMessage() sur l'exception qu'il intercepte, jamais via displayException().
 */
class smartclimException extends Exception {
  // Cloud injoignable : échec cURL (DNS, timeout, TLS, connexion refusée) ou HTTP
  // >= 500 ou HTTP 429 (rate-limit). Ce n'est jamais un problème d'identifiants.
  const TYPE_RESEAU = 1;

  // Refus d'authentification : HTTP < 500 et code métier AUX != 200 sur le login,
  // hors codes connus (9023/64033). Message utilisateur : vérifier les identifiants
  // ET le pays sélectionné.
  const TYPE_AUTH = 2;

  // Réponse inattendue du service (notre requête est mal formée, ou le contrat
  // d'enveloppe HTTP n'est pas respecté) : corps non-JSON, enveloppe absente, jeton
  // absent, code métier AUX 9023 (chiffrement invalide) ou 64033 (clé publique
  // périmée), ou code métier != 200 sur la requête initiale (getPubkey).
  const TYPE_PROTOCOLE = 3;

  // Échec de préparation cryptographique LOCAL (PEM inexploitable, OpenSSL en échec) :
  // le plugin n'a même pas réussi à construire la requête de login.
  const TYPE_INTERNE = 4;

  // Contexte technique : seule valeur non vide utilisée aujourd'hui, pour permettre à
  // smartclim::messageErreurAuxHome() de distinguer le seul cas où le message final
  // dépend de l'endpoint plutôt que du seul type (un code métier inconnu sur la
  // requête initiale doit inviter à vérifier le pays, puisque cet appel envoie déjà
  // l'en-tête "country" ; le même cas sur le login reste générique). Nom NEUTRE
  // délibérément choisi (pas de nom d'endpoint du protocole type "getPubkey") : ce
  // dernier doit rester confiné à la brique de transport (CLAUDE.md § Conventions),
  // pas fuiter jusqu'à smartclim.class.php via une chaîne magique partagée.
  const CONTEXTE_REQUETE_INITIALE = 'requete_initiale';

  /**
   * Contexte technique optionnel, '' par défaut, ou smartclimException::CONTEXTE_REQUETE_INITIALE.
   *
   * @var string
   */
  private $contexte;

  /**
   * @param string $_message Cf. les 2 cas d'usage ci-dessus.
   * @param int $_type Une des constantes TYPE_* ci-dessus.
   * @param string $_contexte '' par défaut, ou self::CONTEXTE_REQUETE_INITIALE.
   */
  public function __construct($_message, $_type, $_contexte = '') {
    parent::__construct($_message, $_type);
    $this->contexte = $_contexte;
  }

  /**
   * @return int Une des constantes TYPE_* ci-dessus.
   */
  public function getType() {
    return $this->getCode();
  }

  /**
   * @return string '' ou self::CONTEXTE_REQUETE_INITIALE.
   */
  public function getContexte() {
    return $this->contexte;
  }
}
