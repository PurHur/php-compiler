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
        $ht = VmArray::requireArray($frame->calledArgs[0], 'key');
        VmArrayPointer::returnKey($frame, $ht->pointerKey());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError('key() expects exactly 1 argument, '.\count($args).' given');
        }

        if (!JitReferencableCheck::guardArrayMutatorByRefArg($context, 'key', $args[0])) {
            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        return JitArrayPointer::key($context, $args[0]);
    }
}
