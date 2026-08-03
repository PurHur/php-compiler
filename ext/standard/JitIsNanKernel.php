<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for is_nan — fcmp uno (#27021).
 * Avoids NestedJIT re-entry via \is_nan and unbound libc isnan.
 * php-src: ext/standard/math.c — PHP_FUNCTION(is_nan)
 */
final class JitIsNanKernel
{
    /** @return Value int1 */
    public static function invoke(Context $context, Value $num): Value
    {
        return $context->builder->fcmp(Builder::REAL_UNO, $num, $num);
    }
}
