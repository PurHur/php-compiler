<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Standalone AOT gc_collect_cycles stats + native registry scan (#18630).
 *
 * Avoids Superglobals/CycleCollector imports so nested JIT during standalone link stays lean.
 * php-src: Zend/zend_gc.c
 */
final class GcCollectCyclesStandaloneJitHelper
{
    private static int $runs = 0;

    private static int $totalCollected = 0;

    private static bool $running = false;

    private static bool $protected = false;

    public static function recordNativeCollect(int $nativeCollected): int
    {
        self::$running = true;
        self::$protected = true;
        ++self::$runs;
        self::$totalCollected += $nativeCollected;
        self::$running = false;
        self::$protected = false;

        return $nativeCollected;
    }

    public static function collectCyclesStandalone(): int
    {
        if (!GcToggleJitHelper::isEnabled()) {
            return 0;
        }

        return GcCollectCyclesNativeScanJitHelper::collect();
    }

    public static function runs(): int
    {
        return self::$runs;
    }

    public static function totalCollected(): int
    {
        return self::$totalCollected;
    }

    /** @return bool LLVM i1 ABI */
    public static function isRunning(): bool
    {
        return self::$running;
    }

    /** @return bool LLVM i1 ABI */
    public static function isProtected(): bool
    {
        return self::$protected;
    }
}
