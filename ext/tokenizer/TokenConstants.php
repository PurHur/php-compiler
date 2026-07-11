<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tokenizer;

/**
 * T_* tokenizer identifiers (php-src ext/tokenizer/tokenizer_data.c; issue #6940, #7254).
 *
 * Native lexer parity tracked in #3171 / #4561.
 */
final class TokenConstants
{
    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
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
