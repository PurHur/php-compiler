<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject `abstract enum` — Zend has no such production (#26519, re-#3737).
 *
 * Earlier #3737 stripped the modifier so nikic/php-parser would accept a non-Zend
 * extension. php-src {@see Zend/zend_language_parser.y} only allows
 * `abstract`/`final`/`readonly` before `class`, never before `enum`.
 */
final class AbstractEnumSourceRewriter
{
    /** Zend/zend_language_parser.y — same shape as `final enum` / `readonly enum`. */
    public const MESSAGE = 'syntax error, unexpected token "enum", expecting "abstract" or "final" or "readonly" or "class"';

    /**
     * @deprecated Use {@see reject()}; kept for call sites that still expect a pair.
     *
     * @return array{0: string, 1: array<int, true>}
     */
    public static function rewrite(string $code): array
    {
        return [$code, []];
    }

    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (!str_contains($code, 'abstract') || !str_contains($code, 'enum')) {
            return $code;
        }
        if (!\function_exists('token_get_all')) {
            return $code;
        }

        $tokens = token_get_all($code);
        $n = \count($tokens);
        for ($i = 0; $i < $n; ++$i) {
            $tok = $tokens[$i];
            if (!\is_array($tok) || !\defined('T_ABSTRACT') || T_ABSTRACT !== $tok[0]) {
                continue;
            }
            $j = self::skipIgnorable($tokens, $i + 1);
            if ($j < $n && \is_array($tokens[$j]) && \defined('T_ENUM') && T_ENUM === $tokens[$j][0]) {
                throw new CompileFatal($filename, (int) $tok[2], self::MESSAGE);
            }
        }

        return $code;
    }

    /**
     * @param list<mixed> $tokens
     */
    private static function skipIgnorable(array $tokens, int $start): int
    {
        $n = \count($tokens);
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
}
