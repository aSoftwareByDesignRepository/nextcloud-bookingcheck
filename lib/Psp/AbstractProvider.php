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
	protected function post(string $url, array $headers, array $body): array
	{
		try {
			$client = $this->clientService->newClient();
			$res = $client->post($url, [
				'headers' => $headers + ['Content-Type' => 'application/json'],
				'body' => json_encode($body),
				'timeout' => self::TIMEOUT,
			]);
			return json_decode($res->getBody(), true) ?? [];
		} catch (\Throwable $e) {
			throw new PspException('PSP request failed: ' . $e->getMessage(), 0, $e);
		}
	}

	protected function get(string $url, array $headers): array
	{
		try {
			$client = $this->clientService->newClient();
			$res = $client->get($url, ['headers' => $headers, 'timeout' => self::TIMEOUT]);
			return json_decode($res->getBody(), true) ?? [];
		} catch (\Throwable $e) {
			throw new PspException('PSP request failed: ' . $e->getMessage(), 0, $e);
		}
	}
}
