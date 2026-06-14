<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringZstd;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM helpers for zstd_compress()/zstd_decompress() — libzstd via StringZstdJit (#6387, #8564). */
final class JitZstd
{
    private static int $blockSerial = 0;

    public static function compress(Context $context, Value $source, Value $level): Value
    {
        StringZstd::ensureLinked($context);

        return self::stringOrFalse($context, $context->builder->call(
            $context->lookupFunction('__compiler_zstd_compress'),
            $source,
            $level
        ));
    }

    public static function decompress(Context $context, Value $source): Value
    {
        StringZstd::ensureLinked($context);

        return self::stringOrFalse($context, $context->builder->call(
            $context->lookupFunction('__compiler_zstd_decompress'),
            $source
        ));
    }

    private static function stringOrFalse(Context $context, Value $result): Value
    {
        $id = (string) (++self::$blockSerial);
        $strPtr = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $result, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'zstd_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'zstd_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'zstd_done_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $result
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
