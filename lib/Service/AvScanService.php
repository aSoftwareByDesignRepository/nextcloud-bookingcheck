<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Service;

use Psr\Log\LoggerInterface;

/**
 * NH-13 / NN-21 — antivirus scan hook for attendee uploads.
 *
 * Contract: when `av_enabled` the app MUST hand bytes to the NC
 * files_antivirus app (ICAP/daemon) before the row becomes visible.
 * Fail-CLOSED: a configured-but-unreachable AV refuses the upload —
 * the whole point is that a dead daemon cannot silently disable the
 * control. When the admin has NOT enabled AV, the allowlist+sniff
 * baseline applies and files stay av_state=none.
 */
class AvScanService
{
	public function __construct(
		private readonly SettingsService $settings,
		private readonly \OCP\App\IAppManager $apps,
		private readonly LoggerInterface $logger,
	) {
	}

	public function configured(): bool
	{
		return $this->settings->getBool('av_enabled');
	}

	/**
	 * @return 'clean'|'infected'|'unscanned'
	 *   'unscanned' is returned ONLY when AV is enabled but the engine
	 *   errored — callers must treat it as refuse, never as clean.
	 */
	public function scan(string $tmpPath, string $mime): string
	{
		if (!$this->configured()) {
			return 'none';
		}
		$icap = $this->icapEndpoint();
		if ($icap === null) {
			$this->logger->error('bookingcheck: av_enabled but no ICAP endpoint configured');
			return 'unscanned';
		}
		[$host, $port] = $icap;
		return $this->icapScan($host, $port, $tmpPath);
	}

	/** @return array{0:string,1:int}|null */
	private function icapEndpoint(): ?array
	{
		$raw = trim($this->settings->get('av_icap_endpoint'));
		if ($raw === '') {
			// fall back to the files_antivirus app config when it exists
			if (!$this->apps->isEnabledForUser('files_antivirus')) {
				return null;
			}
			$raw = '127.0.0.1:1344'; // ICAP default — files_antivirus own host is admin-side
		}
		if (!preg_match('/^[A-Za-z0-9.\-]+:(\d{1,5})$/', $raw, $m)) {
			return null;
		}
		return [$raw === '' ? '127.0.0.1' : strstr($raw, ':', true), min(65535, (int)$m[1])];
	}

	/** Minimal ICAP RESPMOD — sends the file, verdict from response line. */
	private function icapScan(string $host, int $port, string $path): string
	{
		$data = @file_get_contents($path);
		if ($data === false) {
			return 'unscanned';
		}
		$errno = 0; $err = '';
		$fp = @fsockopen($host, $port, $errno, $err, 5);
		if ($fp === false) {
			$this->logger->error('bookingcheck: ICAP connect failed', ['error' => $err]);
			return 'unscanned';
		}
		stream_set_timeout($fp, 10);
		$len = strlen($data);
		$head = "RESPMOD icap://{$host}:{$port}/avscan ICAP/1.0\r\n"
			. "Host: {$host}\r\n"
			. "Allow: 204\r\n"
			. "Encapsulated: res-hdr=0, res-body=0\r\n"
			. "\r\n"
			. "HTTP/1.1 200 OK\r\nContent-Length: {$len}\r\n\r\n";
		fwrite($fp, $head . dechex($len) . "\r\n" . $data . "\r\n0\r\n\r\n");
		$resp = '';
		while (!feof($fp) && strlen($resp) < 65536) {
			$resp .= fread($fp, 8192);
		}
		fclose($fp);
		if ($resp === '') {
			return 'unscanned';
		}
		// ICAP/1.0 403 + X-Virus-ID header = infected; 204 = clean
		if (preg_match('/X-Virus-ID|VirusFound|403 Forbidden/i', $resp)) {
			return 'infected';
		}
		if (preg_match('#ICAP/1\.0 (204|200)#', $resp)) {
			return 'clean';
		}
		return 'unscanned';
	}
}
