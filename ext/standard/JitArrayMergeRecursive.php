<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM JIT helper for array_merge_recursive() — PHP overlay in ArrayBuiltinHelper (#6177). */
final class JitArrayMergeRecursive
{
    public static function overlay(Context $context, Value $dest, Value $src): void
    {
        ArrayBuiltinHelper::mergeRecursiveOverlay($context, $dest, $src);
    }
}
