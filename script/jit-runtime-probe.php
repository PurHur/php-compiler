#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Exit 0 when bin/jit.php can compile trivial code (MCJIT smoke).
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

$code = '<?php echo 2 + 2;'."\n";
$bash = <<<'BASH'
set -euo pipefail
ROOT=%s
CODE=%s
# shellcheck source=php-env.sh
source "$ROOT/script/php-env.sh"
export PHP_COMPILER_LLVM_PATH=%s
export LD_LIBRARY_PATH="%s${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
printf '%%s' "$CODE" | "$PHP_BIN" "${PHP_OPTS[@]}" "$ROOT/bin/jit.php" -l
BASH;

$command = sprintf(
    $bash,
    escapeshellarg($root),
    escapeshellarg($code),
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
    fwrite(STDERR, "jit-runtime-probe: could not start bash harness\n");
    exit(2);
}
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($proc);

if (false !== $stderr && '' !== $stderr) {
    fwrite(STDERR, $stderr);
}
if (0 !== $exit) {
    fwrite(STDERR, "jit-runtime-probe: jit.php -l exited {$exit}\n");
    exit(1);
}

fwrite(STDOUT, "jit-runtime-probe OK\n");
exit(0);

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
