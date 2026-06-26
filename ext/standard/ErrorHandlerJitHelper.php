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

    public static function setApply(int $fnAddr, int $mask, ?string $handlerName): ?string
    {
        $previous = null;
        if (self::$depth > 0) {
            $previous = self::$topName;
        }
        if (self::$depth >= self::MAX) {
            return $previous;
        }
        if (1 === self::$depth) {
            self::$savedFnAddr = self::$topFnAddr;
            self::$savedMask = self::$topMask;
            self::$savedName = self::$topName;
        }
        self::$topFnAddr = $fnAddr;
        self::$topMask = $mask;
        self::$topName = $handlerName;
        ++self::$depth;

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
            self::$topName = self::$savedName;
            self::$savedFnAddr = 0;
            self::$savedMask = 0;
            self::$savedName = null;
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
}
