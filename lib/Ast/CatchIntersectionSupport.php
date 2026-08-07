<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ReferenceProfileTokenScan;

/**
 * Reject (and historically rewrite) catch intersection types (#28439 / #28205).
 *
 * php-src `catch_name_list` is union-only (`|`); intersection `&` and parenthesized
 * catch types are ParseErrors. php-parser 4.x agrees; an earlier rewrite path that
 * accepted `A&B` under PROFILE≥8.1 was inverted vs Zend and is gated off.
 *
 * php-src: Zend/zend_language_parser.y catch_name_list.
 */
final class CatchIntersectionSupport
{
    public const ATTRIBUTE = 'compilerCatchIntersection';

    /** Zend-shaped message when `&` appears in a catch type list. */
    public const REFERENCE_PROFILE_UNEXPECTED_AMPERSAND =
        'syntax error, unexpected token "&", expecting ")"';

    /** Zend-shaped message when `(` appears in a catch type list (`catch ((A&B) $e)`). */
    public const REFERENCE_PROFILE_UNEXPECTED_PAREN =
        'syntax error, unexpected token "(", expecting ")"';

    /** Mixing `|` and `&` in one catch type list is a parse error in php-src. */
    public const MIXED_UNION_INTERSECTION_MESSAGE =
        'syntax error, unexpected token "&", expecting ")"';

    /** @var list<bool> FIFO: true when the next Catch_ was rewritten from intersection */
    private static array $pendingIntersectionFlags = [];

    public static function beginCompilationUnit(): void
    {
        self::$pendingIntersectionFlags = [];
    }

    public static function takeNextIntersectionFlag(): bool
    {
        if ([] === self::$pendingIntersectionFlags) {
            return false;
        }

        return (bool) array_shift(self::$pendingIntersectionFlags);
    }

    /**
     * @return array{line: int, message: string}|null
     */
    public static function referenceProfileSyntaxError(string $code): ?array
    {
        if (ReferenceProfileTokenScan::exceedsTokenScanBudget($code)) {
            return null;
        }
        if (!preg_match('/\bcatch\b/i', $code)) {
            return null;
        }

        $tokens = token_get_all($code);
        foreach (self::findCatchTypeSpans($tokens) as $span) {
            $hasAnd = false;
            $hasOr = false;
            for ($i = $span['open'] + 1; $i < $span['close']; ++$i) {
                $text = self::tokenText($tokens[$i]);
                // Nested `(` is never legal in catch_name_list (Zend: unexpected "(").
                if ('(' === $text) {
                    return [
                        'line' => self::tokenLineAt($tokens, $i),
                        'message' => self::REFERENCE_PROFILE_UNEXPECTED_PAREN,
                    ];
                }
                if ('&' === $text) {
                    $hasAnd = true;
                } elseif ('|' === $text) {
                    $hasOr = true;
                }
            }
            if (!$hasAnd) {
                continue;
            }
            // php-src never accepts `&` in catch lists (#28439).
            if ($hasOr || !CompilerVersion::supportsCatchIntersectionTypes()) {
                return [
                    'line' => self::tokenLineAt($tokens, $span['open']),
                    'message' => $hasOr
                        ? self::MIXED_UNION_INTERSECTION_MESSAGE
                        : self::REFERENCE_PROFILE_UNEXPECTED_AMPERSAND,
                ];
            }
        }

        return null;
    }

    /**
     * Rewrite `catch (A&B $e)` → `catch (A|B $e)` and queue intersection flags.
     *
     * No-op while {@see CompilerVersion::supportsCatchIntersectionTypes()} is false (#28439).
     */
    public static function rewrite(string $code): string
    {
        if (!CompilerVersion::supportsCatchIntersectionTypes()) {
            return $code;
        }
        if (!str_contains($code, '&') || !preg_match('/\bcatch\b/i', $code)) {
            return $code;
        }

        $tokens = token_get_all($code);
        $spans = self::findCatchTypeSpans($tokens);
        if ([] === $spans) {
            return $code;
        }

        // Rebuild from tokens so byte offsets stay consistent; process left-to-right.
        $out = '';
        $i = 0;
        $n = \count($tokens);
        $spanIdx = 0;
        $spanCount = \count($spans);

        while ($i < $n) {
            if ($spanIdx < $spanCount && $i === $spans[$spanIdx]['open']) {
                $span = $spans[$spanIdx];
                ++$spanIdx;
                $hasAnd = false;
                $hasOr = false;
                for ($j = $span['open'] + 1; $j < $span['close']; ++$j) {
                    $text = self::tokenText($tokens[$j]);
                    if ('&' === $text) {
                        $hasAnd = true;
                    } elseif ('|' === $text) {
                        $hasOr = true;
                    }
                }
                // Mixed union/intersection: leave source alone; rejector already ran.
                if ($hasAnd && !$hasOr) {
                    self::$pendingIntersectionFlags[] = true;
                    $out .= '(';
                    for ($j = $span['open'] + 1; $j < $span['close']; ++$j) {
                        $text = self::tokenText($tokens[$j]);
                        $out .= '&' === $text ? '|' : $text;
                    }
                    $out .= ')';
                    $i = $span['close'] + 1;
                    continue;
                }
                self::$pendingIntersectionFlags[] = false;
            }
            $out .= self::tokenText($tokens[$i]);
            ++$i;
        }

        return $out;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<array{open: int, close: int}>
     */
    private static function findCatchTypeSpans(array $tokens): array
    {
        $spans = [];
        $n = \count($tokens);
        for ($i = 0; $i < $n; ++$i) {
            if (!\is_array($tokens[$i]) || T_CATCH !== $tokens[$i][0]) {
                continue;
            }
            $open = self::skipIgnorable($tokens, $i + 1, $n);
            if ($open >= $n || '(' !== self::tokenText($tokens[$open])) {
                continue;
            }
            $close = self::findMatchingCloseParen($tokens, $open);
            if (null === $close) {
                continue;
            }
            $spans[] = ['open' => $open, 'close' => $close];
        }

        return $spans;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function findMatchingCloseParen(array $tokens, int $open): ?int
    {
        $n = \count($tokens);
        $depth = 0;
        for ($i = $open; $i < $n; ++$i) {
            $text = self::tokenText($tokens[$i]);
            if ('(' === $text) {
                ++$depth;
            } elseif (')' === $text) {
                --$depth;
                if (0 === $depth) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipIgnorable(array $tokens, int $i, int $n): int
    {
        while ($i < $n) {
            $tok = $tokens[$i];
            if (\is_array($tok) && self::isIgnorable($tok[0])) {
                ++$i;
                continue;
            }
            break;
        }

        return $i;
    }

    private static function isIgnorable(int $id): bool
    {
        return \in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $tok
     */
    private static function tokenText($tok): string
    {
        return \is_array($tok) ? $tok[1] : $tok;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function tokenLineAt(array $tokens, int $index): int
    {
        for ($i = $index; $i >= 0; --$i) {
            if (\is_array($tokens[$i])) {
                return $tokens[$i][2];
            }
        }

        return 1;
    }
}
