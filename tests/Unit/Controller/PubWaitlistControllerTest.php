<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Controller;

use OCA\BookingCheck\Controller\PubWaitlistController;
use OCA\BookingCheck\Exception\ValidationException;
use OCA\BookingCheck\Service\WaitlistService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/** The join endpoint must stay uniform-202 for every business outcome
 *  (joined, deduped, unjoinable target) — existence oracle protection.
 *  Malformed input shape is different: it is a client contract error and
 *  must 400, never fake-accept a join that stored nothing. */
final class PubWaitlistControllerTest extends TestCase
{
	private IRequest&MockObject $request;
	private WaitlistService&MockObject $waitlist;
	private PubWaitlistController $ctrl;

	protected function setUp(): void
	{
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->waitlist = $this->createMock(WaitlistService::class);
		$this->ctrl = new PubWaitlistController(
			'bookingcheck', $this->request, $this->waitlist);
	}

	private function params(array $p): void
	{
		$this->request->method('getParam')->willReturnCallback(
			fn (string $k, $d = null) => $p[$k] ?? $d);
	}

	public function testValidJoinAlways202CallsService(): void
	{
		$this->params(['target_type' => 'event', 'target_id' => '7',
			'email' => '  Guest@Example.Org ', 'locale' => 'de']);
		$this->waitlist->expects(self::once())->method('join')
			->with('event', 7, 'guest@example.org', 'de')
			->willReturn(true);
		$r = $this->ctrl->join();
		self::assertSame(202, $r->getStatus());
		self::assertTrue($r->getData()['success']);
	}

	public function testBusinessDeclineStillUniform202(): void
	{
		// service returns false (duplicate / unpublished / wl disabled):
		// response stays identical — no existence oracle
		$this->params(['target_type' => 'event', 'target_id' => '7',
			'email' => 'a@b.org']);
		$this->waitlist->expects(self::once())->method('join')
			->willReturn(false);
		$r = $this->ctrl->join();
		self::assertSame(202, $r->getStatus());
	}

	public function testBadEmailIs400NotFakeAccept(): void
	{
		$this->params(['target_type' => 'event', 'target_id' => '7',
			'email' => 'not-an-email']);
		$this->waitlist->expects(self::never())->method('join');
		$this->expectException(ValidationException::class);
		try {
			$this->ctrl->join();
		} catch (ValidationException $e) {
			self::assertArrayHasKey('email', $e->getFields());
			throw $e;
		}
	}

	public function testBadTargetTypeIs400(): void
	{
		$this->params(['target_type' => 'bogus', 'target_id' => '7',
			'email' => 'a@b.org']);
		$this->waitlist->expects(self::never())->method('join');
		$this->expectException(ValidationException::class);
		try {
			$this->ctrl->join();
		} catch (ValidationException $e) {
			self::assertArrayHasKey('target_id', $e->getFields());
			throw $e;
		}
	}

	public function testNonNumericTargetIdIs400(): void
	{
		$this->params(['target_type' => 'event', 'target_id' => 'abc',
			'email' => 'a@b.org']);
		$this->waitlist->expects(self::never())->method('join');
		$this->expectException(ValidationException::class);
		$this->ctrl->join();
	}
}
