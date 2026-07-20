<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** LLVM entry {@see __phpc_session_unset_apply} — body in JitSessionLifecycleKernel (#6261, #21564). */
final class SessionUnset
{
    public static function implement(Context $context): void
    {
    }
}
