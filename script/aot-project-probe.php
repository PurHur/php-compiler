#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Fast AOT project preflight: phpc build --project + CGI execute needle (issue #746).
 *
 * Exit 0 when LLVM missing (skip). Exit 0 when build + execute show HTML needles.
 * Exit non-zero on link/execute failure with actionable stderr.
 */

$root = dirname(__DIR__);
$projectArg = $argv[1] ?? '';
$defaultProject = $root.'/examples/003-MiniWebApp';
if ('' === $projectArg) {
    $projectDir = $defaultProject;
} elseif (str_starts_with($projectArg, '/')) {
    $projectDir = $projectArg;
} else {
    $projectDir = $root.'/'.ltrim($projectArg, '/');
}
$resolvedProject = realpath($projectDir);
if (false === $resolvedProject) {
    fwrite(STDERR, "aot-project-probe: project directory not found: {$projectDir}\n");
    exit(2);
}
$projectDir = $resolvedProject;

$llvmDir = resolve_probe_llvm_dir($root);
if (null === $llvmDir) {
    fwrite(STDOUT, "aot-project-probe skipped: LLVM 9 not found\n");
    exit(0);
}

$autoload = $root.'/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "aot-project-probe: run composer install first\n");
    exit(2);
}
require $autoload;
require_once $root.'/test/support/MiniWebAppCgiEnv.php';
require_once $root.'/test/LlvmToolchain.php';

if (!is_file($projectDir.'/phpc.json')) {
    fwrite(STDERR, "aot-project-probe: no phpc.json in {$projectDir}\n");
    exit(2);
}

$phpc = $root.'/phpc';
if (!is_file($phpc)) {
    fwrite(STDERR, "aot-project-probe: {$phpc} missing\n");
    exit(2);
}

$env = build_base_env();
\PHPCompiler\LlvmToolchain::applyProcessEnv($env, $root);

fwrite(STDOUT, "aot-project-probe: building {$projectDir} …\n");
$buildResult = run_process([$phpc, 'build', '--project', $projectDir], $root, $env);
if (0 !== $buildResult['exit']) {
    fwrite(STDERR, "aot-project-probe: phpc build --project failed (exit {$buildResult['exit']})\n");
    emit_tail_stderr($buildResult['stderr']);
    exit(1);
}

$binary = $projectDir.'/.phpc/bin/app';
if (!is_executable($binary)) {
    fwrite(STDERR, "aot-project-probe: binary missing after build: {$binary}\n");
    exit(1);
}

$executeEnv = $env;
foreach (\PHPCompiler\MiniWebAppCgiEnv::aotFrontController($root) as $key => $value) {
    $executeEnv[$key] = $value;
}
foreach (\PHPCompiler\MiniWebAppCgiEnv::queryRouteHome() as $key => $value) {
    $executeEnv[$key] = $value;
}

fwrite(STDOUT, "aot-project-probe: execute home route …\n");
$runResult = run_process([$binary], $root, $executeEnv);
if (0 !== $runResult['exit']) {
    fwrite(STDERR, "aot-project-probe: binary exited {$runResult['exit']}\n");
    emit_tail_stderr($runResult['stderr']);
    exit(1);
}

$stdout = $runResult['stdout'];
$bytes = strlen($stdout);
if (0 === $bytes) {
    fwrite(STDERR, "aot-project-probe: empty stdout from AOT binary (track #764 execute)\n");
    fwrite(STDERR, "  Hint: phpc doctor --aot-project-probe after LLVM PATH/LD_LIBRARY_PATH fix (#98)\n");
    emit_tail_stderr($runResult['stderr']);
    exit(1);
}

$needle = \PHPCompiler\MiniWebAppCgiEnv::APP_NAME;
if (!str_contains($stdout, $needle)) {
    fwrite(STDERR, "aot-project-probe: stdout ({$bytes} bytes) missing app needle “{$needle}”\n");
    exit(1);
}

fwrite(STDOUT, "aot-project-probe OK ({$bytes} bytes, needle “{$needle}”)\n");
exit(0);

/**
 * @return array<string, string>
 */
function build_base_env(): array
{
    $env = [];
    foreach ($_ENV as $key => $value) {
        if (is_string($value)) {
            $env[$key] = $value;
        }
    }
    foreach ($_SERVER as $key => $value) {
        if (is_string($value) && !isset($env[$key])) {
            $env[$key] = $value;
        }
    }

    return $env;
}

/**
 * @param list<string>          $cmd
 * @param array<string, string> $env
 *
 * @return array{exit: int, stdout: string, stderr: string}
 */
function run_process(array $cmd, string $cwd, array $env): array
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptorSpec, $pipes, $cwd, $env);
    if (!is_resource($proc)) {
        return ['exit' => 2, 'stdout' => '', 'stderr' => 'proc_open failed'];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    return [
        'exit' => is_int($exit) ? $exit : 1,
        'stdout' => false !== $stdout ? $stdout : '',
        'stderr' => false !== $stderr ? $stderr : '',
    ];
}

function emit_tail_stderr(string $stderr): void
{
    $stderr = trim($stderr);
    if ('' === $stderr) {
        return;
    }
    $lines = explode("\n", $stderr);
    $tail = array_slice($lines, -8);
    fwrite(STDERR, implode("\n", $tail)."\n");
}

/**
 * @return non-empty-string|null
 */
function resolve_probe_llvm_dir(string $repoRoot): ?string
{
    $fromEnv = getenv('PHP_COMPILER_LLVM_PATH');
    if (false !== $fromEnv && '' !== $fromEnv && is_file($fromEnv.'/libLLVM-9.so.1')) {
        $resolved = realpath($fromEnv);

        return false !== $resolved ? $resolved : $fromEnv;
    }
    foreach ([$repoRoot.'/.llvm', '/opt/llvm9'] as $candidate) {
        if (is_file($candidate.'/libLLVM-9.so.1')) {
            $resolved = realpath($candidate);

            return false !== $resolved ? $resolved : $candidate;
        }
    }

    return null;
}
