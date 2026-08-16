<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for flock() via __compiler_flock (issue #3141; soft-null $operation #31462).
 *
 * Compile-time null: soft DEP + catchable ValueError (null→0 is not LOCK_*); strict TypeError.
 * Runtime invalid LOCK_* values still raise inside {@see StreamReadJitHelper::flockArgv}.
 */
final class JitFlock
{
    /** @return Value true when flock succeeds */
    public static function invoke(Context $context, Value $handleLong, Value $operationLong): Value
    {
        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_flock'),
            $handleLong,
            $operationLong
        );
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->icmp(Builder::INT_EQ, $ret, $i32->constInt(1, false));
    }

    public static function isCompileTimeNullOperation(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false);
    }

    /**
     * Z_PARAM_LONG $operation — strict TypeError on null; else soft-null DEP+coerce (#31462).
     */
    public static function lowerOperation(Context $context, JITVariable $arg): Value
    {
        return JitIntdiv::lowerIntBuiltinArgForCaller(
            $context,
            $arg,
            'flock',
            2,
            'operation'
        );
    }

    /**
     * Compile-time null $operation without calling __compiler_flock (#31462).
     *
     * Soft: E_DEPRECATED then catchable ValueError (LOCK_* list). Strict: TypeError.
     *
     * @return Value false (flock did not succeed)
     */
    public static function emitCompileTimeNullOperation(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            self::lowerOperation($context, $arg);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'flock_null_op_te_cont');
        } else {
            // Soft-null DEP then ValueError — same order as Zend Z_PARAM_LONG + php_flock (#31462).
            JitIntdiv::emitNullIntDeprecation($context, 'flock', 2, 'operation');
            ExceptionBridge::emitValueErrorAndAbort($context, VmFlockOperation::VALUE_ERROR_MSG);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'flock_null_op_ve_cont');
        }
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt(0, false);
    }
}
