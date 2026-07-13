<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\CycleCollector;
use PHPCompiler\Web\Superglobals;

/**
 * gc_collect_cycles() bookkeeping + embed cycle scan for compiled JIT/AOT (#9183, #13882).
 *
 * VM SSOT: {@see CycleCollector}; embed JIT routes native registry scan through PHP here.
 * Standalone AOT uses {@see collectCyclesStandalone} (registry scan only, no Superglobals).
 * php-src: Zend/zend_gc.c — gc_collect_cycles
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

    /** Embed JIT collect bridge — VM graph + native registry (#13882). */
    public static function collectCyclesEmbed(): int
    {
        if (!GcToggleJitHelper::isEnabled()) {
            return 0;
        }

        $collected = 0;
        $ctx = Superglobals::getActiveContext();
        if (null !== $ctx) {
            $collected += CycleCollector::collect($ctx);
        }
        $collected += GcCollectCyclesNativeScanJitHelper::collect();

        return $collected;
    }

    /** Standalone AOT collect bridge — native registry scan only (#18630). */
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

    /** @internal test reset */
    public static function resetForTest(): void
    {
        self::$runs = 0;
        self::$totalCollected = 0;
        self::$running = false;
        self::$protected = false;
    }
}
