<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\VM\CycleCollector;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM GC registry + cycle collector for JIT/AOT (issues #3160, #5315).
 *
 * Replaces lib/AOT/runtime/phpc_gc.c; semantics mirror Zend gc_collect_cycles subset.
 * php-src: Zend/zend_gc.c
 */
final class GcCollectCyclesRuntime
{
    private const MAX_OBJECTS = 65536;

    private const TYPE_OBJECT = 5;

    private const TYPEINFO_TYPEMASK = 0xFFFFFFFC;

    private const TYPEINFO_TYPE_OBJECT = 8;

    private const G_COUNT = 'phpc_gc_count';

    private const G_RUNS = 'phpc_gc_runs';

    private const G_TOTAL_COLLECTED = 'phpc_gc_total_collected';

    private const G_ALLOW_DELREF = 'phpc_destruct_allow_delref';

    private const G_OBJECTS = 'phpc_gc_objects';

    private const G_PROP_COUNTS = 'phpc_gc_prop_counts';

    private const G_DESTRUCT_INVOKED = 'phpc_destruct_invoked';

    private const G_MARKED = 'phpc_gc_marked';

    private const G_INBOUND = 'phpc_gc_inbound';

    private const G_RUNNING = 'phpc_gc_running';

    private const G_PROTECTED = 'phpc_gc_protected';

    private const G_FULL = 'phpc_gc_full';

    private const G_BUFFER_SIZE = 'phpc_gc_buffer_size';

    private static int $blockSuffix = 0;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    /** LLVM bodies for standalone AOT (replaces phpc_gc.c link — #5315). */
    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('phpc_destruct_delref_allowed');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::$blockSuffix = 0;
        WeakRefRegistryRuntime::ensureLinked($context);
        GcToggleRuntime::ensureLinked($context);
        self::ensureGlobals($context);
        self::ensureExternals($context);
        self::ensureInternalDeclarations($context);

        $voidTy = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        self::implementAllowDelrefToggle($context);
        self::implementDestructDelrefAllowed($context);
        self::implementGcRegister($context);
        self::implementGcUnregister($context);
        self::implementDestructTryInvoke($context);
        self::implementRunShutdownDestructors($context);
        GcCollectCyclesCollectRuntime::implementCollectBridge($context);

        self::registerLinkedRuntime($context);
    }

    private static function implementAllowDelrefToggle(Context $context): void
    {
        $name = 'phpc_destruct_set_allow_delref';
        $voidTy = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($voidTy, false, $i32);
        $fn = self::functionOrCreate($context, $name, $ft);
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $entry = $fn->appendBasicBlock($name.'_entry');
        $context->builder->positionAtEnd($entry);
        $globalPtr = self::globalPtr($context, self::G_ALLOW_DELREF, $i32);
        $allow = $fn->getParam(0);
        $isNonZero = $context->builder->icmp(Builder::INT_NE, $allow, $i32->constInt(0, false));
        $one = $i32->constInt(1, false);
        $zero = $i32->constInt(0, false);
        $val = $context->builder->select($isNonZero, $one, $zero);
        $context->builder->store($val, $globalPtr);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
        $context->registerFunction($name, $fn);
    }

    private static function implementDestructDelrefAllowed(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false);
        $fn = self::functionOrCreate($context, 'phpc_destruct_delref_allowed', $ft);
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $entry = $fn->appendBasicBlock('destruct_delref_allowed_entry');
        $context->builder->positionAtEnd($entry);
        $loaded = $context->builder->load(self::globalPtr($context, self::G_ALLOW_DELREF, $i32));
        $context->builder->returnValue($loaded);
        $context->builder->clearInsertionPosition();
        $context->registerFunction('phpc_destruct_delref_allowed', $fn);
    }

    private static function implementGcRegister(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $ft = $context->context->functionType($voidTy, false, $i8p, $i32);
        $fn = self::functionOrCreate($context, 'phpc_gc_register', $ft);
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $entry = $fn->appendBasicBlock('gc_register_entry');
        $done = $fn->appendBasicBlock('gc_register_done');
        $work = $fn->appendBasicBlock('gc_register_work');
        $context->builder->positionAtEnd($entry);

        $obj = $fn->getParam(0);
        $propCount = $fn->getParam(1);
        $null = $i8p->constNull();
        $countPtr = self::globalPtr($context, self::G_COUNT, $i32);
        $count = $context->builder->load($countPtr);
        $max = $i32->constInt(self::MAX_OBJECTS, false);

        $objNull = $context->builder->icmp(Builder::INT_EQ, $obj, $null);
        $atMax = $context->builder->icmp(Builder::INT_SGE, $count, $max);
        $skip = $context->builder->or($objNull, $atMax);
        $context->builder->branchIf($skip, $done, $work);

        $context->builder->positionAtEnd($work);
        $idxFn = $context->lookupFunction('phpc_gc_index_of');
        $idx = $context->builder->call($idxFn, $obj);
        $already = $context->builder->icmp(Builder::INT_SGE, $idx, $i32->constInt(0, false));
        $insert = $fn->appendBasicBlock('gc_register_insert');
        $context->builder->branchIf($already, $done, $insert);

        $context->builder->positionAtEnd($insert);
        $idxExt = $context->builder->zext($count, $sizeT);
        $context->builder->store($obj, self::arrayElemPtr($context, self::G_OBJECTS, $i8p, $idxExt));
        $propVal = $context->builder->select(
            $context->builder->icmp(Builder::INT_SGT, $propCount, $i32->constInt(0, false)),
            $propCount,
            $i32->constInt(0, false)
        );
        $context->builder->store($propVal, self::arrayElemPtr($context, self::G_PROP_COUNTS, $i32, $idxExt));
        $i8 = $context->getTypeFromString('int8');
        $context->builder->store(
            $i8->constInt(0, false),
            self::arrayElemPtr($context, self::G_DESTRUCT_INVOKED, $i8, $idxExt)
        );
        $context->builder->store(
            $context->builder->add($count, $i32->constInt(1, false)),
            $countPtr
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
        $context->registerFunction('phpc_gc_register', $fn);
    }

    private static function implementGcUnregister(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $ft = $context->context->functionType($voidTy, false, $i8p);
        $fn = self::functionOrCreate($context, 'phpc_gc_unregister', $ft);
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $entry = $fn->appendBasicBlock('gc_unregister_entry');
        $done = $fn->appendBasicBlock('gc_unregister_done');
        $work = $fn->appendBasicBlock('gc_unregister_work');
        $context->builder->positionAtEnd($entry);

        $obj = $fn->getParam(0);
        $idxFn = $context->lookupFunction('phpc_gc_index_of');
        $idx = $context->builder->call($idxFn, $obj);
        $found = $context->builder->icmp(Builder::INT_SGE, $idx, $i32->constInt(0, false));
        $context->builder->branchIf($found, $work, $done);

        $context->builder->positionAtEnd($work);
        $countPtr = self::globalPtr($context, self::G_COUNT, $i32);
        $count = $context->builder->load($countPtr);
        $lastIdx = $context->builder->sub($count, $i32->constInt(1, false));
        $needSwap = $context->builder->icmp(Builder::INT_SLT, $idx, $lastIdx);
        $swapBb = $fn->appendBasicBlock('gc_unregister_swap');
        $decBb = $fn->appendBasicBlock('gc_unregister_dec');
        $context->builder->branchIf($needSwap, $swapBb, $decBb);

        $context->builder->positionAtEnd($swapBb);
        $lastExt = $context->builder->zext($lastIdx, $sizeT);
        $idxExt = $context->builder->zext($idx, $sizeT);
        $lastObj = $context->builder->load(self::arrayElemPtr($context, self::G_OBJECTS, $i8p, $lastExt));
        $lastProp = $context->builder->load(self::arrayElemPtr($context, self::G_PROP_COUNTS, $i32, $lastExt));
        $i8 = $context->getTypeFromString('int8');
        $lastInv = $context->builder->load(self::arrayElemPtr($context, self::G_DESTRUCT_INVOKED, $i8, $lastExt));
        $context->builder->store($lastObj, self::arrayElemPtr($context, self::G_OBJECTS, $i8p, $idxExt));
        $context->builder->store($lastProp, self::arrayElemPtr($context, self::G_PROP_COUNTS, $i32, $idxExt));
        $context->builder->store($lastInv, self::arrayElemPtr($context, self::G_DESTRUCT_INVOKED, $i8, $idxExt));
        $context->builder->branch($decBb);

        $context->builder->positionAtEnd($decBb);
        $context->builder->store($lastIdx, $countPtr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
        $context->registerFunction('phpc_gc_unregister', $fn);
    }

    private static function implementDestructTryInvoke(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $objPtr = $context->getTypeFromString('__object__*');
        $ft = $context->context->functionType($voidTy, false, $i8p);
        $fn = self::functionOrCreate($context, 'phpc_destruct_try_invoke', $ft);
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $entry = $fn->appendBasicBlock('destruct_try_entry');
        $done = $fn->appendBasicBlock('destruct_try_done');
        $work = $fn->appendBasicBlock('destruct_try_work');
        $invoke = $fn->appendBasicBlock('destruct_try_invoke');
        $context->builder->positionAtEnd($entry);

        $obj = $fn->getParam(0);
        $null = $i8p->constNull();
        $objNull = $context->builder->icmp(Builder::INT_EQ, $obj, $null);
        $alreadyFn = $context->lookupFunction('phpc_destruct_already_invoked');
        $already = $context->builder->call($alreadyFn, $obj);
        $alreadySet = $context->builder->icmp(Builder::INT_NE, $already, $i32->constInt(0, false));
        $skip = $context->builder->or($objNull, $alreadySet);
        $context->builder->branchIf($skip, $done, $work);

        $context->builder->positionAtEnd($work);
        $constructed = self::loadObjectConstructed($context, $obj);
        $isConstructed = $context->builder->icmp(Builder::INT_NE, $constructed, $i8->constInt(0, false));
        $context->builder->branchIf($isConstructed, $invoke, $done);

        $context->builder->positionAtEnd($invoke);
        $markFn = $context->lookupFunction('phpc_destruct_mark_invoked');
        $context->builder->call($markFn, $obj);
        $objTyped = $context->builder->pointerCast($obj, $objPtr);
        $context->builder->call($context->lookupFunction('__object__invoke_destructor'), $objTyped);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
        $context->registerFunction('phpc_destruct_try_invoke', $fn);
    }

    private static function implementRunShutdownDestructors(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $ft = $context->context->functionType($voidTy, false);
        $fn = self::functionOrCreate($context, 'phpc_gc_run_shutdown_destructors', $ft);
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $entry = $fn->appendBasicBlock('shutdown_entry');
        $loopHead = $fn->appendBasicBlock('shutdown_loop_head');
        $loopBody = $fn->appendBasicBlock('shutdown_loop_body');
        $loopNext = $fn->appendBasicBlock('shutdown_loop_next');
        $drainHead = $fn->appendBasicBlock('shutdown_drain_head');
        $drainBody = $fn->appendBasicBlock('shutdown_drain_body');
        $done = $fn->appendBasicBlock('shutdown_done');
        $context->builder->positionAtEnd($entry);

        $countPtr = self::globalPtr($context, self::G_COUNT, $i32);
        $allowPtr = self::globalPtr($context, self::G_ALLOW_DELREF, $i32);
        $i8 = $context->getTypeFromString('int8');
        $iSlot = $context->builder->alloca($i32, 1, 'shutdown_i');
        $count = $context->builder->load($countPtr);
        $context->builder->store($context->builder->sub($count, $i32->constInt(1, false)), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $continueLoop = $context->builder->icmp(Builder::INT_SGE, $i, $i32->constInt(0, false));
        $context->builder->branchIf($continueLoop, $loopBody, $drainHead);

        $context->builder->positionAtEnd($loopBody);
        $idxExt = $context->builder->zext($i, $sizeT);
        $invoked = $context->builder->load(self::arrayElemPtr($context, self::G_DESTRUCT_INVOKED, $i8, $idxExt));
        $needsInvoke = $context->builder->icmp(Builder::INT_EQ, $invoked, $i8->constInt(0, false));
        $invokeBb = $fn->appendBasicBlock('shutdown_invoke');
        $context->builder->branchIf($needsInvoke, $invokeBb, $loopNext);

        $context->builder->positionAtEnd($invokeBb);
        $obj = $context->builder->load(self::arrayElemPtr($context, self::G_OBJECTS, $i8p, $idxExt));
        $context->builder->call($context->lookupFunction('phpc_destruct_try_invoke'), $obj);
        $context->builder->branch($loopNext);

        $context->builder->positionAtEnd($loopNext);
        $context->builder->store($context->builder->sub($i, $i32->constInt(1, false)), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($drainHead);
        $context->builder->store($i32->constInt(1, false), $allowPtr);
        $countNow = $context->builder->load($countPtr);
        $hasMore = $context->builder->icmp(Builder::INT_SGT, $countNow, $i32->constInt(0, false));
        $context->builder->branchIf($hasMore, $drainBody, $done);

        $context->builder->positionAtEnd($drainBody);
        $lastIdx = $context->builder->sub($countNow, $i32->constInt(1, false));
        $lastExt = $context->builder->zext($lastIdx, $sizeT);
        $obj = $context->builder->load(self::arrayElemPtr($context, self::G_OBJECTS, $i8p, $lastExt));
        $context->builder->call($context->lookupFunction('phpc_object_release_storage'), $obj);
        $context->builder->branch($drainHead);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
        $context->registerFunction('phpc_gc_run_shutdown_destructors', $fn);
    }

    private static function ensureInternalDeclarations(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $voidTy = $context->getTypeFromString('void');
        $objPtr = $context->getTypeFromString('__object__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $voidpp = $context->getTypeFromString('void**');

        $internals = [
            'phpc_gc_index_of' => [$i32, false, [$i8p]],
            'phpc_destruct_already_invoked' => [$i32, false, [$i8p]],
            'phpc_destruct_mark_invoked' => [$voidTy, false, [$i8p]],
            'phpc_gc_collect_cycles_impl' => [$i32, false, []],
            'phpc_object_release_storage' => [$voidTy, false, [$i8p]],
            'phpc_gc_slot_read_object' => [$objPtr, false, [$voidpp]],
            'phpc_gc_visit_object' => [$voidTy, false, [$i32]],
            'phpc_gc_free_object' => [$voidTy, false, [$i8p]],
            'phpc_gc_clear_slots_pointing_to' => [$voidTy, false, [$i8p]],
        ];
        foreach ($internals as $name => [$ret, $vararg, $params]) {
            if (null !== $context->module->getNamedFunction($name)) {
                continue;
            }
            $ft = $context->context->functionType($ret, $vararg, ...$params);
            $context->registerFunction($name, $context->module->addFunction($name, $ft));
        }

        self::implementIndexOf($context);
        self::implementDestructAlreadyInvoked($context);
        self::implementDestructMarkInvoked($context);
        self::implementSlotReadObject($context);
        self::implementVisitObject($context);
        self::implementClearSlotsPointingTo($context);
        self::implementFreeObject($context);
        self::implementCollectCyclesImpl($context);
        self::implementObjectReleaseStorage($context);
    }

    private static function implementIndexOf(Context $context): void
    {
        $fn = $context->lookupFunction('phpc_gc_index_of');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $entry = $fn->appendBasicBlock('gc_index_entry');
        $loopHead = $fn->appendBasicBlock('gc_index_head');
        $loopBody = $fn->appendBasicBlock('gc_index_body');
        $found = $fn->appendBasicBlock('gc_index_found');
        $notFound = $fn->appendBasicBlock('gc_index_not_found');
        $context->builder->positionAtEnd($entry);

        $target = $fn->getParam(0);
        $iSlot = $context->builder->alloca($i32, 1, 'gc_index_i');
        $context->builder->store($i32->constInt(0, false), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $count = $context->builder->load(self::globalPtr($context, self::G_COUNT, $i32));
        $inRange = $context->builder->icmp(Builder::INT_SLT, $i, $count);
        $context->builder->branchIf($inRange, $loopBody, $notFound);

        $context->builder->positionAtEnd($loopBody);
        $idxExt = $context->builder->zext($i, $sizeT);
        $stored = $context->builder->load(self::arrayElemPtr($context, self::G_OBJECTS, $i8p, $idxExt));
        $match = $context->builder->icmp(Builder::INT_EQ, $stored, $target);
        $next = $fn->appendBasicBlock('gc_index_next');
        $context->builder->branchIf($match, $found, $next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->add($i, $i32->constInt(1, false)), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($found);
        $context->builder->returnValue($i);

        $context->builder->positionAtEnd($notFound);
        $context->builder->returnValue($i32->constInt(-1, true));
        $context->builder->clearInsertionPosition();
    }

    private static function implementDestructAlreadyInvoked(Context $context): void
    {
        $fn = $context->lookupFunction('phpc_destruct_already_invoked');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $entry = $fn->appendBasicBlock('destruct_already_entry');
        $found = $fn->appendBasicBlock('destruct_already_found');
        $miss = $fn->appendBasicBlock('destruct_already_miss');
        $context->builder->positionAtEnd($entry);

        $obj = $fn->getParam(0);
        $idx = $context->builder->call($context->lookupFunction('phpc_gc_index_of'), $obj);
        $hasIdx = $context->builder->icmp(Builder::INT_SGE, $idx, $i32->constInt(0, false));
        $context->builder->branchIf($hasIdx, $found, $miss);

        $context->builder->positionAtEnd($found);
        $idxExt = $context->builder->zext($idx, $sizeT);
        $inv = $context->builder->load(self::arrayElemPtr($context, self::G_DESTRUCT_INVOKED, $i8, $idxExt));
        $context->builder->returnValue($context->builder->zext($inv, $i32));

        $context->builder->positionAtEnd($miss);
        $context->builder->returnValue($i32->constInt(0, false));
        $context->builder->clearInsertionPosition();
    }

    private static function implementDestructMarkInvoked(Context $context): void
    {
        $fn = $context->lookupFunction('phpc_destruct_mark_invoked');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $entry = $fn->appendBasicBlock('destruct_mark_entry');
        $work = $fn->appendBasicBlock('destruct_mark_work');
        $done = $fn->appendBasicBlock('destruct_mark_done');
        $context->builder->positionAtEnd($entry);

        $obj = $fn->getParam(0);
        $idx = $context->builder->call($context->lookupFunction('phpc_gc_index_of'), $obj);
        $hasIdx = $context->builder->icmp(Builder::INT_SGE, $idx, $i32->constInt(0, false));
        $context->builder->branchIf($hasIdx, $work, $done);

        $context->builder->positionAtEnd($work);
        $idxExt = $context->builder->zext($idx, $sizeT);
        $context->builder->store(
            $i8->constInt(1, false),
            self::arrayElemPtr($context, self::G_DESTRUCT_INVOKED, $i8, $idxExt)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementSlotReadObject(Context $context): void
    {
        $fn = $context->lookupFunction('phpc_gc_slot_read_object');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $objPtr = $context->getTypeFromString('__object__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $voidpp = $context->getTypeFromString('void**');
        $entry = $fn->appendBasicBlock('slot_read_entry');
        $nullRet = $fn->appendBasicBlock('slot_read_null');
        $boxed = $fn->appendBasicBlock('slot_read_boxed');
        $direct = $fn->appendBasicBlock('slot_read_direct');
        $done = $fn->appendBasicBlock('slot_read_done');
        $context->builder->positionAtEnd($entry);

        $slotPtr = $fn->getParam(0);
        $null = $voidpp->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $slotPtr, $null);
        $context->builder->branchIf($isNull, $nullRet, $boxed);

        $context->builder->positionAtEnd($boxed);
        $slotVal = $context->builder->load($slotPtr);
        $slotI8 = $context->builder->pointerCast($slotVal, $i8p);
        $boxedVal = $context->builder->pointerCast($slotI8, $valuePtr);
        $typePtr = $context->builder->pointerCast($boxedVal, $i8p);
        $typeByte = $context->builder->load($typePtr);
        $typeMasked = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isObjBox = $context->builder->icmp(Builder::INT_EQ, $typeMasked, $i8->constInt(self::TYPE_OBJECT, false));
        $readBoxed = $fn->appendBasicBlock('slot_read_boxed_obj');
        $context->builder->branchIf($isObjBox, $readBoxed, $direct);

        $context->builder->positionAtEnd($readBoxed);
        $objFromBox = $context->builder->call($context->lookupFunction('__value__readObject'), $boxedVal);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($direct);
        $headTypeinfoPtr = $context->builder->pointerCast(
            $context->builder->inBoundsGEP($slotI8, $i32->constInt(4, false)),
            $i32->pointerType(0)
        );
        $typeinfo = $context->builder->load($headTypeinfoPtr);
        $mask = $i32->constInt(self::TYPEINFO_TYPEMASK, false);
        $objType = $i32->constInt(self::TYPEINFO_TYPE_OBJECT, false);
        $isDirectObj = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->and($typeinfo, $mask),
            $objType
        );
        $directObj = $fn->appendBasicBlock('slot_read_direct_obj');
        $context->builder->branchIf($isDirectObj, $directObj, $nullRet);

        $context->builder->positionAtEnd($directObj);
        $objDirect = $context->builder->pointerCast($slotI8, $objPtr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($nullRet);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($objPtr);
        $phi->addIncoming($objPtr->constNull(), $nullRet);
        $phi->addIncoming($objFromBox, $readBoxed);
        $phi->addIncoming($objDirect, $directObj);
        $context->builder->returnValue($phi);
        $context->builder->clearInsertionPosition();
    }

    private static function implementVisitObject(Context $context): void
    {
        $fn = $context->lookupFunction('phpc_gc_visit_object');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidpp = $context->getTypeFromString('void**');
        $objPtr = $context->getTypeFromString('__object__*');
        $entry = $fn->appendBasicBlock('visit_obj_entry');
        $loopHead = $fn->appendBasicBlock('visit_obj_head');
        $loopBody = $fn->appendBasicBlock('visit_obj_body');
        $done = $fn->appendBasicBlock('visit_obj_done');
        $context->builder->positionAtEnd($entry);

        $objIndex = $fn->getParam(0);
        $idxExt = $context->builder->zext($objIndex, $sizeT);
        $obj = $context->builder->load(self::arrayElemPtr($context, self::G_OBJECTS, $i8p, $idxExt));
        $propCount = $context->builder->load(self::arrayElemPtr($context, self::G_PROP_COUNTS, $i32, $idxExt));
        $headerSize = self::objectHeaderSizeConst($context);
        $base = $context->builder->pointerCast($obj, $i8p);
        $slotSlot = $context->builder->alloca($i32, 1, 'visit_slot');
        $context->builder->store($i32->constInt(0, false), $slotSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $slot = $context->builder->load($slotSlot);
        $inRange = $context->builder->icmp(Builder::INT_SLT, $slot, $propCount);
        $context->builder->branchIf($inRange, $loopBody, $done);

        $context->builder->positionAtEnd($loopBody);
        $slotOff = $context->builder->add(
            $headerSize,
            $context->builder->mul(
                $context->builder->zext($slot, $sizeT),
                $sizeT->constInt(8, false)
            )
        );
        $slotPtr = $context->builder->pointerCast(
            $context->builder->gep($base, $slotOff),
            $voidpp
        );
        $childBb = $fn->appendBasicBlock('visit_obj_child');
        $afterNull = $fn->appendBasicBlock('visit_obj_after_null');
        $child = $context->builder->call($context->lookupFunction('phpc_gc_slot_read_object'), $slotPtr);
        $childNull = $context->builder->icmp(Builder::INT_EQ, $child, $objPtr->constNull());
        $context->builder->branchIf($childNull, $afterNull, $childBb);

        $context->builder->positionAtEnd($childBb);
        $childI8 = $context->builder->pointerCast($child, $i8p);
        $childIdx = $context->builder->call($context->lookupFunction('phpc_gc_index_of'), $childI8);
        $validChild = $context->builder->icmp(Builder::INT_SGE, $childIdx, $i32->constInt(0, false));
        $markBb = $fn->appendBasicBlock('visit_obj_mark');
        $context->builder->branchIf($validChild, $markBb, $afterNull);

        $context->builder->positionAtEnd($markBb);
        $childExt = $context->builder->zext($childIdx, $sizeT);
        $i8 = $context->getTypeFromString('int8');
        $markedPtr = self::arrayElemPtr($context, self::G_MARKED, $i8, $childExt);
        $already = $context->builder->load($markedPtr);
        $notMarked = $context->builder->icmp(Builder::INT_EQ, $already, $i8->constInt(0, false));
        $recurseBb = $fn->appendBasicBlock('visit_obj_recurse');
        $context->builder->branchIf($notMarked, $recurseBb, $afterNull);

        $context->builder->positionAtEnd($recurseBb);
        $context->builder->store($i8->constInt(1, false), $markedPtr);
        $context->builder->call($context->lookupFunction('phpc_gc_visit_object'), $childIdx);
        $context->builder->branch($afterNull);

        $context->builder->positionAtEnd($afterNull);
        $context->builder->store($context->builder->add($slot, $i32->constInt(1, false)), $slotSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementClearSlotsPointingTo(Context $context): void
    {
        $fn = $context->lookupFunction('phpc_gc_clear_slots_pointing_to');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidpp = $context->getTypeFromString('void**');
        $objPtr = $context->getTypeFromString('__object__*');
        $entry = $fn->appendBasicBlock('clear_slots_entry');
        $iLoop = $fn->appendBasicBlock('clear_slots_i_head');
        $iBody = $fn->appendBasicBlock('clear_slots_i_body');
        $sLoop = $fn->appendBasicBlock('clear_slots_s_head');
        $sBody = $fn->appendBasicBlock('clear_slots_s_body');
        $iNext = $fn->appendBasicBlock('clear_slots_i_next');
        $clearBb = $fn->appendBasicBlock('clear_slots_clear');
        $sNext = $fn->appendBasicBlock('clear_slots_s_next');
        $done = $fn->appendBasicBlock('clear_slots_done');
        $context->builder->positionAtEnd($entry);

        $target = $fn->getParam(0);
        $targetObj = $context->builder->pointerCast($target, $objPtr);
        $iSlot = $context->builder->alloca($i32, 1, 'clear_i');
        $sSlot = $context->builder->alloca($i32, 1, 'clear_s');
        $context->builder->store($i32->constInt(0, false), $iSlot);
        $context->builder->branch($iLoop);

        $context->builder->positionAtEnd($iLoop);
        $i = $context->builder->load($iSlot);
        $count = $context->builder->load(self::globalPtr($context, self::G_COUNT, $i32));
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $i, $count),
            $iBody,
            $done
        );

        $context->builder->positionAtEnd($iBody);
        $idxExt = $context->builder->zext($i, $sizeT);
        $obj = $context->builder->load(self::arrayElemPtr($context, self::G_OBJECTS, $i8p, $idxExt));
        $propCount = $context->builder->load(self::arrayElemPtr($context, self::G_PROP_COUNTS, $i32, $idxExt));
        $context->builder->store($i32->constInt(0, false), $sSlot);
        $context->builder->branch($sLoop);

        $context->builder->positionAtEnd($sLoop);
        $s = $context->builder->load($sSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $s, $propCount),
            $sBody,
            $iNext
        );

        $context->builder->positionAtEnd($iNext);
        $context->builder->store($context->builder->add($i, $i32->constInt(1, false)), $iSlot);
        $context->builder->branch($iLoop);

        $context->builder->positionAtEnd($sBody);
        $headerSize = self::objectHeaderSizeConst($context);
        $base = $context->builder->pointerCast($obj, $i8p);
        $slotOff = $context->builder->add(
            $headerSize,
            $context->builder->mul($context->builder->zext($s, $sizeT), $sizeT->constInt(8, false))
        );
        $slotPtr = $context->builder->pointerCast($context->builder->gep($base, $slotOff), $voidpp);
        $child = $context->builder->call($context->lookupFunction('phpc_gc_slot_read_object'), $slotPtr);
        $matches = $context->builder->icmp(Builder::INT_EQ, $child, $targetObj);
        $context->builder->branchIf($matches, $clearBb, $sNext);

        $context->builder->positionAtEnd($clearBb);
        $context->builder->store($context->getTypeFromString('void*')->constNull(), $slotPtr);
        $context->builder->branch($sNext);

        $context->builder->positionAtEnd($sNext);
        $context->builder->store($context->builder->add($s, $i32->constInt(1, false)), $sSlot);
        $context->builder->branch($sLoop);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementFreeObject(Context $context): void
    {
        $fn = $context->lookupFunction('phpc_gc_free_object');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $entry = $fn->appendBasicBlock('free_obj_entry');
        $context->builder->positionAtEnd($entry);
        $obj = $fn->getParam(0);
        $context->builder->call($context->lookupFunction('phpc_destruct_try_invoke'), $obj);
        $context->builder->call($context->lookupFunction('phpc_gc_notify_object_freed'), $obj);
        $context->builder->call($context->lookupFunction('phpc_gc_clear_slots_pointing_to'), $obj);
        $context->builder->call($context->lookupFunction('phpc_gc_unregister'), $obj);
        $context->builder->call($context->lookupFunction('__mm__free'), $obj);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementObjectReleaseStorage(Context $context): void
    {
        $fn = $context->lookupFunction('phpc_object_release_storage');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $i8p = $context->getTypeFromString('int8*');
        $entry = $fn->appendBasicBlock('release_entry');
        $nullRet = $fn->appendBasicBlock('release_null');
        $work = $fn->appendBasicBlock('release_work');
        $context->builder->positionAtEnd($entry);
        $obj = $fn->getParam(0);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $obj, $i8p->constNull());
        $context->builder->branchIf($isNull, $nullRet, $work);

        $context->builder->positionAtEnd($work);
        $context->builder->call($context->lookupFunction('phpc_gc_notify_object_freed'), $obj);
        $context->builder->call($context->lookupFunction('phpc_gc_unregister'), $obj);
        $context->builder->call($context->lookupFunction('__mm__free'), $obj);
        $context->builder->branch($nullRet);

        $context->builder->positionAtEnd($nullRet);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementCollectCyclesImpl(Context $context): void
    {
        $fn = $context->lookupFunction('phpc_gc_collect_cycles_impl');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidpp = $context->getTypeFromString('void**');
        $objPtr = $context->getTypeFromString('__object__*');
        $entry = $fn->appendBasicBlock('collect_impl_entry');
        $early = $fn->appendBasicBlock('collect_impl_early');
        $init = $fn->appendBasicBlock('collect_impl_init');
        $done = $fn->appendBasicBlock('collect_impl_done');
        $context->builder->positionAtEnd($entry);

        $iSlot = $context->builder->alloca($i32, 1, 'collect_i');
        $sSlot = $context->builder->alloca($i32, 1, 'collect_s');
        $collectedSlot = $context->builder->alloca($i32, 1, 'collect_n');
        $context->builder->store($i32->constInt(0, false), $collectedSlot);

        $enabled = $context->builder->call($context->lookupFunction('phpc_gc_is_enabled'));
        $countPtr = self::globalPtr($context, self::G_COUNT, $i32);
        $count = $context->builder->load($countPtr);
        $disabled = $context->builder->icmp(Builder::INT_EQ, $enabled, $i32->constInt(0, false));
        $empty = $context->builder->icmp(Builder::INT_SLE, $count, $i32->constInt(0, false));
        $skip = $context->builder->or($disabled, $empty);
        $context->builder->branchIf($skip, $early, $init);

        $context->builder->positionAtEnd($early);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($init);
        $countExt = $context->builder->zext($count, $sizeT);
        $markedBase = self::globalPtr($context, self::G_MARKED, $i8);
        $inboundBase = self::globalPtr($context, self::G_INBOUND, $i32);
        $context->builder->call(
            $context->lookupFunction('memset'),
            $context->builder->pointerCast($markedBase, $i8p),
            $i32->constInt(0, false),
            $countExt
        );
        $inboundBytes = $context->builder->mul(
            $countExt,
            $sizeT->constInt(4, false)
        );
        $context->builder->call(
            $context->lookupFunction('memset'),
            $context->builder->pointerCast($inboundBase, $i8p),
            $i32->constInt(0, false),
            $inboundBytes
        );

        // Count inbound edges
        $context->builder->store($i32->constInt(0, false), $iSlot);
        $inLoop = $fn->appendBasicBlock('collect_in_i_head');
        $inBody = $fn->appendBasicBlock('collect_in_i_body');
        $markRoots = $fn->appendBasicBlock('collect_mark_roots');
        $sLoop = $fn->appendBasicBlock('collect_in_s_head');
        $sBody = $fn->appendBasicBlock('collect_in_s_body');
        $iNext = $fn->appendBasicBlock('collect_in_i_next');
        $sNext = $fn->appendBasicBlock('collect_in_s_next');
        $incBb = $fn->appendBasicBlock('collect_in_inc');
        $doInc = $fn->appendBasicBlock('collect_in_do_inc');
        $rootLoop = $fn->appendBasicBlock('collect_root_head');
        $rootBody = $fn->appendBasicBlock('collect_root_body');
        $rootMark = $fn->appendBasicBlock('collect_root_mark');
        $rootVisit = $fn->appendBasicBlock('collect_root_visit');
        $rootNext = $fn->appendBasicBlock('collect_root_next');
        $sweep = $fn->appendBasicBlock('collect_sweep');
        $sweepLoop = $fn->appendBasicBlock('collect_sweep_head');
        $sweepBody = $fn->appendBasicBlock('collect_sweep_body');
        $freeBb = $fn->appendBasicBlock('collect_sweep_free');
        $sweepNext = $fn->appendBasicBlock('collect_sweep_next');
        $sweepExit = $fn->appendBasicBlock('collect_sweep_exit');
        $context->builder->branch($inLoop);

        $context->builder->positionAtEnd($inLoop);
        $i = $context->builder->load($iSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $i, $count),
            $inBody,
            $markRoots
        );

        $context->builder->positionAtEnd($inBody);
        $idxExt = $context->builder->zext($i, $sizeT);
        $obj = $context->builder->load(self::arrayElemPtr($context, self::G_OBJECTS, $i8p, $idxExt));
        $propCount = $context->builder->load(self::arrayElemPtr($context, self::G_PROP_COUNTS, $i32, $idxExt));
        $context->builder->store($i32->constInt(0, false), $sSlot);
        $context->builder->branch($sLoop);

        $context->builder->positionAtEnd($sLoop);
        $s = $context->builder->load($sSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $s, $propCount),
            $sBody,
            $iNext
        );

        $context->builder->positionAtEnd($iNext);
        $context->builder->store($context->builder->add($i, $i32->constInt(1, false)), $iSlot);
        $context->builder->branch($inLoop);

        $context->builder->positionAtEnd($sBody);
        $headerSize = self::objectHeaderSizeConst($context);
        $base = $context->builder->pointerCast($obj, $i8p);
        $slotOff = $context->builder->add(
            $headerSize,
            $context->builder->mul($context->builder->zext($s, $sizeT), $sizeT->constInt(8, false))
        );
        $slotPtr = $context->builder->pointerCast($context->builder->gep($base, $slotOff), $voidpp);
        $child = $context->builder->call($context->lookupFunction('phpc_gc_slot_read_object'), $slotPtr);
        $childNull = $context->builder->icmp(Builder::INT_EQ, $child, $objPtr->constNull());
        $context->builder->branchIf($childNull, $sNext, $incBb);

        $context->builder->positionAtEnd($incBb);
        $childI8 = $context->builder->pointerCast($child, $i8p);
        $childIdx = $context->builder->call($context->lookupFunction('phpc_gc_index_of'), $childI8);
        $valid = $context->builder->icmp(Builder::INT_SGE, $childIdx, $i32->constInt(0, false));
        $context->builder->branchIf($valid, $doInc, $sNext);

        $context->builder->positionAtEnd($doInc);
        $childExt = $context->builder->zext($childIdx, $sizeT);
        $inPtr = self::arrayElemPtr($context, self::G_INBOUND, $i32, $childExt);
        $cur = $context->builder->load($inPtr);
        $context->builder->store($context->builder->add($cur, $i32->constInt(1, false)), $inPtr);
        $context->builder->branch($sNext);

        $context->builder->positionAtEnd($sNext);
        $context->builder->store($context->builder->add($s, $i32->constInt(1, false)), $sSlot);
        $context->builder->branch($sLoop);

        // Mark roots and visit
        $context->builder->positionAtEnd($markRoots);
        $context->builder->store($i32->constInt(0, false), $iSlot);
        $context->builder->branch($rootLoop);

        $context->builder->positionAtEnd($rootLoop);
        $ri = $context->builder->load($iSlot);
        $countNow = $context->builder->load($countPtr);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $ri, $countNow),
            $rootBody,
            $sweep
        );

        $context->builder->positionAtEnd($rootBody);
        $riExt = $context->builder->zext($ri, $sizeT);
        $obj = $context->builder->load(self::arrayElemPtr($context, self::G_OBJECTS, $i8p, $riExt));
        $objTyped = $context->builder->pointerCast($obj, $objPtr);
        $refcount = self::loadObjectRefcount($context, $objTyped);
        $inbound = $context->builder->load(self::arrayElemPtr($context, self::G_INBOUND, $i32, $riExt));
        $roots = $context->builder->sub($refcount, $inbound);
        $hasRoots = $context->builder->icmp(Builder::INT_SGT, $roots, $i32->constInt(0, false));
        $context->builder->branchIf($hasRoots, $rootMark, $rootNext);

        $context->builder->positionAtEnd($rootMark);
        $markedPtr = self::arrayElemPtr($context, self::G_MARKED, $i8, $riExt);
        $already = $context->builder->load($markedPtr);
        $needsVisit = $context->builder->icmp(Builder::INT_EQ, $already, $i8->constInt(0, false));
        $context->builder->branchIf($needsVisit, $rootVisit, $rootNext);

        $context->builder->positionAtEnd($rootVisit);
        $context->builder->store($i8->constInt(1, false), $markedPtr);
        $context->builder->call($context->lookupFunction('phpc_gc_visit_object'), $ri);
        $context->builder->branch($rootNext);

        $context->builder->positionAtEnd($rootNext);
        $context->builder->store($context->builder->add($ri, $i32->constInt(1, false)), $iSlot);
        $context->builder->branch($rootLoop);

        // Sweep unmarked (index may shift when freeing)
        $context->builder->positionAtEnd($sweep);
        $context->builder->store($i32->constInt(0, false), $iSlot);
        $context->builder->branch($sweepLoop);

        $context->builder->positionAtEnd($sweepLoop);
        $si = $context->builder->load($iSlot);
        $countSweep = $context->builder->load($countPtr);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $si, $countSweep),
            $sweepBody,
            $sweepExit
        );

        $context->builder->positionAtEnd($sweepExit);
        $finalCollected = $context->builder->load($collectedSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($sweepBody);
        $siExt = $context->builder->zext($si, $sizeT);
        $marked = $context->builder->load(self::arrayElemPtr($context, self::G_MARKED, $i8, $siExt));
        $unmarked = $context->builder->icmp(Builder::INT_EQ, $marked, $i8->constInt(0, false));
        $context->builder->branchIf($unmarked, $freeBb, $sweepNext);

        $context->builder->positionAtEnd($freeBb);
        $obj = $context->builder->load(self::arrayElemPtr($context, self::G_OBJECTS, $i8p, $siExt));
        $context->builder->call($context->lookupFunction('phpc_gc_free_object'), $obj);
        $curN = $context->builder->load($collectedSlot);
        $context->builder->store($context->builder->add($curN, $i32->constInt(1, false)), $collectedSlot);
        $context->builder->branch($sweepLoop);

        $context->builder->positionAtEnd($sweepNext);
        $context->builder->store($context->builder->add($si, $i32->constInt(1, false)), $iSlot);
        $context->builder->branch($sweepLoop);

        $context->builder->positionAtEnd($done);
        $zero = $i32->constInt(0, false);
        $retPhi = $context->builder->phi($i32);
        $retPhi->addIncoming($zero, $early);
        $retPhi->addIncoming($finalCollected, $sweepExit);
        $context->builder->returnValue($retPhi);
        $context->builder->clearInsertionPosition();
    }

    private static function loadObjectConstructed(Context $context, Value $objI8): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $objPtr = $context->getTypeFromString('__object__*');
        $objMap = $context->structFieldMap['__object__'] ?? null;
        if (null !== $objMap && isset($objMap['constructed'])) {
            $objTyped = $context->builder->pointerCast($objI8, $objPtr);

            return $context->builder->load(self::objectFieldPtr($context, $objTyped, 'constructed', $i8));
        }
        $i32 = $context->getTypeFromString('int32');
        $constructedPtr = $context->builder->pointerCast(
            $context->builder->inBoundsGEP($objI8, $i32->constInt(16, false)),
            $i8->pointerType(0)
        );

        return $context->builder->load($constructedPtr);
    }

    private static function loadObjectRefcount(Context $context, Value $obj): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $objMap = $context->structFieldMap['__object__'] ?? null;
        $refMap = $context->structFieldMap['__ref__'] ?? null;
        if (null !== $objMap && null !== $refMap && isset($objMap['ref'], $refMap['refcount'])) {
            $refField = $context->builder->structGep($obj, $objMap['ref']);
            $refcountPtr = $context->builder->structGep($refField, $refMap['refcount']);

            return $context->builder->load($refcountPtr);
        }
        $i8p = $context->getTypeFromString('int8*');
        $raw = $context->builder->pointerCast($obj, $i8p);
        $refcountPtr = $context->builder->pointerCast($raw, $i32->pointerType(0));

        return $context->builder->load($refcountPtr);
    }

    private static function objectHeaderSizeConst(Context $context): Value
    {
        $objTy = $context->getTypeFromString('__object__');
        $one = $context->context->int32Type()->constInt(1, false);

        return $context->builder->pointerCast(
            $context->builder->gep($objTy->pointerType(0)->constNull(), $one),
            $context->getTypeFromString('size_t')
        );
    }

    private static function objectFieldPtr(Context $context, Value $obj, string $field, $fieldType): Value
    {
        $map = $context->structFieldMap['__object__'] ?? null;
        if (null === $map || !isset($map[$field])) {
            throw new \LogicException('__object__ field missing for GC runtime: '.$field);
        }

        return $context->builder->pointerCast(
            $context->builder->structGep($obj, $map[$field]),
            $fieldType->pointerType(0)
        );
    }

    private static function arrayElemPtr(Context $context, string $globalName, $elemType, Value $index): Value
    {
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('GC global missing: '.$globalName);
        }
        $arrayPtr = $context->builder->pointerCast($global, $elemType->pointerType(0)->pointerType(0));
        $elemPtr = $context->builder->inBoundsGEP($arrayPtr, $index);

        return $context->builder->pointerCast($elemPtr, $elemType->pointerType(0));
    }

    private static function globalPtr(Context $context, string $name, $llvmType): Value
    {
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            throw new \LogicException('GC global missing: '.$name);
        }

        return $context->builder->pointerCast($global, $llvmType->pointerType(0));
    }

    private static function functionOrCreate(Context $context, string $name, $fnType): LlvmFunction
    {
        $existing = $context->module->getNamedFunction($name);
        if (null !== $existing) {
            return $existing;
        }
        $fn = $context->module->addFunction($name, $fnType);
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureGlobals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');

        if (null === $context->module->getNamedGlobal(self::G_COUNT)) {
            $g = $context->module->addGlobal($i32, self::G_COUNT);
            $g->setInitializer($i32->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_RUNS)) {
            $g = $context->module->addGlobal($i32, self::G_RUNS);
            $g->setInitializer($i32->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_TOTAL_COLLECTED)) {
            $g = $context->module->addGlobal($i32, self::G_TOTAL_COLLECTED);
            $g->setInitializer($i32->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_ALLOW_DELREF)) {
            $g = $context->module->addGlobal($i32, self::G_ALLOW_DELREF);
            $g->setInitializer($i32->constInt(1, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_OBJECTS)) {
            $ty = $i8p->arrayType(self::MAX_OBJECTS);
            $g = $context->module->addGlobal($ty, self::G_OBJECTS);
            $g->setInitializer($ty->constNull());
        }
        if (null === $context->module->getNamedGlobal(self::G_PROP_COUNTS)) {
            $ty = $i32->arrayType(self::MAX_OBJECTS);
            $g = $context->module->addGlobal($ty, self::G_PROP_COUNTS);
            $g->setInitializer($ty->constNull());
        }
        if (null === $context->module->getNamedGlobal(self::G_DESTRUCT_INVOKED)) {
            $ty = $i8->arrayType(self::MAX_OBJECTS);
            $g = $context->module->addGlobal($ty, self::G_DESTRUCT_INVOKED);
            $g->setInitializer($ty->constNull());
        }
        if (null === $context->module->getNamedGlobal(self::G_MARKED)) {
            $ty = $i8->arrayType(self::MAX_OBJECTS);
            $g = $context->module->addGlobal($ty, self::G_MARKED);
            $g->setInitializer($ty->constNull());
        }
        if (null === $context->module->getNamedGlobal(self::G_INBOUND)) {
            $ty = $i32->arrayType(self::MAX_OBJECTS);
            $g = $context->module->addGlobal($ty, self::G_INBOUND);
            $g->setInitializer($ty->constNull());
        }
        if (null === $context->module->getNamedGlobal(self::G_RUNNING)) {
            $g = $context->module->addGlobal($i32, self::G_RUNNING);
            $g->setInitializer($i32->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_PROTECTED)) {
            $g = $context->module->addGlobal($i32, self::G_PROTECTED);
            $g->setInitializer($i32->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_FULL)) {
            $g = $context->module->addGlobal($i32, self::G_FULL);
            $g->setInitializer($i32->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_BUFFER_SIZE)) {
            $g = $context->module->addGlobal($i32, self::G_BUFFER_SIZE);
            $g->setInitializer($i32->constInt(CycleCollector::DEFAULT_BUFFER_SIZE, false));
        }
    }

    private static function ensureExternals(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $objPtr = $context->getTypeFromString('__object__*');
        $valuePtr = $context->getTypeFromString('__value__*');

        foreach (
            [
                ['memset', $context->context->functionType($i8p, false, $i8p, $i32, $sizeT)],
                ['__mm__free', $context->context->functionType($voidTy, false, $i8p)],
                ['__value__readObject', $context->context->functionType($objPtr, false, $valuePtr)],
                ['__object__invoke_destructor', $context->context->functionType($voidTy, false, $objPtr)],
            ] as [$name, $ft]
        ) {
            self::ensureExternal($context, $name, $ft);
        }
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable $e) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (
            [
                'phpc_gc_register',
                'phpc_gc_unregister',
                'phpc_destruct_set_allow_delref',
                'phpc_destruct_delref_allowed',
                'phpc_destruct_try_invoke',
                'phpc_gc_run_shutdown_destructors',
                'phpc_gc_enable',
                'phpc_gc_disable',
                'phpc_gc_is_enabled',
                '__compiler_gc_collect_cycles',
            ] as $name
        ) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after GcCollectCyclesRuntime LLVM implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
