<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PHPCompiler\CompilerVersion;

/**
 * Desugar `(void)` cast for nikic/php-parser when the language profile enables it (#7346).
 *
 * php-src through 8.5.8 has no T_VOID_CAST (Zend/zend_language_scanner.l) — see #28183.
 * Gated on {@see CompilerVersion::supportsVoidCast()} (currently always false).
 */
final class VoidCastDesugar
{
    public const MARKER = '__phpcVoidCast';

    public static function desugar(string $code): string
    {
        if (!CompilerVersion::supportsVoidCast()) {
            return $code;
        }
        if (false === stripos($code, '(void)')) {
            return $code;
        }

        $tokens = token_get_all($code);
        $replacements = [];
        for ($i = 0, $c = \count($tokens); $i < $c; ++$i) {
            if (!self::isVoidCastPrefix($tokens, $i, $voidCloseIdx)) {
                continue;
            }
            $exprStartIdx = $voidCloseIdx + 1;
            $exprEndIdx = self::consumeUnaryExprEndIndex($tokens, $exprStartIdx);
            if ($exprEndIdx <= $exprStartIdx) {
                continue;
            }
            $voidStart = self::tokenByteOffset($tokens, $i);
            $exprStart = self::tokenByteOffset($tokens, $exprStartIdx);
            $exprEnd = self::tokenByteOffset($tokens, $exprEndIdx);
            if (null === $voidStart || null === $exprStart || null === $exprEnd) {
                continue;
            }
            $exprText = ltrim(substr($code, $exprStart, $exprEnd - $exprStart));
            $replacements[] = [
                'start' => $voidStart,
                'end' => $exprEnd,
                'text' => self::MARKER.'('.$exprText.')',
            ];
            $i = $exprEndIdx - 1;
        }

        if ([] === $replacements) {
            return $code;
        }

        usort($replacements, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
        foreach ($replacements as $replacement) {
            $code = substr($code, 0, $replacement['start'])
                .$replacement['text']
                .substr($code, $replacement['end']);
        }

        return $code;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isVoidCastPrefix(array $tokens, int $i, ?int &$voidCloseIdx): bool
    {
        $voidCloseIdx = null;
        if (!isset($tokens[$i]) || !\is_string($tokens[$i]) || '(' !== $tokens[$i]) {
            return false;
        }
        $pos = $i + 1;
        while ($pos < \count($tokens) && self::isIgnorable($tokens[$pos])) {
            ++$pos;
        }
        if ($pos >= \count($tokens) || !\is_array($tokens[$pos]) || \T_STRING !== $tokens[$pos][0]) {
            return false;
        }
        if ('void' !== strtolower($tokens[$pos][1])) {
            return false;
        }
        ++$pos;
        while ($pos < \count($tokens) && self::isIgnorable($tokens[$pos])) {
            ++$pos;
        }
        if ($pos >= \count($tokens) || !\is_string($tokens[$pos]) || ')' !== $tokens[$pos]) {
            return false;
        }
        $voidCloseIdx = $pos;

        return true;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function consumeUnaryExprEndIndex(array $tokens, int $start): int
    {
        $i = $start;
        while ($i < \count($tokens) && self::isIgnorable($tokens[$i])) {
            ++$i;
        }
        while ($i < \count($tokens) && self::isUnaryPrefix($tokens[$i])) {
            ++$i;
            while ($i < \count($tokens) && self::isIgnorable($tokens[$i])) {
                ++$i;
            }
        }

        return self::consumePostfixExprEndIndex($tokens, $i);
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function consumePostfixExprEndIndex(array $tokens, int $i): int
    {
        $i = self::consumePrimaryEndIndex($tokens, $i);
        while ($i < \count($tokens)) {
            while ($i < \count($tokens) && self::isIgnorable($tokens[$i])) {
                ++$i;
            }
            if ($i >= \count($tokens)) {
                break;
            }
            $token = $tokens[$i];
            if (\is_string($token) && '(' === $token) {
                $i = self::consumeBalanced($tokens, $i, '(', ')');
                continue;
            }
            if (\is_string($token) && '[' === $token) {
                $i = self::consumeBalanced($tokens, $i, '[', ']');
                continue;
            }
            if (\is_array($token) && \in_array($token[0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON], true)) {
                ++$i;
                while ($i < \count($tokens) && self::isIgnorable($tokens[$i])) {
                    ++$i;
                }
                $i = self::consumePropertyNameEndIndex($tokens, $i);
                continue;
            }
            break;
        }

        return $i;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function consumePrimaryEndIndex(array $tokens, int $i): int
    {
        if ($i >= \count($tokens)) {
            return $i;
        }
        $token = $tokens[$i];
        if (\is_string($token) && '(' === $token) {
            return self::consumeBalanced($tokens, $i, '(', ')');
        }
        if (\is_array($token) && self::isPrimaryStart($token)) {
            ++$i;
            if (\T_NEW === $token[0]) {
                while ($i < \count($tokens) && self::isIgnorable($tokens[$i])) {
                    ++$i;
                }
                $i = self::consumePostfixExprEndIndex($tokens, $i);
            }

            return $i;
        }

        return $i;
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function isUnaryPrefix($token): bool
    {
        if (!\is_array($token)) {
            return \in_array($token, ['+', '-', '!', '~', '@'], true);
        }

        return \in_array($token[0], [\T_INC, \T_DEC, \T_BOOL_CAST, \T_INT_CAST, \T_DOUBLE_CAST, \T_STRING_CAST, \T_ARRAY_CAST, \T_OBJECT_CAST, \T_UNSET_CAST], true);
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function isPrimaryStart($token): bool
    {
        if (!\is_array($token)) {
            return false;
        }

        return \in_array($token[0], [
            \T_VARIABLE,
            \T_STRING,
            \T_NAME_QUALIFIED,
            \T_NAME_FULLY_QUALIFIED,
            \T_NAME_RELATIVE,
            \T_LNUMBER,
            \T_DNUMBER,
            \T_CONSTANT_ENCAPSED_STRING,
            \T_ENCAPSED_AND_WHITESPACE,
            \T_NEW,
            \T_CLONE,
            \T_ARRAY,
            \T_STATIC,
        ], true);
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function consumePropertyNameEndIndex(array $tokens, int $i): int
    {
        if ($i >= \count($tokens)) {
            return $i;
        }
        $token = $tokens[$i];
        if (\is_array($token) && \in_array($token[0], [\T_STRING, \T_VARIABLE, \T_LNUMBER, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE], true)) {
            return $i + 1;
        }
        if (\is_string($token) && '{' === $token) {
            return self::consumeBalanced($tokens, $i, '{', '}');
        }

        return $i;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function consumeBalanced(array $tokens, int $i, string $open, string $close): int
    {
        if ($i >= \count($tokens) || !\is_string($tokens[$i]) || $open !== $tokens[$i]) {
            return $i;
        }
        $depth = 1;
        ++$i;
        while ($i < \count($tokens) && $depth > 0) {
            $token = $tokens[$i];
            if (\is_string($token)) {
                if ($token === $open) {
                    ++$depth;
                } elseif ($token === $close) {
                    --$depth;
                }
            }
            ++$i;
        }

        return $i;
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
