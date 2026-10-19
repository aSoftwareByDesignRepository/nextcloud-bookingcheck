<?php
/**
 * BookingCheck page chrome — design-system Check-family shell.
 * Shared component: apps/_shared/check-shell (synced — do not edit in-app).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 *
 * Expected params:
 *   pageId, pageTitle, pageHelp, pageIcon (IconCatalog name),
 *   roleLabel (scope-strip badge), urls.home (breadcrumb root),
 *   navGroups (list of {label, items}), settingsNav (optional),
 *   clientHints {locale, htmlLang, timezone}, hideScopeStrip (optional)
 */

use OCA\BookingCheck\Service\IconCatalog;

$pageId = (string)($_['pageId'] ?? 'home');
$pageTitle = (string)($_['pageTitle'] ?? $l->t('BookingCheck'));
$pageHelp = (string)($_['pageHelp'] ?? '');
$pageIcon = (string)($_['pageIcon'] ?? 'home');
$roleLabel = (string)($_['roleLabel'] ?? $l->t('Member'));
$appTitle = (string)($_['brandTitle'] ?? $l->t('BookingCheck'));
$urls = $_['urls'] ?? [];
$clientHints = $_['clientHints'] ?? ['locale' => 'en-US', 'htmlLang' => 'en-US', 'timezone' => 'UTC'];
$htmlLang = (string)($clientHints['htmlLang'] ?? 'en-US');
$locale = (string)($clientHints['locale'] ?? $htmlLang);
$timezone = (string)($clientHints['timezone'] ?? 'UTC');
$settingsSection = (string)($_['settingsSection'] ?? '');
$urlsJson = htmlspecialchars(json_encode($urls, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
$homeUrl = (string)($urls['home'] ?? '#');

include __DIR__ . '/navigation.php';
?>
<div id="app-content" class="bck-app bck-app--<?php p($pageId); ?>"
	lang="<?php p($htmlLang); ?>"
	data-locale="<?php p($locale); ?>"
	data-bck-page="<?php p($pageId); ?>"
	data-bck-timezone="<?php p($timezone); ?>"
	data-bck-settings-section="<?php p($settingsSection); ?>"
	data-bck-urls="<?php print_unescaped($urlsJson); ?>">
	<a class="bck-skip-link" href="#bck-main-content"><?php p($l->t('Skip to main content')); ?></a>
	<div id="bck-live-region" class="bck-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
	<div id="bck-alert-region" class="bck-sr-only" role="alert" aria-live="assertive" aria-atomic="true"></div>
	<div id="app-content-wrapper" class="bck-shell">
		<!-- div, not header: an unscoped <header> outside sectioning content
		     registers a second banner landmark (duplicate banner with NC
		     #header). -->
		<div class="bck-page-header" aria-labelledby="bck-page-title">
			<div class="bck-page-header__top">
				<?php require __DIR__ . '/nav-toggle.php'; ?>
				<nav class="bck-breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
					<ol class="bck-breadcrumb__list">
						<li class="bck-breadcrumb__item">
							<a class="bck-breadcrumb__link" href="<?php p($homeUrl); ?>"><?php p($appTitle); ?></a>
						</li>
						<li class="bck-breadcrumb__item bck-breadcrumb__item--current" aria-current="page">
							<span class="bck-breadcrumb__current"><?php p($pageTitle); ?></span>
						</li>
					</ol>
				</nav>
			</div>
			<div class="bck-page-header__main">
				<div class="bck-page-header__icon" aria-hidden="true">
					<?php print_unescaped(IconCatalog::render($pageIcon, 'bck-page-header__icon-svg')); ?>
				</div>
				<div class="bck-page-header__text">
					<h1 id="bck-page-title" class="bck-page-title"><?php p($pageTitle); ?></h1>
					<?php if ($pageHelp !== ''): ?>
						<p class="bck-page-header__lead"><?php p($pageHelp); ?></p>
					<?php endif; ?>
				</div>
				<div id="bck-page-actions" class="bck-page-header__actions">
					<?php foreach (($_['pageActions'] ?? []) as $action): ?>
						<?php
						$aClass = 'bck-btn ' . (!empty($action['danger']) ? 'bck-btn--danger' : (!empty($action['primary']) ? 'bck-btn--primary' : 'bck-btn--secondary'));
						?>
						<?php if (!empty($action['href'])): ?>
							<a class="<?php p($aClass); ?>" href="<?php p($action['href']); ?>"
								<?php if (!empty($action['id'])): ?>id="<?php p($action['id']); ?>"<?php endif; ?>>
								<?php if (!empty($action['icon'])): ?><?php print_unescaped(IconCatalog::render((string)$action['icon'])); ?><?php endif; ?>
								<?php p($action['label']); ?>
							</a>
						<?php else: ?>
							<button type="button" class="<?php p($aClass); ?>"
								<?php if (!empty($action['id'])): ?>id="<?php p($action['id']); ?>"<?php endif; ?>>
								<?php if (!empty($action['icon'])): ?><?php print_unescaped(IconCatalog::render((string)$action['icon'])); ?><?php endif; ?>
								<?php p($action['label']); ?>
							</button>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
			</div>
			<?php if (empty($_['hideScopeStrip'])): ?>
			<div class="bck-scope-strip" aria-label="<?php p($l->t('Active session context')); ?>">
				<span class="bck-scope-strip__label"><?php p($l->t('Role')); ?></span>
				<span class="bck-badge bck-badge--neutral bck-scope-strip__badge"><?php p($roleLabel); ?></span>
				<span class="bck-scope-strip__sep" aria-hidden="true">·</span>
				<span class="bck-scope-strip__label"><?php p($l->t('Timezone')); ?></span>
				<span class="bck-scope-strip__value"><?php p($timezone); ?></span>
			</div>
			<?php endif; ?>
		</div>
		<main id="bck-main-content" class="bck-main" tabindex="-1" aria-labelledby="bck-page-title">
