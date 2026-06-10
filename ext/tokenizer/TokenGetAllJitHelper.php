<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tokenizer;

use PHPCompiler\VM\HashTable;

/**
 * Lowered into JIT/AOT modules that call token_get_all() at runtime (#3171).
 *
 * php-src: ext/tokenizer/tokenizer.c — PHP_FUNCTION(token_get_all)
 */
final class TokenGetAllJitHelper
{
    public static function tokenizeToHashTable(string $source, int $flags = 0): HashTable
    {
        return VmTokenizer::hostTokensToHashTable(VmTokenizer::tokenize($source, $flags));
    }
}
