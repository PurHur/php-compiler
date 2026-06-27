<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for ob_end_clean() (issue #3236). */
final class JitObEndClean
{
    /** @return Value */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \ArgumentCountError(
                'ob_end_clean() expects exactly 0 arguments, '.\count($args).' given'
            );
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_end_clean'),
            $ptr
        );

        return $ptr;
    }
}
