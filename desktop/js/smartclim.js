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

/* Permet la réorganisation des commandes dans l'équipement */
$("#table_cmd").sortable({
  axis: "y",
  cursor: "move",
  items: ".cmd",
  placeholder: "ui-state-highlight",
  tolerance: "intersect",
  forcePlaceholderSize: true
})

/* Fonction permettant l'affichage des commandes dans l'équipement */
function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }
  var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">'
  tr += '<td class="hidden-xs">'
  tr += '<span class="cmdAttr" data-l1key="id"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom de la commande}}">'
  tr += '<span class="input-group-btn"><a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a></span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '<select class="cmdAttr form-control input-sm" data-l1key="value" style="display:none;margin-top:5px;" title="{{Commande info liée}}">'
  tr += '<option value="">{{Aucune}}</option>'
  tr += '</select>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked/>{{Afficher}}</label> '
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked/>{{Historiser}}</label> '
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="display" data-l2key="invertBinary"/>{{Inverser}}</label> '
  tr += '<div style="margin-top:7px;">'
  tr += '<input class="tooltips cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="minValue" placeholder="{{Min}}" title="{{Min}}" style="width:30%;max-width:80px;display:inline-block;margin-right:2px;">'
  tr += '<input class="tooltips cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="maxValue" placeholder="{{Max}}" title="{{Max}}" style="width:30%;max-width:80px;display:inline-block;margin-right:2px;">'
  tr += '<input class="tooltips cmdAttr form-control input-sm" data-l1key="unite" placeholder="Unité" title="{{Unité}}" style="width:30%;max-width:80px;display:inline-block;margin-right:2px;">'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>';
  tr += '<span class="cmdAttr" data-l1key="htmlstate"></span>';
  tr += '</td>';
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>'
  }
  tr += '<i class="fas fa-minus-circle pull-right cmdAction cursor" data-action="remove" title="{{Supprimer la commande}}"></i></td>'
  tr += '</tr>'
  $('#table_cmd tbody').append(tr)
  var tr = $('#table_cmd tbody tr').last()
  jeedom.eqLogic.buildSelectCmd({
    id: $('.eqLogicAttr[data-l1key=id]').value(),
    filter: { type: 'info' },
    error: function (error) {
      $('#div_alert').showAlert({ message: error.message, level: 'danger' })
    },
    success: function (result) {
      tr.find('.cmdAttr[data-l1key=value]').append(result)
      tr.setValues(_cmd, '.cmdAttr')
      jeedom.cmd.changeType(tr, init(_cmd.subType))
    }
  })
}

/* Profil de capacités détecté (UC04). Tout le rendu de texte est SERVEUR
   (smartclim::profilAffichable(), déjà traduit) : ce JS injecte uniquement en .text()
   et ne construit aucun libellé lui-même. */
function printEqLogic(_eqLogic) {
  if (!isset(_eqLogic) || !isset(_eqLogic.id)) {
    return
  }
  var profil = isset(smartclimProfils) ? smartclimProfils[_eqLogic.id] : null
  if (!isset(profil) || !profil.detecte) {
    $("#div_profilCapacites").hide()
    $("#div_profilAbsent").show()
    $("#span_profilAbsent").text("{{Aucun profil de capacités détecté — lancez un scan des climatiseurs}}")
  } else {
    $("#div_profilAbsent").hide()
    $("#div_profilCapacites").show()
    $("#span_profilConcepts").text(profil.concepts)
    $("#span_profilModes").text(profil.modes)
    $("#span_profilVitesses").text(profil.vitesses)
    $("#span_profilTemperature").text(profil.effectives)
    $("#span_profilTemperatureQualif").text(profil.qualificatifTemperature ? "(" + profil.qualificatifTemperature + ")" : "")
    $("#span_profilDetecteLe").text(profil.detecteLe)
    $("#span_profilSource").text(profil.source)
  }
  if (isset(profil)) {
    $(".eqLogicAttr[data-l1key=configuration][data-l2key=temp_min]").attr("placeholder", profil.placeholderMin)
    $(".eqLogicAttr[data-l1key=configuration][data-l2key=temp_max]").attr("placeholder", profil.placeholderMax)
  }
}

/* Normalise les bornes de température personnalisées AVANT enregistrement (UC04, §
   Validation de la spec technique — double barrière, ce JS n'est qu'une aide à la
   saisie, jamais autoritaire). ⚠️ DOIT terminer par "return _eqLogic" : le core fait
   "eqLogic = saveEqLogic(eqLogic)" (core/js/plugin.template.js), un return oublié
   perdrait silencieusement l'enregistrement de l'équipement. Ne lève JAMAIS d'exception. */
function saveEqLogic(_eqLogic) {
  if (!isset(_eqLogic) || !isset(_eqLogic.configuration)) {
    return _eqLogic
  }
  var corrige = false
  var pasAutorises = ["0.5", "1"]
  // Enveloppe des bornes PERSONNALISÉES admissibles — mêmes valeurs que
  // smartclimCapabilities::TEMP_ENVELOPPE_MIN/MAX (§ Validation de la spec technique
  // UC04) : ce JS n'appelle pas le serveur pour les lire, la barrière AUTORITAIRE
  // reste preSave() côté PHP.
  var enveloppeMin = 5
  var enveloppeMax = 35

  $.each(["temp_min", "temp_max"], function (index, cle) {
    var brut = _eqLogic.configuration[cle]
    if (!isset(brut) || brut === null) {
      brut = ""
    }
    var normalise = String(brut).trim().replace(",", ".")
    if (normalise !== "" && !is_numeric(normalise)) {
      corrige = true
      normalise = ""
    } else if (normalise !== "") {
      var nombre = parseFloat(normalise)
      var borne = Math.min(enveloppeMax, Math.max(enveloppeMin, nombre))
      if (borne !== nombre) {
        corrige = true
      }
      normalise = String(borne)
    }
    _eqLogic.configuration[cle] = normalise
  })

  var min = _eqLogic.configuration.temp_min
  var max = _eqLogic.configuration.temp_max
  if (min !== "" && max !== "" && parseFloat(min) >= parseFloat(max)) {
    corrige = true
    _eqLogic.configuration.temp_min = ""
    _eqLogic.configuration.temp_max = ""
  }

  var pas = _eqLogic.configuration.temp_pas
  if (!isset(pas) || pas === null || $.inArray(String(pas), pasAutorises) === -1) {
    if (isset(pas) && pas !== null && String(pas) !== "") {
      corrige = true
    }
    _eqLogic.configuration.temp_pas = ""
  }

  if (corrige) {
    $("#div_alert").showAlert({ message: "{{Bornes de température corrigées : vérifiez les valeurs saisies}}", level: "warning" })
  }

  return _eqLogic
}

/* Découverte des climatiseurs AUX Home (UC03). Toute la logique (rapprochement,
   création/mise à jour, libellés traduits) vit côté serveur
   (core/ajax/smartclim.ajax.php -> smartclim::scannerAuxHome()) : ce JS n'envoie
   aucun paramètre, il affiche uniquement le résultat déjà curaté. */
function ajouterLigneScan($table, valeurs) {
  var tr = $('<tr></tr>')
  $.each(valeurs, function (index, valeur) {
    tr.append($('<td></td>').text(valeur))
  })
  $table.find('tbody').append(tr)
}

$('#bt_scannerClimatiseurs').off('click').on('click', function () {
  var $bouton = $(this)
  var libelleInitial = $bouton.find('span').text()
  $bouton.addClass('disableCard')
  $bouton.find('span').text("{{Scan en cours…}}")
  $('#table_scanTrouves tbody').empty()
  $('#table_scanDisparus tbody').empty()
  $('#bt_scanRecharger').addClass('hidden')
  $('#div_scanResultat').hide()
  $.ajax({
    type: 'POST',
    url: 'plugins/smartclim/core/ajax/smartclim.ajax.php',
    data: { action: 'scannerClimatiseurs' },
    dataType: 'json',
    timeout: 30000,
    global: false,
    error: function (jqXHR, textStatus) {
      if (textStatus === 'timeout') {
        $('#div_alert').showAlert({ message: "{{Le scan n'a pas répondu à temps}}", level: 'danger' })
      } else {
        $('#div_alert').showAlert({ message: "{{Erreur de communication avec le serveur Jeedom}}", level: 'danger' })
      }
    },
    success: function (data) {
      if (data.state != 'ok') {
        $('#div_alert').showAlert({ message: data.result, level: 'danger' })
        return
      }
      var resultat = data.result
      $('#span_scanResume').text(resultat.resume)
      $.each(resultat.appareils, function (index, appareil) {
        ajouterLigneScan($('#table_scanTrouves'), [
          appareil.nom,
          appareil.modele,
          appareil.mac,
          appareil.identifiant,
          appareil.enLigneLibelle,
          appareil.statutLibelle
        ])
      })
      $.each(resultat.disparus, function (index, appareil) {
        ajouterLigneScan($('#table_scanDisparus'), [
          appareil.nom,
          appareil.mac,
          appareil.identifiant,
          appareil.statutLibelle
        ])
      })
      if (resultat.compteurs.crees > 0) {
        $('#bt_scanRecharger').find('span').text("{{Afficher les nouveaux équipements}}")
        $('#bt_scanRecharger').removeClass('hidden')
      }
      // UC04 : rafraîchit les profils de capacités déjà chargés (sendVarToJS au rendu
      // de page) sans recharger la page — $.extend fonctionne aussi bien sur un objet
      // que sur un tableau vide (cas "aucun équipement").
      if (resultat.profils) {
        $.extend(smartclimProfils, resultat.profils)
      }
      $('#div_scanResultat').show()
    }
  }).always(function () {
    $bouton.removeClass('disableCard')
    $bouton.find('span').text(libelleInitial)
  })
})

$('#bt_scanRecharger').off('click').on('click', function () {
  location.reload()
})
