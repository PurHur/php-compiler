<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\hash\JitHashContext;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;

/**
 * LLVM implementation of __compiler_hash_context_final for user-script standalone AOT (#3357).
 *
 * Nested HashContextJitHelper + duplicate hash_final() lowering segfaults standalone init;
 * route all hash_final() JIT calls through one module bridge (see StringBase64EncodeLlvm #16734).
 * php-src: ext/hash/hash.c — php_hash_final
 */
final class HashContextFinalLlvm
{
    public static function implement(Context $context): void
    {
        $valuePtr = $context->getTypeFromString('__value__*');
        $rawTy = $context->getTypeFromString('int1');
        $ft = $context->context->functionType($valuePtr, false, $valuePtr, $rawTy);
        $fn = $context->module->getNamedFunction('__compiler_hash_context_final');
        if (null === $fn) {
            $fn = $context->module->addFunction('__compiler_hash_context_final', $ft);
        }
        if (JitVmHelperLink::hasNamedBridgeEntry($fn, 'hash_context_final_llvm_main')) {
            $context->registerFunction('__compiler_hash_context_final', $fn);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $entry = $fn->appendBasicBlock('hash_context_final_llvm_main');
        $context->builder->positionAtEnd($entry);
        $ctxArg = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VALUE, $fn->getParam(0));
        $digest = JitHashContext::finalLowering($context, $ctxArg, $fn->getParam(1));
        $context->builder->returnValue($digest);
        $context->registerFunction('__compiler_hash_context_final', $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
