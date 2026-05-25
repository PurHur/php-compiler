#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard examples/README.md 005-SessionsWeb run matrix + benchmark row vs rebuild-examples.php (issue #1930).
 *
 * Benchmark row policy matches script/rebuild-examples.php (issue #1889):
 *   - Include when BENCH_SESSIONSWEB=1 or phpc lint --all examples/005-SessionsWeb passes
 *   - Omit when lint fails unless BENCH_SESSIONSWEB=1
 *   - AOT columns: n/a when LLVM/execute probe fails; real timings when probe passes (#1891, #1973)
 *
 * Usage:
 *   php script/check-rebuild-examples-005-row.php
 */

require_once __DIR__.'/../test/support/CgiCookieJar.php';
require_once __DIR__.'/../test/support/SessionsWebCgiEnv.php';

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
    if (null !== $rowLine && !benchmark_row_aot_columns_honest($rowLine, $root)) {
        $errors[] = 'examples/README.md: 005-SessionsWeb benchmark AOT columns out of sync (run: BENCH_SESSIONSWEB=1 BENCH_SESSIONSWEB_AOT=1 ./script/rebuild-examples.php; #1973)';
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

    if ('1' === getenv('BENCH_SESSIONSWEB_AOT')) {
        return !$compileNa && !$compiledNa;
    }

    if (sessions_web_aot_execute_probe($repoRoot)) {
        return !$compileNa && !$compiledNa;
    }

    return $compileNa && $compiledNa;
}

function llvm_ready_for_check(string $repoRoot): bool
{
    $candidates = [];
    $fromEnv = getenv('PHP_COMPILER_LLVM_PATH');
    if (false !== $fromEnv && '' !== $fromEnv) {
        $candidates[] = $fromEnv;
    }
    $candidates[] = $repoRoot.'/.llvm';
    $candidates[] = '/opt/llvm9';
    foreach ($candidates as $dir) {
        if (is_file($dir.'/libLLVM-9.so.1')) {
            return true;
        }
    }

    return false;
}

function sessions_web_aot_execute_probe(string $repoRoot): bool
{
    if ('0' === getenv('SESSIONS_WEB_AOT_PROBE')) {
        return false;
    }
    if (!llvm_ready_for_check($repoRoot)) {
        return false;
    }
    $phpc = $repoRoot.'/phpc';
    $project = $repoRoot.'/examples/005-SessionsWeb';
    $binary = $project.'/.phpc/bin/app';
    if (!is_executable($phpc) || !is_file($project.'/example.php')) {
        return false;
    }

    $sessionDir = sys_get_temp_dir().'/phpc_check_sessionsweb_'.uniqid('', true);
    if (!@mkdir($sessionDir, 0700, true) && !is_dir($sessionDir)) {
        return false;
    }

    $env = [];
    foreach ($_ENV as $key => $value) {
        if (is_string($value)) {
            $env[$key] = $value;
        }
    }
    $llvmDir = null;
    foreach ([getenv('PHP_COMPILER_LLVM_PATH') ?: '', $repoRoot.'/.llvm', '/opt/llvm9'] as $dir) {
        if ('' !== $dir && is_file($dir.'/libLLVM-9.so.1')) {
            $llvmDir = realpath($dir) ?: $dir;
            break;
        }
    }
    if (null !== $llvmDir) {
        $env['PHP_COMPILER_LLVM_PATH'] = $llvmDir;
        $ld = $env['LD_LIBRARY_PATH'] ?? '';
        $env['LD_LIBRARY_PATH'] = '' === $ld ? $llvmDir : $llvmDir.':'.$ld;
    }
    $env['PHP_COMPILER_SESSION_DIR'] = $sessionDir;

    if (!is_executable($binary)) {
        $build = proc_open(
            [$phpc, 'build', '--project', $project],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repoRoot,
            $env
        );
        if (!is_resource($build)) {
            check_sessions_web_cleanup($sessionDir);

            return false;
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (0 !== proc_close($build)) {
            check_sessions_web_cleanup($sessionDir);

            return false;
        }
    }

    if (!is_executable($binary)) {
        check_sessions_web_cleanup($sessionDir);

        return false;
    }

    $ok = check_sessions_web_flash_probe($repoRoot, $binary, $env);
    check_sessions_web_cleanup($sessionDir);

    return $ok;
}

/**
 * @param array<string, string> $baseEnv
 */
function check_sessions_web_flash_probe(string $repoRoot, string $binary, array $baseEnv): bool
{
    $jar = new PHPCompiler\CgiCookieJar();
    $empty = check_run_binary($repoRoot, $binary, array_merge($baseEnv, PHPCompiler\SessionsWebCgiEnv::getEmpty()));
    if (null === $empty) {
        return false;
    }
    $jar->absorbFromCgiOutput($empty);
    if (!$jar->hasCookie('PHPSESSID')) {
        return false;
    }
    $cookie = $jar->httpCookieHeader();
    if (null === check_run_binary(
        $repoRoot,
        $binary,
        array_merge($baseEnv, PHPCompiler\SessionsWebCgiEnv::postFlash('Saved'), ['HTTP_COOKIE' => $cookie])
    )) {
        return false;
    }
    $flash = check_run_binary(
        $repoRoot,
        $binary,
        array_merge($baseEnv, PHPCompiler\SessionsWebCgiEnv::getEmpty(), ['HTTP_COOKIE' => $jar->httpCookieHeader()])
    );
    if (null === $flash) {
        return false;
    }

    return str_contains($flash, 'Flash: Saved');
}

/**
 * @param array<string, string> $env
 */
function check_run_binary(string $repoRoot, string $binary, array $env): ?string
{
    $proc = proc_open(
        [$binary],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $repoRoot,
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

function check_sessions_web_cleanup(string $sessionDir): void
{
    if (!is_dir($sessionDir)) {
        return;
    }
    foreach (glob($sessionDir.'/sess_*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($sessionDir);
}
