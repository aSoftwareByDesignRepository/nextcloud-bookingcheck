<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Deliberately NOT the test namespace: AppAccessMiddleware gates on
// get_class() prefix, so the fixture must live in the controller
// namespace to exercise the real gate.
namespace OCA\BookingCheck\Controller;

use OCP\AppFramework\Controller;

class FakeAdminController extends Controller
{
}
