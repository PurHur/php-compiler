<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link facade for socket_import_stream() — SocketImportStreamJitHelper (#9217).
 */
final class StringSocketImportStream
{
    public static function ensureLinked(Context $context): void
    {
        SocketImportStreamRuntime::ensureLinked($context);
    }
}
