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
        self::$currentEmalloc = max(0, self::$currentEmalloc + $delta);
        if (self::$currentEmalloc > self::$peakEmalloc) {
            self::$peakEmalloc = self::$currentEmalloc;
        }
    }

    public static function resetPeakToCurrent(): void
    {
        self::$peakEmalloc = self::$currentEmalloc;
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
