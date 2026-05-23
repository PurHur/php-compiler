<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for base64_decode() — non-strict RFC 4648 decode.
 *
 * Invalid input returns an empty string at the LLVM layer; VM mode returns boolean false.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitBase64Decode
{
    public static function decode(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $six = $i64->constInt(6, false);
        $eight = $i64->constInt(8, false);
        $invalid = $i64->constInt(-1, true);
        $eqOrd = $i8->constInt(61, false);

        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'b64dec_empty');
        $workBlock = BasicBlockHelper::append($context, 'b64dec_work');
        $doneBlock = BasicBlockHelper::append($context, 'b64dec_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyStr = $context->builder->call($context->lookupFunction('__string__alloc'), $zero);
        $context->builder->branch($doneBlock);

        $failBlock = BasicBlockHelper::append($context, 'b64dec_fail');
        $context->builder->positionAtEnd($failBlock);
        $failStr = $context->builder->call($context->lookupFunction('__string__alloc'), $zero);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $len);
        $destMap = $context->structFieldMap['__string__'];
        $destPtr = $context->builder->structGep($dest, $destMap['value']);

        $valSlot = $context->builder->alloca($i64, 1, 'b64dec_val');
        $bitsSlot = $context->builder->alloca($i64, 1, 'b64dec_bits');
        $outSlot = $context->builder->alloca($i64, 1, 'b64dec_out');
        $padSlot = $context->builder->alloca($i8, 1, 'b64dec_pad');
        $context->builder->store($zero, $valSlot);
        $context->builder->store($zero, $bitsSlot);
        $context->builder->store($zero, $outSlot);
        $context->builder->store($i8->constInt(0, false), $padSlot);

        $idxSlot = $context->builder->alloca($i64, 1, 'b64dec_idx');
        $context->builder->store($zero, $idxSlot);

        $loopHead = BasicBlockHelper::append($context, 'b64dec_head');
        $loopBody = BasicBlockHelper::append($context, 'b64dec_body');
        $loopDone = BasicBlockHelper::append($context, 'b64dec_loop_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $idx, $len);
        $context->builder->branchIf($stop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $ch = $context->builder->load($context->builder->gep($charPtr, $idx));
        $isEq = $context->builder->icmp(Builder::INT_EQ, $ch, $eqOrd);

        $padBlock = BasicBlockHelper::append($context, 'b64dec_pad');
        $dataBlock = BasicBlockHelper::append($context, 'b64dec_data');
        $afterChar = BasicBlockHelper::append($context, 'b64dec_after');
        $context->builder->branchIf($isEq, $padBlock, $dataBlock);

        $context->builder->positionAtEnd($padBlock);
        $hadPad = $context->builder->load($padSlot);
        $padTwice = $context->builder->icmp(Builder::INT_NE, $hadPad, $i8->constInt(0, false));
        $padFailBlock = BasicBlockHelper::append($context, 'b64dec_pad_fail');
        $padOkBlock = BasicBlockHelper::append($context, 'b64dec_pad_ok');
        $context->builder->branchIf($padTwice, $padFailBlock, $padOkBlock);
        $context->builder->positionAtEnd($padFailBlock);
        $context->builder->branch($failBlock);
        $context->builder->positionAtEnd($padOkBlock);
        $context->builder->store($i8->constInt(1, false), $padSlot);
        $context->builder->branch($loopDone);

        $context->builder->positionAtEnd($dataBlock);
        $hadPadBefore = $context->builder->load($padSlot);
        $afterPadData = $context->builder->icmp(Builder::INT_NE, $hadPadBefore, $i8->constInt(0, false));
        $context->builder->branchIf($afterPadData, $failBlock, $afterChar);

        $context->builder->positionAtEnd($afterChar);
        $digit = self::base64DigitValue($context, $ch, $i64, $i8);
        $isValid = $context->builder->icmp(Builder::INT_NE, $digit, $invalid);
        $emitBlock = BasicBlockHelper::append($context, 'b64dec_emit');
        $skipBlock = BasicBlockHelper::append($context, 'b64dec_skip');
        $context->builder->branchIf($isValid, $emitBlock, $skipBlock);

        $context->builder->positionAtEnd($skipBlock);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($emitBlock);
        $val = $context->builder->load($valSlot);
        $bits = $context->builder->load($bitsSlot);
        $val = $context->builder->or(
            $context->builder->shl($val, $six),
            $digit
        );
        $bits = $context->builder->addNoSignedWrap($bits, $six);
        $emitByteBlock = BasicBlockHelper::append($context, 'b64dec_emit_byte');
        $noEmitBlock = BasicBlockHelper::append($context, 'b64dec_no_emit');
        $emitMerge = BasicBlockHelper::append($context, 'b64dec_emit_merge');
        $hasByte = $context->builder->icmp(Builder::INT_SGE, $bits, $eight);
        $context->builder->branchIf($hasByte, $emitByteBlock, $noEmitBlock);

        $context->builder->positionAtEnd($emitByteBlock);
        $bitsAfter = $context->builder->subNoSignedWrap($bits, $eight);
        $outIdx = $context->builder->load($outSlot);
        $oneI64 = $i64->constInt(1, false);
        $byte = $context->builder->truncOrBitCast(
            $context->builder->bitwiseAnd(
                $context->builder->lShr($val, $bitsAfter),
                $i64->constInt(0xFF, false)
            ),
            $i8
        );
        $mask = $context->builder->subNoSignedWrap(
            $context->builder->shl($oneI64, $bitsAfter),
            $oneI64
        );
        $valMasked = $context->builder->bitwiseAnd($val, $mask);
        $context->builder->store($byte, $context->builder->gep($destPtr, $outIdx));
        $context->builder->store($context->builder->addNoSignedWrap($outIdx, $one), $outSlot);
        $context->builder->store($valMasked, $valSlot);
        $context->builder->store($bitsAfter, $bitsSlot);
        $context->builder->branch($emitMerge);

        $context->builder->positionAtEnd($noEmitBlock);
        $context->builder->store($val, $valSlot);
        $context->builder->store($bits, $bitsSlot);
        $context->builder->branch($emitMerge);

        $context->builder->positionAtEnd($emitMerge);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($loopHead);

        $finalizeBlock = BasicBlockHelper::append($context, 'b64dec_finalize');

        $context->builder->positionAtEnd($loopDone);
        $hadPadEnd = $context->builder->load($padSlot);
        $bitsEnd = $context->builder->load($bitsSlot);
        $padWithBits = $context->builder->and(
            $context->builder->icmp(Builder::INT_NE, $hadPadEnd, $i8->constInt(0, false)),
            $context->builder->icmp(Builder::INT_NE, $bitsEnd, $zero)
        );
        $context->builder->branchIf($padWithBits, $failBlock, $finalizeBlock);

        $context->builder->positionAtEnd($finalizeBlock);
        $outLen = $context->builder->load($outSlot);
        $context->builder->store(
            $outLen,
            $context->builder->structGep($dest, $destMap['length'])
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $result = $context->builder->phi($dest->typeOf());
        $result->addIncoming($emptyStr, $emptyBlock);
        $result->addIncoming($failStr, $failBlock);
        $result->addIncoming($dest, $finalizeBlock);

        return $result;
    }

    /** @return Value int64 0-63, or -1 when not base64 alphabet */
    private static function base64DigitValue(Context $context, Value $ch, $i64, $i8): Value
    {
        $ord = $context->builder->zExt($ch, $i64);
        $invalid = $i64->constInt(-1, true);

        $isUpper = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ord, $i64->constInt(65, false)),
            $context->builder->icmp(Builder::INT_SLE, $ord, $i64->constInt(90, false))
        );
        $upperVal = $context->builder->subNoSignedWrap($ord, $i64->constInt(65, false));

        $isLower = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ord, $i64->constInt(97, false)),
            $context->builder->icmp(Builder::INT_SLE, $ord, $i64->constInt(122, false))
        );
        $lowerVal = $context->builder->subNoSignedWrap($ord, $i64->constInt(71, false));

        $isDigit = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ord, $i64->constInt(48, false)),
            $context->builder->icmp(Builder::INT_SLE, $ord, $i64->constInt(57, false))
        );
        $digitVal = $context->builder->addNoSignedWrap(
            $context->builder->subNoSignedWrap($ord, $i64->constInt(48, false)),
            $i64->constInt(52, false)
        );

        $isPlus = $context->builder->icmp(Builder::INT_EQ, $ord, $i64->constInt(43, false));
        $isSlash = $context->builder->icmp(Builder::INT_EQ, $ord, $i64->constInt(47, false));

        $v = $context->builder->select($isUpper, $upperVal, $invalid);
        $v = $context->builder->select($isLower, $lowerVal, $v);
        $v = $context->builder->select($isDigit, $digitVal, $v);
        $v = $context->builder->select($isPlus, $i64->constInt(62, false), $v);

        return $context->builder->select($isSlash, $i64->constInt(63, false), $v);
    }
}
