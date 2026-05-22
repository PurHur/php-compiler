<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for strrchr() — libc strrchr(3) plus haystack slice from last byte match.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitStrrchr
{
    private static int $blockSerial = 0;

    /**
     * @return Value __value__* (string slice, or boolean false when not found)
     */
    public static function find(Context $context, Value $haystack, Value $needle): Value
    {
        $id = (string) (++self::$blockSerial);
        $map = $context->structFieldMap['__string__'];
        $hayLen = $context->builder->load(
            $context->builder->structGep($haystack, $map['length'])
        );
        $hayPtr = $context->builder->structGep($haystack, $map['value']);
        $needlePtr = $context->builder->structGep($needle, $map['value']);
        $i32 = $context->getTypeFromString('int32');
        $needleByte = $context->builder->load($needlePtr);
        $needleChar = $context->builder->zext($needleByte, $i32);
        $found = $context->builder->call(
            $context->lookupFunction('strrchr'),
            $hayPtr,
            $needleChar
        );
        $null = $context->getTypeFromString('int8*')->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $found, $null);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'strrchr_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'strrchr_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'strrchr_done_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $i64 = $context->getTypeFromString('int64');
        $foundInt = $context->builder->ptrToInt($found, $i64);
        $baseInt = $context->builder->ptrToInt($hayPtr, $i64);
        $pos = $context->builder->sub($foundInt, $baseInt);
        $lenAfter = $context->builder->sub($hayLen, $pos);
        $slice = string_trim::jitCopySlice($context, $haystack, $hayPtr, $pos, $lenAfter, 'src'.$id);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $slice
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
