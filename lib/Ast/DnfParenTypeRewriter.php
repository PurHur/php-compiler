<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

/**
 * Unwrap parenthesized DNF type leaves for nikic/php-parser 4.x (#9733, #3094).
 *
 * php-src accepts `(I1&I2) $param` and `function f(): (I1&I2)`; php-parser 4.x rejects
 * parenthesized intersection-only (and union-only) leaves unless followed by `|`.
 * `(I1&I2)|null` must keep its parens — only unwrap when the closing `)` is not part of
 * a union continuation.
 *
 * php-src: Zend/zend_compile.c — zend_compile_type / DNF normalization.
 */
final class DnfParenTypeRewriter
{
    public static function rewrite(string $source): string
    {
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
            if (!self::isTypeExpressionTokens($inner) || !self::hasTopLevelUnionOrIntersection($inner)) {
                $out .= self::tokenText($tok);
                ++$i;
                continue;
            }

            $after = self::skipIgnorable($tokens, $close + 1, $n);
            if ($after < $n && '|' === self::tokenText($tokens[$after])) {
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

            foreach ($inner as $innerTok) {
                $out .= self::tokenText($innerTok);
            }
            $i = $close + 1;
        }

        return $out;
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
     * DNF parenthesized leaves always contain a top-level `&` or `|` (#9733).
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function hasTopLevelUnionOrIntersection(array $tokens): bool
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
            if (0 === $depth && ('|' === $text || '&' === $text)) {
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
}
