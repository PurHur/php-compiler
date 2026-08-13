<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitSimilarTextKernel;
use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for phpc_similar_text via {@see JitSimilarTextKernel} (#30810).
 *
 * Thin orchestrator — LLVM Oliver algorithm lives in ext/standard (peer NaturalCompare #30088).
 * NestedJIT of the former SimilarText PHP helper segfaults under thin AOT (#30810 /
 * residual of #26897); keep the Builtin free of helper-link NestedJIT until recursive
 * by-ref string loops are fixed.
 *
 * SSOT (VM): {@see \PHPCompiler\ext\standard\VmString::similar_text()}
 * php-src: ext/standard/string.c — php_similar_text, PHP_FUNCTION(similar_text)
 */
final class StringSimilarText
{
    public static function ensureLinked(Context $context): void
    {
        JitSimilarTextKernel::implement($context);
    }

    public static function implement(Context $context): void
    {
        JitSimilarTextKernel::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        JitSimilarTextKernel::implement($context);
    }
}
