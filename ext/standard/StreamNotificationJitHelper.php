<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * stream_notification_callback() storage for VM + compiled JIT/AOT (#9478, php-in-PHP).
 *
 * SSOT for global notifier slot; VM {@see VmStreamNotification} delegates here.
 * php-src: ext/standard/streams.c — PHP_FUNCTION(stream_notification_callback)
 */
final class StreamNotificationJitHelper
{
    private static ?Variable $globalCallback = null;

    public static function setGlobal(Variable $callback): Variable
    {
        $previous = self::$globalCallback;
        $resolved = $callback->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            self::$globalCallback = null;
        } else {
            self::$globalCallback = self::normalizeCallbackForStorage($callback);
        }

        return self::callbackReturnValue($previous);
    }

    public static function globalCallback(): ?Variable
    {
        return self::$globalCallback;
    }

    /** JIT/AOT: address of the embedded {@see Variable} slot for LLVM exchange. */
    public static function jitCallbackSlot(): Variable
    {
        if (null === self::$globalCallback) {
            $slot = new Variable();
            $slot->null();
            self::$globalCallback = $slot;
        }

        return self::$globalCallback;
    }

    private static function normalizeCallbackForStorage(Variable $callback): Variable
    {
        VmStreamNotification::requireValidCallback($callback);
        $resolved = $callback->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            $out = new Variable();
            $out->null();

            return $out;
        }
        $out = new Variable();
        $out->copyFrom($resolved);

        return $out;
    }

    private static function callbackReturnValue(?Variable $previous): Variable
    {
        $out = new Variable();
        if (null === $previous) {
            $out->null();

            return $out;
        }
        $out->copyFrom($previous);

        return $out;
    }
}
