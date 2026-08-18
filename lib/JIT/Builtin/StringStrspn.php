<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for strspn()/strcspn() — length-bounded LLVM (#14700, #27053, #27054).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\StrspnJitHelper} mis-reads {@see __string__*}
 * under thin AOT (silent 0) — peer {@see StringUtf8StrlenJit} / #27051.
 * Algorithm matches {@see \PHPCompiler\ext\standard\VmString::strspn} / {@see VmString::strcspn}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(strspn), PHP_FUNCTION(strcspn)
 */
final class StringStrspn
{
    /**
     * LLVM ABI names must not collide with libc — AOT exports of `strspn`/`strcspn`
     * interpose into libxcrypt and make crypt(3) return `*0` (#26861).
     *
     * @var list<string>
     */
    private const ABI_FUNCTIONS = [
        'phpc_strspn_extended',
        '__compiler_strspn',
        '__compiler_strcspn',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('phpc_strspn_extended');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        LibcExtern::register($context);
        self::declareExtended($context);
        self::emitExtendedBody($context);
        self::implementTwoArgBridge($context, '__compiler_strspn', true);
        self::implementTwoArgBridge($context, '__compiler_strcspn', false);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function declareExtended(Context $context): void
    {
        $abiName = 'phpc_strspn_extended';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType(
            $i64,
            false,
            $strPtr,
            $strPtr,
            $i64,
            $i64,
            $i32,
            $i32
        );
        $fn = $context->module->addFunction($abiName, $ft);
        $context->registerFunction($abiName, $fn);
    }

    private static function emitExtendedBody(Context $context): void
    {
        $fn = $context->lookupFunction('phpc_strspn_extended');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        $entry = $fn->appendBasicBlock('strspn_ext_entry');
        $context->builder->positionAtEnd($entry);

        $str = $fn->getParam(0);
        $mask = $fn->getParam(1);
        $offset = $fn->getParam(2);
        $lengthArg = $fn->getParam(3);
        $lenIsNull = $fn->getParam(4);
        $isStrspn = $fn->getParam(5);

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $strPtr = $context->getTypeFromString('__string__*');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $nullStr = $strPtr->constNull();

        $strNull = $context->builder->icmp(Builder::INT_EQ, $str, $nullStr);
        $maskNull = $context->builder->icmp(Builder::INT_EQ, $mask, $nullStr);
        $eitherNull = $context->builder->or($strNull, $maskNull);
        $nullBb = $fn->appendBasicBlock('strspn_ext_null');
        $workBb = $fn->appendBasicBlock('strspn_ext_work');
        $context->builder->branchIf($eitherNull, $nullBb, $workBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnValue($zero);

        $context->builder->positionAtEnd($workBb);
        $slen = $context->builder->call($context->lookupFunction('__string__strlen'), $str);
        $mlen = $context->builder->call($context->lookupFunction('__string__strlen'), $mask);
        $strData = self::stringData($context, $str);
        $maskData = self::stringData($context, $mask);

        // normalizeSpnBounds (VmString) — start
        $startSlot = $context->builder->alloca($i64, 1);
        $lenSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($offset, $startSlot);

        $negOffBb = $fn->appendBasicBlock('strspn_neg_off');
        $posOffBb = $fn->appendBasicBlock('strspn_pos_off');
        $offDoneBb = $fn->appendBasicBlock('strspn_off_done');
        $offNeg = $context->builder->icmp(Builder::INT_SLT, $offset, $zero);
        $context->builder->branchIf($offNeg, $negOffBb, $posOffBb);

        $context->builder->positionAtEnd($negOffBb);
        $adj = $context->builder->add($offset, $slen);
        $adjClamped = $context->builder->select(
            $context->builder->icmp(Builder::INT_SLT, $adj, $zero),
            $zero,
            $adj
        );
        $context->builder->store($adjClamped, $startSlot);
        $context->builder->branch($offDoneBb);

        $context->builder->positionAtEnd($posOffBb);
        $tooFar = $context->builder->icmp(Builder::INT_SGT, $offset, $slen);
        $startPos = $context->builder->select($tooFar, $slen, $offset);
        $context->builder->store($startPos, $startSlot);
        $context->builder->branch($offDoneBb);

        $context->builder->positionAtEnd($offDoneBb);
        $start = $context->builder->load($startSlot);
        $remain = $context->builder->sub($slen, $start);

        $lenNullI1 = $context->builder->icmp(
            Builder::INT_NE,
            $lenIsNull,
            $i32->constInt(0, false)
        );
        $lenNullBb = $fn->appendBasicBlock('strspn_len_null');
        $lenSetBb = $fn->appendBasicBlock('strspn_len_set');
        $lenDoneBb = $fn->appendBasicBlock('strspn_len_done');
        $context->builder->branchIf($lenNullI1, $lenNullBb, $lenSetBb);

        $context->builder->positionAtEnd($lenNullBb);
        $context->builder->store($remain, $lenSlot);
        $context->builder->branch($lenDoneBb);

        $context->builder->positionAtEnd($lenSetBb);
        $lenNeg = $context->builder->icmp(Builder::INT_SLT, $lengthArg, $zero);
        $lenNegBb = $fn->appendBasicBlock('strspn_len_neg');
        $lenPosBb = $fn->appendBasicBlock('strspn_len_pos');
        $context->builder->branchIf($lenNeg, $lenNegBb, $lenPosBb);

        $context->builder->positionAtEnd($lenNegBb);
        $lenAdj = $context->builder->add($lengthArg, $remain);
        $lenAdjClamped = $context->builder->select(
            $context->builder->icmp(Builder::INT_SLT, $lenAdj, $zero),
            $zero,
            $lenAdj
        );
        $context->builder->store($lenAdjClamped, $lenSlot);
        $context->builder->branch($lenDoneBb);

        $context->builder->positionAtEnd($lenPosBb);
        $lenTooFar = $context->builder->icmp(Builder::INT_SGT, $lengthArg, $remain);
        $lenPos = $context->builder->select($lenTooFar, $remain, $lengthArg);
        $context->builder->store($lenPos, $lenSlot);
        $context->builder->branch($lenDoneBb);

        $context->builder->positionAtEnd($lenDoneBb);
        $segLen = $context->builder->load($lenSlot);

        $emptySeg = $context->builder->icmp(Builder::INT_EQ, $segLen, $zero);
        $emptySegBb = $fn->appendBasicBlock('strspn_empty_seg');
        $afterSegBb = $fn->appendBasicBlock('strspn_after_seg');
        $context->builder->branchIf($emptySeg, $emptySegBb, $afterSegBb);

        $context->builder->positionAtEnd($emptySegBb);
        $context->builder->returnValue($zero);

        $context->builder->positionAtEnd($afterSegBb);
        $isSpnI1 = $context->builder->icmp(
            Builder::INT_NE,
            $isStrspn,
            $i32->constInt(0, false)
        );
        $maskEmpty = $context->builder->icmp(Builder::INT_EQ, $mlen, $zero);
        $spnEmptyMaskBb = $fn->appendBasicBlock('strspn_spn_empty_mask');
        $cspnEmptyMaskBb = $fn->appendBasicBlock('strspn_cspn_empty_mask');
        $loopSetupBb = $fn->appendBasicBlock('strspn_loop_setup');
        $maskEmptySpn = $context->builder->and($isSpnI1, $maskEmpty);
        $maskEmptyCspn = $context->builder->and(
            $context->builder->xor($isSpnI1, $context->getTypeFromString('int1')->constInt(1, false)),
            $maskEmpty
        );
        // Prefer empty-mask early outs before the scan loop.
        $afterSpnEmpty = $fn->appendBasicBlock('strspn_after_spn_empty');
        $context->builder->branchIf($maskEmptySpn, $spnEmptyMaskBb, $afterSpnEmpty);

        $context->builder->positionAtEnd($spnEmptyMaskBb);
        $context->builder->returnValue($zero);

        $context->builder->positionAtEnd($afterSpnEmpty);
        $context->builder->branchIf($maskEmptyCspn, $cspnEmptyMaskBb, $loopSetupBb);

        $context->builder->positionAtEnd($cspnEmptyMaskBb);
        // Unset PROFILE / PROFILE≥8.4 (GH-12592, #7088): empty mask → full segment length.
        // Explicit PROFILE&lt;8.4 (#27716): stop at first embedded NUL like Zend 8.2.
        if (!\PHPCompiler\ext\standard\VmString::strcspnEmptyMaskStopsAtNul()) {
            $context->builder->returnValue($segLen);
        } else {
            $nulCountSlot = $context->builder->alloca($i64, 1, 'cspn_empty_nul_cnt');
            $nulIdxSlot = $context->builder->alloca($i64, 1, 'cspn_empty_nul_i');
            $context->builder->store($zero, $nulCountSlot);
            $context->builder->store($start, $nulIdxSlot);
            $nulEnd = $context->builder->add($start, $segLen);
            $nulHead = $fn->appendBasicBlock('cspn_empty_nul_head');
            $nulBody = $fn->appendBasicBlock('cspn_empty_nul_body');
            $nulInc = $fn->appendBasicBlock('cspn_empty_nul_inc');
            $nulDone = $fn->appendBasicBlock('cspn_empty_nul_done');
            $context->builder->branch($nulHead);

            $context->builder->positionAtEnd($nulHead);
            $nulI = $context->builder->load($nulIdxSlot);
            $nulAtEnd = $context->builder->icmp(Builder::INT_SGE, $nulI, $nulEnd);
            $context->builder->branchIf($nulAtEnd, $nulDone, $nulBody);

            $context->builder->positionAtEnd($nulBody);
            $nulByte = $context->builder->load($context->builder->gep($strData, $nulI));
            $isNul = $context->builder->icmp(
                Builder::INT_EQ,
                $nulByte,
                $i8->constInt(0, false)
            );
            $context->builder->branchIf($isNul, $nulDone, $nulInc);

            $context->builder->positionAtEnd($nulInc);
            $context->builder->store(
                $context->builder->add($context->builder->load($nulCountSlot), $one),
                $nulCountSlot
            );
            $context->builder->store(
                $context->builder->add($nulI, $one),
                $nulIdxSlot
            );
            $context->builder->branch($nulHead);

            $context->builder->positionAtEnd($nulDone);
            $context->builder->returnValue($context->builder->load($nulCountSlot));
        }

        $context->builder->positionAtEnd($loopSetupBb);
        $iSlot = $context->builder->alloca($i64, 1);
        $countSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($start, $iSlot);
        $context->builder->store($zero, $countSlot);
        $end = $context->builder->add($start, $segLen);

        $head = $fn->appendBasicBlock('strspn_head');
        $body = $fn->appendBasicBlock('strspn_body');
        $inc = $fn->appendBasicBlock('strspn_inc');
        $done = $fn->appendBasicBlock('strspn_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $end);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $b = $context->builder->load($context->builder->gep($strData, $i));
        $inSet = self::emitByteInSet($context, $fn, $b, $maskData, $mlen, $i8, $i64);
        $breakSpn = $context->builder->and(
            $isSpnI1,
            $context->builder->xor($inSet, $context->getTypeFromString('int1')->constInt(1, false))
        );
        $breakCspn = $context->builder->and(
            $context->builder->xor($isSpnI1, $context->getTypeFromString('int1')->constInt(1, false)),
            $inSet
        );
        $shouldBreak = $context->builder->or($breakSpn, $breakCspn);
        $context->builder->branchIf($shouldBreak, $done, $inc);

        $context->builder->positionAtEnd($inc);
        $count = $context->builder->load($countSlot);
        $context->builder->store($context->builder->add($count, $one), $countSlot);
        $context->builder->store($context->builder->add($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($context->builder->load($countSlot));
    }

    private static function emitByteInSet(
        Context $context,
        LlvmFunction $fn,
        Value $byte,
        Value $maskData,
        Value $mlen,
        $i8,
        $i64
    ): Value {
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $jSlot = $context->builder->alloca($i64, 1);
        $foundSlot = $context->builder->alloca($context->getTypeFromString('int1'), 1);
        $context->builder->store($zero, $jSlot);
        $context->builder->store($context->getTypeFromString('int1')->constInt(0, false), $foundSlot);

        $head = $fn->appendBasicBlock('strspn_inset_head');
        $body = $fn->appendBasicBlock('strspn_inset_body');
        $inc = $fn->appendBasicBlock('strspn_inset_inc');
        $done = $fn->appendBasicBlock('strspn_inset_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $j = $context->builder->load($jSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $j, $mlen);
        $already = $context->builder->load($foundSlot);
        $stop = $context->builder->or($atEnd, $already);
        $context->builder->branchIf($stop, $done, $body);

        $context->builder->positionAtEnd($body);
        $mb = $context->builder->load($context->builder->gep($maskData, $j));
        $eq = $context->builder->icmp(Builder::INT_EQ, $byte, $mb);
        $prev = $context->builder->load($foundSlot);
        $context->builder->store($context->builder->or($prev, $eq), $foundSlot);
        $context->builder->branch($inc);

        $context->builder->positionAtEnd($inc);
        $context->builder->store(
            $context->builder->add($context->builder->load($jSlot), $one),
            $jSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($foundSlot);
    }

    private static function implementTwoArgBridge(Context $context, string $abiName, bool $isStrspn): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($sizeT, false, $charPtr, $charPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock($abiName.'_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $str = self::cstrToString($context, $fn->getParam(0));
        $mask = self::cstrToString($context, $fn->getParam(1));
        $raw = $context->builder->call(
            $context->lookupFunction('phpc_strspn_extended'),
            $str,
            $mask,
            $i64->constInt(0, false),
            $i64->constInt(0, false),
            $i32->constInt(1, false),
            $i32->constInt($isStrspn ? 1 : 0, false)
        );
        $context->builder->returnValue($context->builder->truncOrBitCast($raw, $sizeT));
        $context->registerFunction($abiName, $fn);
    }

    private static function cstrToString(Context $context, Value $cstr): Value
    {
        $charPtr = $context->getTypeFromString('char*');
        $i64 = $context->getTypeFromString('int64');
        $empty = $charPtr->constNull();
        $use = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $cstr, $empty),
            $empty,
            $cstr
        );
        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
        $len = $context->builder->call($context->lookupFunction('strlen'), $use);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $use
        );
    }

    private static function stringData(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($str, $map['value']),
            $context->getTypeFromString('int8*')
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringStrspn bridge (#27053)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
