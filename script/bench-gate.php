<?php

declare(strict_types=1);

/**
 * Performance regression gate for benchmarks/ (#36196) and benchmarks/v2/ (#36385).
 *
 * For each headline micro-benchmark:
 *   1. verify AOT output matches Zend (same rule as script/bench.php);
 *   2. measure best-of-3 wall time for Zend and native run in the same job;
 *   3. record LLVM IR size (load-independent proxy for instruction count);
 *   4. compare ratio (aot/zend) and ir_lines against the committed baseline.
 *
 * Usage:
 *   PHP_8_2=$(command -v php) php script/bench-gate.php
 *   PHP_8_2=$(command -v php) php script/bench-gate.php --update
 *   PHP_8_2=$(command -v php) php script/bench-gate.php --v2
 *   PHP_8_2=$(command -v php) php script/bench-gate.php --v2 --update
 *   PHP_8_2=$(command -v php) php script/bench-gate.php --compile
 *   PHP_8_2=$(command -v php) php script/bench-gate.php --compile --update
 */

const COMPILE_BASELINE_REL = 'benchmarks/COMPILE_BASELINE.json';
const V2_BASELINE_REL = 'benchmarks/v2/BASELINE.json';
const COMPILE_WALL_TOLERANCE_PERCENT = 20;
const COMPILE_SCALING_TOLERANCE_PERCENT = 20;

const BENCH_GATE_CASES = [
    'fibo(30)',
    'simple',
    'mandelbrot',
    'Ack(3,8)',
];

/** Gate subset of v2 — must stay AOT-correct and ratio-stable (#36385). */
const BENCH_GATE_V2_CASES = [
    'call-heavy',
    'assoc-heavy',
    'str-builder',
    'k-nucleotide',
    'template-render',
];

const RATIO_TOLERANCE_PERCENT = 30;
const IR_TOLERANCE_PERCENT = 20;
/** Tighter ratio band for v2 so a deliberate 2× slowdown fails the gate (#36385). */
const V2_RATIO_TOLERANCE_PERCENT = 20;
const TIMING_RUNS = 3;

$root = dirname(__DIR__);
$argvList = $argv ?? [];
$update = in_array('--update', $argvList, true);
$compileMode = in_array('--compile', $argvList, true);
$v2Mode = in_array('--v2', $argvList, true);

if ($compileMode) {
    runCompileGate($root, $root.'/'.COMPILE_BASELINE_REL, $update);
    exit(0);
}

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

$cases = $v2Mode ? BENCH_GATE_V2_CASES : BENCH_GATE_CASES;
$suiteRel = $v2Mode ? 'benchmarks/v2' : 'benchmarks';
$baselinePath = $v2Mode ? $root.'/'.V2_BASELINE_REL : $root.'/benchmarks/BASELINE.json';
$work = $root.'/build/bench-gate'.($v2Mode ? '-v2' : '');
if (!is_dir($work) && !mkdir($work, 0777, true) && !is_dir($work)) {
    fwrite(STDERR, "bench-gate: cannot create {$work}\n");
    exit(1);
}

$measured = [];
foreach ($cases as $name) {
    $path = $root.'/'.$suiteRel.'/'.$name.'.php';
    if (!is_file($path)) {
        fwrite(STDERR, "bench-gate: missing {$path}\n");
        exit(1);
    }
    $measured[$name] = measureCase($name, $path, $zend, $llvmEnv, $root, $work);
}

printTable($measured, $v2Mode ? 'v2' : 'v1');

if ($update) {
    writeBaseline($baselinePath, $measured, $v2Mode);
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

$ratioTol = (int) ($baseline['ratio_tolerance_percent'] ?? ($v2Mode ? V2_RATIO_TOLERANCE_PERCENT : RATIO_TOLERANCE_PERCENT));
$irTol = (int) ($baseline['ir_tolerance_percent'] ?? IR_TOLERANCE_PERCENT);
echo 'bench-gate'.($v2Mode ? ' --v2' : '').": OK (ratio <= +{$ratioTol}%, ir_lines <= +{$irTol}% vs baseline)\n";
exit(0);

/** @return array{ratio: float, zend_wall: float, aot_wall: float, ir_lines: int, ir_defines: int, output_ok: bool} */
function measureCase(string $name, string $path, string $zend, string $llvmEnv, string $root, string $work): array
{
    // Sanitize shell-meta characters in paths (e.g. fibo(30), Ack(3,8)). Parentheses in the
    // source path have produced phantom multi-10k-line parse errors under `php bin/compile.php`
    // when the path is used as-is (#36385); compile a safe copy instead.
    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? 'case';
    $binary = $work.'/'.$safeName.'.bin';
    $compileSrc = $path;
    if ($safeName !== $name) {
        $compileSrc = $work.'/'.$safeName.'.php';
        if (!copy($path, $compileSrc)) {
            fwrite(STDERR, "bench-gate: cannot copy {$path} -> {$compileSrc}\n");
            exit(1);
        }
    }
    $expected = trim(capture(escapeshellcmd($zend).' '.escapeshellarg($path)));
    $buildCmd = $llvmEnv.' '.escapeshellcmd($root.'/phpc').' build -o '.escapeshellarg($binary).' '.escapeshellarg($compileSrc);
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
function printTable(array $measured, string $suite = 'v1'): void
{
    echo 'v2' === $suite
        ? "bench-gate v2 measurements (#36385):\n"
        : "bench-gate measurements (#36196):\n";
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
function writeBaseline(string $path, array $measured, bool $v2 = false): void
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
        'suite' => $v2 ? 'v2' : 'v1',
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'arch' => 'x86_64-linux',
        'ratio_tolerance_percent' => $v2 ? V2_RATIO_TOLERANCE_PERCENT : RATIO_TOLERANCE_PERCENT,
        'ir_tolerance_percent' => IR_TOLERANCE_PERCENT,
        'regeneration' => $v2
            ? 'PHP_8_2=$(command -v php) ./script/bench-gate.sh --v2 --update'
            : 'PHP_8_2=$(command -v php) ./script/bench-gate.sh --update',
        'note' => $v2
            ? 'v2 gate subset (#36385). Ratio tolerance 20% so a deliberate ~2× slowdown fails.'
            : null,
        'cases' => $cases,
    ];
    if (null === $doc['note']) {
        unset($doc['note']);
    }
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

function runCompileGate(string $root, string $baselinePath, bool $update): void
{
    $llvmEnv = resolveLlvmEnv($root);
    if ('' === $llvmEnv) {
        fwrite(STDERR, "bench-gate --compile: LLVM 9 required\n");
        exit(1);
    }

    $work = $root.'/build/bench-gate-compile';
    if (!is_dir($work) && !mkdir($work, 0777, true) && !is_dir($work)) {
        fwrite(STDERR, "bench-gate --compile: cannot create {$work}\n");
        exit(1);
    }

    $php = escapeshellcmd(PHP_BINARY);
    $measured = [];

    $hello = $root.'/examples/000-HelloWorld/example.php';
    if (!is_file($hello)) {
        fwrite(STDERR, "bench-gate --compile: missing {$hello}\n");
        exit(1);
    }
    $helloBin = $work.'/hello.bin';
    $measured['hello-warm'] = measureCompileCommand(
        $llvmEnv.' '.$php.' '.escapeshellarg($root.'/bin/compile.php')
        .' -o '.escapeshellarg($helloBin).' '.escapeshellarg($hello),
        $root
    );
    if (!is_executable($helloBin)) {
        fwrite(STDERR, "bench-gate --compile: hello-warm did not emit {$helloBin}\n");
        exit(1);
    }

    $mwIndex = $root.'/examples/003-MiniWebApp/public/index.php';
    $mwConfig = $root.'/examples/003-MiniWebApp/config.php';
    $mwRouter = $root.'/examples/003-MiniWebApp/src/Router.php';
    if (!is_file($mwIndex) || !is_file($mwConfig) || !is_file($mwRouter)) {
        fwrite(STDERR, "bench-gate --compile: MiniWebApp example tree incomplete\n");
        exit(1);
    }
    $mwBin = $work.'/miniwebapp.bin';
    $measured['miniwebapp'] = measureCompileCommand(
        $llvmEnv.' '.$php.' '.escapeshellarg($root.'/bin/compile.php')
        .' -o '.escapeshellarg($mwBin)
        .' --include '.escapeshellarg($mwConfig)
        .' --include '.escapeshellarg($mwRouter).' '
        .escapeshellarg($mwIndex),
        $root
    );
    if (!is_executable($mwBin)) {
        fwrite(STDERR, "bench-gate --compile: miniwebapp did not emit {$mwBin}\n");
        exit(1);
    }

    $block = $root.'/lib/Block.php';
    if (!is_file($block)) {
        fwrite(STDERR, "bench-gate --compile: missing {$block}\n");
        exit(1);
    }
    $measured['block-lint'] = measureCompileCommand(
        $llvmEnv.' '.$php.' '.escapeshellarg($root.'/bin/compile.php')
        .' -l '.escapeshellarg($block),
        $root
    );

    foreach ([50, 100, 200] as $count) {
        $row = measureCompileScaling($count);
        if (null === $row) {
            fwrite(STDERR, "bench-gate --compile: scaling probe failed at {$count} statements\n");
            exit(1);
        }
        $measured['scale-'.$count] = $row;
    }

    printCompileTable($measured);

    if ($update) {
        writeCompileBaseline($baselinePath, $measured);
        echo "bench-gate --compile: updated {$baselinePath}\n";
        exit(0);
    }

    if (!is_file($baselinePath)) {
        fwrite(STDERR, "bench-gate --compile: missing {$baselinePath} — run with --compile --update\n");
        exit(1);
    }

    $baseline = json_decode((string) file_get_contents($baselinePath), true);
    if (!is_array($baseline)) {
        fwrite(STDERR, "bench-gate --compile: invalid JSON in {$baselinePath}\n");
        exit(1);
    }

    $errors = compareCompileToBaseline($baseline, $measured);
    if ([] !== $errors) {
        fwrite(STDERR, "bench-gate --compile: COMPILE-TIME REGRESSION\n");
        foreach ($errors as $err) {
            fwrite(STDERR, "  {$err}\n");
        }
        exit(1);
    }

    $wallTol = (int) ($baseline['wall_tolerance_percent'] ?? COMPILE_WALL_TOLERANCE_PERCENT);
    $scaleTol = (int) ($baseline['scaling_tolerance_percent'] ?? COMPILE_SCALING_TOLERANCE_PERCENT);
    echo "bench-gate --compile: OK (wall <= +{$wallTol}%, scale <= +{$scaleTol}% vs baseline)\n";
}

/** @return array{wall_s: float, peak_rss_kb: int, ok: bool} */
function measureCompileCommand(string $cmd, string $cwd): array
{
    $bash = <<<'BASH'
set -uo pipefail
cd %s
peak_kb=0
start=$(date +%%s.%%N)
collect_peak() {
  local pid="$1"
  if [[ -r "/proc/${pid}/status" ]]; then
    local rss
    rss=$(awk '/^VmRSS:/ {print $2}' "/proc/${pid}/status" 2>/dev/null || echo 0)
    if [[ "$rss" -gt "$peak_kb" ]]; then peak_kb=$rss; fi
  fi
}
timeout --signal=KILL %d env %s &
root_pid=$!
while kill -0 "$root_pid" 2>/dev/null; do
  collect_peak "$root_pid"
  sleep 0.1
done
wait "$root_pid" || true
rc=$?
if [[ "$rc" -eq 0 ]]; then rc=0; elif [[ "$rc" -gt 128 ]]; then rc=124; fi
collect_peak "$root_pid"
end=$(date +%%s.%%N)
wall=$(awk -v s="$start" -v e="$end" 'BEGIN {printf "%%.6f", e - s}')
printf 'peak_kb=%%s\nwall_rc=%%s\nwall_s=%%s\n' "$peak_kb" "$rc" "$wall"
BASH;

    $script = sprintf($bash, escapeshellarg($cwd), buildCapSeconds(), $cmd);
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open(['bash', '-c', $script], $descriptorSpec, $pipes, $cwd);
    if (!is_resource($proc)) {
        fwrite(STDERR, "bench-gate --compile: could not start: {$cmd}\n");
        exit(1);
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $shellRc = proc_close($proc);

    $peakKb = 0;
    $rc = $shellRc;
    $wall = 0.0;
    foreach (explode("\n", trim($stdout)) as $line) {
        if (str_starts_with($line, 'peak_kb=')) {
            $peakKb = (int) substr($line, 8);
        } elseif (str_starts_with($line, 'wall_rc=')) {
            $rc = (int) substr($line, 8);
        } elseif (str_starts_with($line, 'wall_s=')) {
            $wall = (float) substr($line, 7);
        }
    }

    if (0 !== $rc || 0 !== $shellRc) {
        fwrite(STDERR, "bench-gate --compile: command failed (rc={$rc}, shell={$shellRc}): {$cmd}\n");
        if ('' !== trim($stderr)) {
            fwrite(STDERR, substr(trim($stderr), 0, 2000)."\n");
        }
        exit(1);
    }

    return [
        'wall_s' => $wall,
        'peak_rss_kb' => $peakKb,
        'ok' => true,
    ];
}

function measureCompileScaling(int $count): ?array
{
    require_once dirname(__DIR__).'/vendor/autoload.php';

    $src = "<?php function f() {\n";
    for ($i = 0; $i < $count; ++$i) {
        $src .= '    str_pad(implode(",", array_map("strval", [1,2,3])), 5);'."\n";
    }
    $src .= "}\n";

    $runtime = new \PHPCompiler\Runtime();
    $filename = 'build/bench-gate-scale-'.$count.'.php';
    $script = $runtime->parse($src, $filename);

    $compiler = new \PHPCompiler\Compiler();
    $t0 = hrtime(true);
    $compiler->compile($script);
    $wall = (hrtime(true) - $t0) / 1_000_000_000;
    $msPer = ($wall * 1000.0) / $count;

    return [
        'ms_per_statement' => $msPer,
        'wall_s' => $wall,
        'ok' => true,
    ];
}

/** @param array<string, array<string, float|int|bool>> $measured */
function printCompileTable(array $measured): void
{
    echo "bench-gate compile measurements (#36387):\n";
    printf("%-14s %12s %12s %12s\n", 'case', 'wall(s)', 'peak_rss_mb', 'ms/stmt');
    foreach ($measured as $name => $row) {
        $wall = (float) ($row['wall_s'] ?? 0.0);
        $rssMb = isset($row['peak_rss_kb']) ? (int) $row['peak_rss_kb'] / 1024.0 : 0.0;
        $msStmt = isset($row['ms_per_statement']) ? (float) $row['ms_per_statement'] : 0.0;
        printf(
            "%-14s %12.3f %12.1f %12.1f\n",
            $name,
            $wall,
            $rssMb,
            $msStmt
        );
    }
    echo "\n";
}

/** @param array<string, array<string, float|int|bool>> $measured */
function writeCompileBaseline(string $path, array $measured): void
{
    $cases = [];
    foreach ($measured as $name => $row) {
        $entry = ['ok' => (bool) ($row['ok'] ?? true)];
        if (isset($row['wall_s'])) {
            $entry['wall_s'] = round((float) $row['wall_s'], 6);
        }
        if (isset($row['peak_rss_kb'])) {
            $entry['peak_rss_kb'] = (int) $row['peak_rss_kb'];
        }
        if (isset($row['ms_per_statement'])) {
            $entry['ms_per_statement'] = round((float) $row['ms_per_statement'], 3);
        }
        $cases[$name] = $entry;
    }

    $doc = [
        'version' => 1,
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'arch' => 'x86_64-linux',
        'wall_tolerance_percent' => COMPILE_WALL_TOLERANCE_PERCENT,
        'scaling_tolerance_percent' => COMPILE_SCALING_TOLERANCE_PERCENT,
        'regeneration' => 'PHP_8_2=$(command -v php) ./script/bench-gate.sh --compile --update',
        'note' => 'Compile-time gate for hello-warm, MiniWebApp, Block lint, and Compiler::compile scaling (#36387). Absolute targets live in the issue; this baseline tracks regressions.',
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
function compareCompileToBaseline(array $baseline, array $measured): array
{
    $errors = [];
    $wallTol = (int) ($baseline['wall_tolerance_percent'] ?? COMPILE_WALL_TOLERANCE_PERCENT);
    $scaleTol = (int) ($baseline['scaling_tolerance_percent'] ?? COMPILE_SCALING_TOLERANCE_PERCENT);
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

        if (isset($base['wall_s'], $row['wall_s'])) {
            $baseWall = (float) $base['wall_s'];
            $curWall = (float) $row['wall_s'];
            if ($baseWall > 0.0) {
                $growth = ($curWall - $baseWall) * 100.0 / $baseWall;
                if ($growth > $wallTol) {
                    $errors[] = sprintf(
                        '%s.wall_s: %.3fs exceeds baseline %.3fs by %.1f%% (limit +%d%%)',
                        $name,
                        $curWall,
                        $baseWall,
                        $growth,
                        $wallTol
                    );
                }
            }
        }

        if (isset($base['ms_per_statement'], $row['ms_per_statement'])) {
            $baseMs = (float) $base['ms_per_statement'];
            $curMs = (float) $row['ms_per_statement'];
            if ($baseMs > 0.0) {
                $growth = ($curMs - $baseMs) * 100.0 / $baseMs;
                if ($growth > $scaleTol) {
                    $errors[] = sprintf(
                        '%s.ms_per_statement: %.1f exceeds baseline %.1f by %.1f%% (limit +%d%%)',
                        $name,
                        $curMs,
                        $baseMs,
                        $growth,
                        $scaleTol
                    );
                }
            }
        }
    }

    return $errors;
}
