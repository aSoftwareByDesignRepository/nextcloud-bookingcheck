<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Integration;

use OCP\App\IAppManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * US-526 Deck boards. Deck ships no OCP API, so the boundary is a guarded
 * class probe (family precedent: FacadeProbeService) — class_exists +
 * container query + try/catch at every hop. Absent Deck = hidden toggle,
 * never a broken affordance (AC-1).
 */
class DeckIntegration
{
	public function __construct(
		private readonly IAppManager $apps,
		private readonly IUserManager $users,
		private readonly LoggerInterface $logger,
	) {
	}

	public function isAvailable(): bool
	{
		return $this->apps->isEnabledForUser('deck')
			&& class_exists(\OCA\Deck\Service\BoardService::class);
	}

	/** Create a board owned by the seller; returns the board id or null. */
	public function createBoard(string $title, string $ownerUid): ?int
	{
		if (!$this->isAvailable() || $this->users->get($ownerUid) === null) {
			return null;
		}
		try {
			$svc = \OCP\Server::get(\OCA\Deck\Service\BoardService::class);
			$board = $svc->create(mb_substr($title, 0, 100), $ownerUid, '7459ca');
			$id = is_object($board) ? (int)$board->getId() : (int)($board['id'] ?? 0);
			return $id > 0 ? $id : null;
		} catch (\Throwable $e) {
			$this->logger->warning('bookingcheck: Deck board creation failed', ['error' => $e->getMessage()]);
			return null;
		}
	}

	/** True if the board still exists; null when Deck is absent. */
	public function boardExists(?int $boardId): ?bool
	{
		if ($boardId === null || $boardId <= 0 || !$this->isAvailable()) {
			return null;
		}
		try {
			$mapper = \OCP\Server::get(\OCA\Deck\Db\BoardMapper::class);
			return $mapper->find($boardId) !== null;
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return false;
		} catch (\Throwable) {
			return null; // inconclusive → keep the link rather than flicker
		}
	}

	/** Absolute admin URL for a board; null when Deck is absent. */
	public function boardUrl(int $boardId): ?string
	{
		if (!$this->isAvailable()) {
			return null;
		}
		try {
			return \OCP\Server::get(\OCP\IURLGenerator::class)
				->linkToRouteAbsolute('deck.page.index') . '#/board/' . $boardId;
		} catch (\Throwable) {
			return null;
		}
	}
}
