<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM JIT helper for crc32() — delegates to __compiler_crc32. */
final class JitCrc32
{
    public static function compute(Context $context, Value $subject, Value $seed): Value
    {
        $checksum = $context->builder->call(
            $context->lookupFunction('__compiler_crc32'),
            $subject,
            $seed
        );
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $checksum);

        return $context->builder->load($slot);
    }
}
