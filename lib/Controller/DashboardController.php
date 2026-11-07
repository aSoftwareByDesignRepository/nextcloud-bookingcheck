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
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IDBConnection;
use OCP\IRequest;

class DashboardController extends Controller
{
	use ApiControllerTrait;

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
		return new TemplateResponse($this->appName, 'admin/overview', [
			'navId' => 'overview',
			'stats' => $this->statsData(),
			'recentOrders' => $this->recentOrders(),
		]);
	}

	/** @return list<array<string,mixed>> */
	private function recentOrders(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('o.id', 'o.ref', 'o.status', 'o.buyer_name',
			'o.amount_cents', 'o.currency', 'o.created_at', 'f.title')
			->from('bc_order', 'o')
			->leftJoin('o', 'bc_offering', 'f', $qb->expr()->eq('f.id', 'o.offering_id'))
			->orderBy('o.id', 'DESC')
			->setMaxResults(10);
		return $qb->executeQuery()->fetchAll();
	}

	#[NoAdminRequired]
	public function stats(): JSONResponse
	{
		return $this->ok($this->statsData());
	}

	/** @return array<string,mixed> */
	private function statsData(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('status', $qb->func()->count('id', 'n'))
			->from('bc_order')
			->where($qb->expr()->eq('is_test', $qb->createNamedParameter(0)))
			->groupBy('status');
		$byStatus = [];
		foreach ($qb->executeQuery()->fetchAll() as $r) {
			$byStatus[$r['status']] = (int)$r['n'];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from('bc_offering')
			->where($qb->expr()->eq('status', $qb->createNamedParameter('published')));
		$published = (int)$qb->executeQuery()->fetchOne();
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->sum('amount_cents'))->from('bc_order')
			->where($qb->expr()->eq('status', $qb->createNamedParameter('paid')))
			->andWhere($qb->expr()->eq('is_test', $qb->createNamedParameter(0)));
		$revenue = (int)$qb->executeQuery()->fetchOne();
		return [
			'orders_by_status' => $byStatus,
			'published_offerings' => $published,
			'revenue_cents' => $revenue,
		];
	}
	/** Compact widget snapshot (NH-P2): one doc per sync + serverNow (MH-P18), 60 s/user cache. */
	#[NoAdminRequired]
	public function widgetSnapshot(): JSONResponse
	{
		$uid = \OCP\Server::get(\OCP\IUserSession::class)->getUser()?->getUID();
		if ($uid === null) return new JSONResponse(['error' => 'unauthenticated'], 401);
		$cache = \OCP\Server::get(\OCP\ICacheFactory::class)->createDistributed('bookingcheck_wsnap');
		$key = $uid . ':stats';
		if (($hit = $cache->get($key)) !== null) return new JSONResponse($hit);
		$status = $this->stats()->getData();
		$payload = ['status' => $status,
			'serverNow' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM)];
		$cache->set($key, $payload, 60);
		return new JSONResponse($payload);
	}

}
