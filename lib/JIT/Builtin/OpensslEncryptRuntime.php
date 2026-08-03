<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\ext\openssl\VmOpensslCipherNative;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for openssl_encrypt()/openssl_decrypt() via OpensslEncryptJitHelper PHP (#21065, AEAD #21135, #22683).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer OpensslDigest #22554).
 * Peer of {@see OpensslSignRuntime}. SSOT: {@see \PHPCompiler\ext\openssl\VmOpensslCipherNative}
 * php-src: ext/openssl/openssl.c
 */
final class OpensslEncryptRuntime
{
    private const HELPER_PATH = '/ext/openssl/OpensslEncryptJitHelper.php';

    private const ENCRYPT_HELPER = 'PHPCompiler\\ext\\openssl\\OpensslEncryptJitHelper::encryptArgv';

    private const DECRYPT_HELPER = 'PHPCompiler\\ext\\openssl\\OpensslEncryptJitHelper::decryptArgv';

    private const TAKE_TAG_HELPER = 'PHPCompiler\\ext\\openssl\\OpensslEncryptJitHelper::takeEncryptTag';

    private const TAG_IS_NULL_HELPER = 'PHPCompiler\\ext\\openssl\\OpensslEncryptJitHelper::takeEncryptTagIsNull';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ENCRYPT_HELPER,
        self::DECRYPT_HELPER,
        self::TAKE_TAG_HELPER,
        self::TAG_IS_NULL_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_openssl_encrypt',
        '__compiler_openssl_decrypt',
        '__compiler_openssl_encrypt_take_tag',
        '__compiler_openssl_encrypt_tag_is_null',
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
            \PHPCompiler\ext\openssl\JitOpensslCipherKernel::ensureEvpLeaves($context);
            self::ensureJitHelperCompiled($context);
            self::implementIfMissing($context, '__compiler_openssl_encrypt', self::implementEncryptBridge(...));
            self::implementIfMissing($context, '__compiler_openssl_decrypt', self::implementDecryptBridge(...));
            self::implementIfMissing($context, '__compiler_openssl_encrypt_take_tag', self::implementTakeTagBridge(...));
            self::implementIfMissing($context, '__compiler_openssl_encrypt_tag_is_null', self::implementTagIsNullBridge(...));
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
            [
                $fn->getParam(0),
                $fn->getParam(1),
                $fn->getParam(2),
                $fn->getParam(3),
                $fn->getParam(4),
                $fn->getParam(5),
                $fn->getParam(6),
                $fn->getParam(7),
            ]
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
            [
                $fn->getParam(0),
                $fn->getParam(1),
                $fn->getParam(2),
                $fn->getParam(3),
                $fn->getParam(4),
                $fn->getParam(5),
                $fn->getParam(6),
            ]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw)
        );
    }

    private static function implementTakeTagBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ossl_encrypt_take_tag_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::TAKE_TAG_HELPER),
            []
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw)
        );
    }

    private static function implementTagIsNullBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ossl_encrypt_tag_is_null_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::TAG_IS_NULL_HELPER),
            []
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceHelperScalarResult(
                $context,
                $raw,
                $context->getTypeFromString('int64')
            )
        );
    }

    private static function implementStubBridges(Context $context): void
    {
        $stubStr = static function (Context $context, LlvmFunction $fn, string $label): void {
            $entry = $fn->appendBasicBlock($label);
            $context->builder->positionAtEnd($entry);
            $strPtr = $context->getTypeFromString('__string__*');
            $context->builder->returnValue($strPtr->constNull());
        };
        $stubI64 = static function (Context $context, LlvmFunction $fn, string $label): void {
            $entry = $fn->appendBasicBlock($label);
            $context->builder->positionAtEnd($entry);
            $context->builder->returnValue($context->getTypeFromString('int64')->constInt(0, false));
        };
        self::implementIfMissing($context, '__compiler_openssl_encrypt', static function (Context $context, LlvmFunction $fn) use ($stubStr): void {
            $stubStr($context, $fn, 'ossl_encrypt_stub_entry');
        });
        self::implementIfMissing($context, '__compiler_openssl_decrypt', static function (Context $context, LlvmFunction $fn) use ($stubStr): void {
            $stubStr($context, $fn, 'ossl_decrypt_stub_entry');
        });
        self::implementIfMissing($context, '__compiler_openssl_encrypt_take_tag', static function (Context $context, LlvmFunction $fn) use ($stubStr): void {
            $stubStr($context, $fn, 'ossl_encrypt_take_tag_stub_entry');
        });
        self::implementIfMissing($context, '__compiler_openssl_encrypt_tag_is_null', static function (Context $context, LlvmFunction $fn) use ($stubI64): void {
            $stubI64($context, $fn, 'ossl_encrypt_tag_is_null_stub_entry');
        });
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#22683');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#22683'
        );
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
