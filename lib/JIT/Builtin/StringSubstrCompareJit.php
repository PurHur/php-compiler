<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM substr_compare (mirrors VmString::substr_compare / former phpc_substr_compare.c).
 */
final class StringSubstrCompareJit
{
    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            self::declareIfMissing($context);

            return;
        }

        $probe = $context->module->getNamedFunction('substr_compare');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('substr_compare', $probe);

            return;
        }

        $fn = self::declareIfMissing($context);
        self::emitBody($context, $fn);
        $context->registerFunction('substr_compare', $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareIfMissing(Context $context): LlvmFunction
    {
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false, $i8p, $i8p, $i64, $i64, $i32);
        try {
            return $context->lookupFunction('substr_compare');
        } catch (\Throwable $e) {
            $fn = $context->module->addFunction('substr_compare', $ft);
            $context->registerFunction('substr_compare', $fn);

            return $fn;
        }
    }

    private static function emitBody(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $nullPtr = $i8p->constNull();
        $zero64 = $i64->constInt(0, false);
        $negOne64 = $i64->constInt(-1, false);
        $negTwo32 = $i32->constInt(-2, false);

        $emptySlot = $context->builder->alloca($i8, 1);
        $context->builder->store($i8->constInt(0, false), $emptySlot);
        $emptyPtr = $context->builder->pointerCast($emptySlot, $i8p);

        $hayIn = $fn->getParam(0);
        $needleIn = $fn->getParam(1);
        $offsetIn = $fn->getParam(2);
        $lengthArgIn = $fn->getParam(3);
        $caseIn = $fn->getParam(4);

        $haySlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $needleSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $hayIsNull = $context->builder->icmp(Builder::INT_EQ, $hayIn, $nullPtr);
        $needleIsNull = $context->builder->icmp(Builder::INT_EQ, $needleIn, $nullPtr);
        $context->builder->store($context->builder->select($hayIsNull, $emptyPtr, $hayIn), $haySlot);
        $context->builder->store($context->builder->select($needleIsNull, $emptyPtr, $needleIn), $needleSlot);

        $hayPtr = $context->builder->load($haySlot);
        $needlePtr = $context->builder->load($needleSlot);
        $hayLen = $context->builder->zExt(
            $context->builder->call($context->lookupFunction('strlen'), $hayPtr),
            $i64
        );
        $needleLen = $context->builder->zExt(
            $context->builder->call($context->lookupFunction('strlen'), $needlePtr),
            $i64
        );

        $offsetSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($offsetIn, $offsetSlot);

        $negOff = $fn->appendBasicBlock('sc_neg_offset');
        $offReady = $fn->appendBasicBlock('sc_offset_ready');
        $offNeg = $context->builder->icmp(Builder::INT_SLT, $offsetIn, $zero64);
        $context->builder->branchIf($offNeg, $negOff, $offReady);

        $context->builder->positionAtEnd($negOff);
        $adj = $context->builder->add($offsetIn, $hayLen);
        $clampZero = $context->builder->select(
            $context->builder->icmp(Builder::INT_SLT, $adj, $zero64),
            $zero64,
            $adj
        );
        $context->builder->store($clampZero, $offsetSlot);
        $context->builder->branch($offReady);

        $context->builder->positionAtEnd($offReady);
        $offset = $context->builder->load($offsetSlot);
        $tooFar = $context->builder->icmp(Builder::INT_UGT, $offset, $hayLen);
        $badOff = $fn->appendBasicBlock('sc_bad_offset');
        $slice = $fn->appendBasicBlock('sc_slice');
        $context->builder->branchIf($tooFar, $badOff, $slice);

        $context->builder->positionAtEnd($badOff);
        $context->builder->returnValue($negTwo32);

        $context->builder->positionAtEnd($slice);
        $s1 = $context->builder->intToPtr(
            $context->builder->add($context->builder->ptrToInt($hayPtr, $i64), $offset),
            $i8p
        );
        $hayRemain = $context->builder->sub($hayLen, $offset);

        $cmpLenSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $compareRemainSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($hayRemain, $compareRemainSlot);

        $lenGiven = $context->builder->icmp(Builder::INT_SGE, $lengthArgIn, $zero64);
        $lenPos = $fn->appendBasicBlock('sc_len_positive');
        $lenOmitted = $fn->appendBasicBlock('sc_len_omitted');
        $cmpReady = $fn->appendBasicBlock('sc_cmp_ready');
        $context->builder->branchIf($lenGiven, $lenPos, $lenOmitted);

        $context->builder->positionAtEnd($lenPos);
        $lenGtRemain = $context->builder->icmp(Builder::INT_UGT, $lengthArgIn, $hayRemain);
        $cmpLen = $context->builder->select($lenGtRemain, $hayRemain, $lengthArgIn);
        $context->builder->store($cmpLen, $cmpLenSlot);
        $context->builder->store($cmpLen, $compareRemainSlot);
        $context->builder->branch($cmpReady);

        $context->builder->positionAtEnd($lenOmitted);
        $needleGtRemain = $context->builder->icmp(Builder::INT_UGT, $needleLen, $hayRemain);
        $cmpLen = $context->builder->select($needleGtRemain, $hayRemain, $needleLen);
        $context->builder->store($cmpLen, $cmpLenSlot);
        $context->builder->branch($cmpReady);

        $context->builder->positionAtEnd($cmpReady);
        $cmpLen = $context->builder->load($cmpLenSlot);
        $compareRemain = $context->builder->load($compareRemainSlot);
        $caseOn = $context->builder->icmp(Builder::INT_NE, $caseIn, $i32->constInt(0, false));

        $cmpResult = self::emitByteStrncmp($context, $fn, $s1, $needlePtr, $cmpLen, $caseOn);

        $tailCheck = $fn->appendBasicBlock('sc_tail_check');
        $done = $fn->appendBasicBlock('sc_done');
        $context->builder->branch($tailCheck);

        $context->builder->positionAtEnd($tailCheck);
        $zero32 = $i32->constInt(0, false);
        $one32 = $i32->constInt(1, false);
        $negOne32 = $i32->constInt(-1, false);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $cmpResult, $zero32);
        $lenOmittedFlag = $context->builder->icmp(Builder::INT_SLT, $lengthArgIn, $zero64);
        $remainDiff = $context->builder->icmp(Builder::INT_NE, $compareRemain, $needleLen);
        $needOmittedTail = $context->builder->and($isZero, $context->builder->and($lenOmittedFlag, $remainDiff));
        $explicitLonger = $context->builder->and(
            $isZero,
            $context->builder->and(
                $context->builder->not($lenOmittedFlag),
                $context->builder->icmp(Builder::INT_UGT, $compareRemain, $needleLen)
            )
        );
        $omittedTailBody = $fn->appendBasicBlock('sc_omitted_tail_body');
        $checkExplicit = $fn->appendBasicBlock('sc_check_explicit');
        $context->builder->branchIf($needOmittedTail, $omittedTailBody, $checkExplicit);

        $context->builder->positionAtEnd($omittedTailBody);
        $shorter = $context->builder->icmp(Builder::INT_ULT, $compareRemain, $needleLen);
        $tailRet = $context->builder->select($shorter, $negOne32, $one32);
        $context->builder->returnValue($tailRet);

        $context->builder->positionAtEnd($checkExplicit);
        $explicitLongerRet = $fn->appendBasicBlock('sc_explicit_longer_ret');
        $context->builder->branchIf($explicitLonger, $explicitLongerRet, $done);

        $context->builder->positionAtEnd($explicitLongerRet);
        $context->builder->returnValue($one32);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($cmpResult);
    }

    private static function emitByteStrncmp(
        Context $context,
        LlvmFunction $fn,
        Value $s1,
        Value $needlePtr,
        Value $cmpLen,
        Value $caseInsensitive
    ): Value {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $zero64 = $i64->constInt(0, false);
        $one64 = $i64->constInt(1, false);
        $zero32 = $i32->constInt(0, false);

        $iSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($zero64, $iSlot);

        $loopHead = $fn->appendBasicBlock('sc_cmp_head');
        $loopDone = $fn->appendBasicBlock('sc_cmp_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $cmpLen);
        $loopBody = $fn->appendBasicBlock('sc_cmp_body');
        $context->builder->branchIf($atEnd, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $off32 = $context->builder->trunc($i, $i32);
        $ca = self::loadByte($context, $context->builder->gep($s1, $off32));
        $cb = self::loadByte($context, $context->builder->gep($needlePtr, $off32));
        $ca = $context->builder->select($caseInsensitive, self::asciiLower($context, $ca), $ca);
        $cb = $context->builder->select($caseInsensitive, self::asciiLower($context, $cb), $cb);
        $diff = $context->builder->sub(
            $context->builder->zExt($ca, $i64),
            $context->builder->zExt($cb, $i64)
        );
        $same = $context->builder->icmp(Builder::INT_EQ, $diff, $zero64);
        $retBlock = $fn->appendBasicBlock('sc_cmp_ret');
        $nextBlock = $fn->appendBasicBlock('sc_cmp_next');
        $context->builder->branchIf($same, $nextBlock, $retBlock);

        $context->builder->positionAtEnd($retBlock);
        self::returnSign($context, $diff);

        $context->builder->positionAtEnd($nextBlock);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one64), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);

        return $zero32;
    }

    private static function loadByte(Context $context, Value $ptr): Value
    {
        return $context->builder->load($ptr);
    }

    private static function asciiLower(Context $context, Value $ch): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $isUpper = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(65, false)),
            $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(90, false))
        );

        return $context->builder->select(
            $isUpper,
            $context->builder->add($ch, $i8->constInt(32, false)),
            $ch
        );
    }

    private static function returnSign(Context $context, Value $diffI64): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $gt = $context->builder->icmp(Builder::INT_SGT, $diffI64, $zero);
        $lt = $context->builder->icmp(Builder::INT_SLT, $diffI64, $zero);
        $one = $i32->constInt(1, false);
        $negOne = $i32->constInt(-1, false);
        $zero32 = $i32->constInt(0, false);
        $context->builder->returnValue($context->builder->select($gt, $one, $context->builder->select($lt, $negOne, $zero32)));
    }
}
