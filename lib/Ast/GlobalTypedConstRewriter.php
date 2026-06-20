<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PHPCompiler\CompilerVersion;
use PhpParser\Error as ParserError;

/**
 * Rewrite PHP 8.3+ file/namespace typed constants for nikic/php-parser 4.x (#7081).
 *
 * php-src: Zend/zend_compile.c — compile-unit typed const (PHP 8.3+); class scope already
 * parses via Stmt\ClassConst; file scope needs a marker for PHPCfg recovery.
 */
final class GlobalTypedConstRewriter
{
    public const MARKER_PREFIX = 'phpc-global-typed-const:';

    /** @internal Marker embedded in source for PHPCfg to recover declared type. */
    public const MARKER_PATTERN = '/\/\*\s*phpc-global-typed-const:([^*]+?)\s*\*\//';

    /** Zend PHP ≤8.3: `final const` at compile-unit scope is invalid (#10324, zend_compile.c). */
    public const FINAL_GLOBAL_CONST_REJECT_MESSAGE = 'syntax error, unexpected token "const", expecting "abstract" or "final" or "readonly" or "class"';

    public static function rewrite(string $source): string
    {
        if (!CompilerVersion::supportsGlobalTypedConstants()) {
            return $source;
        }
        if (false === stripos($source, 'const')) {
            return $source;
        }

        $tokens = token_get_all($source);
        $n = \count($tokens);
        $out = '';
        $classLikeDepth = 0;
        $pendingClassLike = false;

        for ($i = 0; $i < $n; ++$i) {
            $tok = $tokens[$i];
            $text = self::tokenText($tok);

            if (\is_array($tok)) {
                if (\in_array($tok[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                    $pendingClassLike = true;
                } elseif ('{' === $text && $pendingClassLike) {
                    ++$classLikeDepth;
                    $pendingClassLike = false;
                } elseif ('}' === $text && $classLikeDepth > 0) {
                    --$classLikeDepth;
                } elseif (T_FINAL === $tok[0] && 0 === $classLikeDepth) {
                    if (!CompilerVersion::supportsFinalGlobalTypedConstants()) {
                        self::rejectFinalGlobalConstIfPresent($tokens, $i + 1);
                    }
                    $typed = self::tryParseFinalTypedConst($tokens, $i + 1);
                    if (null !== $typed) {
                        [$typeExpr, $end] = $typed;
                        self::rejectDisallowedGlobalConstType($typeExpr, $tok[2] ?? 1);
                        $out .= '/*'.self::MARKER_PREFIX.'final:'.$typeExpr.'*/ const ';
                        $i = $end - 1;
                        continue;
                    }
                } elseif (T_CONST === $tok[0] && 0 === $classLikeDepth) {
                    $typed = self::tryParseTypedConst($tokens, $i + 1);
                    if (null !== $typed) {
                        [$typeExpr, $end] = $typed;
                        self::rejectDisallowedGlobalConstType($typeExpr, $tok[2] ?? 1);
                        $out .= '/*'.self::MARKER_PREFIX.$typeExpr.'*/ '.$text.' ';
                        $i = $end - 1;
                        continue;
                    }
                }
            } elseif ('{' === $text && $pendingClassLike) {
                ++$classLikeDepth;
                $pendingClassLike = false;
            } elseif ('}' === $text && $classLikeDepth > 0) {
                --$classLikeDepth;
            }

            $out .= $text;
        }

        return $source === $out ? $source : $out;
    }

    /**
     * @return array{0: string, 1: bool}|null [type expression, isFinal]
     */
    public static function parseMarkerPayload(string $payload): ?array
    {
        $payload = trim($payload);
        if ('' === $payload) {
            return null;
        }
        $isFinal = false;
        if (str_starts_with($payload, 'final:')) {
            $isFinal = true;
            $payload = substr($payload, 6);
        }
        $payload = trim($payload);
        if ('' === $payload) {
            return null;
        }

        return [$payload, $isFinal];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: string, 1: int}|null [type expression, index of const name token]
     */
    private static function tryParseFinalTypedConst(array $tokens, int $start): ?array
    {
        $j = self::skipIgnorable($tokens, $start, \count($tokens));
        if ($j >= \count($tokens) || !\is_array($tokens[$j]) || T_CONST !== $tokens[$j][0]) {
            return null;
        }

        return self::tryParseTypedConst($tokens, $j + 1);
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: string, 1: int}|null [type expression, index of const name token]
     */
    private static function tryParseTypedConst(array $tokens, int $start): ?array
    {
        $j = self::skipIgnorable($tokens, $start, \count($tokens));
        if ($j >= \count($tokens) || !self::isTypeStart($tokens, $j)) {
            return null;
        }

        $typeStart = $j;
        $j = self::parseTypeExpression($tokens, $j);
        if (null === $j) {
            return null;
        }

        $nameIdx = self::skipIgnorable($tokens, $j, \count($tokens));
        if ($nameIdx >= \count($tokens) || !self::isConstName($tokens, $nameIdx)) {
            return null;
        }

        $eqIdx = self::skipIgnorable($tokens, $nameIdx + 1, \count($tokens));
        if ($eqIdx >= \count($tokens) || '=' !== self::tokenText($tokens[$eqIdx])) {
            return null;
        }

        $typeExpr = self::collapseWhitespace(
            self::concatTokens($tokens, $typeStart, $j - 1)
        );

        return [$typeExpr, $nameIdx];
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
    private static function isConstName(array $tokens, int $i): bool
    {
        $tok = $tokens[$i];
        if (!\is_array($tok) || T_STRING !== $tok[0]) {
            return false;
        }
        $next = self::skipIgnorable($tokens, $i + 1, \count($tokens));
        if ($next < \count($tokens) && '::' === self::tokenText($tokens[$next])) {
            return false;
        }

        return true;
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
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function rejectFinalGlobalConstIfPresent(array $tokens, int $start): void
    {
        $j = self::skipIgnorable($tokens, $start, \count($tokens));
        if ($j >= \count($tokens) || !\is_array($tokens[$j]) || T_CONST !== $tokens[$j][0]) {
            return;
        }
        throw new ParserError(
            self::FINAL_GLOBAL_CONST_REJECT_MESSAGE,
            ['startLine' => $tokens[$j][2] ?? 1, 'endLine' => $tokens[$j][2] ?? 1]
        );
    }

    private static function rejectDisallowedGlobalConstType(string $typeExpr, int $line): void
    {
        $lower = strtolower($typeExpr);
        foreach (['void', 'never', 'callable', 'object'] as $bad) {
            if ($lower === $bad || str_contains($lower, '|'.$bad) || str_contains($lower, $bad.'|')) {
                throw new ParserError(
                    sprintf('Typed constant cannot have type %s', $bad),
                    ['startLine' => $line, 'endLine' => $line]
                );
            }
        }
        if (preg_match('/(?:^|[|&(])self(?:$|[|&)])/', $lower)
            || preg_match('/(?:^|[|&(])parent(?:$|[|&)])/', $lower)
            || preg_match('/(?:^|[|&(])static(?:$|[|&)])/', $lower)
        ) {
            throw new ParserError(
                'Typed constant cannot have type self, parent, or static',
                ['startLine' => $line, 'endLine' => $line]
            );
        }
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function tokenText($token): string
    {
        return \is_array($token) ? $token[1] : $token;
    }
}
