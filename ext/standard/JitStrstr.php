<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for strstr() — binary-safe search via JitStringSearch (#4146, #14017).
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

    /** @return Value */
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
        if ($caseInsensitive) {
            return self::findCaseInsensitive($context, $id, $haystack, $hayPtr, $hayLen, $needle, $beforeNeedle);
        }

        return self::findCaseSensitive($context, $id, $haystack, $hayPtr, $hayLen, $needle, $beforeNeedle);
    }

    private static function findCaseSensitive(
        Context $context,
        string $id,
        Value $haystack,
        Value $hayPtr,
        Value $hayLen,
        Value $needle,
        ?Value $beforeNeedle
    ): Value {
        $i32 = $context->getTypeFromString('int32');
        $foundI32 = JitStringSearch::findOffsetI32($context, $haystack, $needle, null, false);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $foundI32,
            $i32->constInt(JitStringSearch::NOT_FOUND, true)
        );

        return self::finishFindSlice(
            $context,
            $id,
            $haystack,
            $hayPtr,
            $hayLen,
            $beforeNeedle,
            $isNull,
            $foundI32,
            'strstr',
            'ss'
        );
    }

    private static function findCaseInsensitive(
        Context $context,
        string $id,
        Value $haystack,
        Value $hayPtr,
        Value $hayLen,
        Value $needle,
        ?Value $beforeNeedle
    ): Value {
        $i32 = $context->getTypeFromString('int32');
        $foundI32 = JitStringSearch::findOffsetI32($context, $haystack, $needle, null, true);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $foundI32,
            $i32->constInt(JitStringSearch::NOT_FOUND, true)
        );

        return self::finishFindSlice(
            $context,
            $id,
            $haystack,
            $hayPtr,
            $hayLen,
            $beforeNeedle,
            $isNull,
            $foundI32,
            'strstr_ci',
            'ssci'
        );
    }

    /**
     * @param Value $isNull i1 — match not found
     */
    private static function finishFindSlice(
        Context $context,
        string $id,
        Value $haystack,
        Value $hayPtr,
        Value $hayLen,
        ?Value $beforeNeedle,
        Value $isNull,
        Value $foundI32,
        string $prefix,
        string $sliceTag
    ): Value {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, $prefix.'_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, $prefix.'_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, $prefix.'_done_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $i64 = $context->getTypeFromString('int64');
        $pos = $context->builder->zExt($foundI32, $i64);
        $zero = JitStringIndex::zero($context);

        if (null !== $beforeNeedle) {
            $useBefore = $context->builder->truncOrBitCast($beforeNeedle, $i1);
            $beforeBlock = BasicBlockHelper::append($context, $prefix.'_before_'.$id);
            $afterBlock = BasicBlockHelper::append($context, $prefix.'_after_'.$id);
            $sliceBlock = BasicBlockHelper::append($context, $prefix.'_slice_'.$id);
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
            $slice = string_trim::jitCopySlice($context, $haystack, $hayPtr, $startPhi, $lenPhi, $sliceTag.$id);
        } else {
            $lenAfter = $context->builder->sub($hayLen, $pos);
            $slice = string_trim::jitCopySlice($context, $haystack, $hayPtr, $pos, $lenAfter, $sliceTag.$id);
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
}
