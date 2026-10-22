<?php
/** @var array $_ @var \OCP\IL10N $l — Settings → License */
?>
<form class="bck-card bck-settings-form" data-section="license" novalidate>
	<div class="bck-card__body">
	<p class="settings-hint">
		<?php p($l->t('Current plan:') . ' ' . (string)$_['licenseState']); ?>
	</p>
	<div class="bck-field">
		<label for="bck-license-key"><?php p($l->t('License key')); ?></label>
		<input id="bck-license-key" type="text" value="<?php p((string)$_['licenseKey']); ?>"
			placeholder="BCK-…" maxlength="512">
	</div>
	<p>
		<button type="submit" class="bck-btn bck-btn--primary"><?php p($l->t('Save settings')); ?></button>
		<span class="bck-settings-status" role="status" aria-live="polite"></span>
	</p>
	</div>
</form>
