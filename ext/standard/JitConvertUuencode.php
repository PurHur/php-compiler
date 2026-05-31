<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM JIT/AOT helper for convert_uuencode() — delegates to __compiler_convert_uuencode. */
final class JitConvertUuencode
{
    public static function encode(Context $context, Value $src): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__compiler_convert_uuencode'),
            $src
        );
    }
}
