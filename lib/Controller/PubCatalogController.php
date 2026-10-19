<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\EventMapper;
use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Db\SeriesMapper;
use OCA\BookingCheck\Db\SlotMapper;
use OCA\BookingCheck\Db\SlotResourceMapper;
use OCA\BookingCheck\Db\ResourceMapper;
use OCA\BookingCheck\Db\PriceCatMapper;
use OCA\BookingCheck\Db\AddonMapper;
use OCA\BookingCheck\Service\ReservationService;
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
		private readonly EventMapper $events,
		private readonly SeriesMapper $series,
		private readonly SlotMapper $slots,
		private readonly SlotResourceMapper $slotRes,
		private readonly ResourceMapper $resources,
		private readonly PriceCatMapper $priceCats,
		private readonly AddonMapper $addons,
		private readonly ReservationService $inventory,
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
				'next' => $this->nextOccurrence($o),
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
			'occurrences' => $this->occurrenceList($o),
			'priceCats' => array_map(fn ($c) => $c->jsonSerialize(), $this->priceCats->findAll()),
			'addons' => array_map(fn ($a) => $a->jsonSerialize(), $this->addons->findAll()),
			'approvalRequired' => (int)$o->getApprovalRequired() === 1,
			'tosText' => $o->getTosText(),
		]);
	}

	private function nextOccurrence($o): ?string
	{
		if ($o->getType() === 'timeslot') {
			$next = $this->slots->findUpcoming((int)$o->getId(), 1);
			return $next === [] ? null : $next[0]->getStartsAt()?->format(\DateTimeInterface::ATOM);
		}
		$next = $this->events->findUpcoming((int)$o->getId(), 1);
		return $next === [] ? null : $next[0]->getStartsAt()?->format(\DateTimeInterface::ATOM);
	}

	private function occurrenceList($o): array
	{
		$out = [];
		if ($o->getType() === 'timeslot') {
			foreach ($this->slots->findUpcoming((int)$o->getId()) as $s) {
				$out[] = [
					'target_type' => 'slot', 'id' => (int)$s->getId(),
					'starts_at' => $s->getStartsAt()?->format(\DateTimeInterface::ATOM),
					'ends_at' => $s->getEndsAt()?->format(\DateTimeInterface::ATOM),
					'available' => $this->inventory->available('slot', (int)$s->getId()),
				];
			}
			return $out;
		}
		foreach ($this->events->findUpcoming((int)$o->getId()) as $e) {
			$out[] = [
				'target_type' => 'event', 'id' => (int)$e->getId(),
				'starts_at' => $e->getStartsAt()?->format(\DateTimeInterface::ATOM),
				'ends_at' => $e->getEndsAt()?->format(\DateTimeInterface::ATOM),
				'available' => $this->inventory->available('event', (int)$e->getId()),
				'series_id' => $e->getSeriesId() !== null ? (int)$e->getSeriesId() : null,
			];
		}
		if ((int)$o->getBookSeries() === 1) {
			foreach ($this->series->findAll() as $ser) {
				if ((int)$ser->getOfferingId() !== (int)$o->getId()) {
					continue;
				}
				$out[] = [
					'target_type' => 'series', 'id' => (int)$ser->getId(),
					'title' => $ser->getTitle(),
					'available' => $this->inventory->available('series', (int)$ser->getId()),
				];
			}
		}
		return $out;
	}
}
