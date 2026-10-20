<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCP\IDBConnection;

/**
 * CSV export — formula-injection-safe (api-security §7).
 * Every cell starting with =, +, -, @, TAB, CR or VT is prefixed with a
 * literal apostrophe; embedded delimiters are quoted by fputcsv itself.
 * is_test orders never appear in exports.
 */
class CsvExportService
{
	public function __construct(private readonly IDBConnection $db)
	{
	}

	public function orders(): string
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('o.ref', 'o.buyer_name', 'o.buyer_email', 'o.status',
			'o.amount_cents', 'o.currency', 'o.created_at', 'o.paid_at', 'f.title')
			->from('bc_order', 'o')
			->leftJoin('o', 'bc_offering', 'f', $qb->expr()->eq('f.id', 'o.offering_id'))
			->where($qb->expr()->eq('o.is_test', $qb->createNamedParameter(0)))
			->orderBy('o.id', 'DESC')
			->setMaxResults(50000);

		$out = fopen('php://temp', 'w+');
		fputcsv($out, ['ref', 'title', 'buyer_name', 'buyer_email', 'status',
			'amount_cents', 'currency', 'created_at', 'paid_at']);
		foreach ($qb->executeQuery()->fetchAll() as $r) {
			fputcsv($out, [
				self::cell($r['ref']), self::cell($r['title']),
				self::cell($r['buyer_name']), self::cell($r['buyer_email']),
				self::cell($r['status']), $r['amount_cents'], self::cell($r['currency']),
				self::cell($r['created_at']), self::cell($r['paid_at'] ?? ''),
			]);
		}
		rewind($out);
		return stream_get_contents($out);
	}

	/** Per-offering roster for the seller zip export — same cell safety. */
	public function roster(int $offeringId): string
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('o.ref', 'o.buyer_name', 'o.buyer_email', 'o.status', 'o.paid_at',
			'l.seats', 'l.target_type', 'l.target_id', 't.attendee_name')
			->from('bc_order', 'o')
			->innerJoin('o', 'bc_order_line', 'l', $qb->expr()->eq('l.order_id', 'o.id'))
			->leftJoin('l', 'bc_ticket', 't', $qb->expr()->eq('t.line_id', 'l.id'))
			->where($qb->expr()->eq('o.offering_id', $qb->createNamedParameter($offeringId)))
			->andWhere($qb->expr()->eq('o.is_test', $qb->createNamedParameter(0)))
			->orderBy('o.id', 'ASC')
			->setMaxResults(50000);
		$out = fopen('php://temp', 'w+');
		fputcsv($out, ['ref', 'buyer_name', 'buyer_email', 'status', 'paid_at',
			'seats', 'target_type', 'target_id', 'attendee_name']);
		foreach ($qb->executeQuery()->fetchAll() as $r) {
			fputcsv($out, [
				self::cell($r['ref']), self::cell($r['buyer_name']),
				self::cell($r['buyer_email']), self::cell($r['status']),
				self::cell($r['paid_at'] ?? ''), $r['seats'],
				self::cell($r['target_type']), $r['target_id'],
				self::cell($r['attendee_name'] ?? ''),
			]);
		}
		rewind($out);
		return stream_get_contents($out);
	}

	/** Prefix spreadsheet-dangerous leading chars with a literal quote. */
	public static function cell(?string $v): string
	{
		$v = (string)$v;
		if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r", "\x0B"], true)) {
			return "'" . $v;
		}
		return $v;
	}
}
