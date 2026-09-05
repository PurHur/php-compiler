#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Weekly status report (#36404).
 *
 * Reads committed docs/status-snapshot.json (+ optional prior week metrics),
 * writes docs/pages/status/<date>.md and docs/pages/status/latest.md.
 *
 * Usage:
 *   php script/status/report.php
 *   php script/status/report.php --date=2026-09-05
 *   php script/status/report.php --with-github
 *   php script/status/report.php --stdout
 *
 * No GitHub Actions — local-ci-only. Cron `make status-report` on the maintainer host.
 */

$root = dirname(__DIR__, 2);
require_once $root.'/script/status/report-lib.php';

$withGithub = in_array('--with-github', $argv, true);
$stdoutOnly = in_array('--stdout', $argv, true);
$date = gmdate('Y-m-d');
$tracker = '36379';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--date=')) {
        $date = substr($arg, 7);
    }
    if (str_starts_with($arg, '--tracker=')) {
        $tracker = substr($arg, 10);
    }
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    fwrite(STDERR, "status-report: invalid --date={$date} (want YYYY-MM-DD)\n");
    exit(1);
}

$snapshotPath = $root.'/docs/status-snapshot.json';
if (!is_readable($snapshotPath)) {
    fwrite(STDERR, "status-report: missing {$snapshotPath} — run php script/status/snapshot.php first\n");
    exit(1);
}
$snapshot = json_decode((string) file_get_contents($snapshotPath), true);
if (!is_array($snapshot)) {
    fwrite(STDERR, "status-report: invalid JSON in {$snapshotPath}\n");
    exit(1);
}

$statusDir = $root.'/docs/pages/status';
$previous = status_report_load_previous_metrics($statusDir, $date);

$p0 = [];
$p1 = [];
if ($withGithub) {
    $p0 = status_report_fetch_github_issues('label:release-blocker,label:"MOST IMPORTANT"');
    if ($p0 === []) {
        $p0 = status_report_fetch_github_issues('label:release-blocker');
    }
    $p1 = status_report_fetch_github_issues('label:IMPORTANT');
}

$md = status_report_render_markdown($snapshot, $previous, $date, $p0, $p1, $tracker);
$md .= "\n".status_report_metrics_comment($snapshot)."\n";

if ($stdoutOnly) {
    fwrite(STDOUT, $md);
    exit(0);
}

if (!is_dir($statusDir) && !mkdir($statusDir, 0775, true) && !is_dir($statusDir)) {
    fwrite(STDERR, "status-report: cannot create {$statusDir}\n");
    exit(1);
}

$dated = $statusDir.'/'.$date.'.md';
$latest = $statusDir.'/latest.md';
if (false === file_put_contents($dated, $md)) {
    fwrite(STDERR, "status-report: failed writing {$dated}\n");
    exit(1);
}
if (false === file_put_contents($latest, $md)) {
    fwrite(STDERR, "status-report: failed writing {$latest}\n");
    exit(1);
}

fwrite(STDOUT, "status-report: wrote {$dated}\n");
fwrite(STDOUT, "status-report: wrote {$latest}\n");
if ($withGithub) {
    fwrite(STDOUT, 'status-report: GitHub open P0='.count($p0).' P1='.count($p1)."\n");
} else {
    fwrite(STDOUT, "status-report: skipped GitHub issue lists (pass --with-github)\n");
}
exit(0);
