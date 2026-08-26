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

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

  /* Fonction permettant l'envoi de l'entête 'Content-Type: application/json'
    En V3 : indiquer l'argument 'true' pour contrôler le token d'accès Jeedom
    En V4 : autoriser l'exécution d'une méthode 'action' en GET en indiquant le(s) nom(s) de(s) action(s) dans un tableau en argument
  */
    ajax::init();

    // Referme immédiatement la session PHP (fichier) : sans cela, toute autre requête
    // de la même session Jeedom (menu, autre onglet, autre appel AJAX) reste bloquée
    // derrière ce handler pendant toute la durée de l'appel réseau AUX Home (jusqu'à
    // ~18 s, cf. smartclimAuxHomeApi::BUDGET_LOGIN) — ajax::init() ne le fait pas.
    session_write_close();

    if (init('action') == 'testerConnexion') {
        ajax::success(array('message' => smartclim::testerConnexionAuxHome()));
    }

    if (init('action') == 'effacerIdentifiants') {
        ajax::success(array('message' => smartclim::effacerIdentifiantsAuxHome()));
    }

    if (init('action') == 'scannerClimatiseurs') {
        ajax::success(smartclim::scannerAuxHome());
    }

    // Sonde de diagnostic (outillage de reverse engineering, cf.
    // smartclimAuxHomeApi::sondeDiagnostic()). Aucun paramètre reçu du client : le
    // catalogue de routes est une donnée SERVEUR, le navigateur ne fait que déclencher.
    // C'est ce qui rend cette action exposable sans ouvrir un SSRF vers le cloud AUX.
    if (init('action') == 'sonderDiagnostic') {
        ajax::success(smartclim::sonderDiagnostic());
    }

    // Même neutralisation que dans le catch(Throwable) plus bas (is_scalar, filtre
    // imprimable, troncature courte) : $_GET['action']/$_POST['action'] vient du CLIENT,
    // au même titre que le "message" backend neutralisé par
    // smartclimAuxHomeApi::journaliserErreurBackend() — un "action" porteur d'un "\n"
    // forgerait sinon des lignes de log dans ce message d'exception (cohérence signalée
    // par la revue croisée, 2e tour : ce fichier durcissait déjà cette même valeur plus
    // bas, mais pas ici). Recalculée localement (pas de fonction globale partagée dans
    // ce fichier procédural, pour éviter tout risque de redéclaration) plutôt que
    // factorisée : la duplication de ces 3 lignes reste plus sûre qu'une fonction
    // top-niveau dans un point d'entrée AJAX.
    $actionRecue = init('action');
    $actionRecue = is_scalar($actionRecue) ? (string) $actionRecue : 'Array';
    $actionRecue = substr(preg_replace('/[^\x20-\x7E]/', ' ', $actionRecue), 0, 40);
    throw new Exception(__('Aucune méthode correspondante à', __FILE__) . ' : ' . $actionRecue);
    /*     * *********Catch exeption*************** */
}
catch (smartclimException $e) {
    // Message déjà curaté en français par smartclim::* (cf. smartclim.class.php) :
    // jamais displayException() sur une smartclimException, jamais le code métier AUX
    // ni le statut HTTP dans le code AJAX (uniquement le type 1..4, opaque côté client).
    ajax::error($e->getMessage(), $e->getType());
}
catch (Exception $e) {
    // UC08, AC7 (A7-4) : displayException() met la TRACE dans le DOM dès que
    // log::level <= 100. Une Exception née PENDANT executerRequete() (ex. un
    // log::add() en échec) porterait dans sa trace le jeton complet et le corps
    // chiffré (frame executerRequete($m, $c, $corps, $t, $jeton)). On perd le lien
    // "Show traces" en mode debug ; on garde le message, donc la diagnosticabilité.
    ajax::error($e->getMessage(), $e->getCode());
}
catch (Throwable $t) {
    // Toute Error PHP 7+/8 (TypeError, ValueError...) qui échapperait aux deux catch
    // ci-dessus (ex. un curl_setopt() appelé sur un handle false) ne doit jamais
    // produire une réponse non-JSON ni exposer un chemin ou une trace au client (finding
    // sécurité LOW de la revue croisée). Code figé à 0, jamais displayException().
    // La classe, le message et la position (fichier:ligne) restent dans le log 'error' ;
    // jamais la trace (getTraceAsString() porte les arguments de chaque frame, AC4) —
    // corrigé après revue croisée : la version précédente de ce commentaire affirmait à
    // tort que « la trace complète » restait journalisée, alors que le log ne portait
    // que le nom de l'action et rien du Throwable lui-même (finding MAJOR).
    //
    // $_GET['action']/$_POST['action'] vient du CLIENT : même traitement qu'une donnée
    // externe (is_scalar, filtre imprimable, troncature courte) qu'un champ backend
    // neutralisé ailleurs dans ce plugin (journaliserErreurBackend()) — un "action"
    // porteur d'un \n forgerait sinon des lignes de log, et "action[]=x" (tableau)
    // journaliserait "Array" avec un warning PHP.
    $actionRecue = init('action');
    $actionRecue = is_scalar($actionRecue) ? (string) $actionRecue : 'Array';
    $actionRecue = substr(preg_replace('/[^\x20-\x7E]/', ' ', $actionRecue), 0, 40);
    // UC08, AC7 (A7-1) : $t->getMessage() neutralisé AVANT journalisation, seul
    // endroit du plugin où un message de Throwable échappait à ce filtre (injection de
    // log par retour à la ligne forgé) — cohérence avec smartclim::neutraliserPourLog(),
    // rendue publique à cette fin.
    log::add('smartclim', 'error', 'Erreur interne inattendue dans smartclim.ajax.php (action=' . $actionRecue . ') : ' . get_class($t) . ' : ' . smartclim::neutraliserPourLog($t->getMessage()) . ' (' . basename($t->getFile()) . ':' . $t->getLine() . ')');
    ajax::error(__('Erreur interne du plugin — consultez les logs', __FILE__), 0);
}
