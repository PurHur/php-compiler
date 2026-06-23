<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Execution limit static storage for compiled JIT/AOT modules (#9339, php-in-PHP).
 *
 * VM SSOT delegates to {@see VmExecutionLimits} on {@see \PHPCompiler\VM\Context}.
 * php-src: ext/standard/basic_functions.c — set_time_limit, ignore_user_abort, connection_aborted
 */
final class ExecutionLimitsJitHelper
{
    private const DEFAULT_MAX_SECONDS = 30;

    private static float $deadline = 0.0;

    private static int $limitSeconds = self::DEFAULT_MAX_SECONDS;

    private static int $ignoreUserAbort = 0;

    /** @return bool LLVM i1 ABI for phpc_set_time_limit */
    public static function setTimeLimit(int $seconds): bool
    {
        self::$limitSeconds = $seconds;
        if (0 === $seconds) {
            self::$deadline = 0.0;
        } else {
            self::$deadline = microtime(true) + (float) $seconds;
        }

        return true;
    }

    /** @return int LLVM i32 ABI for phpc_ignore_user_abort */
    public static function ignoreUserAbort(int $apply, int $value): int
    {
        $previous = self::$ignoreUserAbort;
        if (0 !== $apply) {
            self::$ignoreUserAbort = 0 !== $value ? 1 : 0;
        }

        return $previous;
    }

    /** @return int LLVM i32 ABI for phpc_connection_aborted */
    public static function connectionAborted(): int
    {
        return 0;
    }
}
