<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

/**
 * Detect `new readonly class` — PHP 8.3+ (ZEND_ACC_READONLY_ANON_CLASS, #6991, #16255).
 *
 * php-src: Zend/zend_language_parser.y / Zend/zend_compile.c — anonymous readonly class modifier.
 */
final class ReadonlyAnonymousClassSyntax
{
    /** Zend 8.2 reference profile message for `new readonly class` (#16255). */
    public const REFERENCE_PROFILE_UNEXPECTED_READONLY = 'syntax error, unexpected token "readonly"';

    /**
     * @return array{line: int, message: string}|null
     */
    public static function referenceProfileSyntaxError(string $code): ?array
    {
        if (!preg_match('/\bnew\b/i', $code)) {
            return null;
        }

        $tokens = token_get_all($code);
        $count = \count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            if (!self::isNewToken($tokens[$i])) {
                continue;
            }
            $j = self::skipInsignificant($tokens, $i + 1, $count);
            if ($j >= $count || !self::isReadonlyToken($tokens[$j])) {
                continue;
            }
            $k = self::skipInsignificant($tokens, $j + 1, $count);
            if ($k >= $count || !self::isClassToken($tokens[$k])) {
                continue;
            }

            return [
                'line' => self::tokenLine($tokens[$j]),
                'message' => self::REFERENCE_PROFILE_UNEXPECTED_READONLY,
            ];
        }

        return null;
    }

    /**
     * @param array<int|string, mixed> $token
     */
    private static function isNewToken(array|string $token): bool
    {
        return \is_array($token) && T_NEW === $token[0];
    }

    /**
     * @param array<int|string, mixed> $token
     */
    private static function isReadonlyToken(array|string $token): bool
    {
        if (\is_array($token) && \defined('T_READONLY') && T_READONLY === $token[0]) {
            return true;
        }

        return \is_array($token)
            && T_STRING === $token[0]
            && 'readonly' === strtolower((string) $token[1]);
    }

    /**
     * @param array<int|string, mixed> $token
     */
    private static function isClassToken(array|string $token): bool
    {
        return \is_array($token) && T_CLASS === $token[0];
    }

    /**
     * @param list<array<int|string, mixed>|string> $tokens
     */
    private static function skipInsignificant(array $tokens, int $start, int $count): int
    {
        for ($i = $start; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (\is_string($token)) {
                continue;
            }
            if (\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return $count;
    }

    /**
     * @param array<int|string, mixed>|string $token
     */
    private static function tokenLine(array|string $token): int
    {
        if (\is_array($token)) {
            return (int) ($token[2] ?? 1);
        }

        return 1;
    }
}
