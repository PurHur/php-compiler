<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for phpc_md5_r1 (#36388).
 *
 * Thin AOT uses native {@see Md5Runtime} (no NestedJIT HashCryptoJitHelper) so
 * FUNCCALL results free on unset — peer {@see StringStrPad} / str_replace_r1.
 * {@see Context::ensureFullStandaloneBodies} must not NestedJIT this during init.
 * SSOT behaviour: {@see \PHPCompiler\ext\standard\VmHash}.
 * php-src: ext/standard/md5.c — PHP_FUNCTION(md5)
 */
final class StringMd5
{
    private const ABI = Md5Runtime::ABI;

    private const BRIDGE_ENTRY = Md5Runtime::BRIDGE_ENTRY;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $data, Value $rawI32): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
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
        $ft = $context->context->functionType($strPtr, false, $strPtr, $i32);
        $fn = null !== $probe ? $probe : $context->module->addFunction(self::ABI, $ft);
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        Md5Runtime::emitBridgeBody($context, $fn);
        $context->registerFunction(self::ABI, $fn);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }
}
