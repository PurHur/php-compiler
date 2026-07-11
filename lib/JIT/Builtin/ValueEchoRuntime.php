<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\ValueEchoHelper;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ValueEchoSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for echo/print value-box dispatch via ValueEchoJitHelper PHP (#10204).
 *
 * SSOT: {@see \PHPCompiler\VM\ValueEchoSupport}, {@see \PHPCompiler\VM\ValueEchoJitHelper}
 */
final class ValueEchoRuntime
{
    private const HELPER_PATH = '/VM/ValueEchoJitHelper.php';

    private const TYPE_IS_NULL = 'PHPCompiler\\VM\\ValueEchoJitHelper::typeIsNull';

    private const TYPE_IS_NATIVE_LONG = 'PHPCompiler\\VM\\ValueEchoJitHelper::typeIsNativeLong';

    private const TYPE_IS_NATIVE_BOOL = 'PHPCompiler\\VM\\ValueEchoJitHelper::typeIsNativeBool';

    private const TYPE_IS_NATIVE_DOUBLE = 'PHPCompiler\\VM\\ValueEchoJitHelper::typeIsNativeDouble';

    private const TYPE_IS_STRING = 'PHPCompiler\\VM\\ValueEchoJitHelper::typeIsString';

    private const TYPE_IS_HASHTABLE = 'PHPCompiler\\VM\\ValueEchoJitHelper::typeIsHashtable';

    private const TYPE_IS_OBJECT = 'PHPCompiler\\VM\\ValueEchoJitHelper::typeIsObject';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::TYPE_IS_NULL,
        self::TYPE_IS_NATIVE_LONG,
        self::TYPE_IS_NATIVE_BOOL,
        self::TYPE_IS_NATIVE_DOUBLE,
        self::TYPE_IS_STRING,
        self::TYPE_IS_HASHTABLE,
        self::TYPE_IS_OBJECT,
    ];

    /** @var array<string, string> */
    private const TYPE_BRIDGE_MAP = [
        self::TYPE_IS_NULL => '__value_echo__typeIsNull',
        self::TYPE_IS_NATIVE_LONG => '__value_echo__typeIsNativeLong',
        self::TYPE_IS_NATIVE_BOOL => '__value_echo__typeIsNativeBool',
        self::TYPE_IS_NATIVE_DOUBLE => '__value_echo__typeIsNativeDouble',
        self::TYPE_IS_STRING => '__value_echo__typeIsString',
        self::TYPE_IS_HASHTABLE => '__value_echo__typeIsHashtable',
        self::TYPE_IS_OBJECT => '__value_echo__typeIsObject',
    ];

    /** @var array<string, int> */
    private const STANDALONE_TYPE_CONST_MAP = [
        self::TYPE_IS_NULL => JitVariable::TYPE_NULL,
        self::TYPE_IS_NATIVE_LONG => JitVariable::TYPE_NATIVE_LONG,
        self::TYPE_IS_NATIVE_BOOL => JitVariable::TYPE_NATIVE_BOOL,
        self::TYPE_IS_NATIVE_DOUBLE => JitVariable::TYPE_NATIVE_DOUBLE,
        self::TYPE_IS_STRING => JitVariable::TYPE_STRING,
        self::TYPE_IS_HASHTABLE => JitVariable::TYPE_HASHTABLE,
        self::TYPE_IS_OBJECT => JitVariable::TYPE_OBJECT,
    ];

    private static int $seq = 0;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__value_echo__typeIsNull');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $restoreBlock = BasicBlockHelper::tryGetInsertBlock($context);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            foreach (self::TYPE_BRIDGE_MAP as $helperLogical => $abiName) {
                self::implementStandaloneTypeBridge(
                    $context,
                    $abiName,
                    self::STANDALONE_TYPE_CONST_MAP[$helperLogical]
                );
            }
        } else {
            self::ensureJitHelperCompiled($context);
            foreach (self::TYPE_BRIDGE_MAP as $helperLogical => $abiName) {
                self::implementTypeBridge($context, $abiName, $helperLogical);
            }
        }
        self::registerLinkedRuntime($context);
        if (null !== $restoreBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $restoreBlock);
        }
    }

    public static function emitValue(Context $context, Value $valuePtr): void
    {
        $restoreBlock = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureLinked($context);
        ObOutputRuntime::ensureLinked($context);
        if (null !== $restoreBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $restoreBlock);
        } else {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'echo_value_emit_cont');
        }

        $tag = 'ev'.(string) ++self::$seq;
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );

        $nullBlock = BasicBlockHelper::append($context, 'echo_value_null_'.$tag);
        $longBlock = BasicBlockHelper::append($context, 'echo_value_long_'.$tag);
        $boolBlock = BasicBlockHelper::append($context, 'echo_value_bool_'.$tag);
        $doubleBlock = BasicBlockHelper::append($context, 'echo_value_double_'.$tag);
        $stringBlock = BasicBlockHelper::append($context, 'echo_value_string_'.$tag);
        $arrayBlock = BasicBlockHelper::append($context, 'echo_value_array_'.$tag);
        $objectBlock = BasicBlockHelper::append($context, 'echo_value_object_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'echo_value_done_'.$tag);

        $afterNull = BasicBlockHelper::append($context, 'echo_value_after_null_'.$tag);
        $context->builder->branchIf(self::callTypeIsNull($context, $typeByte), $nullBlock, $afterNull);
        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterNull);
        $afterLong = BasicBlockHelper::append($context, 'echo_value_after_long_'.$tag);
        $context->builder->branchIf(self::callTypeIsNativeLong($context, $typeByte), $longBlock, $afterLong);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
        ValueEchoHelper::echoNativeLong($context, $longVal);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterLong);
        $afterBool = BasicBlockHelper::append($context, 'echo_value_after_bool_'.$tag);
        $context->builder->branchIf(self::callTypeIsNativeBool($context, $typeByte), $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        $boolVal = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
        $isTrue = $context->builder->icmp(
            Builder::INT_NE,
            $boolVal,
            $boolVal->typeOf()->constInt(0, false)
        );
        $trueBlock = BasicBlockHelper::append($context, 'echo_value_bool_true_'.$tag);
        $falseBlock = BasicBlockHelper::append($context, 'echo_value_bool_false_'.$tag);
        $boolDone = BasicBlockHelper::append($context, 'echo_value_bool_done_'.$tag);
        $context->builder->branchIf($isTrue, $trueBlock, $falseBlock);
        $context->builder->positionAtEnd($trueBlock);
        ValueEchoHelper::echoLiteral($context, ValueEchoSupport::BOOL_TRUE_LABEL);
        $context->builder->branch($boolDone);
        $context->builder->positionAtEnd($falseBlock);
        $context->builder->branch($boolDone);
        $context->builder->positionAtEnd($boolDone);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterBool);
        $afterDouble = BasicBlockHelper::append($context, 'echo_value_after_double_'.$tag);
        $context->builder->branchIf(self::callTypeIsNativeDouble($context, $typeByte), $doubleBlock, $afterDouble);

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call(
            $context->lookupFunction('__value__readDouble'),
            $valuePtr
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_double'),
            $doubleVal
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterDouble);
        $afterArray = BasicBlockHelper::append($context, 'echo_value_after_array_'.$tag);
        $context->builder->branchIf(self::callTypeIsHashtable($context, $typeByte), $arrayBlock, $afterArray);

        $context->builder->positionAtEnd($arrayBlock);
        ValueEchoHelper::echoLiteral($context, ValueEchoSupport::ARRAY_LABEL);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterArray);
        $afterObject = BasicBlockHelper::append($context, 'echo_value_after_object_'.$tag);
        $context->builder->branchIf(self::callTypeIsObject($context, $typeByte), $objectBlock, $afterObject);

        $context->builder->positionAtEnd($objectBlock);
        $objPtr = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $objVar = new Variable(
            $context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $objPtr
        );
        ValueEchoHelper::echoObjectVariable($context, $objVar);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterObject);
        $context->builder->branchIf(self::callTypeIsString($context, $typeByte), $stringBlock, $doneBlock);

        $context->builder->positionAtEnd($stringBlock);
        $strPtr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        $strMap = $context->structFieldMap['__string__'];
        $strLen = $context->builder->load(
            $context->builder->structGep($strPtr, $strMap['length'])
        );
        $strChars = $context->builder->structGep($strPtr, $strMap['value']);
        $sizeT = $context->getTypeFromString('size_t');
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_substr'),
            $strChars,
            $context->builder->zExt($strLen, $sizeT)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        BasicBlockHelper::branchToFreshContinue($context, 'echo_value_continue_'.$tag);
    }

    public static function callTypeIsNull(Context $context, Value $typeByte): Value
    {
        return self::callTypeBridge($context, '__value_echo__typeIsNull', $typeByte);
    }

    public static function callTypeIsNativeLong(Context $context, Value $typeByte): Value
    {
        return self::callTypeBridge($context, '__value_echo__typeIsNativeLong', $typeByte);
    }

    public static function callTypeIsNativeBool(Context $context, Value $typeByte): Value
    {
        return self::callTypeBridge($context, '__value_echo__typeIsNativeBool', $typeByte);
    }

    public static function callTypeIsNativeDouble(Context $context, Value $typeByte): Value
    {
        return self::callTypeBridge($context, '__value_echo__typeIsNativeDouble', $typeByte);
    }

    public static function callTypeIsString(Context $context, Value $typeByte): Value
    {
        return self::callTypeBridge($context, '__value_echo__typeIsString', $typeByte);
    }

    public static function callTypeIsHashtable(Context $context, Value $typeByte): Value
    {
        return self::callTypeBridge($context, '__value_echo__typeIsHashtable', $typeByte);
    }

    public static function callTypeIsObject(Context $context, Value $typeByte): Value
    {
        return self::callTypeBridge($context, '__value_echo__typeIsObject', $typeByte);
    }

    private static function callTypeBridge(Context $context, string $abiName, Value $typeByte): Value
    {
        self::ensureLinked($context);
        $fn = $context->lookupFunction($abiName);
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->call(
            $fn,
            $context->builder->trunc($typeByte, $i8)
        );
    }

    private static function implementStandaloneTypeBridge(Context $context, string $abiName, int $expectedType): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $ft = $context->context->functionType($i1, false, $i8);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('value_echo_type_standalone_entry');
        $context->builder->positionAtEnd($entry);
        $matches = $context->builder->icmp(
            Builder::INT_EQ,
            $fn->getParam(0),
            $i8->constInt($expectedType, false)
        );
        $context->builder->returnValue($matches);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementTypeBridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $ft = $context->context->functionType($i1, false, $i8);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('value_echo_type_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, $helperLogical),
            $fn->getParam(0)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#10204');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#10204'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::TYPE_BRIDGE_MAP as $abiName) {
            $fn = $context->module->getNamedFunction($abiName);
            if (null !== $fn) {
                $context->registerFunction($abiName, $fn);
            }
        }
    }
}
