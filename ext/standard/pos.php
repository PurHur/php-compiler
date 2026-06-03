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
        $ht = VmArray::requireArray($frame->calledArgs[0], 'pos');
        VmArrayPointer::returnValue($frame, $ht->pointerCurrent());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitArrayPointer::unsupported($context, 'pos');
    }
}
