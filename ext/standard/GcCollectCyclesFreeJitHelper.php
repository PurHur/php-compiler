<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Standalone user-script AOT: free cyclic registry objects via PHP registry (#36245).
 *
 * LLVM phpc_gc_free_object used the standalone global registry while phpc_gc_register
 * wrote the PHP registry — unregister missed, stale pointers segfaulted on the next scan.
 * php-src: Zend/zend_gc.c — gc_remove_from_buffer / free_object
 */
final class GcCollectCyclesFreeJitHelper
{
    public static function freeCyclicRegistryObject(int $objPtr): void
    {
        if ($objPtr <= 0) {
            return;
        }
        GcDestructTryInvokeJitHelper::tryInvoke($objPtr);
        phpc_gc_notify_object_freed_native($objPtr);
        self::clearOwnSlots($objPtr);
        self::clearSlotsPointingTo($objPtr);
        GcCollectCyclesRegistryJitHelper::removeObject($objPtr);
        phpc_mm_free_native($objPtr);
    }

    private static function clearOwnSlots(int $objPtr): void
    {
        $idx = GcCollectCyclesRegistryJitHelper::indexOf($objPtr);
        if ($idx < 0) {
            return;
        }
        $propCount = GcCollectCyclesRegistryJitHelper::propCount($idx);
        for ($s = 0; $s < $propCount; ++$s) {
            phpc_gc_native_clear_slot_at($objPtr, $s);
        }
    }

    public static function clearSlotsPointingTo(int $targetPtr): void
    {
        if ($targetPtr <= 0) {
            return;
        }
        $count = GcCollectCyclesRegistryJitHelper::count();
        for ($i = 0; $i < $count; ++$i) {
            $objPtr = GcCollectCyclesRegistryJitHelper::objectPtr($i);
            if ($objPtr <= 0) {
                continue;
            }
            $propCount = GcCollectCyclesRegistryJitHelper::propCount($i);
            for ($s = 0; $s < $propCount; ++$s) {
                $childPtr = (int) phpc_gc_native_child_at($objPtr, $s);
                if ($childPtr === $targetPtr) {
                    phpc_gc_native_clear_slot_at($objPtr, $s);
                }
            }
        }
    }
}
