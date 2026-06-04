<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Desugar PHP 8.4 comma-separated enum case lists for nikic/php-parser v4 (#5479).
 *
 * `case A, B, C;` inside an enum body becomes `case A; case B; case C;`
 * (php-src: Zend/zend_compile.c enum case list, PHP 8.4).
 */
final class EnumCaseListRewriter
{
    public static function rewrite(string $code): string
    {
        if (!str_contains($code, 'enum') || !str_contains($code, 'case')) {
            return $code;
        }

        $tokens = token_get_all($code);
        $n = \count($tokens);
        $out = '';
        $pendingEnum = false;
        $inEnum = false;
        $enumBraceDepth = 0;
        $i = 0;

        while ($i < $n) {
            $tok = $tokens[$i];
            $text = \is_array($tok) ? $tok[1] : $tok;

            if (!$inEnum && !$pendingEnum && \is_array($tok) && \defined('T_ENUM') && T_ENUM === $tok[0]) {
                $out .= $tok[1];
                ++$i;
                $pendingEnum = true;
                continue;
            }

            if ($pendingEnum) {
                $out .= $text;
                ++$i;
                if ('{' === $text) {
                    $pendingEnum = false;
                    $inEnum = true;
                    $enumBraceDepth = 1;
                }
                continue;
            }

            if ($inEnum) {
                if ('{' === $text) {
                    ++$enumBraceDepth;
                    $out .= $text;
                    ++$i;
                    continue;
                }
                if ('}' === $text) {
                    --$enumBraceDepth;
                    $out .= $text;
                    ++$i;
                    if ($enumBraceDepth <= 0) {
                        $inEnum = false;
                        $enumBraceDepth = 0;
                    }
                    continue;
                }

                if (1 === $enumBraceDepth && \is_array($tok) && T_CASE === $tok[0]) {
                    $parsed = self::parseCommaEnumCaseLabels($tokens, $i + 1);
                    if (null !== $parsed) {
                        [$segments, $endIndex] = $parsed;
                        if (\count($segments) > 1) {
                            foreach ($segments as $label) {
                                $out .= 'case '.$label.';';
                            }
                            $i = $endIndex;
                            continue;
                        }
                    }
                }
            }

            $out .= $text;
            ++$i;
        }

        return $out;
    }

    /**
     * @param list<mixed> $tokens
     *
     * @return null|array{0: list<string>, 1: int}
     */
    private static function parseCommaEnumCaseLabels(array $tokens, int $start): ?array
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
            } else {
                $text = $tok;
            }

            if (0 === $depth && (',' === $text || ';' === $text)) {
                $segments[] = trim($current);
                $current = '';
                if (',' === $text) {
                    continue;
                }

                return [$segments, $i + 1];
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
