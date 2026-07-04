<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
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






    private const G_RUNNING = 'phpc_gc_running';

    private const G_PROTECTED = 'phpc_gc_protected';

    private const G_FULL = 'phpc_gc_full';

    private const G_BUFFER_SIZE = 'phpc_gc_buffer_size';

    private const REGISTRY_HELPER_PATH = '/ext/standard/GcCollectCyclesRegistryJitHelper.php';

    private const DESTRUCT_HELPER_PATH = '/ext/standard/GcDestructAllowDelrefJitHelper.php';

    private const SET_ALLOW_DELREF = 'PHPCompiler\\ext\\standard\\GcDestructAllowDelrefJitHelper::setAllowDelref';

    private const DELREF_ALLOWED = 'PHPCompiler\\ext\\standard\\GcDestructAllowDelrefJitHelper::delrefAllowed';

    /** @var list<string> */
    private const DESTRUCT_COMPILED_HELPERS = [
        self::SET_ALLOW_DELREF,
        self::DELREF_ALLOWED,
    ];

    private const REG_APPEND = 'PHPCompiler\\ext\\standard\\GcCollectCyclesRegistryJitHelper::appendObject';

    private const REG_REMOVE = 'PHPCompiler\\ext\\standard\\GcCollectCyclesRegistryJitHelper::removeObject';

    private const REG_INDEX_OF = 'PHPCompiler\\ext\\standard\\GcCollectCyclesRegistryJitHelper::indexOf';

    private const REG_COUNT = 'PHPCompiler\\ext\\standard\\GcCollectCyclesRegistryJitHelper::count';

    private const REG_OBJECT_PTR = 'PHPCompiler\\ext\\standard\\GcCollectCyclesRegistryJitHelper::objectPtr';

    private const REG_PROP_COUNT = 'PHPCompiler\\ext\\standard\\GcCollectCyclesRegistryJitHelper::propCount';

    private const REG_DESTRUCT_INVOKED = 'PHPCompiler\\ext\\standard\\GcCollectCyclesRegistryJitHelper::isDestructInvoked';

    private const REG_MARK_DESTRUCT = 'PHPCompiler\\ext\\standard\\GcCollectCyclesRegistryJitHelper::markDestructInvoked';

    /** @var list<string> */
    private const REGISTRY_COMPILED_HELPERS = [
        self::REG_APPEND,
        self::REG_REMOVE,
        self::REG_INDEX_OF,
        self::REG_COUNT,
        self::REG_OBJECT_PTR,
        self::REG_PROP_COUNT,
        self::REG_DESTRUCT_INVOKED,
        self::REG_MARK_DESTRUCT,
    ];

    private static int $blockSuffix = 0;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    /** Declare GC ABI symbols for early JIT link (Refcount delref) without full implement (#10087). */
    public static function ensureDeclarations(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $void = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');

        self::declareFunction($context, 'phpc_gc_register', $void, [$i8p, $i32]);
        self::declareFunction($context, 'phpc_gc_unregister', $void, [$i8p]);
        self::declareFunction($context, 'phpc_destruct_set_allow_delref', $void, [$i32]);
        self::declareFunction($context, 'phpc_destruct_delref_allowed', $i32, []);
        self::declareFunction($context, 'phpc_destruct_try_invoke', $void, [$i8p]);
        self::declareFunction($context, 'phpc_gc_notify_object_freed', $void, [$i8p]);
        self::declareFunction($context, 'phpc_gc_run_shutdown_destructors', $void, []);
        self::declareFunction($context, 'phpc_gc_enable', $void, []);
        self::declareFunction($context, 'phpc_gc_disable', $void, []);
        self::declareFunction($context, 'phpc_gc_is_enabled', $i32, []);
        self::declareFunction($context, '__compiler_gc_collect_cycles', $i64, []);
    }

    /**
     * @param list<\PHPLLVM\Type> $paramTypes
     */
    private static function declareFunction(
        Context $context,
        string $name,
        \PHPLLVM\Type $returnType,
        array $paramTypes
    ): void {
        if (null !== $context->module->getNamedFunction($name)) {
            return;
        }
        $fnType = $context->context->functionType($returnType, false, ...$paramTypes);
        $fn = $context->module->addFunction($name, $fnType);
        $context->registerFunction($name, $fn);
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
        self::ensureRegistryJitHelperCompiled($context);
        self::ensureDestructAllowDelrefJitHelperCompiled($context);
        self::ensureGlobals($context);
        self::ensureExternals($context);
        self::ensureInternalDeclarations($context);

        self::implementAllowDelrefBridge($context);
        self::implementDestructDelrefAllowedBridge($context);
        self::implementGcRegisterPhpBridge($context);
        self::implementGcUnregisterPhpBridge($context);
        self::implementDestructTryInvoke($context);
        self::implementRunShutdownDestructors($context);
        GcCollectCyclesCollectRuntime::implementCollectBridge($context);

        self::registerLinkedRuntime($context);
    }

    private static function implementAllowDelrefBridge(Context $context): void
    {
        $name = 'phpc_destruct_set_allow_delref';
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($voidTy, false, $i32);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($name, $ft);

        $entry = $fn->appendBasicBlock('destruct_set_allow_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $allow = $fn->getParam(0);
        $isNonZero = $context->builder->icmp(Builder::INT_NE, $allow, $i32->constInt(0, false));
        $context->builder->call(self::destructHelperFunction($context, self::SET_ALLOW_DELREF), $isNonZero);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
        $context->registerFunction($name, $fn);
    }

    private static function implementDestructDelrefAllowedBridge(Context $context): void
    {
        $name = 'phpc_destruct_delref_allowed';
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($name, $ft);

        $entry = $fn->appendBasicBlock('destruct_delref_allowed_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $allowed = $context->builder->call(self::destructHelperFunction($context, self::DELREF_ALLOWED));
        $context->builder->returnValue($context->builder->zext($allowed, $i32));
        $context->builder->clearInsertionPosition();
        $context->registerFunction($name, $fn);
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

        $i8 = $context->getTypeFromString('int8');
        $iSlot = $context->builder->alloca($i32, 1, 'shutdown_i');
        $count = self::llvmRegistryCount($context);
        $context->builder->store($context->builder->sub($count, $i32->constInt(1, false)), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $continueLoop = $context->builder->icmp(Builder::INT_SGE, $i, $i32->constInt(0, false));
        $context->builder->branchIf($continueLoop, $loopBody, $drainHead);

        $context->builder->positionAtEnd($loopBody);
        $i = $context->builder->load($iSlot);
        $invoked = self::llvmRegistryDestructInvoked($context, $i);
        $needsInvoke = $context->builder->icmp(Builder::INT_EQ, $invoked, $i8->constInt(0, false));
        $invokeBb = $fn->appendBasicBlock('shutdown_invoke');
        $context->builder->branchIf($needsInvoke, $invokeBb, $loopNext);

        $context->builder->positionAtEnd($invokeBb);
        $obj = self::llvmRegistryObjectPtr($context, $i);
        $context->builder->call($context->lookupFunction('phpc_destruct_try_invoke'), $obj);
        $context->builder->branch($loopNext);

        $context->builder->positionAtEnd($loopNext);
        $context->builder->store($context->builder->sub($i, $i32->constInt(1, false)), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($drainHead);
        $context->builder->call(
            $context->lookupFunction('phpc_destruct_set_allow_delref'),
            $i32->constInt(1, false)
        );
        $countNow = self::llvmRegistryCount($context);
        $hasMore = $context->builder->icmp(Builder::INT_SGT, $countNow, $i32->constInt(0, false));
        $context->builder->branchIf($hasMore, $drainBody, $done);

        $context->builder->positionAtEnd($drainBody);
        $lastIdx = $context->builder->sub($countNow, $i32->constInt(1, false));
        $obj = self::llvmRegistryObjectPtr($context, $lastIdx);
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

        self::implementIndexOfPhpBridge($context);
        self::implementDestructAlreadyInvoked($context);
        self::implementDestructMarkInvoked($context);
        self::implementSlotReadObject($context);
        self::implementClearSlotsPointingTo($context);
        self::implementFreeObject($context);
        self::implementCollectCyclesPhpBridge($context);
        self::implementObjectReleaseStorage($context);
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
        $inv = self::llvmRegistryDestructInvoked($context, $idx);
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
        self::llvmRegistryMarkDestructInvoked($context, $idx);
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
        $count = self::llvmRegistryCount($context);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $i, $count),
            $iBody,
            $done
        );

        $context->builder->positionAtEnd($iBody);
        $obj = self::llvmRegistryObjectPtr($context, $i);
        $propCount = self::llvmRegistryPropCount($context, $i);
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

    private static function ensureDestructAllowDelrefJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::DESTRUCT_COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::DESTRUCT_HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'GcDestructAllowDelrefJitHelper.php');
            if (null === $block) {
                throw new \LogicException('GcDestructAllowDelrefJitHelper.php parseAndCompile failed (#15852)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::DESTRUCT_COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT GC destruct gate (#15852)');
            }
        }
    }

    private static function destructHelperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureDestructAllowDelrefJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after GcDestructAllowDelrefJitHelper compile (#15852)');
        }

        return $fn;
    }

    private static function ensureRegistryJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::REGISTRY_COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::REGISTRY_HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'GcCollectCyclesRegistryJitHelper.php');
            if (null === $block) {
                throw new \LogicException('GcCollectCyclesRegistryJitHelper.php parseAndCompile failed (#9541)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::REGISTRY_COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT GC registry (#9541)');
            }
        }
    }

    private static function registryHelperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureRegistryJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after GcCollectCyclesRegistryJitHelper compile (#9541)');
        }

        return $fn;
    }

    private static function syncRegistryCountGlobal(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $count = $context->builder->trunc(
            $context->builder->call(self::registryHelperFunction($context, self::REG_COUNT)),
            $i32
        );
        $context->builder->store($count, self::globalPtr($context, self::G_COUNT, $i32));
    }

    private static function llvmRegistryCount(Context $context): Value
    {
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->trunc(
            $context->builder->call(self::registryHelperFunction($context, self::REG_COUNT)),
            $i32
        );
    }

    private static function llvmRegistryObjectPtr(Context $context, Value $index): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $ptrI64 = $context->builder->call(
            self::registryHelperFunction($context, self::REG_OBJECT_PTR),
            $context->builder->sext($index, $i64)
        );

        return $context->builder->intToPtr($ptrI64, $i8p);
    }

    private static function llvmRegistryPropCount(Context $context, Value $index): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->trunc(
            $context->builder->call(
                self::registryHelperFunction($context, self::REG_PROP_COUNT),
                $context->builder->sext($index, $i64)
            ),
            $i32
        );
    }

    private static function llvmRegistryDestructInvoked(Context $context, Value $index): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $flag = $context->builder->call(
            self::registryHelperFunction($context, self::REG_DESTRUCT_INVOKED),
            $context->builder->sext($index, $i64)
        );

        return $context->builder->zext($flag, $i8);
    }

    private static function llvmRegistryMarkDestructInvoked(Context $context, Value $index): void
    {
        $i64 = $context->getTypeFromString('int64');
        $context->builder->call(
            self::registryHelperFunction($context, self::REG_MARK_DESTRUCT),
            $context->builder->sext($index, $i64)
        );
    }

    private static function implementGcRegisterPhpBridge(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $ft = $context->context->functionType($voidTy, false, $i8p, $i32);
        $fn = self::functionOrCreate($context, 'phpc_gc_register', $ft);
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $entry = $fn->appendBasicBlock('gc_register_php_entry');
        $context->builder->positionAtEnd($entry);
        $objI64 = $context->builder->ptrToInt($fn->getParam(0), $i64);
        $propI64 = $context->builder->sext($fn->getParam(1), $i64);
        $context->builder->call(self::registryHelperFunction($context, self::REG_APPEND), $objI64, $propI64);
        self::syncRegistryCountGlobal($context);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
        $context->registerFunction('phpc_gc_register', $fn);
    }

    private static function implementGcUnregisterPhpBridge(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $ft = $context->context->functionType($voidTy, false, $i8p);
        $fn = self::functionOrCreate($context, 'phpc_gc_unregister', $ft);
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $entry = $fn->appendBasicBlock('gc_unregister_php_entry');
        $context->builder->positionAtEnd($entry);
        $objI64 = $context->builder->ptrToInt($fn->getParam(0), $i64);
        $context->builder->call(self::registryHelperFunction($context, self::REG_REMOVE), $objI64);
        self::syncRegistryCountGlobal($context);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
        $context->registerFunction('phpc_gc_unregister', $fn);
    }

    private static function implementIndexOfPhpBridge(Context $context): void
    {
        $fn = $context->lookupFunction('phpc_gc_index_of');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $entry = $fn->appendBasicBlock('gc_index_php_entry');
        $context->builder->positionAtEnd($entry);
        $objI64 = $context->builder->ptrToInt($fn->getParam(0), $i64);
        $idx = $context->builder->call(self::registryHelperFunction($context, self::REG_INDEX_OF), $objI64);
        $context->builder->returnValue($context->builder->trunc($idx, $i32));
        $context->builder->clearInsertionPosition();
    }

    private static function implementCollectCyclesPhpBridge(Context $context): void
    {
        $fn = $context->lookupFunction('phpc_gc_collect_cycles_impl');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        GcCollectCyclesCollectRuntime::ensureCollectHelperCompiled($context);
        $logical = 'PHPCompiler\\ext\\standard\\GcCollectCyclesJitHelper::collectCyclesEmbed';
        $lc = \strtolower($logical);
        $collectEmbed = $context->functions[$lc] ?? null;
        if (null === $collectEmbed) {
            throw new \LogicException($logical.' missing after GcCollectCyclesJitHelper compile (#13882)');
        }
        $i32 = $context->getTypeFromString('int32');
        $entry = $fn->appendBasicBlock('collect_impl_php_entry');
        $context->builder->positionAtEnd($entry);
        $collected = $context->builder->call($collectEmbed);
        $context->builder->returnValue($collected);
        $context->builder->clearInsertionPosition();
    }
}
