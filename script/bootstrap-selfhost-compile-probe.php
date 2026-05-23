#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Self-host compile probe (issue #816).
 *
 * Bundles test/selfhost/compiler_minimal via bin/compile.php (LiteralIncludeDiscovery),
 * runs -l then -o build/selfhost, and prints the first fatal / LogicException line as
 * NEXT_LOWER for bootstrap inventory triage.
 *
 * Usage:
 *   php script/bootstrap-selfhost-compile-probe.php
 *   php script/bootstrap-selfhost-compile-probe.php --update-inventory
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
        $next = 'LLVM segfault during native compile (exit 139)';
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
    if (!is_file($inventoryFile)) {
        fwrite(STDERR, "bootstrap-selfhost-compile-probe: missing {$inventoryFile}\n");

        return;
    }
    $content = (string) file_get_contents($inventoryFile);
    $needle = '## Live self-host compile probe';
    $bullet = '- `'.$message.'`';
    if (str_contains($content, $bullet)) {
        return;
    }
    if (!str_contains($content, $needle)) {
        $anchor = '## Compiler CFG gaps (`lib/Compiler.php`)';
        $pos = strpos($content, $anchor);
        if (false === $pos) {
            $content .= "\n".$needle."\n\n".$bullet."\n";
        } else {
            $after = strpos($content, "\n## ", $pos + strlen($anchor));
            $insertAt = false !== $after ? $after : strlen($content);
            $block = "\n\n".$needle."\n\n".$bullet."\n";
            $content = substr($content, 0, $insertAt).$block.substr($content, $insertAt);
        }
    } else {
        $content = preg_replace(
            '/(## Live self-host compile probe\n\n)(?:- `[^`]+`\n)*/',
            '$1'.$bullet."\n",
            $content,
            1
        ) ?? $content;
    }
    file_put_contents($inventoryFile, $content);
}
