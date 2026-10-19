<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Service;

use OCA\BookingCheck\Integration\DeckIntegration;
use OCA\BookingCheck\Integration\TalkIntegration;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Talk\IBroker;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

final class OptionalIntegrationsTest extends TestCase
{
	private IAppManager&MockObject $apps;
	private IUserManager&MockObject $users;

	protected function setUp(): void
	{
		$this->apps = $this->createMock(IAppManager::class);
		$this->users = $this->createMock(IUserManager::class);
	}

	private function deck(): DeckIntegration
	{
		return new DeckIntegration($this->apps, $this->users, $this->createMock(LoggerInterface::class));
	}

	public function testDeckUnavailableWhenAppNotEnabled(): void
	{
		$this->apps->method('isEnabledForUser')->with('deck')->willReturn(false);
		$this->assertFalse($this->deck()->isAvailable());
		// every public surface must degrade silently, never throw
		$this->assertNull($this->deck()->createBoard('X', 'seller'));
		$this->assertNull($this->deck()->boardExists(5));
		$this->assertNull($this->deck()->boardUrl(5));
	}

	public function testDeckCreateBoardRejectsUnknownOwner(): void
	{
		$this->apps->method('isEnabledForUser')->willReturn(true);
		$this->users->method('get')->with('ghost')->willReturn(null);
		$this->assertNull($this->deck()->createBoard('X', 'ghost'));
	}

	public function testTalkUnavailableWhenAppDisabled(): void
	{
		$this->apps->method('isEnabledForUser')->with('spreed')->willReturn(false);
		$talk = new TalkIntegration($this->createMock(IBroker::class), $this->apps,
			$this->users, $this->createMock(LoggerInterface::class));
		$this->assertFalse($talk->isAvailable());
		$this->assertNull($talk->createRoom('Room', 'seller'));
	}

	public function testTalkUnavailableWhenNoBackend(): void
	{
		$this->apps->method('isEnabledForUser')->willReturn(true);
		$broker = $this->createMock(IBroker::class);
		$broker->method('hasBackend')->willReturn(false);
		$talk = new TalkIntegration($broker, $this->apps, $this->users,
			$this->createMock(LoggerInterface::class));
		$this->assertFalse($talk->isAvailable());
		$this->assertNull($talk->createRoom('Room', 'seller'));
	}

	public function testTalkCreateRoomRejectsUnknownOwner(): void
	{
		$this->apps->method('isEnabledForUser')->willReturn(true);
		$broker = $this->createMock(IBroker::class);
		$broker->method('hasBackend')->willReturn(true);
		$this->users->method('get')->with('ghost')->willReturn(null);
		$talk = new TalkIntegration($broker, $this->apps, $this->users,
			$this->createMock(LoggerInterface::class));
		$this->assertNull($talk->createRoom('Room', 'ghost'));
	}
}
