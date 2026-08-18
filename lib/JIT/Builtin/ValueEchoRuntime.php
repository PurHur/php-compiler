<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\ValueEchoHelper;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ValueEchoSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for echo/print value-box dispatch via ValueEchoJitHelper PHP (#10204, #21513).
 *
 * Embed + thin standalone AOT: {@see ValueEchoJitHelper} type predicates via
 * {@see JitVmHelperLink} (StringOffset #21497 / ChunkSplit #21399 shape — no STANDALONE
 * icmp fork). Nested helper compile leaf: thin icmp matching {@see ValueEchoJitHelper::typeIs*}
 * without re-entering NestedJIT (#17279).
 * SSOT: {@see \PHPCompiler\VM\ValueEchoSupport}, {@see \PHPCompiler\VM\ValueEchoJitHelper}
 */
final class ValueEchoRuntime
{
    private const HELPER_PATH = '/VM/ValueEchoJitHelper.php';

    private const BRIDGE_ENTRY = 'value_echo_type_bridge_entry';

    private const NESTED_LEAF_ENTRY = 'value_echo_type_nested_leaf_entry';

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
    private const NESTED_LEAF_TYPE_CONST_MAP = [
        self::TYPE_IS_NULL => JitVariable::TYPE_NULL,
        self::TYPE_IS_NATIVE_LONG => JitVariable::TYPE_NATIVE_LONG,
        self::TYPE_IS_NATIVE_BOOL => JitVariable::TYPE_NATIVE_BOOL,
        self::TYPE_IS_NATIVE_DOUBLE => JitVariable::TYPE_NATIVE_DOUBLE,
        self::TYPE_IS_STRING => JitVariable::TYPE_STRING,
        self::TYPE_IS_HASHTABLE => JitVariable::TYPE_HASHTABLE,
        self::TYPE_IS_OBJECT => JitVariable::TYPE_OBJECT,
    ];

    /** @var array<string, int> */
    private const BRIDGE_ABI_TYPE_MAP = [
        '__value_echo__typeIsNull' => JitVariable::TYPE_NULL,
        '__value_echo__typeIsNativeLong' => JitVariable::TYPE_NATIVE_LONG,
        '__value_echo__typeIsNativeBool' => JitVariable::TYPE_NATIVE_BOOL,
        '__value_echo__typeIsNativeDouble' => JitVariable::TYPE_NATIVE_DOUBLE,
        '__value_echo__typeIsString' => JitVariable::TYPE_STRING,
        '__value_echo__typeIsHashtable' => JitVariable::TYPE_HASHTABLE,
        '__value_echo__typeIsObject' => JitVariable::TYPE_OBJECT,
    ];

    private static int $seq = 0;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function implement(Context $context): void
    {
        // Nested helper compile of unrelated units that still need type predicates:
        // thin icmp leaf without re-entering ValueEchoJitHelper (#21513 / #17279).
        if (NestedJitCompileScope::isActive()) {
            self::implementNestedLeafTypeBridges($context);

            return;
        }

        $restoreBlock = BasicBlockHelper::tryGetInsertBlock($context);
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        foreach (self::TYPE_BRIDGE_MAP as $helperLogical => $abiName) {
            JitVmHelperLink::ensureBridge(
                $context,
                $abiName,
                self::BRIDGE_ENTRY,
                [$i8],
                $i1,
                $helperLogical,
                self::HELPER_PATH,
                self::COMPILED_HELPERS,
                '#21513'
            );
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
        $fallbackBlock = BasicBlockHelper::append($context, 'echo_value_fallback_'.$tag);
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
        // __value__readLong has no TYPE_NATIVE_BOOL arm (#21892 / #21948).
        $boolByte = JitValueBox::readBoolByte($context, $valuePtr);
        $isTrue = $context->builder->icmp(
            Builder::INT_NE,
            $boolByte,
            $context->getTypeFromString('int8')->constInt(0, false)
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
        // Boxed AOT temps (echo INF / fdiv result) must match Zend casing — not libc
        // snprintf "inf" via __phpc_ob_echo_double (#27412; peer native path #21963).
        // libc %g → zend_gcvt (`1e+100` → `1.0E+100`) (#32316).
        $formatted = ZendDoubleStringRuntime::formatGcvt($context, $doubleVal);
        ValueEchoHelper::echoStringVariable(
            $context,
            new Variable(
                $context,
                Variable::TYPE_STRING,
                Variable::KIND_VALUE,
                $formatted
            )
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
        $context->builder->branchIf(self::callTypeIsString($context, $typeByte), $stringBlock, $fallbackBlock);

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

        // zend_print_variable: strval/coerce for boxes the dispatch chain missed (#21865).
        $context->builder->positionAtEnd($fallbackBlock);
        JitNativeString::ensureInsertBlock($context);
        $boxed = new Variable(
            $context,
            JitVariable::TYPE_VALUE,
            Variable::KIND_VALUE,
            $valuePtr
        );
        ValueEchoHelper::echoStringVariable($context, JitNativeString::coerce($context, $boxed));
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
        if (!isset(self::BRIDGE_ABI_TYPE_MAP[$abiName])) {
            throw new \LogicException('Unknown value echo type bridge: '.$abiName);
        }
        $i8 = $context->getTypeFromString('int8');
        $truncated = $context->builder->trunc($typeByte, $i8);

        // Thin standalone AOT: icmp matches ValueEchoSupport / strval (#21865); bridges stay for link inventory (#21513).
        return $context->builder->icmp(
            Builder::INT_EQ,
            $truncated,
            $i8->constInt(self::BRIDGE_ABI_TYPE_MAP[$abiName], false)
        );
    }

    /**
     * NestedJIT leaf only — mirrors {@see ValueEchoJitHelper::typeIs*} in LLVM icmp.
     * Not used for user-script / thin standalone AOT (those take {@see JitVmHelperLink::ensureBridge}).
     */
    private static function implementNestedLeafTypeBridges(Context $context): void
    {
        $restoreBlock = BasicBlockHelper::tryGetInsertBlock($context);
        foreach (self::TYPE_BRIDGE_MAP as $helperLogical => $abiName) {
            self::implementNestedLeafTypeBridge(
                $context,
                $abiName,
                self::NESTED_LEAF_TYPE_CONST_MAP[$helperLogical]
            );
        }
        self::registerLinkedRuntime($context);
        if (null !== $restoreBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $restoreBlock);
        }
    }

    private static function implementNestedLeafTypeBridge(Context $context, string $abiName, int $expectedType): void
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

        $entry = $fn->appendBasicBlock(self::NESTED_LEAF_ENTRY);
        $context->builder->positionAtEnd($entry);
        $matches = $context->builder->icmp(
            Builder::INT_EQ,
            $fn->getParam(0),
            $i8->constInt($expectedType, false)
        );
        $context->builder->returnValue($matches);
        $context->registerFunction($abiName, $fn);
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
