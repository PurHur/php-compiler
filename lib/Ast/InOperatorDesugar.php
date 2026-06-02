<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

/**
 * Desugar PHP 8.3+ `$needle in $haystack` before nikic/php-parser (#4682).
 *
 * Rewrites to compile-time marker `__phpcLangIn($needle, $haystack)`; CFG visitor lowers to In_.
 * php-src: Zend/zend_compile.c — enum/array contains (`in` operator).
 */
final class InOperatorDesugar
{
    /** Same binding as `instanceof` (php.net operator precedence). */
    private const PREC_LHS_STOP = 28;

    private const MARKER = '__phpcLangIn';

    public static function desugar(string $code): string
    {
        if (!preg_match('/(?<![\w\$])in(?![\w\$])/i', $code)) {
            return $code;
        }

        for ($guard = 0; $guard < 512; ++$guard) {
            $tokens = token_get_all($code);
            $inIdx = self::findInTokenIndex($tokens);
            if (null === $inIdx) {
                break;
            }

            $lhs = self::extractLhs($code, $tokens, $inIdx);
            $rhs = self::extractRhs($code, $tokens, $inIdx + 1);
            if (null === $lhs || null === $rhs) {
                break;
            }

            $replacement = self::MARKER.'('.$lhs['text'].', '.$rhs['text'].')';
            $code = substr($code, 0, $lhs['start'])
                .$replacement
                .substr($code, $rhs['end']);
        }

        return $code;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function findInTokenIndex(array $tokens): ?int
    {
        for ($i = 0, $c = count($tokens); $i < $c; ++$i) {
            $token = $tokens[$i];
            if (!\is_array($token) || T_STRING !== $token[0]) {
                continue;
            }
            if ('in' !== $token[1]) {
                continue;
            }
            if ($i > 0 && self::isObjectOperator($tokens[$i - 1])) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isObjectOperator($token): bool
    {
        return \is_array($token)
            && \in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{start: int, end: int, text: string}|null
     */
    private static function extractLhs(string $code, array $tokens, int $inIdx): ?array
    {
        $endIdx = $inIdx - 1;
        while ($endIdx >= 0 && self::isIgnorable($tokens[$endIdx])) {
            --$endIdx;
        }
        if ($endIdx < 0) {
            return null;
        }

        $startIdx = self::scanLhsStart($tokens, $endIdx, self::PREC_LHS_STOP);
        if ($startIdx < 0) {
            return null;
        }

        $start = self::tokenByteOffset($tokens, $startIdx);
        $end = self::tokenByteEnd($tokens, $endIdx);
        if (null === $start || null === $end) {
            return null;
        }

        return [
            'start' => $start,
            'end' => $end,
            'text' => trim(substr($code, $start, $end - $start)),
        ];
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{start: int, end: int, text: string}|null
     */
    private static function extractRhs(string $code, array $tokens, int $afterInIdx): ?array
    {
        $startIdx = $afterInIdx;
        while ($startIdx < count($tokens) && self::isIgnorable($tokens[$startIdx])) {
            ++$startIdx;
        }
        if ($startIdx >= count($tokens)) {
            return null;
        }

        $endIdx = self::scanExprEnd($tokens, $startIdx, self::PREC_LHS_STOP);
        if (null === $endIdx) {
            return null;
        }

        $start = self::tokenByteOffset($tokens, $startIdx);
        $end = self::tokenByteEnd($tokens, $endIdx);
        if (null === $start || null === $end) {
            return null;
        }

        return [
            'start' => $start,
            'end' => $end,
            'text' => trim(substr($code, $start, $end - $start)),
        ];
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function scanLhsStart(array $tokens, int $endIdx, int $minPrec): int
    {
        $start = self::scanAtomStart($tokens, $endIdx);
        $pos = $start - 1;
        self::skipBackwardIgnorable($tokens, $pos);

        while ($pos >= 0) {
            $opPrec = self::infixPrecLeft($tokens[$pos]);
            if (null === $opPrec || $opPrec < $minPrec) {
                break;
            }

            --$pos;
            self::skipBackwardIgnorable($tokens, $pos);
            if ($pos < 0) {
                return 0;
            }

            $start = self::scanAtomStart($tokens, $pos);
            $pos = $start - 1;
            self::skipBackwardIgnorable($tokens, $pos);
        }

        return $start;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function scanExprEnd(array $tokens, int $startIdx, int $stopPrec): ?int
    {
        $pos = $startIdx;
        $endIdx = self::scanPrimaryForward($tokens, $pos);
        if (null === $endIdx) {
            return null;
        }
        $pos = $endIdx + 1;

        while ($pos < count($tokens)) {
            self::skipForwardIgnorable($tokens, $pos);
            if ($pos >= count($tokens)) {
                return $endIdx;
            }

            $token = $tokens[$pos];
            if (\is_string($token) && '[' === $token) {
                $pos = self::skipBalancedForward($tokens, $pos, '[', ']');
                if (null === $pos) {
                    return null;
                }
                $endIdx = $pos - 1;
                continue;
            }
            if (\is_string($token) && '(' === $token) {
                $pos = self::skipBalancedForward($tokens, $pos, '(', ')');
                if (null === $pos) {
                    return null;
                }
                $endIdx = $pos - 1;
                continue;
            }
            if (\is_array($token) && \in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_PAAMAYIM_NEKUDOTAYIM], true)) {
                ++$pos;
                self::skipForwardIgnorable($tokens, $pos);
                if ($pos >= count($tokens)) {
                    return null;
                }
                $endIdx = $pos;
                ++$pos;
                continue;
            }

            $opPrec = self::infixPrecLeft($token);
            if (null !== $opPrec && $opPrec <= $stopPrec) {
                break;
            }
            if (null !== $opPrec) {
                ++$pos;
                self::skipForwardIgnorable($tokens, $pos);
                if ($pos >= count($tokens)) {
                    return null;
                }
                $endIdx = self::scanPrimaryForward($tokens, $pos);
                if (null === $endIdx) {
                    return null;
                }
                $pos = $endIdx + 1;
                continue;
            }

            break;
        }

        return $endIdx;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function scanPrimaryForward(array $tokens, int $startIdx): ?int
    {
        $pos = $startIdx;
        while ($pos < count($tokens) && self::isIgnorable($tokens[$pos])) {
            ++$pos;
        }
        if ($pos >= count($tokens)) {
            return null;
        }

        $token = $tokens[$pos];
        if (\is_string($token) && '(' === $token) {
            $next = self::skipBalancedForward($tokens, $pos, '(', ')');

            return null === $next ? null : $next - 1;
        }
        if (\is_string($token) && '[' === $token) {
            $next = self::skipBalancedForward($tokens, $pos, '[', ']');

            return null === $next ? null : $next - 1;
        }
        if (\is_array($token) && \in_array($token[0], [
            T_VARIABLE, T_STRING, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER,
            T_ARRAY, T_NEW, T_CLONE, T_CLASS, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED,
            T_NAME_RELATIVE,
        ], true)) {
            return $pos;
        }

        return null;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipBalancedForward(array $tokens, int $openIdx, string $open, string $close): ?int
    {
        $depth = 0;
        for ($i = $openIdx; $i < count($tokens); ++$i) {
            $t = $tokens[$i];
            if (\is_string($t) && $open === $t) {
                ++$depth;
            } elseif (\is_string($t) && $close === $t) {
                --$depth;
                if (0 === $depth) {
                    return $i + 1;
                }
            } elseif (\is_array($t) && T_CURLY_OPEN === $t[0] && '(' === $open) {
                ++$depth;
            }
        }

        return null;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function scanAtomStart(array $tokens, int $endIdx): int
    {
        $pos = $endIdx;
        while ($pos >= 0) {
            self::skipBackwardIgnorable($tokens, $pos);
            if ($pos < 0) {
                return 0;
            }

            $token = $tokens[$pos];
            if (\is_string($token) && ')' === $token) {
                $depth = 1;
                --$pos;
                while ($pos >= 0 && $depth > 0) {
                    $t = $tokens[$pos];
                    if (\is_string($t) && '(' === $t) {
                        --$depth;
                    } elseif (\is_string($t) && ')' === $t) {
                        ++$depth;
                    } elseif (\is_array($t) && T_CURLY_OPEN === $t[0]) {
                        ++$depth;
                    }
                    if ($depth > 0) {
                        --$pos;
                    }
                }
                --$pos;
                self::skipBackwardIgnorable($tokens, $pos);
                if ($pos >= 0 && self::isCallCalleeToken($tokens[$pos])) {
                    return $pos;
                }
                continue;
            }

            if (\is_string($token) && ']' === $token) {
                $depth = 1;
                --$pos;
                while ($pos >= 0 && $depth > 0) {
                    $t = $tokens[$pos];
                    if (\is_string($t) && '[' === $t) {
                        --$depth;
                    } elseif (\is_string($t) && ']' === $t) {
                        ++$depth;
                    }
                    if ($depth > 0) {
                        --$pos;
                    }
                }
                continue;
            }

            if (\is_array($token) && \in_array($token[0], [
                T_VARIABLE, T_STRING, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER,
                T_ARRAY, T_NEW, T_CLONE, T_ECHO, T_PRINT, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED,
                T_NAME_RELATIVE,
            ], true)) {
                return $pos;
            }

            if (\is_string($token) && '(' === $token) {
                return $pos;
            }

            break;
        }

        return max(0, $pos);
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function isIgnorable($token): bool
    {
        if (!\is_array($token)) {
            return false;
        }

        return \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO], true);
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
    private static function tokenByteOffset(array $tokens, int $idx): ?int
    {
        if (!isset($tokens[$idx])) {
            return null;
        }
        $offset = 0;
        for ($i = 0; $i < $idx; ++$i) {
            $offset += \strlen(self::tokenText($tokens[$i]));
        }

        return $offset;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function tokenByteEnd(array $tokens, int $idx): ?int
    {
        $start = self::tokenByteOffset($tokens, $idx);
        if (null === $start) {
            return null;
        }

        return $start + \strlen(self::tokenText($tokens[$idx]));
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function isCallCalleeToken($token): bool
    {
        if (!\is_array($token)) {
            return false;
        }

        return \in_array($token[0], [
            T_STRING, T_VARIABLE, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED,
            T_NAME_RELATIVE,
        ], true);
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function infixPrecLeft($token): ?int
    {
        if (\is_array($token)) {
            return match ($token[0]) {
                T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_PAAMAYIM_NEKUDOTAYIM => 32,
                T_INSTANCEOF => 28,
                T_POW => 30,
                T_SL, T_SR => 20,
                T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_EQUAL, T_IS_NOT_EQUAL, T_SPACESHIP => 18,
                T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL => 18,
                T_BOOLEAN_AND, T_LOGICAL_AND => 14,
                T_BOOLEAN_OR, T_LOGICAL_OR => 13,
                T_COALESCE => 12,
                default => null,
            };
        }

        return match ($token) {
            '.' => 22,
            '+', '-' => 24,
            '*', '/', '%' => 26,
            '<', '>' => 18,
            '&' => 16,
            '^' => 15,
            '|' => 14,
            default => null,
        };
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipBackwardIgnorable(array $tokens, int &$pos): void
    {
        while ($pos >= 0 && self::isIgnorable($tokens[$pos])) {
            --$pos;
        }
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipForwardIgnorable(array $tokens, int &$pos): void
    {
        while ($pos < count($tokens) && self::isIgnorable($tokens[$pos])) {
            ++$pos;
        }
    }
}
