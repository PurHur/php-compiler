<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for strstr() — libc strstr(3) plus haystack slice (optional before_needle).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitStrstr
{
    private static int $blockSerial = 0;

    /**
     * @return Value __value__* (string slice, or boolean false when not found)
     */
    public static function find(
        Context $context,
        Value $haystack,
        Value $needle,
        ?Value $beforeNeedle = null,
        bool $caseInsensitive = false
    ): Value {
        $id = (string) (++self::$blockSerial);
        $map = $context->structFieldMap['__string__'];
        $hayLen = $context->builder->load(
            $context->builder->structGep($haystack, $map['length'])
        );
        $hayPtr = $context->builder->structGep($haystack, $map['value']);
        $needlePtr = $context->builder->structGep($needle, $map['value']);
        $searchFn = $caseInsensitive ? 'strcasestr' : 'strstr';
        $found = $context->builder->call(
            $context->lookupFunction($searchFn),
            $hayPtr,
            $needlePtr
        );
        $null = $context->getTypeFromString('int8*')->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $found, $null);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'strstr_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'strstr_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'strstr_done_'.$id);
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
        $zero = JitStringIndex::zero($context);

        if (null !== $beforeNeedle) {
            $useBefore = $context->builder->truncOrBitCast($beforeNeedle, $i1);
            $beforeBlock = BasicBlockHelper::append($context, 'strstr_before_'.$id);
            $afterBlock = BasicBlockHelper::append($context, 'strstr_after_'.$id);
            $sliceBlock = BasicBlockHelper::append($context, 'strstr_slice_'.$id);
            $context->builder->branchIf($useBefore, $beforeBlock, $afterBlock);

            $context->builder->positionAtEnd($beforeBlock);
            $startBefore = $zero;
            $lenBefore = $pos;
            $context->builder->branch($sliceBlock);

            $context->builder->positionAtEnd($afterBlock);
            $startAfter = $pos;
            $lenAfter = $context->builder->sub($hayLen, $pos);
            $context->builder->branch($sliceBlock);

            $context->builder->positionAtEnd($sliceBlock);
            $startPhi = $context->builder->phi($i64);
            $startPhi->addIncoming($startBefore, $beforeBlock);
            $startPhi->addIncoming($startAfter, $afterBlock);
            $lenPhi = $context->builder->phi($i64);
            $lenPhi->addIncoming($lenBefore, $beforeBlock);
            $lenPhi->addIncoming($lenAfter, $afterBlock);
            $slice = string_trim::jitCopySlice($context, $haystack, $hayPtr, $startPhi, $lenPhi, 'ss'.$id);
        } else {
            $lenAfter = $context->builder->sub($hayLen, $pos);
            $slice = string_trim::jitCopySlice($context, $haystack, $hayPtr, $pos, $lenAfter, 'ss'.$id);
        }

        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $slice
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    /**
     * strrchr() — libc strrchr(3) on the first needle byte, then haystack slice to end.
     *
     * @return Value __value__* (string slice, or boolean false when not found)
     */
    public static function findLast(Context $context, Value $haystack, Value $needle): Value
    {
        $id = (string) (++self::$blockSerial);
        $map = $context->structFieldMap['__string__'];
        $hayLen = $context->builder->load(
            $context->builder->structGep($haystack, $map['length'])
        );
        $hayPtr = $context->builder->structGep($haystack, $map['value']);
        $needlePtr = $context->builder->structGep($needle, $map['value']);
        $firstByte = $context->builder->load($needlePtr);
        $i32 = $context->getTypeFromString('int32');
        $found = $context->builder->call(
            $context->lookupFunction('strrchr'),
            $hayPtr,
            $context->builder->zExt($firstByte, $i32)
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
        $slice = string_trim::jitCopySlice($context, $haystack, $hayPtr, $pos, $lenAfter, 'sr'.$id);
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
