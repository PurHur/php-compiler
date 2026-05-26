<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** array_key_last() — last key in internal order, or null when empty. */
final class array_key_last extends Internal
{
    public function __construct()
    {
        parent::__construct('array_key_last');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('array_key_last() requires exactly one argument');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_key_last() requires an array in this compiler build');
        }
        $key = VmArray::keyLast($array->toArray());
        if (null === $key) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->copyFrom($key);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('array_key_last() requires exactly one argument');
        }

        return JitArrayKey::keyLast($context, $args[0]);
    }
}
