<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for crc32() via __compiler_crc32 (CRC32B runtime). */
final class JitCrc32
{
    public static function invoke(Context $context, Value $strPtr, Value $seedLong): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__compiler_crc32'),
            $strPtr,
            $seedLong
        );
    }
}
