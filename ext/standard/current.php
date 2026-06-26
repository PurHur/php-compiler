<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitReferencableCheck;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** current() — value at internal pointer or false (ext/standard/array.c; #4967). */
final class current extends Internal
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError('current() expects exactly 1 argument, '.\count($frame->calledArgs).' given');
        }
        $target = VmArrayPointer::requirePointerTarget($frame->calledArgs[0], 'current', false);
        VmArrayPointer::returnValue($frame, $target->pointerCurrent());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError('current() expects exactly 1 argument, '.\count($args).' given');
        }

        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            JitArrayElem::requireArrayParam($context, $args[0], 'current', 1, 'array');

            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        if (!JitReferencableCheck::guardArrayMutatorByRefArg($context, 'current', $args[0])) {
            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        return JitArrayPointer::current($context, $args[0]);
    }
}
