<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** LLVM entry {@see __phpc_session_regenerate_id_apply} — body in JitSessionLifecycleKernel (#1186, #21564). */
final class SessionRegenerateId
{
    public static function implement(Context $context): void
    {
    }
}
