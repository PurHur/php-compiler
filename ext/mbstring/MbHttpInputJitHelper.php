<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_http_input() NestedJIT type-letter kind (#35271 leftover of #4636).
 *
 * Returns a small int kind (NestedJIT bool/array statics are unreliable). List payload
 * is a joined string exploded in {@see JitMbHttpInput} (peer {@see MbEncodingAliasesJitHelper}).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_http_input)
 */
final class MbHttpInputJitHelper
{
    public const JOIN_DELIM = ',';

    /** G/P/C/S or empty identify → bool false. */
    public const KIND_FALSE = 0;

    /** Type "I" → list array (joined payload). */
    public const KIND_LIST = 2;

    /** Type "L" → comma-joined string (same payload). */
    public const KIND_JOINED = 3;

    /**
     * @return int kind code; throws ValueError when $type is not one letter G/P/C/S/I/L
     */
    public static function kindArgv(string $type): int
    {
        if (1 !== \strlen($type)) {
            throw new \ValueError(
                'mb_http_input(): Argument #1 ($type) must be one of "G", "P", "C", "S", "I", or "L"'
            );
        }
        $c = $type[0];
        // Hand-rolled (no strtoupper) — NestedJIT of strtoupper+throw misfires module verify.
        if ('G' === $c || 'g' === $c
            || 'P' === $c || 'p' === $c
            || 'C' === $c || 'c' === $c
            || 'S' === $c || 's' === $c
        ) {
            return self::KIND_FALSE;
        }
        if ('I' === $c || 'i' === $c) {
            return self::KIND_LIST;
        }
        if ('L' === $c || 'l' === $c) {
            return self::KIND_JOINED;
        }
        throw new \ValueError(
            'mb_http_input(): Argument #1 ($type) must be one of "G", "P", "C", "S", "I", or "L"'
        );
    }

    /**
     * Cold-default HTTP input list joined (php-src MBSTRG(http_input_list) tracks
     * internal_encoding — default UTF-8). Mutable list sync with mb_internal_encoding
     * under AOT remains a follow-up.
     */
    public static function listJoinedArgv(): string
    {
        return 'UTF-8';
    }
}
