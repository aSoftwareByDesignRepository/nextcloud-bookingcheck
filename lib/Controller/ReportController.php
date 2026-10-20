<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Service\CsvExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

class ReportController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CsvExportService $csv,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		return new TemplateResponse($this->appName, 'admin/reports', [
			'navId' => 'reports',
			'offerings' => array_map(
				static fn ($o) => $o->jsonSerialize(),
				\OCP\Server::get(\OCA\BookingCheck\Db\OfferingMapper::class)->findAll()),
			'abuseReports' => array_map(
				static fn ($r) => $r->jsonSerialize(),
				\OCP\Server::get(\OCA\BookingCheck\Db\AbuseReportMapper::class)->listOpen()),
		]);
	}

	/** Orders export — formula-injection-safe via CsvExportService. */
	#[NoAdminRequired]
	public function export()
	{
		// only one export exists — a forged kind must not alias the
		// download under a misleading filename
		$kind = (string)$this->request->getParam('kind', 'orders');
		if ($kind !== 'orders') {
			throw new \OCA\BookingCheck\Exception\NotFoundException();
		}
		$data = $this->csv->orders();
		return new DataDownloadResponse($data, 'bookingcheck-orders-' . date('Ymd') . '.csv', 'text/csv');
	}

	/**
	 * NH-05 — DATEV EXTF Buchungsstapel. `from`/`to` are ISO dates;
	 * default = last complete month (the accountant's rhythm).
	 */
	#[NoAdminRequired]
	public function datev()
	{
		$from = \DateTimeImmutable::createFromFormat('Y-m-d',
			(string)$this->request->getParam('from', ''));
		$to = \DateTimeImmutable::createFromFormat('Y-m-d',
			(string)$this->request->getParam('to', ''));
		if ($from === false || $to === false) {
			$first = new \DateTimeImmutable('first day of last month');
			$from = $first;
			$to = $first->modify('first day of this month');
		}
		if ($to <= $from || $to->diff($from)->days > 366 * 5) {
			throw new \OCA\BookingCheck\Exception\ValidationException(
				['range' => 'from must be before to (max 5 years).']);
		}
		$svc = \OCP\Server::get(\OCA\BookingCheck\Service\DatevExportService::class);
		$out = $svc->export($from, $to);
		return new DataDownloadResponse($out['csv'], $out['filename'], 'text/csv');
	}

	/**
	 * NH-16 — full offering export (roster + materials + uploads) as zip.
	 * Temp file streams, then unlinks — nothing lingers in /tmp.
	 */
	#[NoAdminRequired]
	public function exportZip(int $offeringId)
	{
		$offering = \OCP\Server::get(\OCA\BookingCheck\Db\OfferingMapper::class)
			->find($offeringId);
		if ($offering === null) {
			throw new \OCA\BookingCheck\Exception\NotFoundException();
		}
		$tmp = \OCP\Server::get(\OCA\BookingCheck\Service\ExportZipService::class)
			->build($offering);
		$resp = new \OCP\AppFramework\Http\StreamResponse(fopen($tmp, 'rb'));
		$resp->addHeader('Content-Type', 'application/zip');
		$resp->addHeader('Content-Disposition',
			'attachment; filename="bookingcheck-export-' . (int)$offering->getId() . '.zip"');
		register_shutdown_function(static function () use ($tmp): void {
			@unlink($tmp);
		});
		return $resp;
	}

	/** NH-30 — material download stats for the seller dashboard. */
	#[NoAdminRequired]
	public function downloadStats(int $offeringId): \OCP\AppFramework\Http\JSONResponse
	{
		$db = \OCP\Server::get(\OCP\IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select('id', 'title')->from('bc_material')
			->where($qb->expr()->eq('offering_id', $qb->createNamedParameter($offeringId)));
		$mats = $qb->executeQuery()->fetchAll();
		if ($mats === []) {
			throw new \OCA\BookingCheck\Exception\NotFoundException();
		}
		$counts = \OCP\Server::get(\OCA\BookingCheck\Db\DownloadLogMapper::class)
			->countsForOffering(array_map(static fn ($m) => (int)$m['id'], $mats));
		$out = [];
		foreach ($mats as $m) {
			$out[] = ['material_id' => (int)$m['id'], 'title' => $m['title'],
				'downloads' => $counts[(int)$m['id']] ?? 0];
		}
		return $this->ok(['stats' => $out]);
	}
}
