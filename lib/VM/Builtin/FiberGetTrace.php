<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\FiberSupport;
use PHPCompiler\VM\FiberTrace;
use PHPCompiler\VM\Variable;

/** Fiber::getTrace(): array — VM (#6470). */
final class FiberGetTrace extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getTrace');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Fiber::getTrace() called without $this');
        }
        FiberSupport::requireFiberObject($frame->calledArgs[0], 'Fiber::getTrace()');
        $fiber = FiberSupport::fiberFromObject($frame->calledArgs[0]->resolveIndirect()->toObject());
        FiberTrace::requireSuspended($fiber, 'Fiber::getTrace()');
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
