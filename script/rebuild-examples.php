<?php

declare(strict_types=1);

/**
 * Rebuild example JIT artifacts and refresh examples/README.md benchmark table.
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

require_once __DIR__.'/../vendor/autoload.php';

echo "Rebuilding Examples\n";

$repoRoot = realpath(__DIR__.'/..') ?: __DIR__.'/..';
$llvmReady = isLlvmReady($repoRoot);
$phpCmd = phpCommand();
$benchEnv = benchmarkEnv($repoRoot);

$benchmarks = <<<HERE
|         Example Name |      Native PHP |      bin/vm.php |     bin/jit.php | bin/compile.php |      ./compiled |
|----------------------|-----------------|-----------------|-----------------|-----------------|-----------------|
HERE;

$exampleDirs = [];
$it = new DirectoryIterator($repoRoot.'/examples/');
foreach ($it as $file) {
    if ($file->isDot() || !$file->isDir()) {
        continue;
    }
    $dir = $file->getPathname();
    $example = $dir.'/example.php';
    if (is_file($example)) {
        $exampleDirs[] = $dir;
    }
}
usort($exampleDirs, static fn (string $a, string $b): int => strcmp(basename($a), basename($b)));

$miniIndex = $repoRoot.'/examples/003-MiniWebApp/public/index.php';
$benchMiniWebApp = shouldBenchMiniWebApp($repoRoot) && is_file($miniIndex);

foreach ($exampleDirs as $dir) {
    if ($benchMiniWebApp && '004-ApiJson' === basename($dir)) {
        echo " - Benchmarking 003-MiniWebApp (public/index.php)\n";
        $benchmarks .= "\n".benchmarkExample($miniIndex, $phpCmd, $benchEnv, $repoRoot, $llvmReady);
    }

    $example = $dir.'/example.php';
    echo ' - Building Example '.basename($dir)."\n";

    $jitArgv = array_merge($phpCmd, [$repoRoot.'/bin/jit.php', '-y', $example]);
    runProcess($jitArgv, $benchEnv, $repoRoot);
    file_put_contents($dir.'/example.output', '');

    $benchmarks .= "\n".benchmarkExample($example, $phpCmd, $benchEnv, $repoRoot, $llvmReady);
}

if ($benchMiniWebApp && !str_contains($benchmarks, '003-MiniWebApp')) {
    echo " - Benchmarking 003-MiniWebApp (public/index.php)\n";
    $benchmarks .= "\n".benchmarkExample($miniIndex, $phpCmd, $benchEnv, $repoRoot, $llvmReady);
}

$readme = file_get_contents($repoRoot.'/examples/README.md');
$readme = preg_replace(
    '((<!-- benchmark table start -->)(.*)(<!-- benchmark table end -->))ims',
    "\$1\n\n".$benchmarks."\n\$3",
    $readme
);
file_put_contents($repoRoot.'/examples/README.md', $readme);

echo "Done\n";

/**
 * Include 003-MiniWebApp when lint is green (issue #491, #621).
 *
 * BENCH_MINIWEBAPP=1 forces inclusion; MINIWEBAPP_LINT_GATE=0 skips the lint probe.
 */
function shouldBenchMiniWebApp(string $repoRoot): bool
{
    $index = $repoRoot.'/examples/003-MiniWebApp/public/index.php';
    if (!is_file($index)) {
        return false;
    }
    if ('1' === getenv('BENCH_MINIWEBAPP')) {
        return true;
    }
    if ('0' === getenv('MINIWEBAPP_LINT_GATE')) {
        return false;
    }

    return miniWebAppLintPasses($repoRoot);
}

function miniWebAppLintPasses(string $repoRoot): bool
{
    $phpc = $repoRoot.'/phpc';
    if (!is_executable($phpc)) {
        return false;
    }
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open(
        [$phpc, 'lint', '--all', $repoRoot.'/examples/003-MiniWebApp'],
        $descriptorSpec,
        $pipes,
        $repoRoot
    );
    if (!is_resource($proc)) {
        return false;
    }
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return 0 === proc_close($proc);
}

function exampleDisplayName(string $example): string
{
    if (str_contains($example, '/examples/003-MiniWebApp/')) {
        return '003-MiniWebApp';
    }

    return basename(dirname($example));
}

/**
 * Per-example benchmark inputs.
 *
 * @return array{
 *     query: ?string,
 *     cgi_env: array<string, string>,
 *     aot_compile_time_query: bool,
 *     aot_run_env: array<string, string>,
 *     skip_aot: bool
 * }
 */
function exampleProfile(string $example): array
{
    if (str_contains($example, '/examples/003-MiniWebApp/')) {
        return [
            'query' => null,
            'cgi_env' => [
                'REQUEST_METHOD' => 'GET',
                'PATH_INFO' => '/home',
                'SCRIPT_NAME' => '/index.php',
                'REQUEST_URI' => '/index.php/home',
            ],
            'aot_compile_time_query' => false,
            'aot_run_env' => [],
            'skip_aot' => true,
        ];
    }

    if ('001-SimpleWeb' === exampleDisplayName($example)) {
        // Runtime superglobals (#201): compile once without -q; benchmark run uses QUERY_STRING.
        return [
            'query' => 'name=World',
            'cgi_env' => [],
            'aot_compile_time_query' => false,
            'aot_run_env' => [
                'QUERY_STRING' => 'name=World',
                'SCRIPT_NAME' => '/example.php',
                'REQUEST_URI' => '/example.php?name=World',
            ],
            'skip_aot' => false,
        ];
    }

    return [
        'query' => null,
        'cgi_env' => [],
        'aot_compile_time_query' => true,
        'aot_run_env' => [],
        'skip_aot' => false,
    ];
}

function isLlvmReady(string $repoRoot): bool
{
    $llvmDir = $repoRoot.'/.llvm';
    if (!is_file($llvmDir.'/libLLVM-9.so.1')) {
        return false;
    }
    if ('' === getenv('PHP_COMPILER_LLVM_PATH')) {
        putenv('PHP_COMPILER_LLVM_PATH='.$llvmDir);
        $_ENV['PHP_COMPILER_LLVM_PATH'] = $llvmDir;
        $_SERVER['PHP_COMPILER_LLVM_PATH'] = $llvmDir;
    }
    try {
        \PHPLLVM\Chooser::choose();

        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * @return array<string, string>
 */
function benchmarkEnv(string $repoRoot): array
{
    $env = [];
    foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
        if (is_string($value)) {
            $env[$key] = $value;
        }
    }
    $llvmDir = $repoRoot.'/.llvm';
    if (is_file($llvmDir.'/libLLVM-9.so.1')) {
        $prefix = realpath($llvmDir) ?: $llvmDir;
        $env['PHP_COMPILER_LLVM_PATH'] = $prefix;
        $ld = $env['LD_LIBRARY_PATH'] ?? '';
        $env['LD_LIBRARY_PATH'] = '' === $ld ? $prefix : $prefix.':'.$ld;
        $path = $env['PATH'] ?? '';
        $env['PATH'] = '' === $path ? $prefix : $prefix.':'.$path;
    }

    return $env;
}

/**
 * @return list<string>
 */
function phpCommand(): array
{
    $phpEnv = getenv('PHP_COMPILER_PHP');
    if (false !== $phpEnv && '' !== $phpEnv) {
        $cmd = preg_split('/\s+/', $phpEnv) ?: [PHP_BINARY];
    } else {
        $cmd = [PHP_BINARY];
    }
    $extDir = getenv('PHP_COMPILER_EXT_DIR') ?: '/usr/lib/php/20220829';
    if (is_dir($extDir)) {
        foreach (['tokenizer', 'mbstring', 'dom', 'xml', 'xmlwriter', 'ffi', 'posix', 'phar'] as $ext) {
            $so = $extDir.'/'.$ext.'.so';
            if (is_file($so)) {
                $cmd[] = '-d';
                $cmd[] = 'extension='.$so;
            }
        }
    }
    $cmd[] = '-d';
    $cmd[] = 'display_errors=0';
    $cmd[] = '-d';
    $cmd[] = 'error_reporting=0';

    return $cmd;
}

/**
 * @param list<string> $argv
 * @param array<string, string> $env
 */
function runProcess(array $argv, array $env, string $cwd): void
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($argv, $descriptorSpec, $pipes, $cwd, $env);
    if (!is_resource($proc)) {
        return;
    }
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
}

/**
 * @param list<string> $argv
 * @param array<string, string> $env
 */
function runIterations(array $argv, array $env, string $cwd, int $iterations): float
{
    $start = microtime(true);
    for ($i = 0; $i < $iterations; ++$i) {
        runProcess($argv, $env, $cwd);
    }

    return microtime(true) - $start;
}

/**
 * @param list<string> $phpCmd
 * @param array<string, string> $benchEnv
 */
function benchmarkExample(string $example, array $phpCmd, array $benchEnv, string $repoRoot, bool $llvmReady): string
{
    $iterations = 10;
    $profile = exampleProfile($example);
    $runCwd = str_contains($example, '/examples/003-MiniWebApp/')
        ? dirname($example)
        : $repoRoot;
    echo "Benchmarking {$example}\n";

    $nativeEnv = $benchEnv;
    if (null !== $profile['query']) {
        $nativeEnv['QUERY_STRING'] = $profile['query'];
    }
    foreach ($profile['cgi_env'] as $key => $value) {
        $nativeEnv[$key] = $value;
    }

    $nativeArgv = array_merge($phpCmd, [$example]);
    $nativeTime = runIterations($nativeArgv, $nativeEnv, $runCwd, $iterations) / $iterations;

    $vmEnv = $benchEnv;
    foreach ($profile['cgi_env'] as $key => $value) {
        $vmEnv[$key] = $value;
    }
    $vmArgv = array_merge($phpCmd, [$repoRoot.'/bin/vm.php']);
    if (null !== $profile['query']) {
        $vmArgv[] = '-q';
        $vmArgv[] = $profile['query'];
    }
    $vmArgv[] = $example;
    $vmTime = runIterations($vmArgv, $vmEnv, $runCwd, $iterations) / $iterations;

    $jitEnv = $benchEnv;
    foreach ($profile['cgi_env'] as $key => $value) {
        $jitEnv[$key] = $value;
    }
    $jitArgv = array_merge($phpCmd, [$repoRoot.'/bin/jit.php']);
    if (null !== $profile['query']) {
        $jitArgv[] = '-q';
        $jitArgv[] = $profile['query'];
    }
    $jitArgv[] = $example;
    $jitTime = runIterations($jitArgv, $jitEnv, $runCwd, $iterations) / $iterations;

    $compileTime = null;
    $compiledTime = null;
    if ($llvmReady && !$profile['skip_aot']) {
        $binary = str_replace('.php', '', $example);
        $compileArgv = array_merge($phpCmd, [$repoRoot.'/bin/compile.php']);
        if (null !== $profile['query'] && $profile['aot_compile_time_query']) {
            $compileArgv[] = '-q';
            $compileArgv[] = $profile['query'];
        }
        $compileArgv[] = '-o';
        $compileArgv[] = $binary;
        $compileArgv[] = $example;
        $compileStart = microtime(true);
        runProcess($compileArgv, $benchEnv, $repoRoot);
        $compileTime = microtime(true) - $compileStart;
        if (is_executable($binary)) {
            $aotRunEnv = $benchEnv;
            foreach ($profile['aot_run_env'] as $key => $value) {
                $aotRunEnv[$key] = $value;
            }
            $compiledTime = runIterations([$binary], $aotRunEnv, $repoRoot, $iterations) / $iterations;
            @unlink($binary);
        }
    }

    $result = sprintf('| %20s |', exampleDisplayName($example));
    $result .= sprintf('         %0.5f |', $nativeTime);
    $result .= sprintf('         %0.5f |', $vmTime);
    $result .= sprintf('         %0.5f |', $jitTime);
    if (null === $compileTime) {
        $result .= '             n/a |';
    } else {
        $result .= sprintf('         %0.5f |', $compileTime);
    }
    if (null === $compiledTime) {
        $result .= '             n/a |';
    } else {
        $result .= sprintf('         %0.5f |', $compiledTime);
    }

    return $result;
}
