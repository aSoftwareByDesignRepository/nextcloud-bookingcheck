<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Exception;

use Exception;

/** Illegal state transition or optimistic-concurrency (CAS) miss. */
class StateConflictException extends Exception
{
	public function __construct(string $message = 'Conflict', private string $codeName = 'CONFLICT')
	{
		parent::__construct($message, 409);
	}

	public function getCodeName(): string
	{
		return $this->codeName;
	}
}
