<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PHPCompiler\CompilerVersion;

/**
 * Desugar PHP 8.4+ null coalesce inside double-quoted `{$...}` interpolation (#14063).
 *
 * nikic/php-parser (ONLY_PHP7) rejects `??` in encapsed variables; Zend 8.4 allows it.
 * Lower to string concat: `"a{$x ?? 0}b"` → `'a' . ($x ?? 0) . 'b'`.
 *
 * php-src: Zend/zend_language_parser.y encapsed variable grammar; Zend/zend_compile.c.
 */
final class EncapsedCoalesceDesugar
{
    public static function desugar(string $code): string
    {
        if (!CompilerVersion::supportsEncapsedCoalesce()) {
            return $code;
        }
        if (false === strpos($code, '??') || false === strpos($code, '"')) {
            return $code;
        }
        if (!\function_exists('token_get_all')) {
            return $code;
        }

        $tokens = token_get_all($code);
        $offsets = self::tokenByteOffsets($tokens);
        $replacements = [];

        for ($i = 0, $c = \count($tokens); $i < $c; ++$i) {
            $closeIdx = null;
            if (!self::isDoubleQuotedStringStart($tokens, $i, $closeIdx)) {
                continue;
            }

            if (!self::encapsedStringHasCoalesce($tokens, $i, $closeIdx)) {
                $i = $closeIdx;
                continue;
            }

            $replacement = self::buildConcatExpression($tokens, $i, $closeIdx);
            $replacements[] = [
                'start' => $offsets[$i],
                'end' => $offsets[$closeIdx] + 1,
                'text' => $replacement,
            ];
            $i = $closeIdx;
        }

        if ([] === $replacements) {
            return $code;
        }

        usort($replacements, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
        foreach ($replacements as $replacement) {
            $code = substr($code, 0, $replacement['start'])
                .$replacement['text']
                .substr($code, $replacement['end']);
        }

        return $code;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array<int, int>
     */
    private static function tokenByteOffsets(array $tokens): array
    {
        $offsets = [];
        $offset = 0;
        foreach ($tokens as $idx => $token) {
            $offsets[$idx] = $offset;
            $offset += \strlen(\is_array($token) ? $token[1] : $token);
        }

        return $offsets;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isDoubleQuotedStringStart(array $tokens, int $i, ?int &$closeIdx): bool
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
    private static function encapsedStringHasCoalesce(array $tokens, int $openIdx, int $closeIdx): bool
    {
        for ($j = $openIdx + 1; $j < $closeIdx; ++$j) {
            $token = $tokens[$j];
            if (!self::isCurlyOpen($token)) {
                continue;
            }
            $innerStart = $j + 1;
            $afterClose = self::consumeBalancedCurly($tokens, $j);
            $innerTokens = \array_slice($tokens, $innerStart, $afterClose - $innerStart - 1);
            if (null !== self::findTopLevelCoalesceLine($innerTokens)) {
                return true;
            }
            $j = $afterClose - 1;
        }

        return false;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function buildConcatExpression(array $tokens, int $openIdx, int $closeIdx): string
    {
        $parts = [];
        for ($j = $openIdx + 1; $j < $closeIdx; ++$j) {
            $token = $tokens[$j];
            if (\is_array($token) && \T_ENCAPSED_AND_WHITESPACE === $token[0]) {
                if ('' !== $token[1]) {
                    $parts[] = \var_export($token[1], true);
                }
                continue;
            }
            if (\is_array($token) && \T_VARIABLE === $token[0]) {
                $parts[] = $token[1];
                continue;
            }
            if (\is_array($token) && \defined('T_DOLLAR_OPEN_CURLY_BRACES') && \T_DOLLAR_OPEN_CURLY_BRACES === $token[0]) {
                $legacy = $token[1];
                if (isset($tokens[$j + 1]) && \is_array($tokens[$j + 1]) && \T_STRING_VARNAME === $tokens[$j + 1][0]) {
                    $legacy .= $tokens[$j + 1][1];
                    ++$j;
                }
                if (isset($tokens[$j + 1]) && \is_string($tokens[$j + 1]) && '[' === $tokens[$j + 1]) {
                    $legacy .= self::consumeBracketSuffix($tokens, $j + 1);
                }
                $parts[] = $legacy;
                continue;
            }
            if (self::isCurlyOpen($token)) {
                $innerStart = $j + 1;
                $afterClose = self::consumeBalancedCurly($tokens, $j);
                $innerTokens = \array_slice($tokens, $innerStart, $afterClose - $innerStart - 1);
                $parts[] = '('.self::tokensToSource($innerTokens).')';
                $j = $afterClose - 1;
                continue;
            }
        }

        if ([] === $parts) {
            return "''";
        }
        if (1 === \count($parts)) {
            return $parts[0];
        }

        return \implode(' . ', $parts);
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function consumeBracketSuffix(array $tokens, int $openIdx): string
    {
        $depth = 0;
        $text = '';
        for ($j = $openIdx, $c = \count($tokens); $j < $c; ++$j) {
            $token = $tokens[$j];
            $text .= \is_array($token) ? $token[1] : $token;
            if (\is_string($token) && '[' === $token) {
                ++$depth;
            } elseif (\is_string($token) && ']' === $token) {
                --$depth;
                if (0 === $depth) {
                    break;
                }
            }
        }

        return $text;
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

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function tokensToSource(array $tokens): string
    {
        $out = '';
        foreach ($tokens as $token) {
            $out .= \is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}
