<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\JitThrow;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\FiberHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Fiber::throw(Throwable $exception): mixed — JIT (#4624, Zend/zend_fibers.c).
 *
 * Uncaught injected throws return resume status 2; this call site pends the exception
 * and branches into the active try/catch (#27622).
 */
final class FiberThrow implements Call
{
    private const RESUME_SUSPENDED = 1;

    private const RESUME_UNCAUGHT = 2;

    public function call(Context $context, Variable ...$args): Value
    {
        if (count($args) < 1) {
            throw new \LogicException('Fiber::throw() called without $this');
        }
        // php-src Zend/zend_fibers.stub.php — throw(Throwable $exception); exactly 1 user arg (#30906)
        if (!FiberHelper::emitExactInstanceUserArgc($context, $args, 'Fiber::throw', 1)) {
            return FiberHelper::dummyNullValue($context);
        }
        $fiberVar = $args[0];
        $exception = $args[1];
        $resumeName = FiberHelper::resolveResumeLc($context, $fiberVar);
        $statePtr = $fiberVar->fiberStatePtr ?? FiberHelper::loadStateFromFiberObject($context, $fiberVar);
        $map = $context->structFieldMap['__fiber_state__'];
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $doneField = $context->builder->structGep($statePtr, $map['done']);
        $startedField = $context->builder->structGep($statePtr, $map['started']);
        $suspendedField = $context->builder->structGep($statePtr, $map['suspended']);

        $okBlock = BasicBlockHelper::append($context, 'fiber_throw_ok');
        $failBlock = BasicBlockHelper::append($context, 'fiber_throw_fail');

        $started = $context->builder->load($startedField);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $started, $i1->constInt(0, false)),
            $okBlock,
            $failBlock
        );
        $context->builder->positionAtEnd($failBlock);
        TryCatchHelper::emitCatchableClassError(
            $context,
            'FiberError',
            'Cannot throw into a fiber that is not suspended'
        );

        $context->builder->positionAtEnd($okBlock);
        $done = $context->builder->load($doneField);
        $suspendedOk = BasicBlockHelper::append($context, 'fiber_throw_suspended_ok');
        $terminatedFail = BasicBlockHelper::append($context, 'fiber_throw_terminated');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $done, $i1->constInt(0, false)),
            $suspendedOk,
            $terminatedFail
        );
        $context->builder->positionAtEnd($terminatedFail);
        TryCatchHelper::emitCatchableClassError(
            $context,
            'FiberError',
            'Cannot throw into a fiber that is terminated'
        );

        $context->builder->positionAtEnd($suspendedOk);
        $suspended = $context->builder->load($suspendedField);
        $throwOk = BasicBlockHelper::append($context, 'fiber_throw_resume');
        $notSuspendedFail = BasicBlockHelper::append($context, 'fiber_throw_not_suspended');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $suspended, $i1->constInt(0, false)),
            $throwOk,
            $notSuspendedFail
        );
        $context->builder->positionAtEnd($notSuspendedFail);
        TryCatchHelper::emitCatchableClassError(
            $context,
            'FiberError',
            'Cannot throw into a fiber that is not suspended'
        );

        $context->builder->positionAtEnd($throwOk);
        $excObj = $this->resolveThrowableObject($context, $exception);

        $pendingField = $context->builder->structGep($statePtr, $map['pending_throw']);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $pendingField),
            $excObj
        );
        $context->builder->store($i1->constInt(1, false), $context->builder->structGep($statePtr, $map['has_pending_throw']));
        $resumeArgField = $context->builder->structGep($statePtr, $map['resume_argument']);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $resumeArgField)
        );

        $resumeFn = $context->functions[strtolower($resumeName)] ?? null;
        if (!$resumeFn instanceof \PHPLLVM\Value\Function_) {
            throw new \LogicException("Fiber resume function missing: {$resumeName}");
        }
        $status = $context->builder->call($resumeFn, $statePtr);

        // Status 2 = uncaught: pend + branch into caller try/catch (#27622).
        JitThrow::registerDeclarations($context);
        JitThrow::ensureLinked($context);
        $uncaughtBb = BasicBlockHelper::append($context, 'fiber_throw_uncaught');
        $afterStatus = BasicBlockHelper::append($context, 'fiber_throw_after_status');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $status, $i64->constInt(self::RESUME_UNCAUGHT, false)),
            $uncaughtBb,
            $afterStatus
        );
        $context->builder->positionAtEnd($uncaughtBb);
        // KIND_VARIABLE object slots are __object__**; set_throw_pending needs __object__*
        // or get_class()/instanceof see an empty class (#27622).
        $excForPend = Variable::TYPE_OBJECT === $exception->type
            ? $context->helper->loadValue($exception)
            : $excObj;
        $context->builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $excForPend);
        $handler = $context->tryCatch->handlerStack[array_key_last($context->tryCatch->handlerStack)] ?? null;
        if (null !== $handler && null !== $handler->dispatchBb) {
            $context->builder->branch($handler->dispatchBb);
        } else {
            $context->builder->branch($afterStatus);
        }
        $context->builder->positionAtEnd($afterStatus);

        $isSuspended = $context->builder->icmp(
            Builder::INT_EQ,
            $status,
            $i64->constInt(self::RESUME_SUSPENDED, false)
        );
        $context->builder->store(
            $context->builder->zext($isSuspended, $i1),
            $context->builder->structGep($statePtr, $map['suspended'])
        );
        $suspendSlot = JitValueBox::alloc($context);
        $terminatedSlot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer(
            $context,
            $suspendSlot,
            $context->builder->structGep($statePtr, $map['suspend_return'])
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $terminatedSlot)
        );
        $resultPtr = $context->builder->select(
            $isSuspended,
            JitValueBox::pointer($context, $suspendSlot),
            JitValueBox::pointer($context, $terminatedSlot)
        );

        return (new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $resultPtr))->value;
    }

    private function resolveThrowableObject(Context $context, Variable $exception): Value
    {
        if (Variable::TYPE_OBJECT === $exception->type) {
            return $exception->value;
        }
        if (Variable::TYPE_VALUE === $exception->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $exception)
            );
        }
        TryCatchHelper::emitCatchableClassError(
            $context,
            'TypeError',
            'Fiber::throw(): Argument #1 ($exception) must be of type Throwable, '
            .$this->jitTypeLabel($exception).' given'
        );

        return $context->getTypeFromString('__object__*')->constNull();
    }

    private function jitTypeLabel(Variable $value): string
    {
        return match ($value->type) {
            Variable::TYPE_NATIVE_LONG => 'int',
            Variable::TYPE_NATIVE_DOUBLE => 'float',
            Variable::TYPE_NATIVE_BOOL => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }
}
