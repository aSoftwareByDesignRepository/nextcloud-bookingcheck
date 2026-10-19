<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Exception;

/** Sold out / insufficient capacity — 409 with a stable machine code. */
class CapacityException extends StateConflictException
{
	public function __construct(string $message = 'No seats left')
	{
		parent::__construct($message, 'SOLD_OUT');
	}
}
