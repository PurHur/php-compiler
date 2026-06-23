<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * LLVM lowering for hash() / hash_hmac() / hash_pbkdf2() / hash_equals() / hash_hmac_algos().
 *
 * Digest helpers via {@see StringHashCryptoPhp} → HashCryptoJitHelper → VmHash (#9164).
 * hash_equals / hash_hmac_algos via {@see StringHashEquals} / {@see StringHashHmacAlgos} (#7189).
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
    ];

    public static function implement(Context $context): void
    {
        StringHashEquals::ensureLinked($context);
        StringHashHmacAlgos::ensureLinked($context);
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
