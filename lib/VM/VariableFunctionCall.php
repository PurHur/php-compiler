<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Dynamic variable function call semantics shared by VM + JIT (#10135, php-in-PHP).
 *
 * php-src: Zend/zend_execute.c — ZEND_INIT_FCALL_BY_NAME / variable calls
 */
final class VariableFunctionCall
{
    /**
     * @param list<string> $candidateNames lowercase compile-time callee hints
     *
     * @return int candidate index, or -1 when none match
     */
    public static function matchCandidateIndex(string $name, array $candidateNames): int
    {
        $needle = \strtolower($name);
        foreach ($candidateNames as $index => $candidate) {
            if ($needle === \strtolower($candidate)) {
                return $index;
            }
        }

        return -1;
    }
}
