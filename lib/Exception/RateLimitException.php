<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Exception;

use Exception;

class RateLimitException extends Exception
{
	public function __construct(string $message = 'Too many requests')
	{
		parent::__construct($message, 429);
	}
}
