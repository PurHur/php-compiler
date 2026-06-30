<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\CycleCollector;
use PHPCompiler\Web\Superglobals;

/**
 * gc_collect_cycles() bookkeeping + embed cycle scan for compiled JIT/AOT (#9183, #13882).
 *
 * VM SSOT: {@see CycleCollector}; embed JIT routes native registry scan through PHP here.
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
        $collected += self::collectNativeRegistry();

        return $collected;
    }

    /**
     * Tarjan-style scan over {@see GcCollectCyclesRegistryJitHelper} (Zend gc_collect_cycles subset).
     */
    private static function collectNativeRegistry(): int
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
        $i = 0;
        while ($i < GcCollectCyclesRegistryJitHelper::count()) {
            if (!$marked[$i]) {
                $objPtr = GcCollectCyclesRegistryJitHelper::objectPtr($i);
                if ($objPtr > 0) {
                    phpc_gc_native_free_object($objPtr);
                    ++$collected;
                }
            } else {
                ++$i;
            }
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
