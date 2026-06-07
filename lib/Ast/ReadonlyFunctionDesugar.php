<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

/**
 * Strip top-level / expression `readonly function` for nikic/php-parser 4.x (#7428).
 *
 * php-src: Zend/zend_language_parser.y — T_READONLY T_FUNCTION (readonly closures RFC).
 */
final class ReadonlyFunctionDesugar
{
    /**
     * @return array{0: string, 1: list<int>} rewritten source, function/closure start lines
     */
    public static function desugar(string $code): array
    {
        if (!str_contains($code, 'readonly') || !str_contains($code, 'function')) {
            return [$code, []];
        }
        if (!\function_exists('token_get_all')) {
            return [$code, []];
        }

        $tokens = token_get_all($code);
        $readonlyLines = [];
        $replacements = [];
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
                $readonlyIdx = self::findPrecedingReadonlyIndex($tokens, $i);
                if (null !== $readonlyIdx) {
                    $start = self::tokenByteOffset($tokens, $readonlyIdx);
                    $funcLine = $token[2];
                    $end = $start + \strlen(self::tokenText($tokens[$readonlyIdx]));
                    while ($end < \strlen($code) && ctype_space($code[$end])) {
                        ++$end;
                    }
                    if (null !== $start) {
                        $replacements[] = ['start' => $start, 'end' => $end, 'line' => $funcLine];
                    }
                }
                if (self::isClassMemberContext($classBodyDepth, $methodBodyDepth, $braceDepth)) {
                    $methodBodyDepth = self::findFunctionBodyBraceDepth($tokens, $i, $braceDepth);
                    $atMemberStart = false;
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

        if ([] === $replacements) {
            return [$code, []];
        }

        usort($replacements, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
        foreach ($replacements as $replacement) {
            $readonlyLines[] = $replacement['line'];
            $code = substr($code, 0, $replacement['start']).substr($code, $replacement['end']);
        }

        return [$code, array_values(array_unique($readonlyLines))];
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

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function tokenByteOffset(array $tokens, int $index): ?int
    {
        if (!isset($tokens[$index])) {
            return null;
        }
        $offset = 0;
        for ($i = 0; $i < $index; ++$i) {
            $offset += \strlen(self::tokenText($tokens[$i]));
        }

        return $offset;
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function tokenText($token): string
    {
        return \is_array($token) ? $token[1] : $token;
    }
}
