#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Regenerate docs/bootstrap-inventory-lint-snapshot.json from live lint (#2210).
 *
 * Usage:
 *   php script/bootstrap-inventory-lint-snapshot.php --write
 */

require __DIR__.'/bootstrap-inventory-lint-sync-lib.php';

$write = in_array('--write', $argv, true);
if (!$write) {
    fwrite(STDERR, "Usage: php script/bootstrap-inventory-lint-snapshot.php --write\n");
    exit(1);
}

$root = dirname(__DIR__);
$out = $root.'/docs/bootstrap-inventory-lint-snapshot.json';

try {
    $live = bootstrap_inventory_lint_live_report($root);
} catch (Throwable $e) {
    fwrite(STDERR, "bootstrap-inventory-lint-snapshot: {$e->getMessage()}\n");
    exit(1);
}

$payload = bootstrap_inventory_lint_normalize_report($live);
if (false === file_put_contents($out, $payload)) {
    fwrite(STDERR, "bootstrap-inventory-lint-snapshot: cannot write {$out}\n");
    exit(1);
}

$fileCount = count($live['files']);
fwrite(STDOUT, "bootstrap-inventory-lint-snapshot: wrote {$out} ({$fileCount} file(s) with unsupported syntax).\n");
exit(0);
