<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Tests\Unit\Service;

use OCA\BookingCheck\Service\SettingsService;
use OCA\BookingCheck\Service\ViesService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

final class ViesServiceTest extends TestCase
{
	private SettingsService&MockObject $settings;
	private IClientService&MockObject $http;

	protected function setUp(): void
	{
		$this->settings = $this->createMock(SettingsService::class);
		$this->http = $this->createMock(IClientService::class);
	}

	private function svc(): ViesService
	{
		return new ViesService($this->http, $this->settings,
			$this->createMock(LoggerInterface::class));
	}

	private function online(): void
	{
		$this->settings->method('get')->with('vies_online')->willReturn('1');
	}

	private function httpReturns(array $payload): void
	{
		$resp = $this->createMock(IResponse::class);
		$resp->method('getBody')->willReturn(json_encode($payload));
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturn($resp);
		$this->http->method('newClient')->willReturn($client);
	}

	public function testEuCountryList(): void
	{
		self::assertTrue(ViesService::isEuCountry('DE'));
		self::assertTrue(ViesService::isEuCountry('fr')); // case-insensitive
		self::assertTrue(ViesService::isEuCountry('XI')); // NI protocol
		self::assertFalse(ViesService::isEuCountry('US'));
		self::assertFalse(ViesService::isEuCountry('GB')); // post-Brexit
		self::assertFalse(ViesService::isEuCountry('CH'));
	}

	public function testNonEuCountryIsInvalidWithoutAnyHttp(): void
	{
		$this->online();
		$this->http->expects(self::never())->method('newClient');
		self::assertSame('invalid', $this->svc()->verify('US', 'US12345678'));
	}

	public function testOfflineModeNeverBlocksCheckout(): void
	{
		$this->settings->method('get')->with('vies_online')->willReturn('0');
		$this->http->expects(self::never())->method('newClient');
		self::assertSame('unchecked', $this->svc()->verify('FR', 'FR12345678901'));
	}

	public function testValidVerdict(): void
	{
		$this->online();
		$this->httpReturns(['valid' => true]);
		self::assertSame('valid', $this->svc()->verify('FR', 'FR12345678901'));
	}

	public function testInvalidVerdict(): void
	{
		$this->online();
		$this->httpReturns(['valid' => false]);
		self::assertSame('invalid', $this->svc()->verify('FR', 'FR00000000000'));
	}

	public function testMalformedReplyDegradesToUnchecked(): void
	{
		$this->online();
		$this->httpReturns(['unexpected' => 'shape']);
		self::assertSame('unchecked', $this->svc()->verify('FR', 'FR12345678901'));
	}

	public function testNetworkFailureDegradesToUnchecked(): void
	{
		$this->online();
		$client = $this->createMock(IClient::class);
		$client->method('post')->willThrowException(new \RuntimeException('dns'));
		$this->http->method('newClient')->willReturn($client);
		self::assertSame('unchecked', $this->svc()->verify('FR', 'FR12345678901'));
	}

	public function testStripsCountryPrefixForWireCall(): void
	{
		$this->online();
		$resp = $this->createMock(IResponse::class);
		$resp->method('getBody')->willReturn('{"valid":true}');
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())->method('post')
			->with(self::anything(), self::callback(
				fn(array $opts) => $opts['json']['countryCode'] === 'FR'
					&& $opts['json']['vatNumber'] === '12345678901'))
			->willReturn($resp);
		$this->http->method('newClient')->willReturn($client);
		$this->svc()->verify('FR', 'FR12345678901');
	}
}
