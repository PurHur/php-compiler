<?php

declare(strict_types=1);

/**
 * Issue #10947 — symlink() missing path must E_WARNING + false.
 *
 * php-src: ext/standard/file.c — php_symlink.
 *
 * Verify:
 *   php bin/vm.php test/repro/maintainer_gap_symlink_missing_warning.php
 *   php bin/jit.php test/repro/maintainer_gap_symlink_missing_warning.php
 *   php bin/compile.php -l test/repro/maintainer_gap_symlink_missing_warning.php && ./test/repro/maintainer_gap_symlink_missing_warning
 */
$path = '/nonexistent/phpc_symlink_'.getmypid();
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$result = symlink($path.'/t', $path.'/l');
echo 'warnings=', count($warnings), ' result=', var_export($result, true), "\n";
