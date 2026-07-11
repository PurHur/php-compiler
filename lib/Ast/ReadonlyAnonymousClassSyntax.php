<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

/**
 * Detect `new readonly class` — PHP 8.3+ (ZEND_ACC_READONLY_ANON_CLASS, #6991, #16255).
 *
 * php-src: Zend/zend_language_parser.y / Zend/zend_compile.c — anonymous readonly class modifier.
 */
final class ReadonlyAnonymousClassSyntax
{
    /** Zend 8.2 reference profile message for `new readonly class` (#16255). */
    public const REFERENCE_PROFILE_UNEXPECTED_READONLY = 'syntax error, unexpected token "readonly"';

    /**
     * @return array{line: int, message: string}|null
     */
    public static function referenceProfileSyntaxError(string $code): ?array
    {
        if (!preg_match('/\bnew\b/i', $code)) {
            return null;
        }
        if (false === stripos($code, 'readonly')) {
            return null;
        }

        $len = \strlen($code);
        $i = 0;
        $line = 1;
        while ($i < $len) {
            $i = self::skipInsignificantAt($code, $i, $len, $line);
            if ($i >= $len) {
                break;
            }
            if (!self::matchKeywordAt($code, $i, $len, 'new')) {
                ++$i;
                if ("\n" === $code[$i - 1]) {
                    ++$line;
                }
                continue;
            }
            $j = self::skipInsignificantAt($code, $i + 3, $len, $line);
            if ($j >= $len || !self::matchKeywordAt($code, $j, $len, 'readonly')) {
                $i += 3;
                continue;
            }
            $readonlyLine = $line;
            $k = self::skipInsignificantAt($code, $j + 8, $len, $line);
            if ($k < $len && self::matchKeywordAt($code, $k, $len, 'class')) {
                return [
                    'line' => $readonlyLine,
                    'message' => self::REFERENCE_PROFILE_UNEXPECTED_READONLY,
                ];
            }
            $i += 3;
        }

        return null;
    }

    private static function matchKeywordAt(string $code, int $pos, int $len, string $keyword): bool
    {
        $klen = \strlen($keyword);
        if ($pos + $klen > $len) {
            return false;
        }
        if (0 !== strcasecmp(substr($code, $pos, $klen), $keyword)) {
            return false;
        }
        if ($pos > 0 && self::isIdentifierChar($code[$pos - 1])) {
            return false;
        }
        if ($pos + $klen < $len && self::isIdentifierChar($code[$pos + $klen])) {
            return false;
        }

        return true;
    }

    private static function isIdentifierChar(string $ch): bool
    {
        return ctype_alnum($ch) || '_' === $ch;
    }

    private static function skipInsignificantAt(string $code, int $pos, int $len, int &$line): int
    {
        while ($pos < $len) {
            $ch = $code[$pos];
            if (ctype_space($ch)) {
                if ("\n" === $ch) {
                    ++$line;
                }
                ++$pos;
                continue;
            }
            if ('#' === $ch) {
                $pos = self::skipLineComment($code, $pos + 1, $len, $line);
                continue;
            }
            if ($pos + 1 < $len && '/' === $ch) {
                if ('/' === $code[$pos + 1]) {
                    $pos = self::skipLineComment($code, $pos + 2, $len, $line);
                    continue;
                }
                if ('*' === $code[$pos + 1]) {
                    $pos = self::skipBlockComment($code, $pos + 2, $len, $line);
                    continue;
                }
            }
            if ("'" === $ch) {
                $pos = self::skipSingleQuotedString($code, $pos + 1, $len, $line);
                continue;
            }
            if ('"' === $ch) {
                $pos = self::skipDoubleQuotedString($code, $pos + 1, $len, $line);
                continue;
            }

            return $pos;
        }

        return $pos;
    }

    private static function skipLineComment(string $code, int $pos, int $len, int &$line): int
    {
        while ($pos < $len) {
            if ("\n" === $code[$pos]) {
                ++$line;
                return $pos + 1;
            }
            ++$pos;
        }

        return $pos;
    }

    private static function skipBlockComment(string $code, int $pos, int $len, int &$line): int
    {
        while ($pos + 1 < $len) {
            if ("\n" === $code[$pos]) {
                ++$line;
            }
            if ('*' === $code[$pos] && '/' === $code[$pos + 1]) {
                return $pos + 2;
            }
            ++$pos;
        }

        return $len;
    }

    private static function skipSingleQuotedString(string $code, int $pos, int $len, int &$line): int
    {
        while ($pos < $len) {
            $ch = $code[$pos];
            if ("\n" === $ch) {
                ++$line;
            }
            if ("'" === $ch) {
                return $pos + 1;
            }
            if ('\\' === $ch && $pos + 1 < $len) {
                $pos += 2;
                continue;
            }
            ++$pos;
        }

        return $pos;
    }

    private static function skipDoubleQuotedString(string $code, int $pos, int $len, int &$line): int
    {
        while ($pos < $len) {
            $ch = $code[$pos];
            if ("\n" === $ch) {
                ++$line;
            }
            if ('"' === $ch) {
                return $pos + 1;
            }
            if ('\\' === $ch && $pos + 1 < $len) {
                $pos += 2;
                continue;
            }
            ++$pos;
        }

        return $pos;
    }
}
