<?php
/**
 * @var array $_ @var \OCP\IL10N $l — Settings → Access control.
 *
 * Door model (family principle 12): app administrators always get in;
 * Restricted mode adds the allowlists below; Open mode admits every
 * logged-in user. Members of the bookingcheck_admin NC group are app
 * administrators by carrier — the pickers delegate without groups.
 */
$restrictionOn = !empty($_['accessRestrictionOn']);
$failClosed = !empty($_['accessFailClosed']);
$appAdmins = is_array($_['accessAppAdmins'] ?? null) ? $_['accessAppAdmins'] : [];
$allowedUsers = is_array($_['accessAllowedUsers'] ?? null) ? $_['accessAllowedUsers'] : [];
$groups = is_array($_['accessGroups'] ?? null) ? $_['accessGroups'] : [];
$selectedGids = is_array($_['accessSelectedGroupIds'] ?? null) ? $_['accessSelectedGroupIds'] : [];
?>
<form class="bck-card bck-settings-form" data-section="access" novalidate
	data-app-admins="<?php p(htmlspecialchars(json_encode($appAdmins, JSON_UNESCAPED_UNICODE), ENT_QUOTES)); ?>"
	data-allowed-users="<?php p(htmlspecialchars(json_encode($allowedUsers, JSON_UNESCAPED_UNICODE), ENT_QUOTES)); ?>">
	<div class="bck-card__body">
	<p class="settings-hint">
		<?php p($l->t('App administrators and Nextcloud admins always have access. When restriction is on, only allowlisted users and groups may use the app.')); ?>
	</p>
	<p class="settings-hint">
		<?php p($l->t('Members of the bookingcheck_admin group are app administrators. The pickers below delegate access without touching groups.')); ?>
	</p>

	<div class="bck-field">
		<label for="bck-access-mode"><?php p($l->t('Access mode')); ?></label>
		<select id="bck-access-mode">
			<option value="restricted" <?php if ($restrictionOn) { p('selected'); } ?>><?php p($l->t('Restricted — only the people below')); ?></option>
			<option value="open" <?php if (!$restrictionOn) { p('selected'); } ?>><?php p($l->t('Open — every logged-in user')); ?></option>
		</select>
	</div>

	<div id="bck-access-open-confirm-wrap" class="bck-callout bck-callout--warning" <?php if ($restrictionOn) { p('hidden'); } ?>>
		<p><?php p($l->t('Open mode lets every logged-in user manage offerings, orders and customer data. Only choose this on instances where all users are trusted staff.')); ?></p>
		<p>
			<label class="bck-check">
				<input type="checkbox" id="bck-access-confirm-open">
				<span><?php p($l->t('I understand — every user can manage bookings')); ?></span>
			</label>
		</p>
	</div>

	<?php if ($failClosed): ?>
		<div class="bck-callout bck-callout--danger" role="alert">
			<p><?php p($l->t('Restriction is on but the allowlists are empty — right now only app administrators can open BookingCheck.')); ?></p>
		</div>
	<?php endif; ?>

	<div class="bck-field bck-picker">
		<label for="bck-app-admins-search"><?php p($l->t('BookingCheck app administrators')); ?></label>
		<input type="search" id="bck-app-admins-search" autocomplete="off" spellcheck="false"
			role="combobox" aria-expanded="false" aria-controls="bck-app-admins-results" aria-autocomplete="list"
			data-field="access_app_admin_user_ids"
			placeholder="<?php p($l->t('Search users…')); ?>">
		<ul id="bck-app-admins-results" class="bck-picker__results" role="listbox" hidden></ul>
		<ul id="bck-app-admins-chips" class="bck-picker__chips" role="list"
			aria-label="<?php p($l->t('Selected app administrators')); ?>"></ul>
		<p class="settings-hint"><?php p($l->t('App administrators configure this settings page and are always allowed in.')); ?></p>
	</div>

	<div class="bck-field bck-picker">
		<label for="bck-allowed-users-search"><?php p($l->t('Allowed users')); ?></label>
		<input type="search" id="bck-allowed-users-search" autocomplete="off" spellcheck="false"
			role="combobox" aria-expanded="false" aria-controls="bck-allowed-users-results" aria-autocomplete="list"
			data-field="access_allowed_user_ids"
			placeholder="<?php p($l->t('Search users…')); ?>">
		<ul id="bck-allowed-users-results" class="bck-picker__results" role="listbox" hidden></ul>
		<ul id="bck-allowed-users-chips" class="bck-picker__chips" role="list"
			aria-label="<?php p($l->t('Selected allowed users')); ?>"></ul>
	</div>

	<fieldset class="bck-field" data-field="access_allowed_group_ids">
		<legend><?php p($l->t('Allowed groups')); ?></legend>
		<?php if ($groups === []): ?>
			<p class="settings-hint"><?php p($l->t('No groups on this instance.')); ?></p>
		<?php else: ?>
			<ul class="bck-access-groups" role="list">
				<?php foreach ($groups as $g): ?>
					<li class="bck-access-groups__item">
						<label class="bck-check">
							<input type="checkbox" name="access_allowed_groups" value="<?php p($g['id']); ?>"
								<?php if (in_array($g['id'], $selectedGids, true)) { p('checked'); } ?>>
							<span><?php p($g['displayName']); ?></span>
						</label>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</fieldset>

	<p>
		<button type="submit" class="bck-btn bck-btn--primary"><?php p($l->t('Save settings')); ?></button>
		<span class="bck-settings-status" role="status" aria-live="polite"></span>
	</p>
	</div>
</form>
