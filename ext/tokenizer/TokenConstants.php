<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tokenizer;

use PHPCompiler\CompilerVersion;

/**
 * T_* tokenizer identifiers (php-src ext/tokenizer/tokenizer_data.c; issue #6940, #7254).
 *
 * Native lexer parity tracked in #3171 / #4561.
 * PHP 8.4+ forward profile: T_*_SET + T_PROPERTY_C with php-src 8.4 ids (#28130).
 */
final class TokenConstants
{
    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        // PROFILE≥8.4 must use the shifted php-src 8.4 table — host PHP 8.2 ids collide
        // (T_READONLY=327 vs T_PRIVATE_SET=327) and omit T_*_SET / T_PROPERTY_C (#28130).
        if (self::usePhp84TokenizerSurface()) {
            return self::fallbackConstants();
        }

        $constants = [];
        $groups = \get_defined_constants(true);
        if (isset($groups['tokenizer']) && \is_array($groups['tokenizer'])) {
            foreach ($groups['tokenizer'] as $name => $value) {
                if (!\is_string($name) || !\is_int($value)) {
                    continue;
                }
                if (\str_starts_with($name, 'T_') || 'TOKEN_PARSE' === $name) {
                    $constants[$name] = $value;
                }
            }
        }

        if ([] !== $constants) {
            return $constants;
        }

        return self::fallbackConstants();
    }

    public static function nameForId(int $id): ?string
    {
        $name = self::fallbackIdToName()[$id] ?? null;
        if (null !== $name) {
            return $name;
        }

        foreach (self::registeredConstants() as $name => $value) {
            if ($value === $id) {
                // Id 1 is TOKEN_PARSE in userland but not a named lexer token (#14925).
                if ('TOKEN_PARSE' === $name) {
                    return null;
                }

                return $name;
            }
        }

        return null;
    }

    /** PHP 8.4 tokenizer tokens / ids (Zend/zend_language_parser.y; #28130). */
    public static function usePhp84TokenizerSurface(): bool
    {
        return \version_compare(CompilerVersion::languageProfileVersion(), '8.4.0', '>=');
    }

    /** @return array<string, int> */
    private static function fallbackConstants(): array
    {
        return TokenConstantsData::nameToId();
    }

    /** @return array<int, string> */
    private static function fallbackIdToName(): array
    {
        return TokenConstantsData::idToName();
    }
}
