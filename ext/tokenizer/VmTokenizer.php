<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tokenizer;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** Native + host tokenizer bridge for VM builtins (php-src ext/tokenizer/tokenizer.c; #6940, #4561). */
final class VmTokenizer
{
    /**
     * @return list<int|string|array{0: int, 1: string, 2: int}>
     */
    public static function tokenize(string $source, int $flags = 0): array
    {
        return LanguageScanner::tokenize($source, $flags);
    }

    public static function tokenizeToHashTable(string $source, int $flags = 0): HashTable
    {
        return self::hostTokensToHashTable(self::tokenize($source, $flags));
    }

    /**
     * @param list<int|string|array{0: int, 1: string, 2: int}> $tokens
     */
    public static function hostTokensToHashTable(array $tokens): HashTable
    {
        $ht = new HashTable();
        foreach ($tokens as $token) {
            $ht->append(self::hostTokenToVariable($token));
        }

        return $ht;
    }

    /**
     * @param int|string|array{0: int, 1: string, 2: int} $token
     */
    private static function hostTokenToVariable($token): Variable
    {
        $value = new Variable();
        if (\is_array($token)) {
            $inner = new HashTable();
            foreach ($token as $part) {
                $inner->append(self::scalarToVariable($part));
            }
            $value->array($inner);

            return $value;
        }

        self::assignScalar($value, $token);

        return $value;
    }

    /**
     * @param int|string $scalar
     */
    private static function scalarToVariable($scalar): Variable
    {
        $value = new Variable();
        self::assignScalar($value, $scalar);

        return $value;
    }

    /**
     * @param int|string $scalar
     */
    private static function assignScalar(Variable $value, $scalar): void
    {
        if (\is_int($scalar)) {
            $value->int($scalar);

            return;
        }

        $value->string((string) $scalar);
    }
}
