<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * explode() for compiled JIT/AOT modules (#14750, php-in-PHP).
 *
 * SSOT: {@see VmString::explode()}
 * php-src: ext/standard/string.c — php_explode(), php_explode_negative_limit()
 */
final class ExplodeJitHelper
{
    public static function explodeArgv(string $delimiter, string $haystack, int $limit): HashTable
    {
        return self::partsToHashTable(VmString::explode($delimiter, $haystack, $limit));
    }

    /**
     * @param list<string> $parts
     */
    private static function partsToHashTable(array $parts): HashTable
    {
        $ht = new HashTable();
        foreach ($parts as $part) {
            $value = new Variable();
            $value->string($part);
            $ht->append($value);
        }

        return $ht;
    }
}
