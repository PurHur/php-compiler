#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Single source of truth for M2 spine progress counts (issue #1872).
 *
 * Spine: require_once lines in test/selfhost/compiler_lib_spine_smoke/main.php
 * Inventory: "PHP files on vm.php path" row in docs/bootstrap-inventory.md
 *
 * Usage:
 *   php script/bootstrap-spine-count.php          # human summary on stdout
 *   php script/bootstrap-spine-count.php --json   # {"spine":N,"inventory":M}
 */

$root = dirname(__DIR__);
require_once $root.'/vendor/autoload.php';
require_once __DIR__.'/bootstrap-lib.php';
require_once __DIR__.'/bootstrap-phase-a-deferred.php';

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $json = in_array('--json', $argv, true);
    $counts = bootstrap_spine_counts($root);
    if ($json) {
        echo json_encode($counts, JSON_UNESCAPED_SLASHES)."\n";
        exit(0);
    }
    fwrite(STDOUT, "bootstrap-spine-count: {$counts['spine']}/{$counts['inventory']}\n");
    exit(0);
}

/**
 * @return array{spine: int, inventory: int, vm_path_files?: int, phase_a_ratio_deferred?: int}
 */
function bootstrap_spine_counts(string $root): array
{
    $spineMain = $root.'/test/selfhost/compiler_lib_spine_smoke/main.php';
    $spine = bootstrap_count_spine_requires($spineMain);
    $inventory = bootstrap_spine_phase_a_inventory_files($root);

    return ['spine' => $spine, 'inventory' => $inventory];
}

/** Phase A file count from the inventory generator (M2 ratio SSOT — #2553). */
function bootstrap_spine_phase_a_inventory_files(string $root): int
{
    if (!function_exists('bootstrapCollectInventoryReport')) {
        return bootstrap_read_inventory_total($root.'/docs/bootstrap-inventory.md');
    }
    $report = bootstrapCollectInventoryReport($root);
    $phaseA = bootstrap_phase_a_inventory_counts($report);

    return (int) $phaseA['phase_a_inventory_files'];
}

/**
 * @return list<string> repo-relative paths from require_once lines
 */
function bootstrap_spine_require_paths(string $spineMain): array
{
    if (!is_readable($spineMain)) {
        return [];
    }
    $paths = [];
    foreach (file($spineMain, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match("#require_once __DIR__\\.'/\\.\\./\\.\\./\\.\\./([^']+)';#", $line, $match)) {
            $paths[] = $match[1];
        }
    }
    sort($paths, SORT_STRING);

    return $paths;
}

function bootstrap_count_spine_requires(string $spineMain): int
{
    if (!is_readable($spineMain)) {
        return 0;
    }
    $count = 0;
    foreach (file($spineMain, FILE_IGNORE_NEW_LINES) as $line) {
        if (str_starts_with($line, 'require_once')) {
            ++$count;
        }
    }

    return $count;
}

function bootstrap_read_inventory_total(string $inventoryDoc): int
{
    if (!is_readable($inventoryDoc)) {
        return 0;
    }
    $content = (string) file_get_contents($inventoryDoc);
    if (preg_match('/\| Phase A inventory files \(M2 ratio SSOT\) \| (\d+) \|/', $content, $match)) {
        return (int) $match[1];
    }
    if (preg_match('/\| PHP files on vm\.php path \| (\d+) \|/', $content, $match)) {
        return (int) $match[1];
    }

    return 0;
}
