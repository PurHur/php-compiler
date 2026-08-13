<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitFsGlobKernel;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Thin-AOT dir() factory via libc scandir snapshot (#30757).
 *
 * Peer {@see DirectoryIteratorSnapshotRuntime} thin path — NestedJIT VmDir/DirHandle is not
 * reliable on user-script AOT; snapshot entries into Directory `__dir_ht` + `__dir_pos`.
 *
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
        try {
            if ($context->isThinStandaloneAotMain()) {
                JitFsGlobKernel::implement($context);
                self::emitThinAotBridge($context, $probe);
            } else {
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
                    '#30757'
                );
            }
        } finally {
            if (null !== $savedInsert) {
                BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
            } else {
                $context->builder->clearInsertionPosition();
            }
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

        $ft = $context->context->functionType($htPtr, false, $strPtr);
        $fn = null !== $probe ? $probe : $context->module->addFunction(self::ABI, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);

        $path = $fn->getParam(0);
        $itemsSlot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i8pp);
        $context->builder->store($i8pp->constNull(), $itemsSlot);
        $count = $context->builder->call(
            $context->lookupFunction('__phpc_scandir_vec'),
            $path,
            $i32->constInt(0, false),
            $itemsSlot
        );
        $failed = $context->builder->icmp(Builder::INT_SLT, $count, $i32->constInt(0, false));
        $emptyBb = $fn->appendBasicBlock('dir_snap_empty');
        $buildBb = $fn->appendBasicBlock('dir_snap_build');
        $context->builder->branchIf($failed, $emptyBb, $buildBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue(HashTableHelper::alloc($context));

        $context->builder->positionAtEnd($buildBb);
        $ht = HashTableHelper::alloc($context);
        $outIdx = BasicBlockHelper::entryAllocaForFunction($context, $fn, $sizeT);
        $iSlot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $sizeT);
        $context->builder->store($sizeT->constInt(0, false), $outIdx);
        $context->builder->store($sizeT->constInt(0, false), $iSlot);
        $setString = $context->lookupFunction('__hashtable__setStringAt');
        $stringInit = $context->lookupFunction('__string__init');
        $strlenFn = $context->lookupFunction('strlen');
        $loopHead = $fn->appendBasicBlock('dir_snap_head');
        $loopBody = $fn->appendBasicBlock('dir_snap_body');
        $loopDone = $fn->appendBasicBlock('dir_snap_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $countSized = $context->builder->zExt($count, $sizeT);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $countSized);
        $context->builder->branchIf($atEnd, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $items = $context->builder->load($itemsSlot);
        $cstr = $context->builder->load($context->builder->inBoundsGep($items, $i));
        $len = $context->builder->call($strlenFn, $cstr);
        $lenI64 = $len->typeOf() === $i64 ? $len : $context->builder->zExt($len, $i64);
        $str = $context->builder->call($stringInit, $lenI64, $context->builder->pointerCast($cstr, $i8p));
        $oi = $context->builder->load($outIdx);
        $context->builder->call($setString, $ht, $oi, $str);
        $context->builder->store($context->builder->addNoSignedWrap($oi, $sizeT->constInt(1, false)), $outIdx);
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
