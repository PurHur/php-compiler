<?php

declare(strict_types=1);

/**
 * Issue #10932 — readfile() missing path must E_WARNING + false.
 *
 * php-src: ext/standard/streams.c — php_readfile stream open failure.
 *
 * Verify:
 *   php bin/vm.php test/repro/maintainer_gap_readfile_missing_warning.php
 *   php bin/jit.php test/repro/maintainer_gap_readfile_missing_warning.php
 *   php bin/compile.php -l test/repro/maintainer_gap_readfile_missing_warning.php && ./test/repro/maintainer_gap_readfile_missing_warning
 */
$path = '/no/such/phpc-readfile-missing-'.getmypid();
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$result = readfile($path);
echo 'warnings=', count($warnings), ' result=', var_export($result, true), "\n";
