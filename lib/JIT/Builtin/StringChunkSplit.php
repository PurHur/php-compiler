<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __compiler_chunk_split via ChunkSplitJitHelper + VmChunkSplit (#14626, #21399, #26992, #30859).
 *
 * NestedJIT bundle peer {@see StringSoundex} / #30790 and {@see StringConvertUu} / #30811 —
 * solo ChunkSplitJitHelper NestedJIT SIGSEGVs under thin user-script AOT (#30859).
 * php-src: ext/standard/string.c — PHP_FUNCTION(chunk_split)
 */
final class StringChunkSplit
{
    private const ABI = '__compiler_chunk_split';

    private const HELPER_PATH = '/ext/standard/ChunkSplitJitHelper.php';

    /**
     * @var list<string>
     */
    private const HELPER_BUNDLE = [
        '/ext/standard/VmChunkSplit.php',
        '/ext/standard/ChunkSplitJitHelper.php',
    ];

    private const CHUNK_SPLIT_HELPER = 'PHPCompiler\\ext\\standard\\ChunkSplitJitHelper::chunkSplitArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CHUNK_SPLIT_HELPER,
    ];

    private const BRIDGE_ENTRY = 'chunk_split_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::implementBridge($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementBridge(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            self::HELPER_BUNDLE,
            self::COMPILED_HELPERS,
            '#30859'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr, $i64, $strPtr],
            $strPtr,
            self::CHUNK_SPLIT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#30859'
        );
    }
}
