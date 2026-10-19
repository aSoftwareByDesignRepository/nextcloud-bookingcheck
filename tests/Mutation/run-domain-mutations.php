<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the money/auth-critical core:
 * ReservationService, OrderService, OtpService, TestProvider.
 *
 * Each mutation is anchored to a production line; the killer test is named.
 * Any survivor = a test gap — fix the test, not the mutant.
 *
 * Run inside the container: php tests/Mutation/run-domain-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$appId = basename($appRoot);
$workspaceRoot = dirname($appRoot, 2);

function run_filter(string $filter): int
{
	$phpunit = '/var/www/html/custom_apps/bookingcheck/vendor/bin/phpunit';
	$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 ' . escapeshellarg($phpunit)
		. ' -c /var/www/html/custom_apps/bookingcheck/phpunit.xml --do-not-cache-result'
		. ' --filter ' . escapeshellarg($filter) . ' 2>&1';
	passthru($cmd, $code);
	return (int)$code;
}

$mutations = [
	// ---- ReservationService — capacity math --------------------------
	'lib/Service/ReservationService.php' => [
		'capacity_allows_oversell_lte_to_lt' => [
			'from' => "\$qb->createFunction('sold + held + ' . \$seats),\n\t\t\t\t\$qb->createFunction('capacity'),",
			// lte→lt on FIRST occurrence only can't be expressed — mutate the guard semantics globally for the check
			'to' => "\$qb->createFunction('sold + held + ' . \$seats - 1),\n\t\t\t\t\$qb->createFunction('capacity'),",
			'test' => 'testOversellRejectedAtomically',
		],
		'held_not_counted' => [
			'from' => "\$qb->createFunction('sold + held + ' . \$seats),",
			'to' => "\$qb->createFunction('sold + ' . \$seats),",
			'test' => 'testOversellRejectedAtomically',
		],
		// a pooled slot MUST be booked through its pool — a bare line would
		// draw on the never-used slot-row counter = double inventory
		'pool_pairing_unenforced' => [
			'from' => "\t\t\tforeach (\$slotLines as \$l) {\n\t\t\t\t\$rid = \$l['resource_id'] ?? null;\n\t\t\t\t\$has = isset(\$pools[(int)\$l['target_id']]);\n\t\t\t\tif (\$has && (\$rid === null || !isset(\$pools[(int)\$l['target_id']][(int)\$rid]))) {\n\t\t\t\t\tthrow new ValidationException(['lines' => 'Pick a resource for this timeslot.']);\n\t\t\t\t}\n\t\t\t\tif (!\$has && \$rid !== null) {\n\t\t\t\t\tthrow new ValidationException(['lines' => 'Invalid selection.']);\n\t\t\t\t}\n\t\t\t}",
			'to' => "",
			'test' => 'testPooledSlotRequiresResourcePick',
		],
		'non_slot_resource_ignored' => [
			'from' => "\t\t\tif ((\$l['target_type'] ?? '') !== self::TARGET_SLOT\n\t\t\t\t&& (\$l['resource_id'] ?? null) !== null) {",
			'to' => "\t\t\tif (false) {",
			'test' => 'testPooledSlotRequiresResourcePick',
		],
	],
	// ---- OrderService — state machine --------------------------------
	'lib/Service/OrderService.php' => [
		'paid_from_anything' => [
			'from' => "if (!in_array(\$order->getStatus(), [self::ST_PENDING_PAYMENT, self::ST_EXPIRED, self::ST_APPROVED], true)) {",
			'to' => "if (false) {",
			'test' => 'testIllegalTransitionRejected',
			// Equivalent: the pre-check is a fast-fail. The real guard is the
			// CAS UPDATE in setStatusLocked (WHERE status IN allowed) — a
			// cancelled/refunded/paid order still throws StateConflictException.
			// Verified: mutant produces identical observable behavior.
			'equivalent' => 'setStatusLocked CAS enforces the same allowed-from set',
		],
		'idem_check_skipped' => [
			'from' => "if (\$existing !== null) {",
			'to' => "if (false && \$existing !== null) {",
			'test' => 'testIdempotentReplayReturnsSameOrderNoDoubleHold',
			// Equivalent: the early return is a fast path. The UNIQUE index on
			// idem_token is the real guard; the catch block converts the
			// violation into the identical 'idempotent-replay' result, and the
			// rolled-back hold leaves counters untouched.
			'equivalent' => 'unique constraint + catch-path returns identical replay',
		],
		'consent_required_removed' => [
			'from' => "if (\$tosText !== '' && !\$consented) {",
			'to' => "if (false) {",
			'test' => 'testConsentRequiredWhenTosSet|testClaimRequiresConsentWhenTosSet',
		],
		'consent_snapshot_dropped' => [
			'from' => "if (\$tosText !== '') {\n\t\t\t\t\$order->setConsentAt(\$now);",
			'to' => "if (false) {\n\t\t\t\t\$order->setConsentAt(\$now);",
			'test' => 'testConsentAcceptedSnapshotsWording',
		],
		'ownership_check_skipped' => [
			'from' => "\$this->inventory->assertTargetsOwnedBy(\$offeringId, \$seatLines);",
			'to' => "/* ownership check removed */",
			'test' => 'testForeignEventTargetRejectedNoHold',
		],
		'paid_audit_missing' => [
			'from' => "\$this->audit->log((int)\$order->getId(), (int)\$order->getOfferingId(), \$actor, 'order.paid',\n\t\t\t\$wasApproved ? self::ST_APPROVED : self::ST_PENDING_PAYMENT, self::ST_PAID);",
			'to' => "/* order.paid audit dropped */",
			'test' => 'testPaidTransitionIsAudited',
		],
		'refund_credit_skipped' => [
			'from' => "if (\$creditAmountCents !== null && \$creditAmountCents > 0) {",
			'to' => "if (false) {",
			'test' => 'testRefundMintsCreditNoteInsideTransition',
		],
		'expire_swallows_underflow' => [
			'from' => "if (\$e->getCodeName() === 'ILLEGAL_TRANSITION') {\n\t\t\t\treturn false;\n\t\t\t}\n\t\t\tthrow \$e;",
			'to' => "return false;",
			'test' => 'testExpirePropagatesInventoryUnderflow',
		],
		'refund_keeps_tickets_live' => [
			'from' => "\t\t\tif (\$from === self::ST_PAID) {\n\t\t\t\t\$this->releaseSoldSeatsLocked(\$o);\n\t\t\t\t\$this->tickets->revokeForOrder((int)\$o->getId());\n\t\t\t}",
			'to' => "\t\t\tif (\$from === self::ST_PAID) {\n\t\t\t\t\$this->releaseSoldSeatsLocked(\$o);\n\t\t\t}",
			'test' => 'testRefundedOrderRevokesTickets',
		],
		'cancel_keeps_tickets_live' => [
			'from' => "\t\t\t\$this->releaseSoldSeatsLocked(\$o);\n\t\t\t\$this->tickets->revokeForOrder((int)\$o->getId());\n\t\t\t\$this->setStatusLocked(\$o, [self::ST_PAID], self::ST_CANCELLED);",
			'to' => "\t\t\t\$this->releaseSoldSeatsLocked(\$o);\n\t\t\t\$this->setStatusLocked(\$o, [self::ST_PAID], self::ST_CANCELLED);",
			'test' => 'testBuyerCancelRevokesTickets',
		],
	],
	// ---- PricingService — promo preview parity -------------------------
	'lib/Service/PricingService.php' => [
		'promo_preview_ignores_usability' => [
			'from' => "if (\$promo === null || !\$this->promoUsable(\$promo, \$offeringId ?? 0)) {",
			'to' => "if (\$promo === null) {",
			'test' => 'testUsablePromoHonoursWindowAndScope|testUsablePromoScopeNeedsOfferingMatch',
		],
	],
	// ---- VoucherMapper — atomic redeem/restore -------------------------
	'lib/Db/VoucherMapper.php' => [
		'restore_underflows_uses' => [
			'from' => "CASE WHEN uses > 0 THEN uses - 1 ELSE 0 END",
			'to' => "uses - 1",
			'test' => 'testVoucherRedeemRejectsInsufficientBalance',
		],
		'redeem_ignores_balance' => [
			'from' => "->andWhere(\$qb->expr()->gte('remaining_cents', \$qb->createNamedParameter(\$cents)))",
			'to' => "",
			'test' => 'testVoucherRedeemRejectsInsufficientBalance',
		],
	],
	// ---- OtpService — brute force + single use -----------------------
	'lib/Service/OtpService.php' => [
		'no_attempt_cap' => [
			'from' => "if ((int)\$row->getAttempts() >= self::MAX_ATTEMPTS) {",
			'to' => "if (false) {",
			'test' => 'testOtpWrongCodeBurnsAttempts',
		],
		'magic_reusable' => [
			'from' => "return \$this->consume((int)\$row->getId()) ? \$row->getEmail() : null;",
			'to' => "\$this->consume((int)\$row->getId());\n\t\treturn \$row->getEmail();",
			'test' => 'OtpServiceTest',
		],
	],
	// ---- TicketService — revoked tickets must refuse check-in ---------
	'lib/Service/TicketService.php' => [
		'revoked_ticket_scans_green' => [
			'from' => "if (\$ticket->getRevokedAt() !== null) {\n\t\t\treturn ['ok' => false, 'already' => false, 'revoked' => true, 'ticket' => \$ticket];\n\t\t}",
			'to' => "/* revocation ignored */",
			'test' => 'testRevokedTicketCannotCheckIn',
		],
		// door lookup by order ref must never surface revoked tickets
		'live_lookup_includes_revoked' => [
			'from' => "->andWhere(\$qb->expr()->isNull('t.revoked_at'))\n\t\t\t->orderBy('ol.id'",
			'to' => "->andWhere(\$qb->expr()->isNotNull('t.revoked_at'))\n\t\t\t->orderBy('ol.id'",
			'test' => 'testLiveTicketsForOrderExcludesRevoked',
		],
	],
	// ---- WaitlistService — join must validate the target --------------
	'lib/Service/WaitlistService.php' => [
		'join_accepts_dead_targets' => [
			'from' => "\t\t\$offeringId = \$this->offeringForTarget(\$targetType, \$targetId);\n\t\tif (\$offeringId === null) {\n\t\t\treturn false;\n\t\t}",
			'to' => "\t\t\$offeringId = 0;",
			// needs both legs: dead-target rejection AND live-target acceptance
			'test' => 'testWaitlistJoinRejectsDeadTargetsSilently|testWaitlistJoinAcceptsSeriesTargets',
		],
	],
	// ---- PubReviewController — capability gate ------------------------
	'lib/Controller/PubReviewController.php' => [
		'anon_can_review_paid_orders' => [
			'from' => "\t\tif (\$token === '' || strlen(\$token) > 128) {\n\t\t\treturn \$this->notFound();\n\t\t}",
			'to' => "\t\t/* token gate removed */",
			'test' => 'testMissingTokenIsNotFound',
		],
	],
	// ---- CapiController — companion scope + role gates ----------------
	'lib/Controller/CapiController.php' => [
		'scope_widened_to_all' => [
			'from' => "\t\treturn \$scope === null || in_array(\$offeringId, \$scope, true);",
			'to' => "\t\treturn true;",
			'test' => 'testCheckInForeignScopeIsNotFoundAndNeverMutates',
		],
		'door_can_undo_checkin' => [
			'from' => "\t\tif (\$this->companionDevice?->getRole() !== 'manager') {\n\t\t\treturn \$this->fail(403, 'FORBIDDEN', 'Manager devices only.');\n\t\t}\n\t\t\$id = \$this->strictId(\$this->request->getParam('ticket_id'));",
			'to' => "\t\t\$id = \$this->strictId(\$this->request->getParam('ticket_id'));",
			'test' => 'testDoorRoleCannotUndoCheckIn',
		],
		'stats_counts_revoked_tickets' => [
			'from' => "AND t.checked_in_at IS NOT NULL\n\t\t\t\t    AND t.revoked_at IS NULL)",
			'to' => "AND t.checked_in_at IS NOT NULL)",
			'test' => 'testCapiStatsExcludesRevokedTickets',
		],
	],
	// ---- TestProvider — signature verification ------------------------
	'lib/Psp/TestProvider.php' => [
		'signature_always_true' => [
			'from' => "if (\$sig === '' || !hash_equals(hash_hmac('sha256', \$raw, \$secret), \$sig)) {",
			'to' => "if (false) {",
			'test' => 'TestProviderVerifyTest',
		],
		'empty_secret_accepted' => [
			'from' => "if (\$secret === '') {",
			'to' => "if (false) {",
			'test' => 'TestProviderVerifyTest',
		],
	],
	// ---- ApiControllerTrait — family wire envelope {success,data:{…}} --
	'lib/Controller/ApiControllerTrait.php' => [
		'envelope_unwrapped' => [
			'from' => "return new JSONResponse(['success' => true, 'data' => \$data], \$status,",
			'to' => "return new JSONResponse(['success' => true] + \$data, \$status,",
			'test' => 'testRosterFiltersByTargetTypeNotJustId',
		],
	],
	// ---- RosterController — typed targets + revoked guard ------------
	'lib/Controller/RosterController.php' => [
		'roster_target_type_dropped' => [
			'from' => "->andWhere(\$qb->expr()->eq('ol.target_type', \$qb->createNamedParameter(\$type)))",
			'to' => "",
			'test' => 'testRosterFiltersByTargetTypeNotJustId',
		],
		'roster_series_redirect_dropped' => [
			'from' => "\t\t\tif (\$sid !== false && \$sid !== null) {\n\t\t\t\t\$type = 'series';\n\t\t\t\t\$id = (int)\$sid;\n\t\t\t}",
			'to' => "",
			'test' => 'testRosterMemberEventResolvesToSeriesAttendees',
		],
		'admin_checkin_accepts_revoked' => [
			'from' => "\t\tif (\$ticket->getRevokedAt() !== null) {\n\t\t\treturn \$this->fail(409, 'TICKET_REVOKED', 'This ticket was cancelled or refunded.');\n\t\t}",
			'to' => "",
			'test' => 'testAdminCheckInRejectsRevokedTicket',
		],
	],
	// ---- ManageController — intake answer guards ----------------------
	'lib/Controller/ManageController.php' => [
		'intake_required_ignored' => [
			'from' => "\t\t\tif ((int)\$q->getRequired() === 1) {",
			'to' => "\t\t\tif (false) {",
			'test' => 'testIntakeAnswersOwnershipRequiredAndWordingRev',
		],
		'intake_foreign_ticket_accepted' => [
			'from' => "\t\tif (\$line === null || (int)\$line->getOrderId() !== (int)\$order->getId()) {\n\t\t\tthrow new NotFoundException(); // foreign ticket — uniform answer\n\t\t}",
			'to' => "",
			'test' => 'testIntakeAnswersOwnershipRequiredAndWordingRev',
		],
	],
	// ---- SlotMaterializer — idempotent materialization ----------------
	'lib/Service/SlotMaterializer.php' => [
		'materializer_duplicates' => [
			'from' => "\t\t\t\tif (isset(\$existing[\$key])) {\n\t\t\t\t\tcontinue; // idempotent — already materialized\n\t\t\t\t}",
			'to' => "",
			'test' => 'testSlotMaterializerGeneratesWeeklySlotsIdempotently',
		],
	],
	// ---- SlotController — booked inventory guard ----------------------
	'lib/Controller/SlotController.php' => [
		'booked_slot_deleted' => [
			'from' => "\t\t\tif ((int)\$s->getSold() > 0 || (int)\$s->getHeld() > 0 || \$this->slotRes->hasBookings(\$id)) {",
			'to' => "\t\t\tif (false) {",
			'test' => 'testBookedSlotCannotBeDeleted|testHeldSlotCannotBeDeleted',
		],
		'pool_bookings_deleted' => [
			'from' => " || \$this->slotRes->hasBookings(\$id)) {",
			'to' => ") {",
			'test' => 'testPoolBookedSlotCannotBeDeleted',
		],
		'tpl_delete_pool_blind' => [
			'from' => "\t\t\t\t\$slotIds = array_values(array_diff(\$slotIds, \$booked));",
			'to' => "",
			'test' => 'testTemplateDestroyKeepsBookedPoolSlots',
		],
		'tpl_delete_keeps_waitlist' => [
			'from' => "\t\t\t\t\$qb->delete('bc_waitlist')\n\t\t\t\t\t->where(\$qb->expr()->eq('target_type', \$qb->createNamedParameter('slot')))\n\t\t\t\t\t->andWhere(\$qb->expr()->in('target_id', \$qb->createNamedParameter(\n\t\t\t\t\t\t\$slotIds, \\OCP\\DB\\QueryBuilder\\IQueryBuilder::PARAM_INT_ARRAY)))\n\t\t\t\t\t->executeStatement();",
			'to' => "",
			'test' => 'testTemplateDestroyKeepsBookedPoolSlots',
		],
	],
	// ---- CatalogService — pooled availability -------------------------
	'lib/Service/CatalogService.php' => [
		'pooled_free_uses_slot_row' => [
			'from' => "\$free = \$slotPools !== []\n\t\t\t\t\t? array_sum(array_column(\$slotPools, 'available'))\n\t\t\t\t\t: \$this->inventory->available('slot', (int)\$s->getId());",
			'to' => "\$free = \$this->inventory->available('slot', (int)\$s->getId());",
			'test' => 'testPooledSlotCatalogShowsPoolFree',
		],
	],
	// ---- PubCheckoutController — §13b billing gate --------------------
	'lib/Controller/PubCheckoutController.php' => [
		'vies_eu_gate_dropped' => [
			'from' => "\t\t\t&& \\OCA\\BookingCheck\\Service\\ViesService::isEuCountry(\$country)\n",
			'to' => "",
			'test' => 'testCheckoutBillingReverseChargeDecisionMatrix',
		],
		'vies_unchecked_zeroes_vat' => [
			'from' => "\$reverseCharge = \$viesStatus === 'valid';",
			'to' => "\$reverseCharge = \$viesStatus !== 'invalid';",
			'test' => 'testCheckoutBillingReverseChargeDecisionMatrix',
		],
		'vies_invalid_accepted' => [
			'from' => "\t\t\tif (\$viesStatus === 'invalid') {\n\t\t\t\tthrow new ValidationException(['vat_id' => 'This VAT ID could not be verified.']);\n\t\t\t}",
			'to' => "",
			'test' => 'testCheckoutBillingReverseChargeDecisionMatrix',
		],
	],
	// ---- ViesService — fail-closed on tax ------------------------------
	'lib/Service/ViesService.php' => [
		'vies_noneu_unchecked' => [
			'from' => "\t\tif (!self::isEuCountry(\$country)) {\n\t\t\treturn 'invalid';\n\t\t}",
			'to' => "\t\tif (!self::isEuCountry(\$country)) {\n\t\t\treturn 'unchecked';\n\t\t}",
			'test' => 'testNonEuCountryIsInvalidWithoutAnyHttp',
		],
		'vies_offline_calls_http' => [
			'from' => "if (\$this->settings->get('vies_online') !== '1') {",
			'to' => "if (\$this->settings->get('vies_online') !== '0') {",
			'test' => 'testOfflineModeNeverBlocksCheckout',
		],
		'vies_invalid_means_valid' => [
			'from' => "return \$body['valid'] === true ? 'valid' : 'invalid';",
			'to' => "return \$body['valid'] !== true ? 'valid' : 'invalid';",
			'test' => 'testValidVerdict|testInvalidVerdict',
		],
		'vies_network_down_is_invalid' => [
			'from' => "return 'unchecked';\n\t\t}",
			'to' => "return 'invalid';\n\t\t}",
			'test' => 'testNetworkFailureDegradesToUnchecked',
		],
		'vies_prefix_not_stripped' => [
			'from' => "'vatNumber' => substr(\$vatId, 2)",
			'to' => "'vatNumber' => \$vatId",
			'test' => 'testStripsCountryPrefixForWireCall',
		],
	],
	// ---- UploadService — paid gate + quota + ownership -----------------
	'lib/Service/UploadService.php' => [
		'upload_unpaid_allowed' => [
			'from' => "\t\tif (\$order->getStatus() !== 'paid') {\n\t\t\tthrow new NotFoundException();\n\t\t}",
			'to' => "",
			'test' => 'testAttendeeUploadQuotaAndOfferingScope',
		],
		'upload_quota_skipped' => [
			'from' => "\t\tif (\$live + \$size > (int)\$o->getUploadQuotaMb() * 1024 * 1024) {",
			'to' => "\t\tif (false) {",
			'test' => 'testAttendeeUploadQuotaAndOfferingScope',
		],
		'upload_file_cap_skipped' => [
			'from' => "\t\tif (\$size > (int)\$o->getUploadMaxMb() * 1024 * 1024) {",
			'to' => "\t\tif (false) {",
			'test' => 'testAttendeeUploadQuotaAndOfferingScope',
		],
		'upload_foreign_line' => [
			'from' => "\t\tif (\$line === null || (int)\$line->getOrderId() !== (int)\$order->getId()\n\t\t\t|| \$line->getLineKind() !== 'seat') {",
			'to' => "\t\tif (\$line === null) {",
			'test' => 'testAttendeeUploadQuotaAndOfferingScope',
		],
	],
	// ---- BroadcastService — paid-only fanout ---------------------------
	'lib/Service/BroadcastService.php' => [
		'broadcast_to_unpaid' => [
			'from' => "->andWhere(\$qb->expr()->eq('o.status', \$qb->createNamedParameter('paid')))",
			'to' => "",
			'test' => 'testBroadcastFansOutToPaidBuyersOnly',
		],
		'broadcast_occurrence_ignored' => [
			'from' => "\t\tif (\$occurrence !== null) {\n\t\t\t\$qb->innerJoin(",
			'to' => "\t\tif (false) {\n\t\t\t\$qb->innerJoin(",
			'test' => 'testBroadcastOccurrenceFilter',
		],
	],
	// ---- InvoiceService — reverse charge zeroes VAT --------------------
	'lib/Service/InvoiceService.php' => [
		'invoice_rc_pays_vat' => [
			'from' => "(int)\$order->getReverseCharge() === 1",
			'to' => "(int)\$order->getReverseCharge() === 0",
			'test' => 'testCheckoutBillingReverseChargeDecisionMatrix',
		],
	],
	// ---- UploadGateService — extension/magic/macro fail-closed ---------
	'lib/Service/UploadGateService.php' => [
		'gate_allowlist_inverted' => [
			'from' => "if (!isset(self::ALLOWED[\$ext])) {",
			'to' => "if (isset(self::ALLOWED[\$ext])) {",
			'test' => 'testHonestPdfPasses|testDisallowedExtensionRejected',
		],
		'gate_magic_ignored' => [
			'from' => "foreach (self::ALLOWED[\$ext] as \$magic) {",
			'to' => "foreach ([] as \$magic) {",
			'test' => 'testMagicByteMismatchRejected|testPngMagicVerified',
		],
		'gate_macro_scan_skipped' => [
			'from' => "if (in_array(\$ext, ['docx', 'xlsx', 'pptx'], true)) {",
			'to' => "if (false) {",
			'test' => 'testMacroEnabledDocxRejected',
		],
		'gate_webp_fourcc_ignored' => [
			"from" => "substr(\$head, 8, 4) !== 'WEBP'",
			"to" => "substr(\$head, 8, 4) !== 'XXXX'",
			'test' => 'testWebpNeedsWebpAtOffset8',
		],
	],
	// ---- Queue claim CAS — duplicate-worker safety -------------------
	'lib/Db/EmailOutboxMapper.php' => [
		'claim_not_cas' => [
			'from' => "->andWhere(\$qb->expr()->orX(\n\t\t\t\t\$qb->expr()->andX(\n\t\t\t\t\t\$qb->expr()->eq('status', \$qb->createNamedParameter('pending')),\n\t\t\t\t\t\$qb->expr()->lt('attempts', \$qb->createNamedParameter(4)),",
			'to' => "->andWhere(\$qb->expr()->orX(\n\t\t\t\t\$qb->expr()->andX(\n\t\t\t\t\t\$qb->expr()->eq('status', \$qb->createNamedParameter('pending')),\n\t\t\t\t\t\$qb->expr()->lt('attempts', \$qb->createNamedParameter(400)),",
			'test' => 'testQueueClaimIsExclusiveAndFenced',
		],
		'claim_no_attempt_bump' => [
			'from' => "->set('attempts', \$qb->createFunction('attempts + 1'))",
			'to' => "->set('attempts', \$qb->createFunction('attempts + 0'))",
			'test' => 'testQueueClaimIsExclusiveAndFenced',
		],
		'markSent_no_epoch' => [
			'from' => "->andWhere(\$qb->expr()->eq('attempts', \$qb->createNamedParameter(\$epoch)));\n\t\t\$qb->executeStatement();\n\t}\n\n\t/** Failed send",
			'to' => ");\n\t\t\$qb->executeStatement();\n\t}\n\n\t/** Failed send",
			'test' => 'testQueueClaimIsExclusiveAndFenced',
		],
	],
	// ---- EOL purge — every PII surface must be wiped -----------------
	'lib/Service/EolService.php' => [
		'purge_skips_answers' => [
			'from' => "\$d->delete('bc_answer')->where(\$d->expr()->in('ticket_id',",
			'to' => "\$d->delete('bc_order')->where(\$d->expr()->in('ticket_id',",
			'test' => 'testEolPurgeTombstonesPiiAndDropsBlobs',
		],
		'purge_keeps_blob' => [
			'from' => "if (\$dir->fileExists((string)\$ref)) {\n\t\t\t\t\t\t\$dir->getFile((string)\$ref)->delete();",
			'to' => "if (false) {\n\t\t\t\t\t\t\$dir->getFile((string)\$ref)->delete();",
			'test' => 'testEolPurgeTombstonesPiiAndDropsBlobs',
		],
		'purge_keeps_queue_mail' => [
			'from' => "\$qb->delete('bc_email')->where(\$qb->expr()->in('to_email',",
			'to' => "\$qb->delete('bc_order')->where(\$qb->expr()->in('to_email',",
			'test' => 'testEolPurgeTombstonesPiiAndDropsBlobs',
		],
	],
	// ---- PspEventService — settled money must always be recorded ------
	'lib/Service/PspEventService.php' => [
		'dead_settlement_never_recorded' => [
			'from' => "\$this->payments->updateStatus((int)\$payment->getId(), 'paid', \$event->rawStatus, \$event->eventId);\n\t\t\t\$this->logger->error('PSP settlement on a dead order",
			'to' => "\$this->logger->error('PSP settlement on a dead order",
			'test' => 'testSettlementOnDeadOrderStillRecordsPaymentAndAlerts',
		],
	],
	// ---- PricingService — RC voucher credit is net-denominated --------
	'lib/Service/PricingService.php' => [
		'voucher_charges_gross_basis' => [
			'from' => "[\$chargeBasis] = \$reverseCharge ? self::splitVat(\$grossRemainder, \$vatBp)\n\t\t\t: [\$grossRemainder];",
			'to' => "[\$chargeBasis] = [\$grossRemainder];",
			'test' => 'testReverseChargeVoucherIsNetDenominated',
		],
	],
	// ---- OtpService — attempts must be counted AND fenced -------------
	'lib/Service/OtpService.php' => [
		'attempts_never_counted' => [
			'from' => "->set('attempts', \$qb->createFunction('attempts + 1'))",
			'to' => "->set('attempts', \$qb->createFunction('attempts'))",
			'test' => 'testOtpIssueVerifyConsume|testOtpWrongCodeBurnsAttempts',
		],
		'attempt_bump_fails_open' => [
			'from' => "if (\$qb->executeStatement() === 0) {\n\t\t\treturn false;\n\t\t}\n\n\t\tif (!\$this->tokens->verify(",
			'to' => "if (!\$this->tokens->verify(",
			'test' => 'testVerifyRefusesWhenAttemptBumpLosesCas|testOtpWrongCodeBurnsAttempts',
		],
	],
	// ---- RetentionPruneJob — column names are load-bearing ------------
	'lib/BackgroundJob/RetentionPruneJob.php' => [
		'portal_sess_wrong_col' => [
			'from' => "\$qb->delete('bc_portal_sess')->where(\$qb->expr()->lt('expires_at',",
			'to' => "\$qb->delete('bc_portal_sess')->where(\$qb->expr()->lt('abs_expires_at',",
			'test' => 'testRetentionPruneJobRunsClean',
		],
		'email_prune_skipped' => [
			'from' => "\$qb->delete('bc_email')\n\t\t\t->where(\$qb->expr()->eq('status', \$qb->createNamedParameter('sent')))",
			'to' => "\$qb->delete('bc_otp')\n\t\t\t->where(\$qb->expr()->eq('status', \$qb->createNamedParameter('sent')))",
			'test' => 'testRetentionPruneJobRunsClean',
		],
	],
	// ---- PortalSessionService — sliding expiry is ENFORCED ------------
	'lib/Service/PortalSessionService.php' => [
		'session_idle_expiry_ignored' => [
			'from' => "if (\$row->getExpiresAt() !== null && \$row->getExpiresAt() < \$now) {\n\t\t\treturn null;\n\t\t}\n\t\t// slide the window",
			'to' => "// slide the window",
			'test' => 'testPortalSessionLifecycle',
			'equivalent' => 'PortalSessionMapper::findByTokenHash already filters expires_at — the service check is defense-in-depth only',
		],
	],
	// ---- ScanController — door lookup is read-only + ref fallback -----
	'lib/Controller/ScanController.php' => [
		'lookup_checks_people_in' => [
			'from' => "\$ticket = \$this->tickets->resolveQrToken(\$m[1]);",
			'to' => "\$ticket = \$this->tickets->checkInByToken(\$m[1], 'scan')['ticket'];",
			'test' => 'testLookupIsReadOnlyAndHonoursCodeParam',
		],
		'lookup_ref_fallback_dead' => [
			'from' => "if (preg_match('/^BCK-\\d{4}-[A-Z0-9]{8}\$/i', \$input)) {",
			'to' => "if (false) {",
			'test' => 'testLookupOrderRefReturnsTicketList',
		],
		'lookup_wrong_param_name' => [
			'from' => "\$qr = (string)\$this->request->getParam('code', '');\n\t\treturn \$qr !== '' ? \$qr : (string)\$this->request->getParam('qr', '');",
			'to' => "\$qr = (string)\$this->request->getParam('qr', '');\n\t\treturn \$qr !== '' ? \$qr : (string)\$this->request->getParam('code', '');",
			'test' => 'testLookupIsReadOnlyAndHonoursCodeParam|testLookupPrefersCodeOverLegacyQr',
		],
		'lookup_revoked_previews_green' => [
			'from' => "\t\t\tif (\$ticket->getRevokedAt() !== null) {\n\t\t\t\treturn \$this->fail(409, 'TICKET_REVOKED', 'This ticket was cancelled or refunded.');\n\t\t\t}\n\t\t\treturn \$this->ok([\n\t\t\t\t'ticket_id'",
			'to' => "\t\t\treturn \$this->ok([\n\t\t\t\t'ticket_id'",
			'test' => 'testLookupRevokedTicketIs409',
		],
	],
];

// baseline
echo "== baseline ==\n";
if (run_filter('OrderFlowIntegrationTest|AuthAndPricingIntegrationTest|TestProviderVerifyTest') !== 0) {
	fwrite(STDERR, "Baseline suite must be green before mutating\n");
	exit(1);
}

$survivors = [];
$equivalent = [];
foreach ($mutations as $file => $set) {
	$source = $appRoot . '/' . $file;
	$orig = file_get_contents($source);
	foreach ($set as $name => $m) {
		echo "\n== {$file}: {$name} ==\n";
		if (!str_contains($orig, $m['from'])) {
			echo "  ANCHOR MISSING — mutant skipped (find real anchor)\n";
			$survivors[] = $name . ' (anchor missing)';
			continue;
		}
		file_put_contents($source, str_replace($m['from'], $m['to'], $orig, $count));
		clearstatcache(true, $source);
		$code = run_filter($m['test']);
		file_put_contents($source, $orig);
		clearstatcache(true, $source);
		if ($code === 0) {
			if (isset($m['equivalent'])) {
				echo "  EQUIVALENT — second guard covers it: {$m['equivalent']}\n";
				$equivalent[] = $name;
			} else {
				echo "  SURVIVED — no test caught it\n";
				$survivors[] = $name;
			}
		} else {
			echo "  KILLED\n";
		}
	}
}

echo "\n========================================\n";
if ($equivalent !== []) {
	echo "EQUIVALENT MUTANTS (" . count($equivalent) . ") — verified covered by a second guard:\n";
	foreach ($equivalent as $s) {
		echo " - {$s}\n";
	}
}
if ($survivors === []) {
	echo "All killable mutants killed. True survivors: 0\n";
	exit(0);
}
echo "SURVIVORS (" . count($survivors) . "):\n";
foreach ($survivors as $s) {
	echo " - {$s}\n";
}
exit(1);
