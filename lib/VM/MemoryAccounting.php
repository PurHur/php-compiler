<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Zend emalloc-style byte accounting for memory_get_* (false $real_usage).
 *
 * php-src: Zend/zend_alloc.c — tracked separately from RSS ($real_usage=true).
 */
final class MemoryAccounting
{
    private static int $currentEmalloc = 0;

    private static int $peakEmalloc = 0;

    /** Last emalloc peak returned from memory_get_peak_usage(false) for baseline pairing (#5539). */
    private static int $lastPeakQueryEmalloc = 0;

    private static bool $hasPeakQueryEmalloc = false;

    /** Interpreter overhead between consecutive memory_get_* calls (enum/string temps). */
    public static function currentBytes(): int
    {
        return self::$currentEmalloc;
    }

    public static function peakBytes(): int
    {
        if (self::$currentEmalloc > self::$peakEmalloc) {
            self::$peakEmalloc = self::$currentEmalloc;
        }

        return self::$peakEmalloc;
    }

    public static function noteBytes(int $delta): void
    {
        if (0 === $delta) {
            return;
        }
        if (getenv('PHPC_DEBUG_EMALLOC') && $delta > 0 && $delta <= 16) {
            error_log('noteBytes +'.$delta.' cur='.(self::$currentEmalloc + $delta));
        }
        self::$currentEmalloc = max(0, self::$currentEmalloc + $delta);
        if (self::$currentEmalloc > self::$peakEmalloc) {
            self::$peakEmalloc = self::$currentEmalloc;
        }
    }

    public static function resetPeakToCurrent(): void
    {
        self::$peakEmalloc = self::$currentEmalloc;
        self::$hasPeakQueryEmalloc = false;
    }

    /** After memory_get_peak_usage() stores its return value, emalloc may grow; keep peak >= current. */
    public static function syncPeakFromCurrent(): void
    {
        if (self::$currentEmalloc > self::$peakEmalloc) {
            self::$peakEmalloc = self::$currentEmalloc;
        }
    }

    public static function markPeakQuery(int $peak): int
    {
        self::$lastPeakQueryEmalloc = $peak;
        self::$hasPeakQueryEmalloc = true;

        return $peak;
    }

    public static function usageAfterPeakQuery(): int
    {
        self::syncPeakFromCurrent();
        $current = self::currentBytes();
        if (self::$hasPeakQueryEmalloc && $current >= self::$lastPeakQueryEmalloc) {
            self::$hasPeakQueryEmalloc = false;

            return self::$lastPeakQueryEmalloc;
        }
        self::$hasPeakQueryEmalloc = false;

        return $current;
    }

    public static function estimateArrayBytesForTable(HashTable $ht): int
    {
        $bytes = $ht->getNumElements() * 96;
        foreach ($ht->iterateKeyed(true) as [, $value]) {
            $text = $value->resolveIndirect()->optionalScalarString();
            if (null !== $text) {
                $bytes += strlen($text);
            }
        }

        return $bytes;
    }

    public static function releaseVariable(Variable $var): void
    {
        $var->releaseTrackedMemory();
    }
}
