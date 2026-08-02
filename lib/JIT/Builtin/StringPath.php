<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\ext\standard\JitPath;
use PHPLLVM\Value;

/**
 * JIT/AOT link for dirname()/basename() (#15286, #26905).
 *
 * Always emit via {@see JitPath} LLVM — NestedJIT of PathJitHelper returns null
 * under thin AOT (peer getcwd #26928 / getmypid #26944). PathJitHelper remains the
 * VM/PHP SSOT probe surface; it is not linked into user-script AOT modules.
 * php-src: ext/standard/basename.c, ext/standard/dir.c
 */
final class StringPath
{
    public static function ensureLinked(Context $context): void
    {
        // Call-site LLVM — no module-level NestedJIT ABI.
        unset($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invokeDirname(Context $context, Value $path): Value
    {
        return JitPath::dirname($context, $path);
    }

    public static function invokeDirnameWithLevels(Context $context, Value $path, Value $levels): Value
    {
        return JitPath::dirnameWithLevels($context, $path, $levels);
    }

    public static function invokeBasename(Context $context, Value $path): Value
    {
        return JitPath::basename($context, $path);
    }

    public static function invokeBasenameWithSuffix(Context $context, Value $path, Value $suffix): Value
    {
        return JitPath::basenameWithSuffix($context, $path, $suffix);
    }
}
