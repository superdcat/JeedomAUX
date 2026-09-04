<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
// Déclaration des variables obligatoires
$plugin = plugin::byId('smartclim');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
// UC04 : profil de capacités de chaque équipement, DÉJÀ traduit côté serveur
// (smartclim::profilsAffichables()) — le JS n'assemble aucun libellé, il injecte en .text().
sendVarToJS('smartclimProfils', smartclim::profilsAffichables($eqLogics));
// UC08 (AC8) : état de connexion de chaque équipement, DÉJÀ traduit côté serveur
// (smartclim::etatsConnexionAffichables()) — même principe que smartclimProfils
// ci-dessus : le JS n'assemble aucun libellé, il injecte en .text() et dérive une
// classe CSS du seul champ 'niveau'.
$smartclimEtatsConnexion = smartclim::etatsConnexionAffichables($eqLogics);
sendVarToJS('smartclimEtatsConnexion', $smartclimEtatsConnexion);
?>

<div class="row row-overflow">
	<!-- Page d'accueil du plugin -->
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<!-- Boutons de gestion du plugin -->
		<div class="eqLogicThumbnailContainer">
			<div class="cursor eqLogicAction logoPrimary" data-action="add">
				<i class="fas fa-plus-circle"></i>
				<br>
				<span>{{Ajouter}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
			<div class="cursor eqLogicAction" id="bt_scannerClimatiseurs">
				<i class="fas fa-search"></i>
				<br>
				<span>{{Scanner les climatiseurs}}</span>
			</div>
			<!-- Sonde de diagnostic : outillage de reverse engineering, cf.
			     smartclimAuxHomeApi::sondeDiagnostic(). Le navigateur n'envoie AUCUN
			     paramètre — le catalogue de routes sondées est une donnée serveur. -->
			<div class="cursor eqLogicAction" id="bt_sondeDiagnostic">
				<i class="fas fa-stethoscope"></i>
				<br>
				<span>{{Sonde de diagnostic}}</span>
			</div>
		</div>
		<div class="col-xs-12" id="div_scanResultat" style="display:none;">
			<legend><i class="fas fa-search"></i> {{Résultat}}</legend>
			<p id="span_scanResume"></p>
			<div id="div_scanClimatiseursWrapper">
				<h4>{{Climatiseurs (LAN + cloud)}}</h4>
				<div class="table-responsive">
					<table id="table_scanClimatiseurs" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th>{{Nom}}</th>
								<th>{{Adresse MAC}}</th>
								<th>{{Disponible en LAN}}</th>
								<th>{{Disponible dans le cloud}}</th>
								<th>{{Transport actif}}</th>
							</tr>
						</thead>
						<tbody>
						</tbody>
					</table>
				</div>
			</div>
			<div id="div_scanTrouvesWrapper">
				<h4>{{Climatiseurs trouvés}}</h4>
				<div class="table-responsive">
					<table id="table_scanTrouves" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th>{{Nom}}</th>
								<th>{{Modèle}}</th>
								<th>{{Adresse MAC}}</th>
								<th>{{Identifiant cloud}}</th>
								<th>{{État}}</th>
								<th>{{Résultat}}</th>
							</tr>
						</thead>
						<tbody>
						</tbody>
					</table>
				</div>
			</div>
			<div id="div_scanDisparusWrapper">
				<h4>{{Climatiseurs introuvables sur le compte}}</h4>
				<div class="table-responsive">
					<table id="table_scanDisparus" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th>{{Nom}}</th>
								<th>{{Adresse MAC}}</th>
								<th>{{Identifiant cloud}}</th>
								<th>{{Résultat}}</th>
							</tr>
						</thead>
						<tbody>
						</tbody>
					</table>
				</div>
			</div>
			<div id="div_scanLanWrapper">
				<h4>{{Climatiseurs détectés sur le réseau local}}</h4>
				<p id="span_scanResumeLan"></p>
				<div class="table-responsive">
					<table id="table_scanLan" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th>{{Nom}}</th>
								<th>{{Adresse MAC}}</th>
								<th>{{Adresse IP}}</th>
								<th>{{Type d'appareil}}</th>
								<th>{{Résultat}}</th>
							</tr>
						</thead>
						<tbody>
						</tbody>
					</table>
				</div>
			</div>
			<a class="btn btn-primary hidden" id="bt_scanRecharger"><i class="fas fa-sync"></i> <span></span></a>
		</div>
		<div class="col-xs-12" id="div_sondeResultat" style="display:none;">
			<legend><i class="fas fa-stethoscope"></i> {{Rapport de diagnostic}}</legend>
			<p>{{Ce rapport sert à identifier ce que le cloud AUX Home expose des capacités réelles de chaque climatiseur (par exemple : cet appareil sait-il chauffer ?). Les identifiants y sont masqués, vous pouvez le partager tel quel.}}</p>
			<a class="btn btn-default" id="bt_sondeCopier"><i class="fas fa-copy"></i> {{Copier le rapport}}</a>
			<a class="btn btn-default" id="bt_sondeTelecharger"><i class="fas fa-download"></i> {{Télécharger le rapport complet}}</a>
			<pre id="pre_sondeRapport" style="max-height:420px;overflow:auto;margin-top:10px;"></pre>
		</div>
		<legend><i class="fas fa-table"></i> {{Mes smartclims}}</legend>
		<?php
		if (count($eqLogics) == 0) {
			echo '<br><div class="text-center" style="font-size:1.2em;font-weight:bold;">{{Aucun équipement Template trouvé, cliquer sur "Ajouter" pour commencer}}</div>';
		} else {
			// Champ de recherche
			echo '<div class="input-group" style="margin:5px;">';
			echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
			echo '<div class="input-group-btn">';
			echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
			echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
			echo '</div>';
			echo '</div>';
			// Liste des équipements du plugin
			echo '<div class="eqLogicThumbnailContainer">';
			foreach ($eqLogics as $eqLogic) {
				$opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
				echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
				echo '<img src="' . $eqLogic->getImage() . '"/>';
				echo '<br>';
				// Nom d'équipement = donnée d'origine externe (cloud AUX Home ou diffusion LAN Broadlink
				// non authentifiée, post-mvp 01-04) : échapper avant rendu HTML (XSS stocké corrigé en review).
				echo '<span class="name">' . htmlspecialchars($eqLogic->getHumanName(true, true), ENT_QUOTES, 'UTF-8') . '</span>';
				// UC08 (AC8) : badge d'état de connexion, DÉJÀ traduit côté serveur
				// (smartclim::etatsConnexionAffichables() ci-dessus, calculé une seule fois).
				$sc_etatCarte = isset($smartclimEtatsConnexion[$eqLogic->getId()]) ? $smartclimEtatsConnexion[$eqLogic->getId()] : null;
				if (is_array($sc_etatCarte)) {
					$sc_classesNiveau = array('ok' => 'label-success', 'warning' => 'label-warning', 'danger' => 'label-danger', 'neutre' => 'label-default');
					$sc_classeNiveau = isset($sc_classesNiveau[$sc_etatCarte['niveau']]) ? $sc_classesNiveau[$sc_etatCarte['niveau']] : 'label-default';
					echo '<br><span class="label ' . $sc_classeNiveau . '">' . htmlspecialchars($sc_etatCarte['etat'], ENT_QUOTES, 'UTF-8') . '</span>';
				}
				echo '<span class="hiddenAsCard displayTableRight hidden">';
				echo ($eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
				echo '</span>';
				echo '</div>';
			}
			echo '</div>';
		}
		?>
	</div> <!-- /.eqLogicThumbnailDisplay -->

	<!-- Page de présentation de l'équipement -->
	<div class="col-xs-12 eqLogic" style="display: none;">
		<!-- barre de gestion de l'équipement -->
		<div class="input-group pull-right" style="display:inline-flex;">
			<span class="input-group-btn">
				<!-- Les balises <a></a> sont volontairement fermées à la ligne suivante pour éviter les espaces entre les boutons. Ne pas modifier -->
				<a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span>
				</a><a class="btn btn-sm btn-default eqLogicAction" data-action="copy"><i class="fas fa-copy"></i><span class="hidden-xs"> {{Dupliquer}}</span>
				</a><a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}
				</a><a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}
				</a>
			</span>
		</div>
		<!-- Onglets -->
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> {{Equipement}}</a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i> {{Commandes}}</a></li>
		</ul>
		<div class="tab-content">
			<!-- Onglet de configuration de l'équipement -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<!-- Partie gauche de l'onglet "Equipements" -->
				<!-- Paramètres généraux et spécifiques de l'équipement -->
				<form class="form-horizontal">
					<fieldset>
						<div class="col-lg-6">
							<legend><i class="fas fa-wrench"></i> {{Paramètres généraux}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Nom de l'équipement}}</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Nom de l'équipement}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Objet parent}}</label>
								<div class="col-sm-6">
									<select id="sel_object" class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										$options = '';
										foreach ((jeeObject::buildTree(null, false)) as $object) {
											$options .= '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
										}
										echo $options;
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Catégorie}}</label>
								<div class="col-sm-6">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '" >' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Options}}</label>
								<div class="col-sm-6">
									<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>{{Activer}}</label>
									<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>{{Visible}}</label>
								</div>
							</div>

							<legend><i class="fas fa-cogs"></i> {{Paramètres spécifiques}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Nom du paramètre n°1}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Renseignez le paramètre n°1 de l'équipement}}"></i></sup>
								</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="param1" placeholder="{{Paramètre n°1}}">
								</div>
							</div>
							<legend><i class="fas fa-thermometer-half"></i> {{Bornes de température personnalisées}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Température minimale}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Laisser vide pour utiliser la valeur détectée}}"></i></sup>
								</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="temp_min" placeholder="{{Valeur détectée}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Température maximale}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Laisser vide pour utiliser la valeur détectée}}"></i></sup>
								</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="temp_max" placeholder="{{Valeur détectée}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Pas de réglage}}</label>
								<div class="col-sm-6">
									<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="temp_pas">
										<option value="">{{Valeur détectée}}</option>
										<option value="0.5">{{0,5 °C}}</option>
										<option value="1">{{1 °C}}</option>
									</select>
								</div>
							</div>
							<legend><i class="fas fa-wifi"></i> {{Réseau local}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Adresse IP locale}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Renseignez l'adresse locale si la diffusion réseau n'atteint pas l'appareil (VLAN, réseau segmenté)}}"></i></sup>
								</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="lan_ip" placeholder="{{Adresse détectée}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Adresse MAC locale}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Renseignez l'adresse locale si la diffusion réseau n'atteint pas l'appareil (VLAN, réseau segmenté)}}"></i></sup>
								</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="lan_mac" placeholder="{{Adresse détectée}}">
								</div>
							</div>
						</div>

						<!-- Partie droite de l'onglet "Équipement" -->
						<!-- Affiche un champ de commentaire par défaut mais vous pouvez y mettre ce que vous voulez -->
						<div class="col-lg-6">
							<legend><i class="fas fa-info"></i> {{Informations}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Description}}</label>
								<div class="col-sm-6">
									<textarea class="form-control eqLogicAttr autogrow" data-l1key="comment"></textarea>
								</div>
							</div>
							<legend><i class="fas fa-plug"></i> {{État de connexion}}</legend>
							<div id="div_etatConnexion">
								<div class="form-group">
									<label class="col-sm-4 control-label">{{État}}</label>
									<div class="col-sm-8">
										<span class="label" id="span_etatConnexionEtat"></span>
										<span class="text-muted" id="span_etatConnexionIncidentLe"></span>
										<div class="text-muted" id="span_etatConnexionDetail"></div>
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Transport actif}}</label>
									<div class="col-sm-8"><span id="span_etatConnexionTransport"></span></div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Dernière donnée reçue}}</label>
									<div class="col-sm-8"><span id="span_etatConnexionDerniereDonnee"></span> <small class="text-muted" id="span_etatConnexionFraicheur"></small></div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Réseau local}}</label>
									<div class="col-sm-8"><span id="span_etatConnexionLan"></span> <small class="text-muted" id="span_etatConnexionLanAdresse"></small></div>
								</div>
							</div>

							<legend><i class="fas fa-list"></i> {{Profil de capacités détecté}}</legend>
							<div id="div_profilCapacites">
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Fonctions détectées}}</label>
									<div class="col-sm-8"><span id="span_profilConcepts"></span></div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Modes disponibles}}</label>
									<div class="col-sm-8"><span id="span_profilModes"></span></div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Vitesses de ventilation}}</label>
									<div class="col-sm-8"><span id="span_profilVitesses"></span></div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Plage de température}}</label>
									<div class="col-sm-8"><span id="span_profilTemperature"></span> <small class="text-muted" id="span_profilTemperatureQualif"></small></div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Détecté le}}</label>
									<div class="col-sm-8"><span id="span_profilDetecteLe"></span></div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Transport source}}</label>
									<div class="col-sm-8"><span id="span_profilSource"></span></div>
								</div>
							</div>
							<div class="form-group" id="div_profilAbsent" style="display:none;">
								<div class="col-sm-12 text-center"><span id="span_profilAbsent"></span></div>
							</div>
						</div>
					</fieldset>
				</form>
			</div><!-- /.tabpanel #eqlogictab-->

			<!-- Onglet des commandes de l'équipement -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<a class="btn btn-default btn-sm pull-right cmdAction" data-action="add" style="margin-top:5px;"><i class="fas fa-plus-circle"></i> {{Ajouter une commande}}</a>
				<br><br>
				<div class="table-responsive">
					<table id="table_cmd" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th class="hidden-xs" style="min-width:50px;width:70px;">ID</th>
								<th style="min-width:200px;width:350px;">{{Nom}}</th>
								<th>{{Type}}</th>
								<th style="min-width:260px;">{{Options}}</th>
								<th>{{Etat}}</th>
								<th style="min-width:80px;width:200px;">{{Actions}}</th>
							</tr>
						</thead>
						<tbody>
						</tbody>
					</table>
				</div>
			</div><!-- /.tabpanel #commandtab-->

		</div><!-- /.tab-content -->
	</div><!-- /.eqLogic -->
</div><!-- /.row row-overflow -->

<!-- Inclusion du fichier javascript du plugin (dossier, nom_du_fichier, extension_du_fichier, id_du_plugin) -->
<?php include_file('desktop', 'smartclim', 'js', 'smartclim'); ?>
<!-- Inclusion du fichier javascript du core - NE PAS MODIFIER NI SUPPRIMER -->
<?php include_file('core', 'plugin.template', 'js'); ?>
