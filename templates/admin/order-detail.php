<?php
/** @var \OCP\IL10N $l */
$o = $_['order'] ?? [];
$_['pageTitle'] = $l->t('Order') . ' ' . (string)($o['ref'] ?? '');
$_['pageIcon'] = 'clipboard-list';
\OCA\BookingCheck\Support\Chrome::apply($_, 'orders');
include __DIR__ . '/../common/page-start.php';

$lines = $_['lines'] ?? [];
$payment = $_['payment'] ?? null;
$oid = (int)($o['id'] ?? 0);
$status = (string)($o['status'] ?? '');
$canApprove = $status === 'pending_approval';
$canRefund = in_array($status, ['paid'], true);
$canCancel = in_array($status, ['paid', 'pending_approval', 'created', 'approved'], true);
?>
<div class="bck-page-stack" data-bck-order-id="<?php p((string)$oid); ?>">
	<div class="bck-page-grid">
		<section class="bck-card" aria-labelledby="bck-order-main">
			<div class="bck-card__header">
				<h2 id="bck-order-main" class="bck-card__title"><?php p($l->t('Booking')); ?></h2>
				<span class="bck-badge bck-badge--neutral"><?php p($status); ?></span>
			</div>
			<div class="bck-card__body">
				<dl class="bck-kv">
					<dt><?php p($l->t('Buyer')); ?></dt>
					<dd><?php p((string)$o['buyer_name']); ?> &lt;<?php p((string)$o['buyer_email']); ?>&gt;</dd>
					<dt><?php p($l->t('Amount')); ?></dt>
					<dd><?php p((string)$o['currency'] . ' ' . number_format(((int)$o['amount_cents']) / 100, 2, ',', '.')); ?></dd>
					<dt><?php p($l->t('Placed')); ?></dt>
					<dd><?php p((string)$o['created_at']); ?></dd>
					<?php if (!empty($o['paid_at'])): ?>
					<dt><?php p($l->t('Paid')); ?></dt><dd><?php p((string)$o['paid_at']); ?></dd>
					<?php endif; ?>
				</dl>
				<div class="bck-btn-row">
					<?php if ($canApprove): ?>
						<button type="button" class="bck-btn bck-btn--primary" data-bck-order-action="approve"><?php p($l->t('Approve')); ?></button>
						<button type="button" class="bck-btn bck-btn--warn" data-bck-order-action="decline"><?php p($l->t('Decline')); ?></button>
					<?php endif; ?>
					<?php if ($canRefund): ?>
						<button type="button" class="bck-btn bck-btn--warn" data-bck-order-action="refund"><?php p($l->t('Refund')); ?></button>
					<?php endif; ?>
					<?php if ($canCancel): ?>
						<button type="button" class="bck-btn bck-btn--danger" data-bck-order-action="cancel"><?php p($l->t('Cancel order')); ?></button>
					<?php endif; ?>
					<button type="button" class="bck-btn bck-btn--secondary" data-bck-order-action="resend"><?php p($l->t('Resend confirmation')); ?></button>
				</div>
			</div>
		</section>

		<section class="bck-card" aria-labelledby="bck-order-lines">
			<div class="bck-card__header"><h2 id="bck-order-lines" class="bck-card__title"><?php p($l->t('Seats')); ?></h2></div>
			<div class="bck-card__body bck-card__body--table">
				<table class="bck-table">
					<thead><tr>
						<th scope="col"><?php p($l->t('Seat')); ?></th>
						<th scope="col"><?php p($l->t('Holder')); ?></th>
						<th scope="col" class="bck-num"><?php p($l->t('Price')); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ($lines as $ln): ?>
						<tr>
							<td><?php p((string)($ln['seat_label'] ?? $ln['id'])); ?></td>
							<td><?php p((string)($ln['holder_name'] ?? '—')); ?></td>
							<td class="bck-num"><?php p(number_format(((int)($ln['unit_cents'] ?? 0)) / 100, 2, ',', '.')); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>

		<?php if ($payment !== null): ?>
		<section class="bck-card" aria-labelledby="bck-order-pay">
			<div class="bck-card__header"><h2 id="bck-order-pay" class="bck-card__title"><?php p($l->t('Payment')); ?></h2></div>
			<div class="bck-card__body">
				<dl class="bck-kv">
					<dt><?php p($l->t('Provider')); ?></dt><dd><?php p((string)$payment['provider']); ?></dd>
					<dt><?php p($l->t('PSP id')); ?></dt><dd><code><?php p((string)$payment['psp_id']); ?></code></dd>
					<dt><?php p($l->t('State')); ?></dt><dd><?php p((string)$payment['state']); ?></dd>
				</dl>
			</div>
		</section>
		<?php endif; ?>
	</div>
</div>
<?php include __DIR__ . '/../common/page-end.php'; ?>
