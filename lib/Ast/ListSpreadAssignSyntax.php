<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

/**
 * Detect list destructuring spread assignment for reference-profile rejection (#17182).
 *
 * php-src: Zend/zend_compile.c — "Spread operator is not supported in assignments".
 */
final class ListSpreadAssignSyntax
{
    public const REFERENCE_PROFILE_MESSAGE = 'Spread operator is not supported in assignments';

    /**
     * @return array{line: int, message: string}|null
     */
    public static function referenceProfileSyntaxError(string $code): ?array
    {
        if (!str_contains($code, '...')) {
            return null;
        }

        $tokens = token_get_all($code);
        for ($i = 0, $c = \count($tokens); $i < $c; ++$i) {
            if (!\is_array($tokens[$i]) || T_ELLIPSIS !== $tokens[$i][0]) {
                continue;
            }
            if (!self::isListSpreadAssignEllipsis($tokens, $i)) {
                continue;
            }
            $start = self::tokenByteOffset($tokens, $i);
            if (null === $start) {
                continue;
            }

            return [
                'line' => self::byteOffsetToLine($code, $start),
                'message' => self::REFERENCE_PROFILE_MESSAGE,
            ];
        }

        return null;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isListSpreadAssignEllipsis(array $tokens, int $ellipsisIndex): bool
    {
        if ($ellipsisIndex + 1 >= \count($tokens) || !self::isVariableToken($tokens[$ellipsisIndex + 1])) {
            return false;
        }

        $depth = 0;
        for ($i = $ellipsisIndex - 1; $i >= 0; --$i) {
            $text = self::tokenText($tokens[$i]);
            if (']' === $text) {
                ++$depth;
                continue;
            }
            if ('[' === $text) {
                if (0 === $depth) {
                    return self::listBracketIsAssignLhs($tokens, $i);
                }
                --$depth;
                continue;
            }
            if ($depth > 0) {
                continue;
            }
            if (';' === $text || '{' === $text || '}' === $text) {
                return false;
            }
        }

        return false;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function listBracketIsAssignLhs(array $tokens, int $openIndex): bool
    {
        $depth = 0;
        for ($i = $openIndex + 1, $c = \count($tokens); $i < $c; ++$i) {
            $text = self::tokenText($tokens[$i]);
            if ('[' === $text) {
                ++$depth;
                continue;
            }
            if (']' === $text) {
                if (0 === $depth) {
                    return self::nextNonWhitespaceTokenText($tokens, $i + 1) === '=';
                }
                --$depth;
            }
        }

        return false;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function nextNonWhitespaceTokenText(array $tokens, int $start): ?string
    {
        for ($i = $start, $c = \count($tokens); $i < $c; ++$i) {
            if (!\is_array($tokens[$i])) {
                return $tokens[$i];
            }
            if (T_WHITESPACE !== $tokens[$i][0]) {
                return $tokens[$i][1];
            }
        }

        return null;
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function isVariableToken($token): bool
    {
        return \is_array($token) && T_VARIABLE === $token[0];
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function tokenText($token): string
    {
        return \is_array($token) ? $token[1] : $token;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function tokenByteOffset(array $tokens, int $index): ?int
    {
        $token = $tokens[$index] ?? null;
        if (!\is_array($token)) {
            return null;
        }

        return $token[2];
    }

    private static function byteOffsetToLine(string $code, int $offset): int
    {
        return substr_count(substr($code, 0, max(0, $offset)), "\n") + 1;
    }
}
