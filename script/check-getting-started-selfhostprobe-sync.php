#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard docs/GETTING-STARTED.md §6 (008-SelfHostProbe presenter) against drift (issue #2230).
 *
 * Requires harness-safe commands and North Star 2 smoke copy-paste blocks after #2222.
 * Enable in CI via GETTING_STARTED_SELFHOSTPROBE_SYNC_GATE=1 (default 0 until §6 lands).
 *
 * Usage:
 *   php script/check-getting-started-selfhostprobe-sync.php
 */

$root = dirname(__DIR__);
$docPath = $root.'/docs/GETTING-STARTED.md';

if (!is_readable($docPath)) {
    fwrite(STDERR, "check-getting-started-selfhostprobe-sync: missing {$docPath}\n");
    exit(1);
}

$doc = (string) file_get_contents($docPath);
$errors = [];

foreach (preg_split("/\r\n|\n|\r/", $doc) ?: [] as $num => $line) {
    if (!preg_match('/docker run[^\n]*-v[^\n]*["\']?\$\(pwd\)[^\n]*\/compiler/', $line)) {
        continue;
    }
    $plain = preg_replace('/\*\*/', '', $line) ?? $line;
    if (preg_match('/\b(do not|don\'t|must not)\b/i', $plain) || preg_match('/not\s+use/i', $plain)) {
        continue;
    }
    $lineNo = $num + 1;
    $errors[] = "docs/GETTING-STARTED.md:{$lineNo}: forbidden raw docker bind-mount; use make test-harness or ./script/docker-exec.sh (#272, #2245)";
}

if (!preg_match('/^### 6\./m', $doc)) {
    $errors[] = 'docs/GETTING-STARTED.md: missing §6 heading (### 6.)';
    $section6 = '';
} elseif (!preg_match('/^### 6\..*?(?=^### 7\.)/ms', $doc, $match)) {
    $errors[] = 'docs/GETTING-STARTED.md: could not extract §6 body before §7';
    $section6 = '';
} else {
    $section6 = $match[0];
}

$needles = [
    '008-SelfHostProbe' => 'examples/008-SelfHostProbe path',
    'north-star2-verify' => 'make north-star2-verify (North Star 2 presenter)',
    'doctor --selfhost' => './phpc doctor --selfhost',
];

foreach ($needles as $needle => $label) {
    if ($section6 === '' || !str_contains($section6, $needle)) {
        $errors[] = "docs/GETTING-STARTED.md §6: missing {$label}";
    }
}

if ($section6 !== ''
    && !str_contains($section6, './script/docker-exec.sh')
    && !str_contains($section6, 'make test-harness')) {
    $errors[] = 'docs/GETTING-STARTED.md §6: missing harness-safe Docker path (docker-exec.sh or make test-harness)';
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-getting-started-selfhostprobe-sync: {$err}\n");
    }
    fwrite(STDERR, "check-getting-started-selfhostprobe-sync: FAILED (see #2222 §6, #2230)\n");
    exit(1);
}

fwrite(STDOUT, "check-getting-started-selfhostprobe-sync: OK\n");
exit(0);
