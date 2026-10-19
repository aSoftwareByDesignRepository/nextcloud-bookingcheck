<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Exception;

use Exception;

/**
 * Canonical "does not exist OR is not yours" signal.
 * The serialization boundary collapses every variant into one uniform payload.
 */
class NotFoundException extends Exception
{
	public function __construct(string $message = 'Not found')
	{
		parent::__construct($message, 404);
	}
}
