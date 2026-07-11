<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\FiberTrace;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionFiber::getTrace(): array — VM (#4609, ext/reflection/php_reflection.c). */
final class ReflectionFiberGetTrace extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getTrace');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionFiber($frame, $frame->calledArgs[0]);
        $fiber = FiberTrace::fiberStateFromReflection($receiver);
        FiberTrace::requireSuspended($fiber, 'ReflectionFiber::getTrace()');
        if (null === $frame->returnVar) {
            return;
        }
        $trace = $fiber->suspendedTrace->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $trace->type) {
            $frame->returnVar->newArray();

            return;
        }
        $frame->returnVar->copyFrom($trace);
    }
}
