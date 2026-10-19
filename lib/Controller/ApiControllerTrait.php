<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCP\AppFramework\Http\JSONResponse;

/** Uniform success/error envelope — the wire shape clients rely on. */
trait ApiControllerTrait
{
	private function ok(array $data = [], int $status = 200): JSONResponse
	{
		return new JSONResponse(['success' => true] + $data, $status);
	}

	private function fail(int $status, string $code, string $message, array $fields = []): JSONResponse
	{
		return new JSONResponse([
			'success' => false, 'error' => $code, 'message' => $message,
			'fields' => (object)$fields,
		], $status);
	}

	private function notFound(): JSONResponse
	{
		// canonical uniform-404 — never leak which leg failed
		return $this->fail(404, 'NOT_FOUND', 'Not found.');
	}

	/** Strict integer id per api-security rules — "2.9"/"2e3" rejected. */
	private function strictId(mixed $raw): ?int
	{
		if (is_int($raw)) {
			return $raw > 0 ? $raw : null;
		}
		if (is_string($raw) && preg_match('/^\d+$/', $raw)) {
			$v = (int)$raw;
			return $v > 0 ? $v : null;
		}
		return null;
	}
}
