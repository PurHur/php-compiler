<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT ABI for DirectoryIterator construct snapshot (#27289, #32006).
 *
 * Always NestedJIT {@see \PHPCompiler\ext\spl\DirectoryIteratorSnapshotJitHelper} via
 * {@see JitVmHelperLink} (peer glob/scandir #29986 — no thin-AOT libc fork).
 * Linked at Type init (not mid-construct) so NestedJIT cannot orphan the user insert block.
 * php-src: ext/spl/spl_directory.c — spl_filesystem_dir_open
 */
final class DirectoryIteratorSnapshotRuntime
{
    public const ABI = '__compiler_directoryiterator_snapshot';

    private const HELPER_PATH = '/ext/spl/DirectoryIteratorSnapshotJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\spl\\DirectoryIteratorSnapshotJitHelper::entriesArgv';

    private const BRIDGE_ENTRY = 'di_snapshot_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [self::HELPER];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
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

        StringDir::ensureLinked($context);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr, $i64],
            $htPtr,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#32006'
        );

        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
