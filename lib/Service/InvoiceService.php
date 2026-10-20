<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\Invoice;
use OCA\BookingCheck\Db\InvoiceMapper;
use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Db\Order;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;

/**
 * GoBD-compliant invoice allocator.
 *
 * Numbering: SELECT … FOR UPDATE on the single bc_invoice_seq row for
 * (prefix, year) inside the caller's transaction — gapless, race-free,
 * portable. Invoice rows are INSERT-only; corrections are credit notes
 * (type='credit', credits_invoice_id → original), never UPDATE/DELETE.
 * is_test orders never allocate a number (no fake gaps in the real seq).
 */
class InvoiceService
{
	public function __construct(
		private readonly InvoiceMapper $invoices,
		private readonly OfferingMapper $offerings,
		private readonly SettingsService $settings,
		private readonly IDBConnection $db,
	) {
	}

	/** Issue the purchase invoice for a freshly-paid order. Idempotent. */
	public function issueForOrder(Order $order): ?Invoice
	{
		if ((int)$order->getIsTest() === 1) {
			return null;
		}
		$existing = $this->invoices->findByOrderAndType((int)$order->getId(), 'invoice');
		if ($existing !== null) {
			return $existing;
		}
		[$net, $vat] = $this->computeVat($order);
		return $this->allocate((int)$order->getId(), 'invoice', null, $net, $vat, (int)$order->getAmountCents());
	}

	/** Credit note for a refund — negative mirror, references the original. */
	public function issueCredit(Order $order, int $amountCents): ?Invoice
	{
		if ((int)$order->getIsTest() === 1 || $amountCents <= 0) {
			return null;
		}
		$original = $this->invoices->findByOrderAndType((int)$order->getId(), 'invoice');
		[$net, $vat] = $this->computeVatForAmount($order, $amountCents);
		return $this->allocate((int)$order->getId(), 'credit',
			$original !== null ? (int)$original->getId() : null, -$net, -$vat, -$amountCents);
	}

	private function computeVat(Order $order): array
	{
		return $this->computeVatForAmount($order, (int)$order->getAmountCents());
	}

	/** @return array{0:int,1:int} [net, vat] in cents */
	private function computeVatForAmount(Order $order, int $amountCents): array
	{
		// §19 Kleinunternehmer and reverse charge both zero the VAT line.
		if ($this->settings->getBool('kleinunternehmer') || (int)$order->getReverseCharge() === 1) {
			return [$amountCents, 0];
		}
		$vatBp = 1900;
		$offering = $this->offerings->find((int)$order->getOfferingId());
		if ($offering !== null) {
			$vatBp = (int)$offering->getVatBp();
		}
		return PricingService::splitVat($amountCents, $vatBp);
	}

	/**
	 * Allocate the next number. Caller MUST be inside a transaction —
	 * the FOR UPDATE lock is what makes this race-free.
	 */
	private function allocate(int $orderId, string $type, ?int $creditsId, int $net, int $vat, int $gross): Invoice
	{
		$prefix = $this->settings->get('invoice_prefix') !== '' ? $this->settings->get('invoice_prefix') : 'INV';
		$year = (int)date('Y');

		// ensure seq row exists (INSERT IGNORE-style: catch unique race).
		// Only the unique-violation is swallowed — a deadlock/timeout must
		// abort loudly, not fall through to a phantom row.
		$qb = $this->db->getQueryBuilder();
		$qb->insert('bc_invoice_seq')
			->setValue('prefix', $qb->createNamedParameter($prefix))
			->setValue('year', $qb->createNamedParameter($year))
			->setValue('next', $qb->createNamedParameter(1));
		try {
			$qb->executeStatement();
		} catch (DbException $e) {
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'next')->from('bc_invoice_seq')
			->where($qb->expr()->eq('prefix', $qb->createNamedParameter($prefix)))
			->andWhere($qb->expr()->eq('year', $qb->createNamedParameter($year)))
			->forUpdate();
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			throw new DbException('bc_invoice_seq row vanished after ensure-insert');
		}
		$number = (int)$row['next'];

		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_invoice_seq')->set('next', $qb->createNamedParameter($number + 1))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row['id'])));
		$qb->executeStatement();

		$inv = new Invoice();
		$inv->setOrderId($orderId);
		$inv->setNumber($number);
		$inv->setPrefix($prefix);
		$inv->setYear($year);
		$inv->setType($type);
		$inv->setCreditsInvoiceId($creditsId);
		$inv->setNetCents($net);
		$inv->setVatCents($vat);
		$inv->setGrossCents($gross);
		$inv->setIssuedAt(new \DateTimeImmutable());
		$this->invoices->insert($inv);
		return $inv;
	}
}
