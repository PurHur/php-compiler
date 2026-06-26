<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

/**
 * Desugar PHP 8.4+ pipe operator (|>) before nikic/php-parser (#3243, #7219).
 *
 * Lowering matches Zend: $lhs |> $callable(...) becomes $callable($lhs, ...).
 * php-src: Zend/zend_compile.c pipe expression handling.
 */
final class PipeOperatorDesugar
{
    /** Pipe binds tighter than comparison (php.net operator precedence). */
    private const PREC_LHS_STOP = 18;

    public static function desugar(string $code): string
    {
        if (!str_contains($code, '|>')) {
            return $code;
        }

        for ($guard = 0; $guard < 512 && str_contains($code, '|>'); ++$guard) {
            $tokens = token_get_all($code);
            $pipeIdx = self::findPipeTokenIndex($tokens);
            if (null === $pipeIdx) {
                break;
            }

            $pipeSpan = self::pipeSpan($tokens, $pipeIdx);
            $lhs = self::extractLhs($code, $tokens, $pipeIdx);
            $rhs = self::extractRhs($code, $tokens, $pipeSpan['endIdx']);
            if (null === $lhs || null === $rhs) {
                break;
            }

            $replacement = self::rewritePipe($lhs['text'], $rhs['text']);
            $code = substr($code, 0, $lhs['start'])
                . $replacement
                . substr($code, $rhs['end']);
        }

        return $code;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function findPipeTokenIndex(array $tokens): ?int
    {
        for ($i = 0, $c = count($tokens); $i < $c; ++$i) {
            if (self::isPipeAt($tokens, $i)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{startIdx: int, endIdx: int}
     */
    private static function pipeSpan(array $tokens, int $pipeIdx): array
    {
        if (self::isSinglePipeToken($tokens, $pipeIdx)) {
            return ['startIdx' => $pipeIdx, 'endIdx' => $pipeIdx];
        }

        return ['startIdx' => $pipeIdx, 'endIdx' => $pipeIdx + 1];
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isPipeAt(array $tokens, int $i): bool
    {
        if (!isset($tokens[$i])) {
            return false;
        }
        $t = $tokens[$i];
        if (\is_array($t) && \defined('T_PIPE') && \T_PIPE === $t[0]) {
            return true;
        }
        if (\is_string($t) && '|' === $t && isset($tokens[$i + 1]) && \is_string($tokens[$i + 1]) && '>' === $tokens[$i + 1]) {
            return true;
        }

        return false;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isSinglePipeToken(array $tokens, int $i): bool
    {
        $t = $tokens[$i];

        return \is_array($t) && \defined('T_PIPE') && \T_PIPE === $t[0];
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{start: int, end: int, text: string}|null
     */
    private static function extractLhs(string $code, array $tokens, int $pipeIdx): ?array
    {
        $endIdx = $pipeIdx - 1;
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
            'startIdx' => $startIdx,
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
                    } elseif (\is_array($t) && \T_CURLY_OPEN === $t[0]) {
                        ++$depth;
                    }
                    if ($depth > 0) {
                        --$pos;
                    }
                }
                $openIdx = $pos;
                --$pos;
                self::skipBackwardIgnorable($tokens, $pos);
                if ($pos >= 0 && self::isCallCalleeToken($tokens[$pos])) {
                    return $pos;
                }
                // Parenthesized callee: (expr)(args) — e.g. (fn($x) => $x + 1)(3) (#7219).
                if ($pos >= 0 && \is_string($tokens[$pos]) && ')' === $tokens[$pos]) {
                    return self::scanAtomStart($tokens, $pos);
                }

                return $openIdx;
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
                \T_VARIABLE, \T_STRING, \T_CONSTANT_ENCAPSED_STRING, \T_LNUMBER, \T_DNUMBER,
                \T_ARRAY, \T_NEW, \T_CLONE,
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
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{start: int, end: int, endIdx: int, text: string}|null
     */
    private static function extractRhs(string $code, array $tokens, int $afterPipeIdx): ?array
    {
        $startIdx = $afterPipeIdx + 1;
        while ($startIdx < count($tokens) && self::isIgnorable($tokens[$startIdx])) {
            ++$startIdx;
        }
        if ($startIdx >= count($tokens)) {
            return null;
        }

        if (isset($tokens[$startIdx]) && \is_array($tokens[$startIdx]) && \T_FN === $tokens[$startIdx][0]) {
            $endIdx = self::scanArrowFunctionForward($tokens, $startIdx);
            if (null === $endIdx) {
                return null;
            }
            $endIdx = self::extendWithTrailingEmptyInvoke($tokens, $endIdx);

            $start = self::tokenByteOffset($tokens, $startIdx);
            $end = self::tokenByteEnd($tokens, $endIdx);
            if (null === $start || null === $end) {
                return null;
            }

            return [
                'start' => $start,
                'end' => $end,
                'endIdx' => $endIdx,
                'text' => trim(substr($code, $start, $end - $start)),
            ];
        }

        $endIdx = self::scanCallLikeForward($tokens, $startIdx);
        if (null === $endIdx) {
            $endIdx = self::scanBareCallableForward($tokens, $startIdx);
        }
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
            'endIdx' => $endIdx,
            'text' => trim(substr($code, $start, $end - $start)),
        ];
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function tokenText($token): string
    {
        return \is_array($token) ? $token[1] : $token;
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function isIgnorable($token): bool
    {
        if (!\is_array($token)) {
            return false;
        }

        return \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT, \T_OPEN_TAG, \T_OPEN_TAG_WITH_ECHO], true);
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
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function scanCallLikeForward(array $tokens, int $startIdx): ?int
    {
        $pos = $startIdx;
        $parenIdx = null;

        while ($pos < count($tokens)) {
            if (self::isIgnorable($tokens[$pos])) {
                ++$pos;
                continue;
            }

            $token = $tokens[$pos];
            if (\is_string($token) && '(' === $token) {
                $parenIdx = $pos;
                break;
            }

            if (\is_array($token) && \in_array($token[0], [
                \T_STRING, \T_VARIABLE, \T_NS_SEPARATOR, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED,
                \T_NAME_RELATIVE, \T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR,
                \T_PAAMAYIM_NEKUDOTAYIM,
            ], true)) {
                ++$pos;
                continue;
            }

            return null;
        }

        if (null === $parenIdx) {
            return null;
        }

        $depth = 0;
        $end = $parenIdx;
        for ($i = $parenIdx; $i < count($tokens); ++$i) {
            $t = $tokens[$i];
            if (\is_string($t) && '(' === $t) {
                ++$depth;
            } elseif (\is_string($t) && ')' === $t) {
                --$depth;
                if (0 === $depth) {
                    $end = $i;
                    break;
                }
            } elseif (\is_array($t) && \T_CURLY_OPEN === $t[0]) {
                ++$depth;
            }
        }

        if (0 !== $depth) {
            return null;
        }

        // Parenthesized callee immediately invoked: (fn($x) => ...)( ) (#7244).
        $invokeStart = $end + 1;
        while ($invokeStart < \count($tokens) && self::isIgnorable($tokens[$invokeStart])) {
            ++$invokeStart;
        }
        if ($invokeStart < \count($tokens) && \is_string($tokens[$invokeStart]) && '(' === $tokens[$invokeStart]) {
            $invokeDepth = 0;
            for ($j = $invokeStart; $j < \count($tokens); ++$j) {
                $it = $tokens[$j];
                if (\is_string($it) && '(' === $it) {
                    ++$invokeDepth;
                } elseif (\is_string($it) && ')' === $it) {
                    --$invokeDepth;
                    if (0 === $invokeDepth) {
                        $end = $j;
                        break;
                    }
                } elseif (\is_array($it) && \T_CURLY_OPEN === $it[0]) {
                    ++$invokeDepth;
                }
            }
        }

        return $end;
    }

    /**
     * Bare callable reference without argument list: $lhs |> strlen → strlen($lhs).
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function scanBareCallableForward(array $tokens, int $startIdx): ?int
    {
        $pos = $startIdx;
        $sawCallable = false;

        while ($pos < \count($tokens)) {
            if (self::isIgnorable($tokens[$pos])) {
                ++$pos;
                continue;
            }

            $token = $tokens[$pos];
            if (\is_array($token) && \in_array($token[0], [
                \T_STRING, \T_VARIABLE, \T_NS_SEPARATOR, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED,
                \T_NAME_RELATIVE, \T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR,
                \T_PAAMAYIM_NEKUDOTAYIM,
            ], true)) {
                $sawCallable = true;
                ++$pos;
                continue;
            }

            if (\is_string($token) && '(' === $token) {
                return null;
            }

            break;
        }

        if (!$sawCallable) {
            return null;
        }

        $end = $pos - 1;
        while ($end >= $startIdx && self::isIgnorable($tokens[$end])) {
            --$end;
        }

        return $end >= $startIdx ? $end : null;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function scanArrowFunctionForward(array $tokens, int $startIdx): ?int
    {
        if (!isset($tokens[$startIdx]) || !\is_array($tokens[$startIdx]) || \T_FN !== $tokens[$startIdx][0]) {
            return null;
        }

        $pos = $startIdx + 1;
        while ($pos < \count($tokens) && self::isIgnorable($tokens[$pos])) {
            ++$pos;
        }
        if ($pos >= \count($tokens) || !\is_string($tokens[$pos]) || '(' !== $tokens[$pos]) {
            return null;
        }

        $depth = 0;
        for ($i = $pos; $i < \count($tokens); ++$i) {
            $t = $tokens[$i];
            if (\is_string($t) && '(' === $t) {
                ++$depth;
            } elseif (\is_string($t) && ')' === $t) {
                --$depth;
                if (0 === $depth) {
                    $pos = $i + 1;
                    break;
                }
            } elseif (\is_array($t) && \T_CURLY_OPEN === $t[0]) {
                ++$depth;
            }
        }
        if (0 !== $depth) {
            return null;
        }

        while ($pos < \count($tokens) && self::isIgnorable($tokens[$pos])) {
            ++$pos;
        }
        if ($pos >= \count($tokens) || !\is_array($tokens[$pos]) || \T_DOUBLE_ARROW !== $tokens[$pos][0]) {
            return null;
        }

        ++$pos;
        while ($pos < \count($tokens) && self::isIgnorable($tokens[$pos])) {
            ++$pos;
        }
        if ($pos >= \count($tokens)) {
            return null;
        }

        return self::scanExpressionForward($tokens, $pos);
    }

    /**
     * Scan a single expression (arrow-fn body, pipe RHS tail, etc.).
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function scanExpressionForward(array $tokens, int $startIdx): int
    {
        $pos = $startIdx;
        $paren = 0;
        $bracket = 0;

        while ($pos < \count($tokens)) {
            if (self::isIgnorable($tokens[$pos])) {
                ++$pos;
                continue;
            }

            if (0 === $paren && 0 === $bracket) {
                $t = $tokens[$pos];
                if (\is_string($t) && \in_array($t, [';', ','], true)) {
                    break;
                }
                if (\is_string($t) && ')' === $t) {
                    break;
                }
            }

            $t = $tokens[$pos];
            if (\is_string($t)) {
                if ('(' === $t) {
                    ++$paren;
                } elseif (')' === $t) {
                    --$paren;
                } elseif ('[' === $t) {
                    ++$bracket;
                } elseif (']' === $t) {
                    --$bracket;
                }
            } elseif (\is_array($t) && \T_FN === $t[0]) {
                $end = self::scanArrowFunctionForward($tokens, $pos);
                if (null !== $end) {
                    $pos = $end + 1;
                    continue;
                }
            }

            ++$pos;
        }

        $end = $pos - 1;
        while ($end >= $startIdx && self::isIgnorable($tokens[$end])) {
            --$end;
        }

        return max($startIdx, $end);
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
            \T_STRING, \T_VARIABLE, \T_NS_SEPARATOR, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED,
            \T_NAME_RELATIVE,
        ], true);
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function infixPrecLeft($token): ?int
    {
        if (\is_array($token)) {
            return match ($token[0]) {
                \T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_PAAMAYIM_NEKUDOTAYIM => 32,
                \T_INSTANCEOF => 28,
                \T_POW => 30,
                \T_SL, \T_SR => 20,
                \T_IS_IDENTICAL, \T_IS_NOT_IDENTICAL, \T_IS_EQUAL, \T_IS_NOT_EQUAL, \T_SPACESHIP => 18,
                \T_IS_SMALLER_OR_EQUAL, \T_IS_GREATER_OR_EQUAL => 18,
                \T_BOOLEAN_AND, \T_LOGICAL_AND => 14,
                \T_BOOLEAN_OR, \T_LOGICAL_OR => 13,
                \T_COALESCE => 12,
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
    private static function extendWithTrailingEmptyInvoke(array $tokens, int $endIdx): int
    {
        $invokeStart = $endIdx + 1;
        while ($invokeStart < \count($tokens) && self::isIgnorable($tokens[$invokeStart])) {
            ++$invokeStart;
        }
        if ($invokeStart >= \count($tokens) || !\is_string($tokens[$invokeStart]) || '(' !== $tokens[$invokeStart]) {
            return $endIdx;
        }

        $invokeDepth = 0;
        for ($j = $invokeStart; $j < \count($tokens); ++$j) {
            $it = $tokens[$j];
            if (\is_string($it) && '(' === $it) {
                ++$invokeDepth;
            } elseif (\is_string($it) && ')' === $it) {
                --$invokeDepth;
                if (0 === $invokeDepth) {
                    return $j;
                }
            } elseif (\is_array($it) && \T_CURLY_OPEN === $it[0]) {
                ++$invokeDepth;
            }
        }

        return $endIdx;
    }

    private static function rewritePipe(string $lhs, string $rhs): string
    {
        $trimmed = ltrim($rhs);
        if (preg_match('/^\(\s*fn\s*\(/s', $trimmed)) {
            $callable = rtrim($rhs);
            // (fn(...))() — drop empty invoke; pipe LHS becomes the sole argument (#7244).
            if (preg_match('/\(\s*\)$/', $callable)) {
                $callable = preg_replace('/\(\s*\)$/', '', $callable);
            }

            return $callable.'('.$lhs.')';
        }

        if (preg_match('/^fn\s*\(/s', $trimmed)) {
            $callable = rtrim($rhs);
            if (preg_match('/\(\s*\)$/', $callable)) {
                $callable = preg_replace('/\(\s*\)$/', '', $callable);
            }

            return '('.$callable.')('.$lhs.')';
        }

        $open = strpos($rhs, '(');
        if (false === $open) {
            return $rhs.'('.$lhs.')';
        }

        $prefix = substr($rhs, 0, $open + 1);
        $suffix = substr($rhs, $open + 1);
        $inner = ltrim($suffix);
        $callee = substr($rhs, 0, $open);

        // First-class callable: func(...) → func($lhs) (zend_compile.c pipe + ZEND_AST_CALLABLE_CONVERT).
        if (preg_match('/^\\.\\.\\.(\\s*\\))/s', $inner, $m)) {
            return $prefix.$lhs.$m[1];
        }

        if ('' === $inner || str_starts_with($inner, ')')) {
            return $prefix.$lhs.$suffix;
        }

        return $prefix.$lhs.', '.$suffix;
    }
}
