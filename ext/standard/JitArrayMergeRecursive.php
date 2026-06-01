<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM JIT helper for array_merge_recursive() — delegates to C runtime overlay (#3297). */
final class JitArrayMergeRecursive
{
    public static function overlay(Context $context, Value $dest, Value $src): void
    {
        $context->builder->call(
            $context->lookupFunction('__compiler_array_merge_recursive_overlay'),
            $dest,
            $src
        );
    }
}
