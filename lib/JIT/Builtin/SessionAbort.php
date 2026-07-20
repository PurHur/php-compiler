<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** LLVM entry {@see __phpc_session_abort_apply} — body in JitSessionLifecycleKernel (#6002, #21564). */
final class SessionAbort
{
    public static function implement(Context $context): void
    {
    }
}
