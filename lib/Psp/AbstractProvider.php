<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Psp;

use OCA\BookingCheck\Exception\PspException;
use OCP\Http\Client\IClientService;

abstract class AbstractProvider implements PaymentProviderInterface
{
	protected const TIMEOUT = 15;

	public function __construct(
		protected readonly IClientService $clientService,
	) {
	}

	/** JSON POST with auth header; throws PspException on transport/HTTP errors. */
	/** @param array<string,string> $headers
	 * @param array<string,mixed> $body */
	protected function post(string $url, array $headers, array $body): array
	{
		try {
			$client = $this->clientService->newClient();
			$res = $client->post($url, [
				'headers' => $headers + ['Content-Type' => 'application/json'],
				'body' => json_encode($body),
				'timeout' => self::TIMEOUT,
				// Hardcoded provider endpoints today, but keep the SSRF
				// guard on — a provider host that ever becomes configurable
				// must not dial an unverified address.
				'nextcloud' => ['allow_local_address' => false],
				'allow_redirects' => false,
			]);
			return self::bodyJson($res);
		} catch (\Throwable $e) {
			throw new PspException('PSP request failed: ' . $e->getMessage(), 0, $e);
		}
	}

	/** @param array<string,string> $headers */
	protected function get(string $url, array $headers): array
	{
		try {
			$client = $this->clientService->newClient();
			$res = $client->get($url, [
				'headers' => $headers,
				'timeout' => self::TIMEOUT,
				'nextcloud' => ['allow_local_address' => false],
				'allow_redirects' => false,
			]);
			return self::bodyJson($res);
		} catch (\Throwable $e) {
			throw new PspException('PSP request failed: ' . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * getBody() is declared string|resource — a streamed body must be read
	 * to string before json_decode or it fatals on a TypeError.
	 * @return array<mixed>
	 */
	protected static function bodyJson(\OCP\Http\Client\IResponse $res): array
	{
		$body = $res->getBody();
		if (is_resource($body)) {
			$body = stream_get_contents($body);
		}
		if (!is_string($body)) {
			return [];
		}
		$decoded = json_decode($body, true);
		return is_array($decoded) ? $decoded : [];
	}
}
