<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM JIT helper for addcslashes() — delegates to __compiler_addcslashes. */
final class JitAddcslashes
{
    public static function escape(Context $context, Value $subject, Value $charlist): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__compiler_addcslashes'),
            $subject,
            $charlist
        );
    }
}
