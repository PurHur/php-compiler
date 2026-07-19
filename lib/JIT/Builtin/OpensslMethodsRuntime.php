<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\openssl\JitOpensslMethodsKernel;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for openssl_get_cipher_methods()/openssl_get_md_methods() (#21103).
 *
 * ABI bridges emit registry hashtables via {@see JitOpensslMethodsKernel} (hash_algos #20652
 * NestedJIT-leaf shape) — no NestedJIT of OpensslCipherRegistry / no thin C fork.
 * {@see \PHPCompiler\ext\openssl\OpensslMethodsJitHelper} remains the PHP SSOT wrapper for
 * the same kernels under NestedJIT helper-runtime units.
 * php-src: ext/openssl/openssl.c
 */
final class OpensslMethodsRuntime
{
    private const ABI_CIPHER = '__compiler_openssl_get_cipher_methods';

    private const ABI_MD = '__compiler_openssl_get_md_methods';

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        self::ABI_CIPHER,
        self::ABI_MD,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_CIPHER);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        self::implementIfMissing($context, self::ABI_CIPHER, self::implementCipherBridge(...));
        self::implementIfMissing($context, self::ABI_MD, self::implementMdBridge(...));
        self::registerLinkedRuntime($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        if (null === $savedBlock) {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = $context->lookupFunction($name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementCipherBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ossl_cipher_methods_bridge_entry');
        $context->builder->positionAtEnd($entry);
        // $aliases (param 0) ignored — OpensslCipherRegistry ignores aliases today (#6228).
        $context->builder->returnValue(JitOpensslMethodsKernel::invokeCipherMethods($context));
    }

    private static function implementMdBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ossl_md_methods_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(JitOpensslMethodsKernel::invokeMdMethods($context));
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after OpensslMethodsRuntime bridge (#21103)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
