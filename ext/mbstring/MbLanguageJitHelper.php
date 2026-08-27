<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_language() NestedJIT canonicalize (#35259 leftover of #4636 / peer #35221).
 *
 * Returns a small int code (NestedJIT bool/string statics are unreliable). Mutable
 * language is stored in an LLVM module global by {@see JitMbLanguage}.
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_language)
 */
final class MbLanguageJitHelper
{
    public const CODE_NEUTRAL = 1;

    public const CODE_UNI = 2;

    public const CODE_ENGLISH = 3;

    public const CODE_GERMAN = 4;

    public const CODE_JAPANESE = 5;

    public const CODE_KOREAN = 6;

    public const CODE_RUSSIAN = 7;

    public const CODE_SIMPLIFIED_CHINESE = 8;

    public const CODE_TRADITIONAL_CHINESE = 9;

    public const CODE_ARMENIAN = 10;

    public const CODE_UKRAINIAN = 11;

    public const CODE_TURKISH = 12;

    /**
     * @return int language code; throws ValueError when invalid
     */
    public static function canonicalizeArgv(string $language): int
    {
        $code = self::codeFor($language);
        if (0 === $code) {
            // Concat (not sprintf) — NestedJIT sprintf+throw breaks module verify (#34625).
            throw new \ValueError(
                'mb_language(): Argument #1 ($language) must be a valid language, "'.$language.'" given'
            );
        }

        return $code;
    }

    private static function codeFor(string $language): int
    {
        // Hand-rolled (no strtolower) — NestedJIT of strtolower+throw misfires module verify.
        if (
            'neutral' === $language || 'Neutral' === $language || 'NEUTRAL' === $language
        ) {
            return self::CODE_NEUTRAL;
        }
        if (
            'uni' === $language || 'Uni' === $language || 'UNI' === $language
        ) {
            return self::CODE_UNI;
        }
        if (
            'english' === $language || 'English' === $language || 'ENGLISH' === $language
            || 'en' === $language || 'En' === $language || 'EN' === $language
        ) {
            return self::CODE_ENGLISH;
        }
        if (
            'german' === $language || 'German' === $language || 'GERMAN' === $language
            || 'de' === $language || 'De' === $language || 'DE' === $language
        ) {
            return self::CODE_GERMAN;
        }
        if (
            'japanese' === $language || 'Japanese' === $language || 'JAPANESE' === $language
        ) {
            return self::CODE_JAPANESE;
        }
        if (
            'korean' === $language || 'Korean' === $language || 'KOREAN' === $language
        ) {
            return self::CODE_KOREAN;
        }
        if (
            'russian' === $language || 'Russian' === $language || 'RUSSIAN' === $language
        ) {
            return self::CODE_RUSSIAN;
        }
        if (
            'simplified chinese' === $language
            || 'Simplified Chinese' === $language
            || 'SIMPLIFIED CHINESE' === $language
            || 'Simplified chinese' === $language
        ) {
            return self::CODE_SIMPLIFIED_CHINESE;
        }
        if (
            'traditional chinese' === $language
            || 'Traditional Chinese' === $language
            || 'TRADITIONAL CHINESE' === $language
            || 'Traditional chinese' === $language
        ) {
            return self::CODE_TRADITIONAL_CHINESE;
        }
        if (
            'armenian' === $language || 'Armenian' === $language || 'ARMENIAN' === $language
        ) {
            return self::CODE_ARMENIAN;
        }
        if (
            'ukrainian' === $language || 'Ukrainian' === $language || 'UKRAINIAN' === $language
        ) {
            return self::CODE_UKRAINIAN;
        }
        if (
            'turkish' === $language || 'Turkish' === $language || 'TURKISH' === $language
        ) {
            return self::CODE_TURKISH;
        }

        return 0;
    }
}
