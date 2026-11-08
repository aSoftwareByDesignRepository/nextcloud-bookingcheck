<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\Order;
use OCA\BookingCheck\Db\OrderLineMapper;
use OCA\BookingCheck\Db\ResourceMapper;
use OCA\BookingCheck\Db\Ticket;
use OCA\BookingCheck\Db\TicketMapper;
use OCP\IDBConnection;
use OCP\Security\ICrypto;

/**
 * Ticket issuance — one ticket per seat per seat-line.
 *
 * QR payload format: BCK1:<ticketId>:<qrToken>. Only the token HASH is
 * verified by hash (qr_token_hash, indexable); an encrypted copy
 * (qr_token_enc) is kept solely so the manage page can re-render the QR —
 * a DB leak exposes hashes + ciphertext, never usable bearers.
 * Re-issue is idempotent (line_id unique per seat).
 */
class TicketService
{
	// unique-conflict:ok — ticket rows mint crypto-random qr_token_hash values (cannot collide); the resource lookup is read-only.
	public function __construct(
		private readonly TicketMapper $tickets,
		private readonly OrderLineMapper $lines,
		private readonly ResourceMapper $resources,
		private readonly TokenService $tokens,
		private readonly ICrypto $crypto,
		private readonly IDBConnection $db,
	) {
	}

	/** Issue all tickets for a paid order. Idempotent. */
	public function issueForOrder(Order $order): void
	{
		foreach ($this->lines->findByOrder((int)$order->getId()) as $line) {
			if ($line->getLineKind() !== 'seat') {
				continue;
			}
			$existing = $this->tickets->countForLine((int)$line->getId());
			if ($existing >= (int)$line->getSeats()) {
				continue; // already issued (webhook replay)
			}
			$resourceName = null;
			if ($line->getResourceId() !== null) {
				$res = $this->resources->find((int)$line->getResourceId());
				$resourceName = $res !== null ? $res->getName() : null;
			}
			for ($i = $existing + 1; $i <= (int)$line->getSeats(); $i++) {
				[$token, $hash] = $this->tokens->mint();
				$t = new Ticket();
				$t->setLineId((int)$line->getId());
				$t->setSeatNo($i);
				$t->setAttendeeName($order->getBuyerName());
				$t->setAttendeeEmail($order->getBuyerEmail());
				$t->setResourceName($resourceName);
				$t->setQrTokenHash($hash);
				$t->setQrTokenEnc($this->crypto->encrypt($token));
				$this->tickets->insert($t);
			}
		}
	}

	/** Full check-in payload for the QR code: BCK1:<ticketId>:<token>. */
	public function qrPayload(Ticket $ticket): ?string
	{
		$enc = $ticket->getQrTokenEnc();
		if ($enc === null || $enc === '') {
			return null; // pre-1001 rows: re-issue path, never leak a guess
		}
		return 'BCK1:' . (int)$ticket->getId() . ':' . $this->crypto->decrypt($enc);
	}

	/** Inline SVG for the manage/ticket page — same-origin, no QR API. */
	public function qrSvg(Ticket $ticket): ?string
	{
		$payload = $this->qrPayload($ticket);
		return $payload === null ? null : \splitbrain\phpQRCode\QRCode::svg($payload, ['s' => 'qrm']);
	}

	/**
	 * QR check-in: verify token, then CAS-mark checked in.
	 * Returns ['ok'=>bool, 'already'=>bool, 'revoked'=>bool, 'ticket'=>?Ticket].
	 */
	public function checkInByToken(string $qrToken, string $actor): array
	{
		$hash = $this->tokens->hash($qrToken);
		$ticket = $this->tickets->findByQrHash($hash);
		if ($ticket === null) {
			return ['ok' => false, 'already' => false, 'revoked' => false, 'ticket' => null];
		}
		// refunded/cancelled orders kill their tickets — the QR may still
		// be in the buyer's wallet, so the door needs a distinct answer.
		if ($ticket->getRevokedAt() !== null) {
			return ['ok' => false, 'already' => false, 'revoked' => true, 'ticket' => $ticket];
		}
		if ($ticket->getCheckedInAt() !== null) {
			return ['ok' => true, 'already' => true, 'revoked' => false, 'ticket' => $ticket];
		}
		// CAS: only mark when still unchecked AND live — two doors scanning
		// the same ticket get exactly one winner, and a refund/revoke that
		// lands between the read and this update can never admit a dead QR.
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_ticket')
			->set('checked_in_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
			->set('checked_in_by', $qb->createNamedParameter($actor))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$ticket->getId())))
			->andWhere($qb->expr()->isNull('checked_in_at'))
			->andWhere($qb->expr()->isNull('revoked_at'));
		$won = $qb->executeStatement() === 1;
		if ($won) {
			$ticket->setCheckedInAt(new \DateTimeImmutable());
			$ticket->setCheckedInBy($actor);
			return ['ok' => true, 'already' => false, 'revoked' => false, 'ticket' => $ticket];
		}
		// lost the CAS — re-read to say WHICH guard fired (refreshes the
		// door's answer honestly instead of guessing 'already')
		$fresh = $this->tickets->find((int)$ticket->getId());
		if ($fresh !== null && $fresh->getRevokedAt() !== null) {
			return ['ok' => false, 'already' => false, 'revoked' => true, 'ticket' => $fresh];
		}
		return ['ok' => true, 'already' => true, 'revoked' => false,
			'ticket' => $fresh ?? $ticket];
	}

	public function undoCheckIn(int $ticketId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_ticket')
			->set('checked_in_at', $qb->createNamedParameter(null))
			->set('checked_in_by', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($ticketId)));
		$qb->executeStatement();
	}

	/**
	 * Kill every ticket of an order — refund/paid-cancel path. Idempotent:
	 * only live tickets get stamped. Call inside the order transaction so
	 * a rollback can't leave valid QRs on a refunded order.
	 */
	public function revokeForOrder(int $orderId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_ticket')
			->set('revoked_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
			->where($qb->expr()->isNull('revoked_at'))
			->andWhere($qb->expr()->in('line_id',
				$qb->createFunction('(SELECT id FROM *PREFIX*bc_order_line WHERE order_id = '
					. (int)$orderId . ')')));
		return $qb->executeStatement();
	}

	/**
	 * Resolve a raw QR bearer to its ticket WITHOUT checking in —
	 * callers that must scope-check the offering first use this, then
	 * checkInByTicket(). Returns null on an unknown/foreign token.
	 */
	public function resolveQrToken(string $qrToken): ?Ticket
	{
		return $this->tickets->findByQrHash($this->tokens->hash($qrToken));
	}

	/**
	 * Live tickets of an order — door lookup by the printed order ref.
	 * `offering_id` resolves via each line's TARGET (cart orders span
	 * offerings); `qr` is the re-postable check-in payload. Both are
	 * internal keys — callers must not echo them to the client.
	 * @return list<array{id:int,seat_no:?int,attendee_name:string,checked_in_at:?string,offering_id:?int,qr:?string}>
	 */
	public function liveTicketsForOrder(int $orderId): array
	{
		$qb = $this->db->getQueryBuilder();
		// offering_id comes from the line's TARGET — cart orders span
		// offerings, so door-scope filtering needs the per-line offering
		$qb->select('t.id', 't.seat_no', 't.attendee_name', 't.checked_in_at',
				't.qr_token_enc')
			->selectAlias($qb->createFunction(
				'COALESCE(ev.offering_id, sr.offering_id, sl.offering_id)'), 'offering_id')
			->from('bc_ticket', 't')
			->innerJoin('t', 'bc_order_line', 'ol', $qb->expr()->eq('ol.id', 't.line_id'))
			->leftJoin('ol', 'bc_event', 'ev', $qb->expr()->andX(
				$qb->expr()->eq('ev.id', 'ol.target_id'),
				$qb->expr()->eq('ol.target_type', $qb->createNamedParameter('event'))))
			->leftJoin('ol', 'bc_series', 'sr', $qb->expr()->andX(
				$qb->expr()->eq('sr.id', 'ol.target_id'),
				$qb->expr()->eq('ol.target_type', $qb->createNamedParameter('series'))))
			->leftJoin('ol', 'bc_slot', 'sl', $qb->expr()->andX(
				$qb->expr()->eq('sl.id', 'ol.target_id'),
				$qb->expr()->eq('ol.target_type', $qb->createNamedParameter('slot'))))
			->where($qb->expr()->eq('ol.order_id', $qb->createNamedParameter($orderId)))
			->andWhere($qb->expr()->isNull('t.revoked_at'))
			->orderBy('ol.id', 'ASC')->addOrderBy('t.seat_no', 'ASC');
		$rows = $qb->executeQuery()->fetchAll();
		// The door checks tickets in by their QR token, never by enumerable
		// row id — echo the payload so the scan page can re-post it.
		foreach ($rows as &$r) {
			$enc = (string)($r['qr_token_enc'] ?? '');
			$r['qr'] = $enc !== ''
				? 'BCK1:' . (int)$r['id'] . ':' . $this->crypto->decrypt($enc)
				: null;
			$r['offering_id'] = $r['offering_id'] === null ? null : (int)$r['offering_id'];
			unset($r['qr_token_enc']);
		}
		unset($r);
		/** @var list<array{id:int,seat_no:?int,attendee_name:string,checked_in_at:?string,offering_id:?int,qr:?string}> $rows */
		return $rows;
	}

	/** Offering a ticket belongs to — for companion-token scope checks.
	 *  Resolved via the line's TARGET: in a multi-offering cart the order
	 *  row's offering_id is only the lead offering and would scope lines
	 *  of the other offerings wrong (both directions: false deny AND
	 *  cross-offering admit). */
	public function offeringIdForTicket(Ticket $ticket): ?int
	{
		$line = $this->lines->find((int)$ticket->getLineId());
		if ($line === null) {
			return null;
		}
		$table = ['event' => 'bc_event', 'series' => 'bc_series',
			'slot' => 'bc_slot'][$line->getTargetType()] ?? null;
		if ($table === null) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$v = $qb->select('offering_id')->from($table)
			->where($qb->expr()->eq('id',
				$qb->createNamedParameter((int)$line->getTargetId())))
			->executeQuery()->fetchOne();
		return $v === false ? null : (int)$v;
	}
}
