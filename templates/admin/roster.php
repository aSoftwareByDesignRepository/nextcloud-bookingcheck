<?php
/** @var \OCP\IL10N $l */
$_['pageTitle'] = $l->t('Roster');
$_['pageHelp'] = $l->t('Who is coming. Pick a date to see the attendee list.');
$_['pageIcon'] = 'users';
\OCA\BookingCheck\Support\Chrome::apply($_, 'roster');
include __DIR__ . '/../common/page-start.php';
$occurrences = $_['occurrences'] ?? [];
?>
<div class="bck-page-stack">
	<div class="bck-toolbar">
		<label for="bck-roster-occ" class="bck-label"><?php p($l->t('Date / slot')); ?></label>
		<select id="bck-roster-occ" class="bck-toolbar__search" data-bck-roster-picker>
			<option value=""><?php p($l->t('Choose…')); ?></option>
			<?php foreach ($occurrences as $o): ?>
				<option value="<?php p((string)(int)$o['id']); ?>">
					<?php p((string)$o['title'] . ' — ' . (string)$o['start_utc'] . ' (' . (int)$o['sold_qty'] . '/' . (int)$o['capacity'] . ')'); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</div>
	<div id="bck-roster-list" class="bck-card">
		<div class="bck-card__body">
			<div class="bck-empty-state">
				<p class="bck-empty-state__text"><?php p($l->t('Pick a date above to see who is booked.')); ?></p>
			</div>
		</div>
	</div>
</div>
<?php include __DIR__ . '/../common/page-end.php'; ?>
