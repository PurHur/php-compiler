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
        $ht = VmArray::requireArray($frame->calledArgs[0], 'current');
        VmArrayPointer::returnValue($frame, $ht->pointerCurrent());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError('current() expects exactly 1 argument, '.\count($args).' given');
        }

        if (!JitReferencableCheck::guardArrayMutatorByRefArg($context, 'current', $args[0])) {
            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        return JitArrayPointer::current($context, $args[0]);
    }
}
