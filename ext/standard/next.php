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

/** next() — advance internal pointer (ext/standard/array.c; #4967). */
final class next extends Internal
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError('next() expects exactly 1 argument, '.\count($frame->calledArgs).' given');
        }
        $ht = VmArrayPointer::requireByRefArray($frame->calledArgs[0], 'next');
        VmArrayPointer::returnValue($frame, $ht->pointerNext());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError('next() expects exactly 1 argument, '.\count($args).' given');
        }

        if (!JitReferencableCheck::guardArrayMutatorByRefArg($context, 'next', $args[0])) {
            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        return JitArrayPointer::next($context, $args[0]);
    }
}
