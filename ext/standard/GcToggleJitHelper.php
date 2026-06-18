<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * GC enable/disable static storage for compiled JIT/AOT modules (#9577, php-in-PHP).
 *
 * VM SSOT delegates here via {@see \PHPCompiler\VM\CycleCollector}.
 * php-src: ext/standard/php_gc.c — gc_enable, gc_disable, gc_enabled
 */
final class GcToggleJitHelper
{
    private static bool $enabled = true;

    public static function enable(): void
    {
        self::$enabled = true;
    }

    public static function disable(): void
    {
        self::$enabled = false;
    }

    /** @return bool LLVM i1 ABI; bridge zext to i32 for phpc_gc_is_enabled */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }
}
