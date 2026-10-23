<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller {
	/** Stub — anonymous classes lose their namespace in get_class(),
	 *  and the middleware gates on the OCA\BookingCheck prefix. */
	class PublicErrorStubController extends \OCP\AppFramework\Controller
	{
	}
}

namespace OCA\BookingCheck\Tests\Unit\Middleware {

use OCA\BookingCheck\Db\Offering;
use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Exception\ValidationException;
use OCA\BookingCheck\Middleware\PublicErrorMiddleware;
use OCP\AppFramework\Controller;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * NH-05 — error envelopes on /pub/api/orders must carry the same
 * embed-domain ACAO as success envelopes, or the SDK cannot read
 * validation fields, 409 conflicts, or 429 throttles cross-origin.
 */
final class PublicErrorMiddlewareTest extends TestCase
{
	private IRequest&MockObject $request;
	private OfferingMapper&MockObject $offerings;
	private string $path = '/apps/bookingcheck/pub/api/orders';
	private string $origin = '';
	private mixed $offeringId = '7';
	private ?string $locale = null;

	protected function setUp(): void
	{
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getPathInfo')->willReturnCallback(fn () => $this->path);
		$this->request->method('getHeader')->willReturnCallback(
			fn (string $n): string => $n === 'Origin' ? $this->origin : '');
		$this->request->method('getParam')->willReturnCallback(
			fn (string $k) => match ($k) {
				'offering_id' => $this->offeringId,
				'locale' => $this->locale,
				default => null,
			});
		$this->offerings = $this->createMock(OfferingMapper::class);
		$o = new Offering();
		$o->setEmbedDomains('["https://shop.example"]');
		$this->offerings->method('find')->willReturn($o);
	}

	private function mw(): PublicErrorMiddleware
	{
		return new PublicErrorMiddleware($this->request, $this->offerings,
			$this->createMock(LoggerInterface::class));
	}

	private function headersOf(\OCP\AppFramework\Http\JSONResponse $r): array
	{
		$m = new \ReflectionMethod($r, 'getHeaders');
		return $m->invoke($r);
	}

	private function controller(): Controller
	{
		return new \OCA\BookingCheck\Controller\PublicErrorStubController(
			'bookingcheck', $this->createMock(IRequest::class));
	}

	public function testValidationEnvelopeGetsAcaoOnOrderEndpoint(): void
	{
		$this->origin = 'https://shop.example';
		$resp = $this->mw()->afterException($this->controller(), 'create',
			new ValidationException(['buyer_email' => 'bad']));
		self::assertSame(400, $resp->getStatus());
		$h = $this->headersOf($resp);
		self::assertSame('https://shop.example', $h['Access-Control-Allow-Origin'] ?? null,
			'SDK must be able to read field-level errors cross-origin');
	}

	public function testForeignOriginGetsNoAcaoOnErrors(): void
	{
		$this->origin = 'https://evil.example';
		$resp = $this->mw()->afterException($this->controller(), 'create',
			new ValidationException(['x' => 'y']));
		self::assertArrayNotHasKey('Access-Control-Allow-Origin', $this->headersOf($resp));
	}

	public function testOtherPathsNeverGetAcao(): void
	{
		$this->path = '/apps/bookingcheck/api/orders/7/cancel';
		$this->origin = 'https://shop.example';
		$resp = $this->mw()->afterException($this->controller(), 'cancel',
			new ValidationException(['x' => 'y']));
		self::assertArrayNotHasKey('Access-Control-Allow-Origin', $this->headersOf($resp),
			'admin JSON must not learn or reflect embed origins');
	}

	public function testMalformedOfferingIdNeverReflects(): void
	{
		$this->offeringId = '2.9';
		$this->origin = 'https://shop.example';
		$resp = $this->mw()->afterException($this->controller(), 'create',
			new ValidationException(['x' => 'y']));
		self::assertArrayNotHasKey('Access-Control-Allow-Origin', $this->headersOf($resp));
	}

	/** Denied page navigations land on needs-role, not a JSON blob
	 *  (AppAccessMiddleware's docblock promises the friendly page). */
	public function testUnauthorizedPageGetRedirectsToNeedsRole(): void
	{
		$this->path = '/apps/bookingcheck/orders';
		$req = $this->createMock(IRequest::class);
		$req->method('getPathInfo')->willReturn($this->path);
		$req->method('getMethod')->willReturn('GET');
		$req->method('getHeader')->willReturn('');
		$resp = (new PublicErrorMiddleware($req, $this->offerings,
			$this->createMock(LoggerInterface::class)))
			->afterException($this->controller(), 'index',
				new \OCA\BookingCheck\Exception\UnauthorizedException());
		self::assertInstanceOf(\OCP\AppFramework\Http\RedirectResponse::class, $resp);
		self::assertStringContainsString('needs-role', $resp->getRedirectURL());
	}

	/** API calls keep the uniform 401 envelope — a JSON client must not
	 *  be bounced into an HTML page it cannot render. */
	public function testUnauthorizedApiGetKeepsJson401(): void
	{
		$this->path = '/apps/bookingcheck/api/orders/9';
		$req = $this->createMock(IRequest::class);
		$req->method('getPathInfo')->willReturn($this->path);
		$req->method('getMethod')->willReturn('GET');
		$req->method('getHeader')->willReturn('');
		$resp = (new PublicErrorMiddleware($req, $this->offerings,
			$this->createMock(LoggerInterface::class)))
			->afterException($this->controller(), 'show',
				new \OCA\BookingCheck\Exception\UnauthorizedException());
		self::assertInstanceOf(\OCP\AppFramework\Http\JSONResponse::class, $resp);
		self::assertSame(401, $resp->getStatus());
		self::assertSame('UNAUTHORIZED', $resp->getData()['error']);
	}

	/** api_500_html_envelope — an unexpected exception on an API surface
	 *  must answer the JSON envelope (fetch clients cannot parse the
	 *  framework HTML 500). Message stays generic; internals go to the log. */
	public function testUnknownExceptionOnApiRouteReturnsJson500(): void
	{
		$this->path = '/apps/bookingcheck/api/orders/9';
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error');
		$req = $this->createMock(IRequest::class);
		$req->method('getPathInfo')->willReturn($this->path);
		$resp = (new PublicErrorMiddleware($req, $this->offerings, $logger))
			->afterException($this->controller(), 'show',
				new \RuntimeException('db gone sideways'));
		self::assertInstanceOf(\OCP\AppFramework\Http\JSONResponse::class, $resp);
		self::assertSame(500, $resp->getStatus());
		self::assertSame('INTERNAL', $resp->getData()['error']);
		self::assertStringNotContainsString('sideways',
			(string)$resp->getData()['message'], 'no internal detail leak');
	}

	public function testUnknownExceptionOnCapiRouteReturnsJson500(): void
	{
		$this->path = '/apps/bookingcheck/capi/v1/manifest';
		$req = $this->createMock(IRequest::class);
		$req->method('getPathInfo')->willReturn($this->path);
		$resp = (new PublicErrorMiddleware($req, $this->offerings,
			$this->createMock(LoggerInterface::class)))
			->afterException($this->controller(), 'manifest',
				new \RuntimeException('boom'));
		self::assertInstanceOf(\OCP\AppFramework\Http\JSONResponse::class, $resp);
		self::assertSame(500, $resp->getStatus());
		self::assertSame('INTERNAL', $resp->getData()['error']);
	}

	/** restricted_door_status_oracle — a door-denied page navigation must
	 *  answer the core 404 template, indistinguishable from an absent app;
	 *  a 403 or friendly deny page would leak "installed but excluded". */
	public function testDoorDeniedPageReturnsCore404(): void
	{
		$this->path = '/apps/bookingcheck/offerings';
		$req = $this->createMock(IRequest::class);
		$req->method('getPathInfo')->willReturn($this->path);
		$req->method('getMethod')->willReturn('GET');
		$resp = (new PublicErrorMiddleware($req, $this->offerings,
			$this->createMock(LoggerInterface::class)))
			->afterException($this->controller(), 'index',
				new \OCA\BookingCheck\Exception\AppDoorDeniedException());
		self::assertInstanceOf(\OCP\AppFramework\Http\TemplateResponse::class, $resp);
		self::assertSame(404, $resp->getStatus());
	}

	public function testDoorDeniedApiReturnsCanonical404(): void
	{
		$this->path = '/apps/bookingcheck/api/orders';
		$req = $this->createMock(IRequest::class);
		$req->method('getPathInfo')->willReturn($this->path);
		$req->method('getMethod')->willReturn('GET');
		$resp = (new PublicErrorMiddleware($req, $this->offerings,
			$this->createMock(LoggerInterface::class)))
			->afterException($this->controller(), 'index',
				new \OCA\BookingCheck\Exception\AppDoorDeniedException());
		self::assertInstanceOf(\OCP\AppFramework\Http\JSONResponse::class, $resp);
		self::assertSame(404, $resp->getStatus());
		self::assertSame('NOT_FOUND', $resp->getData()['error']);
	}

	/** page_route_error_renders_json_blob — a staff page navigation that
	 *  misses (stale or foreign id in the URL) must answer the core HTML
	 *  404 page, not a JSON blob the browser shows as raw machine output.
	 *  Same template for missing and foreign entities — the uniform-404
	 *  collapse (no existence oracle) survives in HTML form. */
	public function testNotFoundPageGetReturnsCore404(): void
	{
		$this->path = '/apps/bookingcheck/orders/999999999';
		$req = $this->createMock(IRequest::class);
		$req->method('getPathInfo')->willReturn($this->path);
		$req->method('getMethod')->willReturn('GET');
		$resp = (new PublicErrorMiddleware($req, $this->offerings,
			$this->createMock(LoggerInterface::class)))
			->afterException($this->controller(), 'show',
				new \OCA\BookingCheck\Exception\NotFoundException());
		self::assertInstanceOf(\OCP\AppFramework\Http\TemplateResponse::class, $resp);
		self::assertSame(404, $resp->getStatus());
		self::assertSame('404', $resp->getTemplateName());
	}

	/** Buyer-facing page GETs (manage link, invoice, ticket, claim)
	 *  answer the branded "nothing here" card with a recovery CTA —
	 *  the same body the controllers already render inline. */
	public function testNotFoundPubGetReturnsBrandedPage(): void
	{
		$this->path = '/apps/bookingcheck/pub/m/deadbeef/invoice';
		$req = $this->createMock(IRequest::class);
		$req->method('getPathInfo')->willReturn($this->path);
		$req->method('getMethod')->willReturn('GET');
		$resp = (new PublicErrorMiddleware($req, $this->offerings,
			$this->createMock(LoggerInterface::class)))
			->afterException($this->controller(), 'invoice',
				new \OCA\BookingCheck\Exception\NotFoundException());
		self::assertInstanceOf(\OCP\AppFramework\Http\Template\PublicTemplateResponse::class, $resp);
		self::assertSame(404, $resp->getStatus());
		self::assertSame('public/not-found', $resp->getTemplateName());
	}

	/** JSON clients keep the canonical envelope — a fetch caller must
	 *  not be handed an HTML error page it cannot parse. */
	public function testNotFoundApiGetKeepsJsonEnvelope(): void
	{
		$this->path = '/apps/bookingcheck/api/orders/999999999';
		$req = $this->createMock(IRequest::class);
		$req->method('getPathInfo')->willReturn($this->path);
		$req->method('getMethod')->willReturn('GET');
		$resp = (new PublicErrorMiddleware($req, $this->offerings,
			$this->createMock(LoggerInterface::class)))
			->afterException($this->controller(), 'show',
				new \OCA\BookingCheck\Exception\NotFoundException());
		self::assertInstanceOf(\OCP\AppFramework\Http\JSONResponse::class, $resp);
		self::assertSame(404, $resp->getStatus());
		self::assertSame('NOT_FOUND', $resp->getData()['error']);
	}

	/** The public JSON API under /pub/api is still a JSON surface even
	 *  though it shares the /pub/ prefix with buyer-facing pages. */
	public function testNotFoundPubApiGetKeepsJsonEnvelope(): void
	{
		$this->path = '/apps/bookingcheck/pub/api/orders/ref-dead';
		$req = $this->createMock(IRequest::class);
		$req->method('getPathInfo')->willReturn($this->path);
		$req->method('getMethod')->willReturn('GET');
		$resp = (new PublicErrorMiddleware($req, $this->offerings,
			$this->createMock(LoggerInterface::class)))
			->afterException($this->controller(), 'status',
				new \OCA\BookingCheck\Exception\NotFoundException());
		self::assertInstanceOf(\OCP\AppFramework\Http\JSONResponse::class, $resp);
		self::assertSame(404, $resp->getStatus());
		self::assertSame('NOT_FOUND', $resp->getData()['error']);
	}

	/** Mutations are not browser navigations — a POST that misses stays
	 *  on the envelope even on a non-/api/ route (e.g. manage actions). */
	public function testNotFoundPostOnPagePathKeepsJsonEnvelope(): void
	{
		$this->path = '/apps/bookingcheck/pub/m/deadbeef/cancel';
		$req = $this->createMock(IRequest::class);
		$req->method('getPathInfo')->willReturn($this->path);
		$req->method('getMethod')->willReturn('POST');
		$resp = (new PublicErrorMiddleware($req, $this->offerings,
			$this->createMock(LoggerInterface::class)))
			->afterException($this->controller(), 'cancel',
				new \OCA\BookingCheck\Exception\NotFoundException());
		self::assertInstanceOf(\OCP\AppFramework\Http\JSONResponse::class, $resp);
		self::assertSame(404, $resp->getStatus());
		self::assertSame('NOT_FOUND', $resp->getData()['error']);
	}

	/** l10n_unlocalized_error_surface — a buyer request that carries the
	 *  embed's explicit locale param must get the envelope message AND
	 *  every field error in that locale, not raw English. */
	public function testPubApiErrorEnvelopeHonoursLocaleParam(): void
	{
		$this->locale = 'de';
		$resp = $this->mw()->afterException($this->controller(), 'create',
			new ValidationException(['buyer_email' => 'Enter a valid email address.']));
		$data = $resp->getData();
		self::assertSame('VALIDATION', $data['error']);
		self::assertSame('Bitte prüfen Sie die markierten Felder.', $data['message'],
			'envelope message must serialize in the request locale');
		self::assertSame('Geben Sie eine gültige E-Mail-Adresse ein.',
			$data['fields']->buyer_email,
			'field-level errors render inline — they must be localized too');
	}

	/** Without a locale param the ambient language applies — the
	 *  session user's language, else the instance default. Whatever it
	 *  resolves to, the envelope must match that catalog, never a
	 *  hard-coded English fallback. */
	public function testPubApiErrorEnvelopeWithoutLocaleUsesAmbientLanguage(): void
	{
		$lang = \OCP\Server::get(\OCP\L10N\IFactory::class)
			->get('bookingcheck')->getLanguageCode();
		$cat = json_decode((string)file_get_contents(
			dirname(__DIR__, 3) . '/l10n/' . $lang . '.json'), true)['translations'];
		$resp = $this->mw()->afterException($this->controller(), 'create',
			new ValidationException(['buyer_email' => 'Enter a valid email address.']));
		$data = $resp->getData();
		self::assertSame($cat['Please check the highlighted fields.'], $data['message']);
		self::assertSame($cat['Enter a valid email address.'], $data['fields']->buyer_email);
	}

	/** The defect's flagship case: PRO_REQUIRED's static exception text
	 *  is a cataloged msgid — a German requester must never see
	 *  "This feature requires BookingCheck Pro." verbatim. */
	public function testProRequiredMessageTranslatesViaErrorText(): void
	{
		$l = \OCP\Server::get(\OCP\L10N\IFactory::class)->get('bookingcheck', 'de');
		self::assertSame('Diese Funktion erfordert BookingCheck Pro.',
			\OCA\BookingCheck\Support\ErrorText::t(
				$l, (new \OCA\BookingCheck\Exception\ProRequiredException())->getMessage()));
	}

	/** l10n_sprintf_literal_percent_crash — L10NString::__toString()
	 *  vsprintf()s unconditionally, so dynamic text with a literal '%'
	 *  must never enter the l10n pipeline. */
	public function testLiteralPercentPassesThroughUntouched(): void
	{
		$l = \OCP\Server::get(\OCP\L10N\IFactory::class)->get('bookingcheck', 'de');
		self::assertSame('Capacity at 50% of pool',
			\OCA\BookingCheck\Support\ErrorText::t($l, 'Capacity at 50% of pool'));
	}

	/** Unknown/dynamic text that is not a cataloged msgid passes through
	 *  unchanged — the helper never mangles or drops it. */
	public function testUnknownMsgidPassesThrough(): void
	{
		$l = \OCP\Server::get(\OCP\L10N\IFactory::class)->get('bookingcheck', 'de');
		self::assertSame('something never cataloged',
			\OCA\BookingCheck\Support\ErrorText::t($l, 'something never cataloged'));
	}

	/** Page surfaces keep the framework 500 — HTML error page is the
	 *  correct human UX and the exception is logged upstream. */
	public function testUnknownExceptionOnPageRethrows(): void
	{
		$this->path = '/apps/bookingcheck/orders';
		$req = $this->createMock(IRequest::class);
		$req->method('getPathInfo')->willReturn($this->path);
		$this->expectException(\RuntimeException::class);
		(new PublicErrorMiddleware($req, $this->offerings,
			$this->createMock(LoggerInterface::class)))
			->afterException($this->controller(), 'index',
				new \RuntimeException('boom'));
	}
}
}
