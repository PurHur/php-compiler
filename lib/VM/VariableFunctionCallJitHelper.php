<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Variable function dispatch for compiled JIT/AOT modules (#10135, php-in-PHP).
 *
 * SSOT: {@see VariableFunctionCall}
 */
final class VariableFunctionCallJitHelper
{
    /**
     * @param string $candidateTable RS-delimited (\x1e) lowercase callee names (compile-time hints).
     *                               Was NUL-delimited; AOT string constants truncate at NUL (#35075).
     *
     * @return int candidate index, or -1 when none match (LLVM i32 ABI)
     */
    public static function matchCandidateIndex(string $name, string $candidateTable): int
    {
        // NestedJIT: avoid array_filter callback (#27520).
        $names = [];
        foreach (\explode("\x1e", $candidateTable) as $part) {
            if ('' !== $part) {
                $names[] = $part;
            }
        }

        return VariableFunctionCall::matchCandidateIndex($name, $names);
    }
}
