<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\GcCollectCyclesRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Standalone AOT GC cycle-scan LLVM leaf (#17015, #15852, #19596).
 *
 * Moved out of lib/JIT/Builtin/ — {@see \PHPCompiler\JIT\Builtin\GcCollectCyclesRuntime}
 * remains the orchestrator. Embed JIT uses {@see GcCollectCyclesJitHelper::collectCyclesEmbed}.
 * php-src: Zend/zend_gc.c
 */
final class JitGcCollectCyclesStandaloneKernel
{
    private const TYPE_OBJECT = 5;

    private const TYPEINFO_TYPEMASK = 0xFFFFFFFC;

    private const TYPEINFO_TYPE_OBJECT = 8;

    public static function ensureCycleScanInternals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $voidTy = $context->getTypeFromString('void');
        $objPtr = $context->getTypeFromString('__object__*');
        $voidpp = $context->getTypeFromString('void**');

        $internals = [
            'phpc_gc_slot_read_object' => [$objPtr, false, [$voidpp]],
            'phpc_gc_visit_object' => [$voidTy, false, [$i32]],
            'phpc_gc_free_object' => [$voidTy, false, [$i8p]],
            'phpc_gc_clear_slots_pointing_to' => [$voidTy, false, [$i8p]],
        ];
        foreach ($internals as $name => [$ret, $vararg, $params]) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                $fn = $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, $vararg, ...$params)
                );
            }
            $context->registerFunction($name, $fn);
        }

        self::implementSlotReadObject($context);
        self::implementVisitObject($context);
        self::implementClearSlotsPointingTo($context);
        self::implementFreeObject($context);
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
        $obj = GcCollectCyclesRuntime::standaloneRegistryObjectPtr($context, $objIndex);
        $propCount = GcCollectCyclesRuntime::standaloneRegistryPropCount($context, $objIndex);
        $headerSize = GcCollectCyclesRuntime::standaloneObjectHeaderSizeConst($context);
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
        $markedPtr = GcCollectCyclesRuntime::standaloneArrayElemPtr($context, GcCollectCyclesRuntime::G_MARKED, $i8, $childExt);
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
        $count = GcCollectCyclesRuntime::standaloneRegistryCount($context);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $i, $count),
            $iBody,
            $done
        );

        $context->builder->positionAtEnd($iBody);
        $obj = GcCollectCyclesRuntime::standaloneRegistryObjectPtr($context, $i);
        $propCount = GcCollectCyclesRuntime::standaloneRegistryPropCount($context, $i);
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
        $headerSize = GcCollectCyclesRuntime::standaloneObjectHeaderSizeConst($context);
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

    public static function implementCollectCyclesImpl(Context $context): void
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
        $count = GcCollectCyclesRuntime::standaloneRegistryCount($context);
        $disabled = $context->builder->icmp(Builder::INT_EQ, $enabled, $i32->constInt(0, false));
        $empty = $context->builder->icmp(Builder::INT_SLE, $count, $i32->constInt(0, false));
        $skip = $context->builder->or($disabled, $empty);
        $context->builder->branchIf($skip, $early, $init);

        $context->builder->positionAtEnd($early);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($init);
        // Module-local memset(3) after LibcExtern always-on drop (#31863).
        LibcExtern::ensureMemsetDecl($context);
        $countExt = $context->builder->zext($count, $sizeT);
        $markedBase = GcCollectCyclesRuntime::standaloneGlobalPtr($context, GcCollectCyclesRuntime::G_MARKED, $i8);
        $inboundBase = GcCollectCyclesRuntime::standaloneGlobalPtr($context, GcCollectCyclesRuntime::G_INBOUND, $i32);
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
        $i = $context->builder->load($iSlot);
        $obj = GcCollectCyclesRuntime::standaloneRegistryObjectPtr($context, $i);
        $propCount = GcCollectCyclesRuntime::standaloneRegistryPropCount($context, $i);
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
        $headerSize = GcCollectCyclesRuntime::standaloneObjectHeaderSizeConst($context);
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
        $inPtr = GcCollectCyclesRuntime::standaloneArrayElemPtr($context, GcCollectCyclesRuntime::G_INBOUND, $i32, $childExt);
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
        $countNow = GcCollectCyclesRuntime::standaloneRegistryCount($context);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $ri, $countNow),
            $rootBody,
            $sweep
        );

        $context->builder->positionAtEnd($rootBody);
        $obj = GcCollectCyclesRuntime::standaloneRegistryObjectPtr($context, $ri);
        $riExt = $context->builder->zext($ri, $sizeT);
        $objTyped = $context->builder->pointerCast($obj, $objPtr);
        $refcount = GcCollectCyclesRuntime::standaloneLoadObjectRefcount($context, $objTyped);
        $inbound = $context->builder->load(GcCollectCyclesRuntime::standaloneArrayElemPtr($context, GcCollectCyclesRuntime::G_INBOUND, $i32, $riExt));
        $roots = $context->builder->sub($refcount, $inbound);
        $hasRoots = $context->builder->icmp(Builder::INT_SGT, $roots, $i32->constInt(0, false));
        $context->builder->branchIf($hasRoots, $rootMark, $rootNext);

        $context->builder->positionAtEnd($rootMark);
        $markedPtr = GcCollectCyclesRuntime::standaloneArrayElemPtr($context, GcCollectCyclesRuntime::G_MARKED, $i8, $riExt);
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
        $countSweep = GcCollectCyclesRuntime::standaloneRegistryCount($context);
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
        $marked = $context->builder->load(GcCollectCyclesRuntime::standaloneArrayElemPtr($context, GcCollectCyclesRuntime::G_MARKED, $i8, $siExt));
        $unmarked = $context->builder->icmp(Builder::INT_EQ, $marked, $i8->constInt(0, false));
        $context->builder->branchIf($unmarked, $freeBb, $sweepNext);

        $context->builder->positionAtEnd($freeBb);
        $obj = GcCollectCyclesRuntime::standaloneRegistryObjectPtr($context, $si);
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
}
