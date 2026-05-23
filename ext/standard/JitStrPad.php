<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for str_pad() — STR_PAD_LEFT, STR_PAD_RIGHT, STR_PAD_BOTH.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitStrPad
{
    public static function pad(
        Context $context,
        Value $input,
        Value $padLength,
        Value $padString,
        Value $padType
    ): Value {
        $map = $context->structFieldMap['__string__'];
        $inputLen = $context->builder->load(
            $context->builder->structGep($input, $map['length'])
        );
        $inputPtr = $context->builder->structGep($input, $map['value']);
        $padLen = $context->builder->load(
            $context->builder->structGep($padString, $map['length'])
        );
        $padPtr = $context->builder->structGep($padString, $map['value']);

        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $padLeftConst = $i64->constInt(0, false);
        $padBothConst = $i64->constInt(2, false);

        $noPad = $context->builder->icmp(Builder::INT_SLE, $padLength, $inputLen);
        $shortBlock = BasicBlockHelper::append($context, 'strpad_short');
        $workBlock = BasicBlockHelper::append($context, 'strpad_work');
        $doneBlock = BasicBlockHelper::append($context, 'strpad_done');
        $context->builder->branchIf($noPad, $shortBlock, $workBlock);

        $context->builder->positionAtEnd($shortBlock);
        $unchanged = $context->builder->call($context->lookupFunction('__string__separate'), $input);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $need = $context->builder->sub($padLength, $inputLen);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $padLength);
        $destPtr = $context->builder->structGep($dest, $map['value']);
        $context->builder->store(
            $padLength,
            $context->builder->structGep($dest, $map['length'])
        );

        $isLeft = $context->builder->icmp(Builder::INT_EQ, $padType, $padLeftConst);
        $leftBlock = BasicBlockHelper::append($context, 'strpad_left');
        $typeBlock = BasicBlockHelper::append($context, 'strpad_type');
        $bothBlock = BasicBlockHelper::append($context, 'strpad_both');
        $rightBlock = BasicBlockHelper::append($context, 'strpad_right');
        $joinedBlock = BasicBlockHelper::append($context, 'strpad_joined');
        $context->builder->branchIf($isLeft, $leftBlock, $typeBlock);

        $context->builder->positionAtEnd($leftBlock);
        self::fillPadding($context, $destPtr, $need, $padPtr, $padLen, $zero);
        $context->intrinsic->memcpy(
            $context->builder->gep($destPtr, $need),
            $inputPtr,
            $inputLen,
            false
        );
        $context->builder->branch($joinedBlock);

        $context->builder->positionAtEnd($typeBlock);
        $isBoth = $context->builder->icmp(Builder::INT_EQ, $padType, $padBothConst);
        $context->builder->branchIf($isBoth, $bothBlock, $rightBlock);

        $context->builder->positionAtEnd($bothBlock);
        $leftNeed = $context->builder->signedDiv($need, $two);
        $rightNeed = $context->builder->sub($need, $leftNeed);
        self::fillPadding($context, $destPtr, $leftNeed, $padPtr, $padLen, $zero);
        $afterLeft = $context->builder->gep($destPtr, $leftNeed);
        $context->intrinsic->memcpy($afterLeft, $inputPtr, $inputLen, false);
        self::fillPadding(
            $context,
            $context->builder->gep($afterLeft, $inputLen),
            $rightNeed,
            $padPtr,
            $padLen,
            $zero
        );
        $context->builder->branch($joinedBlock);

        $context->builder->positionAtEnd($rightBlock);
        $context->intrinsic->memcpy($destPtr, $inputPtr, $inputLen, false);
        self::fillPadding(
            $context,
            $context->builder->gep($destPtr, $inputLen),
            $need,
            $padPtr,
            $padLen,
            $zero
        );
        $context->builder->branch($joinedBlock);

        $context->builder->positionAtEnd($joinedBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $result = $context->builder->phi($dest->typeOf());
        $result->addIncoming($unchanged, $shortBlock);
        $result->addIncoming($dest, $joinedBlock);

        return $result;
    }

    private static function fillPadding(
        Context $context,
        Value $destAt,
        Value $need,
        Value $padPtr,
        Value $padLen,
        Value $padOffset
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $zero = $i64->constInt(0, false);

        $idxSlot = $context->builder->alloca($i64, 1, 'strpad_pad_idx');
        $context->builder->store($zero, $idxSlot);

        $loopHead = BasicBlockHelper::append($context, 'strpad_pad_head');
        $loopBody = BasicBlockHelper::append($context, 'strpad_pad_body');
        $loopDone = BasicBlockHelper::append($context, 'strpad_pad_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $idx, $need);
        $context->builder->branchIf($stop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $padIdx = $context->builder->unsigendRem(
            $context->builder->add($padOffset, $idx),
            $padLen
        );
        $ch = $context->builder->load($context->builder->gep($padPtr, $padIdx));
        $context->builder->store($ch, $context->builder->gep($destAt, $idx));
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
    }
}
