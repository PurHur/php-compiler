<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\VmClosureCall;
use PHPCompiler\Frame;
use PHPCompiler\VM\FiberState;

/**
 * Fiber helpers (issue #3130; php-src Zend/zend_fibers.c).
 */
final class FiberSupport
{
    public const CLASS_FIBER = 'fiber';

    public static function requireFiberObject(Variable $receiver, string $context): Variable
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException("{$context} called on non-object");
        }
        $object = $receiver->toObject();
        if (null === $object->fiberState) {
            throw new \LogicException("{$context} called on invalid Fiber instance");
        }

        return $receiver;
    }

    public static function fiberFromObject(ObjectEntry $object): FiberState
    {
        $state = $object->fiberState;
        if (null === $state) {
            throw new \LogicException('Fiber state is missing on this object');
        }

        return $state;
    }

    public static function attachCallback(ObjectEntry $object, Variable $callback): void
    {
        $closure = VmClosureCall::resolve($callback);
        $object->fiberState = new FiberState($closure, $object);
        $object->constructed = true;
    }

    public static function suspend(Frame $handlerFrame): void
    {
        $ctx = $handlerFrame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('Fiber::suspend() requires VM context');
        }
        $fiber = $ctx->currentFiber;
        if (null === $fiber) {
            throw new \LogicException('Fiber::suspend() must be called from a Fiber context');
        }
        if (FiberState::STATUS_RUNNING !== $fiber->status) {
            throw new \LogicException('Fiber::suspend() cannot be called in this fiber state');
        }
        if (\count($handlerFrame->calledArgs) >= 1) {
            $fiber->suspendReturn->copyFrom($handlerFrame->calledArgs[0]->resolveIndirect());
        } else {
            $fiber->suspendReturn->null();
        }
        if (null !== $handlerFrame->returnVar) {
            $handlerFrame->returnVar->copyFrom($fiber->resumeArgument);
        }
        $parent = $handlerFrame->parent;
        if (null !== $parent) {
            $parent->fiberSuspend = true;
            $fiber->frame = $parent;
        }
    }
}
