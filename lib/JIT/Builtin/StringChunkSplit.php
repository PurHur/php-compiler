<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __compiler_chunk_split via ChunkSplitJitHelper PHP (#14626, #21399, #26992).
 *
 * Nested helper compile: {@see JitVmHelperLink::ensureBridge} (HelperRuntimeCache + user-script
 * env clear — no hand-rolled NestedJit compile loop). Peer: StringSoundex #21362 / StringWordwrap #26904.
 * Helper is NestedJIT-self-contained (no VmString call — #16075 / #26992).
 * php-src: ext/standard/string.c — PHP_FUNCTION(chunk_split)
 */
final class StringChunkSplit
{
    private const ABI = '__compiler_chunk_split';

    private const HELPER_PATH = '/ext/standard/ChunkSplitJitHelper.php';

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
        self::ensureLinked($context);
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

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr, $i64, $strPtr],
            $strPtr,
            self::CHUNK_SPLIT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21399'
        );
    }
}
