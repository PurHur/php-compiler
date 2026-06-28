<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\ProcessOpenJitHelper;
use PHPCompiler\JIT\Context;

/**
 * Back-compat router for proc_open LLVM (#6904) — ProcessOpenJitHelper PHP bridge (#9408, #12958).
 *
 * @deprecated Prefer {@see ProcessOpenRuntime}.
 */
final class ProcessOpenJit
{
    public const PROCESS_HANDLE_BASE = ProcessOpenJitHelper::PROCESS_HANDLE_BASE;

    public static function ensureLinked(Context $context): void
    {
        ProcessOpenRuntime::ensureLinked($context);
    }

    public static function implement(Context $context): void
    {
        ProcessOpenRuntime::implement($context);
    }
}
