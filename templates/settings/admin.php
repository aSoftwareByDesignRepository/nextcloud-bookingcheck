<?php
/**
 * NC admin-settings pointer — real configuration lives inside the app so
 * delegated BookingCheck administrators (who are not NC sysadmins) can
 * reach it.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<div id="bck-admin-settings" class="section bck-nc-admin-settings">
	<h2><?php p($l->t('BookingCheck')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('BookingCheck is configured inside the app — payments, invoicing, access policy, license and notifications each have their own settings page.')); ?>
	</p>
	<p>
		<a class="bck-btn bck-btn--primary" href="<?php p((string)$_['settingsUrl']); ?>">
			<?php p($l->t('Open BookingCheck settings')); ?>
		</a>
	</p>
</div>
