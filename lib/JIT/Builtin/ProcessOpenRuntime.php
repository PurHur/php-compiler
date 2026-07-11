<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT/AOT proc_open dispatch — ProcessOpenJitHelper PHP bridge (#9408, #12958). */
final class ProcessOpenRuntime
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        ProcessOpenEmbedBridge::implement($context);
    }
}
