<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * assert_options() LLVM runtime (ext/standard/assert.c; issue #3316 phase 2).
 *
 * php-src: ext/standard/assert.c — PHP_FUNCTION(assert_options)
 */
final class AssertOptionsRuntime
{
    private const ASSERT_ACTIVE = 1;

    private const ASSERT_CALLBACK = 2;

    private const ASSERT_BAIL = 3;

    private const ASSERT_WARNING = 4;

    private const ASSERT_EXCEPTION = 5;

    public static function ensureLinked(Context $context): void
    {
        AssertIniRuntime::ensureGlobals($context);
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            self::implement($context);
        }
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_assert_options');
        if (null === $probe || $probe->countBasicBlocks() > 0) {
            return;
        }

        self::ensureLibc($context);
        self::ensureValueHelpers($context);
        self::implementAssertOptions($context, $probe);
    }

    private static function implementAssertOptions(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('aopt_entry');
        $activeBb = $fn->appendBasicBlock('aopt_active');
        $callbackBb = $fn->appendBasicBlock('aopt_callback');
        $bailBb = $fn->appendBasicBlock('aopt_bail');
        $warningBb = $fn->appendBasicBlock('aopt_warning');
        $exceptionBb = $fn->appendBasicBlock('aopt_exception');
        $failBb = $fn->appendBasicBlock('aopt_fail');
        $testCallback = $fn->appendBasicBlock('aopt_test_callback');
        $testBail = $fn->appendBasicBlock('aopt_test_bail');
        $testWarning = $fn->appendBasicBlock('aopt_test_warning');
        $testException = $fn->appendBasicBlock('aopt_test_exception');

        $context->builder->positionAtEnd($entry);

        $hasValue = $fn->getParam(0);
        $what = $fn->getParam(1);
        $valueIn = $fn->getParam(2);
        $out = $fn->getParam(3);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');

        $isActive = $context->builder->icmp(
            Builder::INT_EQ,
            $what,
            $i64->constInt(self::ASSERT_ACTIVE, false)
        );
        $context->builder->branchIf($isActive, $activeBb, $testCallback);

        $context->builder->positionAtEnd($testCallback);
        $isCallback = $context->builder->icmp(
            Builder::INT_EQ,
            $what,
            $i64->constInt(self::ASSERT_CALLBACK, false)
        );
        $context->builder->branchIf($isCallback, $callbackBb, $testBail);

        $context->builder->positionAtEnd($testBail);
        $isBail = $context->builder->icmp(
            Builder::INT_EQ,
            $what,
            $i64->constInt(self::ASSERT_BAIL, false)
        );
        $context->builder->branchIf($isBail, $bailBb, $testWarning);

        $context->builder->positionAtEnd($testWarning);
        $isWarning = $context->builder->icmp(
            Builder::INT_EQ,
            $what,
            $i64->constInt(self::ASSERT_WARNING, false)
        );
        $context->builder->branchIf($isWarning, $warningBb, $testException);

        $context->builder->positionAtEnd($testException);
        $isException = $context->builder->icmp(
            Builder::INT_EQ,
            $what,
            $i64->constInt(self::ASSERT_EXCEPTION, false)
        );
        $context->builder->branchIf($isException, $exceptionBb, $failBb);

        self::implementIntOption($context, $fn, $activeBb, AssertIniRuntime::G_ASSERT_ACTIVE, $hasValue, $valueIn, $out);
        self::implementIntOption($context, $fn, $bailBb, AssertIniRuntime::G_ASSERT_BAIL, $hasValue, $valueIn, $out);
        self::implementIntOption($context, $fn, $exceptionBb, AssertIniRuntime::G_ASSERT_EXCEPTION, $hasValue, $valueIn, $out);
        self::implementCallbackOption($context, $fn, $callbackBb, $hasValue, $valueIn, $out);

        $context->builder->positionAtEnd($warningBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(0, false)
        );
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($failBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(0, false)
        );
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementIntOption(
        Context $context,
        Value $fn,
        BasicBlock $block,
        string $globalName,
        Value $hasValue,
        Value $valueIn,
        Value $out
    ): void {
        $context->builder->positionAtEnd($block);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $globalPtr = AssertIniRuntime::globalPtr($context, $globalName);
        $old = $context->builder->load($globalPtr);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $out,
            $context->builder->sext($old, $i64)
        );

        $applyBb = $fn->appendBasicBlock('aopt_'.$globalName.'_apply');
        $doneBb = $fn->appendBasicBlock('aopt_'.$globalName.'_done');
        $shouldApply = $context->builder->icmp(Builder::INT_NE, $hasValue, $i32->constInt(0, false));
        $context->builder->branchIf($shouldApply, $applyBb, $doneBb);

        $context->builder->positionAtEnd($applyBb);
        $truthy = self::loadTruthyFromValue($context, $fn, $valueIn);
        $context->builder->store($truthy, $globalPtr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }

    private static function implementCallbackOption(
        Context $context,
        Value $fn,
        BasicBlock $block,
        Value $hasValue,
        Value $valueIn,
        Value $out
    ): void {
        $context->builder->positionAtEnd($block);
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $globalPtr = AssertIniRuntime::callbackGlobalPtr($context);
        $oldPtr = $context->builder->load($globalPtr);

        $emptyBb = $fn->appendBasicBlock('aopt_cb_empty');
        $copyBb = $fn->appendBasicBlock('aopt_cb_copy');
        $afterReadBb = $fn->appendBasicBlock('aopt_cb_after_read');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $oldPtr, $i8p->constNull());
        $context->builder->branchIf($isNull, $emptyBb, $copyBb);

        $context->builder->positionAtEnd($emptyBb);
        self::writeValueStringFromCstr($context, $out, $context->constantFromString(''));
        $context->builder->branch($afterReadBb);

        $context->builder->positionAtEnd($copyBb);
        self::writeValueStringFromCstr($context, $out, $oldPtr);
        $context->builder->branch($afterReadBb);

        $applyBb = $fn->appendBasicBlock('aopt_cb_apply');
        $doneBb = $fn->appendBasicBlock('aopt_cb_done');
        $context->builder->positionAtEnd($afterReadBb);
        $shouldApply = $context->builder->icmp(Builder::INT_NE, $hasValue, $i32->constInt(0, false));
        $context->builder->branchIf($shouldApply, $applyBb, $doneBb);

        $context->builder->positionAtEnd($applyBb);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valueIn, $map['type'])
        );
        $stringTy = $context->getTypeFromString('int8')->constInt(VmVariable::TYPE_STRING, false);
        $isString = $context->builder->icmp(Builder::INT_EQ, $typeByte, $stringTy);
        $rejectBb = $fn->appendBasicBlock('aopt_cb_reject');
        $storeBb = $fn->appendBasicBlock('aopt_cb_store');
        $context->builder->branchIf($isString, $storeBb, $rejectBb);

        $context->builder->positionAtEnd($rejectBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($storeBb);
        $strPtr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valueIn
        );
        $strMap = $context->structFieldMap['__string__'];
        $strLen = $context->builder->load(
            $context->builder->structGep($strPtr, $strMap['length'])
        );
        $strData = $context->builder->pointerCast(
            $context->builder->structGep($strPtr, $strMap['value']),
            $i8p
        );
        $i64 = $context->getTypeFromString('int64');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $strLen, $i64->constInt(0, false));
        $freeOldBb = $fn->appendBasicBlock('aopt_cb_free_old');
        $setNullBb = $fn->appendBasicBlock('aopt_cb_set_null');
        $dupBb = $fn->appendBasicBlock('aopt_cb_dup');
        $context->builder->branchIf($isEmpty, $freeOldBb, $dupBb);

        $context->builder->positionAtEnd($freeOldBb);
        $notNull = $context->builder->icmp(Builder::INT_NE, $oldPtr, $i8p->constNull());
        $freeBb = $fn->appendBasicBlock('aopt_cb_do_free');
        $afterFreeBb = $fn->appendBasicBlock('aopt_cb_after_free');
        $context->builder->branchIf($notNull, $freeBb, $afterFreeBb);
        $context->builder->positionAtEnd($freeBb);
        $context->builder->call($context->lookupFunction('free'), $oldPtr);
        $context->builder->branch($afterFreeBb);
        $context->builder->positionAtEnd($afterFreeBb);
        $context->builder->store($i8p->constNull(), $globalPtr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($dupBb);
        $allocLen = $context->builder->add($strLen, $i64->constInt(1, false));
        $allocSize = $context->builder->trunc($allocLen, $sizeT);
        $newPtr = $context->builder->pointerCast(
            $context->builder->call($context->lookupFunction('malloc'), $allocSize),
            $i8p
        );
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $newPtr,
            $strData,
            $context->builder->trunc($strLen, $sizeT)
        );
        $term = $context->builder->inBoundsGEP($newPtr, $context->builder->trunc($strLen, $sizeT));
        $context->builder->store($context->getTypeFromString('int8')->constInt(0, false), $term);
        $hadOld = $context->builder->icmp(Builder::INT_NE, $oldPtr, $i8p->constNull());
        $freeOld2Bb = $fn->appendBasicBlock('aopt_cb_free_old2');
        $afterDupBb = $fn->appendBasicBlock('aopt_cb_after_dup');
        $context->builder->branchIf($hadOld, $freeOld2Bb, $afterDupBb);
        $context->builder->positionAtEnd($freeOld2Bb);
        $context->builder->call($context->lookupFunction('free'), $oldPtr);
        $context->builder->branch($afterDupBb);
        $context->builder->positionAtEnd($afterDupBb);
        $context->builder->store($newPtr, $globalPtr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }

    private static function loadTruthyFromValue(Context $context, Value $fn, Value $valueIn): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valueIn, $map['type'])
        );

        $nullTy = $i8->constInt(VmVariable::TYPE_NULL, false);
        $undefTy = $i8->constInt(VmVariable::TYPE_UNDEFINED, false);
        $boolTy = $i8->constInt(JITVariable::TYPE_NATIVE_BOOL, false);
        $intTy = $i8->constInt(VmVariable::TYPE_INTEGER, false);
        $floatTy = $i8->constInt(VmVariable::TYPE_FLOAT, false);
        $stringTy = $i8->constInt(VmVariable::TYPE_STRING, false);

        $falseBb = $fn->appendBasicBlock('aopt_truthy_false');
        $boolBodyBb = $fn->appendBasicBlock('aopt_truthy_bool_body');
        $boolSetBb = $fn->appendBasicBlock('aopt_truthy_bool_set');
        $intBb = $fn->appendBasicBlock('aopt_truthy_int');
        $floatBodyBb = $fn->appendBasicBlock('aopt_truthy_float_body');
        $stringBb = $fn->appendBasicBlock('aopt_truthy_string');
        $defaultBb = $fn->appendBasicBlock('aopt_truthy_default');
        $doneBb = $fn->appendBasicBlock('aopt_truthy_done');
        $testIntBb = $fn->appendBasicBlock('aopt_truthy_test_int');
        $testFloatBb = $fn->appendBasicBlock('aopt_truthy_test_float');
        $testStringBb = $fn->appendBasicBlock('aopt_truthy_test_string');

        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $nullTy);
        $isUndef = $context->builder->icmp(Builder::INT_EQ, $typeByte, $undefTy);
        $falsy = $context->builder->or($isNull, $isUndef);
        $context->builder->branchIf($falsy, $falseBb, $boolBodyBb);

        $context->builder->positionAtEnd($falseBb);
        $falseVal = $i32->constInt(0, false);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($boolBodyBb);
        $isBool = $context->builder->icmp(Builder::INT_EQ, $typeByte, $boolTy);
        $context->builder->branchIf($isBool, $boolSetBb, $testIntBb);

        $context->builder->positionAtEnd($boolSetBb);
        $valueField = $context->builder->structGep($valueIn, $map['value']);
        $boolByte = $context->builder->load(
            $context->builder->inBoundsGEP(
                $valueField,
                $i32->constInt(0, false),
                $i64->constInt(0, false)
            )
        );
        $boolVal = $context->builder->zext(
            $context->builder->icmp(Builder::INT_NE, $boolByte, $i8->constInt(0, false)),
            $i32
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($testIntBb);
        $isInt = $context->builder->icmp(Builder::INT_EQ, $typeByte, $intTy);
        $context->builder->branchIf($isInt, $intBb, $testFloatBb);

        $context->builder->positionAtEnd($intBb);
        $num = $context->builder->call($context->lookupFunction('__value__readLong'), $valueIn);
        $intVal = $context->builder->zext(
            $context->builder->icmp(Builder::INT_NE, $num, $i64->constInt(0, false)),
            $i32
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($testFloatBb);
        $isFloat = $context->builder->icmp(Builder::INT_EQ, $typeByte, $floatTy);
        $context->builder->branchIf($isFloat, $floatBodyBb, $testStringBb);

        $context->builder->positionAtEnd($floatBodyBb);
        $dbl = $context->builder->call($context->lookupFunction('__value__readDouble'), $valueIn);
        $floatVal = $context->builder->zext(
            $context->builder->fcmp(Builder::REAL_ONE, $dbl, $dbl->typeOf()->constReal(0.0)),
            $i32
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($testStringBb);
        $isStr = $context->builder->icmp(Builder::INT_EQ, $typeByte, $stringTy);
        $context->builder->branchIf($isStr, $stringBb, $defaultBb);

        $context->builder->positionAtEnd($stringBb);
        $strPtr = $context->builder->call($context->lookupFunction('__value__readString'), $valueIn);
        $strMap = $context->structFieldMap['__string__'];
        $strLen = $context->builder->load(
            $context->builder->structGep($strPtr, $strMap['length'])
        );
        $strVal = $context->builder->zext(
            $context->builder->icmp(Builder::INT_NE, $strLen, $i64->constInt(0, false)),
            $i32
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($defaultBb);
        $defaultVal = $i32->constInt(1, false);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $result = $context->builder->phi($i32);
        $result->addIncoming($falseVal, $falseBb);
        $result->addIncoming($boolVal, $boolSetBb);
        $result->addIncoming($intVal, $intBb);
        $result->addIncoming($floatVal, $floatBodyBb);
        $result->addIncoming($strVal, $stringBb);
        $result->addIncoming($defaultVal, $defaultBb);

        return $result;
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

    private static function ensureLibc(Context $context): void
    {
        $voidPtr = $context->getTypeFromString('void*');
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');

        self::ensureExternal($context, 'malloc', $context->context->functionType($voidPtr, false, $sizeT));
        self::ensureExternal($context, 'free', $context->context->functionType($voidTy, false, $i8p));
        self::ensureExternal(
            $context,
            'memcpy',
            $context->context->functionType($voidPtr, false, $voidPtr, $voidPtr, $sizeT)
        );
        self::ensureExternal($context, 'strlen', $context->context->functionType($sizeT, false, $i8p));
    }

    private static function ensureValueHelpers(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $valPtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $dbl = $context->getTypeFromString('double');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $voidTy = $context->getTypeFromString('void');

        self::ensureExternal(
            $context,
            '__string__init',
            $context->context->functionType($strPtr, false, $i64, $i8p)
        );
        foreach ([
            ['__value__readLong', $i64, [$valPtr]],
            ['__value__readDouble', $dbl, [$valPtr]],
            ['__value__readString', $strPtr, [$valPtr]],
            ['__value__writeLong', $voidTy, [$valPtr, $i64]],
            ['__value__writeBool', $voidTy, [$valPtr, $i32]],
            ['__value__writeString', $voidTy, [$valPtr, $strPtr]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }
}
