<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * assert_options() JIT/AOT bridge via AssertOptionsJitHelper PHP (#9513).
 *
 * php-src: ext/standard/assert.c — PHP_FUNCTION(assert_options)
 */
final class AssertOptionsRuntime
{
    private const HELPER_PATH = '/ext/standard/AssertOptionsJitHelper.php';

    public const IS_ENABLED = 'PHPCompiler\\ext\\standard\\AssertOptionsJitHelper::isEnabled';

    public const EXCEPTION_MODE = 'PHPCompiler\\ext\\standard\\AssertOptionsJitHelper::shouldThrowOnFailure';

    private const GET_ACTIVE = 'PHPCompiler\\ext\\standard\\AssertOptionsJitHelper::getActiveInt';

    private const SET_ACTIVE = 'PHPCompiler\\ext\\standard\\AssertOptionsJitHelper::setActiveBool';

    private const GET_BAIL = 'PHPCompiler\\ext\\standard\\AssertOptionsJitHelper::getBailInt';

    private const SET_BAIL = 'PHPCompiler\\ext\\standard\\AssertOptionsJitHelper::setBailBool';

    private const GET_EXCEPTION = 'PHPCompiler\\ext\\standard\\AssertOptionsJitHelper::getExceptionInt';

    private const SET_EXCEPTION = 'PHPCompiler\\ext\\standard\\AssertOptionsJitHelper::setExceptionBool';

    private const GET_CALLBACK = 'PHPCompiler\\ext\\standard\\AssertOptionsJitHelper::getCallbackString';

    private const SET_CALLBACK = 'PHPCompiler\\ext\\standard\\AssertOptionsJitHelper::setCallbackString';

    public const INI_GET_ZEND_ASSERTIONS = 'PHPCompiler\\ext\\standard\\AssertOptionsJitHelper::iniGetZendAssertions';

    public const INI_SET_ZEND_ASSERTIONS = 'PHPCompiler\\ext\\standard\\AssertOptionsJitHelper::iniSetZendAssertionsFromString';

    public const INI_GET_ACTIVE = 'PHPCompiler\\ext\\standard\\AssertOptionsJitHelper::iniGetActive';

    public const INI_SET_ACTIVE = 'PHPCompiler\\ext\\standard\\AssertOptionsJitHelper::iniSetActiveFromString';

    public const INI_GET_EXCEPTION = 'PHPCompiler\\ext\\standard\\AssertOptionsJitHelper::iniGetException';

    public const INI_SET_EXCEPTION = 'PHPCompiler\\ext\\standard\\AssertOptionsJitHelper::iniSetExceptionFromString';

    private const ASSERT_ACTIVE = 1;

    private const ASSERT_CALLBACK = 2;

    private const ASSERT_BAIL = 3;

    private const ASSERT_WARNING = 4;

    private const ASSERT_EXCEPTION = 5;

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::IS_ENABLED,
        self::EXCEPTION_MODE,
        self::GET_ACTIVE,
        self::SET_ACTIVE,
        self::GET_BAIL,
        self::SET_BAIL,
        self::GET_EXCEPTION,
        self::SET_EXCEPTION,
        self::GET_CALLBACK,
        self::SET_CALLBACK,
        self::INI_GET_ZEND_ASSERTIONS,
        self::INI_SET_ZEND_ASSERTIONS,
        self::INI_GET_ACTIVE,
        self::INI_SET_ACTIVE,
        self::INI_GET_EXCEPTION,
        self::INI_SET_EXCEPTION,
    ];

    public static function ensureLinked(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            self::implement($context);
        }
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implementStandaloneStubs($context);
    }

    public static function lookupHelper(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after AssertOptionsJitHelper compile (#9513)');
        }

        return $fn;
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_assert_options');
        if (null === $probe || $probe->countBasicBlocks() > 0) {
            return;
        }

        self::ensureJitHelperCompiled($context);
        self::ensureValueHelpers($context);
        self::implementAbiBridges($context);
        self::implementAssertOptions($context, $probe);
    }

    /** Standalone AOT: const/default ABI stubs without nested AssertOptionsJitHelper JIT (#9225). */
    private static function implementStandaloneStubs(Context $context): void
    {
        $restoreBlock = BasicBlockHelper::tryGetInsertBlock($context);

        self::implementStandaloneConstBoolBridge($context, AssertIniRuntime::ABI_ENABLED, false);
        self::implementStandaloneConstBoolBridge($context, AssertIniRuntime::ABI_EXCEPTION_MODE, true);
        self::implementStandaloneIniGetBridge($context, AssertIniRuntime::ABI_INI_GET_ZEND_ASSERTIONS, '-1');
        self::implementStandaloneIniGetBridge($context, AssertIniRuntime::ABI_INI_GET_ACTIVE, '1');
        self::implementStandaloneIniGetBridge($context, AssertIniRuntime::ABI_INI_GET_EXCEPTION, '1');
        self::implementStandaloneIniSetNoopBridge($context, AssertIniRuntime::ABI_INI_SET_ZEND_ASSERTIONS);
        self::implementStandaloneIniSetNoopBridge($context, AssertIniRuntime::ABI_INI_SET_ACTIVE);
        self::implementStandaloneIniSetNoopBridge($context, AssertIniRuntime::ABI_INI_SET_EXCEPTION);

        $probe = $context->module->getNamedFunction('__compiler_assert_options');
        if (null !== $probe && 0 === $probe->countBasicBlocks()) {
            $entry = $probe->appendBasicBlock('aopt_standalone_stub');
            $context->builder->positionAtEnd($entry);
            self::writeBoolFalse($context, $probe->getParam(3));
            $context->builder->returnVoid();
            $context->registerFunction('__compiler_assert_options', $probe);
        }

        if (null !== $restoreBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $restoreBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementStandaloneConstBoolBridge(Context $context, string $abiName, bool $value): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i1 = $context->getTypeFromString('int1');
        $ft = $context->context->functionType($i1, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('assert_abi_standalone_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue($i1->constInt($value ? 1 : 0, false));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementStandaloneIniGetBridge(Context $context, string $abiName, string $literal): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $valPtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $valPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('assert_ini_get_standalone_entry');
        $context->builder->positionAtEnd($entry);
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $cstr = $context->constantFromString($literal);
        $len = $sizeT->constInt(\strlen($literal), false);
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->sext($len, $i64),
            $context->builder->pointerCast($cstr, $context->getTypeFromString('char*'))
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $fn->getParam(0), $str);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
        self::ensureExternal($context, '__string__init', $context->context->functionType(
            $context->getTypeFromString('__string__*'),
            false,
            $i64,
            $context->getTypeFromString('char*')
        ));
    }

    private static function implementStandaloneIniSetNoopBridge(Context $context, string $abiName): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8p = $context->getTypeFromString('int8*');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $i8p);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('assert_ini_set_standalone_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementAbiBridges(Context $context): void
    {
        self::implementBoolAbiBridge($context, AssertIniRuntime::ABI_ENABLED, self::IS_ENABLED);
        self::implementBoolAbiBridge($context, AssertIniRuntime::ABI_EXCEPTION_MODE, self::EXCEPTION_MODE);
        self::implementIniGetAbiBridge($context, AssertIniRuntime::ABI_INI_GET_ZEND_ASSERTIONS, self::INI_GET_ZEND_ASSERTIONS);
        self::implementIniGetAbiBridge($context, AssertIniRuntime::ABI_INI_GET_ACTIVE, self::INI_GET_ACTIVE);
        self::implementIniGetAbiBridge($context, AssertIniRuntime::ABI_INI_GET_EXCEPTION, self::INI_GET_EXCEPTION);
        self::implementIniSetAbiBridge($context, AssertIniRuntime::ABI_INI_SET_ZEND_ASSERTIONS, self::INI_SET_ZEND_ASSERTIONS);
        self::implementIniSetAbiBridge($context, AssertIniRuntime::ABI_INI_SET_ACTIVE, self::INI_SET_ACTIVE);
        self::implementIniSetAbiBridge($context, AssertIniRuntime::ABI_INI_SET_EXCEPTION, self::INI_SET_EXCEPTION);
    }

    private static function implementBoolAbiBridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i1 = $context->getTypeFromString('int1');
        $ft = $context->context->functionType($i1, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('assert_abi_entry');
        $context->builder->positionAtEnd($entry);
        $enabled = $context->builder->call(self::lookupHelper($context, $helperLogical));
        $context->builder->returnValue($enabled);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementIniGetAbiBridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $valPtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $valPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('assert_ini_get_entry');
        $context->builder->positionAtEnd($entry);
        $out = $fn->getParam(0);
        $str = $context->builder->call(self::lookupHelper($context, $helperLogical));
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $str);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementIniSetAbiBridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $i8p);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('assert_ini_set_entry');
        $context->builder->positionAtEnd($entry);
        $cstr = $fn->getParam(0);
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);
        $valStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->sext($len, $i64),
            $context->builder->pointerCast($cstr, $context->getTypeFromString('char*'))
        );
        $context->builder->call(self::lookupHelper($context, $helperLogical), $valStr);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
        self::ensureExternal($context, 'strlen', $context->context->functionType($sizeT, false, $i8p));
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

        self::implementIntOption($context, $fn, $activeBb, self::GET_ACTIVE, self::SET_ACTIVE, $hasValue, $valueIn, $out);
        self::implementIntOption($context, $fn, $bailBb, self::GET_BAIL, self::SET_BAIL, $hasValue, $valueIn, $out);
        self::implementIntOption($context, $fn, $exceptionBb, self::GET_EXCEPTION, self::SET_EXCEPTION, $hasValue, $valueIn, $out);
        self::implementCallbackOption($context, $fn, $callbackBb, $hasValue, $valueIn, $out);

        $context->builder->positionAtEnd($warningBb);
        self::writeBoolFalse($context, $out);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($failBb);
        self::writeBoolFalse($context, $out);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementIntOption(
        Context $context,
        Value $fn,
        BasicBlock $block,
        string $getHelper,
        string $setHelper,
        Value $hasValue,
        Value $valueIn,
        Value $out
    ): void {
        $context->builder->positionAtEnd($block);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');

        $old = $context->builder->call(self::lookupHelper($context, $getHelper));
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $out,
            $context->builder->sext($old, $i64)
        );

        $applyBb = $fn->appendBasicBlock('aopt_apply_'.(string) ++self::$blockSeq);
        $doneBb = $fn->appendBasicBlock('aopt_done_'.(string) self::$blockSeq);
        $shouldApply = $context->builder->icmp(Builder::INT_NE, $hasValue, $i32->constInt(0, false));
        $context->builder->branchIf($shouldApply, $applyBb, $doneBb);

        $context->builder->positionAtEnd($applyBb);
        $truthy = self::coerceTruthyFromValue($context, $fn, $valueIn);
        $context->builder->call(self::lookupHelper($context, $setHelper), $truthy);
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
        $i8 = $context->getTypeFromString('int8');

        $oldStr = $context->builder->call(self::lookupHelper($context, self::GET_CALLBACK));
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $oldStr);

        $applyBb = $fn->appendBasicBlock('aopt_cb_apply_'.(string) ++self::$blockSeq);
        $doneBb = $fn->appendBasicBlock('aopt_cb_done_'.(string) self::$blockSeq);
        $shouldApply = $context->builder->icmp(Builder::INT_NE, $hasValue, $i32->constInt(0, false));
        $context->builder->branchIf($shouldApply, $applyBb, $doneBb);

        $context->builder->positionAtEnd($applyBb);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valueIn, $map['type'])
        );
        $stringTy = $i8->constInt(VmVariable::TYPE_STRING, false);
        $isString = $context->builder->icmp(Builder::INT_EQ, $typeByte, $stringTy);
        $rejectBb = $fn->appendBasicBlock('aopt_cb_reject_'.(string) self::$blockSeq);
        $storeBb = $fn->appendBasicBlock('aopt_cb_store_'.(string) self::$blockSeq);
        $context->builder->branchIf($isString, $storeBb, $rejectBb);

        $context->builder->positionAtEnd($rejectBb);
        self::writeBoolFalse($context, $out);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($storeBb);
        $strPtr = $context->builder->call($context->lookupFunction('__value__readString'), $valueIn);
        $strMap = $context->structFieldMap['__string__'];
        $strLen = $context->builder->load(
            $context->builder->structGep($strPtr, $strMap['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $strLen, $i64->constInt(0, false));
        $emptyBb = $fn->appendBasicBlock('aopt_cb_empty_'.(string) self::$blockSeq);
        $copyBb = $fn->appendBasicBlock('aopt_cb_copy_'.(string) self::$blockSeq);
        $afterSetBb = $fn->appendBasicBlock('aopt_cb_after_set_'.(string) self::$blockSeq);
        $context->builder->branchIf($isEmpty, $emptyBb, $copyBb);

        $context->builder->positionAtEnd($emptyBb);
        $emptyStr = self::literalEmptyString($context);
        $context->builder->call(self::lookupHelper($context, self::SET_CALLBACK), $emptyStr);
        $context->builder->branch($afterSetBb);

        $context->builder->positionAtEnd($copyBb);
        $context->builder->call(self::lookupHelper($context, self::SET_CALLBACK), $strPtr);
        $context->builder->branch($afterSetBb);

        $context->builder->positionAtEnd($afterSetBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }

    private static int $blockSeq = 0;

    private static function coerceTruthyFromValue(Context $context, Value $fn, Value $valueIn): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
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

        $falseBb = $fn->appendBasicBlock('aopt_truthy_false_'.(string) ++self::$blockSeq);
        $boolBb = $fn->appendBasicBlock('aopt_truthy_bool_'.(string) self::$blockSeq);
        $intBb = $fn->appendBasicBlock('aopt_truthy_int_'.(string) self::$blockSeq);
        $floatBb = $fn->appendBasicBlock('aopt_truthy_float_'.(string) self::$blockSeq);
        $stringBb = $fn->appendBasicBlock('aopt_truthy_string_'.(string) self::$blockSeq);
        $defaultBb = $fn->appendBasicBlock('aopt_truthy_default_'.(string) self::$blockSeq);
        $doneBb = $fn->appendBasicBlock('aopt_truthy_done_'.(string) self::$blockSeq);
        $testBool = $fn->appendBasicBlock('aopt_truthy_test_bool_'.(string) self::$blockSeq);
        $testInt = $fn->appendBasicBlock('aopt_truthy_test_int_'.(string) self::$blockSeq);
        $testFloat = $fn->appendBasicBlock('aopt_truthy_test_float_'.(string) self::$blockSeq);
        $testString = $fn->appendBasicBlock('aopt_truthy_test_string_'.(string) self::$blockSeq);

        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $nullTy);
        $isUndef = $context->builder->icmp(Builder::INT_EQ, $typeByte, $undefTy);
        $context->builder->branchIf(
            $context->builder->or($isNull, $isUndef),
            $falseBb,
            $testBool
        );

        $context->builder->positionAtEnd($falseBb);
        $falseVal = $i1->constInt(0, false);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($testBool);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $boolTy),
            $boolBb,
            $testInt
        );

        $context->builder->positionAtEnd($boolBb);
        $valueField = $context->builder->structGep($valueIn, $map['value']);
        $boolByte = $context->builder->load(
            $context->builder->inBoundsGEP(
                $valueField,
                $i32->constInt(0, false),
                $i64->constInt(0, false)
            )
        );
        $boolVal = $context->builder->icmp(Builder::INT_NE, $boolByte, $i8->constInt(0, false));
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($testInt);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $intTy),
            $intBb,
            $testFloat
        );

        $context->builder->positionAtEnd($intBb);
        $num = $context->builder->call($context->lookupFunction('__value__readLong'), $valueIn);
        $intVal = $context->builder->icmp(Builder::INT_NE, $num, $i64->constInt(0, false));
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($testFloat);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $floatTy),
            $floatBb,
            $testString
        );

        $context->builder->positionAtEnd($floatBb);
        $dbl = $context->builder->call($context->lookupFunction('__value__readDouble'), $valueIn);
        $floatVal = $context->builder->fcmp(Builder::REAL_ONE, $dbl, $dbl->typeOf()->constReal(0.0));
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($testString);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $stringTy),
            $stringBb,
            $defaultBb
        );

        $context->builder->positionAtEnd($stringBb);
        $strPtr = $context->builder->call($context->lookupFunction('__value__readString'), $valueIn);
        $strMap = $context->structFieldMap['__string__'];
        $strLen = $context->builder->load(
            $context->builder->structGep($strPtr, $strMap['length'])
        );
        $strVal = $context->builder->icmp(Builder::INT_NE, $strLen, $i64->constInt(0, false));
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($defaultBb);
        $defaultVal = $i1->constInt(1, false);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($falseVal, $falseBb);
        $phi->addIncoming($boolVal, $boolBb);
        $phi->addIncoming($intVal, $intBb);
        $phi->addIncoming($floatVal, $floatBb);
        $phi->addIncoming($strVal, $stringBb);
        $phi->addIncoming($defaultVal, $defaultBb);

        return $phi;
    }

    private static function literalEmptyString(Context $context): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(0, false),
            $context->builder->pointerCast($context->constantFromString(''), $charPtr)
        );
    }

    private static function writeBoolFalse(Context $context, Value $out): void
    {
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $context->getTypeFromString('int32')->constInt(0, false)
        );
    }

    private static function ensureValueHelpers(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $valPtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $dbl = $context->getTypeFromString('double');
        $i32 = $context->getTypeFromString('int32');
        $voidTy = $context->getTypeFromString('void');

        self::ensureExternal(
            $context,
            '__string__init',
            $context->context->functionType($strPtr, false, $i64, $context->getTypeFromString('char*'))
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

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#9513'
        );
    }
}
