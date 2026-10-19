<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\Otp;
use OCA\BookingCheck\Db\OtpMapper;
use OCP\IDBConnection;

/**
 * Passwordless portal codes (§9.8).
 *
 * 6 digits, random_int, SHA-256 at rest, 10-min TTL, ≤5 verify attempts,
 * single active code per email (a new request invalidates the prior
 * unused one). The same email also gets a magic link with identical
 * bearer semantics. send/verify answers are uniform — no oracle.
 */
class OtpService
{
	public const MAX_ATTEMPTS = 5;

	public function __construct(
		private readonly OtpMapper $otps,
		private readonly TokenService $tokens,
		private readonly SettingsService $settings,
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * Issue a fresh code + magic link. Invalidates any prior unused code.
	 * Returns [code, magicToken] — the caller emails them and NEVER stores plaintext.
	 */
	public function issue(string $email, string $ip): array
	{
		$email = strtolower(trim($email));
		$code = $this->tokens->mintOtp();
		[$magicToken, $magicHash] = $this->tokens->mint();
		$ttl = $this->settings->getInt('otp_ttl_min', 10);
		$now = new \DateTimeImmutable();

		$this->db->beginTransaction();
		try {
			$this->otps->invalidateActive($email);
			$o = new Otp();
			$o->setEmail($email);
			$o->setCodeHash($this->tokens->hash($code));
			$o->setMagicTokenHash($magicHash);
			$o->setAttempts(0);
			$o->setCreatedAt($now);
			$o->setExpiresAt($now->modify('+' . $ttl . ' minutes'));
			$o->setIpHash(hash('sha256', $ip));
			$this->otps->insert($o);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return [$code, $magicToken];
	}

	/**
	 * Verify a presented code. Consumes on success (single use) and on
	 * attempt exhaustion. Returns true only on exact, unexpired match.
	 */
	public function verify(string $email, string $code): bool
	{
		$row = $this->otps->findActive(strtolower(trim($email)));
		if ($row === null) {
			return false;
		}
		if ((int)$row->getAttempts() >= self::MAX_ATTEMPTS) {
			return false;
		}
		// count the attempt BEFORE comparing — a race can only burn tries
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_otp')->set('attempts', $qb->createFunction('attempts + 1'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row->getId())));
		$qb->executeStatement();

		if (!$this->tokens->verify($code, $row->getCodeHash())) {
			return false;
		}
		// exactly-once: only the request that wins the used_at CAS may proceed
		return $this->consume((int)$row->getId());
	}

	/** Magic-link consume: single use, hashed lookup. */
	public function consumeMagic(string $token): ?string
	{
		$row = $this->otps->findByMagicHash($this->tokens->hash($token));
		if ($row === null) {
			return null;
		}
		// exactly-once under concurrency; loser sees null (dead link)
		return $this->consume((int)$row->getId()) ? $row->getEmail() : null;
	}

	private function consume(int $id): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_otp')->set('used_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->isNull('used_at'));
		return $qb->executeStatement() === 1;
	}
}
