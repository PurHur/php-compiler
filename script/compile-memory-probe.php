#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Measure PHP heap retained after compile+discard for compile-memory fixtures (#36231).
 *
 * Usage:
 *   php script/compile-memory-probe.php [--check] [--release-cfg] [--sequential]
 *
 * --check          exit non-zero when sequential retention exceeds 2× baseline
 * --release-cfg    call Block::detachCfgTree after each compile (full scope+orig release)
 * --sequential     compile all fixtures in one Runtime (spine-shaped); default is one fixture each
 */

require __DIR__.'/../vendor/autoload.php';

$check = in_array('--check', $argv, true);
$releaseCfg = in_array('--release-cfg', $argv, true);
$sequential = in_array('--sequential', $argv, true);

// Probe calls detachCfgTree explicitly after compile — do not set
// PHP_COMPILER_RELEASE_CFG_AFTER_COMPILE (that runs before AOT JIT and breaks emit).

$fixtures = [
    'lib/Lint/Linter.php' => 11_000,
    'lib/OpCode.php' => 22_000,
    'lib/Block.php' => 140_000,
];

$baselinePath = __DIR__.'/compile-memory-probe.baseline.json';
$baseline = is_readable($baselinePath)
    ? json_decode((string) file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR)
    : [];

$rows = [];
$failed = false;

if ($sequential) {
    $runtime = new PHPCompiler\Runtime();
    $m0 = memory_get_usage(false);
    foreach ($fixtures as $relPath => $_sourceBytes) {
        $abs = __DIR__.'/../'.$relPath;
        if (!is_readable($abs)) {
            fwrite(STDERR, "compile-memory-probe: unreadable fixture {$relPath}\n");
            exit(2);
        }
        $block = $runtime->parseAndCompile((string) file_get_contents($abs), $relPath);
        if (null === $block) {
            fwrite(STDERR, "compile-memory-probe: compile failed for {$relPath}\n");
            exit(1);
        }
        if ($releaseCfg) {
            PHPCompiler\Block::detachCfgTree($block, true);
        }
        unset($block);
        gc_collect_cycles();
    }
    $retained = memory_get_usage(false) - $m0;
    $sourceBytes = array_sum($fixtures);
    $ratio = $sourceBytes > 0 ? $retained / $sourceBytes : 0.0;
    $rows[] = [
        'fixture' => 'sequential:'.implode(',', array_keys($fixtures)),
        'source_bytes' => $sourceBytes,
        'retained_bytes' => $retained,
        'bytes_per_source_byte' => round($ratio, 1),
    ];
    $baselineKey = 'sequential'.($releaseCfg ? ':release' : '');
    if ($check && isset($baseline[$baselineKey])) {
        $limit = (float) $baseline[$baselineKey] * 2.0;
        if ($ratio > $limit) {
            fwrite(STDERR, sprintf(
                "compile-memory-probe: FAIL sequential — %.1f bytes/source byte > 2× baseline %.1f\n",
                $ratio,
                (float) $baseline[$baselineKey]
            ));
            $failed = true;
        }
    }
} else {
    foreach ($fixtures as $relPath => $sourceBytes) {
        $abs = __DIR__.'/../'.$relPath;
        if (!is_readable($abs)) {
            fwrite(STDERR, "compile-memory-probe: unreadable fixture {$relPath}\n");
            exit(2);
        }
        $code = (string) file_get_contents($abs);
        $runtime = new PHPCompiler\Runtime();
        $m0 = memory_get_usage(false);
        $block = $runtime->parseAndCompile($code, $relPath);
        if (null === $block) {
            fwrite(STDERR, "compile-memory-probe: compile failed for {$relPath}\n");
            exit(1);
        }
        if ($releaseCfg) {
            PHPCompiler\Block::detachCfgTree($block, true);
        }
        unset($block);
        gc_collect_cycles();
        $retained = memory_get_usage(false) - $m0;
        $ratio = $sourceBytes > 0 ? $retained / $sourceBytes : 0.0;
        $rows[] = [
            'fixture' => $relPath,
            'source_bytes' => $sourceBytes,
            'retained_bytes' => $retained,
            'bytes_per_source_byte' => round($ratio, 1),
        ];
    }
}

echo json_encode(['fixtures' => $rows, 'release_cfg' => $releaseCfg, 'sequential' => $sequential], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

if ($failed) {
    exit(1);
}

echo "compile-memory-probe: OK\n";
