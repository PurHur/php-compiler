<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_substitute_character() NestedJIT canonicalize (#35263 leftover of #13100 / peer #35259).
 *
 * Returns a packed int for the LLVM module global:
 * - {@see CODE_NONE} / {@see CODE_LONG} / {@see CODE_ENTITY} for named modes
 * - >= 0 = MODE_CHAR codepoint
 *
 * NestedJIT bool/string statics are unreliable — mutable state lives in the module global
 * owned by {@see JitMbSubstituteCharacter}.
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_substitute_character)
 */
final class MbSubstituteCharacterJitHelper
{
    public const CODE_NONE = -1;

    public const CODE_LONG = -2;

    public const CODE_ENTITY = -3;

    /**
     * @return int packed mode/codepoint; throws ValueError when invalid
     */
    public static function canonicalizeStringArgv(string $substchar): int
    {
        // Hand-rolled (no strcasecmp) — NestedJIT of strcasecmp+throw misfires module verify.
        if (
            'none' === $substchar || 'None' === $substchar || 'NONE' === $substchar
        ) {
            return self::CODE_NONE;
        }
        if (
            'long' === $substchar || 'Long' === $substchar || 'LONG' === $substchar
        ) {
            return self::CODE_LONG;
        }
        if (
            'entity' === $substchar || 'Entity' === $substchar || 'ENTITY' === $substchar
        ) {
            return self::CODE_ENTITY;
        }

        // Concat (not sprintf) — NestedJIT sprintf+throw breaks module verify (#34625).
        // Param name matches php-src stub ($substitute_character).
        throw new \ValueError(
            'mb_substitute_character(): Argument #1 ($substitute_character) must be "none", "long", "entity" or a valid codepoint'
        );
    }

    /**
     * @return int codepoint (>= 0); throws ValueError when invalid
     */
    public static function canonicalizeLongArgv(int $codepoint): int
    {
        if ($codepoint < 0 || $codepoint >= 0x110000) {
            throw new \ValueError(
                'mb_substitute_character(): Argument #1 ($substitute_character) is not a valid codepoint'
            );
        }
        if ($codepoint >= 0xD800 && $codepoint <= 0xDFFF) {
            throw new \ValueError(
                'mb_substitute_character(): Argument #1 ($substitute_character) is not a valid codepoint'
            );
        }

        return $codepoint;
    }
}
