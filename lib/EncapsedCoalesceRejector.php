<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject null coalesce (??) inside encapsed/complex double-quoted interpolation (#14032).
 *
 * php-src: Zend/zend_language_scanner.l — encapsed variable grammar allows ->, ?->, [, { but not ??.
 */
final class EncapsedCoalesceRejector
{
    private const MESSAGE = 'syntax error, unexpected token "??", expecting "->" or "?->" or "{" or "["';

    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (false === strpos($code, '??') || false === strpos($code, '"')) {
            return $code;
        }

        if (!\function_exists('token_get_all')) {
            return $code;
        }

        $tokens = token_get_all($code);

        for ($i = 0, $c = \count($tokens); $i < $c; ++$i) {
            $closeIdx = null;
            if (!self::isDoubleQuotedInterpolationStart($tokens, $i, $closeIdx)) {
                continue;
            }

            $coalesceLine = self::findEncapsedCoalesceLine($tokens, $i, $closeIdx);
            if (null !== $coalesceLine) {
                throw new CompileFatal($filename, $coalesceLine, self::MESSAGE);
            }

            $i = $closeIdx;
        }

        return $code;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isDoubleQuotedInterpolationStart(array $tokens, int $i, ?int &$closeIdx): bool
    {
        $closeIdx = null;
        if (!isset($tokens[$i]) || !\is_string($tokens[$i]) || '"' !== $tokens[$i]) {
            return false;
        }
        $end = self::findDoubleQuotedStringEnd($tokens, $i);
        if (null === $end || $end === $i + 1) {
            return false;
        }
        $closeIdx = $end;

        return true;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function findDoubleQuotedStringEnd(array $tokens, int $openIdx): ?int
    {
        for ($j = $openIdx + 1, $c = \count($tokens); $j < $c; ++$j) {
            if (\is_string($tokens[$j]) && '"' === $tokens[$j]) {
                return $j;
            }
        }

        return null;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function findEncapsedCoalesceLine(array $tokens, int $openIdx, int $closeIdx): ?int
    {
        for ($j = $openIdx + 1; $j < $closeIdx; ++$j) {
            $token = $tokens[$j];
            if (\is_array($token) && \T_ENCAPSED_AND_WHITESPACE === $token[0]) {
                continue;
            }
            if (\is_array($token) && \T_VARIABLE === $token[0]) {
                continue;
            }
            if (self::isCurlyOpen($token)) {
                $innerStart = $j + 1;
                $afterClose = self::consumeBalancedCurly($tokens, $j);
                $innerTokens = \array_slice($tokens, $innerStart, $afterClose - $innerStart - 1);
                $line = self::findTopLevelCoalesceLine($innerTokens);
                if (null !== $line) {
                    return $line;
                }
                $j = $afterClose - 1;
                continue;
            }
            if (\is_array($token) && \defined('T_DOLLAR_OPEN_CURLY_BRACES') && \T_DOLLAR_OPEN_CURLY_BRACES === $token[0]) {
                continue;
            }

            return null;
        }

        return null;
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function isCurlyOpen($token): bool
    {
        if (\is_array($token) && \defined('T_CURLY_OPEN') && \T_CURLY_OPEN === $token[0]) {
            return true;
        }

        return \is_string($token) && '{' === $token;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function consumeBalancedCurly(array $tokens, int $openIdx): int
    {
        $depth = 1;
        $j = $openIdx + 1;
        while ($j < \count($tokens) && $depth > 0) {
            $token = $tokens[$j];
            if (self::isCurlyOpen($token)) {
                ++$depth;
            } elseif (\is_string($token) && '}' === $token) {
                --$depth;
            }
            ++$j;
        }

        return $j;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function findTopLevelCoalesceLine(array $tokens): ?int
    {
        $depth = 0;
        foreach ($tokens as $token) {
            if (\is_string($token)) {
                if (\in_array($token, ['(', '[', '{'], true)) {
                    ++$depth;
                } elseif (\in_array($token, [')', ']', '}'], true) && $depth > 0) {
                    --$depth;
                }
                continue;
            }
            if (0 === $depth && \T_COALESCE === $token[0]) {
                return isset($token[2]) ? (int) $token[2] : 1;
            }
        }

        return null;
    }
}
