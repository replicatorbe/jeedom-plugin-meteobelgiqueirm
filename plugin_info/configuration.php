<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<form class="form-horizontal">
	<fieldset>
		<legend><i class="fas fa-cloud-sun"></i> {{Service de l'IRM}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Délai d'attente des requêtes}}</label>
			<div class="col-md-2">
				<input type="number" class="configKey form-control" data-l1key="api_timeout" placeholder="10">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Secondes avant d'abandonner un appel à l'IRM. Dix suffisent en temps normal ; augmentez si votre connexion est lente, mais restez sous la minute : le cron qui relit les communes est partagé avec les autres plugins.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Langue des textes}}</label>
			<div class="col-md-2">
				<select class="configKey form-control" data-l1key="lang">
					<option value="">{{Comme Jeedom}}</option>
					<option value="fr">{{Français}}</option>
					<option value="nl">{{Néerlandais}}</option>
					<option value="de">{{Allemand}}</option>
					<option value="en">{{Anglais}}</option>
				</select>
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Langue dans laquelle l'IRM rédige ses bulletins et ses avertissements. Par défaut celle de Jeedom, ce qui convient dans presque tous les cas ; ce réglage sert surtout aux installations bilingues.}}</span>
			</div>
		</div>
	</fieldset>
</form>
