#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Gate: a PR may say "Closes #N" only when every Done-when checkbox from issue N
 * appears ticked in the PR body (#36400).
 *
 * Usage:
 *   php script/check-issue-close-scope.php --pr-body FILE --issue-body FILE [--issue-number N]
 *   php script/check-issue-close-scope.php --pr-body FILE [--repo OWNER/REPO]
 *   php script/check-issue-close-scope.php --self-test
 *
 * Exit 0 when every Closes #N is backed by a full ticked Done-when copy.
 * Exit 1 when a Closes #N lacks matching `- [x]` lines (or the issue has no Done when).
 * "Part of #N" alone does not trigger the gate.
 */

$root = dirname(__DIR__);
$selfTest = in_array('--self-test', $argv, true);

if ($selfTest) {
    exit(run_self_test($root));
}

$prBodyPath = null;
$issueBodyPath = null;
$issueNumber = null;
$repo = 'PurHur/php-compiler';

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--pr-body' && isset($argv[$i + 1])) {
        $prBodyPath = $argv[++$i];
        continue;
    }
    if ($arg === '--issue-body' && isset($argv[$i + 1])) {
        $issueBodyPath = $argv[++$i];
        continue;
    }
    if ($arg === '--issue-number' && isset($argv[$i + 1])) {
        $issueNumber = (int) $argv[++$i];
        continue;
    }
    if ($arg === '--repo' && isset($argv[$i + 1])) {
        $repo = $argv[++$i];
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: check-issue-close-scope.php --pr-body FILE [--issue-body FILE|--repo OWNER/REPO] [--self-test]\n");
        exit(0);
    }
}

if ($prBodyPath === null || !is_readable($prBodyPath)) {
    fwrite(STDERR, "check-issue-close-scope: --pr-body FILE is required and must be readable\n");
    exit(1);
}

$prBody = (string) file_get_contents($prBodyPath);
$closes = extract_closes_numbers($prBody);
if ($closes === []) {
    fwrite(STDOUT, "check-issue-close-scope: OK (no Closes #N in PR body)\n");
    exit(0);
}

$errors = [];
if ($issueBodyPath !== null) {
    if (!is_readable($issueBodyPath)) {
        fwrite(STDERR, "check-issue-close-scope: --issue-body not readable: {$issueBodyPath}\n");
        exit(1);
    }
    $issueBody = (string) file_get_contents($issueBodyPath);
    $n = $issueNumber ?? ($closes[0] ?? 0);
    $errors = array_merge($errors, validate_closes_against_issue($n, $issueBody, $prBody));
    foreach ($closes as $other) {
        if ($other !== $n) {
            $errors[] = "Closes #{$other} present but only --issue-body for #{$n} was supplied; fetch live or pass one issue at a time";
        }
    }
} else {
    foreach ($closes as $n) {
        $issueBody = fetch_issue_body($repo, $n);
        if ($issueBody === null) {
            $errors[] = "Closes #{$n}: could not fetch issue body via gh (repo {$repo})";
            continue;
        }
        $errors = array_merge($errors, validate_closes_against_issue($n, $issueBody, $prBody));
    }
}

if ($errors !== []) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-issue-close-scope: {$err}\n");
    }
    fwrite(STDERR, "check-issue-close-scope: use \"Part of #N\" for partial work, or tick every Done-when box in the PR body (#36400)\n");
    exit(1);
}

fwrite(STDOUT, 'check-issue-close-scope: OK ('.count($closes)." Closes # covered)\n");
exit(0);

/**
 * @return list<int>
 */
function extract_closes_numbers(string $body): array
{
    // Do not treat "Part of #N" as a close. Match Closes/Fixes/Resolves variants.
    if (!preg_match_all(
        '/\b(?:close[sd]?|fix(?:e[sd])?|resolve[sd]?)\s+#(\d+)\b/i',
        $body,
        $m
    )) {
        return [];
    }
    $nums = array_map('intval', $m[1]);
    $nums = array_values(array_unique($nums));
    sort($nums);

    return $nums;
}

/**
 * @return list<string> normalized checkbox texts (no leading - [x]/ empty if none
 */
function extract_done_when_items(string $issueBody): array
{
    if (!preg_match('/^##\s+Done when\s*$/mi', $issueBody, $m, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    $start = (int) $m[0][1] + strlen($m[0][0]);
    $rest = substr($issueBody, $start);
    // Stop at next ## heading or EOF
    if (preg_match('/^##\s+/m', $rest, $next, PREG_OFFSET_CAPTURE)) {
        $rest = substr($rest, 0, (int) $next[0][1]);
    }
    $items = [];
    if (preg_match_all('/^\s*-\s*\[([ xX])\]\s*(.+?)\s*$/m', $rest, $boxes, PREG_SET_ORDER)) {
        foreach ($boxes as $box) {
            $text = normalize_checkbox_text($box[2]);
            if ($text !== '') {
                $items[] = $text;
            }
        }
    }

    return $items;
}

function normalize_checkbox_text(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);

    return $text;
}

/**
 * @return list<string> ticked checkbox texts in PR body
 */
function extract_ticked_items(string $prBody): array
{
    $items = [];
    if (preg_match_all('/^\s*-\s*\[[xX]\]\s*(.+?)\s*$/m', $prBody, $boxes, PREG_SET_ORDER)) {
        foreach ($boxes as $box) {
            $text = normalize_checkbox_text($box[1]);
            if ($text !== '') {
                $items[] = $text;
            }
        }
    }

    return $items;
}

/**
 * @return list<string> error messages
 */
function validate_closes_against_issue(int $issueNumber, string $issueBody, string $prBody): array
{
    $errors = [];
    $required = extract_done_when_items($issueBody);
    if ($required === []) {
        $errors[] = "Closes #{$issueNumber}: issue has no ## Done when checklist — add one before closing, or use Part of #{$issueNumber}";

        return $errors;
    }
    $ticked = extract_ticked_items($prBody);
    $tickedSet = array_fill_keys($ticked, true);
    foreach ($required as $item) {
        if (!isset($tickedSet[$item])) {
            $errors[] = "Closes #{$issueNumber}: missing ticked Done-when item: `- [x] {$item}`";
        }
    }

    return $errors;
}

function fetch_issue_body(string $repo, int $number): ?string
{
    $cmd = 'gh issue view '.escapeshellarg((string) $number)
        .' --repo '.escapeshellarg($repo)
        .' --json body -q .body 2>/dev/null';
    $out = shell_exec($cmd);
    if ($out === null || $out === '') {
        return null;
    }

    return $out;
}

function run_self_test(string $root): int
{
    $fixtureDir = $root.'/test/fixtures/issue-close-scope';
    $issue = $fixtureDir.'/sample-issue.md';
    $bad = $fixtureDir.'/pr-closes-without-ticks.md';
    $good = $fixtureDir.'/pr-closes-with-ticks.md';
    $part = $fixtureDir.'/pr-part-of-only.md';
    foreach ([$issue, $bad, $good, $part] as $path) {
        if (!is_readable($path)) {
            fwrite(STDERR, "check-issue-close-scope: missing fixture {$path}\n");

            return 1;
        }
    }

    $php = escapeshellarg(PHP_BINARY);
    $script = escapeshellarg($root.'/script/check-issue-close-scope.php');

    $badCmd = "{$php} {$script} --pr-body ".escapeshellarg($bad)
        .' --issue-body '.escapeshellarg($issue).' --issue-number 36400';
    exec($badCmd.' 2>&1', $badOut, $badCode);
    if ($badCode === 0) {
        fwrite(STDERR, "check-issue-close-scope: self-test FAIL — expected reject for Closes without ticks\n");
        fwrite(STDERR, implode("\n", $badOut)."\n");

        return 1;
    }
    $badText = implode("\n", $badOut);
    if (!str_contains($badText, 'missing ticked Done-when item')) {
        fwrite(STDERR, "check-issue-close-scope: self-test FAIL — expected missing-tick message\n{$badText}\n");

        return 1;
    }

    $goodCmd = "{$php} {$script} --pr-body ".escapeshellarg($good)
        .' --issue-body '.escapeshellarg($issue).' --issue-number 36400';
    exec($goodCmd.' 2>&1', $goodOut, $goodCode);
    if ($goodCode !== 0) {
        fwrite(STDERR, "check-issue-close-scope: self-test FAIL — expected OK for fully ticked Closes\n");
        fwrite(STDERR, implode("\n", $goodOut)."\n");

        return 1;
    }

    $partCmd = "{$php} {$script} --pr-body ".escapeshellarg($part)
        .' --issue-body '.escapeshellarg($issue).' --issue-number 36400';
    exec($partCmd.' 2>&1', $partOut, $partCode);
    if ($partCode !== 0) {
        fwrite(STDERR, "check-issue-close-scope: self-test FAIL — Part of #N must pass without ticks\n");
        fwrite(STDERR, implode("\n", $partOut)."\n");

        return 1;
    }

    fwrite(STDOUT, "check-issue-close-scope: self-test OK (reject bare Closes; accept ticked Closes; accept Part of)\n");

    return 0;
}
