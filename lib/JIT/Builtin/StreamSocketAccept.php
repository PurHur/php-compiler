<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT/AOT link for stream_socket_accept() via StreamSocketAcceptJitHelper (#15346). */
final class StreamSocketAccept
{
    public static function ensureLinked(Context $context): void
    {
        StreamSocketAcceptRuntime::ensureLinked($context);
    }
}
