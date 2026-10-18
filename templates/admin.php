<?php
/**
 * MetaVox admin settings.
 *
 * Everything below the mount point is placeholder: the Vue app in
 * src/components/MetaVoxAdmin.vue replaces it as soon as it boots. It exists
 * so the page is not blank while the bundle loads.
 */

script('metavox', 'admin');
style('metavox', 'admin');
?>

<div id="metavox-admin">
	<div class="metavox-admin-loading">
		<span class="icon-loading"></span>
		<p><?php
		// TRANSLATORS Shown while the MetaVox administration settings page loads; MetaVox is the app name, do not translate it
		p($l->t('Loading MetaVox …')); ?></p>
	</div>
</div>
