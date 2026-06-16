<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Operand;
use PHPCfg\Op\Expr\New_;

/**
 * Detect explicit constructor parentheses on `new` (#6549, PHP 8.3 const expr #9116).
 *
 * php-src: Zend/zend_compile.c — zend_compile_const_expr(); bare `new Class` is invalid in constants.
 */
final class NewCtorParens
{
    /** @var array<string, int> */
    private static array $matchCursor = [];

    public static function resetMatchCursor(): void
    {
        self::$matchCursor = [];
    }

    public static function hasCtorParens(New_ $op, ?string $sourceCode = null): bool
    {
        if ($op->hasAttribute('newHasCtorParens')) {
            return (bool) $op->getAttribute('newHasCtorParens');
        }
        if ([] !== $op->args) {
            return true;
        }
        $start = $op->getAttribute('startFilePos', -1);
        $end = $op->getAttribute('endFilePos', -1);
        if (is_int($start) && is_int($end) && $start >= 0 && $end >= $start && null !== $sourceCode && '' !== $sourceCode) {
            $snippet = substr($sourceCode, $start, $end - $start + 1);
            if (is_string($snippet) && (bool) preg_match('/\)\s*$/', $snippet)) {
                return true;
            }
        }

        return self::hasCtorParensFromTokens($op, $sourceCode);
    }

    private static function hasCtorParensFromTokens(New_ $op, ?string $sourceCode): bool
    {
        if (null === $sourceCode || '' === $sourceCode || !function_exists('token_get_all')) {
            return false;
        }
        $className = self::literalClassName($op->class);
        if (null === $className) {
            return false;
        }
        $tokens = token_get_all($sourceCode);
        if (!is_array($tokens)) {
            return false;
        }
        $classParts = explode('\\', ltrim($className, '\\'));
        $line = $op->getLine();
        $matchKey = $line.':'.$className;
        $wantOccurrence = self::$matchCursor[$matchKey] ?? 0;
        self::$matchCursor[$matchKey] = $wantOccurrence + 1;
        $n = count($tokens);
        $seen = 0;
        for ($i = 0; $i < $n; ++$i) {
            $t = $tokens[$i];
            if (!is_array($t) || T_NEW !== $t[0]) {
                continue;
            }
            if ($line > 0 && $t[2] !== $line) {
                continue;
            }
            $j = self::skipInsignificantTokens($tokens, $i + 1, $n);
            if (!self::tokensMatchClassName($tokens, $j, $n, $classParts)) {
                continue;
            }
            if ($seen < $wantOccurrence) {
                ++$seen;
                continue;
            }
            $j = self::advancePastClassNameTokens($tokens, $j, $n, $classParts);
            $j = self::skipInsignificantTokens($tokens, $j, $n);

            return $j < $n && !is_array($tokens[$j]) && '(' === $tokens[$j];
        }

        return false;
    }

    private static function literalClassName(Operand $class): ?string
    {
        if ($class instanceof Operand\Literal && is_string($class->value)) {
            return $class->value;
        }
        if ($class instanceof Operand\Literal && $class->value instanceof Operand) {
            return self::literalClassName($class->value);
        }

        return null;
    }

    /**
     * @param list<mixed> $tokens
     */
    private static function skipInsignificantTokens(array $tokens, int $i, int $n): int
    {
        while ($i < $n) {
            $t = $tokens[$i];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                ++$i;
                continue;
            }
            break;
        }

        return $i;
    }

    /**
     * @param list<mixed> $tokens
     * @param list<string> $classParts
     */
    private static function tokensMatchClassName(array $tokens, int $i, int $n, array $classParts): bool
    {
        if ([] === $classParts) {
            return false;
        }
        if ($i < $n && !is_array($tokens[$i]) && '\\' === $tokens[$i]) {
            ++$i;
        }
        foreach ($classParts as $part) {
            $i = self::skipInsignificantTokens($tokens, $i, $n);
            if ($i >= $n || !is_array($tokens[$i]) || T_STRING !== $tokens[$i][0]) {
                return false;
            }
            if (strcasecmp($tokens[$i][1], $part) !== 0) {
                return false;
            }
            ++$i;
            $i = self::skipInsignificantTokens($tokens, $i, $n);
            if ($i < $n && !is_array($tokens[$i]) && '\\' === $tokens[$i]) {
                ++$i;
            } else {
                break;
            }
        }

        return true;
    }

    /**
     * @param list<mixed> $tokens
     * @param list<string> $classParts
     */
    private static function advancePastClassNameTokens(array $tokens, int $i, int $n, array $classParts): int
    {
        if ($i < $n && !is_array($tokens[$i]) && '\\' === $tokens[$i]) {
            ++$i;
        }
        foreach ($classParts as $partIndex => $part) {
            $i = self::skipInsignificantTokens($tokens, $i, $n);
            if ($i < $n && is_array($tokens[$i]) && T_STRING === $tokens[$i][0]) {
                ++$i;
            }
            if ($partIndex < count($classParts) - 1) {
                $i = self::skipInsignificantTokens($tokens, $i, $n);
                if ($i < $n && !is_array($tokens[$i]) && '\\' === $tokens[$i]) {
                    ++$i;
                }
            }
        }

        return $i;
    }
}
