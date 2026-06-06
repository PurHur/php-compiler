<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject `new readonly class { … }` — invalid PHP syntax (#6903).
 *
 * php-src: Zend/zend_language_parser.y — readonly modifier on named classes only;
 * anonymous `new class` may use per-property `readonly` (PHP 8.3, #6724).
 */
final class ReadonlyAnonymousClassRejector
{
    private const MESSAGE = 'syntax error, unexpected token "readonly"';

    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (!str_contains($code, 'readonly') || !str_contains($code, 'new')) {
            return $code;
        }

        if (!\function_exists('token_get_all')) {
            return $code;
        }

        $tokens = token_get_all($code);
        $n = \count($tokens);

        for ($i = 0; $i < $n; ++$i) {
            if (!self::isNewToken($tokens[$i])) {
                continue;
            }

            $readonlyIndex = self::nextSignificantIndex($tokens, $i + 1, $n);
            if (null === $readonlyIndex || !self::isReadonlyToken($tokens[$readonlyIndex])) {
                continue;
            }

            $classIndex = self::nextSignificantIndex($tokens, $readonlyIndex + 1, $n);
            if (null === $classIndex || !self::isClassToken($tokens[$classIndex])) {
                continue;
            }

            $line = self::lineForToken($tokens, $readonlyIndex);
            throw new CompileFatal($filename, $line, self::MESSAGE);
        }

        return $code;
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function isNewToken($token): bool
    {
        return \is_array($token) && T_NEW === $token[0];
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function isReadonlyToken($token): bool
    {
        return \is_array($token) && T_READONLY === $token[0];
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function isClassToken($token): bool
    {
        return \is_array($token) && T_CLASS === $token[0];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function nextSignificantIndex(array $tokens, int $start, int $n): ?int
    {
        for ($i = $start; $i < $n; ++$i) {
            $token = $tokens[$i];
            if (\is_array($token)) {
                if (\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                return $i;
            }
            if (' ' === $token || "\t" === $token || "\n" === $token || "\r" === $token) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function lineForToken(array $tokens, int $index): int
    {
        for ($i = $index; $i >= 0; --$i) {
            $token = $tokens[$i];
            if (\is_array($token) && isset($token[2])) {
                return (int) $token[2];
            }
        }

        return 1;
    }
}
