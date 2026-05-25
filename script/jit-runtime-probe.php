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

$bash = <<<'BASH'
set -euo pipefail
ROOT=%s
SCRIPT=%s
# shellcheck source=php-env.sh
source "$ROOT/script/php-env.sh"
export PHP_COMPILER_LLVM_PATH=%s
export LD_LIBRARY_PATH="%s${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
unset PHP_COMPILER_SKIP_LLVM_PRELOAD
OUT=$("$PHP_BIN" "${PHP_OPTS[@]}" "$ROOT/bin/jit.php" "$SCRIPT" 2>&1) || {
  echo "$OUT" >&2
  exit 1
}
echo "$OUT"
BASH;

$command = sprintf(
    $bash,
    escapeshellarg($root),
    escapeshellarg($script),
    escapeshellarg($llvmDir),
    $llvmDir
);

$descriptorSpec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$proc = proc_open(['bash', '-lc', $command], $descriptorSpec, $pipes, $root);
if (!is_resource($proc)) {
    @unlink($script);
    fwrite(STDERR, "jit-runtime-probe: could not start bash harness\n");
    exit(2);
}
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($proc);
@unlink($script);

if (false !== $stderr && '' !== $stderr) {
    fwrite(STDERR, $stderr);
}
if (0 !== $exit) {
    fwrite(STDERR, "jit-runtime-probe: bin/jit.php execute exited {$exit}\n");
    exit(1);
}
if (!str_contains((string) $stdout, '4')) {
    fwrite(STDERR, "jit-runtime-probe: unexpected output: ".trim((string) $stdout)."\n");
    exit(1);
}

fwrite(STDOUT, "jit-runtime-probe OK\n");
exit(0);

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
