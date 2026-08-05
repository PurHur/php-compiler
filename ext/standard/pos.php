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

/**
 * pos() — alias of current() (ext/standard/array.c; #4965 / #27512).
 *
 * JIT/AOT must emit TypeError as pos(), not current(), and must reject null before
 * HashTable load (thin AOT: "Expected array (hashtable), got __value__").
 */
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
        $target = VmArrayPointer::requirePointerTarget($frame->calledArgs[0], 'pos', false, $frame->vmContext, $frame);
        VmArrayPointer::returnValue($frame, $target->pointerCurrent());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError('pos() expects exactly 1 argument, '.\count($args).' given');
        }

        // Mirror current()/key() — compile-time null must not reach loadHashTable (#27512).
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            JitArrayElem::requireArrayParam($context, $args[0], 'pos', 1, 'array');

            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        if (!JitReferencableCheck::guardArrayMutatorByRefArg($context, 'pos', $args[0])) {
            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        return JitArrayPointer::current($context, $args[0], 'pos');
    }
}
