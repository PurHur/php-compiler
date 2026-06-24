<?php

declare(strict_types=1);

/**
 * Issue #11186 — mkdir($path, 0777, true) on existing directory must E_WARNING + false.
 *
 * php-src: ext/standard/filestat.c — php_mkdir / EEXIST.
 *
 * Verify:
 *   php bin/vm.php test/repro/maintainer_gap_mkdir_recursive_exists.php
 *   php bin/jit.php test/repro/maintainer_gap_mkdir_recursive_exists.php
 *   php bin/compile.php -l test/repro/maintainer_gap_mkdir_recursive_exists.php && ./test/repro/maintainer_gap_mkdir_recursive_exists
 */
$dir = 'test/compliance/cases/stdlib/mkdir_recursive_exists_fixture/'.getmypid();
@mkdir($dir, 0777, true);
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$result = mkdir($dir, 0777, true);
echo 'warnings=', count($warnings), ' result=', var_export($result, true), "\n";
if (1 === count($warnings)) {
    echo $warnings[0], "\n";
}
@rmdir($dir);
