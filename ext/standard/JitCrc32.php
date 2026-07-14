<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\Crc32Runtime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM JIT helper for crc32() — Crc32JitHelper PHP bridge (#5389, #15759). */
final class JitCrc32
{
    public static function compute(Context $context, Value $subject, Value $seed): Value
    {
        $checksum = Crc32Runtime::invokeCrc32($context, $subject, $seed);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $checksum);

        return $context->builder->load($slot);
    }

    /** Lower crc32() subject with Z_PARAM_STR parity (#4594, #5780, #16115, ext/standard/string.c). */
    public static function lowerStringSubject(Context $context, JITVariable $arg): Value
    {
        return JitStringBuiltinArg::lowerZparamStr($context, $arg, 'crc32', 0, 'string');
    }
}
