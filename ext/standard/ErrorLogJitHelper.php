<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * error_log() stderr branch for compiled JIT/AOT modules (#9253, php-in-PHP).
 *
 * SSOT: {@see VmErrorLog::errorLog()}
 * php-src: ext/standard/basic_functions.c — _php_error_log default branch
 */
final class ErrorLogJitHelper
{
    /** @return bool LLVM i1 ABI; bridge zext for __compiler_error_log callers */
    public static function logStderr(string $message): bool
    {
        return VmErrorLog::errorLog(0, $message);
    }
}
