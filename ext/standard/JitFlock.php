<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for flock() via __compiler_flock (issue #3141). */
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

    public static function emitCompileTimeNullOperationError(Context $context): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitBranchOrAbortOnValueErrorFailure(
            $context,
            $context->getTypeFromString('int1')->constInt(0, false),
            'flock_null_const',
            VmFlockOperation::VALUE_ERROR_MSG
        );
    }

    public static function guardValueBoxNullOperation(Context $context, JITVariable $arg): void
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_NULL, false)
        );
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitBranchOrAbortOnValueErrorFailure(
            $context,
            $context->builder->not($isNull),
            'flock_op_null',
            VmFlockOperation::VALUE_ERROR_MSG
        );
    }
}
