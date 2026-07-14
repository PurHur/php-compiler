<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitConvertUuBodies;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/**
 * LLVM implementation of __compiler_convert_uuencode for user-script standalone AOT (#4567, #16734).
 *
 * Nested ConvertUuJitHelper segfaults in minimal standalone init; restore pre-#13227 LLVM.
 * php-src: ext/standard/uuencode.c — PHP_FUNCTION(convert_uuencode)
 */
final class StringConvertUuEncodeLlvm
{
    public static function implement(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr);
        $fn = $context->module->getNamedFunction('__compiler_convert_uuencode');
        if (null === $fn) {
            $fn = $context->module->addFunction('__compiler_convert_uuencode', $ft);
        }
        if (JitVmHelperLink::hasNamedBridgeEntry($fn, 'uu_enc_entry')) {
            $context->registerFunction('__compiler_convert_uuencode', $fn);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        JitConvertUuBodies::implementEncodeBridge($context, $fn);
        $context->registerFunction('__compiler_convert_uuencode', $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
