<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringUnserialize;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM lowering for unserialize() via __compiler_unserialize (issue #1175). */
final class JitUnserialize
{
    /** @return Value __value__* */
    public static function decodeRuntime(Context $context, Value $payload): Value
    {
        StringUnserialize::ensureLinked($context);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_unserialize'),
            $payload,
            $ptr
        );

        return $ptr;
    }
}
