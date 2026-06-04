<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for strrpos() — inline scan for last match (VmString parity; no phpc_strrpos.c).
 *
 * Not found is represented as 0 (native long). VM mode returns boolean false instead.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitStrrpos
{
    public const NOT_FOUND = 0;

    private static int $blockSerial = 0;

    public static function find(
        Context $context,
        Value $haystack,
        Value $needle,
        ?Value $offset = null
    ): Value {
        $map = $context->structFieldMap['__string__'];
        $hayLen = $context->builder->load(
            $context->builder->structGep($haystack, $map['length'])
        );
        $needleLen = $context->builder->load(
            $context->builder->structGep($needle, $map['length'])
        );
        $hayPtr = $context->builder->structGep($haystack, $map['value']);
        $needlePtr = $context->builder->structGep($needle, $map['value']);
        $i64 = $context->getTypeFromString('int64');
        $zero = JitStringIndex::zero($context);
        $one = $i64->constInt(1, false);
        $notFound = $i64->constInt(self::NOT_FOUND, false);

        $emptyNeedle = $context->builder->icmp(Builder::INT_EQ, $needleLen, $zero);
        $tooShort = $context->builder->icmp(Builder::INT_SLT, $hayLen, $needleLen);
        $limit = $context->builder->sub($hayLen, $needleLen);

        [$minStart, $limit] = self::applyOffsetWindow($context, $hayLen, $limit, $offset);

        $pastWindow = $context->builder->icmp(Builder::INT_SGT, $minStart, $limit);

        $id = (string) (++self::$blockSerial);
        $lastSlot = $context->builder->alloca($i64, 1, 'strrpos_last_'.$id);
        $posSlot = $context->builder->alloca($i64, 1, 'strrpos_pos_'.$id);
        $context->builder->store($notFound, $lastSlot);
        $context->builder->store($minStart, $posSlot);

        $earlySkip = BasicBlockHelper::append($context, 'strrpos_skip_'.$id);
        $earlyDone = BasicBlockHelper::append($context, 'strrpos_early_'.$id);
        $loopHead = BasicBlockHelper::append($context, 'strrpos_head_'.$id);
        $loopDone = BasicBlockHelper::append($context, 'strrpos_done_'.$id);
        $merge = BasicBlockHelper::append($context, 'strrpos_merge_'.$id);

        $context->builder->branchIf($emptyNeedle, $earlySkip, $earlyDone);

        $context->builder->positionAtEnd($earlyDone);
        $skipLoop = $context->builder->or($tooShort, $pastWindow);
        $context->builder->branchIf($skipLoop, $earlySkip, $loopHead);

        $context->builder->positionAtEnd($earlySkip);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($loopHead);
        $loopBody = BasicBlockHelper::append($context, 'strrpos_body_'.$id);
        $pos = $context->builder->load($posSlot);
        $pastLimit = $context->builder->icmp(Builder::INT_SGT, $pos, $limit);
        $context->builder->branchIf($pastLimit, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $at = $context->builder->inBoundsGEP($hayPtr, $pos);
        $i32 = $context->getTypeFromString('int32');
        $cmp = $context->builder->call(
            $context->lookupFunction('strncmp'),
            $at,
            $needlePtr,
            $context->builder->intCast($needleLen, $context->getTypeFromString('size_t'))
        );
        $isMatch = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
        $noMatch = BasicBlockHelper::append($context, 'strrpos_nomatch_'.$id);
        $hit = BasicBlockHelper::append($context, 'strrpos_hit_'.$id);
        $context->builder->branchIf($isMatch, $hit, $noMatch);

        $context->builder->positionAtEnd($hit);
        $context->builder->store($pos, $lastSlot);
        $context->builder->branch($noMatch);

        $context->builder->positionAtEnd($noMatch);
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $loopResult = $context->builder->load($lastSlot);
        $loopDoneBlock = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $donePhi = $context->builder->phi($i64);
        $donePhi->addIncoming($notFound, $earlySkip);
        $donePhi->addIncoming($loopResult, $loopDoneBlock);

        return $donePhi;
    }

    /**
     * @return array{0: Value, 1: Value} min start offset and inclusive max start (VmString::strrpos window)
     */
    private static function applyOffsetWindow(
        Context $context,
        Value $hayLen,
        Value $limit,
        ?Value $offset
    ): array {
        $i64 = $context->getTypeFromString('int64');
        $zero = JitStringIndex::zero($context);
        $minStart = $zero;

        if (null === $offset) {
            return [$minStart, $limit];
        }

        $off = self::offsetAsSignedI64($context, $offset);
        $suffixEnd = $context->builder->addNoSignedWrap($hayLen, $off);
        $suffixBeforeHay = $context->builder->icmp(Builder::INT_SLT, $suffixEnd, $hayLen);

        $negSuffix = $context->builder->icmp(Builder::INT_SLT, $suffixEnd, $zero);
        $badOffset = $context->builder->and($suffixBeforeHay, $negSuffix);
        $minPos = $context->builder->select($badOffset, $limit, $context->builder->select($suffixBeforeHay, $zero, $off));
        $maxPos = $context->builder->select($badOffset, $limit, $context->builder->select($suffixBeforeHay, $suffixEnd, $limit));
        $maxPos = JitStringIndex::min($context, $maxPos, $limit);

        return [$minPos, $maxPos];
    }

    /** LLVM constInt(..., false) stores negative literals unsigned; read with SExt (issue #4104). */
    private static function offsetAsSignedI64(Context $context, Value $offset): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $lib = $context->llvm->lib;
        $llvmValue = $offset->value;
        if (null !== $lib->LLVMIsAConstantInt($llvmValue)) {
            $signed = (int) $lib->LLVMConstIntGetSExtValue($llvmValue);

            return $i64->constInt($signed, true);
        }

        return $offset->typeOf() === $i64
            ? $offset
            : $context->builder->sExtOrBitCast($offset, $i64);
    }
}
