<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * JIT/AOT return-through-finally pending state (#9663, php-in-PHP).
 *
 * Replaces LLVM globals in {@see \PHPCompiler\JIT\Builtin\JitReturnPending} on the JIT path.
 * php-src: Zend/zend_execute.c — ZEND_RETURN through finally unwinding
 */
final class ReturnPendingJitHelper
{
    private static bool $pending = false;

    private static bool $isVoid = false;

    private static int $valueAddr = 0;

    public static function clearReturnPending(): void
    {
        self::$pending = false;
        self::$isVoid = false;
        self::$valueAddr = 0;
    }

    /** LLVM i1 ABI — bridge zext to i32 for phpc_jit_has_return_pending */
    public static function hasReturnPending(): bool
    {
        return self::$pending;
    }

    /** LLVM i1 ABI — bridge zext to i32 for phpc_jit_return_pending_is_void */
    public static function returnPendingIsVoid(): bool
    {
        return self::$isVoid;
    }

    public static function setReturnPending(int $valueAddr, bool $isVoid): void
    {
        self::$pending = true;
        self::$isVoid = $isVoid;
        self::$valueAddr = $valueAddr;
    }

    /** @return int __value__* address, or 0 when no pending return */
    public static function takeReturnPending(): int
    {
        if (!self::$pending) {
            return 0;
        }
        $addr = self::$valueAddr;
        self::clearReturnPending();

        return $addr;
    }

    /** @internal test reset */
    public static function resetForTest(): void
    {
        self::clearReturnPending();
    }
}
