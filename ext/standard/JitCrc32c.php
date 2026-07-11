<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\Crc32Runtime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
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
}
