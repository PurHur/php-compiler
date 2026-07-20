<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** LLVM entry {@see __phpc_session_destroy_apply} — body in JitSessionLifecycleKernel (#1182, #21564). */
final class SessionDestroy
{
    public static function implement(Context $context): void
    {
    }
}
