<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

use PHPCompiler\JIT\Builtin\ConvertUuRuntime;

/** LLVM JIT/AOT helper for convert_uuencode() — ConvertUuRuntime bitcode, no AOT phpc_uuencode.c (#5277). */
final class JitConvertUuencode
{
    public static function encode(Context $context, Value $src): Value
    {
        ConvertUuRuntime::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_convert_uuencode'),
            $src
        );
    }
}
