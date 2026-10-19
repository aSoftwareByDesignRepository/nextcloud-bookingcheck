<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Integration;

use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Talk\Exceptions\ConversationCreationException;
use OCP\Talk\IBroker;
use Psr\Log\LoggerInterface;

/**
 * US-527 Talk rooms via the official OCP broker (no app-internal coupling).
 * The stored value is Talk's absolute conversation URL — a join link, not
 * auto-admission; Talk's own access control gates entry (spec AC-4).
 */
class TalkIntegration
{
	public function __construct(
		private readonly IBroker $talk,
		private readonly IAppManager $apps,
		private readonly IUserManager $users,
		private readonly LoggerInterface $logger,
	) {
	}

	public function isAvailable(): bool
	{
		try {
			return $this->apps->isEnabledForUser('spreed') && $this->talk->hasBackend();
		} catch (\Throwable) {
			return false;
		}
	}

	/** Create a private room; returns its absolute join URL or null. */
	public function createRoom(string $name, string $ownerUid): ?string
	{
		if (!$this->isAvailable()) {
			return null;
		}
		$owner = $this->users->get($ownerUid);
		if (!$owner instanceof IUser) {
			return null;
		}
		try {
			$options = $this->talk->newConversationOptions()->setPublic(false);
			$room = $this->talk->createConversation($name, [$owner], $options);
			return $room->getAbsoluteUrl();
		} catch (ConversationCreationException|\Throwable $e) {
			$this->logger->warning('bookingcheck: Talk room creation failed', ['error' => $e->getMessage()]);
			return null;
		}
	}
}
