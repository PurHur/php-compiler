<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * LLVM lowering for hash() / hash_hmac() / hash_pbkdf2() / hash_equals() / hash_hmac_algos().
 *
 * Digest helpers via {@see StringHashCryptoPhp} → HashCryptoJitHelper → phpc_hash_crypto_* EVP leaves (#9164, #21026).
 * Embed + thin standalone AOT both use {@see JitVmHelperLink} (no thin-standalone ABI fork).
 * hash_equals / hash_hmac_algos / hash_algos via {@see StringHashEquals} / {@see StringHashHmacAlgos} / {@see StringHashAlgos}.
 */
final class StringHashCryptoJit
{
    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_hash',
        '__compiler_hash_hmac',
        '__compiler_hash_pbkdf2',
        '__compiler_hash_hkdf',
        '__compiler_hash_equals',
        '__compiler_hash_hmac_algos',
        '__compiler_hash_algos',
    ];

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        StringHashEquals::ensureLinked($context);
        StringHashHmacAlgos::ensureLinked($context);
        StringHashAlgos::ensureLinked($context);
        StringHashCryptoPhp::implement($context);
        self::registerLinkedRuntime($context);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after hash crypto JIT implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
