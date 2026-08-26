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

// Ce fichier est TOUJOURS inclus par le core (index.php?v=d&plugin=smartclim&configure=1),
// donc core.inc.php est déjà chargé : volontairement AUCUN require_once ici. Un chemin
// relatif ('/../../../core/php/core.inc.php') devient faux dès que le dossier du plugin
// n'est pas directement sous <jeedom>/plugins (montage Docker, lien symbolique, install
// atypique) et produit alors un E_COMPILE_ERROR NON rattrapable : HTTP 500 sur le panneau
// de configuration, donc un panneau VIDE, sans le moindre message pour l'admin. Même
// convention que desktop/php/smartclim.php, également inclus par le core sans require.
include_file('core', 'authentification', 'php');
if (!isConnect('admin')) {
  include_file('desktop', '404', 'php');
  die();
}

// Données de rendu du champ Pays (liste déroulante). Replis posés AVANT le try : si une
// classe annexe manquait (mise à jour partielle -> Error « Class not found » que l'on
// rattrape ici), le formulaire doit rester UTILISABLE — liste vide et saisie libre
// affichée — plutôt que de renvoyer un HTTP 500, c'est-à-dire un panneau de configuration
// blanc : sans lui, l'admin perd l'accès à toute la configuration du plugin, y compris la
// saisie de ses identifiants. L'échec part au log, jamais à l'écran.
$sc_paysDisponibles = array();
$sc_paysActuel = '';
try {
  $sc_paysDisponibles = smartclim::paysDisponiblesAuxHome();
  $sc_paysActuel = smartclim::paysAuxHome();
} catch (Throwable $t) {
  // UC08, AC7 (A7-2) : $t->getMessage() neutralisé AVANT journalisation (même motif
  // que core/ajax/smartclim.ajax.php, A7-1) : neutraliserPourLog() est PUBLIC depuis
  // cette UC précisément pour cet appel.
  log::add('smartclim', 'error', 'Préparation de la liste des pays impossible : ' . get_class($t) . ' : ' . smartclim::neutraliserPourLog($t->getMessage()) . ' (' . basename($t->getFile()) . ':' . $t->getLine() . ')');
}

// Mode « saisie libre » : le pays enregistré sort de la liste proposée (compte hors de la
// couverture européenne, valeur posée par script ou par l'API JSON-RPC), ou la liste est
// indisponible. La liste déroulante doit alors porter une option DE CETTE VALEUR, sans
// quoi le chargement AJAX du core (.val() sur une valeur qui n'a pas d'option) la
// laisserait sur « Sélectionnez un pays » et le pays serait ÉCRASÉ au premier
// enregistrement du formulaire — y compris un enregistrement visant un tout autre champ.
$sc_saisieLibre = (count($sc_paysDisponibles) == 0
  || ($sc_paysActuel != '' && !isset($sc_paysDisponibles[$sc_paysActuel])));
?>
<form class="form-horizontal">
  <fieldset>
    <legend>{{Compte AUX Home}}</legend>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Adresse e-mail}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Adresse e-mail du compte AUX Home (celle de l'application mobile)}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input type="email" class="configKey form-control" data-l1key="auxhome_email"/>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Mot de passe}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Mot de passe du compte AUX Home. Il est stocké chiffré et n'est jamais journalisé.}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input type="password" class="configKey form-control" data-l1key="auxhome_password" autocomplete="new-password"/>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Pays}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Pays du compte AUX Home, tel que choisi à la création du compte dans l'application mobile : vérifiez-le, un pays erroné fait échouer la connexion au cloud AUX Home.}}"></i></sup>
      </label>
      <div class="col-md-4">
        <!-- ⚠️ C'est la LISTE qui porte configKey, jamais les deux contrôles à la fois :
             avec deux éléments .configKey de même data-l1key, le core enregistrerait
             celui des deux qu'il rencontre en dernier. Le champ texte plus bas n'est
             qu'une saisie d'appoint pour un pays hors liste ; le JS recopie ce qu'on y
             tape dans la value de l'option « Autre pays », donc dans la valeur que le
             core enregistrera. -->
        <select class="configKey form-control" data-l1key="auxhome_country" id="sc_selectPays">
          <option value=""<?php echo (!$sc_saisieLibre && $sc_paysActuel == '') ? ' selected="selected"' : ''; ?>>{{Sélectionnez un pays}}</option>
        <?php foreach ($sc_paysDisponibles as $sc_code => $sc_libelle) { ?>
          <option value="<?php echo htmlspecialchars($sc_code, ENT_QUOTES, 'UTF-8'); ?>"<?php echo (!$sc_saisieLibre && $sc_code == $sc_paysActuel) ? ' selected="selected"' : ''; ?>><?php echo htmlspecialchars($sc_libelle . ' (' . $sc_code . ')', ENT_NOQUOTES, 'UTF-8'); ?></option>
        <?php } ?>
          <!-- Option porteuse d'un pays hors liste : sa value EST la valeur enregistrée
               (cf. $sc_saisieLibre en tête de fichier), et ce même quand le pays figure
               dans la liste — la choisir n'efface donc jamais le pays en place, elle
               ouvre juste sa correction. Le doublon de value avec l'option de liste est
               sans effet : .val() sélectionne la PREMIÈRE option qui correspond, donc
               celle de la liste. -->
          <option value="<?php echo htmlspecialchars($sc_paysActuel, ENT_QUOTES, 'UTF-8'); ?>" data-sc-libre="1"<?php echo $sc_saisieLibre ? ' selected="selected"' : ''; ?>>{{Autre pays (code ISO à 3 lettres)}}</option>
        </select>
        <input type="text" maxlength="3" id="sc_champPaysLibre" class="form-control" style="text-transform:uppercase;margin-top:6px;<?php echo $sc_saisieLibre ? '' : 'display:none;'; ?>" value="<?php echo htmlspecialchars($sc_paysActuel, ENT_QUOTES, 'UTF-8'); ?>"/>
        <span class="help-block">{{Le pays attendu est celui du compte AUX Home — pas nécessairement celui de l'installation Jeedom.}}</span>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label"></label>
      <div class="col-md-6">
        <button type="button" class="btn btn-default" id="sc_btnTesterConnexion">{{Tester la connexion}}</button>
        <button type="button" class="btn btn-danger" id="sc_btnEffacerIdentifiants">{{Effacer les identifiants}}</button>
        <span id="sc_resultatConnexion"></span>
        <br/>
        <span class="help-block">{{Le test utilise les identifiants enregistrés : enregistrez vos modifications avant de tester.}}</span>
      </div>
    </div>
  </fieldset>
  <fieldset>
    <legend>{{Rafraîchissement}}</legend>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Intervalle de rafraîchissement (minutes)}}
        <!-- bornes et défaut doivent rester identiques à smartclim::INTERVALLE_MIN/INTERVALLE_MAX/INTERVALLE_DEFAUT (core/class/smartclim.class.php) -->
        <sup><i class="fas fa-question-circle tooltips" title="{{Entre 1 et 1440 minutes, 5 minutes par défaut.}}"></i></sup>
      </label>
      <div class="col-md-4">
        <!-- min/max doivent rester identiques à smartclim::INTERVALLE_MIN/INTERVALLE_MAX (core/class/smartclim.class.php) -->
        <input type="number" min="1" max="1440" step="1" class="configKey form-control" data-l1key="refresh_interval"/>
        <span class="help-block">{{La température ambiante remontée par AUX Home se rafraîchit lentement (jusqu'à environ 30 minutes) ; réduire cet intervalle n'accélère pas la donnée.}}</span>
        <span class="help-block">{{Cette température n'est donc pas une mesure temps réel : ne l'utilisez pas comme sonde d'une régulation fine (thermostat).}}</span>
      </div>
    </div>
  </fieldset>
</form>
<script>
  // Toute la logique (protocole, chiffrement, classement des erreurs) vit côté serveur
  // (core/ajax/smartclim.ajax.php -> smartclim::testerConnexionAuxHome() /
  // ::effacerIdentifiantsAuxHome()) : ce JS ne fait qu'appeler l'action et afficher un
  // message déjà traduit — il n'envoie et ne lit AUCUN secret, et ne touche à un élément
  // .configKey QUE pour le champ Pays, sur action explicite de l'utilisateur (cf. le bloc
  // « Champ Pays » ci-dessous). Aucun champ porteur d'un SECRET n'est jamais modifié ni
  // vidé côté client : un champ vidé écraserait le secret stocké à l'enregistrement
  // suivant de la modale, y compris lors d'un enregistrement visant un autre champ.
  // ⚠️ Les 5 chaînes traduisibles de ce bloc sont toutes en guillemets DOUBLES (jamais
  // simples) : le français comme plusieurs traductions cibles contiennent des
  // apostrophes, qui casseraient une chaîne délimitée par des guillemets simples — panne
  // invisible à php -l comme à la CI. Les repérer par une recherche des doubles
  // accolades dans ce bloc, JAMAIS par numéro de ligne : les numéros qui figuraient ici
  // ont été décalés à chaque édition, et le sous-agent traducteur doit vérifier les 5,
  // pas s'arrêter à 4.
  // ⚠️ Et ne JAMAIS écrire une double accolade ouvrante littérale dans ce fichier, pas
  // même en commentaire : le core la traite comme un début de clé de traduction sur le
  // HTML rendu, et avale tout jusqu'à la fermeture suivante — la chaîne d'après cesse
  // alors d'être traduite, sans erreur nulle part.

  // --- Champ Pays ---------------------------------------------------------------
  // La liste déroulante est la SEULE source de vérité (c'est elle qui porte configKey) ;
  // le champ texte ne sert qu'à alimenter l'option « Autre pays » quand le code n'est pas
  // dans la liste. Écrire dans un élément .configKey est ici sans danger, à la différence
  // du cas interdit plus haut : aucun secret n'est en jeu, et la valeur ne change que sur
  // une action explicite de l'utilisateur — jamais à son insu au chargement de la page.
  $('#sc_selectPays').off('change').on('change', function () {
    var $champ = $('#sc_champPaysLibre');
    if ($(this).find('option:selected').attr('data-sc-libre') === '1') {
      $champ.show().focus();
      return;
    }
    $champ.hide();
  });

  // Normalisation identique à celle du serveur (smartclim::normaliserPays) : lettres
  // seulement, majuscules, 3 caractères. Le code est reporté dans l'ATTRIBUT value de
  // l'option « Autre pays » — c'est ce que le core lira à l'enregistrement, .val() d'un
  // <select> renvoyant la value de l'option sélectionnée. On écoute aussi 'change' pour
  // couvrir un collage ou un remplissage automatique du navigateur.
  $('#sc_champPaysLibre').off('input change').on('input change', function () {
    var code = $(this).val().toUpperCase().replace(/[^A-Z]/g, '').substring(0, 3);
    $(this).val(code);
    $('#sc_selectPays').find('option[data-sc-libre="1"]').attr('value', code);
  });

  $('#sc_btnTesterConnexion').off('click').on('click', function () {
    var $bouton = $(this);
    var libelleInitial = $bouton.text();
    var $resultat = $('#sc_resultatConnexion');
    $bouton.prop('disabled', true).text("{{Test de connexion en cours…}}");
    $resultat.removeClass('label label-success label-danger').text('');
    $.ajax({
      type: 'POST',
      url: 'plugins/smartclim/core/ajax/smartclim.ajax.php',
      data: {action: 'testerConnexion'},
      dataType: 'json',
      timeout: 22000,
      global: false,
      error: function (jqXHR, textStatus) {
        if (textStatus === 'timeout') {
          $resultat.addClass('label label-danger').text("{{Le test n'a pas répondu à temps}}");
        } else {
          $resultat.addClass('label label-danger').text("{{Erreur de communication avec le serveur Jeedom}}");
        }
      },
      success: function (data) {
        if (data.state != 'ok') {
          $resultat.addClass('label label-danger').text(data.result);
          return;
        }
        $resultat.addClass('label label-success').text(data.result.message);
      }
    }).always(function () {
      $bouton.prop('disabled', false).text(libelleInitial);
    });
  });

  $('#sc_btnEffacerIdentifiants').off('click').on('click', function () {
    var $bouton = $(this);
    var $resultat = $('#sc_resultatConnexion');
    bootbox.confirm("{{Effacer l'e-mail et le mot de passe du compte AUX Home ?}}", function (confirme) {
      if (!confirme) {
        return;
      }
      $bouton.prop('disabled', true);
      $resultat.removeClass('label label-success label-danger').text('');
      $.ajax({
        type: 'POST',
        url: 'plugins/smartclim/core/ajax/smartclim.ajax.php',
        data: {action: 'effacerIdentifiants'},
        dataType: 'json',
        // Sans timeout, une réponse perdue (proxy qui pend, worker Apache recyclé sans
        // RST) ne déclenche NI error: NI success: — aucun rechargement, aucune
        // désactivation, pendant de longues minutes (finding LOW, 2e tour : c'était le
        // 4e trou du finding sécurité MEDIUM d'origine, non couvert par les 3 premiers).
        timeout: 15000,
        global: false,
        error: function () {
          // L'état serveur est INDÉTERMINÉ ici (coupure réseau après traitement,
          // redémarrage d'Apache, ou désormais le timeout ci-dessus) : recharger reste
          // la SEULE resynchronisation fiable, cf. reinitialiserApresEffacement()
          // plus bas.
          $resultat.addClass('label label-danger').text("{{L'effacement des identifiants a échoué}}");
          reinitialiserApresEffacement(3000);
        },
        success: function (data) {
          if (data.state != 'ok') {
            // Échec métier PARTIEL (ex. le 2e config::remove() ou purgerSession() a
            // levé après que le 1er ait réussi) : même traitement — la session peut ne
            // pas avoir été purgée, l'état serveur reste incertain.
            $resultat.addClass('label label-danger').text(data.result);
            reinitialiserApresEffacement(3000);
            return;
          }
          $resultat.addClass('label label-success').text(data.result.message);
          reinitialiserApresEffacement(1200);
        }
      });
    });
  });

  // Recharge la page entière plutôt que de "refléter" l'effacement en modifiant un
  // champ .configKey en JS (interdit, cf. commentaire en tête de fichier) : sur les 3
  // issues possibles de l'appel ci-dessus (succès, échec métier, échec réseau/serveur),
  // l'état serveur est incertain ou modifié, et le rechargement est la SEULE
  // resynchronisation fiable — sans lui, les champs e-mail/mot de passe resteraient
  // peuplés EN CLAIR dans le DOM (le core les y a mis au chargement), et un
  // "Sauvegarder" ultérieur les réécrirait en base, ressuscitant silencieusement les
  // secrets qu'on pensait effacés (finding sécurité MEDIUM de la revue croisée).
  // Délai PARAMÉTRÉ (finding LOW, 2e tour) : 1200 ms sur le succès, ~3000 ms sur les
  // deux chemins d'échec — sans ce délai plus long, le rechargement effacerait le
  // message d'échec avant que l'admin ait pu le lire, et emporterait sans avertissement
  // ses éventuelles saisies non enregistrées ailleurs sur la page.
  // Sélecteur de désactivation RESTREINT (finding LOW, 2e tour) : #div_plugin_configuration
  // — conteneur confirmé dans lequel Jeedom injecte le contenu de
  // plugin_info/configuration.php (cf. desktop/php/plugin.php du core, panneau
  // "Configuration") — plutôt que TOUS les boutons/submit de la page : un rechargement
  // qui n'aboutirait pas (script tiers en erreur, unload annulé par le navigateur) ne
  // doit pas rendre TOUTE la page Jeedom durablement inutilisable. #bt_savePluginConfig
  // (bouton "Sauvegarder" de ce panneau, dans le chrome du core donc SIBLING de ce
  // conteneur, hors du <form> injecté par ce fichier) est rendu en <a>, sur lequel
  // .prop('disabled') est sans effet natif : désactivé via .addClass('disabled') +
  // pointer-events:none (idiome Bootstrap pour un faux bouton en ancre).
  // Filet de sécurité : un 2e minuteur, 5 s après le rechargement prévu, réactive tout
  // si le rechargement n'a pas eu lieu (sans effet si la page a bien déchargé : ce
  // minuteur meurt alors avec le document).
  function reinitialiserApresEffacement(delai) {
    $('#div_plugin_configuration button, #div_plugin_configuration input[type="submit"], #div_plugin_configuration input[type="button"]').prop('disabled', true);
    $('#bt_savePluginConfig').addClass('disabled').css('pointer-events', 'none');
    setTimeout(function () {
      window.location.reload();
    }, delai);
    setTimeout(function () {
      $('#div_plugin_configuration button, #div_plugin_configuration input[type="submit"], #div_plugin_configuration input[type="button"]').prop('disabled', false);
      $('#bt_savePluginConfig').removeClass('disabled').css('pointer-events', '');
    }, delai + 5000);
  }
</script>
