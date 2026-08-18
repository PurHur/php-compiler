<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;

/**
 * dir() factory snapshot for JIT/AOT (#30757, #32027).
 *
 * Always NestedJIT {@see \PHPCompiler\ext\standard\DirSnapshotJitHelper} via
 * {@see JitVmHelperLink} (peer glob/scandir #29986 / iterator snapshots #32006 — no thin-AOT libc fork).
 * php-src: ext/standard/dir.c — PHP_FUNCTION(dir)
 */
final class StringDirFactory
{
    public const ABI = '__phpc_jit_dir_snapshot';

    private const BRIDGE_ENTRY = 'dir_snap_bridge_entry';

    private const HELPER_PATH = '/ext/standard/DirSnapshotJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\standard\\DirSnapshotJitHelper::entriesArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [self::HELPER];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    /** @return Value __hashtable__* of entry strings (empty on failure) */
    public static function invokeSnapshot(Context $context, Value $pathStr): Value
    {
        self::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dir_snap_after_link');

        return $context->builder->call($context->lookupFunction(self::ABI), $pathStr);
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

        StringDir::ensureLinked($context);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr],
            $htPtr,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#32027'
        );

        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
