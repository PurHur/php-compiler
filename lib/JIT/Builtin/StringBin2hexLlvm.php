<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitBin2hex;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/**
 * LLVM implementation of __compiler_bin2hex for user-script standalone AOT (#16734).
 *
 * Nested Bin2hexJitHelper segfaults in minimal standalone init; restore pre-#14603 LLVM.
 * php-src: ext/standard/string.c — PHP_FUNCTION(bin2hex)
 */
final class StringBin2hexLlvm
{
    public static function implement(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr);
        $fn = $context->module->getNamedFunction('__compiler_bin2hex');
        if (null === $fn) {
            $fn = $context->module->addFunction('__compiler_bin2hex', $ft);
        }
        if (JitVmHelperLink::hasNamedBridgeEntry($fn, 'bin2hex_llvm_main')) {
            $context->registerFunction('__compiler_bin2hex', $fn);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $entry = $fn->appendBasicBlock('bin2hex_llvm_main');
        $context->builder->positionAtEnd($entry);
        $result = JitBin2hex::convert($context, $fn->getParam(0));
        $context->builder->returnValue($result);
        $context->registerFunction('__compiler_bin2hex', $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
