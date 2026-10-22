<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\BookingCheck\Exception;

/**
 * Whole-app door deny: the logged-in user fails
 * {@see \OCA\BookingCheck\Service\AccessControlService::canUseApp()}.
 * Mapped to a uniform 404 — a restricted app must be indistinguishable
 * from an absent one (restricted_door_status_oracle class; a 403 or a
 * friendly "needs role" page leaks "installed but you are excluded").
 * Distinct from {@see UnauthorizedException}, which covers in-app
 * role/auth failures an entitled user may legitimately see.
 */
final class AppDoorDeniedException extends \Exception
{
}
