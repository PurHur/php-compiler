<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\FiberState;
use PHPCompiler\VM\FiberSupport;

/** Fiber::isRunning(): bool — VM (Zend/zend_fibers.c parity, #4481). */
final class FiberIsRunning extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isRunning');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Fiber::isRunning() called without $this');
        }
        // php-src Zend/zend_fibers.stub.php — isRunning(): bool; ZEND_PARSE_PARAMETERS_NONE (#30906)
        $this->requireExactUserArgCount($frame, 'Fiber::isRunning', 0);
        FiberSupport::requireFiberObject($frame->calledArgs[0], 'Fiber::isRunning()');
        $fiber = FiberSupport::fiberFromObject($frame->calledArgs[0]->resolveIndirect()->toObject());
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(FiberState::STATUS_RUNNING === $fiber->status);
        }
    }
}

