<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ExceptionTraceFormat;
use PHPCompiler\VM\FiberSupport;
use PHPCompiler\VM\FiberTrace;
use PHPCompiler\VM\Variable;

/** Fiber::getTraceAsString(): string — VM (#6470). */
final class FiberGetTraceAsString extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getTraceAsString');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Fiber::getTraceAsString() called without $this');
        }
        FiberSupport::requireFiberObject($frame->calledArgs[0], 'Fiber::getTraceAsString()');
        $fiber = FiberSupport::fiberFromObject($frame->calledArgs[0]->resolveIndirect()->toObject());
        FiberTrace::requireSuspended($fiber, 'Fiber::getTraceAsString()');
        if (null === $frame->returnVar) {
            return;
        }
        $trace = $fiber->suspendedTrace->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $trace->type) {
            $frame->returnVar->string('#0 {main}');

            return;
        }
        $frame->returnVar->string(ExceptionTraceFormat::asString($trace));
    }
}
