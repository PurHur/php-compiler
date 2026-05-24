<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** usleep() — microsecond delay (VM host; JIT/AOT via libc usleep(3)). */
final class usleep extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('usleep() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $v->type) {
            throw new \LogicException('usleep() requires an integer in this compiler build');
        }
        VmSleep::usleep($v->toInt());
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('usleep() requires exactly one argument');
        }

        return JitSleep::usleep($context, $args[0]);
    }
}
