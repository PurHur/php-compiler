<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT LLVM globals + __phpc_resolve_stream for stream handle table (#5343 phase 5). */
final class StreamGlobals
{
    public static function ensureLinked(Context $context): void
    {
        StreamGlobalsJit::implement($context);
    }
}
