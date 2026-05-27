<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;
use PHPCompiler\Block;

/**
 * Native lowering for OperandName::resolve (#816).
 *
 * PHP CFG lowering of the Temporary walk crashes LLVM 9 on self-host bundles when
 * nested inside IssetHelper. Emit a minimal native entry that returns null; IssetHelper
 * already avoids OperandName on the self-host AOT path (literal keys + superglobalName).
 */
final class OperandNameNative
{
    public static function isNativeLoweringName(string $lower): bool
    {
        return str_ends_with($lower, '\\jit\\operandname::resolve');
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

        $objectPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $func = $context->module->addFunction(
            $internalName,
            $context->context->functionType($strPtr, false, $objectPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();
        $context->builder->positionAtEnd($bb);
        $context->builder->returnValue($strPtr->constNull());
        $context->builder->clearInsertionPosition();
        $context->builder = $saved;

        $context->functions[$lc] = $func;
        $context->functionReturnType[$lc] = '__string__*';
        $context->functionProxies[$lc] = new Call\Native(
            $func,
            $logicalName,
            [$objectPtr],
            []
        );

        return $func;
    }
}
