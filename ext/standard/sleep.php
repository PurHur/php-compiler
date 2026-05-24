<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** sleep() — delay execution (VM host clock; JIT/AOT via libc sleep(3)). */
final class sleep extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('sleep() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $v->type) {
            throw new \LogicException('sleep() requires an integer in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmSleep::sleep($v->toInt());
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('sleep() requires exactly one argument');
        }

        return JitSleep::sleep($context, $args[0]);
    }
}
