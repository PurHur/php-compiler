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

/** Fiber::throw(Throwable $exception): mixed — JIT (#4624, Zend/zend_fibers.c). */
final class FiberThrow implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (count($args) < 1) {
            throw new \LogicException('Fiber::throw() called without $this');
        }
        if (count($args) < 2) {
            throw new \LogicException('Fiber::throw() expects exactly 1 argument');
        }
        $fiberVar = $args[0];
        $exception = $args[1];
        $resumeName = FiberHelper::resolveResumeLc($context, $fiberVar);
        $statePtr = $fiberVar->fiberStatePtr ?? FiberHelper::loadStateFromFiberObject($context, $fiberVar);
        $map = $context->structFieldMap['__fiber_state__'];
        $i1 = $context->getTypeFromString('int1');
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
        $excObj = $exception->value;
        if (Variable::TYPE_OBJECT !== $exception->type) {
            if (Variable::TYPE_VALUE === $exception->type) {
                $excObj = $context->builder->call(
                    $context->lookupFunction('__value__readObject'),
                    JitValueBox::valuePtrFromVariable($context, $exception)
                );
            } else {
                TryCatchHelper::emitCatchableClassError(
                    $context,
                    'TypeError',
                    'Fiber::throw(): Argument #1 ($exception) must be of type Throwable, '
                    .$this->jitTypeLabel($exception).' given'
                );
                $excObj = $context->getTypeFromString('__object__*')->constNull();
            }
        }

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

        $result = FiberHelper::runResumeAndBoxResult($context, $resumeName, $statePtr);

        return $result->value;
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
