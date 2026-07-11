<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\GzStreamRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for gzrewind() via __compiler_gzrewind (#14585). */
final class JitGzrewind
{
    /** @return Value */
    public static function invoke(Context $context, Value $handleLong): Value
    {
        GzStreamRuntime::ensureLinked($context);
        $i32 = $context->getTypeFromString('int32');
        $result = $context->builder->call(
            $context->lookupFunction('__compiler_gzrewind'),
            $handleLong
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i1 = $context->getTypeFromString('int1');
        $ok = $context->builder->icmp(Builder::INT_NE, $result, $i32->constInt(0, false));
        JitValueBox::writeBool($context, $slot, $ok);

        return $ptr;
    }
}
