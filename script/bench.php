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
 * Usage (pinned env):
 *   ./script/docker-exec.sh -- bash -lc 'PHP_8_2=$(command -v php) php script/bench.php'
 *   ./script/docker-exec.sh -- bash -lc 'PHP_8_2=$(command -v php) php script/bench.php --v2'
 */

const ITERATIONS = 5;

$root = dirname(__DIR__);
$argvList = $argv ?? [];
$v2 = in_array('--v2', $argvList, true);

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

echo 'Running '.ITERATIONS.' iterations of each '.($v2 ? 'v2 ' : '')."test, and averaging\n";
$files = [];
foreach ($it as $file) {
    $files[$file->getBasename('.php')] = $file->getPathname();
}
ksort($files, \SORT_STRING);
if ([] === $files) {
    die("No benchmark PHP files under {$suiteDir}\n");
}
foreach ($files as $name => $path) {
    echo "Running {$name}:\n";
    $testResults[$name] = bench($path, $runtimes, $harnessPhp, $llvmEnv, $root);
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
    ITERATIONS
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
        'iterations' => ITERATIONS,
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
function bench(string $file, array $runtimes, string $harnessPhp, string $llvmEnv, string $root): array
{
    echo "Testing each method:\n";
    $expected = trim(capture(escapeshellcmd($harnessPhp).' '.escapeshellarg($file)));
    foreach ($runtimes as $name => $binary) {
        $got = trim(capture(escapeshellcmd($binary).' '.escapeshellarg($file)));
        if ($expected !== $got) {
            die("Failure for Zend {$name}: \"{$got}\" != \"{$expected}\"\n");
        }
    }
    $vmCmd = escapeshellcmd($harnessPhp)
        .' -d error_reporting=1 -d log_errors=1 -d display_errors=stderr'
        .' '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($file);
    $vmOk = trim(capture($vmCmd, $vmRc)) === $expected;
    if (!$vmOk) {
        echo capture_timed_out($vmRc)
            ? "  vm.php exceeded the time cap — vm column n/a\n"
            : "  vm.php output mismatch — vm column n/a\n";
    }

    $jitCmd = $llvmEnv.' '.escapeshellcmd($harnessPhp).' '.escapeshellarg($root.'/bin/jit.php').' '.escapeshellarg($file);
    $jitOk = false;
    if ('' !== $llvmEnv) {
        $jitOk = trim(capture($jitCmd, $jitRc)) === $expected;
        if (!$jitOk) {
            echo capture_timed_out($jitRc)
                ? "  jit.php exceeded the time cap — jit column n/a\n"
                : "  jit.php unavailable or mismatch — jit column n/a\n";
        }
    } else {
        echo "  no LLVM available — jit column n/a\n";
    }

    $binary = tempnam(sys_get_temp_dir(), 'phpcbench');
    $buildCmd = $llvmEnv.' '.escapeshellcmd($root.'/phpc').' build -o '.escapeshellarg($binary).' '.escapeshellarg($file);
    $aotOk = false;
    $buildRc = null;
    if ('' !== $llvmEnv) {
        capture($buildCmd.' 2>&1', $buildRc, buildCapSeconds());
        $aotOk = 0 === $buildRc && is_executable($binary)
            && trim(capture(escapeshellarg($binary))) === $expected;
    }
    if (!$aotOk) {
        echo capture_timed_out($buildRc)
            ? "  phpc build exceeded the build cap — AOT columns n/a\n"
            : "  phpc build failed or output mismatch — AOT columns n/a (#15642)\n";
    }

    $times = [];
    foreach ($runtimes as $name => $bin) {
        $times[$name] = timeCmd(escapeshellcmd($bin).' '.escapeshellarg($file));
    }
    $times['vm'] = $vmOk ? timeCmd($vmCmd) : null;
    $times['jit'] = $jitOk ? timeCmd($jitCmd) : null;
    $times['aotcompile'] = $aotOk ? timeCmd($buildCmd) : null;
    $times['aot'] = $aotOk ? timeCmd(escapeshellarg($binary)) : null;
    @unlink($binary);

    return $times;
}

function timeCmd(string $cmd): float
{
    $start = microtime(true);
    for ($i = 0; $i < ITERATIONS; ++$i) {
        capture($cmd);
    }

    return (microtime(true) - $start) / ITERATIONS;
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

function capture_timed_out(?int $rc): bool
{
    return 124 === $rc || 137 === $rc;
}

function buildCapSeconds(): int
{
    $v = getenv('PHP_COMPILER_BENCH_BUILD_TIMEOUT');

    return is_string($v) && ctype_digit($v) ? (int) $v : 1800;
}
