<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\Crc32Runtime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM JIT helper for crc32c() — Crc32JitHelper PHP bridge (#5389, #15759). */
final class JitCrc32c
{
    public static function compute(Context $context, Value $subject): Value
    {
        $checksum = Crc32Runtime::invokeCrc32c($context, $subject);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $checksum);

        return $context->builder->load($slot);
    }

    /** Lower crc32c() subject with Z_PARAM_STR parity (#19185, ext/standard/crc32c.c). */
    public static function lowerStringSubject(Context $context, JITVariable $arg): Value
    {
        return JitStringBuiltinArg::lowerTypedString($context, $arg, 'crc32c', 0, 'string');
    }
}
