#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard M2 spine progress footnotes against BootstrapSelfhostBundleTest drift (issue #1834).
 *
 * Canonical spine count: assertSame(N, $count) in testCompilerLibSpineSmokeBundleUnitCountAndKeyUnits.
 * Inventory total: docs/bootstrap-inventory.md "PHP files on vm.php path" row.
 *
 * Usage:
 *   php script/check-selfhost-spine-count-sync.php
 */

$root = dirname(__DIR__);
$bundleTest = $root.'/test/unit/BootstrapSelfhostBundleTest.php';
$spineMain = $root.'/test/selfhost/compiler_lib_spine_smoke/main.php';
$inventoryDoc = $root.'/docs/bootstrap-inventory.md';

$errors = [];

[$canonicalSpine, $testSource] = read_canonical_spine_count($bundleTest);
if (null === $canonicalSpine) {
    $errors[] = 'BootstrapSelfhostBundleTest.php: missing assertSame(N, $count) for compiler_lib_spine_smoke';
}

$spineFromMain = count_spine_requires($spineMain);
if ($spineFromMain <= 0) {
    $errors[] = "missing or empty spine bundle: {$spineMain}";
} elseif (null !== $canonicalSpine && $spineFromMain !== $canonicalSpine) {
    $errors[] = "compiler_lib_spine_smoke require_once count {$spineFromMain} != BootstrapSelfhostBundleTest canonical {$canonicalSpine}";
}

$inventoryTotal = read_inventory_total($inventoryDoc);
if ($inventoryTotal <= 0) {
    $errors[] = 'docs/bootstrap-inventory.md: missing PHP files on vm.php path count';
}

$spineCount = $canonicalSpine ?? $spineFromMain;
if ($spineCount > 0 && $inventoryTotal > 0) {
    $trackedDocs = [
        'README.md',
        'docs/roadmap-wave3.md',
        'docs/self-host-target.md',
        'docs/pages/development-status.md',
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

    if (null !== $testSource) {
        validate_test_matches_bundle($testSource, $spineCount, $errors);
    }
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-selfhost-spine-count-sync: {$err}\n");
    }
    fwrite(STDERR, "check-selfhost-spine-count-sync: FAILED — sync spine footnotes to BootstrapSelfhostBundleTest (issue #1834).\n");
    exit(1);
}

fwrite(STDOUT, "check-selfhost-spine-count-sync: OK (spine {$spineCount}/{$inventoryTotal} from BootstrapSelfhostBundleTest)\n");
exit(0);

/**
 * @return array{0: ?int, 1: ?string}
 */
function read_canonical_spine_count(string $bundleTest): array
{
    if (!is_readable($bundleTest)) {
        return [null, null];
    }
    $source = (string) file_get_contents($bundleTest);
    if (!preg_match(
        "/function testCompilerLibSpineSmokeBundleUnitCountAndKeyUnits.*?assertSame\((\d+), \\\$count/s",
        $source,
        $match
    )) {
        return [null, $source];
    }

    return [(int) $match[1], $source];
}

function count_spine_requires(string $spineMain): int
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

function read_inventory_total(string $inventoryDoc): int
{
    if (!is_readable($inventoryDoc)) {
        return 0;
    }
    if (!preg_match('/\| PHP files on vm\.php path \| (\d+) \|/', (string) file_get_contents($inventoryDoc), $match)) {
        return 0;
    }

    return (int) $match[1];
}

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
        $errors[] = "{$rel}: missing spine tracker {$spineCount}/{$inventoryTotal} (canonical from BootstrapSelfhostBundleTest)";
    }

    if (!preg_match('/\*\*'.$spineCount.'\*\*|\b'.$spineCount.'\b/', $doc)) {
        $errors[] = "{$rel}: missing canonical spine count {$spineCount}";
    }

    if (!preg_match('/\*\*'.$inventoryTotal.'\*\*|\b'.$inventoryTotal.'\b/', $doc)) {
        $errors[] = "{$rel}: missing inventory total {$inventoryTotal}";
    }

    // Reject stale M2 ratio footnotes (e.g. 358/586, 578/586) when they disagree with canonical.
    if (preg_match_all('/\b(\d{2,4})\s*\/\s*'.$inventoryTotal.'\b/', $doc, $ratioMatches, PREG_SET_ORDER)) {
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
        $errors[] = 'BootstrapSelfhostBundleTest.php: assertSame spine count out of sync with bundle';
    }
}
