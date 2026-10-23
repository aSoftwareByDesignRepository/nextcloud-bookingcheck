<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Service;

use OCA\BookingCheck\Service\PushService;
use OCA\BookingCheck\Service\SettingsService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * l10n_unlocalized_error_surface — Expo renders notification title/body
 * verbatim on the lock screen, so payloads must be resolved in each
 * recipient's language: the device's registered locale, else the shop's
 * configured default_locale — never hardcoded English.
 */
final class PushServiceTest extends TestCase
{
	private IClient&MockObject $client;
	private IFactory&MockObject $l10nFactory;
	private SettingsService&MockObject $settings;
	/** @var list<array<string,mixed>> */
	private array $rows = [];
	/** @var list<list<array<string,mixed>>> */
	private array $sentBatches = [];

	protected function setUp(): void
	{
		parent::setUp();

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('x=y');
		$expr->method('isNull')->willReturn('x IS NULL');
		$expr->method('isNotNull')->willReturn('x IS NOT NULL');

		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturnCallback(fn () => $this->rows);

		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			fn ($v) => ':' . (string)$v);
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$this->client = $this->createMock(IClient::class);
		$this->sentBatches = [];
		$this->client->method('post')->willReturnCallback(
			function (string $url, array $opts) {
				$this->sentBatches[] = json_decode((string)$opts['body'], true);
				return $this->createMock(\OCP\Http\Client\IResponse::class);
			});
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->client);

		$this->l10nFactory = $this->createMock(IFactory::class);
		$this->l10nFactory->method('get')->willReturnCallback(
			function (string $app, string $locale) {
				$l = $this->createMock(IL10N::class);
				// render as <locale>:<msgid> so assertions prove routing
				$l->method('t')->willReturnCallback(
					fn (string $text, array $params = []) => vsprintf("$locale:$text", $params));
				return $l;
			});

		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('get')->willReturnCallback(
			fn (string $k) => $k === 'default_locale' ? 'fr' : '');

		$this->service = new PushService(
			$clientService, $db,
			$this->createMock(LoggerInterface::class),
			$this->l10nFactory, $this->settings);
	}

	private PushService $service;

	public function testFanOutPerRecipientLocale(): void
	{
		$this->rows = [
			['push_token' => 'TOK_DE', 'locale' => 'de'],
			['push_token' => 'TOK_LEGACY', 'locale' => null],
			['push_token' => 'TOK_EN', 'locale' => 'en'],
		];
		$this->service->notifyManagers('order.paid', ['order_ref' => 'BCK-1']);

		$byToken = [];
		foreach ($this->sentBatches as $batch) {
			foreach ($batch as $msg) {
				$byToken[$msg['to']] = $msg;
			}
		}
		self::assertSame('de:New paid booking', $byToken['TOK_DE']['title']);
		self::assertSame('de:Order BCK-1 paid.', $byToken['TOK_DE']['body']);
		// legacy row (no reported locale) → shop default_locale 'fr'
		self::assertSame('fr:New paid booking', $byToken['TOK_LEGACY']['title']);
		self::assertSame('fr:Order BCK-1 paid.', $byToken['TOK_LEGACY']['body']);
		self::assertSame('en:New paid booking', $byToken['TOK_EN']['title']);
		// three locales → three sends (grouped, not per-device)
		self::assertCount(3, $this->sentBatches);
	}

	public function testCancellationAndReportCopyIsLocalized(): void
	{
		$this->rows = [['push_token' => 'TOK_DE', 'locale' => 'de']];
		$this->service->notifyManagers('order.refunded', ['order_ref' => 'BCK-9']);
		$msg = $this->sentBatches[0][0];
		self::assertSame('de:Refund issued', $msg['title']);
		self::assertSame('de:Order BCK-9 was refunded.', $msg['body']);

		$this->sentBatches = [];
		$this->service->notifyManagers('abuse.reported', []);
		$msg = $this->sentBatches[0][0];
		self::assertSame('de:Content report', $msg['title']);
		self::assertSame('de:An attendee reported shared content for review.', $msg['body']);
	}

	public function testSendFailureIsSilentAndNoTargetsSendNothing(): void
	{
		$this->rows = [];
		$this->service->notifyManagers('order.paid', ['order_ref' => 'BCK-1']);
		self::assertSame([], $this->sentBatches);

		$this->rows = [['push_token' => 'TOK_EN', 'locale' => 'en']];
		$this->client->method('post')->willThrowException(new \RuntimeException('dead endpoint'));
		$this->service->notifyManagers('order.paid', ['order_ref' => 'BCK-1']); // must not throw
		self::assertTrue(true);
	}
}
