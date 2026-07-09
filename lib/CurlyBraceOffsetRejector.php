<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject PHP 7 curly-brace array/string offset syntax ($s{0}, "abc"{1}) (#5313).
 *
 * php-src: Zend/zend_language_scanner.l — removed in PHP 8.0.
 */
final class CurlyBraceOffsetRejector
{
    private const MESSAGE = 'Array and string offset access syntax with curly braces is no longer supported';

    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (!str_contains($code, '{')) {
            return $code;
        }

        if (!\function_exists('token_get_all')) {
            return $code;
        }

        $tokens = token_get_all($code);
        $encapsedBraceDepth = 0;

        for ($i = 0, $n = \count($tokens); $i < $n; ++$i) {
            $token = $tokens[$i];

            if (\is_array($token)) {
                if (T_CURLY_OPEN === $token[0]) {
                    ++$encapsedBraceDepth;
                    continue;
                }
                if ($encapsedBraceDepth > 0 && '}' === $token[1]) {
                    --$encapsedBraceDepth;
                    continue;
                }
                continue;
            }

            if ('{' !== $token) {
                continue;
            }

            if ($encapsedBraceDepth > 0) {
                continue;
            }

            if (self::isPropertyNameBrace($tokens, $i)) {
                continue;
            }

            if (!self::isOffsetAccessLhs($tokens, $i)) {
                continue;
            }

            if (self::braceOpensBlockBody($tokens, $i)) {
                continue;
            }

            $line = self::lineForToken($tokens, $i);
            throw new CompileFatal($filename, $line, self::MESSAGE);
        }

        return $code;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isPropertyNameBrace(array $tokens, int $braceIndex): bool
    {
        $prev = self::previousSignificantToken($tokens, $braceIndex - 1);
        if (null === $prev) {
            return false;
        }

        if (\is_array($prev)) {
            return \in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
        }

        return '::' === $prev;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isOffsetAccessLhs(array $tokens, int $braceIndex): bool
    {
        $prev = self::previousSignificantToken($tokens, $braceIndex - 1);
        if (null === $prev) {
            return false;
        }

        if (\is_array($prev)) {
            return \in_array($prev[0], [T_VARIABLE, T_CONSTANT_ENCAPSED_STRING], true);
        }

        if (\in_array($prev, [']', ')'], true)) {
            if (')' !== $prev) {
                return true;
            }

            return !self::closingParenOpensControlBlock($tokens, $braceIndex - 1);
        }

        return false;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function closingParenOpensControlBlock(array $tokens, int $closeParenIndex): bool
    {
        $openParenIndex = self::findMatchingOpenParen($tokens, $closeParenIndex);
        if (null === $openParenIndex) {
            return false;
        }

        $beforeOpen = self::previousSignificantToken($tokens, $openParenIndex - 1);
        if (null === $beforeOpen || !\is_array($beforeOpen)) {
            return false;
        }

        static $blockOpeners = [
            T_MATCH,
            T_IF,
            T_ELSEIF,
            T_WHILE,
            T_FOR,
            T_FOREACH,
            T_SWITCH,
            T_CATCH,
            T_FUNCTION,
            T_FN,
            T_USE,
        ];

        if (\in_array($beforeOpen[0], $blockOpeners, true)) {
            return true;
        }

        if (T_NEW === $beforeOpen[0]) {
            return true;
        }

        // `new class(...)` / `new readonly class(...)` anonymous class ctor args — `{` opens class body (#6881, #17467).
        if (T_CLASS === $beforeOpen[0]) {
            $beforeClass = self::previousSignificantToken($tokens, $openParenIndex - 2);
            if (\is_array($beforeClass) && T_NEW === $beforeClass[0]) {
                return true;
            }
            if (\is_array($beforeClass) && \defined('T_READONLY') && T_READONLY === $beforeClass[0]) {
                $beforeReadonly = self::previousSignificantToken($tokens, $openParenIndex - 4);
                if (\is_array($beforeReadonly) && T_NEW === $beforeReadonly[0]) {
                    return true;
                }
            }
        }

        if (T_STRING === $beforeOpen[0]) {
            $beforeString = self::previousSignificantToken($tokens, $openParenIndex - 2);
            if (\is_array($beforeString) && T_FUNCTION === $beforeString[0]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function braceOpensBlockBody(array $tokens, int $braceIndex): bool
    {
        $depth = 0;
        for ($i = $braceIndex, $n = \count($tokens); $i < $n; ++$i) {
            $token = $tokens[$i];
            if (\is_array($token)) {
                if (T_CURLY_OPEN === $token[0]) {
                    ++$depth;
                    continue;
                }
                if (';' === $token[1] && 1 === $depth) {
                    return true;
                }
                if (T_DOUBLE_ARROW === $token[0] && 1 === $depth) {
                    return true;
                }
                if ('}' === $token[1]) {
                    if (1 === $depth) {
                        return false;
                    }
                    if ($depth > 0) {
                        --$depth;
                    }
                    continue;
                }
                continue;
            }

            if ('{' === $token) {
                ++$depth;
                continue;
            }
            if (';' === $token && 1 === $depth) {
                return true;
            }
            if ('}' === $token) {
                if (1 === $depth) {
                    return false;
                }
                if ($depth > 0) {
                    --$depth;
                }
            }
        }

        return false;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return null|array{0: int, 1: string, 2: int}|string
     */
    private static function previousSignificantToken(array $tokens, int $index)
    {
        for ($i = $index; $i >= 0; --$i) {
            $token = $tokens[$i];
            if (\is_array($token)) {
                if (\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                return $token;
            }
            if (' ' === $token || "\t" === $token || "\n" === $token || "\r" === $token) {
                continue;
            }

            return $token;
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function findMatchingOpenParen(array $tokens, int $closeIndex): ?int
    {
        $depth = 0;
        for ($i = $closeIndex; $i >= 0; --$i) {
            $token = $tokens[$i];
            if (\is_string($token)) {
                if (')' === $token) {
                    ++$depth;
                    continue;
                }
                if ('(' === $token) {
                    if (1 === $depth) {
                        return $i;
                    }
                    --$depth;
                }
                continue;
            }
            if (\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
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
