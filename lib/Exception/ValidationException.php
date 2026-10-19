<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Exception;

use Exception;

/** Carries a field→message map; serializes to the canonical 400 envelope. */
class ValidationException extends Exception
{
	public function __construct(private array $fields, string $message = 'Validation failed')
	{
		parent::__construct($message, 400);
	}

	public function getFields(): array
	{
		return $this->fields;
	}
}
