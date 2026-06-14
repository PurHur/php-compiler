#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Merge missing Phase A inventory paths into compiler_lib_spine_smoke (issue #2868).
 *
 * Usage: php script/bootstrap-spine-merge-missing-includes.php [--dry-run]
 */

$root = dirname(__DIR__);
$dryRun = in_array('--dry-run', $argv, true);
$spineMain = $root.'/test/selfhost/compiler_lib_spine_smoke/main.php';

require $root.'/vendor/autoload.php';
require $root.'/script/bootstrap-lib.php';
require $root.'/script/bootstrap-spine-count.php';

$report = bootstrapCollectInventoryReport($root);
$inventory = array_keys($report['files'] ?? []);
$spineSubs = [
    'src/cli.php' => 'test/bootstrap-aot/cli_spine_shim.php',
    'src/llvm-env.php' => 'test/bootstrap-aot/llvm_env_spine_shim.php',
    'src/macro_functions.php' => 'test/bootstrap-aot/macro_functions_spine_shim.php',
];

$lines = file($spineMain, FILE_IGNORE_NEW_LINES) ?: [];
$header = [];
$requires = [];
$footer = [];
$seenRequire = false;

foreach ($lines as $line) {
    if (preg_match("#^require_once __DIR__\\.'/\\.\\./\\.\\./\\.\\./([^']+)';#", $line, $m)) {
        $requires[$m[1]] = $line;
        $seenRequire = true;
        continue;
    }
    if (!$seenRequire) {
        $header[] = $line;
    } else {
        $footer[] = $line;
    }
}

$missing = [];
foreach ($inventory as $rel) {
    if (isset($requires[$rel])) {
        continue;
    }
    if (isset($spineSubs[$rel]) && isset($requires[$spineSubs[$rel]])) {
        continue;
    }
    $missing[] = $rel;
}
sort($missing, SORT_STRING);

foreach ($missing as $rel) {
    $requires[$rel] = "require_once __DIR__.'/../../../{$rel}';";
}

ksort($requires, SORT_STRING);

$before = bootstrap_count_spine_requires($spineMain);
$after = count($requires);

fwrite(STDOUT, "bootstrap-spine-merge: missing ".count($missing)." inventory paths\n");
fwrite(STDOUT, "bootstrap-spine-merge: require_once {$before} -> {$after}\n");

if ($dryRun) {
    foreach ($missing as $rel) {
        fwrite(STDOUT, "  + {$rel}\n");
    }
    exit(0);
}

$newLines = array_merge(
    $header,
    array_values($requires),
    $footer
);
file_put_contents($spineMain, implode("\n", $newLines)."\n");
fwrite(STDOUT, "bootstrap-spine-merge: wrote {$spineMain}\n");
