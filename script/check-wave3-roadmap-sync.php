#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard Wave 3 roadmap docs against internal drift (issue #1802).
 *
 * Checks:
 *  - docs/roadmap-wave3.md summary Done/Open counts match detail tables
 *  - M2 spine require_once count matches tracked docs and inventory total
 *
 * Usage:
 *   php script/check-wave3-roadmap-sync.php
 */

require __DIR__.'/bootstrap-spine-count.php';

$root = dirname(__DIR__);
$roadmap = $root.'/docs/roadmap-wave3.md';
$spineMain = $root.'/test/selfhost/compiler_lib_spine_smoke/main.php';
$inventoryDoc = $root.'/docs/bootstrap-inventory.md';

$errors = [];

if (!is_readable($roadmap)) {
    fwrite(STDERR, "check-wave3-roadmap-sync: missing {$roadmap}\n");
    exit(1);
}

$text = (string) file_get_contents($roadmap);

$summaryOpen = '(\d+)(?:\s*\(\[#\d+\]\([^)]+\)\))?';

if (!preg_match(
    '/\| Language \(#1354–#1366\) \| (\d+) \| '.$summaryOpen.' \|/',
    $text,
    $langSummary
)) {
    $errors[] = 'roadmap-wave3.md: Language summary row missing';
} else {
    $langDone = (int) $langSummary[1];
    $langOpen = (int) $langSummary[2];
}

if (!preg_match(
    '/\| Stdlib \(#1367–#1379\) \| (\d+) \| '.$summaryOpen.' \|/',
    $text,
    $stdlibSummary
)) {
    $errors[] = 'roadmap-wave3.md: Stdlib summary row missing';
} else {
    $stdlibDone = (int) $stdlibSummary[1];
    $stdlibOpen = (int) $stdlibSummary[2];
}

$langSection = extract_section($text, '## Language (#1354–#1366)', '## Stdlib (#1367–#1379)');
$stdlibSection = extract_section($text, '## Stdlib (#1367–#1379)', '## Do not duplicate');

$langRows = count_status_rows($langSection);
$stdlibRows = count_status_rows($stdlibSection);

if (isset($langDone, $langOpen)) {
    if ($langRows['Closed'] !== $langDone) {
        $errors[] = "roadmap-wave3.md: Language Closed {$langRows['Closed']} != summary Done {$langDone}";
    }
    if ($langRows['Open'] !== $langOpen) {
        $errors[] = "roadmap-wave3.md: Language Open {$langRows['Open']} != summary Open {$langOpen}";
    }
    $langTotal = $langRows['Closed'] + $langRows['Open'] + $langRows['other'];
    if (12 !== $langTotal) {
        $errors[] = "roadmap-wave3.md: Language table has {$langTotal} rows (expected 12; #1355 unused)";
    }
}

if (isset($stdlibDone, $stdlibOpen)) {
    if ($stdlibRows['Closed'] !== $stdlibDone) {
        $errors[] = "roadmap-wave3.md: Stdlib Closed {$stdlibRows['Closed']} != summary Done {$stdlibDone}";
    }
    if ($stdlibRows['Open'] !== $stdlibOpen) {
        $errors[] = "roadmap-wave3.md: Stdlib Open {$stdlibRows['Open']} != summary Open {$stdlibOpen}";
    }
    $stdlibTotal = $stdlibRows['Closed'] + $stdlibRows['Open'] + $stdlibRows['other'];
    if (13 !== $stdlibTotal) {
        $errors[] = "roadmap-wave3.md: Stdlib table has {$stdlibTotal} rows (expected 13 for #1367–#1379)";
    }
}

$spineCount = 0;
$inventoryTotal = 0;
if (is_readable($spineMain)) {
    $counts = bootstrap_spine_counts($root);
    $spineCount = $counts['spine'];
    $inventoryTotal = $counts['inventory'];
} else {
    $errors[] = "missing spine bundle: {$spineMain}";
}

if ($inventoryTotal <= 0 && is_readable($inventoryDoc)
    && preg_match('/\| PHP files on vm\.php path \| (\d+) \|/', (string) file_get_contents($inventoryDoc), $invMatch)) {
    $inventoryTotal = (int) $invMatch[1];
}

if ($spineCount > 0 && !preg_match('/\*\*'.$spineCount.'\*\* \/ \*\*(\d+)\*\*/', $text, $spineDoc)) {
    $errors[] = "roadmap-wave3.md: M2 spine line missing **{$spineCount}** / **<total>**";
} elseif ($spineCount > 0 && isset($spineDoc[1]) && (int) $spineDoc[1] !== $inventoryTotal && $inventoryTotal > 0) {
    $errors[] = "roadmap-wave3.md: spine inventory total {$spineDoc[1]} != bootstrap-inventory {$inventoryTotal}";
}

$spineDocs = [
    'docs/self-host-target.md',
    'docs/bootstrap-selfhost.md',
    'docs/pages/development-status.md',
];
foreach ($spineDocs as $rel) {
    $path = $root.'/'.$rel;
    if (!is_readable($path)) {
        $errors[] = "missing doc: {$rel}";
        continue;
    }
    $doc = (string) file_get_contents($path);
    if ($spineCount <= 0) {
        continue;
    }
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
    if (!$hasSpine || !$hasInventory || (!$hasPair && 'docs/bootstrap-selfhost.md' !== $rel)) {
        $errors[] = "{$rel}: spine tracker stale (expected {$spineCount}/{$inventoryTotal} footnotes)";
    }
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-wave3-roadmap-sync: {$err}\n");
    }
    fwrite(STDERR, "check-wave3-roadmap-sync: FAILED — sync docs/roadmap-wave3.md and spine footnotes (issue #1802).\n");
    exit(1);
}

fwrite(STDOUT, "check-wave3-roadmap-sync: OK (language open {$langRows['Open']}, stdlib open {$stdlibRows['Open']}, spine {$spineCount}/{$inventoryTotal})\n");
exit(0);

/**
 * @return array{Closed: int, Open: int, other: int}
 */
function count_status_rows(string $section): array
{
    $counts = ['Closed' => 0, 'Open' => 0, 'other' => 0];
    foreach (explode("\n", $section) as $line) {
        if (!str_contains($line, '| [#')) {
            continue;
        }
        if (preg_match('/\| Closed/', $line)) {
            ++$counts['Closed'];
        } elseif (preg_match('/\| Open \|/', $line)) {
            ++$counts['Open'];
        } else {
            ++$counts['other'];
        }
    }

    return $counts;
}

function extract_section(string $text, string $start, string $end): string
{
    $pos = strpos($text, $start);
    if (false === $pos) {
        return '';
    }
    $pos += strlen($start);
    $endPos = strpos($text, $end, $pos);

    return false === $endPos ? substr($text, $pos) : substr($text, $pos, $endPos - $pos);
}
