<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\FiberHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** Fiber::getReturn(): mixed — JIT (#6310, Zend/zend_fibers.c). */
final class FiberGetReturn implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('Fiber::getReturn() called without $this');
        }
        // php-src Zend/zend_fibers.stub.php — getReturn(): mixed; ZEND_PARSE_PARAMETERS_NONE (#30906)
        if (!FiberHelper::emitExactInstanceUserArgc($context, $args, 'Fiber::getReturn', 0)) {
            return FiberHelper::dummyNullValue($context);
        }
        $fiberVar = $args[0];
        FiberHelper::ensureTypes($context);
        $statePtr = $fiberVar->fiberStatePtr ?? FiberHelper::loadStateFromFiberObject($context, $fiberVar);
        $map = $context->structFieldMap['__fiber_state__'];
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $stateBits = $context->builder->ptrtoint($statePtr, $i64);

        $hasState = BasicBlockHelper::append($context, 'fiber_getreturn_has_state');
        $notStarted = BasicBlockHelper::append($context, 'fiber_getreturn_not_started');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $stateBits, $i64->constInt(0, false)),
            $notStarted,
            $hasState
        );
        $context->builder->positionAtEnd($notStarted);
        TryCatchHelper::emitCatchableClassError(
            $context,
            'FiberError',
            'Cannot get fiber return value: The fiber has not been started'
        );

        $context->builder->positionAtEnd($hasState);
        $startedField = $context->builder->structGep($statePtr, $map['started']);
        $doneField = $context->builder->structGep($statePtr, $map['done']);
        $doneOk = BasicBlockHelper::append($context, 'fiber_getreturn_done');
        $notReturned = BasicBlockHelper::append($context, 'fiber_getreturn_not_returned');
        $done = $context->builder->load($doneField);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $done, $i1->constInt(0, false)),
            $doneOk,
            $notReturned
        );
        $context->builder->positionAtEnd($notReturned);
        TryCatchHelper::emitCatchableClassError(
            $context,
            'FiberError',
            'Cannot get fiber return value: The fiber has not returned'
        );

        $context->builder->positionAtEnd($doneOk);
        $slot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer(
            $context,
            $slot,
            $context->builder->structGep($statePtr, $map['fiber_return'])
        );

        return $slot;
    }
}
