<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for str_shuffle() via __compiler_str_shuffle (Fisher–Yates + getrandom). */
final class JitStrShuffle
{
    public static function invoke(Context $context, Value $input): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__compiler_str_shuffle'),
            $input
        );
    }
}
