<?php

declare(strict_types=1);

/**
 * Settings → Transfer configuration (test → production).
 *
 * The section body is the synced config-transfer partial — kept verbatim
 * under templates/parts/settings/ and included here so the literal
 * slug → file dispatcher in templates/settings.php stays intact.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

include __DIR__ . '/../parts/settings/transfer.php';
