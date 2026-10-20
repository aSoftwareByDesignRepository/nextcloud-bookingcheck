<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Db\OrderMapper;
use OCA\BookingCheck\Exception\NotFoundException;
use OCA\BookingCheck\Exception\PspException;
use OCA\BookingCheck\Exception\StateConflictException;
use OCA\BookingCheck\Exception\ValidationException;
use OCA\BookingCheck\Service\CatalogService;
use OCA\BookingCheck\Service\OrderService;
use OCA\BookingCheck\Service\PaySessionService;
use OCA\BookingCheck\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Public guest checkout.
 *
 * Truth rules: the buyer-facing return URL is NEVER trusted — status
 * comes only from the order row, which only a verified webhook can move
 * to paid (§9.3). The create endpoint is idempotent on `idem_token`.
 */
class PubCheckoutController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OrderService $orders,
		private readonly OrderMapper $orderMapper,
		private readonly OfferingMapper $offerings,
		private readonly CatalogService $catalog,
		private readonly PaySessionService $paySessions,
		private readonly SettingsService $settings,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
		private readonly \OCA\BookingCheck\Service\ViesService $vies,
		private readonly \OCA\BookingCheck\Service\LicenseService $license,
		private readonly \OCA\BookingCheck\Service\PricingService $pricing,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function start(string $slug): PublicTemplateResponse
	{
		$o = $this->offerings->findBySlug($slug);
		if ($o === null || $o->getStatus() !== 'published') {
			return new PublicTemplateResponse($this->appName, 'public/not-found', []);
		}
		$isPass = $o->getType() === 'pass';
		// Curated whitelist — jsonSerialize() leaks internal refs
		// (talkRoom token, deckBoardId) to anonymous checkout visitors.
		return new PublicTemplateResponse($this->appName, 'public/checkout', [
			'offering' => [
				'id' => (int)$o->getId(),
				'title' => $o->getTitle(),
				'priceCents' => (int)$o->getPriceCents(),
				'currency' => $o->getCurrency(),
				'tosText' => $o->getTosText(),
			],
			// a pass sells memberships — no occurrence picker at all
			'occurrences' => $isPass ? [] : $this->catalog->occurrenceList($o),
			'priceCats' => $isPass ? [] : $this->catalog->priceCatsFor($o),
			'addons' => $isPass ? [] : $this->catalog->addonsFor($o),
			'approvalRequired' => !$isPass && (int)$o->getApprovalRequired() === 1,
			'isPass' => $isPass,
			'invoicePay' => (int)$o->getInvoicePay() === 1,
			'depositBp' => (int)$o->getDepositBp(),
		]);
	}

	/**
	 * POST /pub/api/orders — the checkout create.
	 *
	 * Body: {offering_id, buyer_name, buyer_email, locale,
	 *        lines:[{target_type,target_id,seats,price_cat_id?,resource_id?}],
	 *        addons:[{addon_id,qty}], promo?, voucher?, idem_token,
	 *        billing?{company,country,vat_id}, honeypot?}
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(): JSONResponse
	{
		$in = $this->request->getParams();

		// honeypot: bots fill hidden fields; humans never do. Silent 200.
		if (($in['hp_website'] ?? '') !== '') {
			return $this->ok(['action' => 'done', 'order_ref' => 'BCK-XXXX-XXXXXX']);
		}

		$offeringId = $this->strictId($in['offering_id'] ?? null);
		if ($offeringId === null) {
			throw new ValidationException(['offering_id' => 'Missing offering.']);
		}

		$lines = $this->parseLines($in['lines'] ?? []);
		$addons = $this->parseAddons($in['addons'] ?? []);
		$billing = $this->parseBilling($in);
		$idem = (string)($in['idem_token'] ?? '');

		// is_test can ONLY come from an admin session — public buyers can
		// never mint test orders (structural sandbox isolation, §9.13).
		$isTest = false;

		// consent is a checkbox — arrive as '1'/'true' or absent (api-security §4)
		$consented = in_array((string)($in['consent'] ?? ''), ['1', 'true'], true);
		$payMethod = in_array($in['pay_method'] ?? null, ['psp', 'invoice'], true)
			? $in['pay_method'] : null;
		$passQty = isset($in['pass_qty']) && preg_match('/^\d+$/', (string)$in['pass_qty'])
			? (int)$in['pass_qty'] : null;

		try {
			// NH-28: a qty on a pass offering routes to the pass flow —
			// the service re-checks the offering type, never the client.
			$result = $passQty !== null
				? $this->orders->createPassOrder(
					$offeringId,
					(string)($in['buyer_name'] ?? ''),
					(string)($in['buyer_email'] ?? ''),
					(string)($in['locale'] ?? 'en'),
					$passQty,
					$this->strOrNull($in['promo'] ?? null),
					$this->strOrNull($in['voucher'] ?? null),
					$idem,
					$billing,
					'guest',
					$consented,
					$payMethod,
				)
				: $this->orders->createOrder(
				$offeringId,
				(string)($in['buyer_name'] ?? ''),
				(string)($in['buyer_email'] ?? ''),
				(string)($in['locale'] ?? 'en'),
				$lines,
				$addons,
				$this->strOrNull($in['promo'] ?? null),
				$this->strOrNull($in['voucher'] ?? null),
				$idem,
				$billing,
				$isTest,
				'guest',
				$consented,
				$payMethod,
				);
		} catch (StateConflictException $e) {
			return $this->fail(409, $e->getCodeName(), $e->getMessage());
		}

		$order = $result['order'];
		$base = ['order_ref' => $order->getRef(), 'status' => $order->getStatus()];

		if ($result['action'] === 'approve') {
			return $this->ok($base + ['action' => 'approval',
				'manage_url' => $this->manageUrl($result['manageToken'])], 201);
		}
		if ($result['action'] === 'done') {
			return $this->ok($base + ['action' => 'done',
				'manage_url' => $this->manageUrl($result['manageToken'])], 201);
		}
		if ($result['action'] === 'idempotent-replay') {
			// same idem token ⇒ return the standing state, no redirect token
			return $this->ok($base + ['action' => 'replay']);
		}

		// action=pay: create the PSP session OUTSIDE the db txn
		try {
			$session = $this->paySessions->createForOrder($order);
		} catch (PspException $e) {
			// PSP unreachable → release the holds so seats aren't stranded
			$this->logger->warning('checkout PSP session failed', ['order' => $order->getRef(), 'err' => $e->getMessage()]);
			$this->orders->cancelPending((int)$order->getId(), 'psp');
			return $this->fail(502, 'PAYMENT_UNAVAILABLE', 'Payment is temporarily unavailable — please try again.');
		}

		return $this->ok($base + [
			'action' => 'pay',
			'checkout_url' => $session->checkoutUrl,
		], 201);
	}

	/**
	 * NH-36 — cart checkout: `cart` = list of {offering_id, lines, addons}
	 * (max 10 groups, unique offerings). One order, one payment.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function createCart(): JSONResponse
	{
		$in = $this->request->getParams();
		if (($in['hp_website'] ?? '') !== '') {
			return $this->ok(['action' => 'done', 'order_ref' => 'BCK-XXXX-XXXXXX']);
		}
		$rawCart = $in['cart'] ?? null;
		if (!is_array($rawCart) || count($rawCart) < 1 || count($rawCart) > 10) {
			throw new ValidationException(['cart' => 'Cart must contain between 1 and 10 offerings.']);
		}
		$groups = [];
		foreach (array_values($rawCart) as $i => $g) {
			if (!is_array($g)) {
				throw new ValidationException(["cart.$i" => 'Invalid cart group.']);
			}
			$oid = $this->strictId($g['offering_id'] ?? null);
			if ($oid === null) {
				throw new ValidationException(["cart.$i.offering_id" => 'Missing offering.']);
			}
			$groups[] = [
				'offering_id' => $oid,
				'lines' => $this->parseLines($g['lines'] ?? []),
				'addons' => $this->parseAddons($g['addons'] ?? []),
			];
		}
		$promos = [];
		if (is_array($in['promos'] ?? null)) {
			foreach ($in['promos'] as $oid => $code) {
				if (preg_match('/^\d+$/', (string)$oid) && is_string($code)) {
					$promos[(int)$oid] = $code;
				}
			}
		}
		$consented = in_array((string)($in['consent'] ?? ''), ['1', 'true'], true);
		$payMethod = in_array($in['pay_method'] ?? null, ['psp', 'invoice'], true)
			? $in['pay_method'] : null;
		try {
			$result = $this->orders->createCartOrder(
				$groups,
				(string)($in['buyer_name'] ?? ''),
				(string)($in['buyer_email'] ?? ''),
				(string)($in['locale'] ?? 'en'),
				$promos,
				$this->strOrNull($in['voucher'] ?? null),
				(string)($in['idem_token'] ?? ''),
				$this->parseBilling($in),
				'guest',
				$consented,
				$payMethod,
			);
		} catch (StateConflictException $e) {
			return $this->fail(409, $e->getCodeName(), $e->getMessage());
		}
		$order = $result['order'];
		$base = ['order_ref' => $order->getRef(), 'status' => $order->getStatus()];
		if ($result['action'] === 'approve') {
			return $this->ok($base + ['action' => 'approval',
				'manage_url' => $this->manageUrl($result['manageToken'])], 201);
		}
		if ($result['action'] === 'done') {
			return $this->ok($base + ['action' => 'done',
				'manage_url' => $this->manageUrl($result['manageToken'])], 201);
		}
		if ($result['action'] === 'idempotent-replay') {
			return $this->ok($base + ['action' => 'replay']);
		}
		try {
			$session = $this->paySessions->createForOrder($order);
		} catch (PspException $e) {
			$this->logger->warning('cart PSP session failed', ['order' => $order->getRef(), 'err' => $e->getMessage()]);
			$this->orders->cancelPending((int)$order->getId(), 'psp');
			return $this->fail(502, 'PAYMENT_UNAVAILABLE', 'Payment is temporarily unavailable — please try again.');
		}
		return $this->ok($base + [
			'action' => 'pay',
			'checkout_url' => $session->checkoutUrl,
		], 201);
	}

	/** Poll endpoint: truth = order row, never the redirect. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function status(string $publicRef): JSONResponse
	{
		$order = $this->orderMapper->findByRef($publicRef);
		if ($order === null) {
			throw new NotFoundException();
		}
		return $this->ok([
			'order_ref' => $order->getRef(),
			'status' => $order->getStatus(),
			'paid' => $order->getStatus() === 'paid',
		]);
	}

	/** PSP return landing — display state comes from our row only. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function return(string $publicRef): PublicTemplateResponse
	{
		$order = $this->orderMapper->findByRef($publicRef);
		if ($order === null) {
			return new PublicTemplateResponse($this->appName, 'public/not-found', []);
		}
		return new PublicTemplateResponse($this->appName, 'public/return', [
			'order' => ['ref' => $order->getRef(), 'status' => $order->getStatus()],
		]);
	}

	/** Buyer aborts a pending checkout → holds released. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function cancel(string $publicRef): JSONResponse
	{
		$order = $this->orderMapper->findByRef($publicRef);
		if ($order === null) {
			throw new NotFoundException();
		}
		$this->orders->cancelPending((int)$order->getId(), 'public');
		return $this->ok(['status' => 'cancelled']);
	}

	/** Promo pre-validation for the price display (quote only). */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function promo(): JSONResponse
	{
		$code = trim((string)$this->request->getParam('code', ''));
		if ($code === '' || mb_strlen($code) > 32) {
			return $this->ok(['valid' => false]);
		}
		$promo = $this->pricing
			->usablePromo($code, $this->strictId($this->request->getParam('offering_id')));
		// existence is deliberately part of the response — promo codes are
		// published marketing material, not secrets. Still rate-limited.
		if ($promo === null) {
			return $this->ok(['valid' => false]);
		}
		return $this->ok([
			'valid' => true,
			'kind' => $promo->getKind(),
			'percent_bps' => $promo->getPercentBps() !== null ? (int)$promo->getPercentBps() : null,
		]);
	}

	private function manageUrl(string $token): ?string
	{
		if ($token === '') {
			return null;
		}
		return $this->urlGenerator->linkToRouteAbsolute('bookingcheck.manage.show', ['token' => $token]);
	}

	private function strOrNull(mixed $v): ?string
	{
		$v = is_string($v) ? trim($v) : null;
		return $v === '' || $v === null ? null : $v;
	}

	/** @return list<array{target_type:string,target_id:int,seats:int,price_cat_id:?int,resource_id:?int}> validated structural line picks (no prices!) */
	private function parseLines(mixed $raw): array
	{
		$out = [];
		$decoded = is_string($raw) ? json_decode($raw, true) : $raw;
		if (!is_array($decoded)) {
			throw new ValidationException(['lines' => 'Pick at least one date or seat.']);
		}
		foreach ($decoded as $i => $l) {
			if (!is_array($l)) {
				continue;
			}
			$tt = (string)($l['target_type'] ?? '');
			$tid = $this->strictId($l['target_id'] ?? null);
			$seats = $this->strictId($l['seats'] ?? null);
			if (!in_array($tt, ['event', 'series', 'slot'], true) || $tid === null || $seats === null) {
				throw new ValidationException(["lines.$i" => 'Invalid line.']);
			}
			$out[] = [
				'target_type' => $tt,
				'target_id' => $tid,
				'seats' => $seats,
				'price_cat_id' => $this->strictId($l['price_cat_id'] ?? null),
				'resource_id' => $this->strictId($l['resource_id'] ?? null),
			];
		}
		if ($out === []) {
			throw new ValidationException(['lines' => 'Pick at least one date or seat.']);
		}
		return $out;
	}

	/** @return list<array{addon_id:int,qty:int}> */
	private function parseAddons(mixed $raw): array
	{
		$decoded = is_string($raw) ? json_decode($raw, true) : $raw;
		if (!is_array($decoded)) {
			return [];
		}
		$out = [];
		foreach ($decoded as $a) {
			$id = $this->strictId($a['addon_id'] ?? null);
			$qty = $this->strictId($a['qty'] ?? null);
			if ($id !== null && $qty !== null) {
				$out[] = ['addon_id' => $id, 'qty' => $qty];
			}
		}
		return $out;
	}

	/** @param array<string,mixed> $in
	 * @return array{company:string|null,country:string|null,vat_id:string|null,vies_status:string,reverse_charge:bool}|null */
	private function parseBilling(array $in): ?array
	{
		$company = $this->strOrNull($in['buyer_company'] ?? null);
		$country = $this->strOrNull($in['billing_country'] ?? null);
		$vatId = $this->strOrNull($in['vat_id'] ?? null);
		if ($company === null && $country === null && $vatId === null) {
			return null;
		}
		if ($country !== null && !preg_match('/^[A-Z]{2}$/', $country)) {
			throw new ValidationException(['billing_country' => 'Invalid country code.']);
		}
		if ($vatId !== null) {
			if (!preg_match('/^[A-Z]{2}[A-Z0-9]{8,12}$/i', $vatId)) {
				throw new ValidationException(['vat_id' => 'Invalid VAT ID format.']);
			}
			if ($country === null) {
				throw new ValidationException(['billing_country' => 'Country required for a VAT ID.']);
			}
			$vatId = strtoupper($vatId);
			$country = strtoupper($country);
			if (substr($vatId, 0, 2) !== $country) {
				throw new ValidationException(['vat_id' => 'VAT ID must start with the billing country.']);
			}
		}
		$viesStatus = 'none';
		$reverseCharge = false;
		if ($vatId !== null && $this->license->hasFeature('b2b_vies')
			&& \OCA\BookingCheck\Service\ViesService::isEuCountry($country)
			&& $this->settings->get('seller_vat_id') !== ''
			&& !$this->settings->getBool('kleinunternehmer')
			&& $country !== $this->settings->get('seller_country')) {
			$viesStatus = $this->vies->verify($country, $vatId);
			if ($viesStatus === 'invalid') {
				throw new ValidationException(['vat_id' => 'This VAT ID could not be verified.']);
			}
			// only a live 'valid' verdict zeroes VAT — 'unchecked' bills normally
			$reverseCharge = $viesStatus === 'valid';
		}
		return [
			'company' => $company,
			'country' => $country,
			'vat_id' => $vatId,
			'vies_status' => $viesStatus,
			'reverse_charge' => $reverseCharge,
		];
	}
}
