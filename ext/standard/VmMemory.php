<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\MemoryAccounting;

/**
 * VM memory introspection without host Zend memory_get_* (issue #4862, #3134).
 *
 * php-src: ext/standard/basic_functions.c, Zend/zend_alloc.c (emalloc subset).
 * JIT/AOT: ext/standard/JitMemory.php + lib/JIT/Builtin/MemoryRuntime.php.
 */
final class VmMemory
{
    private static int $peakReal = 0;

    public static function getUsage(bool $realUsage = false): int
    {
        if ($realUsage) {
            $usage = self::readRssBytes();
            if ($usage > self::$peakReal) {
                self::$peakReal = $usage;
            }

            return $usage;
        }

        return MemoryAccounting::currentBytes();
    }

    public static function getPeakUsage(bool $realUsage = false): int
    {
        if ($realUsage) {
            self::getUsage(true);

            return self::$peakReal;
        }

        return MemoryAccounting::peakBytes();
    }

    /** php-src: zend_reset_peak_memory_usage — baseline peak at current usage. */
    public static function resetPeakUsage(bool $realUsage = false): void
    {
        if ($realUsage) {
            self::$peakReal = self::readRssBytes();

            return;
        }
        MemoryAccounting::resetPeakToCurrent();
    }

    /**
     * RSS via /proc/self/statm only (issue #7287, #4862).
     *
     * JIT/AOT use the same source in MemoryRuntime::__phpc_memory_read_rss_bytes.
     */
    private static function readRssBytes(): int
    {
        if ('Linux' !== \PHP_OS_FAMILY || !is_readable('/proc/self/statm')) {
            return 0;
        }
        $statm = @file_get_contents('/proc/self/statm');
        if (false === $statm || '' === $statm) {
            return 0;
        }
        $parts = preg_split('/\s+/', trim($statm));
        $rssPages = (int) ($parts[1] ?? 0);

        return $rssPages * self::pageSize();
    }

    private static function pageSize(): int
    {
        static $size = null;
        if (null !== $size) {
            return $size;
        }
        $ps = (int) @ini_get('memory.page_size');
        $size = $ps > 0 ? $ps : 4096;

        return $size;
    }
}
