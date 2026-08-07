<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvshm;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for shmop_delete() (#27408). */
final class JitShmopDelete
{
    public static function invoke(Context $context, JITVariable $arg): Value
    {
        $handle = JitShmopHandle::fromArg($context, $arg, 'shmop_delete');
        JitShmopHandle::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $rc = $context->builder->call(
            $context->lookupFunction('__compiler_shmop_delete'),
            $handle
        );
        $ok = $context->builder->icmp(
            Builder::INT_NE,
            $rc,
            $i64->constInt(0, false)
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool($context, $slot, $ok);

        return $ptr;
    }

    public static function emitArgumentCountError(Context $context, int $argc): Value
    {
        return JitShmopHandle::emitArgumentCountError(
            $context,
            'shmop_delete() expects exactly 1 argument, '.$argc.' given'
        );
    }
}
