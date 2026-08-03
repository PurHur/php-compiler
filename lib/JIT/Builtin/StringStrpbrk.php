<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for phpc_strpbrk_scan — length-bounded LLVM (#14791, #27055).
 *
 * ABI is intentionally not `phpc_strpbrk`: committed helper-runtime objects still
 * define the NestedJIT bridge under that name, and an early return would keep the
 * AOT-broken nullable {@see __string__*} path (silent false/NULL). Peer
 * {@see StringStrspn} / #27053 / {@see StringUtf8StrlenJit} / #27051.
 * Algorithm matches {@see \PHPCompiler\ext\standard\VmString::strpbrk}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(strpbrk)
 */
final class StringStrpbrk
{
    /** Fresh ABI — do not reuse `phpc_strpbrk` (stale NestedJIT helper-runtime). */
    private const ABI_STRPBRK = 'phpc_strpbrk_scan';

    private const ENTRY = 'strpbrk_scan_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function invoke(Context $context, Value $haystack, Value $mask): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_STRPBRK),
            $haystack,
            $mask
        );
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_STRPBRK);
        if (self::hasScanEntry($probe)) {
            $context->registerFunction(self::ABI_STRPBRK, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        LibcExtern::register($context);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
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
        if (null === $probe || $probe->countBasicBlocks() === 0) {
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
        $probe = $context->module->getNamedFunction(self::ABI_STRPBRK);
        if (null !== $probe) {
            $context->registerFunction(self::ABI_STRPBRK, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $strPtr);
        $fn = $context->module->addFunction(self::ABI_STRPBRK, $ft);
        $context->registerFunction(self::ABI_STRPBRK, $fn);
    }

    private static function emitBody(Context $context): void
    {
        $fn = $context->lookupFunction(self::ABI_STRPBRK);
        if (self::hasScanEntry($fn)) {
            return;
        }

        $haystack = $fn->getParam(0);
        $mask = $fn->getParam(1);

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $nullStr = $strPtr->constNull();

        $entry = $fn->appendBasicBlock(self::ENTRY);
        $context->builder->positionAtEnd($entry);

        $hayNull = $context->builder->icmp(Builder::INT_EQ, $haystack, $nullStr);
        $maskNull = $context->builder->icmp(Builder::INT_EQ, $mask, $nullStr);
        $eitherNull = $context->builder->or($hayNull, $maskNull);
        $nullBb = $fn->appendBasicBlock('strpbrk_scan_null');
        $workBb = $fn->appendBasicBlock('strpbrk_scan_work');
        $context->builder->branchIf($eitherNull, $nullBb, $workBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($workBb);
        $slen = $context->builder->call($context->lookupFunction('__string__strlen'), $haystack);
        $mlen = $context->builder->call($context->lookupFunction('__string__strlen'), $mask);
        $hayData = self::stringData($context, $haystack);
        $maskData = self::stringData($context, $mask);

        $maskNonEmpty = $context->builder->icmp(Builder::INT_NE, $mlen, $zero);
        TypeErrorRaise::emitBranchOrAbortOnValueErrorFailure(
            $context,
            $maskNonEmpty,
            'strpbrk_scan_mask',
            'strpbrk(): Argument #2 ($characters) must be a non-empty string'
        );

        $iSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $iSlot);

        $loopHead = $fn->appendBasicBlock('strpbrk_scan_loop_head');
        $loopBody = $fn->appendBasicBlock('strpbrk_scan_loop_body');
        $missBb = $fn->appendBasicBlock('strpbrk_scan_miss');
        $hitBb = $fn->appendBasicBlock('strpbrk_scan_hit');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $pastEnd = $context->builder->icmp(Builder::INT_SGE, $i, $slen);
        $context->builder->branchIf($pastEnd, $missBb, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $byte = $context->builder->load($context->builder->gep($hayData, $i));
        $inSet = self::emitByteInSet($context, $fn, $byte, $maskData, $mlen);
        $incBb = $fn->appendBasicBlock('strpbrk_scan_inc');
        $context->builder->branchIf($inSet, $hitBb, $incBb);

        $context->builder->positionAtEnd($incBb);
        $context->builder->store(
            $context->builder->add($context->builder->load($iSlot), $one),
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

    private static function emitByteInSet(
        Context $context,
        $fn,
        Value $byte,
        Value $maskData,
        Value $mlen
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $jSlot = $context->builder->alloca($i64, 1);
        $foundSlot = $context->builder->alloca($i1, 1);
        $context->builder->store($zero, $jSlot);
        $context->builder->store($i1->constInt(0, false), $foundSlot);

        $head = $fn->appendBasicBlock('strpbrk_scan_inset_head');
        $body = $fn->appendBasicBlock('strpbrk_scan_inset_body');
        $inc = $fn->appendBasicBlock('strpbrk_scan_inset_inc');
        $done = $fn->appendBasicBlock('strpbrk_scan_inset_done');
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

        $emptyBb = $fn->appendBasicBlock('strpbrk_scan_slice_empty');
        $copyBb = $fn->appendBasicBlock('strpbrk_scan_slice_copy');
        $doneBb = $fn->appendBasicBlock('strpbrk_scan_slice_done');
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
