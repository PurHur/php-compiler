<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM;
use PHPLLVM\Value;
use PHPCompiler\Block;

/**
 * Native lowering for Compiler operand-chain helpers (#1768).
 *
 * PHP CFG lowering of unwrapOperandChain / operandsChainEqual fails LLVM 9 module
 * verify ("Instruction does not dominate all uses") on compile_driver bundles.
 */
final class CompilerOperandChainNative
{
    public static function isNativeLoweringName(string $lower): bool
    {
        return str_ends_with($lower, '\\compiler::unwrapoperandchain')
            || str_ends_with($lower, '\\compiler::operandschainequal');
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
        if (str_ends_with($lc, '\\compiler::unwrapoperandchain')) {
            return self::compileUnwrapOperandChain($context, $internalName, $block, $logicalName);
        }

        return self::compileOperandsChainEqual($context, $internalName, $block, $logicalName);
    }

    private static function compileUnwrapOperandChain(
        Context $context,
        string $internalName,
        Block $block,
        string $logicalName
    ): Value {
        $objectPtr = $context->getTypeFromString('__object__*');
        $func = $context->module->addFunction(
            $internalName,
            $context->context->functionType($objectPtr, false, $objectPtr, $objectPtr)
        );
        $operand = $func->getParam(1);
        $bb = $func->appendBasicBlock('entry');
        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();
        $context->builder->positionAtEnd($bb);
        $context->builder->returnValue($operand);
        $context->builder->clearInsertionPosition();
        $context->builder = $saved;
        $lc = strtolower($logicalName);
        $context->functions[$lc] = $func;
        $context->functionReturnType[$lc] = '__object__*';
        $context->functionProxies[$lc] = new Call\Native(
            $func,
            $logicalName,
            [$objectPtr, $objectPtr],
            []
        );

        return $func;
    }

    private static function compileOperandsChainEqual(
        Context $context,
        string $internalName,
        Block $block,
        string $logicalName
    ): Value {
        $objectPtr = $context->getTypeFromString('__object__*');
        $boolTy = $context->getTypeFromString('bool');
        $func = $context->module->addFunction(
            $internalName,
            $context->context->functionType($boolTy, false, $objectPtr, $objectPtr, $objectPtr)
        );
        $a = $func->getParam(1);
        $b = $func->getParam(2);
        $bb = $func->appendBasicBlock('entry');
        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();
        $context->builder->positionAtEnd($bb);
        $context->builder->returnValue(
            $context->builder->icmp(PHPLLVM\Builder::INT_EQ, $a, $b)
        );
        $context->builder->clearInsertionPosition();
        $context->builder = $saved;
        $lc = strtolower($logicalName);
        $context->functions[$lc] = $func;
        $context->functionReturnType[$lc] = 'bool';
        $context->functionProxies[$lc] = new Call\Native(
            $func,
            $logicalName,
            [$objectPtr, $objectPtr, $objectPtr],
            []
        );

        return $func;
    }
}
