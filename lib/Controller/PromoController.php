<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\Promo;
use OCA\BookingCheck\Db\PromoMapper;
use OCA\BookingCheck\Exception\NotFoundException;
use OCA\BookingCheck\Exception\ValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

class PromoController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PromoMapper $items,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		return new TemplateResponse($this->appName, 'admin/promos', [
			'navId' => 'promos',
			'promos' => array_map(fn ($p) => $p->jsonSerialize(), $this->items->findAll()),
		]);
	}

	#[NoAdminRequired]
	public function store(): JSONResponse
	{
		$code = strtoupper(trim((string)$this->request->getParam('code', '')));
		$kind = (string)$this->request->getParam('kind', '');
		if (!preg_match('/^[A-Z0-9_-]{3,32}$/', $code)) {
			throw new ValidationException(['code' => '3–32 chars, A–Z 0–9.']);
		}
		if (!in_array($kind, ['percent', 'fixed'], true)) {
			throw new ValidationException(['kind' => 'Pick a discount type.']);
		}
		// the unique index still guards the race — this turns it into a
		// field error instead of a bare 500
		if ($this->items->findByCode($code) !== null) {
			throw new ValidationException(['code' => 'That code is already taken.']);
		}
		$p = new Promo();
		$p->setCode($code);
		$p->setKind($kind);
		if ($kind === 'percent') {
			$bps = $this->strictId($this->request->getParam('percent_bps'));
			if ($bps === null || $bps <= 0 || $bps > 10000) {
				throw new ValidationException(['percent_bps' => '0–100%.']);
			}
			$p->setPercentBps($bps);
		} else {
			$cents = $this->strictId($this->request->getParam('amount_cents'));
			if ($cents === null || $cents <= 0) {
				throw new ValidationException(['amount_cents' => 'Enter an amount.']);
			}
			// fixed discounts are denominated — a €10 code must not
			// silently subtract 10 units of a foreign currency
			$currency = strtoupper(trim((string)$this->request->getParam('currency', 'EUR')));
			if (!preg_match('/^[A-Z]{3}$/', $currency)) {
				throw new ValidationException(['currency' => 'Invalid currency.']);
			}
			$p->setCurrency($currency);
			$p->setAmountCents($cents);
		}
		$p->setScope('all');
		$p->setActive(1);
		$this->items->insert($p);
		return $this->ok(['id' => (int)$p->getId()], 201);
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		$row = $this->items->find($id);
		if ($row === null) {
			throw new NotFoundException();
		}
		$row->setActive(0);
		$this->items->update($row);
		return $this->ok(['deleted' => true]);
	}
}
