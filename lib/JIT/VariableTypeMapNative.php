<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM;
use PHPLLVM\Value;
use PHPCompiler\Block;
use PHPCompiler\VM\Variable as VMVariable;

/**
 * Native lowering for JIT Variable VM-type mapping helpers (#1768).
 *
 * PHP CFG lowering of fromVMVariable / jitTypeByteFromVmType segfaults LLVM 9 on
 * compile_driver bundles; emit a small switch instead of interpreting the PHP body.
 */
final class VariableTypeMapNative
{
    /** @var array<int, int> VM Variable::TYPE_* => JIT Variable::TYPE_* */
    private const VM_TO_JIT = [
        VMVariable::TYPE_NULL => Variable::TYPE_NULL,
        VMVariable::TYPE_INTEGER => Variable::TYPE_NATIVE_LONG,
        VMVariable::TYPE_FLOAT => Variable::TYPE_NATIVE_DOUBLE,
        VMVariable::TYPE_BOOLEAN => Variable::TYPE_NATIVE_BOOL,
        VMVariable::TYPE_STRING => Variable::TYPE_STRING,
        VMVariable::TYPE_OBJECT => Variable::TYPE_OBJECT,
        VMVariable::TYPE_ARRAY => Variable::TYPE_HASHTABLE,
    ];

    public static function isNativeLoweringName(string $lower): bool
    {
        return str_ends_with($lower, '\\jit\\variable::fromvmvariable')
            || str_ends_with($lower, '\\jit\\variable::jittypebytefromvmtype');
    }

    public static function compile(
        Context $context,
        string $internalName,
        Block $block,
        string $logicalName
    ): Value {
        $lc = strtolower($logicalName);
        if (isset($context->functions[$lc])) {
            return $context->functions[$lc];
        }
        if (str_ends_with($lc, '\\jit\\variable::jittypebytefromvmtype')) {
            return self::compileJitTypeByteFromVmType($context, $internalName, $block, $logicalName);
        }

        return self::compileFromVmVariable($context, $internalName, $block, $logicalName);
    }

    private static function compileFromVmVariable(
        Context $context,
        string $internalName,
        Block $block,
        string $logicalName
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $func = $context->module->addFunction(
            $internalName,
            $context->context->functionType($i64, false, $i64)
        );
        self::emitVmToJitSwitchReturn($context, $func, $func->getParam(0), $i64, self::VM_TO_JIT);
        self::registerNative($context, $func, $logicalName, [$i64], 'int64');

        return $func;
    }

    private static function compileJitTypeByteFromVmType(
        Context $context,
        string $internalName,
        Block $block,
        string $logicalName
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $func = $context->module->addFunction(
            $internalName,
            $context->context->functionType($i8, false, $i64)
        );
        self::emitVmToJitSwitchReturn($context, $func, $func->getParam(0), $i8, self::VM_TO_JIT);
        self::registerNative($context, $func, $logicalName, [$i64], 'int8');

        return $func;
    }

    /**
     * @param array<int, int> $vmToJit
     */
    private static function emitVmToJitSwitchReturn(
        Context $context,
        Value $func,
        Value $vmTypeParam,
        PHPLLVM\Type $returnType,
        array $vmToJit
    ): void {
        $switchTy = $context->getTypeFromString('int64');
        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();

        $entry = $func->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $default = $entry->insertBasicBlock('default');
        $entry->moveBefore($default);

        $switchInst = $context->builder->branchSwitch($vmTypeParam, $default, count($vmToJit));
        foreach ($vmToJit as $vmVal => $jitVal) {
            $caseBb = $default->insertBasicBlock('case_'.$vmVal);
            $switchInst->addCase($switchTy->constInt($vmVal, false), $caseBb);
            $context->builder->positionAtEnd($caseBb);
            $context->builder->returnValue($returnType->constInt($jitVal, false));
        }

        $context->builder->positionAtEnd($default);
        $context->builder->returnValue($returnType->constInt(0, false));

        $context->builder->clearInsertionPosition();
        $context->builder = $saved;
    }

    /**
     * @param list<PHPLLVM\Type> $argTypes
     */
    private static function registerNative(
        Context $context,
        Value $func,
        string $logicalName,
        array $argTypes,
        string $returnType
    ): void {
        $lc = strtolower($logicalName);
        $context->functions[$lc] = $func;
        $context->functionReturnType[$lc] = $returnType;
        $context->functionProxies[$lc] = new Call\Native(
            $func,
            $logicalName,
            $argTypes,
            []
        );
    }
}
