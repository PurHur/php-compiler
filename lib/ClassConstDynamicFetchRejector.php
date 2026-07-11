<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\CompilerVersion;

/**
 * Reject PHP 8.3+ dynamic class constant fetch (`C::{$name}`) on the 8.2 reference profile (#17863).
 *
 * php-src Zend 8.2: parse error on `::{$expr}`; PHP 8.3+ allows runtime constant name resolution.
 * php-src: Zend/zend_language_parser.y class_constant; Zend/zend_compile.c zend_compile_const_expr().
 */
final class ClassConstDynamicFetchRejector
{
    /** Zend 8.2 parse error for `echo C::{$name}, ...` (unexpected comma). */
    public const PARSE_MESSAGE_COMMA = 'syntax error, unexpected token ","';

    /** Zend 8.2 parse error for `echo C::{$name};` (unexpected statement terminator). */
    public const PARSE_MESSAGE_SEMICOLON = 'syntax error, unexpected token ";"';

    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (CompilerVersion::supportsDynamicClassConstFetch()) {
            return $code;
        }
        if (!str_contains($code, '::') || !str_contains($code, '{')) {
            return $code;
        }
        if (!\function_exists('token_get_all')) {
            return $code;
        }

        $tokens = token_get_all($code);
        $count = \count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            if (!self::isDoubleColon($tokens, $i)) {
                continue;
            }
            $braceIdx = self::nextSignificantIndex($tokens, $i + 1);
            if (null === $braceIdx || !self::isToken($tokens, $braceIdx, '{')) {
                continue;
            }
            $innerIdx = self::nextSignificantIndex($tokens, $braceIdx + 1);
            if (null === $innerIdx) {
                continue;
            }
            if (self::isConstantEncapsedString($tokens, $innerIdx)) {
                continue;
            }
            $closeIdx = self::findMatchingCloseBrace($tokens, $braceIdx);
            if (null === $closeIdx) {
                continue;
            }
            $afterClose = self::nextSignificantIndex($tokens, $closeIdx + 1);
            $line = self::lineForIndex($tokens, $innerIdx);
            $message = self::PARSE_MESSAGE_COMMA;
            if (null !== $afterClose && self::isToken($tokens, $afterClose, ';')) {
                $message = self::PARSE_MESSAGE_SEMICOLON;
            }
            throw new CompileFatal($filename, $line, $message);
        }

        return $code;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isDoubleColon(array $tokens, int $index): bool
    {
        return isset($tokens[$index])
            && \is_array($tokens[$index])
            && \T_DOUBLE_COLON === $tokens[$index][0];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isConstantEncapsedString(array $tokens, int $index): bool
    {
        return isset($tokens[$index])
            && \is_array($tokens[$index])
            && \T_CONSTANT_ENCAPSED_STRING === $tokens[$index][0];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isToken(array $tokens, int $index, string $char): bool
    {
        return isset($tokens[$index]) && \is_string($tokens[$index]) && $char === $tokens[$index];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function findMatchingCloseBrace(array $tokens, int $openIndex): ?int
    {
        $depth = 0;
        for ($i = $openIndex, $count = \count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
            if (\is_string($token)) {
                if ('{' === $token) {
                    ++$depth;
                    continue;
                }
                if ('}' === $token) {
                    --$depth;
                    if (0 === $depth) {
                        return $i;
                    }
                }
                continue;
            }
            if (\is_array($token) && T_CURLY_OPEN === $token[0]) {
                ++$depth;
            }
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function nextSignificantIndex(array $tokens, int $start): ?int
    {
        for ($i = $start, $count = \count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
            if (\is_array($token)) {
                if (\in_array($token[0], [\T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                return $i;
            }
            if (' ' === $token || "\t" === $token || "\n" === $token || "\r" === $token) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function lineForIndex(array $tokens, int $index): int
    {
        for ($i = $index; $i >= 0; --$i) {
            $token = $tokens[$i];
            if (\is_array($token) && isset($token[2])) {
                return (int) $token[2];
            }
        }

        return 1;
    }
}
