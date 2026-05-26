#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard M2 spine progress footnotes against bundle drift (issues #1834, #1872).
 *
 * Canonical spine/inventory: script/bootstrap-spine-count.php (main.php + inventory doc).
 *
 * Usage:
 *   php script/check-selfhost-spine-count-sync.php
 */

require __DIR__.'/bootstrap-spine-count.php';

$root = dirname(__DIR__);
$bundleTest = $root.'/test/unit/BootstrapSelfhostBundleTest.php';

$errors = [];

$counts = bootstrap_spine_counts($root);
$spineCount = $counts['spine'];
$inventoryTotal = $counts['inventory'];

if ($spineCount <= 0) {
    $errors[] = 'compiler_lib_spine_smoke: missing or empty require_once spine bundle';
}

if ($inventoryTotal <= 0) {
    $errors[] = 'docs/bootstrap-inventory.md: missing PHP files on vm.php path count';
}

if ($spineCount > 0 && is_readable($bundleTest)) {
    $testSource = (string) file_get_contents($bundleTest);
    validate_test_matches_bundle($testSource, $spineCount, $errors);
}

if ($spineCount > 0 && $inventoryTotal > 0) {
    $trackedDocs = [
        'README.md',
        'docs/roadmap-wave3.md',
        'docs/self-host-target.md',
        'docs/pages/development-status.md',
        'docs/bootstrap-selfhost.md',
        'docs/pages/index.html',
    ];
    foreach ($trackedDocs as $rel) {
        $path = $root.'/'.$rel;
        if (!is_readable($path)) {
            $errors[] = "missing doc: {$rel}";
            continue;
        }
        $doc = (string) file_get_contents($path);
        validate_spine_footnotes($rel, $doc, $spineCount, $inventoryTotal, $errors);
    }
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-selfhost-spine-count-sync: {$err}\n");
    }
    fwrite(STDERR, "check-selfhost-spine-count-sync: FAILED — sync spine footnotes to bootstrap-spine-count.php (issues #1834, #1872).\n");
    exit(1);
}

fwrite(STDOUT, "check-selfhost-spine-count-sync: OK (spine {$spineCount}/{$inventoryTotal} from compiler_lib_spine_smoke)\n");
exit(0);

/**
 * @param list<string> $errors
 */
function validate_spine_footnotes(string $rel, string $doc, int $spineCount, int $inventoryTotal, array &$errors): void
{
    $pairPatterns = [
        '/\*\*'.$spineCount.'\*\*\/\*\*'.$inventoryTotal.'\*\*/',
        '/\*\*'.$spineCount.'\/'.$inventoryTotal.'\*\*/',
        '/\*\*'.$spineCount.'\*\* \/ \*\*'.$inventoryTotal.'\*\*/',
        '/\*\*'.$spineCount.'\*\* \/ '.$inventoryTotal.'/',
        '/\*\*'.$spineCount.' \/ '.$inventoryTotal.'\*\*/',
        '/\*\*'.$spineCount.' \/ '.$inventoryTotal.' /',
        '/\b'.$spineCount.'\/'.$inventoryTotal.'\b/',
    ];
    $hasPair = false;
    foreach ($pairPatterns as $pattern) {
        if (preg_match($pattern, $doc)) {
            $hasPair = true;
            break;
        }
    }
    if (!$hasPair) {
        $errors[] = "{$rel}: missing spine tracker {$spineCount}/{$inventoryTotal} (canonical from compiler_lib_spine_smoke)";
    }

    if (!preg_match('/\*\*'.$spineCount.'\*\*|\b'.$spineCount.'\b/', $doc)) {
        $errors[] = "{$rel}: missing canonical spine count {$spineCount}";
    }

    if (!preg_match('/\*\*'.$inventoryTotal.'\*\*|\b'.$inventoryTotal.'\b/', $doc)) {
        $errors[] = "{$rel}: missing inventory total {$inventoryTotal}";
    }

    // Reject stale M2 ratio footnotes (e.g. 358/586, 584/588, **654** / 657) when they disagree with canonical.
    $ratioPatterns = [
        '/\b(\d{2,4})\s*\/\s*'.$inventoryTotal.'\b/',
        '/\*\*(\d{2,4})\*\*\s*\/\s*'.$inventoryTotal.'\b/',
    ];
    foreach ($ratioPatterns as $ratioPattern) {
        if (!preg_match_all($ratioPattern, $doc, $ratioMatches, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($ratioMatches as $ratioMatch) {
            $found = (int) $ratioMatch[1];
            if ($found !== $spineCount && $found !== $inventoryTotal) {
                $errors[] = "{$rel}: stale spine ratio {$found}/{$inventoryTotal} (expected {$spineCount}/{$inventoryTotal})";
            }
        }
    }
}

/**
 * @param list<string> $errors
 */
function validate_test_matches_bundle(string $testSource, int $spineCount, array &$errors): void
{
    if (!str_contains($testSource, "assertSame({$spineCount}, \$count")) {
        $errors[] = 'BootstrapSelfhostBundleTest.php: assertSame spine count out of sync with compiler_lib_spine_smoke (run php script/bootstrap-spine-count.php)';
    }
}
