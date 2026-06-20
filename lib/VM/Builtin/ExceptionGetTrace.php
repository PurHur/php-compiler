<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ExceptionSupport;
use PHPCompiler\VM\ExceptionTrace;
use PHPCompiler\VM\Variable;

/** Throwable::getTrace() — VM (#7159). */
final class ExceptionGetTrace extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getTrace');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('getTrace() called without $this');
        }
        $receiver = ExceptionSupport::requireThrowableObject($frame->calledArgs[0], 'getTrace()', $frame->vmContext);
        if (null === $frame->returnVar) {
            return;
        }
        $trace = ExceptionTrace::resolveTraceVariable($receiver);
        if (Variable::TYPE_ARRAY !== $trace->type) {
            $frame->returnVar->newArray();

            return;
        }
        $frame->returnVar->copyFrom($trace);
    }
}
