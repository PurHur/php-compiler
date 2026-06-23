<?php

declare(strict_types=1);

/**
 * Issue #10931 — rmdir() on non-empty directory must E_WARNING + false.
 *
 * php-src: ext/standard/file.c — php_rmdir / php_do_rmdir.
 *
 * Verify:
 *   php bin/vm.php test/repro/maintainer_gap_rmdir_nonempty.php
 *   php bin/jit.php test/repro/maintainer_gap_rmdir_nonempty.php
 *   php bin/compile.php -l test/repro/maintainer_gap_rmdir_nonempty.php && ./test/repro/maintainer_gap_rmdir_nonempty
 */
$d = sys_get_temp_dir().'/phpc_rmdir_nonempty_'.getmypid();
mkdir($d);
file_put_contents($d.'/file', 'x');
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$result = rmdir($d);
echo 'warnings=', count($warnings), ' result=', var_export($result, true), "\n";
@unlink($d.'/file');
@rmdir($d);
