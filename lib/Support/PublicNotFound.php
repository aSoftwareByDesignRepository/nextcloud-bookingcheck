<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Support;

use OCA\BookingCheck\AppInfo\Application;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;

/**
 * Public "nothing here" page — uniform body for gone/mistyped/foreign
 * links AND a real 404 status. Serving 200 tells crawlers and caches the
 * dead link is a live page; the template's recovery copy only works for
 * humans if machines get the right code too.
 */
final class PublicNotFound
{
	/** @param array<string,mixed> $params */
	public static function render(array $params = []): PublicTemplateResponse
	{
		$resp = new PublicTemplateResponse(Application::APP_ID, 'public/not-found', $params);
		$resp->setStatus(Http::STATUS_NOT_FOUND);
		return $resp;
	}
}
