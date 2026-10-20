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
		// CAS: only mark when still unchecked — two doors scanning the same
		// ticket concurrently get exactly one winner.
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_ticket')
			->set('checked_in_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
			->set('checked_in_by', $qb->createNamedParameter($actor))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$ticket->getId())))
			->andWhere($qb->expr()->isNull('checked_in_at'));
		$won = $qb->executeStatement() === 1;
		if ($won) {
			$ticket->setCheckedInAt(new \DateTimeImmutable());
			$ticket->setCheckedInBy($actor);
		}
		return ['ok' => true, 'already' => !$won, 'revoked' => false, 'ticket' => $ticket];
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
	 * @return list<array{id:int,seat_no:?int,attendee_name:string,checked_in_at:?string}>
	 */
	public function liveTicketsForOrder(int $orderId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.id', 't.seat_no', 't.attendee_name', 't.checked_in_at', 't.qr_token_enc')
			->from('bc_ticket', 't')
			->innerJoin('t', 'bc_order_line', 'ol', $qb->expr()->eq('ol.id', 't.line_id'))
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
			unset($r['qr_token_enc']);
		}
		return $rows;
	}

	/** Offering a ticket belongs to — for companion-token scope checks. */
	public function offeringIdForTicket(Ticket $ticket): ?int
	{
		$line = $this->lines->find((int)$ticket->getLineId());
		if ($line === null) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$v = $qb->select('offering_id')->from('bc_order')
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$line->getOrderId())))
			->executeQuery()->fetchOne();
		return $v === false ? null : (int)$v;
	}
}
