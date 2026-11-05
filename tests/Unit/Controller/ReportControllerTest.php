<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Controller;

use OCA\BookingCheck\Controller\ReportController;
use OCA\BookingCheck\Db\AbuseReportMapper;
use OCA\BookingCheck\Db\DownloadLogMapper;
use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Exception\ValidationException;
use OCA\BookingCheck\Service\CsvExportService;
use OCA\BookingCheck\Service\DatevExportService;
use OCA\BookingCheck\Service\ExportZipService;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * DATEV range contract (seed bookingcheck-intake-20261105T2210Z adjacent
 * regression): the empty-params default must produce [first-of-last-month
 * 00:00, first-of-this-month 00:00). The previous `->modify('first day of
 * this month')` on a "first day of last month" object stayed in the same
 * month — the default path collapsed to an empty range and 400'd on every
 * real click.
 */
final class ReportControllerTest extends TestCase
{
	private IRequest&MockObject $request;
	private DatevExportService&MockObject $datev;
	private ReportController $ctrl;

	protected function setUp(): void
	{
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->datev = $this->createMock(DatevExportService::class);
		$this->ctrl = new ReportController(
			'bookingcheck',
			$this->request,
			$this->createMock(CsvExportService::class),
			$this->createMock(OfferingMapper::class),
			$this->createMock(AbuseReportMapper::class),
			$this->createMock(ExportZipService::class),
			$this->datev,
			$this->createMock(IDBConnection::class),
			$this->createMock(DownloadLogMapper::class),
		);
	}

	private function params(array $map): void
	{
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $k, $d = null) => $map[$k] ?? $d);
	}

	public function testEmptyDatesDefaultToLastCompleteMonth(): void
	{
		$this->params([]);
		$captured = null;
		$this->datev->expects(self::once())->method('export')
			->willReturnCallback(static function (\DateTimeImmutable $from, \DateTimeImmutable $to) use (&$captured) {
				$captured = [$from, $to];
				return ['csv' => 'x', 'filename' => 'x.csv'];
			});
		$this->ctrl->datev();
		self::assertNotNull($captured);
		[$from, $to] = $captured;
		self::assertSame('01', $from->format('d'), 'from must start a month');
		self::assertSame('01', $to->format('d'), 'to must start a month');
		self::assertSame('00:00:00', $from->format('H:i:s'));
		self::assertSame('00:00:00', $to->format('H:i:s'));
		self::assertSame(
			$from->modify('+1 month')->format('Y-m-d'),
			$to->format('Y-m-d'),
			'default must span exactly the last complete month',
		);
	}

	public function testExplicitEmptyStringsAlsoDefault(): void
	{
		$this->params(['from' => '', 'to' => '']);
		$this->datev->expects(self::once())->method('export')
			->willReturn(['csv' => 'x', 'filename' => 'x.csv']);
		$this->ctrl->datev();
	}

	public function testGarbageDateIs400NotSilentDefault(): void
	{
		$this->params(['from' => 'bad', 'to' => '2026-11-01']);
		$this->datev->expects(self::never())->method('export');
		$this->expectException(ValidationException::class);
		$this->ctrl->datev();
	}

	public function testReversedRangeIs400(): void
	{
		$this->params(['from' => '2026-11-01', 'to' => '2026-10-01']);
		$this->datev->expects(self::never())->method('export');
		$this->expectException(ValidationException::class);
		$this->ctrl->datev();
	}

	public function testValidRangeParses(): void
	{
		$this->params(['from' => '2026-10-01', 'to' => '2026-11-01']);
		$this->datev->expects(self::once())->method('export')
			->willReturn(['csv' => 'x', 'filename' => 'x.csv']);
		$this->ctrl->datev();
	}
}
