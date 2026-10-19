<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/** Public directory feed — discovery only, no checkout, no PII. */
class FeedController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OfferingMapper $offerings,
		private readonly SettingsService $settings,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function directory(): JSONResponse
	{
		if (!$this->settings->getBool('directory_enabled')) {
			return $this->notFound();
		}
		$out = [];
		foreach ($this->offerings->findPublished() as $o) {
			if ((int)$o->getListInDirectory() !== 1) {
				continue;
			}
			$out[] = [
				'slug' => $o->getSlug(),
				'type' => $o->getType(),
				'title' => $o->getTitle(),
				'descr' => mb_substr((string)$o->getDescr(), 0, 500),
				'price_cents' => (int)$o->getPriceCents(),
				'currency' => $o->getCurrency(),
			];
		}
		$resp = $this->ok(['v' => 1, 'offerings' => $out]);
		// CORS allowlist from settings — a configured '*' or a non-https
		// origin is rejected, never echoed back
		foreach (array_filter(array_map('trim', explode(',', $this->settings->get('cors_origins')))) as $origin) {
			if ($origin === '*' || !str_starts_with($origin, 'https://')) {
				continue;
			}
			$resp->addHeader('Access-Control-Allow-Origin', $origin);
			break; // single origin only
		}
		return $resp;
	}
}
