<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\GcCollectCyclesRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM lowering for gc_collect_cycles() via GcCollectCyclesJitHelper PHP (#3160, #9183). */
final class JitGcCollectCycles
{
    public static function invoke(Context $context): Value
    {
        GcCollectCyclesRuntime::ensureLinked($context);

        $collected = $context->builder->call(
            $context->lookupFunction('__compiler_gc_collect_cycles')
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $ptr,
            $collected
        );

        return $ptr;
    }
}
