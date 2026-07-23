<?php

declare(strict_types=1);

namespace PHPCompiler\ext\lz4;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringLz4;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM helpers for lz4_*() — Lz4JitHelper in-module (#22529). */
final class JitLz4
{
    private static int $blockSerial = 0;

    public static function compress(Context $context, Value $data, Value $level): Value
    {
        StringLz4::ensureLinked($context);

        return self::stringOrFalse($context, $context->builder->call(
            StringLz4::compressHelper($context),
            $data,
            $level
        ));
    }

    public static function uncompress(Context $context, Value $data, Value $max, Value $offset): Value
    {
        StringLz4::ensureLinked($context);

        return self::stringOrFalse($context, $context->builder->call(
            StringLz4::uncompressHelper($context),
            $data,
            $max,
            $offset
        ));
    }

    public static function defaultLevel(Context $context): Value
    {
        return $context->getTypeFromString('int64')->constInt(0, false);
    }

    private static function stringOrFalse(Context $context, Value $result): Value
    {
        $id = (string) (++self::$blockSerial);
        $strPtr = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $result, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'lz4_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'lz4_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'lz4_done_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeString($context, $slot, $result);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return JitValueBox::read($context, $ptr);
    }
}
