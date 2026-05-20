#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Exit 0 when bin/jit.php can compile and execute trivial code (MCJIT smoke).
 * Used by script/ci-local.sh to skip @group jit when LLVM JIT crashes in the container.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$repoRoot = $root;
$llvmDir = getenv('PHP_COMPILER_LLVM_PATH') ?: '';
if ('' === $llvmDir || !is_file($llvmDir . '/libLLVM-9.so.1')) {
    foreach ([$repoRoot . '/.llvm', '/opt/llvm9'] as $candidate) {
        if (is_file($candidate . '/libLLVM-9.so.1')) {
            $llvmDir = realpath($candidate) ?: $candidate;
            break;
        }
    }
}
if ('' === $llvmDir || !is_file($llvmDir . '/libLLVM-9.so.1')) {
    fwrite(STDERR, "jit-runtime-probe: LLVM 9 not found\n");
    exit(2);
}

putenv('PHP_COMPILER_LLVM_PATH=' . $llvmDir);
$_ENV['PHP_COMPILER_LLVM_PATH'] = $llvmDir;
$_SERVER['PHP_COMPILER_LLVM_PATH'] = $llvmDir;
$ld = getenv('LD_LIBRARY_PATH');
$ldVal = false === $ld || '' === $ld ? $llvmDir : $llvmDir . ':' . $ld;
putenv('LD_LIBRARY_PATH=' . $ldVal);
$path = getenv('PATH');
$pathVal = false === $path || '' === $path ? $llvmDir : $llvmDir . ':' . $path;
putenv('PATH=' . $pathVal);

$jitBin = $root . '/bin/jit.php';
$code = "<?php echo 2 + 2;\n";
$descriptorSpec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$proc = proc_open(
    array_merge(
        ['env', 'LD_LIBRARY_PATH=' . $ldVal, 'PATH=' . $pathVal, 'PHP_COMPILER_LLVM_PATH=' . $llvmDir],
        [PHP_BINARY, $jitBin]
    ),
    $descriptorSpec,
    $pipes,
    $repoRoot
);
if (!is_resource($proc)) {
    fwrite(STDERR, "jit-runtime-probe: could not start jit.php\n");
    exit(2);
}
fwrite($pipes[0], $code);
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
fclose($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[2]);
$exit = proc_close($proc);

if (0 !== $exit) {
    fwrite(STDERR, "jit-runtime-probe: jit.php exited {$exit}\n");
    if (is_string($stderr) && '' !== $stderr) {
        fwrite(STDERR, rtrim($stderr) . "\n");
    }
    exit(1);
}

if ('4' !== trim((string) $stdout)) {
    fwrite(STDERR, "jit-runtime-probe: unexpected output: " . var_export($stdout, true) . "\n");
    exit(1);
}

fwrite(STDOUT, "jit-runtime-probe OK\n");
exit(0);
