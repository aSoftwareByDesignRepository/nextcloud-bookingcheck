<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Db\Resource;
use OCA\BookingCheck\Db\ResourceMapper;
use OCA\BookingCheck\Exception\NotFoundException;
use OCA\BookingCheck\Exception\ValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * Resource pools (rooms, trainers, equipment) bound to slots via
 * bc_slot_res — the pool's own capacity is what buyers draw on.
 */
class ResourceController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ResourceMapper $items,
		private readonly OfferingMapper $offerings,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		$offerings = [];
		foreach ($this->offerings->findAll() as $o) {
			$offerings[(int)$o->getId()] = $o->getTitle();
		}
		return new TemplateResponse($this->appName, 'admin/resources', [
			'navId' => 'resources',
			'offerings' => $offerings,
			'resources' => array_map(
				fn (\OCA\BookingCheck\Db\Resource $r) => $r->jsonSerialize(),
				$this->items->findAll()),
		]);
	}

	#[NoAdminRequired]
	public function store(): JSONResponse
	{
		$offeringId = $this->strictId($this->request->getParam('offering_id'));
		$name = trim((string)$this->request->getParam('name', ''));
		$capacity = $this->strictId($this->request->getParam('capacity'));
		if ($offeringId === null || $this->offerings->find($offeringId) === null) {
			throw new NotFoundException();
		}
		if ($name === '' || mb_strlen($name) > 190) {
			throw new ValidationException(['name' => 'Name required.']);
		}
		if ($capacity === null || $capacity < 1 || $capacity > 100000) {
			throw new ValidationException(['capacity' => 'Capacity must be 1 or more.']);
		}
		$r = new Resource();
		$r->setOfferingId($offeringId);
		$r->setName($name);
		$r->setCapacity($capacity);
		$r->setActive(1);
		$this->items->insert($r);
		return $this->ok(['id' => (int)$r->getId()], 201);
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		$r = $this->items->find($id);
		if ($r === null) {
			throw new NotFoundException();
		}
		$name = trim((string)$this->request->getParam('name', (string)$r->getName()));
		if ($name === '' || mb_strlen($name) > 190) {
			throw new ValidationException(['name' => 'Name required.']);
		}
		$capacity = $this->strictId($this->request->getParam('capacity'));
		if ($capacity !== null && ($capacity < 1 || $capacity > 100000)) {
			throw new ValidationException(['capacity' => 'Capacity must be 1 or more.']);
		}
		$r->setName($name);
		if ($capacity !== null) {
			$r->setCapacity($capacity);
		}
		$this->items->update($r);
		return $this->ok(['id' => $id]);
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		$row = $this->items->find($id);
		if ($row === null) {
			throw new NotFoundException();
		}
		if ($this->items->hasUsage($id)) {
			// bound to slot pools or order lines — deactivate, never delete:
			// live counters and ticket/roster joins would dangle otherwise
			$row->setActive(0);
			$this->items->update($row);
			return $this->ok(['deleted' => true, 'deactivated' => true]);
		}
		$this->items->delete($row);
		return $this->ok(['deleted' => true]);
	}
}
