<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\FiberState;
use PHPCompiler\VM\FiberSupport;
use PHPCompiler\VM\NativeFiberError;

/** Fiber::getReturn(): mixed — VM (#5019, Zend/zend_fibers.c). */
final class FiberGetReturn extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getReturn');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Fiber::getReturn() called without $this');
        }
        // php-src Zend/zend_fibers.stub.php — getReturn(): mixed; ZEND_PARSE_PARAMETERS_NONE (#30906)
        $this->requireExactUserArgCount($frame, 'Fiber::getReturn', 0);
        FiberSupport::requireFiberObject($frame->calledArgs[0], 'Fiber::getReturn()');
        $fiber = FiberSupport::fiberFromObject($frame->calledArgs[0]->resolveIndirect()->toObject());
        if (FiberState::STATUS_TERMINATED === $fiber->status) {
            if ($fiber->threw) {
                throw new NativeFiberError('Cannot get fiber return value: The fiber threw an exception');
            }
            if (!$fiber->hasReturnValue) {
                throw new NativeFiberError('Cannot get fiber return value: The fiber has not returned');
            }
            if (null !== $frame->returnVar) {
                $frame->returnVar->copyFrom($fiber->returnValue);
            }

            return;
        }
        if (FiberState::STATUS_INIT === $fiber->status) {
            throw new NativeFiberError('Cannot get fiber return value: The fiber has not been started');
        }
        throw new NativeFiberError('Cannot get fiber return value: The fiber has not returned');
    }
}
