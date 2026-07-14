<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitConvertUuBodies;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/**
 * LLVM implementation of __compiler_convert_uudecode for user-script standalone AOT (#4567, #16734).
 *
 * Nested ConvertUuJitHelper segfaults in minimal standalone init; restore pre-#13227 LLVM.
 * php-src: ext/standard/uuencode.c — PHP_FUNCTION(convert_uudecode)
 */
final class StringConvertUuDecodeLlvm
{
    public static function implement(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');
        $fn = $context->module->getNamedFunction('__compiler_convert_uudecode');
        if (null === $fn) {
            $fn = $context->module->addFunction(
                '__compiler_convert_uudecode',
                $context->context->functionType($voidTy, false, $strPtr, $valuePtr)
            );
        }
        if (JitVmHelperLink::hasNamedBridgeEntry($fn, 'uu_dec_entry')) {
            $context->registerFunction('__compiler_convert_uudecode', $fn);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        JitConvertUuBodies::implementDecodeBridge($context, $fn);
        $context->registerFunction('__compiler_convert_uudecode', $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
