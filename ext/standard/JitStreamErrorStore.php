<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StreamErrorStoreRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM lowering for stream_last_errors() / stream_clear_errors() (#21020). */
final class JitStreamErrorStore
{
    public static function lastErrors(Context $context): Value
    {
        StreamErrorStoreRuntime::ensureLinked($context);

        $ht = $context->builder->call(
            $context->lookupFunction(StreamErrorStoreRuntime::FN_LAST)
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }

    public static function clear(Context $context): Value
    {
        StreamErrorStoreRuntime::ensureLinked($context);
        $context->builder->call(
            $context->lookupFunction(StreamErrorStoreRuntime::FN_CLEAR)
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $ptr
        );

        return $ptr;
    }
}
