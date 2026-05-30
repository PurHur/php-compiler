<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for boxed gettype() via __compiler_gettype (#3618). */
final class JitGettype
{
    public static function boxed(Context $context, JITVariable $arg): Value
    {
        $ptr = JitValueBox::normalizeValuePtr(
            $context,
            JitValueBox::valuePtrFromVariable($context, $arg)
        );

        return $context->builder->call(
            $context->lookupFunction('__compiler_gettype'),
            $ptr
        );
    }
}
