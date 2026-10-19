<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Db\PriceCat;
use OCA\BookingCheck\Db\PriceCatMapper;
use OCA\BookingCheck\Exception\NotFoundException;
use OCA\BookingCheck\Exception\ValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class PricecatController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PriceCatMapper $items,
		private readonly OfferingMapper $offerings,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(int $id): JSONResponse
	{
		if ($this->offerings->find($id) === null) {
			throw new NotFoundException();
		}
		return $this->ok(['pricecats' => array_map(
			fn ($p) => $p->jsonSerialize(), $this->items->findByOffering($id))]);
	}

	#[NoAdminRequired]
	public function store(int $id): JSONResponse
	{
		if ($this->offerings->find($id) === null) {
			throw new NotFoundException();
		}
		$name = trim((string)$this->request->getParam('name', ''));
		$cents = $this->strictId($this->request->getParam('price_cents'));
		if ($name === '' || mb_strlen($name) > 190) {
			throw new ValidationException(['name' => 'Name required.']);
		}
		if ($cents === null || $cents < 0 || $cents > 99999999) {
			throw new ValidationException(['price_cents' => 'Price required.']);
		}
		$c = new PriceCat();
		$c->setOfferingId($id);
		$c->setName($name);
		$c->setPriceCents($cents);
		$c->setMaxPerOrder($this->strictId($this->request->getParam('max_per_order')));
		$c->setActive(1);
		$this->items->insert($c);
		return $this->ok(['id' => (int)$c->getId()], 201);
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
