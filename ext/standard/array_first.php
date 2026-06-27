<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** array_first() — first element value (php-src array.c, #3491). */
final class array_first extends Internal
{
    public function __construct()
    {
        parent::__construct('array_first');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('array_first() requires exactly one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ht = VmArray::requireArray($frame->calledArgs[0]->resolveIndirect(), 'array_first');
        VmArray::requireNonEmptyFirstLastArray($ht, 'array_first');
        $value = VmArray::valueFirst($ht);
        if (null === $value) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->copyFrom($value);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('array_first() requires exactly one argument');
        }

        return JitArrayElem::first($context, $args[0]);
    }
}
