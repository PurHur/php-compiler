<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\FiberState;
use PHPCompiler\VM\FiberSupport;

/** Fiber::isStarted(): bool — VM (Zend/zend_fibers.c parity, #4481). */
final class FiberIsStarted extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isStarted');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Fiber::isStarted() called without $this');
        }
        // php-src Zend/zend_fibers.stub.php — isStarted(): bool; ZEND_PARSE_PARAMETERS_NONE (#30906)
        $this->requireExactUserArgCount($frame, 'Fiber::isStarted', 0);
        FiberSupport::requireFiberObject($frame->calledArgs[0], 'Fiber::isStarted()');
        $fiber = FiberSupport::fiberFromObject($frame->calledArgs[0]->resolveIndirect()->toObject());
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(FiberState::STATUS_INIT !== $fiber->status);
        }
    }
}

