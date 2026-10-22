<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Support;

use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * NH-05 — SDK-embed CORS reflection.
 *
 * Reflects the requester's `Origin` into `Access-Control-Allow-Origin`
 * iff it matches the offering's `embed_domains` allowlist (exact match,
 * or an `https://*.domain` suffix entry — same wildcard grammar CSP
 * frame-ancestors accepts). Foreign/absent origins get no ACAO: the
 * browser blocks the cross-origin read. The payloads behind it are
 * anonymous-readable anyway, so this leaks nothing — it only honours
 * the embed contract the seller configured.
 *
 * Single authority for the rule — controllers call it for success
 * envelopes, PublicErrorMiddleware for error envelopes (the SDK must
 * be able to read validation/409/429 responses too).
 */
final class EmbedCors
{
	public static function apply(JSONResponse $resp, IRequest $request, ?string $domainsJson): JSONResponse
	{
		$origin = trim($request->getHeader('Origin'));
		if ($origin === '' || !str_starts_with($origin, 'https://')) {
			return $resp;
		}
		$domains = json_decode((string)$domainsJson, true);
		if (!is_array($domains)) {
			return $resp;
		}
		foreach ($domains as $d) {
			if (!is_string($d)) {
				continue;
			}
			$d = trim($d);
			if (strcasecmp($origin, $d) === 0) {
				return self::reflect($resp, $origin);
			}
			// 'https://*.example.com' → any https subdomain (not the apex)
			if (str_starts_with($d, 'https://*.')
				&& str_ends_with(strtolower((string)parse_url($origin, PHP_URL_HOST)),
					strtolower(substr($d, 9)))) {
				return self::reflect($resp, $origin);
			}
		}
		return $resp;
	}

	private static function reflect(JSONResponse $resp, string $origin): JSONResponse
	{
		$resp->addHeader('Access-Control-Allow-Origin', $origin);
		$resp->addHeader('Vary', 'Origin');
		return $resp;
	}
}
