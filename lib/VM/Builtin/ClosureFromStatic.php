<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClosureSupport;
use PHPCompiler\VM\Variable;

/** Closure::fromStatic() — VM (#9992, Zend/zend_closures.c). */
final class ClosureFromStatic extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('fromStatic');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Closure::fromStatic() expects at least 1 argument');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('Closure::fromStatic() requires VM context');
        }
        $entry = ClosureSupport::fromStatic(
            $frame->vmContext,
            $frame,
            $frame->calledArgs[0]
        );
        if (null !== $frame->returnVar) {
            $ret = new Variable(Variable::TYPE_OBJECT);
            $ret->object($entry);
            $frame->returnVar->copyFrom($ret);
        }
    }
}
