#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard examples/README.md 009-FastCGIWeb benchmark row vs rebuild-examples.php (issue #2370).
 *
 * Benchmark row policy matches script/rebuild-examples.php:
 *   - Include when BENCH_FASTCGIWEB=1 or phpc lint --all examples/009-FastCGIWeb passes
 *   - Omit when lint fails unless BENCH_FASTCGIWEB=1
 *   - AOT columns: n/a when LLVM/execute probe fails; real timings when probe passes (#2352)
 *
 * Usage:
 *   php script/check-rebuild-examples-009-sync.php
 */

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../test/LlvmToolchain.php';

$root = dirname(__DIR__);
$readme = $root.'/examples/README.md';
$example = $root.'/examples/009-FastCGIWeb/example.php';

if (!is_file($example)) {
    fwrite(STDOUT, "check-rebuild-examples-009-sync: OK (009-FastCGIWeb tree absent)\n");
    exit(0);
}

if (!is_readable($readme)) {
    fwrite(STDERR, "check-rebuild-examples-009-sync: missing {$readme}\n");
    exit(1);
}

$body = (string) file_get_contents($readme);
$errors = [];

$expectRow = should_expect_benchmark_row($root);
$hasRow = benchmark_table_has_fastcgi_web_row($body);

if ($expectRow && !$hasRow) {
    $errors[] = 'examples/README.md: benchmark table missing 009-FastCGIWeb row (lint green or BENCH_FASTCGIWEB=1; run: BENCH_FASTCGIWEB=1 ./script/rebuild-examples.php)';
}

if (!$expectRow && $hasRow) {
    $errors[] = 'examples/README.md: benchmark table has stale 009-FastCGIWeb row (lint failing; remove row or fix lint; FASTCGIWEB_LINT_GATE=0 only for rebuild script)';
}

if ($hasRow) {
    $rowLine = extract_fastcgi_web_benchmark_line($body);
    if (null !== $rowLine && !benchmark_row_aot_columns_honest($rowLine, $root)) {
        $errors[] = 'examples/README.md: 009-FastCGIWeb benchmark AOT columns out of sync (run: BENCH_FASTCGIWEB=1 BENCH_FASTCGIWEB_AOT=1 ./script/rebuild-examples.php; #2370)';
    }
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-rebuild-examples-009-sync: {$err}\n");
    }
    fwrite(STDERR, "check-rebuild-examples-009-sync: FAILED (sync examples/README.md with script/rebuild-examples.php; see #2370)\n");
    exit(1);
}

fwrite(STDOUT, 'check-rebuild-examples-009-sync: OK (benchmark row '.($expectRow ? 'expected' : 'omitted').")\n");
exit(0);

function should_expect_benchmark_row(string $repoRoot): bool
{
    if ('1' === getenv('BENCH_FASTCGIWEB')) {
        return true;
    }
    if ('0' === getenv('FASTCGIWEB_LINT_GATE')) {
        return false;
    }

    return fastcgi_web_lint_passes($repoRoot);
}

function fastcgi_web_lint_passes(string $repoRoot): bool
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
        [$phpc, 'lint', '--all', $repoRoot.'/examples/009-FastCGIWeb'],
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

function benchmark_table_has_fastcgi_web_row(string $readmeBody): bool
{
    if (!preg_match('/<!-- benchmark table start -->(.*)<!-- benchmark table end -->/ims', $readmeBody, $m)) {
        return false;
    }

    return (bool) preg_match('/\|\s*009-FastCGIWeb\s*\|/i', $m[1]);
}

function extract_fastcgi_web_benchmark_line(string $readmeBody): ?string
{
    if (!preg_match('/<!-- benchmark table start -->(.*)<!-- benchmark table end -->/ims', $readmeBody, $m)) {
        return null;
    }
    if (!preg_match('/^.*\|\s*009-FastCGIWeb\s*\|.*$/mi', $m[1], $line)) {
        return null;
    }

    return trim($line[0]);
}

function benchmark_row_aot_columns_honest(string $rowLine, string $repoRoot): bool
{
    $parts = array_map('trim', explode('|', $rowLine));
    $parts = array_values(array_filter($parts, static fn (string $p): bool => '' !== $p));
    if (count($parts) < 6) {
        return true;
    }
    $compileCol = $parts[4] ?? '';
    $compiledCol = $parts[5] ?? '';
    $compileNa = (bool) preg_match('/n\/a/i', $compileCol);
    $compiledNa = (bool) preg_match('/n\/a/i', $compiledCol);

    if (!llvm_ready_for_check($repoRoot)) {
        return true;
    }

    if ('1' === getenv('BENCH_FASTCGIWEB_AOT')) {
        return !$compileNa && !$compiledNa;
    }

    if (fastcgi_web_aot_execute_probe($repoRoot)) {
        return !$compileNa && !$compiledNa;
    }

    return $compileNa && $compiledNa;
}

function llvm_ready_for_check(string $repoRoot): bool
{
    return \PHPCompiler\LlvmToolchain::isReady($repoRoot);
}

function fastcgi_web_aot_execute_probe(string $repoRoot): bool
{
    if ('0' === getenv('FASTCGI_WEB_AOT_PROBE')) {
        return false;
    }
    if (!llvm_ready_for_check($repoRoot)) {
        return false;
    }
    $phpc = $repoRoot.'/phpc';
    $project = $repoRoot.'/examples/009-FastCGIWeb';
    $binary = $project.'/.phpc/bin/app';
    if (!is_executable($phpc) || !is_file($project.'/example.php')) {
        return false;
    }

    $env = [];
    foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
        if (is_string($value)) {
            $env[$key] = $value;
        }
    }
    \PHPCompiler\LlvmToolchain::applyProcessEnv($env, $repoRoot);

    if (!is_executable($binary)) {
        $build = proc_open(
            [$phpc, 'build', '--project', $project],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repoRoot,
            $env
        );
        if (!is_resource($build)) {
            return false;
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (0 !== proc_close($build)) {
            return false;
        }
    }

    if (!is_executable($binary)) {
        return false;
    }

    $health = [
        'QUERY_STRING' => '',
        'REQUEST_URI' => '/example.php',
        'SCRIPT_NAME' => '/example.php',
    ];
    $healthOut = check_run_fastcgi_binary($binary, array_merge($env, $health));
    if (null === $healthOut || !str_contains($healthOut, 'ok')) {
        return false;
    }

    $pathInfo = [
        'PATH_INFO' => '/ping',
        'REQUEST_URI' => '/example.php/ping',
        'SCRIPT_NAME' => '/example.php',
    ];

    $out = check_run_fastcgi_binary($binary, array_merge($env, $pathInfo));
    if (null === $out) {
        return false;
    }

    return str_contains($out, 'PATH_INFO=')
        && str_contains($out, 'REQUEST_URI=')
        && str_contains($out, 'SCRIPT_NAME=');
}

/**
 * @param array<string, string> $env
 */
function check_run_fastcgi_binary(string $binary, array $env): ?string
{
    $proc = proc_open(
        [$binary],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        $env
    );
    if (!is_resource($proc)) {
        return null;
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (0 !== proc_close($proc)) {
        return null;
    }

    return false !== $stdout ? $stdout : '';
}
