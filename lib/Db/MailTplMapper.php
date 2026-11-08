<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<MailTpl> */
class MailTplMapper extends QBMapper
{
	// unique-conflict:ok — upsert carries the REASON_UNIQUE catch → winner-row update; the update path never rewrites the (kind,locale) key to a colliding value.
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'bc_mail_tpl', MailTpl::class);
	}

	public function findFor(string $kind, string $locale): ?MailTpl
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
			->andWhere($qb->expr()->eq('locale', $qb->createNamedParameter($locale)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Exact kind+locale first, then the '' (all-languages) row — sellers who
	 * write one template without picking a language expect it to apply.
	 */
	public function findApplicable(string $kind, string $locale): ?MailTpl
	{
		return $this->findFor($kind, $locale) ?? $this->findFor($kind, '');
	}

	public function upsert(string $kind, string $locale, ?string $subject, ?string $extraText): MailTpl
	{
		$tpl = $this->findFor($kind, $locale) ?? new MailTpl();
		$tpl->setKind($kind);
		$tpl->setLocale($locale);
		$tpl->setSubject($subject === null || $subject === '' ? null : $subject);
		$tpl->setExtraText($extraText === null || $extraText === '' ? null : $extraText);
		if ($tpl->getId() !== null) {
			return $this->update($tpl);
		}
		try {
			return $this->insert($tpl);
		} catch (\OCP\DB\Exception $e) {
			// findFor→insert is check-then-act: a concurrent upsert of the
			// same (kind,locale) wins; apply our values to the winner's row
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			$winner = $this->findFor($kind, $locale);
			if ($winner === null) {
				throw $e;
			}
			$winner->setSubject($tpl->getSubject());
			$winner->setExtraText($tpl->getExtraText());
			return $this->update($winner);
		}
	}

	public function find(int $id): ?MailTpl
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<MailTpl> */
	public function listAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('kind', 'ASC');
		return $this->findEntities($qb);
	}
}
