<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for openssl_sign()/openssl_verify() (#3324).
 *
 * When libssl-dev is present, lib/AOT/runtime/openssl_ev.c satisfies the ABI at
 * link time. Otherwise LLVM stub bridges return failure (sign=null, verify=-1).
 */
final class OpensslSignRuntime
{
    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_openssl_sign',
        '__compiler_openssl_verify',
    ];

    public static function opensslEvRuntimeAvailable(): bool
    {
        foreach (['/usr/include/openssl/evp.h', '/usr/local/include/openssl/evp.h'] as $path) {
            if (is_file($path)) {
                return true;
            }
        }

        return false;
    }

    public static function ensureLinked(Context $context): void
    {
        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        if (!self::opensslEvRuntimeAvailable()) {
            self::implementStubBridges($context);
        }
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementStubBridges(Context $context): void
    {
        self::implementIfMissing($context, '__compiler_openssl_sign', static function (Context $context, LlvmFunction $fn): void {
            $entry = $fn->appendBasicBlock('ossl_sign_stub_entry');
            $context->builder->positionAtEnd($entry);
            $strPtr = $context->getTypeFromString('__string__*');
            $context->builder->returnValue($strPtr->constNull());
        });
        self::implementIfMissing($context, '__compiler_openssl_verify', static function (Context $context, LlvmFunction $fn): void {
            $entry = $fn->appendBasicBlock('ossl_verify_stub_entry');
            $context->builder->positionAtEnd($entry);
            $i32 = $context->getTypeFromString('int32');
            $context->builder->returnValue($i32->constInt(-1, true));
        });
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
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing from LLVM module (#3324)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
