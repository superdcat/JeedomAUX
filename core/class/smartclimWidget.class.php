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
 * Assemblage de la charge du widget de tuile « climatiseur » (UC01 du domaine
 * post-mvp/06-ergonomie-jeedom). Même statut que smartclimDiagnostic : mise en forme
 * PURE, aucune E/S, aucun config::, aucun cache::, aucun réseau, aucun cmd::save()/
 * eqLogic::save(). Le SEUL accès au modèle est une lecture ($_eqLogic->getCmd(null,
 * null) et les valeurs déjà en cache des commandes info) — jamais une écriture.
 *
 * Un seul appelant : smartclimCmd::toHtml(), au moment du rendu d'une commande portant
 * smartclim::WIDGET_TUILE. La charge produite est injectée dans le canal $_options de
 * cmd::toHtml() (§ 1.3/1.4 de la spec technique), encodée en base64 pour rester hors
 * d'atteinte de jeedom::toHumanReadable() (qui ne réécrit que #\d*#, #eqLogic\d+#,
 * #scenario\d+#, #object\d+#) et des remplacements #clé# du template.
 *
 * ⚠️ GARDE FONCTIONNELLE NON NÉGOCIABLE (§ 5 de la spec technique) : valeurInfo() teste
 * getType() === 'info' AVANT tout execCmd(). Un execCmd() sur une commande ACTION
 * actionnerait le matériel — donc allumerait le climatiseur au simple affichage du
 * dashboard.
 */
class smartclimWidget {
  /*     * *************************Attributs****************************** */

  // Classes FontAwesome par MODE générique (D5, § 3 de la spec technique) : présentation
  // pure, jamais dans smartclimCapabilities (qui ne connaît aucune notion d'icône). Code
  // inconnu => '' (aucune icône), jamais d'erreur.
  private static $_iconesMode = array(
    smartclimCapabilities::MODE_AUTO => 'fas fa-sync-alt',
    smartclimCapabilities::MODE_COOL => 'fas fa-snowflake',
    smartclimCapabilities::MODE_HEAT => 'fas fa-fire',
    smartclimCapabilities::MODE_DRY => 'fas fa-tint',
    smartclimCapabilities::MODE_FAN => 'fas fa-wind',
  );

  /*     * ***********************Methode static*************************** */

  /**
   * Charge utile de la tuile : ids, libellés DÉJÀ traduits, valeurs courantes, bornes
   * (§ 6 de la spec technique). Une SEULE lecture getCmd(null, null) (R7). Renvoie
   * array() si l'équipement n'est pas exploitable (pas de commande info 'power' — D2 :
   * un profil sans CONCEPT_POWER n'obtient de toute façon ni 'on' ni 'off').
   *
   * @param smartclim $_eqLogic
   * @return array
   */
  public static function chargeTuile($_eqLogic) {
    if (!($_eqLogic instanceof smartclim)) {
      return array();
    }

    $commandes = array();
    foreach ($_eqLogic->getCmd(null, null) as $cmd) {
      $commandes[$cmd->getLogicalId()] = $cmd;
    }

    if (!isset($commandes[smartclimCapabilities::CONCEPT_POWER]) || $commandes[smartclimCapabilities::CONCEPT_POWER]->getType() !== 'info') {
      return array();
    }

    $infos = array();
    $clesInfo = array(
      smartclimCapabilities::CONCEPT_POWER,
      smartclimCapabilities::CONCEPT_MODE,
      smartclimCapabilities::CONCEPT_FAN_SPEED,
      smartclimCapabilities::CONCEPT_AMBIENT_TEMP,
      smartclimCapabilities::CONCEPT_TARGET_TEMP,
      smartclimCapabilities::CONCEPT_ONLINE,
      smartclim::CMD_TRANSPORT,
      smartclim::CMD_DERNIERE_MAJ,
    );
    foreach ($clesInfo as $logicalId) {
      if (!isset($commandes[$logicalId]) || $commandes[$logicalId]->getType() !== 'info') {
        continue;
      }
      $cmd = $commandes[$logicalId];
      $infos[$logicalId] = array(
        'id' => (int) $cmd->getId(),
        'valeur' => self::valeurInfo($cmd),
        'unite' => (string) $cmd->getUnite(),
        'nom' => (string) $cmd->getName(),
        'date' => (string) $cmd->getValueDate(),
      );
    }

    $actions = array();
    $clesAction = array(
      'on' => smartclim::CMD_ON,
      'off' => smartclim::CMD_OFF,
      'consigne' => smartclim::CMD_CONSIGNE,
    );
    foreach ($clesAction as $cleCharge => $logicalId) {
      if (!isset($commandes[$logicalId]) || $commandes[$logicalId]->getType() !== 'action') {
        continue;
      }
      $actions[$cleCharge] = array(
        'id' => (int) $commandes[$logicalId]->getId(),
        'nom' => (string) $commandes[$logicalId]->getName(),
      );
    }

    // Modes et vitesses NE SONT PAS dérivés d'un catalogue de transport (jamais de
    // smartclimCapabilities::valeursLisibles() ici) : ils sont lus depuis les commandes
    // action RÉELLEMENT créées par definitionsCommandesAction() (privée à smartclim,
    // donc jamais dupliquée ici). C'est ce qui garantit AC3/AC4/AC7 SANS dupliquer la
    // logique de profil : un mode absent du profil n'a simplement pas de commande.
    $modes = array();
    $vitesses = array();
    foreach ($commandes as $logicalId => $cmd) {
      if ($cmd->getType() !== 'action') {
        continue;
      }
      if (strpos($logicalId, smartclim::PREFIXE_CMD_MODE) === 0) {
        $code = strtoupper(substr($logicalId, strlen(smartclim::PREFIXE_CMD_MODE)));
        $libelle = smartclimCapabilities::libelle(smartclimCapabilities::CONCEPT_MODE, $code);
        if ($libelle === '') {
          continue;
        }
        $modes[] = array(
          'id' => (int) $cmd->getId(),
          'code' => $code,
          'libelle' => $libelle,
          'icone' => self::iconeMode($code),
          'nom' => (string) $cmd->getName(),
          'ordre' => (int) $cmd->getOrder(),
        );
      } elseif (strpos($logicalId, smartclim::PREFIXE_CMD_VITESSE) === 0) {
        $code = strtoupper(substr($logicalId, strlen(smartclim::PREFIXE_CMD_VITESSE)));
        $libelle = smartclimCapabilities::libelle(smartclimCapabilities::CONCEPT_FAN_SPEED, $code);
        if ($libelle === '') {
          continue;
        }
        $vitesses[] = array(
          'id' => (int) $cmd->getId(),
          'code' => $code,
          'libelle' => $libelle,
          'nom' => (string) $cmd->getName(),
          'ordre' => (int) $cmd->getOrder(),
        );
      }
    }
    usort($modes, function ($_a, $_b) {
      return $_a['ordre'] - $_b['ordre'];
    });
    usort($vitesses, function ($_a, $_b) {
      return $_a['ordre'] - $_b['ordre'];
    });
    foreach ($modes as &$mode) {
      unset($mode['ordre']);
    }
    unset($mode);
    foreach ($vitesses as &$vitesse) {
      unset($vitesse['ordre']);
    }
    unset($vitesse);

    $bornes = $_eqLogic->bornesTemperature();

    return array(
      // Version de FORME de la charge (schéma), pas une version d'écran : défensif côté
      // client, jamais consommé côté serveur. Avec 'eqLogic.id' ci-dessous, ce champ n'est
      // volontairement lu par aucun des deux templates de cette UC : il anticipe l'UC03 du
      // domaine (page-panneau multi-climatiseurs), qui distinguera les tuiles entre elles.
      'version' => 1,
      'eqLogic' => array(
        'id' => (int) $_eqLogic->getId(),
        'nom' => (string) $_eqLogic->getHumanName(),
      ),
      'infos' => $infos,
      'actions' => array_merge($actions, array(
        'modes' => $modes,
        'vitesses' => $vitesses,
      )),
      'bornes' => array(
        'min' => $bornes['min'],
        'max' => $bornes['max'],
        'pas' => $bornes['pas'],
      ),
      'libelles' => array(
        'hors_ligne' => smartclim::libelleEnLigne(false),
        'moins' => __('Diminuer la consigne', __FILE__),
        'plus' => __('Augmenter la consigne', __FILE__),
      ),
    );
  }

  /**
   * json_encode(array(smartclim::JETON_TUILE => base64_encode(json_encode(chargeTuile))))
   * prêt pour parent::toHtml() (§ 1.3/6 de la spec technique). '' si l'équipement n'est
   * pas exploitable.
   *
   * @param smartclim $_eqLogic
   * @return string
   */
  public static function optionsTuile($_eqLogic) {
    $charge = self::chargeTuile($_eqLogic);
    if (empty($charge)) {
      return '';
    }
    $encodee = json_encode($charge);
    if ($encodee === false) {
      // json_encode() renvoie false sur une chaîne non-UTF-8 (nom d'équipement/commande
      // corrompu) : mode dégradé côté template plutôt qu'un base64_encode(false) invalide
      // (dépréciation PHP 8.1+, argument bool là où une chaîne est attendue).
      return '';
    }
    return json_encode(array(smartclim::JETON_TUILE => base64_encode($encodee)));
  }

  /**
   * Classe FontAwesome d'un MODE générique ; '' si inconnu (jamais d'erreur, D5).
   *
   * @param string $_codeGenerique
   * @return string
   */
  private static function iconeMode($_codeGenerique) {
    return isset(self::$_iconesMode[$_codeGenerique]) ? self::$_iconesMode[$_codeGenerique] : '';
  }

  /**
   * Valeur courante d'une commande INFO ; '' si $_cmd n'est pas une info. GARDE
   * FONCTIONNELLE (§ 5 de la spec technique) : un execCmd() sur une commande action
   * actionnerait le matériel au simple affichage du dashboard.
   *
   * @param cmd $_cmd
   * @return string
   */
  private static function valeurInfo($_cmd) {
    if (!($_cmd instanceof cmd) || $_cmd->getType() !== 'info') {
      return '';
    }
    return (string) $_cmd->execCmd();
  }

  /*     * *********************Methode d'instance************************* */
}
