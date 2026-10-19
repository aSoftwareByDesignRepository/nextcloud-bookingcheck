<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\Event;
use OCA\BookingCheck\Db\EventMapper;
use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Db\Slot;
use OCA\BookingCheck\Db\SlotMapper;
use OCA\BookingCheck\Db\SlotTpl;
use OCA\BookingCheck\Db\SlotTplMapper;
use OCA\BookingCheck\Exception\NotFoundException;
use OCA\BookingCheck\Exception\ValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/** Event occurrences + timeslot materialization. */
class SlotController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OfferingMapper $offerings,
		private readonly EventMapper $events,
		private readonly SlotTplMapper $tpls,
		private readonly SlotMapper $slots,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function list(int $id): JSONResponse
	{
		$o = $this->mustOffering($id);
		if ($o->getType() === 'timeslot') {
			return $this->ok(['slots' => array_map(
				fn ($s) => $s->jsonSerialize(), $this->slots->findUpcoming($id, 500))]);
		}
		return $this->ok(['events' => array_map(
			fn ($e) => $e->jsonSerialize(), $this->events->findByOffering($id))]);
	}

	/** Create a single occurrence (event) or slot. */
	#[NoAdminRequired]
	public function store(int $id): JSONResponse
	{
		$o = $this->mustOffering($id);
		$start = $this->parseDt('starts_at');
		$end = $this->parseDt('ends_at');
		$cap = $this->strictId($this->request->getParam('capacity'));
		if ($cap === null || $cap < 1 || $cap > 100000) {
			throw new ValidationException(['capacity' => 'Capacity must be 1 or more.']);
		}
		if ($end <= $start) {
			throw new ValidationException(['ends_at' => 'End must be after start.']);
		}
		if ($o->getType() === 'timeslot') {
			$tplId = $this->strictId($this->request->getParam('tpl_id'));
			$tpl = $tplId !== null ? $this->tpls->find($tplId) : null;
			if ($tpl === null || (int)$tpl->getOfferingId() !== $id) {
				throw new ValidationException(['tpl_id' => 'Unknown template.']);
			}
			$s = new Slot();
			$s->setTplId($tplId);
			$s->setOfferingId($id);
			$s->setStartsAt($start);
			$s->setEndsAt($end);
			$s->setCapacity($cap);
			$this->slots->insert($s);
			return $this->ok(['id' => (int)$s->getId()], 201);
		}
		$e = new Event();
		$e->setOfferingId($id);
		$e->setStartsAt($start);
		$e->setEndsAt($end);
		$e->setCapacity($cap);
		$e->setStatus('scheduled');
		$this->events->insert($e);
		return $this->ok(['id' => (int)$e->getId()], 201);
	}

	/** Series: create N weekly occurrences in one call. */
	#[NoAdminRequired]
	public function series(int $id): JSONResponse
	{
		$o = $this->mustOffering($id);
		$start = $this->parseDt('starts_at');
		$end = $this->parseDt('ends_at');
		$cap = $this->strictId($this->request->getParam('capacity'));
		$count = $this->strictId($this->request->getParam('weeks')) ?? 1;
		if ($cap === null || $cap < 1) {
			throw new ValidationException(['capacity' => 'Capacity required.']);
		}
		if ($count < 1 || $count > 52) {
			throw new ValidationException(['weeks' => '1–52 weeks.']);
		}
		$series = new \OCA\BookingCheck\Db\Series();
		$series->setOfferingId($id);
		$series->setCapacity($cap);
		$series->setTitle((string)$this->request->getParam('title', ''));
		\OCP\Server::get(\OCA\BookingCheck\Db\SeriesMapper::class)->insert($series);
		$created = [];
		for ($i = 0; $i < $count; $i++) {
			$e = new Event();
			$e->setOfferingId($id);
			$e->setSeriesId((int)$series->getId());
			$e->setStartsAt($start->modify('+' . ($i * 7) . ' days'));
			$e->setEndsAt($end->modify('+' . ($i * 7) . ' days'));
			$e->setCapacity($cap);
			$e->setStatus('scheduled');
			$this->events->insert($e);
			$created[] = (int)$e->getId();
		}
		return $this->ok(['series_id' => (int)$series->getId(), 'events' => $created], 201);
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		// routes call /api/slots/{id} — the id is a slot OR event; try both
		$s = $this->slots->find($id);
		if ($s !== null) {
			$this->slots->delete($s);
			return $this->ok(['deleted' => true]);
		}
		$e = $this->events->find($id);
		if ($e !== null) {
			if ((int)$e->getSold() > 0 || (int)$e->getHeld() > 0) {
				$e->setStatus('cancelled'); // booked occurrences cancel, never delete
				$this->events->update($e);
			} else {
				$this->events->delete($e);
			}
			return $this->ok(['deleted' => true]);
		}
		throw new NotFoundException();
	}

	private function mustOffering(int $id): \OCA\BookingCheck\Db\Offering
	{
		$o = $this->offerings->find($id);
		if ($o === null) {
			throw new NotFoundException();
		}
		return $o;
	}

	private function parseDt(string $key): \DateTimeImmutable
	{
		$raw = (string)$this->request->getParam($key, '');
		try {
			return new \DateTimeImmutable($raw);
		} catch (\Throwable) {
			throw new ValidationException([$key => 'Invalid date/time.']);
		}
	}
}
