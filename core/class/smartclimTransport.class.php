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

/**
 * UC01 du domaine post-mvp/02-strategies-de-transport (§ 1.2/5.1 de sa spec technique) :
 * couche de DÉCISION PURE du mode de transport par équipement (AUTO/LOCAL/CLOUD). Même
 * statut que smartclimCapabilities et smartclimFrame : aucune E/S, aucun socket, aucun
 * cURL, aucune écriture de cache, aucun config::save, aucun eqLogic->save(). Toutes les
 * entrées transitent par des accesseurs publics DÉJÀ existants
 * (smartclim::compteConfigure(), $eqLogic->adresseLan(), $eqLogic->sondeLanEquipement(),
 * $eqLogic->getConfiguration(), et depuis l'UC02 de ce domaine $eqLogic->echecsLanConsecutifs())
 * — cette classe ne fait QUE les combiner.
 *
 * ⚠️ Aucune méthode ne lève : une décision de transport ne doit jamais faire échouer une
 * commande ou un cycle — normaliserMode() en particulier accepte toute entrée, y compris
 * non scalaire, et retombe toujours sur MODE_AUTO.
 */
class smartclimTransport {
  /*     * *************************Attributs****************************** */

  const MODE_AUTO = 'auto';
  const MODE_LOCAL = 'local';
  const MODE_CLOUD = 'cloud';

  // UC02 du domaine post-mvp/02-strategies-de-transport (§ 4.1 de sa spec technique) :
  // constantes de POLITIQUE de repli — SEUIL_ECHECS_LAN aligné sur LAN_FAILURE_THRESHOLD
  // (.memory/analyse/smartclim-transport-broadlink-lan.md § 7). DELAI_MAX_CLOUD est un
  // plafond de FRÉQUENCE des tentatives cloud, JAMAIS un nombre maximal d'essais — un
  // abandon définitif rendrait l'équipement impilotable.
  const SEUIL_ECHECS_LAN = 3;
  const DELAI_BASE_CLOUD = 30;  // secondes
  const DELAI_MAX_CLOUD = 300;  // secondes

  /*     * ***********************Methode static*************************** */

  /**
   * Modes proposables, code => libellé français déjà traduit, ORDRE d'affichage FIGÉ
   * (AUTO/LOCAL/CLOUD) — c'est cet ordre qui garantit que AUTO est la première
   * `<option>` du `<select>` (§ 4 de la spec technique : `.val(undefined)` sur un
   * `<select>` laisse la liste sur son premier item, un enregistrement suivant
   * écrirait alors cette valeur — même piège que la liste Pays de
   * plugin_info/configuration.php).
   *
   * @return array<string,string>
   */
  public static function modes() {
    return array(
      self::MODE_AUTO => __('Automatique', __FILE__),
      self::MODE_LOCAL => __('Local', __FILE__),
      self::MODE_CLOUD => __('Cloud', __FILE__),
    );
  }

  /**
   * Normalise une valeur de mode de transport — barrière AUTORITAIRE et SILENCIEUSE
   * (même patron que smartclim::normaliserPays()/normaliserIntervalle()) : ne lève
   * JAMAIS, tolère toute entrée (y compris non scalaire), défaut MODE_AUTO.
   *
   * @param mixed $_valeur
   * @return string Une des constantes MODE_*.
   */
  public static function normaliserMode($_valeur) {
    if (!is_scalar($_valeur)) {
      return self::MODE_AUTO;
    }
    $valeur = (string) $_valeur;
    if (in_array($valeur, array(self::MODE_AUTO, self::MODE_LOCAL, self::MODE_CLOUD), true)) {
      return $valeur;
    }
    return self::MODE_AUTO;
  }

  /**
   * Mode de transport de CET équipement — barrière de LECTURE (repasse par
   * normaliserMode() : une valeur corrompue en base, malgré preSave(), n'est jamais
   * renvoyée telle quelle).
   *
   * @param smartclim $_eqLogic
   * @return string Une des constantes MODE_*.
   */
  public static function mode(smartclim $_eqLogic) {
    return self::normaliserMode($_eqLogic->getConfiguration(smartclim::CLE_CONF_TRANSPORT_MODE));
  }

  /**
   * Libellé français déjà traduit du mode de transport de cet équipement.
   *
   * @param smartclim $_eqLogic
   * @return string
   */
  public static function libelleMode(smartclim $_eqLogic) {
    $modes = self::modes();
    $mode = self::mode($_eqLogic);
    return isset($modes[$mode]) ? $modes[$mode] : $modes[self::MODE_AUTO];
  }

  /**
   * Joignabilité LAN de cet équipement — ZÉRO réseau, ZÉRO timeout : lecture PURE de la
   * mémoire de sonde déjà en cache (§ 6 de la spec technique UC01) et, depuis l'UC02 de
   * ce domaine (§ 4.2 de sa spec technique), du compteur d'échecs consécutifs. Toujours
   * PLUS STRICTE que smartclim::statutEnEchec() : ce dernier compte STATUT_ETABLIE /
   * STATUT_REUTILISEE / STATUT_ETAT_ILLISIBLE comme des succès, or aucun des trois ne
   * prouve que l'appareil parle le HVAC — router un ordre vers un appareil
   * ETAT_ILLISIBLE ferait échouer smartclimFrame::encoderOrdre() au lieu de partir au
   * cloud. STATUT_ETAT_LU est la MÊME preuve que celle qui autorise la création d'un
   * équipement depuis le LAN (UC04 post-mvp/01, § 5.5 de sa spec technique).
   *
   * ⚠️⚠️ Étape 4 ci-dessous, INVERSÉE (renvoie VRAI quand une série d'échecs est EN
   * COURS, 0 < echecs < SEUIL) — invariant CENTRAL de l'UC02 de ce domaine (§ 4.2 de sa
   * spec technique) : elle n'est sûre QUE parce que smartclim::memoriserEchecLan()
   * refuse d'incrémenter tant qu'aucune preuve STATUT_ETAT_LU n'a jamais été constatée
   * (`preuve === 0`, no-op). Sans ce garde-fou, un appareil qui n'a JAMAIS parlé
   * Broadlink (ex. une lan_ip saisie sur un appareil qui ignore le protocole) verrait son
   * compteur passer à 1 au 1er échec de sonde, et cette étape le déclarerait joignable —
   * routant un ordre réel vers un appareil qui n'a jamais répondu.
   *
   * @param smartclim $_eqLogic
   * @return bool
   */
  public static function lanJoignable(smartclim $_eqLogic) {
    $adresse = $_eqLogic->adresseLan();
    if (!isset($adresse['ip']) || $adresse['ip'] === '') {
      return false;
    }
    $echecs = $_eqLogic->echecsLanConsecutifs();
    if ($echecs >= self::SEUIL_ECHECS_LAN) {
      return false;
    }
    $sonde = $_eqLogic->sondeLanEquipement();
    if (is_array($sonde) && isset($sonde['statut']) && $sonde['statut'] === smartclimBroadlinkLan::STATUT_ETAT_LU) {
      return true;
    }
    // Étape 4 — cf. avertissement ci-dessus : VRAI si une série d'échecs (< SEUIL) est
    // en cours, car garantie par memoriserEchecLan() de reposer sur une preuve déjà
    // constatée.
    return $echecs > 0;
  }

  /**
   * Repli CLOUD actif pour cet équipement (UC02 de ce domaine, § 4.3 de sa spec
   * technique) : AUTO + seuil d'échecs LAN atteint + cloud disponible pour CET appareil.
   * Le terme cloudDisponible() est ce qui rend AC4 visible : un équipement sans
   * identifiant cloud n'est jamais « en repli », il est BLOQUÉ en LAN.
   *
   * @param smartclim $_eqLogic
   * @return bool
   */
  public static function repliCloudActif(smartclim $_eqLogic) {
    return self::mode($_eqLogic) === self::MODE_AUTO
      && $_eqLogic->echecsLanConsecutifs() >= self::SEUIL_ECHECS_LAN
      && self::cloudDisponible($_eqLogic);
  }

  /**
   * Temporisation cloud (secondes) pour un nombre d'échecs cloud consécutifs donné
   * (UC02 de ce domaine, § 4.4 de sa spec technique — disjoncteur à demi-ouverture) :
   * 30 · 60 · 120 · 240 · 300 · 300 … Fonction PURE, ne lève jamais.
   *
   * ⚠️ Le plafond DELAI_MAX_CLOUD porte sur la FRÉQUENCE des tentatives, jamais sur un
   * nombre total d'essais : un abandon définitif rendrait l'équipement impilotable.
   *
   * @param int $_echecs
   * @return int
   */
  public static function delaiTemporisationCloud($_echecs) {
    $echecs = (int) $_echecs;
    if ($echecs <= 0) {
      return 0;
    }
    return (int) min(self::DELAI_MAX_CLOUD, self::DELAI_BASE_CLOUD * pow(2, $echecs - 1));
  }

  /**
   * Disponibilité CLOUD de cet équipement (§ 5.1 de la spec technique — réponse à AC3) :
   * le compte cloud doit être configuré (global au plugin) ET cet équipement précis
   * doit être relié à un appareil AUX Home (`auxhome_device_id`, par équipement).
   *
   * @param smartclim $_eqLogic
   * @return bool
   */
  public static function cloudDisponible(smartclim $_eqLogic) {
    if (!smartclim::compteConfigure()) {
      return false;
    }
    $identifiant = $_eqLogic->getConfiguration('auxhome_device_id');
    return is_string($identifiant) && $identifiant !== '';
  }

  /**
   * Disponibilité du cloud HISTORIQUE (AC Freedom) pour cet équipement (UC03 du domaine
   * post-mvp/03-cloud-aux-legacy, § 3.6/D6 de sa spec technique) — pendant EXACT de
   * cloudDisponible() ci-dessus, deux termes dont un par équipement : le compte legacy
   * doit être configuré (global au plugin) ET cet équipement précis doit être relié à
   * un appareil du cloud historique (`auxcloud_endpoint_id`, par équipement).
   *
   * @param smartclim $_eqLogic
   * @return bool
   */
  public static function cloudLegacyDisponible(smartclim $_eqLogic) {
    if (!smartclim::compteAuxCloudConfigure()) {
      return false;
    }
    $identifiant = $_eqLogic->getConfiguration(smartclim::CLE_CONF_AUXCLOUD_ENDPOINT_ID);
    return is_string($identifiant) && $identifiant !== '';
  }

  /**
   * true si le cycle CLOUD HISTORIQUE (smartclim::rafraichirAuxCloud()) doit lire l'état
   * de cet équipement (UC03 du domaine post-mvp/03-cloud-aux-legacy, § 3.6/D6 de sa
   * spec technique) — SIMPLE ÉGALITÉ à transportRetenu(), contrairement à
   * lectureCloudAutorisee() ci-dessus (qui autorise AUX Home même quand l'ordre part en
   * LAN, parce que son cycle est UNE requête pour tout le parc). ⚠️ Délibérément PLUS
   * STRICTE : le cycle legacy est O(N) — un équipement vu par AUX Home ET par le cloud
   * historique n'entre JAMAIS dans le cycle legacy (« jamais deux transports pour une
   * même opération », AC3 de l'UC02 du domaine post-mvp/02).
   *
   * @param smartclim $_eqLogic
   * @return bool
   */
  public static function lectureLegacyAutorisee(smartclim $_eqLogic) {
    return self::transportRetenu($_eqLogic) === smartclimCapabilities::TRANSPORT_AUX_CLOUD_LEGACY;
  }

  /**
   * Transport RETENU pour cet équipement — ne renvoie JAMAIS de chaîne vide (§ 5.1 de la
   * spec technique, ÉTENDUE par l'UC03 du domaine post-mvp/03-cloud-aux-legacy, § 3.6/D6
   * de sa spec technique). En MODE_CLOUD : AUX Home si disponible, SINON le cloud
   * historique si disponible, SINON AUX Home (repli INCHANGÉ — le message « compte non
   * configuré » reste préservé). En AUTO : LAN joignable -> AUX Home -> cloud historique
   * -> LAN (dernier repli, PAS une erreur dédiée) — c'est ce dernier repli qui rend AC3
   * (du domaine post-mvp/02) vrai sans écrire une seule ligne de message : un
   * équipement sans AUCUN identifiant cloud se comporte comme LOCAL.
   *
   * ⚠️ Non-régression GARANTIE PAR CONSTRUCTION (§ 3.6 de la spec technique UC03) : sur
   * un parc sans compte legacy, cloudLegacyDisponible() est faux PARTOUT, les deux
   * branches AJOUTÉES sont donc MORTES et le comportement reste STRICTEMENT identique.
   *
   * @param smartclim $_eqLogic
   * @return string Une des constantes smartclimCapabilities::TRANSPORT_*.
   */
  public static function transportRetenu(smartclim $_eqLogic) {
    $mode = self::mode($_eqLogic);
    if ($mode === self::MODE_LOCAL) {
      return smartclimCapabilities::TRANSPORT_BROADLINK_LAN;
    }
    if ($mode === self::MODE_CLOUD) {
      if (self::cloudDisponible($_eqLogic)) {
        return smartclimCapabilities::TRANSPORT_AUX_HOME;
      }
      if (self::cloudLegacyDisponible($_eqLogic)) {
        return smartclimCapabilities::TRANSPORT_AUX_CLOUD_LEGACY;
      }
      return smartclimCapabilities::TRANSPORT_AUX_HOME;
    }
    // MODE_AUTO.
    if (self::lanJoignable($_eqLogic)) {
      return smartclimCapabilities::TRANSPORT_BROADLINK_LAN;
    }
    if (self::cloudDisponible($_eqLogic)) {
      return smartclimCapabilities::TRANSPORT_AUX_HOME;
    }
    if (self::cloudLegacyDisponible($_eqLogic)) {
      return smartclimCapabilities::TRANSPORT_AUX_CLOUD_LEGACY;
    }
    return smartclimCapabilities::TRANSPORT_BROADLINK_LAN;
  }

  /**
   * true si le cycle cloud GLOBAL (smartclim::rafraichirAuxHome()) doit lire l'état de
   * cet équipement — faux uniquement en mode LOCAL (§ 5.4/6.1 de la spec technique) :
   * en AUTO, la lecture cloud reste maintenue même quand la commande part en LAN (le
   * cloud est le seul porteur de `online`, et le cycle est un seul appel pour tout le
   * compte — exclure un équipement n'économise aucune requête).
   *
   * @param smartclim $_eqLogic
   * @return bool
   */
  public static function lectureCloudAutorisee(smartclim $_eqLogic) {
    return self::mode($_eqLogic) !== self::MODE_LOCAL;
  }

  /**
   * true si une sonde/lecture LAN (scan manuel ou cycle périodique) est autorisée pour
   * cet équipement — faux uniquement en mode CLOUD (AC5, § 5.4 de la spec technique) :
   * aucun paquet LAN n'est jamais émis vers un équipement dont l'utilisateur a
   * explicitement choisi le cloud.
   *
   * @param smartclim $_eqLogic
   * @return bool
   */
  public static function sondeLanAutorisee(smartclim $_eqLogic) {
    return self::mode($_eqLogic) !== self::MODE_CLOUD;
  }
}
