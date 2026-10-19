<?php

declare(strict_types=1);

namespace OCA\BookingCheck\Exception;

use Exception;

/** PSP adapter failure (network, auth, malformed payload). Never leaks secrets. */
class PspException extends Exception
{
}
