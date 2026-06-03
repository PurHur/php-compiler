<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** reset() — rewind internal pointer (ext/standard/array.c; #4967). */
final class reset_ extends Internal
{
    public function __construct()
    {
        parent::__construct('reset');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError('reset() expects exactly 1 argument, '.\count($frame->calledArgs).' given');
        }
        $ht = VmArrayPointer::requireByRefArray($frame->calledArgs[0], 'reset');
        VmArrayPointer::returnValue($frame, $ht->pointerReset());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitArrayPointer::unsupported($context, 'reset');
    }
}
