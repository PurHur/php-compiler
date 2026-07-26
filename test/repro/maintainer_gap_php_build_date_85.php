<?php

declare(strict_types=1);

/**
 * Repro #23231 — PHP_BUILD_DATE on PROFILE=8.5 (php-src main/php_version.h).
 *
 * Run:
 *   PHP_COMPILER_PROFILE=8.5 php bin/vm.php test/repro/maintainer_gap_php_build_date_85.php
 */
if (!defined('PHP_BUILD_DATE')) {
    echo "fail: PHP_BUILD_DATE undefined\n";
    exit(1);
}
$stamp = PHP_BUILD_DATE;
$dt = DateTimeImmutable::createFromFormat('M j Y H:i:s', $stamp);
if (false === $dt) {
    echo 'fail: unparseable PHP_BUILD_DATE: ', $stamp, "\n";
    exit(1);
}
$core = get_defined_constants(true)['Core'] ?? [];
if (!isset($core['PHP_BUILD_DATE'])) {
    echo "fail: PHP_BUILD_DATE missing from Core bucket\n";
    exit(1);
}
if ($core['PHP_BUILD_DATE'] !== $stamp) {
    echo "fail: Core bucket mismatch\n";
    exit(1);
}
echo "ok\n";
