<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Error-handler stack for compiled JIT/AOT modules (#9472, php-in-PHP).
 *
 * Scalar two-slot stack for nested JIT compile compatibility.
 * VM SSOT remains {@see VmErrorHandler}.
 * php-src: ext/standard/basic_functions.c
 */
final class ErrorHandlerJitHelper
{
    private const MAX = 8;

    private static int $depth = 0;

    private static int $topFnAddr = 0;

    private static int $topMask = 0;

    private static ?string $topName = null;

    private static int $savedFnAddr = 0;

    private static int $savedMask = 0;

    private static ?string $savedName = null;

    /** Previous handler name before push; call before {@see pushScalars()} (#17671). */
    public static function peekPreviousName(): ?string
    {
        if (self::$depth > 0) {
            return self::$topName;
        }

        return null;
    }

    /** Scalar stack push for AOT bridges (#17671). */
    public static function pushScalars(int $fnAddr, int $mask): bool
    {
        if (self::$depth >= self::MAX) {
            return true;
        }
        if (1 === self::$depth) {
            self::$savedFnAddr = self::$topFnAddr;
            self::$savedMask = self::$topMask;
        }
        self::$topFnAddr = $fnAddr;
        self::$topMask = $mask;
        ++self::$depth;

        return true;
    }

    /** Clear handler name after {@see pushScalars()} (#17671). */
    public static function clearTopName(): bool
    {
        self::$topName = null;

        return true;
    }

    /** Bind handler name after {@see pushScalars()} (#17671). */
    public static function bindTopNameString(string $handlerName): bool
    {
        self::$topName = $handlerName;

        return true;
    }

    public static function setApply(int $fnAddr, int $mask, ?string $handlerName): ?string
    {
        $previous = self::peekPreviousName();
        self::pushScalars($fnAddr, $mask);
        if (null === $handlerName) {
            self::clearTopName();
        } else {
            self::bindTopNameString($handlerName);
        }

        return $previous;
    }

    public static function restoreApply(): bool
    {
        if (self::$depth <= 0) {
            return true;
        }
        if (self::$depth > 1) {
            self::$topFnAddr = self::$savedFnAddr;
            self::$topMask = self::$savedMask;
            self::$savedFnAddr = 0;
            self::$savedMask = 0;
        } else {
            self::$topFnAddr = 0;
            self::$topMask = 0;
            self::$topName = null;
        }
        --self::$depth;

        return true;
    }

    public static function resolveHandlerAddr(int $errno): int
    {
        if (self::$depth <= 0) {
            return 0;
        }
        if (0 === self::$topFnAddr) {
            return 0;
        }
        if (0 === (self::$topMask & $errno)) {
            return 0;
        }

        return self::$topFnAddr;
    }

    /** Whether an error handler is active (AOT get bridge; #17671). */
    public static function hasActiveHandler(): bool
    {
        return self::$depth > 0;
    }

    /** Active handler name for get_error_handler() JIT/AOT (#17668). */
    public static function getTopName(): ?string
    {
        if (self::$depth <= 0) {
            return null;
        }

        return self::$topName;
    }

    /** Standalone AOT __init__: zero nested-compile static stack (#17671). */
    public static function resetStack(): bool
    {
        self::$depth = 0;
        self::$topFnAddr = 0;
        self::$topMask = 0;
        self::$topName = null;
        self::$savedFnAddr = 0;
        self::$savedMask = 0;
        self::$savedName = null;

        return true;
    }
}
