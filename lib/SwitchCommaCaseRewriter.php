<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler;

/**
 * Desugar PHP 8.0+ comma-separated switch labels for nikic/php-parser v4 (#3608).
 *
 * `case 1, 2:` becomes `case 1:` + `case 2:` (fall-through), matching Zend zend_compile.c.
 */
final class SwitchCommaCaseRewriter
{
    public static function rewrite(string $code): string
    {
        if (!str_contains($code, 'case')) {
            return $code;
        }

        $tokens = token_get_all($code);
        $n = \count($tokens);
        $out = '';
        $i = 0;

        while ($i < $n) {
            $tok = $tokens[$i];
            if (!\is_array($tok) || T_CASE !== $tok[0]) {
                $out .= \is_array($tok) ? $tok[1] : $tok;
                ++$i;
                continue;
            }

            $parsed = self::parseCommaCaseLabels($tokens, $i + 1);
            if (null === $parsed) {
                $out .= $tok[1];
                ++$i;
                continue;
            }

            [$segments, $separator, $endIndex] = $parsed;
            if (\count($segments) <= 1) {
                for ($j = $i; $j < $endIndex; ++$j) {
                    $out .= \is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                }
                $i = $endIndex;
                continue;
            }

            $last = \count($segments) - 1;
            foreach ($segments as $idx => $label) {
                $out .= 'case '.$label;
                $out .= $idx === $last ? $separator : ':';
            }
            $i = $endIndex;
        }

        return $out;
    }

    /**
     * @param list<mixed> $tokens
     *
     * @return null|array{0: list<string>, 1: string, 2: int}
     */
    private static function parseCommaCaseLabels(array $tokens, int $start): ?array
    {
        $n = \count($tokens);
        $depth = 0;
        $segments = [];
        $current = '';
        $i = $start;

        for (; $i < $n; ++$i) {
            $tok = $tokens[$i];
            if (\is_array($tok)) {
                if (self::isIgnorableToken($tok[0])) {
                    if ('' !== $current) {
                        $current .= $tok[1];
                    }
                    continue;
                }
                $text = $tok[1];
                $id = $tok[0];
            } else {
                $text = $tok;
                $id = null;
            }

            if (0 === $depth && (',' === $text || ':' === $text || ';' === $text)) {
                $segments[] = trim($current);
                $current = '';
                if (',' === $text) {
                    continue;
                }

                return [$segments, $text, $i + 1];
            }

            if ('(' === $text || '[' === $text || '{' === $text) {
                ++$depth;
            } elseif (')' === $text || ']' === $text || '}' === $text) {
                --$depth;
            }

            $current .= $text;
        }

        return null;
    }

    private static function isIgnorableToken(int $id): bool
    {
        return \in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }
}
