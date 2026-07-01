<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\GzStreamRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM lowering for gzeof() via __compiler_gzeof (#14596). */
final class JitGzeof
{
    /** @return Value */
    public static function invoke(Context $context, Value $handleLong): Value
    {
        GzStreamRuntime::ensureLinked($context);
        $result = $context->builder->call(
            $context->lookupFunction('__compiler_gzeof'),
            $handleLong
        );
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $result);

        return JitValueBox::pointer($context, $slot);
    }
}
