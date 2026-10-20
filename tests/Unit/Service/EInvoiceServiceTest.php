<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Service;

use OCA\BookingCheck\Db\Invoice;
use OCA\BookingCheck\Db\InvoiceMapper;
use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Db\Order;
use OCA\BookingCheck\Db\OrderLine;
use OCA\BookingCheck\Db\OrderLineMapper;
use OCA\BookingCheck\Service\EInvoiceService;
use OCA\BookingCheck\Service\SettingsService;
use Test\TestCase;

/**
 * EInvoiceService emits CII/XRechnung XML whose field values carry
 * buyer-controlled text (names, company). The contract under test:
 * the document must stay well-formed and escape every special char —
 * XMLWriter does the escaping, this is the regression net against a
 * future writeRaw()-style "optimization".
 */
final class EInvoiceServiceTest extends TestCase
{
	private function service(SettingsService $settings, OrderLineMapper $lines): EInvoiceService
	{
		return new EInvoiceService(
			$this->createMock(InvoiceMapper::class),
			$lines,
			$this->createMock(OfferingMapper::class),
			$settings,
		);
	}

	private function settings(): SettingsService
	{
		$s = $this->createMock(SettingsService::class);
		$s->method('get')->willReturnCallback(fn (string $k) => match ($k) {
			'seller_name' => 'Muster GmbH & Co.',
			'seller_country' => 'DE',
			default => '',
		});
		return $s;
	}

	private function order(): Order
	{
		$o = new Order();
		$o->setId(42);
		$o->setRef('BCK-2026-AAAABBBB');
		$o->setCurrency('EUR');
		$o->setBuyerName('Buyer <script>alert(1)</script> & "Co"');
		$o->setBuyerCompany('A & B <Traders> GmbH');
		$o->setBuyerEmail('b@x.test');
		return $o;
	}

	private function invoice(): Invoice
	{
		$i = new Invoice();
		$i->setId(7);
		$i->setPrefix('INV');
		$i->setYear(2026);
		$i->setNumber(7);
		$i->setType('invoice');
		$i->setIssuedAt(new \DateTimeImmutable('2026-10-26'));
		return $i;
	}

	private function line(): OrderLine
	{
		$l = new OrderLine();
		$l->setLineKind('seat');
		$l->setCatName('Adult & "VIP" <tier>');
		$l->setSeats(2);
		$l->setUnitCents(5000);
		$l->setLineCents(10000);
		return $l;
	}

	private function lines(OrderLine ...$ls): OrderLineMapper
	{
		$m = $this->createMock(OrderLineMapper::class);
		$m->method('findByOrder')->willReturn($ls);
		return $m;
	}

	public function testXmlIsWellFormedWithHostileBuyerData(): void
	{
		$xml = $this->service($this->settings(), $this->lines($this->line()))
			->ciiXml($this->invoice(), $this->order());
		$doc = simplexml_load_string($xml);
		self::assertNotFalse($doc, 'XML must parse: ' . substr($xml, 0, 200));
	}

	public function testBuyerSpecialCharsAreEscapedNotRaw(): void
	{
		$xml = $this->service($this->settings(), $this->lines($this->line()))
			->ciiXml($this->invoice(), $this->order());
		self::assertStringNotContainsString('<script>', $xml);
		self::assertStringContainsString('&lt;Traders&gt;', $xml);
		self::assertStringContainsString('&amp;', $xml);
		self::assertStringNotContainsString('<Traders>', $xml);
	}

	public function testBuyerNameEscapedWhenNoCompany(): void
	{
		$o = $this->order();
		$o->setBuyerCompany('');
		$xml = $this->service($this->settings(), $this->lines($this->line()))
			->ciiXml($this->invoice(), $o);
		self::assertStringNotContainsString('<script>', $xml);
		self::assertStringContainsString('&lt;script&gt;', $xml);
	}

	public function testEscapedValuesStayTruthful(): void
	{
		$xml = $this->service($this->settings(), $this->lines($this->line()))
			->ciiXml($this->invoice(), $this->order());
		// the decoded document still carries the truthful name
		$doc = simplexml_load_string($xml);
		$names = array_map('strval', $doc->xpath('//*[local-name()="Name"]'));
		self::assertContains('A & B <Traders> GmbH', $names);
		self::assertContains('Muster GmbH & Co.', $names);
	}

	public function testCreditNoteEmitsDocType381(): void
	{
		$i = $this->invoice();
		$i->setType('credit');
		$xml = $this->service($this->settings(), $this->lines($this->line()))
			->ciiXml($i, $this->order());
		self::assertStringContainsString('>381<', $xml);
	}

	public function testReverseChargeEmitsCategoryAE(): void
	{
		$o = $this->order();
		$o->setReverseCharge(1);
		$xml = $this->service($this->settings(), $this->lines($this->line()))
			->ciiXml($this->invoice(), $o);
		self::assertStringContainsString('>AE<', $xml);
	}
}
