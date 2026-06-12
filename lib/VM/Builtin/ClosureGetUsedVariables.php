<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClosureSupport;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** Closure::getUsedVariables() — VM (#6067, Zend zend_closures.c zif_closure_get_used_vars). */
final class ClosureGetUsedVariables extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getUsedVariables');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Closure::getUsedVariables() expects a closure receiver');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \TypeError(
                'Closure::getUsedVariables(): Argument #1 ($closure) must be of type Closure, '
                .EnumCaseSupport::typeNameForVariable($receiver).' given'
            );
        }
        $state = ClosureSupport::requireClosureState($receiver->toObject(), 'Closure::getUsedVariables()');
        ReflectionSupport::returnClosureUsedVariables($frame->returnVar, $state);
    }
}
