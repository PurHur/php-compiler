<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\hash\JitHashCryptoKernel;
use PHPCompiler\JIT\Context;

/**
 * LLVM lowering for hash() / hash_hmac() / hash_pbkdf2() / hash_equals() / hash_hmac_algos().
 *
 * Digest helpers via {@see StringHashCryptoPhp} → HashCryptoJitHelper → VmHash (#9164).
 * Thin standalone AOT: {@see JitHashCryptoKernel} libcrypto EVP bridge (#3357, #19362, #20065).
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
        if ($context->isThinStandaloneAotMain()) {
            self::implementThin($context);

            return;
        }

        StringHashEquals::ensureLinked($context);
        StringHashHmacAlgos::ensureLinked($context);
        StringHashAlgos::ensureLinked($context);
        StringHashCryptoPhp::implement($context);
        self::registerLinkedRuntime($context);
    }

    private static function implementThin(Context $context): void
    {
        JitHashCryptoKernel::implement($context);
        StringHashHmacAlgos::ensureLinked($context);
        StringHashAlgos::ensureLinked($context);
        StringHashEquals::ensureLinked($context);
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
