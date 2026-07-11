<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\UserScriptAotDeferNestedJit;

/**
 * LLVM lowering for hash() / hash_hmac() / hash_pbkdf2() / hash_equals() / hash_hmac_algos().
 *
 * Digest helpers via {@see StringHashCryptoPhp} → HashCryptoJitHelper → VmHash (#9164).
 * User-script AOT nested-compiles helpers in-module (#3357) instead of cached split units (#16075).
 * hash_equals / hash_hmac_algos / hash_algos via {@see StringHashEquals} / {@see StringHashHmacAlgos} / {@see StringHashAlgos} (#7189, #11463).
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
        StringHashEquals::ensureLinked($context);
        StringHashHmacAlgos::ensureLinked($context);
        StringHashAlgos::ensureLinked($context);

        // User-script standalone init: nested-compile HashCryptoJitHelper into the
        // main module (#3357). Cached split units skip __init__ (#16075) and return
        // empty digests; in-module helpers share standalone __init__ instead.
        if (UserScriptAotDeferNestedJit::shouldDefer($context)) {
            StringHashCryptoPhp::implement($context, true);
        } else {
            StringHashCryptoPhp::implement($context);
        }

        self::registerLinkedRuntime($context);
    }

    public static function implement(Context $context): void
    {
        StringHashEquals::ensureLinked($context);
        StringHashHmacAlgos::ensureLinked($context);
        StringHashAlgos::ensureLinked($context);

        if (UserScriptAotDeferNestedJit::shouldDefer($context)) {
            StringHashCryptoPhp::implement($context, true);
        } else {
            StringHashCryptoPhp::implement($context);
        }

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
