<?php

declare(strict_types=1);

/**
 * Issue #10945 — mkdir() on existing directory must E_WARNING + false.
 *
 * php-src: ext/standard/file.c — php_mkdir / EEXIST.
 *
 * Verify:
 *   php bin/vm.php test/repro/maintainer_gap_mkdir_existing_warning.php
 *   php bin/jit.php test/repro/maintainer_gap_mkdir_existing_warning.php
 *   php bin/compile.php -l test/repro/maintainer_gap_mkdir_existing_warning.php && ./test/repro/maintainer_gap_mkdir_existing_warning
 */
$dir = sys_get_temp_dir().'/phpc_mkdir_exist_'.getmypid();
@mkdir($dir);
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$result = mkdir($dir);
echo 'warnings=', count($warnings), ' result=', var_export($result, true), "\n";
@rmdir($dir);
