<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use OCA\BookingCheck\Db\Order;
use OCA\BookingCheck\Db\Ticket;

/**
 * NH-30 — wallet pass adapter (demoted MH-25).
 *
 * Google Wallet: fully implemented — the "Save to Wallet" link is a
 * signed JWT (HS256 with the service-account secret from settings).
 * Apple Wallet: .pkpass signing needs the seller's Pass Type ID cert —
 * implemented behind credential presence; without certs the adapter
 * reports unavailable and UI shows PDF+QR only (spec's guaranteed
 * fallback). No dead buttons, ever.
 */
class WalletPassService
{
	public function __construct(
		private readonly SettingsService $settings,
	) {
	}

	public function googleAvailable(): bool
	{
		return $this->settings->get('wallet_google_issuer') !== ''
			&& $this->settings->getSecret('wallet_google_secret') !== '';
	}

	public function appleAvailable(): bool
	{
		return $this->settings->getSecret('wallet_apple_cert_pem') !== ''
			&& $this->settings->get('wallet_apple_pass_type_id') !== ''
			&& $this->settings->get('wallet_apple_team_id') !== '';
	}

	/**
	 * Google "Save to Wallet" URL — a generic-pass JWT signed with the
	 * issuer secret. Returns null when unconfigured (caller hides the CTA).
	 */
	public function googleSaveUrl(Order $order, Ticket $ticket, string $qrPayload, string $title): ?string
	{
		if (!$this->googleAvailable()) {
			return null;
		}
		$issuer = $this->settings->get('wallet_google_issuer');
		$objectId = $issuer . '.bck-' . $ticket->getId();
		$payload = [
			'iss' => $this->settings->get('wallet_google_sa_email') ?: $issuer,
			'aud' => 'google',
			'typ' => 'savetowallet',
			'iat' => time(),
			'payload' => [
				'genericObjects' => [[
					'id' => $objectId,
					'classId' => $issuer . '.bck-event',
					'genericType' => 'GENERIC_TYPE_UNSPECIFIED',
					'cardTitle' => ['defaultValue' => ['language' => 'en', 'value' => $title]],
					'header' => ['defaultValue' => ['language' => 'en', 'value' => $ticket->getAttendeeName() ?: $order->getBuyerName()]],
					'subheader' => ['defaultValue' => ['language' => 'en', 'value' => $order->getRef()]],
					'barcode' => [
						'type' => 'QR_CODE',
						'value' => $qrPayload,
						'alternateText' => $order->getRef(),
					],
				]],
			],
		];
		$jwt = $this->jwtHs256($payload, $this->settings->getSecret('wallet_google_secret'));
		return 'https://pay.google.com/gp/v/save/' . $jwt;
	}

	/**
	 * Apple .pkpass bundle — returns raw zip bytes or null when the
	 * seller cert is not configured. pass.json is generated; manifest +
	 * signature require the uploaded cert/key pair.
	 */
	public function applePass(Order $order, Ticket $ticket, string $qrPayload, string $title): ?string
	{
		if (!$this->appleAvailable()) {
			return null;
		}
		$passTypeId = $this->settings->get('wallet_apple_pass_type_id');
		$teamId = $this->settings->get('wallet_apple_team_id');
		$serial = 'BCK-' . $ticket->getId();
		$pass = [
			'formatVersion' => 1,
			'passTypeIdentifier' => $passTypeId,
			'teamIdentifier' => $teamId,
			'serialNumber' => $serial,
			'organizationName' => $this->settings->get('seller_name') ?: 'BookingCheck',
			'description' => $title,
			'generic' => [
				'primaryFields' => [[
					'key' => 'event', 'label' => 'Booking', 'value' => $title,
				]],
				'secondaryFields' => [[
					'key' => 'attendee', 'label' => 'Attendee',
					'value' => $ticket->getAttendeeName() ?: $order->getBuyerName(),
				]],
			],
			'barcodes' => [[
				'format' => 'PKBarcodeFormatQR',
				'message' => $qrPayload,
				'messageEncoding' => 'iso-8859-1',
				'altText' => $order->getRef(),
			]],
		];
		$passJson = json_encode($pass, JSON_UNESCAPED_SLASHES);
		if ($passJson === false) {
			return null;
		}
		$manifest = ['pass.json' => sha1($passJson)];

		// detached signature over manifest.json with seller cert
		$manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES);
		if ($manifestJson === false) {
			return null;
		}
		$certPem = $this->settings->getSecret('wallet_apple_cert_pem');
		$keyPem = $this->settings->getSecret('wallet_apple_key_pem');
		$sig = null;
		$tmpIn = tempnam(sys_get_temp_dir(), 'bckm'); file_put_contents($tmpIn, $manifestJson);
		$tmpOut = tempnam(sys_get_temp_dir(), 'bcks');
		// wallet_apple_cert_pem may be a PEM bundle: pass-signing cert
		// first, then chain certs (the WWDR intermediate). Without the
		// intermediate embedded in the signature the device cannot build
		// the chain to the Apple root and rejects the pass outright.
		$pemBlocks = [];
		preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
			$certPem, $pemBlocks);
		$blocks = $pemBlocks[0] !== [] ? $pemBlocks[0] : [$certPem];
		$cert = openssl_x509_read($blocks[0]);
		$chainFile = null;
		if (count($blocks) > 1) {
			// extracerts reach openssl_pkcs7_sign as a PEM FILENAME
			$chainFile = tempnam(sys_get_temp_dir(), 'bckc');
			file_put_contents($chainFile, implode("\n", array_slice($blocks, 1)));
		}
		$key = openssl_pkey_get_private($keyPem !== '' ? $keyPem : $certPem);
		$signed = $cert !== false && $key !== false && ($chainFile !== null
			? openssl_pkcs7_sign($tmpIn, $tmpOut, $cert, $key, [], PKCS7_BINARY | PKCS7_DETACHED, $chainFile)
			: openssl_pkcs7_sign($tmpIn, $tmpOut, $cert, $key, [], PKCS7_BINARY | PKCS7_DETACHED));
		if ($chainFile !== null) {
			@unlink($chainFile);
		}
		if ($signed) {
			$raw = file_get_contents($tmpOut);
			// openssl_pkcs7_sign emits a multipart S/MIME message — the
			// .pkpass signature is the base64 body of the smime.p7s part,
			// DER-decoded. A "-----BEGIN PKCS7-----" armor never exists.
			if (is_string($raw)) {
				$body = preg_replace('/\A.*?Content-Disposition:[^\n]*smime\.p7s[^\n]*\n\n/s', '', $raw);
				$body = $body === null ? '' : preg_replace('/-{4,}[^\n]*\n?$/', '', $body);
				$body = $body === null ? '' : $body;
				$der = base64_decode(preg_replace('/\s+/', '', $body) ?? '', true);
				if ($der !== false && $der !== '') {
					$sig = $der;
				}
			}
		}
		@unlink($tmpIn); @unlink($tmpOut);
		if ($sig === null) {
			return null;
		}

		$tmpZip = tempnam(sys_get_temp_dir(), 'bckp');
		$zip = new \ZipArchive();
		if ($zip->open($tmpZip, \ZipArchive::OVERWRITE) !== true) {
			@unlink($tmpZip);
			return null;
		}
		$zip->addFromString('pass.json', $passJson);
		$zip->addFromString('manifest.json', $manifestJson);
		$zip->addFromString('signature', $sig);
		$zip->close();
		$bytes = file_get_contents($tmpZip);
		@unlink($tmpZip);
		return $bytes === false ? null : $bytes;
	}

	private function jwtHs256(array $payload, string $secret): string
	{
		$b64 = fn ($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
		$head = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
		$body = $b64(json_encode($payload, JSON_UNESCAPED_SLASHES));
		return $head . '.' . $body . '.' . $b64(hash_hmac('sha256', $head . '.' . $body, $secret, true));
	}
}
