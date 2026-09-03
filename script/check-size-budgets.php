#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Size budgets + ratchet for load-bearing files (#36403).
 *
 * Budget file: script/size-budgets.json
 *   path => { "budget": int, "target": int, "note"?: string }
 *
 * Rules:
 * - Live line count > budget → fail (growth past the ratchet).
 * - budget < target is allowed (ahead of schedule).
 * - Empty / missing budget file → fail (not a pass-by-absence).
 * - script/ file count and ci-defaults.env export count are also budgeted.
 */

$root = dirname(__DIR__);
chdir($root);

$printOnly = in_array('--print', $argv, true);
if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    fwrite(STDOUT, "Usage: php script/check-size-budgets.php [--print]\n");
    exit(0);
}

const BUDGET_PATH = 'script/size-budgets.json';

$raw = @file_get_contents(BUDGET_PATH);
if (false === $raw || '' === trim($raw)) {
    fwrite(STDERR, "check-size-budgets: missing or empty ".BUDGET_PATH." — not a pass (#36403)\n");
    exit(1);
}
$cfg = json_decode($raw, true);
if (!is_array($cfg) || !isset($cfg['files']) || !is_array($cfg['files']) || [] === $cfg['files']) {
    fwrite(STDERR, "check-size-budgets: ".BUDGET_PATH." must define a non-empty files map (#36403)\n");
    exit(1);
}

/**
 * @return int
 */
function count_lines(string $path): int
{
    if (!is_file($path)) {
        throw new RuntimeException("missing file: {$path}");
    }
    $n = 0;
    $fh = fopen($path, 'rb');
    if (false === $fh) {
        throw new RuntimeException("cannot read: {$path}");
    }
    while (false !== fgets($fh)) {
        ++$n;
    }
    fclose($fh);

    return $n;
}

/**
 * Count top-level script/*.sh + script/*.php files (not subdirs).
 */
function count_script_files(string $dir): int
{
    $n = 0;
    foreach (scandir($dir) ?: [] as $name) {
        if ('.' === $name || '..' === $name) {
            continue;
        }
        $path = $dir.'/'.$name;
        if (!is_file($path)) {
            continue;
        }
        if (str_ends_with($name, '.sh') || str_ends_with($name, '.php')) {
            ++$n;
        }
    }

    return $n;
}

/**
 * Count export / assign lines in ci-defaults.env (NAME= or export NAME=).
 */
function count_ci_default_exports(string $path): int
{
    $n = 0;
    foreach (file($path) ?: [] as $line) {
        $line = trim($line);
        if ('' === $line || str_starts_with($line, '#')) {
            continue;
        }
        if (preg_match('/^(?:export\s+)?[A-Za-z_][A-Za-z0-9_]*=/', $line)) {
            ++$n;
        }
    }

    return $n;
}

$fail = 0;
$rows = [];

foreach ($cfg['files'] as $path => $meta) {
    if (!is_array($meta) || !isset($meta['budget'])) {
        fwrite(STDERR, "check-size-budgets: bad entry for {$path}\n");
        $fail = 1;
        continue;
    }
    $budget = (int) $meta['budget'];
    $target = (int) ($meta['target'] ?? $budget);
    try {
        $live = count_lines($path);
    } catch (Throwable $e) {
        fwrite(STDERR, "check-size-budgets: {$e->getMessage()}\n");
        $fail = 1;
        continue;
    }
    $ok = $live <= $budget;
    $rows[] = [$path, $live, $budget, $target, $ok];
    if (!$ok) {
        fwrite(STDERR, sprintf(
            "check-size-budgets: FAIL %s — %d lines > budget %d (target %d)%s\n",
            $path,
            $live,
            $budget,
            $target,
            isset($meta['note']) ? ' — '.$meta['note'] : ''
        ));
        $fail = 1;
    } elseif ($printOnly || getenv('SIZE_BUDGETS_VERBOSE')) {
        fwrite(STDOUT, sprintf(
            "check-size-budgets: OK   %s — %d / budget %d (target %d)\n",
            $path,
            $live,
            $budget,
            $target
        ));
    }
}

if (isset($cfg['script_file_count']) && is_array($cfg['script_file_count'])) {
    $budget = (int) $cfg['script_file_count']['budget'];
    $target = (int) ($cfg['script_file_count']['target'] ?? $budget);
    $live = count_script_files($root.'/script');
    $ok = $live <= $budget;
    $rows[] = ['script/*.{sh,php} count', $live, $budget, $target, $ok];
    if (!$ok) {
        fwrite(STDERR, sprintf(
            "check-size-budgets: FAIL script/ file count — %d > budget %d (target %d)\n",
            $live,
            $budget,
            $target
        ));
        $fail = 1;
    } elseif ($printOnly || getenv('SIZE_BUDGETS_VERBOSE')) {
        fwrite(STDOUT, sprintf(
            "check-size-budgets: OK   script/ file count — %d / budget %d (target %d)\n",
            $live,
            $budget,
            $target
        ));
    }
}

if (isset($cfg['ci_defaults_exports']) && is_array($cfg['ci_defaults_exports'])) {
    $budget = (int) $cfg['ci_defaults_exports']['budget'];
    $target = (int) ($cfg['ci_defaults_exports']['target'] ?? $budget);
    $live = count_ci_default_exports($root.'/script/ci-defaults.env');
    $ok = $live <= $budget;
    $rows[] = ['ci-defaults.env exports', $live, $budget, $target, $ok];
    if (!$ok) {
        fwrite(STDERR, sprintf(
            "check-size-budgets: FAIL ci-defaults.env exports — %d > budget %d (target %d)\n",
            $live,
            $budget,
            $target
        ));
        $fail = 1;
    } elseif ($printOnly || getenv('SIZE_BUDGETS_VERBOSE')) {
        fwrite(STDOUT, sprintf(
            "check-size-budgets: OK   ci-defaults.env exports — %d / budget %d (target %d)\n",
            $live,
            $budget,
            $target
        ));
    }
}

if (0 === count($rows)) {
    fwrite(STDERR, "check-size-budgets: empty result set is not a pass (#36403)\n");
    exit(1);
}

if (0 === $fail) {
    fwrite(STDOUT, sprintf("check-size-budgets: OK (%d entries)\n", count($rows)));
}

exit($fail);
