<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StreamNotificationRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for stream_notification_callback() (#6055). */
final class JitStreamNotificationCallback
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(
                'stream_notification_callback() expects exactly 1 argument, '.\count($args).' given'
            );
        }

        StreamNotificationRuntime::ensureLinked($context);

        $outSlot = JitValueBox::alloc($context);
        $outPtr = JitValueBox::pointer($context, $outSlot);
        $callbackPtr = JitValueBox::pointer($context, $args[0]);

        $context->builder->call(
            $context->lookupFunction('__phpc_stream_notification_callback_set'),
            $callbackPtr,
            $outPtr
        );

        return $outPtr;
    }
}
