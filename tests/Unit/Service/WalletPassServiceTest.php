<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Tests\Unit\Service;

use OCA\BookingCheck\Db\Order;
use OCA\BookingCheck\Db\Ticket;
use OCA\BookingCheck\Service\SettingsService;
use OCA\BookingCheck\Service\WalletPassService;
use PHPUnit\Framework\TestCase;

class WalletPassServiceTest extends TestCase
{
	private function service(array $plain = [], array $secrets = []): WalletPassService
	{
		$settings = $this->createMock(SettingsService::class);
		$settings->method('get')->willReturnCallback(
			fn (string $k, $d = null) => $plain[$k] ?? ($d ?? ''));
		$settings->method('getSecret')->willReturnCallback(
			fn (string $k) => $secrets[$k] ?? '');
		return new WalletPassService($settings);
	}

	private function ticket(): Ticket
	{
		$t = new Ticket();
		$t->setId(77);
		$t->setAttendeeName('Ada Lovelace');
		return $t;
	}

	private function order(): Order
	{
		$o = new Order();
		$o->setRef('BCK-2026-0042');
		$o->setBuyerName('Ada Lovelace');
		return $o;
	}

	public function testGoogleSaveUrlNullWhenUnconfigured(): void
	{
		self::assertNull($this->service()->googleSaveUrl(
			$this->order(), $this->ticket(), 'BCK1:77:tok', 'Gala'));
		// issuer set but no secret → still unavailable (fail-closed)
		self::assertNull($this->service(['wallet_google_issuer' => '3388'])->googleSaveUrl(
			$this->order(), $this->ticket(), 'BCK1:77:tok', 'Gala'));
	}

	public function testGoogleSaveUrlCarriesSignedJwtWithTicketData(): void
	{
		$url = $this->service(
			['wallet_google_issuer' => '338800001234', 'wallet_google_sa_email' => 'sa@x.iam'],
			['wallet_google_secret' => 'shh'])
			->googleSaveUrl($this->order(), $this->ticket(), 'BCK1:77:tok', 'Gala Night');
		self::assertNotNull($url);
		self::assertStringStartsWith('https://pay.google.com/gp/v/save/', $url);
		$jwt = substr($url, strlen('https://pay.google.com/gp/v/save/'));
		[$h, $b, $sig] = explode('.', $jwt);
		// header declares HS256
		self::assertSame('HS256', json_decode(base64_decode(strtr($h, '-_', '+/')), true)['alg']);
		$payload = json_decode(base64_decode(strtr($b, '-_', '+/')), true);
		self::assertSame('sa@x.iam', $payload['iss']);
		$obj = $payload['payload']['genericObjects'][0];
		self::assertSame('338800001234.bck-77', $obj['id']);
		self::assertSame('QR_CODE', $obj['barcode']['type']);
		self::assertSame('BCK1:77:tok', $obj['barcode']['value'],
			'wallet barcode must carry the same admission bearer as the printed QR');
		self::assertSame('Gala Night', $obj['cardTitle']['defaultValue']['value']);
		// HMAC verifies against the configured secret
		$expect = rtrim(strtr(base64_encode(
			hash_hmac('sha256', $h . '.' . $b, 'shh', true)), '+/', '-_'), '=');
		self::assertSame($expect, $sig);
	}

	public function testApplePassNullWhenUnconfiguredOrBadCert(): void
	{
		$o = $this->order();
		$t = $this->ticket();
		self::assertNull($this->service()->applePass($o, $t, 'BCK1:77:tok', 'Gala'));
		// garbage PEM must fail closed — null, never an exception to the caller
		$bad = $this->service(
			['wallet_apple_pass_type_id' => 'pass.x', 'wallet_apple_team_id' => 'T1'],
			['wallet_apple_cert_pem' => 'not-a-pem', 'wallet_apple_key_pem' => 'not-a-key']);
		self::assertNull($bad->applePass($o, $t, 'BCK1:77:tok', 'Gala'));
	}

	public function testApplePassBuildsSignedPkpassWithRealCert(): void
	{
		// self-signed cert + key generated in-test — exercises the real
		// openssl_pkcs7_sign + zip path end to end
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		$csr = openssl_csr_new(['commonName' => 'BCK Test'], $key);
		$x509 = openssl_csr_sign($csr, null, $key, 1);
		openssl_x509_export($x509, $certPem);
		openssl_pkey_export($key, $keyPem);
		$svc = $this->service(
			['wallet_apple_pass_type_id' => 'pass.de.test.bck',
			 'wallet_apple_team_id' => 'TEAM123456',
			 'seller_name' => 'Test Venue'],
			['wallet_apple_cert_pem' => $certPem, 'wallet_apple_key_pem' => $keyPem]);
		$bytes = $svc->applePass($this->order(), $this->ticket(), 'BCK1:77:tok', 'Gala Night');
		self::assertNotNull($bytes, 'valid PEM pair must produce a signed pkpass');
		$tmp = tempnam(sys_get_temp_dir(), 'pkt');
		file_put_contents($tmp, $bytes);
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($tmp));
		self::assertNotFalse($zip->getFromName('pass.json'));
		self::assertNotFalse($zip->getFromName('manifest.json'));
		self::assertNotFalse($zip->getFromName('signature'));
		$pass = json_decode($zip->getFromName('pass.json'), true);
		self::assertSame('BCK-77', $pass['serialNumber']);
		self::assertSame('Test Venue', $pass['organizationName']);
		self::assertSame('BCK1:77:tok', $pass['barcodes'][0]['message']);
		$manifest = json_decode($zip->getFromName('manifest.json'), true);
		self::assertSame(sha1($zip->getFromName('pass.json')), $manifest['pass.json']);
		$zip->close();
		@unlink($tmp);
	}

	public function testAppleAvailableRequiresTeamId(): void
	{
		// an empty teamIdentifier produces a pass Apple rejects — the CTA
		// must not appear until the credential set is complete.
		$svc = $this->service(
			['wallet_apple_pass_type_id' => 'pass.x'],
			['wallet_apple_cert_pem' => 'some-pem']);
		self::assertFalse($svc->appleAvailable(), 'team id missing → unavailable');
		self::assertNull($svc->applePass($this->order(), $this->ticket(), 'BCK1:77:tok', 'Gala'));
	}

	public function testApplePassSignsWithChainFromPemBundle(): void
	{
		// seller pastes a PEM bundle: pass cert first, then the WWDR
		// intermediate — the chain file must reach openssl_pkcs7_sign so
		// the device can build the path to the Apple root.
		$mkCert = function (string $cn): array {
			$key = openssl_pkey_new(['private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA]);
			$csr = openssl_csr_new(['commonName' => $cn], $key);
			$x509 = openssl_csr_sign($csr, null, $key, 1);
			openssl_x509_export($x509, $pem);
			return [$pem, $key];
		};
		[$passCertPem, $passKey] = $mkCert('Pass Cert');
		[$wwdrPem] = $mkCert('Apple WWDR-like Intermediate');
		openssl_pkey_export($passKey, $passKeyPem);

		$svc = $this->service(
			['wallet_apple_pass_type_id' => 'pass.de.test.bck',
			 'wallet_apple_team_id' => 'TEAM123456'],
			['wallet_apple_cert_pem' => $passCertPem . "\n" . $wwdrPem,
			 'wallet_apple_key_pem' => $passKeyPem]);
		$bytes = $svc->applePass($this->order(), $this->ticket(), 'BCK1:77:tok', 'Gala');
		self::assertNotNull($bytes, 'bundle signing must succeed end to end');
		$tmp = tempnam(sys_get_temp_dir(), 'pkt');
		file_put_contents($tmp, $bytes);
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($tmp));
		$sig = $zip->getFromName('signature');
		self::assertNotFalse($sig);
		// DER-signed content embeds both certs: decode and count CERTIFICATE
		// oids — the WWDR CN must appear in the signature blob.
		self::assertStringContainsString('WWDR', $sig,
			'chain certs must be embedded in the detached signature');
		$zip->close();
		@unlink($tmp);
	}
}
