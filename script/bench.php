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
    $vmOk = trim(capture($vmCmd)) === $expected;
    if (!$vmOk) {
        echo "  vm.php output mismatch — vm column n/a\n";
    }

    $jitCmd = $llvmEnv.' '.escapeshellcmd($harnessPhp).' '.escapeshellarg($root.'/bin/jit.php').' '.escapeshellarg($file);
    $jitOk = '' !== $llvmEnv && trim(capture($jitCmd)) === $expected;
    if (!$jitOk) {
        echo "  jit.php unavailable or mismatch — jit column n/a\n";
    }

    $binary = tempnam(sys_get_temp_dir(), 'phpcbench');
    $buildCmd = $llvmEnv.' '.escapeshellcmd($root.'/phpc').' build -o '.escapeshellarg($binary).' '.escapeshellarg($file);
    $aotOk = false;
    if ('' !== $llvmEnv) {
        capture($buildCmd.' 2>&1', $buildRc);
        $aotOk = 0 === $buildRc && is_executable($binary)
            && trim(capture(escapeshellarg($binary))) === $expected;
    }
    if (!$aotOk) {
        echo "  phpc build failed or output mismatch — AOT columns n/a (#15642)\n";
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

function capture(string $cmd, ?int &$rc = null): string
{
    exec($cmd.' 2>/dev/null', $lines, $rc);

    return implode("\n", $lines);
}
