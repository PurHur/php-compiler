#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard M2 spine per-file issues against stale open tickets (issue #1808).
 *
 * Parses require_once paths from compiler_lib_spine_smoke and compares them to
 * open GitHub issues labeled m2-spine-unit. Stale = issue title references a
 * file already bundled on master.
 *
 * Usage:
 *   php script/check-m2-spine-issue-hygiene.php              # check (exit 1 if stale)
 *   php script/check-m2-spine-issue-hygiene.php --list       # print stale issue numbers
 *   php script/check-m2-spine-issue-hygiene.php --close      # close stale via gh
 *   php script/check-m2-spine-issue-hygiene.php --update-fixture  # refresh docs/fixtures (needs gh)
 *
 * Opt-in CI gate: M2_SPINE_ISSUE_HYGIENE_GATE=1 (see script/ci-common.sh).
 */

$root = dirname(__DIR__);
$spineMain = $root.'/test/selfhost/compiler_lib_spine_smoke/main.php';
$inventoryDoc = $root.'/docs/bootstrap-inventory.md';

$listOnly = in_array('--list', $argv, true);
$closeStale = in_array('--close', $argv, true);
$updateFixture = in_array('--update-fixture', $argv, true);
$fixturePath = $root.'/docs/fixtures/m2-spine-open-issues.json';

if (!is_readable($spineMain)) {
    fwrite(STDERR, "check-m2-spine-issue-hygiene: missing {$spineMain}\n");
    exit(1);
}

$spinePaths = parse_spine_paths($spineMain);
$spineSet = array_flip($spinePaths);
$spineCount = count($spinePaths);

$inventoryTotal = 586;
if (is_readable($inventoryDoc)
    && preg_match('/\| PHP files on vm\.php path \| (\d+) \|/', (string) file_get_contents($inventoryDoc), $invMatch)) {
    $inventoryTotal = (int) $invMatch[1];
}

$remainingGap = max(0, $inventoryTotal - $spineCount);
$openMax = min(20, $remainingGap + 5);

$issues = fetch_open_spine_issues($root, $fixturePath);
if ($updateFixture) {
    if (!gh_available()) {
        fwrite(STDERR, "check-m2-spine-issue-hygiene: --update-fixture requires gh on PATH\n");
        exit(1);
    }
    $live = fetch_open_spine_issues_from_gh();
    file_put_contents($fixturePath, json_encode($live, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    fwrite(STDOUT, 'check-m2-spine-issue-hygiene: wrote '.count($live)." open issues to {$fixturePath}\n");
    exit(0);
}
$stale = [];
$legitimate = [];

foreach ($issues as $issue) {
    $path = parse_issue_path($issue['title'] ?? '');
    if (null !== $path && isset($spineSet[$path])) {
        $stale[] = ['number' => (int) $issue['number'], 'path' => $path, 'title' => $issue['title']];
        continue;
    }
    $legitimate[] = $issue;
}

if ($listOnly) {
    foreach ($stale as $row) {
        fwrite(STDOUT, $row['number']."\t".$row['path']."\n");
    }
    exit(0);
}

if ($closeStale) {
    if ([] === $stale) {
        fwrite(STDOUT, "check-m2-spine-issue-hygiene: no stale issues to close\n");
        exit(0);
    }
    $comment = 'Already in spine smoke on master ('.$spineCount.'/'.$inventoryTotal.'); tracked on #1419';
    foreach ($stale as $row) {
        $n = (int) $row['number'];
        $cmd = 'gh issue close '.(string) $n.' --comment '.escapeshellarg($comment).' 2>&1';
        exec($cmd, $out, $code);
        if (0 !== $code) {
            fwrite(STDERR, "check-m2-spine-issue-hygiene: failed to close #{$n}: ".implode("\n", $out)."\n");
            exit(1);
        }
        fwrite(STDOUT, "closed #{$n} ({$row['path']})\n");
    }
    exit(0);
}

$errors = [];
if ([] !== $stale) {
    $sample = array_slice(array_map(
        static fn (array $row): string => '#'.$row['number'].' '.$row['path'],
        $stale
    ), 0, 8);
    $errors[] = count($stale).' stale open m2-spine-unit issue(s) (file already in spine): '
        .implode(', ', $sample)
        .(count($stale) > 8 ? ', …' : '')
        .'; run php script/check-m2-spine-issue-hygiene.php --close';
}

$openCount = count($issues);
if ($openCount > $openMax) {
    $errors[] = "open m2-spine-unit count {$openCount} exceeds expected max {$openMax} "
        ."(spine {$spineCount}/{$inventoryTotal}, remaining gap {$remainingGap})";
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-m2-spine-issue-hygiene: {$err}\n");
    }
    fwrite(STDERR, "check-m2-spine-issue-hygiene: FAILED (issue #1808)\n");
    exit(1);
}

fwrite(
    STDOUT,
    "check-m2-spine-issue-hygiene: OK (spine {$spineCount}/{$inventoryTotal}, "
    .count($legitimate)." open m2-spine-unit, 0 stale)\n"
);
exit(0);

/**
 * @return list<string>
 */
function parse_spine_paths(string $mainPhp): array
{
    $paths = [];
    foreach (file($mainPhp, FILE_IGNORE_NEW_LINES) as $line) {
        if (!str_starts_with($line, 'require_once')) {
            continue;
        }
        if (preg_match("#require_once __DIR__\\.'/\\.\\./\\.\\./\\.\\./([^']+)'#", $line, $m)) {
            $paths[] = $m[1];
        }
    }

    return $paths;
}

function parse_issue_path(string $title): ?string
{
    if (preg_match('/`([^`]+\.php)`/', $title, $m)) {
        return $m[1];
    }

    return null;
}

/**
 * @return list<array{number: int|string, title: string}>
 */
function fetch_open_spine_issues(string $root, string $fixturePath): array
{
    if (gh_available()) {
        return fetch_open_spine_issues_from_gh();
    }
    if (!is_readable($fixturePath)) {
        fwrite(STDERR, "check-m2-spine-issue-hygiene: gh unavailable and missing fixture {$fixturePath}\n");
        exit(1);
    }
    $decoded = json_decode((string) file_get_contents($fixturePath), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "check-m2-spine-issue-hygiene: invalid fixture JSON\n");
        exit(1);
    }

    return $decoded;
}

function gh_available(): bool
{
    exec('command -v gh >/dev/null 2>&1', $_, $code);

    return 0 === $code;
}

/**
 * @return list<array{number: int|string, title: string}>
 */
function fetch_open_spine_issues_from_gh(): array
{
    $cmd = 'gh issue list --state open --label m2-spine-unit --limit 500 --json number,title 2>&1';
    exec($cmd, $out, $code);
    if (0 !== $code) {
        fwrite(STDERR, "check-m2-spine-issue-hygiene: gh issue list failed: ".implode("\n", $out)."\n");
        exit(1);
    }
    $decoded = json_decode(implode("\n", $out), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "check-m2-spine-issue-hygiene: invalid gh JSON\n");
        exit(1);
    }

    return $decoded;
}
