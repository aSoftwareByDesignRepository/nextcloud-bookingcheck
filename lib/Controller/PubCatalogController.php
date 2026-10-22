<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Service\CatalogService;
use OCA\BookingCheck\Support\Locales;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\IRequest;
use OCP\L10N\IFactory as IL10NFactory;

/** Public catalog — anonymous browsing surface. */
class PubCatalogController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OfferingMapper $offerings,
		private readonly CatalogService $catalog,
		private readonly IL10NFactory $l10nFactory,
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
				'next_start' => $this->catalog->nextOccurrence($o),
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
			return \OCA\BookingCheck\Support\PublicNotFound::render([]);
		}
		// Curated whitelist — jsonSerialize() leaks internal refs
		// (talkRoom token, deckBoardId, logoFileRef) to anonymous visitors,
		// and the template reads snake_case keys the full serialize doesn't
		// even have ('price_cents' — the badge silently rendered €0.00).
		return new PublicTemplateResponse($this->appName, 'public/offering', [
			'offering' => [
				'slug' => $o->getSlug(),
				'type' => $o->getType(),
				'title' => $o->getTitle(),
				'descr' => $o->getDescr(),
				'price_cents' => (int)$o->getPriceCents(),
				'currency' => $o->getCurrency(),
			],
			'occurrences' => $this->catalog->occurrenceList($o),
			'priceCats' => $this->catalog->priceCatsFor($o),
			'addons' => $this->catalog->addonsFor($o),
			'approvalRequired' => (int)$o->getApprovalRequired() === 1,
			'tosText' => $o->getTosText(),
		]);
	}

	/**
	 * NH-05 — JSON offering card for the SDK/WebComponent embed
	 * (`<bck-booking>`). Same curated whitelist as the public page —
	 * never jsonSerialize (internal refs). ACAO is reflected only for
	 * origins in the offering's embed_domains allowlist; the payload
	 * itself is anonymous-readable catalog data either way.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function offeringJson(string $slug): JSONResponse
	{
		$o = $this->offerings->findBySlug($slug);
		if ($o === null || $o->getStatus() !== 'published') {
			return $this->notFound();
		}
		$isPass = $o->getType() === 'pass';
		return $this->withEmbedCors($this->ok([
			'strings' => $this->embedStrings($this->request->getParam('locale')),
			'offering' => [
				'id' => (int)$o->getId(),
				'slug' => $o->getSlug(),
				'type' => $o->getType(),
				'title' => $o->getTitle(),
				'descr' => $o->getDescr(),
				'price_cents' => (int)$o->getPriceCents(),
				'currency' => $o->getCurrency(),
			],
			'occurrences' => $isPass ? [] : $this->catalog->occurrenceList($o),
			'priceCats' => $isPass ? [] : $this->catalog->priceCatsFor($o),
			'addons' => $isPass ? [] : $this->catalog->addonsFor($o),
			'approvalRequired' => !$isPass && (int)$o->getApprovalRequired() === 1,
			'invoicePay' => (int)$o->getInvoicePay() === 1,
			'depositBp' => (int)$o->getDepositBp(),
			'tosRequired' => $o->getTosText() !== null && trim((string)$o->getTosText()) !== '',
			'tosText' => $o->getTosText(),
		]), $o->getEmbedDomains());
	}

	/**
	 * UI strings for the `<bck-booking>` embed widget. The SDK carries an
	 * en/de bundle as offline/old-server fallback; when this key is present
	 * it overrides per `?locale=` from the app's own catalog — the single
	 * source of truth for all shipped languages. Unknown locales fall back
	 * to English (the factory resolves missing languages to en).
	 */
	private function embedStrings(mixed $locale): array
	{
		$l = $this->l10nFactory->get('bookingcheck', Locales::canonicalize($locale));
		return [
			'loading'        => $l->t('Loading…'),
			'unavailable'    => $l->t('This booking is currently unavailable.'),
			'date'           => $l->t('Date'),
			'seats'          => $l->t('Seats'),
			'category'       => $l->t('Ticket type'),
			'addons'         => $l->t('Extras'),
			'name'           => $l->t('Name'),
			'email'          => $l->t('Email'),
			'company'        => $l->t('Company (optional)'),
			'country'        => $l->t('Billing country (optional, e.g. DE)'),
			'vatId'          => $l->t('VAT ID (optional)'),
			'tosLabel'       => $l->t('I accept the terms'),
			'payInvoice'     => $l->t('Pay by invoice'),
			'payNow'         => $l->t('Pay now'),
			'book'           => $l->t('Book'),
			'booking'        => $l->t('Booking…'),
			'done'           => $l->t('Booking confirmed'),
			'approval'       => $l->t('Booking request sent — the seller will confirm shortly.'),
			'manage'         => $l->t('Open your booking'),
			'error'          => $l->t('Something went wrong — please try again.'),
			'soldOut'        => $l->t('Sold out'),
			'passQty'        => $l->t('Quantity'),
			'optionalFields' => $l->t('Show company / VAT fields'),
			'deposit'        => $l->t('Deposit'),
			'paymentMethod'  => $l->t('Payment method'),
		];
	}
}
