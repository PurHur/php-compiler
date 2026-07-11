<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPLLVM\Builder;
use PHPLLVM\Type;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Shared LLVM bridge: call a PHP helper returning hashtable, store into __value__* out param (#9181).
 */
final class HashtableValueOutJitBridge
{
    /**
     * @param list<Type> $abiParamTypes ABI params including trailing __value__* out slot
     * @param callable(Context, LlvmFunction): list<\PHPLLVM\Value> $abiToHelperArgs
     */
    public static function implement(
        Context $context,
        string $abiName,
        string $blockPrefix,
        array $abiParamTypes,
        LlvmFunction $helperFn,
        callable $abiToHelperArgs
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $ft = $context->context->functionType($voidTy, false, ...$abiParamTypes);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $out = $fn->getParam($fn->countParams() - 1);

        $entry = $fn->appendBasicBlock($blockPrefix.'_entry');
        $nullOutBb = $fn->appendBasicBlock($blockPrefix.'_null_out');
        $bodyBb = $fn->appendBasicBlock($blockPrefix.'_body');
        $context->builder->positionAtEnd($entry);
        $nullOut = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull());
        $context->builder->branchIf($nullOut, $nullOutBb, $bodyBb);

        $context->builder->positionAtEnd($nullOutBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBb);
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            $helperFn,
            $abiToHelperArgs($context, $fn)
        );
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
        $htNull = JitNestedHelperCoerce::isHelperResultNull($context, $htRaw);
        $failBb = $fn->appendBasicBlock($blockPrefix.'_fail');
        $storeBb = $fn->appendBasicBlock($blockPrefix.'_store');
        $context->builder->branchIf($htNull, $failBb, $storeBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($storeBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $out,
            $ht
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }
}
