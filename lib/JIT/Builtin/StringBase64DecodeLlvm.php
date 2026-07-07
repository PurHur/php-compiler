<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitBase64Decode;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/**
 * LLVM implementation of __compiler_base64_decode for user-script standalone AOT (#16734).
 *
 * Nested Base64DecodeJitHelper segfaults in minimal standalone init; restore pre-#17234 LLVM.
 * php-src: ext/standard/base64.c — PHP_FUNCTION(base64_decode)
 */
final class StringBase64DecodeLlvm
{
    public static function implement(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr);
        $fn = $context->module->getNamedFunction('__compiler_base64_decode');
        if (null === $fn) {
            $fn = $context->module->addFunction('__compiler_base64_decode', $ft);
        }
        if (JitVmHelperLink::hasNamedBridgeEntry($fn, 'base64_decode_llvm_main')) {
            $context->registerFunction('__compiler_base64_decode', $fn);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $entry = $fn->appendBasicBlock('base64_decode_llvm_main');
        $context->builder->positionAtEnd($entry);
        $result = JitBase64Decode::decode($context, $fn->getParam(0));
        $context->builder->returnValue($result);
        $context->registerFunction('__compiler_base64_decode', $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
