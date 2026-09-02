<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Mirror LLVM standalone GC registry globals into PHP statics before NativeScan (#36245).
 *
 * User-script standalone still registers via LLVM phpc_gc_register; collect uses PHP
 * NativeScanJitHelper. Sync at collect boundary avoids nested-JIT during object alloc.
 * php-src: Zend/zend_gc.c — gc_collect_cycles root buffer
 */
final class GcCollectCyclesRegistrySyncJitHelper
{
    public static function syncFromLlvmRegistry(): void
    {
        GcCollectCyclesRegistryJitHelper::resetForTest();
        $count = (int) phpc_gc_llvm_registry_count();
        for ($i = 0; $i < $count; ++$i) {
            $ptr = (int) phpc_gc_llvm_registry_object_at($i);
            if ($ptr <= 0) {
                continue;
            }
            $props = (int) phpc_gc_llvm_registry_prop_count_at($i);
            GcCollectCyclesRegistryJitHelper::appendObject($ptr, $props);
        }
    }
}
