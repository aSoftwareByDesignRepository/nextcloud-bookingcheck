<?php
\OCA\BookingCheck\Support\AssetRegistrar::registerPublic();
/** Printable invoice document — frozen at issue time (GoBD). */
$o = $_['order'];
$inv = $_['invoice'];
$seller = $_['seller'];
$lines = $_['lines'];
$credits = $_['credits'] ?? [];
$vatBp = (int)($_['vatBp'] ?? 0);
$cur = (string)$o['currency'];
$fmt = fn (int $c) => $cur . ' ' . number_format($c / 100, 2, ',', '.');
$invNo = sprintf('%s-%d-%04d', $inv['prefix'], $inv['year'], $inv['number']);
$reverseCharge = (int)$o['reverseCharge'] === 1;
$klein = !empty($seller['kleinunternehmer']);
?>
<div class="bck-pub bck-invoice">
	<div class="bck-invoice__sheet">
		<header class="bck-invoice__head">
			<div>
				<h1 class="bck-invoice__title"><?php p($l->t('Invoice')); ?> <?php p($invNo); ?></h1>
				<p class="bck-invoice__meta">
					<?php p($l->t('Issued')); ?>: <?php p(substr((string)$inv['issuedAt'], 0, 10)); ?>
					· <?php p($l->t('Order')); ?>: <?php p((string)$o['ref']); ?>
				</p>
			</div>
			<button type="button" class="bck-btn bck-btn--secondary bck-no-print"
				onclick="window.print()"><?php p($l->t('Print')); ?></button>
		</header>

		<div class="bck-invoice__parties">
			<section aria-label="<?php p($l->t('Seller')); ?>">
				<h2 class="bck-invoice__party-title"><?php p($l->t('From')); ?></h2>
				<p>
					<strong><?php p((string)$seller['name']); ?></strong><br>
					<?php foreach (explode("\n", (string)$seller['address']) as $al): ?>
						<?php if (trim($al) !== ''): ?><?php p($al); ?><br><?php endif; ?>
					<?php endforeach; ?>
					<?php if ($seller['email'] !== ''): ?><?php p((string)$seller['email']); ?><br><?php endif; ?>
					<?php if ($seller['vatId'] !== ''): ?>
						<?php p($l->t('VAT ID')); ?>: <?php p((string)$seller['vatId']); ?>
					<?php endif; ?>
				</p>
			</section>
			<section aria-label="<?php p($l->t('Buyer')); ?>">
				<h2 class="bck-invoice__party-title"><?php p($l->t('To')); ?></h2>
				<p>
					<strong><?php p((string)$o['buyerName']); ?></strong><br>
					<?php if (!empty($o['buyerCompany'])): ?><?php p((string)$o['buyerCompany']); ?><br><?php endif; ?>
					<?php p((string)$o['buyerEmail']); ?><br>
					<?php if (!empty($o['billingCountry'])): ?><?php p((string)$o['billingCountry']); ?><br><?php endif; ?>
					<?php if (!empty($o['vatId'])): ?>
						<?php p($l->t('VAT ID')); ?>: <?php p((string)$o['vatId']); ?>
					<?php endif; ?>
				</p>
			</section>
		</div>

		<table class="bck-table bck-invoice__lines">
			<thead><tr>
				<th scope="col"><?php p($l->t('Item')); ?></th>
				<th scope="col" class="bck-num"><?php p($l->t('Qty')); ?></th>
				<th scope="col" class="bck-num"><?php p($l->t('Unit')); ?></th>
				<th scope="col" class="bck-num"><?php p($l->t('Total')); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ($lines as $ln): ?>
				<tr>
					<td><?php p((string)$_['offeringTitle']); ?>
						<?php if (!empty($ln['catName'])): ?>— <?php p((string)$ln['catName']); ?><?php endif; ?></td>
					<td class="bck-num"><?php p((string)(int)$ln['seats']); ?></td>
					<td class="bck-num"><?php p($fmt((int)$ln['unitCents'])); ?></td>
					<td class="bck-num"><?php p($fmt((int)$ln['lineCents'])); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th scope="row" colspan="3"><?php p($l->t('Net')); ?></th>
					<td class="bck-num"><?php p($fmt((int)$inv['netCents'])); ?></td>
				</tr>
				<tr>
					<th scope="row" colspan="3">
						<?php if ($reverseCharge || $klein): ?>
							<?php p($l->t('VAT')); ?> 0%
						<?php else: ?>
							<?php p($l->t('VAT')); ?> <?php p(number_format($vatBp / 100, 0)); ?>%
						<?php endif; ?>
					</th>
					<td class="bck-num"><?php p($fmt((int)$inv['vatCents'])); ?></td>
				</tr>
				<tr class="bck-invoice__total">
					<th scope="row" colspan="3"><?php p($l->t('Total')); ?></th>
					<td class="bck-num"><strong><?php p($fmt((int)$inv['grossCents'])); ?></strong></td>
				</tr>
			</tfoot>
		</table>

		<?php if ($reverseCharge): ?>
			<p class="bck-invoice__note">
				<?php p($l->t('Reverse charge — tax liability shifts to the recipient (§13b UStG / Art. 196 EU VAT Directive).')); ?>
			</p>
		<?php elseif ($klein): ?>
			<p class="bck-invoice__note">
				<?php p($l->t('No VAT charged — small-business exemption (§19 UStG).')); ?>
			</p>
		<?php endif; ?>

		<?php foreach ($credits as $cr): ?>
			<p class="bck-invoice__note">
				<?php p($l->t('Credit note')); ?>
				<?php p(sprintf('%s-%d-%04d', $cr['prefix'], $cr['year'], $cr['number'])); ?>:
				<?php p($fmt((int)$cr['grossCents'])); ?>
				(<?php p(substr((string)$cr['issuedAt'], 0, 10)); ?>)
			</p>
		<?php endforeach; ?>

		<p class="bck-no-print bck-invoice__back">
			<a href="<?php p(\OCP\Server::get(\OCP\IURLGenerator::class)
				->linkToRoute('bookingcheck.manage.show', ['token' => (string)$_['token']])); ?>">
				<?php p($l->t('Back to your booking')); ?></a>
		</p>
	</div>
</div>
