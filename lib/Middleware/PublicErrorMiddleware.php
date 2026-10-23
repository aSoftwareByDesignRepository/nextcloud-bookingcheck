<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Middleware;

use OCA\BookingCheck\AppInfo\Application;
use OCA\BookingCheck\Exception\AppDoorDeniedException;
use OCA\BookingCheck\Exception\CapacityException;
use OCA\BookingCheck\Exception\NotFoundException;
use OCA\BookingCheck\Exception\ProRequiredException;
use OCA\BookingCheck\Exception\RateLimitException;
use OCA\BookingCheck\Exception\StateConflictException;
use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Exception\UnauthorizedException;
use OCA\BookingCheck\Exception\ValidationException;
use OCA\BookingCheck\Support\EmbedCors;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Canonical error envelope for bookingcheck routes.
 *
 * "Not found" and "not yours" collapse into ONE payload — no existence
 * oracle (api-security-patterns §1). ValidationException carries a
 * fields map for inline rendering (§5).
 */
class PublicErrorMiddleware extends Middleware
{
	public function __construct(
		private readonly IRequest $request,
		private readonly OfferingMapper $offerings,
		private readonly LoggerInterface $logger,
	) {
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
			// Same surface split as the door-deny branch: API clients and
			// non-GET mutations keep the canonical envelope, but a browser
			// page navigation landing here followed a dead link — a JSON
			// blob is a dead end (page_route_error_renders_json_blob).
			// Both HTML answers are identical for missing and foreign
			// entities, so the uniform-404 collapse survives.
			$path = (string)$this->request->getPathInfo();
			$isApi = str_contains($path, '/api/')
				|| str_contains($path, '/capi/')
				|| str_contains($path, '/ocs/')
				|| $this->request->getMethod() !== 'GET';
			if ($isApi) {
				return $this->envelope(404, 'NOT_FOUND', 'Not found.');
			}
			if (str_contains($path, '/pub/')) {
				// Buyer-facing pages get the branded "nothing here" card
				// with a recovery CTA — the same answer the controllers
				// already return inline for bad manage/claim links.
				return \OCA\BookingCheck\Support\PublicNotFound::render();
			}
			$r = new TemplateResponse('core', '404', [], 'guest');
			$r->setStatus(Http::STATUS_NOT_FOUND);
			return $r;
		}
		if ($exception instanceof CapacityException) {
			return $this->envelope(409, $exception->getCodeName(), $exception->getMessage());
		}
		if ($exception instanceof StateConflictException) {
			return $this->envelope(409, $exception->getCodeName(), $exception->getMessage());
		}
		if ($exception instanceof AppDoorDeniedException) {
			// Whole-app door deny is indistinguishable from an absent app:
			// API surfaces get the canonical NOT_FOUND envelope, page
			// navigations get the core 404 template — a 403 or a friendly
			// needs-role page would leak "installed but you are excluded"
			// (restricted_door_status_oracle, carecheck/budgetcheck shape).
			$path = (string)$this->request->getPathInfo();
			$isApi = str_contains($path, '/api/')
				|| str_contains($path, '/capi/')
				|| str_contains($path, '/ocs/')
				|| $this->request->getMethod() !== 'GET';
			if ($isApi) {
				return $this->envelope(404, 'NOT_FOUND', 'Not found.');
			}
			$r = new TemplateResponse('core', '404', [], 'guest');
			$r->setStatus(Http::STATUS_NOT_FOUND);
			return $r;
		}
		if ($exception instanceof UnauthorizedException) {
			$path = ($this->request->getPathInfo() ?: '');
			// a dead/expired portal session on a PAGE request is a human
			// navigation — land them on the request form, not a JSON blob
			if ($this->request->getMethod() === 'GET'
				&& str_contains($path, '/pub/portal')) {
				return new \OCP\AppFramework\Http\RedirectResponse(
					\OCP\Server::get(\OCP\IURLGenerator::class)
						->linkToRoute('bookingcheck.portal.request'));
			}
			// Denied page navigation inside the app shell → the friendly
			// needs-role page; a JSON 401 blob on a browser visit is a dead
			// end (AppAccessMiddleware's own docblock promises the page).
			if ($this->request->getMethod() === 'GET'
				&& !str_contains($path, '/api/')
				&& !str_contains($path, '/capi/')) {
				return new \OCP\AppFramework\Http\RedirectResponse(
					\OCP\Server::get(\OCP\IURLGenerator::class)
						->linkToRoute('bookingcheck.page.needsRole'));
			}
			return $this->envelope(401, 'UNAUTHORIZED', 'Unauthorized.');
		}
		if ($exception instanceof RateLimitException) {
			return $this->envelope(429, 'RATE_LIMITED', 'Too many requests — please try again shortly.');
		}
		if ($exception instanceof ProRequiredException) {
			$path = ($this->request->getPathInfo() ?: '');
			if (str_contains($path, '/api/') || str_contains($path, '/capi/')) {
				return $this->envelope(403, 'PRO_REQUIRED', $exception->getMessage());
			}
			if (str_contains($path, '/pub/')) {
				// buyers never see seller upsells — a lapsed license reads
				// as a normal "unavailable" page on public surfaces
				return \OCA\BookingCheck\Support\PublicNotFound::render();
			}
			// admin page navigations → the upsell page, not a JSON blob
			return new \OCP\AppFramework\Http\RedirectResponse(
				\OCP\Server::get(\OCP\IURLGenerator::class)
					->linkToRoute('bookingcheck.page.pro'));
		}
		if ($exception instanceof \OCA\BookingCheck\Exception\PspException) {
			return $this->envelope(502, 'PAYMENT_UNAVAILABLE', 'Payment is temporarily unavailable.');
		}
		// Unknown exceptions on API surfaces must still answer the JSON
		// envelope — an escaped framework HTML 500 leaves fetch clients
		// parsing markup (api_500_html_envelope). Pages re-throw so the
		// core error page stays the human UX.
		$path = (string)$this->request->getPathInfo();
		if (str_contains($path, '/api/') || str_contains($path, '/capi/')
			|| str_contains($path, '/pub/api')) {
			$this->logger->error('Unhandled exception on bookingcheck API route', [
				'app' => Application::APP_ID,
				'exception' => $exception,
			]);
			return $this->envelope(500, 'INTERNAL', 'Internal error.');
		}
		throw $exception; // unknown page error → framework 500 (logged upstream)
	}

	/** @param array<string,string> $fields */
	private function envelope(int $status, string $code, string $message, array $fields = []): JSONResponse
	{
		$resp = new JSONResponse([
			'success' => false,
			'error' => $code,
			'message' => $message,
			'fields' => (object)$fields,
		], $status);
		// NH-05 — the SDK embed must be able to READ error envelopes on
		// the order endpoint (validation fields, 409, 429). Same origin
		// gate as the success path; other routes stay uncorsed.
		$path = ($this->request->getPathInfo() ?: '');
		if (str_ends_with($path, '/pub/api/orders')) {
			$oid = $this->request->getParam('offering_id');
			if (is_numeric($oid) && (string)(int)$oid === (string)$oid) {
				return EmbedCors::apply($resp, $this->request,
					$this->offerings->find((int)$oid)?->getEmbedDomains());
			}
		}
		return $resp;
	}
}
