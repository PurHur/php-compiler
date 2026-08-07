<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvshm;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for shmop_read() (#27408). */
final class JitShmopRead
{
    public static function invoke(
        Context $context,
        JITVariable $shmopArg,
        JITVariable $offsetArg,
        JITVariable $sizeArg
    ): Value {
        $handle = JitShmopHandle::fromArg($context, $shmopArg, 'shmop_read');
        JitShmopHandle::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $offset = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $offsetArg, 'shmop_read() offset'),
            $i64
        );
        $size = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $sizeArg, 'shmop_read() size'),
            $i64
        );
        $str = $context->builder->call(
            $context->lookupFunction('__compiler_shmop_read'),
            $handle,
            $offset,
            $size
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $str
        );

        return $ptr;
    }

    public static function emitArgumentCountError(Context $context, int $argc): Value
    {
        return JitShmopHandle::emitArgumentCountError(
            $context,
            'shmop_read() expects exactly 3 arguments, '.$argc.' given'
        );
    }
}
