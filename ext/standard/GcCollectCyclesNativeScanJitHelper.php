<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Tarjan-style native registry cycle scan shared by embed + standalone GC JIT (#18630).
 *
 * php-src: Zend/zend_gc.c — gc_collect_cycles subset over root buffer
 */
final class GcCollectCyclesNativeScanJitHelper
{
    public static function collect(): int
    {
        $count = GcCollectCyclesRegistryJitHelper::count();
        if ($count <= 0) {
            return 0;
        }

        /** @var array<int, bool> $marked */
        $marked = [];
        /** @var array<int, int> $inbound */
        $inbound = [];
        for ($i = 0; $i < $count; ++$i) {
            $marked[$i] = false;
            $inbound[$i] = 0;
        }

        for ($i = 0; $i < $count; ++$i) {
            $objPtr = GcCollectCyclesRegistryJitHelper::objectPtr($i);
            if ($objPtr <= 0) {
                continue;
            }
            $propCount = GcCollectCyclesRegistryJitHelper::propCount($i);
            for ($s = 0; $s < $propCount; ++$s) {
                $childPtr = (int) phpc_gc_native_child_at($objPtr, $s);
                if ($childPtr <= 0) {
                    continue;
                }
                $childIdx = GcCollectCyclesRegistryJitHelper::indexOf($childPtr);
                if ($childIdx >= 0) {
                    $inbound[$childIdx] = $inbound[$childIdx] + 1;
                }
            }
        }

        for ($i = 0; $i < $count; ++$i) {
            $objPtr = GcCollectCyclesRegistryJitHelper::objectPtr($i);
            if ($objPtr <= 0) {
                continue;
            }
            $refcount = (int) phpc_gc_native_object_refcount($objPtr);
            $roots = $refcount - $inbound[$i];
            if ($roots > 0 && !$marked[$i]) {
                $marked[$i] = true;
                self::visitNativeRegistryObject($i, $marked);
            }
        }

        $collected = 0;
        /** @var list<int> $toFree */
        $toFree = [];
        $count = GcCollectCyclesRegistryJitHelper::count();
        for ($i = 0; $i < $count; ++$i) {
            if (!($marked[$i] ?? false)) {
                $objPtr = GcCollectCyclesRegistryJitHelper::objectPtr($i);
                if ($objPtr > 0) {
                    $toFree[] = $objPtr;
                }
            }
        }
        foreach ($toFree as $objPtr) {
            GcCollectCyclesNativeFreeJitHelper::freeRegistryObject($objPtr);
            ++$collected;
        }

        return $collected;
    }

    /**
     * @param array<int, bool> $marked
     */
    private static function visitNativeRegistryObject(int $index, array &$marked): void
    {
        $propCount = GcCollectCyclesRegistryJitHelper::propCount($index);
        $objPtr = GcCollectCyclesRegistryJitHelper::objectPtr($index);
        if ($objPtr <= 0) {
            return;
        }
        for ($s = 0; $s < $propCount; ++$s) {
            $childPtr = (int) phpc_gc_native_child_at($objPtr, $s);
            if ($childPtr <= 0) {
                continue;
            }
            $childIdx = GcCollectCyclesRegistryJitHelper::indexOf($childPtr);
            if ($childIdx < 0 || $marked[$childIdx]) {
                continue;
            }
            $marked[$childIdx] = true;
            self::visitNativeRegistryObject($childIdx, $marked);
        }
    }
}
