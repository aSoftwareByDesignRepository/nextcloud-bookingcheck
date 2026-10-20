<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Service;

use OCA\BookingCheck\Db\CompanionToken;
use OCA\BookingCheck\Db\CompanionTokenMapper;
use OCA\BookingCheck\Service\DoorSessionService;
use OCA\BookingCheck\Service\TokenService;
use OCP\ISession;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * Door sessions are the web scanner's least-privilege entry: a companion
 * token binds to the cookie once, and every later request re-reads the
 * device row so revocation/re-role takes effect immediately.
 */
final class DoorSessionServiceTest extends TestCase
{
	private ISession&MockObject $session;
	private CompanionTokenMapper&MockObject $devices;
	private DoorSessionService $svc;

	protected function setUp(): void
	{
		parent::setUp();
		$this->session = $this->createMock(ISession::class);
		$this->devices = $this->createMock(CompanionTokenMapper::class);
		$this->svc = new DoorSessionService(
			$this->session, $this->devices, new TokenService());
	}

	private function device(string $role = 'door', bool $revoked = false, ?string $scope = null): CompanionToken
	{
		$d = new CompanionToken();
		$d->setId(3);
		$d->setRole($role);
		$d->setOfferingIds($scope);
		if ($revoked) {
			$d->setRevokedAt(new \DateTimeImmutable());
		}
		return $d;
	}

	public function testOpenStoresDeviceIdForGoodToken(): void
	{
		$hash = (new TokenService())->hash('good-token');
		$this->devices->method('findByTokenHash')->with($hash)->willReturn($this->device());
		$this->devices->expects(self::once())->method('touch')->with(3);
		$this->session->expects(self::once())->method('set')
			->with(DoorSessionService::SESSION_KEY, 3);
		self::assertTrue($this->svc->open('good-token'));
	}

	public function testOpenRejectsUnknownRevokedAndWrongRoleUniformly(): void
	{
		$this->session->expects(self::never())->method('set');
		$this->devices->expects(self::never())->method('touch');

		$this->devices->method('findByTokenHash')->willReturnOnConsecutiveCalls(
			null, $this->device('door', true), $this->device('buyer'));
		self::assertFalse($this->svc->open('a'));
		self::assertFalse($this->svc->open('b'));
		self::assertFalse($this->svc->open('c'));
		self::assertFalse($this->svc->open('')); // empty never even queries…
	}

	public function testDeviceRevalidatesEveryRequest(): void
	{
		$this->session->method('get')->with(DoorSessionService::SESSION_KEY)->willReturn(3);
		$this->devices->method('find')->with(3)->willReturn($this->device());
		self::assertSame(3, (int)$this->svc->device()?->getId());
	}

	public function testDeviceRevokedMidSessionClearsAndRefuses(): void
	{
		$this->session->method('get')->willReturn(3);
		$this->devices->method('find')->willReturn($this->device('door', true));
		$this->session->expects(self::once())->method('remove')
			->with(DoorSessionService::SESSION_KEY);
		self::assertNull($this->svc->device());
	}

	public function testDeviceMissingRowClearsAndRefuses(): void
	{
		$this->session->method('get')->willReturn(3);
		$this->devices->method('find')->willReturn(null);
		$this->session->expects(self::once())->method('remove');
		self::assertNull($this->svc->device());
	}

	public function testDeviceWithoutSessionIsNull(): void
	{
		$this->session->method('get')->willReturn(null);
		$this->devices->expects(self::never())->method('find');
		self::assertNull($this->svc->device());
	}

	public function testScopeEmptyAllowsAll(): void
	{
		foreach ([null, ''] as $scope) {
			self::assertTrue($this->svc->scopeAllows($this->device('door', false, $scope), 99));
		}
	}

	public function testScopeListMembership(): void
	{
		$d = $this->device('door', false, '[7, 12]');
		self::assertTrue($this->svc->scopeAllows($d, 7));
		self::assertTrue($this->svc->scopeAllows($d, 12));
		self::assertFalse($this->svc->scopeAllows($d, 8));
	}

	public function testScopeMalformedFailsClosed(): void
	{
		// a corrupt scope row means "no offerings", never "all"
		foreach (['{bad', '"7"', '7', '["a"]'] as $scope) {
			self::assertFalse(
				$this->svc->scopeAllows($this->device('door', false, $scope), 7),
				"scope {$scope} must fail closed");
		}
	}
}
