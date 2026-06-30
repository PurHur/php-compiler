<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

/**
 * Desugar PHP 8.3+ null coalesce inside encapsed "${... ?? ...}" for nikic/php-parser 4.x (#14024).
 *
 * php-src: Zend/zend_compile.c — zend_compile_encapsed_string complex-variable expressions.
 */
final class EncapsedCoalesceDesugar
{
    public static function desugar(string $code): string
    {
        if (false === strpos($code, '??') || false === strpos($code, '"')) {
            return $code;
        }

        $tokens = token_get_all($code);
        $replacements = [];

        for ($i = 0, $c = \count($tokens); $i < $c; ++$i) {
            $closeIdx = null;
            if (!self::isDoubleQuotedInterpolationStart($tokens, $i, $closeIdx)) {
                continue;
            }
            $rewrite = self::tryRewriteEncapsedString($tokens, $i, $closeIdx, $code);
            if (null === $rewrite) {
                $i = $closeIdx;
                continue;
            }
            $replacements[] = $rewrite;
            $i = $closeIdx;
        }

        if ([] === $replacements) {
            return $code;
        }

        \usort($replacements, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
        foreach ($replacements as $replacement) {
            $code = \substr($code, 0, $replacement['start'])
                .$replacement['text']
                .\substr($code, $replacement['end']);
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
     *
     * @return array{start: int, end: int, text: string}|null
     */
    private static function tryRewriteEncapsedString(array $tokens, int $openIdx, int $closeIdx, string $code): ?array
    {
        $parts = [];
        $hasCoalesce = false;

        for ($j = $openIdx + 1; $j < $closeIdx; ++$j) {
            $token = $tokens[$j];
            if (\is_array($token) && \T_ENCAPSED_AND_WHITESPACE === $token[0]) {
                $parts[] = ['literal', $token[1]];
                continue;
            }
            if (\is_array($token) && \T_VARIABLE === $token[0]) {
                $parts[] = ['expr', $token[1]];
                continue;
            }
            if (self::isCurlyOpen($token)) {
                $innerStart = $j + 1;
                $afterClose = self::consumeBalancedCurly($tokens, $j);
                $innerTokens = \array_slice($tokens, $innerStart, $afterClose - $innerStart - 1);
                if (self::containsTopLevelCoalesce($innerTokens)) {
                    $hasCoalesce = true;
                }
                $exprStart = self::tokenByteOffset($tokens, $innerStart);
                $exprEnd = self::tokenByteOffset($tokens, $afterClose - 1);
                if (null === $exprStart || null === $exprEnd) {
                    return null;
                }
                $parts[] = ['expr', \substr($code, $exprStart, $exprEnd - $exprStart)];
                $j = $afterClose - 1;
                continue;
            }
            if (\is_array($token) && \defined('T_DOLLAR_OPEN_CURLY_BRACES') && \T_DOLLAR_OPEN_CURLY_BRACES === $token[0]) {
                $parts[] = ['expr', $token[1]];
                continue;
            }

            return null;
        }

        if (!$hasCoalesce) {
            return null;
        }

        $exprs = [];
        foreach ($parts as $part) {
            if ('literal' === $part[0]) {
                if ('' !== $part[1]) {
                    $exprs[] = \var_export($part[1], true);
                }
                continue;
            }
            $exprs[] = '('.$part[1].')';
        }

        if ([] === $exprs) {
            return null;
        }

        $start = self::tokenByteOffset($tokens, $openIdx);
        $end = self::tokenByteOffset($tokens, $closeIdx + 1);
        if (null === $start || null === $end) {
            return null;
        }

        return [
            'start' => $start,
            'end' => $end,
            'text' => \implode(' . ', $exprs),
        ];
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
    private static function containsTopLevelCoalesce(array $tokens): bool
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
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function tokenByteOffset(array $tokens, int $index): ?int
    {
        if (!isset($tokens[$index])) {
            return null;
        }
        $offset = 0;
        for ($i = 0; $i < $index; ++$i) {
            $offset += \strlen(self::tokenText($tokens[$i]));
        }

        return $offset;
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function tokenText($token): string
    {
        return \is_array($token) ? $token[1] : $token;
    }
}
