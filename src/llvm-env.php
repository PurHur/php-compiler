<?php

declare(strict_types=1);

/**
 * Point PHPLLVM at a project-local libLLVM-9 when the system only ships newer LLVM.
 */
$llvmDir = dirname(__DIR__) . '/.llvm';
if (is_file($llvmDir . '/libLLVM-9.so.1')) {
    putenv('PHP_COMPILER_LLVM_PATH=' . $llvmDir);
    $ldPath = getenv('LD_LIBRARY_PATH');
    putenv('LD_LIBRARY_PATH=' . $llvmDir . (false === $ldPath || '' === $ldPath ? '' : ':' . $ldPath));
}
if (is_executable($llvmDir . '/clang-9')) {
    $path = getenv('PATH');
    putenv('PATH=' . $llvmDir . (false === $path ? '' : ':' . $path));
}
