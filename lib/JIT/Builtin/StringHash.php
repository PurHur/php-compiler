<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for phpc_hash_r1 (#36388).
 *
 * Thin AOT uses native {@see HashRuntime} (no NestedJIT of the hash crypto helper) so
 * FUNCCALL results free on unset — peer {@see StringMd5} / {@see StringSha1}.
 * {@see Context::ensureFullStandaloneBodies} must not NestedJIT this during init.
 * SSOT behaviour: {@see \PHPCompiler\ext\standard\VmHash}.
 * php-src: ext/hash/hash.c — PHP_FUNCTION(hash)
 */
final class StringHash
{
    private const ABI = HashRuntime::ABI;

    private const BRIDGE_ENTRY = HashRuntime::BRIDGE_ENTRY;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $algo, Value $data, Value $rawI32): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $algo,
            $data,
            $rawI32
        );
    }

    private static function implement(Context $context): void
    {
        // Native emit does not NestedJIT HashCrypto — safe under NestedJIT scope.
        $probe = $context->module->getNamedFunction(self::ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $strPtr, $i32);
        $fn = null !== $probe ? $probe : $context->module->addFunction(self::ABI, $ft);
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        HashRuntime::emitBridgeBody($context, $fn);
        $context->registerFunction(self::ABI, $fn);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }
}
