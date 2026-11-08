<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\Addon;
use OCA\BookingCheck\Db\AddonMapper;
use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Exception\NotFoundException;
use OCA\BookingCheck\Exception\ValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class AddonController extends Controller
{
	// unique-conflict:ok — Addon rows carry no unique index; the bc_offering blame is an unattributed mapper write.
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AddonMapper $items,
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
		return $this->ok(['addons' => array_map(
			fn ($a) => $a->jsonSerialize(), $this->items->findByOffering($id))]);
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
		$a = new Addon();
		$a->setOfferingId($id);
		$a->setName($name);
		$a->setPriceCents($cents);
		$a->setMaxPerOrder($this->strictId($this->request->getParam('max_per_order')));
		$a->setActive(1);
		$this->items->insert($a);
		return $this->ok(['id' => (int)$a->getId()], 201);
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
