<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT LLVM bodies for stream_socket_enable_crypto() (#4610). */
final class StreamEnableCrypto
{
    public static function ensureLinked(Context $context): void
    {
        StreamMeta::ensureLinked($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
