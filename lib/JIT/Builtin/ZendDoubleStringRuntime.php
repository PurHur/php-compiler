<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringCompare;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT float→string honoring PG(precision) (#21963).
 *
 * LLVM snprintf over {@see IniRuntime::loadPrecision()} (echo / `(string)`).
 * var_dump uses {@see formatVarDumpH()} / {@see IniRuntime::loadSerializePrecision()} (#32328).
 * Echo / `(string)` rewrite libc `%g` to zend_gcvt E-form (#32316).
 * VM SSOT: {@see \PHPCompiler\ext\standard\VmZendDoubleString}.
 * php-src: Zend/zend_operators.c — _convert_to_string float branch
 */
final class ZendDoubleStringRuntime
{
    private const ABI = '__compiler_zend_double_string';

    private const ENTRY = 'zend_double_string_entry';

    /**
     * Fresh ABI — not inside cached `__compiler_zend_double_string` helper-runtime.
     * php_var_dump %.*H / PG(serialize_precision) (#32328).
     */
    private const H_ABI = '__compiler_zend_double_string_h';

    private const H_ENTRY = 'zend_double_string_h_entry';

    /** Fresh ABI — not inside cached `__compiler_zend_double_string` helper-runtime (#32316). */
    private const ZENDIFY_ABI = '__compiler_zendify_snprintf_g';

    private const ZENDIFY_ENTRY = 'zendify_snprintf_g_entry';

    private static int $seq = 0;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
        self::ensureHLinked($context);
        self::ensureZendifyLinked($context);
    }

    /**
     * convert_to_string / echo float display (zend_gcvt / PG(precision)).
     * json_encode keeps {@see format()} then {@see jsonEncodeNumberOrNull()} (#32326).
     * var_dump uses {@see formatVarDumpH()} / PG(serialize_precision) (#32328).
     */
    public static function formatGcvt(Context $context, Value $doubleVal): Value
    {
        return self::zendifyGcvt($context, self::format($context, $doubleVal));
    }

    /**
     * php_json_encode_double: INF/NAN is not a JSON number (#32326).
     *
     * Sets {@see JSON_ERROR_INF_OR_NAN} (7). Soft-fail returns a null {@see __string__*}
     * (json_encode → false). {@see JSON_PARTIAL_OUTPUT_ON_ERROR} (512) emits `0`.
     * {@param $fn} must be the json_encode value bridge (not {@see BasicBlockHelper::parentFunction}).
     *
     * @see php-src ext/json/json_encoder.c php_json_encode_double
     */
    public static function jsonEncodeNumberOrNull(Context $context, LlvmFunction $fn, Value $dbl, Value $flags): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $s = ++self::$seq;

        $isNan = $context->builder->fcmp(Builder::REAL_UNO, $dbl, $dbl);
        $posInf = $dbl->typeOf()->constReal(\INF);
        $negInf = $dbl->typeOf()->constReal(-\INF);
        $isInf = $context->builder->fcmp(Builder::REAL_OEQ, $dbl, $posInf);
        $isNinf = $context->builder->fcmp(Builder::REAL_OEQ, $dbl, $negInf);
        $isNf = $context->builder->or($isNan, $context->builder->or($isInf, $isNinf));

        $nfBb = $fn->appendBasicBlock('json_dbl_nf_'.$s);
        $okBb = $fn->appendBasicBlock('json_dbl_ok_'.$s);
        $doneBb = $fn->appendBasicBlock('json_dbl_done_'.$s);
        $context->builder->branchIf($isNf, $nfBb, $okBb);

        $context->builder->positionAtEnd($okBb);
        $formatted = self::format($context, $dbl);
        $okEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($nfBb);
        StringJsonDecode::ensureLinked($context);
        $context->builder->call(
            $context->lookupFunction('__compiler_json_set_last_error'),
            $i64->constInt(7, false)
        );
        $isPartial = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($flags, $i64->constInt(512, false)),
            $i64->constInt(0, false)
        );
        $partBb = $fn->appendBasicBlock('json_dbl_partial_'.$s);
        $failBb = $fn->appendBasicBlock('json_dbl_fail_'.$s);
        $context->builder->branchIf($isPartial, $partBb, $failBb);

        $context->builder->positionAtEnd($partBb);
        $zeroStr = $context->builder->load($context->constantStringFromString('0'));
        $partEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($failBb);
        $nullStr = $strPtr->constNull();
        $failEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($strPtr, 'json_dbl_phi_'.$s);
        $phi->addIncoming($formatted, $okEnd);
        $phi->addIncoming($zeroStr, $partEnd);
        $phi->addIncoming($nullStr, $failEnd);

        return $phi;
    }

    /**
     * php_var_dump IS_DOUBLE: zend_strpprintf("%.*H", PG(serialize_precision)) (#32328).
     *
     * Distinct from {@see formatGcvt()} — echo stays on PG(precision).
     */
    public static function formatVarDumpH(Context $context, Value $doubleVal): Value
    {
        return self::zendifyGcvt($context, self::formatH($context, $doubleVal));
    }

    public static function format(Context $context, Value $doubleVal): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return self::snprintfCall($context, $doubleVal, '%.14g', null);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $doubleVal
        );
    }

    /** %.*H / PG(serialize_precision); default -1 → %.16g dtoa (#32328). */
    public static function formatH(Context $context, Value $doubleVal): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return self::snprintfCall($context, $doubleVal, '%.16g', null);
        }

        self::ensureHLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::H_ABI),
            $doubleVal
        );
    }

    /** serialize() wire `d:…;` without NestedJIT float cast (#31963). */
    public static function formatSerializeWire(Context $context, Value $doubleVal): Value
    {
        self::ensureSerializeWireLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_zend_serialize_double'),
            $doubleVal
        );
    }

    public static function ensureSerializeWireLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_zend_serialize_double');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_zend_serialize_double', $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureDecls($context);

        $double = $context->getTypeFromString('double');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                '__compiler_zend_serialize_double',
                $context->context->functionType($strPtr, false, $double)
            );
        $entry = $fn->appendBasicBlock('zend_ser_double_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            self::snprintfCall($context, $fn->getParam(0), 'd:%.16g;', null)
        );
        $context->registerFunction('__compiler_zend_serialize_double', $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        IniRuntime::ensureLinked($context);
        self::ensureDecls($context);

        $double = $context->getTypeFromString('double');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($strPtr, false, $double)
            );

        $savedActive = $context->activeFunction;
        $savedLowering = $context->loweringLlvmFunction;
        $entry = $fn->appendBasicBlock(self::ENTRY);
        $context->registerFunction(self::ABI, $fn);
        $context->activeFunction = self::ABI;
        $context->loweringLlvmFunction = $fn instanceof LlvmFunction ? $fn : null;
        $context->builder->positionAtEnd($entry);
        try {
            $result = self::emitBody($context, $fn, $fn->getParam(0), false);
            $context->builder->returnValue($result);
        } finally {
            $context->activeFunction = $savedActive;
            $context->loweringLlvmFunction = $savedLowering;
            if (null !== $savedBlock) {
                $context->builder->positionAtEnd($savedBlock);
            } else {
                $context->builder->clearInsertionPosition();
            }
        }
    }

    private static function ensureHLinked(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::H_ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::H_ABI, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        IniRuntime::ensureLinked($context);
        self::ensureDecls($context);

        $double = $context->getTypeFromString('double');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::H_ABI,
                $context->context->functionType($strPtr, false, $double)
            );

        $savedActive = $context->activeFunction;
        $savedLowering = $context->loweringLlvmFunction;
        $entry = $fn->appendBasicBlock(self::H_ENTRY);
        $context->registerFunction(self::H_ABI, $fn);
        $context->activeFunction = self::H_ABI;
        $context->loweringLlvmFunction = $fn instanceof LlvmFunction ? $fn : null;
        $context->builder->positionAtEnd($entry);
        try {
            $result = self::emitBody($context, $fn, $fn->getParam(0), true);
            $context->builder->returnValue($result);
        } finally {
            $context->activeFunction = $savedActive;
            $context->loweringLlvmFunction = $savedLowering;
            if (null !== $savedBlock) {
                $context->builder->positionAtEnd($savedBlock);
            } else {
                $context->builder->clearInsertionPosition();
            }
        }
    }

    private static function emitBody(
        Context $context,
        LlvmFunction $fn,
        Value $val,
        bool $useSerializePrecision
    ): Value {
        $s = ++self::$seq;
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');

        // Tokenize NAN/INF via snprintf then map; keeps AOT free of isnan/isinf link quirks.
        $raw = self::snprintfCall($context, $val, '%.16g', null);

        $nanBb = $fn->appendBasicBlock('zds_nan_'.$s);
        $infBb = $fn->appendBasicBlock('zds_inf_'.$s);
        $ninfBb = $fn->appendBasicBlock('zds_ninf_'.$s);
        $okBb = $fn->appendBasicBlock('zds_ok_'.$s);
        $doneBb = $fn->appendBasicBlock('zds_done_'.$s);

        $nanLit = $context->builder->load($context->constantStringFromString('nan'));
        // Use JitStringCompare SSOT — bare __string__compare was never implemented (#21948 AOT link).
        $isNan = JitStringCompare::identical($context, $raw, $nanLit);
        $afterNan = $fn->appendBasicBlock('zds_after_nan_'.$s);
        $context->builder->branchIf($isNan, $nanBb, $afterNan);

        $context->builder->positionAtEnd($nanBb);
        $nanStr = $context->builder->load($context->constantStringFromString('NAN'));
        $nanEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($afterNan);
        $infLit = $context->builder->load($context->constantStringFromString('inf'));
        $isInf = JitStringCompare::identical($context, $raw, $infLit);
        $afterInf = $fn->appendBasicBlock('zds_after_inf_'.$s);
        $context->builder->branchIf($isInf, $infBb, $afterInf);

        $context->builder->positionAtEnd($infBb);
        $infStr = $context->builder->load($context->constantStringFromString('INF'));
        $infEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($afterInf);
        $ninfLit = $context->builder->load($context->constantStringFromString('-inf'));
        $isNinf = JitStringCompare::identical($context, $raw, $ninfLit);
        $context->builder->branchIf($isNinf, $ninfBb, $okBb);

        $context->builder->positionAtEnd($ninfBb);
        $ninfStr = $context->builder->load($context->constantStringFromString('-INF'));
        $ninfEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        if ($useSerializePrecision) {
            // php_var_dump %.*H — PG(serialize_precision) default -1, not PG(precision) 14 (#32328).
            $prec = IniRuntime::loadSerializePrecision($context);
            $isZeroPrec = $context->builder->icmp(
                Builder::INT_EQ,
                $prec,
                $i32->constInt(0, false)
            );
            // zend_gcvt: ndigit <= 0 becomes 1 (Zend/zend_strtod.c).
            $prec = $context->builder->select($isZeroPrec, $i32->constInt(1, true), $prec);
        } else {
            $prec = IniRuntime::loadPrecision($context);
            $useDefault = $context->builder->icmp(
                Builder::INT_EQ,
                $prec,
                $i32->constInt(0, false)
            );
            $prec = $context->builder->select($useDefault, $i32->constInt(14, true), $prec);
        }
        $isNegPrec = $context->builder->icmp(Builder::INT_SLT, $prec, $i32->constInt(0, false));
        $dtoaBb = $fn->appendBasicBlock('zds_dtoa_'.$s);
        $precBb = $fn->appendBasicBlock('zds_prec_'.$s);
        $joinBb = $fn->appendBasicBlock('zds_join_'.$s);
        $context->builder->branchIf($isNegPrec, $dtoaBb, $precBb);

        $context->builder->positionAtEnd($dtoaBb);
        if ($useSerializePrecision) {
            // dtoa mode 0: shortest round-trip. %.16g misses 0.1+0.2; %.17g overshoots 1/3 (#32328).
            LibcExtern::ensureStrtodDecl($context);
            $strMap = $context->structFieldMap['__string__'];
            $charPtr = $context->builder->structGep($raw, $strMap['value']);
            $endNull = $context->getTypeFromString('int8**')->constNull();
            $parsed = $context->builder->call(
                $context->lookupFunction('strtod'),
                $charPtr,
                $endNull
            );
            $roundTrip = $context->builder->fcmp(Builder::REAL_OEQ, $parsed, $val);
            $keep16 = $fn->appendBasicBlock('zds_keep16_'.$s);
            $use17 = $fn->appendBasicBlock('zds_use17_'.$s);
            $dtoaJoin = $fn->appendBasicBlock('zds_dtoa_join_'.$s);
            $context->builder->branchIf($roundTrip, $keep16, $use17);

            $context->builder->positionAtEnd($keep16);
            $keep16End = $keep16;
            $context->builder->branch($dtoaJoin);

            $context->builder->positionAtEnd($use17);
            $raw17 = self::snprintfCall($context, $val, '%.17g', null);
            $use17End = $context->builder->getInsertBlock();
            $context->builder->branch($dtoaJoin);

            $context->builder->positionAtEnd($dtoaJoin);
            $dtoaPhi = $context->builder->phi($strPtr);
            $dtoaPhi->addIncoming($raw, $keep16End);
            $dtoaPhi->addIncoming($raw17, $use17End);
            $dtoaVal = $dtoaPhi;
            $dtoaEnd = $dtoaJoin;
            $context->builder->branch($joinBb);
        } else {
            // raw already %.16g — reuse for precision=-1
            $dtoaVal = $raw;
            $dtoaEnd = $dtoaBb;
            $context->builder->branch($joinBb);
        }

        $context->builder->positionAtEnd($precBb);
        $precStr = self::snprintfCall($context, $val, '%.*g', $prec);
        $precEnd = $context->builder->getInsertBlock();
        $context->builder->branch($joinBb);

        $context->builder->positionAtEnd($joinBb);
        $okPhi = $context->builder->phi($strPtr);
        $okPhi->addIncoming($dtoaVal, $dtoaEnd);
        $okPhi->addIncoming($precStr, $precEnd);
        $okEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($strPtr);
        $phi->addIncoming($nanStr, $nanEnd);
        $phi->addIncoming($infStr, $infEnd);
        $phi->addIncoming($ninfStr, $ninfEnd);
        $phi->addIncoming($okPhi, $okEnd);

        return $phi;
    }

    public static function zendifyGcvt(Context $context, Value $str): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return $str;
        }
        self::ensureZendifyLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ZENDIFY_ABI),
            $str
        );
    }

    private static function ensureZendifyLinked(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ZENDIFY_ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ZENDIFY_ABI, $probe);

            return;
        }

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        $savedActive = $context->activeFunction;
        $savedLowering = $context->loweringLlvmFunction;

        self::ensureDecls($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ZENDIFY_ABI,
                $context->context->functionType($strPtr, false, $strPtr)
            );
        $entry = $fn->appendBasicBlock(self::ZENDIFY_ENTRY);
        $context->registerFunction(self::ZENDIFY_ABI, $fn);
        $context->activeFunction = self::ZENDIFY_ABI;
        $context->loweringLlvmFunction = $fn instanceof LlvmFunction ? $fn : null;
        $context->builder->positionAtEnd($entry);
        try {
            self::emitZendifyBody($context, $fn, $fn->getParam(0));
        } finally {
            $context->activeFunction = $savedActive;
            $context->loweringLlvmFunction = $savedLowering;
            if (null !== $savedBlock) {
                BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
            } else {
                $context->builder->clearInsertionPosition();
            }
        }
    }

    /**
     * LLVM peer of {@see \PHPCompiler\ext\standard\VmZendDoubleString::zendifySnprintfG()}.
     *
     * php-src Zend/zend_strtod.c zend_gcvt E branch (#32316).
     */
    private static function emitZendifyBody(Context $context, LlvmFunction $fn, Value $str): void
    {
        $b = $context->builder;
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $map = $context->structFieldMap['__string__'];

        $len = $b->load($b->structGep($str, $map['length']));
        $data = $b->structGep($str, $map['value']);

        $ePosPtr = BasicBlockHelper::entryAlloca($context, $i64);
        $hasDotPtr = BasicBlockHelper::entryAlloca($context, $i8);
        $iPtr = BasicBlockHelper::entryAlloca($context, $i64);
        $b->store($len, $ePosPtr);
        $b->store($i8->constInt(0, false), $hasDotPtr);
        $b->store($i64->constInt(0, false), $iPtr);

        $scanHead = $fn->appendBasicBlock('zgcvt_scan_head');
        $scanBody = $fn->appendBasicBlock('zgcvt_scan_body');
        $foundE = $fn->appendBasicBlock('zgcvt_found_e');
        $scanNext = $fn->appendBasicBlock('zgcvt_scan_next');
        $scanDone = $fn->appendBasicBlock('zgcvt_scan_done');
        $rewrite = $fn->appendBasicBlock('zgcvt_rewrite');
        $done = $fn->appendBasicBlock('zgcvt_done');

        $b->branch($scanHead);
        $b->positionAtEnd($scanHead);
        $i = $b->load($iPtr);
        $b->branchIf($b->icmp(Builder::INT_ULT, $i, $len), $scanBody, $scanDone);

        $b->positionAtEnd($scanBody);
        $c = $b->load($b->gep($data, $i));
        $isDot = $b->icmp(Builder::INT_EQ, $c, $i8->constInt(\ord('.'), false));
        $b->store(
            $b->select($isDot, $i8->constInt(1, false), $b->load($hasDotPtr)),
            $hasDotPtr
        );
        $isE = $b->or(
            $b->icmp(Builder::INT_EQ, $c, $i8->constInt(\ord('e'), false)),
            $b->icmp(Builder::INT_EQ, $c, $i8->constInt(\ord('E'), false))
        );
        $b->branchIf($isE, $foundE, $scanNext);

        $b->positionAtEnd($foundE);
        $b->store($i, $ePosPtr);
        $b->branch($scanDone);

        $b->positionAtEnd($scanNext);
        $b->store($b->add($i, $i64->constInt(1, false)), $iPtr);
        $b->branch($scanHead);

        $b->positionAtEnd($scanDone);
        $ePos = $b->load($ePosPtr);
        $b->branchIf($b->icmp(Builder::INT_ULT, $ePos, $len), $rewrite, $done);
        $noEEnd = $scanDone;

        $b->positionAtEnd($rewrite);
        $outBuf = $b->call(
            $context->lookupFunction('__mm__malloc'),
            $sizeT->constInt(80, false)
        );
        $out = $b->pointerCast($outBuf, $i8p);
        $diPtr = BasicBlockHelper::entryAlloca($context, $i64);
        $siPtr = BasicBlockHelper::entryAlloca($context, $i64);
        $b->store($i64->constInt(0, false), $diPtr);
        $b->store($i64->constInt(0, false), $siPtr);

        $copyMantHead = $fn->appendBasicBlock('zgcvt_mant_head');
        $copyMantBody = $fn->appendBasicBlock('zgcvt_mant_body');
        $afterMant = $fn->appendBasicBlock('zgcvt_after_mant');
        $b->branch($copyMantHead);
        $b->positionAtEnd($copyMantHead);
        $si = $b->load($siPtr);
        $b->branchIf($b->icmp(Builder::INT_ULT, $si, $ePos), $copyMantBody, $afterMant);
        $b->positionAtEnd($copyMantBody);
        $di = $b->load($diPtr);
        $b->store($b->load($b->gep($data, $si)), $b->gep($out, $di));
        $b->store($b->add($si, $i64->constInt(1, false)), $siPtr);
        $b->store($b->add($di, $i64->constInt(1, false)), $diPtr);
        $b->branch($copyMantHead);

        $b->positionAtEnd($afterMant);
        $needDot = $fn->appendBasicBlock('zgcvt_need_dot');
        $afterDot = $fn->appendBasicBlock('zgcvt_after_dot');
        $b->branchIf(
            $b->icmp(Builder::INT_EQ, $b->load($hasDotPtr), $i8->constInt(0, false)),
            $needDot,
            $afterDot
        );
        $b->positionAtEnd($needDot);
        $di = $b->load($diPtr);
        $b->store($i8->constInt(\ord('.'), false), $b->gep($out, $di));
        $di1 = $b->add($di, $i64->constInt(1, false));
        $b->store($i8->constInt(\ord('0'), false), $b->gep($out, $di1));
        $b->store($b->add($di1, $i64->constInt(1, false)), $diPtr);
        $b->branch($afterDot);

        $b->positionAtEnd($afterDot);
        $di = $b->load($diPtr);
        $b->store($i8->constInt(\ord('E'), false), $b->gep($out, $di));
        $b->store($b->add($di, $i64->constInt(1, false)), $diPtr);

        $expIdx = $b->add($ePos, $i64->constInt(1, false));
        $b->store($expIdx, $siPtr);
        $haveSign = $fn->appendBasicBlock('zgcvt_have_sign');
        $signBody = $fn->appendBasicBlock('zgcvt_sign_body');
        $plusSign = $fn->appendBasicBlock('zgcvt_plus_sign');
        $afterSign = $fn->appendBasicBlock('zgcvt_after_sign');
        $b->branchIf($b->icmp(Builder::INT_ULT, $expIdx, $len), $haveSign, $plusSign);

        $b->positionAtEnd($haveSign);
        $sc = $b->load($b->gep($data, $expIdx));
        $isSign = $b->or(
            $b->icmp(Builder::INT_EQ, $sc, $i8->constInt(\ord('+'), false)),
            $b->icmp(Builder::INT_EQ, $sc, $i8->constInt(\ord('-'), false))
        );
        $b->branchIf($isSign, $signBody, $plusSign);

        $b->positionAtEnd($signBody);
        $di = $b->load($diPtr);
        $b->store($sc, $b->gep($out, $di));
        $b->store($b->add($di, $i64->constInt(1, false)), $diPtr);
        $b->store($b->add($expIdx, $i64->constInt(1, false)), $siPtr);
        $b->branch($afterSign);

        $b->positionAtEnd($plusSign);
        $di = $b->load($diPtr);
        $b->store($i8->constInt(\ord('+'), false), $b->gep($out, $di));
        $b->store($b->add($di, $i64->constInt(1, false)), $diPtr);
        $b->branch($afterSign);

        $b->positionAtEnd($afterSign);
        $skipHead = $fn->appendBasicBlock('zgcvt_skip_head');
        $skipBody = $fn->appendBasicBlock('zgcvt_skip_body');
        $skipDone = $fn->appendBasicBlock('zgcvt_skip_done');
        $b->branch($skipHead);
        $b->positionAtEnd($skipHead);
        $si = $b->load($siPtr);
        $inRange = $b->icmp(Builder::INT_ULT, $si, $len);
        $isZero = $fn->appendBasicBlock('zgcvt_is_zero');
        $b->branchIf($inRange, $isZero, $skipDone);
        $b->positionAtEnd($isZero);
        $zc = $b->load($b->gep($data, $si));
        $b->branchIf(
            $b->icmp(Builder::INT_EQ, $zc, $i8->constInt(\ord('0'), false)),
            $skipBody,
            $skipDone
        );
        $b->positionAtEnd($skipBody);
        $b->store($b->add($si, $i64->constInt(1, false)), $siPtr);
        $b->branch($skipHead);

        $b->positionAtEnd($skipDone);
        $emptyExp = $fn->appendBasicBlock('zgcvt_empty_exp');
        $copyExp = $fn->appendBasicBlock('zgcvt_copy_exp');
        $afterExp = $fn->appendBasicBlock('zgcvt_after_exp');
        $si = $b->load($siPtr);
        $b->branchIf($b->icmp(Builder::INT_ULT, $si, $len), $copyExp, $emptyExp);

        $b->positionAtEnd($emptyExp);
        $di = $b->load($diPtr);
        $b->store($i8->constInt(\ord('0'), false), $b->gep($out, $di));
        $b->store($b->add($di, $i64->constInt(1, false)), $diPtr);
        $b->branch($afterExp);

        $copyExpHead = $fn->appendBasicBlock('zgcvt_cexp_head');
        $copyExpBody = $fn->appendBasicBlock('zgcvt_cexp_body');
        $b->positionAtEnd($copyExp);
        $b->branch($copyExpHead);
        $b->positionAtEnd($copyExpHead);
        $si = $b->load($siPtr);
        $b->branchIf($b->icmp(Builder::INT_ULT, $si, $len), $copyExpBody, $afterExp);
        $b->positionAtEnd($copyExpBody);
        $di = $b->load($diPtr);
        $b->store($b->load($b->gep($data, $si)), $b->gep($out, $di));
        $b->store($b->add($si, $i64->constInt(1, false)), $siPtr);
        $b->store($b->add($di, $i64->constInt(1, false)), $diPtr);
        $b->branch($copyExpHead);

        $b->positionAtEnd($afterExp);
        $outLen = $b->load($diPtr);
        $newStr = $b->call(
            $context->lookupFunction('__string__init'),
            $outLen,
            $out
        );
        $b->call($context->lookupFunction('__mm__free'), $outBuf);
        $rewriteEnd = $b->getInsertBlock();
        $b->branch($done);

        $b->positionAtEnd($done);
        $phi = $b->phi($strPtr);
        $phi->addIncoming($str, $noEEnd);
        $phi->addIncoming($newStr, $rewriteEnd);
        $b->returnValue($phi);
    }

    private static function snprintfCall(
        Context $context,
        Value $doubleVal,
        string $fmt,
        ?Value $precisionArg
    ): Value {
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $i64 = $context->getTypeFromString('int64');
        $bufSize = $sizeT->constInt(64, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmtPtr = $context->builder->pointerCast($context->constantFromString($fmt), $charPtr);
        // snprintf(3) via LibcExtern::ensureSnprintf after always-on drop (#32092).
        // Always reuse the named decl — addFunction('snprintf') without getNamedFunction
        // silently creates snprintf.1 and Module.php:180 aborts (#32122 / #31894).
        LibcExtern::ensureSnprintf($context);
        if (null === $precisionArg) {
            $written = $context->builder->call(
                $context->lookupFunction('snprintf'),
                $bufChar,
                $bufSize,
                $fmtPtr,
                $doubleVal
            );
        } else {
            $written = $context->builder->call(
                $context->lookupFunction('snprintf'),
                $bufChar,
                $bufSize,
                $fmtPtr,
                $precisionArg,
                $doubleVal
            );
        }
        $len = $context->builder->zExt($written, $i64);
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $bufChar
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $buf);

        return $str;
    }

    private static function ensureDecls(Context $context): void
    {
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $voidTy = $context->getTypeFromString('void');

        LibcExtern::ensureSnprintf($context);
        foreach (
            [
                '__mm__malloc' => [$i8p, false, [$sizeT]],
                '__mm__free' => [$voidTy, false, [$i8p]],
                '__string__init' => [$strPtr, false, [$i64, $charPtr]],
                // Unused memcmp decl dropped with LibcExtern always-on (#31954).
            ] as $name => [$ret, $vararg, $params]
        ) {
            try {
                $context->lookupFunction($name);
                continue;
            } catch (\Throwable) {
            }
            // Reuse the module symbol when lookupFunction misses the registry (#32122 / #31894).
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                $fn = $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, $vararg, ...$params)
                );
            }
            $context->registerFunction($name, $fn);
        }
    }
}
