<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for ob_flush() (issue #3588). */
final class JitObFlush
{
    /** @return Value */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            ExceptionBridge::emitArgumentCountError(
                $context,
                \sprintf('ob_flush() expects exactly 0 arguments, %d given', \count($args))
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_flush'),
            $ptr
        );

        return $ptr;
    }
}
