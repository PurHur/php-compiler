<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClosureSupport;
use PHPCompiler\VM\FiberTrace;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/**
 * ReflectionFiber::getCallable(): callable — VM (#22066, ext/reflection/php_reflection.c).
 *
 * Returns the Fiber entry-point Closure retained at construct time.
 */
final class ReflectionFiberGetCallable extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getCallable');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionFiber_getCallable — ZEND_PARSE_PARAMETERS_NONE (#30928)
        $this->requireExactUserArgCount($frame, 'ReflectionFiber::getCallable', 0);
        $receiver = ReflectionSupport::requireReflectionFiber($frame, $frame->calledArgs[0]);
        $fiber = FiberTrace::fiberStateFromReflection($receiver);
        if (null === $frame->returnVar) {
            return;
        }
        $callable = $fiber->entryCallable->resolveIndirect();
        if (Variable::TYPE_OBJECT === $callable->type && null !== $callable->toObject()->closureState) {
            $frame->returnVar->copyFrom($callable);

            return;
        }
        // Fallback when entryCallable was not retained (legacy fiber state): wrap ClosureState.
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('ReflectionFiber::getCallable() requires VM context');
        }
        $owner = $fiber->callback->ownerObject;
        if (null !== $owner) {
            $wrapped = new Variable();
            $wrapped->object($owner);
            $frame->returnVar->copyFrom($wrapped);

            return;
        }
        $wrapped = new Variable();
        $wrapped->object(ClosureSupport::wrapState($ctx, $fiber->callback));
        $frame->returnVar->copyFrom($wrapped);
    }
}
