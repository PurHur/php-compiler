<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Embed JIT shutdown destructor walk for compiled modules (#15852 increment 2, php-in-PHP).
 *
 * Replaces LLVM phpc_gc_run_shutdown_destructors loop in {@see \PHPCompiler\JIT\Builtin\GcCollectCyclesRuntime}.
 * php-src: Zend/zend_objects_API.c — shutdown destructors / delayed destruction
 */
final class GcDestructShutdownJitHelper
{
    /** Invoke pending destructors then drain remaining registry objects (embed JIT only). */
    public static function runShutdownDestructors(): void
    {
        $count = GcCollectCyclesRegistryJitHelper::count();
        for ($i = $count - 1; $i >= 0; $i--) {
            if (!GcCollectCyclesRegistryJitHelper::isDestructInvoked($i)) {
                phpc_destruct_try_invoke_native(GcCollectCyclesRegistryJitHelper::objectPtr($i));
            }
        }

        GcDestructAllowDelrefJitHelper::setAllowDelref(true);

        while (($remaining = GcCollectCyclesRegistryJitHelper::count()) > 0) {
            phpc_object_release_storage_native(
                GcCollectCyclesRegistryJitHelper::objectPtr($remaining - 1)
            );
        }
    }
}
