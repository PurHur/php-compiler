<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM JIT helper for substr_replace() — delegates to __compiler_substr_replace. */
final class JitSubstrReplace
{
    public static function replace(
        Context $context,
        Value $string,
        Value $replace,
        Value $offset,
        Value $length,
        Value $hasLength
    ): Value {
        return $context->builder->call(
            $context->lookupFunction('__compiler_substr_replace'),
            $string,
            $replace,
            $offset,
            $length,
            $hasLength
        );
    }
}
