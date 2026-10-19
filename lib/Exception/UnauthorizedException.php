<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Exception;

use Exception;

class UnauthorizedException extends Exception
{
	public function __construct(string $message = 'Unauthorized')
	{
		parent::__construct($message, 401);
	}
}
