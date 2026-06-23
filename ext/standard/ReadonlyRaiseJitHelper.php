<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Pending Error buffer for JIT readonly property write guards (#9522, php-in-PHP).
 *
 * VM raises via {@see \PHPCompiler\VM\ObjectReadonlySupport} / readonlyPropertyWriteErrorMessage;
 * compiled modules use this static store until throwPendingIfAny() drains it.
 *
 * php-src: Zend/zend_object_handlers.c — zend_readonly_property_modification_error
 */
final class ReadonlyRaiseJitHelper
{
    private const MAX_MESSAGE_BYTES = 511;

    private static bool $pending = false;

    private static string $message = '';

    public static function raise(string $message): void
    {
        if (\strlen($message) > self::MAX_MESSAGE_BYTES) {
            $message = \substr($message, 0, self::MAX_MESSAGE_BYTES);
        }
        self::$message = $message;
        self::$pending = true;
    }

    public static function clear(): void
    {
        self::$pending = false;
        self::$message = '';
    }

    /** @return bool LLVM i1 ABI; bridge zext to i32 for phpc_jit_has_pending_exception */
    public static function hasPending(): bool
    {
        return self::$pending;
    }

    public static function getMessage(): string
    {
        return self::$message;
    }

    /** Returns stored message and clears pending state (phpc_jit_copy_pending_exception). */
    public static function takeMessage(): string
    {
        $message = self::$message;
        self::clear();

        return $message;
    }
}
