<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * explode() for compiled JIT/AOT modules (#14750, php-in-PHP).
 *
 * Semantics match {@see VmString} explode() / php-src ext/standard/string.c
 * (`php_explode()`, `php_explode_negative_limit()`).
 *
 * NestedJIT notes (peer StrReplace #27079 / StrIncdec #27345 / #27660):
 * - No cross-class string SSOT calls — unbound stubs become `__value__writeNull` then abort
 *   on HashTable type-check under thin AOT.
 * - No `\strlen` / `\strpos` / `\substr`; walk bytes with `isset` / `++` only.
 * - Never index with `$s[$i+$j]`; advance a separate cursor.
 */
final class ExplodeJitHelper
{
    public static function explodeArgv(string $delimiter, string $haystack, int $limit): HashTable
    {
        if ('' === $delimiter) {
            // NestedJIT-safe: do not call VmString (unbound → writeNull). Wording SSOT: #30505 / #30625.
            throw new \ValueError('explode(): Argument #1 ($separator) '.self::zendEmptyArgSuffix());
        }
        if ('' === $haystack) {
            if ($limit >= 0) {
                return self::singlePart('');
            }

            return new HashTable();
        }
        if ($limit > 1) {
            return self::explodePositiveLimit($delimiter, $haystack, $limit);
        }
        if ($limit < 0) {
            return self::explodeNegativeLimit($delimiter, $haystack, $limit);
        }

        return self::singlePart($haystack);
    }

    private static function explodePositiveLimit(string $delimiter, string $haystack, int $limit): HashTable
    {
        $ht = new HashTable();
        $offset = 0;
        $delimLen = self::byteLen($delimiter);
        $strLen = self::byteLen($haystack);
        $pos = self::findAt($haystack, $delimiter, $offset);
        if ($pos < 0) {
            self::appendString($ht, self::slice($haystack, $offset, $strLen - $offset));

            return $ht;
        }
        do {
            self::appendString($ht, self::slice($haystack, $offset, $pos - $offset));
            $offset = $pos + $delimLen;
            $pos = self::findAt($haystack, $delimiter, $offset);
            --$limit;
        } while ($pos >= 0 && $limit > 1);
        if ($offset <= $strLen) {
            self::appendString($ht, self::slice($haystack, $offset, $strLen - $offset));
        }

        return $ht;
    }

    private static function explodeNegativeLimit(string $delimiter, string $haystack, int $limit): HashTable
    {
        $delimLen = self::byteLen($delimiter);
        $strLen = self::byteLen($haystack);
        $delimCount = 0;
        $scan = 0;
        while (true) {
            $pos = self::findAt($haystack, $delimiter, $scan);
            if ($pos < 0) {
                break;
            }
            ++$delimCount;
            $scan = $pos + $delimLen;
        }
        // positions = [0] + after each delim → count = delimCount + 1 (php_explode_negative_limit)
        $found = $delimCount + 1;
        $toReturn = $limit + $found;
        if ($toReturn <= 0) {
            return new HashTable();
        }
        $ht = new HashTable();
        $partIndex = 0;
        $start = 0;
        $scan = 0;
        while ($partIndex < $toReturn) {
            $pos = self::findAt($haystack, $delimiter, $scan);
            // Last position index → remainder of string (php_explode_negative_limit)
            if ($pos < 0 || ($partIndex + 1) >= $found) {
                self::appendString($ht, self::slice($haystack, $start, $strLen - $start));
                break;
            }
            self::appendString($ht, self::slice($haystack, $start, $pos - $start));
            $start = $pos + $delimLen;
            $scan = $start;
            ++$partIndex;
        }

        return $ht;
    }

    private static function singlePart(string $part): HashTable
    {
        $ht = new HashTable();
        self::appendString($ht, $part);

        return $ht;
    }

    private static function appendString(HashTable $ht, string $part): void
    {
        $value = new Variable();
        $value->string($part);
        $ht->append($value);
    }

    /** NestedJIT-safe byte length (no \strlen). */
    private static function byteLen(string $s): int
    {
        $n = 0;
        while (true) {
            if (!isset($s[$n])) {
                return $n;
            }
            ++$n;
        }
    }

    /**
     * Find needle at/after offset; -1 if missing.
     * Walks with separate cursors — no `$s[$i+$j]` (#27079).
     */
    private static function findAt(string $haystack, string $needle, int $offset): int
    {
        $hayLen = self::byteLen($haystack);
        $needleLen = self::byteLen($needle);
        if ($needleLen < 1 || $offset > $hayLen) {
            return -1;
        }
        $i = $offset;
        while ($i < $hayLen) {
            $matched = true;
            $j = 0;
            $hi = $i;
            while ($j < $needleLen) {
                if ($hi >= $hayLen || $haystack[$hi] !== $needle[$j]) {
                    $matched = false;
                    break;
                }
                ++$j;
                ++$hi;
            }
            if ($matched) {
                return $i;
            }
            ++$i;
        }

        return -1;
    }

    /** Build a byte slice via concat (no \substr). */
    private static function slice(string $s, int $start, int $len): string
    {
        if ($len <= 0) {
            return '';
        }
        $strLen = self::byteLen($s);
        if ($start >= $strLen) {
            return '';
        }
        $out = '';
        $i = $start;
        $taken = 0;
        while ($taken < $len && isset($s[$i])) {
            $out = self::concat($out, $s[$i]);
            ++$i;
            ++$taken;
        }

        return $out;
    }

    private static function concat(string $left, string $right): string
    {
        $out = '';
        $out .= $left;
        $out .= $right;

        return $out;
    }

    /**
     * NestedJIT-safe copy of {@see VmString::zendArgumentMustNotBeEmptySuffix} (#30625).
     */
    private static function zendEmptyArgSuffix(): string
    {
        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === $raw) {
            return 'cannot be empty';
        }
        $raw = trim($raw);
        if (isset($raw[0], $raw[1], $raw[2]) && '8' === $raw[0] && '.' === $raw[1] && $raw[2] >= '4') {
            return 'must not be empty';
        }

        return 'cannot be empty';
    }
}
