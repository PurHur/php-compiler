<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClosureSupport;
use PHPCompiler\VM\Variable;

/** Closure::bindTo() — VM (#3266). */
final class ClosureBindTo extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('bindTo');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('Closure::bindTo() expects at least 2 arguments');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('Closure::bindTo() requires VM context');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('Closure::bindTo() called without $this');
        }
        $state = ClosureSupport::requireClosureState(
            $receiver->toObject(),
            'Closure::bindTo()'
        );
        $newScope = $frame->calledArgs[2] ?? null;
        $bound = ClosureSupport::bindTo(
            $frame->vmContext,
            $state,
            $frame->calledArgs[1],
            $newScope
        );
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $bound) {
            $frame->returnVar->null();

            return;
        }
        $ret = new Variable(Variable::TYPE_OBJECT);
        $ret->object($bound);
        $frame->returnVar->copyFrom($ret);
    }
}
