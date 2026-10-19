<?php
declare(strict_types=1);
/** @var string $pspRef @var string $status */
$l = \OCP\Util::getL10N('bookingcheck');
?>
<div id="content" class="app-bookingcheck bck-page" role="main">
	<div class="bck-section bck-sandbox">
		<h2><?php p($l->t('Sandbox payment')); ?></h2>
		<p><?php p($l->t('This is a test checkout. No real money moves.')); ?></p>
		<p><code><?php p($pspRef); ?></code> — <span><?php p($status); ?></span></p>
		<div class="bck-actions">
			<button class="primary" data-sandbox-settle="<?php p($pspRef); ?>" data-outcome="paid">
				<?php p($l->t('Pay (test)')); ?>
			</button>
			<button data-sandbox-settle="<?php p($pspRef); ?>" data-outcome="failed">
				<?php p($l->t('Fail payment (test)')); ?>
			</button>
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
