<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM JIT helper for stripslashes() — delegates to __string__stripslashes. */
final class JitStripslashes
{
    public static function unescape(Context $context, Value $subject): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__string__stripslashes'),
            $subject
        );
    }
}
