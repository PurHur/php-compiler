#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Fail CI when LLVM is present but JIT compliance (JITTest) ran zero tests.
 *
 * @see https://github.com/PurHur/php-compiler/issues/250
 * @see https://github.com/PurHur/php-compiler/issues/98
 * @see https://github.com/PurHur/php-compiler/issues/717
 * @see https://github.com/PurHur/php-compiler/issues/728
 */
if ($argc >= 2 && ('--probe' === $argv[1] || '--preflight' === $argv[1])) {
    $repoRoot = isset($argv[2]) && '' !== $argv[2] ? $argv[2] : dirname(__DIR__);
    exit(probeJitReadiness($repoRoot));
}

if ($argc < 2) {
    fwrite(STDERR, "Usage: {$argv[0]} <junit-xml> [llvm-dir]\n");
    fwrite(STDERR, "       {$argv[0]} --probe|--preflight [repo-root]\n");
    exit(2);
}

$junitPath = $argv[1];
$llvmDir = $argv[2] ?? getenv('PHP_COMPILER_LLVM_PATH') ?: '';

if (!is_file($junitPath)) {
    fwrite(STDERR, "JIT compliance guard: JUnit log not found: {$junitPath}\n");
    exit(1);
}

$xml = @simplexml_load_file($junitPath);
if (false === $xml) {
    fwrite(STDERR, "JIT compliance guard: could not parse JUnit XML: {$junitPath}\n");
    exit(1);
}

$total = 0;
$skipped = 0;
$executed = 0;

/** @var \SimpleXMLElement $testcase */
foreach ($xml->xpath('//testcase') ?: [] as $testcase) {
    $classname = (string) ($testcase['classname'] ?? '');
    $file = (string) ($testcase['file'] ?? '');
    if (!str_contains($classname, 'JITTest') && !str_contains($file, 'JITTest.php')) {
        continue;
    }
    ++$total;
    if (isset($testcase->skipped)) {
        ++$skipped;
    } else {
        ++$executed;
    }
}

if (0 === $total) {
    fwrite(STDERR, "JIT compliance guard: no JITTest cases in {$junitPath}\n");
    fwrite(STDERR, "  Ensure @group llvm includes test/compliance/JITTest.php.\n");
    exit(1);
}

if (0 === $executed) {
    fwrite(STDERR, "JIT compliance guard FAILED: LLVM is present but all {$total} JIT tests were skipped.\n");
    if ('' !== $llvmDir) {
        fwrite(STDERR, "  LLVM dir: {$llvmDir}\n");
    }
    fwrite(STDERR, "  Fix: export PHP_COMPILER_LLVM_PATH to a tree containing libLLVM-9.so.1\n");
    fwrite(STDERR, "       and prepend that directory to LD_LIBRARY_PATH and PATH.\n");
    fwrite(STDERR, "  Docker: use /opt/llvm9 (image #237) — avoid a broken host .llvm/ bind-mount override.\n");
    fwrite(STDERR, "  Preflight: phpc doctor --jit-probe  or  {$argv[0]} --preflight\n");
    fwrite(STDERR, "  Override (broken dev env only): PHP_COMPILER_ALLOW_JIT_SKIP=1\n");
    exit(1);
}

fwrite(STDOUT, "JIT compliance guard OK: {$executed} of {$total} JIT tests executed ({$skipped} skipped).\n");
exit(0);

/**
 * Exit 0 when LLVM is missing (nothing to guard). Exit 1 when LLVM is present but JIT would not run.
 */
function probeJitReadiness(string $repoRoot): int
{
    $llvmDir = resolveLlvmDir($repoRoot);
    if (null === $llvmDir) {
        fwrite(STDOUT, "JIT probe: LLVM 9 not found — ci-local JIT guard not applicable\n");
        return 0;
    }

    $autoload = $repoRoot.'/vendor/autoload.php';
    if (!is_file($autoload)) {
        fwrite(STDERR, "JIT probe: run composer install first\n");
        return 2;
    }

    $probeScript = $repoRoot.'/script/jit-runtime-probe.php';
    if (!is_file($probeScript)) {
        fwrite(STDERR, "JIT probe: {$probeScript} missing\n");
        return 2;
    }

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open(
        ['bash', '-lc', 'cd '.escapeshellarg($repoRoot).' && source script/php-env.sh && "$PHP_BIN" "${PHP_OPTS[@]}" script/jit-runtime-probe.php'],
        $descriptorSpec,
        $pipes,
        $repoRoot
    );
    if (!is_resource($proc)) {
        fwrite(STDERR, "JIT probe: could not start jit-runtime-probe.php\n");
        return 2;
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    if (false !== $stdout && '' !== $stdout) {
        fwrite(STDOUT, $stdout);
    }
    if (false !== $stderr && '' !== $stderr) {
        fwrite(STDERR, $stderr);
    }
    if (0 !== $exit) {
        fwrite(STDERR, "JIT probe FAILED: MCJIT runtime probe failed (#98)\n");
        return 1;
    }

    require $autoload;
    require_once $repoRoot.'/test/LlvmToolchain.php';

    if (!\PHPCompiler\LlvmToolchain::isReady($repoRoot)) {
        $reason = \PHPCompiler\LlvmToolchain::readyFailureReason();
        fwrite(STDERR, "JIT probe FAILED: libLLVM at {$llvmDir} but PHPLLVM bootstrap failed (#98)\n");
        if (null !== $reason && '' !== $reason) {
            fwrite(STDERR, "  {$reason}\n");
        }
        fwrite(STDERR, "  Set PHP_COMPILER_LLVM_PATH and prepend LLVM dir to LD_LIBRARY_PATH and PATH.\n");
        return 1;
    }

    fwrite(STDOUT, "JIT probe OK: LLVM + PHPLLVM + MCJIT ready for ci-local JITTest\n");
    return 0;
}

/**
 * @return non-empty-string|null
 */
function resolveLlvmDir(string $repoRoot): ?string
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

function applyLlvmEnv(string $llvmDir): void
{
    putenv('PHP_COMPILER_LLVM_PATH='.$llvmDir);
    $_ENV['PHP_COMPILER_LLVM_PATH'] = $llvmDir;
    $_SERVER['PHP_COMPILER_LLVM_PATH'] = $llvmDir;
    $ld = getenv('LD_LIBRARY_PATH');
    $ldVal = false === $ld || '' === $ld ? $llvmDir : $llvmDir.':'.$ld;
    putenv('LD_LIBRARY_PATH='.$ldVal);
    $path = getenv('PATH');
    $pathVal = false === $path || '' === $path ? $llvmDir : $llvmDir.':'.$path;
    putenv('PATH='.$pathVal);
}

/**
 * @param array<string, string> $env
 */
function applyLlvmEnvToArray(string $llvmDir, array &$env): void
{
    $env['PHP_COMPILER_LLVM_PATH'] = $llvmDir;
    $ld = $env['LD_LIBRARY_PATH'] ?? getenv('LD_LIBRARY_PATH') ?: '';
    $env['LD_LIBRARY_PATH'] = '' === $ld ? $llvmDir : $llvmDir.':'.$ld;
    $path = $env['PATH'] ?? getenv('PATH') ?: '';
    $env['PATH'] = '' === $path ? $llvmDir : $llvmDir.':'.$path;
}

function probePhpllvmChooser(): bool
{
    if (!class_exists(\PHPLLVM\Chooser::class)) {
        return false;
    }
    try {
        \PHPLLVM\Chooser::choose();

        return true;
    } catch (\Throwable $e) {
        return false;
    }
}
