<?php
/** Offering detail — what it is, when, price, one big Book button. */
\OCP\Util::addScript('bookingcheck', 'pages/public');
$o = $_['offering'] ?? [];
$url = \OCP\Server::get(\OCP\IURLGenerator::class);
$money = static fn (int $c, string $cur): string =>
	$c === 0 ? $l->t('Free') : $cur . ' ' . number_format($c / 100, 2, ',', '.');
$occ = $_['occurrences'] ?? [];
?>
<div class="bck-pub">
	<nav class="bck-breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
		<ol class="bck-breadcrumb__list">
			<li class="bck-breadcrumb__item"><a class="bck-breadcrumb__link" href="<?php p($url->linkToRoute('bookingcheck.pubCatalog.index')); ?>"><?php p($l->t('All offerings')); ?></a></li>
			<li class="bck-breadcrumb__item bck-breadcrumb__item--current" aria-current="page"><span class="bck-breadcrumb__current"><?php p((string)$o['title']); ?></span></li>
		</ol>
	</nav>

	<article class="bck-card bck-card--offer">
		<div class="bck-card__header">
			<div class="bck-card__header-text">
				<h1 class="bck-card__title"><?php p((string)$o['title']); ?></h1>
			</div>
			<span class="bck-badge bck-badge--primary bck-badge--lg">
				<?php p($money((int)$o['price_cents'], (string)$o['currency'])); ?></span>
		</div>
		<div class="bck-card__body">
			<?php if (!empty($o['descr'])): ?>
				<p class="bck-offer__descr"><?php p((string)$o['descr']); ?></p>
			<?php endif; ?>

			<?php if ($occ !== []): ?>
				<h2 class="bck-offer__h"><?php p($l->t('Dates')); ?></h2>
				<ul class="bck-occ-list">
					<?php foreach ($occ as $oc): ?>
						<li class="bck-occ-list__item">
							<span><?php p((string)$oc['label']); ?></span>
							<?php if ((int)($oc['free'] ?? 0) <= 0): ?>
								<span class="bck-badge bck-badge--danger"><?php p($l->t('Full')); ?></span>
							<?php elseif (!empty($oc['waitlist'])): ?>
								<span class="bck-badge bck-badge--warn"><?php p($l->t('Waitlist')); ?></span>
							<?php else: ?>
								<span class="bck-badge bck-badge--success"><?php p($l->t('Seats open')); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<div class="bck-form-actions">
				<a class="bck-btn bck-btn--primary bck-btn--xl"
					href="<?php p($url->linkToRoute('bookingcheck.pubCheckout.start', ['slug' => $o['slug']])); ?>">
					<?php p($_['approvalRequired'] ? $l->t('Request booking') : $l->t('Book now')); ?></a>
			</div>
		</div>
	</article>
</div>
