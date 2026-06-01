#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard M2 spine require_once coverage against vm.php-path inventory (issues #1945, #1922).
 *
 * Canonical counts: script/bootstrap-spine-count.php
 * Inventory file list: docs/bootstrap-inventory.md ## Files table (same set as bootstrap-inventory.php)
 *
 * Usage:
 *   php script/check-selfhost-spine-coverage-sync.php
 *   php script/check-selfhost-spine-coverage-sync.php --verbose
 */

require __DIR__.'/bootstrap-spine-count.php';
require_once __DIR__.'/bootstrap-spine-deferred-lib.php';

$root = dirname(__DIR__);
$verbose = in_array('--verbose', $argv, true);
$spineMain = getenv('PHP_COMPILER_SPINE_COVERAGE_TEST_SPINE') ?: $root.'/test/selfhost/compiler_lib_spine_smoke/main.php';

$testInventoryFile = getenv('PHP_COMPILER_SPINE_COVERAGE_TEST_INVENTORY_FILE');
$testInventory = getenv('PHP_COMPILER_SPINE_COVERAGE_TEST_INVENTORY');
if (false !== $testInventoryFile && '' !== $testInventoryFile && is_readable($testInventoryFile)) {
    $inventoryFiles = bootstrap_coverage_parse_inventory_list((string) file_get_contents($testInventoryFile));
} elseif (false !== $testInventory && '' !== $testInventory) {
    $inventoryFiles = bootstrap_coverage_parse_inventory_list($testInventory);
} else {
    $inventoryFiles = bootstrap_coverage_inventory_files($root);
}
$spinePaths = array_flip(bootstrap_spine_require_paths($spineMain));

/** Inventory paths covered by spine substitutes (not literal require_once) — issue #1423, #1945. */
$spineSubstitutes = [
    'src/cli.php' => 'test/bootstrap-aot/cli_spine_shim.php',
    'src/llvm-env.php' => 'test/bootstrap-aot/llvm_env_spine_shim.php',
    'src/macro_functions.php' => 'test/bootstrap-aot/macro_functions_spine_shim.php',
];

/** Deferred from compiler_lib_spine_smoke until native link lands (#1960, #2134, #2202). */
$spineNativeLinkDeferred = bootstrap_spine_native_link_deferred();

/** Inventory paths not yet in spine (regenerated inventory ahead of bundle — #1922). */
$spineInventoryAheadDeferred = [];

$spineCoverageDeferred = array_values(array_unique(array_merge(
    $spineNativeLinkDeferred,
    $spineInventoryAheadDeferred
)));

$missing = [];
foreach ($inventoryFiles as $rel) {
    if (in_array($rel, $spineCoverageDeferred, true)) {
        continue;
    }
    if (bootstrap_spine_is_inventory_ahead_deferred($rel)) {
        continue;
    }
    if (isset($spineSubstitutes[$rel])) {
        if (isset($spinePaths[$rel]) || isset($spinePaths[$spineSubstitutes[$rel]])) {
            continue;
        }
    }
    if (!isset($spinePaths[$rel])) {
        $missing[] = $rel;
    }
}
sort($missing, SORT_STRING);

if ([] === $missing) {
    $counts = bootstrap_spine_counts($root);
    $deferredNote = count($spineCoverageDeferred) > 0
        ? ', '.count($spineCoverageDeferred).' deferred (#1960, #1922)'
        : '';
    fwrite(STDOUT, "check-selfhost-spine-coverage-sync: OK (spine covers all {$counts['inventory']} inventory files{$deferredNote})\n");
    exit(0);
}

$limit = 20;
$shown = array_slice($missing, 0, $limit);
fwrite(STDERR, 'check-selfhost-spine-coverage-sync: FAILED — '.count($missing)." inventory files missing from spine:\n");
foreach ($shown as $path) {
    fwrite(STDERR, "  {$path}\n");
}
if (count($missing) > $limit) {
    fwrite(STDERR, '  ... and '.(count($missing) - $limit)." more\n");
}

$fullList = sys_get_temp_dir().'/selfhost-spine-coverage-missing.txt';
file_put_contents($fullList, implode("\n", $missing)."\n");
fwrite(STDERR, "Full list: {$fullList}\n");

if ($verbose) {
    foreach ($missing as $path) {
        fwrite(STDERR, "  {$path}\n");
    }
}

fwrite(STDERR, "Next: php script/bootstrap-selfhost-next-includes.php --bundle=test/selfhost/compiler_lib_spine_smoke/main.php --limit=".count($missing)." (issues #1922, #1945)\n");
exit(1);

/**
 * @return list<string> repo-relative paths (sorted)
 */
function bootstrap_coverage_parse_inventory_list(string $list): array
{
    $files = array_values(array_filter(array_map('trim', explode("\n", $list))));
    sort($files, SORT_STRING);

    return $files;
}

/**
 * @return list<string> repo-relative paths (sorted)
 */
function bootstrap_coverage_inventory_files(string $root): array
{
    $doc = $root.'/docs/bootstrap-inventory.md';
    if (!is_readable($doc)) {
        fwrite(STDERR, "check-selfhost-spine-coverage-sync: missing docs/bootstrap-inventory.md\n");
        exit(1);
    }
    $files = [];
    $inFiles = false;
    foreach (file($doc, FILE_IGNORE_NEW_LINES) as $line) {
        if ('## Files' === $line) {
            $inFiles = true;
            continue;
        }
        if ($inFiles && str_starts_with($line, '## ')) {
            break;
        }
        if ($inFiles && preg_match('/^\| `([^`]+)` \|/', $line, $match)) {
            $files[] = $match[1];
        }
    }
    if ([] === $files) {
        fwrite(STDERR, "check-selfhost-spine-coverage-sync: no files parsed from docs/bootstrap-inventory.md\n");
        exit(1);
    }
    sort($files, SORT_STRING);

    return $files;
}
