<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringBz2;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM helpers for bzcompress()/bzdecompress() — Bz2Runtime PHP bridge (#3402, #8868). */
final class JitBz2
{
    private static int $blockSerial = 0;

    public static function compress(Context $context, Value $source, Value $blockSize, Value $workFactor): Value
    {
        StringBz2::ensureLinked($context);

        return self::stringOrFalse($context, $context->builder->call(
            $context->lookupFunction('__compiler_bzcompress'),
            $source,
            $blockSize,
            $workFactor
        ));
    }

    public static function decompress(Context $context, Value $source, Value $small): Value
    {
        StringBz2::ensureLinked($context);

        return self::stringOrFalse($context, $context->builder->call(
            $context->lookupFunction('__compiler_bzdecompress'),
            $source,
            $small
        ));
    }

    private static function stringOrFalse(Context $context, Value $result): Value
    {
        $id = (string) (++self::$blockSerial);
        $strPtr = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $result, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'bz2_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'bz2_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'bz2_done_'.$id);
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
