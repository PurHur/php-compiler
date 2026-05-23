<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM JIT helper for quotemeta() — delegates to __string__quotemeta. */
final class JitQuotemeta
{
    public static function quote(Context $context, Value $subject): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__string__quotemeta'),
            $subject
        );
    }
}
