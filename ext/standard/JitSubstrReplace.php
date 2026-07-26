<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helper for substr_replace() — mirrors VmString::substr_replace (#3356, #5652).
 */
final class JitSubstrReplace
{
    public static function replace(
        Context $context,
        Value $string,
        Value $replace,
        Value $offset,
        Value $length,
        Value $hasLength
    ): Value {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $zero64 = $i64->constInt(0, false);
        $one64 = $i64->constInt(1, false);

        $strLen = $context->builder->load(
            $context->builder->structGep($string, $map['length'])
        );
        $strPtr = $context->builder->structGep($string, $map['value']);

        $offSlot = $context->builder->alloca($i64, 1, 'substr_replace_off');
        $lenSlot = $context->builder->alloca($i64, 1, 'substr_replace_len');
        $context->builder->store($offset, $offSlot);

        $negOff = $context->builder->icmp(Builder::INT_SLT, $offset, $zero64);
        $negBlock = BasicBlockHelper::append($context, 'substr_replace_neg_off');
        $posOffBlock = BasicBlockHelper::append($context, 'substr_replace_pos_off');
        $afterNegBlock = BasicBlockHelper::append($context, 'substr_replace_after_neg');
        $context->builder->branchIf($negOff, $negBlock, $posOffBlock);

        $context->builder->positionAtEnd($negBlock);
        $adjOff = $context->builder->add($offset, $strLen);
        $stillNeg = $context->builder->icmp(Builder::INT_SLT, $adjOff, $zero64);
        $clampedNeg = $context->builder->select($stillNeg, $zero64, $adjOff);
        $context->builder->store($clampedNeg, $offSlot);
        $context->builder->branch($afterNegBlock);

        $context->builder->positionAtEnd($posOffBlock);
        $pastEnd = $context->builder->icmp(Builder::INT_SGT, $offset, $strLen);
        $clampedPos = $context->builder->select($pastEnd, $strLen, $offset);
        $context->builder->store($clampedPos, $offSlot);
        $context->builder->branch($afterNegBlock);

        $context->builder->positionAtEnd($afterNegBlock);
        $normOff = $context->builder->load($offSlot);
        $remain = $context->builder->sub($strLen, $normOff);

        $hasLenFlag = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->zExt($hasLength, $i32),
            $i32->constInt(0, false)
        );
        $noLenBlock = BasicBlockHelper::append($context, 'substr_replace_no_len');
        $hasLenBlock = BasicBlockHelper::append($context, 'substr_replace_has_len');
        $afterLenPick = BasicBlockHelper::append($context, 'substr_replace_after_len_pick');
        $context->builder->branchIf($hasLenFlag, $hasLenBlock, $noLenBlock);

        $context->builder->positionAtEnd($noLenBlock);
        $context->builder->store($remain, $lenSlot);
        $context->builder->branch($afterLenPick);

        $context->builder->positionAtEnd($hasLenBlock);
        $lenNeg = $context->builder->icmp(Builder::INT_SLT, $length, $zero64);
        $lenNegBlock = BasicBlockHelper::append($context, 'substr_replace_len_neg');
        $lenPosBlock = BasicBlockHelper::append($context, 'substr_replace_len_pos');
        $afterLenNorm = BasicBlockHelper::append($context, 'substr_replace_after_len_norm');
        $context->builder->branchIf($lenNeg, $lenNegBlock, $lenPosBlock);

        $context->builder->positionAtEnd($lenNegBlock);
        $adjLen = $context->builder->add($length, $remain);
        $stillNegLen = $context->builder->icmp(Builder::INT_SLT, $adjLen, $zero64);
        $clampedLenNeg = $context->builder->select($stillNegLen, $zero64, $adjLen);
        $context->builder->store($clampedLenNeg, $lenSlot);
        $context->builder->branch($afterLenNorm);

        $context->builder->positionAtEnd($lenPosBlock);
        $lenPast = $context->builder->icmp(Builder::INT_SGT, $length, $remain);
        $clampedLenPos = $context->builder->select($lenPast, $remain, $length);
        $context->builder->store($clampedLenPos, $lenSlot);
        $context->builder->branch($afterLenNorm);

        $context->builder->positionAtEnd($afterLenNorm);
        $context->builder->branch($afterLenPick);

        $context->builder->positionAtEnd($afterLenPick);
        $normLen = $context->builder->load($lenSlot);
        $tailStart = $context->builder->add($normOff, $normLen);
        $tailLen = $context->builder->sub($strLen, $tailStart);

        $prefixHasLen = $context->builder->icmp(Builder::INT_SGT, $normOff, $zero64);
        $prefixBlock = BasicBlockHelper::append($context, 'substr_replace_prefix');
        $skipPrefixBlock = BasicBlockHelper::append($context, 'substr_replace_skip_prefix');
        $afterPrefixBlock = BasicBlockHelper::append($context, 'substr_replace_after_prefix');
        $context->builder->branchIf($prefixHasLen, $prefixBlock, $skipPrefixBlock);

        $context->builder->positionAtEnd($prefixBlock);
        $prefixSlice = string_trim::jitCopySlice($context, $string, $strPtr, $zero64, $normOff, 'pre');
        // jitCopySlice ends in a fresh continue block — use that as the PHI predecessor.
        $prefixEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($afterPrefixBlock);

        $context->builder->positionAtEnd($skipPrefixBlock);
        $emptyPrefix = $context->builder->call($context->lookupFunction('__string__alloc'), $zero64);
        $skipPrefixEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($afterPrefixBlock);

        $context->builder->positionAtEnd($afterPrefixBlock);
        $prefixPhi = $context->builder->phi($context->getTypeFromString('__string__*'));
        $prefixPhi->addIncoming($prefixSlice, $prefixEndBlock);
        $prefixPhi->addIncoming($emptyPrefix, $skipPrefixEndBlock);

        $withReplace = JitStringConcat::concat($context, $prefixPhi, $replace);

        $tailHasLen = $context->builder->icmp(Builder::INT_SLT, $tailStart, $strLen);
        $tailBlock = BasicBlockHelper::append($context, 'substr_replace_tail');
        $skipTailBlock = BasicBlockHelper::append($context, 'substr_replace_skip_tail');
        $doneBlock = BasicBlockHelper::append($context, 'substr_replace_done');
        $context->builder->branchIf($tailHasLen, $tailBlock, $skipTailBlock);

        $context->builder->positionAtEnd($tailBlock);
        $tailSlice = string_trim::jitCopySlice($context, $string, $strPtr, $tailStart, $tailLen, 'tail');
        $result = JitStringConcat::concat($context, $withReplace, $tailSlice);
        $tailEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($skipTailBlock);
        $skipTailEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $resultPhi = $context->builder->phi($context->getTypeFromString('__string__*'));
        $resultPhi->addIncoming($result, $tailEndBlock);
        $resultPhi->addIncoming($withReplace, $skipTailEndBlock);

        return $resultPhi;
    }
}
