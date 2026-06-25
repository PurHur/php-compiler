<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * Back-compat router for proc_open LLVM (#6904) — embed PHP bridge in {@see ProcessOpenRuntime} (#9408).
 *
 * @deprecated Prefer {@see ProcessOpenRuntime} and {@see ProcessOpenStandaloneLlvm}.
 */
final class ProcessOpenJit
{
    public const PROCESS_HANDLE_BASE = ProcessOpenStandaloneLlvm::PROCESS_HANDLE_BASE;

    public static function ensureLinked(Context $context): void
    {
        ProcessOpenRuntime::ensureLinked($context);
    }

    public static function implement(Context $context): void
    {
        ProcessOpenRuntime::implement($context);
    }
}
