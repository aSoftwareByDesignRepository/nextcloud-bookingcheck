<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Controller;

use OCA\BookingCheck\Controller\OfferingController;
use OCA\BookingCheck\Db\Offering;
use OCA\BookingCheck\Db\OfferingMapper;
use OCA\BookingCheck\Exception\NotFoundException;
use OCA\BookingCheck\Exception\ValidationException;
use OCA\BookingCheck\Integration\DeckIntegration;
use OCA\BookingCheck\Integration\TalkIntegration;
use OCA\BookingCheck\Service\AuditService;
use OCA\BookingCheck\Service\LicenseService;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/** offerings-cannot-be-published — the lifecycle endpoints existed but no UI
 *  reached them. These tests pin the server-side contract the admin buttons
 *  now call: the legal transition map and the duplicate postconditions. */
final class OfferingLifecycleControllerTest extends TestCase
{
	private OfferingMapper&MockObject $offerings;
	private AuditService&MockObject $audit;
	private IRequest&MockObject $request;
	private OfferingController $ctrl;

	protected function setUp(): void
	{
		parent::setUp();
		$this->offerings = $this->createMock(OfferingMapper::class);
		$this->audit = $this->createMock(AuditService::class);
		$this->request = $this->createMock(IRequest::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('staff1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->ctrl = new OfferingController(
			'bookingcheck',
			$this->request,
			$this->offerings,
			$this->audit,
			$session,
			$this->createMock(DeckIntegration::class),
			$this->createMock(TalkIntegration::class),
			$this->createMock(LicenseService::class),
			$this->createMock(IDBConnection::class),
		);
	}

	private function offeringWithStatus(string $status): Offering
	{
		$o = new Offering();
		$o->setId(7);
		$o->setStatus($status);
		$o->setTitle('Morning ride');
		$o->setSlug('morning-ride');
		return $o;
	}

	public static function legalTransitions(): array
	{
		return [
			'draft → published' => ['draft', 'published'],
			'published → draft' => ['published', 'draft'],
			'published → archived' => ['published', 'archived'],
			'archived → draft' => ['archived', 'draft'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('legalTransitions')]
	public function testLegalTransitionPersistsAndAudits(string $from, string $to): void
	{
		$o = $this->offeringWithStatus($from);
		$this->offerings->method('find')->with(7)->willReturn($o);
		$this->request->method('getParam')->with('to', '')->willReturn($to);
		$this->offerings->expects(self::once())->method('update')
			->with(self::callback(fn (Offering $u) => $u->getStatus() === $to));
		$this->audit->expects(self::once())->method('log')
			->with(null, 7, 'staff1', 'offering.transition', $from, $to);

		$resp = $this->ctrl->transition(7);
		$body = $resp->getData();
		self::assertTrue($body['success']);
		self::assertSame($to, $body['data']['status']);
		self::assertSame($to, $o->getStatus());
	}

	public static function illegalTransitions(): array
	{
		return [
			'draft → archived' => ['draft', 'archived'],
			'draft → draft' => ['draft', 'draft'],
			'published → published' => ['published', 'published'],
			'archived → published' => ['archived', 'published'],
			'archived → archived' => ['archived', 'archived'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('illegalTransitions')]
	public function testIllegalTransitionRejectedAndUnchanged(string $from, string $to): void
	{
		$o = $this->offeringWithStatus($from);
		$this->offerings->method('find')->willReturn($o);
		$this->request->method('getParam')->with('to', '')->willReturn($to);
		$this->offerings->expects(self::never())->method('update');

		try {
			$this->ctrl->transition(7);
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertSame($from, $o->getStatus()); // fail closed, no half-write
		}
	}

	public function testTransitionUnknownIdThrowsNotFound(): void
	{
		$this->offerings->method('find')->willReturn(null);
		$this->request->method('getParam')->willReturn('published');
		$this->expectException(NotFoundException::class);
		$this->ctrl->transition(404);
	}

	public function testDuplicateInsertsDraftCopyAndReturnsNewId(): void
	{
		$src = $this->offeringWithStatus('published');
		$src->setType('event');
		$src->setPriceCents(4900);
		$src->setCurrency('EUR');
		$src->setDeckBoardId(42);
		$src->setTalkRoom('room-abc');
		$src->setEolState('closing');
		$this->offerings->method('find')->with(7)->willReturn($src);
		// QBMapper::insert() writes only dirty fields — the copy must mark
		// every copied column dirty or SQLSTATE 1364 fires on `type`.
		$this->offerings->expects(self::once())->method('insert')
			->with(self::callback(fn (Offering $n) =>
				$n->getStatus() === 'draft'
				&& $n->getTitle() === 'Morning ride (copy)'
				&& $n->getType() === 'event'
				&& $n->getPriceCents() === 4900
				&& $n->getCurrency() === 'EUR'
				&& $n->getDeckBoardId() === null
				&& $n->getTalkRoom() === null
				&& $n->getEolState() === 'none'
				&& $n->getSlug() !== $src->getSlug()));
		$this->audit->expects(self::once())->method('log')
			->with(null, self::anything(), 'staff1', 'offering.duplicated', '7');

		$resp = $this->ctrl->duplicate(7);
		$body = $resp->getData();
		self::assertTrue($body['success']);
		self::assertSame(201, $resp->getStatus());
		self::assertArrayHasKey('id', $body['data']);
	}

	public function testDuplicateUnknownIdThrowsNotFound(): void
	{
		$this->offerings->method('find')->willReturn(null);
		$this->expectException(NotFoundException::class);
		$this->ctrl->duplicate(404);
	}
}
