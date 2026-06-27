<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\Builtin\ExecutionLimitsRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for set_time_limit/ignore_user_abort/connection_aborted (#8078, #3242). */
final class JitExecutionLimits
{
    public static function setTimeLimit(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'set_time_limit() expects exactly 1 argument, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        ExecutionLimitsRuntime::ensureLinked($context);
        $seconds = JitIntdiv::lowerIntBuiltinArg($context, $args[0], 'set_time_limit', 1, 'seconds');
        $i32 = $context->getTypeFromString('int32');
        $seconds32 = $context->builder->truncOrBitCast($seconds, $i32);
        $ok = $context->builder->call(
            $context->lookupFunction('phpc_set_time_limit'),
            $seconds32
        );

        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $ok);

        return JitValueBox::pointer($context, $slot);
    }

    public static function ignoreUserAbort(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'ignore_user_abort() expects at most 1 argument, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        ExecutionLimitsRuntime::ensureLinked($context);
        $i32 = $context->getTypeFromString('int32');
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $apply = $zero;
        $value = $zero;
        if (1 === $argc) {
            $arg = $args[0];
            if (Variable::TYPE_NULL === $arg->type) {
                $apply = $zero;
            } else {
                JitInternalStrictArg::requireBuiltinTypedBool(
                    $context,
                    $arg,
                    'ignore_user_abort',
                    'value',
                    1
                );
                $apply = $one;
                $boolVal = JitBoolArg::lowerBuiltinTyped($context, $arg, 'ignore_user_abort', 'value', 1);
                $value = $context->builder->zext($boolVal, $i32);
            }
        }

        $previous = $context->builder->call(
            $context->lookupFunction('phpc_ignore_user_abort'),
            $apply,
            $value
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $ptr,
            $context->builder->sextOrBitCast($previous, $context->getTypeFromString('int64'))
        );

        return $ptr;
    }

    public static function connectionAborted(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 0) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'connection_aborted() expects exactly 0 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        ExecutionLimitsRuntime::ensureLinked($context);
        $status = $context->builder->call($context->lookupFunction('phpc_connection_aborted'));

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $ptr,
            $context->builder->sextOrBitCast($status, $context->getTypeFromString('int64'))
        );

        return $ptr;
    }
}
