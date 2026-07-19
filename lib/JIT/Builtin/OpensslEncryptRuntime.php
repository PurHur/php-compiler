<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\openssl\VmOpensslCipherNative;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for openssl_encrypt()/openssl_decrypt() via OpensslEncryptJitHelper PHP (#21065).
 *
 * Peer of {@see OpensslSignRuntime}. SSOT: {@see \PHPCompiler\ext\openssl\VmOpensslCipherNative}
 * php-src: ext/openssl/openssl.c
 */
final class OpensslEncryptRuntime
{
    private const HELPER_PATH = '/ext/openssl/OpensslEncryptJitHelper.php';

    private const ENCRYPT_HELPER = 'PHPCompiler\\ext\\openssl\\OpensslEncryptJitHelper::encryptArgv';

    private const DECRYPT_HELPER = 'PHPCompiler\\ext\\openssl\\OpensslEncryptJitHelper::decryptArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ENCRYPT_HELPER,
        self::DECRYPT_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_openssl_encrypt',
        '__compiler_openssl_decrypt',
    ];

    public static function opensslCipherRuntimeAvailable(): bool
    {
        return VmOpensslCipherNative::available();
    }

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_openssl_encrypt');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        if (!self::opensslCipherRuntimeAvailable()) {
            self::implementStubBridges($context);
        } else {
            // Helper uses base64_encode/decode; those ABIs no-op while NestedJIT is active (#21065).
            StringBase64Encode::ensureLinked($context);
            StringBase64Decode::ensureLinked($context);
            self::ensureJitHelperCompiled($context);
            self::implementIfMissing($context, '__compiler_openssl_encrypt', self::implementEncryptBridge(...));
            self::implementIfMissing($context, '__compiler_openssl_decrypt', self::implementDecryptBridge(...));
        }
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
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

    private static function implementEncryptBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ossl_encrypt_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::ENCRYPT_HELPER),
            [$fn->getParam(0), $fn->getParam(1), $fn->getParam(2), $fn->getParam(3), $fn->getParam(4)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw)
        );
    }

    private static function implementDecryptBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ossl_decrypt_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::DECRYPT_HELPER),
            [$fn->getParam(0), $fn->getParam(1), $fn->getParam(2), $fn->getParam(3), $fn->getParam(4)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw)
        );
    }

    private static function implementStubBridges(Context $context): void
    {
        $stub = static function (Context $context, LlvmFunction $fn, string $label): void {
            $entry = $fn->appendBasicBlock($label);
            $context->builder->positionAtEnd($entry);
            $strPtr = $context->getTypeFromString('__string__*');
            $context->builder->returnValue($strPtr->constNull());
        };
        self::implementIfMissing($context, '__compiler_openssl_encrypt', static function (Context $context, LlvmFunction $fn) use ($stub): void {
            $stub($context, $fn, 'ossl_encrypt_stub_entry');
        });
        self::implementIfMissing($context, '__compiler_openssl_decrypt', static function (Context $context, LlvmFunction $fn) use ($stub): void {
            $stub($context, $fn, 'ossl_decrypt_stub_entry');
        });
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after OpensslEncryptJitHelper compile (#21065)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'OpensslEncryptJitHelper.php');
            if (null === $block) {
                throw new \LogicException('OpensslEncryptJitHelper.php parseAndCompile failed (#21065)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT openssl encrypt (#21065)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after OpensslEncryptRuntime bridge (#21065)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
