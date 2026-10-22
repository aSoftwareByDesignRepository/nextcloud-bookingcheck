<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Controller;

use OCA\BookingCheck\Db\OfferingMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\IRequest;

/**
 * Iframe embed — frame-ancestors comes from the offering's own
 * embed_domains allowlist (NULL = not embeddable, X-Frame-Options DENY).
 */
class EmbedController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OfferingMapper $offerings,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function offering(string $slug): PublicTemplateResponse
	{
		$o = $this->offerings->findBySlug($slug);
		$domains = $o !== null ? json_decode((string)$o->getEmbedDomains(), true) : null;
		if ($o === null || $o->getStatus() !== 'published' || !is_array($domains) || $domains === []) {
			$resp = \OCA\BookingCheck\Support\PublicNotFound::render([]);
			$resp->addHeader('X-Frame-Options', 'DENY');
			return $resp;
		}
		$resp = new PublicTemplateResponse($this->appName, 'public/embed', [
			// whitelist — never jsonSerialize: talkRoom/deckBoardId are
			// internal refs, and embed.php reads 'price_cents' (the full
			// serialize has no such key — the badge silently showed 0)
			'offering' => [
				'slug' => $o->getSlug(),
				'title' => $o->getTitle(),
				'price_cents' => (int)$o->getPriceCents(),
				'currency' => $o->getCurrency(),
			],
		]);
		// CSP frame-ancestors is the enforceable boundary
		$resp->addHeader('Content-Security-Policy',
			"frame-ancestors " . implode(' ', array_map([self::class, 'origin'], $domains)));
		return $resp;
	}

	/**
	 * NH-05 — serve the WebComponent SDK (`<bck-booking>`). Public static
	 * asset: anonymous, cacheable, read from the app bundle at request
	 * time so a shipped file fix propagates on the next cache window.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function sdk(): \OCP\AppFramework\Http\DataDisplayResponse
	{
		$file = __DIR__ . '/../../js/sdk/bck-embed.js';
		$js = is_file($file) ? file_get_contents($file) : false;
		if ($js === false) {
			throw new \OCA\BookingCheck\Exception\NotFoundException();
		}
		$resp = new \OCP\AppFramework\Http\DataDisplayResponse($js);
		$resp->addHeader('Content-Type', 'application/javascript; charset=utf-8');
		// short cache — the SDK may need hotfixes; embed sites must pick them up
		$resp->addHeader('Cache-Control', 'public, max-age=300');
		$resp->addHeader('X-Content-Type-Options', 'nosniff');
		return $resp;
	}

	private static function origin(mixed $d): string
	{
		// only scheme://host(:port) survives — paths/userinfo stripped
		if (!is_string($d)) {
			return "'none'";
		}
		if (preg_match('#^https?://[a-z0-9.\-:*]+$#i', $d)) {
			return $d;
		}
		return "'none'";
	}
}
