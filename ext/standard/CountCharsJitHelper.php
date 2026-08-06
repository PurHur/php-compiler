<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * count_chars() for compiled JIT/AOT modules (#14692, php-in-PHP).
 *
 * SSOT: {@see VmString::count_chars()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(count_chars)
 */
final class CountCharsJitHelper
{
    public static function arrayArgv(string $string, int $mode): HashTable
    {
        $result = VmString::count_chars($string, $mode);
        if (!\is_array($result)) {
            throw new \LogicException('count_chars arrayArgv expected array result');
        }

        return self::histogramToHashTable($result);
    }

    public static function stringArgv(string $string, int $mode): string
    {
        $result = VmString::count_chars($string, $mode);
        if (!\is_string($result)) {
            throw new \LogicException('count_chars stringArgv expected string result');
        }

        return $result;
    }

    /**
     * @param array<int, int> $histogram
     */
    private static function histogramToHashTable(array $histogram): HashTable
    {
        $ht = new HashTable();
        // Sparse int keys (mode 1/2): NestedJIT lowers addIndex → packed setAtIndex,
        // which grows capacity itself. ensureHashSlotCapacity is VM-hash-index only and
        // is not NestedJIT-safe (#27536).
        foreach ($histogram as $byte => $count) {
            $slot = new Variable();
            $slot->int((int) $count);
            $ht->addIndex((int) $byte, $slot);
        }

        return $ht;
    }
}
