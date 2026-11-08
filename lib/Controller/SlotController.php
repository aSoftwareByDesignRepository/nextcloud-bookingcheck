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
	// unique-conflict:ok — the real unique edge (manual slot create/move onto an occupied tpl_id+starts_at) converts to a starts_at field error in the TX catches; the rest are unattributed mapper writes (events/series/tpl inserts with no user-keyed index, fresh-id slot_res links, deletes).
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OfferingMapper $offerings,
		private readonly EventMapper $events,
		private readonly SlotTplMapper $tpls,
		private readonly SlotMapper $slots,
		private readonly \OCA\BookingCheck\Db\SeriesMapper $series,
		private readonly \OCP\IDBConnection $db,
		private readonly \OCA\BookingCheck\Service\SlotMaterializer $materializer,
		private readonly \OCA\BookingCheck\Db\ResourceMapper $resources,
		private readonly \OCA\BookingCheck\Db\SlotResourceMapper $slotRes,
		private readonly \OCA\BookingCheck\Service\EmailService $email,
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
	/** Timeslot templates — the recurring pattern slots materialize from. */
	#[NoAdminRequired]
	public function templates(int $id): JSONResponse
	{
		$this->mustOffering($id);
		return $this->ok([
			'templates' => array_map(
				fn ($t) => $t->jsonSerialize(), $this->tpls->findByOffering($id)),
			// active resource pools for the template form's selector
			'resources' => array_map(
				fn ($r) => $r->jsonSerialize(),
				array_filter($this->resources->findByOffering($id),
					fn ($r) => (int)$r->getActive() === 1)),
		]);
	}

	#[NoAdminRequired]
	public function templateStore(int $id): JSONResponse
	{
		$o = $this->mustOffering($id);
		if ($o->getType() !== 'timeslot') {
			throw new ValidationException(['id' => 'Only timeslot offerings have templates.']);
		}
		$duration = $this->strictId($this->request->getParam('duration_min'));
		$cap = $this->strictId($this->request->getParam('cap_per_slot'));
		$horizon = $this->strictId($this->request->getParam('horizon_days')) ?? 60;
		$lead = $this->strictId($this->request->getParam('lead_min')) ?? 0;
		$buffer = $this->strictId($this->request->getParam('buffer_min')) ?? 0;
		if ($duration === null || $duration < 1 || $duration > 1440) {
			throw new ValidationException(['duration_min' => 'Duration 1–1440 minutes.']);
		}
		if ($cap === null || $cap < 1 || $cap > 100000) {
			throw new ValidationException(['cap_per_slot' => 'Seats per slot required.']);
		}
		$rrule = trim((string)$this->request->getParam('weekly_rrule', ''));
		try {
			\OCA\BookingCheck\Service\SlotMaterializer::parseRrule($rrule); // validate eagerly
		} catch (\InvalidArgumentException $e) {
			throw new ValidationException(['weekly_rrule' => $e->getMessage()]);
		}
		$tpl = new SlotTpl();
		$tpl->setOfferingId($id);
		$tpl->setDurationMin($duration);
		$tpl->setCapPerSlot($cap);
		$tpl->setBufferMin($buffer);
		$tpl->setLeadMin($lead);
		$tpl->setHorizonDays(min(365, $horizon));
		$tpl->setWeeklyRrule($rrule);
		// optional resource binding: every materialized slot gets a
		// bc_slot_res row so buyers pick the resource's capacity pool
		$tpl->setResourceId($this->resourceIdFor($id));
		$this->tpls->insert($tpl);
		// materialize immediately — the buyer should see slots right away
		$created = $this->materializer->materialize($tpl);
		return $this->ok(['id' => (int)$tpl->getId(), 'materialized' => $created], 201);
	}

	/**
	 * Remove a recurring template. Past or booked slots survive (their
	 * tpl_id is only materializer bookkeeping) — unbooked future slots
	 * and their resource-pool rows go with the template.
	 */
	#[NoAdminRequired]
	public function templateDestroy(int $id): JSONResponse
	{
		$tpl = $this->tpls->find($id);
		if ($tpl === null) {
			throw new NotFoundException();
		}
		$removed = 0;
		$this->db->beginTransaction();
		try {
			// "unbooked" must check BOTH counter homes: plain slots count on
			// bc_slot, resource-bound slots count on bc_slot_res — a slot row
			// at zero can still carry live pool bookings
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')->from('bc_slot')
				->where($qb->expr()->eq('tpl_id', $qb->createNamedParameter($id)))
				->andWhere($qb->expr()->eq('sold', $qb->createNamedParameter(0)))
				->andWhere($qb->expr()->eq('held', $qb->createNamedParameter(0)))
				->andWhere($qb->expr()->gt('starts_at', $qb->createNamedParameter(
					new \DateTimeImmutable(), 'datetime')));
			$slotIds = array_map('intval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
			if ($slotIds !== []) {
				// drop ids whose resource pool is booked/held
				$qb = $this->db->getQueryBuilder();
				$qb->select('slot_id')->from('bc_slot_res')
					->where($qb->expr()->in('slot_id', $qb->createNamedParameter(
						$slotIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
					->andWhere($qb->expr()->orX(
						$qb->expr()->gt('sold', $qb->createNamedParameter(0)),
						$qb->expr()->gt('held', $qb->createNamedParameter(0))));
				$booked = array_map('intval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
				$slotIds = array_values(array_diff($slotIds, $booked));
			}
			if ($slotIds !== []) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete('bc_slot_res')
					->where($qb->expr()->in('slot_id', $qb->createNamedParameter(
						$slotIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
				// waiting entries keyed on a deleted slot would never fire
				$qb = $this->db->getQueryBuilder();
				$qb->delete('bc_waitlist')
					->where($qb->expr()->eq('target_type', $qb->createNamedParameter('slot')))
					->andWhere($qb->expr()->in('target_id', $qb->createNamedParameter(
						$slotIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
				$qb = $this->db->getQueryBuilder();
				$removed = $qb->delete('bc_slot')
					->where($qb->expr()->in('id', $qb->createNamedParameter(
						$slotIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
			}
			$this->tpls->delete($tpl);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $this->ok(['deleted' => true, 'slots_removed' => $removed]);
	}

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
			$this->db->beginTransaction();
			try {
				$this->slots->insert($s);
				// optional per-slot resource pool — capacity lives on the
				// resource, not the slot row
				$resId = $this->resourceIdFor($id);
				if ($resId !== null) {
					$sr = new \OCA\BookingCheck\Db\SlotResource();
					$sr->setSlotId((int)$s->getId());
					$sr->setResourceId($resId);
					$sr->setCapacity($this->resourceCapacity($resId));
					$this->slotRes->insert($sr);
				}
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				// a manual slot onto an occupied (tpl_id, starts_at) is a
				// user error — surface it as a field error, not a 500
				if ($e instanceof \OCP\DB\Exception
					&& $e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw new ValidationException(['starts_at' => 'A slot already exists at that time.']);
				}
				throw $e;
			}
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
		$title = mb_substr(trim((string)$this->request->getParam('title', '')), 0, 190);
		// one transaction — a mid-loop failure must not leave a partial series
		$this->db->beginTransaction();
		try {
			$series = new \OCA\BookingCheck\Db\Series();
			$series->setOfferingId($id);
			$series->setCapacity($cap);
			$series->setTitle($title);
			$this->series->insert($series);
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
			$this->db->commit();
		} catch (\Throwable $t) {
			$this->db->rollBack();
			throw $t;
		}
		return $this->ok(['series_id' => (int)$series->getId(), 'events' => $created], 201);
	}

	/**
	 * Full update of one occurrence — slot (timeslot offering) or event.
	 * PUT semantics: starts_at, ends_at and capacity are all required.
	 * Capacity may shrink only down to the live sold+held count, and is
	 * rejected entirely for pooled slots — pool capacity is managed on
	 * the resource, never per-occurrence. A moved occurrence notifies
	 * paid buyers (see notifyRescheduled).
	 */
	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		$start = $this->parseDt('starts_at');
		$end = $this->parseDt('ends_at');
		$cap = $this->strictId($this->request->getParam('capacity'));
		if ($cap === null || $cap < 1 || $cap > 100000) {
			throw new ValidationException(['capacity' => 'Capacity must be 1 or more.']);
		}
		if ($end <= $start) {
			throw new ValidationException(['ends_at' => 'End must be after start.']);
		}

		// Row lock before the capacity check — without it a racing hold()
		// could land between the read and the write and oversell the new
		// cap. The held lock also makes the concurrent hold's conditional
		// UPDATE re-evaluate against the committed capacity.
		if ($this->slots->find($id) !== null) {
			$this->db->beginTransaction();
			try {
				$s = $this->slots->findForUpdate($id);
				if ($s === null) {
					throw new NotFoundException();
				}
				$booked = (int)$s->getSold() + (int)$s->getHeld();
				if ($this->slotRes->hasRows($id)) {
					// pooled slot: bc_slot_res rows own the counters — a row-level
					// cap edit would silently desync from pool capacity
					if ($cap !== (int)$s->getCapacity()) {
						throw new ValidationException(['capacity' => 'Capacity is managed on the resource pools for this slot.']);
					}
				} elseif ($cap < $booked) {
					throw new ValidationException(['capacity' => $this->tx('Below the number already sold or held') . ' (' . $booked . ').']);
				}
				$moved = $s->getStartsAt() != $start || $s->getEndsAt() != $end;
				$s->setStartsAt($start);
				$s->setEndsAt($end);
				$s->setCapacity($cap);
				$this->slots->update($s);
				$this->db->commit();
				if ($moved) {
					$this->notifyRescheduled('slot', $id, $start, $end);
				}
				return $this->ok(['id' => $id]);
			} catch (\Throwable $ex) {
				$this->db->rollBack();
				if ($ex instanceof \OCP\DB\Exception
					&& $ex->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw new ValidationException(['starts_at' => 'A slot already exists at that time.']);
				}
				throw $ex;
			}
		}

		if ($this->events->find($id) !== null) {
			$this->db->beginTransaction();
			try {
				$e = $this->events->findForUpdate($id);
				if ($e === null) {
					throw new NotFoundException();
				}
				$booked = (int)$e->getSold() + (int)$e->getHeld();
				if ($cap < $booked) {
					throw new ValidationException(['capacity' => $this->tx('Below the number already sold or held') . ' (' . $booked . ').']);
				}
				$moved = $e->getStartsAt() != $start || $e->getEndsAt() != $end;
				$e->setStartsAt($start);
				$e->setEndsAt($end);
				$e->setCapacity($cap);
				$this->events->update($e);
				$this->db->commit();
				if ($moved) {
					$this->notifyRescheduled('event', $id, $start, $end);
				}
				return $this->ok(['id' => $id]);
			} catch (\Throwable $ex) {
				$this->db->rollBack();
				throw $ex;
			}
		}
		throw new NotFoundException();
	}

	/**
	 * Queue a reschedule notice to every paid buyer holding a seat on the
	 * moved occurrence. Paid-only: in-flight carts revalidate at pay time
	 * and approved-pending orders render fresh times on the pay link.
	 */
	private function notifyRescheduled(string $targetType, int $targetId,
		\DateTimeImmutable $start, \DateTimeImmutable $end): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('o.id')->addSelect('o.ref', 'o.buyer_email', 'o.buyer_locale', 'o.offering_id')
			->from('bc_order', 'o')
			->innerJoin('o', 'bc_order_line', 'l', $qb->expr()->andX(
				$qb->expr()->eq('l.order_id', 'o.id'),
				$qb->expr()->eq('l.target_type', $qb->createNamedParameter($targetType)),
				$qb->expr()->eq('l.target_id', $qb->createNamedParameter($targetId))
			))
			->where($qb->expr()->eq('o.status', $qb->createNamedParameter('paid')))
			->setMaxResults(2000);
		$title = '';
		foreach ($qb->executeQuery()->fetchAll() as $r) {
			if ($title === '') {
				$off = $this->offerings->find((int)$r['offering_id']);
				$title = $off === null ? '' : (string)$off->getTitle();
			}
			$this->email->queue('rescheduled', (string)$r['buyer_email'], (string)$r['buyer_locale'], [
				'order_ref' => (string)$r['ref'],
				'title' => $title,
				'new_start' => $start->format('Y-m-d H:i'),
				'new_end' => $end->format('Y-m-d H:i'),
			]);
		}
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		// routes call /api/slots/{id} — the id is a slot OR event; try both.
		// The booking check runs UNDER row locks: without them a racing
		// hold() can book between the read and the delete, leaving an
		// order line pointed at a ghost target.
		if ($this->slots->find($id) !== null) {
			$this->db->beginTransaction();
			try {
				$s = $this->slots->findForUpdate($id);
				if ($s === null) {
					throw new NotFoundException();
				}
				// pooled bookings live on bc_slot_res — those rows take the
				// hold() lock, so they must be locked + re-read here too.
				$poolBooked = false;
				foreach ($this->slotRes->lockRowsForSlot($id) as $sr) {
					if ((int)$sr->getSold() > 0 || (int)$sr->getHeld() > 0) {
						$poolBooked = true;
						break;
					}
				}
				// bc_slot has no cancelled state — a booked slot must never
				// vanish: order lines would point at a ghost target and the
				// capacity counters could never reconcile.
				if ((int)$s->getSold() > 0 || (int)$s->getHeld() > 0 || $poolBooked) {
					throw new ValidationException(['id' => 'Booked slots cannot be deleted.']);
				}
				$this->deleteTargetGhosts('slot', $id);
				$this->slots->delete($s);
				$this->db->commit();
			} catch (\Throwable $ex) {
				$this->db->rollBack();
				throw $ex;
			}
			return $this->ok(['deleted' => true]);
		}
		if ($this->events->find($id) !== null) {
			$this->db->beginTransaction();
			try {
				$e = $this->events->findForUpdate($id);
				if ($e === null) {
					throw new NotFoundException();
				}
				if ((int)$e->getSold() > 0 || (int)$e->getHeld() > 0) {
					$e->setStatus('cancelled'); // booked occurrences cancel, never delete
					$this->events->update($e);
				} else {
					$this->deleteTargetGhosts('event', $id);
					$this->events->delete($e);
				}
				$this->db->commit();
			} catch (\Throwable $ex) {
				$this->db->rollBack();
				throw $ex;
			}
			return $this->ok(['deleted' => true]);
		}
		throw new NotFoundException();
	}

	/** Remove rows that dangle after a target delete (no FKs to help). */
	private function deleteTargetGhosts(string $type, int $targetId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_waitlist')
			->where($qb->expr()->eq('target_type', $qb->createNamedParameter($type)))
			->andWhere($qb->expr()->eq('target_id', $qb->createNamedParameter($targetId)))
			->executeStatement();
		if ($type === 'slot') {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_slot_res')
				->where($qb->expr()->eq('slot_id', $qb->createNamedParameter($targetId)))
				->executeStatement();
		}
	}

	/** Validated optional resource_id: offering-owned and active, or 404-free rejection. */
	private function resourceIdFor(int $offeringId): ?int
	{
		$rid = $this->strictId($this->request->getParam('resource_id'));
		if ($rid === null) {
			return null;
		}
		$r = $this->resources->find($rid);
		if ($r === null || (int)$r->getOfferingId() !== $offeringId || (int)$r->getActive() !== 1) {
			throw new ValidationException(['resource_id' => 'Unknown resource.']);
		}
		return $rid;
	}

	private function resourceCapacity(int $resourceId): int
	{
		$r = $this->resources->find($resourceId);
		return $r !== null ? max(1, (int)$r->getCapacity()) : 1;
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
		$raw = trim((string)$this->request->getParam($key, ''));
		if ($raw === '') {
			// an empty datetime silently parses as "now" — reject it
			throw new ValidationException([$key => 'A date and time are required.']);
		}
		try {
			return new \DateTimeImmutable($raw);
		} catch (\Throwable) {
			throw new ValidationException([$key => 'Invalid date/time.']);
		}
	}
}
