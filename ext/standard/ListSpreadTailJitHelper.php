<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * List destructuring spread tail for keyed destructuring (#4889, #4979, php-in-PHP).
 *
 * SSOT: {@see HashTable::copyListSpreadTail()}
 */
final class ListSpreadTailJitHelper
{
    public static function copyTail(
        HashTable $ht,
        int $offset,
        HashTable $excludedKeys
    ): HashTable {
        $excluded = [];
        foreach ($excludedKeys->iterateKeyed(true) as [$keyVar, $_valueVar]) {
            $excluded[] = $keyVar->resolveIndirect()->toString();
        }

        return $ht->copyListSpreadTail($offset, $excluded);
    }

    /** Build a string-key set hashtable for {@see copyTail()} at JIT compile time. */
    public static function excludedKeysTable(array $excludedStringKeys): HashTable
    {
        $out = new HashTable();
        foreach ($excludedStringKeys as $key) {
            $marker = new Variable();
            $marker->int(1);
            $out->add((string) $key, $marker);
        }

        return $out;
    }
}
