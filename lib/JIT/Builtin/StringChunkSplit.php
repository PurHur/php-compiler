<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for phpc_chunk_split_r1 (#14626, #30859, #36388).
 *
 * Thin AOT uses native {@see ChunkSplitRuntime} (no NestedJIT ChunkSplitJitHelper) so
 * FUNCCALL results free on unset — peer {@see StringStrPad} / str_pad_r1.
 * {@see Context::ensureFullStandaloneBodies} must not NestedJIT this during init.
 * SSOT behaviour: {@see \PHPCompiler\ext\standard\VmChunkSplit}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(chunk_split)
 */
final class StringChunkSplit
{
    private const ABI = ChunkSplitRuntime::ABI;

    private const BRIDGE_ENTRY = ChunkSplitRuntime::BRIDGE_ENTRY;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(
        Context $context,
        Value $input,
        Value $chunkLen,
        Value $separator
    ): Value {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $input,
            $chunkLen,
            $separator
        );
    }

    private static function implement(Context $context): void
    {
        // Native emit does not NestedJIT ChunkSplitJitHelper — safe under NestedJIT scope.
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
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $i64, $strPtr);
        $fn = null !== $probe ? $probe : $context->module->addFunction(self::ABI, $ft);
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        ChunkSplitRuntime::emitBridgeBody($context, $fn);
        $context->registerFunction(self::ABI, $fn);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }
}
