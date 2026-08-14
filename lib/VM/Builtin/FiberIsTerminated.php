<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\FiberState;
use PHPCompiler\VM\FiberSupport;

/** Fiber::isTerminated(): bool — VM (#4372, Zend/zend_fibers.c). */
final class FiberIsTerminated extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isTerminated');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Fiber::isTerminated() called without $this');
        }
        // php-src Zend/zend_fibers.stub.php — isTerminated(): bool; ZEND_PARSE_PARAMETERS_NONE (#30906)
        $this->requireExactUserArgCount($frame, 'Fiber::isTerminated', 0);
        FiberSupport::requireFiberObject($frame->calledArgs[0], 'Fiber::isTerminated()');
        $fiber = FiberSupport::fiberFromObject($frame->calledArgs[0]->resolveIndirect()->toObject());
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(FiberState::STATUS_TERMINATED === $fiber->status);
        }
    }
}

