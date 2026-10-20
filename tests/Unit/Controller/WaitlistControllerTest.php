<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Controller;

use OCA\BookingCheck\Controller\WaitlistController;
use OCA\BookingCheck\Db\WaitlistEntry;
use OCA\BookingCheck\Db\WaitlistEntryMapper;
use OCA\BookingCheck\Service\WaitlistService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/** The waitlist admin list must ship only the keys the template reads —
 *  jsonSerialize() would leak the live claimTokenHash credential and its
 *  camelCase keys must match the template exactly. */
final class WaitlistControllerTest extends TestCase
{
	private WaitlistEntryMapper&MockObject $waitlist;
	private WaitlistController $ctrl;

	protected function setUp(): void
	{
		parent::setUp();
		$this->waitlist = $this->createMock(WaitlistEntryMapper::class);
		$this->ctrl = new WaitlistController(
			'bookingcheck',
			$this->createMock(IRequest::class),
			$this->waitlist,
			$this->createMock(WaitlistService::class),
		);
	}

	private function entry(): WaitlistEntry
	{
		$e = new WaitlistEntry();
		$e->setId(7);
		$e->setTargetType('slot');
		$e->setTargetId(42);
		$e->setEmail('guest@example.org');
		$e->setLocale('de');
		$e->setState('waiting');
		$e->setClaimTokenHash('deadbeef-live-claim-credential');
		$e->setCreatedAt(new \DateTimeImmutable('2026-10-27T10:00:00+00:00'));
		return $e;
	}

	public function testIndexPayloadMatchesTemplateKeysAndDropsClaimToken(): void
	{
		$this->waitlist->method('findAll')->willReturn([$this->entry()]);
		$params = $this->ctrl->index()->getParams();
		$row = $params['entries'][0];

		// every key templates/admin/waitlist.php reads must exist
		foreach (['id', 'targetType', 'targetId', 'email', 'locale',
				'state', 'createdAt', 'offerExpiresAt'] as $key) {
			$this->assertArrayHasKey($key, $row, "template reads \${$key}");
		}
		$this->assertSame('slot', $row['targetType']);
		$this->assertSame(42, $row['targetId']);
		// a live claim credential must never reach a rendered template
		$this->assertArrayNotHasKey('claimTokenHash', $row);
	}
}
