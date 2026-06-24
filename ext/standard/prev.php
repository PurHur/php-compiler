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

/** prev() — retreat internal pointer (ext/standard/array.c; #4967). */
final class prev extends Internal
{
    public function __construct()
    {
        parent::__construct('prev');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError('prev() expects exactly 1 argument, '.\count($frame->calledArgs).' given');
        }
        $target = VmArrayPointer::requirePointerTarget($frame->calledArgs[0], 'prev', true);
        VmArrayPointer::returnValue($frame, $target->pointerPrev());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError('prev() expects exactly 1 argument, '.\count($args).' given');
        }

        if (!JitReferencableCheck::guardArrayMutatorByRefArg($context, 'prev', $args[0])) {
            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        return JitArrayPointer::prev($context, $args[0]);
    }
}
