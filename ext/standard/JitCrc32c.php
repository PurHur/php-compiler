<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM JIT helper for crc32c() — table-driven CRC32C from ext/standard/VmCrc32c.php (#5389). */
final class JitCrc32c
{
    public static function compute(Context $context, Value $subject): Value
    {
        $checksum = JitCrcCore::computeCrc32c($context, $subject);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $checksum);

        return $context->builder->load($slot);
    }
}
