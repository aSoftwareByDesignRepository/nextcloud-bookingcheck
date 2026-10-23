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
		// family wire contract: {success, data:{…}} — BCK.api resolves body.data
		return new JSONResponse(['success' => true, 'data' => $data], $status,
			[], JSON_INVALID_UTF8_SUBSTITUTE);
	}

	/** @param array<string,string> $fields field-level errors for inline display */
	private function fail(int $status, string $code, string $message, array $fields = []): JSONResponse
	{
		// Serialize in the requester's language — the message/fields reach
		// toasts and inline errors verbatim (l10n_unlocalized_error_surface).
		$l = \OCA\BookingCheck\Support\ErrorText::l10nFor($this->request);
		return new JSONResponse([
			'success' => false, 'error' => $code,
			'message' => \OCA\BookingCheck\Support\ErrorText::t($l, $message),
			'fields' => (object)\OCA\BookingCheck\Support\ErrorText::fields($l, $fields),
		], $status);
	}

	/**
	 * Translate a fragment that will be COMPOSED into a dynamic field
	 * message at the throw site (e.g. 'Unknown event' . ': ' . $name) —
	 * the boundary translator only sees whole strings, so dynamic tails
	 * must be handled before the exception is thrown.
	 */
	private function tx(string $text): string
	{
		$l = \OCA\BookingCheck\Support\ErrorText::l10nFor($this->request);
		return \OCA\BookingCheck\Support\ErrorText::t($l, $text);
	}

	private function notFound(): JSONResponse
	{
		// canonical uniform-404 — never leak which leg failed
		return $this->fail(404, 'NOT_FOUND', 'Not found.');
	}

	/** See \OCA\BookingCheck\Support\EmbedCors — the single authority. */
	private function withEmbedCors(JSONResponse $resp, ?string $domainsJson): JSONResponse
	{
		return \OCA\BookingCheck\Support\EmbedCors::apply($resp, $this->request, $domainsJson);
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
