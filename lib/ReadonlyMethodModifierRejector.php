<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject readonly as a class method modifier (#7183).
 *
 * php-src: Zend/zend_language_parser.y — "Cannot use 'readonly' as method modifier".
 */
final class ReadonlyMethodModifierRejector
{
    public const MESSAGE = "Cannot use 'readonly' as method modifier";

    /** @var list<int> */
    private const MEMBER_MODIFIERS = [
        T_ABSTRACT,
        T_FINAL,
        T_PUBLIC,
        T_PROTECTED,
        T_PRIVATE,
        T_STATIC,
        T_READONLY,
    ];

    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (ReferenceProfileTokenScan::shouldSkipReferenceProfileReject($code, $filename)) {
            return $code;
        }
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

            if (\in_array($id, [T_CLASS, T_TRAIT, T_INTERFACE], true)) {
                if (T_CLASS === $id && $pendingNewClass) {
                    $pendingNewClass = false;
                }
                $awaitingClassBrace = true;
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

            if (T_READONLY !== $id) {
                if (\in_array($id, self::MEMBER_MODIFIERS, true)) {
                    continue;
                }
                if (T_ATTRIBUTE === $id) {
                    continue;
                }
                $atMemberStart = false;
                continue;
            }

            $line = self::readonlyPrecedesMemberFunction($tokens, $i, $n);
            if (null !== $line) {
                throw new CompileFatal($filename, $line, self::MESSAGE);
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
     * @param list<mixed> $tokens
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
                if (')' === $token) {
                    if ($depth > 0) {
                        --$depth;
                    }
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

    /**
     * @param list<mixed> $tokens
     */
    private static function readonlyPrecedesMemberFunction(array $tokens, int $readonlyIndex, int $n): ?int
    {
        $line = null;
        for ($i = $readonlyIndex; $i < $n; ++$i) {
            $token = $tokens[$i];
            if (\is_array($token)) {
                if (T_READONLY === $token[0]) {
                    $line = $token[2];
                    continue;
                }
                if (\in_array($token[0], self::MEMBER_MODIFIERS, true)) {
                    continue;
                }
                if (\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_ATTRIBUTE], true)) {
                    continue;
                }
                if (T_FUNCTION === $token[0]) {
                    return $line ?? $token[2];
                }

                return null;
            }
        }

        return null;
    }
}
