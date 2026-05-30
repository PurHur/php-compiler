<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\FiberSupport;
use PHPCompiler\VM\Variable;

/** Fiber::start(mixed ...$args): mixed — VM (#3130). */
final class FiberStart extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('start');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Fiber::start() called without $this');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('Fiber::start() requires VM context');
        }
        FiberSupport::requireFiberObject($frame->calledArgs[0], 'Fiber::start()');
        $fiber = FiberSupport::fiberFromObject($frame->calledArgs[0]->resolveIndirect()->toObject());
        $startArgs = [];
        for ($i = 1, $n = \count($frame->calledArgs); $i < $n; ++$i) {
            $copy = new Variable();
            $copy->copyFrom($frame->calledArgs[$i]);
            $startArgs[] = $copy;
        }
        $result = $frame->vmContext->runtime->vm->startFiber($fiber, ...$startArgs);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }
}
