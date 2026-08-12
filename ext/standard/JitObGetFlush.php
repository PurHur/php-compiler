<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for ob_get_flush() (issue #3753). */
final class JitObGetFlush
{
    /** @return Value */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 0) {
            throw new \ArgumentCountError(
                'ob_get_flush() expects exactly 0 arguments, '.$argc.' given'
            );
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_get_flush'),
            $ptr
        );

        return $ptr;
    }
}
