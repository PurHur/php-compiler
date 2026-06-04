<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

use PHPCompiler\JIT\Builtin\ConvertUuRuntime;

/** LLVM JIT/AOT helper for convert_uudecode() — ConvertUuRuntime bitcode, no AOT phpc_uuencode.c (#5277). */
final class JitConvertUudecode
{
    public static function decode(Context $context, Value $src): Value
    {
        ConvertUuRuntime::ensureLinked($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_convert_uudecode'),
            $src,
            $ptr
        );

        return $ptr;
    }
}
