<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM JIT helper for strtr() — delegates to __compiler_strtr. */
final class JitStrtr
{
    public static function translate(Context $context, Value $subject, Value $from, Value $to): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__compiler_strtr'),
            $subject,
            $from,
            $to
        );
    }
}
