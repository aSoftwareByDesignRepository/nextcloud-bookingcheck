<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\Voucher;
use OCA\BookingCheck\Db\VoucherMapper;
use OCA\BookingCheck\Exception\NotFoundException;
use OCA\BookingCheck\Exception\ValidationException;
use OCA\BookingCheck\Service\TokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

class VoucherController extends Controller
{
	// unique-conflict:ok — voucher codes mint server-side via tokens->voucherCode() (random); a collision is cryptographically impossible and a loud failure would be correct.
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly VoucherMapper $items,
		private readonly TokenService $tokens,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		return new TemplateResponse($this->appName, 'admin/vouchers', [
			'navId' => 'vouchers',
			'vouchers' => array_map(fn ($v) => $v->jsonSerialize(), $this->items->findAll()),
		]);
	}

	#[NoAdminRequired]
	public function store(): JSONResponse
	{
		$cents = $this->strictId($this->request->getParam('credit_cents'));
		$maxUses = $this->strictId($this->request->getParam('max_uses')) ?? 1;
		if ($cents === null || $cents <= 0 || $cents > 99999999) {
			throw new ValidationException(['credit_cents' => 'Enter a valid amount.']);
		}
		if ($maxUses < 1 || $maxUses > 100000) {
			throw new ValidationException(['max_uses' => 'At least one use.']);
		}
		// voucher credit is denominated — pricing rejects redemption in any
		// other currency (a €50 code must never silently cover $50)
		$currency = strtoupper(trim((string)$this->request->getParam('currency', 'EUR')));
		if (!preg_match('/^[A-Z]{3}$/', $currency)) {
			throw new ValidationException(['currency' => 'Invalid currency.']);
		}
		$v = new Voucher();
		$v->setCode($this->tokens->voucherCode());
		$v->setCurrency($currency);
		$v->setCreditCents($cents);
		$v->setRemainingCents($cents);
		$v->setMaxUses($maxUses);
		$v->setSource('manual');
		$v->setActive(1);
		$v->setCreatedAt(new \DateTimeImmutable());
		$this->items->insert($v);
		return $this->ok(['id' => (int)$v->getId(), 'code' => $v->getCode()], 201);
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		$row = $this->items->find($id);
		if ($row === null) {
			throw new NotFoundException();
		}
		$row->setActive(0); // deactivate, never delete — outstanding credit
		$this->items->update($row);
		return $this->ok(['deleted' => true]);
	}
}
