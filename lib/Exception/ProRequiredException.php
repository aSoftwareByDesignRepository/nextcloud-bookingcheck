<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Exception;

/**
 * A Pro-gated feature was invoked on the free tier. Serializes to
 * 403 PRO_REQUIRED for APIs; page navigations redirect to the upsell.
 */
class ProRequiredException extends \Exception
{
	public function __construct(private readonly string $feature = '')
	{
		parent::__construct('This feature requires BookingCheck Pro.');
	}

	public function getFeature(): string
	{
		return $this->feature;
	}
}
