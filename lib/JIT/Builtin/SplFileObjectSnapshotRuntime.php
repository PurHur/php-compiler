<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;

/**
 * JIT/AOT ABI: file contents → SplFileObject foreach line HT (#28709).
 *
 * Construct reads via {@see StringFileGetContents} (libc), then NestedJIT-splits
 * with {@see \PHPCompiler\ext\spl\SplFileObjectSnapshotJitHelper} (no NestedJIT fopen).
 * php-src: ext/spl/spl_directory.c — SplFileObject iterator / fgets
 */
final class SplFileObjectSnapshotRuntime
{
    public const ABI = '__compiler_splfileobject_lines';

    private const HELPER_PATH = '/ext/spl/SplFileObjectSnapshotJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\spl\\SplFileObjectSnapshotJitHelper::linesFromContentsArgv';

    private const BRIDGE_ENTRY = 'sfo_lines_bridge_entry';

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
            '#28709'
        );

        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /** Read path → line HT (empty/missing file → single ""). */
    public static function snapshotPath(Context $context, Value $pathStr): Value
    {
        StringFileGetContents::ensureStandaloneBodies($context);
        self::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'sfo_snap_after_link');

        $strPtr = $context->getTypeFromString('__string__*');
        $contents = $context->builder->call(
            $context->lookupFunction('__compiler_file_get_contents'),
            $pathStr
        );
        // Write modes (w+/a+/…) open a missing path — file_get_contents is null.
        // NestedJIT linesFromContentsArgv SIGSEGVs on null (#33340).
        $isNull = $context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $contents,
            $strPtr->constNull()
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $emptyBb = $fn->appendBasicBlock('sfo_snap_empty');
        $useBb = $fn->appendBasicBlock('sfo_snap_use');
        $joinBb = $fn->appendBasicBlock('sfo_snap_join');
        $context->builder->branchIf($isNull, $emptyBb, $useBb);

        $context->builder->positionAtEnd($emptyBb);
        $emptyStr = $context->builder->load($context->constantStringFromString(''));
        $context->builder->branch($joinBb);

        $context->builder->positionAtEnd($useBb);
        $context->builder->branch($joinBb);

        $context->builder->positionAtEnd($joinBb);
        $phi = $context->builder->phi($strPtr);
        $phi->addIncoming($emptyStr, $emptyBb);
        $phi->addIncoming($contents, $useBb);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $phi
        );
    }
}
