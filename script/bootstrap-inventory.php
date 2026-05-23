#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Bootstrap self-host inventory (issue #212 Phase A).
 *
 * Lists PHP files on the bin/vm.php dependency path and flags language constructs
 * that the static compiler cannot lower yet.
 *
 * Usage:
 *   php script/bootstrap-inventory.php          # write docs/bootstrap-inventory.md
 *   php script/bootstrap-inventory.php --check  # exit 1 if committed doc is stale
 *   php script/bootstrap-inventory.php --json   # machine-readable report on stdout
 */

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
require __DIR__.'/bootstrap-lib.php';

$check = in_array('--check', $argv, true);
$jsonOut = in_array('--json', $argv, true);
$outFile = $root.'/docs/bootstrap-inventory.md';

$report = bootstrapCollectInventoryReport($root);

if ($jsonOut) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    exit(0);
}

$markdown = bootstrapRenderMarkdown($report);
if ($check) {
    if (!is_file($outFile)) {
        fwrite(STDERR, "Missing {$outFile}; run: php script/bootstrap-inventory.php\n");
        exit(1);
    }
    $committed = bootstrapStripInventoryProbeSection((string) file_get_contents($outFile));
    if ($committed !== $markdown) {
        fwrite(STDERR, "Stale {$outFile}; run: php script/bootstrap-inventory.php\n");
        exit(1);
    }
    exit(0);
}

if (!is_dir(dirname($outFile))) {
    mkdir(dirname($outFile), 0775, true);
}
file_put_contents($outFile, $markdown);
fwrite(STDOUT, "Wrote {$outFile} ({$report['totals']['files']} files, {$report['totals']['blockers']} blockers)\n");
