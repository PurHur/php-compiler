<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PHPCompiler\CompilerVersion;

/**
 * Desugar PHP 8.4+ dereferencable `new` without outer parentheses before nikic/php-parser (#6974).
 *
 * Rewrites `new Class()->m()` to `(new Class())->m()` so php-parser v4 accepts the source.
 * Only `new` with constructor parentheses (or anonymous class) is dereferencable per RFC —
 * bare `new Class->m()` remains a parse error on PHP 8.4+ (ctor `()` required; #20598).
 * Withheld on 8.4.0-dev reference profile (#19684) — see {@see referenceProfileSyntaxError()}.
 * php-src: Zend/zend_language_parser.y — new_dereferenceable / new_non_dereferenceable.
 */
final class NewDereferenceableDesugar
{
    /** Zend 8.2 profile message for `new Class()->…` (#19684). */
    public const REFERENCE_PROFILE_UNEXPECTED_OBJECT_OPERATOR = 'syntax error, unexpected token "->", expecting "," or ";"';

    /** Zend 8.2 profile message for `new Class()?->…`. */
    public const REFERENCE_PROFILE_UNEXPECTED_NULLSAFE = 'syntax error, unexpected token "?->", expecting "," or ";"';

    /** Zend 8.2 profile message for `new Class()::…`. */
    public const REFERENCE_PROFILE_UNEXPECTED_DOUBLE_COLON = 'syntax error, unexpected token "::", expecting "," or ";"';

    /** Zend 8.2 profile message for `new Class()(…)`. */
    public const REFERENCE_PROFILE_UNEXPECTED_PAREN = 'syntax error, unexpected token "(", expecting "," or ";"';

    /** Zend 8.2 profile message for `new Class()[…]`. */
    public const REFERENCE_PROFILE_UNEXPECTED_BRACKET = 'syntax error, unexpected token "[", expecting "," or ";"';

    /**
     * Bare named-class `new Name->…` / `new Name?->…` (no ctor parentheses) — illegal on every PHP
     * version including 8.4 (RFC new_without_parentheses requires ctor `()`; #20598).
     *
     * Does not touch `new $var->prop` (class name from property) or `new Name::…` (mixed Zend rules).
     *
     * @return array{line: int, message: string}|null
     */
    public static function bareNamedClassObjectDerefSyntaxError(string $code): ?array
    {
        if (!preg_match('/\bnew\b/i', $code)) {
            return null;
        }

        $tokens = token_get_all($code);
        if (!\is_array($tokens) || [] === $tokens) {
            return null;
        }

        for ($i = 0, $c = \count($tokens); $i < $c; ++$i) {
            $token = $tokens[$i];
            if (!\is_array($token) || \T_NEW !== $token[0]) {
                continue;
            }

            $pos = $i + 1;
            self::skipForwardIgnorable($tokens, $pos);
            if ($pos >= $c) {
                continue;
            }

            if (self::isAnonymousClassToken($tokens[$pos])) {
                continue;
            }

            if (!self::isBareNamedClassStartToken($tokens[$pos])) {
                continue;
            }

            if (!self::skipBareNamedClassTarget($tokens, $pos)) {
                continue;
            }

            self::skipForwardIgnorable($tokens, $pos);
            if ($pos >= $c || '(' === $tokens[$pos]) {
                continue;
            }

            $message = self::bareNamedClassObjectDerefMessageForToken($tokens[$pos]);
            if (null === $message) {
                continue;
            }

            $deref = $tokens[$pos];
            if (\is_array($deref) && isset($deref[2])) {
                $line = (int) $deref[2];
            } else {
                $offset = self::tokenByteOffset($tokens, $pos);
                $line = null !== $offset ? self::byteOffsetToLine($code, $offset) : 1;
            }

            return [
                'line' => max(1, $line),
                'message' => $message,
            ];
        }

        return null;
    }

    /**
     * @return array{line: int, message: string}|null
     */
    public static function referenceProfileSyntaxError(string $code): ?array
    {
        if (!preg_match('/\bnew\b/i', $code)) {
            return null;
        }

        $tokens = token_get_all($code);
        if (!\is_array($tokens) || [] === $tokens) {
            return null;
        }

        for ($i = 0, $c = \count($tokens); $i < $c; ++$i) {
            $token = $tokens[$i];
            if (!\is_array($token) || \T_NEW !== $token[0]) {
                continue;
            }

            $endIdx = self::dereferenceableNewEndIndex($tokens, $i);
            if (null === $endIdx || !self::needsDereferenceWrap($tokens, $endIdx)) {
                continue;
            }
            if (self::alreadyParenthesized($tokens, $i, $endIdx)) {
                continue;
            }

            $pos = $endIdx + 1;
            self::skipForwardIgnorable($tokens, $pos);
            if ($pos >= $c) {
                continue;
            }

            $message = self::referenceProfileMessageForToken($tokens[$pos]);
            if (null === $message) {
                continue;
            }

            $deref = $tokens[$pos];
            if (\is_array($deref) && isset($deref[2])) {
                $line = (int) $deref[2];
            } else {
                $offset = self::tokenByteOffset($tokens, $pos);
                $line = null !== $offset ? self::byteOffsetToLine($code, $offset) : 1;
            }

            return [
                'line' => max(1, $line),
                'message' => $message,
            ];
        }

        return null;
    }

    public static function desugar(string $code): string
    {
        if (!CompilerVersion::supportsDereferencableNewWithoutOuterParens()) {
            return $code;
        }
        if (!preg_match('/\bnew\b/i', $code)) {
            return $code;
        }

        $tokens = token_get_all($code);
        if (!\is_array($tokens) || [] === $tokens) {
            return $code;
        }

        $wraps = [];
        for ($i = 0, $c = \count($tokens); $i < $c; ++$i) {
            $token = $tokens[$i];
            if (!\is_array($token) || \T_NEW !== $token[0]) {
                continue;
            }

            $endIdx = self::dereferenceableNewEndIndex($tokens, $i);
            if (null === $endIdx || !self::needsDereferenceWrap($tokens, $endIdx)) {
                continue;
            }
            if (self::alreadyParenthesized($tokens, $i, $endIdx)) {
                continue;
            }

            $start = self::tokenByteOffset($tokens, $i);
            $end = self::tokenByteEnd($tokens, $endIdx);
            if (null === $start || null === $end) {
                continue;
            }

            $wraps[] = [$start, $end];
        }

        if ([] === $wraps) {
            return $code;
        }

        \usort($wraps, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($wraps as [$start, $end]) {
            $code = \substr($code, 0, $start)
                .'('
                .\substr($code, $start, $end - $start)
                .')'
                .\substr($code, $end);
        }

        return $code;
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function referenceProfileMessageForToken($token): ?string
    {
        if (\is_string($token)) {
            return match ($token) {
                '(' => self::REFERENCE_PROFILE_UNEXPECTED_PAREN,
                '[' => self::REFERENCE_PROFILE_UNEXPECTED_BRACKET,
                default => null,
            };
        }

        return match ($token[0]) {
            \T_OBJECT_OPERATOR => self::REFERENCE_PROFILE_UNEXPECTED_OBJECT_OPERATOR,
            \T_NULLSAFE_OBJECT_OPERATOR => self::REFERENCE_PROFILE_UNEXPECTED_NULLSAFE,
            \T_DOUBLE_COLON => self::REFERENCE_PROFILE_UNEXPECTED_DOUBLE_COLON,
            default => null,
        };
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function bareNamedClassObjectDerefMessageForToken($token): ?string
    {
        if (!\is_array($token)) {
            return null;
        }

        return match ($token[0]) {
            \T_OBJECT_OPERATOR => self::REFERENCE_PROFILE_UNEXPECTED_OBJECT_OPERATOR,
            \T_NULLSAFE_OBJECT_OPERATOR => self::REFERENCE_PROFILE_UNEXPECTED_NULLSAFE,
            default => null,
        };
    }

    /**
     * Named class after `new` without a variable / `(expr)` wrapper (#20598).
     *
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function isBareNamedClassStartToken($token): bool
    {
        if (!\is_array($token)) {
            return false;
        }

        return \in_array($token[0], [
            \T_STRING,
            \T_STATIC,
            \T_NAME_QUALIFIED,
            \T_NAME_FULLY_QUALIFIED,
            \T_NAME_RELATIVE,
        ], true);
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipBareNamedClassTarget(array $tokens, int &$pos): bool
    {
        if ($pos >= \count($tokens) || !self::isBareNamedClassStartToken($tokens[$pos])) {
            return false;
        }

        ++$pos;
        while ($pos < \count($tokens)) {
            self::skipForwardIgnorable($tokens, $pos);
            if ($pos >= \count($tokens)) {
                break;
            }
            if ('\\' === $tokens[$pos]) {
                ++$pos;
                self::skipForwardIgnorable($tokens, $pos);
                if ($pos >= \count($tokens) || !self::isClassNamePartToken($tokens[$pos])) {
                    return false;
                }
                ++$pos;
                continue;
            }
            break;
        }

        return true;
    }

    private static function byteOffsetToLine(string $code, int $offset): int
    {
        if ($offset <= 0) {
            return 1;
        }

        return substr_count(substr($code, 0, $offset), "\n") + 1;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function dereferenceableNewEndIndex(array $tokens, int $newIdx): ?int
    {
        $pos = $newIdx + 1;
        self::skipForwardIgnorable($tokens, $pos);
        if ($pos >= \count($tokens)) {
            return null;
        }

        if (self::isAnonymousClassToken($tokens[$pos])) {
            return self::anonymousClassEndIndex($tokens, $pos);
        }

        if (!self::skipNewClassTarget($tokens, $pos)) {
            return null;
        }

        self::skipForwardIgnorable($tokens, $pos);
        if ($pos >= \count($tokens) || '(' !== $tokens[$pos]) {
            return null;
        }

        if (!self::skipBalancedParens($tokens, $pos)) {
            return null;
        }

        return $pos - 1;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function anonymousClassEndIndex(array $tokens, int $classIdx): ?int
    {
        $pos = $classIdx + 1;
        self::skipForwardIgnorable($tokens, $pos);
        if ($pos < \count($tokens) && '(' === $tokens[$pos]) {
            if (!self::skipBalancedParens($tokens, $pos)) {
                return null;
            }
            self::skipForwardIgnorable($tokens, $pos);
        }

        if ($pos >= \count($tokens) || '{' !== $tokens[$pos]) {
            return null;
        }

        if (!self::skipBalancedBraces($tokens, $pos)) {
            return null;
        }

        return $pos - 1;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipNewClassTarget(array $tokens, int &$pos): bool
    {
        if ($pos >= \count($tokens)) {
            return false;
        }

        $token = $tokens[$pos];
        if ('(' === $token) {
            if (!self::skipBalancedParens($tokens, $pos)) {
                return false;
            }
            self::skipForwardIgnorable($tokens, $pos);

            return true;
        }

        if (!self::isClassNameStartToken($token)) {
            return false;
        }

        ++$pos;
        while ($pos < \count($tokens)) {
            self::skipForwardIgnorable($tokens, $pos);
            if ($pos >= \count($tokens)) {
                break;
            }
            if ('\\' === $tokens[$pos]) {
                ++$pos;
                self::skipForwardIgnorable($tokens, $pos);
                if ($pos >= \count($tokens) || !self::isClassNamePartToken($tokens[$pos])) {
                    return false;
                }
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
    private static function needsDereferenceWrap(array $tokens, int $endIdx): bool
    {
        $pos = $endIdx + 1;
        self::skipForwardIgnorable($tokens, $pos);
        if ($pos >= \count($tokens)) {
            return false;
        }

        $token = $tokens[$pos];
        if (\is_string($token)) {
            return \in_array($token, ['(', '['], true);
        }

        return \in_array($token[0], [
            \T_OBJECT_OPERATOR,
            \T_NULLSAFE_OBJECT_OPERATOR,
            \T_DOUBLE_COLON,
        ], true);
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function alreadyParenthesized(array $tokens, int $startIdx, int $endIdx): bool
    {
        $before = $startIdx - 1;
        self::skipBackwardIgnorable($tokens, $before);
        if ($before < 0 || '(' !== $tokens[$before]) {
            return false;
        }

        $after = $endIdx + 1;
        self::skipForwardIgnorable($tokens, $after);

        return $after < \count($tokens) && ')' === $tokens[$after];
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipBalancedParens(array $tokens, int &$pos): bool
    {
        if ($pos >= \count($tokens) || '(' !== $tokens[$pos]) {
            return false;
        }

        $depth = 0;
        for ($c = \count($tokens); $pos < $c; ++$pos) {
            $token = $tokens[$pos];
            if ('(' === $token) {
                ++$depth;
                continue;
            }
            if (')' === $token) {
                --$depth;
                if (0 === $depth) {
                    ++$pos;

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipBalancedBraces(array $tokens, int &$pos): bool
    {
        if ($pos >= \count($tokens) || '{' !== $tokens[$pos]) {
            return false;
        }

        $depth = 0;
        for ($c = \count($tokens); $pos < $c; ++$pos) {
            $token = $tokens[$pos];
            if ('{' === $token) {
                ++$depth;
                continue;
            }
            if ('}' === $token) {
                --$depth;
                if (0 === $depth) {
                    ++$pos;

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function isAnonymousClassToken($token): bool
    {
        return \is_array($token) && \T_CLASS === $token[0];
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function isClassNameStartToken($token): bool
    {
        if (!\is_array($token)) {
            return false;
        }

        return \in_array($token[0], [
            \T_STRING,
            \T_VARIABLE,
            \T_STATIC,
            \T_NAME_QUALIFIED,
            \T_NAME_FULLY_QUALIFIED,
            \T_NAME_RELATIVE,
        ], true);
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function isClassNamePartToken($token): bool
    {
        if (!\is_array($token)) {
            return false;
        }

        return \in_array($token[0], [
            \T_STRING,
            \T_NAME_QUALIFIED,
            \T_NAME_FULLY_QUALIFIED,
            \T_NAME_RELATIVE,
        ], true);
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
        while ($pos < \count($tokens) && self::isIgnorable($tokens[$pos])) {
            ++$pos;
        }
    }
}
