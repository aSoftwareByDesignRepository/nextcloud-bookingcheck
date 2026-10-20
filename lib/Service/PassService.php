<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\Offering;
use OCA\BookingCheck\Db\Order;
use OCA\BookingCheck\Db\Pass;
use OCA\BookingCheck\Db\PassMapper;
use OCA\BookingCheck\Db\PassUseMapper;
use OCP\IDBConnection;

/**
 * NH-28 — annual passes / memberships (recurring-validity tickets).
 *
 * Model: an offering of type 'pass' is sold like a seat; on paid, one
 * bc_pass row per seat is minted with a 256-bit bearer token stored
 * SHA-256 — same contract as ticket QR tokens (NN-15). The pass grants
 * admission to the offerings listed in `pass_scope` (JSON ids; NULL =
 * only the pass offering itself).
 *
 * Door flow: scan BCK1P-<48hex> → resolve hash → scope check →
 * CAS uses++ + unique (pass,target) admission row. A pass never books a
 * seat (no capacity consumed — passes admit, not reserve); the unique
 * admission index stops re-admission to the same target; uses_max caps
 * total uses for limited products.
 */
class PassService
{
	public function __construct(
		private readonly PassMapper $passes,
		private readonly PassUseMapper $uses,
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * Mint passes for a freshly-paid pass-offering order. Idempotent —
	 * existing passes for the order are returned on replay.
	 * @return list<array{pass:Pass,token:string}>
	 */
	public function issueForOrder(Order $order): array
	{
		$existing = $this->passes->listForOrder((int)$order->getId());
		if ($existing !== []) {
			return array_map(fn ($p) => ['pass' => $p, 'token' => ''], $existing);
		}
		$out = [];
		$seats = max(1, $this->seatCount((int)$order->getId()));
		$validFrom = new \DateTimeImmutable();
		$validUntil = $validFrom->modify('+1 year'); // membership-year default
		for ($i = 0; $i < $seats; $i++) {
			$token = 'BCK1P-' . bin2hex(random_bytes(24));
			$p = new Pass();
			$p->setOfferingId((int)$order->getOfferingId());
			$p->setOrderId((int)$order->getId());
			$p->setTokenHash(hash('sha256', $token));
			$p->setHolderName((string)$order->getBuyerName());
			$p->setHolderEmail((string)$order->getBuyerEmail());
			$p->setValidFrom($validFrom);
			$p->setValidUntil($validUntil);
			$p->setUsesMax(null); // unlimited within validity window
			$p->setUses(0);
			$p->setStatus('active');
			$p->setCreatedAt($validFrom);
			$this->passes->insert($p);
			$out[] = ['pass' => $p, 'token' => $token];
		}
		return $out;
	}

	/**
	 * Door admission — resolve token, validate, claim one use.
	 * @return array{ok:bool, reason:string, holder:?string}
	 */
	public function admit(string $token, string $targetType, int $targetId, int $offeringIdOfTarget, string $device): array
	{
		$pass = $this->passes->findByTokenHash(hash('sha256', $token));
		if ($pass === null || $pass->getStatus() !== 'active') {
			return ['ok' => false, 'reason' => 'invalid', 'holder' => null];
		}
		$now = new \DateTimeImmutable();
		if ($now < $pass->getValidFrom() || $now > $pass->getValidUntil()) {
			return ['ok' => false, 'reason' => 'outside_validity', 'holder' => $pass->getHolderName()];
		}
		if (!$this->scopeAllows($pass->getOfferingId(), $offeringIdOfTarget)) {
			return ['ok' => false, 'reason' => 'out_of_scope', 'holder' => $pass->getHolderName()];
		}
		$this->db->beginTransaction();
		try {
			if (!$this->passes->claimUse((int)$pass->getId())) {
				$this->db->rollBack();
				return ['ok' => false, 'reason' => 'exhausted', 'holder' => $pass->getHolderName()];
			}
			if (!$this->uses->record((int)$pass->getId(), $targetType, $targetId, $device)) {
				$this->db->rollBack();
				return ['ok' => false, 'reason' => 'already_admitted', 'holder' => $pass->getHolderName()];
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return ['ok' => true, 'reason' => 'admitted', 'holder' => $pass->getHolderName()];
	}

	/** Revoke all passes of an order (cancel/refund path). */
	public function revokeForOrder(int $orderId): void
	{
		foreach ($this->passes->listForOrder($orderId) as $p) {
			$p->setStatus('revoked');
			$this->passes->update($p);
		}
	}

	/** pass_scope JSON offering ids; NULL means the pass offering itself. */
	private function scopeAllows(int $passOfferingId, int $targetOfferingId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('pass_scope')->from('bc_offering')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($passOfferingId)));
		$raw = $qb->executeQuery()->fetchOne();
		$scope = $raw === false || $raw === null || $raw === '' ? null : json_decode((string)$raw, true);
		if (!is_array($scope) || $scope === []) {
			return $passOfferingId === $targetOfferingId;
		}
		return in_array($targetOfferingId, array_map('intval', $scope), true);
	}

	private function seatCount(int $orderId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->sum('seats'))->from('bc_order_line')
			->where($qb->expr()->eq('order_id', $qb->createNamedParameter($orderId)))
			->andWhere($qb->expr()->in('line_kind', $qb->createNamedParameter(['seat', 'pass'],
			\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
		return (int)($qb->executeQuery()->fetchOne() ?: 0);
	}
}
