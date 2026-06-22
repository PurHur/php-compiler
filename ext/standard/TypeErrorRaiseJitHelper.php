<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * JIT/AOT pending TypeError / ArgumentCountError / ValueError buffer (#9778, php-in-PHP).
 *
 * Replaces LLVM globals phpc_jit_type_error_pending_* in {@see \PHPCompiler\JIT\Builtin\TypeErrorRaise}.
 * php-src: Zend/zend_exceptions.c — zend_throw_error, zend_type_error
 */
final class TypeErrorRaiseJitHelper
{
    public const KIND_TYPE_ERROR = 1;

    public const KIND_ARGUMENT_COUNT_ERROR = 2;

    public const KIND_VALUE_ERROR = 3;

    private static bool $pending = false;

    private static string $message = '';

    private static int $kind = 0;

    public static function raiseTypeError(string $message): void
    {
        self::raiseWithKind($message, self::KIND_TYPE_ERROR);
    }

    public static function raiseArgumentCountError(string $message): void
    {
        self::raiseWithKind($message, self::KIND_ARGUMENT_COUNT_ERROR);
    }

    public static function raiseValueError(string $message): void
    {
        self::raiseWithKind($message, self::KIND_VALUE_ERROR);
    }

    public static function clearPending(): void
    {
        self::$pending = false;
        self::$message = '';
        self::$kind = 0;
    }

    /** LLVM i1 ABI */
    public static function hasPending(): bool
    {
        return self::$pending;
    }

    /** LLVM i32 ABI for phpc_jit_type_error_pending_kind_get */
    public static function pendingKind(): int
    {
        return self::$kind;
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

    private static function raiseWithKind(string $message, int $kind): void
    {
        self::$message = $message;
        self::$kind = $kind;
        self::$pending = true;
    }
}
