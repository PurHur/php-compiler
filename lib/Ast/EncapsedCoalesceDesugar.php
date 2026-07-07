<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PHPCompiler\CompilerVersion;

/**
 * Desugar PHP 8.4+ null coalesce inside double-quoted `{$...}` interpolation (#14063, #14113).
 *
 * nikic/php-parser (ONLY_PHP7) rejects `??` in encapsed variables; Zend 8.4 allows it.
 * Lower to string concat with statement temps for each `??` chunk so CFG merge blocks
 * keep coalesce results when multiple chunks participate in one concat (#14113).
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
        $tempCounter = 0;

        for ($i = 0, $c = \count($tokens); $i < $c; ++$i) {
            $closeIdx = null;
            if (!self::isDoubleQuotedStringStart($tokens, $i, $closeIdx)) {
                continue;
            }

            if (!self::encapsedStringHasCoalesce($tokens, $i, $closeIdx)) {
                $i = $closeIdx;
                continue;
            }

            $built = self::buildConcatExpression($tokens, $i, $closeIdx, $tempCounter);
            $tempCounter = $built['nextTemp'];
            $replacements[] = [
                'start' => $offsets[$i],
                'end' => $offsets[$closeIdx] + 1,
                'text' => $built['expr'],
                'prelude' => $built['prelude'],
            ];
            $i = $closeIdx;
        }

        if ([] === $replacements) {
            return $code;
        }

        usort($replacements, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $shift = 0;
        foreach ($replacements as $replacement) {
            $start = $replacement['start'] + $shift;
            $end = $replacement['end'] + $shift;
            if ([] !== $replacement['prelude']) {
                $lineStart = self::lineStartForOffset($code, $start);
                $preludeText = \implode("\n", $replacement['prelude'])."\n";
                $code = \substr($code, 0, $lineStart)
                    .$preludeText
                    .\substr($code, $lineStart);
                $preludeLen = \strlen($preludeText);
                if ($start >= $lineStart) {
                    $start += $preludeLen;
                    $end += $preludeLen;
                }
                $shift += $preludeLen;
            }
            $code = \substr($code, 0, $start)
                .$replacement['text']
                .\substr($code, $end);
            $shift += \strlen($replacement['text']) - ($end - $start);
        }

        return $code;
    }

    private static function lineStartForOffset(string $code, int $offset): int
    {
        $lineStart = \strrpos(\substr($code, 0, $offset), "\n");

        return false === $lineStart ? 0 : $lineStart + 1;
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
            if (\is_array($token) && \defined('T_DOLLAR_OPEN_CURLY_BRACES') && \T_DOLLAR_OPEN_CURLY_BRACES === $token[0]) {
                $block = self::consumeDollarCurlyInner($tokens, $j);
                if (null !== self::findTopLevelCoalesceLine($block['innerTokens'])) {
                    return true;
                }
                $j = $block['end'] - 1;
                continue;
            }
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
     *
     * @return array{expr: string, prelude: list<string>, nextTemp: int}
     */
    private static function buildConcatExpression(
        array $tokens,
        int $openIdx,
        int $closeIdx,
        int $tempCounter
    ): array {
        $parts = [];
        $prelude = [];
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
                $block = self::consumeDollarCurlyInner($tokens, $j);
                $innerTokens = $block['innerTokens'];
                $innerSource = self::dollarInnerTokensToExpression($innerTokens);
                if (null !== self::findTopLevelCoalesceLine($innerTokens)) {
                    $var = '$__encapsedCoalesce'.$tempCounter;
                    ++$tempCounter;
                    $prelude[] = $var.' = ('.$innerSource.');';
                    $parts[] = $var;
                } else {
                    $parts[] = $innerSource;
                }
                $j = $block['end'] - 1;
                continue;
            }
            if (self::isCurlyOpen($token)) {
                $innerStart = $j + 1;
                $afterClose = self::consumeBalancedCurly($tokens, $j);
                $innerTokens = \array_slice($tokens, $innerStart, $afterClose - $innerStart - 1);
                $innerSource = self::tokensToSource($innerTokens);
                if (null !== self::findTopLevelCoalesceLine($innerTokens)) {
                    $var = '$__encapsedCoalesce'.$tempCounter;
                    ++$tempCounter;
                    $prelude[] = $var.' = ('.$innerSource.');';
                    $parts[] = $var;
                } else {
                    $parts[] = '('.$innerSource.')';
                }
                $j = $afterClose - 1;
                continue;
            }
        }

        if ([] === $parts) {
            $expr = "''";
        } elseif (1 === \count($parts)) {
            $expr = $parts[0];
        } else {
            $expr = \implode(' . ', $parts);
        }

        return ['expr' => $expr, 'prelude' => $prelude, 'nextTemp' => $tempCounter];
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{end: int, innerTokens: array<int, array{0: int, 1: string, 2: int}|string>}
     */
    private static function consumeDollarCurlyInner(array $tokens, int $dollarIdx): array
    {
        $innerStart = $dollarIdx + 1;
        $depth = 0;
        $j = $innerStart;
        $c = \count($tokens);
        while ($j < $c) {
            $token = $tokens[$j];
            if (\is_string($token)) {
                if (\in_array($token, ['(', '[', '{'], true)) {
                    ++$depth;
                } elseif (']' === $token || ')' === $token) {
                    if ($depth > 0) {
                        --$depth;
                    }
                } elseif ('}' === $token) {
                    if (0 === $depth) {
                        break;
                    }
                    --$depth;
                }
            }
            ++$j;
        }

        return [
            'end' => $j + 1,
            'innerTokens' => \array_slice($tokens, $innerStart, $j - $innerStart),
        ];
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $innerTokens
     */
    private static function dollarInnerTokensToExpression(array $innerTokens): string
    {
        if ([] === $innerTokens) {
            return "''";
        }
        $first = $innerTokens[0];
        if (\is_array($first) && (\T_STRING_VARNAME === $first[0] || \T_STRING === $first[0])) {
            $expr = '$'.$first[1];
            if (\count($innerTokens) > 1) {
                $expr .= self::tokensToSource(\array_slice($innerTokens, 1));
            }

            return $expr;
        }

        return '('.self::tokensToSource($innerTokens).')';
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
