<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT/AOT link facade for msg_* — MsgRuntime (#28432). */
final class StringMsg
{
    public static function ensureLinked(Context $context): void
    {
        MsgRuntime::ensureLinked($context);
    }
}
