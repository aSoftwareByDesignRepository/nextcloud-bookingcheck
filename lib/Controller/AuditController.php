<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IDBConnection;
use OCP\IRequest;

class AuditController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IDBConnection $db,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'order_id', 'offering_id', 'actor', 'action',
			'from_state', 'to_state', 'created_at')
			->from('bc_audit')
			->orderBy('id', 'DESC')
			->setMaxResults(500);
		return new TemplateResponse($this->appName, 'admin/audit', [
			'navId' => 'audit',
			'entries' => $qb->executeQuery()->fetchAll(),
		]);
	}
}
