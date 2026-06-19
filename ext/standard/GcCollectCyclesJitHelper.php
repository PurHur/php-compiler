<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * gc_collect_cycles() bookkeeping for compiled JIT/AOT modules (#9183, php-in-PHP).
 *
 * VM SSOT delegates via {@see \PHPCompiler\VM\CycleCollector}; native cycle scan stays
 * in {@see \PHPCompiler\JIT\Builtin\GcCollectCyclesRuntime} until full PHP graph walk.
 * php-src: Zend/zend_gc.c — gc_collect_cycles stats / enable gate
 */
final class GcCollectCyclesJitHelper
{
    private static int $runs = 0;

    private static int $totalCollected = 0;

    private static bool $running = false;

    private static bool $protected = false;

    /** Record stats after phpc_gc_collect_cycles_impl returns (#9183). */
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

    /** @internal test reset */
    public static function resetForTest(): void
    {
        self::$runs = 0;
        self::$totalCollected = 0;
        self::$running = false;
        self::$protected = false;
    }
}
