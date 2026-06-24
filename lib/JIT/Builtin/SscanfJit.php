<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM __compiler_sscanf* (mirrors VmSscanf / former superglobals_refresh.c sscanf; #7330).
 */
final class SscanfJit
{
    private const STRING_BUF_SIZE = 4096;

    private const FLOAT_TMP_SIZE = 64;

    public static function implement(Context $context): void
    {
        $restore = self::captureInsertBlock($context);
        self::ensureLibc($context);
        self::ensureRuntimeHelpers($context);

        $probe = $context->module->getNamedFunction('__compiler_sscanf');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_sscanf', $probe);
            foreach ([
                '__compiler_sscanf_ex',
                '__compiler_sscanf_array',
                '__compiler_vfscanf',
            ] as $linkedName) {
                $linked = $context->module->getNamedFunction($linkedName);
                if (null !== $linked) {
                    $context->registerFunction($linkedName, $linked);
                }
            }
            self::restoreInsertBlock($context, $restore);

            return;
        }

        self::implementIfMissing($context, '__phpc_sscanf_is_space', self::emitIsSpace(...));
        self::implementIfMissing($context, '__phpc_sscanf_scan_int', self::emitScanInt(...));
        self::implementIfMissing($context, '__phpc_sscanf_scan_string', self::emitScanString(...));
        self::implementIfMissing($context, '__phpc_sscanf_scan_float', self::emitScanFloat(...));
        self::implementIfMissing($context, '__phpc_sscanf_count_specs', self::emitCountConversionSpecs(...));
        self::implementIfMissing($context, '__compiler_sscanf', self::emitCompilerSscanf(...));
        self::implementIfMissing($context, '__compiler_sscanf_ex', self::emitCompilerSscanfEx(...));
        self::implementIfMissing($context, '__compiler_sscanf_array', self::emitCompilerSscanfArray(...));
        self::implementIfMissing($context, '__compiler_vfscanf', self::emitCompilerVfscanf(...));

        self::restoreInsertBlock($context, $restore);
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        try {
            $fn = $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = self::declareFunction($context, $name);
            $context->registerFunction($name, $fn);
        }

        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $valuePtrPtr = $context->getTypeFromString('__value__**');
        $void = $context->getTypeFromString('void');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $dbl = $context->getTypeFromString('double');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $sizeTp = $sizeT->pointerType(0);
        $i64p = $i64->pointerType(0);
        $dblp = $dbl->pointerType(0);

        return match ($name) {
            '__phpc_sscanf_is_space' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8)
            ),
            '__phpc_sscanf_scan_int' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8p, $sizeT, $sizeTp, $i64p)
            ),
            '__phpc_sscanf_scan_string' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8p, $sizeT, $sizeTp, $i8p, $sizeT)
            ),
            '__phpc_sscanf_scan_float' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8p, $sizeT, $sizeTp, $dblp)
            ),
            '__phpc_sscanf_count_specs' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $i8p, $sizeT)
            ),
            '__compiler_sscanf' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $strPtr, $strPtr, $i64, $valuePtrPtr)
            ),
            '__compiler_sscanf_ex' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $strPtr, $strPtr, $i64, $valuePtrPtr, $sizeTp)
            ),
            '__compiler_vfscanf' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $i64, $strPtr, $i64, $valuePtrPtr)
            ),
            '__compiler_sscanf_array' => $context->module->addFunction(
                $name,
                $context->context->functionType($context->getTypeFromString('__value__*'), false, $strPtr, $strPtr)
            ),
            default => throw new \LogicException('Unknown sscanf JIT helper: '.$name),
        };
    }

    private static function ensureLibc(Context $context): void
    {
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $i8p->pointerType(0);
        $dbl = $context->getTypeFromString('double');

        foreach ([
            ['memcpy', $voidPtr, [$voidPtr, $voidPtr, $sizeT]],
            ['strlen', $sizeT, [$i8p]],
            ['strtod', $dbl, [$i8p, $i8pp]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $void = $context->getTypeFromString('void');
        $i64 = $context->getTypeFromString('int64');
        $dbl = $context->getTypeFromString('double');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $valuePtr = $context->getTypeFromString('__value__*');

        foreach (
            [
                ['__string__init', $strPtr, [$i64, $i8p]],
                ['__hashtable__alloc', $htPtr, []],
                ['__hashtable__setLongAt', $void, [$htPtr, $sizeT, $i64]],
                ['__hashtable__setStringAt', $void, [$htPtr, $sizeT, $strPtr]],
                ['__hashtable__setDoubleAt', $void, [$htPtr, $sizeT, $dbl]],
                ['__hashtable__setNullAt', $void, [$htPtr, $sizeT]],
                ['__value__writeLong', $void, [$valuePtr, $i64]],
                ['__value__writeString', $void, [$valuePtr, $strPtr]],
                ['__value__writeDouble', $void, [$valuePtr, $dbl]],
                ['__value__writeNull', $void, [$valuePtr]],
                ['__value__writeHashtable', $void, [$valuePtr, $htPtr]],
            ] as [$name, $ret, $params]
        ) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureExternal(Context $context, string $name, $fnType): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $fnType);
            $context->registerFunction($name, $fn);
        }
    }

    private static function emitIsSpace(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $ch = $fn->getParam(0);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $isSpace = self::isSpaceChar($context, $ch);

        $context->builder->returnValue($context->builder->zExt($isSpace, $i32));
    }

    private static function emitScanInt(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $oneSize = $sizeT->constInt(1, false);
        $zero32 = $i32->constInt(0, false);
        $one32 = $i32->constInt(1, false);

        $s = $fn->getParam(0);
        $len = $fn->getParam(1);
        $posPtr = $fn->getParam(2);
        $outPtr = $fn->getParam(3);

        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $negSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $anySlot = BasicBlockHelper::entryAlloca($context, $i32);
        $valSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($context->builder->load($posPtr), $iSlot);
        $context->builder->store($zero32, $negSlot);
        $context->builder->store($zero32, $anySlot);
        $context->builder->store($i64->constInt(0, false), $valSlot);

        $afterSkip = self::emitSkipSpaceLoop($context, $fn, $s, $len, $iSlot);

        $context->builder->positionAtEnd($afterSkip);
        $fail = $fn->appendBasicBlock('scan_int_fail');
        $sign = $fn->appendBasicBlock('scan_int_sign');
        $i = $context->builder->load($iSlot);
        $context->builder->branchIf($context->builder->icmp(Builder::INT_UGE, $i, $len), $fail, $sign);

        $context->builder->positionAtEnd($sign);
        $ch = $context->builder->load($context->builder->inBoundsGEP($s, $i));
        $isMinus = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(45, false));
        $isPlus = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(43, false));
        $negBb = $fn->appendBasicBlock('scan_int_neg');
        $plusBb = $fn->appendBasicBlock('scan_int_plus');
        $digitsBb = $fn->appendBasicBlock('scan_int_digits');
        $context->builder->branchIf($isMinus, $negBb, $plusBb);

        $context->builder->positionAtEnd($negBb);
        $context->builder->store($one32, $negSlot);
        $context->builder->store($context->builder->add($context->builder->load($iSlot), $oneSize), $iSlot);
        $context->builder->branch($digitsBb);

        $context->builder->positionAtEnd($plusBb);
        $plusBody = $fn->appendBasicBlock('scan_int_plus_body');
        $context->builder->branchIf($isPlus, $plusBody, $digitsBb);

        $context->builder->positionAtEnd($plusBody);
        $context->builder->store($context->builder->add($context->builder->load($iSlot), $oneSize), $iSlot);
        $context->builder->branch($digitsBb);

        $context->builder->positionAtEnd($digitsBb);
        $afterDigits = self::emitDigitLoop($context, $fn, $s, $len, $iSlot, $anySlot, $valSlot);

        $context->builder->positionAtEnd($afterDigits);
        $ok = $fn->appendBasicBlock('scan_int_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $context->builder->load($anySlot), $zero32),
            $fail,
            $ok
        );

        $context->builder->positionAtEnd($ok);
        $val = $context->builder->load($valSlot);
        $neg = $context->builder->icmp(Builder::INT_NE, $context->builder->load($negSlot), $zero32);
        $val = $context->builder->select($neg, $context->builder->sub($i64->constInt(0, false), $val), $val);
        $context->builder->store($val, $outPtr);
        $context->builder->store($context->builder->load($iSlot), $posPtr);
        $context->builder->returnValue($one32);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zero32);
    }

    private static function emitScanString(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $oneSize = $sizeT->constInt(1, false);
        $zero32 = $i32->constInt(0, false);
        $one32 = $i32->constInt(1, false);

        $s = $fn->getParam(0);
        $len = $fn->getParam(1);
        $posPtr = $fn->getParam(2);
        $buf = $fn->getParam(3);
        $bufCap = $fn->getParam(4);

        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $outSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($context->builder->load($posPtr), $iSlot);
        $context->builder->store($sizeT->constInt(0, false), $outSlot);

        $afterSkip = self::emitSkipSpaceLoop($context, $fn, $s, $len, $iSlot);

        $context->builder->positionAtEnd($afterSkip);
        $fail = $fn->appendBasicBlock('scan_str_fail');
        $body = $fn->appendBasicBlock('scan_str_body');
        $i = $context->builder->load($iSlot);
        $context->builder->branchIf($context->builder->icmp(Builder::INT_UGE, $i, $len), $fail, $body);

        $loopHead = $fn->appendBasicBlock('scan_str_loop');
        $loopDone = $fn->appendBasicBlock('scan_str_done');
        $context->builder->positionAtEnd($body);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $out = $context->builder->load($outSlot);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $i, $len);
        $loopBody = $fn->appendBasicBlock('scan_str_loop_body');
        $context->builder->branchIf($atEnd, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $ch = $context->builder->load($context->builder->inBoundsGEP($s, $i));
        $isSpace = $context->builder->call($context->lookupFunction('__phpc_sscanf_is_space'), $ch);
        $full = $context->builder->icmp(Builder::INT_UGE, $out, $context->builder->sub($bufCap, $oneSize));
        $stop = $fn->appendBasicBlock('scan_str_stop');
        $copy = $fn->appendBasicBlock('scan_str_copy');
        $context->builder->branchIf(
            $context->builder->or(
                $context->builder->icmp(Builder::INT_NE, $isSpace, $zero32),
                $full
            ),
            $stop,
            $copy
        );

        $context->builder->positionAtEnd($copy);
        $context->builder->store($ch, $context->builder->inBoundsGEP($buf, $out));
        $context->builder->store($context->builder->add($out, $oneSize), $outSlot);
        $context->builder->store($context->builder->add($i, $oneSize), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($stop);
        $context->builder->branch($loopDone);

        $context->builder->positionAtEnd($loopDone);
        $ok = $fn->appendBasicBlock('scan_str_ok');
        $out = $context->builder->load($outSlot);
        $context->builder->branchIf($context->builder->icmp(Builder::INT_EQ, $out, $sizeT->constInt(0, false)), $fail, $ok);

        $context->builder->positionAtEnd($ok);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($buf, $out));
        $context->builder->store($context->builder->load($iSlot), $posPtr);
        $context->builder->returnValue($one32);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zero32);
    }

    private static function emitScanFloat(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $oneSize = $sizeT->constInt(1, false);
        $zero32 = $i32->constInt(0, false);
        $one32 = $i32->constInt(1, false);

        $s = $fn->getParam(0);
        $len = $fn->getParam(1);
        $posPtr = $fn->getParam(2);
        $outPtr = $fn->getParam(3);

        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $startSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $anySlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($context->builder->load($posPtr), $iSlot);
        $context->builder->store($zero32, $anySlot);

        $afterSkip = self::emitSkipSpaceLoop($context, $fn, $s, $len, $iSlot);

        $context->builder->positionAtEnd($afterSkip);
        $fail = $fn->appendBasicBlock('scan_flt_fail');
        $sign = $fn->appendBasicBlock('scan_flt_sign');
        $i = $context->builder->load($iSlot);
        $context->builder->branchIf($context->builder->icmp(Builder::INT_UGE, $i, $len), $fail, $sign);

        $context->builder->positionAtEnd($sign);
        $context->builder->store($i, $startSlot);
        $ch = $context->builder->load($context->builder->inBoundsGEP($s, $i));
        $isSign = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(45, false)),
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(43, false))
        );
        $afterSign = $fn->appendBasicBlock('scan_flt_after_sign');
        $signBb = $fn->appendBasicBlock('scan_flt_sign_skip');
        $context->builder->branchIf($isSign, $signBb, $afterSign);

        $context->builder->positionAtEnd($signBb);
        $context->builder->store($context->builder->add($i, $oneSize), $iSlot);
        $context->builder->branch($afterSign);

        $context->builder->positionAtEnd($afterSign);
        $afterInt = self::emitFloatDigitRun($context, $fn, $s, $len, $iSlot, $anySlot, true);

        $context->builder->positionAtEnd($afterInt);
        $dotCheck = $fn->appendBasicBlock('scan_flt_dot_check');
        $context->builder->branch($dotCheck);

        $context->builder->positionAtEnd($dotCheck);
        $i = $context->builder->load($iSlot);
        $hasDot = $context->builder->and(
            $context->builder->icmp(Builder::INT_SLT, $i, $len),
            $context->builder->icmp(
                Builder::INT_EQ,
                $context->builder->load($context->builder->inBoundsGEP($s, $i)),
                $i8->constInt(46, false)
            )
        );
        $afterDot = $fn->appendBasicBlock('scan_flt_after_dot');
        $dotBb = $fn->appendBasicBlock('scan_flt_dot');
        $context->builder->branchIf($hasDot, $dotBb, $afterDot);

        $context->builder->positionAtEnd($dotBb);
        $context->builder->store($context->builder->add($i, $oneSize), $iSlot);
        $afterFrac = self::emitFloatDigitRun($context, $fn, $s, $len, $iSlot, $anySlot, false);
        $context->builder->positionAtEnd($afterFrac);
        $context->builder->branch($afterDot);

        $context->builder->positionAtEnd($afterDot);
        $afterExp = self::emitOptionalFloatExponent($context, $fn, $s, $len, $iSlot, $anySlot);

        $context->builder->positionAtEnd($afterExp);
        $ok = $fn->appendBasicBlock('scan_flt_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $context->builder->load($anySlot), $zero32),
            $fail,
            $ok
        );

        $context->builder->positionAtEnd($ok);
        $start = $context->builder->load($startSlot);
        $end = $context->builder->load($iSlot);
        $sliceLen = $context->builder->sub($end, $start);
        $tooLong = $context->builder->icmp(
            Builder::INT_UGE,
            $sliceLen,
            $sizeT->constInt(self::FLOAT_TMP_SIZE, false)
        );
        $copyBb = $fn->appendBasicBlock('scan_flt_copy');
        $context->builder->branchIf($tooLong, $fail, $copyBb);

        $context->builder->positionAtEnd($copyBb);
        $tmp = $context->builder->alloca($i8->arrayType(self::FLOAT_TMP_SIZE), 1, 'sscanf_flt_tmp');
        $tmpPtr = $context->builder->pointerCast($tmp, $context->getTypeFromString('int8*'));
        $context->intrinsic->memcpy(
            $tmpPtr,
            $context->builder->inBoundsGEP($s, $start),
            $sliceLen,
            false
        );
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($tmpPtr, $sliceLen));
        $endPtrSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('int8*'));
        $context->builder->store($context->getTypeFromString('int8*')->constNull(), $endPtrSlot);
        $val = $context->builder->call(
            $context->lookupFunction('strtod'),
            $tmpPtr,
            $endPtrSlot
        );
        $context->builder->store($val, $outPtr);
        $context->builder->store($end, $posPtr);
        $context->builder->returnValue($one32);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zero32);
    }

    private static function emitCompilerSscanf(Context $context, LlvmFunction $fn): void
    {
        self::emitCompilerSscanfImpl($context, $fn, false);
    }

    private static function emitCompilerSscanfEx(Context $context, LlvmFunction $fn): void
    {
        self::emitCompilerSscanfImpl($context, $fn, true);
    }

    private static function emitCompilerSscanfImpl(Context $context, LlvmFunction $fn, bool $trackConsumed): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $valuePtrPtr = $context->getTypeFromString('__value__**');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $oneSize = $sizeT->constInt(1, false);
        $zero64 = $i64->constInt(0, false);
        $one64 = $i64->constInt(1, false);

        $str = $fn->getParam(0);
        $fmt = $fn->getParam(1);
        $outCount = $fn->getParam(2);
        $outPtrs = $fn->getParam(3);
        $consumedOut = $trackConsumed ? $fn->getParam(4) : null;

        $nullRet = $fn->appendBasicBlock('sscanf_null');
        $work = $fn->appendBasicBlock('sscanf_work');
        $nullStr = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $str, $strPtr->constNull()),
            $context->builder->icmp(Builder::INT_EQ, $fmt, $strPtr->constNull())
        );
        $context->builder->branchIf($nullStr, $nullRet, $work);

        $context->builder->positionAtEnd($nullRet);
        if ($trackConsumed && null !== $consumedOut) {
            $context->builder->store($sizeT->constInt(0, false), $consumedOut);
        }
        $context->builder->returnValue($zero64);

        $context->builder->positionAtEnd($work);
        [$input, $inLen, $format, $fmtLen] = self::loadStringPair($context, $str, $fmt);
        self::emitValidateOutVarArity($context, $fn, $format, $fmtLen, $outCount);

        $inPosSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $assignedSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $outIdxSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $fposSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($sizeT->constInt(0, false), $inPosSlot);
        $context->builder->store($zero64, $assignedSlot);
        $context->builder->store($zero64, $outIdxSlot);
        $context->builder->store($sizeT->constInt(0, false), $fposSlot);

        $scanInt = $context->lookupFunction('__phpc_sscanf_scan_int');
        $scanString = $context->lookupFunction('__phpc_sscanf_scan_string');
        $scanFloat = $context->lookupFunction('__phpc_sscanf_scan_float');
        $writeLong = $context->lookupFunction('__value__writeLong');
        $writeString = $context->lookupFunction('__value__writeString');
        $writeDouble = $context->lookupFunction('__value__writeDouble');
        $stringInit = $context->lookupFunction('__string__init');

        $loopHead = $fn->appendBasicBlock('sscanf_loop');
        $loopDone = $fn->appendBasicBlock('sscanf_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $fpos = $context->builder->load($fposSlot);
        $atFmtEnd = $context->builder->icmp(Builder::INT_UGE, $fpos, $fmtLen);
        $loopBody = $fn->appendBasicBlock('sscanf_body');
        $context->builder->branchIf($atFmtEnd, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $ch = $context->builder->load($context->builder->inBoundsGEP($format, $fpos));
        $notPct = $fn->appendBasicBlock('sscanf_literal');
        $pct = $fn->appendBasicBlock('sscanf_pct');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(37, false)),
            $pct,
            $notPct
        );

        $context->builder->positionAtEnd($notPct);
        $inPos = $context->builder->load($inPosSlot);
        $literalFail = $fn->appendBasicBlock('sscanf_ret_assigned');
        $literalOk = $fn->appendBasicBlock('sscanf_literal_ok');
        $badLit = $context->builder->or(
            $context->builder->icmp(Builder::INT_UGE, $inPos, $inLen),
            $context->builder->icmp(
                Builder::INT_NE,
                $context->builder->load($context->builder->inBoundsGEP($input, $inPos)),
                $ch
            )
        );
        $context->builder->branchIf($badLit, $literalFail, $literalOk);
        $context->builder->positionAtEnd($literalOk);
        $context->builder->store($context->builder->add($inPos, $oneSize), $inPosSlot);
        $context->builder->store($context->builder->add($fpos, $oneSize), $fposSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($pct);
        $specMissing = $fn->appendBasicBlock('sscanf_spec_missing');
        $nextSpec = $context->builder->add($fpos, $oneSize);
        $hasSpec = $context->builder->icmp(Builder::INT_SLT, $nextSpec, $fmtLen);
        $specBody = $fn->appendBasicBlock('sscanf_spec');
        $context->builder->branchIf($hasSpec, $specBody, $specMissing);

        $context->builder->positionAtEnd($specBody);
        $specPosSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($nextSpec, $specPosSlot);
        self::emitSkipOptionalFieldWidth($context, $fn, $format, $fmtLen, $specPosSlot);
        $specPos = $context->builder->load($specPosSlot);
        $spec = $context->builder->load($context->builder->inBoundsGEP($format, $specPos));
        $pctLit = $fn->appendBasicBlock('sscanf_pct_lit');
        $conv = $fn->appendBasicBlock('sscanf_conv');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $spec, $i8->constInt(37, false)),
            $pctLit,
            $conv
        );

        $context->builder->positionAtEnd($pctLit);
        $inPos = $context->builder->load($inPosSlot);
        $badPct = $context->builder->or(
            $context->builder->icmp(Builder::INT_UGE, $inPos, $inLen),
            $context->builder->icmp(
                Builder::INT_NE,
                $context->builder->load($context->builder->inBoundsGEP($input, $inPos)),
                $i8->constInt(37, false)
            )
        );
        $pctOk = $fn->appendBasicBlock('sscanf_pct_ok');
        $context->builder->branchIf($badPct, $literalFail, $pctOk);
        $context->builder->positionAtEnd($pctOk);
        $context->builder->store($context->builder->add($inPos, $oneSize), $inPosSlot);
        $context->builder->store($context->builder->add($specPos, $oneSize), $fposSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($conv);
        $outIdx = $context->builder->load($outIdxSlot);
        $outSlotPtr = $context->builder->inBoundsGEP($outPtrs, $outIdx);
        $outVarPtr = $context->builder->load($outSlotPtr);
        $badOut = $context->builder->or(
            $context->builder->icmp(Builder::INT_SGE, $outIdx, $outCount),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $outPtrs, $valuePtrPtr->constNull()),
                $context->builder->icmp(Builder::INT_EQ, $outVarPtr, $valuePtr->constNull())
            )
        );
        $convSwitch = $fn->appendBasicBlock('sscanf_conv_switch');
        $context->builder->branchIf($badOut, $literalFail, $convSwitch);

        $context->builder->positionAtEnd($convSwitch);
        $defaultBb = $fn->appendBasicBlock('sscanf_default');
        $caseD = $fn->appendBasicBlock('sscanf_case_d');
        $caseS = $fn->appendBasicBlock('sscanf_case_s');
        $caseF = $fn->appendBasicBlock('sscanf_case_f');
        $spec32 = $context->builder->zExt($spec, $i32);
        $switch = $context->builder->branchSwitch($spec32, $defaultBb, 3);
        $switch->addCase($i32->constInt(100, false), $caseD);
        $switch->addCase($i32->constInt(115, false), $caseS);
        $switch->addCase($i32->constInt(102, false), $caseF);

        $context->builder->positionAtEnd($caseD);
        $valSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $okD = $context->builder->call($scanInt, $input, $inLen, $inPosSlot, $valSlot);
        $afterD = $fn->appendBasicBlock('sscanf_after_d');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $okD, $i32->constInt(0, false)),
            $literalFail,
            $afterD
        );
        $context->builder->positionAtEnd($afterD);
        $context->builder->call($writeLong, $outVarPtr, $context->builder->load($valSlot));
        $context->builder->store($context->builder->add($outIdx, $one64), $outIdxSlot);
        $context->builder->store($context->builder->add($context->builder->load($assignedSlot), $one64), $assignedSlot);
        $context->builder->store($context->builder->add($specPos, $oneSize), $fposSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($caseS);
        $buf = $context->builder->alloca($i8->arrayType(self::STRING_BUF_SIZE), 1, 'sscanf_str_buf');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $okS = $context->builder->call(
            $scanString,
            $input,
            $inLen,
            $inPosSlot,
            $bufPtr,
            $sizeT->constInt(self::STRING_BUF_SIZE, false)
        );
        $afterS = $fn->appendBasicBlock('sscanf_after_s');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $okS, $i32->constInt(0, false)),
            $literalFail,
            $afterS
        );
        $context->builder->positionAtEnd($afterS);
        $strLen = $context->builder->call($context->lookupFunction('strlen'), $bufPtr);
        $newStr = $context->builder->call(
            $stringInit,
            $context->builder->zExt($strLen, $i64),
            $bufPtr
        );
        $context->builder->call($writeString, $outVarPtr, $newStr);
        $context->builder->store($context->builder->add($outIdx, $one64), $outIdxSlot);
        $context->builder->store($context->builder->add($context->builder->load($assignedSlot), $one64), $assignedSlot);
        $context->builder->store($context->builder->add($specPos, $oneSize), $fposSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($caseF);
        $fltSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('double'));
        $okF = $context->builder->call($scanFloat, $input, $inLen, $inPosSlot, $fltSlot);
        $afterF = $fn->appendBasicBlock('sscanf_after_f');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $okF, $i32->constInt(0, false)),
            $literalFail,
            $afterF
        );
        $context->builder->positionAtEnd($afterF);
        $context->builder->call($writeDouble, $outVarPtr, $context->builder->load($fltSlot));
        $context->builder->store($context->builder->add($outIdx, $one64), $outIdxSlot);
        $context->builder->store($context->builder->add($context->builder->load($assignedSlot), $one64), $assignedSlot);
        $context->builder->store($context->builder->add($specPos, $oneSize), $fposSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($defaultBb);
        $context->builder->branch($literalFail);

        $context->builder->positionAtEnd($specMissing);
        $context->builder->branch($literalFail);

        $context->builder->positionAtEnd($literalFail);
        if ($trackConsumed && null !== $consumedOut) {
            $context->builder->store($context->builder->load($inPosSlot), $consumedOut);
        }
        $context->builder->returnValue($context->builder->load($assignedSlot));

        $context->builder->positionAtEnd($loopDone);
        if ($trackConsumed && null !== $consumedOut) {
            $context->builder->store($context->builder->load($inPosSlot), $consumedOut);
        }
        $context->builder->returnValue($context->builder->load($assignedSlot));
    }

    private static function emitCompilerVfscanf(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $minusOne = $i64->constInt(-1, false);
        $zero64 = $i64->constInt(0, false);
        $seekSet = $i64->constInt(\SEEK_SET, false);

        $handle = $fn->getParam(0);
        $fmt = $fn->getParam(1);
        $outCount = $fn->getParam(2);
        $outPtrs = $fn->getParam(3);
        $consumedSlot = BasicBlockHelper::entryAlloca($context, $sizeT);

        $fail = $fn->appendBasicBlock('vfscanf_fail');
        $work = $fn->appendBasicBlock('vfscanf_work');
        $start = $context->builder->call($context->lookupFunction('__compiler_ftell'), $handle);
        $startOk = $context->builder->icmp(Builder::INT_NE, $start, $minusOne);
        $context->builder->branchIf($startOk, $work, $fail);

        $context->builder->positionAtEnd($work);
        $content = $context->builder->call(
            $context->lookupFunction('__compiler_stream_get_contents'),
            $handle,
            $minusOne,
            $minusOne
        );
        $contentOk = $context->builder->icmp(Builder::INT_NE, $content, $strPtr->constNull());
        $scan = $fn->appendBasicBlock('vfscanf_scan');
        $context->builder->branchIf($contentOk, $scan, $fail);

        $context->builder->positionAtEnd($scan);
        $assigned = $context->builder->call(
            $context->lookupFunction('__compiler_sscanf_ex'),
            $content,
            $fmt,
            $outCount,
            $outPtrs,
            $consumedSlot
        );
        $consumed = $context->builder->load($consumedSlot);
        $newPos = $context->builder->add($start, $context->builder->intCast($consumed, $i64));
        $context->builder->call($context->lookupFunction('__compiler_fseek'), $handle, $newPos, $seekSet);
        $map = $context->structFieldMap['__string__'];
        $contentLen = $context->builder->load($context->builder->structGep($content, $map['length']));
        $emptyContent = $context->builder->icmp(Builder::INT_EQ, $contentLen, $sizeT->constInt(0, false));
        $noAssign = $context->builder->icmp(Builder::INT_EQ, $assigned, $zero64);
        $eofFalse = $context->builder->and($emptyContent, $noAssign);
        $context->builder->returnValue($context->builder->select($eofFalse, $minusOne, $assigned));

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($minusOne);
    }

    private static function emitCompilerSscanfArray(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $oneSize = $sizeT->constInt(1, false);
        $one64 = $i64->constInt(1, false);

        $str = $fn->getParam(0);
        $fmt = $fn->getParam(1);

        $nullRet = $fn->appendBasicBlock('sscanf_arr_null');
        $work = $fn->appendBasicBlock('sscanf_arr_work');
        $nullStr = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $str, $strPtr->constNull()),
            $context->builder->icmp(Builder::INT_EQ, $fmt, $strPtr->constNull())
        );
        $context->builder->branchIf($nullStr, $nullRet, $work);

        $context->builder->positionAtEnd($nullRet);
        self::emitSscanfArrayValueFromHashtable(
            $context,
            $fn,
            $context->builder->call($context->lookupFunction('__hashtable__alloc')),
            $sizeT->constInt(0, false),
            $sizeT->constInt(0, false),
            $sizeT->constInt(0, false)
        );

        $context->builder->positionAtEnd($work);
        [$input, $inLen, $format, $fmtLen] = self::loadStringPair($context, $str, $fmt);
        $slots = $context->builder->call(
            $context->lookupFunction('__phpc_sscanf_count_specs'),
            $format,
            $fmtLen
        );
        $slotsSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($slots, $slotsSlot);
        $htSlot = BasicBlockHelper::entryAlloca($context, $htPtr);
        $context->builder->store(
            $context->builder->call($context->lookupFunction('__hashtable__alloc')),
            $htSlot
        );

        $inPosSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $outIdxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $fposSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($sizeT->constInt(0, false), $inPosSlot);
        $context->builder->store($sizeT->constInt(0, false), $outIdxSlot);
        $context->builder->store($sizeT->constInt(0, false), $fposSlot);

        $scanInt = $context->lookupFunction('__phpc_sscanf_scan_int');
        $scanString = $context->lookupFunction('__phpc_sscanf_scan_string');
        $scanFloat = $context->lookupFunction('__phpc_sscanf_scan_float');
        $setLong = $context->lookupFunction('__hashtable__setLongAt');
        $setString = $context->lookupFunction('__hashtable__setStringAt');
        $setDouble = $context->lookupFunction('__hashtable__setDoubleAt');
        $setNullAt = $context->lookupFunction('__hashtable__setNullAt');
        $stringInit = $context->lookupFunction('__string__init');

        $loopHead = $fn->appendBasicBlock('sscanf_arr_loop');
        $loopDone = $fn->appendBasicBlock('sscanf_arr_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $fpos = $context->builder->load($fposSlot);
        $atFmtEnd = $context->builder->icmp(Builder::INT_UGE, $fpos, $fmtLen);
        $loopBody = $fn->appendBasicBlock('sscanf_arr_body');
        $context->builder->branchIf($atFmtEnd, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $ch = $context->builder->load($context->builder->inBoundsGEP($format, $fpos));
        $notPct = $fn->appendBasicBlock('sscanf_arr_literal');
        $pct = $fn->appendBasicBlock('sscanf_arr_pct');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(37, false)),
            $pct,
            $notPct
        );

        $retHt = $fn->appendBasicBlock('sscanf_arr_ret');

        $context->builder->positionAtEnd($notPct);
        $inPos = $context->builder->load($inPosSlot);
        $literalOk = $fn->appendBasicBlock('sscanf_arr_literal_ok');
        $badLit = $context->builder->or(
            $context->builder->icmp(Builder::INT_UGE, $inPos, $inLen),
            $context->builder->icmp(
                Builder::INT_NE,
                $context->builder->load($context->builder->inBoundsGEP($input, $inPos)),
                $ch
            )
        );
        $context->builder->branchIf($badLit, $retHt, $literalOk);
        $context->builder->positionAtEnd($literalOk);
        $context->builder->store($context->builder->add($inPos, $oneSize), $inPosSlot);
        $context->builder->store($context->builder->add($fpos, $oneSize), $fposSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($pct);
        $nextSpec = $context->builder->add($fpos, $oneSize);
        $hasSpec = $context->builder->icmp(Builder::INT_SLT, $nextSpec, $fmtLen);
        $specBody = $fn->appendBasicBlock('sscanf_arr_spec');
        $context->builder->branchIf($hasSpec, $specBody, $retHt);

        $context->builder->positionAtEnd($specBody);
        $specPosSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($nextSpec, $specPosSlot);
        self::emitSkipOptionalFieldWidth($context, $fn, $format, $fmtLen, $specPosSlot);
        $specPos = $context->builder->load($specPosSlot);
        $spec = $context->builder->load($context->builder->inBoundsGEP($format, $specPos));
        $pctLit = $fn->appendBasicBlock('sscanf_arr_pct_lit');
        $conv = $fn->appendBasicBlock('sscanf_arr_conv');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $spec, $i8->constInt(37, false)),
            $pctLit,
            $conv
        );

        $context->builder->positionAtEnd($pctLit);
        $inPos = $context->builder->load($inPosSlot);
        $badPct = $context->builder->or(
            $context->builder->icmp(Builder::INT_UGE, $inPos, $inLen),
            $context->builder->icmp(
                Builder::INT_NE,
                $context->builder->load($context->builder->inBoundsGEP($input, $inPos)),
                $i8->constInt(37, false)
            )
        );
        $pctOk = $fn->appendBasicBlock('sscanf_arr_pct_ok');
        $context->builder->branchIf($badPct, $retHt, $pctOk);
        $context->builder->positionAtEnd($pctOk);
        $context->builder->store($context->builder->add($inPos, $oneSize), $inPosSlot);
        $context->builder->store($context->builder->add($specPos, $oneSize), $fposSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($conv);
        $outIdx = $context->builder->load($outIdxSlot);
        $defaultBb = $fn->appendBasicBlock('sscanf_arr_default');
        $caseD = $fn->appendBasicBlock('sscanf_arr_case_d');
        $caseS = $fn->appendBasicBlock('sscanf_arr_case_s');
        $caseF = $fn->appendBasicBlock('sscanf_arr_case_f');
        $spec32 = $context->builder->zExt($spec, $i32);
        $switch = $context->builder->branchSwitch($spec32, $defaultBb, 3);
        $switch->addCase($i32->constInt(100, false), $caseD);
        $switch->addCase($i32->constInt(115, false), $caseS);
        $switch->addCase($i32->constInt(102, false), $caseF);

        $context->builder->positionAtEnd($caseD);
        $valSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $okD = $context->builder->call($scanInt, $input, $inLen, $inPosSlot, $valSlot);
        $afterD = $fn->appendBasicBlock('sscanf_arr_after_d');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $okD, $i32->constInt(0, false)),
            $retHt,
            $afterD
        );
        $context->builder->positionAtEnd($afterD);
        $context->builder->call($setLong, $context->builder->load($htSlot), $outIdx, $context->builder->load($valSlot));
        $context->builder->store($context->builder->add($outIdx, $oneSize), $outIdxSlot);
        $context->builder->store($context->builder->add($specPos, $oneSize), $fposSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($caseS);
        $buf = $context->builder->alloca($i8->arrayType(self::STRING_BUF_SIZE), 1, 'sscanf_arr_str_buf');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $okS = $context->builder->call(
            $scanString,
            $input,
            $inLen,
            $inPosSlot,
            $bufPtr,
            $sizeT->constInt(self::STRING_BUF_SIZE, false)
        );
        $afterS = $fn->appendBasicBlock('sscanf_arr_after_s');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $okS, $i32->constInt(0, false)),
            $retHt,
            $afterS
        );
        $context->builder->positionAtEnd($afterS);
        $strLen = $context->builder->call($context->lookupFunction('strlen'), $bufPtr);
        $newStr = $context->builder->call(
            $stringInit,
            $context->builder->zExt($strLen, $i64),
            $bufPtr
        );
        $context->builder->call($setString, $context->builder->load($htSlot), $outIdx, $newStr);
        $context->builder->store($context->builder->add($outIdx, $oneSize), $outIdxSlot);
        $context->builder->store($context->builder->add($specPos, $oneSize), $fposSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($caseF);
        $fltSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('double'));
        $okF = $context->builder->call($scanFloat, $input, $inLen, $inPosSlot, $fltSlot);
        $afterF = $fn->appendBasicBlock('sscanf_arr_after_f');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $okF, $i32->constInt(0, false)),
            $retHt,
            $afterF
        );
        $context->builder->positionAtEnd($afterF);
        $context->builder->call($setDouble, $context->builder->load($htSlot), $outIdx, $context->builder->load($fltSlot));
        $context->builder->store($context->builder->add($outIdx, $oneSize), $outIdxSlot);
        $context->builder->store($context->builder->add($specPos, $oneSize), $fposSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($defaultBb);
        $context->builder->branch($retHt);

        $context->builder->positionAtEnd($retHt);
        self::emitSscanfArrayValueFromHashtable(
            $context,
            $fn,
            $context->builder->load($htSlot),
            $inLen,
            $context->builder->load($outIdxSlot),
            $context->builder->load($slotsSlot)
        );

        $context->builder->positionAtEnd($loopDone);
        self::emitSscanfArrayValueFromHashtable(
            $context,
            $fn,
            $context->builder->load($htSlot),
            $inLen,
            $context->builder->load($outIdxSlot),
            $context->builder->load($slotsSlot)
        );
    }

    private static function emitSscanfArrayValueFromHashtable(
        Context $context,
        LlvmFunction $fn,
        Value $ht,
        Value $inLen,
        Value $assigned,
        Value $slots
    ): void {
        $sizeT = $context->getTypeFromString('size_t');
        $zeroSize = $sizeT->constInt(0, false);
        $setNullAt = $context->lookupFunction('__hashtable__setNullAt');

        $nullPhp = $fn->appendBasicBlock('sscanf_arr_null_php');
        $arrayPhp = $fn->appendBasicBlock('sscanf_arr_array_php');
        $isEmptyInput = $context->builder->icmp(Builder::INT_EQ, $inLen, $zeroSize);
        $noAssigned = $context->builder->icmp(Builder::INT_EQ, $assigned, $zeroSize);
        $hasSlots = $context->builder->icmp(Builder::INT_SGT, $slots, $zeroSize);
        $returnNull = $context->builder->and(
            $isEmptyInput,
            $context->builder->and($noAssigned, $hasSlots)
        );
        $context->builder->branchIf($returnNull, $nullPhp, $arrayPhp);

        $context->builder->positionAtEnd($arrayPhp);
        $padHead = $fn->appendBasicBlock('sscanf_arr_pad_head');
        $padBody = $fn->appendBasicBlock('sscanf_arr_pad_body');
        $padDone = $fn->appendBasicBlock('sscanf_arr_pad_done');
        $padIdxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($assigned, $padIdxSlot);
        $context->builder->branch($padHead);

        $context->builder->positionAtEnd($padHead);
        $padIdx = $context->builder->load($padIdxSlot);
        $padFinished = $context->builder->icmp(Builder::INT_SGE, $padIdx, $slots);
        $context->builder->branchIf($padFinished, $padDone, $padBody);

        $context->builder->positionAtEnd($padBody);
        $context->builder->call($setNullAt, $ht, $padIdx);
        $context->builder->store(
            $context->builder->add($padIdx, $sizeT->constInt(1, false)),
            $padIdxSlot
        );
        $context->builder->branch($padHead);

        $context->builder->positionAtEnd($padDone);
        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $resultPtr,
            $ht
        );
        $context->builder->returnValue($resultPtr);

        $context->builder->positionAtEnd($nullPhp);
        $nullSlot = JitValueBox::alloc($context);
        $nullPtr = JitValueBox::pointer($context, $nullSlot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $nullPtr);
        $context->builder->returnValue($nullPtr);
    }

    private static function emitCountConversionSpecs(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $format = $fn->getParam(0);
        $fmtLen = $fn->getParam(1);
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $oneSize = $sizeT->constInt(1, false);
        $zero64 = $i64->constInt(0, false);
        $one64 = $i64->constInt(1, false);

        $countSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $fposSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero64, $countSlot);
        $context->builder->store($sizeT->constInt(0, false), $fposSlot);

        $loopHead = $fn->appendBasicBlock('count_loop');
        $loopDone = $fn->appendBasicBlock('count_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $fpos = $context->builder->load($fposSlot);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $fpos, $fmtLen);
        $loopBody = $fn->appendBasicBlock('count_body');
        $context->builder->branchIf($atEnd, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $ch = $context->builder->load($context->builder->inBoundsGEP($format, $fpos));
        $notPct = $fn->appendBasicBlock('count_not_pct');
        $pct = $fn->appendBasicBlock('count_pct');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(37, false)),
            $pct,
            $notPct
        );

        $context->builder->positionAtEnd($notPct);
        $context->builder->store($context->builder->add($fpos, $oneSize), $fposSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($pct);
        $nextSpec = $context->builder->add($fpos, $oneSize);
        $hasSpec = $context->builder->icmp(Builder::INT_SLT, $nextSpec, $fmtLen);
        $specBody = $fn->appendBasicBlock('count_spec');
        $specEnd = $fn->appendBasicBlock('count_spec_end');
        $context->builder->branchIf($hasSpec, $specBody, $specEnd);

        $context->builder->positionAtEnd($specBody);
        $specPos = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($nextSpec, $specPos);
        self::emitSkipOptionalFieldWidth($context, $fn, $format, $fmtLen, $specPos);
        $specPosVal = $context->builder->load($specPos);
        $spec = $context->builder->load($context->builder->inBoundsGEP($format, $specPosVal));
        $isLitPct = $context->builder->icmp(Builder::INT_EQ, $spec, $i8->constInt(37, false));
        $incCount = $fn->appendBasicBlock('count_inc');
        $afterSpec = $fn->appendBasicBlock('count_after_spec');
        $context->builder->branchIf($isLitPct, $afterSpec, $incCount);

        $context->builder->positionAtEnd($incCount);
        $context->builder->store(
            $context->builder->add($context->builder->load($countSlot), $one64),
            $countSlot
        );
        $context->builder->branch($afterSpec);

        $context->builder->positionAtEnd($afterSpec);
        $context->builder->store($context->builder->add($specPosVal, $oneSize), $fposSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($specEnd);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->returnValue($context->builder->load($countSlot));
    }

    private static function emitValidateOutVarArity(
        Context $context,
        LlvmFunction $fn,
        Value $format,
        Value $fmtLen,
        Value $outCount
    ): void {
        TypeErrorRaise::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');

        $specCount = $context->builder->call(
            $context->lookupFunction('__phpc_sscanf_count_specs'),
            $format,
            $fmtLen
        );
        $matches = $context->builder->icmp(Builder::INT_EQ, $specCount, $outCount);
        $arityOk = $fn->appendBasicBlock('sscanf_arity_ok');
        $arityFail = $fn->appendBasicBlock('sscanf_arity_fail');
        $context->builder->branchIf($matches, $arityOk, $arityFail);

        $context->builder->positionAtEnd($arityFail);
        $tooFew = $context->builder->icmp(Builder::INT_SLT, $outCount, $specCount);
        $msgFew = $fn->appendBasicBlock('sscanf_arity_msg_few');
        $msgExtra = $fn->appendBasicBlock('sscanf_arity_msg_extra');
        $context->builder->branchIf($tooFew, $msgFew, $msgExtra);

        $context->builder->positionAtEnd($msgFew);
        self::emitArityValueError($context, 'Different numbers of variable names and field specifiers');

        $context->builder->positionAtEnd($msgExtra);
        self::emitArityValueError($context, 'Variable is not assigned by any conversion specifiers');

        $context->builder->positionAtEnd($arityOk);
    }

    private static function emitArityValueError(Context $context, string $message): void
    {
        if (null !== TryCatchHelper::resolveThrowHandler($context)) {
            TryCatchHelper::emitCatchableClassError($context, 'ValueError', $message);

            return;
        }

        TypeErrorRaise::emitValueError($context, $message);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_type_error'));
        } else {
            $context->builder->call($context->lookupFunction('abort'));
        }
    }

    /**
     * @return array{0: Value, 1: Value, 2: Value, 3: Value}
     */
    private static function loadStringPair(Context $context, Value $str, Value $fmt): array
    {
        $map = $context->structFieldMap['__string__'];
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        $input = $context->builder->pointerCast(
            $context->builder->structGep($str, $map['value']),
            $i8p
        );
        $inLen = $context->builder->trunc(
            $context->builder->load($context->builder->structGep($str, $map['length'])),
            $sizeT
        );
        $format = $context->builder->pointerCast(
            $context->builder->structGep($fmt, $map['value']),
            $i8p
        );
        $fmtLen = $context->builder->trunc(
            $context->builder->load($context->builder->structGep($fmt, $map['length'])),
            $sizeT
        );

        return [$input, $inLen, $format, $fmtLen];
    }

    private static function emitSkipSpaceLoop(
        Context $context,
        LlvmFunction $fn,
        Value $s,
        Value $len,
        Value $iSlot
    ): BasicBlock {
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $oneSize = $sizeT->constInt(1, false);
        $zero32 = $i32->constInt(0, false);
        $isSpaceFn = $context->lookupFunction('__phpc_sscanf_is_space');

        $loopHead = $fn->appendBasicBlock('ss_skip_head');
        $loopDone = $fn->appendBasicBlock('ss_skip_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $i, $len);
        $loopBody = $fn->appendBasicBlock('ss_skip_body');
        $context->builder->branchIf($atEnd, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $ch = $context->builder->load($context->builder->inBoundsGEP($s, $i));
        $space = $context->builder->call($isSpaceFn, $ch);
        $cont = $fn->appendBasicBlock('ss_skip_cont');
        $exit = $fn->appendBasicBlock('ss_skip_exit');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $space, $zero32),
            $cont,
            $exit
        );

        $context->builder->positionAtEnd($cont);
        $context->builder->store($context->builder->add($i, $oneSize), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($exit);
        $context->builder->branch($loopDone);

        $context->builder->positionAtEnd($loopDone);

        return $loopDone;
    }

    private static function emitDigitLoop(
        Context $context,
        LlvmFunction $fn,
        Value $s,
        Value $len,
        Value $iSlot,
        Value $anySlot,
        Value $valSlot
    ): BasicBlock {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $oneSize = $sizeT->constInt(1, false);
        $one32 = $i32->constInt(1, false);

        $loopHead = $fn->appendBasicBlock('ss_digit_head');
        $loopDone = $fn->appendBasicBlock('ss_digit_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $i, $len);
        $loopBody = $fn->appendBasicBlock('ss_digit_body');
        $context->builder->branchIf($atEnd, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $ch = $context->builder->load($context->builder->inBoundsGEP($s, $i));
        $isDigit = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(48, false)),
            $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(57, false))
        );
        $stop = $fn->appendBasicBlock('ss_digit_stop');
        $consume = $fn->appendBasicBlock('ss_digit_consume');
        $context->builder->branchIf($isDigit, $consume, $stop);

        $context->builder->positionAtEnd($consume);
        $digit = $context->builder->sub(
            $context->builder->zExt($ch, $i64),
            $i64->constInt(48, false)
        );
        $val = $context->builder->add(
            $context->builder->mul($context->builder->load($valSlot), $i64->constInt(10, false)),
            $digit
        );
        $context->builder->store($val, $valSlot);
        $context->builder->store($one32, $anySlot);
        $context->builder->store($context->builder->add($i, $oneSize), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($stop);
        $context->builder->branch($loopDone);

        $context->builder->positionAtEnd($loopDone);

        return $loopDone;
    }

    private static function emitFloatDigitRun(
        Context $context,
        LlvmFunction $fn,
        Value $s,
        Value $len,
        Value $iSlot,
        Value $anySlot,
        bool $firstRun
    ): BasicBlock {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $oneSize = $sizeT->constInt(1, false);
        $one32 = $i32->constInt(1, false);
        $prefix = $firstRun ? 'ss_fint' : 'ss_ffrac';

        $loopHead = $fn->appendBasicBlock($prefix.'_head');
        $loopDone = $fn->appendBasicBlock($prefix.'_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $i, $len);
        $loopBody = $fn->appendBasicBlock($prefix.'_body');
        $context->builder->branchIf($atEnd, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $ch = $context->builder->load($context->builder->inBoundsGEP($s, $i));
        $isDigit = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(48, false)),
            $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(57, false))
        );
        $stop = $fn->appendBasicBlock($prefix.'_stop');
        $consume = $fn->appendBasicBlock($prefix.'_consume');
        $context->builder->branchIf($isDigit, $consume, $stop);

        $context->builder->positionAtEnd($consume);
        $context->builder->store($one32, $anySlot);
        $context->builder->store($context->builder->add($i, $oneSize), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($stop);
        $context->builder->branch($loopDone);

        $context->builder->positionAtEnd($loopDone);

        return $loopDone;
    }

    /** Optional [eE][+-]?[0-9]+ suffix for %f (php-src formatted_io.c / strtod; #11210). */
    private static function emitOptionalFloatExponent(
        Context $context,
        LlvmFunction $fn,
        Value $s,
        Value $len,
        Value $iSlot,
        Value $anySlot
    ): BasicBlock {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $oneSize = $sizeT->constInt(1, false);
        $one32 = $i32->constInt(1, false);
        $zero32 = $i32->constInt(0, false);

        $done = $fn->appendBasicBlock('scan_flt_exp_done');
        $expCheck = $fn->appendBasicBlock('scan_flt_exp_check');
        $context->builder->branch($expCheck);

        $context->builder->positionAtEnd($expCheck);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $i, $len);
        $noExp = $fn->appendBasicBlock('scan_flt_exp_skip');
        $hasExp = $fn->appendBasicBlock('scan_flt_exp_has');
        $context->builder->branchIf($atEnd, $noExp, $hasExp);

        $context->builder->positionAtEnd($hasExp);
        $ch = $context->builder->load($context->builder->inBoundsGEP($s, $i));
        $isExp = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(101, false)),
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(69, false))
        );
        $expBody = $fn->appendBasicBlock('scan_flt_exp_body');
        $context->builder->branchIf($isExp, $expBody, $noExp);

        $context->builder->positionAtEnd($expBody);
        $expPosSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($i, $expPosSlot);
        $context->builder->store($context->builder->add($i, $oneSize), $iSlot);

        $afterSign = $fn->appendBasicBlock('scan_flt_exp_after_sign');
        $signBb = $fn->appendBasicBlock('scan_flt_exp_sign');
        $i = $context->builder->load($iSlot);
        $hasSign = $context->builder->and(
            $context->builder->icmp(Builder::INT_SLT, $i, $len),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $context->builder->load($context->builder->inBoundsGEP($s, $i)), $i8->constInt(43, false)),
                $context->builder->icmp(Builder::INT_EQ, $context->builder->load($context->builder->inBoundsGEP($s, $i)), $i8->constInt(45, false))
            )
        );
        $context->builder->branchIf($hasSign, $signBb, $afterSign);

        $context->builder->positionAtEnd($signBb);
        $context->builder->store($context->builder->add($context->builder->load($iSlot), $oneSize), $iSlot);
        $context->builder->branch($afterSign);

        $context->builder->positionAtEnd($afterSign);
        $expDigitsSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($zero32, $expDigitsSlot);
        $afterDigits = self::emitFloatDigitRun($context, $fn, $s, $len, $iSlot, $expDigitsSlot, true);

        $context->builder->positionAtEnd($afterDigits);
        $hadDigits = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($expDigitsSlot),
            $one32
        );
        $revertBb = $fn->appendBasicBlock('scan_flt_exp_revert');
        $keepBb = $fn->appendBasicBlock('scan_flt_exp_keep');
        $context->builder->branchIf($hadDigits, $keepBb, $revertBb);

        $context->builder->positionAtEnd($revertBb);
        $context->builder->store($context->builder->load($expPosSlot), $iSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($keepBb);
        $context->builder->store($one32, $anySlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($noExp);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $done;
    }

    private static function emitSkipOptionalFieldWidth(
        Context $context,
        LlvmFunction $fn,
        Value $format,
        Value $fmtLen,
        Value $posSlot
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $oneSize = $sizeT->constInt(1, false);

        $loopHead = $fn->appendBasicBlock('sscanf_width_head');
        $loopDone = $fn->appendBasicBlock('sscanf_width_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $pos = $context->builder->load($posSlot);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $pos, $fmtLen);
        $loopBody = $fn->appendBasicBlock('sscanf_width_body');
        $context->builder->branchIf($atEnd, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $ch = $context->builder->load($context->builder->inBoundsGEP($format, $pos));
        $isDigit = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(48, false)),
            $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(57, false))
        );
        $cont = $fn->appendBasicBlock('sscanf_width_cont');
        $exit = $fn->appendBasicBlock('sscanf_width_exit');
        $context->builder->branchIf($isDigit, $cont, $exit);

        $context->builder->positionAtEnd($cont);
        $context->builder->store($context->builder->add($pos, $oneSize), $posSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($exit);
        $context->builder->branch($loopDone);

        $context->builder->positionAtEnd($loopDone);
    }

    private static function isSpaceChar(Context $context, Value $ch): Value
    {
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->or(
            $context->builder->or(
                $context->builder->or(
                    $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(32, false)),
                    $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(9, false))
                ),
                $context->builder->or(
                    $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(10, false)),
                    $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(13, false))
                )
            ),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(12, false)),
                $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(11, false))
            )
        );
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);

            return;
        }
        $context->builder->clearInsertionPosition();
    }
}
