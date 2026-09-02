<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * PHP-registry GC sweep free — destruct, clear inbound slots, unregister, mm_free (#36245).
 *
 * Replaces phpc_gc_native_free_object → LLVM phpc_gc_free_object for embed + user-script AOT
 * so sweep does not walk empty LLVM phpc_gc_objects[] when the registry lives in PHP statics.
 * php-src: Zend/zend_gc.c — gc_remove_from_buffer / free_object
 */
final class GcCollectCyclesNativeFreeJitHelper
{
    public static function freeRegistryObject(int $objPtr): void
    {
        if ($objPtr <= 0) {
            return;
        }
        phpc_destruct_try_invoke_native($objPtr);
        phpc_gc_notify_object_freed_native($objPtr);
        self::clearSlotsPointingTo($objPtr);
        GcCollectCyclesRegistryJitHelper::removeObject($objPtr);
        phpc_mm_free_native($objPtr);
    }

    private static function clearSlotsPointingTo(int $targetPtr): void
    {
        $i = 0;
        while ($i < GcCollectCyclesRegistryJitHelper::count()) {
            $objPtr = GcCollectCyclesRegistryJitHelper::objectPtr($i);
            if ($objPtr <= 0) {
                ++$i;
                continue;
            }
            $propCount = GcCollectCyclesRegistryJitHelper::propCount($i);
            for ($s = 0; $s < $propCount; ++$s) {
                $childPtr = (int) phpc_gc_native_child_at($objPtr, $s);
                if ($childPtr === $targetPtr) {
                    phpc_gc_native_clear_slot_at($objPtr, $s);
                }
            }
            ++$i;
        }
    }
}
