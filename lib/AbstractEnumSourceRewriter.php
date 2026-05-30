<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Lower `abstract enum` for nikic/php-parser v4/v5 (#3737).
 *
 * Zend/php-src may gain this syntax later; php-parser currently rejects `abstract enum`.
 * We strip the modifier before parse and record declaration line numbers for {@see Ast\AbstractEnumMarker}.
 */
final class AbstractEnumSourceRewriter
{
    /**
     * @return array{0: string, 1: array<int, true>}
     */
    public static function rewrite(string $code): array
    {
        if (!str_contains($code, 'abstract') || !str_contains($code, 'enum')) {
            return [$code, []];
        }

        $tokens = token_get_all($code);
        $n = \count($tokens);
        $out = '';
        /** @var array<int, true> $abstractLines */
        $abstractLines = [];

        for ($i = 0; $i < $n; ++$i) {
            $tok = $tokens[$i];
            if (\is_array($tok) && \defined('T_ABSTRACT') && T_ABSTRACT === $tok[0]) {
                $j = self::skipIgnorable($tokens, $i + 1);
                if ($j < $n && \is_array($tokens[$j]) && \defined('T_ENUM') && T_ENUM === $tokens[$j][0]) {
                    $abstractLines[(int) $tok[2]] = true;
                    continue;
                }
            }

            $out .= \is_array($tok) ? $tok[1] : $tok;
        }

        return [$out, $abstractLines];
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
