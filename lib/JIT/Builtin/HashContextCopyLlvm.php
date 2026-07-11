<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\hash\JitHashContext;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;

/**
 * LLVM implementation of __compiler_hash_context_copy for user-script standalone AOT (#3357).
 *
 * Duplicate hash_copy() lowering in one function segfaults standalone init; single module bridge.
 * php-src: ext/hash/hash.c — php_hash_copy
 */
final class HashContextCopyLlvm
{
    public static function implement(Context $context): void
    {
        $valuePtr = $context->getTypeFromString('__value__*');
        $ft = $context->context->functionType($valuePtr, false, $valuePtr);
        $fn = $context->module->getNamedFunction('__compiler_hash_context_copy');
        if (null === $fn) {
            $fn = $context->module->addFunction('__compiler_hash_context_copy', $ft);
        }
        if (JitVmHelperLink::hasNamedBridgeEntry($fn, 'hash_context_copy_llvm_main')) {
            $context->registerFunction('__compiler_hash_context_copy', $fn);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $entry = $fn->appendBasicBlock('hash_context_copy_llvm_main');
        $context->builder->positionAtEnd($entry);
        $ctxArg = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VALUE, $fn->getParam(0));
        $clone = JitHashContext::copyLowering($context, $ctxArg);
        $context->builder->returnValue($clone);
        $context->registerFunction('__compiler_hash_context_copy', $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
