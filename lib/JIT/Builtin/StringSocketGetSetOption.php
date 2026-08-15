<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT/AOT facade for socket_get/set_option() — SocketCreateJitHelper (#31295). */
final class StringSocketGetSetOption
{
    public static function ensureLinked(Context $context): void
    {
        SocketGetSetOptionRuntime::ensureLinked($context);
    }
}
