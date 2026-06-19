<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject `readonly function` outside class/property contexts (#10012).
 *
 * php-src: Zend/zend_language_parser.y — `readonly` is valid on classes and typed
 * properties only, not on functions or closures.
 */
final class ReadonlyFunctionRejector
{
    public const MESSAGE = 'syntax error, unexpected token "function", expecting "abstract" or "final" or "readonly" or "class"';

    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (!str_contains($code, 'readonly') || !str_contains($code, 'function')) {
            return $code;
        }
        if (!\function_exists('token_get_all')) {
            return $code;
        }

        $tokens = token_get_all($code);
        $braceDepth = 0;
        $classBodyDepth = null;
        $methodBodyDepth = null;
        $awaitingClassBrace = false;
        $pendingNewClass = false;
        $atMemberStart = false;

        for ($i = 0, $n = \count($tokens); $i < $n; ++$i) {
            $token = $tokens[$i];

            if (\is_string($token)) {
                if ('{' === $token) {
                    ++$braceDepth;
                    if ($awaitingClassBrace) {
                        $classBodyDepth = $braceDepth;
                        $awaitingClassBrace = false;
                        $pendingNewClass = false;
                        $atMemberStart = true;
                    }
                } elseif ('}' === $token) {
                    if (null !== $methodBodyDepth && $braceDepth === $methodBodyDepth) {
                        $methodBodyDepth = null;
                        $atMemberStart = self::isClassMemberContext($classBodyDepth, $methodBodyDepth, $braceDepth);
                    }
                    if (null !== $classBodyDepth && $braceDepth === $classBodyDepth) {
                        $classBodyDepth = null;
                        $atMemberStart = false;
                    }
                    --$braceDepth;
                } elseif (';' === $token && self::isClassMemberContext($classBodyDepth, $methodBodyDepth, $braceDepth)) {
                    $atMemberStart = true;
                }
                continue;
            }

            $id = $token[0];
            if (\in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (T_NEW === $id) {
                $pendingNewClass = true;
                continue;
            }

            if (\in_array($id, [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true)) {
                if (T_CLASS === $id && $pendingNewClass) {
                    $pendingNewClass = false;
                }
                $awaitingClassBrace = true;
                continue;
            }

            if (T_FUNCTION === $id && !self::isClassMemberContext($classBodyDepth, $methodBodyDepth, $braceDepth)) {
                if (null !== self::findPrecedingReadonlyIndex($tokens, $i)) {
                    throw new CompileFatal($filename, $token[2], self::MESSAGE);
                }
                continue;
            }

            if (!self::isClassMemberContext($classBodyDepth, $methodBodyDepth, $braceDepth)) {
                continue;
            }

            if (!$atMemberStart) {
                continue;
            }

            if (T_FUNCTION === $id) {
                $methodBodyDepth = self::findFunctionBodyBraceDepth($tokens, $i, $braceDepth);
                $atMemberStart = false;
                continue;
            }

            if (\in_array($id, [T_ABSTRACT, T_FINAL, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_READONLY, T_ATTRIBUTE], true)) {
                continue;
            }

            $atMemberStart = false;
        }

        return $code;
    }

    private static function isClassMemberContext(?int $classBodyDepth, ?int $methodBodyDepth, int $braceDepth): bool
    {
        if (null === $classBodyDepth || $braceDepth !== $classBodyDepth) {
            return false;
        }

        return null === $methodBodyDepth;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function findPrecedingReadonlyIndex(array $tokens, int $functionIndex): ?int
    {
        $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_ATTRIBUTE, T_STATIC];
        for ($j = $functionIndex - 1; $j >= 0; --$j) {
            $token = $tokens[$j];
            if (\is_array($token)) {
                if (T_READONLY === $token[0]) {
                    return $j;
                }
                if (\in_array($token[0], $skip, true)) {
                    continue;
                }

                return null;
            }
            if ('#' === $token || '@' === $token) {
                continue;
            }

            return null;
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function findFunctionBodyBraceDepth(array $tokens, int $functionIndex, int $currentBraceDepth): ?int
    {
        $depth = 0;
        for ($i = $functionIndex + 1, $n = \count($tokens); $i < $n; ++$i) {
            $token = $tokens[$i];
            if (\is_string($token)) {
                if ('(' === $token) {
                    ++$depth;
                    continue;
                }
                if (')' === $token && $depth > 0) {
                    --$depth;
                    continue;
                }
                if ('{' === $token && 0 === $depth) {
                    return $currentBraceDepth + 1;
                }
                if (';' === $token && 0 === $depth) {
                    return null;
                }
                continue;
            }
            if (T_CURLY_OPEN === $token[0] && 0 === $depth) {
                return $currentBraceDepth + 1;
            }
        }

        return null;
    }
}
