<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Embed JIT destructor try-invoke for compiled modules (#18660, php-in-PHP).
 *
 * Replaces inline LLVM phpc_destruct_try_invoke in {@see \PHPCompiler\JIT\Builtin\GcCollectCyclesRuntime}.
 * php-src: Zend/zend_objects_API.c — zend_call_known_instance_method destruct path
 */
final class GcDestructTryInvokeJitHelper
{
    /** Invoke user __destruct when object is constructed and not yet invoked. */
    public static function tryInvoke(int $objPtr): void
    {
        if ($objPtr <= 0) {
            return;
        }
        if (0 !== GcCollectCyclesRegistryJitHelper::destructAlreadyInvokedByObject($objPtr)) {
            return;
        }
        if (!phpc_object_is_constructed_native($objPtr)) {
            return;
        }
        GcCollectCyclesRegistryJitHelper::markDestructInvokedByObject($objPtr);
        phpc_object_invoke_destructor_native($objPtr);
    }
}
