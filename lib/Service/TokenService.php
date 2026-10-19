<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

/**
 * Bearer-token mint/verify.
 *
 * Invariant: only SHA-256 hashes of tokens are ever persisted. The plaintext
 * exists exactly once — in the URL/email we hand to its owner. Verification
 * is a constant-time hash compare.
 */
class TokenService
{
	/** @return array{0:string,1:string} [plaintext token, sha256 hash] */
	public function mint(int $bytes = 32): array
	{
		$token = rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
		return [$token, $this->hash($token)];
	}

	public function hash(string $token): string
	{
		return hash('sha256', $token);
	}

	public function verify(string $presentedToken, string $storedHash): bool
	{
		return hash_equals($storedHash, $this->hash($presentedToken));
	}

	/** Human-typable OTP code; random_int only (never mt_rand). */
	public function mintOtp(): string
	{
		return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
	}

	/** Short public refs like BCK-2026-A3F9KQX2 — unambiguous alphabet.
	 *  8 chars ≈ 40 bits: the ref doubles as the capability for the public
	 *  status/cancel routes, so it must survive brute force + throttling. */
	public function publicRef(string $prefix, int $year): string
	{
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$ref = '';
		for ($i = 0; $i < 8; $i++) {
			$ref .= $alphabet[random_int(0, strlen($alphabet) - 1)];
		}
		return sprintf('%s-%d-%s', $prefix, $year, $ref);
	}

	/** Voucher codes: 12 chars, grouped for humans, no lookalikes. */
	public function voucherCode(): string
	{
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$code = '';
		for ($i = 0; $i < 12; $i++) {
			$code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
		}
		return $code;
	}
}
