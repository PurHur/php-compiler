#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Local CI green-streak metric for #36401.
 *
 * Source of truth: docs/ci-streak.json (committed). A day counts only when an
 * implementer records a verified green local gate set after the gates actually
 * ran — never by inventing a stamp. release-readiness --json embeds the
 * committed values; GitHub Actions is billing-disabled so this is not a GHA
 * check streak (.cursor/rules/local-ci-only.mdc).
 *
 * Usage:
 *   php script/ci-streak.php --json
 *   php script/ci-streak.php --record-green --sha=<40hex> --day=YYYY-MM-DD [--write]
 *
 * Exit: 0 on success, 1 on usage/IO error, 2 on invalid input.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/script/status/ci-streak-lib.php';
$path = $root . '/docs/ci-streak.json';

$jsonOut = in_array('--json', $argv, true);
$record = in_array('--record-green', $argv, true);
$write = in_array('--write', $argv, true);

$sha = null;
$day = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--sha=')) {
        $sha = substr($arg, 6);
    } elseif (str_starts_with($arg, '--day=')) {
        $day = substr($arg, 6);
    }
}

if ($record) {
    if (!is_string($sha) || !preg_match('/^[0-9a-f]{7,40}$/i', $sha)) {
        fwrite(STDERR, "ci-streak: --record-green requires --sha=<git-sha>\n");
        exit(2);
    }
    if (!is_string($day) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        fwrite(STDERR, "ci-streak: --record-green requires --day=YYYY-MM-DD\n");
        exit(2);
    }

    $prev = ci_streak_load($path);
    $next = ci_streak_record($prev, $sha, $day);
    if ($write) {
        $encoded = json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (false === file_put_contents($path, $encoded)) {
            fwrite(STDERR, "ci-streak: failed writing {$path}\n");
            exit(1);
        }
        fwrite(STDERR, "ci-streak: wrote {$path} (streak_days={$next['ci_green_streak_days']}, sha={$next['last_green_master_sha']})\n");
    }
    echo json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

$doc = ci_streak_load($path);
echo json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
exit(0);
