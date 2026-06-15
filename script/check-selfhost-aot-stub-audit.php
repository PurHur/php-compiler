#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard self-host AOT stub audit snapshot against lib/JIT.php drift (#8720).
 *
 * Usage:
 *   php script/check-selfhost-aot-stub-audit.php
 */

$root = dirname(__DIR__);
$audit = $root.'/script/audit-selfhost-aot-stubs.php';
if (!is_readable($audit)) {
    fwrite(STDERR, "check-selfhost-aot-stub-audit: missing script/audit-selfhost-aot-stubs.php\n");
    exit(1);
}

passthru(PHP_BINARY.' '.escapeshellarg($audit).' --check', $exitCode);
exit((int) $exitCode);
