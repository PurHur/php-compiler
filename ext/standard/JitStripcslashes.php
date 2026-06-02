<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM JIT helper for stripcslashes() — delegates to __compiler_stripcslashes. */
final class JitStripcslashes
{
    public static function unescape(Context $context, Value $subject): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__compiler_stripcslashes'),
            $subject
        );
    }
}
