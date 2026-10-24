<?php
/** @var array $_ @var \OCP\IL10N $l — Settings → Portal & sharing */
?>
<form class="bck-card bck-settings-form" data-section="portal" novalidate>
	<div class="bck-card__body">
	<p>
		<label class="bck-checkbox">
			<input type="checkbox" id="bck-directory" <?php if ((string)$_['directoryEnabled'] === '1') { p('checked'); } ?>>
			<span><?php p($l->t('Public directory feed (read-only catalog JSON)')); ?></span>
		</label>
	</p>
	<p>
		<label class="bck-checkbox">
			<input type="checkbox" id="bck-portal-enabled" <?php if ((string)$_['portalEnabled'] === '1') { p('checked'); } ?>>
			<span><?php p($l->t('Customer portal (passwordless sign-in)')); ?></span>
		</label>
	</p>
	<div class="bck-field">
		<label for="bck-cors"><?php p($l->t('Allowed feed origins (comma-separated)')); ?></label>
		<input id="bck-cors" type="text" value="<?php p((string)$_['corsOrigins']); ?>"
			placeholder="https://example.com">
	</div>
	<div class="bck-field">
		<label for="bck-ical-token"><?php p($l->t('Calendar feed token')); ?></label>
		<input id="bck-ical-token" type="text" value="<?php p((string)$_['icalFeedToken']); ?>" maxlength="64">
		<?php if ((string)$_['icalFeedUrl'] !== ''): ?>
			<p class="settings-hint"><?php p($l->t('Feed URL:')) . ' ' . (string)$_['icalFeedUrl']; ?></p>
		<?php endif; ?>
	</div>
	<div class="bck-field">
		<label for="bck-default-locale"><?php p($l->t('Default language')); ?></label>
		<select id="bck-default-locale">
			<option value="" <?php if ((string)$_['defaultLocale'] === '') { p('selected'); } ?>><?php p($l->t('Buyer browser language')); ?></option>
			<option value="en" <?php if ((string)$_['defaultLocale'] === 'en') { p('selected'); } ?>>English</option>
			<option value="de" <?php if ((string)$_['defaultLocale'] === 'de') { p('selected'); } ?>>Deutsch</option>
		</select>
	</div>
	<p>
		<button type="submit" class="bck-btn bck-btn--primary"><?php p($l->t('Save settings')); ?></button>
		<span class="bck-settings-status" role="status" aria-live="polite"></span>
	</p>
	</div>
</form>
