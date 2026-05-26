#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard examples/README.md 003-MiniWebApp project-JIT benchmark sub-row (issue #2334).
 *
 * When MiniWebAppJitProjectTest would run (MINIWEBAPP_JIT_PROJECT_GATE=1, LLVM + JIT probe green),
 * the committed benchmark table must include the project-JIT sub-row label from rebuild-examples SSOT.
 *
 * Usage:
 *   php script/check-rebuild-examples-003-jit-project-sync.php
 */

require_once __DIR__.'/rebuild-examples-ssot.php';

$root = dirname(__DIR__);
$readme = $root.'/examples/README.md';
$index = $root.'/examples/003-MiniWebApp/public/index.php';

if (!is_file($index)) {
    fwrite(STDOUT, "check-rebuild-examples-003-jit-project-sync: OK (003-MiniWebApp tree absent)\n");
    exit(0);
}

if (!is_readable($readme)) {
    fwrite(STDERR, "check-rebuild-examples-003-jit-project-sync: missing {$readme}\n");
    exit(1);
}

$body = (string) file_get_contents($readme);
$label = rebuild_examples_miniwebapp_jit_project_row_label();
$expectSegment = miniwebapp_jit_project_test_would_run($root);
$hasSegment = benchmark_table_has_miniwebapp_jit_project_row($body, $label);

$errors = [];

if ($expectSegment && !$hasSegment) {
    $errors[] = 'examples/README.md: benchmark table missing '.$label.' row (MiniWebAppJitProjectTest green; run: MINIWEBAPP_JIT_PROJECT_GATE=1 BENCH_MINIWEBAPP_JIT_PROJECT=1 ./script/rebuild-examples.php; #2183)';
}

if (!$expectSegment && $hasSegment) {
    $errors[] = 'examples/README.md: benchmark table has stale '.$label.' row (MiniWebAppJitProjectTest skipped; remove row or fix JIT project gate; #2334)';
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-rebuild-examples-003-jit-project-sync: {$err}\n");
    }
    fwrite(STDERR, "check-rebuild-examples-003-jit-project-sync: FAILED (sync examples/README.md with script/rebuild-examples.php; see #2334)\n");
    exit(1);
}

fwrite(STDOUT, 'check-rebuild-examples-003-jit-project-sync: OK (project-JIT segment '.($expectSegment ? 'expected' : 'omitted').")\n");
exit(0);

function miniwebapp_jit_project_test_would_run(string $repoRoot): bool
{
    $index = $repoRoot.'/examples/003-MiniWebApp/public/index.php';
    if (!is_file($index)) {
        return false;
    }
    if ('0' === getenv('MINIWEBAPP_JIT_PROJECT_GATE')) {
        return false;
    }
    if ('1' !== getenv('MINIWEBAPP_JIT_PROJECT_GATE')) {
        return false;
    }
    if (!llvm_ready_for_jit_project_check($repoRoot)) {
        return false;
    }
    $jit = realpath($repoRoot.'/bin/jit.php');
    if (false === $jit) {
        return false;
    }

    return jit_runtime_probe_ok_for_check($repoRoot);
}

function benchmark_table_has_miniwebapp_jit_project_row(string $readmeBody, string $rowLabel): bool
{
    if (!preg_match('/<!-- benchmark table start -->(.*)<!-- benchmark table end -->/ims', $readmeBody, $m)) {
        return false;
    }

    $escaped = preg_quote($rowLabel, '/');

    return (bool) preg_match('/\|\s*'.$escaped.'\s*\|/i', $m[1]);
}

function llvm_ready_for_jit_project_check(string $repoRoot): bool
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

function jit_runtime_probe_ok_for_check(string $repoRoot): bool
{
    $script = $repoRoot.'/script/jit-runtime-probe.php';
    if (!is_file($script)) {
        return false;
    }
    $phpBin = getenv('PHP_COMPILER_PHP') ?: PHP_BINARY;
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
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
    $proc = proc_open(
        [$phpBin, $script],
        $descriptorSpec,
        $pipes,
        $repoRoot,
        $env
    );
    if (!is_resource($proc)) {
        return false;
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return 0 === proc_close($proc)
        && is_string($stdout)
        && str_contains($stdout, 'jit-runtime-probe OK');
}
