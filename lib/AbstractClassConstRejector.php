<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject `abstract` on class / interface / trait constants (#30011).
 *
 * php-src Zend 8.4+: {@see Zend/zend_compile.c} /
 * {@see Zend/zend_language_parser.y} —
 * {@code Cannot use the abstract modifier on a class constant}.
 *
 * nikic/php-parser either Syntax-errors valueless forms or reports
 * {@code Cannot use 'abstract' as constant modifier}; both diverge from Zend.
 */
final class AbstractClassConstRejector
{
    public const MESSAGE = 'Cannot use the abstract modifier on a class constant';

    /** @var list<int> */
    private const MEMBER_MODIFIERS = [
        T_ABSTRACT,
        T_FINAL,
        T_PUBLIC,
        T_PROTECTED,
        T_PRIVATE,
        T_STATIC,
        T_READONLY,
    ];

    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (ReferenceProfileTokenScan::shouldSkipReferenceProfileReject($code, $filename)) {
            return $code;
        }
        if (!str_contains($code, 'abstract') || !str_contains($code, 'const')) {
            return $code;
        }
        if (!\function_exists('token_get_all')) {
            return $code;
        }

        $tokens = token_get_all($code);
        $n = \count($tokens);
        for ($i = 0; $i < $n; ++$i) {
            $tok = $tokens[$i];
            if (!\is_array($tok) || T_ABSTRACT !== $tok[0]) {
                continue;
            }
            if (self::abstractPrecedesConst($tokens, $i, $n)) {
                throw new CompileFatal($filename, (int) $tok[2], self::MESSAGE);
            }
        }

        return $code;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function abstractPrecedesConst(array $tokens, int $abstractIndex, int $n): bool
    {
        $i = $abstractIndex + 1;
        while ($i < $n) {
            $tok = $tokens[$i];
            if (\is_array($tok) && self::isIgnorable($tok[0])) {
                ++$i;
                continue;
            }
            if (\is_array($tok) && T_ATTRIBUTE === $tok[0]) {
                $i = self::skipAttribute($tokens, $i, $n);
                continue;
            }
            if (\is_array($tok) && \in_array($tok[0], self::MEMBER_MODIFIERS, true)) {
                ++$i;
                continue;
            }
            if (\is_array($tok) && T_CONST === $tok[0]) {
                return true;
            }

            return false;
        }

        return false;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipAttribute(array $tokens, int $attrIndex, int $n): int
    {
        $depth = 0;
        for ($i = $attrIndex + 1; $i < $n; ++$i) {
            $tok = $tokens[$i];
            if (\is_string($tok)) {
                if ('[' === $tok) {
                    ++$depth;
                } elseif (']' === $tok) {
                    --$depth;
                    if (0 === $depth) {
                        return $i + 1;
                    }
                }
                continue;
            }
            if (T_ATTRIBUTE === $tok[0] && 0 === $depth) {
                return $i;
            }
        }

        return $n;
    }

    private static function isIgnorable(int $id): bool
    {
        return \in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }
}
