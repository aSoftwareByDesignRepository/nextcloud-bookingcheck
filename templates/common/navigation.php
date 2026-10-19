<?php
/**
 * BookingCheck sidebar — Check-family chrome (family parity).
 * Shared component: apps/_shared/check-shell (synced — do not edit in-app).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 *
 * Params:
 *   navGroups  list of {label: string, items: list<{id,label,hint?,href,icon,active}>}
 *   settingsNav list of {id,label,hint,href,icon,active} — rendered as its own
 *               group at the end when non-empty
 *   roleLabel, brandIcon (IconCatalog name), brandTitle, brandSubtitle,
 *   footerPartial (optional absolute template path, e.g. app-feedback nav footer)
 */

use OCA\BookingCheck\Service\IconCatalog;

$navGroups = $_['navGroups'] ?? [];
$settingsItems = $_['settingsNav'] ?? [];
$roleLabel = (string)($_['roleLabel'] ?? $l->t('Viewer'));
$brandIcon = (string)($_['brandIcon'] ?? 'shield');
$appTitle = (string)($_['brandTitle'] ?? $l->t('BookingCheck'));
$appSubtitle = (string)($_['brandSubtitle'] ?? '');

$renderItems = static function (array $items) use ($l): void {
	?>
	<ul class="bck-nav__list">
		<?php foreach ($items as $item): ?>
			<li class="bck-nav__item<?php if (!empty($item['active'])): ?> is-active<?php endif; ?>">
				<a class="bck-nav__link<?php if (!empty($item['active'])): ?> is-active<?php endif; ?>"
					href="<?php p($item['href']); ?>"
					<?php if (!empty($item['active'])): ?>aria-current="page"<?php endif; ?>>
					<span class="bck-nav__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render((string)($item['icon'] ?? 'list'))); ?></span>
					<span class="bck-nav__label">
						<span class="bck-nav__name"><?php p($item['label']); ?></span>
						<?php if (!empty($item['hint'])): ?>
							<span class="bck-nav__hint"><?php p((string)$item['hint']); ?></span>
						<?php endif; ?>
					</span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php
};
?>
<a class="skip-link bck-skip-link--nav" href="#app-navigation"><?php p($l->t('Skip to navigation')); ?></a>
<nav id="app-navigation" class="bck-nav" role="navigation" aria-label="<?php p($appTitle); ?>">
	<div class="bck-brand">
		<span class="bck-brand__icon" aria-hidden="true">
			<?php print_unescaped(IconCatalog::render($brandIcon, 'bck-brand__icon-svg')); ?>
		</span>
		<div class="bck-brand__text">
			<h2 class="bck-brand__title"><?php p($appTitle); ?></h2>
			<?php if ($appSubtitle !== ''): ?>
				<p class="bck-brand__subtitle"><?php p($appSubtitle); ?></p>
			<?php endif; ?>
			<span class="bck-badge bck-badge--neutral"><?php p($roleLabel); ?></span>
		</div>
	</div>
	<div class="bck-nav__body">
		<?php foreach ($navGroups as $i => $group): ?>
			<?php if ($i > 0): ?><hr class="bck-nav__divider" role="separator" /><?php endif; ?>
			<section class="bck-nav__group" aria-labelledby="bck-nav-group-<?php p((string)$i); ?>">
				<h3 class="bck-nav__group-label" id="bck-nav-group-<?php p((string)$i); ?>"><?php p((string)($group['label'] ?? '')); ?></h3>
				<?php $renderItems($group['items'] ?? []); ?>
			</section>
		<?php endforeach; ?>
		<?php if ($settingsItems !== []): ?>
			<hr class="bck-nav__divider" role="separator" />
			<section class="bck-nav__group" aria-labelledby="bck-nav-settings">
				<h3 class="bck-nav__group-label" id="bck-nav-settings"><?php p($l->t('Settings')); ?></h3>
				<?php $renderItems($settingsItems); ?>
			</section>
		<?php endif; ?>
	</div>
	<?php if (!empty($_['footerPartial'])): ?>
		<?php include $_['footerPartial']; ?>
	<?php endif; ?>
</nav>
