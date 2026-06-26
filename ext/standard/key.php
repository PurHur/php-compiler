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

/** key() — current array key or null (ext/standard/array.c; #4967). */
final class key extends Internal
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError('key() expects exactly 1 argument, '.\count($frame->calledArgs).' given');
        }
        $target = VmArrayPointer::requirePointerTarget($frame->calledArgs[0], 'key', false);
        VmArrayPointer::returnKey($frame, $target->pointerKey());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError('key() expects exactly 1 argument, '.\count($args).' given');
        }

        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            JitArrayElem::requireArrayParam($context, $args[0], 'key', 1, 'array');

            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        if (!JitReferencableCheck::guardArrayMutatorByRefArg($context, 'key', $args[0])) {
            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        return JitArrayPointer::key($context, $args[0]);
    }
}
