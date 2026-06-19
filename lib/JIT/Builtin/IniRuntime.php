<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM ini_set()/ini_get()/error_reporting/@ silence state (issue #5736, #1374, #4070).
 *
 * Replaces {@see lib/AOT/runtime/phpc_ini_set.c}. Semantics match {@see \PHPCompiler\ext\standard\VmIni}.
 * php-src: ext/standard/ini.c, main/php_ini.c
 */
final class IniRuntime
{
    private static int $blockSuffix = 0;

    private const G_DISPLAY_ERRORS = 'phpc_ini_display_errors';

    private const G_MEMORY_LIMIT = 'phpc_ini_memory_limit';

    private const G_SERIALIZE_PRECISION = 'phpc_ini_serialize_precision';

    private const MEMORY_LIMIT_CAP = 64;

    private const MEMORY_LIMIT_DEFAULT = '128M';

    private const SNPRINTF_BUF = 64;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::$blockSuffix = 0;
        $probe = $context->module->getNamedFunction('__compiler_ini_get');
        $cfgProbe = $context->module->getNamedFunction('__compiler_ini_cfg_get');
        if (null !== $probe && $probe->countBasicBlocks() > 0
            && null !== $cfgProbe && $cfgProbe->countBasicBlocks() > 0) {
            SilenceRuntime::ensureLinked($context);
            self::ensureIniRestore($context);
            self::registerLinkedRuntime($context);

            return;
        }

        $restoreBlock = self::captureInsertBlock($context);
        self::ensureGlobals($context);
        AssertIniRuntime::ensureGlobals($context);
        self::ensureLibc($context);
        self::ensureValueWriters($context);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $valPtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');

        SilenceRuntime::ensureLinked($context);

        $getProbe = $context->module->getNamedFunction('__compiler_ini_get');
        $ftGet = $context->context->functionType($voidTy, false, $strPtr, $valPtr);
        $fnGet = null !== $getProbe
            ? $getProbe
            : $context->module->addFunction('__compiler_ini_get', $ftGet);
        self::implementIniGet($context, $fnGet);

        $cfgGetProbe = $context->module->getNamedFunction('__compiler_ini_cfg_get');
        $fnCfgGet = null !== $cfgGetProbe
            ? $cfgGetProbe
            : $context->module->addFunction('__compiler_ini_cfg_get', $ftGet);
        self::implementIniCfgGet($context, $fnCfgGet);

        $setProbe = $context->module->getNamedFunction('__compiler_ini_set');
        $ftSet = $context->context->functionType($voidTy, false, $strPtr, $strPtr, $valPtr);
        $fnSet = null !== $setProbe
            ? $setProbe
            : $context->module->addFunction('__compiler_ini_set', $ftSet);
        self::implementIniSet($context, $fnSet);

        self::ensureIniRestore($context);

        self::registerLinkedRuntime($context);
        self::restoreInsertBlock($context, $restoreBlock);
    }

    private static function implementIniGet(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('ig_entry');
        $failBb = $fn->appendBasicBlock('ig_fail');
        $erBb = $fn->appendBasicBlock('ig_er');
        $deBb = $fn->appendBasicBlock('ig_de');
        $mlBb = $fn->appendBasicBlock('ig_ml');
        $spBb = $fn->appendBasicBlock('ig_sp');
        $zaBb = $fn->appendBasicBlock('ig_za');
        $aaBb = $fn->appendBasicBlock('ig_aa');
        $aeBb = $fn->appendBasicBlock('ig_ae');
        $testEr = $fn->appendBasicBlock('ig_test_er');
        $testDe = $fn->appendBasicBlock('ig_test_de');
        $testMl = $fn->appendBasicBlock('ig_test_ml');
        $testSp = $fn->appendBasicBlock('ig_test_sp');
        $testZa = $fn->appendBasicBlock('ig_test_za');
        $testAa = $fn->appendBasicBlock('ig_test_aa');
        $testAe = $fn->appendBasicBlock('ig_test_ae');

        $context->builder->positionAtEnd($entry);
        self::ensureMemoryLimitBuffer($context, $fn);
        $option = $fn->getParam(0);
        $out = $fn->getParam(1);
        $optCstr = self::copyStringObjectToCstr($context, $fn, $option);
        $optOk = $context->builder->icmp(
            Builder::INT_NE,
            $optCstr,
            $context->getTypeFromString('int8*')->constNull()
        );
        $context->builder->branchIf($optOk, $testEr, $failBb);

        self::branchIfKey($context, $testEr, $optCstr, 'error_reporting', $erBb, $testDe);
        self::branchIfKey($context, $testDe, $optCstr, 'display_errors', $deBb, $testMl);
        self::branchIfKey($context, $testMl, $optCstr, 'memory_limit', $mlBb, $testSp);
        self::branchIfKey($context, $testSp, $optCstr, 'serialize_precision', $spBb, $testZa);
        self::branchIfKey($context, $testZa, $optCstr, 'zend.assertions', $zaBb, $testAa);
        self::branchIfKey($context, $testAa, $optCstr, 'assert.active', $aaBb, $testAe);
        self::branchIfKey($context, $testAe, $optCstr, 'assert.exception', $aeBb, $failBb);

        $context->builder->positionAtEnd($erBb);
        SilenceRuntime::emitIniGetErrorReporting($context, $out);
        self::freeCstr($context, $fn, $optCstr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($deBb);
        self::writeValueStringFromDisplayErrorsGlobal($context, $fn, $out);
        self::freeCstr($context, $fn, $optCstr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($mlBb);
        self::writeValueStringFromMemoryLimitGlobal($context, $out);
        self::freeCstr($context, $fn, $optCstr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($spBb);
        self::writeValueStringFromSerializePrecisionGlobal($context, $out);
        self::freeCstr($context, $fn, $optCstr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($zaBb);
        AssertIniRuntime::writeIniGetZendAssertions($context, $out);
        self::freeCstr($context, $fn, $optCstr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($aaBb);
        AssertIniRuntime::writeIniGetActive($context, $out);
        self::freeCstr($context, $fn, $optCstr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($aeBb);
        AssertIniRuntime::writeIniGetException($context, $out);
        self::freeCstr($context, $fn, $optCstr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($failBb);
        self::writeValueBoolFalse($context, $out);
        self::freeCstr($context, $fn, $optCstr);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementIniCfgGet(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('icg_entry');
        $failBb = $fn->appendBasicBlock('icg_fail');
        $erBb = $fn->appendBasicBlock('icg_er');
        $deBb = $fn->appendBasicBlock('icg_de');
        $mlBb = $fn->appendBasicBlock('icg_ml');
        $spBb = $fn->appendBasicBlock('icg_sp');
        $testEr = $fn->appendBasicBlock('icg_test_er');
        $testDe = $fn->appendBasicBlock('icg_test_de');
        $testMl = $fn->appendBasicBlock('icg_test_ml');
        $testSp = $fn->appendBasicBlock('icg_test_sp');

        $context->builder->positionAtEnd($entry);
        $option = $fn->getParam(0);
        $out = $fn->getParam(1);
        $optCstr = self::copyStringObjectToCstr($context, $fn, $option);
        $optOk = $context->builder->icmp(
            Builder::INT_NE,
            $optCstr,
            $context->getTypeFromString('int8*')->constNull()
        );
        $context->builder->branchIf($optOk, $testEr, $failBb);

        self::branchIfKey($context, $testEr, $optCstr, 'error_reporting', $erBb, $testDe);
        self::branchIfKey($context, $testDe, $optCstr, 'display_errors', $deBb, $testMl);
        self::branchIfKey($context, $testMl, $optCstr, 'memory_limit', $mlBb, $testSp);
        self::branchIfKey($context, $testSp, $optCstr, 'serialize_precision', $spBb, $failBb);

        $i8p = $context->getTypeFromString('int8*');

        $context->builder->positionAtEnd($erBb);
        self::writeValueStringFromCstr(
            $context,
            $out,
            $context->builder->pointerCast(
                $context->constantFromString((string) ErrorReporter::DEFAULT_STARTUP_REPORTING),
                $i8p
            )
        );
        self::freeCstr($context, $fn, $optCstr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($deBb);
        self::writeValueStringFromCstr(
            $context,
            $out,
            $context->builder->pointerCast($context->constantFromString('1'), $i8p)
        );
        self::freeCstr($context, $fn, $optCstr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($mlBb);
        self::writeValueStringFromCstr(
            $context,
            $out,
            $context->builder->pointerCast($context->constantFromString(self::MEMORY_LIMIT_DEFAULT), $i8p)
        );
        self::freeCstr($context, $fn, $optCstr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($spBb);
        self::writeValueStringFromCstr(
            $context,
            $out,
            $context->builder->pointerCast($context->constantFromString('-1'), $i8p)
        );
        self::freeCstr($context, $fn, $optCstr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($failBb);
        self::writeValueBoolFalse($context, $out);
        self::freeCstr($context, $fn, $optCstr);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementIniSet(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('is_entry');
        $failBb = $fn->appendBasicBlock('is_fail');
        $erBb = $fn->appendBasicBlock('is_er');
        $deBb = $fn->appendBasicBlock('is_de');
        $mlBb = $fn->appendBasicBlock('is_ml');
        $spBb = $fn->appendBasicBlock('is_sp');
        $zaBb = $fn->appendBasicBlock('is_za');
        $aaBb = $fn->appendBasicBlock('is_aa');
        $aeBb = $fn->appendBasicBlock('is_ae');
        $mlApplyBb = $fn->appendBasicBlock('is_ml_apply');
        $testEr = $fn->appendBasicBlock('is_test_er');
        $testDe = $fn->appendBasicBlock('is_test_de');
        $testMl = $fn->appendBasicBlock('is_test_ml');
        $testSp = $fn->appendBasicBlock('is_test_sp');
        $testZa = $fn->appendBasicBlock('is_test_za');
        $testAa = $fn->appendBasicBlock('is_test_aa');
        $testAe = $fn->appendBasicBlock('is_test_ae');

        $context->builder->positionAtEnd($entry);
        self::ensureMemoryLimitBuffer($context, $fn);
        $option = $fn->getParam(0);
        $newValue = $fn->getParam(1);
        $out = $fn->getParam(2);
        $optCstr = self::copyStringObjectToCstr($context, $fn, $option);
        $valCstr = self::copyStringObjectToCstr($context, $fn, $newValue);
        $i8p = $context->getTypeFromString('int8*');
        $bothOk = $context->builder->and(
            $context->builder->icmp(Builder::INT_NE, $optCstr, $i8p->constNull()),
            $context->builder->icmp(Builder::INT_NE, $valCstr, $i8p->constNull())
        );
        $context->builder->branchIf($bothOk, $testEr, $failBb);

        self::branchIfKey($context, $testEr, $optCstr, 'error_reporting', $erBb, $testDe);
        self::branchIfKey($context, $testDe, $optCstr, 'display_errors', $deBb, $testMl);
        self::branchIfKey($context, $testMl, $optCstr, 'memory_limit', $mlBb, $testSp);
        self::branchIfKey($context, $testSp, $optCstr, 'serialize_precision', $spBb, $testZa);
        self::branchIfKey($context, $testZa, $optCstr, 'zend.assertions', $zaBb, $testAa);
        self::branchIfKey($context, $testAa, $optCstr, 'assert.active', $aaBb, $testAe);
        self::branchIfKey($context, $testAe, $optCstr, 'assert.exception', $aeBb, $failBb);

        $i32 = $context->getTypeFromString('int32');

        $context->builder->positionAtEnd($erBb);
        SilenceRuntime::emitIniGetErrorReporting($context, $out);
        $endPtrSlot = $context->builder->alloca($i8p, 1, 'ini_strtol_end');
        $context->builder->store($i8p->constNull(), $endPtrSlot);
        SilenceRuntime::emitSetErrorReporting(
            $context,
            $context->builder->trunc(
                $context->builder->call(
                    $context->lookupFunction('strtol'),
                    $valCstr,
                    $endPtrSlot,
                    $i32->constInt(10, false)
                ),
                $i32
            )
        );
        self::freeCstrPair($context, $fn, $optCstr, $valCstr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($deBb);
        self::writeValueStringFromDisplayErrorsGlobal($context, $fn, $out);
        $context->builder->store(
            self::emitParseBoolIni($context, $fn, $valCstr),
            self::globalPtr($context, self::G_DISPLAY_ERRORS, $i32)
        );
        self::freeCstrPair($context, $fn, $optCstr, $valCstr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($mlBb);
        $context->builder->branch($mlApplyBb);

        $context->builder->positionAtEnd($mlApplyBb);
        self::writeValueStringFromMemoryLimitGlobal($context, $out);
        self::storeMemoryLimitFromCstr($context, $valCstr);
        self::freeCstrPair($context, $fn, $optCstr, $valCstr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($spBb);
        self::writeValueStringFromSerializePrecisionGlobal($context, $out);
        $endPtrSlot = $context->builder->alloca($i8p, 1, 'ini_sp_strtol_end');
        $context->builder->store($i8p->constNull(), $endPtrSlot);
        $context->builder->store(
            $context->builder->trunc(
                $context->builder->call(
                    $context->lookupFunction('strtol'),
                    $valCstr,
                    $endPtrSlot,
                    $i32->constInt(10, false)
                ),
                $i32
            ),
            self::globalPtr($context, self::G_SERIALIZE_PRECISION, $i32)
        );
        self::freeCstrPair($context, $fn, $optCstr, $valCstr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($zaBb);
        AssertIniRuntime::writeIniGetZendAssertions($context, $out);
        AssertIniRuntime::applyIniSetZendAssertions($context, $fn, $valCstr);
        self::freeCstrPair($context, $fn, $optCstr, $valCstr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($aaBb);
        AssertIniRuntime::writeIniGetActive($context, $out);
        AssertIniRuntime::applyIniSetActive($context, $fn, $valCstr);
        self::freeCstrPair($context, $fn, $optCstr, $valCstr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($aeBb);
        AssertIniRuntime::writeIniGetException($context, $out);
        AssertIniRuntime::applyIniSetException($context, $fn, $valCstr);
        self::freeCstrPair($context, $fn, $optCstr, $valCstr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($failBb);
        self::writeValueBoolFalse($context, $out);
        self::freeCstrPair($context, $fn, $optCstr, $valCstr);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function ensureIniRestore(Context $context): void
    {
        $restoreProbe = $context->module->getNamedFunction('__compiler_ini_restore');
        if (null !== $restoreProbe && $restoreProbe->countBasicBlocks() > 0) {
            return;
        }

        $restoreBlock = self::captureInsertBlock($context);
        self::ensureGlobals($context);
        self::ensureLibc($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $voidTy = $context->getTypeFromString('void');
        $fnRestore = null !== $restoreProbe
            ? $restoreProbe
            : $context->module->addFunction(
                '__compiler_ini_restore',
                $context->context->functionType($voidTy, false, $strPtr)
            );
        self::implementIniRestore($context, $fnRestore);
        self::restoreInsertBlock($context, $restoreBlock);
    }

    private static function implementIniRestore(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('ir_entry');
        $failBb = $fn->appendBasicBlock('ir_fail');
        $erBb = $fn->appendBasicBlock('ir_er');
        $deBb = $fn->appendBasicBlock('ir_de');
        $mlBb = $fn->appendBasicBlock('ir_ml');
        $spBb = $fn->appendBasicBlock('ir_sp');
        $testEr = $fn->appendBasicBlock('ir_test_er');
        $testDe = $fn->appendBasicBlock('ir_test_de');
        $testMl = $fn->appendBasicBlock('ir_test_ml');
        $testSp = $fn->appendBasicBlock('ir_test_sp');

        $context->builder->positionAtEnd($entry);
        self::ensureMemoryLimitBuffer($context, $fn);
        $option = $fn->getParam(0);
        $optCstr = self::copyStringObjectToCstr($context, $fn, $option);
        $i8p = $context->getTypeFromString('int8*');
        $optOk = $context->builder->icmp(
            Builder::INT_NE,
            $optCstr,
            $i8p->constNull()
        );
        $context->builder->branchIf($optOk, $testEr, $failBb);

        self::branchIfKey($context, $testEr, $optCstr, 'error_reporting', $erBb, $testDe);
        self::branchIfKey($context, $testDe, $optCstr, 'display_errors', $deBb, $testMl);
        self::branchIfKey($context, $testMl, $optCstr, 'memory_limit', $mlBb, $testSp);
        self::branchIfKey($context, $testSp, $optCstr, 'serialize_precision', $spBb, $failBb);

        $i32 = $context->getTypeFromString('int32');

        $context->builder->positionAtEnd($erBb);
        SilenceRuntime::emitIniRestoreErrorReporting($context);
        self::freeCstr($context, $fn, $optCstr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($deBb);
        $context->builder->store(
            $i32->constInt(1, false),
            self::globalPtr($context, self::G_DISPLAY_ERRORS, $i32)
        );
        self::freeCstr($context, $fn, $optCstr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($mlBb);
        $defPtr = $context->builder->pointerCast(
            $context->constantFromString(self::MEMORY_LIMIT_DEFAULT),
            $i8p
        );
        self::storeMemoryLimitFromCstr($context, $defPtr);
        self::freeCstr($context, $fn, $optCstr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($spBb);
        $context->builder->store(
            $i32->constInt(-1, true),
            self::globalPtr($context, self::G_SERIALIZE_PRECISION, $i32)
        );
        self::freeCstr($context, $fn, $optCstr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($failBb);
        self::freeCstr($context, $fn, $optCstr);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function branchIfKey(
        Context $context,
        BasicBlock $testBb,
        Value $optCstr,
        string $key,
        BasicBlock $matchBb,
        BasicBlock $elseBb
    ): void {
        $context->builder->positionAtEnd($testBb);
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $lit = $context->builder->pointerCast($context->constantFromString($key), $i8p);
        $cmp = $context->builder->call($context->lookupFunction('strcasecmp'), $optCstr, $lit);
        $isMatch = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
        $context->builder->branchIf($isMatch, $matchBb, $elseBb);
    }

    private static function emitParseBoolIni(Context $context, Value $fn, Value $valCstr): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $valLen = $context->builder->call($context->lookupFunction('strlen'), $valCstr);

        $falseVal = $i32->constInt(0, false);
        $trueVal = $i32->constInt(1, false);
        $falseBb = $fn->appendBasicBlock('pbi_false');
        $trueBb = $fn->appendBasicBlock('pbi_true');
        $doneBb = $fn->appendBasicBlock('pbi_done');
        $checkBb = $fn->appendBasicBlock('pbi_check');
        $maybeBb = $fn->appendBasicBlock('pbi_maybe');

        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $valLen, $sizeT->constInt(0, false));
        $context->builder->branchIf($isEmpty, $falseBb, $checkBb);

        $context->builder->positionAtEnd($checkBb);
        $first = $context->builder->load($valCstr);
        $lenOne = $context->builder->icmp(Builder::INT_EQ, $valLen, $sizeT->constInt(1, false));
        $isZero = $context->builder->icmp(Builder::INT_EQ, $first, $i8->constInt(ord('0'), false));
        $isOne = $context->builder->icmp(Builder::INT_EQ, $first, $i8->constInt(ord('1'), false));
        $litOff = self::matchLit($context, $valCstr, $valLen, 'off', 3);
        $litFalse = self::matchLit($context, $valCstr, $valLen, 'false', 5);
        $isFalse = $context->builder->or(
            $context->builder->and($lenOne, $isZero),
            $context->builder->or($litOff, $litFalse)
        );
        $context->builder->branchIf($isFalse, $falseBb, $maybeBb);

        $context->builder->positionAtEnd($maybeBb);
        $litOn = self::matchLit($context, $valCstr, $valLen, 'on', 2);
        $litTrue = self::matchLit($context, $valCstr, $valLen, 'true', 4);
        $isTrue = $context->builder->or(
            $context->builder->and($lenOne, $isOne),
            $context->builder->or($litOn, $litTrue)
        );
        $context->builder->branchIf($isTrue, $trueBb, $trueBb);

        $context->builder->positionAtEnd($falseBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($trueBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($i32);
        $phi->addIncoming($falseVal, $falseBb);
        $phi->addIncoming($trueVal, $trueBb);

        return $phi;
    }

    private static function matchLit(
        Context $context,
        Value $valCstr,
        Value $valLen,
        string $lit,
        int $len
    ): Value {
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $lenOk = $context->builder->icmp(Builder::INT_EQ, $valLen, $sizeT->constInt($len, false));
        $litPtr = $context->builder->pointerCast($context->constantFromString($lit), $context->getTypeFromString('int8*'));
        $cmp = $context->builder->call(
            $context->lookupFunction('strncmp'),
            $valCstr,
            $litPtr,
            $sizeT->constInt($len, false)
        );

        return $context->builder->and(
            $lenOk,
            $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false))
        );
    }

    private static function writeValueBoolFalse(Context $context, Value $out): void
    {
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $context->getTypeFromString('int32')->constInt(0, false)
        );
    }

    private static function writeValueStringFromCstr(Context $context, Value $out, Value $cstr): void
    {
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->sext($len, $i64),
            $cstr
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $str);
    }

    private static function writeValueStringFromSerializePrecisionGlobal(Context $context, Value $out): void
    {
        $i32 = $context->getTypeFromString('int32');
        $buf = self::snprintfAlloca(
            $context,
            '%d',
            [$context->builder->load(self::globalPtr($context, self::G_SERIALIZE_PRECISION, $i32))]
        );
        self::writeValueStringFromCstr($context, $out, $buf);
    }

    private static function writeValueStringFromDisplayErrorsGlobal(Context $context, Value $fn, Value $out): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $de = $context->builder->load(self::globalPtr($context, self::G_DISPLAY_ERRORS, $i32));
        $isOn = $context->builder->icmp(Builder::INT_NE, $de, $i32->constInt(0, false));
        $oneBb = $fn->appendBasicBlock('ig_de_one');
        $zeroBb = $fn->appendBasicBlock('ig_de_zero');
        $doneBb = $fn->appendBasicBlock('ig_de_done');
        $context->builder->branchIf($isOn, $oneBb, $zeroBb);
        $context->builder->positionAtEnd($oneBb);
        $onePtr = $context->builder->pointerCast($context->constantFromString('1'), $i8p);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($zeroBb);
        $zeroPtr = $context->builder->pointerCast($context->constantFromString('0'), $i8p);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($i8p);
        $phi->addIncoming($onePtr, $oneBb);
        $phi->addIncoming($zeroPtr, $zeroBb);
        self::writeValueStringFromCstr($context, $out, $phi);
    }

    private static function writeValueStringFromMemoryLimitGlobal(Context $context, Value $out): void
    {
        self::writeValueStringFromCstr($context, $out, self::memoryLimitPtr($context));
    }

    private static function storeMemoryLimitFromCstr(Context $context, Value $src): void
    {
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $mlPtr = self::memoryLimitPtr($context);
        $maxCopy = $sizeT->constInt(self::MEMORY_LIMIT_CAP - 1, false);
        $srcLen = $context->builder->call($context->lookupFunction('strlen'), $src);
        $useSrc = $context->builder->icmp(Builder::INT_ULT, $srcLen, $maxCopy);
        $copyLen = $context->builder->select($useSrc, $srcLen, $maxCopy);
        $voidPtr = $context->getTypeFromString('void*');
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($mlPtr),
            $context->bytePtr($src),
            $copyLen
        );
        $end = $context->builder->gep($mlPtr, $copyLen);
        $context->builder->store($i8->constInt(0, false), $end);
    }

    /** @param list<Value> $extraArgs */
    private static function snprintfAlloca(Context $context, string $fmt, array $extraArgs): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $buf = $context->builder->alloca($i8, self::SNPRINTF_BUF, 'ini_snprintf');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $fmtPtr = $context->builder->pointerCast($context->constantFromString($fmt), $i8p);
        $args = [$bufPtr, $context->getTypeFromString('size_t')->constInt(self::SNPRINTF_BUF, false), $fmtPtr];
        foreach ($extraArgs as $arg) {
            $args[] = $arg;
        }
        $context->builder->call($context->lookupFunction('snprintf'), ...$args);

        return $bufPtr;
    }

    private static function memoryLimitPtr(Context $context): Value
    {
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->load(self::globalPtr($context, self::G_MEMORY_LIMIT, $i8p));
    }

    private static function ensureMemoryLimitBuffer(Context $context, Value $fn): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $slot = self::globalPtr($context, self::G_MEMORY_LIMIT, $i8p);
        $cur = $context->builder->load($slot);
        $needsInit = $context->builder->icmp(Builder::INT_EQ, $cur, $i8p->constNull());
        $initBb = $fn->appendBasicBlock('ini_ml_init');
        $doneBb = $fn->appendBasicBlock('ini_ml_ready');
        $context->builder->branchIf($needsInit, $initBb, $doneBb);

        $context->builder->positionAtEnd($initBb);
        $buf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $sizeT->constInt(self::MEMORY_LIMIT_CAP, false)
        );
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $defPtr = $context->builder->pointerCast(
            $context->constantFromString(self::MEMORY_LIMIT_DEFAULT),
            $i8p
        );
        $defLen = $context->builder->call($context->lookupFunction('strlen'), $defPtr);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($bufPtr),
            $context->bytePtr($defPtr),
            $defLen
        );
        $end = $context->builder->gep($bufPtr, $defLen);
        $context->builder->store(
            $context->getTypeFromString('int8')->constInt(0, false),
            $end
        );
        $context->builder->store($bufPtr, $slot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
    }

    private static function copyStringObjectToCstr(Context $context, Value $fn, Value $strObj): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $strPtrTy = $context->getTypeFromString('__string__*');
        $nullBb = $fn->appendBasicBlock('ics_null');
        $workBb = $fn->appendBasicBlock('ics_work');
        $doneBb = $fn->appendBasicBlock('ics_done');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $strObj, $strPtrTy->constNull());
        $context->builder->branchIf($isNull, $nullBb, $workBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($workBb);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($strObj, $map['length']));
        $bytes = $context->builder->structGep($strObj, $map['value']);
        $dup = self::dupBuffer($context, $bytes, $context->builder->zext($len, $context->getTypeFromString('size_t')));
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($i8p);
        $phi->addIncoming($i8p->constNull(), $nullBb);
        $phi->addIncoming($dup, $workBb);

        return $phi;
    }

    private static function dupBuffer(Context $context, Value $src, Value $len): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $allocSize = $context->builder->add($len, $sizeT->constInt(1, false));
        $raw = $context->builder->call($context->lookupFunction('malloc'), $allocSize);
        $out = $context->builder->pointerCast($raw, $i8p);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($out),
            $context->bytePtr($src),
            $len
        );
        $end = $context->builder->gep($out, $len);
        $context->builder->store($i8->constInt(0, false), $end);

        return $out;
    }

    private static function freeCstr(Context $context, Value $fn, Value $ptr): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $nonNull = $context->builder->icmp(Builder::INT_NE, $ptr, $i8p->constNull());
        $suffix = (string) ++self::$blockSuffix;
        $freeBb = $fn->appendBasicBlock('ini_free_'.$suffix);
        $skipBb = $fn->appendBasicBlock('ini_skip_'.$suffix);
        $contBb = $fn->appendBasicBlock('ini_cont_'.$suffix);
        $context->builder->branchIf($nonNull, $freeBb, $skipBb);
        $context->builder->positionAtEnd($freeBb);
        $context->builder->call($context->lookupFunction('free'), $ptr);
        $context->builder->branch($contBb);
        $context->builder->positionAtEnd($skipBb);
        $context->builder->branch($contBb);
        $context->builder->positionAtEnd($contBb);
    }

    private static function freeCstrPair(Context $context, Value $fn, Value $opt, Value $val): void
    {
        self::freeCstr($context, $fn, $opt);
        self::freeCstr($context, $fn, $val);
    }

    private static function globalPtr(Context $context, string $name, $llvmType): Value
    {
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            throw new \LogicException('IniRuntime global missing: '.$name);
        }

        return $context->builder->pointerCast($global, $llvmType->pointerType(0));
    }

    private static function ensureGlobals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');

        if (null === $context->module->getNamedGlobal(self::G_DISPLAY_ERRORS)) {
            $g = $context->module->addGlobal($i32, self::G_DISPLAY_ERRORS);
            $g->setInitializer($i32->constInt(1, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_MEMORY_LIMIT)) {
            $g = $context->module->addGlobal($i8p, self::G_MEMORY_LIMIT);
            $g->setInitializer($i8p->constNull());
        }
        if (null === $context->module->getNamedGlobal(self::G_SERIALIZE_PRECISION)) {
            $g = $context->module->addGlobal($i32, self::G_SERIALIZE_PRECISION);
            $g->setInitializer($i32->constInt(-1, true));
        }
    }

    private static function ensureLibc(Context $context): void
    {
        $voidPtr = $context->getTypeFromString('void*');
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        self::ensureExternal($context, 'malloc', $context->context->functionType($voidPtr, false, $sizeT));
        self::ensureExternal($context, 'free', $context->context->functionType($voidTy, false, $i8p));
        self::ensureExternal(
            $context,
            'memcpy',
            $context->context->functionType($voidPtr, false, $voidPtr, $voidPtr, $sizeT)
        );
        self::ensureExternal($context, 'strlen', $context->context->functionType($sizeT, false, $i8p));
        self::ensureExternal($context, 'strcmp', $context->context->functionType($i32, false, $i8p, $i8p));
        self::ensureExternal($context, 'strncmp', $context->context->functionType($i32, false, $i8p, $i8p, $sizeT));
        self::ensureExternal($context, 'strcasecmp', $context->context->functionType($i32, false, $i8p, $i8p));
        $i8pp = $i8p->pointerType(0);
        self::ensureExternal(
            $context,
            'strtol',
            $context->context->functionType($i64, false, $i8p, $i8pp, $i32)
        );
        self::ensureExternal(
            $context,
            'snprintf',
            $context->context->functionType($i32, false, $i8p, $sizeT, $i8p, $i32)
        );
    }

    private static function ensureValueWriters(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $valPtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $voidTy = $context->getTypeFromString('void');

        self::ensureExternal(
            $context,
            '__string__init',
            $context->context->functionType($strPtr, false, $i64, $context->getTypeFromString('int8*'))
        );
        self::ensureExternal(
            $context,
            '__value__writeString',
            $context->context->functionType($voidTy, false, $valPtr, $strPtr)
        );
        self::ensureExternal(
            $context,
            '__value__writeLong',
            $context->context->functionType($voidTy, false, $valPtr, $i64)
        );
        self::ensureExternal(
            $context,
            '__value__writeBool',
            $context->context->functionType($voidTy, false, $valPtr, $i32)
        );
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable $e) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (
            [
                '__compiler_phpc_error_level_enabled',
                '__compiler_ini_get',
                '__compiler_ini_cfg_get',
                '__compiler_ini_set',
                '__compiler_ini_restore',
                '__compiler_error_reporting',
                '__compiler_begin_silence',
                '__compiler_end_silence',
            ] as $name
        ) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after IniRuntime LLVM implement');
            }
            $context->registerFunction($name, $fn);
        }
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
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
