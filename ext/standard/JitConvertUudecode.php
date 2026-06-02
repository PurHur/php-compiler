<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM JIT/AOT helper for convert_uudecode() — delegates to __compiler_convert_uudecode. */
final class JitConvertUudecode
{
    public static function decode(Context $context, Value $src): Value
    {
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
