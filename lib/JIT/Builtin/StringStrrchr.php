<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for phpc_strrchr_scan — reverse scan + slice (#15406, #27951).
 *
 * Fresh ABI avoids stale NestedJIT {@see \PHPCompiler\ext\standard\StrrchrJitHelper}
 * bridges that mis-materialize under thin AOT (peer {@see StringStrpbrk} / #27055).
 *
 * SSOT: {@see \PHPCompiler\ext\standard\VmString::strrchr()}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(strrchr)
 */
final class StringStrrchr
{
    /** Fresh ABI — do not reuse `__phpc_jit_strrchr` (stale NestedJIT helper-runtime). */
    private const ABI = 'phpc_strrchr_scan';

    private const ENTRY = 'strrchr_scan_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function invoke(Context $context, Value $haystack, Value $needle): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $haystack,
            $needle
        );
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (self::hasScanEntry($probe)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::declareAbi($context);
        self::emitBody($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function hasScanEntry(?\PHPLLVM\Value\Function_ $probe): bool
    {
        if (null === $probe || 0 === $probe->countBasicBlocks()) {
            return false;
        }
        try {
            foreach ($probe->getBasicBlocks() as $block) {
                if ($block->getName() === self::ENTRY && null !== $block->getTerminator()) {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }

    private static function declareAbi(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $strPtr);
        $fn = $context->module->addFunction(self::ABI, $ft);
        $context->registerFunction(self::ABI, $fn);
    }

    private static function emitBody(Context $context): void
    {
        $fn = $context->lookupFunction(self::ABI);
        if (self::hasScanEntry($fn)) {
            return;
        }

        $haystack = $fn->getParam(0);
        $needle = $fn->getParam(1);

        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $strPtr = $context->getTypeFromString('__string__*');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $nullStr = $strPtr->constNull();

        $entry = $fn->appendBasicBlock(self::ENTRY);
        $context->builder->positionAtEnd($entry);

        $hayNull = $context->builder->icmp(Builder::INT_EQ, $haystack, $nullStr);
        $needleNull = $context->builder->icmp(Builder::INT_EQ, $needle, $nullStr);
        $eitherNull = $context->builder->or($hayNull, $needleNull);
        $nullBb = $fn->appendBasicBlock('strrchr_scan_null');
        $workBb = $fn->appendBasicBlock('strrchr_scan_work');
        $context->builder->branchIf($eitherNull, $nullBb, $workBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($workBb);
        $slen = $context->builder->call($context->lookupFunction('__string__strlen'), $haystack);
        $nlen = $context->builder->call($context->lookupFunction('__string__strlen'), $needle);
        $needleEmpty = $context->builder->icmp(Builder::INT_EQ, $nlen, $zero);
        $emptyBb = $fn->appendBasicBlock('strrchr_scan_empty_needle');
        $scanBb = $fn->appendBasicBlock('strrchr_scan_scan');
        $context->builder->branchIf($needleEmpty, $emptyBb, $scanBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($scanBb);
        $needleData = self::stringData($context, $needle);
        $needleByte = $context->builder->load($context->builder->gep($needleData, $zero));
        $hayData = self::stringData($context, $haystack);

        $iSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($context->builder->sub($slen, $one), $iSlot);

        $loopHead = $fn->appendBasicBlock('strrchr_scan_loop_head');
        $loopBody = $fn->appendBasicBlock('strrchr_scan_loop_body');
        $missBb = $fn->appendBasicBlock('strrchr_scan_miss');
        $hitBb = $fn->appendBasicBlock('strrchr_scan_hit');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $beforeZero = $context->builder->icmp(Builder::INT_SLT, $i, $zero);
        $context->builder->branchIf($beforeZero, $missBb, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $byte = $context->builder->load($context->builder->gep($hayData, $i));
        $matches = $context->builder->icmp(Builder::INT_EQ, $byte, $needleByte);
        $decBb = $fn->appendBasicBlock('strrchr_scan_dec');
        $context->builder->branchIf($matches, $hitBb, $decBb);

        $context->builder->positionAtEnd($decBb);
        $context->builder->store(
            $context->builder->sub($context->builder->load($iSlot), $one),
            $iSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($missBb);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($hitBb);
        $pos = $context->builder->load($iSlot);
        $lenAfter = $context->builder->sub($slen, $pos);
        $slice = self::emitCopySlice($context, $fn, $hayData, $pos, $lenAfter);
        $context->builder->returnValue($slice);
    }

    private static function emitCopySlice(
        Context $context,
        $fn,
        Value $srcData,
        Value $start,
        Value $sliceLen
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_SLE, $sliceLen, $zero);

        $emptyBb = $fn->appendBasicBlock('strrchr_scan_slice_empty');
        $copyBb = $fn->appendBasicBlock('strrchr_scan_slice_copy');
        $doneBb = $fn->appendBasicBlock('strrchr_scan_slice_done');
        $context->builder->branchIf($isEmpty, $emptyBb, $copyBb);

        $context->builder->positionAtEnd($emptyBb);
        $emptyStr = $context->builder->call($context->lookupFunction('__string__alloc'), $zero);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($copyBb);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $sliceLen);
        $destMap = $context->structFieldMap['__string__'];
        $context->builder->store(
            $sliceLen,
            $context->builder->structGep($dest, $destMap['length'])
        );
        $srcAt = $context->builder->gep($srcData, $start);
        $destAt = $context->builder->pointerCast(
            $context->builder->structGep($dest, $destMap['value']),
            $context->getTypeFromString('int8*')
        );
        $context->intrinsic->memcpy($destAt, $srcAt, $sliceLen, false);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $result = $context->builder->phi($dest->typeOf());
        $result->addIncoming($emptyStr, $emptyBb);
        $result->addIncoming($dest, $copyBb);

        return $result;
    }

    private static function stringData(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($str, $map['value']),
            $context->getTypeFromString('int8*')
        );
    }
}
