<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT LLVM bodies for stream_filter_* builtins (#9047). */
final class StreamFilter
{
    public static function ensureLinked(Context $context): void
    {
        StreamFilterJit::implement($context);
    }
}
