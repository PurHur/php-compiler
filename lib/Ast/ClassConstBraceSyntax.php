<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

/**
 * Detect PHP 8.3+ class constant brace dereference `Class::{'NAME'}` / `Class::{"NAME"}` (#16597).
 *
 * php-src: Zend/zend_language_parser.y — dynamic class constant fetch with braced string name (PHP 8.3).
 */
final class ClassConstBraceSyntax
{
    /** Zend 8.2 reference profile diagnostic for braced class member fetch (#16597). */
    public const REFERENCE_PROFILE_UNEXPECTED_SEMICOLON = 'syntax error, unexpected token ";", expecting "("';

    /**
     * @return array{line: int, message: string}|null
     */
    public static function referenceProfileSyntaxError(string $code): ?array
    {
        if (!str_contains($code, '::') || !str_contains($code, '{')) {
            return null;
        }

        if (!\function_exists('token_get_all')) {
            return null;
        }

        $tokens = token_get_all($code);
        $count = \count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            if (!self::isDoubleColonToken($tokens[$i])) {
                continue;
            }

            $j = self::skipInsignificant($tokens, $i + 1, $count);
            if ($j >= $count || '{' !== self::tokenText($tokens[$j])) {
                continue;
            }

            $k = self::skipInsignificant($tokens, $j + 1, $count);
            if ($k >= $count || !self::isEncapsedStringToken($tokens[$k])) {
                continue;
            }

            return [
                'line' => self::tokenLine($tokens[$k]),
                'message' => self::REFERENCE_PROFILE_UNEXPECTED_SEMICOLON,
            ];
        }

        return null;
    }

    /**
     * @param array<int|string, mixed>|string $token
     */
    private static function isDoubleColonToken(array|string $token): bool
    {
        return \is_array($token) && T_DOUBLE_COLON === $token[0];
    }

    /**
     * @param array<int|string, mixed>|string $token
     */
    private static function isEncapsedStringToken(array|string $token): bool
    {
        return \is_array($token) && T_CONSTANT_ENCAPSED_STRING === $token[0];
    }

    /**
     * @param list<array<int|string, mixed>|string> $tokens
     */
    private static function skipInsignificant(array $tokens, int $index, int $count): int
    {
        for ($i = $index; $i < $count; ++$i) {
            if (!self::isInsignificantToken($tokens[$i])) {
                return $i;
            }
        }

        return $count;
    }

    /**
     * @param array<int|string, mixed>|string $token
     */
    private static function isInsignificantToken(array|string $token): bool
    {
        if (!\is_array($token)) {
            return \in_array($token, [' ', "\t", "\n", "\r"], true);
        }

        return \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /**
     * @param array<int|string, mixed>|string $token
     */
    private static function tokenText(array|string $token): string
    {
        return \is_array($token) ? (string) $token[1] : (string) $token;
    }

    /**
     * @param list<array<int|string, mixed>|string> $tokens
     */
    private static function tokenLine(array|string $token): int
    {
        if (\is_array($token) && isset($token[2])) {
            return (int) $token[2];
        }

        return 1;
    }
}
