<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

/**
 * PHP 8.3+ `new readonly class { ... }` detection for reference-profile rejection (#6991, #16255).
 *
 * php-src: Zend/zend_language_parser.y — anonymous class with readonly modifier;
 * Zend/zend_compile.c ZEND_ACC_READONLY on anonymous class.
 */
final class ReadonlyAnonymousClassSupport
{
    /** Zend 8.2 profile message for `new readonly class` (#16255). */
    public const REFERENCE_PROFILE_UNEXPECTED_READONLY = 'syntax error, unexpected token "readonly"';

    /**
     * @return array{line: int, message: string}|null
     */
    public static function referenceProfileSyntaxError(string $code): ?array
    {
        if (!str_contains($code, 'readonly') || !str_contains($code, 'class')) {
            return null;
        }
        if (!\function_exists('token_get_all')) {
            return null;
        }

        $tokens = token_get_all($code);
        for ($i = 0, $n = \count($tokens); $i < $n; ++$i) {
            $token = $tokens[$i];
            if (!\is_array($token) || T_NEW !== $token[0]) {
                continue;
            }

            $readonlyIndex = self::nextSignificantTokenIndex($tokens, $i + 1, $n);
            if (null === $readonlyIndex) {
                continue;
            }
            $readonlyToken = $tokens[$readonlyIndex];
            if (!\is_array($readonlyToken) || T_READONLY !== $readonlyToken[0]) {
                continue;
            }

            $classIndex = self::nextSignificantTokenIndex($tokens, $readonlyIndex + 1, $n);
            if (null === $classIndex) {
                continue;
            }
            $classToken = $tokens[$classIndex];
            if (!\is_array($classToken) || T_CLASS !== $classToken[0]) {
                continue;
            }

            return [
                'line' => $readonlyToken[2],
                'message' => self::REFERENCE_PROFILE_UNEXPECTED_READONLY,
            ];
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function nextSignificantTokenIndex(array $tokens, int $start, int $n): ?int
    {
        for ($i = $start; $i < $n; ++$i) {
            $token = $tokens[$i];
            if (\is_array($token) && \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }
}
