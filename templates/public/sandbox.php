<?php
declare(strict_types=1);
\OCA\BookingCheck\Support\AssetRegistrar::registerPublic();
/** @var string $pspRef @var string $status */
$l = \OCP\Util::getL10N('bookingcheck');
?>
<div class="bck-pub bck-pub--narrow bck-sandbox" role="main">
	<div class="bck-card">
		<div class="bck-card__body">
			<h1 class="bck-pub__title"><?php p($l->t('Sandbox payment')); ?></h1>
			<p><?php p($l->t('This is a test checkout. No real money moves.')); ?></p>
			<p><code><?php p($pspRef); ?></code> — <span><?php p($status); ?></span></p>
			<div class="bck-actions">
				<button class="bck-btn bck-btn--primary" data-sandbox-settle="<?php p($pspRef); ?>" data-outcome="paid">
					<?php p($l->t('Pay (test)')); ?>
				</button>
				<button class="bck-btn bck-btn--secondary" data-sandbox-settle="<?php p($pspRef); ?>" data-outcome="failed">
					<?php p($l->t('Fail payment (test)')); ?>
				</button>
			</div>
		</div>
	</div>
</div>
<script>
document.querySelectorAll('[data-sandbox-settle]').forEach(function(btn){
	btn.addEventListener('click', function(){
		fetch(btn.dataset.sandboxSettle !== undefined
			? OC.generateUrl('/apps/bookingcheck/pub/sandbox/settle/' + btn.dataset.sandboxSettle)
			: '#', {
			method: 'POST',
			headers: {'Content-Type':'application/json'},
			body: JSON.stringify({outcome: btn.dataset.outcome}),
		}).then(function(){ window.location.href = OC.generateUrl('/apps/bookingcheck/pub/sandbox/pay/' + btn.dataset.sandboxSettle); });
	});
});
</script>
