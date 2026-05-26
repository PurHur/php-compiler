#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard M2 spine native-link deferred list vs public footnotes (issue #2202).
 *
 * Canonical deferred paths: script/bootstrap-spine-deferred-lib.php
 *
 * Usage:
 *   php script/check-selfhost-spine-deferred-sync.php
 */

require __DIR__.'/bootstrap-spine-count.php';
require_once __DIR__.'/bootstrap-spine-deferred-lib.php';

$root = dirname(__DIR__);
$errors = [];

$deferred = bootstrap_spine_native_link_deferred();
sort($deferred, SORT_STRING);
$deferredCount = count($deferred);

$counts = bootstrap_spine_counts($root);
$spineCount = $counts['spine'];
$inventoryTotal = $counts['inventory'];

if ($spineCount <= 0 || $inventoryTotal <= 0) {
    $errors[] = 'bootstrap-spine-count: missing spine or inventory totals';
}

if ($spineCount > 0 && $inventoryTotal > 0 && $spineCount + $deferredCount !== $inventoryTotal) {
    $errors[] = "spine {$spineCount} + deferred {$deferredCount} != inventory {$inventoryTotal} (update bootstrap-spine-deferred-lib.php or spine bundle)";
}

$coverageScript = $root.'/script/check-selfhost-spine-coverage-sync.php';
if (is_readable($coverageScript)) {
    $coverageSource = (string) file_get_contents($coverageScript);
    if (!str_contains($coverageSource, "require_once __DIR__.'/bootstrap-spine-deferred-lib.php'")
        || !str_contains($coverageSource, 'bootstrap_spine_native_link_deferred()')) {
        $errors[] = 'check-selfhost-spine-coverage-sync.php: must require bootstrap-spine-deferred-lib.php';
    }
}

$trackedDocs = [
    'README.md',
    'docs/roadmap-wave3.md',
    'docs/self-host-target.md',
    'docs/pages/development-status.md',
    'docs/bootstrap-selfhost.md',
    'test/unit/BootstrapSelfhostBundleTest.php',
];

$docsContent = [];
foreach ($trackedDocs as $rel) {
    $path = $root.'/'.$rel;
    if (!is_readable($path)) {
        $errors[] = "missing doc: {$rel}";
        continue;
    }
    $doc = (string) file_get_contents($path);
    $docsContent[$rel] = $doc;
    validate_deferred_footnotes($rel, $doc, $deferredCount, $spineCount, $inventoryTotal, $errors);
}

$allDocs = implode("\n", $docsContent);
foreach ($deferred as $path) {
    if (!str_contains($allDocs, '`'.$path.'`') && !str_contains($allDocs, $path)) {
        $errors[] = "tracked docs: missing deferred path {$path} (SSOT bootstrap-spine-deferred-lib.php)";
    }
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-selfhost-spine-deferred-sync: {$err}\n");
    }
    fwrite(STDERR, "check-selfhost-spine-deferred-sync: FAILED — sync deferred list with spine footnotes (#2202).\n");
    exit(1);
}

$deferredNote = $deferredCount > 0 ? implode(', ', $deferred) : '(none)';
fwrite(STDOUT, "check-selfhost-spine-deferred-sync: OK ({$deferredCount} deferred: {$deferredNote}; spine {$spineCount}/{$inventoryTotal})\n");
exit(0);

/**
 * @param list<string> $errors
 */
function validate_deferred_footnotes(
    string $rel,
    string $doc,
    int $deferredCount,
    int $spineCount,
    int $inventoryTotal,
    array &$errors
): void {
    if ($deferredCount > 0) {
        if (!preg_match('/\b'.$deferredCount.'\s+deferred\b/i', $doc)) {
            $errors[] = "{$rel}: missing {$deferredCount} deferred footnote";
        }
        if ($spineCount > 0 && $inventoryTotal > 0 && !doc_has_spine_ratio($doc, $spineCount, $inventoryTotal)) {
            $errors[] = "{$rel}: missing spine ratio {$spineCount}/{$inventoryTotal} with deferred footnote";
        }

        return;
    }

    if (preg_match('/\b[1-9]\d*\s+deferred\b/i', $doc)) {
        $errors[] = "{$rel}: stale deferred footnote (SSOT list is empty; shrink bootstrap-spine-deferred-lib.php)";
    }
}

function doc_has_spine_ratio(string $doc, int $spineCount, int $inventoryTotal): bool
{
    $pairPatterns = [
        '/\*\*'.$spineCount.'\*\*\/\*\*'.$inventoryTotal.'\*\*/',
        '/\*\*'.$spineCount.'\/'.$inventoryTotal.'\*\*/',
        '/\*\*'.$spineCount.'\*\* \/ \*\*'.$inventoryTotal.'\*\*/',
        '/\*\*'.$spineCount.'\*\* \/ '.$inventoryTotal.'/',
        '/\*\*'.$spineCount.' \/ '.$inventoryTotal.'\*\*/',
        '/\*\*'.$spineCount.' \/ '.$inventoryTotal.' /',
        '/\b'.$spineCount.'\/'.$inventoryTotal.'\b/',
        '/\b'.$spineCount.'\s*\/\s*'.$inventoryTotal.'\b/',
    ];
    foreach ($pairPatterns as $pattern) {
        if (preg_match($pattern, $doc)) {
            return true;
        }
    }

    return false;
}
