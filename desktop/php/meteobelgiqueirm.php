<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('meteobelgiqueirm');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<div class="eqLogicThumbnailContainer">
			<div class="cursor eqLogicAction logoPrimary" data-action="add">
				<i class="fas fa-plus-circle"></i>
				<br>
				<span>{{Ajouter une commune}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>
		<legend><i class="fas fa-list"></i> {{Mes communes}}</legend>
		<?php
		if (count($eqLogics) == 0) {
			echo '<div class="alert alert-info" style="margin:5px;">';
			echo '<b>{{Aucune commune pour le moment. Pour démarrer :}}</b>';
			echo '<ol style="margin:5px 0 0 0;padding-left:20px;">';
			echo '<li>{{Cliquez sur « Ajouter une commune » et donnez-lui un nom, par exemple « Maison ».}}</li>';
			echo '<li>{{Tapez les premières lettres de la commune, puis choisissez-la dans la liste. Les 565 communes belges sont livrées avec le plugin : vous pouvez chercher en français comme en néerlandais.}}</li>';
			echo '<li>{{Enregistrez : les commandes sont créées et la météo est relevée aussitôt.}}</li>';
			echo '</ol>';
			echo '<span class="help-block" style="margin:8px 0 0 0;">{{Les données proviennent de l\'Institut Royal Météorologique de Belgique. Ce plugin n\'est pas affilié à l\'IRM et n\'est ni parrainé ni approuvé par lui.}}</span>';
			echo '</div>';
		}
		echo '<div class="input-group" style="margin:5px;">';
		echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
		echo '<div class="input-group-btn">';
		echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
		echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
		echo '</div>';
		echo '</div>';
		echo '<div class="eqLogicThumbnailContainer">';
		foreach ($eqLogics as $eqLogic) {
			$opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
			echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
			echo '<i class="fas fa-cloud-sun" style="font-size:4em;"></i>';
			echo '<br>';
			echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
			echo '<span class="hiddenAsCard displayTableRight hidden">';
			echo ($eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
			echo '</span>';
			echo '</div>';
		}
		echo '</div>';
		?>
	</div>

	<div class="col-xs-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex">
			<span class="input-group-btn">
				<a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a>
				<a class="btn btn-default btn-sm eqLogicAction" data-action="copy"><i class="fas fa-copy"></i><span class="hidden-xs"> {{Dupliquer}}</span></a>
				<a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
				<a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i><span class="hidden-xs"> {{Équipement}}</span></a></li>
			<li role="presentation"><a href="#forecasttab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-cloud-sun"></i><span class="hidden-xs"> {{Prévisions}}</span></a></li>
			<li role="presentation"><a href="#warningtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-exclamation-triangle"></i><span class="hidden-xs"> {{Avertissements}}</span></a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i><span class="hidden-xs"> {{Commandes}}</span></a></li>
		</ul>

		<div class="tab-content">
			<!-- ========================= ÉQUIPEMENT ========================= -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<br>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-tag"></i> {{Général}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Nom}}</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Maison}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Objet parent}}</label>
								<div class="col-sm-6">
									<select class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										foreach ((jeeObject::buildTree(null, false)) as $object) {
											echo '<option value="' . $object->getId() . '">' . $object->getHumanName(true, true) . '</option>';
										}
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Catégorie}}</label>
								<div class="col-sm-8">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '">' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Activer}}</label>
								<div class="col-sm-8">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Visible}}</label>
								<div class="col-sm-8">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>
								</div>
							</div>
						</fieldset>
					</form>
				</div>

				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-map-marker-alt"></i> {{Commune}}</legend>

							<!--
								Le code INS est la seule valeur fonctionnelle ; le libellé
								n'est là que pour que l'utilisateur relise son choix. Les
								deux sont cachés : on ne saisit pas un code INS à la main.
							-->
							<input type="hidden" class="eqLogicAttr" data-l1key="configuration" data-l2key="ins">
							<input type="hidden" class="eqLogicAttr" data-l1key="configuration" data-l2key="ins_label">

							<div class="form-group">
								<label class="col-sm-3 control-label">{{Rechercher}}</label>
								<div class="col-sm-6">
									<div class="input-group">
										<input type="text" class="form-control roundedLeft" id="in_meteobelgiqueirmSearch" placeholder="{{Namur, Elsene, Bruges…}}">
										<span class="input-group-btn">
											<a class="btn btn-default roundedRight" id="bt_meteobelgiqueirmSearch"><i class="fas fa-search"></i></a>
										</span>
									</div>
								</div>
								<div class="col-sm-3">
									<span class="help-block" style="margin:0;">{{Le nom de la commune, pas le code postal.}}</span>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-3 control-label">{{Résultats}}</label>
								<div class="col-sm-6">
									<select class="form-control" id="sel_meteobelgiqueirmCommune"></select>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-3 control-label">{{Commune retenue}}</label>
								<div class="col-sm-9">
									<span id="span_meteobelgiqueirmCommune" class="label label-default" style="font-size:1em;">{{Aucune}}</span>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-3 control-label"></label>
								<div class="col-sm-9">
									<a class="btn btn-default btn-sm" id="bt_meteobelgiqueirmRefresh"><i class="fas fa-sync"></i> {{Relever maintenant}}</a>
									<span id="span_meteobelgiqueirmStatus" style="margin-left:10px;"></span>
								</div>
							</div>
						</fieldset>
					</form>
				</div>
			</div>

			<!-- ========================= PRÉVISIONS ========================= -->
			<div role="tabpanel" class="tab-pane" id="forecasttab">
				<br>
				<div class="col-xs-12">
					<div class="alert alert-info" id="div_meteobelgiqueirmFetched" style="margin-bottom:10px;">{{Chargement…}}</div>

					<legend><i class="fas fa-calendar-alt"></i> {{Les prochains jours}}</legend>
					<table class="table table-condensed table-bordered">
						<thead>
							<tr>
								<th>{{Jour}}</th>
								<th>{{Temps}}</th>
								<th>{{Min}}</th>
								<th>{{Max}}</th>
								<th>{{Pluie}}</th>
								<th>{{Bulletin de l'IRM}}</th>
							</tr>
						</thead>
						<tbody id="table_meteobelgiqueirmDaily"></tbody>
					</table>

					<legend><i class="fas fa-clock"></i> {{Heure par heure}}</legend>
					<table class="table table-condensed table-bordered">
						<thead>
							<tr>
								<th>{{Échéance}}</th>
								<th>{{Temps}}</th>
								<th>{{Température}}</th>
								<th>{{Risque de pluie}}</th>
								<th>{{Vent}}</th>
							</tr>
						</thead>
						<tbody id="table_meteobelgiqueirmHourly"></tbody>
					</table>
				</div>
			</div>

			<!-- ====================== AVERTISSEMENTS ======================= -->
			<div role="tabpanel" class="tab-pane" id="warningtab">
				<br>
				<div class="col-xs-12">
					<div id="div_meteobelgiqueirmWarnings"></div>
					<span class="help-block">{{Les avertissements sont publiés à l'avance : un avertissement annoncé n'est pas encore en cours. Seuls ceux marqués « en cours » font passer la commande « Avertissement actif » à 1.}}</span>
				</div>
			</div>

			<!-- ========================= COMMANDES ========================= -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<div class="col-xs-12">
					<a class="btn btn-default btn-sm pull-right" id="bt_meteobelgiqueirmHideHidden"><i class="fas fa-eye"></i> {{Afficher seulement les commandes visibles}}</a>
					<br><br>
					<table id="table_cmd" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th style="width:60px;">{{ID}}</th>
								<th style="width:250px;">{{Nom}}</th>
								<th style="width:120px;">{{Type}}</th>
								<th>{{Paramètres}}</th>
								<th style="width:180px;">{{Valeur}}</th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>

<?php include_file('desktop', 'meteobelgiqueirm', 'js', 'meteobelgiqueirm'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
