#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Exit 0 when bin/jit.php can compile and execute trivial code (MCJIT smoke).
 * Used by script/ci-local.sh to skip @group jit when LLVM JIT crashes in the container.
 *
 * Spawns bin/jit.php via bash + script/php-env.sh so this process never preloads libLLVM
 * (in-process dlopen before a jit.php child segfaults — issue #98).
 */

$root = dirname(__DIR__);
$llvmDir = resolve_probe_llvm_dir($root);
if (null === $llvmDir) {
    fwrite(STDERR, "jit-runtime-probe: LLVM 9 not found\n");
    exit(2);
}

$probeDir = $root.'/var';
if (!is_dir($probeDir) && !mkdir($probeDir, 0775, true) && !is_dir($probeDir)) {
    fwrite(STDERR, "jit-runtime-probe: could not create {$probeDir}\n");
    exit(2);
}
$script = $probeDir.'/jit-runtime-probe-'.getmypid().'.php';
file_put_contents($script, "<?php echo 2 + 2;\n");

$fileProbe = run_jit_probe_bash(
    $root,
    $llvmDir,
    sprintf(
        'OUT=$("$PHP_BIN" "${PHP_OPTS[@]}" "$ROOT/bin/jit.php" %s 2>&1) || { echo "$OUT" >&2; exit 1; }',
        escapeshellarg($script)
    )
);
@unlink($script);

if (0 !== $fileProbe['exit']) {
    if ('' !== $fileProbe['stderr']) {
        fwrite(STDERR, $fileProbe['stderr']);
    }
    fwrite(STDERR, "jit-runtime-probe: bin/jit.php file execute exited {$fileProbe['exit']}\n");
    exit(1);
}
if (!str_contains($fileProbe['stdout'], '4')) {
    fwrite(STDERR, "jit-runtime-probe: unexpected file output: ".trim($fileProbe['stdout'])."\n");
    exit(1);
}

$inlineProbe = run_jit_probe_bash(
    $root,
    $llvmDir,
    'OUT=$("$PHP_BIN" "${PHP_OPTS[@]}" "$ROOT/bin/jit.php" -r "echo 1;" 2>&1) || { echo "$OUT" >&2; exit 1; }'
);
if (0 !== $inlineProbe['exit']) {
    if ('' !== $inlineProbe['stderr']) {
        fwrite(STDERR, $inlineProbe['stderr']);
    }
    fwrite(STDERR, "jit-runtime-probe: bin/jit.php -r execute exited {$inlineProbe['exit']} (#98, #8721)\n");
    exit(1);
}
if (!str_contains($inlineProbe['stdout'], '1')) {
    fwrite(STDERR, "jit-runtime-probe: unexpected -r output: ".trim($inlineProbe['stdout'])."\n");
    exit(1);
}

fwrite(STDOUT, "jit-runtime-probe OK\n");
exit(0);

/**
 * @return array{exit: int, stdout: string, stderr: string}
 */
function run_jit_probe_bash(string $root, string $llvmDir, string $jitCommand): array
{
    $bash = <<<'BASH'
set -euo pipefail
ROOT=%s
# shellcheck source=php-env.sh
source "$ROOT/script/php-env.sh"
export PHP_COMPILER_LLVM_PATH=%s
export LD_LIBRARY_PATH="%s${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
unset PHP_COMPILER_SKIP_LLVM_PRELOAD
%s
echo "$OUT"
BASH;

    $command = sprintf(
        $bash,
        escapeshellarg($root),
        escapeshellarg($llvmDir),
        $llvmDir,
        $jitCommand
    );

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open(['bash', '-lc', $command], $descriptorSpec, $pipes, $root);
    if (!is_resource($proc)) {
        return ['exit' => 2, 'stdout' => '', 'stderr' => "jit-runtime-probe: could not start bash harness\n"];
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
 * @return non-empty-string|null
 */
function resolve_probe_llvm_dir(string $repoRoot): ?string
{
    if (is_file('/opt/llvm9/libLLVM-9.so.1')) {
        $resolved = realpath('/opt/llvm9');

        return false !== $resolved ? $resolved : '/opt/llvm9';
    }
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
