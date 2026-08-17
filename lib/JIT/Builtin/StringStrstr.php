<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\VM\VmStringCompare;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT lowering for strstr()/stristr() (#14778, #27185).
 *
 * Search via {@see VmStringCompare::findOffset} (memcmp IR) + haystack slice —
 * NestedJIT {@see \PHPCompiler\ext\standard\StrstrJitHelper} mis-materializes
 * nullable {@see __string__*} under thin AOT (silent false). Fresh scan ABI avoids
 * stale helper-runtime `phpc_strstr` NestedJIT bridges (peer {@see StringStrpbrk} / #27055).
 *
 * SSOT: {@see \PHPCompiler\ext\standard\VmString} (compile-time fold + VM).
 * php-src: ext/standard/string.c — PHP_FUNCTION(strstr), PHP_FUNCTION(stristr)
 */
final class StringStrstr
{
    /** Fresh ABI — do not reuse `phpc_strstr` (stale NestedJIT helper-runtime). */
    private const ABI_STRSTR = 'phpc_strstr_scan';

    /** Fresh ABI — do not reuse `phpc_stristr` (stale NestedJIT helper-runtime). */
    private const ABI_STRISTR = 'phpc_stristr_scan';

    private const ENTRY_STRSTR = 'strstr_scan_entry';

    private const ENTRY_STRISTR = 'stristr_scan_entry';

    private static int $sliceSerial = 0;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context, false);
        self::implement($context, true);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(
        Context $context,
        Value $haystack,
        Value $needle,
        Value $beforeNeedle,
        bool $caseInsensitive = false
    ): Value {
        self::ensureLinked($context);
        $abi = $caseInsensitive ? self::ABI_STRISTR : self::ABI_STRSTR;

        return $context->builder->call(
            $context->lookupFunction($abi),
            $haystack,
            $needle,
            $beforeNeedle
        );
    }

    private static function implement(Context $context, bool $caseInsensitive): void
    {
        $abi = $caseInsensitive ? self::ABI_STRISTR : self::ABI_STRSTR;
        $entryName = $caseInsensitive ? self::ENTRY_STRISTR : self::ENTRY_STRSTR;
        $probe = $context->module->getNamedFunction($abi);
        if (self::hasScanEntry($probe, $entryName)) {
            $context->registerFunction($abi, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureMemcmp($context);
        self::declareAbi($context, $abi);
        self::emitBody($context, $abi, $entryName, $caseInsensitive);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function hasScanEntry(?\PHPLLVM\Value\Function_ $probe, string $entryName): bool
    {
        if (null === $probe || 0 === $probe->countBasicBlocks()) {
            return false;
        }
        try {
            foreach ($probe->getBasicBlocks() as $block) {
                if ($block->getName() === $entryName && null !== $block->getTerminator()) {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }

    private static function declareAbi(Context $context, string $abi): void
    {
        $probe = $context->module->getNamedFunction($abi);
        if (null !== $probe) {
            $context->registerFunction($abi, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $strPtr, $i8);
        $fn = $context->module->addFunction($abi, $ft);
        $context->registerFunction($abi, $fn);
    }

    private static function emitBody(
        Context $context,
        string $abi,
        string $entryName,
        bool $caseInsensitive
    ): void {
        $fn = $context->lookupFunction($abi);
        if (self::hasScanEntry($fn, $entryName)) {
            return;
        }

        $haystack = $fn->getParam(0);
        $needle = $fn->getParam(1);
        $beforeNeedle = $fn->getParam(2);

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $zero = $i64->constInt(0, false);
        $nullStr = $strPtr->constNull();
        $notFound = $i64->constInt(-1, true);

        $entry = $fn->appendBasicBlock($entryName);
        $context->builder->positionAtEnd($entry);

        $hayNull = $context->builder->icmp(Builder::INT_EQ, $haystack, $nullStr);
        $needleNull = $context->builder->icmp(Builder::INT_EQ, $needle, $nullStr);
        $eitherNull = $context->builder->or($hayNull, $needleNull);
        $nullBb = $fn->appendBasicBlock($abi.'_null');
        $workBb = $fn->appendBasicBlock($abi.'_work');
        $context->builder->branchIf($eitherNull, $nullBb, $workBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($workBb);
        $found = VmStringCompare::findOffset(
            $context,
            $haystack,
            $needle,
            $zero,
            $caseInsensitive
        );
        $isMiss = $context->builder->icmp(Builder::INT_EQ, $found, $notFound);
        $missBb = $fn->appendBasicBlock($abi.'_miss');
        $hitBb = $fn->appendBasicBlock($abi.'_hit');
        $context->builder->branchIf($isMiss, $missBb, $hitBb);

        $context->builder->positionAtEnd($missBb);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($hitBb);
        $map = $context->structFieldMap['__string__'];
        $hayLen = $context->builder->load(
            $context->builder->structGep($haystack, $map['length'])
        );
        $hayData = $context->builder->pointerCast(
            $context->builder->structGep($haystack, $map['value']),
            $context->getTypeFromString('int8*')
        );
        $wantBefore = $context->builder->icmp(
            Builder::INT_NE,
            $beforeNeedle,
            $context->getTypeFromString('int8')->constInt(0, false)
        );
        $beforeBb = $fn->appendBasicBlock($abi.'_before');
        $afterBb = $fn->appendBasicBlock($abi.'_after');
        $context->builder->branchIf($wantBefore, $beforeBb, $afterBb);

        $context->builder->positionAtEnd($beforeBb);
        $beforeSlice = self::emitCopySlice($context, $fn, $hayData, $zero, $found, $abi.'_b');
        $context->builder->returnValue($beforeSlice);

        $context->builder->positionAtEnd($afterBb);
        $afterLen = $context->builder->sub($hayLen, $found);
        $afterSlice = self::emitCopySlice($context, $fn, $hayData, $found, $afterLen, $abi.'_a');
        $context->builder->returnValue($afterSlice);
    }

    private static function emitCopySlice(
        Context $context,
        $fn,
        Value $srcData,
        Value $start,
        Value $sliceLen,
        string $id
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $suffix = $id.'_'.(string) (++self::$sliceSerial);
        $isEmpty = $context->builder->icmp(Builder::INT_SLE, $sliceLen, $zero);

        $emptyBb = $fn->appendBasicBlock('strstr_scan_slice_empty_'.$suffix);
        $copyBb = $fn->appendBasicBlock('strstr_scan_slice_copy_'.$suffix);
        $doneBb = $fn->appendBasicBlock('strstr_scan_slice_done_'.$suffix);
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

    private static function ensureMemcmp(Context $context): void
    {
        // memcmp(3) via LibcExtern::ensureMemcmpDecl after always-on drop (#31954).
        LibcExtern::ensureMemcmpDecl($context);
    }
}
