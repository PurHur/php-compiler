<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitSessionStorageKernel;
use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for session file I/O via SessionStorageJitHelper PHP (#9495, #19882).
 *
 * Thin orchestrator — NestedJIT bridges live in {@see JitSessionStorageKernel}.
 * php-src: ext/session/mod_files.c
 */
final class SessionStorageRuntime
{
    public static function ensureLinked(Context $context): void
    {
        JitSessionStorageKernel::ensureLinked($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        JitSessionStorageKernel::ensureStandaloneBodies($context);
    }

    public static function implement(Context $context): void
    {
        JitSessionStorageKernel::implement($context);
    }
}
