<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Destructor delref gate for compiled JIT/AOT modules (#15852, php-in-PHP).
 *
 * Replaces LLVM phpc_destruct_allow_delref global in {@see \PHPCompiler\JIT\Builtin\GcCollectCyclesRuntime}.
 * php-src: Zend/zend_objects_API.c — GC/protected destructor refcount semantics
 */
final class GcDestructAllowDelrefJitHelper
{
    private static bool $allow = true;

    public static function setAllowDelref(bool $allow): void
    {
        self::$allow = $allow;
    }

    /** @return bool LLVM i1 ABI; bridge zext to i32 for phpc_destruct_delref_allowed */
    public static function delrefAllowed(): bool
    {
        return self::$allow;
    }

    /** @internal test reset */
    public static function resetForTest(): void
    {
        self::$allow = true;
    }
}
