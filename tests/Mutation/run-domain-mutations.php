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
	],
	// ---- OrderService — state machine --------------------------------
	'lib/Service/OrderService.php' => [
		'paid_from_anything' => [
			'from' => "if (!in_array(\$order->getStatus(), [self::ST_PENDING_PAYMENT, self::ST_EXPIRED, self::ST_APPROVED], true)) {",
			'to' => "if (false) {",
			'test' => 'testIllegalTransitionRejected',
		],
		'idem_check_skipped' => [
			'from' => "if (\$existing !== null) {",
			'to' => "if (false && \$existing !== null) {",
			'test' => 'testIdempotentReplayReturnsSameOrderNoDoubleHold',
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
			'test' => 'testMagicTokenSingleUse',
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
			'test' => 'testMissingSecretRefuses',
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
			echo "  SURVIVED — no test caught it\n";
			$survivors[] = $name;
		} else {
			echo "  KILLED\n";
		}
	}
}

echo "\n========================================\n";
if ($survivors === []) {
	echo "All mutants killed. Score: 100%\n";
	exit(0);
}
echo "SURVIVORS (" . count($survivors) . "):\n";
foreach ($survivors as $s) {
	echo " - {$s}\n";
}
exit(1);
