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
     * @param string $candidateTable NUL-delimited lowercase callee names (compile-time hints)
     *
     * @return int candidate index, or -1 when none match (LLVM i32 ABI)
     */
    public static function matchCandidateIndex(string $name, string $candidateTable): int
    {
        $names = \array_values(\array_filter(
            \explode("\0", $candidateTable),
            static fn (string $part): bool => '' !== $part
        ));

        return VariableFunctionCall::matchCandidateIndex($name, $names);
    }
}
