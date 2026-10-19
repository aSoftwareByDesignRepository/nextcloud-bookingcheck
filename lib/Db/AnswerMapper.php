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

/** @template-extends QBMapper<Answer> */
class AnswerMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'bc_answer', Answer::class);
	}

	public function find(int $id): ?Answer
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

	/** Row lock for CAS updates — call inside an open transaction. */
	public function findForUpdate(int $id): ?Answer
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->forUpdate();
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<Answer> */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * @param list<int> $ticketIds
	 * @return array<int, array<int,string>> ticket_id ⇒ question_id ⇒ value
	 */
	public function answersByTicket(array $ticketIds): array
	{
		if ($ticketIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('ticket_id', 'question_id', 'value')->from($this->getTableName())
			->where($qb->expr()->in('ticket_id',
				$qb->createNamedParameter($ticketIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
		$out = [];
		foreach ($qb->executeQuery()->fetchAll() as $r) {
			$out[(int)$r['ticket_id']][(int)$r['question_id']] = (string)($r['value'] ?? '');
		}
		return $out;
	}

	/** Insert-or-update one answer (unique on ticket+question). */
	public function upsert(int $ticketId, int $questionId, string $value, int $wordingRev): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName())
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId)))
			->andWhere($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)))
			->setMaxResults(1);
		$existing = $qb->executeQuery()->fetchOne();
		if ($existing !== false) {
			$u = $this->db->getQueryBuilder();
			$u->update($this->getTableName())
				->set('value', $u->createNamedParameter($value))
				->set('answered_at', $u->createNamedParameter(new \DateTime(), 'datetime'))
				->set('wording_rev', $u->createNamedParameter($wordingRev))
				->where($u->expr()->eq('id', $u->createNamedParameter((int)$existing)));
			$u->executeStatement();
			return;
		}
		$a = new Answer();
		$a->setTicketId($ticketId);
		$a->setQuestionId($questionId);
		$a->setValue($value);
		$a->setWordingRev($wordingRev);
		$a->setAnsweredAt(new \DateTimeImmutable());
		$this->insert($a);
	}
}
