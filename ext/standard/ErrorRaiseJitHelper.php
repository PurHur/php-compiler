<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * JIT/AOT pending Error buffer for catchable Error paths (#9778, php-in-PHP).
 *
 * Replaces LLVM globals phpc_jit_error_pending_* in {@see \PHPCompiler\JIT\Builtin\ErrorRaise}.
 * php-src: Zend/zend_exceptions.c — zend_throw_error
 */
final class ErrorRaiseJitHelper
{
    private static bool $pending = false;

    private static string $message = '';

    public static function raise(string $message): void
    {
        self::$message = $message;
        self::$pending = true;
    }

    public static function clearPending(): void
    {
        self::$pending = false;
        self::$message = '';
    }

    /** LLVM i1 ABI — bridge zext to i32 for phpc_jit_error_has_pending */
    public static function hasPending(): bool
    {
        return self::$pending;
    }

    /** @return string pending message; clears pending state */
    public static function takePending(): string
    {
        if (!self::$pending) {
            return '';
        }
        $msg = self::$message;
        self::clearPending();

        return $msg;
    }
}
