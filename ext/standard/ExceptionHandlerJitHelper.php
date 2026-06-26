<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Exception-handler stack for compiled JIT/AOT modules (#9473, php-in-PHP).
 *
 * Scalar slot stack for nested JIT compile compatibility (mirrors ErrorHandlerJitHelper).
 * VM SSOT remains {@see VmExceptionHandler}.
 * php-src: ext/standard/basic_functions.c — set_exception_handler
 */
final class ExceptionHandlerJitHelper
{
    private const MAX = 8;

    private static int $depth = 0;

    private static int $fn0 = 0;

    private static int $fn1 = 0;

    private static int $fn2 = 0;

    private static int $fn3 = 0;

    private static ?string $name0 = null;

    private static ?string $name1 = null;

    private static ?string $name2 = null;

    private static ?string $name3 = null;

    public static function currentDepth(): int
    {
        return self::$depth;
    }

    public static function handlerFnAddrAt(int $index): int
    {
        if ($index < 0 || $index >= self::$depth) {
            return 0;
        }

        return self::fnAt($index);
    }

    /** @return ?string previous handler name (null when none) */
    public static function setApply(int $fnAddr, ?string $handlerName): ?string
    {
        if (0 === $fnAddr) {
            return self::popReturningName();
        }

        $previous = self::$depth > 0 ? self::nameAt(self::$depth - 1) : null;
        if (self::$depth >= self::MAX) {
            return $previous;
        }
        self::setFnAt(self::$depth, $fnAddr);
        self::setNameAt(self::$depth, $handlerName);
        ++self::$depth;

        return $previous;
    }

    public static function restoreApply(): bool
    {
        if (self::$depth <= 0) {
            return true;
        }
        --self::$depth;
        self::setFnAt(self::$depth, 0);
        self::setNameAt(self::$depth, null);

        return true;
    }

    private static function popReturningName(): ?string
    {
        if (self::$depth <= 0) {
            return null;
        }
        $removed = self::nameAt(self::$depth - 1);
        self::setFnAt(self::$depth - 1, 0);
        self::setNameAt(self::$depth - 1, null);
        --self::$depth;

        return $removed;
    }

    private static function fnAt(int $index): int
    {
        switch ($index) {
            case 0: return self::$fn0;
            case 1: return self::$fn1;
            case 2: return self::$fn2;
            case 3: return self::$fn3;
            default: return 0;
        }
    }

    private static function nameAt(int $index): ?string
    {
        switch ($index) {
            case 0: return self::$name0;
            case 1: return self::$name1;
            case 2: return self::$name2;
            case 3: return self::$name3;
            default: return null;
        }
    }

    private static function setFnAt(int $index, int $fnAddr): void
    {
        switch ($index) {
            case 0: self::$fn0 = $fnAddr; break;
            case 1: self::$fn1 = $fnAddr; break;
            case 2: self::$fn2 = $fnAddr; break;
            case 3: self::$fn3 = $fnAddr; break;
        }
    }

    private static function setNameAt(int $index, ?string $name): void
    {
        switch ($index) {
            case 0: self::$name0 = $name; break;
            case 1: self::$name1 = $name; break;
            case 2: self::$name2 = $name; break;
            case 3: self::$name3 = $name; break;
        }
    }
}
