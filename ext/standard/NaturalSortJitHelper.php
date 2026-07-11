<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * natsort()/natcasesort() for compiled JIT/AOT modules (#12753, php-in-PHP).
 *
 * SSOT shared with {@see natsort_} / {@see natcasesort_} VM execute()
 * php-src: ext/standard/array.c — php_natsort / php_natcasesort
 */
final class NaturalSortJitHelper
{
    public static function natsortByValue(HashTable $ht): void
    {
        if ($ht->getNumElements() < 2) {
            return;
        }
        self::applySortedCopy($ht, VmArray::natsortCopy($ht));
    }

    public static function natcasesortByValue(HashTable $ht): void
    {
        if ($ht->getNumElements() < 2) {
            return;
        }
        self::applySortedCopy($ht, VmArray::natcasesortCopy($ht));
    }

    private static function applySortedCopy(HashTable $ht, HashTable $sorted): void
    {
        $pairs = [];
        foreach ($sorted->iterateKeyed(true) as [$key, $value]) {
            $keyCopy = new Variable();
            $keyCopy->copyFrom($key);
            $valCopy = new Variable();
            $valCopy->copyFrom($value);
            $pairs[] = [$keyCopy, $valCopy];
        }
        $ht->reorderKeyedPairs($pairs);
    }
}
