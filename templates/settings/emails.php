<?php
/** @var array $_ @var \OCP\IL10N $l — Settings → Emails */
?>
<form class="bck-card bck-settings-form" data-section="emails" novalidate>
	<div class="bck-card__body">
	<h3 class="bck-card__title"><?php p($l->t('Notifications')); ?></h3>
	<p>
		<label class="bck-checkbox">
			<input type="checkbox" id="bck-notify-paid" <?php if ((string)$_['notifyPaid'] === '1') { p('checked'); } ?>>
			<span><?php p($l->t('Notify me when a paid order arrives')); ?></span>
		</label>
	</p>
	<p>
		<button type="submit" class="bck-btn bck-btn--primary"><?php p($l->t('Save settings')); ?></button>
		<span class="bck-settings-status" role="status" aria-live="polite"></span>
	</p>
	</div>
</form>

<div class="bck-card">
	<div class="bck-card__body">
	<h3 class="bck-card__title"><?php p($l->t('Email texts')); ?></h3>
	<p class="settings-hint">
		<?php p($l->t('Adjust the subject and add your own text to automatic emails. Empty fields keep the built-in text. Leave the language empty to use it for all languages.')); ?>
	</p>
	<div class="bck-field">
		<label for="bck-mailtpl-kind"><?php p($l->t('Email')); ?></label>
		<select id="bck-mailtpl-kind">
			<?php foreach ($_['mailKindLabels'] as $k): ?>
				<option value="<?php p($k[0]); ?>"><?php p($k[1]); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
	<div class="bck-field">
		<label for="bck-mailtpl-locale"><?php p($l->t('Language')); ?></label>
		<select id="bck-mailtpl-locale">
			<option value=""><?php p($l->t('All languages')); ?></option>
			<option value="en">English</option>
			<option value="de">Deutsch</option>
		</select>
	</div>
	<div class="bck-field">
		<label for="bck-mailtpl-subject"><?php p($l->t('Subject')); ?></label>
		<input id="bck-mailtpl-subject" type="text" maxlength="190">
	</div>
	<div class="bck-field">
		<label for="bck-mailtpl-extra"><?php p($l->t('Extra text')); ?></label>
		<textarea id="bck-mailtpl-extra" rows="3" maxlength="4000"></textarea>
	</div>
	<p>
		<button id="bck-mailtpl-save" class="bck-btn bck-btn--primary"><?php p($l->t('Save email text')); ?></button>
		<span id="bck-mailtpl-status" role="status" aria-live="polite"></span>
	</p>
	<?php if (!empty($_['mailTemplates'])): ?>
		<h4><?php p($l->t('Saved email texts')); ?></h4>
		<ul id="bck-mailtpl-list" class="bck-list-cards">
			<?php foreach ($_['mailTemplates'] as $t): ?>
				<li>
					<strong><?php p($t['kind']); ?></strong>
					<?php if ($t['locale'] !== ''): ?>(<?php p($t['locale']); ?>)<?php endif; ?>
					<?php if ($t['subject'] !== ''): ?> — <?php p($t['subject']); ?><?php endif; ?>
					<button type="button" class="bck-btn bck-btn--secondary bck-mailtpl-del"
						data-id="<?php p($t['id']); ?>"><?php p($l->t('Delete')); ?></button>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	</div>
</div>
