#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Bootstrap self-host profile (issue #212 Phase B).
 *
 * Builds docs/bootstrap-profile.json from the vm.php path inventory: files with
 * source blockers are excluded; procedural lint targets gate compile.php -l.
 *
 * Usage:
 *   php script/bootstrap-profile.php          # write docs/bootstrap-profile.json
 *   php script/bootstrap-profile.php --check  # exit 1 if committed profile is stale
 */

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
require __DIR__.'/bootstrap-lib.php';

$check = in_array('--check', $argv, true);
$outFile = $root.'/docs/bootstrap-profile.json';

$inventory = bootstrapCollectInventoryReport($root);
$profile = bootstrapBuildProfile($inventory, $root);
$json = bootstrapProfileJson($profile);

if ($check) {
    if (!is_file($outFile)) {
        fwrite(STDERR, "Missing {$outFile}; run: php script/bootstrap-profile.php\n");
        exit(1);
    }
    $committed = (string) file_get_contents($outFile);
    if ($committed !== $json) {
        fwrite(STDERR, "Stale {$outFile}; run: php script/bootstrap-profile.php\n");
        exit(1);
    }
    exit(0);
}

if (!is_dir(dirname($outFile))) {
    mkdir(dirname($outFile), 0775, true);
}
file_put_contents($outFile, $json);
fwrite(STDOUT, "Wrote {$outFile} ({$profile['totals']['aot_lint_targets']} AOT lint targets, {$profile['totals']['excluded']} excluded files)\n");
