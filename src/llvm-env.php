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
    $_ENV['LD_LIBRARY_PATH'] = getenv('LD_LIBRARY_PATH');
    $_SERVER['LD_LIBRARY_PATH'] = $_ENV['LD_LIBRARY_PATH'];
}
if (is_executable($llvmDir . '/clang-9')) {
    $path = getenv('PATH');
    putenv('PATH=' . $llvmDir . (false === $path ? '' : ':' . $path));
    $_ENV['PATH'] = getenv('PATH');
    $_SERVER['PATH'] = $_ENV['PATH'];
}
