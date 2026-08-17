<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitFsGlobKernel;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT ABI for GlobIterator construct snapshot (#27422).
 *
 * Thin standalone AOT: libc {@see __phpc_glob_vec} via {@see JitFsGlobKernel::implement}
 * directly (#29986 — user-facing glob() no longer always-on links the kernel). Embed:
 * NestedJIT {@see \PHPCompiler\ext\spl\GlobIteratorSnapshotJitHelper}.
 * php-src: ext/spl/spl_directory.c — GlobIterator
 */
final class GlobIteratorSnapshotRuntime
{
    public const ABI = '__compiler_globiterator_snapshot';

    private const HELPER_PATH = '/ext/spl/GlobIteratorSnapshotJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\spl\\GlobIteratorSnapshotJitHelper::entriesArgv';

    private const BRIDGE_ENTRY = 'gi_snapshot_bridge_entry';

    private const FLAG_SKIP_DOTS = 4096;

    /** Match {@see \PHPCompiler\ext\standard\StdlibConstants::GLOB_AVAILABLE_FLAGS}. */
    private const GLOB_AVAILABLE_FLAGS = 9303;

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
            // User-facing glob() no longer always-on links the libc kernel (#29986); iterators
            // still need __phpc_glob_vec for the thin snapshot bridge.
            JitFsGlobKernel::implement($context);
            self::emitThinAotBridge($context, $probe);
        } else {
            StringFsGlob::ensureLinked($context);
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
                '#27422'
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

        $pattern = $fn->getParam(0);
        $flags = $fn->getParam(1);
        $itemsSlot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i8pp);
        $context->builder->store($i8pp->constNull(), $itemsSlot);
        $globFlags = $context->builder->trunc(
            $context->builder->and($flags, $i64->constInt(self::GLOB_AVAILABLE_FLAGS, false)),
            $i32
        );
        $count = $context->builder->call(
            $context->lookupFunction('__phpc_glob_vec'),
            $pattern,
            $globFlags,
            $itemsSlot
        );
        $failed = $context->builder->icmp(Builder::INT_SLT, $count, $i32->constInt(0, false));
        $emptyBb = $fn->appendBasicBlock('gi_snap_empty');
        $buildBb = $fn->appendBasicBlock('gi_snap_build');
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
        // strcmp(3) via LibcExtern::ensureStrcmpDecl after always-on drop (#31971).
        LibcExtern::ensureStrcmpDecl($context);
        $strcmpFn = $context->lookupFunction('strcmp');
        $loopHead = $fn->appendBasicBlock('gi_snap_head');
        $loopBody = $fn->appendBasicBlock('gi_snap_body');
        $loopDone = $fn->appendBasicBlock('gi_snap_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $countSized = $context->builder->zExt($count, $sizeT);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $countSized);
        $context->builder->branchIf($atEnd, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $items = $context->builder->load($itemsSlot);
        $cstr = $context->builder->load($context->builder->inBoundsGep($items, $i));
        // SKIP_DOTS: filter when basename is "." or ".." (path may be "./." etc.).
        $baseCstr = self::emitBasenameCstr($context, $fn, $cstr);
        $dot = $context->builder->pointerCast($context->constantFromString('.'), $i8p);
        $dotdot = $context->builder->pointerCast($context->constantFromString('..'), $i8p);
        $isDot = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call($strcmpFn, $baseCstr, $dot),
            $i32->constInt(0, false)
        );
        $isDotDot = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call($strcmpFn, $baseCstr, $dotdot),
            $i32->constInt(0, false)
        );
        $isDotEntry = $context->builder->or($isDot, $isDotDot);
        $skip = $context->builder->and($skipDots, $isDotEntry);
        $skipBb = $fn->appendBasicBlock('gi_snap_skip');
        $keepBb = $fn->appendBasicBlock('gi_snap_keep');
        $nextBb = $fn->appendBasicBlock('gi_snap_next');
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

    /** Pointer to final path component (after last '/'); $cstr when no slash. */
    private static function emitBasenameCstr(Context $context, LlvmFunction $fn, Value $cstr): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $slash = $i8->constInt(ord('/'), false);
        $zero = $i8->constInt(0, false);
        $one = $i64->constInt(1, false);

        $slot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i8p);
        $context->builder->store($cstr, $slot);
        $pSlot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i8p);
        $context->builder->store($cstr, $pSlot);

        $head = $fn->appendBasicBlock('gi_base_head');
        $body = $fn->appendBasicBlock('gi_base_body');
        $found = $fn->appendBasicBlock('gi_base_found');
        $done = $fn->appendBasicBlock('gi_base_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $p = $context->builder->load($pSlot);
        $ch = $context->builder->load($p);
        $atNul = $context->builder->icmp(Builder::INT_EQ, $ch, $zero);
        $context->builder->branchIf($atNul, $done, $body);

        $context->builder->positionAtEnd($body);
        $isSlash = $context->builder->icmp(Builder::INT_EQ, $ch, $slash);
        $context->builder->branchIf($isSlash, $found, $advance = $fn->appendBasicBlock('gi_base_adv'));

        $context->builder->positionAtEnd($found);
        $next = $context->builder->gep($p, $one);
        $context->builder->store($next, $slot);
        $context->builder->store($next, $pSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->gep($p, $one), $pSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($slot);
    }
}
