<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCP\IDBConnection;

/**
 * NH-06 — DATEV posting batch export (EXTF CSV, "Buchungsstapel" format).
 *
 * One row per issued invoice/credit note in the period — the accountant
 * books receivables, not raw orders. Field sanitizing is the api-security
 * hard rule: every substituted value passes datevField() which strips
 * the field separator ';' AND record separators \r \n — a crafted buyer
 * name can never forge extra columns or rows. is_test orders are excluded
 * unconditionally (NN-25).
 */
class DatevExportService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly SettingsService $settings,
	) {
	}

	/**
	 * @return array{filename:string,csv:string}
	 */
	public function export(\DateTimeImmutable $from, \DateTimeImmutable $to): array
	{
		$consultant = $this->settings->get('datev_consultant_no') ?: '0';
		$client = $this->settings->get('datev_client_no') ?: '0';
		$account = $this->settings->get('datev_revenue_account') ?: '8400';
		$receivable = $this->settings->get('datev_receivable_account') ?: '1400';
		$currency = 'EUR';

		$qb = $this->db->getQueryBuilder();
		$qb->select('i.number', 'i.type', 'i.net_cents', 'i.vat_cents', 'i.issued_at',
			'o.ref', 'o.buyer_name', 'o.buyer_company', 'o.currency', 'o.vat_bp')
			->from('bc_invoice', 'i')
			->innerJoin('i', 'bc_order', 'o', $qb->expr()->eq('o.id', 'i.order_id'))
			->where($qb->expr()->gte('i.issued_at', $qb->createNamedParameter($from, 'datetime_immutable')))
			->andWhere($qb->expr()->lt('i.issued_at', $qb->createNamedParameter($to, 'datetime_immutable')))
			->andWhere($qb->expr()->eq('o.is_test', $qb->createNamedParameter(0)))
			->orderBy('i.number', 'ASC');
		$rows = $qb->executeQuery()->fetchAll();

		$fy = $from->format('Y');
		$lines = [];
		// EXTF v700 header — DATEV validates this verbatim
		$lines[] = implode(';', [
			'"EXTF"', '700', '21', '"Buchungsstapel"', '12', '', '',
			$this->q($consultant), $this->q($client),
			$from->format('Ymd') . '0101', '4', '1', '',
			$this->q($from->format('Ymd')), $this->q($to->format('Ymd')),
			'"BookingCheck"', '', '', '', '0', '0', $this->q($currency), '', '', '', '',
		]);
		$lines[] = implode(';', [
			'Umsatz (ohne Soll/Haben-Kz)', 'Soll/Haben-Kennzeichen', 'WKZ Umsatz',
			'Kurs', 'Basis-Umsatz', 'WKZ Basis-Umsatz', 'Konto', 'Gegenkonto (ohne BU-Schlüssel)',
			'BU-Schlüssel', 'Belegdatum', 'Belegfeld 1', 'Belegfeld 2', 'Skonto', 'Buchungstext',
		]);
		foreach ($rows as $r) {
			$gross = ((int)$r['net_cents'] + (int)$r['vat_cents']) / 100;
			$sign = $r['type'] === 'credit' ? -1 : 1;
			$amount = number_format($gross * $sign, 2, ',', '');
			$issued = $r['issued_at'] instanceof \DateTimeInterface
				? $r['issued_at']->format('dm') : date('dm');
			$text = trim(($r['buyer_company'] ?: $r['buyer_name']) . ' ' . $r['ref']);
			$lines[] = implode(';', [
				$amount,
				'"S"',
				$this->q((string)$r['currency']),
				'', '', '',
				$this->q($receivable),
				$this->q($account),
				((int)$r['vat_bp'] === 0 ? '' : '9'), // BU 9 = VSt
				$issued,
				$this->q((string)$r['number']),
				'',
				'',
				$this->q($this->datevField($text)),
			]);
		}
		$csv = implode("\r\n", $lines) . "\r\n";
		return [
			'filename' => 'EXTF_Buchungsstapel_' . $from->format('Ymd') . '-' . $to->format('Ymd') . '.csv',
			'csv' => $csv,
		];
	}

	/** DATEV field: strip separator + CR/LF — nothing else touches the wire. */
	private function datevField(string $v): string
	{
		return str_replace([';', "\r", "\n"], ' ', $v);
	}

	/** Quote a scalar the DATEV way — inner quotes are doubled first. */
	private function q(string $v): string
	{
		$v = $this->datevField($v);
		return '"' . str_replace('"', '""', $v) . '"';
	}
}
