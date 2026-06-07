<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tokenizer;

/**
 * T_* tokenizer identifiers (php-src ext/tokenizer/tokenizer_data.c; issue #6940).
 *
 * Full lexer parity tracked in #3171 / #4561.
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
        foreach (self::registeredConstants() as $name => $value) {
            if ($value === $id) {
                return $name;
            }
        }

        return null;
    }

    /** @return array<string, int> */
    private static function fallbackConstants(): array
    {
        return [
            'T_OPEN_TAG' => 389,
            'T_CLOSE_TAG' => 390,
            'T_ECHO' => 266,
            'T_WHITESPACE' => 392,
            'T_STRING' => 262,
            'T_VARIABLE' => 266,
            // T_ECHO and T_VARIABLE share id 266 in php-src; nameForId returns first match.
            'T_LNUMBER' => 260,
            'T_DNUMBER' => 261,
            'T_CONSTANT_ENCAPSED_STRING' => 269,
            'T_DOUBLE_COLON' => 387,
            'T_PAAMAYIM_NEKUDOTAYIM' => 387,
            'TOKEN_PARSE' => 1,
        ];
    }
}
