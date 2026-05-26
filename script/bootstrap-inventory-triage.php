#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Rank CFG gaps across the bin/vm.php bootstrap inventory (#2254).
 *
 * Reuses lint --bootstrap-inventory --json (SSOT with #2208).
 *
 * Usage:
 *   php script/bootstrap-inventory-triage.php [--top N] [--json]
 */

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
require __DIR__.'/bootstrap-lib.php';
require __DIR__.'/bootstrap-inventory-lint-sync-lib.php';

$top = 20;
$jsonOut = false;
$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); ++$i) {
    $arg = $args[$i];
    if ('--json' === $arg) {
        $jsonOut = true;
        continue;
    }
    if (str_starts_with($arg, '--top=')) {
        $top = max(0, (int) substr($arg, 6));
        continue;
    }
    if ('--top' === $arg && isset($args[$i + 1]) && is_numeric($args[$i + 1])) {
        $top = max(0, (int) $args[$i + 1]);
        ++$i;
    }
}

try {
    $bundle = bootstrap_inventory_lint_report_for_triage($root);
} catch (Throwable $e) {
    fwrite(STDERR, 'bootstrap-inventory-triage: '.$e->getMessage()."\n");
    exit(1);
}

$report = ['files' => $bundle['files']];
$scanned = count(bootstrapVmPathPhpFiles($root));
$rows = bootstrap_inventory_lint_triage_rows($report, $top);
$sourceNote = 'snapshot' === $bundle['source']
    ? ' (committed docs/bootstrap-inventory-lint-snapshot.json — live lint unavailable)'
    : '';

if ($jsonOut) {
    $payload = json_decode(bootstrap_inventory_lint_triage_render_json($rows, $scanned, $top), true);
    $payload['source'] = $bundle['source'];
    fwrite(STDOUT, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    exit(0);
}

fwrite(STDOUT, "bootstrap-inventory-triage: top {$top} CFG gap(s) ({$scanned} file(s) on vm.php path){$sourceNote}\n\n");
fwrite(STDOUT, bootstrap_inventory_lint_triage_render_table($rows));
fwrite(STDOUT, "\nRegenerate: php script/bootstrap-inventory-triage.php\n");
fwrite(STDOUT, "Lint SSOT: ./phpc lint --bootstrap-inventory --json\n");

exit(0);
