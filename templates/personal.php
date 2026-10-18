<?php
/**
 * MetaVox personal settings.
 *
 * Everything below the mount point is placeholder: the Vue app in
 * src/components/MetaVoxPersonal.vue replaces it as soon as it boots. It
 * exists so the page is not blank while the bundle loads.
 *
 * Same shape as templates/admin.php, including the class name, so both
 * placeholders are styled by one rule per stylesheet rather than an inline
 * <style> block carrying its own copy of a spinner.
 */

script('metavox', 'user');
style('metavox', 'user');
?>

<div id="metavox-personal" data-has-permission="<?php p($_['hasPermission'] ? '1' : '0'); ?>">
	<div class="metavox-personal-loading">
		<span class="icon-loading"></span>
		<p><?php
		// TRANSLATORS Shown while the MetaVox personal settings page loads; MetaVox is the app name, do not translate it
		p($l->t('Loading MetaVox …')); ?></p>
	</div>
</div>
