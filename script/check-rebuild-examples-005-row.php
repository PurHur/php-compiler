#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard examples/README.md 005-SessionsWeb run matrix + benchmark row vs rebuild-examples.php (issue #1930).
 *
 * Benchmark row policy matches script/rebuild-examples.php (issue #1889):
 *   - Include when BENCH_SESSIONSWEB=1 or phpc lint --all examples/005-SessionsWeb passes
 *   - Omit when lint fails unless BENCH_SESSIONSWEB=1
 *
 * Usage:
 *   php script/check-rebuild-examples-005-row.php
 */

$root = dirname(__DIR__);
$readme = $root.'/examples/README.md';
$exampleDir = $root.'/examples/005-SessionsWeb';
$example = $exampleDir.'/example.php';

if (!is_file($example)) {
    fwrite(STDOUT, "check-rebuild-examples-005-row: OK (005-SessionsWeb tree absent)\n");
    exit(0);
}

if (!is_readable($readme)) {
    fwrite(STDERR, "check-rebuild-examples-005-row: missing {$readme}\n");
    exit(1);
}

$body = (string) file_get_contents($readme);
$errors = [];

if (!preg_match('/\| \[005-SessionsWeb\]/', $body)) {
    $errors[] = 'examples/README.md: run matrix missing [005-SessionsWeb] row (tree exists; see #1889)';
}

$expectRow = should_expect_benchmark_row($root);
$hasRow = benchmark_table_has_sessions_web_row($body);

if ($expectRow && !$hasRow) {
    $errors[] = 'examples/README.md: benchmark table missing 005-SessionsWeb row (lint green or BENCH_SESSIONSWEB=1; run: ./script/rebuild-examples.php)';
}

if (!$expectRow && $hasRow) {
    $errors[] = 'examples/README.md: benchmark table has stale 005-SessionsWeb row (lint failing; remove row or fix lint; SESSIONSWEB_LINT_GATE=0 only for rebuild script)';
}

if ($hasRow) {
    $rowLine = extract_sessions_web_benchmark_line($body);
    if (null !== $rowLine && !benchmark_row_aot_columns_honest($rowLine)) {
        $errors[] = 'examples/README.md: 005-SessionsWeb benchmark row must keep bin/compile.php and ./compiled as n/a until AOT execute is green (#1891)';
    }
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-rebuild-examples-005-row: {$err}\n");
    }
    fwrite(STDERR, "check-rebuild-examples-005-row: FAILED (sync examples/README.md with script/rebuild-examples.php; see #1930)\n");
    exit(1);
}

fwrite(STDOUT, 'check-rebuild-examples-005-row: OK (benchmark row '.($expectRow ? 'expected' : 'omitted').")\n");
exit(0);

function should_expect_benchmark_row(string $repoRoot): bool
{
    if ('1' === getenv('BENCH_SESSIONSWEB')) {
        return true;
    }
    if ('0' === getenv('SESSIONSWEB_LINT_GATE')) {
        return false;
    }

    return sessions_web_lint_passes($repoRoot);
}

function sessions_web_lint_passes(string $repoRoot): bool
{
    $phpc = $repoRoot.'/phpc';
    if (!is_executable($phpc)) {
        return false;
    }
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open(
        [$phpc, 'lint', '--all', $repoRoot.'/examples/005-SessionsWeb'],
        $descriptorSpec,
        $pipes,
        $repoRoot
    );
    if (!is_resource($proc)) {
        return false;
    }
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return 0 === proc_close($proc);
}

function benchmark_table_has_sessions_web_row(string $readmeBody): bool
{
    if (!preg_match('/<!-- benchmark table start -->(.*)<!-- benchmark table end -->/ims', $readmeBody, $m)) {
        return false;
    }

    return (bool) preg_match('/\|\s*005-SessionsWeb\s*\|/i', $m[1]);
}

function extract_sessions_web_benchmark_line(string $readmeBody): ?string
{
    if (!preg_match('/<!-- benchmark table start -->(.*)<!-- benchmark table end -->/ims', $readmeBody, $m)) {
        return null;
    }
    if (!preg_match('/^.*\|\s*005-SessionsWeb\s*\|.*$/mi', $m[1], $line)) {
        return null;
    }

    return trim($line[0]);
}

function benchmark_row_aot_columns_honest(string $rowLine): bool
{
    if ('1' === getenv('BENCH_SESSIONSWEB_AOT')) {
        return true;
    }

    $parts = array_map('trim', explode('|', $rowLine));
    $parts = array_values(array_filter($parts, static fn (string $p): bool => '' !== $p));
    if (count($parts) < 6) {
        return true;
    }
    $compileCol = $parts[4] ?? '';
    $compiledCol = $parts[5] ?? '';

    return preg_match('/n\/a/i', $compileCol) && preg_match('/n\/a/i', $compiledCol);
}
