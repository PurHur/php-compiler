<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * getimagesize*() JIT hook — parse/HT are LLVM in {@see \PHPCompiler\ext\standard\JitGetimagesize} (#27291).
 *
 * Kept so existing ensureStandaloneBodies / shrink tests stay meaningful; NestedJIT HashTable
 * helpers were retired (thin AOT false/segfault — peer #26910 / #26829).
 */
final class GetimagesizeJit
{
    public static function ensureLinked(Context $context): void
    {
        // no-op — lowering is pure LLVM in JitGetimagesize / GetimagesizeParseLlvm
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }
}
