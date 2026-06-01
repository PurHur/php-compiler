<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM JIT/AOT helper for utf8_encode()/utf8_decode() — delegates to runtime C. */
final class JitUtf8Latin1
{
    public static function encode(Context $context, Value $src): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__compiler_utf8_encode'),
            $src
        );
    }

    public static function decode(Context $context, Value $src): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__compiler_utf8_decode'),
            $src
        );
    }
}
