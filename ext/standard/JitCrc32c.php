<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM JIT helper for crc32c() — delegates to __compiler_crc32c. */
final class JitCrc32c
{
    public static function compute(Context $context, Value $subject): Value
    {
        $checksum = $context->builder->call(
            $context->lookupFunction('__compiler_crc32c'),
            $subject
        );
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $checksum);

        return $context->builder->load($slot);
    }
}
