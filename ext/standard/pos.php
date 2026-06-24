<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** pos() — alias of current() (#4965). */
final class pos extends Internal
{
    public function __construct()
    {
        parent::__construct('pos');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError('pos() expects exactly 1 argument, '.\count($frame->calledArgs).' given');
        }
        $target = VmArrayPointer::requirePointerTarget($frame->calledArgs[0], 'pos', false);
        VmArrayPointer::returnValue($frame, $target->pointerCurrent());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError('pos() expects exactly 1 argument, '.\count($args).' given');
        }

        return JitArrayPointer::current($context, $args[0]);
    }
}
