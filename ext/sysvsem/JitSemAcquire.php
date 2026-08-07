<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvsem;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for sem_acquire() (#28431). */
final class JitSemAcquire
{
    /**
     * @param list<JITVariable> $args
     */
    public static function invoke(Context $context, array $args): Value
    {
        $handle = JitSemHandle::fromArg($context, $args[0], 'sem_acquire');
        JitSemHandle::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $nowait = isset($args[1])
            ? $context->builder->zext(
                JitBoolArg::lower($context, $args[1], 'sem_acquire() non_blocking'),
                $i64
            )
            : $i64->constInt(0, false);
        $rc = $context->builder->call(
            $context->lookupFunction('__compiler_sem_acquire'),
            $handle,
            $nowait
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
            $argc < 1
                ? 'sem_acquire() expects at least 1 argument, '.$argc.' given'
                : 'sem_acquire() expects at most 2 arguments, '.$argc.' given'
        );
    }
}
