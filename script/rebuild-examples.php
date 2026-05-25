<?php

declare(strict_types=1);

/**
 * Rebuild example JIT artifacts and refresh examples/README.md benchmark table.
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../test/support/CgiCookieJar.php';
require_once __DIR__.'/../test/support/SessionsWebCgiEnv.php';

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

    if ('005-SessionsWeb' === basename($dir) && !shouldBenchSessionsWeb($repoRoot)) {
        echo " - Skipping 005-SessionsWeb benchmark row (lint gate; BENCH_SESSIONSWEB=1 to force)\n";
        continue;
    }

    if ('006-FileUploadWeb' === basename($dir) && !shouldBenchFileUploadWeb($repoRoot)) {
        echo " - Skipping 006-FileUploadWeb benchmark row (lint gate; BENCH_FILEUPLOADWEB=1 to force)\n";
        continue;
    }

    if ('007-ThrowsWeb' === basename($dir) && !shouldBenchThrowsWeb($repoRoot)) {
        echo " - Skipping 007-ThrowsWeb benchmark row (lint gate; BENCH_THROWSWEB=1 to force)\n";
        continue;
    }

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

/**
 * Include 005-SessionsWeb when lint is green (issue #1889).
 *
 * BENCH_SESSIONSWEB=1 forces inclusion; SESSIONSWEB_LINT_GATE=0 skips the lint probe.
 */
function shouldBenchSessionsWeb(string $repoRoot): bool
{
    $example = $repoRoot.'/examples/005-SessionsWeb/example.php';
    if (!is_file($example)) {
        return false;
    }
    if ('1' === getenv('BENCH_SESSIONSWEB')) {
        return true;
    }
    if ('0' === getenv('SESSIONSWEB_LINT_GATE')) {
        return false;
    }

    return sessionsWebLintPasses($repoRoot);
}

/**
 * Include 006-FileUploadWeb when lint is green (issue #2027).
 *
 * BENCH_FILEUPLOADWEB=1 forces inclusion; FILEUPLOADWEB_LINT_GATE=0 skips the lint probe.
 */
function shouldBenchFileUploadWeb(string $repoRoot): bool
{
    $example = $repoRoot.'/examples/006-FileUploadWeb/example.php';
    if (!is_file($example)) {
        return false;
    }
    if ('1' === getenv('BENCH_FILEUPLOADWEB')) {
        return true;
    }
    if ('0' === getenv('FILEUPLOADWEB_LINT_GATE')) {
        return false;
    }

    return fileUploadWebLintPasses($repoRoot);
}

/**
 * Include 007-ThrowsWeb when lint is green (issue #2113).
 *
 * BENCH_THROWSWEB=1 forces inclusion; THROWSWEB_LINT_GATE=0 skips the lint probe.
 */
function shouldBenchThrowsWeb(string $repoRoot): bool
{
    $example = $repoRoot.'/examples/007-ThrowsWeb/example.php';
    if (!is_file($example)) {
        return false;
    }
    if ('1' === getenv('BENCH_THROWSWEB')) {
        return true;
    }
    if ('0' === getenv('THROWSWEB_LINT_GATE')) {
        return false;
    }

    return throwsWebLintPasses($repoRoot);
}

function throwsWebLintPasses(string $repoRoot): bool
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
        [$phpc, 'lint', '--all', $repoRoot.'/examples/007-ThrowsWeb'],
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

function fileUploadWebLintPasses(string $repoRoot): bool
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
        [$phpc, 'lint', '--all', $repoRoot.'/examples/006-FileUploadWeb'],
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

function sessionsWebLintPasses(string $repoRoot): bool
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
        [$phpc, 'lint', '--all', $repoRoot.'/examples/005-SessionsWeb'],
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
 *     skip_aot: bool,
 *     project_aot: bool,
 *     sessions_web_project_aot: bool,
 *     fileupload_web_project_aot: bool
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
            'skip_aot' => false,
            'project_aot' => true,
            'sessions_web_project_aot' => false,
            'fileupload_web_project_aot' => false,
        ];
    }

    if ('005-SessionsWeb' === exampleDisplayName($example)) {
        return [
            'query' => null,
            'cgi_env' => [],
            'aot_compile_time_query' => true,
            'aot_run_env' => [],
            'skip_aot' => false,
            'project_aot' => false,
            'sessions_web_project_aot' => true,
            'fileupload_web_project_aot' => false,
        ];
    }

    if ('006-FileUploadWeb' === exampleDisplayName($example)) {
        return [
            'query' => null,
            'cgi_env' => fileUploadWebMultipartCgiEnv(),
            'aot_compile_time_query' => true,
            'aot_run_env' => [],
            'skip_aot' => false,
            'project_aot' => false,
            'sessions_web_project_aot' => false,
            'fileupload_web_project_aot' => true,
        ];
    }

    if ('007-ThrowsWeb' === exampleDisplayName($example)) {
        return [
            'query' => null,
            'cgi_env' => throwsWebPostCgiEnv(),
            'aot_compile_time_query' => true,
            'aot_run_env' => [],
            'skip_aot' => true,
            'project_aot' => false,
            'sessions_web_project_aot' => false,
            'fileupload_web_project_aot' => false,
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
            'project_aot' => false,
            'sessions_web_project_aot' => false,
            'fileupload_web_project_aot' => false,
        ];
    }

    return [
        'query' => null,
        'cgi_env' => [],
        'aot_compile_time_query' => true,
        'aot_run_env' => [],
        'skip_aot' => false,
        'project_aot' => false,
        'sessions_web_project_aot' => false,
        'fileupload_web_project_aot' => false,
    ];
}

/**
 * POST invalid-email CGI overlay for 007-ThrowsWeb VM/JIT benches (#2113).
 *
 * @return array<string, string>
 */
function throwsWebPostCgiEnv(): array
{
    return [
        'REQUEST_METHOD' => 'POST',
        'REQUEST_BODY' => 'email=bad',
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'SCRIPT_NAME' => '/example.php',
        'REQUEST_URI' => '/example.php',
    ];
}

/**
 * Multipart POST CGI overlay for 006-FileUploadWeb VM/JIT/AOT benches (#2027).
 *
 * @return array<string, string>
 */
function fileUploadWebMultipartCgiEnv(): array
{
    return [
        'REQUEST_METHOD' => 'POST',
        'REQUEST_BODY' => "--phpcFileB\r\n"
            ."Content-Disposition: form-data; name=\"doc\"; filename=\"README.md\"\r\n"
            ."Content-Type: text/plain\r\n\r\n"
            ."bytes\r\n"
            ."--phpcFileB--\r\n",
        'CONTENT_TYPE' => 'multipart/form-data; boundary=phpcFileB',
        'SCRIPT_NAME' => '/example.php',
        'REQUEST_URI' => '/example.php',
    ];
}

function resolveLlvmDir(string $repoRoot): ?string
{
    $candidates = [];
    $fromEnv = getenv('PHP_COMPILER_LLVM_PATH');
    if (false !== $fromEnv && '' !== $fromEnv) {
        $candidates[] = $fromEnv;
    }
    $candidates[] = $repoRoot.'/.llvm';
    $candidates[] = '/opt/llvm9';

    foreach ($candidates as $dir) {
        if (is_file($dir.'/libLLVM-9.so.1')) {
            $resolved = realpath($dir);

            return false !== $resolved ? $resolved : $dir;
        }
    }

    return null;
}

function isLlvmReady(string $repoRoot): bool
{
    $llvmDir = resolveLlvmDir($repoRoot);
    if (null === $llvmDir) {
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
    $llvmDir = resolveLlvmDir($repoRoot);
    if (null !== $llvmDir) {
        $env['PHP_COMPILER_LLVM_PATH'] = $llvmDir;
        $ld = $env['LD_LIBRARY_PATH'] ?? '';
        $env['LD_LIBRARY_PATH'] = '' === $ld ? $llvmDir : $llvmDir.':'.$ld;
        $path = $env['PATH'] ?? '';
        $env['PATH'] = '' === $path ? $llvmDir : $llvmDir.':'.$path;
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
    runProcessCapturing($argv, $env, $cwd);
}

/**
 * @param list<string> $argv
 * @param array<string, string> $env
 *
 * @return array{exit: int, stdout: string, stderr: string}
 */
function runProcessCapturing(array $argv, array $env, string $cwd): array
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($argv, $descriptorSpec, $pipes, $cwd, $env);
    if (!is_resource($proc)) {
        return ['exit' => 1, 'stdout' => '', 'stderr' => 'proc_open failed'];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    return [
        'exit' => $exit,
        'stdout' => false !== $stdout ? $stdout : '',
        'stderr' => false !== $stderr ? $stderr : '',
    ];
}

/**
 * @param array<string, string> $cgiEnv VM column CGI overlay (PATH_INFO=/home, #491)
 *
 * @return array<string, string>
 */
function miniWebAppAotRunEnv(string $repoRoot, array $cgiEnv): array
{
    $publicDir = $repoRoot.'/examples/003-MiniWebApp/public';
    $projectDir = $repoRoot.'/examples/003-MiniWebApp';

    return array_merge([
        'SCRIPT_FILENAME' => $publicDir.'/index.php',
        'SCRIPT_NAME' => '/index.php',
        'DOCUMENT_ROOT' => $publicDir,
        'PHPC_DEPLOY_ROOT' => $projectDir,
    ], $cgiEnv);
}

/**
 * phpc build --project + native execute probe for 003-MiniWebApp (issues #716, #764).
 *
 * @param array<string, string> $benchEnv
 * @param array{
 *     query: ?string,
 *     cgi_env: array<string, string>,
 *     aot_compile_time_query: bool,
 *     aot_run_env: array<string, string>,
 *     skip_aot: bool,
 *     project_aot: bool
 * } $profile
 *
 * @return array{compile: float, compiled: float}|null
 */
function tryBenchmarkMiniWebAppProjectAot(string $repoRoot, array $benchEnv, array $profile): ?array
{
    $project = $repoRoot.'/examples/003-MiniWebApp';
    $phpc = $repoRoot.'/phpc';
    $binary = $project.'/.phpc/bin/app';

    if (!is_executable($phpc)) {
        echo "  003-MiniWebApp AOT: skip (phpc not executable)\n";

        return null;
    }

    $compileStart = microtime(true);
    $build = runProcessCapturing(
        [$phpc, 'build', '--project', $project],
        $benchEnv,
        $repoRoot
    );
    $compileTime = microtime(true) - $compileStart;

    if (0 !== $build['exit']) {
        $stderr = $build['stderr'];
        if (\PHPCompiler\Cli\PhpcBuild::isUserClassAotBlocked($stderr)) {
            echo '  003-MiniWebApp AOT: skip (link blocked: '.trim(substr($stderr, 0, 120))."…)\n";
        } else {
            echo "  003-MiniWebApp AOT: skip (phpc build --project exit {$build['exit']})\n";
        }

        return null;
    }

    if (!is_executable($binary)) {
        echo "  003-MiniWebApp AOT: skip (binary missing after link)\n";

        return null;
    }

    $aotRunEnv = $benchEnv;
    foreach (miniWebAppAotRunEnv($repoRoot, $profile['cgi_env']) as $key => $value) {
        $aotRunEnv[$key] = $value;
    }

    $probe = runProcessCapturing([$binary], $aotRunEnv, $repoRoot);
    if (0 !== $probe['exit'] || '' === trim($probe['stdout'])) {
        echo "  003-MiniWebApp AOT: skip (execute empty stdout or non-zero exit)\n";

        return null;
    }
    if (!str_contains($probe['stdout'], 'MiniWebApp')) {
        echo "  003-MiniWebApp AOT: skip (execute stdout lacks app marker)\n";

        return null;
    }

    $iterations = 10;
    $compiledTime = runIterations([$binary], $aotRunEnv, $repoRoot, $iterations) / $iterations;

    return ['compile' => $compileTime, 'compiled' => $compiledTime];
}

/**
 * phpc build --project + two-request session flash for 005-SessionsWeb (#1891, #1973).
 *
 * @param array<string, string> $benchEnv
 *
 * @return array{compile: float, compiled: float}|null
 */
function tryBenchmarkSessionsWebProjectAot(string $repoRoot, array $benchEnv): ?array
{
    if ('0' === getenv('BENCH_SESSIONSWEB_AOT')) {
        echo "  005-SessionsWeb AOT: skip (BENCH_SESSIONSWEB_AOT=0)\n";

        return null;
    }

    $project = $repoRoot.'/examples/005-SessionsWeb';
    $phpc = $repoRoot.'/phpc';
    $binary = $project.'/.phpc/bin/app';

    if (!is_executable($phpc)) {
        echo "  005-SessionsWeb AOT: skip (phpc not executable)\n";

        return null;
    }

    $sessionDir = sys_get_temp_dir().'/phpc_bench_sessionsweb_'.uniqid('', true);
    if (!@mkdir($sessionDir, 0700, true) && !is_dir($sessionDir)) {
        echo "  005-SessionsWeb AOT: skip (session temp dir)\n";

        return null;
    }

    $aotEnv = $benchEnv;
    $aotEnv['PHP_COMPILER_SESSION_DIR'] = $sessionDir;

    $compileStart = microtime(true);
    $build = runProcessCapturing(
        [$phpc, 'build', '--project', $project],
        $aotEnv,
        $repoRoot
    );
    $compileTime = microtime(true) - $compileStart;

    if (0 !== $build['exit']) {
        $stderr = $build['stderr'];
        if (\PHPCompiler\Cli\PhpcBuild::isUserClassAotBlocked($stderr)) {
            echo '  005-SessionsWeb AOT: skip (link blocked: '.trim(substr($stderr, 0, 120))."…)\n";
        } else {
            echo "  005-SessionsWeb AOT: skip (phpc build --project exit {$build['exit']})\n";
        }
        sessionsWebBenchCleanup($sessionDir);

        return null;
    }

    if (!is_executable($binary)) {
        echo "  005-SessionsWeb AOT: skip (binary missing after link)\n";
        sessionsWebBenchCleanup($sessionDir);

        return null;
    }

    if (!sessionsWebAotFlashProbe($repoRoot, $binary, $aotEnv)) {
        echo "  005-SessionsWeb AOT: skip (two-request flash probe failed)\n";
        sessionsWebBenchCleanup($sessionDir);

        return null;
    }

    $iterations = 10;
    $compiledStart = microtime(true);
    for ($i = 0; $i < $iterations; ++$i) {
        sessionsWebAotFlashProbe($repoRoot, $binary, $aotEnv);
    }
    $compiledTime = (microtime(true) - $compiledStart) / $iterations;

    sessionsWebBenchCleanup($sessionDir);

    return ['compile' => $compileTime, 'compiled' => $compiledTime];
}

/**
 * @param array<string, string> $baseEnv
 */
function sessionsWebAotFlashProbe(string $repoRoot, string $binary, array $baseEnv): bool
{
    $jar = new \PHPCompiler\CgiCookieJar();
    $empty = runProcessCapturing(
        [$binary],
        array_merge($baseEnv, \PHPCompiler\SessionsWebCgiEnv::getEmpty()),
        $repoRoot
    );
    if (0 !== $empty['exit']) {
        return false;
    }
    $jar->absorbFromCgiOutput($empty['stdout']);
    if (!$jar->hasCookie('PHPSESSID')) {
        return false;
    }

    $cookie = $jar->httpCookieHeader();
    $post = runProcessCapturing(
        [$binary],
        array_merge($baseEnv, \PHPCompiler\SessionsWebCgiEnv::postFlash('Saved'), ['HTTP_COOKIE' => $cookie]),
        $repoRoot
    );
    if (0 !== $post['exit']) {
        return false;
    }

    $flash = runProcessCapturing(
        [$binary],
        array_merge($baseEnv, \PHPCompiler\SessionsWebCgiEnv::getEmpty(), ['HTTP_COOKIE' => $jar->httpCookieHeader()]),
        $repoRoot
    );
    if (0 !== $flash['exit']) {
        return false;
    }

    return str_contains($flash['stdout'], 'Flash: Saved');
}

function sessionsWebBenchCleanup(string $sessionDir): void
{
    if (!is_dir($sessionDir)) {
        return;
    }
    foreach (glob($sessionDir.'/sess_*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($sessionDir);
}

/**
 * phpc build --project + multipart upload probe for 006-FileUploadWeb (#2027).
 *
 * @param array<string, string> $benchEnv
 *
 * @return array{compile: float, compiled: float}|null
 */
function tryBenchmarkFileUploadWebProjectAot(string $repoRoot, array $benchEnv): ?array
{
    if ('0' === getenv('BENCH_FILEUPLOADWEB_AOT')) {
        echo "  006-FileUploadWeb AOT: skip (BENCH_FILEUPLOADWEB_AOT=0)\n";

        return null;
    }

    $project = $repoRoot.'/examples/006-FileUploadWeb';
    $phpc = $repoRoot.'/phpc';
    $binary = $project.'/.phpc/bin/app';

    if (!is_executable($phpc)) {
        echo "  006-FileUploadWeb AOT: skip (phpc not executable)\n";

        return null;
    }

    $compileStart = microtime(true);
    $build = runProcessCapturing(
        [$phpc, 'build', '--project', $project],
        $benchEnv,
        $repoRoot
    );
    $compileTime = microtime(true) - $compileStart;

    if (0 !== $build['exit']) {
        $stderr = $build['stderr'];
        if (\PHPCompiler\Cli\PhpcBuild::isUserClassAotBlocked($stderr)) {
            echo '  006-FileUploadWeb AOT: skip (link blocked: '.trim(substr($stderr, 0, 120))."…)\n";
        } else {
            echo "  006-FileUploadWeb AOT: skip (phpc build --project exit {$build['exit']})\n";
        }

        return null;
    }

    if (!is_executable($binary)) {
        echo "  006-FileUploadWeb AOT: skip (binary missing after link)\n";

        return null;
    }

    $aotEnv = $benchEnv;
    foreach (fileUploadWebMultipartCgiEnv() as $key => $value) {
        $aotEnv[$key] = $value;
    }

    if (!fileUploadWebAotMultipartProbe($repoRoot, $binary, $aotEnv)) {
        echo "  006-FileUploadWeb AOT: skip (multipart upload probe failed)\n";

        return null;
    }

    $iterations = 10;
    $compiledStart = microtime(true);
    for ($i = 0; $i < $iterations; ++$i) {
        fileUploadWebAotMultipartProbe($repoRoot, $binary, $aotEnv);
    }
    $compiledTime = (microtime(true) - $compiledStart) / $iterations;

    return ['compile' => $compileTime, 'compiled' => $compiledTime];
}

/**
 * @param array<string, string> $baseEnv
 */
function fileUploadWebAotMultipartProbe(string $repoRoot, string $binary, array $baseEnv): bool
{
    $probe = runProcessCapturing([$binary], $baseEnv, $repoRoot);
    if (0 !== $probe['exit']) {
        return false;
    }

    return str_contains($probe['stdout'], 'Uploaded: README.md');
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
    if ($llvmReady && !empty($profile['sessions_web_project_aot'])) {
        $sessionsAot = tryBenchmarkSessionsWebProjectAot($repoRoot, $benchEnv);
        if (null !== $sessionsAot) {
            $compileTime = $sessionsAot['compile'];
            $compiledTime = $sessionsAot['compiled'];
        }
    } elseif ($llvmReady && !empty($profile['fileupload_web_project_aot'])) {
        $fileUploadAot = tryBenchmarkFileUploadWebProjectAot($repoRoot, $benchEnv);
        if (null !== $fileUploadAot) {
            $compileTime = $fileUploadAot['compile'];
            $compiledTime = $fileUploadAot['compiled'];
        }
    } elseif ($llvmReady && !empty($profile['project_aot'])) {
        $projectAot = tryBenchmarkMiniWebAppProjectAot($repoRoot, $benchEnv, $profile);
        if (null !== $projectAot) {
            $compileTime = $projectAot['compile'];
            $compiledTime = $projectAot['compiled'];
        }
    } elseif ($llvmReady && !$profile['skip_aot']) {
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
