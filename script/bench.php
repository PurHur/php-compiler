<?php

declare(strict_types=1);

/**
 * Benchmark harness: Zend php-src vs php-compiler VM / JIT / AOT.
 *
 * Default: benchmarks/*.php (legacy micro-suite).
 * --v2:     benchmarks/v2/*.php (#36385) + RESULTS.json + optional history.
 *           Also runs script/bench-web-request.php (MiniWebApp req/s) and refreshes
 *           docs/pages/bench.html unless PHP_COMPILER_BENCH_SKIP_WEB=1.
 *
 * v2 wall budget (#36385 Done-when): regenerate in < 15 min. Defaults differ from
 * the legacy suite — shorter per-run cap, fewer average iterations, skip JIT after
 * a VM time-cap, and reuse a slow verify sample instead of re-running it N times.
 *
 * Usage (pinned env):
 *   ./script/docker-exec.sh -- bash -lc 'PHP_8_2=$(command -v php) php script/bench.php'
 *   ./script/docker-exec.sh -- bash -lc 'PHP_8_2=$(command -v php) php script/bench.php --v2'
 */

const ITERATIONS_LEGACY = 5;
/** Fewer averages keep v2 under the 15 min regen budget (#36385). */
const ITERATIONS_V2 = 3;
/**
 * Fail-fast per-run cap for v2 VM/JIT (seconds). Legacy default remains 300s.
 * Override with PHP_COMPILER_BENCH_TIMEOUT. binary-trees / fannkuch-redux still
 * time out under VM; a 300s cap burned ~20 min on those two alone.
 */
const V2_DEFAULT_TIMEOUT_SEC = 25;
/**
 * When a successful VM/JIT verify already took this long, reuse that sample as
 * the column time instead of averaging more runs (#36385 wall budget).
 */
const V2_REUSE_VERIFY_SEC = 5.0;

$root = dirname(__DIR__);
$argvList = $argv ?? [];
$v2 = in_array('--v2', $argvList, true);
$iterations = $v2 ? ITERATIONS_V2 : ITERATIONS_LEGACY;

$runtimes = [];
foreach (getenv() as $key => $value) {
    if (1 === preg_match("/^PHP_\\d+_\\d+$/", (string) $key) && is_string($value) && is_executable($value)) {
        $runtimes[str_replace('_', '.', substr($key, 4))] = $value;
    }
}
ksort($runtimes, \SORT_STRING);
if ([] === $runtimes) {
    die("Specify at least one Zend runtime via PHP_X_Y=/path/to/php (e.g. PHP_8_2)\n");
}
$harnessPhp = reset($runtimes);

$llvmEnv = '';
foreach ([getenv('PHP_COMPILER_LLVM_PATH') ?: '', $root.'/.llvm', '/opt/llvm9'] as $dir) {
    if ('' !== $dir && is_file($dir.'/libLLVM-9.so.1')) {
        $llvmEnv = 'PHP_COMPILER_LLVM_PATH='.escapeshellarg($dir)
            .' LD_LIBRARY_PATH='.escapeshellarg($dir);
        break;
    }
}

$suiteDir = $v2 ? $root.'/benchmarks/v2' : $root.'/benchmarks';
$it = new GlobIterator($suiteDir.'/*.php');
$testResults = [];

echo 'Running '.$iterations.' iterations of each '.($v2 ? 'v2 ' : '')."test, and averaging\n";
if ($v2) {
    echo 'v2 caps: timeout='.benchTimeoutSeconds(true).'s'
        .' · reuse-verify>='.V2_REUSE_VERIFY_SEC.'s'
        ." · skip JIT after VM time-cap (#36385)\n";
}
$files = [];
foreach ($it as $file) {
    $files[$file->getBasename('.php')] = $file->getPathname();
}
ksort($files, \SORT_STRING);
if ([] === $files) {
    die("No benchmark PHP files under {$suiteDir}\n");
}
$suiteStarted = microtime(true);
foreach ($files as $name => $path) {
    echo "Running {$name}:\n";
    $testResults[$name] = bench($path, $runtimes, $harnessPhp, $llvmEnv, $root, $v2, $iterations);
}

$results = '| Test Name          ';
foreach ($runtimes as $name => $path) {
    $results .= sprintf('| Zend %9s (s)', $name);
}
$results .= "| bin/vm.php (s) | bin/jit.php (s) | phpc build (s) | native run (s) |\n";
$results .= '|--------------------';
foreach ($runtimes as $name => $path) {
    $results .= '|'.str_repeat('-', 19);
}
$results .= "|----------------|-----------------|----------------|----------------|\n";
foreach ($testResults as $name => $resultset) {
    $results .= sprintf('| %18s ', $name);
    foreach (array_keys($runtimes) as $rt) {
        $val = $resultset[$rt] ?? null;
        $results .= is_float($val)
            ? sprintf('|      %12.4f ', $val)
            : sprintf('|      %12s ', 'n/a');
    }
    foreach (['vm', 'jit', 'aotcompile', 'aot'] as $col) {
        $results .= is_float($resultset[$col] ?? null)
            ? sprintf('|   %12.4f ', $resultset[$col])
            : sprintf('|   %12s ', 'n/a');
    }
    $results .= "|\n";
}

$stamp = sprintf(
    "Environment: %s · LLVM 9 %s · %d iterations averaged, wall time per run.\n\n",
    trim((string) shell_exec(escapeshellcmd($harnessPhp).' -r "echo PHP_VERSION;"')),
    '' !== $llvmEnv ? 'available' : 'unavailable (VM/JIT only)',
    $iterations
);

if ($v2) {
    $readmePath = $suiteDir.'/README.md';
    $readme = is_file($readmePath) ? (string) file_get_contents($readmePath) : '';
    if (!str_contains($readme, '<!-- v2 benchmark table start -->')) {
        $readme .= "\n<!-- v2 benchmark table start -->\n\n<!-- v2 benchmark table end -->\n";
    }
    $readme = preg_replace(
        '((<!-- v2 benchmark table start -->)(.*)(<!-- v2 benchmark table end -->))ims',
        "\$1\n\n".$stamp.$results."\n\$3",
        $readme
    );
    file_put_contents($readmePath, $readme);

    $payload = [
        'version' => 1,
        'suite' => 'v2',
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'php_version' => trim((string) shell_exec(escapeshellcmd($harnessPhp).' -r "echo PHP_VERSION;"')),
        'iterations' => $iterations,
        'cases' => $testResults,
    ];
    $resultsJson = $suiteDir.'/RESULTS.json';
    file_put_contents($resultsJson, json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
    echo "Wrote {$resultsJson}\n";

    if ('1' !== getenv('PHP_COMPILER_BENCH_SKIP_WEB')) {
        $webCmd = escapeshellcmd($harnessPhp).' '.escapeshellarg($root.'/script/bench-web-request.php')
            .' --merge-results';
        echo "Running web-request column (#36385)...\n";
        passthru($webCmd, $webRc);
        if (0 !== $webRc) {
            fwrite(STDERR, "bench.php --v2: bench-web-request exited {$webRc} (continuing; web column may be incomplete)\n");
        }
    }

    if ('1' === getenv('PHP_COMPILER_BENCH_HISTORY')) {
        $histDir = $root.'/benchmarks/history';
        if (!is_dir($histDir) && !mkdir($histDir, 0777, true) && !is_dir($histDir)) {
            fwrite(STDERR, "bench.php --v2: cannot create {$histDir}\n");
        } else {
            $sha = trim((string) shell_exec('git -C '.escapeshellarg($root).' rev-parse --short HEAD 2>/dev/null'));
            if ('' === $sha) {
                $sha = gmdate('YmdHis');
            }
            $histPayload = json_decode((string) file_get_contents($resultsJson), true);
            if (!is_array($histPayload)) {
                $histPayload = $payload;
            }
            $histPath = $histDir.'/'.$sha.'.json';
            file_put_contents($histPath, json_encode($histPayload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
            echo "Wrote {$histPath}\n";
        }
    }

    $chartCmd = escapeshellcmd($harnessPhp).' '.escapeshellarg($root.'/script/generate-bench-chart.php');
    passthru($chartCmd, $chartRc);
    if (0 !== $chartRc) {
        fwrite(STDERR, "bench.php --v2: generate-bench-chart exited {$chartRc}\n");
    }

    $suiteWall = microtime(true) - $suiteStarted;
    printf("v2 suite wall: %.1fs (budget 900s / 15 min, #36385)\n", $suiteWall);
    if ($suiteWall > 900.0) {
        fwrite(STDERR, "bench.php --v2: exceeded 15 min wall (#36385 Done-when)\n");
        exit(1);
    }
} else {
    $readme = file_get_contents($root.'/benchmarks/README.md');
    $readme = preg_replace(
        '((<!-- benchmark table start -->)(.*)(<!-- benchmark table end -->))ims',
        "\$1\n\n".$stamp.$results."\n\$3",
        $readme
    );
    file_put_contents($root.'/benchmarks/README.md', $readme);
}

echo $results;

/** @param array<string, string> $runtimes */
function bench(
    string $file,
    array $runtimes,
    string $harnessPhp,
    string $llvmEnv,
    string $root,
    bool $v2,
    int $iterations
): array {
    echo "Testing each method:\n";
    $expected = trim(capture(escapeshellcmd($harnessPhp).' '.escapeshellarg($file), $zendRc, null, $v2));
    foreach ($runtimes as $name => $binary) {
        $got = trim(capture(escapeshellcmd($binary).' '.escapeshellarg($file), $rtRc, null, $v2));
        if ($expected !== $got) {
            die("Failure for Zend {$name}: \"{$got}\" != \"{$expected}\"\n");
        }
    }
    $vmCmd = escapeshellcmd($harnessPhp)
        .' -d error_reporting=1 -d log_errors=1 -d display_errors=stderr'
        .' '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($file);
    $vmStarted = microtime(true);
    $vmOut = trim(capture($vmCmd, $vmRc, null, $v2));
    $vmVerifySec = microtime(true) - $vmStarted;
    $vmOk = $vmOut === $expected;
    $vmTimedOut = !$vmOk && capture_timed_out($vmRc);
    if (!$vmOk) {
        echo $vmTimedOut
            ? "  vm.php exceeded the time cap — vm column n/a\n"
            : "  vm.php output mismatch — vm column n/a\n";
    }

    $jitCmd = $llvmEnv.' '.escapeshellcmd($harnessPhp).' '.escapeshellarg($root.'/bin/jit.php').' '.escapeshellarg($file);
    $jitOk = false;
    $jitVerifySec = 0.0;
    $jitRc = null;
    if ('' === $llvmEnv) {
        echo "  no LLVM available — jit column n/a\n";
    } elseif ($v2 && $vmTimedOut) {
        // Same workload will hit the cap under JIT; do not burn another full timeout (#36385).
        echo "  skip jit after vm time-cap — jit column n/a\n";
    } else {
        $jitStarted = microtime(true);
        $jitOk = trim(capture($jitCmd, $jitRc, null, $v2)) === $expected;
        $jitVerifySec = microtime(true) - $jitStarted;
        if (!$jitOk) {
            echo capture_timed_out($jitRc)
                ? "  jit.php exceeded the time cap — jit column n/a\n"
                : "  jit.php unavailable or mismatch — jit column n/a\n";
        }
    }

    $binary = tempnam(sys_get_temp_dir(), 'phpcbench');
    $buildCmd = $llvmEnv.' '.escapeshellcmd($root.'/phpc').' build -o '.escapeshellarg($binary).' '.escapeshellarg($file);
    $aotOk = false;
    $buildRc = null;
    if ('' !== $llvmEnv) {
        capture($buildCmd.' 2>&1', $buildRc, buildCapSeconds(), $v2);
        $aotOk = 0 === $buildRc && is_executable($binary)
            && trim(capture(escapeshellarg($binary), $aotRunRc, null, $v2)) === $expected;
    }
    if (!$aotOk) {
        echo capture_timed_out($buildRc)
            ? "  phpc build exceeded the build cap — AOT columns n/a\n"
            : "  phpc build failed or output mismatch — AOT columns n/a (#15642)\n";
    }

    $times = [];
    foreach ($runtimes as $name => $bin) {
        $times[$name] = timeCmd(escapeshellcmd($bin).' '.escapeshellarg($file), $iterations, $v2);
    }
    $times['vm'] = $vmOk
        ? timeOrReuse($vmCmd, $vmVerifySec, $iterations, $v2)
        : null;
    $times['jit'] = $jitOk
        ? timeOrReuse($jitCmd, $jitVerifySec, $iterations, $v2)
        : null;
    $times['aotcompile'] = $aotOk ? timeCmd($buildCmd, $iterations, $v2) : null;
    $times['aot'] = $aotOk ? timeCmd(escapeshellarg($binary), $iterations, $v2) : null;
    @unlink($binary);

    return $times;
}

function timeOrReuse(string $cmd, float $verifySec, int $iterations, bool $v2): float
{
    if ($v2 && $verifySec >= V2_REUSE_VERIFY_SEC) {
        echo sprintf("  reuse %.2fs verify sample (skip %d-iter average)\n", $verifySec, $iterations);

        return $verifySec;
    }

    return timeCmd($cmd, $iterations, $v2);
}

function timeCmd(string $cmd, int $iterations, bool $v2): float
{
    $start = microtime(true);
    for ($i = 0; $i < $iterations; ++$i) {
        capture($cmd, $rc, null, $v2);
    }

    return (microtime(true) - $start) / $iterations;
}

function capture(string $cmd, ?int &$rc = null, ?int $capSeconds = null, bool $v2 = false): string
{
    if (null === $capSeconds) {
        $timeout = benchTimeoutSeconds($v2);
    } else {
        $timeout = $capSeconds;
    }
    if ($timeout > 0) {
        $cmd = 'timeout --signal=KILL '.$timeout.' env '.$cmd;
    }
    exec($cmd.' 2>/dev/null', $lines, $rc);

    return implode("\n", $lines);
}

function capture_timed_out(?int $rc): bool
{
    return 124 === $rc || 137 === $rc;
}

function benchTimeoutSeconds(bool $v2): int
{
    $fromEnv = getenv('PHP_COMPILER_BENCH_TIMEOUT');
    if (is_string($fromEnv) && ctype_digit($fromEnv)) {
        return (int) $fromEnv;
    }

    return $v2 ? V2_DEFAULT_TIMEOUT_SEC : 300;
}

function buildCapSeconds(): int
{
    $v = getenv('PHP_COMPILER_BENCH_BUILD_TIMEOUT');

    return is_string($v) && ctype_digit($v) ? (int) $v : 1800;
}
