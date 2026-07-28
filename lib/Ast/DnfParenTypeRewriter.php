<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ReferenceProfileTokenScan;

/**
 * Unwrap parenthesized DNF type leaves for nikic/php-parser 4.x (#9733, #3094).
 *
 * php-src accepts `(I1&I2) $param` and `function f(): (I1&I2)`; php-parser 4.x rejects
 * parenthesized intersection-only leaves unless followed by `|` or `&`.
 * Parenthesized union-only leaves such as `(A|B) $param` are a Zend parse error (#9968) —
 * do not unwrap them into `A|B`.
 * `(I1&I2)|null`, `A|(B&C)`, and `(A|B)&C` must keep their parens — only unwrap bare
 * intersection-only leaves when the closing `)` is not part of a larger DNF type.
 * php-parser accepts `A|(B&C)` but not the unwrapped `A|B&C` (#11745).
 * Expression `(Name & Name)` / call-arg `foo(Name & Name)` must not be treated as types (#24131).
 *
 * php-src: Zend/zend_compile.c — zend_compile_type / DNF normalization.
 */
final class DnfParenTypeRewriter
{
    /**
     * @return array{line: int, message: string}|null
     */
    public static function referenceProfileSyntaxError(string $source): ?array
    {
        if (ReferenceProfileTokenScan::exceedsTokenScanBudget($source)) {
            return null;
        }
        if (!str_contains($source, '(')) {
            return null;
        }

        $tokens = token_get_all($source);
        $n = \count($tokens);
        $i = 0;

        while ($i < $n) {
            $tok = $tokens[$i];
            if ('(' !== self::tokenText($tok)) {
                ++$i;
                continue;
            }

            $close = self::findMatchingCloseParen($tokens, $i);
            if (null === $close) {
                ++$i;
                continue;
            }

            $inner = \array_slice($tokens, $i + 1, $close - $i - 1);
            if (!self::isTypeExpressionTokens($inner) || !self::hasTopLevelIntersection($inner)) {
                ++$i;
                continue;
            }

            $after = self::skipIgnorable($tokens, $close + 1, $n);
            if ($after < $n && ('|' === self::tokenText($tokens[$after]) || '&' === self::tokenText($tokens[$after]))) {
                ++$i;
                continue;
            }

            if (self::isPrecededByTypeUnionOperator($tokens, $i)) {
                ++$i;
                continue;
            }

            if (self::isCatchTypeParenContext($tokens, $i)) {
                ++$i;
                continue;
            }

            // `(Name & Name)` is a valid bitwise expr; only type-position leaves are 8.3+ (#24131).
            if (!self::isTypePositionIntersectionParen($tokens, $i, $close)) {
                ++$i;
                continue;
            }

            $unexpected = self::unexpectedTokenAfterIntersectionParen($tokens, $after, $n);

            return [
                'line' => self::tokenLineAt($tokens, $unexpected['index']),
                'message' => $unexpected['message'],
            ];
        }

        return null;
    }

    public static function rewrite(string $source): string
    {
        if (!CompilerVersion::supportsParenthesizedDnfIntersectionTypes()) {
            return $source;
        }
        if (!str_contains($source, '(')) {
            return $source;
        }

        do {
            $prev = $source;
            $source = self::rewriteOnce($source);
        } while ($source !== $prev);

        return $source;
    }

    private static function rewriteOnce(string $source): string
    {
        $tokens = token_get_all($source);
        $n = \count($tokens);
        $out = '';
        $i = 0;

        while ($i < $n) {
            $tok = $tokens[$i];
            if ('(' !== self::tokenText($tok)) {
                $out .= self::tokenText($tok);
                ++$i;
                continue;
            }

            $close = self::findMatchingCloseParen($tokens, $i);
            if (null === $close) {
                $out .= self::tokenText($tok);
                ++$i;
                continue;
            }

            $inner = \array_slice($tokens, $i + 1, $close - $i - 1);
            if (!self::isTypeExpressionTokens($inner) || !self::hasTopLevelIntersection($inner)) {
                $out .= self::tokenText($tok);
                ++$i;
                continue;
            }

            $after = self::skipIgnorable($tokens, $close + 1, $n);
            if ($after < $n && ('|' === self::tokenText($tokens[$after]) || '&' === self::tokenText($tokens[$after]))) {
                $out .= self::tokenText($tok);
                ++$i;
                continue;
            }

            // Union RHS intersection arms: `A|(B&C)` must keep parens — unwrapping breaks php-parser (#11745).
            if (self::isPrecededByTypeUnionOperator($tokens, $i)) {
                $out .= self::tokenText($tok);
                ++$i;
                continue;
            }

            // catch (A|B) requires parens for php-parser; unwrap breaks non-capturing union (#9766).
            if (self::isCatchTypeParenContext($tokens, $i)) {
                $out .= self::tokenText($tok);
                ++$i;
                continue;
            }

            // Do not unwrap expr parens / call args: `(E_ERROR & E_WARNING)` (#24131).
            if (!self::isTypePositionIntersectionParen($tokens, $i, $close)) {
                $out .= self::tokenText($tok);
                ++$i;
                continue;
            }

            foreach ($inner as $innerTok) {
                $out .= self::tokenText($innerTok);
            }
            $i = $close + 1;
        }

        return $out;
    }

    /**
     * Parenthesized intersection leaves only in type positions (param/property/return/const).
     * Expression forms like `echo (E_ERROR & E_WARNING)` and `foo(A & B)` must stay exprs (#24131).
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isTypePositionIntersectionParen(array $tokens, int $open, int $close): bool
    {
        $n = \count($tokens);
        $after = self::skipIgnorable($tokens, $close + 1, $n);
        if ($after < $n && \is_array($tokens[$after]) && T_VARIABLE === $tokens[$after][0]) {
            // `(I1&I2) $param` / `public (I1&I2) $prop`
            return true;
        }

        $before = self::previousNonIgnorableIndex($tokens, $open - 1);
        if (null === $before) {
            return false;
        }

        // Typed class const: `const (I1&I2) NAME = …` (PHP 8.3+).
        if ($after < $n && \is_array($tokens[$after])
            && \in_array($tokens[$after][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)
            && \is_array($tokens[$before]) && T_CONST === $tokens[$before][0]
        ) {
            return true;
        }

        // Return type: `function f(): (I1&I2)` / `fn(): (I1&I2) =>` — not ternary `$a ? ($b) : (X&Y)`.
        if (':' !== self::tokenText($tokens[$before])) {
            return false;
        }
        $beforeColon = self::previousNonIgnorableIndex($tokens, $before - 1);
        if (null === $beforeColon || ')' !== self::tokenText($tokens[$beforeColon])) {
            // Named arg `foo(bar: (A&B))` — expression.
            return false;
        }

        return self::closesFunctionLikeParamList($tokens, $beforeColon);
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function previousNonIgnorableIndex(array $tokens, int $from): ?int
    {
        for ($i = $from; $i >= 0; --$i) {
            $tok = $tokens[$i];
            if (\is_array($tok) && self::isIgnorable($tok[0])) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * True when `$closeParen` closes `function`/`fn`/method parameter list (return-type `):`).
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function closesFunctionLikeParamList(array $tokens, int $closeParen): bool
    {
        $open = self::findMatchingOpenParen($tokens, $closeParen);
        if (null === $open) {
            return false;
        }

        $i = self::previousNonIgnorableIndex($tokens, $open - 1);
        while (null !== $i) {
            $tok = $tokens[$i];
            $text = self::tokenText($tok);
            if ('&' === $text) {
                // `function &(): (A&B)` by-ref return function.
                $i = self::previousNonIgnorableIndex($tokens, $i - 1);
                continue;
            }
            if (!\is_array($tok)) {
                return false;
            }
            if (\in_array($tok[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                // Method/function name before `(params)`.
                $i = self::previousNonIgnorableIndex($tokens, $i - 1);
                continue;
            }
            if (\in_array($tok[0], [
                T_PUBLIC, T_PROTECTED, T_PRIVATE, T_FINAL, T_ABSTRACT, T_STATIC, T_READONLY,
            ], true)) {
                $i = self::previousNonIgnorableIndex($tokens, $i - 1);
                continue;
            }

            return T_FUNCTION === $tok[0] || T_FN === $tok[0];
        }

        return false;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function findMatchingOpenParen(array $tokens, int $close): ?int
    {
        $depth = 0;
        for ($i = $close; $i >= 0; --$i) {
            $text = self::tokenText($tokens[$i]);
            if (')' === $text) {
                ++$depth;
            } elseif ('(' === $text) {
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
    private static function isPrecededByTypeUnionOperator(array $tokens, int $open): bool
    {
        for ($i = $open - 1; $i >= 0; --$i) {
            $tok = $tokens[$i];
            if (\is_array($tok) && self::isIgnorable($tok[0])) {
                continue;
            }

            return '|' === self::tokenText($tok);
        }

        return false;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isCatchTypeParenContext(array $tokens, int $open): bool
    {
        for ($i = $open - 1; $i >= 0; --$i) {
            $tok = $tokens[$i];
            if (\is_array($tok) && self::isIgnorable($tok[0])) {
                continue;
            }

            return \is_array($tok) && T_CATCH === $tok[0];
        }

        return false;
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
    private static function isTypeExpressionTokens(array $tokens): bool
    {
        if ([] === $tokens) {
            return false;
        }

        foreach ($tokens as $tok) {
            if (!\is_array($tok)) {
                continue;
            }
            if (\in_array($tok[0], [
                T_VARIABLE,
                T_NEW,
                T_ECHO,
                T_PRINT,
                T_INCLUDE,
                T_INCLUDE_ONCE,
                T_REQUIRE,
                T_REQUIRE_ONCE,
                T_LNUMBER,
                T_DNUMBER,
                T_CONSTANT_ENCAPSED_STRING,
                T_DOUBLE_ARROW,
                T_OBJECT_OPERATOR,
                T_NULLSAFE_OBJECT_OPERATOR,
                T_INSTANCEOF,
            ], true)) {
                return false;
            }
        }

        $end = self::parseTypeExpression($tokens, 0, \count($tokens));
        if (null === $end) {
            return false;
        }

        return $end === self::skipIgnorable($tokens, $end, \count($tokens));
    }

    /**
     * Intersection-only parenthesized leaves contain a top-level `&` (#9733).
     * Union-only `(A|B)` leaves are rejected by php-parser when left unwrapped (#9968).
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function hasTopLevelIntersection(array $tokens): bool
    {
        $depth = 0;
        foreach ($tokens as $tok) {
            $text = self::tokenText($tok);
            if ('(' === $text) {
                ++$depth;
                continue;
            }
            if (')' === $text) {
                --$depth;
                continue;
            }
            if (0 === $depth && '&' === $text) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function parseTypeExpression(array $tokens, int $i, int $n): ?int
    {
        $i = self::skipIgnorable($tokens, $i, $n);
        if ($i >= $n) {
            return null;
        }

        if ('?' === self::tokenText($tokens[$i])) {
            ++$i;
            $i = self::skipIgnorable($tokens, $i, $n);
        }

        if ($i >= $n) {
            return null;
        }

        if ('(' === self::tokenText($tokens[$i])) {
            $close = self::findMatchingCloseParen($tokens, $i);
            if (null === $close) {
                return null;
            }
            $i = $close + 1;
        } else {
            if (!self::isAtomicType($tokens, $i)) {
                return null;
            }
            ++$i;
        }

        while ($i < $n) {
            $i = self::skipIgnorable($tokens, $i, $n);
            if ($i >= $n) {
                break;
            }
            $ch = self::tokenText($tokens[$i]);
            if ('|' !== $ch && '&' !== $ch) {
                break;
            }
            ++$i;
            $i = self::skipIgnorable($tokens, $i, $n);
            if ($i >= $n) {
                return null;
            }
            if ('(' === self::tokenText($tokens[$i])) {
                $close = self::findMatchingCloseParen($tokens, $i);
                if (null === $close) {
                    return null;
                }
                $i = $close + 1;
            } else {
                if (!self::isAtomicType($tokens, $i)) {
                    return null;
                }
                ++$i;
            }
        }

        return $i;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isAtomicType(array $tokens, int $i): bool
    {
        $tok = $tokens[$i];
        if (!\is_array($tok)) {
            return false;
        }

        if (\in_array($tok[0], [T_ARRAY, T_CALLABLE, T_LIST, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
            if (T_ARRAY === $tok[0] || T_LIST === $tok[0]) {
                $j = self::skipIgnorable($tokens, $i + 1, \count($tokens));
                if ($j < \count($tokens) && '<' === self::tokenText($tokens[$j])) {
                    return true;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipIgnorable(array $tokens, int $start, int $n): int
    {
        for ($i = $start; $i < $n; ++$i) {
            $tok = $tokens[$i];
            if (\is_array($tok) && self::isIgnorable($tok[0])) {
                continue;
            }

            return $i;
        }

        return $n;
    }

    private static function isIgnorable(int $id): bool
    {
        return \in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $tok
     */
    private static function tokenText(array|string $tok): string
    {
        return \is_array($tok) ? $tok[1] : $tok;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{index: int, message: string}
     */
    private static function unexpectedTokenAfterIntersectionParen(array $tokens, int $after, int $n): array
    {
        $index = self::skipIgnorable($tokens, $after, $n);
        if ($index >= $n) {
            return [
                'index' => max(0, $n - 1),
                'message' => 'syntax error, unexpected end of file, expecting "|"',
            ];
        }

        $tok = $tokens[$index];
        if (\is_array($tok) && T_VARIABLE === $tok[0]) {
            return [
                'index' => $index,
                'message' => sprintf('syntax error, unexpected variable "%s", expecting "|"', $tok[1]),
            ];
        }

        $text = self::tokenText($tok);
        if ('{' === $text) {
            return [
                'index' => $index,
                'message' => 'syntax error, unexpected token "{", expecting "|"',
            ];
        }

        return [
            'index' => $index,
            'message' => sprintf('syntax error, unexpected token "%s", expecting "|"', $text),
        ];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function tokenLineAt(array $tokens, int $index): int
    {
        for ($i = $index; $i >= 0; --$i) {
            $tok = $tokens[$i];
            if (\is_array($tok)) {
                return max(1, $tok[2]);
            }
        }

        return 1;
    }
}
