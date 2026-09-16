<?php
if (!isConnect()) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
// Page-panneau utilisateur (UC03 du domaine post-mvp/06-ergonomie-jeedom) : AUCUN endpoint
// AJAX, AUCUN JS de page (D1 de la spec technique) — le rendu est intégralement délégué au
// mécanisme natif eqLogic::toHtml(), qui applique lui-même le filtrage par droits
// (preToHtml()) et le masquage de commandes posé par UC01 (masquerCommandesTuile()).
$groupes = smartclim::climatiseursVisibles();
?>
<legend>{{Mes climatiseurs}}</legend>
<style>
	/* D5 de la spec technique : sur un profil non-admin, getLinkToConfiguration() rend
	   '#' (le nom d'équipement devient une ancre inerte) — sans cette règle, un clic sur
	   le nom fait sauter la page en haut. Discriminée par la sortie du core elle-même
	   (href="#"), jamais par une branche PHP sur le profil : reste pleinement
	   fonctionnelle pour un admin. */
	#smartclim-panel .eqLogic-widget .widget-name a[href="#"] { pointer-events: none; cursor: default; color: inherit; }
</style>
<?php if (empty($groupes)) { ?>
	<div class="alert alert-info">{{Aucun climatiseur accessible}}</div>
<?php
} else {
	?>
	<div id="smartclim-panel">
	<?php
	foreach ($groupes as $groupe) {
		$tuiles = '';
		foreach ($groupe['equipements'] as $eqLogic) {
			try {
				$tuiles .= $eqLogic->toHtml('dashboard');
			} catch (Throwable $t) {
				log::add('smartclim', 'error', 'Rendu de la page-panneau : échec sur l\'équipement "' . smartclim::neutraliserPourLog($eqLogic->getHumanName()) . '" : ' . get_class($t) . ' : ' . smartclim::neutraliserPourLog($t->getMessage()));
			}
		}
		if (trim($tuiles) === '') {
			// D3, 2e barrière : preToHtml() réévalue hasRight()/getIsEnable() AU MOMENT du
			// rendu — ne jamais retirer ce tampon au prétexte que climatiseursVisibles()
			// a déjà filtré (course résiduelle entre les deux appels).
			continue;
		}
		$nomGroupe = ($groupe['nom'] !== '') ? htmlspecialchars($groupe['nom'], ENT_QUOTES, 'UTF-8') : '{{Sans objet}}';
		?>
		<h4><?php echo $nomGroupe; ?></h4>
		<div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
			<?php echo $tuiles; ?>
		</div>
		<?php
	}
	?>
	</div>
	<?php
}
?>
