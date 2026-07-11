<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * str_word_count() for compiled JIT/AOT modules (#14651, php-in-PHP).
 *
 * SSOT: {@see VmString::str_word_count()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_word_count)
 */
final class StrWordCountJitHelper
{
    public static function countArgv(string $string): int
    {
        return VmString::str_word_count($string, 0);
    }

    public static function wordsArgv(string $string, int $format, string $chars): HashTable
    {
        $result = VmString::str_word_count($string, $format, $chars);
        $ht = new HashTable();
        if (1 === $format) {
            foreach ($result as $word) {
                $value = new Variable();
                $value->string($word);
                $ht->append($value);
            }
        } else {
            foreach ($result as $pos => $word) {
                $value = new Variable();
                $value->string($word);
                $ht->addIndex((int) $pos, $value);
            }
        }

        return $ht;
    }
}
