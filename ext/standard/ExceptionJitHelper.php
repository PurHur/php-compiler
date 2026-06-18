<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * JIT/AOT throw-pending + active-catch state for try/catch lowering (#9632, php-in-PHP).
 *
 * Replaces per-module LLVM globals in {@see \PHPCompiler\JIT\Builtin\JitThrow} on the JIT path.
 * php-src: Zend/zend_exceptions.c — pending exception dispatch
 */
final class ExceptionJitHelper
{
    private static bool $throwPending = false;

    private static int $throwObjectAddr = 0;

    private static int $activeCatchAddr = 0;

    public static function clearThrowPending(): void
    {
        self::$throwPending = false;
        self::$throwObjectAddr = 0;
    }

    /** LLVM i1 ABI — bridge zext to i32 for phpc_jit_has_throw_pending */
    public static function hasThrowPending(): bool
    {
        return self::$throwPending;
    }

    public static function setThrowPending(int $objectAddr): void
    {
        self::$throwPending = true;
        self::$throwObjectAddr = $objectAddr;
    }

    /** @return int object address, or 0 when no pending throw */
    public static function takeThrowPending(): int
    {
        if (!self::$throwPending) {
            return 0;
        }
        $addr = self::$throwObjectAddr;
        self::$throwPending = false;
        self::$throwObjectAddr = 0;

        return $addr;
    }

    public static function clearActiveCatch(): void
    {
        self::$activeCatchAddr = 0;
    }

    /** @return int active catch object address, or 0 */
    public static function getActiveCatch(): int
    {
        return self::$activeCatchAddr;
    }

    public static function setActiveCatch(int $objectAddr): void
    {
        self::$activeCatchAddr = $objectAddr;
    }
}
