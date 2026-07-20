<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** LLVM entry {@see __phpc_session_write_close_apply} — body in JitSessionLifecycleKernel (#1185, #21564). */
final class SessionWriteClose
{
    public static function implement(Context $context): void
    {
    }
}
