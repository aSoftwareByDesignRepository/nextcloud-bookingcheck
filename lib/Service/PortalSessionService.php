<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\PortalSession;
use OCA\BookingCheck\Db\PortalSessionMapper;
use OCP\IDBConnection;

/**
 * Portal sessions: 256-bit bearer token in an httponly cookie, hash at
 * rest, 30d sliding expiry, 12-month absolute cap. Admin can revoke per
 * email; user logs out by revoking own row.
 */
class PortalSessionService
{
	private const ABS_CAP_DAYS = 365;

	public function __construct(
		private readonly PortalSessionMapper $sessions,
		private readonly TokenService $tokens,
		private readonly SettingsService $settings,
		private readonly IDBConnection $db,
	) {
	}

	/** @return array{0:string,1:PortalSession} plaintext token + row */
	public function create(string $email, string $ua): array
	{
		[$token, $hash] = $this->tokens->mint();
		$now = new \DateTimeImmutable();
		$days = min($this->settings->getInt('portal_sess_days', 30), self::ABS_CAP_DAYS);
		$s = new PortalSession();
		$s->setTokenHash($hash);
		$s->setEmail(strtolower(trim($email)));
		$s->setCreatedAt($now);
		$s->setExpiresAt($now->modify('+' . $days . ' days'));
		$s->setAbsExpiresAt($now->modify('+' . self::ABS_CAP_DAYS . ' days'));
		$s->setLastSeenAt($now);
		$s->setUaShort(mb_substr($ua, 0, 190));
		$this->sessions->insert($s);
		return [$token, $s];
	}

	/**
	 * Resolve a presented cookie token → email or null.
	 * Sliding renewal: extends expires_at but never beyond abs_expires_at.
	 */
	public function resolve(string $token): ?string
	{
		if ($token === '' || strlen($token) > 128) {
			return null;
		}
		$row = $this->sessions->findByTokenHash($this->tokens->hash($token));
		if ($row === null || $row->getRevokedAt() !== null) {
			return null;
		}
		$now = new \DateTimeImmutable();
		if ($row->getAbsExpiresAt() !== null && $row->getAbsExpiresAt() < $now) {
			return null;
		}
		// sliding expiry must be ENFORCED before it is renewed — without this
		// check an idle session stays valid for the whole absolute cap
		if ($row->getExpiresAt() !== null && $row->getExpiresAt() < $now) {
			return null;
		}
		// slide the window, capped by the absolute expiry
		$days = min($this->settings->getInt('portal_sess_days', 30), self::ABS_CAP_DAYS);
		$newExp = $now->modify('+' . $days . ' days');
		if ($row->getAbsExpiresAt() !== null && $newExp > $row->getAbsExpiresAt()) {
			$newExp = $row->getAbsExpiresAt();
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_portal_sess')
			->set('expires_at', $qb->createNamedParameter($newExp, 'datetime'))
			->set('last_seen_at', $qb->createNamedParameter($now, 'datetime'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row->getId())));
		$qb->executeStatement();
		return $row->getEmail();
	}

	public function revoke(string $token): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_portal_sess')
			->set('revoked_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
			->where($qb->expr()->eq('token_hash', $qb->createNamedParameter($this->tokens->hash($token))));
		$qb->executeStatement();
	}

	public function revokeAllForEmail(string $email): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_portal_sess')
			->set('revoked_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
			->where($qb->expr()->eq('email', $qb->createNamedParameter(strtolower(trim($email)))))
			->andWhere($qb->expr()->isNull('revoked_at'));
		$qb->executeStatement();
	}
}
