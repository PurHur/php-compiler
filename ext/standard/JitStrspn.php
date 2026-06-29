<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM JIT/AOT for strspn()/strcspn() — mirrors VmString::strspn/strcspn (#7119).
 *
 * PHP 8.4 (GH-12592): empty mask — strspn returns 0, strcspn returns segment byte length.
 */
final class JitStrspn
{
    private const MASK_SCAN = '__jit_strspn_mask_scan';

    /**
     * @param list<JITVariable> $args
     */
    public static function extended(Context $context, array $args, bool $isStrspn, string $name): Value
    {
        $argc = \count($args);

        self::implementMaskScan($context);
        $map = $context->structFieldMap['__string__'];
        $strVal = JitStringBuiltinArg::lower($context, $args[0], $name, 0, 'string');
        $maskVal = JitStringBuiltinArg::lower($context, $args[1], $name, 1, 'characters');
        $strLen = $context->builder->load($context->builder->structGep($strVal, $map['length']));
        $maskLen = $context->builder->load($context->builder->structGep($maskVal, $map['length']));
        $strData = self::stringDataPtr($context, $strVal);
        $maskData = self::stringDataPtr($context, $maskVal);

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $offset = $argc >= 3
            ? JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[2], $name, 3, 'offset')
            : $i64->constInt(0, false);
        $length = 4 === $argc
            ? JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[3], $name, 4, 'length')
            : $i64->constInt(0, false);
        $lenIsNull = $i32->constInt(4 === $argc ? 0 : 1, false);
        $mode = $i32->constInt($isStrspn ? 1 : 0, false);
        $fn = $context->lookupFunction(self::MASK_SCAN);

        return $context->builder->call(
            $fn,
            $strData,
            $context->builder->truncOrBitCast($strLen, $sizeT),
            $maskData,
            $context->builder->truncOrBitCast($maskLen, $sizeT),
            $offset,
            $length,
            $lenIsNull,
            $mode
        );
    }

    /** Emit 2-arg strspn/strcspn bodies for internal callers (e.g. parse_str JIT). */
    public static function ensureTwoArgLinked(Context $context): void
    {
        self::implementTwoArg($context, 'strspn', true);
        self::implementTwoArg($context, 'strcspn', false);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implementMaskScan($context);
        self::implementTwoArg($context, 'strspn', true);
        self::implementTwoArg($context, 'strcspn', false);
    }

    private static function implementMaskScan(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::MASK_SCAN);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::MASK_SCAN, $probe);

            return;
        }

        $fn = self::declareMaskScanIfMissing($context);
        self::emitMaskScanBody($context, $fn);
        $context->registerFunction(self::MASK_SCAN, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementTwoArg(Context $context, string $name, bool $isStrspn): void
    {
        self::implementMaskScan($context);
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareTwoArgIfMissing($context, $name);
        self::emitTwoArgBody($context, $fn, $isStrspn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareMaskScanIfMissing(Context $context): LlvmFunction
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $ptr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $ft = $context->context->functionType(
            $i64,
            false,
            $ptr,
            $sizeT,
            $ptr,
            $sizeT,
            $i64,
            $i64,
            $i32,
            $i32
        );
        try {
            return $context->lookupFunction(self::MASK_SCAN);
        } catch (\Throwable) {
            $fn = $context->module->addFunction(self::MASK_SCAN, $ft);
            $context->registerFunction(self::MASK_SCAN, $fn);

            return $fn;
        }
    }

    private static function declareTwoArgIfMissing(Context $context, string $name): LlvmFunction
    {
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $ft = $context->context->functionType($sizeT, false, $i8p, $i8p);
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);

            return $fn;
        }
    }

    private static function emitTwoArgBody(Context $context, LlvmFunction $fn, bool $isStrspn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $str = $fn->getParam(0);
        $mask = $fn->getParam(1);
        $slen = $context->builder->call($context->lookupFunction('strlen'), $str);
        $mlen = $context->builder->call($context->lookupFunction('strlen'), $mask);
        $raw = $context->builder->call(
            $context->lookupFunction(self::MASK_SCAN),
            $str,
            $slen,
            $mask,
            $mlen,
            $i64->constInt(0, false),
            $i64->constInt(0, false),
            $i32->constInt(1, false),
            $i32->constInt($isStrspn ? 1 : 0, false)
        );

        $context->builder->returnValue($context->builder->truncOrBitCast($raw, $sizeT));
    }

    private static function emitMaskScanBody(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $nullPtr = $i8p->constNull();
        $zero64 = $i64->constInt(0, false);
        $one64 = $i64->constInt(1, false);
        $zero32 = $i32->constInt(0, false);
        $one32 = $i32->constInt(1, false);

        $emptySlot = $context->builder->alloca($i8, 1);
        $context->builder->store($i8->constInt(0, false), $emptySlot);
        $emptyPtr = $context->builder->pointerCast($emptySlot, $i8p);

        $strIn = $fn->getParam(0);
        $slenIn = $fn->getParam(1);
        $maskIn = $fn->getParam(2);
        $mlenIn = $fn->getParam(3);
        $startIn = $fn->getParam(4);
        $lenIn = $fn->getParam(5);
        $lenIsNullIn = $fn->getParam(6);
        $isStrspnIn = $fn->getParam(7);

        $strSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $maskSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $slenSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $mlenSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $strIsNull = $context->builder->icmp(Builder::INT_EQ, $strIn, $nullPtr);
        $maskIsNull = $context->builder->icmp(Builder::INT_EQ, $maskIn, $nullPtr);
        $context->builder->store($context->builder->select($strIsNull, $emptyPtr, $strIn), $strSlot);
        $context->builder->store($context->builder->select($maskIsNull, $emptyPtr, $maskIn), $maskSlot);
        $context->builder->store(
            $context->builder->select($strIsNull, $zero64, $context->builder->zExt($slenIn, $i64)),
            $slenSlot
        );
        $context->builder->store(
            $context->builder->select($maskIsNull, $zero64, $context->builder->zExt($mlenIn, $i64)),
            $mlenSlot
        );

        $strPtr = $context->builder->load($strSlot);
        $maskPtr = $context->builder->load($maskSlot);
        $slen = $context->builder->load($slenSlot);
        $mlen = $context->builder->load($mlenSlot);

        $startSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $lenSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($startIn, $startSlot);
        $context->builder->store($lenIn, $lenSlot);

        $normDone = $fn->appendBasicBlock('spn_norm_done');
        self::emitNormalize($context, $fn, $slen, $startSlot, $lenSlot, $lenIsNullIn, $normDone);

        $context->builder->positionAtEnd($normDone);
        $start = $context->builder->load($startSlot);
        $segLen = $context->builder->load($lenSlot);
        $lenLeZero = $context->builder->icmp(Builder::INT_SLE, $segLen, $zero64);
        $retZero = $fn->appendBasicBlock('spn_ret_zero');
        $afterZero = $fn->appendBasicBlock('spn_after_zero');
        $context->builder->branchIf($lenLeZero, $retZero, $afterZero);

        $context->builder->positionAtEnd($retZero);
        $context->builder->returnValue($zero64);

        $context->builder->positionAtEnd($afterZero);
        $mlenZero = $context->builder->icmp(Builder::INT_EQ, $mlen, $zero64);
        $emptyMask = $fn->appendBasicBlock('spn_empty_mask');
        $loopPrep = $fn->appendBasicBlock('spn_loop_prep');
        $context->builder->branchIf($mlenZero, $emptyMask, $loopPrep);

        $context->builder->positionAtEnd($emptyMask);
        $isStrspn = $context->builder->icmp(Builder::INT_NE, $isStrspnIn, $zero32);
        $context->builder->returnValue($context->builder->select($isStrspn, $zero64, $segLen));

        $context->builder->positionAtEnd($loopPrep);
        $countSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $iSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($zero64, $countSlot);
        $context->builder->store($start, $iSlot);

        $loopHead = $fn->appendBasicBlock('spn_loop_head');
        $loopDone = $fn->appendBasicBlock('spn_loop_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $end = $context->builder->add($start, $segLen);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $end);
        $loopBody = $fn->appendBasicBlock('spn_loop_body');
        $context->builder->branchIf($atEnd, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $off32 = $context->builder->trunc($i, $i32);
        $ch = $context->builder->load($context->builder->gep($strPtr, $off32));
        $inSet = self::emitByteInSet($context, $fn, $ch, $maskPtr, $mlen);
        $isStrspn = $context->builder->icmp(Builder::INT_NE, $isStrspnIn, $zero32);
        $strspnBreak = $context->builder->and($isStrspn, $context->builder->not($inSet));
        $strcspnBreak = $context->builder->and(
            $context->builder->not($isStrspn),
            $inSet
        );
        $shouldBreak = $context->builder->or($strspnBreak, $strcspnBreak);
        $breakBlock = $fn->appendBasicBlock('spn_break');
        $incBlock = $fn->appendBasicBlock('spn_inc');
        $context->builder->branchIf($shouldBreak, $breakBlock, $incBlock);

        $context->builder->positionAtEnd($incBlock);
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->load($countSlot), $one64),
            $countSlot
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->load($iSlot), $one64),
            $iSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($breakBlock);
        $context->builder->branch($loopDone);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->returnValue($context->builder->load($countSlot));
    }

    private static function emitNormalize(
        Context $context,
        LlvmFunction $fn,
        Value $slen,
        Value $startSlot,
        Value $lenSlot,
        Value $lenIsNullIn,
        BasicBlock $done
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $zero64 = $i64->constInt(0, false);

        $remainSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($slen, $remainSlot);

        $start = $context->builder->load($startSlot);
        $negStart = $fn->appendBasicBlock('spn_neg_start');
        $startClamp = $fn->appendBasicBlock('spn_start_clamp');
        $startReady = $fn->appendBasicBlock('spn_start_ready');
        $isNeg = $context->builder->icmp(Builder::INT_SLT, $start, $zero64);
        $context->builder->branchIf($isNeg, $negStart, $startClamp);

        $context->builder->positionAtEnd($negStart);
        $adj = $context->builder->add($start, $slen);
        $clamped = $context->builder->select(
            $context->builder->icmp(Builder::INT_SLT, $adj, $zero64),
            $zero64,
            $adj
        );
        $context->builder->store($clamped, $startSlot);
        $context->builder->branch($startReady);

        $context->builder->positionAtEnd($startClamp);
        $tooFar = $context->builder->icmp(Builder::INT_UGT, $start, $slen);
        $context->builder->store($context->builder->select($tooFar, $slen, $start), $startSlot);
        $context->builder->branch($startReady);

        $context->builder->positionAtEnd($startReady);
        $startVal = $context->builder->load($startSlot);
        $remain = $context->builder->sub($slen, $startVal);
        $context->builder->store($remain, $remainSlot);

        $lenIsNull = $context->builder->icmp(Builder::INT_NE, $lenIsNullIn, $i32->constInt(0, false));
        $lenOmitted = $fn->appendBasicBlock('spn_len_omitted');
        $lenGiven = $fn->appendBasicBlock('spn_len_given');
        $context->builder->branchIf($lenIsNull, $lenOmitted, $lenGiven);

        $context->builder->positionAtEnd($lenOmitted);
        $context->builder->store($remain, $lenSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($lenGiven);
        $len = $context->builder->load($lenSlot);
        $negLen = $fn->appendBasicBlock('spn_neg_len');
        $lenClamp = $fn->appendBasicBlock('spn_len_clamp');
        $lenIsNeg = $context->builder->icmp(Builder::INT_SLT, $len, $zero64);
        $context->builder->branchIf($lenIsNeg, $negLen, $lenClamp);

        $context->builder->positionAtEnd($negLen);
        $adjLen = $context->builder->add($len, $remain);
        $clampedLen = $context->builder->select(
            $context->builder->icmp(Builder::INT_SLT, $adjLen, $zero64),
            $zero64,
            $adjLen
        );
        $context->builder->store($clampedLen, $lenSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($lenClamp);
        $remainVal = $context->builder->load($remainSlot);
        $tooLong = $context->builder->icmp(Builder::INT_UGT, $len, $remainVal);
        $context->builder->store($context->builder->select($tooLong, $remainVal, $len), $lenSlot);
        $context->builder->branch($done);
    }

    private static function emitByteInSet(
        Context $context,
        LlvmFunction $fn,
        Value $ch,
        Value $maskPtr,
        Value $mlen
    ): Value {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $zero64 = $i64->constInt(0, false);
        $one64 = $i64->constInt(1, false);
        $zero32 = $i32->constInt(0, false);
        $one32 = $i32->constInt(1, false);

        $foundSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $jSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($zero32, $foundSlot);
        $context->builder->store($zero64, $jSlot);

        $jHead = $fn->appendBasicBlock('spn_set_head');
        $jDone = $fn->appendBasicBlock('spn_set_done');
        $context->builder->branch($jHead);

        $context->builder->positionAtEnd($jHead);
        $j = $context->builder->load($jSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $j, $mlen);
        $jBody = $fn->appendBasicBlock('spn_set_body');
        $context->builder->branchIf($atEnd, $jDone, $jBody);

        $context->builder->positionAtEnd($jBody);
        $off32 = $context->builder->trunc($j, $i32);
        $maskCh = $context->builder->load($context->builder->gep($maskPtr, $off32));
        $match = $context->builder->icmp(Builder::INT_EQ, $ch, $maskCh);
        $found = $context->builder->load($foundSlot);
        $context->builder->store(
            $context->builder->select($match, $one32, $found),
            $foundSlot
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap($j, $one64),
            $jSlot
        );
        $context->builder->branch($jHead);

        $context->builder->positionAtEnd($jDone);
        $found = $context->builder->load($foundSlot);

        return $context->builder->icmp(Builder::INT_NE, $found, $zero32);
    }

    private static function stringDataPtr(Context $context, Value $strPtr): Value
    {
        $off = $context->structFieldIndex($strPtr, 'value');

        return $context->builder->structGep($strPtr, $off);
    }
}
