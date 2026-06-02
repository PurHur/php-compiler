#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * JIT compile phase timings for optimization baselines (issues #94, #1898).
 *
 * Spawns bench-compile-probe.php in an isolated PHP child (same pattern as
 * jit-runtime-probe) so the orchestrator never preloads libLLVM.
 *
 * Sample (Docker php-compiler:22.04-dev):
 *   php script/bench-compile.php examples/001-SimpleWeb/example.php
 *   php script/bench-compile.php --json examples/000-HelloWorld/example.php
 *
 * @see https://github.com/PurHur/php-compiler/issues/94
 * @see https://github.com/PurHur/php-compiler/issues/1898
 */

$root = dirname(__DIR__);

[$target, $json] = bench_compile_parse_argv($argv);
if (!is_file($target)) {
    fwrite(STDERR, "bench-compile: file not found: {$target}\n");
    exit(1);
}

$llvmDir = bench_compile_resolve_llvm_dir($root);
if (null === $llvmDir) {
    fwrite(STDERR, "bench-compile: LLVM 9 not found (set PHP_COMPILER_LLVM_PATH or install under .llvm/)\n");
    exit(2);
}

$payload = bench_compile_run_probe($root, $llvmDir, $target);
if (null === $payload) {
    exit(1);
}

$rows = [
    'parse' => [
        'cold' => $payload['cold']['parse_ms'],
        'warm' => $payload['warm']['parse_ms'],
    ],
    'llvm emit' => [
        'cold' => $payload['cold']['llvm_ms'],
        'warm' => $payload['warm']['llvm_ms'],
    ],
    'total' => [
        'cold' => $payload['cold']['total_ms'],
        'warm' => $payload['warm']['total_ms'],
    ],
];

if ($json) {
    fwrite(STDOUT, json_encode([
        'file' => $target,
        'phases' => $rows,
        'warm_note' => bench_compile_warm_note(),
    ], JSON_PRETTY_PRINT)."\n");
    exit(0);
}

$resolved = realpath($target);
$display = false !== $resolved ? $resolved : $target;
fwrite(STDOUT, "bench-compile: {$display}\n");
fwrite(STDOUT, bench_compile_warm_note()."\n\n");
fwrite(STDOUT, sprintf("%-12s | %10s | %10s\n", 'Phase', 'Cold (ms)', 'Warm (ms)'));
fwrite(STDOUT, str_repeat('-', 40)."\n");
foreach ($rows as $phase => $times) {
    fwrite(STDOUT, sprintf(
        "%-12s | %10.2f | %10s\n",
        $phase,
        $times['cold'],
        bench_compile_format_warm($times['warm'], $phase)
    ));
}
exit(0);

/**
 * @param list<string> $argv
 *
 * @return array{0: string, 1: bool}
 */
function bench_compile_parse_argv(array $argv): array
{
    $json = false;
    $path = '';
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--json') {
            $json = true;
            continue;
        }
        if (str_starts_with($arg, '--file=')) {
            $path = substr($arg, 7);
            continue;
        }
        if ($arg === '--help') {
            fwrite(STDOUT, "Usage: php script/bench-compile.php [--json] [--file=path] [path]\n");
            fwrite(STDOUT, "Default: examples/001-SimpleWeb/example.php\n");
            exit(0);
        }
        if ($arg[0] !== '-') {
            $path = $arg;
        }
    }
    if ($path === '') {
        $path = 'examples/001-SimpleWeb/example.php';
    }
    if (!str_starts_with($path, '/')) {
        $path = dirname(__DIR__).'/'.$path;
    }

    return [$path, $json];
}

/**
 * @return array{cold: array{parse_ms: float, llvm_ms: float, total_ms: float}, warm: array{parse_ms: float, llvm_ms: float, total_ms: float}}|null
 */
function bench_compile_run_probe(string $root, string $llvmDir, string $target): ?array
{
    $bash = <<<'BASH'
set -euo pipefail
ROOT=%s
TARGET=%s
LLVM=%s
source "$ROOT/script/php-env.sh"
export PHP_COMPILER_LLVM_PATH="$LLVM"
export LD_LIBRARY_PATH="%s${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
"$PHP_BIN" "${PHP_OPTS[@]}" "$ROOT/script/bench-compile-probe.php" "$TARGET"
BASH;

    $command = sprintf(
        $bash,
        escapeshellarg($root),
        escapeshellarg($target),
        escapeshellarg($llvmDir),
        $llvmDir
    );

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open(['bash', '-lc', $command], $descriptorSpec, $pipes, $root);
    if (!is_resource($proc)) {
        fwrite(STDERR, "bench-compile: could not start probe subprocess\n");
        return null;
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    if (false !== $stderr && '' !== trim($stderr)) {
        fwrite(STDERR, $stderr);
    }
    if (0 !== $exit) {
        fwrite(STDERR, "bench-compile: probe exited {$exit}\n");
        return null;
    }

    $decoded = json_decode($stdout !== false ? trim($stdout) : '', true);
    if (!is_array($decoded) || !isset($decoded['cold'], $decoded['warm'])) {
        fwrite(STDERR, "bench-compile: invalid probe output\n");
        return null;
    }

    return $decoded;
}

function bench_compile_warm_note(): string
{
    return 'Warm = second compile in same probe process (MCJIT engine cached); cross-process warm uses .php-compiler-cache/ (#153); lazy ext/* JIT enabled by default (#94, PHP_COMPILER_JIT_LAZY_BUILTINS=0 to disable)';
}

function bench_compile_format_warm(float $ms, string $phase): string
{
    if ($phase === 'llvm emit' && $ms < 0.05) {
        return sprintf('%.2f (cached)', $ms);
    }

    return sprintf('%.2f', $ms);
}

/**
 * @return non-empty-string|null
 */
function bench_compile_resolve_llvm_dir(string $repoRoot): ?string
{
    $fromEnv = getenv('PHP_COMPILER_LLVM_PATH');
    if (false !== $fromEnv && '' !== $fromEnv && is_file($fromEnv.'/libLLVM-9.so.1')) {
        $resolved = realpath($fromEnv);

        return false !== $resolved ? $resolved : $fromEnv;
    }
    foreach ([$repoRoot.'/.llvm', '/opt/llvm9'] as $candidate) {
        if (is_file($candidate.'/libLLVM-9.so.1')) {
            $resolved = realpath($candidate);

            return false !== $resolved ? $resolved : $candidate;
        }
    }

    return null;
}
