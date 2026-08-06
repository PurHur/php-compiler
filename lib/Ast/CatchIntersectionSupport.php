<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ReferenceProfileTokenScan;

/**
 * Rewrite catch intersection types for nikic/php-parser 4.x (#28205).
 *
 * php-src accepts `catch (Countable&Throwable $e)` since intersection types (PHP 8.1);
 * php-parser 4.x only allows `|` in catch type lists. Rewrite `&` → `|` and mark the
 * Catch_ node so php-cfg / TYPE_CATCH encode `a&b` (all must match) rather than `a|b`.
 *
 * php-src: Zend/zend_language_parser.y catch_list; Zend/zend_compile.c zend_compile_try.
 */
final class CatchIntersectionSupport
{
    public const ATTRIBUTE = 'compilerCatchIntersection';

    /** Zend-shaped message when intersection catch is used below PHP 8.1. */
    public const REFERENCE_PROFILE_UNEXPECTED_AMPERSAND =
        'syntax error, unexpected token "&", expecting ")"';

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
        if (!str_contains($code, '&') || !preg_match('/\bcatch\b/i', $code)) {
            return null;
        }

        $tokens = token_get_all($code);
        foreach (self::findCatchTypeSpans($tokens) as $span) {
            $hasAnd = false;
            $hasOr = false;
            for ($i = $span['open'] + 1; $i < $span['close']; ++$i) {
                $text = self::tokenText($tokens[$i]);
                if ('&' === $text) {
                    $hasAnd = true;
                } elseif ('|' === $text) {
                    $hasOr = true;
                }
            }
            if (!$hasAnd) {
                continue;
            }
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
