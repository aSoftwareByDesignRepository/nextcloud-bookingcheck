<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Service\CatalogService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\IRequest;

/** Public catalog — anonymous browsing surface. */
class PubCatalogController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OfferingMapper $offerings,
		private readonly CatalogService $catalog,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): PublicTemplateResponse
	{
		$catalog = [];
		foreach ($this->offerings->findPublished() as $o) {
			$catalog[] = [
				'slug' => $o->getSlug(),
				'type' => $o->getType(),
				'title' => $o->getTitle(),
				'descr' => $o->getDescr(),
				'price_cents' => (int)$o->getPriceCents(),
				'currency' => $o->getCurrency(),
				'next' => $this->catalog->nextOccurrence($o),
			];
		}
		return new PublicTemplateResponse($this->appName, 'public/catalog', [
			'offerings' => $catalog,
		]);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function offering(string $slug): PublicTemplateResponse
	{
		$o = $this->offerings->findBySlug($slug);
		if ($o === null || $o->getStatus() !== 'published') {
			return new PublicTemplateResponse($this->appName, 'public/not-found', []);
		}
		return new PublicTemplateResponse($this->appName, 'public/offering', [
			'offering' => $o->jsonSerialize(),
			'occurrences' => $this->catalog->occurrenceList($o),
			'priceCats' => $this->catalog->priceCatsFor($o),
			'addons' => $this->catalog->addonsFor($o),
			'approvalRequired' => (int)$o->getApprovalRequired() === 1,
			'tosText' => $o->getTosText(),
		]);
	}

}
