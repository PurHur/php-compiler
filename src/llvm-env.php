<?php

declare(strict_types=1);

/**
 * Point PHPLLVM at a project-local libLLVM-9 when the system only ships newer LLVM.
 *
 * putenv('LD_LIBRARY_PATH') does not affect in-process dlopen on glibc; preload bundled
 * dependencies with dlopen(RTLD_GLOBAL) before libLLVM is opened via FFI.
 */
$llvmDir = dirname(__DIR__) . '/.llvm';

/**
 * @param list<string> $names Basenames under $dir (e.g. libffi.so.7).
 */
function php_compiler_preload_llvm_deps(string $dir, array $names): void
{
    if (!extension_loaded('ffi')) {
        return;
    }
    static $dl = null;
    if (null === $dl) {
        $dl = \FFI::cdef(
            'void *dlopen(const char *filename, int flags);',
            'libc.so.6'
        );
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
        $dl->dlopen($resolved, $flags);
    }
}

if (is_file($llvmDir . '/libLLVM-9.so.1')) {
    putenv('PHP_COMPILER_LLVM_PATH=' . $llvmDir);
    $ldPath = getenv('LD_LIBRARY_PATH');
    putenv('LD_LIBRARY_PATH=' . $llvmDir . (false === $ldPath || '' === $ldPath ? '' : ':' . $ldPath));
    $_ENV['LD_LIBRARY_PATH'] = getenv('LD_LIBRARY_PATH');
    $_SERVER['LD_LIBRARY_PATH'] = $_ENV['LD_LIBRARY_PATH'];
    php_compiler_preload_llvm_deps($llvmDir, ['libffi.so.7']);
}
if (is_executable($llvmDir . '/clang-9')) {
    $path = getenv('PATH');
    putenv('PATH=' . $llvmDir . (false === $path ? '' : ':' . $path));
    $_ENV['PATH'] = getenv('PATH');
    $_SERVER['PATH'] = $_ENV['PATH'];
}
