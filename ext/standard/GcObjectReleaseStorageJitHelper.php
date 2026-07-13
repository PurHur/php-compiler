<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Embed JIT object storage release for compiled modules (#18660, php-in-PHP).
 *
 * Replaces inline LLVM phpc_object_release_storage in {@see \PHPCompiler\JIT\Builtin\GcCollectCyclesRuntime}.
 * php-src: Zend/zend_objects_API.c — object dtor / free_object
 */
final class GcObjectReleaseStorageJitHelper
{
    /** Notify weak refs, unregister from GC table, and free object storage. */
    public static function release(int $objPtr): void
    {
        if ($objPtr <= 0) {
            return;
        }
        phpc_gc_notify_object_freed_native($objPtr);
        GcCollectCyclesRegistryJitHelper::removeObject($objPtr);
        phpc_mm_free_native($objPtr);
    }
}
