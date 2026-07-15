<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\UserScriptAotDeferNestedJit;

/**
 * LLVM lowering for hash() / hash_hmac() / hash_pbkdf2() / hash_equals() / hash_hmac_algos().
 *
 * Digest helpers via {@see StringHashCryptoPhp} → HashCryptoJitHelper → VmHash (#9164, #19074).
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
        if (UserScriptAotDeferNestedJit::shouldDefer($context)) {
            self::implementDeferred($context);

            return;
        }

        StringHashEquals::ensureLinked($context);
        StringHashHmacAlgos::ensureLinked($context);
        StringHashAlgos::ensureLinked($context);
        StringHashCryptoPhp::implement($context);
        self::registerLinkedRuntime($context);
    }

    public static function implement(Context $context): void
    {
        if (UserScriptAotDeferNestedJit::shouldDefer($context)) {
            self::implementDeferred($context);

            return;
        }

        StringHashEquals::ensureLinked($context);
        StringHashHmacAlgos::ensureLinked($context);
        StringHashAlgos::ensureLinked($context);
        StringHashCryptoPhp::implement($context);
        self::registerLinkedRuntime($context);
    }

    private static function implementDeferred(Context $context): void
    {
        StringHashCryptoPhp::implement($context);
        StringHashHmacAlgos::ensureLinked($context);
        StringHashAlgos::ensureLinked($context);
        self::ensureDeferredEqualsStub($context);
        self::registerLinkedRuntime($context);
    }

    private static function ensureDeferredEqualsStub(Context $context): void
    {
        $abiName = '__compiler_hash_equals';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && JitVmHelperLink::hasNamedBridgeEntry($probe, 'hash_equals_deferred_stub')) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i32, false, $strPtr, $strPtr)
            );
        $entry = $fn->appendBasicBlock('hash_equals_deferred_stub');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue($i32->constInt(0, false));
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
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
