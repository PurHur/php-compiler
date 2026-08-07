<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvsem;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringSem;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for sem_get() via SemJitHelper (#28431).
 *
 * php-src: ext/sysvsem/sysvsem.c — PHP_FUNCTION(sem_get)
 */
final class JitSemGet
{
    /**
     * @param list<JITVariable> $args
     */
    public static function invoke(Context $context, array $args): Value
    {
        StringSem::ensureLinked($context);

        $i64 = $context->getTypeFromString('int64');
        $key = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'sem_get() key'),
            $i64
        );
        $maxAcquire = isset($args[1])
            ? $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[1], 'sem_get() max_acquire'),
                $i64
            )
            : $i64->constInt(1, false);
        $perm = isset($args[2])
            ? $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[2], 'sem_get() permissions'),
                $i64
            )
            : $i64->constInt(0o666, false);
        $autoRelease = isset($args[3])
            ? $context->builder->zext(
                JitBoolArg::lowerCoerce($context, $args[3], 'sem_get() auto_release'),
                $i64
            )
            : $i64->constInt(1, false);

        $objPtr = self::allocateSysvSemaphoreObject($context);
        $voidp = $context->getTypeFromString('void')->pointerType(0);
        $objAddr = $context->builder->ptrToInt(
            $context->builder->pointerCast($objPtr, $voidp),
            $i64
        );
        $rc = $context->builder->call(
            $context->lookupFunction('__compiler_sem_get_register'),
            $objAddr,
            $key,
            $maxAcquire,
            $perm,
            $autoRelease
        );
        $ok = $context->builder->icmp(
            Builder::INT_NE,
            $rc,
            $i64->constInt(0, false)
        );

        $failBb = BasicBlockHelper::append($context, 'sem_get_fail');
        $okBb = BasicBlockHelper::append($context, 'sem_get_ok');
        $doneBb = BasicBlockHelper::append($context, 'sem_get_done');
        $context->builder->branchIf($ok, $okBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $falseSlot = JitValueBox::alloc($context);
        $falsePtr = JitValueBox::pointer($context, $falseSlot);
        JitValueBox::writeBool($context, $falseSlot, $context->getTypeFromString('int1')->constInt(0, false));
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $objPtr
        );
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($ptrTy);
        $result->addIncoming($falsePtr, $failTail);
        $result->addIncoming($ptr, $okTail);

        return $result;
    }

    private static function allocateSysvSemaphoreObject(Context $context): Value
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup('SysvSemaphore');
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        return $obj;
    }

    public static function emitArgumentCountError(Context $context, int $argc): Value
    {
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitArgumentCountError(
            $context,
            $argc < 1
                ? 'sem_get() expects at least 1 argument, '.$argc.' given'
                : 'sem_get() expects at most 4 arguments, '.$argc.' given'
        );
        $slot = JitValueBox::alloc($context);

        return JitValueBox::pointer($context, $slot);
    }
}
