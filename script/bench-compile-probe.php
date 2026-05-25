#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * In-process JIT compile phase probe (child of bench-compile.php; issue #1898).
 * Emits one JSON object on stdout. Not for direct use.
 */

use PHPCompiler\Runtime;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

if ($argc < 2) {
    fwrite(STDERR, "bench-compile-probe: missing target file argument\n");
    exit(1);
}

$target = $argv[1];
if (!is_file($target)) {
    fwrite(STDERR, "bench-compile-probe: file not found: {$target}\n");
    exit(1);
}

$code = file_get_contents($target);
if (false === $code) {
    fwrite(STDERR, "bench-compile-probe: cannot read {$target}\n");
    exit(1);
}

$resolved = realpath($target);
$filename = false !== $resolved ? $resolved : $target;

$cold = bench_compile_probe_run(new Runtime(), $code, $filename);
$runtime = new Runtime();
$warm = bench_compile_probe_run($runtime, $code, $filename);

fwrite(STDOUT, json_encode(['cold' => $cold, 'warm' => $warm])."\n");
exit(0);

/**
 * @return array{parse_ms: float, llvm_ms: float, total_ms: float}
 */
function bench_compile_probe_run(Runtime $runtime, string $code, string $filename): array
{
    $totalStart = hrtime(true);

    $parseStart = hrtime(true);
    $script = $runtime->parse($code, $filename);
    $block = $runtime->compile($script);
    if (null !== $block) {
        $block->setScriptPath($filename);
    }
    $parseMs = (hrtime(true) - $parseStart) / 1e6;

    $llvmStart = hrtime(true);
    $runtime->jitCompileBlock($block);
    $runtime->jitEmitInPlace();
    $llvmMs = (hrtime(true) - $llvmStart) / 1e6;

    $totalMs = (hrtime(true) - $totalStart) / 1e6;

    return [
        'parse_ms' => $parseMs,
        'llvm_ms' => $llvmMs,
        'total_ms' => $totalMs,
    ];
}
