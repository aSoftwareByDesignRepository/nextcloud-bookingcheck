<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Tests\Unit\Controller;

use OCA\BookingCheck\Controller\PubCatalogController;
use OCA\BookingCheck\Db\Offering;
use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Service\CatalogService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\L10N\IFactory as IL10NFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * NH-05 — JSON offering card for the SDK embed: whitelisted payload
 * (same shape as the public page), uniform 404, ACAO via embed_domains.
 */
final class PubCatalogJsonTest extends TestCase
{
	private IRequest&MockObject $request;
	private OfferingMapper&MockObject $offerings;
	private CatalogService&MockObject $catalog;
	private IL10NFactory&MockObject $l10nFactory;
	private string $origin = '';
	private string $locale = 'en';

	protected function setUp(): void
	{
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getHeader')->willReturnCallback(
			fn (string $n): string => $n === 'Origin' ? $this->origin : '');
		$this->offerings = $this->createMock(OfferingMapper::class);
		$this->catalog = $this->createMock(CatalogService::class);
		$this->catalog->method('occurrenceList')->willReturn(
			[['target_type' => 'event', 'id' => 9, 'label' => 'Fr 19:00', 'available' => 5]]);
		$this->catalog->method('priceCatsFor')->willReturn([]);
		$this->catalog->method('addonsFor')->willReturn([]);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $k, $d = null) => $k === 'locale' ? $this->locale : $d);
		// Per-locale stub: fr returns a marker so tests can prove the
		// requested catalog was used; everything else returns the key.
		$this->l10nFactory = $this->createMock(IL10NFactory::class);
		$this->l10nFactory->method('get')->willReturnCallback(function ($app, $lang) {
			$l = $this->createMock(IL10N::class);
			$l->method('t')->willReturnCallback(
				fn (string $s) => $lang === 'fr' ? "fr:$s" : $s);
			return $l;
		});
	}

	private function ctrl(): PubCatalogController
	{
		return new PubCatalogController('bookingcheck', $this->request,
			$this->offerings, $this->catalog, $this->l10nFactory);
	}

	private function offering(array $over = []): Offering
	{
		$o = new Offering();
		$o->setId(7);
		$o->setSlug('yoga');
		$o->setType('event');
		$o->setTitle('Yoga');
		$o->setStatus($over['status'] ?? 'published');
		$o->setPriceCents(1200);
		$o->setCurrency('EUR');
		$o->setEmbedDomains($over['embed'] ?? '["https://shop.example"]');
		$o->setApprovalRequired(0);
		$o->setInvoicePay(0);
		$o->setDepositBp(0);
		$o->setTosText('Terms here.');
		return $o;
	}

	private function headersOf(\OCP\AppFramework\Http\JSONResponse $r): array
	{
		$m = new \ReflectionMethod($r, 'getHeaders');
		return $m->invoke($r);
	}

	public function testPayloadIsWhitelistedAndCarriesCheckoutFields(): void
	{
		$this->offerings->method('findBySlug')->with('yoga')->willReturn($this->offering());
		$resp = $this->ctrl()->offeringJson('yoga');
		self::assertSame(200, $resp->getStatus());
		$d = $resp->getData()['data'];
		self::assertSame(7, $d['offering']['id'], 'SDK needs the numeric id for the create POST');
		self::assertSame(1200, $d['offering']['price_cents']);
		self::assertTrue($d['tosRequired']);
		self::assertSame('event', $d['occurrences'][0]['target_type']);
		foreach (['talkRoom', 'deckBoardId', 'logoFileRef', 'createdBy'] as $internal) {
			self::assertArrayNotHasKey($internal, $d['offering'], 'internal key leaked: ' . $internal);
		}
	}

	public function testUnpublishedAndUnknownAreUniform404(): void
	{
		$this->offerings->method('findBySlug')->willReturnCallback(
			fn (string $s) => $s === 'drafted' ? $this->offering(['status' => 'draft']) : null);
		self::assertSame(404, $this->ctrl()->offeringJson('missing')->getStatus());
		self::assertSame('NOT_FOUND', $this->ctrl()->offeringJson('missing')->getData()['error']);
		self::assertSame(404, $this->ctrl()->offeringJson('drafted')->getStatus());
	}

	public function testAllowlistedOriginGetsAcao(): void
	{
		$this->offerings->method('findBySlug')->willReturn($this->offering());
		$this->origin = 'https://shop.example';
		$h = $this->headersOf($this->ctrl()->offeringJson('yoga'));
		self::assertSame('https://shop.example', $h['Access-Control-Allow-Origin'] ?? null);
		$this->origin = 'https://evil.example';
		$h = $this->headersOf($this->ctrl()->offeringJson('yoga'));
		self::assertArrayNotHasKey('Access-Control-Allow-Origin', $h);
	}

	public function testStringsCoverEverySdkKeyAndLocalize(): void
	{
		$this->offerings->method('findBySlug')->willReturn($this->offering());
		$this->locale = 'fr';
		$d = $this->ctrl()->offeringJson('yoga')->getData()['data'];
		self::assertArrayHasKey('strings', $d, 'embed widgets need server-side localized UI strings');
		// Every t.* key the SDK reads — a miss silently renders English.
		foreach (['loading','unavailable','date','seats','category','addons','name',
			'email','company','country','vatId','tosLabel','payInvoice','payNow',
			'book','booking','done','approval','manage','error','soldOut','passQty',
			'optionalFields','deposit','paymentMethod'] as $k) {
			self::assertArrayHasKey($k, $d['strings'], "missing strings.$k");
			self::assertNotSame('', $d['strings'][$k]);
		}
		self::assertSame('fr:Sold out', $d['strings']['soldOut'], 'locale=fr must resolve the fr catalog');
	}

	public function testUnknownLocaleFallsBackToEnglishStrings(): void
	{
		$this->offerings->method('findBySlug')->willReturn($this->offering());
		$this->locale = 'xx-INJECT';
		$d = $this->ctrl()->offeringJson('yoga')->getData()['data'];
		self::assertSame('Sold out', $d['strings']['soldOut'], 'unsupported locale must fall back to en');
	}
}
