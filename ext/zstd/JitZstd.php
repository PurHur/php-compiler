<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringZstd;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM helpers for zstd_*() — ZstdJitHelper in-module (#6387, #8564, #8869). */
final class JitZstd
{
    private static int $blockSerial = 0;

    public static function compress(Context $context, Value $data, Value $level): Value
    {
        StringZstd::ensureLinked($context);

        return self::stringOrFalse($context, $context->builder->call(
            StringZstd::compressHelper($context),
            $data,
            $level
        ));
    }

    public static function decompress(Context $context, Value $data): Value
    {
        StringZstd::ensureLinked($context);

        return self::stringOrFalse($context, $context->builder->call(
            StringZstd::decompressHelper($context),
            $data
        ));
    }

    public static function defaultLevel(Context $context): Value
    {
        return $context->getTypeFromString('int64')->constInt(3, false);
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
