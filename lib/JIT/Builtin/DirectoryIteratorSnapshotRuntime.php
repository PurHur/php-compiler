<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT ABI for DirectoryIterator construct snapshot (#27289).
 *
 * Thin standalone AOT: libc {@see __phpc_scandir_vec} (peer #27236) — NestedJIT of
 * DirHandle→VmDirPure→scandir recurses / returns empty under user-script AOT.
 * Embed: NestedJIT {@see \PHPCompiler\ext\spl\DirectoryIteratorSnapshotJitHelper}.
 * Linked at Type init (not mid-construct) so NestedJIT cannot orphan the user insert block.
 * php-src: ext/spl/spl_directory.c — spl_filesystem_dir_open
 */
final class DirectoryIteratorSnapshotRuntime
{
    public const ABI = '__compiler_directoryiterator_snapshot';

    private const HELPER_PATH = '/ext/spl/DirectoryIteratorSnapshotJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\spl\\DirectoryIteratorSnapshotJitHelper::entriesArgv';

    private const BRIDGE_ENTRY = 'di_snapshot_bridge_entry';

    private const FLAG_SKIP_DOTS = 4096;

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

        if ($context->isThinStandaloneAotMain()) {
            StringFsGlob::ensureLinked($context);
            self::emitThinAotBridge($context, $probe);
        } else {
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
                '#27289'
            );
        }

        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function emitThinAotBridge(Context $context, ?LlvmFunction $probe): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8pp = $context->getTypeFromString('int8**');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        $ft = $context->context->functionType($htPtr, false, $strPtr, $i64);
        $fn = null !== $probe ? $probe : $context->module->addFunction(self::ABI, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);

        $path = $fn->getParam(0);
        $flags = $fn->getParam(1);
        $itemsSlot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i8pp);
        $context->builder->store($i8pp->constNull(), $itemsSlot);
        // SCANDIR_SORT_ASCENDING = 0
        $count = $context->builder->call(
            $context->lookupFunction('__phpc_scandir_vec'),
            $path,
            $i32->constInt(0, false),
            $itemsSlot
        );
        $failed = $context->builder->icmp(Builder::INT_SLT, $count, $i32->constInt(0, false));
        $emptyBb = $fn->appendBasicBlock('di_snap_empty');
        $buildBb = $fn->appendBasicBlock('di_snap_build');
        $context->builder->branchIf($failed, $emptyBb, $buildBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue(HashTableHelper::alloc($context));

        $context->builder->positionAtEnd($buildBb);
        $skipDots = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($flags, $i64->constInt(self::FLAG_SKIP_DOTS, false)),
            $i64->constInt(0, false)
        );
        $ht = HashTableHelper::alloc($context);
        $outIdx = BasicBlockHelper::entryAllocaForFunction($context, $fn, $sizeT);
        $iSlot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $sizeT);
        $context->builder->store($sizeT->constInt(0, false), $outIdx);
        $context->builder->store($sizeT->constInt(0, false), $iSlot);
        $setString = $context->lookupFunction('__hashtable__setStringAt');
        $stringInit = $context->lookupFunction('__string__init');
        $strlenFn = $context->lookupFunction('strlen');
        $strcmpFn = $context->lookupFunction('strcmp');
        $loopHead = $fn->appendBasicBlock('di_snap_head');
        $loopBody = $fn->appendBasicBlock('di_snap_body');
        $loopDone = $fn->appendBasicBlock('di_snap_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $countSized = $context->builder->zExt($count, $sizeT);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $countSized);
        $context->builder->branchIf($atEnd, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $items = $context->builder->load($itemsSlot);
        $cstr = $context->builder->load($context->builder->inBoundsGep($items, $i));
        $dot = $context->builder->pointerCast($context->constantFromString('.'), $i8p);
        $dotdot = $context->builder->pointerCast($context->constantFromString('..'), $i8p);
        $isDot = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call($strcmpFn, $cstr, $dot),
            $i32->constInt(0, false)
        );
        $isDotDot = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call($strcmpFn, $cstr, $dotdot),
            $i32->constInt(0, false)
        );
        $isDotEntry = $context->builder->or($isDot, $isDotDot);
        $skip = $context->builder->and($skipDots, $isDotEntry);
        $skipBb = $fn->appendBasicBlock('di_snap_skip');
        $keepBb = $fn->appendBasicBlock('di_snap_keep');
        $nextBb = $fn->appendBasicBlock('di_snap_next');
        $context->builder->branchIf($skip, $skipBb, $keepBb);

        $context->builder->positionAtEnd($skipBb);
        $context->builder->branch($nextBb);

        $context->builder->positionAtEnd($keepBb);
        $len = $context->builder->call($strlenFn, $cstr);
        $lenI64 = $len->typeOf() === $i64 ? $len : $context->builder->zExt($len, $i64);
        $str = $context->builder->call($stringInit, $lenI64, $context->builder->pointerCast($cstr, $i8p));
        $oi = $context->builder->load($outIdx);
        $context->builder->call($setString, $ht, $oi, $str);
        $context->builder->store($context->builder->addNoSignedWrap($oi, $sizeT->constInt(1, false)), $outIdx);
        $context->builder->branch($nextBb);

        $context->builder->positionAtEnd($nextBb);
        $context->builder->store($context->builder->addNoSignedWrap($i, $sizeT->constInt(1, false)), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->call(
            $context->lookupFunction('__phpc_strvec_free'),
            $context->builder->load($itemsSlot),
            $count
        );
        $context->builder->returnValue($ht);
        $context->registerFunction(self::ABI, $fn);
        $context->builder->clearInsertionPosition();
    }
}
