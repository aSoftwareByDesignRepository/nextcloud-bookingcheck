<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\AbuseReportMapper;
use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Service\CsvExportService;
use OCA\BookingCheck\Service\ExportZipService;
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
		private readonly OfferingMapper $offerings,
		private readonly AbuseReportMapper $abuseReports,
		private readonly ExportZipService $exportZip,
		private readonly \OCA\BookingCheck\Service\DatevExportService $datev,
		private readonly \OCP\IDBConnection $db,
		private readonly \OCA\BookingCheck\Db\DownloadLogMapper $downloadLog,
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
				$this->offerings->findAll()),
			'abuseReports' => array_map(
				static fn ($r) => $r->jsonSerialize(),
				$this->abuseReports->listOpen()),
		]);
	}

	/** Orders export — formula-injection-safe via CsvExportService. */
	#[NoAdminRequired]
	public function export(): DataDownloadResponse
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
	 * #[NoCSRFRequired] is mandatory: the only caller is the method=get
	 * form in templates/admin/reports.php — a tokenless navigation the
	 * SecurityMiddleware would 412 before this method ever ran. The
	 * export is read-only and AppAccessMiddleware re-checks authz.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function datev(): DataDownloadResponse
	{
		$fromRaw = (string)$this->request->getParam('from', '');
		$toRaw = (string)$this->request->getParam('to', '');
		if ($fromRaw === '' && $toRaw === '') {
			// Default = last complete month at date granularity. Both
			// bounds resolve against today — `->modify('first day of this
			// month')` on a "first day of last month" object stays in
			// October, which collapses the range and 400s.
			$from = (new \DateTimeImmutable('first day of last month'))->setTime(0, 0);
			$to = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0);
		} else {
			$from = \DateTimeImmutable::createFromFormat('Y-m-d', $fromRaw);
			$to = \DateTimeImmutable::createFromFormat('Y-m-d', $toRaw);
			if ($from === false || $to === false) {
				throw new \OCA\BookingCheck\Exception\ValidationException(
					['range' => 'from and to must be ISO dates (YYYY-MM-DD).']);
			}
		}
		if ($to <= $from || $to->diff($from)->days > 366 * 5) {
			throw new \OCA\BookingCheck\Exception\ValidationException(
				['range' => 'from must be before to (max 5 years).']);
		}
		$out = $this->datev->export($from, $to);
		return new DataDownloadResponse($out['csv'], $out['filename'], 'text/csv');
	}

	/**
	 * NH-16 — full offering export (roster + materials + uploads) as zip.
	 * Temp file streams, then unlinks — nothing lingers in /tmp.
	 * #[NoCSRFRequired] is mandatory: the only caller is a raw <a href>
	 * download link in templates/admin/reports.php — a tokenless
	 * navigation the SecurityMiddleware would 412 before this method
	 * ever ran. Read-only; AppAccessMiddleware re-checks authz.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function exportZip(int $offeringId): \OCP\AppFramework\Http\Response
	{
		$offering = $this->offerings->find($offeringId);
		if ($offering === null) {
			throw new \OCA\BookingCheck\Exception\NotFoundException();
		}
		$tmp = $this->exportZip->build($offering);
		// unlink registration BEFORE any throwable call below — a failed
		// fopen/StreamResponse must not orphan the zip in /tmp
		register_shutdown_function(static function () use ($tmp): void {
			@unlink($tmp);
		});
		$fh = fopen($tmp, 'rb');
		if ($fh === false) {
			return $this->fail(500, 'EXPORT_FAILED', 'Export stream failed.');
		}
		$resp = new \OCP\AppFramework\Http\StreamResponse($fh);
		$resp->addHeader('Content-Type', 'application/zip');
		$resp->addHeader('Content-Disposition',
			'attachment; filename="bookingcheck-export-' . (int)$offering->getId() . '.zip"');
		return $resp;
	}

	/**
	 * NH-30 — material download stats for the seller dashboard.
	 * #[NoCSRFRequired] is mandatory: the only caller is a raw <a href>
	 * in templates/admin/reports.php — a tokenless navigation the
	 * SecurityMiddleware would 412 before this method ever ran.
	 * Read-only; AppAccessMiddleware re-checks authz.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function downloadStats(int $offeringId): \OCP\AppFramework\Http\JSONResponse
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'title')->from('bc_material')
			->where($qb->expr()->eq('offering_id', $qb->createNamedParameter($offeringId)));
		$mats = $qb->executeQuery()->fetchAll();
		if ($mats === []) {
			throw new \OCA\BookingCheck\Exception\NotFoundException();
		}
		$counts = $this->downloadLog
			->countsForOffering(array_map(static fn ($m) => (int)$m['id'], $mats));
		$out = [];
		foreach ($mats as $m) {
			$out[] = ['material_id' => (int)$m['id'], 'title' => $m['title'],
				'downloads' => $counts[(int)$m['id']] ?? 0];
		}
		return $this->ok(['stats' => $out]);
	}
}
