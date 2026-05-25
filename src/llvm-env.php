<?php

declare(strict_types=1);

/**
 * Point PHPLLVM at a project-local libLLVM-9 when the system only ships newer LLVM.
 *
 * putenv('LD_LIBRARY_PATH') does not affect in-process dlopen on glibc; preload bundled
 * dependencies with dlopen(RTLD_GLOBAL) before libLLVM is opened via FFI.
 */
$repoRoot = dirname(__DIR__);
$llvmDir = '';
if (is_file('/opt/llvm9/libLLVM-9.so.1') && (is_file('/.dockerenv') || '1' === getenv('PHP_COMPILER_PREFER_IMAGE_LLVM'))) {
    $llvmDir = '/opt/llvm9';
} elseif (getenv('PHP_COMPILER_LLVM_PATH') && is_file(getenv('PHP_COMPILER_LLVM_PATH').'/libLLVM-9.so.1')) {
    $llvmDir = getenv('PHP_COMPILER_LLVM_PATH');
} elseif (is_file($repoRoot.'/.llvm/libLLVM-9.so.1')) {
    $llvmDir = $repoRoot.'/.llvm';
} elseif (is_file('/opt/llvm9/libLLVM-9.so.1')) {
    $llvmDir = '/opt/llvm9';
}

/** @var \FFI|null Cached dlopen FFI handle for php_compiler_preload_llvm_deps(). */
$php_compiler_llvm_dl = null;

/**
 * @param list<string> $names Basenames under $dir (e.g. libffi.so.7).
 */
function php_compiler_preload_llvm_deps(string $dir, array $names): void
{
    if (!extension_loaded('ffi')) {
        return;
    }
    global $php_compiler_llvm_dl;
    if (null === $php_compiler_llvm_dl) {
        foreach (['libdl.so.2', 'libc.so.6'] as $lib) {
            try {
                $php_compiler_llvm_dl = \FFI::cdef(
                    'void *dlopen(const char *filename, int flags);',
                    $lib
                );
                break;
            } catch (\FFI\Exception $e) {
                continue;
            }
        }
        if (null === $php_compiler_llvm_dl) {
            return;
        }
    }
    // RTLD_NOW | RTLD_GLOBAL — expose symbols to subsequently loaded libs.
    $flags = 258;
    foreach ($names as $name) {
        $path = $dir . '/' . $name;
        if (!is_file($path)) {
            continue;
        }
        $resolved = realpath($path);
        if (false === $resolved) {
            continue;
        }
        $php_compiler_llvm_dl->dlopen($resolved, $flags);
    }
}

if ('' !== $llvmDir && is_file($llvmDir . '/libLLVM-9.so.1')) {
    putenv('PHP_COMPILER_LLVM_PATH=' . $llvmDir);
    $ldPath = getenv('LD_LIBRARY_PATH');
    putenv('LD_LIBRARY_PATH=' . $llvmDir . (false === $ldPath || '' === $ldPath ? '' : ':' . $ldPath));
    $_ENV['LD_LIBRARY_PATH'] = getenv('LD_LIBRARY_PATH');
    $_SERVER['LD_LIBRARY_PATH'] = $_ENV['LD_LIBRARY_PATH'];
    // PHPUnit spawns bin/jit.php children; RTLD_GLOBAL preload here segfaults MCJIT (#98, #2055).
    if ('1' !== getenv('PHP_COMPILER_SKIP_LLVM_PRELOAD')) {
        php_compiler_preload_llvm_deps($llvmDir, ['libffi.so.7']);
    }
}
if ('' !== $llvmDir && is_executable($llvmDir . '/clang-9')) {
    $path = getenv('PATH');
    putenv('PATH=' . $llvmDir . (false === $path ? '' : ':' . $path));
    $_ENV['PATH'] = getenv('PATH');
    $_SERVER['PATH'] = $_ENV['PATH'];
}
