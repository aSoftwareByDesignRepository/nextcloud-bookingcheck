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
	public function offering(string $slug)
	{
		$o = $this->offerings->findBySlug($slug);
		$domains = $o !== null ? json_decode((string)$o->getEmbedDomains(), true) : null;
		if ($o === null || $o->getStatus() !== 'published' || !is_array($domains) || $domains === []) {
			$resp = new PublicTemplateResponse($this->appName, 'public/not-found', []);
			$resp->addHeader('X-Frame-Options', 'DENY');
			return $resp;
		}
		$resp = new PublicTemplateResponse($this->appName, 'public/embed', [
			'offering' => $o->jsonSerialize(),
		]);
		// CSP frame-ancestors is the enforceable boundary
		$resp->addHeader('Content-Security-Policy',
			"frame-ancestors " . implode(' ', array_map([self::class, 'origin'], $domains)));
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
