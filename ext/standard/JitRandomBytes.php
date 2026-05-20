<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

final class JitRandomBytes
{
    public static function generate(Context $context, Value $lengthI64): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__compiler_random_bytes'),
            $lengthI64
        );
    }
}
