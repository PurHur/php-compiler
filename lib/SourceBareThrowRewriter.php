<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler;

/**
 * Lower bare `throw;` (rethrow in catch) to `throw null;` for nikic/php-parser v4 (#3508).
 *
 * php-src reference: Zend/zend_compile.c — rethrow active catch exception (PHP 8+).
 * The compiler maps marked lines to TYPE_RETHROW instead of throwing null.
 *
 * @return array{0: string, 1: array<int, true>} rewritten source and 1-based line map
 */
final class SourceBareThrowRewriter
{
    public static function rewrite(string $code): array
    {
        if (!str_contains($code, 'throw')) {
            return [$code, []];
        }

        $tokens = token_get_all($code);
        $bareLines = [];
        $out = '';
        $n = \count($tokens);

        for ($i = 0; $i < $n; ++$i) {
            $tok = $tokens[$i];
            if (!\is_array($tok) || T_THROW !== $tok[0]) {
                $out .= \is_array($tok) ? $tok[1] : $tok;
                continue;
            }

            $j = self::nextMeaningfulTokenIndex($tokens, $i + 1);
            if ($j >= $n) {
                $out .= $tok[1];
                continue;
            }

            $next = $tokens[$j];
            if (!\is_array($next) && ';' === $next) {
                $line = $tok[2] ?? 0;
                if ($line > 0) {
                    $bareLines[$line] = true;
                }
                $out .= $tok[1].' null';
                continue;
            }

            $out .= $tok[1];
        }

        return [$out, $bareLines];
    }

    /**
     * @param list<mixed> $tokens
     */
    private static function nextMeaningfulTokenIndex(array $tokens, int $start): int
    {
        $n = \count($tokens);
        for ($j = $start; $j < $n; ++$j) {
            $tok = $tokens[$j];
            if (!\is_array($tok)) {
                return $j;
            }
            $id = $tok[0];
            if (\in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG_WITH_ECHO], true)) {
                continue;
            }

            return $j;
        }

        return $n;
    }
}
