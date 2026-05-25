#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard M2 self-host spine counts against doc drift (issue #1834).
 *
 * Canonical spine count: require_once lines in compiler_lib_spine_smoke/main.php,
 * cross-checked against BootstrapSelfhostBundleTest::testCompilerLibSpineSmokeBundleUnitCountAndKeyUnits.
 * Inventory total: docs/bootstrap-inventory.md "PHP files on vm.php path" row.
 *
 * ROADMAP #78 is manual — do not duplicate counts there; link docs/self-host-target.md instead.
 *
 * Usage:
 *   php script/check-selfhost-spine-count-sync.php
 */

$root = dirname(__DIR__);
$spineMain = $root.'/test/selfhost/compiler_lib_spine_smoke/main.php';
$bundleTest = $root.'/test/unit/BootstrapSelfhostBundleTest.php';
$inventoryDoc = $root.'/docs/bootstrap-inventory.md';

$errors = [];

if (!is_readable($spineMain)) {
    fwrite(STDERR, "check-selfhost-spine-count-sync: missing {$spineMain}\n");
    exit(1);
}

$spineContents = (string) file_get_contents($spineMain);
$spineCount = substr_count($spineContents, 'require_once __DIR__');
if ($spineCount <= 0) {
    $errors[] = 'compiler_lib_spine_smoke/main.php: no require_once __DIR__ lines found';
}

$expectedFromTest = null;
if (is_readable($bundleTest)) {
    $testBody = (string) file_get_contents($bundleTest);
    if (preg_match(
        '/testCompilerLibSpineSmokeBundleUnitCountAndKeyUnits[\s\S]*?assertSame\((\d+),\s*\$count/',
        $testBody,
        $testMatch
    )) {
        $expectedFromTest = (int) $testMatch[1];
    } else {
        $errors[] = 'BootstrapSelfhostBundleTest.php: could not parse spine assertSame count';
    }
} else {
    $errors[] = 'missing test/unit/BootstrapSelfhostBundleTest.php';
}

if (null !== $expectedFromTest && $spineCount > 0 && $expectedFromTest !== $spineCount) {
    $errors[] = "spine main.php count {$spineCount} != BootstrapSelfhostBundleTest expected {$expectedFromTest}";
}

$inventoryTotal = 0;
if (is_readable($inventoryDoc)
    && preg_match('/\| PHP files on vm\.php path \| (\d+) \|/', (string) file_get_contents($inventoryDoc), $invMatch)) {
    $inventoryTotal = (int) $invMatch[1];
} else {
    $errors[] = 'docs/bootstrap-inventory.md: missing "PHP files on vm.php path" row';
}

$docChecks = [
    'docs/roadmap-wave3.md' => [
        'label' => 'roadmap-wave3.md M2 spine summary',
        'requirePair' => true,
    ],
    'docs/self-host-target.md' => [
        'label' => 'self-host-target.md M2 tables',
        'requirePair' => true,
    ],
    'README.md' => [
        'label' => 'README.md self-host row',
        'requirePair' => true,
    ],
    'docs/pages/development-status.md' => [
        'label' => 'development-status.md North Star 2',
        'requirePair' => true,
    ],
    'docs/bootstrap-selfhost.md' => [
        'label' => 'bootstrap-selfhost.md M2 lint row',
        'requirePair' => false,
    ],
];

foreach ($docChecks as $rel => $meta) {
    $path = $root.'/'.$rel;
    if (!is_readable($path)) {
        $errors[] = "missing doc: {$rel}";
        continue;
    }
    if ($spineCount <= 0 || $inventoryTotal <= 0) {
        continue;
    }
    $doc = (string) file_get_contents($path);
    $label = $meta['label'];
    $hasSpine = (bool) preg_match('/\*\*'.$spineCount.'\*\*/', $doc);
    $hasInventory = (bool) preg_match('/\*\*'.$inventoryTotal.'\*\*/', $doc);
    $hasPair = (bool) preg_match(
        '/\*\*'.$spineCount.'\*\*\/\*\*'.$inventoryTotal.'\*\*'
        .'|\*\*'.$spineCount.'\/'.$inventoryTotal.'\*\*'
        .'|\*\*'.$spineCount.'\*\* \/ '.$inventoryTotal
        .'|\*\*'.$spineCount.'\*\* \/ \*\*'.$inventoryTotal.'\*\*'
        .'|\*\*'.$spineCount.' \/ '.$inventoryTotal
        .'|\b'.$spineCount.'\/'.$inventoryTotal.'\b/',
        $doc
    );
    if ($meta['requirePair']) {
        if (!$hasSpine || !$hasInventory || !$hasPair) {
            $errors[] = "{$rel}: {$label} stale (expected {$spineCount}/{$inventoryTotal} footnotes)";
        }
    } elseif (!$hasSpine) {
        $errors[] = "{$rel}: {$label} missing spine count **{$spineCount}**";
    }
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-selfhost-spine-count-sync: {$err}\n");
    }
    fwrite(STDERR, "check-selfhost-spine-count-sync: FAILED — sync spine footnotes (issue #1834).\n");
    exit(1);
}

fwrite(
    STDOUT,
    "check-selfhost-spine-count-sync: OK (spine {$spineCount}/{$inventoryTotal}, test expected {$expectedFromTest})\n"
);
exit(0);
