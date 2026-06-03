<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClosureSupport;
use PHPCompiler\VM\Variable;

/** Closure::call() — temporary $this invoke (issue #4927, Zend zend_closures.c zif_closure_call). */
final class ClosureCall extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('call');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'Closure::call() expects at least 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('Closure::call() requires VM context');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('Closure::call() called without $this');
        }
        $state = ClosureSupport::requireClosureState(
            $receiver->toObject(),
            'Closure::call()'
        );
        $invokeArgs = \array_slice($frame->calledArgs, 2);
        $result = ClosureSupport::call(
            $frame->vmContext,
            $state,
            $frame->calledArgs[1],
            $invokeArgs
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom($result);
    }
}
