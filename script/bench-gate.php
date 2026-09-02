<?php

declare(strict_types=1);

/**
 * Performance regression gate for benchmarks/ (#36196).
 *
 * For each headline micro-benchmark:
 *   1. verify AOT output matches Zend (same rule as script/bench.php);
 *   2. measure best-of-3 wall time for Zend and native run in the same job;
 *   3. record LLVM IR size (load-independent proxy for instruction count);
 *   4. compare ratio (aot/zend) and ir_lines against benchmarks/BASELINE.json.
 *
 * Usage:
 *   PHP_8_2=$(command -v php) php script/bench-gate.php
 *   PHP_8_2=$(command -v php) php script/bench-gate.php --update
 */

const BENCH_GATE_CASES = [
    'fibo(30)',
    'simple',
    'mandelbrot',
    'Ack(3,8)',
];

const RATIO_TOLERANCE_PERCENT = 30;
const IR_TOLERANCE_PERCENT = 20;
const TIMING_RUNS = 3;

$root = dirname(__DIR__);
$baselinePath = $root.'/benchmarks/BASELINE.json';
$update = in_array('--update', $argv ?? [], true);

$zend = resolveZendRuntime();
if (null === $zend) {
    fwrite(STDERR, "bench-gate: specify Zend via PHP_X_Y=/path/to/php (e.g. PHP_8_2)\n");
    exit(1);
}

$llvmEnv = resolveLlvmEnv($root);
if ('' === $llvmEnv) {
    fwrite(STDERR, "bench-gate: LLVM 9 required for AOT columns\n");
    exit(1);
}

$work = $root.'/build/bench-gate';
if (!is_dir($work) && !mkdir($work, 0777, true) && !is_dir($work)) {
    fwrite(STDERR, "bench-gate: cannot create {$work}\n");
    exit(1);
}

$measured = [];
foreach (BENCH_GATE_CASES as $name) {
    $path = $root.'/benchmarks/'.$name.'.php';
    if (!is_file($path)) {
        fwrite(STDERR, "bench-gate: missing {$path}\n");
        exit(1);
    }
    $measured[$name] = measureCase($name, $path, $zend, $llvmEnv, $root, $work);
}

printTable($measured);

if ($update) {
    writeBaseline($baselinePath, $measured);
    echo "bench-gate: updated {$baselinePath}\n";
    exit(0);
}

if (!is_file($baselinePath)) {
    fwrite(STDERR, "bench-gate: missing {$baselinePath} — run with --update after verifying output\n");
    exit(1);
}

$baseline = json_decode((string) file_get_contents($baselinePath), true);
if (!is_array($baseline)) {
    fwrite(STDERR, "bench-gate: invalid JSON in {$baselinePath}\n");
    exit(1);
}

$errors = compareToBaseline($baseline, $measured);
if ([] !== $errors) {
    fwrite(STDERR, "bench-gate: PERFORMANCE REGRESSION\n");
    foreach ($errors as $err) {
        fwrite(STDERR, "  {$err}\n");
    }
    exit(1);
}

$ratioTol = (int) ($baseline['ratio_tolerance_percent'] ?? RATIO_TOLERANCE_PERCENT);
$irTol = (int) ($baseline['ir_tolerance_percent'] ?? IR_TOLERANCE_PERCENT);
echo "bench-gate: OK (ratio <= +{$ratioTol}%, ir_lines <= +{$irTol}% vs baseline)\n";
exit(0);

/** @return array{ratio: float, zend_wall: float, aot_wall: float, ir_lines: int, ir_defines: int, output_ok: bool} */
function measureCase(string $name, string $path, string $zend, string $llvmEnv, string $root, string $work): array
{
    $binary = $work.'/'.$name.'.bin';
    $expected = trim(capture(escapeshellcmd($zend).' '.escapeshellarg($path)));
    $buildCmd = $llvmEnv.' '.escapeshellcmd($root.'/phpc').' build -o '.escapeshellarg($binary).' '.escapeshellarg($path);
    capture($buildCmd.' 2>&1', $buildRc, buildCapSeconds());
    $outputOk = 0 === $buildRc
        && is_executable($binary)
        && trim(capture(escapeshellarg($binary))) === $expected;
    if (!$outputOk) {
        fwrite(STDERR, "bench-gate: {$name}: AOT build or output mismatch — cannot time\n");
        exit(1);
    }

    $zendWall = bestOfN(escapeshellcmd($zend).' '.escapeshellarg($path), TIMING_RUNS);
    $aotWall = bestOfN(escapeshellarg($binary), TIMING_RUNS);
    $ratio = $zendWall > 0.0 ? $aotWall / $zendWall : INF;

    putenv('PHP_COMPILER_DUMP_IR=1');
    capture($buildCmd.' 2>&1');
    $ir = is_file('/tmp/phpc-last.ll') ? (string) file_get_contents('/tmp/phpc-last.ll') : '';
    $irLines = '' === $ir ? 0 : substr_count($ir, "\n") + (str_ends_with($ir, "\n") ? 0 : 1);
    $irDefines = 0;
    foreach (explode("\n", $ir) as $line) {
        if (str_starts_with($line, 'define ')) {
            ++$irDefines;
        }
    }

    return [
        'ratio' => $ratio,
        'zend_wall' => $zendWall,
        'aot_wall' => $aotWall,
        'ir_lines' => $irLines,
        'ir_defines' => $irDefines,
        'output_ok' => true,
    ];
}

function resolveZendRuntime(): ?string
{
    $runtimes = [];
    foreach (getenv() as $key => $value) {
        if (1 === preg_match('/^PHP_\\d+_\\d+$/', (string) $key) && is_string($value) && is_executable($value)) {
            $runtimes[] = $value;
        }
    }
    if ([] === $runtimes) {
        return null;
    }
    sort($runtimes, \SORT_STRING);

    return $runtimes[0];
}

function resolveLlvmEnv(string $root): string
{
    foreach ([getenv('PHP_COMPILER_LLVM_PATH') ?: '', $root.'/.llvm', '/opt/llvm9'] as $dir) {
        if ('' !== $dir && is_file($dir.'/libLLVM-9.so.1')) {
            return 'PHP_COMPILER_LLVM_PATH='.escapeshellarg($dir)
                .' LD_LIBRARY_PATH='.escapeshellarg($dir);
        }
    }

    return '';
}

function bestOfN(string $cmd, int $runs): float
{
    $best = INF;
    for ($i = 0; $i < $runs; ++$i) {
        $start = microtime(true);
        capture($cmd);
        $elapsed = microtime(true) - $start;
        if ($elapsed < $best) {
            $best = $elapsed;
        }
    }

    return $best;
}

function capture(string $cmd, ?int &$rc = null, ?int $capSeconds = null): string
{
    if (null === $capSeconds) {
        $timeout = getenv('PHP_COMPILER_BENCH_TIMEOUT');
        $timeout = is_string($timeout) && ctype_digit($timeout) ? (int) $timeout : 300;
    } else {
        $timeout = $capSeconds;
    }
    if ($timeout > 0) {
        $cmd = 'timeout --signal=KILL '.$timeout.' env '.$cmd;
    }
    exec($cmd.' 2>/dev/null', $lines, $rc);

    return implode("\n", $lines);
}

function buildCapSeconds(): int
{
    $v = getenv('PHP_COMPILER_BENCH_BUILD_TIMEOUT');

    return is_string($v) && ctype_digit($v) ? (int) $v : 1800;
}

/** @param array<string, array<string, float|int|bool>> $measured */
function printTable(array $measured): void
{
    echo "bench-gate measurements (#36196):\n";
    printf(
        "%-14s %10s %10s %10s %10s %10s\n",
        'case',
        'zend(s)',
        'aot(s)',
        'ratio',
        'ir_lines',
        'defines'
    );
    foreach ($measured as $name => $row) {
        printf(
            "%-14s %10.4f %10.4f %10.4f %10d %10d\n",
            $name,
            (float) $row['zend_wall'],
            (float) $row['aot_wall'],
            (float) $row['ratio'],
            (int) $row['ir_lines'],
            (int) $row['ir_defines']
        );
    }
    echo "\n";
}

/** @param array<string, array<string, float|int|bool>> $measured */
function writeBaseline(string $path, array $measured): void
{
    $cases = [];
    foreach ($measured as $name => $row) {
        $cases[$name] = [
            'ratio_aot_over_zend' => round((float) $row['ratio'], 6),
            'zend_wall' => round((float) $row['zend_wall'], 6),
            'aot_wall' => round((float) $row['aot_wall'], 6),
            'ir_lines' => (int) $row['ir_lines'],
            'ir_defines' => (int) $row['ir_defines'],
        ];
    }
    $doc = [
        'version' => 1,
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'arch' => 'x86_64-linux',
        'ratio_tolerance_percent' => RATIO_TOLERANCE_PERCENT,
        'ir_tolerance_percent' => IR_TOLERANCE_PERCENT,
        'regeneration' => 'PHP_8_2=$(command -v php) ./script/bench-gate.sh --update',
        'cases' => $cases,
    ];
    file_put_contents($path, json_encode($doc, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
}

/**
 * @param array<string, mixed> $baseline
 * @param array<string, array<string, float|int|bool>> $measured
 *
 * @return list<string>
 */
function compareToBaseline(array $baseline, array $measured): array
{
    $errors = [];
    $ratioTol = (int) ($baseline['ratio_tolerance_percent'] ?? RATIO_TOLERANCE_PERCENT);
    $irTol = (int) ($baseline['ir_tolerance_percent'] ?? IR_TOLERANCE_PERCENT);
    $baseCases = $baseline['cases'] ?? [];
    if (!is_array($baseCases)) {
        return ['baseline cases missing or invalid'];
    }

    foreach ($measured as $name => $row) {
        if (!isset($baseCases[$name]) || !is_array($baseCases[$name])) {
            $errors[] = "{$name}: no baseline entry";
            continue;
        }
        $base = $baseCases[$name];
        $baseRatio = (float) ($base['ratio_aot_over_zend'] ?? 0.0);
        $curRatio = (float) $row['ratio'];
        if ($baseRatio > 0.0) {
            $ratioGrowth = ($curRatio - $baseRatio) * 100.0 / $baseRatio;
            if ($ratioGrowth > $ratioTol) {
                $errors[] = sprintf(
                    '%s.ratio: %.4f exceeds baseline %.4f by %.1f%% (limit +%d%%)',
                    $name,
                    $curRatio,
                    $baseRatio,
                    $ratioGrowth,
                    $ratioTol
                );
            }
        }

        $baseIr = (int) ($base['ir_lines'] ?? 0);
        $curIr = (int) $row['ir_lines'];
        if ($baseIr > 0) {
            $irGrowth = ($curIr - $baseIr) * 100.0 / $baseIr;
            if ($irGrowth > $irTol) {
                $errors[] = sprintf(
                    '%s.ir_lines: %d exceeds baseline %d by %.1f%% (limit +%d%%)',
                    $name,
                    $curIr,
                    $baseIr,
                    $irGrowth,
                    $irTol
                );
            }
        }
    }

    return $errors;
}
