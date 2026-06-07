<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

/**
 * Desugar PHP 8.3+ `clone $obj with { prop: $value, ... }` before nikic/php-parser (#4513).
 *
 * Rewrites to an IIFE that clones then assigns properties — matches Zend/zend_compile.c lowering.
 * php-src: Zend/zend_language_parser.y clone_expr / with clause; zend_clones.c.
 */
final class CloneWithDesugar
{
    public static function desugar(string $code): string
    {
        if (!preg_match('/\bclone\b/i', $code) || !preg_match('/\bwith\b/i', $code)) {
            return $code;
        }

        for ($guard = 0; $guard < 256; ++$guard) {
            $tokens = token_get_all($code);
            $span = self::findCloneWithSpan($code, $tokens);
            if (null === $span) {
                break;
            }

            $assignments = self::parsePropertyAssignments($span['blockText']);
            if ([] === $assignments) {
                break;
            }

            $body = '$__phpc_r = clone $__phpc_o;';
            $propNames = [];
            foreach ($assignments as [$name, $_value]) {
                $propNames[] = var_export($name, true);
            }
            $body .= '__phpc_clone_with_reinit($__phpc_r, ['.implode(', ', $propNames).']);';
            foreach ($assignments as [$name, $value]) {
                $body .= '$__phpc_r->'.$name.' = '.$value.';';
            }
            $body .= '__phpc_clone_with_reinit_done($__phpc_r);';
            $body .= 'return $__phpc_r;';

            $replacement = '(function ($__phpc_o) { '.$body.' })('.$span['exprText'].')';
            $code = substr($code, 0, $span['start'])
                .$replacement
                .substr($code, $span['end']);
        }

        return $code;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{start: int, end: int, exprText: string, blockText: string}|null
     */
    private static function findCloneWithSpan(string $code, array $tokens): ?array
    {
        for ($i = 0, $c = \count($tokens); $i < $c; ++$i) {
            $token = $tokens[$i];
            if (!\is_array($token) || \T_CLONE !== $token[0]) {
                continue;
            }

            $exprStart = $i + 1;
            self::skipForwardIgnorable($tokens, $exprStart);
            if ($exprStart >= $c) {
                continue;
            }

            $exprEnd = self::scanCloneOperandEnd($tokens, $exprStart);
            if (null === $exprEnd) {
                continue;
            }

            $withIdx = $exprEnd + 1;
            self::skipForwardIgnorable($tokens, $withIdx);
            if (!self::isWithKeyword($tokens, $withIdx)) {
                continue;
            }

            $braceIdx = $withIdx + 1;
            self::skipForwardIgnorable($tokens, $braceIdx);
            if ($braceIdx >= $c || '{' !== $tokens[$braceIdx]) {
                continue;
            }

            $blockEndIdx = self::skipBalancedBraces($tokens, $braceIdx);
            if (null === $blockEndIdx) {
                continue;
            }

            $start = self::tokenByteOffset($tokens, $i);
            $end = self::tokenByteEnd($tokens, $blockEndIdx);
            $exprTextStart = self::tokenByteOffset($tokens, $exprStart);
            $exprTextEnd = self::tokenByteEnd($tokens, $exprEnd);
            if (null === $start || null === $end || null === $exprTextStart || null === $exprTextEnd) {
                continue;
            }

            $blockOpen = self::tokenByteEnd($tokens, $braceIdx);
            $blockClose = self::tokenByteOffset($tokens, $blockEndIdx);
            if (null === $blockOpen || null === $blockClose) {
                continue;
            }

            return [
                'start' => $start,
                'end' => $end,
                'exprText' => trim(substr($code, $exprTextStart, $exprTextEnd - $exprTextStart)),
                'blockText' => trim(substr($code, $blockOpen, $blockClose - $blockOpen)),
            ];
        }

        return null;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function parsePropertyAssignments(string $blockText): array
    {
        $blockText = trim($blockText);
        if ('' === $blockText) {
            return [];
        }

        $tokens = token_get_all('<?php '.$blockText.';');
        if (!\is_array($tokens)) {
            return [];
        }

        $assignments = [];
        $i = 0;
        $c = \count($tokens);
        while ($i < $c) {
            self::skipForwardIgnorable($tokens, $i);
            if ($i >= $c) {
                break;
            }

            $token = $tokens[$i];
            if (!\is_array($token) || \T_STRING !== $token[0]) {
                break;
            }
            $name = $token[1];
            ++$i;
            self::skipForwardIgnorable($tokens, $i);
            if ($i >= $c || ':' !== $tokens[$i]) {
                break;
            }
            ++$i;

            $valueStart = $i;
            self::skipForwardIgnorable($tokens, $valueStart);
            if ($valueStart >= $c) {
                break;
            }

            $valueEnd = self::scanPropertyValueEnd($tokens, $valueStart);
            if (null === $valueEnd) {
                break;
            }

            $valueStartByte = self::tokenByteOffset($tokens, $valueStart);
            $valueEndByte = self::tokenByteEnd($tokens, $valueEnd);
            if (null === $valueStartByte || null === $valueEndByte) {
                break;
            }
            $snippet = '';
            foreach ($tokens as $token) {
                $snippet .= self::tokenText($token);
            }
            $valueText = trim(substr($snippet, $valueStartByte, $valueEndByte - $valueStartByte));
            $assignments[] = [$name, $valueText];

            $i = $valueEnd + 1;
            self::skipForwardIgnorable($tokens, $i);
            if ($i < $c && ',' === $tokens[$i]) {
                ++$i;
                continue;
            }
            break;
        }

        return $assignments;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function scanPropertyValueEnd(array $tokens, int $startIdx): ?int
    {
        $pos = $startIdx;
        $endIdx = self::scanPrimaryForward($tokens, $pos);
        if (null === $endIdx) {
            return null;
        }
        $pos = $endIdx + 1;

        while ($pos < \count($tokens)) {
            self::skipForwardIgnorable($tokens, $pos);
            if ($pos >= \count($tokens)) {
                return $endIdx;
            }

            $token = $tokens[$pos];
            if (\is_string($token) && ',' === $token) {
                return $endIdx;
            }
            if (\is_string($token) && ';' === $token) {
                return $endIdx;
            }
            if (\is_string($token) && '[' === $token) {
                $next = self::skipBalancedForward($tokens, $pos, '[', ']');
                if (null === $next) {
                    return null;
                }
                $endIdx = $next - 1;
                $pos = $next;
                continue;
            }
            if (\is_string($token) && '(' === $token) {
                $next = self::skipBalancedForward($tokens, $pos, '(', ')');
                if (null === $next) {
                    return null;
                }
                $endIdx = $next - 1;
                $pos = $next;
                continue;
            }
            if (\is_array($token) && \in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                ++$pos;
                self::skipForwardIgnorable($tokens, $pos);
                if ($pos >= \count($tokens)) {
                    return null;
                }
                $endIdx = $pos;
                ++$pos;
                continue;
            }

            break;
        }

        return $endIdx;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function scanCloneOperandEnd(array $tokens, int $startIdx): ?int
    {
        $pos = $startIdx;
        $endIdx = self::scanPrimaryForward($tokens, $pos);
        if (null === $endIdx) {
            return null;
        }
        $pos = $endIdx + 1;

        while ($pos < \count($tokens)) {
            self::skipForwardIgnorable($tokens, $pos);
            if ($pos >= \count($tokens)) {
                return $endIdx;
            }

            $token = $tokens[$pos];
            if (\is_string($token) && '[' === $token) {
                $next = self::skipBalancedForward($tokens, $pos, '[', ']');
                if (null === $next) {
                    return null;
                }
                $endIdx = $next - 1;
                $pos = $next;
                continue;
            }
            if (\is_string($token) && '(' === $token) {
                $next = self::skipBalancedForward($tokens, $pos, '(', ')');
                if (null === $next) {
                    return null;
                }
                $endIdx = $next - 1;
                $pos = $next;
                continue;
            }
            if (\is_array($token) && \in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                ++$pos;
                self::skipForwardIgnorable($tokens, $pos);
                if ($pos >= \count($tokens)) {
                    return null;
                }
                $endIdx = $pos;
                ++$pos;
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
        while ($pos < \count($tokens) && self::isIgnorable($tokens[$pos])) {
            ++$pos;
        }
        if ($pos >= \count($tokens)) {
            return null;
        }

        $token = $tokens[$pos];
        if (\is_string($token) && '(' === $token) {
            $next = self::skipBalancedForward($tokens, $pos, '(', ')');

            return null === $next ? null : $next - 1;
        }
        if (\is_array($token) && \in_array($token[0], [
            T_VARIABLE, T_STRING, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER,
            T_ARRAY, T_NEW, T_CLONE, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED,
            T_NAME_RELATIVE,
        ], true)) {
            if (\T_NEW === $token[0]) {
                return self::scanNewExpressionEnd($tokens, $pos);
            }

            return $pos;
        }

        return null;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function scanNewExpressionEnd(array $tokens, int $newIdx): ?int
    {
        $pos = $newIdx + 1;
        self::skipForwardIgnorable($tokens, $pos);
        if ($pos >= \count($tokens)) {
            return null;
        }

        if (!self::skipNewClassTarget($tokens, $pos)) {
            return null;
        }

        self::skipForwardIgnorable($tokens, $pos);
        if ($pos < \count($tokens) && '(' === $tokens[$pos]) {
            if (null === self::skipBalancedForward($tokens, $pos, '(', ')')) {
                return null;
            }
        }

        return $pos - 1;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipNewClassTarget(array $tokens, int &$pos): bool
    {
        while ($pos < \count($tokens)) {
            self::skipForwardIgnorable($tokens, $pos);
            if ($pos >= \count($tokens)) {
                return false;
            }

            $token = $tokens[$pos];
            if (\is_array($token) && \in_array($token[0], [
                T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR,
            ], true)) {
                ++$pos;
                continue;
            }

            break;
        }

        return true;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isWithKeyword(array $tokens, int $idx): bool
    {
        if (!isset($tokens[$idx])) {
            return false;
        }
        $token = $tokens[$idx];
        if (\is_array($token) && \defined('T_WITH') && \T_WITH === $token[0]) {
            return true;
        }

        return \is_array($token) && \T_STRING === $token[0] && 'with' === $token[1];
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipBalancedBraces(array $tokens, int $openIdx): ?int
    {
        return self::skipBalancedForward($tokens, $openIdx, '{', '}');
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipBalancedForward(array $tokens, int $openIdx, string $open, string $close): ?int
    {
        $depth = 0;
        for ($i = $openIdx; $i < \count($tokens); ++$i) {
            $t = $tokens[$i];
            if (\is_string($t) && $open === $t) {
                ++$depth;
            } elseif (\is_string($t) && $close === $t) {
                --$depth;
                if (0 === $depth) {
                    return $i;
                }
            } elseif (\is_array($t) && \T_CURLY_OPEN === $t[0] && '(' === $open) {
                ++$depth;
            }
        }

        return null;
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
    private static function skipForwardIgnorable(array $tokens, int &$pos): void
    {
        while ($pos < \count($tokens) && self::isIgnorable($tokens[$pos])) {
            ++$pos;
        }
    }
}
