#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Self-host compile probe (issue #816, #853).
 *
 * Bundles test/selfhost/compiler_minimal via bin/compile.php (LiteralIncludeDiscovery),
 * runs -l then -o build/selfhost, and prints the first fatal / LogicException line as
 * NEXT_LOWER for bootstrap inventory triage.
 *
 * Usage:
 *   php script/bootstrap-selfhost-compile-probe.php
 *   php script/bootstrap-selfhost-compile-probe.php --update-inventory
 *     (writes docs/bootstrap-inventory-live-probe.md — not docs/bootstrap-inventory.md; #2891)
 */

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
require __DIR__.'/bootstrap-lib.php';

$updateInventory = in_array('--update-inventory', $argv, true);
$entry = $root.'/test/selfhost/compiler_minimal/main.php';
$compileBin = $root.'/bin/compile.php';
$outBinary = $root.'/build/selfhost';

if (!is_file($entry)) {
    fwrite(STDERR, "bootstrap-selfhost-compile-probe: missing {$entry}\n");
    exit(1);
}
if (!is_file($compileBin)) {
    fwrite(STDERR, "bootstrap-selfhost-compile-probe: missing {$compileBin}\n");
    exit(1);
}

$llvmDir = bootstrapResolveLlvmDir($root);
$env = null !== $llvmDir ? bootstrapLlvmProcessEnv($llvmDir) : null;
if (null === $env) {
    fwrite(STDERR, "bootstrap-selfhost-compile-probe: LLVM 9 not found (skip native -o)\n");
}

if (!is_dir($root.'/build')) {
    mkdir($root.'/build', 0775, true);
}
if (is_file($outBinary)) {
    unlink($outBinary);
}

$progressFile = getenv('PHP_COMPILER_JIT_PROGRESS_FILE');
if (false === $progressFile || '' === $progressFile) {
    $progressFile = $root.'/build/.last-jit-func';
    putenv('PHP_COMPILER_JIT_PROGRESS_FILE='.$progressFile);
}
if (is_file($progressFile)) {
    unlink($progressFile);
}

$steps = [
    ['-l'],
    ['-o', $outBinary],
];

foreach ($steps as $args) {
    if (in_array('-o', $args, true) && null === $env) {
        continue;
    }
    [$exit, $combined] = bootstrapSelfhostProbeRunCompile($root, $compileBin, $entry, $args, $env);
    if (0 === $exit) {
        continue;
    }
    if (139 === $exit) {
        $lastJit = bootstrapSelfhostProbeLastJitFunc($progressFile);
        $next = 'LLVM segfault during native compile (exit 139)';
        if (null !== $lastJit) {
            $next .= ' (last JIT: '.$lastJit.')';
            fwrite(STDOUT, 'LAST_JIT_FUNC: '.$lastJit."\n");
        }
    } else {
        $next = bootstrapSelfhostProbeExtractNextLower($combined);
    }
    if (null === $next) {
        $next = trim($combined) !== '' ? trim($combined) : 'compile failed (exit '.$exit.')';
    }
    fwrite(STDOUT, 'NEXT_LOWER: '.$next."\n");
    if ($updateInventory) {
        bootstrapSelfhostProbeAppendInventory($root.'/docs/bootstrap-inventory.md', $next);
    }
    exit(1);
}

fwrite(STDOUT, "bootstrap-selfhost-compile-probe: OK {$entry}\n");
if (null !== $env && is_executable($outBinary)) {
    fwrite(STDOUT, "bootstrap-selfhost-compile-probe: linked {$outBinary}\n");
}
exit(0);

/**
 * @param list<string> $extraArgs
 * @param array<string, string>|null $env
 *
 * @return array{0: int, 1: string}
 */
function bootstrapSelfhostProbeRunCompile(
    string $root,
    string $compileBin,
    string $entry,
    array $extraArgs,
    ?array $env
): array {
    $parts = array_merge([PHP_BINARY, $compileBin], $extraArgs, [$entry]);
    $inner = implode(' ', array_map('escapeshellarg', $parts)).' 2>&1';
    $bash = 'cd '.escapeshellarg($root).' && '.$inner;
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    if (null !== $env) {
        $env['PHP_COMPILER_SELFHOST_AOT'] = '1';
        $progress = getenv('PHP_COMPILER_JIT_PROGRESS_FILE');
        if (false !== $progress && '' !== $progress) {
            $env['PHP_COMPILER_JIT_PROGRESS_FILE'] = $progress;
        }
    }
    $proc = proc_open(['bash', '-c', $bash], $descriptorSpec, $pipes, $root, $env);
    if (!is_resource($proc)) {
        return [1, 'proc_open failed'];
    }
    fclose($pipes[0]);
    fclose($pipes[2]);
    $combined = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exit = proc_close($proc);

    return [is_int($exit) ? $exit : 1, trim($combined !== false ? $combined : '')];
}

function bootstrapSelfhostProbeAppendInventory(string $inventoryFile, string $message): void
{
    $root = dirname($inventoryFile);
    bootstrapWriteInventoryLiveProbe($root, $message);
    fwrite(STDOUT, 'bootstrap-selfhost-compile-probe: updated '.bootstrapInventoryLiveProbeFile($root)."\n");
}
