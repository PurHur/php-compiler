<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PHPCompiler\CompilerVersion;

/**
 * Rewrite PHP 8.3+ typed function-local static variables for nikic/php-parser 4.x (#9998).
 *
 * php-src: Zend/zend_compile.c — typed static local compilation + runtime type checks.
 */
final class TypedFunctionStaticRewriter
{
    public const MARKER_PREFIX = 'phpc-typed-function-static:';

    /** @internal Marker embedded before the variable name for PHPCfg recovery. */
    public const MARKER_PATTERN = '/\/\*\s*phpc-typed-function-static:([^*]+?)\s*\*\//';

    public static function rewrite(string $source): string
    {
        if (!CompilerVersion::supportsTypedFunctionStatic()) {
            return $source;
        }
        if (false === stripos($source, 'static')) {
            return $source;
        }

        $tokens = token_get_all($source);
        $n = \count($tokens);
        $out = '';
        $classLikeDepth = 0;
        $pendingClassLike = false;
        $pendingFunction = false;
        $inFunction = false;
        $functionBraceLevel = 0;
        $braceDepth = 0;

        for ($i = 0; $i < $n; ++$i) {
            $tok = $tokens[$i];
            $text = self::tokenText($tok);

            if (\is_array($tok)) {
                if (\in_array($tok[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                    $pendingClassLike = true;
                } elseif (T_FUNCTION === $tok[0]) {
                    $pendingFunction = true;
                } elseif (T_STATIC === $tok[0]
                    && !self::staticIsVisibilityModifierContext($tokens, $i)
                    && !($classLikeDepth > 0 && !$inFunction)) {
                    $typed = self::tryParseTypedStaticVar($tokens, $i + 1);
                    if (null !== $typed) {
                        [$typeExpr, $varStart, $varEnd] = $typed;
                        $out .= 'static ';
                        $out .= '/*'.self::MARKER_PREFIX.$typeExpr.'*/ ';
                        for ($j = $varStart; $j <= $varEnd; ++$j) {
                            $out .= self::tokenText($tokens[$j]);
                        }
                        $i = $varEnd;
                        continue;
                    }
                }
            } elseif ('{' === $text) {
                if ($pendingClassLike) {
                    ++$classLikeDepth;
                    $pendingClassLike = false;
                }
                if ($pendingFunction) {
                    $inFunction = true;
                    $functionBraceLevel = $braceDepth + 1;
                    $pendingFunction = false;
                }
                ++$braceDepth;
            } elseif ('}' === $text) {
                if ($inFunction && $braceDepth === $functionBraceLevel) {
                    $inFunction = false;
                }
                if ($classLikeDepth > 0 && 1 === $braceDepth) {
                    --$classLikeDepth;
                }
                if ($braceDepth > 0) {
                    --$braceDepth;
                }
            }

            $out .= $text;
        }

        return $source === $out ? $source : $out;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function staticIsVisibilityModifierContext(array $tokens, int $staticIdx): bool
    {
        $j = $staticIdx - 1;
        while ($j >= 0) {
            $tok = $tokens[$j];
            if (!\is_array($tok)) {
                return false;
            }
            if (\in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                --$j;
                continue;
            }

            return \in_array($tok[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY, T_VAR], true);
        }

        return false;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: string, 1: int, 2: int}|null [type expression, var token start, var token end]
     */
    private static function tryParseTypedStaticVar(array $tokens, int $start): ?array
    {
        $n = \count($tokens);
        $j = self::skipIgnorable($tokens, $start, $n);
        if ($j >= $n) {
            return null;
        }
        if (\is_array($tokens[$j]) && T_FUNCTION === $tokens[$j][0]) {
            return null;
        }
        if ('::' === self::tokenText($tokens[$j])) {
            return null;
        }
        if (\is_array($tokens[$j]) && T_VARIABLE === $tokens[$j][0]) {
            return null;
        }
        if (!self::isTypeStart($tokens, $j)) {
            return null;
        }

        $typeStart = $j;
        $j = self::parseTypeExpression($tokens, $j);
        if (null === $j) {
            return null;
        }

        $varIdx = self::skipIgnorable($tokens, $j, $n);
        if ($varIdx >= $n || !\is_array($tokens[$varIdx]) || T_VARIABLE !== $tokens[$varIdx][0]) {
            return null;
        }

        $typeExpr = self::collapseWhitespace(self::concatTokens($tokens, $typeStart, $j - 1));

        return [$typeExpr, $varIdx, $varIdx];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function parseTypeExpression(array $tokens, int $i): ?int
    {
        $n = \count($tokens);
        $i = self::skipIgnorable($tokens, $i, $n);
        if ($i >= $n) {
            return null;
        }

        if ('?' === self::tokenText($tokens[$i])) {
            ++$i;
            $i = self::skipIgnorable($tokens, $i, $n);
        }

        if ($i >= $n) {
            return null;
        }

        if ('(' === self::tokenText($tokens[$i])) {
            $depth = 1;
            ++$i;
            while ($i < $n && $depth > 0) {
                $ch = self::tokenText($tokens[$i]);
                if ('(' === $ch) {
                    ++$depth;
                } elseif (')' === $ch) {
                    --$depth;
                }
                ++$i;
            }
        } else {
            if (!self::isAtomicType($tokens, $i)) {
                return null;
            }
            ++$i;
        }

        while ($i < $n) {
            $i = self::skipIgnorable($tokens, $i, $n);
            if ($i >= $n) {
                break;
            }
            $ch = self::tokenText($tokens[$i]);
            if ('|' !== $ch && '&' !== $ch) {
                break;
            }
            ++$i;
            $i = self::skipIgnorable($tokens, $i, $n);
            if ($i >= $n) {
                return null;
            }
            if ('(' === self::tokenText($tokens[$i])) {
                $depth = 1;
                ++$i;
                while ($i < $n && $depth > 0) {
                    $ch = self::tokenText($tokens[$i]);
                    if ('(' === $ch) {
                        ++$depth;
                    } elseif (')' === $ch) {
                        --$depth;
                    }
                    ++$i;
                }
            } else {
                if (!self::isAtomicType($tokens, $i)) {
                    return null;
                }
                ++$i;
            }
        }

        return $i;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isTypeStart(array $tokens, int $i): bool
    {
        $text = self::tokenText($tokens[$i]);

        return '?' === $text || '(' === $text || self::isAtomicType($tokens, $i);
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isAtomicType(array $tokens, int $i): bool
    {
        $tok = $tokens[$i];
        if (!\is_array($tok)) {
            return false;
        }

        return \in_array($tok[0], [T_ARRAY, T_CALLABLE, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true);
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipIgnorable(array $tokens, int $i, int $n): int
    {
        while ($i < $n) {
            $tok = $tokens[$i];
            if (!\is_array($tok)) {
                break;
            }
            if (!\in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                break;
            }
            ++$i;
        }

        return $i;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function concatTokens(array $tokens, int $start, int $end): string
    {
        $buf = '';
        for ($i = $start; $i <= $end; ++$i) {
            $buf .= self::tokenText($tokens[$i]);
        }

        return $buf;
    }

    private static function collapseWhitespace(string $typeExpr): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $typeExpr));
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function tokenText($token): string
    {
        return \is_array($token) ? $token[1] : $token;
    }
}
