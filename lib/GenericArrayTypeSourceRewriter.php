<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Rewrite PHP 8.3+ list&lt;T&gt; / array&lt;K,V&gt; declarations for nikic/php-parser v4 (#3705).
 *
 * Zend accepts these forms; php-parser v4 treats list as destructuring only. We lower to magic
 * identifier type names that php-cfg parses as Op\Type\Literal, then recover via {@see GenericArrayTypeSpec}.
 */
final class GenericArrayTypeSourceRewriter
{
    public static function rewrite(string $code): string
    {
        if (!str_contains($code, 'list') && !str_contains($code, 'array')) {
            return $code;
        }

        $tokens = token_get_all($code);
        $n = \count($tokens);
        $out = '';
        $i = 0;

        while ($i < $n) {
            $tok = $tokens[$i];
            if (!\is_array($tok) || T_ARRAY !== $tok[0] && T_LIST !== $tok[0]) {
                $out .= \is_array($tok) ? $tok[1] : $tok;
                ++$i;
                continue;
            }

            $j = self::skipIgnorable($tokens, $i + 1);
            if ($j >= $n) {
                $out .= $tok[1];
                ++$i;
                continue;
            }

            $next = $tokens[$j];
            $nextText = \is_array($next) ? $next[1] : $next;

            if (T_LIST === $tok[0]) {
                if ('(' === $nextText) {
                    $out .= $tok[1];
                    ++$i;
                    continue;
                }
                if ('<' === $nextText) {
                    $parsed = self::parseAngleGeneric($tokens, $j + 1);
                    if (null !== $parsed) {
                        [$inner, $end] = $parsed;
                        $out .= GenericArrayTypeSpec::encodeList(self::firstTypeSegment($inner));
                        $i = $end;
                        continue;
                    }
                }
                if (\is_array($next) && T_VARIABLE === $next[0]) {
                    $out .= GenericArrayTypeSpec::encodeList('mixed');
                    ++$i;
                    continue;
                }
            }

            if (T_ARRAY === $tok[0] && '<' === $nextText) {
                $parsed = self::parseAngleGeneric($tokens, $j + 1);
                if (null !== $parsed) {
                    [$inner, $end] = $parsed;
                    [$key, $value] = self::splitKeyValue($inner);
                    $out .= GenericArrayTypeSpec::encodeArray($key, $value);
                    $i = $end;
                    continue;
                }
            }

            $out .= $tok[1];
            ++$i;
        }

        return $out;
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

    /**
     * @param list<mixed> $tokens
     *
     * @return null|array{0: string, 1: int}
     */
    private static function parseAngleGeneric(array $tokens, int $start): ?array
    {
        $n = \count($tokens);
        $depth = 0;
        $buf = '';
        $i = $start;

        for (; $i < $n; ++$i) {
            $tok = $tokens[$i];
            $text = \is_array($tok) ? $tok[1] : $tok;

            if (0 === $depth && '>' === $text) {
                return [trim($buf), $i + 1];
            }

            if ('<' === $text || '(' === $text || '[' === $text) {
                ++$depth;
            } elseif ('>' === $text || ')' === $text || ']' === $text) {
                --$depth;
            }

            $buf .= $text;
        }

        return null;
    }

    private static function firstTypeSegment(string $inner): string
    {
        $parts = preg_split('/\s*,\s*/', $inner, 2);
        $first = trim($parts[0] ?? 'mixed');

        return '' !== $first ? $first : 'mixed';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitKeyValue(string $inner): array
    {
        $depth = 0;
        $len = strlen($inner);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $inner[$i];
            if ('<' === $ch || '(' === $ch || '[' === $ch) {
                ++$depth;
                continue;
            }
            if ('>' === $ch || ')' === $ch || ']' === $ch) {
                --$depth;
                continue;
            }
            if (0 === $depth && ',' === $ch) {
                $key = trim(substr($inner, 0, $i));
                $value = trim(substr($inner, $i + 1));

                return [
                    '' !== $key ? $key : 'mixed',
                    '' !== $value ? $value : 'mixed',
                ];
            }
        }

        return [self::firstTypeSegment($inner), 'mixed'];
    }
}
