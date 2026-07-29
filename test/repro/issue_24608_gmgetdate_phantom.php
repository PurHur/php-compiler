<?php

declare(strict_types=1);

/**
 * Repro #24608 — gmgetdate() must not exist (php-src-strict).
 *
 * Run: php bin/vm.php test/repro/issue_24608_gmgetdate_phantom.php
 */

if (function_exists('gmgetdate')) {
    fwrite(STDERR, "FAIL: gmgetdate still registered\n");
    exit(1);
}
if (!function_exists('getdate') || !function_exists('gmdate')) {
    fwrite(STDERR, "FAIL: getdate/gmdate missing\n");
    exit(1);
}
$d = getdate(0);
if (($d['year'] ?? null) !== 1970) {
    fwrite(STDERR, 'FAIL: getdate(0) year='.var_export($d['year'] ?? null, true)."\n");
    exit(1);
}

echo "ok\n";
