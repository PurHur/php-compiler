<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for base64_decode() — non-strict RFC 4648 decode (PHP-compatible subset).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitBase64Decode
{
    public static function convert(Context $context, Value $strPtr): Value
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
        $two = $i64->constInt(2, false);
        $three = $i64->constInt(3, false);
        $skip = $i64->constInt(-1, true);

        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'b64dec_empty');
        $workBlock = BasicBlockHelper::append($context, 'b64dec_work');
        $doneBlock = BasicBlockHelper::append($context, 'b64dec_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyStr = $context->builder->call($context->lookupFunction('__string__alloc'), $zero);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $len);
        $destMap = $context->structFieldMap['__string__'];
        $destPtr = $context->builder->structGep($dest, $destMap['value']);

        $idxSlot = $context->builder->alloca($i64, 1, 'b64dec_in');
        $outSlot = $context->builder->alloca($i64, 1, 'b64dec_out');
        $stateSlot = $context->builder->alloca($i64, 1, 'b64dec_state');
        $accSlot = $context->builder->alloca($i8, 1, 'b64dec_acc');
        $context->builder->store($zero, $idxSlot);
        $context->builder->store($zero, $outSlot);
        $context->builder->store($zero, $stateSlot);
        $context->builder->store($i8->constInt(0, false), $accSlot);

        $loopHead = BasicBlockHelper::append($context, 'b64dec_head');
        $loopBody = BasicBlockHelper::append($context, 'b64dec_body');
        $processBlock = BasicBlockHelper::append($context, 'b64dec_process');
        $loopNext = BasicBlockHelper::append($context, 'b64dec_next');
        $state0 = BasicBlockHelper::append($context, 'b64dec_s0');
        $state1 = BasicBlockHelper::append($context, 'b64dec_s1');
        $state2 = BasicBlockHelper::append($context, 'b64dec_s2');
        $state3 = BasicBlockHelper::append($context, 'b64dec_s3');
        $loopDone = BasicBlockHelper::append($context, 'b64dec_loop_done');
        $trimBlock = BasicBlockHelper::append($context, 'b64dec_trim');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $inIdx = $context->builder->load($idxSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $inIdx, $len);
        $context->builder->branchIf($stop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $ch = $context->builder->load($context->builder->gep($charPtr, $inIdx));
        $isPad = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('='), false));
        $context->builder->branchIf($isPad, $loopNext, $processBlock);

        $dispatchBlock = BasicBlockHelper::append($context, 'b64dec_dispatch');
        $pick1 = BasicBlockHelper::append($context, 'b64dec_pick1');
        $pick23 = BasicBlockHelper::append($context, 'b64dec_pick23');

        $context->builder->positionAtEnd($processBlock);
        $val = self::base64NibbleValue($context, $ch, $i64, $i8);
        $isSkip = $context->builder->icmp(Builder::INT_EQ, $val, $skip);
        $context->builder->branchIf($isSkip, $loopNext, $dispatchBlock);

        $context->builder->positionAtEnd($dispatchBlock);
        $state = $context->builder->load($stateSlot);
        $mod4 = $context->builder->bitwiseAnd($state, $three);
        $is0 = $context->builder->icmp(Builder::INT_EQ, $mod4, $zero);
        $is1 = $context->builder->icmp(Builder::INT_EQ, $mod4, $one);
        $is2 = $context->builder->icmp(Builder::INT_EQ, $mod4, $two);
        $context->builder->branchIf($is0, $state0, $pick1);

        $context->builder->positionAtEnd($pick1);
        $context->builder->branchIf($is1, $state1, $pick23);

        $context->builder->positionAtEnd($pick23);
        $context->builder->branchIf($is2, $state2, $state3);

        $context->builder->positionAtEnd($state0);
        $context->builder->store(
            $context->builder->truncOrBitCast($context->builder->shl($val, $two), $i8),
            $accSlot
        );
        $context->builder->store($context->builder->addNoSignedWrap($state, $one), $stateSlot);
        $context->builder->branch($loopNext);

        $context->builder->positionAtEnd($state1);
        $accPrev = $context->builder->zExt($context->builder->load($accSlot), $i64);
        $outIdx = $context->builder->load($outSlot);
        $context->builder->store(
            $context->builder->truncOrBitCast(
                $context->builder->or($accPrev, $context->builder->lShr($val, $i64->constInt(4, false))),
                $i8
            ),
            $context->builder->gep($destPtr, $outIdx)
        );
        $context->builder->store(
            $context->builder->truncOrBitCast(
                $context->builder->shl($context->builder->bitwiseAnd($val, $i64->constInt(0x0f, false)), $i64->constInt(4, false)),
                $i8
            ),
            $accSlot
        );
        $context->builder->store($context->builder->addNoSignedWrap($outIdx, $one), $outSlot);
        $context->builder->store($context->builder->addNoSignedWrap($state, $one), $stateSlot);
        $context->builder->branch($loopNext);

        $context->builder->positionAtEnd($state2);
        $accPrev2 = $context->builder->zExt($context->builder->load($accSlot), $i64);
        $outIdx2 = $context->builder->load($outSlot);
        $context->builder->store(
            $context->builder->truncOrBitCast(
                $context->builder->or($accPrev2, $context->builder->lShr($val, $two)),
                $i8
            ),
            $context->builder->gep($destPtr, $outIdx2)
        );
        $context->builder->store(
            $context->builder->truncOrBitCast(
                $context->builder->shl($context->builder->bitwiseAnd($val, $i64->constInt(0x03, false)), $i64->constInt(6, false)),
                $i8
            ),
            $accSlot
        );
        $context->builder->store($context->builder->addNoSignedWrap($outIdx2, $one), $outSlot);
        $context->builder->store($context->builder->addNoSignedWrap($state, $one), $stateSlot);
        $context->builder->branch($loopNext);

        $context->builder->positionAtEnd($state3);
        $accPrev3 = $context->builder->zExt($context->builder->load($accSlot), $i64);
        $outIdx3 = $context->builder->load($outSlot);
        $context->builder->store(
            $context->builder->truncOrBitCast($context->builder->or($accPrev3, $val), $i8),
            $context->builder->gep($destPtr, $outIdx3)
        );
        $context->builder->store($context->builder->addNoSignedWrap($outIdx3, $one), $outSlot);
        $context->builder->store($context->builder->addNoSignedWrap($state, $one), $stateSlot);
        $context->builder->branch($loopNext);

        $context->builder->positionAtEnd($loopNext);
        $context->builder->store($context->builder->addNoSignedWrap($context->builder->load($idxSlot), $one), $idxSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->branch($trimBlock);

        $context->builder->positionAtEnd($trimBlock);
        $finalLen = $context->builder->load($outSlot);
        $context->builder->store($finalLen, $context->builder->structGep($dest, $destMap['length']));
        $destChars = $context->builder->structGep($dest, $destMap['value']);
        $context->builder->store($i8->constInt(0, false), $context->builder->gep($destChars, $finalLen));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $result = $context->builder->phi($dest->typeOf());
        $result->addIncoming($emptyStr, $emptyBlock);
        $result->addIncoming($dest, $trimBlock);

        return $result;
    }

    /** @return Value int64 nibble 0-63, or -1 when not a base64 symbol (whitespace / invalid) */
    private static function base64NibbleValue(Context $context, Value $ch, $i64, $i8): Value
    {
        $ord = $context->builder->zExt($ch, $i64);
        $skip = $i64->constInt(-1, true);

        $isUpper = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ord, $i64->constInt(ord('A'), false)),
            $context->builder->icmp(Builder::INT_SLE, $ord, $i64->constInt(ord('Z'), false))
        );
        $upperVal = $context->builder->subNoSignedWrap($ord, $i64->constInt(ord('A'), false));

        $isLower = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ord, $i64->constInt(ord('a'), false)),
            $context->builder->icmp(Builder::INT_SLE, $ord, $i64->constInt(ord('z'), false))
        );
        $lowerVal = $context->builder->subNoSignedWrap($ord, $i64->constInt(71, false));

        $isDigit = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ord, $i64->constInt(ord('0'), false)),
            $context->builder->icmp(Builder::INT_SLE, $ord, $i64->constInt(ord('9'), false))
        );
        $digitVal = $context->builder->addNoSignedWrap($ord, $i64->constInt(4, false));

        $isPlus = $context->builder->icmp(Builder::INT_EQ, $ord, $i64->constInt(ord('+'), false));
        $plusVal = $i64->constInt(62, false);

        $isSlash = $context->builder->icmp(Builder::INT_EQ, $ord, $i64->constInt(ord('/'), false));
        $slashVal = $i64->constInt(63, false);

        $v = $context->builder->select($isUpper, $upperVal, $skip);
        $v = $context->builder->select($isLower, $lowerVal, $v);
        $v = $context->builder->select($isDigit, $digitVal, $v);
        $v = $context->builder->select($isPlus, $plusVal, $v);

        return $context->builder->select($isSlash, $slashVal, $v);
    }
}
