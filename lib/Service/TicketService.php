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
	 * Returns ['ok'=>bool, 'already'=>bool, 'ticket'=>?Ticket].
	 */
	public function checkInByToken(string $qrToken, string $actor): array
	{
		$hash = $this->tokens->hash($qrToken);
		$ticket = $this->tickets->findByQrHash($hash);
		if ($ticket === null) {
			return ['ok' => false, 'already' => false, 'ticket' => null];
		}
		if ($ticket->getCheckedInAt() !== null) {
			return ['ok' => true, 'already' => true, 'ticket' => $ticket];
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
		return ['ok' => true, 'already' => !$won, 'ticket' => $ticket];
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
}
