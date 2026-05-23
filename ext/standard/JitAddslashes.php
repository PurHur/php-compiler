<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM JIT helper for addslashes() — delegates to __string__addslashes. */
final class JitAddslashes
{
    public static function escape(Context $context, Value $subject): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__string__addslashes'),
            $subject
        );
    }
}
