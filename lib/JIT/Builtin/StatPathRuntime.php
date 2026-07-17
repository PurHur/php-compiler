<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStatPathKernel;
use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for file predicates + stat fields via PHP helpers (#9112, #19849).
 *
 * Thin orchestrator — NestedJIT bridges live in {@see JitStatPathKernel}.
 * Thin libc mode/access leaf stays in {@see \PHPCompiler\ext\standard\JitStatKernel}.
 * php-src: ext/standard/filestat.c
 */
final class StatPathRuntime
{
    public const FN_EXISTS = JitStatPathKernel::FN_EXISTS;

    public const FN_IS_FILE = JitStatPathKernel::FN_IS_FILE;

    public const FN_IS_DIR = JitStatPathKernel::FN_IS_DIR;

    public const FN_IS_LINK = JitStatPathKernel::FN_IS_LINK;

    public const FN_IS_READABLE = JitStatPathKernel::FN_IS_READABLE;

    public const FN_IS_WRITABLE = JitStatPathKernel::FN_IS_WRITABLE;

    public const FN_IS_EXECUTABLE = JitStatPathKernel::FN_IS_EXECUTABLE;

    public const FN_LONG_FIELD = JitStatPathKernel::FN_LONG_FIELD;

    public const FN_FILETYPE_LABEL = JitStatPathKernel::FN_FILETYPE_LABEL;

    public const FN_DISK_FREE = JitStatPathKernel::FN_DISK_FREE;

    public const FN_DISK_TOTAL = JitStatPathKernel::FN_DISK_TOTAL;

    public static function ensureLinked(Context $context): void
    {
        JitStatPathKernel::ensureLinked($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        JitStatPathKernel::ensureStandaloneBodies($context);
    }

    public static function implement(Context $context): void
    {
        JitStatPathKernel::implement($context);
    }
}
