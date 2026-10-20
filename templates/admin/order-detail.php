<?php
/** @var \OCP\IL10N $l */
$o = $_['order'] ?? [];
$_['pageTitle'] = $l->t('Order') . ' ' . (string)($o['ref'] ?? '');
$_['pageIcon'] = 'clipboard-list';
\OCA\BookingCheck\Support\Chrome::apply($_, 'orders');
include __DIR__ . '/../common/page-start.php';

$lines = $_['lines'] ?? [];
$payment = $_['payment'] ?? null;
$holdersByLine = $_['holdersByLine'] ?? [];
$invoices = $_['invoices'] ?? [];
$fmt = fn (int $c) => number_format($c / 100, 2, ',', '.');
$oid = (int)($o['id'] ?? 0);
$status = (string)($o['status'] ?? '');
$canApprove = $status === 'pending_approval';
$canRefund = in_array($status, ['paid'], true);
$canCancel = in_array($status, ['paid', 'pending_approval', 'created', 'approved'], true);
$remainder = (int)($o['remainderCents'] ?? 0);
$payMethod = (string)($o['payMethod'] ?? 'psp');
$canMarkPaid = $status === 'paid' && $remainder > 0;
?>
<div class="bck-page-stack" data-bck-order-id="<?php p((string)$oid); ?>"
	data-bck-order-currency="<?php p((string)($o['currency'] ?? '')); ?>">
	<div class="bck-page-grid">
		<section class="bck-card" aria-labelledby="bck-order-main">
			<div class="bck-card__header">
				<h2 id="bck-order-main" class="bck-card__title"><?php p($l->t('Booking')); ?></h2>
				<span class="bck-badge bck-badge--neutral"><?php p($status); ?></span>
			</div>
			<div class="bck-card__body">
				<dl class="bck-kv">
					<dt><?php p($l->t('Buyer')); ?></dt>
					<dd><?php p((string)$o['buyerName']); ?> &lt;<?php p((string)$o['buyerEmail']); ?>&gt;</dd>
					<dt><?php p($l->t('Amount')); ?></dt>
					<dd><?php p((string)$o['currency'] . ' ' . number_format(((int)$o['amountCents']) / 100, 2, ',', '.')); ?></dd>
					<dt><?php p($l->t('Placed')); ?></dt>
					<dd><?php p((string)$o['createdAt']); ?></dd>
					<?php if (!empty($o['paidAt'])): ?>
					<dt><?php p($l->t('Paid')); ?></dt><dd><?php p((string)$o['paidAt']); ?></dd>
					<?php endif; ?>
					<?php if (!empty($o['buyerCompany']) || !empty($o['billingCountry']) || !empty($o['vatId'])): ?>
					<dt><?php p($l->t('Company')); ?></dt>
					<dd><?php p((string)($o['buyerCompany'] ?? '—')); ?>
						<?php if (!empty($o['billingCountry'])): ?>(<?php p((string)$o['billingCountry']); ?>)<?php endif; ?></dd>
					<?php if (!empty($o['vatId'])): ?>
					<dt><?php p($l->t('VAT ID')); ?></dt>
					<dd><?php p((string)$o['vatId']); ?>
						<span class="bck-badge bck-badge--neutral"><?php p((string)$o['viesStatus']); ?></span></dd>
					<?php endif; ?>
					<?php if ((int)($o['reverseCharge'] ?? 0) === 1): ?>
					<dt><?php p($l->t('Tax')); ?></dt>
					<dd><span class="bck-badge bck-badge--info"><?php p($l->t('Reverse charge §13b')); ?></span></dd>
					<?php endif; ?>
					<?php endif; ?>
					<?php if ($payMethod !== 'psp' || $remainder > 0): ?>
					<dt><?php p($l->t('Payment method')); ?></dt>
					<dd><span class="bck-badge bck-badge--neutral"><?php p($payMethod === 'invoice'
						? $l->t('Invoice') : ($payMethod === 'deposit'
							? $l->t('Deposit') : ($payMethod === 'walkup' ? $l->t('Walk-up') : $l->t('Online')))); ?></span></dd>
					<?php endif; ?>
					<?php if ($remainder > 0): ?>
					<dt><?php p($l->t('Open amount')); ?></dt>
					<dd><strong class="bck-amount-due"><?php p((string)$o['currency'] . ' ' . number_format($remainder / 100, 2, ',', '.')); ?></strong>
						<span class="bck-field__hint"><?php p($l->t('Still to be received')); ?></span></dd>
					<?php endif; ?>
				</dl>
				<div class="bck-btn-row">
					<?php if ($canApprove): ?>
						<button type="button" class="bck-btn bck-btn--primary" data-bck-order-action="approve"><?php p($l->t('Approve')); ?></button>
						<button type="button" class="bck-btn bck-btn--warn" data-bck-order-action="decline"><?php p($l->t('Decline')); ?></button>
					<?php endif; ?>
					<?php if ($canMarkPaid): ?>
						<button type="button" class="bck-btn bck-btn--primary" data-bck-order-action="mark-paid"><?php p($l->t('Received payment')); ?></button>
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
						<th scope="col"><?php p($l->t('Line')); ?></th>
						<th scope="col" class="bck-num"><?php p($l->t('Qty')); ?></th>
						<th scope="col"><?php p($l->t('Holders')); ?></th>
						<th scope="col" class="bck-num"><?php p($l->t('Price')); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ($lines as $ln): ?>
						<tr>
							<td><?php p((string)($ln['catName'] ?? $ln['targetType'] . ' #' . $ln['targetId'])); ?></td>
							<td class="bck-num"><?php p((string)(int)$ln['seats']); ?></td>
							<td><?php p(implode(', ', $holdersByLine[(int)$ln['id']] ?? []) ?: '—'); ?></td>
							<td class="bck-num"><?php p($fmt((int)$ln['lineCents'])); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>

		<?php if ($invoices !== []): ?>
		<section class="bck-card" aria-labelledby="bck-order-inv">
			<div class="bck-card__header"><h2 id="bck-order-inv" class="bck-card__title"><?php p($l->t('Invoices')); ?></h2></div>
			<div class="bck-card__body bck-card__body--table">
				<table class="bck-table">
					<thead><tr>
						<th scope="col"><?php p($l->t('Number')); ?></th>
						<th scope="col"><?php p($l->t('Type')); ?></th>
						<th scope="col"><?php p($l->t('Issued')); ?></th>
						<th scope="col" class="bck-num"><?php p($l->t('Net')); ?></th>
						<th scope="col" class="bck-num"><?php p($l->t('VAT')); ?></th>
						<th scope="col" class="bck-num"><?php p($l->t('Gross')); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ($invoices as $inv): ?>
						<tr>
							<td><code><?php p(sprintf('%s-%d-%04d', $inv['prefix'], $inv['year'], $inv['number'])); ?></code></td>
							<td><span class="bck-badge bck-badge--<?php p($inv['type'] === 'credit' ? 'warning' : 'neutral'); ?>"><?php p((string)$inv['type']); ?></span></td>
							<td><?php p(substr((string)$inv['issuedAt'], 0, 10)); ?></td>
							<td class="bck-num"><?php p($fmt((int)$inv['netCents'])); ?></td>
							<td class="bck-num"><?php p($fmt((int)$inv['vatCents'])); ?></td>
							<td class="bck-num"><?php p($fmt((int)$inv['grossCents'])); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
		<?php endif; ?>

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
