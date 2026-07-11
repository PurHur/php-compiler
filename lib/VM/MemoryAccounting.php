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
    /** Zend MM page size (zend_alloc.c ZEND_MM_PAGE_SIZE). */
    private const ZEND_MM_PAGE_SIZE = 4096;

    /** Fallback when host Zend probe unavailable (zend_alloc.c 15-page bucket). */
    private const FALLBACK_MM_CACHE = 15 * self::ZEND_MM_PAGE_SIZE;

    private static ?int $initialMmCacheResolved = null;

    private static int $mmCacheRemaining = 0;

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

    /** Seed Zend MM cache bucket at request start (php_gc.c gc_mem_caches parity, #9160, #12921). */
    public static function beginRequest(): void
    {
        self::$mmCacheRemaining = self::initialMmCache();
        self::resetPeakToCurrent();
        self::$hasPeakQueryEmalloc = false;
    }

    /** Host-aligned first-call bucket (php_gc.c gc_mem_caches / zend_mm_gc, #12921). */
    public static function initialMmCache(): int
    {
        if (null === self::$initialMmCacheResolved) {
            self::$initialMmCacheResolved = self::resolveInitialMmCache();
        }

        return self::$initialMmCacheResolved;
    }

    private static function resolveInitialMmCache(): int
    {
        $override = getenv('PHP_COMPILER_MM_CACHE_INITIAL');
        if (false !== $override && '' !== $override) {
            return (int) $override;
        }
        $probed = self::probeHostZendMmCache();

        return null !== $probed ? $probed : self::FALLBACK_MM_CACHE;
    }

    /** Probe fresh host Zend gc_mem_caches() (ext/standard/php_gc.c). */
    private static function probeHostZendMmCache(): ?int
    {
        $binary = \defined('PHP_BINARY') ? PHP_BINARY : 'php';
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open(
            [$binary, '-n', '-r', 'echo gc_mem_caches();'],
            $descriptorSpec,
            $pipes
        );
        if (!\is_resource($proc)) {
            return null;
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $stdout = trim($stdout);
        if ('' === $stdout || !ctype_digit($stdout)) {
            return null;
        }

        return (int) $stdout;
    }

    /** Release VM allocator caches (php_gc.c gc_mem_caches / zend_mm_gc parity, #9160). */
    public static function releaseMmCaches(): int
    {
        $fromMmCache = self::$mmCacheRemaining;
        self::$mmCacheRemaining = 0;
        $peak = self::peakBytes();
        $current = self::currentBytes();
        self::resetPeakToCurrent();
        $fromPeak = max(0, $peak - $current);

        return $fromMmCache + $fromPeak;
    }
}
