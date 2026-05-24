#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Wave 3 roadmap drift guard (issue #1802).
 *
 * Validates internal consistency of docs/roadmap-wave3.md (summary vs tables,
 * M2 spine require_once count vs bundle) without calling the GitHub API.
 *
 * Usage:
 *   php script/check-wave3-roadmap-sync.php
 *   WAVE3_ROADMAP_SYNC_GATE=1 ./script/ci-fast.sh
 */

$root = dirname(__DIR__);
$roadmap = $root.'/docs/roadmap-wave3.md';
$spineMain = $root.'/test/selfhost/compiler_lib_spine_smoke/main.php';

/** @var list<string> */
$errors = [];

if (!is_file($roadmap)) {
    fwrite(STDERR, "Missing {$roadmap}\n");
    exit(1);
}

$text = (string) file_get_contents($roadmap);

/**
 * @return array{done: int, open: int}
 */
function wave3_parse_summary_row(string $text, string $trackPrefix): array
{
    if (!preg_match(
        '/\|\s*'.preg_quote($trackPrefix, '/').'[^\|]*\|\s*(\d+)(?:[^\d\|][^\|]*)?\s*\|\s*(\d+)(?:[^\d\|][^\|]*)?\s*\|/m',
        $text,
        $m
    )) {
        return ['done' => -1, 'open' => -1];
    }

    return ['done' => (int) $m[1], 'open' => (int) $m[2]];
}

/**
 * @return array{open: int, closed: int}
 */
function wave3_count_table_status(string $sectionPrefix, string $text): array
{
    if (!preg_match('/## '.preg_quote($sectionPrefix, '/').'[^\n]*\n.*?(?=## |\z)/s', $text, $block)) {
        return ['open' => -1, 'closed' => -1];
    }
    $body = $block[0];
    $open = 0;
    $closed = 0;
    if (preg_match_all('/\|\s*\[#\d+\][^\|]*\|\s*[^|]+\|\s*([^|]+)\s*\|/m', $body, $rows, PREG_SET_ORDER)) {
        foreach ($rows as $row) {
            $status = trim($row[1]);
            if (stripos($status, 'open') === 0) {
                ++$open;
            } elseif (stripos($status, 'closed') === 0) {
                ++$closed;
            }
        }
    }

    return ['open' => $open, 'closed' => $closed];
}

function wave3_assert_summary_matches_table(
    string $track,
    array $summary,
    array $table,
    array &$errors
): void {
    if ($summary['done'] < 0 || $summary['open'] < 0) {
        $errors[] = "roadmap-wave3.md: missing Summary row for {$track}";

        return;
    }
    if ($table['open'] < 0 || $table['closed'] < 0) {
        $errors[] = "roadmap-wave3.md: could not parse {$track} status table";

        return;
    }
    if ($summary['done'] !== $table['closed']) {
        $errors[] = "roadmap-wave3.md: {$track} Summary Done={$summary['done']} but table has {$table['closed']} Closed rows";
    }
    if ($summary['open'] !== $table['open']) {
        $errors[] = "roadmap-wave3.md: {$track} Summary Open={$summary['open']} but table has {$table['open']} Open rows";
    }
}

$langSummary = wave3_parse_summary_row($text, 'Language (#1354');
$stdlibSummary = wave3_parse_summary_row($text, 'Stdlib (#1367');
$langTable = wave3_count_table_status('Language (#1354', $text);
$stdlibTable = wave3_count_table_status('Stdlib (#1367', $text);

wave3_assert_summary_matches_table('Language', $langSummary, $langTable, $errors);
wave3_assert_summary_matches_table('Stdlib', $stdlibSummary, $stdlibTable, $errors);

$spineCount = 0;
if (is_file($spineMain)) {
    $spineCount = substr_count((string) file_get_contents($spineMain), 'require_once __DIR__');
} else {
    $errors[] = "missing {$spineMain}";
}

if ($spineCount > 0 && !preg_match(
    '/\*\*M2 spine:\*\*\s*\*\*'.preg_quote((string) $spineCount, '/').'\*\*/',
    $text
)) {
    $errors[] = "roadmap-wave3.md: M2 spine count does not match bundle (expected **{$spineCount}**)";
}

/** @var list<string> */
$spineRatioDocs = [
    'docs/pages/development-status.md',
    'docs/self-host-target.md',
    'README.md',
];

foreach ($spineRatioDocs as $rel) {
    $path = $root.'/'.$rel;
    if (!is_file($path)) {
        $errors[] = "missing spine doc {$rel}";

        continue;
    }
    $doc = (string) file_get_contents($path);
    if (!preg_match('/\*\*'.preg_quote((string) $spineCount, '/').'\*\*\s*\/\s*\*\*586\*\*/', $doc)
        && !preg_match('/\*\*'.preg_quote((string) $spineCount, '/').'\*\*\s*\/\s*586/', $doc)
        && !preg_match('/'.preg_quote((string) $spineCount, '/').'\s*\/\s*586/', $doc)) {
        $errors[] = "{$rel}: expected M2 spine progress {$spineCount}/586 (issue #1802)";
    }
}

$bootstrapSelfhost = $root.'/docs/bootstrap-selfhost.md';
if (is_file($bootstrapSelfhost)) {
    $doc = (string) file_get_contents($bootstrapSelfhost);
    if (!preg_match('/\*\*'.preg_quote((string) $spineCount, '/').'\*\* units/', $doc)) {
        $errors[] = 'docs/bootstrap-selfhost.md: expected M2 spine lint row with **'.$spineCount.'** units';
    }
} else {
    $errors[] = 'missing docs/bootstrap-selfhost.md';
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-wave3-roadmap-sync: {$err}\n");
    }
    fwrite(STDERR, "Regenerate tables: edit docs/roadmap-wave3.md; spine count: grep -c \"^require_once\" test/selfhost/compiler_lib_spine_smoke/main.php\n");
    exit(1);
}

fwrite(STDOUT, "check-wave3-roadmap-sync: OK (language {$langTable['closed']}/{$langTable['open']}, stdlib {$stdlibTable['closed']}/{$stdlibTable['open']}, M2 spine {$spineCount}/586)\n");
exit(0);
