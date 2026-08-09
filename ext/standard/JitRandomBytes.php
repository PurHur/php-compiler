<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;

/**
 * JIT/AOT helper for random_bytes() via RandomBytesJitHelper PHP (#21186, #29531).
 *
 * NestedJIT of the helper uses {@see JitRandomBytesKernel} directly so compiling
 * `@random_bytes` does not re-enter `__compiler_random_bytes` (gethostname #29364 shape).
 */
final class JitRandomBytes
{
    public static function generate(Context $context, Value $lengthI64): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return JitRandomBytesKernel::invoke($context, $lengthI64);
        }

        return $context->builder->call(
            $context->lookupFunction('__compiler_random_bytes'),
            $lengthI64
        );
    }
}
