<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

use PHPCompiler\JIT\Builtin\StringConvertUu;

/** LLVM JIT/AOT helper for convert_uuencode() — ConvertUuJitHelper+VmConvertUu (#30811). */
final class JitConvertUuencode
{
    public static function encode(Context $context, Value $src): Value
    {
        StringConvertUu::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_convert_uuencode'),
            $src
        );
    }
}
