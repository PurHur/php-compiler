<?php

declare(strict_types=1);

/**
 * Benchmark harness: Zend php-src vs php-compiler VM / JIT / AOT (#15884-era refresh).
 *
 * Runs each benchmarks/*.php under every PHP_X_Y runtime from the environment
 * (e.g. PHP_8_2=/usr/bin/php) plus bin/vm.php, bin/jit.php and a phpc-built
 * native binary, verifies all runtimes agree on output, averages ITERATIONS
 * runs, and rewrites the table in benchmarks/README.md.
 *
 * A per-invocation wall-clock cap (PHP_COMPILER_BENCH_TIMEOUT, default 300 s; builds get
 * PHP_COMPILER_BENCH_BUILD_TIMEOUT, default 1800 s) keeps one unfinishable runtime from blocking
 * the whole suite — bin/vm.php on Ack(3,10) runs >38 min where Zend takes 1.9 s.
 *
 * Usage (pinned env):
 *   ./script/docker-exec.sh -- bash -lc 'PHP_8_2=$(command -v php) php script/bench.php'
 *
 * AOT columns degrade to n/a when the build fails (see #15642) — the harness
 * must never die on a single broken lowering.
 */

const ITERATIONS = 5;

$root = dirname(__DIR__);

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

$it = new GlobIterator($root.'/benchmarks/*.php');
$testResults = [];

echo 'Running '.ITERATIONS." iterations of each test, and averaging\n";
$files = [];
foreach ($it as $file) {
    $files[$file->getBasename('.php')] = $file->getPathname();
}
ksort($files, \SORT_STRING);
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
        $results .= sprintf('|      %12.4f ', $resultset[$rt]);
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

$readme = file_get_contents($root.'/benchmarks/README.md');
$readme = preg_replace(
    '((<!-- benchmark table start -->)(.*)(<!-- benchmark table end -->))ims',
    "\$1\n\n".$stamp.$results."\n\$3",
    $readme
);
file_put_contents($root.'/benchmarks/README.md', $readme);

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
    $vmCmd = escapeshellcmd($harnessPhp).' '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($file);
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
    if ('' !== $llvmEnv) {
        // A compile is allowed far longer than a run: the run cap exists to bound interpreters that
        // cannot finish, whereas `phpc build` is legitimately slow (and slower still on a cold
        // helper-runtime cache, #23458).
        capture($buildCmd.' 2>&1', $buildRc, buildCapSeconds());
        $aotOk = 0 === $buildRc && is_executable($binary)
            && trim(capture(escapeshellarg($binary))) === $expected;
    }
    if (!$aotOk) {
        echo capture_timed_out($buildRc ?? null)
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

/**
 * Run a command under a wall-clock cap.
 *
 * Without a cap a single pathologically slow runtime blocks the whole suite: bin/vm.php is PHP
 * interpreting PHP, so on Ack(3,10) — 1.9 s under Zend — it ran 38 minutes at 100% CPU without
 * finishing, and the harness never reached the AOT columns anyone actually wanted. A runtime that
 * cannot finish inside the cap gets `n/a` for that benchmark, which is the honest answer and lets
 * the remaining columns be measured.
 *
 * Cap applies per invocation (verification and each timed iteration). Override with
 * PHP_COMPILER_BENCH_TIMEOUT; 0 disables it.
 */
function capture(string $cmd, ?int &$rc = null, ?int $capSeconds = null): string
{
    if (null === $capSeconds) {
        $timeout = getenv('PHP_COMPILER_BENCH_TIMEOUT');
        $timeout = is_string($timeout) && ctype_digit($timeout) ? (int) $timeout : 300;
    } else {
        $timeout = $capSeconds;
    }
    if ($timeout > 0) {
        // `env` is required: the jit/AOT commands are prefixed with VAR=value assignments, and
        // `timeout VAR=value cmd` tries to exec "VAR=value" as a program. Without this the LLVM
        // columns silently degrade to n/a while the underlying build is perfectly fine.
        $cmd = 'timeout --signal=KILL '.$timeout.' env '.$cmd;
    }
    exec($cmd.' 2>/dev/null', $lines, $rc);

    return implode("\n", $lines);
}

/** True when {@see capture} killed the command at the cap (timeout(1) reports 124, or 137 for KILL). */
function capture_timed_out(?int $rc): bool
{
    return 124 === $rc || 137 === $rc;
}

/** Wall-clock cap for `phpc build`; override with PHP_COMPILER_BENCH_BUILD_TIMEOUT, 0 disables. */
function buildCapSeconds(): int
{
    $v = getenv('PHP_COMPILER_BENCH_BUILD_TIMEOUT');

    return is_string($v) && ctype_digit($v) ? (int) $v : 1800;
}
