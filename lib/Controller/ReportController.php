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
		return new TemplateResponse($this->appName, 'admin/reports', ['navId' => 'reports']);
	}

	/** Orders export — formula-injection-safe via CsvExportService. */
	#[NoAdminRequired]
	public function export()
	{
		$kind = (string)$this->request->getParam('kind', 'orders');
		$data = $this->csv->orders();
		return new DataDownloadResponse($data, 'bookingcheck-' . $kind . '-' . date('Ymd') . '.csv', 'text/csv');
	}
}
