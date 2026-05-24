<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringUnserialize;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

final class JitUnserialize
{
    public static function decodeRuntime(Context $context, JITVariable $payload): Value
    {
        StringUnserialize::ensureLinked($context);

        return self::decodeRuntimeString(
            $context,
            JitStringArg::lower($context, $payload, 'unserialize() payload')
        );
    }

    public static function decodeRuntimeString(Context $context, Value $payloadString): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_unserialize'),
            $payloadString,
            $ptr
        );

        return $ptr;
    }
}
