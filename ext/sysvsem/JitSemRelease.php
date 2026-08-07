<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvsem;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for sem_release() (#28431). */
final class JitSemRelease
{
    public static function invoke(Context $context, JITVariable $arg): Value
    {
        $handle = JitSemHandle::fromArg($context, $arg, 'sem_release');
        JitSemHandle::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $rc = $context->builder->call(
            $context->lookupFunction('__compiler_sem_release'),
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
        return JitSemHandle::emitArgumentCountError(
            $context,
            'sem_release() expects exactly 1 argument, '.$argc.' given'
        );
    }
}
