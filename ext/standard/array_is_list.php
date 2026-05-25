<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** array_is_list() — true when keys are consecutive integers 0..count-1 (issue #2211). */
final class array_is_list extends Internal
{
    public function __construct()
    {
        parent::__construct('array_is_list');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('array_is_list() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_ARRAY !== $v->type) {
            throw new \LogicException('array_is_list() requires an array in this compiler build');
        }
        $frame->returnVar->bool(VmArray::isList($v->toArray()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('array_is_list() requires exactly one argument');
        }

        return JitArrayIsList::invoke($context, $args[0]);
    }
}
