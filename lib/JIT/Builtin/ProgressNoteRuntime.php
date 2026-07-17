<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitProgressNoteKernel;
use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for __phpc_progress_note via ProgressJitHelper PHP (#9521, #19874).
 *
 * Thin orchestrator — NestedJIT bridges + SIGSEGV breadcrumb LLVM live in {@see JitProgressNoteKernel}.
 * Refs #9521, #9795, #6748
 */
final class ProgressNoteRuntime
{
    public static function ensureLinked(Context $context): void
    {
        JitProgressNoteKernel::ensureLinked($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        JitProgressNoteKernel::ensureStandaloneBodies($context);
    }

    public static function emitCall(Context $context, string $message): void
    {
        JitProgressNoteKernel::emitCall($context, $message);
    }

    public static function implement(Context $context): void
    {
        JitProgressNoteKernel::implement($context);
    }
}
