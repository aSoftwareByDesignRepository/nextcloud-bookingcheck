<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\CompanionToken;
use OCA\BookingCheck\Db\CompanionTokenMapper;
use OCP\ISession;

/**
 * Web-scanner door sessions.
 *
 * Door tablets are not Nextcloud users — the seller mints a `door` (or
 * `manager`) companion token and the web scanner exchanges it ONCE for a
 * cookie session. The session stores only the device row id; every
 * request revalidates the row so a revoked device loses access
 * immediately, not at session expiry.
 *
 * The session grants access to scan endpoints ONLY — AppAccessMiddleware
 * still requires the NC admin role everywhere else.
 */
final class DoorSessionService
{
	public const SESSION_KEY = 'bck_door_device';
	private const ROLES = ['door', 'manager'];

	public function __construct(
		private readonly ISession $session,
		private readonly CompanionTokenMapper $tokens,
		private readonly TokenService $tokenService,
	) {
	}

	/** Exchange a plaintext device token for a session. Uniform false —
	 *  bad token, revoked row, wrong role all look identical. */
	public function open(string $token): bool
	{
		if (trim($token) === '') {
			return false;
		}
		$row = $this->tokens->findByTokenHash($this->tokenService->hash($token));
		if (!$this->deviceUsable($row)) {
			return false;
		}
		$this->tokens->touch((int)$row->getId());
		$this->session->set(self::SESSION_KEY, (int)$row->getId());
		return true;
	}

	/** The session's device — revalidated per request so revocation is
	 *  immediate. A dead row clears the session. */
	public function device(): ?CompanionToken
	{
		$id = $this->session->get(self::SESSION_KEY);
		if (!is_int($id) && !is_string($id)) {
			return null;
		}
		$row = $this->tokens->find((int)$id);
		if (!$this->deviceUsable($row)) {
			$this->close();
			return null;
		}
		return $row;
	}

	public function close(): void
	{
		$this->session->remove(self::SESSION_KEY);
	}

	/** `offering_ids` is a JSON int list; empty/unset = all offerings.
	 *  Anything malformed fails closed — a corrupt scope row means "no
	 *  offerings", never "all". */
	public function scopeAllows(CompanionToken $device, int $offeringId): bool
	{
		$raw = $device->getOfferingIds();
		if ($raw === null || trim((string)$raw) === '') {
			return true;
		}
		$ids = json_decode((string)$raw, true);
		if (!is_array($ids)) {
			return false;
		}
		return in_array($offeringId, array_map('intval', $ids), true);
	}

	private function deviceUsable(?CompanionToken $row): bool
	{
		return $row !== null
			&& $row->getRevokedAt() === null
			&& in_array($row->getRole(), self::ROLES, true);
	}
}
