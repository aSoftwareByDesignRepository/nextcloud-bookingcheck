<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Middleware;

use OCA\BookingCheck\AppInfo\Application;
use OCA\BookingCheck\Exception\CapacityException;
use OCA\BookingCheck\Exception\NotFoundException;
use OCA\BookingCheck\Exception\RateLimitException;
use OCA\BookingCheck\Exception\StateConflictException;
use OCA\BookingCheck\Exception\UnauthorizedException;
use OCA\BookingCheck\Exception\ValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

/**
 * Canonical error envelope for bookingcheck routes.
 *
 * "Not found" and "not yours" collapse into ONE payload — no existence
 * oracle (api-security-patterns §1). ValidationException carries a
 * fields map for inline rendering (§5).
 */
class PublicErrorMiddleware extends Middleware
{
	public function __construct(private readonly IRequest $request)
	{
	}

	public function afterException(Controller $controller, string $methodName, \Exception $exception): JSONResponse|\OCP\AppFramework\Http\Response
	{
		// Only handle our app's controllers — let other apps' errors pass.
		if (!str_starts_with(get_class($controller), 'OCA\\BookingCheck\\')) {
			throw $exception;
		}
		if ($exception instanceof ValidationException) {
			return $this->envelope(400, 'VALIDATION', 'Please check the highlighted fields.', $exception->getFields());
		}
		if ($exception instanceof NotFoundException) {
			return $this->envelope(404, 'NOT_FOUND', 'Not found.');
		}
		if ($exception instanceof CapacityException) {
			return $this->envelope(409, $exception->getCodeName(), $exception->getMessage());
		}
		if ($exception instanceof StateConflictException) {
			return $this->envelope(409, $exception->getCodeName(), $exception->getMessage());
		}
		if ($exception instanceof UnauthorizedException) {
			return $this->envelope(401, 'UNAUTHORIZED', 'Unauthorized.');
		}
		if ($exception instanceof RateLimitException) {
			return $this->envelope(429, 'RATE_LIMITED', 'Too many requests — please try again shortly.');
		}
		if ($exception instanceof \OCA\BookingCheck\Exception\PspException) {
			return $this->envelope(502, 'PAYMENT_UNAVAILABLE', 'Payment is temporarily unavailable.');
		}
		throw $exception; // unknown → framework 500 (logged upstream)
	}

	private function envelope(int $status, string $code, string $message, array $fields = []): JSONResponse
	{
		return new JSONResponse([
			'success' => false,
			'error' => $code,
			'message' => $message,
			'fields' => (object)$fields,
		], $status);
	}
}
