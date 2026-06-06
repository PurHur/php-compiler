<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

/**
 * Desugar PHP 8.4+ `exit($status, $message)` / `die($status, $message)` before nikic/php-parser (#6718).
 *
 * Rewrites to compile-time marker `__phpcExitTwo($status, $message)`; CFG visitor lowers to Exit_.
 * php-src: Zend/zend_compile.c — exit/die two-argument form (PHP 8.4).
 */
final class ExitTwoArgDesugar
{
    public const MARKER = '__phpcExitTwo';

    public static function desugar(string $code): string
    {
        if (!preg_match('/\b(?:exit|die)\s*\(/i', $code)) {
            return $code;
        }

        for ($guard = 0; $guard < 512; ++$guard) {
            $tokens = token_get_all($code);
            $exitIdx = self::findTwoArgExitIndex($tokens);
            if (null === $exitIdx) {
                break;
            }

            $call = self::extractTwoArgCall($code, $tokens, $exitIdx);
            if (null === $call) {
                break;
            }

            $replacement = self::MARKER.'('.$call['arg1'].', '.$call['arg2'].')';
            $code = substr($code, 0, $call['start'])
                .$replacement
                .substr($code, $call['end']);
        }

        return $code;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function findTwoArgExitIndex(array $tokens): ?int
    {
        for ($i = 0, $c = count($tokens); $i < $c; ++$i) {
            if (!self::isExitKeyword($tokens, $i)) {
                continue;
            }
            if (!self::hasTwoArgParenCall($tokens, $i)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isExitKeyword(array $tokens, int $i): bool
    {
        if (!isset($tokens[$i]) || !\is_array($tokens[$i])) {
            return false;
        }

        return \T_EXIT === $tokens[$i][0];
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function hasTwoArgParenCall(array $tokens, int $exitIdx): bool
    {
        $pos = $exitIdx + 1;
        while ($pos < count($tokens) && self::isIgnorable($tokens[$pos])) {
            ++$pos;
        }
        if ($pos >= count($tokens) || !\is_string($tokens[$pos]) || '(' !== $tokens[$pos]) {
            return false;
        }

        $depth = 0;
        $sawComma = false;
        for ($i = $pos; $i < count($tokens); ++$i) {
            $t = $tokens[$i];
            if (\is_string($t)) {
                if ('(' === $t) {
                    ++$depth;
                } elseif (')' === $t) {
                    --$depth;
                    if (0 === $depth) {
                        return $sawComma;
                    }
                } elseif (',' === $t && 1 === $depth) {
                    $sawComma = true;
                }
            } elseif (\is_array($t) && \T_CURLY_OPEN === $t[0]) {
                ++$depth;
            }
        }

        return false;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{start: int, end: int, arg1: string, arg2: string}|null
     */
    private static function extractTwoArgCall(string $code, array $tokens, int $exitIdx): ?array
    {
        $pos = $exitIdx + 1;
        while ($pos < count($tokens) && self::isIgnorable($tokens[$pos])) {
            ++$pos;
        }
        if ($pos >= count($tokens) || !\is_string($tokens[$pos]) || '(' !== $tokens[$pos]) {
            return null;
        }

        $openIdx = $pos;
        $depth = 0;
        $commaIdx = null;
        $closeIdx = null;
        for ($i = $openIdx; $i < count($tokens); ++$i) {
            $t = $tokens[$i];
            if (\is_string($t)) {
                if ('(' === $t) {
                    ++$depth;
                } elseif (')' === $t) {
                    --$depth;
                    if (0 === $depth) {
                        $closeIdx = $i;
                        break;
                    }
                } elseif (',' === $t && 1 === $depth && null === $commaIdx) {
                    $commaIdx = $i;
                }
            } elseif (\is_array($t) && \T_CURLY_OPEN === $t[0]) {
                ++$depth;
            }
        }

        if (null === $commaIdx || null === $closeIdx) {
            return null;
        }

        $start = self::tokenByteOffset($tokens, $exitIdx);
        $end = self::tokenByteEnd($tokens, $closeIdx);
        $arg1Start = self::tokenByteEnd($tokens, $openIdx);
        $arg1End = self::tokenByteOffset($tokens, $commaIdx);
        $arg2Start = self::tokenByteEnd($tokens, $commaIdx);
        $arg2End = self::tokenByteOffset($tokens, $closeIdx);
        if (null === $start || null === $end || null === $arg1Start || null === $arg1End || null === $arg2Start || null === $arg2End) {
            return null;
        }

        return [
            'start' => $start,
            'end' => $end,
            'arg1' => trim(substr($code, $arg1Start, $arg1End - $arg1Start)),
            'arg2' => trim(substr($code, $arg2Start, $arg2End - $arg2Start)),
        ];
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
}
